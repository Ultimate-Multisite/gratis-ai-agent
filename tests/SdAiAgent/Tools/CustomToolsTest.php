<?php
/**
 * Test case for CustomTools class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Tools;

use SdAiAgent\Core\Database;
use SdAiAgent\Tools\CustomTools;
use WP_UnitTestCase;

/**
 * Test CustomTools CRUD and validation functionality.
 */
class CustomToolsTest extends WP_UnitTestCase {

	/**
	 * Ensure tables exist before tests run.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Database::install();
	}

	/**
	 * Clean up custom tools after each test.
	 */
	public function tear_down(): void {
		parent::tear_down();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE 1=1', CustomTools::table_name() ) );
	}

	// ── Constants ─────────────────────────────────────────────────────────

	/**
	 * Test TYPE constants have expected values.
	 */
	public function test_type_constants(): void {
		$this->assertSame( 'http', CustomTools::TYPE_HTTP );
		$this->assertSame( 'action', CustomTools::TYPE_ACTION );
		$this->assertSame( 'cli', CustomTools::TYPE_CLI );
	}

	/**
	 * Test VALID_TYPES contains core tool types only.
	 */
	public function test_valid_types_constant(): void {
		$this->assertContains( 'http', CustomTools::VALID_TYPES );
		$this->assertContains( 'action', CustomTools::VALID_TYPES );
		$this->assertNotContains( 'cli', CustomTools::VALID_TYPES );
		$this->assertCount( 2, CustomTools::VALID_TYPES );
	}

	/**
	 * Test valid_types() can include advanced companion plugin types.
	 */
	public function test_valid_types_method_allows_filtered_types(): void {
		$filter = static function ( array $types ): array {
			$types[] = CustomTools::TYPE_CLI;
			return $types;
		};

		add_filter( 'sd_ai_agent_custom_tool_types', $filter );
		try {
			$this->assertContains( 'cli', CustomTools::valid_types() );
		} finally {
			remove_filter( 'sd_ai_agent_custom_tool_types', $filter );
		}
	}

	/**
	 * Test VALID_HTTP_METHODS contains expected methods.
	 */
	public function test_valid_http_methods_constant(): void {
		foreach ( [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ] as $method ) {
			$this->assertContains( $method, CustomTools::VALID_HTTP_METHODS );
		}
	}

	// ── table_name ────────────────────────────────────────────────────────

	/**
	 * Test table_name returns correct prefixed table name.
	 */
	public function test_table_name(): void {
		global $wpdb;
		$expected = $wpdb->prefix . 'sd_ai_agent_custom_tools';
		$this->assertSame( $expected, CustomTools::table_name() );
	}

	// ── validate ──────────────────────────────────────────────────────────

	/**
	 * Test validate returns WP_Error when name is missing.
	 */
	public function test_validate_missing_name(): void {
		$result = CustomTools::validate( [
			'type'   => CustomTools::TYPE_HTTP,
			'config' => [ 'url' => 'https://example.com' ],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_name', $result->get_error_code() );
	}

	/**
	 * Test validate returns WP_Error when type is missing.
	 */
	public function test_validate_missing_type(): void {
		$result = CustomTools::validate( [
			'name' => 'Test Tool',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_type', $result->get_error_code() );
	}

	/**
	 * Test validate returns WP_Error for invalid type.
	 */
	public function test_validate_invalid_type(): void {
		$result = CustomTools::validate( [
			'name' => 'Test Tool',
			'type' => 'invalid_type',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_type', $result->get_error_code() );
	}

	/**
	 * Test validate returns WP_Error for HTTP tool missing URL.
	 */
	public function test_validate_http_missing_url(): void {
		$result = CustomTools::validate( [
			'name'   => 'Test HTTP Tool',
			'type'   => CustomTools::TYPE_HTTP,
			'config' => [],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_url', $result->get_error_code() );
	}

	/**
	 * Test validate returns WP_Error for HTTP tool with invalid method.
	 */
	public function test_validate_http_invalid_method(): void {
		$result = CustomTools::validate( [
			'name'   => 'Test HTTP Tool',
			'type'   => CustomTools::TYPE_HTTP,
			'config' => [
				'url'    => 'https://example.com',
				'method' => 'INVALID',
			],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_method', $result->get_error_code() );
	}

	/**
	 * Test validate normalises HTTP method to uppercase.
	 */
	public function test_validate_http_normalises_method_to_uppercase(): void {
		$result = CustomTools::validate( [
			'name'   => 'Test HTTP Tool',
			'type'   => CustomTools::TYPE_HTTP,
			'config' => [
				'url'    => 'https://example.com',
				'method' => 'post',
			],
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'POST', $result['config']['method'] );
	}

	/**
	 * Test validate returns WP_Error for ACTION tool missing hook_name.
	 */
	public function test_validate_action_missing_hook_name(): void {
		$result = CustomTools::validate( [
			'name'   => 'Test Action Tool',
			'type'   => CustomTools::TYPE_ACTION,
			'config' => [],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_hook', $result->get_error_code() );
	}

	/**
	 * Test validate returns WP_Error for CLI tool missing command.
	 */
	public function test_validate_cli_missing_command(): void {
		$result = CustomTools::validate( [
			'name'   => 'Test CLI Tool',
			'type'   => CustomTools::TYPE_CLI,
			'config' => [],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_command', $result->get_error_code() );
	}

	/**
	 * Test validate auto-generates slug from name when not provided.
	 */
	public function test_validate_auto_generates_slug(): void {
		$result = CustomTools::validate( [
			'name'   => 'My Test Tool',
			'type'   => CustomTools::TYPE_HTTP,
			'config' => [ 'url' => 'https://example.com' ],
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'my-test-tool', $result['slug'] );
	}

	/**
	 * Test validate sanitises name and description.
	 */
	public function test_validate_sanitises_fields(): void {
		$result = CustomTools::validate( [
			'name'        => '  Test Tool  ',
			'type'        => CustomTools::TYPE_CLI,
			'config'      => [ 'command' => 'cache flush' ],
			'description' => "Line 1\nLine 2",
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'Test Tool', $result['name'] );
	}

	/**
	 * Test validate passes for valid HTTP tool.
	 */
	public function test_validate_valid_http_tool(): void {
		$result = CustomTools::validate( [
			'name'   => 'Weather API',
			'type'   => CustomTools::TYPE_HTTP,
			'config' => [
				'url'    => 'https://api.example.com/weather',
				'method' => 'GET',
			],
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'Weather API', $result['name'] );
		$this->assertSame( CustomTools::TYPE_HTTP, $result['type'] );
	}

	/**
	 * Test validate passes for valid ACTION tool.
	 */
	public function test_validate_valid_action_tool(): void {
		$result = CustomTools::validate( [
			'name'   => 'Site Health Check',
			'type'   => CustomTools::TYPE_ACTION,
			'config' => [ 'hook_name' => 'sd_ai_agent_health_check' ],
		] );

		$this->assertIsArray( $result );
		$this->assertSame( CustomTools::TYPE_ACTION, $result['type'] );
	}

	/**
	 * Test validate passes for valid CLI tool.
	 */
	public function test_validate_valid_cli_tool(): void {
		$result = CustomTools::validate( [
			'name'   => 'Cache Flush',
			'type'   => CustomTools::TYPE_CLI,
			'config' => [ 'command' => 'cache flush' ],
		] );

		$this->assertIsArray( $result );
		$this->assertSame( CustomTools::TYPE_CLI, $result['type'] );
	}

	// ── create ────────────────────────────────────────────────────────────

	/**
	 * Test create returns false for invalid data.
	 */
	public function test_create_returns_false_for_invalid_data(): void {
		$result = CustomTools::create( [ 'name' => 'No Type' ] );
		$this->assertFalse( $result );
	}

	/**
	 * Test create returns integer ID for valid HTTP tool.
	 */
	public function test_create_http_tool(): void {
		$id = CustomTools::create( [
			'name'   => 'Test HTTP',
			'type'   => CustomTools::TYPE_HTTP,
			'config' => [ 'url' => 'https://example.com', 'method' => 'GET' ],
		] );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	/**
	 * Test create returns integer ID for valid ACTION tool.
	 */
	public function test_create_action_tool(): void {
		$id = CustomTools::create( [
			'name'   => 'Test Action',
			'type'   => CustomTools::TYPE_ACTION,
			'config' => [ 'hook_name' => 'sd_ai_agent_test_hook' ],
		] );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	/**
	 * Test create returns integer ID for valid CLI tool.
	 */
	public function test_create_cli_tool(): void {
		$id = CustomTools::create( [
			'name'   => 'Test CLI',
			'type'   => CustomTools::TYPE_CLI,
			'config' => [ 'command' => 'cache flush' ],
		] );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	/**
	 * Test create stores config as JSON and decodes it on retrieval.
	 */
	public function test_create_stores_config_as_json(): void {
		$config = [
			'url'     => 'https://api.example.com',
			'method'  => 'POST',
			'headers' => [ 'Authorization' => 'Bearer token' ],
		];

		$id   = CustomTools::create( [
			'name'   => 'Config Test',
			'type'   => CustomTools::TYPE_HTTP,
			'config' => $config,
		] );
		$tool = CustomTools::get( $id );

		$this->assertIsArray( $tool['config'] );
		$this->assertSame( 'https://api.example.com', $tool['config']['url'] );
		$this->assertSame( 'POST', $tool['config']['method'] );
	}

	/**
	 * Test create stores input_schema as JSON.
	 */
	public function test_create_stores_input_schema(): void {
		$schema = [
			'type'       => 'object',
			'properties' => [
				'city' => [ 'type' => 'string', 'description' => 'City name' ],
			],
			'required'   => [ 'city' ],
		];

		$id   = CustomTools::create( [
			'name'         => 'Schema Test',
			'type'         => CustomTools::TYPE_HTTP,
			'config'       => [ 'url' => 'https://example.com' ],
			'input_schema' => $schema,
		] );
		$tool = CustomTools::get( $id );

		$this->assertIsArray( $tool['input_schema'] );
		$this->assertArrayHasKey( 'properties', $tool['input_schema'] );
	}

	/**
	 * Test create defaults enabled to 1.
	 */
	public function test_create_defaults_enabled_to_true(): void {
		$id   = CustomTools::create( [
			'name'   => 'Enabled Default',
			'type'   => CustomTools::TYPE_CLI,
			'config' => [ 'command' => 'cache flush' ],
		] );
		$tool = CustomTools::get( $id );

		$this->assertTrue( $tool['enabled'] );
	}

	/**
	 * Test create respects enabled = false.
	 */
	public function test_create_respects_enabled_false(): void {
		$id   = CustomTools::create( [
			'name'    => 'Disabled Tool',
			'type'    => CustomTools::TYPE_CLI,
			'config'  => [ 'command' => 'cache flush' ],
			'enabled' => false,
		] );
		$tool = CustomTools::get( $id );

		$this->assertFalse( $tool['enabled'] );
	}

	// ── get ───────────────────────────────────────────────────────────────

	/**
	 * Test get returns null for non-existent ID.
	 */
	public function test_get_returns_null_for_nonexistent_id(): void {
		$result = CustomTools::get( 999999 );
		$this->assertNull( $result );
	}

	/**
	 * Test get returns correct tool data.
	 */
	public function test_get_returns_tool_data(): void {
		$id   = CustomTools::create( [
			'name'        => 'Get Test Tool',
			'description' => 'A test description',
			'type'        => CustomTools::TYPE_CLI,
			'config'      => [ 'command' => 'cache flush' ],
		] );
		$tool = CustomTools::get( $id );

		$this->assertIsArray( $tool );
		$this->assertSame( $id, $tool['id'] );
		$this->assertSame( 'Get Test Tool', $tool['name'] );
		$this->assertSame( 'A test description', $tool['description'] );
		$this->assertSame( CustomTools::TYPE_CLI, $tool['type'] );
		$this->assertArrayHasKey( 'slug', $tool );
		$this->assertArrayHasKey( 'created_at', $tool );
		$this->assertArrayHasKey( 'updated_at', $tool );
	}

	// ── get_by_slug ───────────────────────────────────────────────────────

	/**
	 * Test get_by_slug returns null for non-existent slug.
	 */
	public function test_get_by_slug_returns_null_for_nonexistent(): void {
		$result = CustomTools::get_by_slug( 'nonexistent-slug-xyz' );
		$this->assertNull( $result );
	}

	/**
	 * Test get_by_slug returns correct tool.
	 */
	public function test_get_by_slug_returns_tool(): void {
		CustomTools::create( [
			'name'   => 'Slug Test Tool',
			'slug'   => 'slug-test-tool',
			'type'   => CustomTools::TYPE_CLI,
			'config' => [ 'command' => 'cache flush' ],
		] );

		$tool = CustomTools::get_by_slug( 'slug-test-tool' );

		$this->assertIsArray( $tool );
		$this->assertSame( 'slug-test-tool', $tool['slug'] );
		$this->assertSame( 'Slug Test Tool', $tool['name'] );
	}

	// ── list ──────────────────────────────────────────────────────────────

	/**
	 * Test list returns array.
	 */
	public function test_list_returns_array(): void {
		$result = CustomTools::list();
		$this->assertIsArray( $result );
	}

	/**
	 * Test list returns all tools.
	 */
	public function test_list_returns_all_tools(): void {
		CustomTools::create( [
			'name'    => 'Tool A',
			'type'    => CustomTools::TYPE_CLI,
			'config'  => [ 'command' => 'cache flush' ],
			'enabled' => true,
		] );
		CustomTools::create( [
			'name'    => 'Tool B',
			'type'    => CustomTools::TYPE_CLI,
			'config'  => [ 'command' => 'cache flush' ],
			'enabled' => false,
		] );

		$all = CustomTools::list();
		$this->assertCount( 2, $all );
	}

	/**
	 * Test list with enabled_only=true returns only enabled tools.
	 */
	public function test_list_enabled_only(): void {
		CustomTools::create( [
			'name'    => 'Enabled Tool',
			'type'    => CustomTools::TYPE_CLI,
			'config'  => [ 'command' => 'cache flush' ],
			'enabled' => true,
		] );
		CustomTools::create( [
			'name'    => 'Disabled Tool',
			'type'    => CustomTools::TYPE_CLI,
			'config'  => [ 'command' => 'cache flush' ],
			'enabled' => false,
		] );

		$enabled = CustomTools::list( true );

		$this->assertCount( 1, $enabled );
		$this->assertTrue( $enabled[0]['enabled'] );
	}

	/**
	 * Test list returns tools sorted by name ascending.
	 */
	public function test_list_sorted_by_name(): void {
		CustomTools::create( [
			'name'   => 'Zebra Tool',
			'type'   => CustomTools::TYPE_CLI,
			'config' => [ 'command' => 'cache flush' ],
		] );
		CustomTools::create( [
			'name'   => 'Alpha Tool',
			'type'   => CustomTools::TYPE_CLI,
			'config' => [ 'command' => 'cache flush' ],
		] );

		$tools = CustomTools::list();

		$this->assertSame( 'Alpha Tool', $tools[0]['name'] );
		$this->assertSame( 'Zebra Tool', $tools[1]['name'] );
	}

	// ── update ────────────────────────────────────────────────────────────

	/**
	 * Test update returns false for non-existent ID.
	 */
	public function test_update_returns_false_for_nonexistent(): void {
		$result = CustomTools::update( 999999, [ 'name' => 'New Name' ] );
		$this->assertFalse( $result );
	}

	/**
	 * Test update returns true for empty data (no-op).
	 */
	public function test_update_returns_true_for_empty_data(): void {
		$id     = CustomTools::create( [
			'name'   => 'Update Empty Test',
			'type'   => CustomTools::TYPE_CLI,
			'config' => [ 'command' => 'cache flush' ],
		] );
		$result = CustomTools::update( $id, [] );

		$this->assertTrue( $result );
	}

	/**
	 * Test update changes name.
	 */
	public function test_update_changes_name(): void {
		$id = CustomTools::create( [
			'name'   => 'Original Name',
			'type'   => CustomTools::TYPE_CLI,
			'config' => [ 'command' => 'cache flush' ],
		] );

		CustomTools::update( $id, [ 'name' => 'Updated Name' ] );

		$tool = CustomTools::get( $id );
		$this->assertSame( 'Updated Name', $tool['name'] );
	}

	/**
	 * Test update changes enabled status.
	 */
	public function test_update_changes_enabled_status(): void {
		$id = CustomTools::create( [
			'name'    => 'Toggle Test',
			'type'    => CustomTools::TYPE_CLI,
			'config'  => [ 'command' => 'cache flush' ],
			'enabled' => true,
		] );

		CustomTools::update( $id, [ 'enabled' => false ] );

		$tool = CustomTools::get( $id );
		$this->assertFalse( $tool['enabled'] );
	}

	/**
	 * Test update ignores invalid type.
	 */
	public function test_update_ignores_invalid_type(): void {
		$id = CustomTools::create( [
			'name'   => 'Type Guard Test',
			'type'   => CustomTools::TYPE_CLI,
			'config' => [ 'command' => 'cache flush' ],
		] );

		CustomTools::update( $id, [ 'type' => 'invalid_type' ] );

		$tool = CustomTools::get( $id );
		$this->assertSame( CustomTools::TYPE_CLI, $tool['type'] );
	}

	/**
	 * Test update changes config.
	 */
	public function test_update_changes_config(): void {
		$id = CustomTools::create( [
			'name'   => 'Config Update Test',
			'type'   => CustomTools::TYPE_HTTP,
			'config' => [ 'url' => 'https://old.example.com', 'method' => 'GET' ],
		] );

		CustomTools::update( $id, [ 'config' => [ 'url' => 'https://new.example.com', 'method' => 'POST' ] ] );

		$tool = CustomTools::get( $id );
		$this->assertSame( 'https://new.example.com', $tool['config']['url'] );
	}

	// ── delete ────────────────────────────────────────────────────────────

	/**
	 * Test delete removes the tool.
	 */
	public function test_delete_removes_tool(): void {
		$id = CustomTools::create( [
			'name'   => 'To Delete',
			'type'   => CustomTools::TYPE_CLI,
			'config' => [ 'command' => 'cache flush' ],
		] );

		$result = CustomTools::delete( $id );

		$this->assertTrue( $result );
		$this->assertNull( CustomTools::get( $id ) );
	}

	/**
	 * Test delete returns true for non-existent ID.
	 *
	 * wpdb->delete returns 0 (int) when no rows are affected, and 0 !== false
	 * evaluates to true, so delete() returns true even when nothing was deleted.
	 */
	public function test_delete_nonexistent_returns_true(): void {
		$result = CustomTools::delete( 999999 );
		$this->assertTrue( $result );
	}
}
