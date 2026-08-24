<?php

declare(strict_types=1);
/**
 * Automation Runner — cron handler that fires Agent_Loop for scheduled automations.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Automations;

use SdAiAgent\Core\AgentLoop;
use SdAiAgent\Core\BudgetManager;
use SdAiAgent\Core\Database;
use SdAiAgent\Core\ProviderCredentialLoader;
use SdAiAgent\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AutomationRunner {

	const CRON_HOOK = 'sd_ai_agent_run_automation';

	/** Default bounded lease for one scheduled automation execution. */
	const DEFAULT_LEASE_SECONDS = 3600;

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'run' ] );

		// Register custom weekly schedule if not already available.
		add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedules' ] );
	}

	/**
	 * Add custom cron schedules.
	 *
	 * @param array<string, mixed> $schedules Existing schedules.
	 * @return array<string, mixed>
	 */
	public static function add_cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = [
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'superdav-ai-agent' ),
			];
		}
		return $schedules;
	}

	/**
	 * Schedule a cron event for an automation.
	 *
	 * @param int    $automation_id Automation ID.
	 * @param string $schedule      WordPress cron schedule name.
	 */
	public static function schedule( int $automation_id, string $schedule ): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK, [ $automation_id ] ) ) {
			wp_schedule_event( time(), $schedule, self::CRON_HOOK, [ $automation_id ] );
		}
	}

	/**
	 * Unschedule a cron event for an automation.
	 *
	 * @param int $automation_id Automation ID.
	 */
	public static function unschedule( int $automation_id ): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK, [ $automation_id ] );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK, [ $automation_id ] );
		}
		// Also clear any recurring schedules.
		wp_clear_scheduled_hook( self::CRON_HOOK, [ $automation_id ] );
	}

	/**
	 * Run an automation (fired by WP Cron or manually).
	 *
	 * @param int $automation_id Automation ID.
	 * @return array<string, mixed>|null Run result or null when the automation does not exist.
	 */
	public static function run( int $automation_id ): ?array {
		$automation = Automations::get( $automation_id );
		if ( ! $automation ) {
			return null;
		}

		$run_id = wp_generate_uuid4();
		if ( ! Database::has_transactional_automation_storage() ) {
			return self::record_blocked_delivery(
				$automation,
				$run_id,
				__( 'Automation lifecycle storage is unavailable and must be repaired before this automation can run.', 'superdav-ai-agent' )
			);
		}

		// Never attempt a correlated stale-state transition unless the two
		// lifecycle tables have been verified as transactional.
		self::recover_stale_runs();

		// Recovery can change lifecycle fields while another request can disable
		// or delete the automation, so use a fresh definition for this delivery.
		$automation = Automations::get( $automation_id );
		if ( ! $automation ) {
			return null;
		}

		if ( empty( $automation['enabled'] ) ) {
			return self::record_blocked_delivery(
				$automation,
				$run_id,
				__( 'This automation is disabled and cannot run.', 'superdav-ai-agent' )
			);
		}

		$lease_expires_at = self::get_lease_expiration( $automation );
		$claim_state      = self::claim_run_with_lifecycle( $automation, $run_id, $lease_expires_at );
		if ( 'failed' === $claim_state ) {
			return self::build_fallback_result(
				$automation,
				$run_id,
				'failed',
				__( 'The automation run could not be recorded before execution.', 'superdav-ai-agent' )
			);
		}

		if ( 'contended' === $claim_state ) {
			return self::record_blocked_delivery(
				$automation,
				$run_id,
				__( 'Another scheduled delivery already owns this automation run.', 'superdav-ai-agent' )
			);
		}

		$owner_id = (int) ( $automation['owner_user_id'] ?? 0 );
		if ( $owner_id <= 0 ) {
			return self::finish_owned_run(
				$automation,
				$run_id,
				'blocked',
				[
					'error_message' => __( 'This automation has no authorized owner and must be reconfigured.', 'superdav-ai-agent' ),
				]
			);
		}

		$owner = get_user_by( 'id', $owner_id );
		if ( ! $owner || ! user_can( $owner, 'manage_options' ) ) {
			return self::finish_owned_run(
				$automation,
				$run_id,
				'blocked',
				[
					'error_message' => __( 'The automation owner no longer has permission to run automations.', 'superdav-ai-agent' ),
				]
			);
		}

		$previous_user_id = get_current_user_id();
		wp_set_current_user( $owner_id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_set_current_user( $previous_user_id );
			return self::finish_owned_run(
				$automation,
				$run_id,
				'blocked',
				[
					'error_message' => __( 'The automation owner could not be restored for this run.', 'superdav-ai-agent' ),
				]
			);
		}

		$execution_lock = null;
		try {
			// Budget checks run after the exact owner is restored and before any
			// provider credential or model work starts.
			if ( BudgetManager::is_exceeded() ) {
				return self::finish_owned_run(
					$automation,
					$run_id,
					'blocked',
					[
						'error_message' => __( 'The automation budget is currently exceeded.', 'superdav-ai-agent' ),
					]
				);
			}

			$tool_profile = Automations::resolve_tool_profile( $automation );
			if ( $tool_profile instanceof \WP_Error ) {
				return self::finish_owned_run(
					$automation,
					$run_id,
					'blocked',
					[
						'error_message' => $tool_profile->get_error_message(),
					]
				);
			}

			$execution_lock = Automations::acquire_execution_lock( $automation_id );
			if ( null === $execution_lock ) {
				return self::finish_owned_run(
					$automation,
					$run_id,
					'blocked',
					[
						'error_message' => __( 'Another automation execution is still active.', 'superdav-ai-agent' ),
					]
				);
			}

			if ( ! self::mark_owned_run_running( $automation_id, $run_id ) ) {
				return self::finish_owned_run(
					$automation,
					$run_id,
					'failed',
					[
						'error_message' => __( 'The automation run could not enter its execution lifecycle.', 'superdav-ai-agent' ),
					]
				);
			}

			register_shutdown_function( [ __CLASS__, 'handle_run_shutdown' ], $automation_id, $run_id, $execution_lock );

			$start_time = microtime( true );

			// Ensure provider credentials are available only after the claim,
			// owner validation, and stored tool profile have all passed.
			ProviderCredentialLoader::load();

			// Build agent loop options.
			$settings = Settings::instance()->get();
			$options  = [
				// @phpstan-ignore-next-line
				'max_iterations' => $automation['max_iterations'] ?: ( $settings['max_iterations'] ?: 10 ),
				// @phpstan-ignore-next-line
				'provider_id'    => $settings['default_provider'] ?? '',
				// @phpstan-ignore-next-line
				'model_id'       => $settings['default_model'] ?? '',
			];

			if ( is_array( $tool_profile ) ) {
				// AgentLoop applies this through ToolDiscovery's canonical request-
				// scoped allowlist, covering direct tools, Tier-2 search, ability-
				// call dispatch, and provider-native tool search.
				$options['anonymous_policy_active']     = true;
				$options['anonymous_allowed_abilities'] = $tool_profile;
			}

			// @phpstan-ignore-next-line
			$loop   = new AgentLoop( $automation['prompt'], [], [], $options );
			$result = $loop->run();

			$duration = (int) round( ( microtime( true ) - $start_time ) * 1000 );
			if ( $result instanceof \WP_Error ) {
				$terminal_status = 'failed';
				$log_data        = self::finish_owned_run(
					$automation,
					$run_id,
					$terminal_status,
					[
						'duration_ms'   => $duration,
						'error_message' => $result->get_error_message(),
					]
				);
			} else {
				$terminal_status = 'succeeded';
				$token_usage     = isset( $result['token_usage'] ) && is_array( $result['token_usage'] ) ? $result['token_usage'] : [];
				$log_data        = self::finish_owned_run(
					$automation,
					$run_id,
					$terminal_status,
					[
						'reply'             => $result['reply'] ?? '',
						'tool_calls'        => $result['tool_calls'] ?? [],
						'prompt_tokens'     => $token_usage['prompt'] ?? 0,
						'completion_tokens' => $token_usage['completion'] ?? 0,
						'duration_ms'       => $duration,
					]
				);
			}

			if ( ! self::has_authoritative_terminal_state( $automation_id, $run_id, $terminal_status ) ) {
				return $log_data;
			}

			// Dispatch Slack/Discord notifications after provider work completes.
			NotificationDispatcher::dispatch( $automation, $log_data );

			/**
			 * Fires after a scheduled automation completes.
			 *
			 * @param int   $automation_id The automation ID.
			 * @param array $log_data      The scrubbed durable log data for this run.
			 * @param array $automation    The automation definition.
			 */
			do_action( 'sd_ai_agent_automation_complete', $automation_id, $log_data, $automation );

			return $log_data;
		} catch ( \Throwable ) {
			return self::finish_owned_run(
				$automation,
				$run_id,
				'failed',
				[
					'error_message' => __( 'The automation execution ended unexpectedly before completion.', 'superdav-ai-agent' ),
				]
			);
		} finally {
			if ( null !== $execution_lock ) {
				Automations::release_execution_lock( $execution_lock );
			}
			wp_set_current_user( $previous_user_id );
		}
	}

	/**
	 * Mark a still-active claim abandoned when PHP exits before normal cleanup.
	 *
	 * Normal terminal paths use conditional updates, so this shutdown handler is
	 * a harmless no-op after a successful, failed, or blocked completion.
	 *
	 * @param int    $automation_id  Automation ID.
	 * @param string $run_id         Correlation UUID for the execution.
	 * @param string $execution_lock Advisory lock held by the execution.
	 */
	public static function handle_run_shutdown( int $automation_id, string $run_id, string $execution_lock = '' ): void {
		$reason = __( 'The automation request ended before completion.', 'superdav-ai-agent' );

		try {
			$automation = Automations::get( $automation_id );
			if ( is_array( $automation ) ) {
				self::finish_owned_run( $automation, $run_id, 'abandoned', [ 'error_message' => $reason ] );
			}
		} finally {
			if ( '' !== $execution_lock ) {
				Automations::release_execution_lock( $execution_lock );
			}
		}
	}

	/**
	 * Recover bounded stale leases before admitting another delivery.
	 */
	private static function recover_stale_runs(): void {
		Automations::abandon_expired_runs();
		AutomationLogs::abandon_expired_runs();
	}

	/**
	 * Calculate a bounded UTC lease expiration for a delivery.
	 *
	 * @param array<string, mixed> $automation Automation definition.
	 */
	private static function get_lease_expiration( array $automation ): string {
		/**
		 * Filter the maximum duration of a scheduled automation execution lease.
		 *
		 * @param int                  $seconds    Bounded lease duration in seconds.
		 * @param array<string, mixed> $automation Automation definition.
		 */
		$seconds = (int) apply_filters( 'sd_ai_agent_automation_run_lease_seconds', self::DEFAULT_LEASE_SECONDS, $automation );
		// A filter may extend the recovery window, but must not reduce it below
		// the conservative default and allow a slow valid delivery to overlap.
		$seconds = max( self::DEFAULT_LEASE_SECONDS, min( DAY_IN_SECONDS, $seconds ) );

		return gmdate( 'Y-m-d H:i:s', time() + $seconds );
	}

	/**
	 * Persist a terminal blocked delivery that did not acquire an active claim.
	 *
	 * @param array<string, mixed> $automation Automation definition.
	 * @param string               $run_id     Correlation UUID for the execution.
	 * @param string               $reason     Safe operator-facing block reason.
	 * @return array<string, mixed>
	 */
	private static function record_blocked_delivery( array $automation, string $run_id, string $reason ): array {
		$log_id = AutomationLogs::create(
			[
				'automation_id'    => (int) ( $automation['id'] ?? 0 ),
				'run_id'           => $run_id,
				'owner_user_id'    => (int) ( $automation['owner_user_id'] ?? 0 ),
				'trigger_type'     => 'scheduled',
				'status'           => 'error',
				'lifecycle_status' => 'blocked',
				'error_message'    => $reason,
			]
		);

		if ( false !== $log_id ) {
			$log = AutomationLogs::get( $log_id );
			if ( is_array( $log ) ) {
				return $log;
			}
		}

		return self::build_fallback_result( $automation, $run_id, 'blocked', $reason );
	}

	/**
	 * Atomically persist the claimed log and the matching automation claim.
	 *
	 * @param array<string, mixed> $automation       Automation definition.
	 * @param string               $run_id           Correlation UUID for this delivery.
	 * @param string               $lease_expires_at UTC MySQL expiration datetime.
	 * @return 'claimed'|'contended'|'failed'
	 */
	private static function claim_run_with_lifecycle( array $automation, string $run_id, string $lease_expires_at ): string {
		$automation_id = (int) ( $automation['id'] ?? 0 );
		if ( $automation_id <= 0 || ! self::begin_lifecycle_transaction() ) {
			return 'failed';
		}

		$log_id = AutomationLogs::create(
			[
				'automation_id'    => $automation_id,
				'run_id'           => $run_id,
				'owner_user_id'    => (int) ( $automation['owner_user_id'] ?? 0 ),
				'trigger_type'     => 'scheduled',
				'status'           => 'pending',
				'lifecycle_status' => 'claimed',
				'lease_expires_at' => $lease_expires_at,
			]
		);
		if ( false === $log_id ) {
			self::rollback_lifecycle_transaction();
			return 'failed';
		}

		if ( ! Automations::claim_run( $automation_id, $run_id, $lease_expires_at ) ) {
			self::rollback_lifecycle_transaction();
			return 'contended';
		}

		if ( ! self::commit_lifecycle_transaction() ) {
			self::rollback_lifecycle_transaction();
			return 'failed';
		}

		return 'claimed';
	}

	/** Transition both claimed lifecycle rows to running together. */
	private static function mark_owned_run_running( int $automation_id, string $run_id ): bool {
		if ( ! self::begin_lifecycle_transaction() ) {
			return false;
		}

		$automation_running = Automations::mark_run_running( $automation_id, $run_id );
		$log_running        = AutomationLogs::mark_run_running( $run_id );
		if ( ! $automation_running || ! $log_running || ! self::commit_lifecycle_transaction() ) {
			self::rollback_lifecycle_transaction();
			return false;
		}

		return true;
	}

	/**
	 * Finalize an owned claim in the log and automation state tables.
	 *
	 * @param array<string, mixed> $automation Automation definition.
	 * @param string               $run_id     Correlation UUID for the execution.
	 * @param string               $status     Terminal lifecycle status.
	 * @param array<string, mixed> $data       Completion data.
	 * @return array<string, mixed>
	 */
	private static function finish_owned_run( array $automation, string $run_id, string $status, array $data = [] ): array {
		$automation_id = (int) ( $automation['id'] ?? 0 );
		$error         = isset( $data['error_message'] ) && is_scalar( $data['error_message'] ) ? (string) $data['error_message'] : '';

		if ( $automation_id <= 0 || ! self::begin_lifecycle_transaction() ) {
			return self::build_fallback_result( $automation, $run_id, $status, $error );
		}

		$log_completed        = AutomationLogs::complete_run( $run_id, $status, $data );
		$automation_completed = Automations::finish_run( $automation_id, $run_id, $status, $error );
		if ( ! $log_completed || ! $automation_completed || ! self::commit_lifecycle_transaction() ) {
			self::rollback_lifecycle_transaction();
			return self::build_fallback_result( $automation, $run_id, $status, $error );
		}

		$log = AutomationLogs::get_by_run_id( $run_id );
		if ( is_array( $log ) ) {
			return $log;
		}

		return self::build_fallback_result( $automation, $run_id, $status, $error );
	}

	/** Confirm that both durable rows hold the same terminal run outcome. */
	private static function has_authoritative_terminal_state( int $automation_id, string $run_id, string $status ): bool {
		$automation = Automations::get( $automation_id );
		$log        = AutomationLogs::get_by_run_id( $run_id );

		return is_array( $automation )
			&& is_array( $log )
			&& '' === (string) ( $automation['active_run_id'] ?? '' )
			&& $run_id === (string) ( $automation['last_run_id'] ?? '' )
			&& $status === (string) ( $automation['last_run_status'] ?? '' )
			&& $status === (string) ( $log['lifecycle_status'] ?? '' );
	}

	/** Begin one short transaction spanning the automation and log lifecycle rows. */
	private static function begin_lifecycle_transaction(): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Keeps automation and log lifecycle transitions atomic.
		return false !== $wpdb->query( 'START TRANSACTION' );
	}

	/** Commit one short transaction spanning the automation and log lifecycle rows. */
	private static function commit_lifecycle_transaction(): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Keeps automation and log lifecycle transitions atomic.
		return false !== $wpdb->query( 'COMMIT' );
	}

	/** Roll back one short transaction spanning the automation and log lifecycle rows. */
	private static function rollback_lifecycle_transaction(): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Restores both durable lifecycle rows after a guarded transition fails.
		$wpdb->query( 'ROLLBACK' );
	}

	/**
	 * Provide a safe response when durable log retrieval fails.
	 *
	 * @param array<string, mixed> $automation Automation definition.
	 * @param string               $run_id     Correlation UUID for the execution.
	 * @param string               $status     Lifecycle status.
	 * @param string               $error      Safe operator-facing error detail.
	 * @return array<string, mixed>
	 */
	private static function build_fallback_result( array $automation, string $run_id, string $status, string $error ): array {
		return [
			'automation_id'     => (int) ( $automation['id'] ?? 0 ),
			'run_id'            => $run_id,
			'owner_user_id'     => (int) ( $automation['owner_user_id'] ?? 0 ),
			'status'            => 'succeeded' === $status ? 'success' : 'error',
			'lifecycle_status'  => $status,
			'reply'             => '',
			'tool_calls'        => [],
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'duration_ms'       => 0,
			'error_message'     => $error,
		];
	}

	/**
	 * Reschedule all enabled automations (called on activation).
	 */
	public static function reschedule_all(): void {
		$automations = Automations::list( true );

		foreach ( $automations as $automation ) {
			$id       = isset( $automation['id'] ) ? (int) $automation['id'] : 0;
			$schedule = isset( $automation['schedule'] ) ? (string) $automation['schedule'] : '';
			if ( $id <= 0 || '' === $schedule ) {
				continue;
			}
			self::unschedule( $id );
			self::schedule( $id, $schedule );
		}
	}

	/**
	 * Unschedule all automations (called on deactivation).
	 */
	public static function unschedule_all(): void {
		$automations = Automations::list();

		foreach ( $automations as $automation ) {
			$id = isset( $automation['id'] ) ? (int) $automation['id'] : 0;
			if ( $id > 0 ) {
				self::unschedule( $id );
			}
		}
	}
}
