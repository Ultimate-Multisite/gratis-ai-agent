<?php
/**
 * Integration tests for AutomationLogs.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Automations;

use SdAiAgent\Automations\AutomationLogs;
use SdAiAgent\Automations\Automations;
use WP_UnitTestCase;

/**
 * Test AutomationLogs functionality.
 */
class AutomationLogsTest extends WP_UnitTestCase {

	/**
	 * Automation ID created for each test.
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
			'name'    => 'Log Test Automation',
			'prompt'  => 'Test prompt',
			'enabled' => 0,
		] );
	}

	/**
	 * Build minimal log data for the current automation.
	 *
	 * @param array $overrides Field overrides.
	 * @return array
	 */
	private function make_log_data( array $overrides = [] ): array {
		return array_merge(
			[
				'automation_id'     => $this->automation_id,
				'trigger_type'      => 'scheduled',
				'trigger_name'      => '',
				'status'            => 'success',
				'reply'             => 'The task completed successfully.',
				'tool_calls'        => [],
				'prompt_tokens'     => 100,
				'completion_tokens' => 50,
				'duration_ms'       => 1234,
				'error_message'     => '',
			],
			$overrides
		);
	}

	// -------------------------------------------------------------------------
	// table_name
	// -------------------------------------------------------------------------

	/**
	 * Test table_name returns correct prefixed name.
	 */
	public function test_table_name(): void {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'sd_ai_agent_automation_logs', AutomationLogs::table_name() );
	}

	// -------------------------------------------------------------------------
	// create
	// -------------------------------------------------------------------------

	/**
	 * Test create returns a positive integer ID.
	 */
	public function test_create_returns_id(): void {
		$id = AutomationLogs::create( $this->make_log_data() );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	/**
	 * Test create stores all fields correctly.
	 */
	public function test_create_stores_fields(): void {
		$data = $this->make_log_data( [
			'status'            => 'error',
			'reply'             => 'Something went wrong.',
			'prompt_tokens'     => 200,
			'completion_tokens' => 80,
			'duration_ms'       => 5000,
			'error_message'     => 'Provider timeout',
		] );

		$id  = AutomationLogs::create( $data );
		$log = AutomationLogs::get( $id );

		$this->assertNotNull( $log );
		$this->assertSame( $this->automation_id, $log['automation_id'] );
		$this->assertSame( 'error', $log['status'] );
		$this->assertSame( 'Something went wrong.', $log['reply'] );
		$this->assertSame( 200, $log['prompt_tokens'] );
		$this->assertSame( 80, $log['completion_tokens'] );
		$this->assertSame( 5000, $log['duration_ms'] );
		$this->assertSame( 'Provider timeout', $log['error_message'] );
	}

	/**
	 * Test create stores tool_calls as decoded array.
	 */
	public function test_create_stores_tool_calls_as_array(): void {
		$tool_calls = [ [ 'name' => 'get_posts', 'result' => 'ok' ] ];
		$id         = AutomationLogs::create( $this->make_log_data( [ 'tool_calls' => $tool_calls ] ) );
		$log        = AutomationLogs::get( $id );

		$this->assertIsArray( $log['tool_calls'] );
		$this->assertCount( 1, $log['tool_calls'] );
		$this->assertSame( 'get_posts', $log['tool_calls'][0]['name'] );
	}

	/**
	 * Test create sets created_at timestamp.
	 */
	public function test_create_sets_created_at(): void {
		$id  = AutomationLogs::create( $this->make_log_data() );
		$log = AutomationLogs::get( $id );

		$this->assertNotEmpty( $log['created_at'] );
	}

	/**
	 * Test create with event trigger_type stores trigger_name.
	 */
	public function test_create_event_trigger_type(): void {
		$id = AutomationLogs::create( $this->make_log_data( [
			'trigger_type' => 'event',
			'trigger_name' => 'transition_post_status',
		] ) );

		$log = AutomationLogs::get( $id );

		$this->assertSame( 'event', $log['trigger_type'] );
		$this->assertSame( 'transition_post_status', $log['trigger_name'] );
	}

	// -------------------------------------------------------------------------
	// get
	// -------------------------------------------------------------------------

	/**
	 * Test get returns null for non-existent ID.
	 */
	public function test_get_returns_null_for_missing_id(): void {
		$this->assertNull( AutomationLogs::get( 999999 ) );
	}

	/**
	 * Test get returns array with expected keys.
	 */
	public function test_get_returns_expected_keys(): void {
		$id  = AutomationLogs::create( $this->make_log_data() );
		$log = AutomationLogs::get( $id );

		$expected_keys = [
			'id', 'automation_id', 'run_id', 'owner_user_id', 'trigger_type',
			'trigger_name', 'status', 'lifecycle_status',
			'reply', 'tool_calls', 'prompt_tokens', 'completion_tokens',
			'duration_ms', 'error_message', 'lease_expires_at', 'started_at',
			'finished_at', 'created_at',
		];

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $log, "Missing key: {$key}" );
		}
	}

	/**
	 * Test get casts numeric fields to int.
	 */
	public function test_get_casts_numeric_fields(): void {
		$id  = AutomationLogs::create( $this->make_log_data() );
		$log = AutomationLogs::get( $id );

		$this->assertIsInt( $log['id'] );
		$this->assertIsInt( $log['automation_id'] );
		$this->assertIsInt( $log['prompt_tokens'] );
		$this->assertIsInt( $log['completion_tokens'] );
		$this->assertIsInt( $log['duration_ms'] );
	}

	// -------------------------------------------------------------------------
	// Durable execution lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Test a claimed run transitions through running to a scrubbed terminal log.
	 */
	public function test_run_lifecycle_has_correlation_and_scrubbed_tool_summary(): void {
		$run_id = '11111111-2222-4333-8444-555555555555';
		$id     = AutomationLogs::create(
			$this->make_log_data(
				[
					'run_id'           => $run_id,
					'owner_user_id'    => 42,
					'status'           => 'pending',
					'lifecycle_status' => 'claimed',
					'lease_expires_at' => '2099-01-01 00:00:00',
				]
			)
		);

		$this->assertTrue( AutomationLogs::mark_run_running( $run_id ) );
		$this->assertTrue(
			AutomationLogs::complete_run(
				$run_id,
				'succeeded',
				[
					'reply'             => 'Completed safely.',
					'tool_calls'        => [ [ 'name' => 'sd-ai-agent/site-health-summary', 'arguments' => [ 'secret' => 'hidden' ], 'response' => 'sensitive' ] ],
					'prompt_tokens'     => 10,
					'completion_tokens' => 5,
					'duration_ms'       => 20,
				]
			)
		);

		$log = AutomationLogs::get_by_run_id( $run_id );

		$this->assertNotNull( $log );
		$this->assertSame( $id, $log['id'] );
		$this->assertSame( 42, $log['owner_user_id'] );
		$this->assertSame( 'success', $log['status'] );
		$this->assertSame( 'succeeded', $log['lifecycle_status'] );
		$this->assertNotEmpty( $log['started_at'] );
		$this->assertNotEmpty( $log['finished_at'] );
		$this->assertSame( 'sd-ai-agent/site-health-summary', $log['tool_calls'][0]['name'] );
		$this->assertArrayNotHasKey( 'arguments', $log['tool_calls'][0] );
		$this->assertArrayNotHasKey( 'response', $log['tool_calls'][0] );
	}

	/**
	 * Test stale active logs are retained as abandoned rather than disappearing.
	 */
	public function test_abandon_expired_runs_retains_terminal_evidence(): void {
		$run_id = '99999999-2222-4333-8444-555555555555';
		AutomationLogs::create(
			$this->make_log_data(
				[
					'run_id'           => $run_id,
					'status'           => 'pending',
					'lifecycle_status' => 'running',
					'lease_expires_at' => '2000-01-01 00:00:00',
				]
			)
		);

		$this->assertGreaterThanOrEqual( 1, AutomationLogs::abandon_expired_runs() );

		$log = AutomationLogs::get_by_run_id( $run_id );
		$this->assertNotNull( $log );
		$this->assertSame( 'error', $log['status'] );
		$this->assertSame( 'abandoned', $log['lifecycle_status'] );
		$this->assertNull( $log['lease_expires_at'] );
		$this->assertNotEmpty( $log['finished_at'] );
	}

	// -------------------------------------------------------------------------
	// list_for_automation
	// -------------------------------------------------------------------------

	/**
	 * Test list_for_automation returns logs for the given automation.
	 */
	public function test_list_for_automation(): void {
		AutomationLogs::create( $this->make_log_data() );
		AutomationLogs::create( $this->make_log_data() );

		$logs = AutomationLogs::list_for_automation( $this->automation_id );

		$this->assertIsArray( $logs );
		$this->assertGreaterThanOrEqual( 2, count( $logs ) );

		foreach ( $logs as $log ) {
			$this->assertSame( $this->automation_id, $log['automation_id'] );
		}
	}

	/**
	 * Test list_for_automation returns empty array for unknown automation.
	 */
	public function test_list_for_automation_unknown_id(): void {
		$logs = AutomationLogs::list_for_automation( 999999 );

		$this->assertIsArray( $logs );
		$this->assertEmpty( $logs );
	}

	/**
	 * Test list_for_automation respects limit parameter.
	 */
	public function test_list_for_automation_limit(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			AutomationLogs::create( $this->make_log_data() );
		}

		$logs = AutomationLogs::list_for_automation( $this->automation_id, 3 );

		$this->assertCount( 3, $logs );
	}

	/**
	 * Test list_for_automation returns results ordered by created_at DESC.
	 */
	public function test_list_for_automation_ordered_desc(): void {
		AutomationLogs::create( $this->make_log_data( [ 'reply' => 'First' ] ) );
		AutomationLogs::create( $this->make_log_data( [ 'reply' => 'Second' ] ) );

		$logs = AutomationLogs::list_for_automation( $this->automation_id );

		// Most recent should be first.
		$this->assertGreaterThanOrEqual( 2, count( $logs ) );
		$ids = array_column( $logs, 'id' );
		$this->assertGreaterThan( $ids[1], $ids[0] );
	}

	// -------------------------------------------------------------------------
	// list_recent
	// -------------------------------------------------------------------------

	/**
	 * Test list_recent returns logs across all automations.
	 */
	public function test_list_recent(): void {
		$other_id = (int) Automations::create( [
			'name'    => 'Other Automation',
			'prompt'  => 'Other prompt',
			'enabled' => 0,
		] );

		AutomationLogs::create( $this->make_log_data() );
		AutomationLogs::create( $this->make_log_data( [ 'automation_id' => $other_id ] ) );

		$logs = AutomationLogs::list_recent( 50 );

		$this->assertIsArray( $logs );
		$this->assertGreaterThanOrEqual( 2, count( $logs ) );
	}

	/**
	 * Test list_recent respects limit parameter.
	 */
	public function test_list_recent_limit(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			AutomationLogs::create( $this->make_log_data() );
		}

		$logs = AutomationLogs::list_recent( 2 );

		$this->assertCount( 2, $logs );
	}

	// -------------------------------------------------------------------------
	// delete_for_automation
	// -------------------------------------------------------------------------

	/**
	 * Test delete_for_automation removes all logs for an automation.
	 */
	public function test_delete_for_automation(): void {
		AutomationLogs::create( $this->make_log_data() );
		AutomationLogs::create( $this->make_log_data() );

		$deleted = AutomationLogs::delete_for_automation( $this->automation_id );

		$this->assertGreaterThanOrEqual( 2, $deleted );

		$remaining = AutomationLogs::list_for_automation( $this->automation_id );
		$this->assertEmpty( $remaining );
	}

	/**
	 * Test delete_for_automation returns 0 when no logs exist.
	 */
	public function test_delete_for_automation_no_logs(): void {
		$deleted = AutomationLogs::delete_for_automation( 999999 );

		$this->assertSame( 0, $deleted );
	}

	/**
	 * Test delete_for_automation does not affect other automations' logs.
	 */
	public function test_delete_for_automation_isolates(): void {
		$other_id = (int) Automations::create( [
			'name'    => 'Isolated Automation',
			'prompt'  => 'Isolated prompt',
			'enabled' => 0,
		] );

		AutomationLogs::create( $this->make_log_data() );
		AutomationLogs::create( $this->make_log_data( [ 'automation_id' => $other_id ] ) );

		AutomationLogs::delete_for_automation( $this->automation_id );

		$other_logs = AutomationLogs::list_for_automation( $other_id );
		$this->assertNotEmpty( $other_logs );
	}

	// -------------------------------------------------------------------------
	// prune
	// -------------------------------------------------------------------------

	/**
	 * Test prune keeps only the specified number of logs per automation.
	 */
	public function test_prune_keeps_limit(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			AutomationLogs::create( $this->make_log_data() );
		}

		AutomationLogs::prune( 3 );

		$remaining = AutomationLogs::list_for_automation( $this->automation_id );
		$this->assertCount( 3, $remaining );
	}

	/**
	 * Test prune does not delete when count is within limit.
	 */
	public function test_prune_no_delete_within_limit(): void {
		AutomationLogs::create( $this->make_log_data() );
		AutomationLogs::create( $this->make_log_data() );

		AutomationLogs::prune( 10 );

		$remaining = AutomationLogs::list_for_automation( $this->automation_id );
		$this->assertGreaterThanOrEqual( 2, count( $remaining ) );
	}
}
