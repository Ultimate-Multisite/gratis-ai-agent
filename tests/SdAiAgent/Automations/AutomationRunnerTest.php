<?php
/**
 * Tests for AutomationRunner (cron scheduling).
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Automations;

use SdAiAgent\Automations\AutomationRunner;
use SdAiAgent\Automations\AutomationLogs;
use SdAiAgent\Automations\Automations;
use WP_UnitTestCase;

/**
 * Test AutomationRunner scheduling functionality.
 *
 * Provider-dependent success paths are covered by E2E tests. This suite also
 * covers local no-provider lifecycle guards, cron scheduling, unscheduling,
 * and the custom schedule filter.
 */
class AutomationRunnerTest extends WP_UnitTestCase {

	/**
	 * Automation ID used across tests.
	 *
	 * @var int
	 */
	private int $automation_id;

	/**
	 * Set up a fresh automation before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->automation_id = (int) Automations::create( [
			'name'    => 'Runner Test Automation',
			'prompt'  => 'Test prompt',
			'enabled' => 0,
		] );
	}

	/**
	 * Tear down: unschedule any cron events created during the test.
	 */
	public function tear_down(): void {
		AutomationRunner::unschedule( $this->automation_id );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// CRON_HOOK constant
	// -------------------------------------------------------------------------

	/**
	 * Test CRON_HOOK constant has expected value.
	 */
	public function test_cron_hook_constant(): void {
		$this->assertSame( 'sd_ai_agent_run_automation', AutomationRunner::CRON_HOOK );
	}

	// -------------------------------------------------------------------------
	// add_cron_schedules
	// -------------------------------------------------------------------------

	/**
	 * Test add_cron_schedules adds weekly schedule when not present.
	 */
	public function test_add_cron_schedules_adds_weekly(): void {
		$schedules = AutomationRunner::add_cron_schedules( [] );

		$this->assertArrayHasKey( 'weekly', $schedules );
		$this->assertArrayHasKey( 'interval', $schedules['weekly'] );
		$this->assertArrayHasKey( 'display', $schedules['weekly'] );
		$this->assertSame( WEEK_IN_SECONDS, $schedules['weekly']['interval'] );
	}

	/**
	 * Test add_cron_schedules does not overwrite existing weekly schedule.
	 */
	public function test_add_cron_schedules_preserves_existing_weekly(): void {
		$existing = [
			'weekly' => [
				'interval' => 999,
				'display'  => 'Custom Weekly',
			],
		];

		$schedules = AutomationRunner::add_cron_schedules( $existing );

		$this->assertSame( 999, $schedules['weekly']['interval'] );
	}

	/**
	 * Test add_cron_schedules preserves other existing schedules.
	 */
	public function test_add_cron_schedules_preserves_others(): void {
		$existing = [
			'hourly' => [
				'interval' => HOUR_IN_SECONDS,
				'display'  => 'Once Hourly',
			],
		];

		$schedules = AutomationRunner::add_cron_schedules( $existing );

		$this->assertArrayHasKey( 'hourly', $schedules );
		$this->assertSame( HOUR_IN_SECONDS, $schedules['hourly']['interval'] );
	}

	// -------------------------------------------------------------------------
	// schedule / unschedule
	// -------------------------------------------------------------------------

	/**
	 * Test schedule creates a cron event for the automation.
	 */
	public function test_schedule_creates_cron_event(): void {
		AutomationRunner::schedule( $this->automation_id, 'daily' );

		$timestamp = wp_next_scheduled( AutomationRunner::CRON_HOOK, [ $this->automation_id ] );

		$this->assertNotFalse( $timestamp );
		$this->assertGreaterThan( 0, $timestamp );
	}

	/**
	 * Test schedule does not create duplicate events.
	 */
	public function test_schedule_no_duplicate(): void {
		AutomationRunner::schedule( $this->automation_id, 'daily' );
		AutomationRunner::schedule( $this->automation_id, 'daily' );

		// wp_next_scheduled returns a single timestamp — duplicates would cause
		// multiple events but we can only verify one is scheduled.
		$timestamp = wp_next_scheduled( AutomationRunner::CRON_HOOK, [ $this->automation_id ] );
		$this->assertNotFalse( $timestamp );
	}

	/**
	 * Test unschedule removes the cron event.
	 */
	public function test_unschedule_removes_event(): void {
		AutomationRunner::schedule( $this->automation_id, 'daily' );
		AutomationRunner::unschedule( $this->automation_id );

		$timestamp = wp_next_scheduled( AutomationRunner::CRON_HOOK, [ $this->automation_id ] );

		$this->assertFalse( $timestamp );
	}

	/**
	 * Test unschedule on non-scheduled automation does not error.
	 */
	public function test_unschedule_nonexistent_is_safe(): void {
		// Should not throw or produce errors.
		AutomationRunner::unschedule( 999999 );

		$this->assertTrue( true ); // Reached without error.
	}

	// -------------------------------------------------------------------------
	// Durable execution lifecycle
	// -------------------------------------------------------------------------

	/**
	 * A stale cron delivery for a disabled automation is recorded as blocked
	 * before any provider or tool work can begin.
	 */
	public function test_run_blocks_disabled_automation_with_durable_log(): void {
		$result = AutomationRunner::run( $this->automation_id );

		$this->assertIsArray( $result );
		$this->assertSame( 'blocked', $result['lifecycle_status'] );
		$this->assertSame( 'error', $result['status'] );
		$this->assertNotEmpty( $result['run_id'] );

		$log = AutomationLogs::get_by_run_id( $result['run_id'] );
		$this->assertNotNull( $log );
		$this->assertSame( 'blocked', $log['lifecycle_status'] );
	}

	/**
	 * Legacy ownerless rows fail closed even when an old cron event is still
	 * delivered after the schema upgrade.
	 */
	public function test_run_blocks_ownerless_automation_without_provider_work(): void {
		Automations::update( $this->automation_id, [ 'enabled' => 1 ] );

		$result = AutomationRunner::run( $this->automation_id );

		$this->assertIsArray( $result );
		$this->assertSame( 'blocked', $result['lifecycle_status'] );

		$automation = Automations::get( $this->automation_id );
		$this->assertSame( 'blocked', $automation['execution_status'] );
		$this->assertSame( $result['run_id'], $automation['last_run_id'] );
		$this->assertSame( '', $automation['active_run_id'] );
	}

	/**
	 * A deleted or revoked owner cannot be replaced by cron user zero or an
	 * administrator when a stale event is delivered.
	 */
	public function test_run_blocks_revoked_owner_without_provider_work(): void {
		$owner_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		Automations::update(
			$this->automation_id,
			[
				'enabled'       => 1,
				'owner_user_id' => $owner_id,
			]
		);

		$result = AutomationRunner::run( $this->automation_id );

		$this->assertIsArray( $result );
		$this->assertSame( 'blocked', $result['lifecycle_status'] );

		$log = AutomationLogs::get_by_run_id( $result['run_id'] );
		$this->assertNotNull( $log );
		$this->assertSame( $owner_id, $log['owner_user_id'] );
	}

	/**
	 * A duplicate delivery cannot pass the durable claim and therefore reaches
	 * neither owner switching, credential loading, nor provider execution.
	 */
	public function test_run_blocks_second_delivery_before_provider_work(): void {
		$owner_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		Automations::update(
			$this->automation_id,
			[
				'enabled'       => 1,
				'owner_user_id' => $owner_id,
			]
		);
		$this->assertTrue( Automations::claim_run( $this->automation_id, 'active-run', '2099-01-01 00:00:00' ) );

		$result = AutomationRunner::run( $this->automation_id );

		$this->assertIsArray( $result );
		$this->assertSame( 'blocked', $result['lifecycle_status'] );

		$automation = Automations::get( $this->automation_id );
		$this->assertSame( 'active-run', $automation['active_run_id'] );
		$this->assertSame( 'claimed', $automation['execution_status'] );
	}

	/**
	 * A lease is diagnostic, not permission to overlap a still-live worker. An
	 * expired claim held by the execution fence remains owned until that worker
	 * exits; the next recovery pass then transitions both durable rows together.
	 */
	public function test_run_keeps_an_expired_claim_held_by_execution_fence(): void {
		$run_id = '33333333-2222-4333-8444-555555555555';

		Automations::update( $this->automation_id, [ 'enabled' => 1 ] );
		$this->assertNotFalse(
			AutomationLogs::create(
				[
					'automation_id'    => $this->automation_id,
					'run_id'           => $run_id,
					'status'           => 'pending',
					'lifecycle_status' => 'claimed',
					'lease_expires_at' => '2000-01-01 00:00:00',
				]
			)
		);
		$this->assertTrue( Automations::claim_run( $this->automation_id, $run_id, '2000-01-01 00:00:00' ) );

		$execution_lock = Automations::acquire_execution_lock( $this->automation_id );
		$this->assertNotNull( $execution_lock );

		try {
			$result = AutomationRunner::run( $this->automation_id );

			$this->assertIsArray( $result );
			$this->assertSame( 'blocked', $result['lifecycle_status'] );

			$automation = Automations::get( $this->automation_id );
			$log        = AutomationLogs::get_by_run_id( $run_id );
			$this->assertSame( $run_id, $automation['active_run_id'] );
			$this->assertSame( 'claimed', $automation['execution_status'] );
			$this->assertNotNull( $log );
			$this->assertSame( 'claimed', $log['lifecycle_status'] );
		} finally {
			Automations::release_execution_lock( (string) $execution_lock );
		}

		// A later delivery can now recover the expired pair. Disabling it keeps
		// the test on the no-provider stale-event path after recovery.
		Automations::update( $this->automation_id, [ 'enabled' => 0 ] );
		AutomationRunner::run( $this->automation_id );

		$automation = Automations::get( $this->automation_id );
		$log        = AutomationLogs::get_by_run_id( $run_id );
		$this->assertSame( 'abandoned', $automation['execution_status'] );
		$this->assertSame( $run_id, $automation['last_run_id'] );
		$this->assertNotNull( $log );
		$this->assertSame( 'abandoned', $log['lifecycle_status'] );
	}

	/**
	 * A non-empty unknown profile is blocked after the authorized owner is
	 * restored and the request user context is then returned to its caller.
	 */
	public function test_run_restores_context_after_blocked_tool_profile(): void {
		$owner_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$previous = get_current_user_id();

		Automations::update(
			$this->automation_id,
			[
				'enabled'       => 1,
				'owner_user_id' => $owner_id,
				'tool_profile'  => 'unconfigured-profile',
			]
		);

		$result = AutomationRunner::run( $this->automation_id );

		$this->assertIsArray( $result );
		$this->assertSame( 'blocked', $result['lifecycle_status'] );
		$this->assertSame( $previous, get_current_user_id() );
	}

	// -------------------------------------------------------------------------
	// reschedule_all / unschedule_all
	// -------------------------------------------------------------------------

	/**
	 * Test reschedule_all schedules all enabled automations.
	 */
	public function test_reschedule_all_schedules_enabled(): void {
		// Enable the automation.
		Automations::update( $this->automation_id, [ 'enabled' => 1, 'schedule' => 'daily' ] );

		AutomationRunner::reschedule_all();

		$timestamp = wp_next_scheduled( AutomationRunner::CRON_HOOK, [ $this->automation_id ] );
		$this->assertNotFalse( $timestamp );
	}

	/**
	 * Test unschedule_all removes all scheduled events.
	 */
	public function test_unschedule_all_removes_events(): void {
		// Enable and schedule the automation.
		Automations::update( $this->automation_id, [ 'enabled' => 1, 'schedule' => 'daily' ] );
		AutomationRunner::schedule( $this->automation_id, 'daily' );

		AutomationRunner::unschedule_all();

		$timestamp = wp_next_scheduled( AutomationRunner::CRON_HOOK, [ $this->automation_id ] );
		$this->assertFalse( $timestamp );
	}

	// -------------------------------------------------------------------------
	// register
	// -------------------------------------------------------------------------

	/**
	 * Test register hooks the run method to the cron hook.
	 */
	public function test_register_hooks_run_action(): void {
		AutomationRunner::register();

		$this->assertGreaterThan(
			0,
			has_action( AutomationRunner::CRON_HOOK, [ AutomationRunner::class, 'run' ] )
		);
	}

	/**
	 * Test register hooks add_cron_schedules to cron_schedules filter.
	 */
	public function test_register_hooks_cron_schedules_filter(): void {
		AutomationRunner::register();

		$this->assertGreaterThan(
			0,
			has_filter( 'cron_schedules', [ AutomationRunner::class, 'add_cron_schedules' ] )
		);
	}
}
