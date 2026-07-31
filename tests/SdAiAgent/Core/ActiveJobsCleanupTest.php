<?php
/**
 * Test case for active-jobs zombie cleanup (GH#1510).
 *
 * Covers:
 * - ActiveJobRepository::heartbeat() advances updated_at.
 * - ActiveJobRepository::mark_interrupted() sets status='interrupted' with error and timestamp.
 * - ActiveJobRepository::cleanup_stale() marks stale processing rows as 'abandoned'.
 * - ActiveJobRepository::cleanup_stale() leaves recently-updated rows untouched.
 * - ActiveJobsCleanupService::schedule() / unschedule() lifecycle.
 * - ActiveJobsCleanupService::run() triggers cleanup and fires action.
 * - New status values 'interrupted' and 'abandoned' are accepted by update_status().
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\ActiveJobsCleanupService;
use SdAiAgent\Core\ActiveJobFailureDiagnostic;
use SdAiAgent\Models\ActiveJobRepository;
use WP_UnitTestCase;

/**
 * Integration tests for active-jobs zombie cleanup.
 *
 * Runs inside wp-env (real MySQL) so direct SQL manipulation of timestamps
 * works as expected.
 */
class ActiveJobsCleanupTest extends WP_UnitTestCase {

	/**
	 * Remove all test rows and unschedule cron events after each test.
	 */
	public function tear_down(): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
		$wpdb->query( 'DELETE FROM ' . ActiveJobRepository::table_name() . " WHERE job_id LIKE 'test-%'" );
		ActiveJobsCleanupService::unschedule();
		remove_all_actions( 'sd_ai_agent_stale_jobs_reaped' );
		remove_all_actions( 'sd_ai_agent_terminal_job_diagnostics_pruned' );
		remove_all_filters( 'sd_ai_agent_stale_job_threshold_minutes' );
		remove_all_filters( 'sd_ai_agent_job_diagnostic_retention_days' );

		parent::tear_down();
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	/**
	 * Insert a synthetic active-jobs row with a controlled updated_at timestamp.
	 *
	 * @param string $job_id     UUID for the test row (prefix with 'test-' for teardown cleanup).
	 * @param string $status     Row status (default 'processing').
	 * @param string $updated_at MySQL datetime string for updated_at (default NOW()).
	 * @return int Row ID.
	 */
	private function insert_job( string $job_id, string $status = 'processing', string $updated_at = '' ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$now        = current_time( 'mysql', true );
		$updated_at = '' === $updated_at ? $now : $updated_at;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test helper.
		$wpdb->insert(
			ActiveJobRepository::table_name(),
			[
				'session_id'    => 1,
				'job_id'        => $job_id,
				'user_id'       => 1,
				'status'        => $status,
				'pending_tools' => '[]',
				'tool_calls'    => '[]',
				'created_at'    => $now,
				'updated_at'    => $updated_at,
			],
			[ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch the raw row for a job_id.
	 *
	 * @param string $job_id Job UUID.
	 * @return object|null Raw row or null.
	 */
	private function fetch_row( string $job_id ): ?object {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test helper.
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE job_id = %s',
				ActiveJobRepository::table_name(),
				$job_id
			)
		);
	}

	// ── heartbeat() ──────────────────────────────────────────────────────

	/**
	 * heartbeat() advances updated_at for an existing processing row.
	 */
	public function test_heartbeat_advances_updated_at(): void {
		// Insert a row with a timestamp two minutes in the past.
		$stale_time = gmdate( 'Y-m-d H:i:s', time() - 120 );
		$this->insert_job( 'test-heartbeat-1', 'processing', $stale_time );

		$before = $this->fetch_row( 'test-heartbeat-1' );
		$this->assertNotNull( $before );
		$this->assertSame( $stale_time, $before->updated_at );

		$result = ActiveJobRepository::heartbeat( 'test-heartbeat-1' );

		$after = $this->fetch_row( 'test-heartbeat-1' );
		$this->assertNotNull( $after );
		$this->assertTrue( $result );
		$this->assertGreaterThan( $before->updated_at, $after->updated_at, 'updated_at should advance after heartbeat' );
	}

	/**
	 * heartbeat() returns false for a non-existent job_id without erroring.
	 */
	public function test_heartbeat_returns_false_for_unknown_job(): void {
		$result = ActiveJobRepository::heartbeat( 'test-heartbeat-nonexistent' );
		$this->assertFalse( $result );
	}

	// ── mark_interrupted() ───────────────────────────────────────────────

	/**
	 * mark_interrupted() sets status='interrupted', error, and interrupted_at
	 * for a row that is still 'processing'.
	 */
	public function test_mark_interrupted_updates_processing_row(): void {
		$this->insert_job( 'test-interrupted-1', 'processing' );

		$reason = 'shutdown handler — request terminated without loop completion';
		$result = ActiveJobRepository::mark_interrupted( 'test-interrupted-1', $reason );

		$row = $this->fetch_row( 'test-interrupted-1' );

		$this->assertTrue( $result, 'mark_interrupted() should return true when it updated a row' );
		$this->assertNotNull( $row );
		$this->assertSame( 'interrupted', $row->status );
		$diagnostic = ActiveJobFailureDiagnostic::from_stored( 'test-interrupted-1', (string) $row->error );

		$this->assertSame( ActiveJobFailureDiagnostic::REASON_WORKER_TERMINATED, $diagnostic['reason'] );
		$this->assertStringNotContainsString( $reason, (string) $row->error );
		$this->assertNotEmpty( $row->interrupted_at );
	}

	/**
	 * mark_interrupted() is a no-op when the row status is not 'processing'
	 * (e.g. the loop already completed normally and set status='complete').
	 */
	public function test_mark_interrupted_does_not_overwrite_complete_row(): void {
		$this->insert_job( 'test-interrupted-2', 'complete' );

		$result = ActiveJobRepository::mark_interrupted( 'test-interrupted-2', 'shutdown' );
		$row    = $this->fetch_row( 'test-interrupted-2' );

		$this->assertFalse( $result, 'mark_interrupted() should return false when no rows were updated' );
		$this->assertNotNull( $row );
		$this->assertSame( 'complete', $row->status, 'Complete row status must not be overwritten' );
	}

	/**
	 * save_checkpoint() stores durable loop state for later crash resume.
	 */
	public function test_save_checkpoint_persists_phase_and_payload(): void {
		$this->insert_job( 'test-checkpoint-1', 'processing' );

		$result = ActiveJobRepository::save_checkpoint(
			'test-checkpoint-1',
			'before_provider_call',
			array(
				'history'              => array( array( 'role' => 'user', 'parts' => array() ) ),
				'iterations_remaining' => 3,
			)
		);

		$row = $this->fetch_row( 'test-checkpoint-1' );

		$this->assertTrue( $result );
		$this->assertNotNull( $row );
		$this->assertSame( 'before_provider_call', $row->checkpoint_phase );
		$this->assertIsArray( json_decode( (string) $row->checkpoint, true ) );
	}

	/**
	 * claim_resume_attempt() returns interrupted rows to processing with a retry cap.
	 */
	public function test_claim_resume_attempt_caps_retries(): void {
		$this->insert_job( 'test-resume-1', 'interrupted' );

		$this->assertTrue( ActiveJobRepository::claim_resume_attempt( 'test-resume-1', 1 ) );
		$row = $this->fetch_row( 'test-resume-1' );

		$this->assertNotNull( $row );
		$this->assertSame( 'processing', $row->status );
		$this->assertSame( '1', (string) $row->resume_attempts );

		ActiveJobRepository::mark_interrupted( 'test-resume-1', 'second crash' );
		$this->assertFalse( ActiveJobRepository::claim_resume_attempt( 'test-resume-1', 1 ) );
	}

	/**
	 * A claimed resume atomically stores its compact checkpoint metadata.
	 */
	public function test_claim_resume_attempt_persists_checkpoint_with_claim(): void {
		$this->insert_job( 'test-resume-checkpoint-1', 'interrupted' );
		$checkpoint = array(
			'history'                    => array( array( 'role' => 'user', 'parts' => array( array( 'text' => 'Resume safely.' ) ) ) ),
			'checkpoint_resume_metadata' => array(
				'version'      => 1,
				'next_request' => array( 'fingerprint' => str_repeat( 'a', 64 ) ),
			),
		);

		$this->assertTrue( ActiveJobRepository::claim_resume_attempt( 'test-resume-checkpoint-1', 2, $checkpoint ) );
		$row = $this->fetch_row( 'test-resume-checkpoint-1' );

		$this->assertNotNull( $row );
		$this->assertSame( 'processing', $row->status );
		$this->assertSame( '1', (string) $row->resume_attempts );
		$this->assertSame( $checkpoint, json_decode( (string) $row->checkpoint, true ) );
	}

	/** A queued durable worker may be claimed exactly once. */
	public function test_claim_queued_job_has_one_winner(): void {
		$this->insert_job( 'test-queued-claim-1', 'queued' );

		$this->assertTrue( ActiveJobRepository::claim_queued_job( 'test-queued-claim-1' ) );
		$this->assertFalse( ActiveJobRepository::claim_queued_job( 'test-queued-claim-1' ) );

		$row = $this->fetch_row( 'test-queued-claim-1' );
		$this->assertNotNull( $row );
		$this->assertSame( 'processing', $row->status );
	}

	/** Processing and paused jobs cannot be claimed as new durable workers. */
	public function test_claim_queued_job_rejects_nonqueued_jobs(): void {
		$this->insert_job( 'test-queued-processing-1', 'processing' );
		$this->insert_job( 'test-queued-paused-1', 'awaiting_confirmation' );

		$this->assertFalse( ActiveJobRepository::claim_queued_job( 'test-queued-processing-1' ) );
		$this->assertFalse( ActiveJobRepository::claim_queued_job( 'test-queued-paused-1' ) );
	}

	/** A durable confirmation can be requeued once without reviving a newer job state. */
	public function test_requeue_paused_job_transitions_only_confirmation_rows(): void {
		$this->insert_job( 'test-requeue-confirmation-1', 'awaiting_confirmation' );
		$this->assertTrue( ActiveJobRepository::requeue_paused_job( 'test-requeue-confirmation-1' ) );
		$this->assertFalse( ActiveJobRepository::requeue_paused_job( 'test-requeue-confirmation-1' ) );
		$confirmation = $this->fetch_row( 'test-requeue-confirmation-1' );
		$this->assertNotNull( $confirmation );
		$this->assertSame( 'queued', $confirmation->status );

		$this->insert_job( 'test-requeue-client-tools-1', 'awaiting_client_tools' );
		$this->assertFalse( ActiveJobRepository::requeue_paused_job( 'test-requeue-client-tools-1' ) );
		$client_tools = $this->fetch_row( 'test-requeue-client-tools-1' );
		$this->assertNotNull( $client_tools );
		$this->assertSame( 'awaiting_client_tools', $client_tools->status );

		$this->insert_job( 'test-requeue-processing-1', 'processing' );
		$this->assertFalse( ActiveJobRepository::requeue_paused_job( 'test-requeue-processing-1' ) );
		$processing = $this->fetch_row( 'test-requeue-processing-1' );
		$this->assertNotNull( $processing );
		$this->assertSame( 'processing', $processing->status );
	}

	// ── cleanup_stale() ──────────────────────────────────────────────────

	/**
	 * cleanup_stale() marks rows as 'abandoned' when status='processing' and
	 * updated_at is older than the threshold.
	 */
	public function test_cleanup_stale_marks_old_processing_rows_as_abandoned(): void {
		// Set updated_at to 30 minutes ago — well beyond the 15-minute default.
		$stale_time = gmdate( 'Y-m-d H:i:s', time() - 1800 );
		$this->insert_job( 'test-stale-1', 'processing', $stale_time );

		$count = ActiveJobRepository::cleanup_stale( 15 );
		$row   = $this->fetch_row( 'test-stale-1' );

		$this->assertGreaterThanOrEqual( 1, $count, 'cleanup_stale() should report at least one reaped row' );
		$this->assertNotNull( $row );
		$this->assertSame( 'abandoned', $row->status );
		$diagnostic = ActiveJobFailureDiagnostic::from_stored( 'test-stale-1', $row->error );
		$this->assertSame( ActiveJobFailureDiagnostic::REASON_WORKER_TERMINATED, $diagnostic['reason'] );
	}

	/** cleanup_stale() also reaps queued workers that never receive a loopback delivery. */
	public function test_cleanup_stale_marks_old_queued_rows_as_abandoned(): void {
		$stale_time = gmdate( 'Y-m-d H:i:s', time() - 1800 );
		$this->insert_job( 'test-stale-queued-1', 'queued', $stale_time );

		$count = ActiveJobRepository::cleanup_stale( 15 );
		$row   = $this->fetch_row( 'test-stale-queued-1' );

		$this->assertGreaterThanOrEqual( 1, $count );
		$this->assertNotNull( $row );
		$this->assertSame( 'abandoned', $row->status );
	}

	/** cleanup_stale() continues through bounded candidate-query batches. */
	public function test_cleanup_stale_reaps_more_than_one_candidate_batch(): void {
		$stale_time = gmdate( 'Y-m-d H:i:s', time() - 1800 );

		for ( $index = 1; $index <= 501; ++$index ) {
			$this->insert_job( "test-stale-batch-{$index}", 'processing', $stale_time );
		}

		$count = ActiveJobRepository::cleanup_stale( 15 );
		$first = $this->fetch_row( 'test-stale-batch-1' );
		$last  = $this->fetch_row( 'test-stale-batch-501' );

		$this->assertSame( 501, $count );
		$this->assertNotNull( $first );
		$this->assertNotNull( $last );
		$this->assertSame( 'abandoned', $first->status );
		$this->assertSame( 'abandoned', $last->status );
	}

	/** A failed stale-job write stops the batch and leaves its candidate for the next cleanup run. */
	public function test_reap_stale_jobs_stops_after_a_database_write_failure(): void {
		$stale_time = gmdate( 'Y-m-d H:i:s', time() - 1800 );
		$this->insert_job( 'test-stale-write-failure-first', 'processing', $stale_time );
		$this->insert_job( 'test-stale-write-failure-failed', 'processing', $stale_time );
		$this->insert_job( 'test-stale-write-failure-later', 'processing', $stale_time );

		global $wpdb;
		/** @var \wpdb $database */
		$database = $wpdb;
		$wpdb     = new class( $database ) {
			public string $prefix;

			private \wpdb $database;

			public int $update_calls = 0;

			public function __construct( \wpdb $database ) {
				$this->database = $database;
				$this->prefix   = $database->prefix;
			}

			public function get_results( string $query ): mixed {
				return $this->database->get_results( $query );
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $this->database->prepare( $query, ...$args );
			}

			public function query( string $query ): int|false {
				++$this->update_calls;

				if ( 2 === $this->update_calls ) {
					return false;
				}

				return $this->database->query( $query );
			}
		};

		try {
			$reaped       = ActiveJobRepository::reap_stale_jobs( 15 );
			$update_calls = $wpdb->update_calls;
		} finally {
			$wpdb = $database;
		}

		$this->assertCount( 1, $reaped );
		$this->assertSame( 2, $update_calls, 'The failed write must stop later candidates from being touched.' );

		$remaining = array();
		foreach ( array( 'test-stale-write-failure-first', 'test-stale-write-failure-failed', 'test-stale-write-failure-later' ) as $job_id ) {
			$row = $this->fetch_row( $job_id );
			$this->assertNotNull( $row );
			if ( 'processing' === $row->status ) {
				$remaining[] = $job_id;
			}
		}

		$this->assertCount( 2, $remaining, 'The failed and later candidates must remain available for cleanup.' );
		$this->assertSame( $remaining, ActiveJobRepository::reap_stale_jobs( 15 ) );
	}

	/**
	 * cleanup_stale() does NOT touch rows whose updated_at is within the threshold.
	 */
	public function test_cleanup_stale_leaves_fresh_rows_untouched(): void {
		// updated_at is only 2 minutes ago — well within the 15-minute window.
		$fresh_time = gmdate( 'Y-m-d H:i:s', time() - 120 );
		$this->insert_job( 'test-fresh-1', 'processing', $fresh_time );

		ActiveJobRepository::cleanup_stale( 15 );
		$row = $this->fetch_row( 'test-fresh-1' );

		$this->assertNotNull( $row );
		$this->assertSame( 'processing', $row->status, 'Fresh row must not be marked abandoned' );
	}

	/**
	 * cleanup_stale() does NOT touch rows with terminal statuses (complete, error, etc.).
	 */
	public function test_cleanup_stale_ignores_terminal_status_rows(): void {
		$stale_time = gmdate( 'Y-m-d H:i:s', time() - 3600 );
		$this->insert_job( 'test-complete-1', 'complete', $stale_time );
		$this->insert_job( 'test-error-1', 'error', $stale_time );
		$this->insert_job( 'test-abandoned-1', 'abandoned', $stale_time );

		$count = ActiveJobRepository::cleanup_stale( 15 );
		$this->assertSame( 0, $count, 'cleanup_stale() should not touch terminal-status rows' );
	}

	/**
	 * cleanup_stale() returns 0 when there are no stale rows.
	 */
	public function test_cleanup_stale_returns_zero_when_no_stale_rows(): void {
		$count = ActiveJobRepository::cleanup_stale( 15 );
		$this->assertSame( 0, $count );
	}

	/**
	 * Terminal job diagnostics are retained only for their bounded support window.
	 */
	public function test_cleanup_terminal_diagnostics_removes_only_expired_terminal_rows(): void {
		$expired_time = gmdate( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ) );
		$this->insert_job( 'test-terminal-expired', 'error', $expired_time );
		$this->insert_job( 'test-terminal-fresh', 'error' );
		$this->insert_job( 'test-terminal-processing', 'processing', $expired_time );

		$deleted = ActiveJobRepository::cleanup_terminal_diagnostics( 1 );

		$this->assertSame( 1, $deleted );
		$this->assertNull( $this->fetch_row( 'test-terminal-expired' ) );
		$this->assertNotNull( $this->fetch_row( 'test-terminal-fresh' ) );
		$this->assertNotNull( $this->fetch_row( 'test-terminal-processing' ) );
	}

	// ── ActiveJobsCleanupService scheduling ──────────────────────────────

	/**
	 * schedule() registers the hourly cron event exactly once (idempotent).
	 */
	public function test_schedule_registers_hourly_cron_event(): void {
		ActiveJobsCleanupService::unschedule();

		ActiveJobsCleanupService::schedule();
		$first = wp_next_scheduled( ActiveJobsCleanupService::CRON_HOOK );

		ActiveJobsCleanupService::schedule();
		$second = wp_next_scheduled( ActiveJobsCleanupService::CRON_HOOK );

		$this->assertNotFalse( $first );
		$this->assertSame( $first, $second, 'Calling schedule() twice should not move the timestamp' );
	}

	/**
	 * unschedule() removes the hourly cron event.
	 */
	public function test_unschedule_removes_cron_event(): void {
		ActiveJobsCleanupService::schedule();
		$this->assertNotFalse( wp_next_scheduled( ActiveJobsCleanupService::CRON_HOOK ) );

		ActiveJobsCleanupService::unschedule();
		$this->assertFalse( wp_next_scheduled( ActiveJobsCleanupService::CRON_HOOK ) );
	}

	// ── ActiveJobsCleanupService::run() ──────────────────────────────────

	/**
	 * run() calls cleanup_stale() with the filtered threshold and fires
	 * sd_ai_agent_stale_jobs_reaped when rows are reaped.
	 */
	public function test_run_reaps_stale_rows_and_fires_action(): void {
		$stale_time = gmdate( 'Y-m-d H:i:s', time() - 1800 );
		$this->insert_job( 'test-run-stale-1', 'processing', $stale_time );

		$reaped_count = 0;
		add_action(
			'sd_ai_agent_stale_jobs_reaped',
			static function ( int $count ) use ( &$reaped_count ): void {
				$reaped_count = $count;
			}
		);

		ActiveJobsCleanupService::run();

		$row = $this->fetch_row( 'test-run-stale-1' );

		$this->assertNotNull( $row );
		$this->assertSame( 'abandoned', $row->status );
		$this->assertGreaterThanOrEqual( 1, $reaped_count, 'sd_ai_agent_stale_jobs_reaped action should receive the count' );
	}

	/**
	 * run() respects the sd_ai_agent_stale_job_threshold_minutes filter.
	 */
	public function test_run_uses_filterable_threshold(): void {
		// Row updated 5 minutes ago — stale only with a 3-minute threshold.
		$five_min_ago = gmdate( 'Y-m-d H:i:s', time() - 300 );
		$this->insert_job( 'test-threshold-1', 'processing', $five_min_ago );

		// With the default 15-minute threshold the row should not be reaped.
		ActiveJobsCleanupService::run();
		$row = $this->fetch_row( 'test-threshold-1' );
		$this->assertSame( 'processing', $row->status ?? '', 'Row should not be reaped by default 15-min threshold' );

		// Now lower the threshold to 3 minutes — the row should be reaped.
		add_filter( 'sd_ai_agent_stale_job_threshold_minutes', static fn() => 3 );
		ActiveJobsCleanupService::run();
		$row = $this->fetch_row( 'test-threshold-1' );

		$this->assertSame( 'abandoned', $row->status ?? '', 'Row should be reaped with 3-minute threshold' );
	}

	/**
	 * run() does not fire sd_ai_agent_stale_jobs_reaped when no rows were reaped.
	 */
	public function test_run_does_not_fire_action_when_no_rows_reaped(): void {
		$action_fired = false;
		add_action(
			'sd_ai_agent_stale_jobs_reaped',
			static function () use ( &$action_fired ): void {
				$action_fired = true;
			}
		);

		ActiveJobsCleanupService::run();

		$this->assertFalse( $action_fired, 'sd_ai_agent_stale_jobs_reaped must not fire when no rows were reaped' );
	}

	// ── STATUSES constant ─────────────────────────────────────────────────

	/**
	 * 'queued', 'interrupted', and 'abandoned' are valid status values accepted by update_status().
	 */
	public function test_interrupted_and_abandoned_are_valid_statuses(): void {
		$this->insert_job( 'test-status-queued', 'processing' );
		$this->insert_job( 'test-status-interrupted', 'processing' );
		$this->insert_job( 'test-status-abandoned', 'processing' );

		$this->assertTrue(
			ActiveJobRepository::update_status( 'test-status-queued', 'queued' ),
			"'queued' should be a valid status"
		);
		$this->assertTrue(
			ActiveJobRepository::update_status( 'test-status-interrupted', 'interrupted' ),
			"'interrupted' should be a valid status"
		);
		$this->assertTrue(
			ActiveJobRepository::update_status( 'test-status-abandoned', 'abandoned' ),
			"'abandoned' should be a valid status"
		);

		$row0 = $this->fetch_row( 'test-status-queued' );
		$row1 = $this->fetch_row( 'test-status-interrupted' );
		$row2 = $this->fetch_row( 'test-status-abandoned' );

		$this->assertSame( 'queued', $row0->status ?? '' );
		$this->assertSame( 'interrupted', $row1->status ?? '' );
		$this->assertSame( 'abandoned', $row2->status ?? '' );
	}
}
