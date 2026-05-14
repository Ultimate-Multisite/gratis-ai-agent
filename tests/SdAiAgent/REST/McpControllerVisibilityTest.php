<?php

declare(strict_types=1);
/**
 * Visibility-gate integration tests for McpController.
 *
 * Asserts that the `sd_ai_agent_third_party_mode` setting controls which
 * abilities are exposed through the public MCP `list_tools` endpoint.
 *
 * Coverage:
 *   - Legacy mode: all registered abilities (including private-unknown ones)
 *     appear in list_tools (zero behavioural change vs pre-1.9.0).
 *   - Auto mode: abilities with ai_hidden=true are hidden.
 *   - Auto mode: private-unknown abilities (no mcp.public, unknown namespace)
 *     are hidden from list_tools.
 *   - Auto mode: abilities on the partner allowlist appear.
 *   - Auto mode: mcp.public === true abilities appear regardless of namespace.
 *
 * @package SdAiAgent
 * @subpackage Tests\REST
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\REST;

use SdAiAgent\Core\Settings;
use SdAiAgent\REST\McpController;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * MCP endpoint visibility tests.
 *
 * @group mcp
 * @group rest
 * @group visibility
 */
class McpControllerVisibilityTest extends WP_UnitTestCase {

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
	 * MCP endpoint route.
	 */
	private const ROUTE = '/sd-ai-agent/v1/mcp';

	/**
	 * Ability name that is first-party (partner allowlist).
	 */
	private const FIRST_PARTY_ABILITY = 'sd-ai-agent/visibility-test-public';

	/**
	 * Ability name that is private due to ai_hidden.
	 */
	private const HIDDEN_ABILITY = 'test-visibility-plugin/hidden-tool';

	/**
	 * Ability name that is private-unknown (unknown namespace, no mcp.public).
	 */
	private const PRIVATE_UNKNOWN_ABILITY = 'unknown-third-party/no-declaration';

	/**
	 * Ability name with explicit mcp.public = true.
	 */
	private const MCP_PUBLIC_ABILITY = 'another-plugin/opted-in-tool';

	/**
	 * Set up REST server, test users, and mock abilities before each test.
	 *
	 * WordPress 7.0 enforces that wp_register_ability() is called from within
	 * the wp_abilities_api_init hook. The same hack as McpControllerTest is used
	 * here: push the hook name onto $wp_current_filter to satisfy the check.
	 */
	public function set_up(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress test global.
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Standard WordPress core hook.
		do_action( 'rest_api_init' );

		parent::set_up();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		if ( function_exists( 'wp_register_ability' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress test global.
			global $wp_current_filter;
			$wp_current_filter[] = 'wp_abilities_api_init';

			// First-party ability — always visible via partner namespace.
			wp_register_ability(
				self::FIRST_PARTY_ABILITY,
				array(
					'label'               => 'Visibility Test Public',
					'description'         => 'First-party ability for visibility tests.',
					'category'            => 'sd-ai-agent',
					'execute_callback'    => '__return_true',
					'permission_callback' => '__return_true',
				)
			);

			// Explicitly hidden ability — must be filtered out in every mode.
			wp_register_ability(
				self::HIDDEN_ABILITY,
				array(
					'label'               => 'Hidden Tool',
					'description'         => 'This ability is hidden from AI.',
					'category'            => 'test-visibility-plugin',
					'meta'                => array( 'ai_hidden' => true ),
					'execute_callback'    => '__return_true',
					'permission_callback' => '__return_true',
				)
			);

			// Private-unknown ability — unknown namespace, no mcp.public flag.
			wp_register_ability(
				self::PRIVATE_UNKNOWN_ABILITY,
				array(
					'label'               => 'Unknown No Declaration',
					'description'         => "   \t  ", // Whitespace-only so heuristic fails.
					'category'            => '',
					'execute_callback'    => '__return_true',
					'permission_callback' => '__return_true',
				)
			);

			// Explicitly opted-in ability — mcp.public = true.
			wp_register_ability(
				self::MCP_PUBLIC_ABILITY,
				array(
					'label'               => 'Opted-in Tool',
					'description'         => 'An opted-in third-party ability.',
					'category'            => 'another-plugin',
					'meta'                => array( 'mcp' => array( 'public' => true ) ),
					'execute_callback'    => '__return_true',
					'permission_callback' => '__return_true',
				)
			);

			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Tear down REST server, unregister abilities, and restore settings.
	 */
	public function tear_down(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress test global.
		global $wp_rest_server;
		$wp_rest_server = null;

		if ( function_exists( 'wp_unregister_ability' ) ) {
			wp_unregister_ability( self::FIRST_PARTY_ABILITY );
			wp_unregister_ability( self::HIDDEN_ABILITY );
			wp_unregister_ability( self::PRIVATE_UNKNOWN_ABILITY );
			wp_unregister_ability( self::MCP_PUBLIC_ABILITY );
		}

		delete_option( Settings::OPTION_NAME );

		parent::tear_down();
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Dispatch list_tools as admin and return the tools array.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_tools_list(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_body( wp_json_encode( array( 'method' => 'list_tools' ) ) );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		return $data['tools'] ?? array();
	}

	/**
	 * Return the MCP tool names from the list.
	 *
	 * @return list<string>
	 */
	private function get_tool_names(): array {
		return array_column( $this->get_tools_list(), 'name' );
	}

	// ─── Legacy mode (default) ───────────────────────────────────────────────

	/**
	 * In legacy mode (the default), all registered abilities except ai_hidden
	 * ones are exposed — zero behavioural change from pre-1.9.0.
	 */
	public function test_legacy_mode_exposes_unknown_namespace_ability(): void {
		// Default: no option set → mode is 'legacy'.
		delete_option( Settings::OPTION_NAME );

		$tool_names = $this->get_tool_names();

		// First-party ability appears.
		$this->assertContains(
			McpController::ability_name_to_mcp_name( self::FIRST_PARTY_ABILITY ),
			$tool_names,
			'Legacy: first-party ability should appear in list_tools.'
		);

		// Private-unknown appears in legacy mode (no ai_hidden).
		$this->assertContains(
			McpController::ability_name_to_mcp_name( self::PRIVATE_UNKNOWN_ABILITY ),
			$tool_names,
			'Legacy: private-unknown ability should appear in list_tools (no ai_hidden).'
		);
	}

	/**
	 * In legacy mode, ai_hidden abilities are still excluded.
	 */
	public function test_legacy_mode_hides_ai_hidden_ability(): void {
		delete_option( Settings::OPTION_NAME );

		$tool_names = $this->get_tool_names();

		$this->assertNotContains(
			McpController::ability_name_to_mcp_name( self::HIDDEN_ABILITY ),
			$tool_names,
			'Legacy: ai_hidden ability must not appear in list_tools.'
		);
	}

	// ─── Auto mode ───────────────────────────────────────────────────────────

	/**
	 * In auto mode, ai_hidden abilities are excluded from list_tools.
	 */
	public function test_auto_mode_hides_ai_hidden_ability(): void {
		update_option( Settings::OPTION_NAME, array( 'third_party_mode' => 'auto' ) );

		$tool_names = $this->get_tool_names();

		$this->assertNotContains(
			McpController::ability_name_to_mcp_name( self::HIDDEN_ABILITY ),
			$tool_names,
			'Auto: ai_hidden ability must not appear in list_tools.'
		);
	}

	/**
	 * In auto mode, private-unknown abilities (unknown namespace, no mcp.public)
	 * are excluded from list_tools even though they have no ai_hidden flag.
	 *
	 * This is the highest-value fix in the epic — external MCP clients should
	 * not see third-party abilities the site owner hasn't sanctioned.
	 */
	public function test_auto_mode_hides_private_unknown_ability(): void {
		update_option( Settings::OPTION_NAME, array( 'third_party_mode' => 'auto' ) );

		$tool_names = $this->get_tool_names();

		$this->assertNotContains(
			McpController::ability_name_to_mcp_name( self::PRIVATE_UNKNOWN_ABILITY ),
			$tool_names,
			'Auto: private-unknown ability must not appear in list_tools.'
		);
	}

	/**
	 * In auto mode, first-party abilities (partner namespace) appear in list_tools.
	 */
	public function test_auto_mode_exposes_first_party_ability(): void {
		update_option( Settings::OPTION_NAME, array( 'third_party_mode' => 'auto' ) );

		$tool_names = $this->get_tool_names();

		$this->assertContains(
			McpController::ability_name_to_mcp_name( self::FIRST_PARTY_ABILITY ),
			$tool_names,
			'Auto: first-party ability should appear in list_tools.'
		);
	}

	/**
	 * In auto mode, abilities with mcp.public === true appear in list_tools.
	 */
	public function test_auto_mode_exposes_mcp_public_ability(): void {
		update_option( Settings::OPTION_NAME, array( 'third_party_mode' => 'auto' ) );

		$tool_names = $this->get_tool_names();

		$this->assertContains(
			McpController::ability_name_to_mcp_name( self::MCP_PUBLIC_ABILITY ),
			$tool_names,
			'Auto: mcp.public=true ability should appear in list_tools.'
		);
	}
}
