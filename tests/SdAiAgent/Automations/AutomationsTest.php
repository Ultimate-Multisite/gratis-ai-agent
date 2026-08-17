<?php
/**
 * Integration tests for Automations (scheduled automations CRUD).
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Automations;

use SdAiAgent\Automations\Automations;
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

	/** WhatsApp and Telegram notification destinations survive sanitization. */
	public function test_create_stores_messaging_notification_channels(): void {
		$id  = Automations::create(
			$this->make_automation_data(
				[
					'notification_channels' => [
						[ 'type' => 'whatsapp', 'recipient' => '+15551234567', 'enabled' => true ],
						[ 'type' => 'telegram', 'recipient' => '@validchannel', 'enabled' => true ],
					],
				]
			)
		);
		$row = Automations::get( $id );

		$this->assertSame( '+15551234567', $row['notification_channels'][0]['recipient'] ?? '' );
		$this->assertSame( '@validchannel', $row['notification_channels'][1]['recipient'] ?? '' );
		$this->assertArrayNotHasKey( 'webhook_url', $row['notification_channels'][0] );
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
			'tool_profile', 'max_iterations', 'enabled', 'last_run_at', 'next_run_at',
			'run_count', 'created_at', 'updated_at',
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
