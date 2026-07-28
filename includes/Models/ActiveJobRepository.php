<?php

declare(strict_types=1);
/**
 * Active jobs repository — persistent storage for background job state.
 *
 * Tracks the lifecycle of background AI jobs so that clients can reconnect
 * after page navigation and resume polling for completion.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Models;

use SdAiAgent\Core\ActiveJobFailureDiagnostic;
use SdAiAgent\Models\DTO\ActiveJobRow;

class ActiveJobRepository {

	/**
	 * Valid job status values.
	 *
	 * - processing            — loop is actively running.
	 * - awaiting_confirmation — paused waiting for user confirmation.
	 * - awaiting_client_tools — paused waiting for browser to execute JS tools.
	 * - complete              — loop finished normally.
	 * - error                 — loop finished with an error.
	 * - interrupted           — PHP request terminated before loop completion (shutdown handler fired).
	 * - abandoned             — row reaped by the hourly cron because updated_at is stale.
	 */
	const STATUSES = [ 'processing', 'awaiting_confirmation', 'awaiting_client_tools', 'complete', 'error', 'interrupted', 'abandoned' ];

	/**
	 * Get the active jobs table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_active_jobs';
	}

	/**
	 * Create a new active job record.
	 *
	 * @param int    $session_id Session ID.
	 * @param string $job_id     UUID identifying the background job.
	 * @param int    $user_id    WordPress user ID.
	 * @param string $status     Initial job status (default 'processing').
	 * @return int|false Inserted row ID or false on failure.
	 */
	public static function create( int $session_id, string $job_id, int $user_id, string $status = 'processing' ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = 'processing';
		}

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query; caching not applicable.
		$result = $wpdb->insert(
			self::table_name(),
			[
				'session_id'    => $session_id,
				'job_id'        => $job_id,
				'user_id'       => $user_id,
				'status'        => $status,
				'pending_tools' => '[]',
				'tool_calls'    => '[]',
				'created_at'    => $now,
				'updated_at'    => $now,
			],
			[ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Get an active job row by its UUID.
	 *
	 * @param string $job_id The job UUID.
	 * @return ActiveJobRow|null Row DTO or null if not found.
	 */
	public static function get_by_job_id( string $job_id ): ?ActiveJobRow {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE job_id = %s LIMIT 1',
				$table,
				$job_id
			)
		);

		if ( null === $row ) {
			return null;
		}

		return ActiveJobRow::from_row( $row );
	}

	/**
	 * Get the active (non-terminal) job for a session.
	 *
	 * Returns the most-recently-created job that is still in a non-terminal
	 * state (processing or awaiting_confirmation).
	 *
	 * @param int $session_id Session ID.
	 * @return ActiveJobRow|null Row DTO or null if no active job exists.
	 */
	public static function get_by_session_id( int $session_id ): ?ActiveJobRow {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE session_id = %d AND status IN ('processing', 'awaiting_confirmation', 'awaiting_client_tools') ORDER BY created_at DESC LIMIT 1",
				$table,
				$session_id
			)
		);

		if ( null === $row ) {
			return null;
		}

		return ActiveJobRow::from_row( $row );
	}

	/**
	 * Get all active (non-terminal) jobs for a user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return list<ActiveJobRow>
	 */
	public static function get_active_for_user( int $user_id ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE user_id = %d AND status IN ('processing', 'awaiting_confirmation', 'awaiting_client_tools') ORDER BY created_at DESC",
				$table,
				$user_id
			)
		);

		return array_map( [ ActiveJobRow::class, 'from_row' ], $rows ?: [] );
	}

	/**
	 * Update the status (and optional fields) of an active job.
	 *
	 * @param string               $job_id The job UUID.
	 * @param string               $status New status value.
	 * @param array<string, mixed> $extra  Optional extra fields to update (pending_tools, tool_calls, error).
	 * @return bool True on success, false on failure.
	 */
	public static function update_status( string $job_id, string $status, array $extra = [] ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}

		$allowed = [ 'pending_tools', 'tool_calls', 'checkpoint', 'checkpoint_phase', 'resume_attempts', 'error' ];
		$data    = array_intersect_key( $extra, array_flip( $allowed ) );
		if ( array_key_exists( 'error', $data ) ) {
			$data['error'] = ActiveJobFailureDiagnostic::encode(
				$job_id,
				ActiveJobFailureDiagnostic::from_stored(
					$job_id,
					is_string( $data['error'] ) ? $data['error'] : null
				)
			);
		}

		$data['status']     = $status;
		$data['updated_at'] = current_time( 'mysql', true );

		$formats = array_fill( 0, count( $data ), '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->update(
			self::table_name(),
			$data,
			[ 'job_id' => $job_id ],
			$formats,
			[ '%s' ]
		);

		return $result !== false;
	}

	/**
	 * Persist a normalized, prompt-free terminal failure and emit safe telemetry.
	 *
	 * @param string               $job_id  Active-job UUID.
	 * @param string               $status  Terminal status (error, interrupted, or abandoned).
	 * @param string               $reason  Normalized terminal reason.
	 * @param array<string, mixed> $context Safe diagnostic metadata.
	 * @param array<string, mixed> $extra   Additional persisted active-job fields.
	 * @return array<string, bool|int|string> Persisted diagnostic envelope.
	 */
	public static function record_failure( string $job_id, string $status, string $reason, array $context = array(), array $extra = array() ): array {
		$row            = self::get_by_job_id( $job_id );
		$diagnostic     = self::build_failure_diagnostic( $job_id, $reason, $row, $context );
		$session_id     = null !== $row ? $row->session_id : 0;
		$extra['error'] = ActiveJobFailureDiagnostic::encode( $job_id, $diagnostic );

		if ( ! in_array( $status, array( 'error', 'interrupted', 'abandoned' ), true ) ) {
			$status = 'error';
		}

		if ( self::update_status( $job_id, $status, $extra ) ) {
			ActiveJobFailureDiagnostic::log( $diagnostic, $session_id );
		}

		return $diagnostic;
	}

	/**
	 * Persist a durable agent-loop checkpoint for a processing job.
	 *
	 * @param string               $job_id     The job UUID.
	 * @param string               $phase      Last durable loop phase.
	 * @param array<string, mixed> $checkpoint Serializable checkpoint payload.
	 * @return bool True on success, false on failure.
	 */
	public static function save_checkpoint( string $job_id, string $phase, array $checkpoint ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$encoded = wp_json_encode( $checkpoint );
		if ( false === $encoded ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->update(
			self::table_name(),
			[
				'checkpoint'       => $encoded,
				'checkpoint_phase' => $phase,
				'updated_at'       => current_time( 'mysql', true ),
			],
			[ 'job_id' => $job_id ],
			[ '%s', '%s', '%s' ],
			[ '%s' ]
		);

		return $result !== false;
	}

	/**
	 * Claim one automatic resume attempt by returning the row to processing.
	 *
	 * @param string                    $job_id       The job UUID.
	 * @param int                       $max_attempts Maximum permitted attempts.
	 * @param array<string, mixed>|null $checkpoint Updated checkpoint to persist atomically with the claim.
	 * @return bool True when an attempt was claimed.
	 */
	public static function claim_resume_attempt( string $job_id, int $max_attempts, ?array $checkpoint = null ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$now = current_time( 'mysql', true );
		if ( null !== $checkpoint ) {
			$encoded = wp_json_encode( $checkpoint );
			if ( false === $encoded ) {
				return false;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; claim and checkpoint snapshot must be atomic.
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status = 'processing', error = NULL, interrupted_at = NULL, checkpoint = %s, resume_attempts = resume_attempts + 1, updated_at = %s WHERE job_id = %s AND status IN ('interrupted', 'abandoned') AND resume_attempts < %d",
					self::table_name(),
					$encoded,
					$now,
					$job_id,
					$max_attempts
				)
			);

			return $result !== false && $result > 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'processing', error = NULL, interrupted_at = NULL, resume_attempts = resume_attempts + 1, updated_at = %s WHERE job_id = %s AND status IN ('interrupted', 'abandoned') AND resume_attempts < %d",
				self::table_name(),
				$now,
				$job_id,
				$max_attempts
			)
		);

		return $result !== false && $result > 0;
	}

	/**
	 * Update only the updated_at timestamp for a job (heartbeat).
	 *
	 * Called at the start of each AgentLoop iteration so the periodic reaper
	 * can distinguish an actively-running job from a zombie.
	 *
	 * @param string $job_id The job UUID.
	 * @return bool True on success, false on failure.
	 */
	public static function heartbeat( string $job_id ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->update(
			self::table_name(),
			[ 'updated_at' => current_time( 'mysql', true ) ],
			[ 'job_id' => $job_id ],
			[ '%s' ],
			[ '%s' ]
		);

		return $result !== false && (int) $result > 0;
	}

	/**
	 * Mark a job as interrupted (PHP request terminated before loop completion).
	 *
	 * Called by the shutdown handler registered in handle_process(). Only
	 * updates the row when the status is still 'processing' so a normally-
	 * completed job that finished just before shutdown is not overwritten.
	 *
	 * @param string               $job_id  The job UUID.
	 * @param string               $reason  Retained for call-site compatibility; never persisted as free text.
	 * @param array<string, mixed> $context Safe diagnostic metadata.
	 * @return bool True on success, false on failure.
	 */
	public static function mark_interrupted( string $job_id, string $reason = '', array $context = array() ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$row        = self::get_by_job_id( $job_id );
		$diagnostic = self::build_failure_diagnostic(
			$job_id,
			ActiveJobFailureDiagnostic::REASON_WORKER_TERMINATED,
			$row,
			$context
		);
		$session_id = null !== $row ? $row->session_id : 0;
		$now        = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'interrupted', error = %s, interrupted_at = %s, updated_at = %s WHERE job_id = %s AND status = 'processing'",
				self::table_name(),
				ActiveJobFailureDiagnostic::encode( $job_id, $diagnostic ),
				$now,
				$now,
				$job_id
			)
		);

		$updated = $result !== false && $result > 0;
		if ( $updated ) {
			ActiveJobFailureDiagnostic::log( $diagnostic, $session_id );
		}

		return $updated;
	}

	/**
	 * Reap stale processing rows by marking them as 'abandoned'.
	 *
	 * Rows are considered stale when status='processing' and updated_at has
	 * not advanced within the given threshold. Called by the hourly cron job.
	 *
	 * @param int $threshold_minutes Number of minutes of inactivity before a row is considered stale.
	 * @return int Number of rows updated.
	 */
	public static function cleanup_stale( int $threshold_minutes ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE status = 'processing' AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d MINUTE)",
				$table,
				$threshold_minutes
			)
		);
		$count = 0;

		foreach ( $rows ?: array() as $raw_row ) {
			$row        = ActiveJobRow::from_row( $raw_row );
			$diagnostic = self::build_failure_diagnostic(
				$row->job_id,
				ActiveJobFailureDiagnostic::REASON_WORKER_TERMINATED,
				$row
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional custom-table state transition.
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status = 'abandoned', error = %s, updated_at = UTC_TIMESTAMP() WHERE job_id = %s AND status = 'processing'",
					$table,
					ActiveJobFailureDiagnostic::encode( $row->job_id, $diagnostic ),
					$row->job_id
				)
			);

			if ( $result !== false && $result > 0 ) {
				++$count;
				ActiveJobFailureDiagnostic::log( $diagnostic, $row->session_id );
			}
		}

		return $count;
	}

	/**
	 * Replace exhausted auto-recovery state with a compact continuation hint.
	 *
	 * Clearing the checkpoint prevents stale prompt history from being retained
	 * after it can no longer be resumed automatically.
	 *
	 * @param string $job_id Active-job UUID.
	 */
	public static function mark_resume_exhausted( string $job_id ): void {
		$row = self::get_by_job_id( $job_id );
		if ( null === $row ) {
			return;
		}

		self::record_failure(
			$job_id,
			$row->status,
			ActiveJobFailureDiagnostic::REASON_RESUME_EXHAUSTED,
			array(
				'last_safe_phase' => $row->checkpoint_phase,
				'resume_count'    => $row->resume_attempts,
			),
			array( 'checkpoint' => '' )
		);
	}

	/**
	 * Delete terminal rows after the diagnostic retention window expires.
	 *
	 * @param int $retention_days Number of days to retain undelivered terminal diagnostics.
	 * @return int Number of deleted rows.
	 */
	public static function cleanup_terminal_diagnostics( int $retention_days ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$retention_days = max( 1, $retention_days );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data-retention cleanup.
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE status IN ('complete', 'error', 'interrupted', 'abandoned') AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				self::table_name(),
				$retention_days
			)
		);

		return $result === false ? 0 : (int) $result;
	}

	/**
	 * Build an allowlisted diagnostic using the row's durable metadata.
	 *
	 * @param string               $job_id  Active-job UUID.
	 * @param string               $reason  Normalized failure reason.
	 * @param ActiveJobRow|null    $row     Existing row, when available.
	 * @param array<string, mixed> $context Caller-supplied safe metadata.
	 * @return array<string, bool|int|string>
	 */
	private static function build_failure_diagnostic( string $job_id, string $reason, ?ActiveJobRow $row, array $context = array() ): array {
		$defaults = array(
			'last_safe_phase' => null !== $row ? $row->checkpoint_phase : '',
			'resume_count'    => null !== $row ? $row->resume_attempts : 0,
		);

		return ActiveJobFailureDiagnostic::create( $job_id, $reason, array_merge( $defaults, $context ) );
	}

	/**
	 * Delete an active job record by its UUID.
	 *
	 * @param string $job_id The job UUID.
	 * @return bool True on success, false on failure.
	 */
	public static function delete( string $job_id ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->delete(
			self::table_name(),
			[ 'job_id' => $job_id ],
			[ '%s' ]
		);

		return $result !== false;
	}
}
