<?php

declare(strict_types=1);
/**
 * Automation Logs — execution history for scheduled and event-driven automations.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Automations;

use SdAiAgent\Core\DurablePlanTextSanitizer;
use SdAiAgent\Core\JobErrorSanitizer;

class AutomationLogs {

	/** @var list<string> Lifecycle statuses that may still be claimed by a worker. */
	const ACTIVE_LIFECYCLE_STATUSES = [ 'claimed', 'running' ];

	/** @var list<string> Lifecycle statuses that are terminal and inspectable. */
	const TERMINAL_LIFECYCLE_STATUSES = [ 'succeeded', 'failed', 'blocked', 'abandoned' ];

	/**
	 * Get the logs table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_automation_logs';
	}

	/**
	 * Create a log entry.
	 *
	 * @param array<string, mixed> $data Log data.
	 * @return int|false Inserted ID or false.
	 */
	public static function create( array $data ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$now              = current_time( 'mysql', true );
		$status           = sanitize_key( (string) ( $data['status'] ?? 'success' ) );
		$lifecycle_status = sanitize_key( (string) ( $data['lifecycle_status'] ?? self::lifecycle_for_legacy_status( $status ) ) );
		$started_at       = self::sanitize_datetime( $data['started_at'] ?? $now );
		$finished_at      = self::sanitize_datetime( $data['finished_at'] ?? null );

		if ( null === $finished_at && in_array( $lifecycle_status, self::TERMINAL_LIFECYCLE_STATUSES, true ) ) {
			$finished_at = $now;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query; caching not applicable.
		$result = $wpdb->insert(
			self::table_name(),
			[
				// @phpstan-ignore-next-line
				'automation_id'     => absint( $data['automation_id'] ?? 0 ),
				// @phpstan-ignore-next-line
				'run_id'            => sanitize_text_field( $data['run_id'] ?? '' ),
				// @phpstan-ignore-next-line
				'owner_user_id'     => absint( $data['owner_user_id'] ?? 0 ),
				// @phpstan-ignore-next-line
				'trigger_type'      => sanitize_text_field( $data['trigger_type'] ?? 'scheduled' ),
				// @phpstan-ignore-next-line
				'trigger_name'      => sanitize_text_field( $data['trigger_name'] ?? '' ),
				'status'            => $status,
				'lifecycle_status'  => $lifecycle_status,
				'reply'             => self::sanitize_reply( $data['reply'] ?? '' ),
				'tool_calls'        => wp_json_encode( self::summarize_tool_calls( $data['tool_calls'] ?? [] ) ),
				// @phpstan-ignore-next-line
				'prompt_tokens'     => absint( $data['prompt_tokens'] ?? 0 ),
				// @phpstan-ignore-next-line
				'completion_tokens' => absint( $data['completion_tokens'] ?? 0 ),
				// @phpstan-ignore-next-line
				'duration_ms'       => absint( $data['duration_ms'] ?? 0 ),
				'error_message'     => self::sanitize_error( $data['error_message'] ?? '' ),
				'lease_expires_at'  => self::sanitize_datetime( $data['lease_expires_at'] ?? null ),
				'started_at'        => $started_at,
				'finished_at'       => $finished_at,
				'created_at'        => $now,
			],
			[ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Transition a claimed log record to running.
	 *
	 * @param string $run_id Correlation UUID for this delivery.
	 * @return bool True when the row was transitioned by its owner.
	 */
	public static function mark_run_running( string $run_id ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional lifecycle transition prevents stale workers from rewriting terminal evidence.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET lifecycle_status = 'running' WHERE run_id = %s AND lifecycle_status = 'claimed'",
				self::table_name(),
				$run_id
			)
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Complete a claimed or running record with scrubbed terminal evidence.
	 *
	 * @param string               $run_id           Correlation UUID for this delivery.
	 * @param string               $lifecycle_status Terminal lifecycle status.
	 * @param array<string, mixed> $data             Safe completion details.
	 * @return bool True when the active row was completed.
	 */
	public static function complete_run( string $run_id, string $lifecycle_status, array $data = [] ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( ! in_array( $lifecycle_status, self::TERMINAL_LIFECYCLE_STATUSES, true ) ) {
			return false;
		}

		$now    = current_time( 'mysql', true );
		$status = 'succeeded' === $lifecycle_status ? 'success' : 'error';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Terminal update is guarded so an expired worker cannot overwrite abandoned evidence.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, lifecycle_status = %s, reply = %s, tool_calls = %s, prompt_tokens = %d, completion_tokens = %d, duration_ms = %d, error_message = %s, lease_expires_at = NULL, finished_at = %s WHERE run_id = %s AND lifecycle_status IN ('claimed', 'running')",
				self::table_name(),
				$status,
				$lifecycle_status,
				self::sanitize_reply( $data['reply'] ?? '' ),
				wp_json_encode( self::summarize_tool_calls( $data['tool_calls'] ?? [] ) ),
				absint( $data['prompt_tokens'] ?? 0 ),
				absint( $data['completion_tokens'] ?? 0 ),
				absint( $data['duration_ms'] ?? 0 ),
				self::sanitize_error( $data['error_message'] ?? '' ),
				$now,
				$run_id
			)
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Mark one matching expired run record abandoned.
	 *
	 * This low-level conditional transition is called inside the matching
	 * automation-row transaction so a stale recovery never leaves mismatched
	 * lifecycle evidence across the two durable tables.
	 */
	public static function abandon_run( int $automation_id, string $run_id, string $reason ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( $automation_id <= 0 || '' === $run_id ) {
			return false;
		}

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional stale transition is coordinated transactionally with the owning automation row.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'error', lifecycle_status = 'abandoned', error_message = %s, lease_expires_at = NULL, finished_at = %s WHERE automation_id = %d AND run_id = %s AND lifecycle_status IN ('claimed', 'running') AND lease_expires_at IS NOT NULL AND lease_expires_at < %s",
				self::table_name(),
				self::sanitize_error( $reason ),
				$now,
				$automation_id,
				$run_id,
				$now
			)
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Mark expired active logs abandoned only when they have no active matching
	 * automation claim.
	 *
	 * Matching automation/log pairs are transitioned transactionally by
	 * Automations::abandon_expired_runs(). This orphan cleanup covers only an
	 * interrupted legacy or pre-claim log write without risking divergent state.
	 *
	 * @return int Number of stale run records updated.
	 */
	public static function abandon_expired_runs(): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$now    = current_time( 'mysql', true );
		$reason = __( 'The automation execution lease expired before completion.', 'superdav-ai-agent' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Orphaned durable lifecycle logs are safe to terminalize after their bounded lease expires.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i AS logs LEFT JOIN %i AS automations ON automations.id = logs.automation_id AND automations.active_run_id = logs.run_id AND automations.execution_status IN ('claimed', 'running') SET logs.status = 'error', logs.lifecycle_status = 'abandoned', logs.error_message = %s, logs.lease_expires_at = NULL, logs.finished_at = %s WHERE logs.lifecycle_status IN ('claimed', 'running') AND logs.lease_expires_at IS NOT NULL AND logs.lease_expires_at < %s AND automations.id IS NULL",
				self::table_name(),
				Automations::table_name(),
				$reason,
				$now,
				$now
			)
		);

		return false !== $result ? (int) $result : 0;
	}

	/**
	 * List logs for an automation.
	 *
	 * @param int $automation_id Automation ID.
	 * @param int $limit         Max results.
	 * @param int $offset        Offset for pagination.
	 * @return list<array<string, mixed>>
	 */
	public static function list_for_automation( int $automation_id, int $limit = 20, int $offset = 0 ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE automation_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
				self::table_name(),
				$automation_id,
				$limit,
				$offset
			)
		);

		return array_map( [ __CLASS__, 'decode_row' ], $rows ?: [] );
	}

	/**
	 * List recent logs across all automations.
	 *
	 * @param int $limit Max results.
	 * @return list<array<string, mixed>>
	 */
	public static function list_recent( int $limit = 50 ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY created_at DESC LIMIT %d',
				self::table_name(),
				$limit
			)
		);

		return array_map( [ __CLASS__, 'decode_row' ], $rows ?: [] );
	}

	/**
	 * Get a single log entry.
	 *
	 * @param int $id Log ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table_name(), $id )
		);

		return $row ? self::decode_row( $row ) : null;
	}

	/**
	 * Get an automation log by its durable correlation UUID.
	 *
	 * @param string $run_id Correlation UUID for the execution.
	 * @return array<string, mixed>|null
	 */
	public static function get_by_run_id( string $run_id ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( '' === $run_id ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Run correlation lookup for a custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %s ORDER BY id DESC LIMIT 1', self::table_name(), $run_id )
		);

		return $row ? self::decode_row( $row ) : null;
	}

	/**
	 * Delete all logs for an automation.
	 *
	 * @param int $automation_id Automation ID.
	 * @return int Rows deleted.
	 */
	public static function delete_for_automation( int $automation_id ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->delete(
			self::table_name(),
			[ 'automation_id' => $automation_id ],
			[ '%d' ]
		);

		return $result !== false ? (int) $result : 0;
	}

	/**
	 * Prune old logs (keep last N per automation).
	 *
	 * @param int $keep_per_automation Max logs to keep per automation.
	 */
	public static function prune( int $keep_per_automation = 100 ): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = self::table_name();

		// Get all automation IDs with logs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table query; table name from internal method.
		$automation_ids = $wpdb->get_col( "SELECT DISTINCT automation_id FROM {$table}" );

		foreach ( $automation_ids as $aid ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
			$count = $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE automation_id = %d', $table, $aid )
			);

			if ( (int) $count > $keep_per_automation ) {
				$delete_count = (int) $count - $keep_per_automation;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
				$wpdb->query(
					$wpdb->prepare(
						'DELETE FROM %i WHERE automation_id = %d ORDER BY created_at ASC LIMIT %d',
						$table,
						$aid,
						$delete_count
					)
				);
			}
		}
	}

	/**
	 * Decode a database row.
	 *
	 * @param object $row Database row.
	 * @return array<string, mixed>
	 */
	private static function decode_row( object $row ): array {
		$lifecycle_status = (string) ( $row->lifecycle_status ?? '' );
		if ( '' === $lifecycle_status ) {
			$lifecycle_status = self::lifecycle_for_legacy_status( (string) $row->status );
		}

		return [
			'id'                => (int) $row->id,
			'automation_id'     => (int) $row->automation_id,
			'run_id'            => (string) ( $row->run_id ?? '' ),
			'owner_user_id'     => (int) ( $row->owner_user_id ?? 0 ),
			'trigger_type'      => $row->trigger_type,
			'trigger_name'      => $row->trigger_name,
			'status'            => $row->status,
			'lifecycle_status'  => $lifecycle_status,
			'reply'             => $row->reply,
			'tool_calls'        => json_decode( $row->tool_calls, true ) ?: [],
			'prompt_tokens'     => (int) $row->prompt_tokens,
			'completion_tokens' => (int) $row->completion_tokens,
			'duration_ms'       => (int) $row->duration_ms,
			'error_message'     => $row->error_message,
			'lease_expires_at'  => $row->lease_expires_at ?? null,
			'started_at'        => $row->started_at ?? null,
			'finished_at'       => $row->finished_at ?? null,
			'created_at'        => $row->created_at,
		];
	}

	/**
	 * Translate historical success/error values into explicit lifecycle states.
	 */
	private static function lifecycle_for_legacy_status( string $status ): string {
		return 'success' === $status ? 'succeeded' : ( 'pending' === $status ? 'claimed' : 'failed' );
	}

	/**
	 * Retain tool names and coarse outcome metadata without storing arguments or
	 * arbitrary tool response bodies that can contain credentials or site data.
	 *
	 * @param mixed $tool_calls Raw AgentLoop activity log.
	 * @return list<array<string, string>>
	 */
	private static function summarize_tool_calls( $tool_calls ): array {
		if ( ! is_array( $tool_calls ) ) {
			return [];
		}

		$summary = [];
		foreach ( $tool_calls as $tool_call ) {
			if ( ! is_array( $tool_call ) ) {
				continue;
			}

			$name = isset( $tool_call['name'] ) && is_string( $tool_call['name'] )
				? sanitize_text_field( $tool_call['name'] )
				: '';
			if ( '' === $name ) {
				continue;
			}

			$entry = [ 'name' => $name ];
			foreach ( [ 'type', 'source' ] as $key ) {
				if ( isset( $tool_call[ $key ] ) && is_string( $tool_call[ $key ] ) && '' !== $tool_call[ $key ] ) {
					$entry[ $key ] = sanitize_key( $tool_call[ $key ] );
				}
			}

			$entry['outcome'] = array_key_exists( 'error', $tool_call ) ? 'error' : 'recorded';
			$summary[]        = $entry;
		}

		return $summary;
	}

	/**
	 * Scrub stored reply text before it reaches persistent logs or REST output.
	 *
	 * @param mixed $reply Raw response text.
	 */
	private static function sanitize_reply( $reply ): string {
		return DurablePlanTextSanitizer::sanitize( is_scalar( $reply ) ? (string) $reply : '', 4000 );
	}

	/**
	 * Scrub low-level provider and tool failure text before persistence.
	 *
	 * @param mixed $error Raw error detail.
	 */
	private static function sanitize_error( $error ): string {
		return JobErrorSanitizer::sanitize( is_scalar( $error ) ? (string) $error : '', 500 );
	}

	/**
	 * Normalize a nullable internal UTC datetime value.
	 *
	 * @param mixed $value Candidate datetime.
	 */
	private static function sanitize_datetime( $value ): ?string {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		return sanitize_text_field( $value );
	}
}
