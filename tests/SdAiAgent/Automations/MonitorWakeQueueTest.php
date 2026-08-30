<?php

declare(strict_types=1);
/**
 * Integration tests for the durable, privacy-safe Monitor wake queue.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Automations;

use SdAiAgent\Automations\Automations;
use SdAiAgent\Automations\MonitorWakeQueue;
use SdAiAgent\Core\Database;
use WP_UnitTestCase;

final class MonitorWakeQueueTest extends WP_UnitTestCase {

	/** @var list<int> Monitor definitions created by this test. */
	private array $monitor_ids = [];

	/** Ensure the new table exists even when this class runs independently. */
	public function set_up(): void {
		parent::set_up();
		Database::install();
		MonitorWakeQueue::unschedule_processing();
	}

	/** Remove durable wake rows and all test cron entries. */
	public function tear_down(): void {
		foreach ( $this->monitor_ids as $monitor_id ) {
			Automations::delete( $monitor_id );
		}

		MonitorWakeQueue::unschedule_processing();
		parent::tear_down();
	}

	/**
	 * Create one enabled Monitor with explicit consent for delete_post wakes.
	 *
	 * @param array<string, mixed> $overrides Monitor fields to override.
	 */
	private function create_event_monitor( array $overrides = [] ): int {
		$monitor_id = Automations::create(
			array_merge(
				[
					'name'                        => 'Monitor wake queue test',
					'prompt'                      => 'Assess current site state.',
					'mode'                        => Automations::MONITOR_MODE,
					'monitor_scratch'             => '',
					'monitor_event_wakes_enabled' => true,
					'monitor_event_sources'       => [ 'delete_post' ],
					'enabled'                     => 1,
				],
				$overrides
			)
		);

		$this->assertIsInt( $monitor_id );
		$this->monitor_ids[] = (int) $monitor_id;

		return (int) $monitor_id;
	}

	/** Return queue rows for one Monitor without exposing them outside this test. */
	private function get_wakes( int $monitor_id ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-only queue inspection.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE monitor_id = %d ORDER BY id ASC',
				MonitorWakeQueue::table_name(),
				$monitor_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/** Insert one claimed fixture without exposing queue internals outside this test. */
	private function insert_claimed_wake( int $monitor_id, string $run_id, ?string $provider_started_at, string $lease_expires_at, int $attempt_count = 0 ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */
		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test-only claimed queue fixture.
		$this->assertNotFalse(
			$wpdb->insert(
				MonitorWakeQueue::table_name(),
				[
					'monitor_id'          => $monitor_id,
					'source'              => 'delete_post',
					'state_key'           => 'claimed:' . $run_id,
					'status'              => 'claimed',
					'event_summary'       => '{"source":"delete_post","identifiers":{"post_id":42}}',
					'event_count'         => 1,
					'dropped_count'       => 0,
					'deferred_count'      => 0,
					'attempt_count'       => $attempt_count,
					'available_at'        => $now,
					'lease_expires_at'    => $lease_expires_at,
					'claimed_run_id'      => $run_id,
					'provider_started_at' => $provider_started_at,
					'first_seen_at'       => $now,
					'last_seen_at'        => $now,
					'expires_at'          => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
					'created_at'          => $now,
					'updated_at'          => $now,
				],
				[ '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
			)
		);

		return (int) $wpdb->insert_id;
	}

	/** Repeated approved events coalesce while retaining only source-safe identifiers. */
	public function test_capture_coalesces_events_and_clears_them_when_consent_is_revoked(): void {
		$monitor_id = $this->create_event_monitor();

		MonitorWakeQueue::capture( 'delete_post', [ 42, 'unretained-input' ] );
		MonitorWakeQueue::capture( 'delete_post', [ 42, 'unretained-input' ] );

		$wakes = $this->get_wakes( $monitor_id );
		$this->assertCount( 1, $wakes );
		$this->assertSame( 'pending', $wakes[0]['status'] );
		$this->assertSame( 'delete_post', $wakes[0]['source'] );
		$this->assertSame( 2, (int) $wakes[0]['event_count'] );
		$this->assertSame(
			[
				'source'      => 'delete_post',
				'identifiers' => [ 'post_id' => 42 ],
			],
			json_decode( $wakes[0]['event_summary'], true )
		);
		$this->assertStringNotContainsString( 'unretained-input', $wakes[0]['event_summary'] );
		$this->assertSame(
			[
				'pending_groups'  => 1,
				'pending_events'  => 2,
				'deferred_groups' => 0,
				'claimed_groups'  => 0,
				'expired_groups'  => 0,
			],
			MonitorWakeQueue::get_status_for_monitor( $monitor_id )
		);

		$this->assertTrue( Automations::update( $monitor_id, [ 'monitor_event_wakes_enabled' => false ] ) );
		$this->assertSame( [], $this->get_wakes( $monitor_id ) );
		$this->assertSame(
			[
				'pending_groups'  => 0,
				'pending_events'  => 0,
				'deferred_groups' => 0,
				'claimed_groups'  => 0,
				'expired_groups'  => 0,
			],
			MonitorWakeQueue::get_status_for_monitor( $monitor_id )
		);
	}

	/** The persisted boundary succeeds only for the owned claimed queue row. */
	public function test_mark_provider_started_records_the_no_replay_boundary(): void {
		$monitor_id = $this->create_event_monitor();
		$run_id     = '11111111-2222-4333-8444-555555555555';
		$wake_id    = $this->insert_claimed_wake(
			$monitor_id,
			$run_id,
			null,
			gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS )
		);

		$this->assertTrue( MonitorWakeQueue::mark_provider_started( $wake_id, $run_id ) );
		$this->assertFalse( MonitorWakeQueue::mark_provider_started( $wake_id, '22222222-3333-4444-8555-666666666666' ) );

		$wakes = $this->get_wakes( $monitor_id );
		$this->assertCount( 1, $wakes );
		$this->assertNotEmpty( $wakes[0]['provider_started_at'] );
	}

	/** Expired claims retry only before provider work and stop after the bounded retry budget. */
	public function test_process_due_wakes_recovers_only_safe_pre_provider_claims(): void {
		$monitor_id = $this->create_event_monitor();
		$expired_at = '2000-01-01 00:00:00';
		$recover_id = $this->insert_claimed_wake( $monitor_id, '21111111-2222-4333-8444-555555555555', null, $expired_at );
		$exhausted_id = $this->insert_claimed_wake(
			$monitor_id,
			'31111111-2222-4333-8444-555555555555',
			null,
			$expired_at,
			MonitorWakeQueue::MAX_RETRY_ATTEMPTS
		);
		$started_id = $this->insert_claimed_wake( $monitor_id, '41111111-2222-4333-8444-555555555555', $expired_at, $expired_at );

		MonitorWakeQueue::process_due_wakes();

		$wakes_by_id = [];
		foreach ( $this->get_wakes( $monitor_id ) as $wake ) {
			$wakes_by_id[ (int) $wake['id'] ] = $wake;
		}

		$this->assertSame( 'deferred', $wakes_by_id[ $recover_id ]['status'] );
		$this->assertSame( 1, (int) $wakes_by_id[ $recover_id ]['attempt_count'] );
		$this->assertSame( 'expired', $wakes_by_id[ $exhausted_id ]['status'] );
		$this->assertSame( 'expired', $wakes_by_id[ $started_id ]['status'] );
		$this->assertSame( $expired_at, $wakes_by_id[ $started_id ]['provider_started_at'] );
		$this->assertSame( 1, (int) Automations::get( $monitor_id )['monitor_wake_deferred_count'] );
	}

	/** A due quiet Monitor wake is claimed, completed, and places its Monitor in cooldown. */
	public function test_process_due_wakes_completes_quiet_monitor_and_applies_cooldown(): void {
		global $wpdb;
		/** @var \wpdb $wpdb */
		$owner_id   = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$monitor_id = $this->create_event_monitor( [ 'owner_user_id' => $owner_id ] );

		MonitorWakeQueue::capture( 'delete_post', [ 42 ] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test-only wake admission override.
		$this->assertSame(
			1,
			$wpdb->update(
				MonitorWakeQueue::table_name(),
				[ 'available_at' => '2000-01-01 00:00:00' ],
				[ 'monitor_id' => $monitor_id ],
				[ '%s' ],
				[ '%d' ]
			)
		);

		MonitorWakeQueue::process_due_wakes();

		$monitor = Automations::get( $monitor_id );
		$this->assertSame( [], $this->get_wakes( $monitor_id ) );
		$this->assertNotNull( $monitor );
		$this->assertGreaterThan( time(), strtotime( (string) $monitor['monitor_wake_cooldown_until'] . ' UTC' ) );
	}

	/** Retained wakes schedule the global processor again after plugin activation. */
	public function test_reschedule_pending_wakes_schedules_retained_work(): void {
		$monitor_id = $this->create_event_monitor();

		MonitorWakeQueue::capture( 'delete_post', [ 42 ] );
		MonitorWakeQueue::unschedule_processing();
		$this->assertFalse( wp_next_scheduled( MonitorWakeQueue::CRON_HOOK ) );

		MonitorWakeQueue::reschedule_pending_wakes();

		$this->assertNotFalse( wp_next_scheduled( MonitorWakeQueue::CRON_HOOK ) );
		$this->assertNotEmpty( $this->get_wakes( $monitor_id ) );
	}

	/** The cleanup cron callback removes retained evidence idempotently. */
	public function test_retry_clear_for_monitor_removes_retained_wakes(): void {
		$monitor_id = $this->create_event_monitor();

		MonitorWakeQueue::capture( 'delete_post', [ 42 ] );
		$this->assertNotEmpty( $this->get_wakes( $monitor_id ) );

		MonitorWakeQueue::retry_clear_for_monitor( $monitor_id );
		MonitorWakeQueue::retry_clear_for_monitor( $monitor_id );

		$this->assertSame( [], $this->get_wakes( $monitor_id ) );
		$this->assertFalse( wp_next_scheduled( MonitorWakeQueue::CLEANUP_CRON_HOOK, [ $monitor_id ] ) );
	}
}
