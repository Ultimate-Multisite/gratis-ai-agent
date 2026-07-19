<?php
/**
 * Integration tests for EventAutomations (event-driven automations CRUD).
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Automations;

use SdAiAgent\Automations\EventAutomations;
use SdAiAgent\Core\Database;
use WP_UnitTestCase;

/**
 * Test EventAutomations CRUD functionality.
 */
class EventAutomationsTest extends WP_UnitTestCase {

	/**
	 * Build minimal valid event automation data.
	 *
	 * @param array $overrides Field overrides.
	 * @return array
	 */
	private function make_event_data( array $overrides = [] ): array {
		return array_merge(
			[
				'name'             => 'Test Event Automation',
				'description'      => 'Fires on post publish',
				'hook_name'        => 'transition_post_status',
				'prompt_template'  => 'A post was published: {{post.title}}',
				'conditions'       => [],
				'tool_profile'     => '',
				'max_iterations'   => 5,
				'enabled'          => 0,
			],
			$overrides
		);
	}

	/**
	 * Test the database version is available through WordPress's alloptions cache.
	 */
	public function test_database_version_option_is_autoloaded(): void {
		$this->assertArrayHasKey( Database::DB_VERSION_OPTION, wp_load_alloptions() );
	}

	// -------------------------------------------------------------------------
	// table_name
	// -------------------------------------------------------------------------

	/**
	 * Test table_name returns correct prefixed name.
	 */
	public function test_table_name(): void {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'sd_ai_agent_event_automations', EventAutomations::table_name() );
	}

	// -------------------------------------------------------------------------
	// create
	// -------------------------------------------------------------------------

	/**
	 * Test create returns a positive integer ID.
	 */
	public function test_create_returns_id(): void {
		$id = EventAutomations::create( $this->make_event_data() );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	/**
	 * Test create stores all fields correctly.
	 */
	public function test_create_stores_fields(): void {
		$data = $this->make_event_data( [
			'name'            => 'Post Published',
			'description'     => 'Fires when a post is published',
			'hook_name'       => 'transition_post_status',
			'prompt_template' => 'Post {{post.title}} was published.',
			'max_iterations'  => 3,
			'enabled'         => 0,
		] );

		$id  = EventAutomations::create( $data );
		$row = EventAutomations::get( $id );

		$this->assertNotNull( $row );
		$this->assertSame( 'Post Published', $row['name'] );
		$this->assertSame( 'Fires when a post is published', $row['description'] );
		$this->assertSame( 'transition_post_status', $row['hook_name'] );
		$this->assertSame( 'Post {{post.title}} was published.', $row['prompt_template'] );
		$this->assertSame( 3, $row['max_iterations'] );
		$this->assertFalse( $row['enabled'] );
	}

	/**
	 * Test create stores conditions as decoded array.
	 */
	public function test_create_stores_conditions_as_array(): void {
		$conditions = [ 'post_type' => 'post', 'new_status' => 'publish' ];
		$id         = EventAutomations::create( $this->make_event_data( [ 'conditions' => $conditions ] ) );
		$row        = EventAutomations::get( $id );

		$this->assertIsArray( $row['conditions'] );
		$this->assertSame( 'post', $row['conditions']['post_type'] );
		$this->assertSame( 'publish', $row['conditions']['new_status'] );
	}

	/**
	 * Test create with empty conditions stores empty array.
	 */
	public function test_create_empty_conditions(): void {
		$id  = EventAutomations::create( $this->make_event_data( [ 'conditions' => [] ] ) );
		$row = EventAutomations::get( $id );

		$this->assertIsArray( $row['conditions'] );
		$this->assertEmpty( $row['conditions'] );
	}

	/**
	 * Test create defaults max_iterations to 5.
	 */
	public function test_create_defaults_max_iterations(): void {
		$data = $this->make_event_data();
		unset( $data['max_iterations'] );

		$id  = EventAutomations::create( $data );
		$row = EventAutomations::get( $id );

		$this->assertSame( 5, $row['max_iterations'] );
	}

	/**
	 * Test create sets run_count to 0.
	 */
	public function test_create_sets_run_count_zero(): void {
		$id  = EventAutomations::create( $this->make_event_data() );
		$row = EventAutomations::get( $id );

		$this->assertSame( 0, $row['run_count'] );
	}

	/**
	 * Test create sets timestamps.
	 */
	public function test_create_sets_timestamps(): void {
		$id  = EventAutomations::create( $this->make_event_data() );
		$row = EventAutomations::get( $id );

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
		$this->assertNull( EventAutomations::get( 999999 ) );
	}

	/**
	 * Test get returns array with expected keys.
	 */
	public function test_get_returns_expected_keys(): void {
		$id  = EventAutomations::create( $this->make_event_data() );
		$row = EventAutomations::get( $id );

		$expected_keys = [
			'id', 'name', 'description', 'hook_name', 'prompt_template',
			'conditions', 'tool_profile', 'max_iterations', 'enabled',
			'run_count', 'last_run_at', 'created_at', 'updated_at',
		];

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $row, "Missing key: {$key}" );
		}
	}

	/**
	 * Test get casts id to int.
	 */
	public function test_get_casts_id_to_int(): void {
		$id  = EventAutomations::create( $this->make_event_data() );
		$row = EventAutomations::get( $id );

		$this->assertIsInt( $row['id'] );
		$this->assertSame( $id, $row['id'] );
	}

	/**
	 * Test get casts enabled to bool.
	 */
	public function test_get_casts_enabled_to_bool(): void {
		$id  = EventAutomations::create( $this->make_event_data( [ 'enabled' => 0 ] ) );
		$row = EventAutomations::get( $id );

		$this->assertIsBool( $row['enabled'] );
	}

	// -------------------------------------------------------------------------
	// list
	// -------------------------------------------------------------------------

	/**
	 * Test list returns all event automations.
	 */
	public function test_list_returns_all(): void {
		EventAutomations::create( $this->make_event_data( [ 'name' => 'Event A' ] ) );
		EventAutomations::create( $this->make_event_data( [ 'name' => 'Event B' ] ) );

		$all = EventAutomations::list();

		$this->assertIsArray( $all );
		$this->assertGreaterThanOrEqual( 2, count( $all ) );
	}

	/**
	 * Test list with enabled_only=true returns only enabled events.
	 */
	public function test_list_enabled_only(): void {
		EventAutomations::create( $this->make_event_data( [ 'name' => 'Disabled Event', 'enabled' => 0 ] ) );
		EventAutomations::create( $this->make_event_data( [ 'name' => 'Enabled Event', 'enabled' => 1 ] ) );

		$enabled = EventAutomations::list( true );

		foreach ( $enabled as $row ) {
			$this->assertTrue( $row['enabled'], 'list(true) should only return enabled events' );
		}
	}

	/**
	 * Test list returns results ordered by name ASC.
	 */
	public function test_list_ordered_by_name(): void {
		EventAutomations::create( $this->make_event_data( [ 'name' => 'Zebra Event' ] ) );
		EventAutomations::create( $this->make_event_data( [ 'name' => 'Alpha Event' ] ) );

		$all   = EventAutomations::list();
		$names = array_column( $all, 'name' );

		$sorted = $names;
		sort( $sorted );

		$this->assertSame( $sorted, $names );
	}

	/**
	 * Test list returns empty when the current site has not installed the database.
	 */
	public function test_list_returns_empty_when_database_is_not_installed(): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$installed_version = get_option( Database::DB_VERSION_OPTION, false );
		delete_option( Database::DB_VERSION_OPTION );
		$wpdb->last_error = '';

		try {
			$this->assertSame( [], EventAutomations::list( true ) );
			$this->assertSame( '', $wpdb->last_error );
		} finally {
			if ( false === $installed_version ) {
				delete_option( Database::DB_VERSION_OPTION );
			} else {
				update_option( Database::DB_VERSION_OPTION, $installed_version );
			}
		}
	}

	// -------------------------------------------------------------------------
	// update
	// -------------------------------------------------------------------------

	/**
	 * Test update returns false for non-existent ID.
	 */
	public function test_update_returns_false_for_missing_id(): void {
		$this->assertFalse( EventAutomations::update( 999999, [ 'name' => 'Ghost' ] ) );
	}

	/**
	 * Test update modifies name field.
	 */
	public function test_update_name(): void {
		$id = EventAutomations::create( $this->make_event_data( [ 'name' => 'Original' ] ) );

		$result = EventAutomations::update( $id, [ 'name' => 'Updated Name' ] );

		$this->assertTrue( $result );
		$this->assertSame( 'Updated Name', EventAutomations::get( $id )['name'] );
	}

	/**
	 * Test update modifies hook_name field.
	 */
	public function test_update_hook_name(): void {
		$id = EventAutomations::create( $this->make_event_data( [ 'hook_name' => 'user_register' ] ) );

		EventAutomations::update( $id, [ 'hook_name' => 'wp_login' ] );

		$this->assertSame( 'wp_login', EventAutomations::get( $id )['hook_name'] );
	}

	/**
	 * Test update modifies conditions field.
	 */
	public function test_update_conditions(): void {
		$id = EventAutomations::create( $this->make_event_data( [ 'conditions' => [] ] ) );

		$new_conditions = [ 'post_type' => 'page' ];
		EventAutomations::update( $id, [ 'conditions' => $new_conditions ] );

		$row = EventAutomations::get( $id );
		$this->assertSame( 'page', $row['conditions']['post_type'] );
	}

	/**
	 * Test update modifies enabled field.
	 */
	public function test_update_enabled(): void {
		$id = EventAutomations::create( $this->make_event_data( [ 'enabled' => 0 ] ) );

		EventAutomations::update( $id, [ 'enabled' => 1 ] );

		$this->assertTrue( EventAutomations::get( $id )['enabled'] );
	}

	/**
	 * Test update with empty data returns true (no-op).
	 */
	public function test_update_empty_data_returns_true(): void {
		$id = EventAutomations::create( $this->make_event_data() );

		$this->assertTrue( EventAutomations::update( $id, [] ) );
	}

	// -------------------------------------------------------------------------
	// delete
	// -------------------------------------------------------------------------

	/**
	 * Test delete removes the event automation.
	 */
	public function test_delete_removes_event(): void {
		$id = EventAutomations::create( $this->make_event_data() );

		$result = EventAutomations::delete( $id );

		$this->assertTrue( $result );
		$this->assertNull( EventAutomations::get( $id ) );
	}

	/**
	 * Test delete returns false for non-existent ID.
	 */
	public function test_delete_nonexistent_returns_false(): void {
		$this->assertFalse( EventAutomations::delete( 999999 ) );
	}

	// -------------------------------------------------------------------------
	// record_run
	// -------------------------------------------------------------------------

	/**
	 * Test record_run increments run_count.
	 */
	public function test_record_run_increments_count(): void {
		$id = EventAutomations::create( $this->make_event_data() );

		EventAutomations::record_run( $id );

		$row = EventAutomations::get( $id );
		$this->assertSame( 1, $row['run_count'] );
	}

	/**
	 * Test record_run sets last_run_at.
	 */
	public function test_record_run_sets_last_run_at(): void {
		$id = EventAutomations::create( $this->make_event_data() );

		EventAutomations::record_run( $id );

		$row = EventAutomations::get( $id );
		$this->assertNotEmpty( $row['last_run_at'] );
	}

	/**
	 * Test record_run accumulates across multiple calls.
	 */
	public function test_record_run_accumulates(): void {
		$id = EventAutomations::create( $this->make_event_data() );

		EventAutomations::record_run( $id );
		EventAutomations::record_run( $id );

		$row = EventAutomations::get( $id );
		$this->assertSame( 2, $row['run_count'] );
	}
}
