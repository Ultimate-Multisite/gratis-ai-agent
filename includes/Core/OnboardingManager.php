<?php

declare(strict_types=1);
/**
 * Onboarding Manager — tracks first-activation state and the bootstrap session.
 *
 * Manages two concerns:
 * 1. Background SiteScanner job (existing — collects raw site data).
 * 2. Bootstrap session (new in Phase 2) — an AI-driven auto-discovery run that
 *    explores the site with abilities, infers purpose/audience/style, stores
 *    memories, and presents findings + starter prompts to the site owner.
 *
 * REST endpoints:
 *   GET  /sd-ai-agent/v1/onboarding/status    — scan status + completion flag
 *   POST /sd-ai-agent/v1/onboarding/rescan    — reset and schedule a new scan
 *   POST /sd-ai-agent/v1/onboarding/bootstrap — create the bootstrap discovery session
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use SdAiAgent\Models\ActiveJobRepository;
use SdAiAgent\Models\Memory;
use SdAiAgent\REST\RestController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OnboardingManager {

	/**
	 * Option key that records whether the AI-driven bootstrap discovery has been completed.
	 * Set to true once the bootstrap session job has been dispatched.
	 */
	const COMPLETE_OPTION = 'sd_ai_agent_onboarding_complete';

	/**
	 * Option key that records whether the background site scan has been triggered.
	 * Kept for backward compatibility.
	 */
	const TRIGGERED_OPTION = 'sd_ai_agent_onboarding_triggered';

	/**
	 * Option key that persists the onboarding bootstrap session ID.
	 * Stored on first call to rest_bootstrap_start; reused on subsequent calls
	 * to make the endpoint idempotent — repeat calls return the same session.
	 */
	const BOOTSTRAP_SESSION_OPTION = 'sd_ai_agent_bootstrap_session_id';

	// ── Bootstrap ─────────────────────────────────────────────────────────

	/**
	 * Register all hooks.
	 */
	public static function register(): void {
		// Register the cron handler.
		SiteScanner::register();

		// On every admin_init, check whether we should trigger onboarding.
		add_action( 'admin_init', [ __CLASS__, 'maybe_trigger' ] );

		// REST endpoint for status polling.
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
	}

	/**
	 * Called on plugin activation — schedule the scan immediately.
	 */
	public static function on_activation(): void {
		self::trigger();
	}

	// ── Trigger logic ─────────────────────────────────────────────────────

	/**
	 * Trigger the onboarding scan if conditions are met.
	 *
	 * Conditions (all must be true):
	 *  1. Scan has never been triggered before.
	 *  2. No existing memories (fresh install).
	 *  3. Scan is not already complete or running.
	 */
	public static function maybe_trigger(): void {
		// Already triggered — nothing to do.
		if ( get_option( self::TRIGGERED_OPTION ) ) {
			return;
		}

		// Scan already complete or running.
		if ( SiteScanner::is_complete() || SiteScanner::is_pending() ) {
			return;
		}

		// If there are existing memories, this is not a fresh install.
		$existing_memories = Memory::get_all();
		if ( ! empty( $existing_memories ) ) {
			// Mark as triggered so we don't keep checking.
			update_option( self::TRIGGERED_OPTION, true, false );
			return;
		}

		self::trigger();
	}

	/**
	 * Schedule the background scan and mark as triggered.
	 */
	public static function trigger(): void {
		update_option( self::TRIGGERED_OPTION, true, false );
		SiteScanner::schedule();
	}

	/**
	 * Reset onboarding state (allows re-running the scan and bootstrap session).
	 *
	 * Clears both the triggered flag and the completion flag so the next
	 * admin_init will re-evaluate and a fresh bootstrap session can be created.
	 */
	public static function reset(): void {
		delete_option( self::TRIGGERED_OPTION );
		delete_option( self::COMPLETE_OPTION );
		delete_option( self::BOOTSTRAP_SESSION_OPTION );
		delete_option( SiteScanner::STATUS_OPTION );
		SiteScanner::unschedule();
	}

	/**
	 * Mark onboarding as complete (called after the bootstrap session job is dispatched).
	 */
	public static function mark_complete(): void {
		update_option( self::COMPLETE_OPTION, true, false );
	}

	/**
	 * Whether the AI-driven onboarding bootstrap session has been completed.
	 *
	 * Checks both the completion flag and the presence of a persisted bootstrap
	 * session ID so that /onboarding/status stays consistent with bootstrap-start.
	 *
	 * @return bool
	 */
	public static function is_complete(): bool {
		return (bool) get_option( self::COMPLETE_OPTION )
			|| (bool) get_option( self::BOOTSTRAP_SESSION_OPTION );
	}

	// ── REST API ──────────────────────────────────────────────────────────

	/**
	 * Register all onboarding REST routes.
	 */
	public static function register_rest_routes(): void {
		register_rest_route(
			'sd-ai-agent/v1',
			'/onboarding/status',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'rest_get_status' ],
				'permission_callback' => [ __CLASS__, 'rest_permission' ],
			]
		);

		register_rest_route(
			'sd-ai-agent/v1',
			'/onboarding/rescan',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'rest_rescan' ],
				'permission_callback' => [ __CLASS__, 'rest_permission' ],
			]
		);

		register_rest_route(
			'sd-ai-agent/v1',
			'/onboarding/bootstrap',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'rest_create_bootstrap_session' ],
				'permission_callback' => [ __CLASS__, 'rest_permission' ],
			]
		);

		register_rest_route(
			'sd-ai-agent/v1',
			'/onboarding/bootstrap-start',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'rest_bootstrap_start' ],
				'permission_callback' => [ __CLASS__, 'rest_permission' ],
			]
		);
	}

	/**
	 * Permission callback — require manage_options capability.
	 *
	 * @return bool|\WP_Error
	 */
	public static function rest_permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this endpoint.', 'superdav-ai-agent' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * GET /sd-ai-agent/v1/onboarding/status
	 *
	 * Returns the current onboarding state:
	 *  - triggered:           whether the background site scan was triggered
	 *  - scan:                current SiteScanner status
	 *  - scheduled:           whether the scan cron job is queued
	 *  - onboarding_complete: whether the bootstrap session has been dispatched
	 *
	 * @return \WP_REST_Response
	 */
	public static function rest_get_status(): \WP_REST_Response {
		$scan_status = SiteScanner::get_status();

		return new \WP_REST_Response(
			[
				'triggered'           => (bool) get_option( self::TRIGGERED_OPTION ),
				'scan'                => $scan_status,
				'scheduled'           => (bool) wp_next_scheduled( SiteScanner::CRON_HOOK ),
				'onboarding_complete' => self::is_complete(),
			],
			200
		);
	}

	/**
	 * POST /sd-ai-agent/v1/onboarding/rescan
	 *
	 * Resets onboarding state and schedules a fresh scan.
	 *
	 * @return \WP_REST_Response
	 */
	public static function rest_rescan(): \WP_REST_Response {
		self::reset();
		self::trigger();

		return new \WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Site scan scheduled. Results will be available shortly.', 'superdav-ai-agent' ),
			],
			200
		);
	}

	/**
	 * POST /sd-ai-agent/v1/onboarding/bootstrap
	 *
	 * Creates a new session and dispatches the AI-driven auto-discovery job.
	 *
	 * The job uses BootstrapPrompt::generate() as a prepended system instruction
	 * alongside the regular prompt, explores the site with available abilities,
	 * stores memories for future sessions, and presents findings + starter prompts.
	 *
	 * Returns { session_id, job_id, bootstrap_session: true } so the frontend
	 * can open the session and poll the job via the standard job-polling mechanism.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_create_bootstrap_session() {
		$user_id    = get_current_user_id();
		$session_id = Database::create_session(
			[
				'user_id' => $user_id,
				'title'   => __( 'Site Discovery', 'superdav-ai-agent' ),
			]
		);

		if ( ! $session_id ) {
			return new \WP_Error(
				'bootstrap_session_failed',
				__( 'Failed to create bootstrap session.', 'superdav-ai-agent' ),
				[ 'status' => 500 ]
			);
		}

		$job_id = wp_generate_uuid4();
		$token  = wp_generate_password( 40, false );

		$job = [
			'status'     => 'processing',
			'token'      => $token,
			'user_id'    => $user_id,
			'tool_calls' => [],
			'params'     => [
				'message'          => __(
					'Please explore this WordPress site and present your findings.',
					'superdav-ai-agent'
				),
				'session_id'       => $session_id,
				'bootstrap_prompt' => BootstrapPrompt::generate(),
				'max_iterations'   => 100,
			],
		];

		set_transient( RestController::JOB_PREFIX . $job_id, $job, RestController::JOB_TTL );
		ActiveJobRepository::create( $session_id, $job_id, $user_id );

		// Mark onboarding complete — prevents re-dispatching on subsequent calls.
		self::mark_complete();

		// Spawn the background worker via a non-blocking loopback request.
		// This mirrors the pattern in SessionController::handle_run().
		wp_remote_post(
			rest_url( RestController::NAMESPACE . '/process' ),
			[
				'timeout'  => 0.01,
				'blocking' => false,
				'body'     => (string) wp_json_encode(
					[
						'job_id' => $job_id,
						'token'  => $token,
					]
				),
				'headers'  => [ 'Content-Type' => 'application/json' ],
			]
		);

		return new \WP_REST_Response(
			[
				'session_id'        => $session_id,
				'job_id'            => $job_id,
				'bootstrap_session' => true,
			],
			200
		);
	}

	// ── Bootstrap-start REST handler (onboarding v2) ──────────────────────

	/**
	 * POST /sd-ai-agent/v1/onboarding/bootstrap-start
	 *
	 * Called by the frontend when a provider is available and onboarding has
	 * not yet completed. This handler:
	 *
	 *  1. Returns early (already_complete) if onboarding was already completed,
	 *     re-using the persisted session ID so the frontend can resume the chat.
	 *  2. Silently auto-detects WooCommerce and stores a site-context memory.
	 *  3. Creates a dedicated onboarding session for the AI discovery conversation.
	 *  4. Persists the session ID and marks onboarding complete via both the
	 *     COMPLETE_OPTION WordPress option and the Settings store so that
	 *     is_complete() and /onboarding/status stay consistent.
	 *  5. Returns the session ID, bootstrap system prompt, and kickoff message
	 *     so the frontend can auto-send the first message.
	 *
	 * Idempotent: repeat calls return the originally-created session ID with
	 * already_complete=true instead of creating a duplicate session.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_bootstrap_start(): \WP_REST_Response|\WP_Error {
		$settings = Settings::instance();
		$all      = $settings->get();

		// Early-return if onboarding was already completed. Reuse the persisted
		// session ID so the frontend can resume the same conversation.
		$existing_session_id = get_option( self::BOOTSTRAP_SESSION_OPTION );
		if ( self::is_complete() || ! empty( $all['onboarding_complete'] ) ) {
			// Ensure both persistence layers are consistent on legacy installs
			// where only one of the two stores was set.
			self::mark_complete();
			if ( empty( $all['onboarding_complete'] ) ) {
				$settings->update( [ 'onboarding_complete' => true ] );
			}

			$bootstrap_prompt = SystemInstructionBuilder::get_onboarding_bootstrap_prompt();
			$kickoff_message  = __(
				"Hi! I just set up this plugin and I'm ready to get started.",
				'superdav-ai-agent'
			);

			return new \WP_REST_Response(
				[
					'success'                 => true,
					'onboarding_complete'     => true,
					'already_complete'        => true,
					'session_id'              => $existing_session_id ?: null,
					'bootstrap_system_prompt' => $bootstrap_prompt,
					'kickoff_message'         => $kickoff_message,
				],
				200
			);
		}

		// Auto-detect WooCommerce and save a context memory silently.
		$woo_active = class_exists( 'WooCommerce' );
		if ( $woo_active ) {
			$woo_version = defined( 'WC_VERSION' ) ? (string) WC_VERSION : __( '(unknown version)', 'superdav-ai-agent' );
			Memory::create(
				'site_info',
				sprintf(
					/* translators: %s: WooCommerce version */
					__( 'WooCommerce %s is active on this site.', 'superdav-ai-agent' ),
					$woo_version
				)
			);
		}

		// Create the bootstrap session.
		$session_id = Database::create_session(
			[
				'user_id'     => get_current_user_id(),
				'title'       => __( 'Getting started', 'superdav-ai-agent' ),
				'provider_id' => $all['default_provider'] ?? '',
				'model_id'    => $all['default_model'] ?? '',
			]
		);

		if ( ! $session_id ) {
			return new \WP_Error(
				'bootstrap_session_failed',
				__( 'Failed to create bootstrap session.', 'superdav-ai-agent' ),
				[ 'status' => 500 ]
			);
		}

		// Persist the session ID and mark onboarding complete atomically across
		// both persistence layers so is_complete() and /onboarding/status agree.
		update_option( self::BOOTSTRAP_SESSION_OPTION, $session_id, false );
		self::mark_complete();
		$settings->update( [ 'onboarding_complete' => true ] );

		$bootstrap_prompt = SystemInstructionBuilder::get_onboarding_bootstrap_prompt();

		// The kickoff message is sent by the frontend as the first user turn.
		// Keeping it short and natural — the system prompt handles exploration.
		$kickoff_message = __(
			"Hi! I just set up this plugin and I'm ready to get started.",
			'superdav-ai-agent'
		);

		return new \WP_REST_Response(
			[
				'success'                 => true,
				'onboarding_complete'     => true,
				'session_id'              => $session_id,
				'bootstrap_system_prompt' => $bootstrap_prompt,
				'kickoff_message'         => $kickoff_message,
				'woo_detected'            => $woo_active,
			],
			200
		);
	}
}
