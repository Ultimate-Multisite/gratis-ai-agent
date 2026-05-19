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
		remove_all_filters( 'sd_ai_agent_stale_job_threshold_minutes' );

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
		$this->assertSame( $reason, $row->error );
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
	 * 'interrupted' and 'abandoned' are valid status values accepted by update_status().
	 */
	public function test_interrupted_and_abandoned_are_valid_statuses(): void {
		$this->insert_job( 'test-status-interrupted', 'processing' );
		$this->insert_job( 'test-status-abandoned', 'processing' );

		$this->assertTrue(
			ActiveJobRepository::update_status( 'test-status-interrupted', 'interrupted' ),
			"'interrupted' should be a valid status"
		);
		$this->assertTrue(
			ActiveJobRepository::update_status( 'test-status-abandoned', 'abandoned' ),
			"'abandoned' should be a valid status"
		);

		$row1 = $this->fetch_row( 'test-status-interrupted' );
		$row2 = $this->fetch_row( 'test-status-abandoned' );

		$this->assertSame( 'interrupted', $row1->status ?? '' );
		$this->assertSame( 'abandoned', $row2->status ?? '' );
	}
}
