<?php
/**
 * Test case for Database class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\Database;
use WP_UnitTestCase;

/**
 * Test Database functionality.
 */
class DatabaseTest extends WP_UnitTestCase {

	/**
	 * Test table_name returns correct table name.
	 */
	public function test_table_name() {
		global $wpdb;
		$expected = $wpdb->prefix . 'sd_ai_agent_sessions';
		$this->assertSame( $expected, Database::table_name() );
	}

	/**
	 * Test usage_table_name returns correct table name.
	 */
	public function test_usage_table_name() {
		global $wpdb;
		$expected = $wpdb->prefix . 'sd_ai_agent_usage';
		$this->assertSame( $expected, Database::usage_table_name() );
	}

	/**
	 * Test memories_table_name returns correct table name.
	 */
	public function test_memories_table_name() {
		global $wpdb;
		$expected = $wpdb->prefix . 'sd_ai_agent_memories';
		$this->assertSame( $expected, Database::memories_table_name() );
	}

	/**
	 * Test skills_table_name returns correct table name.
	 */
	public function test_skills_table_name() {
		global $wpdb;
		$expected = $wpdb->prefix . 'sd_ai_agent_skills';
		$this->assertSame( $expected, Database::skills_table_name() );
	}

	/**
	 * Test custom_tools_table_name returns correct table name.
	 */
	public function test_custom_tools_table_name() {
		global $wpdb;
		$expected = $wpdb->prefix . 'sd_ai_agent_custom_tools';
		$this->assertSame( $expected, Database::custom_tools_table_name() );
	}

	/**
	 * Test automations_table_name returns correct table name.
	 */
	public function test_automations_table_name() {
		global $wpdb;
		$expected = $wpdb->prefix . 'sd_ai_agent_automations';
		$this->assertSame( $expected, Database::automations_table_name() );
	}

	/**
	 * Test automation_logs_table_name returns correct table name.
	 */
	public function test_automation_logs_table_name() {
		global $wpdb;
		$expected = $wpdb->prefix . 'sd_ai_agent_automation_logs';
		$this->assertSame( $expected, Database::automation_logs_table_name() );
	}

	/**
	 * Test approval_requests_table_name returns correct table name.
	 */
	public function test_approval_requests_table_name() {
		global $wpdb;
		$expected = $wpdb->prefix . 'sd_ai_agent_approval_requests';
		$this->assertSame( $expected, Database::approval_requests_table_name() );
	}

	/**
	 * Test event_automations_table_name returns correct table name.
	 */
	public function test_event_automations_table_name() {
		global $wpdb;
		$expected = $wpdb->prefix . 'sd_ai_agent_event_automations';
		$this->assertSame( $expected, Database::event_automations_table_name() );
	}

	/**
	 * Test DB_VERSION constant exists.
	 */
	public function test_db_version_constant() {
		$this->assertNotEmpty( Database::DB_VERSION );
		$this->assertIsString( Database::DB_VERSION );
	}

	/**
	 * Test DB_VERSION_OPTION constant exists.
	 */
	public function test_db_version_option_constant() {
		$this->assertSame( 'sd_ai_agent_db_version', Database::DB_VERSION_OPTION );
	}

	/**
	 * Test create_session creates a session and returns ID.
	 */
	public function test_create_session() {
		$user_id = self::factory()->user->create();

		$session_id = Database::create_session( [
			'user_id'     => $user_id,
			'title'       => 'Test Session',
			'provider_id' => 'anthropic',
			'model_id'    => 'claude-sonnet-4',
		] );

		$this->assertIsInt( $session_id );
		$this->assertGreaterThan( 0, $session_id );
	}

	/**
	 * Test get_session returns session data.
	 */
	public function test_get_session() {
		$user_id = self::factory()->user->create();

		$session_id = Database::create_session( [
			'user_id'     => $user_id,
			'title'       => 'Get Test Session',
			'provider_id' => 'openai',
			'model_id'    => 'gpt-4o',
		] );

		$session = Database::get_session( $session_id );

		$this->assertNotNull( $session );
		$this->assertSame( 'Get Test Session', $session->title );
		$this->assertSame( 'openai', $session->provider_id );
		$this->assertSame( 'gpt-4o', $session->model_id );
	}

	/**
	 * Test get_session returns null for non-existent session.
	 */
	public function test_get_session_not_found() {
		$session = Database::get_session( 999999 );
		$this->assertNull( $session );
	}

	/**
	 * Test update_session updates fields.
	 */
	public function test_update_session() {
		$user_id = self::factory()->user->create();

		$session_id = Database::create_session( [
			'user_id' => $user_id,
			'title'   => 'Original Title',
		] );

		$result = Database::update_session( $session_id, [
			'title' => 'Updated Title',
		] );

		$this->assertTrue( $result );

		$session = Database::get_session( $session_id );
		$this->assertSame( 'Updated Title', $session->title );
	}

	/**
	 * Test delete_session removes session.
	 */
	public function test_delete_session() {
		$user_id = self::factory()->user->create();

		$session_id = Database::create_session( [
			'user_id' => $user_id,
			'title'   => 'To Delete',
		] );

		$result = Database::delete_session( $session_id );
		$this->assertTrue( $result );

		$session = Database::get_session( $session_id );
		$this->assertNull( $session );
	}

	/**
	 * Test list_sessions returns sessions for user.
	 */
	public function test_list_sessions() {
		$user_id = self::factory()->user->create();

		Database::create_session( [
			'user_id' => $user_id,
			'title'   => 'Session 1',
		] );

		Database::create_session( [
			'user_id' => $user_id,
			'title'   => 'Session 2',
		] );

		$sessions = Database::list_sessions( $user_id );

		$this->assertIsArray( $sessions );
		$this->assertGreaterThanOrEqual( 2, count( $sessions ) );
	}

	/**
	 * Test list_sessions filters by status.
	 */
	public function test_list_sessions_status_filter() {
		$user_id = self::factory()->user->create();

		$session_id = Database::create_session( [
			'user_id' => $user_id,
			'title'   => 'Active Session',
		] );

		$this->assertNotFalse( $session_id, 'Session should be created successfully.' );

		// Move to trash.
		$updated = Database::update_session( $session_id, [ 'status' => 'trash' ] );
		$this->assertTrue( $updated, 'Session should be updated successfully.' );

		$active = Database::list_sessions( $user_id, [ 'status' => 'active' ] );
		$trashed = Database::list_sessions( $user_id, [ 'status' => 'trash' ] );

		// The trashed session should appear in trash list but NOT in active list.
		$active_ids  = array_map( 'intval', array_column( $active, 'id' ) );
		$trashed_ids = array_map( 'intval', array_column( $trashed, 'id' ) );
		$this->assertNotContains( (int) $session_id, $active_ids );
		$this->assertContains( (int) $session_id, $trashed_ids );
	}

	/**
	 * Test log_usage records usage data.
	 */
	public function test_log_usage() {
		$user_id = self::factory()->user->create();

		$usage_id = Database::log_usage( [
			'user_id'           => $user_id,
			'session_id'        => 0,
			'provider_id'       => 'anthropic',
			'model_id'          => 'claude-sonnet-4',
			'prompt_tokens'     => 1000,
			'completion_tokens' => 500,
			'cost_usd'          => 0.015,
		] );

		$this->assertIsInt( $usage_id );
		$this->assertGreaterThan( 0, $usage_id );
	}

	/**
	 * Test get_usage_summary returns summary data.
	 */
	public function test_get_usage_summary() {
		$summary = Database::get_usage_summary();

		$this->assertIsArray( $summary );
		$this->assertArrayHasKey( 'totals', $summary );
		$this->assertArrayHasKey( 'by_model', $summary );
	}

	/**
	 * Test update_session_tokens accumulates tokens.
	 */
	public function test_update_session_tokens() {
		$user_id = self::factory()->user->create();

		$session_id = Database::create_session( [
			'user_id' => $user_id,
			'title'   => 'Token Test',
		] );

		Database::update_session_tokens( $session_id, 100, 50 );
		$session = Database::get_session( $session_id );
		$this->assertSame( '100', $session->prompt_tokens );
		$this->assertSame( '50', $session->completion_tokens );

		Database::update_session_tokens( $session_id, 200, 100 );
		$session = Database::get_session( $session_id );
		$this->assertSame( '300', $session->prompt_tokens );
		$this->assertSame( '150', $session->completion_tokens );
	}

	/**
	 * Test append_to_session adds messages.
	 */
	public function test_append_to_session() {
		$user_id = self::factory()->user->create();

		$session_id = Database::create_session( [
			'user_id' => $user_id,
			'title'   => 'Append Test',
		] );

		$result = Database::append_to_session( $session_id, [
			[ 'role' => 'user', 'content' => 'Hello' ],
		] );

		$this->assertTrue( $result );

		$session = Database::get_session( $session_id );
		$messages = json_decode( $session->messages, true );

		$this->assertCount( 1, $messages );
		$this->assertSame( 'user', $messages[0]['role'] );
	}

	/**
	 * Test bulk_update_sessions updates multiple sessions.
	 */
	public function test_bulk_update_sessions() {
		$user_id = self::factory()->user->create();

		$session1 = Database::create_session( [ 'user_id' => $user_id, 'title' => 'Bulk 1' ] );
		$session2 = Database::create_session( [ 'user_id' => $user_id, 'title' => 'Bulk 2' ] );

		$count = Database::bulk_update_sessions(
			[ $session1, $session2 ],
			$user_id,
			[ 'status' => 'trash' ]
		);

		$this->assertSame( 2, $count );

		$s1 = Database::get_session( $session1 );
		$s2 = Database::get_session( $session2 );

		$this->assertSame( 'trash', $s1->status );
		$this->assertSame( 'trash', $s2->status );
	}

	/**
	 * Test bulk_update_sessions ignores fields outside the allowlist.
	 */
	public function test_bulk_update_sessions_ignores_unknown_fields() {
		$user_id    = self::factory()->user->create();
		$session_id = Database::create_session( [ 'user_id' => $user_id, 'title' => 'Bulk Unknown' ] );

		$count = Database::bulk_update_sessions(
			[ $session_id ],
			$user_id,
			[ 'title = %s WHERE 1=1 --' => 'Injected' ]
		);

		$this->assertSame( 0, $count );
		$this->assertSame( 'Bulk Unknown', Database::get_session( $session_id )->title );
	}

	/**
	 * Test empty_trash deletes trashed sessions.
	 */
	public function test_empty_trash() {
		$user_id = self::factory()->user->create();

		$session_id = Database::create_session( [
			'user_id' => $user_id,
			'title'   => 'To Trash',
		] );

		Database::update_session( $session_id, [ 'status' => 'trash' ] );

		$deleted = Database::empty_trash( $user_id );

		$this->assertGreaterThanOrEqual( 1, $deleted );

		$session = Database::get_session( $session_id );
		$this->assertNull( $session );
	}

	/**
	 * Test list_folders returns distinct folders.
	 */
	public function test_list_folders() {
		$user_id = self::factory()->user->create();

		Database::create_session( [
			'user_id' => $user_id,
			'title'   => 'In Folder',
		] );

		$session_id = Database::create_session( [
			'user_id' => $user_id,
			'title'   => 'In Work Folder',
		] );

		Database::update_session( $session_id, [ 'folder' => 'work' ] );

		$folders = Database::list_folders( $user_id );

		$this->assertIsArray( $folders );
		$this->assertContains( 'work', $folders );
	}

	// ─── Plugin download / modified files ────────────────────────────────────

	/**
	 * Test modified_files_table_name returns correct table name.
	 */
	public function test_modified_files_table_name() {
		global $wpdb;
		$expected = $wpdb->prefix . 'sd_ai_agent_modified_files';
		$this->assertSame( $expected, Database::modified_files_table_name() );
	}

	/**
	 * Test extract_plugin_slug with a standard plugin path.
	 */
	public function test_extract_plugin_slug_standard_path() {
		$this->assertSame(
			'my-plugin',
			Database::extract_plugin_slug( 'plugins/my-plugin/includes/file.php' )
		);
	}

	/**
	 * Test extract_plugin_slug with a root-level plugin file.
	 */
	public function test_extract_plugin_slug_root_file() {
		$this->assertSame(
			'my-plugin',
			Database::extract_plugin_slug( 'plugins/my-plugin/my-plugin.php' )
		);
	}

	/**
	 * Test extract_plugin_slug with a leading slash.
	 */
	public function test_extract_plugin_slug_leading_slash() {
		$this->assertSame(
			'my-plugin',
			Database::extract_plugin_slug( '/plugins/my-plugin/file.php' )
		);
	}

	/**
	 * Test extract_plugin_slug returns empty string for theme paths.
	 */
	public function test_extract_plugin_slug_theme_path_returns_empty() {
		$this->assertSame(
			'',
			Database::extract_plugin_slug( 'themes/my-theme/style.css' )
		);
	}

	/**
	 * Test extract_plugin_slug returns empty string for uploads paths.
	 */
	public function test_extract_plugin_slug_uploads_path_returns_empty() {
		$this->assertSame(
			'',
			Database::extract_plugin_slug( 'uploads/2024/01/image.jpg' )
		);
	}

	/**
	 * Test extract_plugin_slug returns empty string for empty input.
	 */
	public function test_extract_plugin_slug_empty_returns_empty() {
		$this->assertSame( '', Database::extract_plugin_slug( '' ) );
	}

	/**
	 * Test record_modified_file only records plugin paths.
	 */
	public function test_record_modified_file_ignores_non_plugin_paths() {
		$result = Database::record_modified_file( 'themes/my-theme/style.css', 'write', 0, 1 );
		$this->assertFalse( $result );
	}

	/**
	 * Test record_modified_file records plugin paths.
	 */
	public function test_record_modified_file_records_plugin_paths() {
		$result = Database::record_modified_file( 'plugins/my-plugin/file.php', 'write', 0, 1 );
		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
	}

	/**
	 * Test get_modified_plugins returns recorded plugins.
	 */
	public function test_get_modified_plugins_returns_recorded_plugins() {
		Database::record_modified_file( 'plugins/test-plugin/file.php', 'write', 0, 1 );
		Database::record_modified_file( 'plugins/test-plugin/other.php', 'edit', 0, 1 );

		$plugins = Database::get_modified_plugins();

		$slugs = array_column( (array) $plugins, 'plugin_slug' );
		$this->assertContains( 'test-plugin', $slugs );
	}

	/**
	 * Test get_modified_files_for_plugin returns files for a specific plugin.
	 */
	public function test_get_modified_files_for_plugin() {
		Database::record_modified_file( 'plugins/specific-plugin/file.php', 'write', 0, 1 );

		$files = Database::get_modified_files_for_plugin( 'specific-plugin' );

		$this->assertNotEmpty( $files );
		$this->assertSame( 'specific-plugin', $files[0]->plugin_slug );
		$this->assertSame( 'plugins/specific-plugin/file.php', $files[0]->file_path );
	}
}
