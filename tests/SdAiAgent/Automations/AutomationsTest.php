<?php
/**
 * Integration tests for Automations (scheduled automations CRUD).
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Automations;

use SdAiAgent\Automations\AutomationLogs;
use SdAiAgent\Automations\Automations;
use SdAiAgent\Core\Database;
use WP_UnitTestCase;

/**
 * Test Automations CRUD functionality.
 */
class AutomationsTest extends WP_UnitTestCase {

	/**
	 * Minimal valid automation data.
	 *
	 * @return array
	 */
	private function make_automation_data( array $overrides = [] ): array {
		return array_merge(
			[
				'name'        => 'Test Automation',
				'description' => 'A test automation',
				'prompt'      => 'Run a test task.',
				'schedule'    => 'daily',
				'enabled'     => 0,
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
		$this->assertSame( $wpdb->prefix . 'sd_ai_agent_automations', Automations::table_name() );
	}

	// -------------------------------------------------------------------------
	// create
	// -------------------------------------------------------------------------

	/**
	 * Test create returns a positive integer ID.
	 */
	public function test_create_returns_id(): void {
		$id = Automations::create( $this->make_automation_data() );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	/**
	 * Test create stores all provided fields correctly.
	 */
	public function test_create_stores_fields(): void {
		$data = $this->make_automation_data( [
			'name'           => 'My Automation',
			'description'    => 'Does something useful',
			'prompt'         => 'Check the site health.',
			'schedule'       => 'weekly',
			'max_iterations' => 5,
			'enabled'        => 0,
		] );

		$id   = Automations::create( $data );
		$row  = Automations::get( $id );

		$this->assertNotNull( $row );
		$this->assertSame( 'My Automation', $row['name'] );
		$this->assertSame( 'Does something useful', $row['description'] );
		$this->assertSame( 'Check the site health.', $row['prompt'] );
		$this->assertSame( 'weekly', $row['schedule'] );
		$this->assertSame( 5, $row['max_iterations'] );
		$this->assertFalse( $row['enabled'] );
	}

	/**
	 * Test create defaults max_iterations to 10 when not provided.
	 */
	public function test_create_defaults_max_iterations(): void {
		$id  = Automations::create( $this->make_automation_data() );
		$row = Automations::get( $id );

		$this->assertSame( 10, $row['max_iterations'] );
	}

	/**
	 * Test create sets run_count to 0.
	 */
	public function test_create_sets_run_count_zero(): void {
		$id  = Automations::create( $this->make_automation_data() );
		$row = Automations::get( $id );

		$this->assertSame( 0, $row['run_count'] );
	}

	/**
	 * Test create sets created_at and updated_at timestamps.
	 */
	public function test_create_sets_timestamps(): void {
		$id  = Automations::create( $this->make_automation_data() );
		$row = Automations::get( $id );

		$this->assertNotEmpty( $row['created_at'] );
		$this->assertNotEmpty( $row['updated_at'] );
	}

	// -------------------------------------------------------------------------
	// get
	// -------------------------------------------------------------------------

	/**
	 * Test get returns null for non-existent ID.
	 */
	public function test_get_returns_null_for_missing_id(): void {
		$this->assertNull( Automations::get( 999999 ) );
	}

	/**
	 * Test get returns array with expected keys.
	 */
	public function test_get_returns_expected_keys(): void {
		$id  = Automations::create( $this->make_automation_data() );
		$row = Automations::get( $id );

		$expected_keys = [
			'id', 'name', 'description', 'prompt', 'schedule', 'cron_expression',
			'tool_profile', 'owner_user_id', 'max_iterations', 'enabled', 'last_run_at',
			'next_run_at', 'run_count', 'active_run_id', 'execution_status',
			'lease_expires_at', 'last_run_id', 'last_run_status', 'last_run_error',
			'created_at', 'updated_at',
		];

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $row, "Missing key: {$key}" );
		}
	}

	/**
	 * Test get casts id to int.
	 */
	public function test_get_casts_id_to_int(): void {
		$id  = Automations::create( $this->make_automation_data() );
		$row = Automations::get( $id );

		$this->assertIsInt( $row['id'] );
		$this->assertSame( $id, $row['id'] );
	}

	/**
	 * Test get casts enabled to bool.
	 */
	public function test_get_casts_enabled_to_bool(): void {
		$id  = Automations::create( $this->make_automation_data( [ 'enabled' => 0 ] ) );
		$row = Automations::get( $id );

		$this->assertIsBool( $row['enabled'] );
	}

	// -------------------------------------------------------------------------
	// list
	// -------------------------------------------------------------------------

	/**
	 * Test list returns all automations.
	 */
	public function test_list_returns_all(): void {
		Automations::create( $this->make_automation_data( [ 'name' => 'List A' ] ) );
		Automations::create( $this->make_automation_data( [ 'name' => 'List B' ] ) );

		$all = Automations::list();

		$this->assertIsArray( $all );
		$this->assertGreaterThanOrEqual( 2, count( $all ) );
	}

	/**
	 * Test list with enabled_only=true returns only enabled automations.
	 */
	public function test_list_enabled_only(): void {
		Automations::create( $this->make_automation_data( [ 'name' => 'Disabled', 'enabled' => 0 ] ) );
		Automations::create( $this->make_automation_data( [ 'name' => 'Enabled', 'enabled' => 1 ] ) );

		$enabled = Automations::list( true );

		foreach ( $enabled as $row ) {
			$this->assertTrue( $row['enabled'], 'list(true) should only return enabled automations' );
		}
	}

	/**
	 * Test list returns results ordered by name ASC.
	 */
	public function test_list_ordered_by_name(): void {
		Automations::create( $this->make_automation_data( [ 'name' => 'Zebra Task' ] ) );
		Automations::create( $this->make_automation_data( [ 'name' => 'Alpha Task' ] ) );

		$all   = Automations::list();
		$names = array_column( $all, 'name' );

		$sorted = $names;
		sort( $sorted );

		$this->assertSame( $sorted, $names );
	}

	// -------------------------------------------------------------------------
	// update
	// -------------------------------------------------------------------------

	/**
	 * Test update returns false for non-existent ID.
	 */
	public function test_update_returns_false_for_missing_id(): void {
		$this->assertFalse( Automations::update( 999999, [ 'name' => 'Ghost' ] ) );
	}

	/**
	 * Test update modifies name field.
	 */
	public function test_update_name(): void {
		$id = Automations::create( $this->make_automation_data( [ 'name' => 'Original' ] ) );

		$result = Automations::update( $id, [ 'name' => 'Updated Name' ] );

		$this->assertTrue( $result );
		$this->assertSame( 'Updated Name', Automations::get( $id )['name'] );
	}

	/**
	 * Test update modifies schedule field.
	 */
	public function test_update_schedule(): void {
		$id = Automations::create( $this->make_automation_data( [ 'schedule' => 'daily' ] ) );

		Automations::update( $id, [ 'schedule' => 'weekly' ] );

		$this->assertSame( 'weekly', Automations::get( $id )['schedule'] );
	}

	/**
	 * Test update modifies enabled field.
	 */
	public function test_update_enabled(): void {
		$id = Automations::create( $this->make_automation_data( [ 'enabled' => 0 ] ) );

		Automations::update( $id, [ 'enabled' => 1 ] );

		$this->assertTrue( Automations::get( $id )['enabled'] );
	}

	/**
	 * Test update with empty data returns true (no-op).
	 */
	public function test_update_empty_data_returns_true(): void {
		$id = Automations::create( $this->make_automation_data() );

		$this->assertTrue( Automations::update( $id, [] ) );
	}

	/**
	 * Test update modifies max_iterations.
	 */
	public function test_update_max_iterations(): void {
		$id = Automations::create( $this->make_automation_data() );

		Automations::update( $id, [ 'max_iterations' => 20 ] );

		$this->assertSame( 20, Automations::get( $id )['max_iterations'] );
	}

	// -------------------------------------------------------------------------
	// delete
	// -------------------------------------------------------------------------

	/**
	 * Test delete removes the automation.
	 */
	public function test_delete_removes_automation(): void {
		$id = Automations::create( $this->make_automation_data() );

		$result = Automations::delete( $id );

		$this->assertTrue( $result );
		$this->assertNull( Automations::get( $id ) );
	}

	/**
	 * Test delete returns false for non-existent ID.
	 */
	public function test_delete_nonexistent_returns_false(): void {
		$this->assertFalse( Automations::delete( 999999 ) );
	}

	// -------------------------------------------------------------------------
	// record_run
	// -------------------------------------------------------------------------

	/**
	 * Test record_run increments run_count.
	 */
	public function test_record_run_increments_count(): void {
		$id  = Automations::create( $this->make_automation_data() );
		$now = current_time( 'mysql', true );

		Automations::record_run( $id, $now );

		$row = Automations::get( $id );
		$this->assertSame( 1, $row['run_count'] );
	}

	/**
	 * Test record_run sets last_run_at.
	 */
	public function test_record_run_sets_last_run_at(): void {
		$id  = Automations::create( $this->make_automation_data() );
		$now = current_time( 'mysql', true );

		Automations::record_run( $id, $now );

		$row = Automations::get( $id );
		$this->assertSame( $now, $row['last_run_at'] );
	}

	/**
	 * Test record_run accumulates run_count across multiple calls.
	 */
	public function test_record_run_accumulates(): void {
		$id  = Automations::create( $this->make_automation_data() );
		$now = current_time( 'mysql', true );

		Automations::record_run( $id, $now );
		Automations::record_run( $id, $now );
		Automations::record_run( $id, $now );

		$row = Automations::get( $id );
		$this->assertSame( 3, $row['run_count'] );
	}

	// -------------------------------------------------------------------------
	// Durable execution lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Test create retains explicit owner and idle execution metadata.
	 */
	public function test_create_stores_owner_and_idle_execution_metadata(): void {
		$id = Automations::create( $this->make_automation_data( [ 'owner_user_id' => 42 ] ) );

		$row = Automations::get( $id );

		$this->assertSame( 42, $row['owner_user_id'] );
		$this->assertSame( 'idle', $row['execution_status'] );
		$this->assertSame( '', $row['active_run_id'] );
		$this->assertSame( '', $row['last_run_id'] );
	}

	/**
	 * Correlated automation and log transitions require transactional storage.
	 */
	public function test_lifecycle_storage_uses_a_transactional_engine(): void {
		$this->assertTrue( Database::has_transactional_automation_storage() );
	}

	/**
	 * The cross-request execution fence must outlive the maximum recoverable
	 * lease, including on hosts with a short default MySQL idle timeout.
	 */
	public function test_execution_fence_session_outlives_maximum_lease(): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-only capture and restoration of the current MySQL session setting.
		$original_wait_timeout = (int) $wpdb->get_var( 'SELECT @@SESSION.wait_timeout' );
		$automation_id         = (int) Automations::create( $this->make_automation_data() );
		$lock_name             = Automations::acquire_execution_lock( $automation_id );

		$this->assertNotNull( $lock_name );
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-only assertion of the execution-fence session lifetime.
			$wait_timeout = (int) $wpdb->get_var( 'SELECT @@SESSION.wait_timeout' );
			$this->assertGreaterThanOrEqual( DAY_IN_SECONDS + HOUR_IN_SECONDS, $wait_timeout );
		} finally {
			Automations::release_execution_lock( (string) $lock_name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore the test connection's original session setting.
			$wpdb->query( $wpdb->prepare( 'SET SESSION wait_timeout = %d', $original_wait_timeout ) );
		}
	}

	/**
	 * Test only one concurrent delivery can atomically claim an enabled row.
	 */
	public function test_claim_run_allows_only_one_delivery(): void {
		$id = Automations::create( $this->make_automation_data( [ 'enabled' => 1 ] ) );

		$this->assertTrue( Automations::claim_run( $id, 'run-one', '2099-01-01 00:00:00' ) );
		$this->assertFalse( Automations::claim_run( $id, 'run-two', '2099-01-01 00:00:00' ) );

		$row = Automations::get( $id );
		$this->assertSame( 'run-one', $row['active_run_id'] );
		$this->assertSame( 'claimed', $row['execution_status'] );

		$this->assertTrue( Automations::finish_run( $id, 'run-one', 'succeeded' ) );
		$row = Automations::get( $id );
		$this->assertSame( '', $row['active_run_id'] );
		$this->assertSame( 'succeeded', $row['last_run_status'] );
	}

	/**
	 * Test an expired claim becomes an inspectable abandoned lifecycle state.
	 */
	public function test_abandon_expired_runs_releases_stale_claim(): void {
		$id     = Automations::create( $this->make_automation_data( [ 'enabled' => 1 ] ) );
		$run_id = 'expired-run';

		$this->assertNotFalse(
			AutomationLogs::create(
				[
					'automation_id'    => $id,
					'run_id'           => $run_id,
					'status'           => 'pending',
					'lifecycle_status' => 'claimed',
					'lease_expires_at' => '2000-01-01 00:00:00',
				]
			)
		);
		$this->assertTrue( Automations::claim_run( $id, $run_id, '2000-01-01 00:00:00' ) );
		$this->assertTrue( Automations::mark_run_running( $id, $run_id ) );
		$this->assertTrue( AutomationLogs::mark_run_running( $run_id ) );
		$this->assertGreaterThanOrEqual( 1, Automations::abandon_expired_runs() );

		$row = Automations::get( $id );
		$this->assertSame( '', $row['active_run_id'] );
		$this->assertSame( 'abandoned', $row['execution_status'] );
		$this->assertSame( $run_id, $row['last_run_id'] );

		$log = AutomationLogs::get_by_run_id( $run_id );
		$this->assertNotNull( $log );
		$this->assertSame( 'abandoned', $log['lifecycle_status'] );
	}

	/**
	 * Test a built-in stored profile resolves to a constrained ability list.
	 */
	public function test_resolve_tool_profile_returns_builtin_allowlist(): void {
		$abilities = Automations::resolve_tool_profile( [ 'tool_profile' => 'site-health' ] );

		$this->assertIsArray( $abilities );
		$this->assertContains( 'sd-ai-agent/site-health-summary', $abilities );
		$this->assertContains( 'sd-ai-agent/ability-search', $abilities );
		$this->assertContains( 'sd-ai-agent/ability-call', $abilities );
	}

	/**
	 * Test an unknown non-empty profile fails closed instead of enabling all tools.
	 */
	public function test_resolve_tool_profile_rejects_unknown_profile(): void {
		$result = Automations::resolve_tool_profile( [ 'tool_profile' => 'unknown-profile' ] );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'sd_ai_agent_automation_unknown_tool_profile', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// get_templates
	// -------------------------------------------------------------------------

	/**
	 * Test get_templates returns a non-empty array.
	 */
	public function test_get_templates_returns_array(): void {
		$templates = Automations::get_templates();

		$this->assertIsArray( $templates );
		$this->assertNotEmpty( $templates );
	}

	/**
	 * Test each template has required keys.
	 */
	public function test_get_templates_have_required_keys(): void {
		$templates = Automations::get_templates();

		foreach ( $templates as $template ) {
			$this->assertArrayHasKey( 'name', $template );
			$this->assertArrayHasKey( 'description', $template );
			$this->assertArrayHasKey( 'prompt', $template );
			$this->assertArrayHasKey( 'schedule', $template );
		}
	}

	/**
	 * Test each template schedule is a valid value.
	 */
	public function test_get_templates_valid_schedules(): void {
		$templates = Automations::get_templates();

		foreach ( $templates as $template ) {
			$this->assertContains(
				$template['schedule'],
				Automations::VALID_SCHEDULES,
				"Template '{$template['name']}' has invalid schedule: {$template['schedule']}"
			);
		}
	}

	// -------------------------------------------------------------------------
	// VALID_SCHEDULES constant
	// -------------------------------------------------------------------------

	/**
	 * Test VALID_SCHEDULES contains expected values.
	 */
	public function test_valid_schedules_constant(): void {
		$this->assertContains( 'hourly', Automations::VALID_SCHEDULES );
		$this->assertContains( 'twicedaily', Automations::VALID_SCHEDULES );
		$this->assertContains( 'daily', Automations::VALID_SCHEDULES );
		$this->assertContains( 'weekly', Automations::VALID_SCHEDULES );
	}
}
