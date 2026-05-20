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
 *   GET  /sd-ai-agent/v1/onboarding/status          — scan status + completion flag
 *   POST /sd-ai-agent/v1/onboarding/rescan          — reset and schedule a new scan
 *   POST /sd-ai-agent/v1/onboarding/bootstrap-start — create the bootstrap discovery session
 *                                                     (attaches the Setup Assistant agent)
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use SdAiAgent\Models\Agent;
use SdAiAgent\Models\Memory;

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

	/**
	 * Option key that persists the theme-builder session ID.
	 * Stored on first call to rest_theme_builder_start; reused on subsequent calls
	 * to make the endpoint idempotent — repeat calls return the same session.
	 */
	const THEME_BUILDER_SESSION_OPTION = 'sd_ai_agent_theme_builder_session_id';

	/**
	 * Option key that records whether the theme-builder session has been started.
	 * Set to true on first call to rest_theme_builder_start to prevent the React
	 * component from re-firing the kickoff message on reload.
	 */
	const THEME_BUILDER_STARTED_OPTION = 'sd_ai_agent_theme_builder_started';

	/**
	 * Called on plugin activation.
	 *
	 * Two paths:
	 *
	 * 1. Genuine fresh install (no existing sessions and no existing memories):
	 *    schedule the background site scan so the bootstrap discovery has
	 *    context to work with.
	 *
	 * 2. Upgrade on an install that already has user-generated data: mark
	 *    onboarding complete in both persistence layers so the
	 *    OnboardingBootstrap UI never opens. Existing installs should never
	 *    be dropped into the Setup Assistant discovery flow on upgrade.
	 *
	 * Detection rule: if either Memory rows or chat sessions exist, this is
	 * not a fresh install. We deliberately do not rely on the value of
	 * `onboarding_complete` itself because legacy installs may have
	 * never set it.
	 */
	public static function on_activation(): void {
		if ( self::install_has_existing_data() ) {
			update_option( self::TRIGGERED_OPTION, true, false );
			self::mark_complete();

			$settings = Settings::instance();
			$current  = $settings->get();
			if ( empty( $current['onboarding_complete'] ) ) {
				$settings->update( [ 'onboarding_complete' => true ] );
			}
			return;
		}

		self::trigger();
	}

	/**
	 * Whether this install already has user-generated agent data.
	 *
	 * Used by on_activation() to distinguish a genuine fresh install from
	 * an upgrade of an already-used install. We check both Memory rows
	 * (set by any prior onboarding or normal use) and chat sessions
	 * (set by any prior conversation) so a single positive signal in
	 * either store is enough to flag the install as non-fresh.
	 */
	private static function install_has_existing_data(): bool {
		$existing_memories = Memory::get_all();
		if ( ! empty( $existing_memories ) ) {
			return true;
		}

		global $wpdb;
		$sessions_table = $wpdb->prefix . 'sd_ai_agent_sessions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$session_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$sessions_table}`" );
		return $session_count > 0;
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
	 * Clears the triggered flag, the completion flag, the persisted bootstrap
	 * and theme-builder session IDs, the theme-builder started flag, and the
	 * SiteScanner status so the next admin_init re-evaluates from scratch.
	 * Also unschedules any pending scan cron event. The Settings store flag
	 * `onboarding_complete` is NOT modified here — callers that need to re-open
	 * the v2 admin-page gate must flip it to `false` separately (see
	 * {@see rest_reset()}).
	 */
	public static function reset(): void {
		delete_option( self::TRIGGERED_OPTION );
		delete_option( self::COMPLETE_OPTION );
		delete_option( self::BOOTSTRAP_SESSION_OPTION );
		delete_option( self::THEME_BUILDER_SESSION_OPTION );
		delete_option( self::THEME_BUILDER_STARTED_OPTION );
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
			'/onboarding/bootstrap-start',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'rest_bootstrap_start' ],
				'permission_callback' => [ __CLASS__, 'rest_permission' ],
			]
		);

		register_rest_route(
			'sd-ai-agent/v1',
			'/onboarding/theme-builder-start',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'rest_theme_builder_start' ],
				'permission_callback' => [ __CLASS__, 'rest_permission' ],
			]
		);

		register_rest_route(
			'sd-ai-agent/v1',
			'/onboarding/reset',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'rest_reset' ],
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

	// ── Bootstrap-start REST handler ──────────────────────────────────────

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
	 *  5. Returns the session ID, the Setup Assistant agent_id, and a kickoff
	 *     message so the frontend can attach the agent and auto-send the first
	 *     message — the agent's own system prompt drives the discovery flow.
	 *
	 * Idempotent: repeat calls return the originally-created session ID with
	 * already_complete=true instead of creating a duplicate session.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_bootstrap_start(): \WP_REST_Response|\WP_Error {
		$settings = Settings::instance();
		$all      = $settings->get();

		// Resolve the Setup Assistant agent so the frontend can attach it to
		// the bootstrap session. The agent's stored system prompt is the
		// canonical source of truth — no parallel bootstrap prompt is needed.
		$onboarding_agent    = Agent::get_by_slug( Agent::ONBOARDING_AGENT_SLUG );
		$onboarding_agent_id = $onboarding_agent ? (int) $onboarding_agent->id : 0;

		$kickoff_message = __(
			"Hi! I just set up this plugin and I'm ready to get started.",
			'superdav-ai-agent'
		);

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

			return new \WP_REST_Response(
				[
					'success'             => true,
					'onboarding_complete' => true,
					'already_complete'    => true,
					'session_id'          => $existing_session_id ?: null,
					'agent_id'            => $onboarding_agent_id,
					'kickoff_message'     => $kickoff_message,
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

		// Create the bootstrap session, applying the Setup Assistant agent's
		// provider/model overrides if present so the session starts with the
		// right model for the first turn.
		$session_data = [
			'user_id'     => get_current_user_id(),
			'title'       => __( 'Getting started', 'superdav-ai-agent' ),
			'provider_id' => $all['default_provider'] ?? '',
			'model_id'    => $all['default_model'] ?? '',
		];

		if ( $onboarding_agent_id > 0 ) {
			$agent_options = Agent::get_loop_options( $onboarding_agent_id );
			if ( ! empty( $agent_options['provider_id'] ) ) {
				$session_data['provider_id'] = $agent_options['provider_id'];
			}
			if ( ! empty( $agent_options['model_id'] ) ) {
				$session_data['model_id'] = $agent_options['model_id'];
			}
		}

		$session_id = Database::create_session( $session_data );

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

		return new \WP_REST_Response(
			[
				'success'             => true,
				'onboarding_complete' => true,
				'session_id'          => $session_id,
				'agent_id'            => $onboarding_agent_id,
				'kickoff_message'     => $kickoff_message,
				'woo_detected'        => $woo_active,
			],
			200
		);
	}

	// ── Theme-builder-start REST handler ──────────────────────────────────

	/**
	 * POST /sd-ai-agent/v1/onboarding/theme-builder-start
	 *
	 * Called by the frontend when a user chooses the theme-builder onboarding
	 * path. This handler mirrors rest_bootstrap_start but:
	 *
	 *  1. Resolves the theme-builder agent instead of the onboarding agent.
	 *  2. Does NOT mark onboarding complete (the user may still want the
	 *     bootstrap discovery flow after building the theme).
	 *  3. Does NOT auto-detect WooCommerce.
	 *  4. Persists the session ID under THEME_BUILDER_SESSION_OPTION so
	 *     repeat calls return the same session.
	 *  5. Sets THEME_BUILDER_STARTED_OPTION on first call to prevent the React
	 *     component from re-firing the kickoff message on reload.
	 *  6. Returns an explicit `is_fresh_start` boolean so the React component
	 *     can distinguish a fresh-create request (kickoff SHOULD fire) from a
	 *     resume request (kickoff MUST NOT fire). The boolean is the
	 *     authoritative signal — `started_at` is also returned for
	 *     observability/back-compat but MUST NOT be used to drive kickoff
	 *     behaviour because both branches return a truthy timestamp.
	 *  7. Returns the same JSON shape as bootstrap-start so the React entry
	 *     component can use a single helper.
	 *
	 * Idempotent: repeat calls return the originally-created session ID
	 * instead of creating a duplicate session.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_theme_builder_start(): \WP_REST_Response|\WP_Error {
		$settings = Settings::instance();
		$all      = $settings->get();

		// Resolve the theme-builder agent so the frontend can attach it to
		// the theme-builder session. The agent's stored system prompt is the
		// canonical source of truth — no parallel theme-builder prompt is needed.
		$theme_builder_agent    = Agent::get_by_slug( Agent::THEME_BUILDER_AGENT_SLUG );
		$theme_builder_agent_id = $theme_builder_agent ? (int) $theme_builder_agent->id : 0;

		$kickoff_message = __(
			"I'd like to design a custom block theme for my site. Please start the interview.",
			'superdav-ai-agent'
		);

		// Early-return if a theme-builder session was already created. Reuse the
		// persisted session ID so the frontend can resume the same conversation
		// without re-firing the kickoff message. `is_fresh_start` is false on
		// this branch so the React component skips the kickoff send.
		$existing_session_id = get_option( self::THEME_BUILDER_SESSION_OPTION );
		if ( ! empty( $existing_session_id ) ) {
			return new \WP_REST_Response(
				[
					'success'         => true,
					'session_id'      => $existing_session_id,
					'agent_id'        => $theme_builder_agent_id,
					'kickoff_message' => $kickoff_message,
					'started_at'      => get_option( self::THEME_BUILDER_STARTED_OPTION ),
					'is_fresh_start'  => false,
				],
				200
			);
		}

		// Create the theme-builder session, applying the theme-builder agent's
		// provider/model overrides if present so the session starts with the
		// right model for the first turn.
		$session_data = [
			'user_id'     => get_current_user_id(),
			'title'       => __( 'Theme Builder', 'superdav-ai-agent' ),
			'provider_id' => $all['default_provider'] ?? '',
			'model_id'    => $all['default_model'] ?? '',
		];

		if ( $theme_builder_agent_id > 0 ) {
			$agent_options = Agent::get_loop_options( $theme_builder_agent_id );
			if ( ! empty( $agent_options['provider_id'] ) ) {
				$session_data['provider_id'] = $agent_options['provider_id'];
			}
			if ( ! empty( $agent_options['model_id'] ) ) {
				$session_data['model_id'] = $agent_options['model_id'];
			}
		}

		$session_id = Database::create_session( $session_data );

		if ( ! $session_id ) {
			return new \WP_Error(
				'theme_builder_session_failed',
				__( 'Failed to create theme-builder session.', 'superdav-ai-agent' ),
				[ 'status' => 500 ]
			);
		}

		// Persist the session ID and the started timestamp so repeat calls return
		// the same session and the React component knows not to re-fire the kickoff.
		// Note: we do NOT mark onboarding complete — the user may still want
		// the bootstrap discovery flow after building the theme.
		$started_at = time();
		update_option( self::THEME_BUILDER_SESSION_OPTION, $session_id, false );
		update_option( self::THEME_BUILDER_STARTED_OPTION, $started_at, false );

		// `is_fresh_start` is true on this branch — the React component will
		// auto-send the kickoff message exactly once. Subsequent calls hit the
		// resume branch above (is_fresh_start=false) so reloads never duplicate
		// the kickoff. See #1522 for the regression this signal fixes.
		return new \WP_REST_Response(
			[
				'success'         => true,
				'session_id'      => $session_id,
				'agent_id'        => $theme_builder_agent_id,
				'kickoff_message' => $kickoff_message,
				'started_at'      => $started_at,
				'is_fresh_start'  => true,
			],
			200
		);
	}

	// ── Reset REST handler ────────────────────────────────────────────────

	/**
	 * POST /sd-ai-agent/v1/onboarding/reset
	 *
	 * Clears onboarding state so the v2 direct-routing gate in
	 * src/admin-page/index.js fires on the next chat-page mount. Used by the
	 * "Restart Setup Assistant" control on the Settings → Advanced tab.
	 *
	 * Unlike the legacy v1 flow, this endpoint does NOT re-launch a wizard.
	 * The v2 gate probes `/wp/v2/posts` once per mount and drops the user
	 * into either:
	 *  - OnboardingBootstrap (Setup Assistant agent) — sites with content
	 *  - OnboardingThemeBuilder (Theme Builder agent) — empty installs
	 *
	 * Resets, in order:
	 *  1. {@see OnboardingManager::reset()} — clears TRIGGERED_OPTION,
	 *     COMPLETE_OPTION, BOOTSTRAP_SESSION_OPTION,
	 *     THEME_BUILDER_SESSION_OPTION, and the SiteScanner status; unschedules
	 *     any pending scan cron event.
	 *  2. `settings.onboarding_complete` — the React admin-page treats
	 *     `settings.onboarding_complete !== false` as "done", so an explicit
	 *     `false` is required to re-open the gate.
	 *
	 * Returns the chat-page URL so the frontend can offer a one-click link
	 * into the freshly-mounted gate.
	 *
	 * @return \WP_REST_Response
	 */
	public static function rest_reset(): \WP_REST_Response {
		self::reset();

		// Mirror the reset in the Settings store. The JS admin-page gates the
		// onboarding flow on `settings.onboarding_complete !== false`, so an
		// explicit `false` (not just option deletion) is required.
		Settings::instance()->update( [ 'onboarding_complete' => false ] );

		return new \WP_REST_Response(
			[
				'success'  => true,
				'message'  => __( 'Onboarding state cleared. The Setup Assistant will reintroduce itself the next time you open the AI Agent chat page.', 'superdav-ai-agent' ),
				'chat_url' => admin_url( 'admin.php?page=sd-ai-agent#/chat' ),
			],
			200
		);
	}
}
