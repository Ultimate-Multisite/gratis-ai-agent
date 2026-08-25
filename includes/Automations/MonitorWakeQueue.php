<?php

declare(strict_types=1);
/**
 * Monitor Wake Queue — bounded, durable event-wake coalescing for Monitors.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Automations;

use SdAiAgent\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MonitorWakeQueue {

	public const CRON_HOOK         = 'sd_ai_agent_process_monitor_wakes';
	public const CLEANUP_CRON_HOOK = 'sd_ai_agent_clear_monitor_wakes';

	/** Coalesce a burst before one assessment is admitted. */
	public const COALESCE_WINDOW_SECONDS = MINUTE_IN_SECONDS;

	/** Retain event evidence for at most one day. */
	public const MAX_PENDING_AGE_SECONDS = DAY_IN_SECONDS;

	/** Do not retain more than this many deliveries in one group. */
	public const MAX_EVENTS_PER_GROUP = 50;

	/** Bound retained source groups across one Monitor. */
	public const MAX_OPEN_GROUPS_PER_MONITOR = 8;

	/** Retry only safe pre-provider blocks a bounded number of times. */
	public const MAX_RETRY_ATTEMPTS = 3;

	/** Keep distinct Monitor event runs apart after a terminal assessment. */
	public const COOLDOWN_SECONDS = 300;

	/** Bound one cron invocation to avoid cross-Monitor fan-out. */
	private const MAX_WAKES_PER_CRON = 3;

	/** Wait briefly for an in-flight capture before removing retained wake data. */
	private const CLEANUP_LOCK_TIMEOUT_SECONDS = 5;

	/** Recover a process that stops before it reaches the provider boundary. */
	private const CLAIM_LEASE_SECONDS = HOUR_IN_SECONDS;

	/** Retain expired diagnostic rows briefly without building an unbounded backlog. */
	private const EXPIRED_RETENTION_SECONDS = WEEK_IN_SECONDS;

	/** @var bool Prevent hook recursion caused by a Monitor's own tool work. */
	private static bool $processing = false;

	/** Return the durable queue table name. */
	public static function table_name(): string {
		return Database::monitor_wakes_table_name();
	}

	/**
	 * Capture one registered event for every explicitly opted-in enabled Monitor.
	 *
	 * @param string $source WordPress action name.
	 * @param array  $hook_args Action arguments.
	 * @phpstan-param list<mixed> $hook_args
	 */
	public static function capture( string $source, array $hook_args ): void {
		if ( self::$processing ) {
			return;
		}

		$summary = EventTriggerRegistry::summarize_monitor_wake( $source, $hook_args );
		if ( null === $summary || ! Database::has_transactional_monitor_wake_storage() ) {
			return;
		}

		foreach ( Automations::list_monitor_wake_subscribers( $source ) as $monitor ) {
			self::upsert_pending_wake( $monitor, $source, $summary );
		}
	}

	/**
	 * Return approved source names that need WordPress hook registration.
	 *
	 * @return array<string, int>
	 */
	public static function get_enabled_source_hooks(): array {
		$hooks = [];
		foreach ( EventTriggerRegistry::get_monitor_wake_sources() as $source ) {
			$hook_name = (string) ( $source['hook_name'] ?? '' );
			if ( '' === $hook_name || empty( Automations::list_monitor_wake_subscribers( $hook_name ) ) ) {
				continue;
			}

			$hooks[ $hook_name ] = EventTriggerRegistry::get_monitor_wake_hook_arg_count( $hook_name );
		}

		return $hooks;
	}

	/**
	 * Process a bounded number of due groups through the normal Monitor runner.
	 */
	public static function process_due_wakes(): void {
		if ( self::$processing || ! Database::has_transactional_monitor_wake_storage() ) {
			return;
		}

		self::$processing = true;
		try {
			self::expire_stale_wakes();
			foreach ( self::get_due_wakes() as $wake ) {
				$claimed = self::claim_wake( $wake );
				if ( null === $claimed ) {
					continue;
				}

				$monitor = Automations::get( (int) $claimed['monitor_id'] );
				if ( ! self::is_current_subscription( $monitor, (string) $claimed['source'] ) ) {
					self::complete_claimed_wake( $claimed, false );
					continue;
				}

				$result      = AutomationRunner::run_monitor_wake(
					(int) $claimed['monitor_id'],
					self::build_runner_context( $claimed ),
					(int) $claimed['id'],
					(string) $claimed['claimed_run_id']
				);
				$disposition = is_array( $result ) ? (string) ( $result['_monitor_wake_disposition'] ?? 'complete' ) : 'complete';
				if ( 'defer' === $disposition ) {
					self::defer_claimed_wake( $claimed );
				} else {
					self::complete_claimed_wake( $claimed, true );
				}
			}
		} finally {
			self::$processing = false;
			self::schedule_next_due_wake();
		}
	}

	/**
	 * Return non-sensitive queue status for an inspectable Monitor response.
	 *
	 * @return array{pending_groups:int,pending_events:int,deferred_groups:int,claimed_groups:int,expired_groups:int}
	 */
	public static function get_status_for_monitor( int $monitor_id ): array {
		$status = [
			'pending_groups'  => 0,
			'pending_events'  => 0,
			'deferred_groups' => 0,
			'claimed_groups'  => 0,
			'expired_groups'  => 0,
		];
		if ( $monitor_id <= 0 || ! Database::has_transactional_monitor_wake_storage() ) {
			return $status;
		}

		global $wpdb;
		/** @var \wpdb $wpdb */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compact per-Monitor operational queue status has no cache-safe global lifetime.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM( CASE WHEN status = 'pending' THEN 1 ELSE 0 END ) AS pending_groups,
					SUM( CASE WHEN status = 'pending' THEN event_count ELSE 0 END ) AS pending_events,
					SUM( CASE WHEN status = 'deferred' THEN 1 ELSE 0 END ) AS deferred_groups,
					SUM( CASE WHEN status = 'claimed' THEN 1 ELSE 0 END ) AS claimed_groups,
					SUM( CASE WHEN status = 'expired' THEN 1 ELSE 0 END ) AS expired_groups
				FROM %i WHERE monitor_id = %d",
				self::table_name(),
				$monitor_id
			)
		);
		if ( ! is_object( $row ) ) {
			return $status;
		}

		foreach ( array_keys( $status ) as $key ) {
			$status[ $key ] = (int) ( $row->{$key} ?? 0 );
		}

		return $status;
	}

	/** Remove all pending, deferred, claimed, or expired wakes for one Monitor. */
	public static function clear_for_monitor( int $monitor_id ): void {
		if ( $monitor_id <= 0 ) {
			return;
		}

		$lock_name = self::acquire_monitor_lock( $monitor_id, self::CLEANUP_LOCK_TIMEOUT_SECONDS );
		if ( null === $lock_name ) {
			self::schedule_cleanup_retry( $monitor_id );
			return;
		}

		global $wpdb;
		/** @var \wpdb $wpdb */
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Disabling or deleting a Monitor must immediately remove its retained wake evidence, including when new capture is fail-closed for non-transactional storage.
			$wpdb->delete( self::table_name(), [ 'monitor_id' => $monitor_id ], [ '%d' ] );
		} finally {
			self::release_monitor_lock( $lock_name );
		}
		wp_clear_scheduled_hook( self::CLEANUP_CRON_HOOK, [ $monitor_id ] );

		if ( Database::has_transactional_monitor_wake_storage() ) {
			self::schedule_next_due_wake();
		} else {
			self::unschedule_processing();
		}
	}

	/** Retry consent-revocation cleanup after an in-flight capture releases its lock. */
	public static function retry_clear_for_monitor( int $monitor_id ): void {
		self::clear_for_monitor( $monitor_id );
	}

	/** Restore processing for durable wakes retained while the plugin was inactive. */
	public static function reschedule_pending_wakes(): void {
		if ( ! Database::has_transactional_monitor_wake_storage() ) {
			self::unschedule_processing();
			return;
		}

		self::expire_stale_wakes();
		self::schedule_next_due_wake();
	}

	/** Remove the global processor without discarding durable queue rows. */
	public static function unschedule_processing(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Upsert one source-specific pending group and preserve only its latest safe identifiers.
	 *
	 * @param array<string, mixed> $monitor Monitor definition.
	 * @param string               $source  Approved source name.
	 * @param array<string, mixed> $summary Safe summary with approved scalar identifiers.
	 */
	private static function upsert_pending_wake( array $monitor, string $source, array $summary ): void {
		$monitor_id = (int) ( $monitor['id'] ?? 0 );
		if ( $monitor_id <= 0 ) {
			return;
		}

		$lock_name = self::acquire_monitor_lock( $monitor_id );
		if ( null === $lock_name ) {
			Automations::record_monitor_wake_drop( $monitor_id );
			return;
		}

		$scheduled_at = 0;
		try {
			$current_monitor = Automations::get( $monitor_id );
			if ( ! is_array( $current_monitor ) || ! self::is_current_subscription( $current_monitor, $source ) ) {
				return;
			}

			$existing = self::get_pending_wake( $monitor_id, $source );
			if ( null === $existing && self::count_open_groups( $monitor_id ) >= self::MAX_OPEN_GROUPS_PER_MONITOR ) {
				Automations::record_monitor_wake_drop( $monitor_id );
				return;
			}

			$now          = current_time( 'mysql', true );
			$available_at = self::get_available_at( $current_monitor );
			$expires_at   = gmdate( 'Y-m-d H:i:s', time() + self::MAX_PENDING_AGE_SECONDS );
			$encoded      = wp_json_encode( $summary );
			$encoded      = is_string( $encoded ) ? substr( $encoded, 0, 1024 ) : '{}';
			if ( null !== $existing && (int) $existing['event_count'] >= self::MAX_EVENTS_PER_GROUP ) {
				Automations::record_monitor_wake_drop( $monitor_id );
			}

			global $wpdb;
			/** @var \wpdb $wpdb */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A unique pending state key atomically coalesces one bounded Monitor/source group while the per-Monitor admission lock enforces its group cap.
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO %i (monitor_id, source, state_key, status, event_summary, event_count, dropped_count, deferred_count, attempt_count, available_at, lease_expires_at, claimed_run_id, provider_started_at, first_seen_at, last_seen_at, expires_at, created_at, updated_at)
					VALUES (%d, %s, 'pending', 'pending', %s, 1, 0, 0, 0, %s, NULL, '', NULL, %s, %s, %s, %s, %s)
					ON DUPLICATE KEY UPDATE
						dropped_count = dropped_count + IF(event_count >= %d, 1, 0),
						event_count = LEAST(event_count + 1, %d),
						event_summary = VALUES(event_summary),
						last_seen_at = VALUES(last_seen_at),
						updated_at = VALUES(updated_at)",
					self::table_name(),
					$monitor_id,
					$source,
					$encoded,
					$available_at,
					$now,
					$now,
					$expires_at,
					$now,
					$now,
					self::MAX_EVENTS_PER_GROUP,
					self::MAX_EVENTS_PER_GROUP
				)
			);
			if ( false !== $result ) {
				$scheduled_at = null === $existing
					? ( strtotime( $available_at . ' UTC' ) ?: time() )
					: ( strtotime( (string) $existing['available_at'] . ' UTC' ) ?: time() );
			}
		} finally {
			self::release_monitor_lock( $lock_name );
		}

		if ( $scheduled_at > 0 ) {
			self::schedule_processing_at( $scheduled_at );
		}
	}

	/**
	 * Return the oldest due queue groups, bounded to one sequential cron request.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function get_due_wakes(): array {
		global $wpdb;
		/** @var \wpdb $wpdb */
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Due queue rows require an atomic claim immediately after this bounded read.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE status IN ('pending', 'deferred') AND available_at <= %s AND expires_at > %s ORDER BY first_seen_at ASC LIMIT %d",
				self::table_name(),
				$now,
				$now,
				self::MAX_WAKES_PER_CRON
			)
		);

		return array_map( [ __CLASS__, 'decode_wake' ], $rows ?: [] );
	}

	/**
	 * Atomically move a due wake out of its reusable pending/deferred state.
	 *
	 * @param array<string, mixed> $wake Due wake.
	 * @return array<string, mixed>|null Claimed wake or null when another worker won.
	 */
	private static function claim_wake( array $wake ): ?array {
		$wake_id   = (int) ( $wake['id'] ?? 0 );
		$state_key = (string) ( $wake['state_key'] ?? '' );
		if ( $wake_id <= 0 || '' === $state_key ) {
			return null;
		}

		$run_id = wp_generate_uuid4();
		$now    = current_time( 'mysql', true );
		// The provider boundary is persisted separately. Before that boundary, a
		// short lease makes a stopped worker safely recoverable within the wake's
		// one-day retention period.
		$lease     = gmdate( 'Y-m-d H:i:s', time() + self::CLAIM_LEASE_SECONDS );
		$claim_key = 'claimed:' . $run_id;
		self::schedule_processing_at( strtotime( $lease . ' UTC' ) ?: time() + self::CLAIM_LEASE_SECONDS );

		global $wpdb;
		/** @var \wpdb $wpdb */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional state-key update gives exactly one worker a durable wake claim.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'claimed', state_key = %s, claimed_run_id = %s, provider_started_at = NULL, lease_expires_at = %s, updated_at = %s WHERE id = %d AND state_key = %s AND status IN ('pending', 'deferred') AND available_at <= %s",
				self::table_name(),
				$claim_key,
				$run_id,
				$lease,
				$now,
				$wake_id,
				$state_key,
				$now
			)
		);
		if ( 1 !== (int) $result ) {
			return null;
		}

		return self::get_wake( $wake_id );
	}

	/**
	 * Persist the no-replay boundary immediately before provider execution starts.
	 *
	 * @param int    $wake_id         Claimed queue row ID.
	 * @param string $claimed_run_id  Durable queue claim ID.
	 */
	public static function mark_provider_started( int $wake_id, string $claimed_run_id ): bool {
		if ( $wake_id <= 0 || '' === $claimed_run_id || ! Database::has_transactional_monitor_wake_storage() ) {
			return false;
		}

		global $wpdb;
		/** @var \wpdb $wpdb */
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Persisting this boundary prevents an uncertain provider call from being replayed after a process crash.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET provider_started_at = %s, updated_at = %s WHERE id = %d AND status = 'claimed' AND claimed_run_id = %s",
				self::table_name(),
				$now,
				$now,
				$wake_id,
				$claimed_run_id
			)
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Defer a claim only when the runner proves provider work never started.
	 *
	 * @param array<string, mixed> $wake Claimed wake.
	 */
	private static function defer_claimed_wake( array $wake ): void {
		$wake_id     = (int) ( $wake['id'] ?? 0 );
		$monitor_id  = (int) ( $wake['monitor_id'] ?? 0 );
		$claimed_run = (string) ( $wake['claimed_run_id'] ?? '' );
		$attempts    = (int) ( $wake['attempt_count'] ?? 0 ) + 1;
		$expires_at  = strtotime( (string) ( $wake['expires_at'] ?? '' ) . ' UTC' ) ?: 0;
		if ( $wake_id <= 0 || '' === $claimed_run || $attempts > self::MAX_RETRY_ATTEMPTS || ( $expires_at > 0 && $expires_at <= time() ) ) {
			self::expire_claimed_wake( $wake );
			return;
		}

		$delay     = min( HOUR_IN_SECONDS, MINUTE_IN_SECONDS * ( 2 ** $attempts ) );
		$available = gmdate( 'Y-m-d H:i:s', time() + $delay );
		$state_key = 'deferred:' . $wake_id;
		$now       = current_time( 'mysql', true );

		global $wpdb;
		/** @var \wpdb $wpdb */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A claimed wake retains its own state key so newer events can continue coalescing separately.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'deferred', state_key = %s, claimed_run_id = '', provider_started_at = NULL, lease_expires_at = NULL, deferred_count = deferred_count + 1, attempt_count = %d, available_at = %s, updated_at = %s WHERE id = %d AND status = 'claimed' AND claimed_run_id = %s",
				self::table_name(),
				$state_key,
				$attempts,
				$available,
				$now,
				$wake_id,
				$claimed_run
			)
		);
		if ( 1 === (int) $result ) {
			Automations::record_monitor_wake_deferral( $monitor_id );
			self::schedule_processing_at( strtotime( $available . ' UTC' ) ?: time() + $delay );
		}
	}

	/**
	 * Delete one terminal wake and start a cooldown only after a real assessment attempt.
	 *
	 * @param array<string, mixed> $wake      Claimed wake.
	 * @param bool                 $cool_down Whether the runner was invoked.
	 */
	private static function complete_claimed_wake( array $wake, bool $cool_down ): void {
		$wake_id     = (int) ( $wake['id'] ?? 0 );
		$monitor_id  = (int) ( $wake['monitor_id'] ?? 0 );
		$claimed_run = (string) ( $wake['claimed_run_id'] ?? '' );
		if ( $wake_id <= 0 || '' === $claimed_run ) {
			return;
		}

		global $wpdb;
		/** @var \wpdb $wpdb */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A terminal claimed wake is safe to remove only for its owning run ID.
		$result = $wpdb->delete(
			self::table_name(),
			[
				'id'             => $wake_id,
				'status'         => 'claimed',
				'claimed_run_id' => $claimed_run,
			],
			[ '%d', '%s', '%s' ]
		);
		if ( $cool_down && (int) $result > 0 ) {
			$cooldown_until = gmdate( 'Y-m-d H:i:s', time() + self::COOLDOWN_SECONDS );
			Automations::set_monitor_wake_cooldown( $monitor_id, self::COOLDOWN_SECONDS );
			self::apply_monitor_cooldown( $monitor_id, $cooldown_until );
		}
	}

	/**
	 * Expire an unsafe or exhausted wake instead of replaying unknown work.
	 *
	 * @param array<string, mixed> $wake Claimed wake.
	 */
	private static function expire_claimed_wake( array $wake ): void {
		$wake_id     = (int) ( $wake['id'] ?? 0 );
		$claimed_run = (string) ( $wake['claimed_run_id'] ?? '' );
		if ( $wake_id <= 0 || '' === $claimed_run ) {
			return;
		}

		global $wpdb;
		/** @var \wpdb $wpdb */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Expired claims remain inspectable briefly but never replay unknown consequential work.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'expired', state_key = %s, claimed_run_id = '', lease_expires_at = NULL, updated_at = %s WHERE id = %d AND status = 'claimed' AND claimed_run_id = %s",
				self::table_name(),
				'expired:' . $wake_id,
				current_time( 'mysql', true ),
				$wake_id,
				$claimed_run
			)
		);
	}

	/** Recover expired pre-provider claims and expire all other over-age work. */
	private static function expire_stale_wakes(): void {
		global $wpdb;
		/** @var \wpdb $wpdb */
		$now = current_time( 'mysql', true );
		foreach ( self::get_expired_unstarted_claims( $now ) as $wake ) {
			self::defer_claimed_wake( $wake );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Expiry is a bounded, terminal queue state transition.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'expired', state_key = CONCAT('expired:', id), claimed_run_id = '', lease_expires_at = NULL, updated_at = %s WHERE (status IN ('pending', 'deferred') AND expires_at <= %s) OR (status = 'claimed' AND provider_started_at IS NOT NULL AND lease_expires_at IS NOT NULL AND lease_expires_at <= %s)",
				self::table_name(),
				$now,
				$now,
				$now
			)
		);

		$cleanup_before = gmdate( 'Y-m-d H:i:s', time() - self::EXPIRED_RETENTION_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded retention prevents terminal diagnostic queue rows becoming a backlog.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE status = 'expired' AND updated_at < %s",
				self::table_name(),
				$cleanup_before
			)
		);
	}

	/**
	 * Return claimed wakes that crashed before the persisted provider boundary.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function get_expired_unstarted_claims( string $now ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Only pre-provider claims are safe to recover, and the bounded batch avoids cron fan-out.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE status = 'claimed' AND provider_started_at IS NULL AND lease_expires_at IS NOT NULL AND lease_expires_at <= %s ORDER BY id ASC LIMIT %d",
				self::table_name(),
				$now,
				self::MAX_WAKES_PER_CRON
			)
		);

		return array_map( [ __CLASS__, 'decode_wake' ], $rows ?: [] );
	}

	/**
	 * Return sanitized context passed into the Monitor prompt builder.
	 *
	 * @param array<string, mixed> $wake Claimed queue row.
	 * @return array<string, mixed>
	 */
	private static function build_runner_context( array $wake ): array {
		$source  = (string) ( $wake['source'] ?? '' );
		$decoded = json_decode( (string) ( $wake['event_summary'] ?? '' ), true );
		$summary = is_array( $decoded ) ? $decoded : [];
		$raw_ids = isset( $summary['identifiers'] ) && is_array( $summary['identifiers'] ) ? $summary['identifiers'] : [];

		return [
			'source'      => $source,
			'event_count' => min( self::MAX_EVENTS_PER_GROUP, max( 1, (int) ( $wake['event_count'] ?? 1 ) ) ),
			'identifiers' => EventTriggerRegistry::sanitize_monitor_wake_identifiers( $source, $raw_ids ),
		];
	}

	/**
	 * Return whether a current Monitor still authorizes the claimed event source.
	 *
	 * @param array<string, mixed>|null $monitor Current definition.
	 */
	private static function is_current_subscription( ?array $monitor, string $source ): bool {
		return is_array( $monitor )
			&& Automations::is_monitor( $monitor )
			&& ! empty( $monitor['enabled'] )
			&& Automations::is_monitor_event_wakes_enabled( $monitor )
			&& in_array( $source, Automations::get_monitor_event_sources( $monitor ), true );
	}

	/**
	 * Return one pending group for a Monitor/source pair.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function get_pending_wake( int $monitor_id, string $source ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The unique pending key is inspected only to account for bounded drops.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE monitor_id = %d AND source = %s AND state_key = 'pending'",
				self::table_name(),
				$monitor_id,
				$source
			)
		);

		return is_object( $row ) ? self::decode_wake( $row ) : null;
	}

	/**
	 * Return one queue row by ID.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function get_wake( int $wake_id ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The row is reread after a successful conditional claim.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table_name(), $wake_id ) );

		return is_object( $row ) ? self::decode_wake( $row ) : null;
	}

	/** Return the current bounded count of retained groups for one Monitor. */
	private static function count_open_groups( int $monitor_id ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The queue bound is checked immediately before an atomic pending upsert.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE monitor_id = %d AND status IN ('pending', 'deferred', 'claimed')",
				self::table_name(),
				$monitor_id
			)
		);

		return (int) $count;
	}

	/** Acquire a short per-Monitor admission lock around group-count and upsert work. */
	private static function acquire_monitor_lock( int $monitor_id, int $timeout = 0 ): ?string {
		if ( $monitor_id <= 0 ) {
			return null;
		}

		global $wpdb;
		/** @var \wpdb $wpdb */
		$lock_name = 'sd_ai_agent_monitor_wake_' . substr( hash( 'sha256', self::table_name() . ':' . $monitor_id ), 0, 32 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A short advisory lock serializes per-Monitor queue admission and cleanup across web heads.
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, max( 0, $timeout ) ) );

		return 1 === (int) $acquired ? $lock_name : null;
	}

	/** Release a per-Monitor queue-admission lock. */
	private static function release_monitor_lock( string $lock_name ): void {
		if ( '' === $lock_name ) {
			return;
		}

		global $wpdb;
		/** @var \wpdb $wpdb */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the short-lived admission lock acquired for this Monitor.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
	}

	/** Hold other retained groups behind a completed Monitor's bounded cooldown. */
	private static function apply_monitor_cooldown( int $monitor_id, string $cooldown_until ): void {
		if ( $monitor_id <= 0 || '' === $cooldown_until ) {
			return;
		}

		global $wpdb;
		/** @var \wpdb $wpdb */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prevents a second retained source group from immediately bypassing the completed Monitor's cooldown.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET available_at = %s, updated_at = %s WHERE monitor_id = %d AND status IN ('pending', 'deferred') AND available_at < %s",
				self::table_name(),
				$cooldown_until,
				current_time( 'mysql', true ),
				$monitor_id,
				$cooldown_until
			)
		);
	}

	/**
	 * Return a bounded availability time that honours the last completed wake cooldown.
	 *
	 * @param array<string, mixed> $monitor Current Monitor definition.
	 */
	private static function get_available_at( array $monitor ): string {
		$available_at = time() + self::COALESCE_WINDOW_SECONDS;
		$cooldown     = (string) ( $monitor['monitor_wake_cooldown_until'] ?? '' );
		$cooldown_at  = '' === $cooldown ? 0 : ( strtotime( $cooldown . ' UTC' ) ?: 0 );

		return gmdate( 'Y-m-d H:i:s', max( $available_at, $cooldown_at ) );
	}

	/** Schedule one global processor at the earliest known pending group time. */
	private static function schedule_processing_at( int $timestamp ): void {
		$timestamp = max( time() + 1, $timestamp );
		$current   = wp_next_scheduled( self::CRON_HOOK );
		if ( false !== $current && $current <= $timestamp ) {
			return;
		}

		if ( false !== $current ) {
			wp_unschedule_event( $current, self::CRON_HOOK );
		}

		wp_schedule_single_event( $timestamp, self::CRON_HOOK );
	}

	/** Schedule one idempotent cleanup retry for retained evidence. */
	private static function schedule_cleanup_retry( int $monitor_id ): void {
		if ( $monitor_id <= 0 || wp_next_scheduled( self::CLEANUP_CRON_HOOK, [ $monitor_id ] ) ) {
			return;
		}

		wp_schedule_single_event( time() + self::CLEANUP_LOCK_TIMEOUT_SECONDS, self::CLEANUP_CRON_HOOK, [ $monitor_id ] );
	}

	/** Schedule the next processor invocation for retained or recoverable work. */
	private static function schedule_next_due_wake(): void {
		global $wpdb;
		/** @var \wpdb $wpdb */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads one bounded operational timestamp to schedule the next shared processor.
		$next = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(CASE WHEN status IN ('pending', 'deferred') THEN available_at WHEN status = 'claimed' THEN lease_expires_at ELSE NULL END) FROM %i WHERE status IN ('pending', 'deferred') OR (status = 'claimed' AND lease_expires_at IS NOT NULL)",
				self::table_name()
			)
		);
		if ( ! is_string( $next ) || '' === $next ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return;
		}

		self::schedule_processing_at( strtotime( $next . ' UTC' ) ?: time() + self::COALESCE_WINDOW_SECONDS );
	}

	/**
	 * Decode a queue row without exposing raw hook arguments outside this class.
	 *
	 * @return array<string, mixed>
	 */
	private static function decode_wake( object $row ): array {
		return [
			'id'                  => (int) ( $row->id ?? 0 ),
			'monitor_id'          => (int) ( $row->monitor_id ?? 0 ),
			'source'              => (string) ( $row->source ?? '' ),
			'state_key'           => (string) ( $row->state_key ?? '' ),
			'status'              => (string) ( $row->status ?? '' ),
			'event_summary'       => (string) ( $row->event_summary ?? '' ),
			'event_count'         => (int) ( $row->event_count ?? 0 ),
			'dropped_count'       => (int) ( $row->dropped_count ?? 0 ),
			'deferred_count'      => (int) ( $row->deferred_count ?? 0 ),
			'attempt_count'       => (int) ( $row->attempt_count ?? 0 ),
			'available_at'        => (string) ( $row->available_at ?? '' ),
			'lease_expires_at'    => $row->lease_expires_at ?? null,
			'claimed_run_id'      => (string) ( $row->claimed_run_id ?? '' ),
			'provider_started_at' => $row->provider_started_at ?? null,
			'first_seen_at'       => (string) ( $row->first_seen_at ?? '' ),
			'last_seen_at'        => (string) ( $row->last_seen_at ?? '' ),
			'expires_at'          => (string) ( $row->expires_at ?? '' ),
		];
	}
}
