<?php

declare(strict_types=1);
/**
 * Integration tests for McpController.
 *
 * Exercises the MCP REST endpoint (POST /wp-json/sd-ai-agent/v1/mcp)
 * using the WordPress REST API test infrastructure (WP_REST_Server).
 *
 * Coverage:
 *   - Unauthenticated access is rejected (403).
 *   - list_tools returns all registered abilities as MCP tool definitions.
 *   - call_tool executes a named ability and returns MCP tool-result format.
 *   - call_tool with an unknown tool name returns a 404 error response.
 *   - call_tool with a missing name parameter returns a 400 error response.
 *   - Unknown MCP method returns a 400 error response.
 *   - ability_name_to_mcp_name / mcp_name_to_ability_name round-trip.
 *   - list_tools returns empty array when Abilities API is unavailable.
 *
 * @package SdAiAgent
 * @subpackage Tests\REST
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\REST;

use SdAiAgent\REST\McpController;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Integration tests for McpController.
 *
 * @group mcp
 * @group rest
 */
class McpControllerTest extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	protected WP_REST_Server $server;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected int $admin_id;

	/**
	 * Subscriber user ID (no manage_options).
	 *
	 * @var int
	 */
	protected int $subscriber_id;

	/**
	 * MCP endpoint route.
	 */
	private const ROUTE = '/sd-ai-agent/v1/mcp';

	/**
	 * Name of the mock ability registered for tests.
	 */
	private const MOCK_ABILITY = 'test-plugin/mock-tool';

	/**
	 * Expected MCP tool name for the mock ability.
	 */
	private const MOCK_MCP_NAME = 'test-plugin__mock-tool';

	/**
	 * Set up REST server, test users, and a mock ability before each test.
	 *
	 * WordPress 7.0 enforces that wp_register_ability() is called from within
	 * the wp_abilities_api_init hook. Calling it outside the hook triggers
	 * _doing_it_wrong() and the ability is not registered.
	 *
	 * The hook fires lazily on first registry access and only fires once per
	 * request. By the time set_up() runs, earlier tests in the suite have
	 * already triggered registry initialisation, so add_action() on that hook
	 * adds a callback that will never fire.
	 *
	 * The solution used by WordPress core's own test suite (see
	 * tests/phpunit/tests/abilities-api/wpRegisterAbility.php) is to push the
	 * hook name onto $wp_current_filter before calling wp_register_ability().
	 * This satisfies the hook-context check without requiring the hook to fire.
	 */
	public function set_up(): void {
		// REST server + rest_api_init must precede parent::set_up() so that
		// _backup_hooks() snapshots the DI route callbacks. See BenchmarkControllerTest
		// for the full explanation of why this ordering is required.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress test global.
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Standard WordPress core hook.
		do_action( 'rest_api_init' );

		parent::set_up();

		$this->admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		// Register the mock ability using the same pattern as WordPress core tests:
		// push the hook name onto $wp_current_filter to satisfy the hook-context
		// check inside wp_register_ability(), then pop it after registration.
		if ( function_exists( 'wp_register_ability' ) ) {
			global $wp_current_filter;
			$wp_current_filter[] = 'wp_abilities_api_init';

			wp_register_ability(
				self::MOCK_ABILITY,
				[
					'label'               => 'Mock Tool',
					'description'         => 'A mock tool for testing.',
					'category'            => 'sd-ai-agent',
					'input_schema'        => [
						'type'       => 'object',
						'properties' => [
							'message' => [
								'type'        => 'string',
								'description' => 'A test message.',
							],
						],
						'required'   => [ 'message' ],
					],
					'execute_callback'    => static function ( $args ) {
						return [ 'echo' => $args['message'] ?? 'no-message' ];
					},
					'permission_callback' => static function () {
						return current_user_can( 'manage_options' );
					},
				]
			);

			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Tear down REST server and unregister the mock ability after each test.
	 */
	public function tear_down(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress test global.
		global $wp_rest_server;
		$wp_rest_server = null;

		// Clean up the mock ability so it does not bleed into other test classes.
		if ( function_exists( 'wp_unregister_ability' ) ) {
			wp_unregister_ability( self::MOCK_ABILITY );
		}

		parent::tear_down();
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Dispatch a POST request to the MCP endpoint.
	 *
	 * @param array $params Request body parameters.
	 * @param bool  $as_admin Whether to authenticate as admin (default true).
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function dispatch_mcp( array $params, bool $as_admin = true ) {
		if ( $as_admin ) {
			wp_set_current_user( $this->admin_id );
		} else {
			wp_set_current_user( 0 );
		}

		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_body( wp_json_encode( $params ) );
		$request->set_header( 'Content-Type', 'application/json' );

		return $this->server->dispatch( $request );
	}

	/**
	 * Assert a response has the expected HTTP status code.
	 *
	 * @param int                        $expected Expected status code.
	 * @param \WP_REST_Response|\WP_Error $response Response to check.
	 */
	private function assertStatus( int $expected, $response ): void {
		if ( is_wp_error( $response ) ) {
			$data   = $response->get_error_data();
			$status = is_array( $data ) ? ( $data['status'] ?? 0 ) : 0;
		} else {
			$status = $response->get_status();
		}
		$this->assertSame( $expected, $status, "Expected HTTP $expected, got $status." );
	}

	// ─── Authentication ───────────────────────────────────────────────────────

	/**
	 * Unauthenticated requests must be rejected.
	 */
	public function test_unauthenticated_request_is_rejected(): void {
		$response = $this->dispatch_mcp( [ 'method' => 'list_tools' ], false );
		$this->assertStatus( 401, $response );
	}

	/**
	 * Subscriber (no manage_options) must be rejected.
	 */
	public function test_subscriber_request_is_rejected(): void {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_body( wp_json_encode( [ 'method' => 'list_tools' ] ) );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->server->dispatch( $request );
		$this->assertStatus( 403, $response );
	}

	// ─── list_tools ───────────────────────────────────────────────────────────

	/**
	 * list_tools returns a 200 response with protocol_version and tools array.
	 */
	public function test_list_tools_returns_200(): void {
		$response = $this->dispatch_mcp( [ 'method' => 'list_tools' ] );
		$this->assertStatus( 200, $response );
	}

	/**
	 * list_tools response includes protocol_version field.
	 */
	public function test_list_tools_includes_protocol_version(): void {
		$response = $this->dispatch_mcp( [ 'method' => 'list_tools' ] );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'protocol_version', $data );
		$this->assertSame( '2024-11-05', $data['protocol_version'] );
	}

	/**
	 * list_tools response includes a tools array.
	 */
	public function test_list_tools_returns_tools_array(): void {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$response = $this->dispatch_mcp( [ 'method' => 'list_tools' ] );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'tools', $data );
		$this->assertIsArray( $data['tools'] );
	}

	/**
	 * list_tools includes the mock ability as an MCP tool definition.
	 */
	public function test_list_tools_includes_mock_ability(): void {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$response = $this->dispatch_mcp( [ 'method' => 'list_tools' ] );
		$data     = $response->get_data();

		$tool_names = array_column( $data['tools'], 'name' );
		$this->assertContains( self::MOCK_MCP_NAME, $tool_names, 'Mock ability should appear in tools list.' );
	}

	/**
	 * Each tool definition has name, description, and inputSchema fields.
	 */
	public function test_list_tools_tool_definitions_have_required_fields(): void {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$response = $this->dispatch_mcp( [ 'method' => 'list_tools' ] );
		$data     = $response->get_data();

		// Find the mock tool.
		$mock_tool = null;
		foreach ( $data['tools'] as $tool ) {
			if ( $tool['name'] === self::MOCK_MCP_NAME ) {
				$mock_tool = $tool;
				break;
			}
		}

		$this->assertNotNull( $mock_tool, 'Mock tool not found in tools list.' );
		$this->assertArrayHasKey( 'name', $mock_tool );
		$this->assertArrayHasKey( 'description', $mock_tool );
		$this->assertArrayHasKey( 'inputSchema', $mock_tool );
		$this->assertSame( 'A mock tool for testing.', $mock_tool['description'] );
		$this->assertSame( 'object', $mock_tool['inputSchema']['type'] );
	}

	// ─── call_tool ────────────────────────────────────────────────────────────

	/**
	 * call_tool executes the mock ability and returns MCP tool-result format.
	 */
	public function test_call_tool_executes_ability_and_returns_result(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$response = $this->dispatch_mcp(
			[
				'method' => 'call_tool',
				'params' => [
					'name'      => self::MOCK_MCP_NAME,
					'arguments' => [ 'message' => 'hello' ],
				],
			]
		);

		$this->assertStatus( 200, $response );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'protocol_version', $data );
		$this->assertArrayHasKey( 'tool', $data );
		$this->assertArrayHasKey( 'isError', $data );
		$this->assertArrayHasKey( 'content', $data );
		$this->assertSame( self::MOCK_MCP_NAME, $data['tool'] );
		$this->assertFalse( $data['isError'] );
		$this->assertIsArray( $data['content'] );
		$this->assertNotEmpty( $data['content'] );
		$this->assertSame( 'text', $data['content'][0]['type'] );
	}

	/**
	 * call_tool result content text contains the ability's return value.
	 */
	public function test_call_tool_result_contains_ability_output(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$response = $this->dispatch_mcp(
			[
				'method' => 'call_tool',
				'params' => [
					'name'      => self::MOCK_MCP_NAME,
					'arguments' => [ 'message' => 'test-value' ],
				],
			]
		);

		$data        = $response->get_data();
		$result_text = $data['content'][0]['text'] ?? '';

		// The mock ability returns ['echo' => 'test-value'], which is JSON-encoded.
		$this->assertStringContainsString( 'test-value', $result_text );
	}

	/**
	 * call_tool with an unknown tool name returns isError=true (not a 404 HTTP status).
	 *
	 * Per MCP spec, tool errors are returned as 200 responses with isError=true,
	 * except when the tool is not found — that returns a WP_Error which the REST
	 * API converts to a 404 HTTP response.
	 */
	public function test_call_tool_unknown_tool_returns_404(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$response = $this->dispatch_mcp(
			[
				'method' => 'call_tool',
				'params' => [
					'name'      => 'nonexistent__tool',
					'arguments' => [],
				],
			]
		);

		$this->assertStatus( 404, $response );
	}

	/**
	 * call_tool with missing name parameter returns 400.
	 */
	public function test_call_tool_missing_name_returns_400(): void {
		$response = $this->dispatch_mcp(
			[
				'method' => 'call_tool',
				'params' => [
					'arguments' => [ 'message' => 'hello' ],
				],
			]
		);

		$this->assertStatus( 400, $response );
	}

	/**
	 * call_tool with empty name parameter returns 400.
	 */
	public function test_call_tool_empty_name_returns_400(): void {
		$response = $this->dispatch_mcp(
			[
				'method' => 'call_tool',
				'params' => [
					'name'      => '',
					'arguments' => [],
				],
			]
		);

		$this->assertStatus( 400, $response );
	}

	// ─── Unknown method ───────────────────────────────────────────────────────

	/**
	 * An unknown MCP method returns 400.
	 */
	public function test_unknown_method_returns_400(): void {
		$response = $this->dispatch_mcp( [ 'method' => 'unknown_method' ] );
		$this->assertStatus( 400, $response );
	}

	/**
	 * Missing method parameter returns 400 (required field validation).
	 */
	public function test_missing_method_returns_400(): void {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_body( wp_json_encode( [] ) );
		$request->set_header( 'Content-Type', 'application/json' );

		wp_set_current_user( $this->admin_id );
		$response = $this->server->dispatch( $request );

		$this->assertStatus( 400, $response );
	}

	// ─── Name mapping ─────────────────────────────────────────────────────────

	/**
	 * ability_name_to_mcp_name converts slash to double-underscore.
	 */
	public function test_ability_name_to_mcp_name_converts_slash(): void {
		$this->assertSame(
			'sd-ai-agent__memory-save',
			McpController::ability_name_to_mcp_name( 'sd-ai-agent/memory-save' )
		);
	}

	/**
	 * ability_name_to_mcp_name preserves hyphens.
	 */
	public function test_ability_name_to_mcp_name_preserves_hyphens(): void {
		$this->assertSame(
			'my-plugin__my-tool',
			McpController::ability_name_to_mcp_name( 'my-plugin/my-tool' )
		);
	}

	/**
	 * mcp_name_to_ability_name converts double-underscore back to slash.
	 */
	public function test_mcp_name_to_ability_name_converts_double_underscore(): void {
		$this->assertSame(
			'sd-ai-agent/memory-save',
			McpController::mcp_name_to_ability_name( 'sd-ai-agent__memory-save' )
		);
	}

	/**
	 * Name mapping is a round-trip: ability → MCP → ability.
	 */
	public function test_name_mapping_round_trip(): void {
		$original = 'sd-ai-agent/memory-save';
		$mcp_name = McpController::ability_name_to_mcp_name( $original );
		$restored = McpController::mcp_name_to_ability_name( $mcp_name );

		$this->assertSame( $original, $restored );
	}

	/**
	 * Name mapping handles names without a namespace separator.
	 */
	public function test_ability_name_to_mcp_name_no_slash(): void {
		$this->assertSame(
			'simple-tool',
			McpController::ability_name_to_mcp_name( 'simple-tool' )
		);
	}

	/**
	 * Name mapping handles multiple slashes (nested namespaces).
	 */
	public function test_ability_name_to_mcp_name_multiple_slashes(): void {
		$this->assertSame(
			'ns__resource__action',
			McpController::ability_name_to_mcp_name( 'ns/resource/action' )
		);
	}
}
