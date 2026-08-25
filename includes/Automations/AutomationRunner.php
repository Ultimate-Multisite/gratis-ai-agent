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

	/** Minimum delay before a newly enabled Monitor may run. */
	const MONITOR_START_DELAY_SECONDS = MINUTE_IN_SECONDS;

	/** Deterministic per-Monitor schedule spread to avoid synchronized calls. */
	const MONITOR_JITTER_SECONDS = 900;

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
			wp_schedule_event( self::get_initial_schedule_timestamp( $automation_id ), $schedule, self::CRON_HOOK, [ $automation_id ] );
		}

		self::sync_next_run_at( $automation_id );
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
		self::sync_next_run_at( $automation_id );
	}

	/**
	 * Choose a normal immediate start for tasks and a stable spread for Monitors.
	 */
	private static function get_initial_schedule_timestamp( int $automation_id ): int {
		$automation = Automations::get( $automation_id );
		if ( ! is_array( $automation ) || ! Automations::is_monitor( $automation ) ) {
			return time();
		}

		$jitter = (int) ( crc32( (string) $automation_id ) % self::MONITOR_JITTER_SECONDS );

		return time() + self::MONITOR_START_DELAY_SECONDS + $jitter;
	}

	/** Synchronize the stored next-run timestamp with WordPress cron state. */
	private static function sync_next_run_at( int $automation_id ): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK, [ $automation_id ] );

		Automations::update_next_run_at( $automation_id, false === $timestamp ? null : (int) $timestamp );
	}

	/**
	 * Run an enabled automation (fired by WP Cron or the regular manual action).
	 *
	 * @param int $automation_id Automation ID.
	 * @return array<string, mixed>|null Run result or null when the automation does not exist.
	 */
	public static function run( int $automation_id ): ?array {
		return self::run_internal( $automation_id, false );
	}

	/**
	 * Run one disabled Monitor draft without enabling or scheduling it.
	 *
	 * This is intentionally a separate entry point so normal cron and manual task
	 * deliveries retain their disabled-run block. The REST controller exposes it
	 * only to authorized administrators after confirming the stored definition is
	 * a disabled Monitor.
	 *
	 * @param int $automation_id Monitor automation ID.
	 * @return array<string, mixed>|null Run result or null when the automation does not exist.
	 */
	public static function run_manual_monitor_draft( int $automation_id ): ?array {
		return self::run_internal( $automation_id, true );
	}

	/**
	 * Run one coalesced Monitor event wake through the normal ownership, budget,
	 * tool-profile, lifecycle, outcome, and notification path.
	 *
	 * The returned disposition is internal queue metadata. It is `defer` only
	 * while no model request has started, so retained evidence is never replayed
	 * after an uncertain provider or tool execution.
	 *
	 * @param int                  $automation_id        Monitor automation ID.
	 * @param array<string, mixed> $wake_context         Sanitized queue context.
	 * @param int                  $queue_wake_id        Claimed durable queue row ID.
	 * @param string               $queue_claimed_run_id Durable queue claim ID.
	 * @return array<string, mixed>|null Run result or null when the Monitor no longer exists.
	 */
	public static function run_monitor_wake( int $automation_id, array $wake_context, int $queue_wake_id = 0, string $queue_claimed_run_id = '' ): ?array {
		$wake_disposition = 'defer';
		$result           = self::run_internal( $automation_id, false, $wake_context, $wake_disposition, $queue_wake_id, $queue_claimed_run_id );
		if ( ! is_array( $result ) ) {
			return $result;
		}

		$result['_monitor_wake_disposition'] = $wake_disposition;

		return $result;
	}

	/**
	 * Execute an automation under the normal or narrowly authorized draft contract.
	 *
	 * @param int                  $automation_id              Automation ID.
	 * @param bool                 $allow_manual_monitor_draft Whether this request is the controller-authorized draft path.
	 * @param array<string, mixed> $wake_context               Sanitized event metadata for a queue wake.
	 * @param string|null          $wake_disposition           Safe queue disposition, when this is a queue wake.
	 * @param int                  $queue_wake_id              Claimed durable queue row ID.
	 * @param string               $queue_claimed_run_id       Durable queue claim ID.
	 * @return array<string, mixed>|null Run result or null when the automation does not exist.
	 */
	private static function run_internal( int $automation_id, bool $allow_manual_monitor_draft, array $wake_context = [], ?string &$wake_disposition = null, int $queue_wake_id = 0, string $queue_claimed_run_id = '' ): ?array {
		$is_monitor_wake = null !== $wake_disposition;
		if ( $is_monitor_wake ) {
			$wake_disposition = 'defer';
			$wake_context     = MonitorOutcome::sanitize_wake_context( $wake_context );
		}

		$automation = Automations::get( $automation_id );
		if ( ! $automation ) {
			if ( $is_monitor_wake ) {
				$wake_disposition = 'complete';
			}
			return null;
		}
		if ( $is_monitor_wake && ! self::is_authorized_monitor_wake( $automation, $wake_context ) ) {
			$wake_disposition = 'complete';
			return self::record_blocked_delivery(
				$automation,
				wp_generate_uuid4(),
				__( 'This Monitor wake is no longer authorized to run.', 'superdav-ai-agent' ),
				true,
				$wake_context
			);
		}

		$run_id = wp_generate_uuid4();
		if ( ! Database::has_transactional_automation_storage() ) {
			return self::record_blocked_delivery(
				$automation,
				$run_id,
				__( 'Automation lifecycle storage is unavailable and must be repaired before this automation can run.', 'superdav-ai-agent' ),
				$is_monitor_wake,
				$wake_context
			);
		}

		// Never attempt a correlated stale-state transition unless the two
		// lifecycle tables have been verified as transactional.
		self::recover_stale_runs();

		// Recovery can change lifecycle fields while another request can disable
		// or delete the automation, so use a fresh definition for this delivery.
		$automation = Automations::get( $automation_id );
		if ( ! $automation ) {
			if ( $is_monitor_wake ) {
				$wake_disposition = 'complete';
			}
			return null;
		}
		if ( $is_monitor_wake && ! self::is_authorized_monitor_wake( $automation, $wake_context ) ) {
			$wake_disposition = 'complete';
			return self::record_blocked_delivery(
				$automation,
				$run_id,
				__( 'This Monitor wake is no longer authorized to run.', 'superdav-ai-agent' ),
				true,
				$wake_context
			);
		}

		$is_manual_monitor_draft = $allow_manual_monitor_draft
			&& Automations::is_monitor( $automation )
			&& empty( $automation['enabled'] );

		if ( $allow_manual_monitor_draft && ! $is_manual_monitor_draft ) {
			return self::record_blocked_delivery(
				$automation,
				$run_id,
				__( 'Only a disabled Monitor draft can use the manual check path.', 'superdav-ai-agent' )
			);
		}

		if ( empty( $automation['enabled'] ) && ! $is_manual_monitor_draft ) {
			return self::record_blocked_delivery(
				$automation,
				$run_id,
				__( 'This automation is disabled and cannot run.', 'superdav-ai-agent' )
			);
		}

		$lease_expires_at = self::get_lease_expiration( $automation );
		$claim_state      = self::claim_run_with_lifecycle(
			$automation,
			$run_id,
			$lease_expires_at,
			$is_manual_monitor_draft,
			$is_monitor_wake,
			$wake_context
		);
		if ( 'failed' === $claim_state ) {
			return self::build_fallback_result(
				$automation,
				$run_id,
				'failed',
				__( 'The automation run could not be recorded before execution.', 'superdav-ai-agent' ),
				self::monitor_outcome_for_lifecycle( $automation, 'failed' )
			);
		}

		if ( 'contended' === $claim_state ) {
			return self::record_blocked_delivery(
				$automation,
				$run_id,
				__( 'Another scheduled delivery already owns this automation run.', 'superdav-ai-agent' ),
				$is_monitor_wake,
				$wake_context
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
			$is_monitor = Automations::is_monitor( $automation );
			if ( $is_monitor && ! MonitorOutcome::has_scratch( $automation ) ) {
				if ( $is_monitor_wake ) {
					$wake_disposition = 'complete';
				}
				$terminal_status = 'succeeded';
				$log_data        = self::finish_owned_run(
					$automation,
					$run_id,
					$terminal_status,
					[
						'duration_ms'     => (int) round( ( microtime( true ) - $start_time ) * 1000 ),
						'monitor_outcome' => 'quiet',
					]
				);
			} else {
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

				$prompt = $is_monitor ? MonitorOutcome::build_prompt( $automation, $wake_context ) : (string) $automation['prompt'];
				// @phpstan-ignore-next-line
				$loop = new AgentLoop( $prompt, [], [], $options );
				if ( $is_monitor_wake && ! MonitorWakeQueue::mark_provider_started( $queue_wake_id, $queue_claimed_run_id ) ) {
					return self::finish_owned_run(
						$automation,
						$run_id,
						'failed',
						[
							'error_message'   => __( 'The Monitor wake could not establish its pre-provider safety boundary.', 'superdav-ai-agent' ),
							'monitor_outcome' => 'error',
						]
					);
				}
				if ( $is_monitor_wake ) {
					$wake_disposition = 'complete';
				}
				$result = $loop->run();

				$duration = (int) round( ( microtime( true ) - $start_time ) * 1000 );
				if ( $result instanceof \WP_Error ) {
					$terminal_status = 'failed';
					$log_data        = self::finish_owned_run(
						$automation,
						$run_id,
						$terminal_status,
						[
							'duration_ms'     => $duration,
							'error_message'   => $result->get_error_message(),
							'monitor_outcome' => $is_monitor ? 'error' : '',
						]
					);
				} else {
					$token_usage = isset( $result['token_usage'] ) && is_array( $result['token_usage'] ) ? $result['token_usage'] : [];
					if ( $is_monitor ) {
						$reply           = isset( $result['reply'] ) && is_scalar( $result['reply'] ) ? (string) $result['reply'] : '';
						$monitor_outcome = MonitorOutcome::parse( $reply );
						$terminal_status = null === $monitor_outcome ? 'failed' : MonitorOutcome::lifecycle_status( $monitor_outcome['outcome'] );
						$summary         = null === $monitor_outcome ? '' : $monitor_outcome['summary'];
						$error_message   = null === $monitor_outcome
							? __( 'The Monitor returned an invalid structured outcome.', 'superdav-ai-agent' )
							: ( in_array( $monitor_outcome['outcome'], [ 'blocked', 'error' ], true ) ? $summary : '' );
						$log_data        = self::finish_owned_run(
							$automation,
							$run_id,
							$terminal_status,
							[
								'reply'             => $summary,
								'tool_calls'        => $result['tool_calls'] ?? [],
								'prompt_tokens'     => $token_usage['prompt'] ?? 0,
								'completion_tokens' => $token_usage['completion'] ?? 0,
								'duration_ms'       => $duration,
								'error_message'     => $error_message,
								'monitor_outcome'   => null === $monitor_outcome ? 'error' : $monitor_outcome['outcome'],
							]
						);
					} else {
						$terminal_status = 'succeeded';
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
				}
			}

			$monitor_outcome = isset( $log_data['monitor_outcome'] ) && is_scalar( $log_data['monitor_outcome'] ) ? (string) $log_data['monitor_outcome'] : '';
			if ( ! self::has_authoritative_terminal_state( $automation_id, $run_id, $terminal_status, $monitor_outcome ) ) {
				return $log_data;
			}

			// The run is already terminal and durable. A notification or hook failure
			// must not change the outcome reported to the initiating request.
			try {
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
			} catch ( \Throwable ) {
				// The durable terminal state remains authoritative.
			}

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
			self::sync_next_run_at( $automation_id );
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
	 * @param array<string, mixed> $automation      Automation definition.
	 * @param string               $run_id          Correlation UUID for the execution.
	 * @param string               $reason          Safe operator-facing block reason.
	 * @param bool                 $is_monitor_wake Whether this came from a Monitor event wake.
	 * @param array<string, mixed> $wake_context    Sanitized Monitor wake context.
	 * @return array<string, mixed>
	 */
	private static function record_blocked_delivery( array $automation, string $run_id, string $reason, bool $is_monitor_wake = false, array $wake_context = [] ): array {
		$monitor_outcome = self::monitor_outcome_for_lifecycle( $automation, 'blocked' );
		$log_id          = AutomationLogs::create(
			[
				'automation_id'    => (int) ( $automation['id'] ?? 0 ),
				'run_id'           => $run_id,
				'owner_user_id'    => (int) ( $automation['owner_user_id'] ?? 0 ),
				'trigger_type'     => $is_monitor_wake ? 'event' : 'scheduled',
				'trigger_name'     => $is_monitor_wake ? (string) ( $wake_context['source'] ?? '' ) : '',
				'status'           => 'error',
				'lifecycle_status' => 'blocked',
				'monitor_outcome'  => $monitor_outcome,
				'error_message'    => $reason,
			]
		);

		if ( false !== $log_id ) {
			$log = AutomationLogs::get( $log_id );
			if ( is_array( $log ) ) {
				return $log;
			}
		}

		return self::build_fallback_result( $automation, $run_id, 'blocked', $reason, $monitor_outcome );
	}

	/**
	 * Confirm a claimed wake still matches the current explicit Monitor consent.
	 *
	 * @param array<string, mixed> $automation  Current automation definition.
	 * @param array<string, mixed> $wake_context Sanitized wake context.
	 */
	private static function is_authorized_monitor_wake( array $automation, array $wake_context ): bool {
		$source = (string) ( $wake_context['source'] ?? '' );

		return Automations::is_monitor( $automation )
			&& ! empty( $automation['enabled'] )
			&& Automations::is_monitor_event_wakes_enabled( $automation )
			&& '' !== $source
			&& in_array( $source, Automations::get_monitor_event_sources( $automation ), true );
	}

	/**
	 * Atomically persist the claimed log and the matching automation claim.
	 *
	 * @param array<string, mixed> $automation              Automation definition.
	 * @param string               $run_id                  Correlation UUID for this delivery.
	 * @param string               $lease_expires_at        UTC MySQL expiration datetime.
	 * @param bool                 $is_manual_monitor_draft Whether this is one authorized disabled Monitor check.
	 * @param bool                 $is_monitor_wake         Whether this delivery came from a coalesced event wake.
	 * @param array<string, mixed> $wake_context            Sanitized event context for a Monitor wake.
	 * @return 'claimed'|'contended'|'failed'
	 */
	private static function claim_run_with_lifecycle(
		array $automation,
		string $run_id,
		string $lease_expires_at,
		bool $is_manual_monitor_draft = false,
		bool $is_monitor_wake = false,
		array $wake_context = []
	): string {
		$automation_id = (int) ( $automation['id'] ?? 0 );
		if ( $automation_id <= 0 || ! Automations::begin_lifecycle_transaction() ) {
			return 'failed';
		}
		$trigger_type = $is_manual_monitor_draft ? 'manual' : ( $is_monitor_wake ? 'event' : 'scheduled' );
		$trigger_name = $is_monitor_wake ? (string) ( $wake_context['source'] ?? '' ) : '';

		$log_id = AutomationLogs::create(
			[
				'automation_id'    => $automation_id,
				'run_id'           => $run_id,
				'owner_user_id'    => (int) ( $automation['owner_user_id'] ?? 0 ),
				'trigger_type'     => $trigger_type,
				'trigger_name'     => $trigger_name,
				'status'           => 'pending',
				'lifecycle_status' => 'claimed',
				'lease_expires_at' => $lease_expires_at,
			]
		);
		if ( false === $log_id ) {
			Automations::rollback_lifecycle_transaction();
			return 'failed';
		}

		$claimed = $is_manual_monitor_draft
			? self::claim_manual_monitor_draft_run( $automation_id, $run_id, $lease_expires_at )
			: Automations::claim_run( $automation_id, $run_id, $lease_expires_at );
		if ( ! $claimed ) {
			Automations::rollback_lifecycle_transaction();
			return 'contended';
		}

		if ( ! Automations::commit_lifecycle_transaction() ) {
			Automations::rollback_lifecycle_transaction();
			return 'failed';
		}

		return 'claimed';
	}

	/**
	 * Claim exactly one disabled Monitor draft without mutating its enabled state.
	 *
	 * The ordinary model claim intentionally requires enabled=1. This parallel
	 * predicate is scoped to the controller-authorized draft run and preserves the
	 * same active-run fence while also proving no recurring schedule was enabled.
	 *
	 * @param int    $automation_id    Automation ID.
	 * @param string $run_id           Correlation UUID for this delivery.
	 * @param string $lease_expires_at UTC MySQL expiration datetime.
	 */
	private static function claim_manual_monitor_draft_run( int $automation_id, string $run_id, string $lease_expires_at ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The lifecycle transaction atomically claims one disabled Monitor draft without changing its enabled state.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET active_run_id = %s, execution_status = 'claimed', lease_expires_at = %s, updated_at = %s WHERE id = %d AND enabled = 0 AND mode = %s AND active_run_id = ''",
				Automations::table_name(),
				$run_id,
				$lease_expires_at,
				$now,
				$automation_id,
				Automations::MONITOR_MODE
			)
		);

		return false !== $result && $result > 0;
	}

	/** Transition both claimed lifecycle rows to running together. */
	private static function mark_owned_run_running( int $automation_id, string $run_id ): bool {
		if ( ! Automations::begin_lifecycle_transaction() ) {
			return false;
		}

		$automation_running = Automations::mark_run_running( $automation_id, $run_id );
		$log_running        = AutomationLogs::mark_run_running( $run_id );
		if ( ! $automation_running || ! $log_running || ! Automations::commit_lifecycle_transaction() ) {
			Automations::rollback_lifecycle_transaction();
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
		$automation_id   = (int) ( $automation['id'] ?? 0 );
		$error           = isset( $data['error_message'] ) && is_scalar( $data['error_message'] ) ? (string) $data['error_message'] : '';
		$monitor_outcome = isset( $data['monitor_outcome'] ) && is_scalar( $data['monitor_outcome'] ) ? (string) $data['monitor_outcome'] : '';
		if ( ! MonitorOutcome::is_valid( $monitor_outcome ) ) {
			$monitor_outcome = self::monitor_outcome_for_lifecycle( $automation, $status );
		}
		if ( '' !== $monitor_outcome ) {
			$data['monitor_outcome'] = $monitor_outcome;
		}

		$monitor_summary = isset( $data['reply'] ) && is_scalar( $data['reply'] ) ? (string) $data['reply'] : '';

		if ( $automation_id <= 0 || ! Automations::begin_lifecycle_transaction() ) {
			return self::build_fallback_result( $automation, $run_id, $status, $error, $monitor_outcome );
		}

		$log_completed        = AutomationLogs::complete_run( $run_id, $status, $data );
		$automation_completed = Automations::finish_run( $automation_id, $run_id, $status, $error, $monitor_outcome, $monitor_summary );
		if ( ! $log_completed || ! $automation_completed || ! Automations::commit_lifecycle_transaction() ) {
			Automations::rollback_lifecycle_transaction();
			return self::build_fallback_result( $automation, $run_id, $status, $error, $monitor_outcome );
		}

		$log = AutomationLogs::get_by_run_id( $run_id );
		if ( is_array( $log ) ) {
			return $log;
		}

		return self::build_fallback_result( $automation, $run_id, $status, $error, $monitor_outcome );
	}

	/** Confirm that both durable rows hold the same terminal run outcome. */
	private static function has_authoritative_terminal_state( int $automation_id, string $run_id, string $status, string $monitor_outcome = '' ): bool {
		$automation = Automations::get( $automation_id );
		$log        = AutomationLogs::get_by_run_id( $run_id );

		$has_terminal_state = is_array( $automation )
			&& is_array( $log )
			&& '' === (string) ( $automation['active_run_id'] ?? '' )
			&& $run_id === (string) ( $automation['last_run_id'] ?? '' )
			&& $status === (string) ( $automation['last_run_status'] ?? '' )
			&& $status === (string) ( $log['lifecycle_status'] ?? '' );

		if ( ! $has_terminal_state || '' === $monitor_outcome ) {
			return $has_terminal_state;
		}

		return $monitor_outcome === (string) ( $automation['last_monitor_outcome'] ?? '' )
			&& $monitor_outcome === (string) ( $log['monitor_outcome'] ?? '' );
	}

	/**
	 * Provide a safe response when durable log retrieval fails.
	 *
	 * @param array<string, mixed> $automation Automation definition.
	 * @param string               $run_id     Correlation UUID for the execution.
	 * @param string               $status     Lifecycle status.
	 * @param string               $error            Safe operator-facing error detail.
	 * @param string               $monitor_outcome  Valid Monitor outcome when known.
	 * @return array<string, mixed>
	 */
	private static function build_fallback_result( array $automation, string $run_id, string $status, string $error, string $monitor_outcome = '' ): array {
		return [
			'automation_id'     => (int) ( $automation['id'] ?? 0 ),
			'run_id'            => $run_id,
			'owner_user_id'     => (int) ( $automation['owner_user_id'] ?? 0 ),
			'status'            => 'succeeded' === $status ? 'success' : 'error',
			'lifecycle_status'  => $status,
			'monitor_outcome'   => MonitorOutcome::is_valid( $monitor_outcome ) ? $monitor_outcome : '',
			'reply'             => '',
			'tool_calls'        => [],
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'duration_ms'       => 0,
			'error_message'     => $error,
		];
	}

	/**
	 * Derive a safe Monitor outcome when execution stops before model parsing.
	 *
	 * @param array<string, mixed> $automation Automation definition.
	 */
	private static function monitor_outcome_for_lifecycle( array $automation, string $status ): string {
		if ( ! Automations::is_monitor( $automation ) ) {
			return '';
		}

		if ( 'blocked' === $status ) {
			return 'blocked';
		}

		return in_array( $status, [ 'failed', 'abandoned' ], true ) ? 'error' : '';
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
