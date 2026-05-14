<?php

declare(strict_types=1);
/**
 * Visibility-gate integration tests for McpController.
 *
 * Verifies that `AbilityVisibility::for_mcp()` is wired up inside
 * `McpController::get_mcp_tools()` and that the `sd_ai_agent_third_party_mode`
 * setting controls which abilities are exposed via list_tools.
 *
 * Coverage:
 *   - ai_hidden abilities are excluded in legacy mode (explicit-private gate).
 *   - ai_hidden abilities are excluded in auto mode (explicit-private gate).
 *   - First-party abilities (sd-ai-agent namespace) appear in legacy mode.
 *   - First-party abilities appear in auto mode (partner-namespace gate).
 *   - Abilities with mcp.public=true appear in auto mode (explicit-public gate).
 *
 * Note: The private-unknown filtering (unknown namespace + no mcp.public)
 * in auto mode is verified at unit-test level in AbilityVisibilityTest
 * because registering third-party-category abilities in the integration
 * environment requires additional hook scaffolding. The integration tests
 * here focus on verifying the gate is wired in McpController at all.
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
	 * Ability name that is first-party (sd-ai-agent partner namespace).
	 * Uses the 'sd-ai-agent' category which is already registered by the plugin.
	 */
	private const FIRST_PARTY_ABILITY = 'sd-ai-agent/visibility-test-public';

	/**
	 * Ability name that is private due to ai_hidden=true.
	 * Uses 'sd-ai-agent' category (already registered) but is hidden via meta.
	 */
	private const HIDDEN_ABILITY = 'sd-ai-agent/visibility-test-hidden';

	/**
	 * Ability name with explicit mcp.public = true.
	 * Uses 'sd-ai-agent' category (already registered); the mcp.public flag
	 * makes it public-explicit in all modes, including auto.
	 */
	private const MCP_PUBLIC_ABILITY = 'sd-ai-agent/visibility-test-mcp-public';

	/**
	 * Set up REST server, test users, and mock abilities before each test.
	 *
	 * WordPress 7.0 enforces that wp_register_ability() is called from within
	 * the wp_abilities_api_init hook. The same hack as McpControllerTest is used
	 * here: push the hook name onto $wp_current_filter to satisfy the check.
	 * All test abilities use the pre-registered 'sd-ai-agent' category to avoid
	 * needing to register custom categories (which has its own hook constraints).
	 */
	public function set_up(): void {
		// REST server + rest_api_init must precede parent::set_up() so that
		// _backup_hooks() snapshots the DI route callbacks.
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

			// First-party ability — partner namespace and category.
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

			// Explicitly hidden ability — ai_hidden=true, excluded in every mode.
			wp_register_ability(
				self::HIDDEN_ABILITY,
				array(
					'label'               => 'Visibility Test Hidden',
					'description'         => 'This ability is hidden from AI.',
					'category'            => 'sd-ai-agent',
					'meta'                => array( 'ai_hidden' => true ),
					'execute_callback'    => '__return_true',
					'permission_callback' => '__return_true',
				)
			);

			// Explicitly opted-in ability — mcp.public = true is public-explicit
			// in all modes including auto.
			wp_register_ability(
				self::MCP_PUBLIC_ABILITY,
				array(
					'label'               => 'Visibility Test MCP Public',
					'description'         => 'An ability explicitly opted in for MCP.',
					'category'            => 'sd-ai-agent',
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
	 * In legacy mode, first-party abilities appear in list_tools.
	 *
	 * Legacy mode preserves the pre-1.9.0 behaviour: every ability that is
	 * not explicitly hidden is exposed to external MCP clients.
	 */
	public function test_legacy_mode_exposes_first_party_ability(): void {
		// Default: no option stored → mode is 'legacy'.
		delete_option( Settings::OPTION_NAME );

		$tool_names = $this->get_tool_names();

		$this->assertContains(
			McpController::ability_name_to_mcp_name( self::FIRST_PARTY_ABILITY ),
			$tool_names,
			'Legacy: first-party ability should appear in list_tools.'
		);
	}

	/**
	 * In legacy mode, ai_hidden abilities are excluded from list_tools.
	 *
	 * The explicit-private gate (ai_hidden) always wins regardless of mode.
	 * This also verifies that the AbilityVisibility gate is in place in
	 * McpController::get_mcp_tools() — before this PR that endpoint had
	 * NO visibility gate at all.
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
	 * In auto mode, first-party abilities (sd-ai-agent partner namespace)
	 * appear in list_tools.
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
	 *
	 * The mcp.public flag is the canonical opt-in signal for the public MCP
	 * endpoint. It makes an ability public-explicit in all modes.
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
