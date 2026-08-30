<?php

declare(strict_types=1);
/**
 * Integration tests for opt-in Monitor automation behaviour.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Automations;

use SdAiAgent\Automations\AutomationLogs;
use SdAiAgent\Automations\AutomationRunner;
use SdAiAgent\Automations\Automations;
use SdAiAgent\Automations\NotificationDispatcher;
use SdAiAgent\Core\BudgetManager;
use SdAiAgent\Core\Database;
use SdAiAgent\Core\Settings;
use WP_UnitTestCase;

class MonitorAutomationTest extends WP_UnitTestCase {

	/** @var list<int> Automations whose cron events must be removed. */
	private array $automation_ids = [];

	/** @var mixed Original settings option value. */
	private $previous_settings;

	/** Reset budget controls before each Monitor execution test. */
	public function set_up(): void {
		parent::set_up();

		$this->previous_settings = get_option( Settings::OPTION_NAME, false );
		delete_option( Settings::OPTION_NAME );
		delete_transient( BudgetManager::TRANSIENT_DAILY );
		delete_transient( BudgetManager::TRANSIENT_MONTHLY );
	}

	/** Restore shared budget state and remove test cron events. */
	public function tear_down(): void {
		foreach ( $this->automation_ids as $automation_id ) {
			AutomationRunner::unschedule( $automation_id );
		}

		if ( false === $this->previous_settings ) {
			delete_option( Settings::OPTION_NAME );
		} else {
			update_option( Settings::OPTION_NAME, $this->previous_settings );
		}

		delete_transient( BudgetManager::TRANSIENT_DAILY );
		delete_transient( BudgetManager::TRANSIENT_MONTHLY );
		parent::tear_down();
	}

	/**
	 * Create and track a Monitor definition for one test.
	 *
	 * @param array<string, mixed> $overrides Monitor fields to override.
	 */
	private function create_monitor( array $overrides = [] ): int {
		$id = Automations::create(
			array_merge(
				[
					'name'            => 'Monitor test automation',
					'prompt'          => 'Assess the current site state.',
					'mode'            => Automations::MONITOR_MODE,
					'monitor_scratch' => '',
					'schedule'        => 'daily',
				],
				$overrides
			)
		);

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
		$this->automation_ids[] = (int) $id;

		return (int) $id;
	}

	/** A Monitor is disabled by default and carries UI-ready WP-Cron guidance. */
	public function test_monitor_defaults_to_disabled_with_timing_help(): void {
		$monitor_id = $this->create_monitor();
		$monitor    = Automations::get( $monitor_id );

		$this->assertNotNull( $monitor );
		$this->assertSame( Automations::MONITOR_MODE, $monitor['mode'] );
		$this->assertFalse( $monitor['enabled'] );
		$this->assertFalse( wp_next_scheduled( AutomationRunner::CRON_HOOK, [ $monitor_id ] ) );
		$this->assertStringContainsString( 'WP-Cron', $monitor['monitor_timing_help'] );
		$this->assertStringContainsString( 'real system cron', $monitor['monitor_timing_help'] );
	}

	/** Explicit enable schedules an inspectable, jittered Monitor; disable removes only its future schedule. */
	public function test_monitor_enable_and_disable_manage_only_future_schedule(): void {
		$monitor_id = $this->create_monitor();
		$before     = time();

		$this->assertTrue( Automations::update( $monitor_id, [ 'enabled' => 1 ] ) );
		$timestamp = wp_next_scheduled( AutomationRunner::CRON_HOOK, [ $monitor_id ] );
		$monitor   = Automations::get( $monitor_id );

		$this->assertIsInt( $timestamp );
		$this->assertGreaterThanOrEqual( $before + AutomationRunner::MONITOR_START_DELAY_SECONDS, $timestamp );
		$this->assertLessThanOrEqual( $before + AutomationRunner::MONITOR_START_DELAY_SECONDS + AutomationRunner::MONITOR_JITTER_SECONDS + 1, $timestamp );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $timestamp ), $monitor['next_run_at'] );

		$this->assertTrue( Automations::update( $monitor_id, [ 'enabled' => 0 ] ) );
		$this->assertFalse( wp_next_scheduled( AutomationRunner::CRON_HOOK, [ $monitor_id ] ) );
		$this->assertNull( Automations::get( $monitor_id )['next_run_at'] );
	}

	/** Empty checklist input completes quietly before any provider credentials or model work are loaded. */
	public function test_empty_monitor_scratch_runs_quietly_without_provider_work(): void {
		$owner_id   = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$monitor_id = $this->create_monitor(
			[
				'monitor_scratch' => " \n\t",
				'owner_user_id'   => $owner_id,
				'enabled'         => 1,
			]
		);

		$result = AutomationRunner::run( $monitor_id );
		$monitor = Automations::get( $monitor_id );
		$log     = AutomationLogs::get_by_run_id( $result['run_id'] );

		$this->assertSame( 'success', $result['status'] );
		$this->assertSame( 'succeeded', $result['lifecycle_status'] );
		$this->assertSame( 'quiet', $result['monitor_outcome'] );
		$this->assertSame( 'quiet', $monitor['last_monitor_outcome'] );
		$this->assertSame( '', $monitor['last_monitor_summary'] );
		$this->assertNotNull( $log );
		$this->assertSame( 'quiet', $log['monitor_outcome'] );

		$this->assertTrue( Automations::update( $monitor_id, [ 'enabled' => 0 ] ) );
		$this->assertFalse( wp_next_scheduled( AutomationRunner::CRON_HOOK, [ $monitor_id ] ) );
		$this->assertSame( 'quiet', AutomationLogs::get_by_run_id( $result['run_id'] )['monitor_outcome'] );
	}

	/** Monitor budget checks remain before provider work and preserve a blocked result. */
	public function test_budget_exceeded_monitor_is_blocked_before_provider_work(): void {
		update_option(
			Settings::OPTION_NAME,
			[
				'budget_daily_cap'       => 1.00,
				'budget_monthly_cap'     => 0,
				'budget_exceeded_action' => 'pause',
			]
		);
		set_transient( BudgetManager::TRANSIENT_DAILY, 1.00, BudgetManager::CACHE_TTL );

		$owner_id   = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$monitor_id = $this->create_monitor(
			[
				'monitor_scratch' => 'Check backups.',
				'owner_user_id'   => $owner_id,
				'enabled'         => 1,
			]
		);

		$result = AutomationRunner::run( $monitor_id );
		$monitor = Automations::get( $monitor_id );
		$log     = AutomationLogs::get_by_run_id( $result['run_id'] );

		$this->assertSame( 'blocked', $result['lifecycle_status'] );
		$this->assertSame( 'blocked', $result['monitor_outcome'] );
		$this->assertSame( 'blocked', $monitor['last_monitor_outcome'] );
		$this->assertNotNull( $log );
		$this->assertSame( 'blocked', $log['monitor_outcome'] );
	}

	/** Recovery preserves an inspectable Monitor error rather than dropping outcome state. */
	public function test_stale_monitor_recovery_records_error_outcome(): void {
		$monitor_id = $this->create_monitor( [ 'enabled' => 1 ] );
		$run_id     = '11111111-2222-4333-8444-555555555555';

		$this->assertNotFalse(
			AutomationLogs::create(
				[
					'automation_id'    => $monitor_id,
					'run_id'           => $run_id,
					'status'           => 'pending',
					'lifecycle_status' => 'claimed',
					'lease_expires_at' => '2000-01-01 00:00:00',
				]
			)
		);
		$this->assertTrue( Automations::claim_run( $monitor_id, $run_id, '2000-01-01 00:00:00' ) );
		$this->assertTrue( Automations::mark_run_running( $monitor_id, $run_id ) );
		$this->assertTrue( AutomationLogs::mark_run_running( $run_id ) );
		$this->assertSame( 1, Automations::abandon_expired_runs() );

		$monitor = Automations::get( $monitor_id );
		$log     = AutomationLogs::get_by_run_id( $run_id );

		$this->assertSame( 'abandoned', $monitor['execution_status'] );
		$this->assertSame( 'error', $monitor['last_monitor_outcome'] );
		$this->assertNotNull( $log );
		$this->assertSame( 'abandoned', $log['lifecycle_status'] );
		$this->assertSame( 'error', $log['monitor_outcome'] );
	}

	/** Orphan cleanup preserves an error outcome for an interrupted Monitor log. */
	public function test_orphaned_monitor_log_recovery_records_error_outcome(): void {
		$monitor_id = $this->create_monitor();
		$run_id     = '22222222-3333-4444-8555-666666666666';

		$this->assertNotFalse(
			AutomationLogs::create(
				[
					'automation_id'    => $monitor_id,
					'run_id'           => $run_id,
					'status'           => 'pending',
					'lifecycle_status' => 'claimed',
					'lease_expires_at' => '2000-01-01 00:00:00',
				]
			)
		);

		$this->assertSame( 1, AutomationLogs::abandon_expired_runs() );
		$log = AutomationLogs::get_by_run_id( $run_id );

		$this->assertNotNull( $log );
		$this->assertSame( 'abandoned', $log['lifecycle_status'] );
		$this->assertSame( 'error', $log['monitor_outcome'] );
	}

	/** Only a validated notify result can reach a configured user-facing channel. */
	public function test_only_notify_monitor_outcome_dispatches_notification(): void {
		$requests = 0;
		$handler  = static function () use ( &$requests ): array {
			++$requests;

			return [
				'headers'  => [],
				'body'     => '',
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies'  => [],
				'filename' => '',
			];
		};
		add_filter( 'pre_http_request', $handler );

		$automation = [
			'mode'                  => Automations::MONITOR_MODE,
			'name'                  => 'Notification Monitor',
			'schedule'              => 'daily',
			'notification_channels' => [
				[
					'type'        => 'slack',
					'webhook_url' => 'https://example.test/monitor',
					'enabled'     => true,
				],
			],
		];

		try {
			foreach ( [ 'quiet', 'blocked', 'error' ] as $outcome ) {
				NotificationDispatcher::dispatch( $automation, [ 'monitor_outcome' => $outcome ] );
			}
			$this->assertSame( 0, $requests );

			NotificationDispatcher::dispatch( $automation, [ 'monitor_outcome' => 'notify' ] );
			$this->assertSame( 1, $requests );
		} finally {
			remove_filter( 'pre_http_request', $handler );
		}
	}

	/** Existing task rows keep task semantics, and unsupported monitor fields fail closed. */
	public function test_tasks_remain_tasks_and_unknown_monitor_input_is_rejected(): void {
		$task_id = Automations::create(
			[
				'name'    => 'Existing task',
				'prompt'  => 'Run ordinary work.',
				'enabled' => 1,
			]
		);
		$this->automation_ids[] = (int) $task_id;
		$task = Automations::get( (int) $task_id );

		$this->assertSame( Automations::TASK_MODE, $task['mode'] );
		$this->assertTrue( $task['enabled'] );
		$this->assertSame( '', $task['monitor_timing_help'] );
		$this->assertFalse(
			Automations::create(
				[
					'name'           => 'Invalid Monitor',
					'prompt'         => 'This must not persist.',
					'mode'           => Automations::MONITOR_MODE,
					'monitor_unknown' => 'reject me',
				]
			)
		);
	}

	/** A schema upgrade leaves existing task rows intact and does not seed a Monitor. */
	public function test_database_upgrade_creates_no_monitor_records(): void {
		$task_id = Automations::create(
			[
				'name'    => 'Upgrade compatibility task',
				'prompt'  => 'Remain an ordinary task.',
				'enabled' => 1,
			]
		);
		$this->automation_ids[] = (int) $task_id;
		$previous_version       = get_option( Database::DB_VERSION_OPTION, false );

		try {
			update_option( Database::DB_VERSION_OPTION, '19.12.0' );
			Database::install();

			$automations = Automations::list();
			$monitors    = array_filter( $automations, [ Automations::class, 'is_monitor' ] );
			$task        = Automations::get( (int) $task_id );

			$this->assertSame( [], array_values( $monitors ) );
			$this->assertSame( Automations::TASK_MODE, $task['mode'] );
			$this->assertTrue( $task['enabled'] );
		} finally {
			if ( false === $previous_version ) {
				delete_option( Database::DB_VERSION_OPTION );
			} else {
				update_option( Database::DB_VERSION_OPTION, $previous_version );
			}
		}
	}

	/** Event wakes require separately selected, strict Monitor sources. */
	public function test_monitor_event_wakes_require_approved_sources(): void {
		$missing_sources = Automations::validate_definition(
			[
				'mode'                        => Automations::MONITOR_MODE,
				'monitor_event_wakes_enabled' => true,
				'monitor_event_sources'       => [],
			]
		);
		$this->assertWPError( $missing_sources );
		$this->assertSame( 'sd_ai_agent_automation_monitor_event_wakes_requires_source', $missing_sources->get_error_code() );

		$unapproved_source = Automations::validate_definition(
			[
				'mode'                  => Automations::MONITOR_MODE,
				'monitor_event_sources' => [ 'user_register' ],
			]
		);
		$this->assertWPError( $unapproved_source );
		$this->assertSame( 'sd_ai_agent_automation_monitor_event_source_not_allowed', $unapproved_source->get_error_code() );

		$monitor_id = $this->create_monitor(
			[
				'monitor_event_wakes_enabled' => true,
				'monitor_event_sources'       => [ 'delete_post', 'delete_post', 'add_attachment' ],
			]
		);
		$monitor    = Automations::get( $monitor_id );

		$this->assertNotNull( $monitor );
		$this->assertTrue( $monitor['monitor_event_wakes_enabled'] );
		$this->assertSame( [ 'delete_post', 'add_attachment' ], $monitor['monitor_event_sources'] );
	}
}
