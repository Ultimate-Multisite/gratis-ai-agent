<?php
/**
 * Test case for ToolCapabilities class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\ToolCapabilities;
use WP_UnitTestCase;

/**
 * Test ToolCapabilities functionality.
 */
class ToolCapabilitiesTest extends WP_UnitTestCase {

	/**
	 * Test cap_name derives the correct capability name from an ability ID.
	 *
	 * @dataProvider provider_cap_name
	 *
	 * @param string $ability_id   Input ability ID.
	 * @param string $expected_cap Expected capability name.
	 */
	public function test_cap_name( string $ability_id, string $expected_cap ): void {
		$this->assertSame( $expected_cap, ToolCapabilities::cap_name( $ability_id ) );
	}

	/**
	 * Data provider for test_cap_name.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function provider_cap_name(): array {
		return [
			// sd-ai-agent/ prefix (plugin-specific abilities).
			'memory-save'              => [ 'sd-ai-agent/memory-save', 'sd_ai_agent_tool_memory_save' ],
			'memory-list'              => [ 'sd-ai-agent/memory-list', 'sd_ai_agent_tool_memory_list' ],
			'memory-delete'            => [ 'sd-ai-agent/memory-delete', 'sd_ai_agent_tool_memory_delete' ],
			'db-query'                 => [ 'sd-ai-agent/db-query', 'sd_ai_agent_tool_db_query' ],
			'run-php'                  => [ 'sd-ai-agent/run-php', 'sd_ai_agent_tool_run_php' ],
			'file-read'                => [ 'sd-ai-agent/file-read', 'sd_ai_agent_tool_file_read' ],
			'get-plugins'              => [ 'sd-ai-agent/get-plugins', 'sd_ai_agent_tool_get_plugins' ],
			'navigate'                 => [ 'sd-ai-agent/navigate', 'sd_ai_agent_tool_navigate' ],
			'seo-audit-url'            => [ 'sd-ai-agent/seo-audit-url', 'sd_ai_agent_tool_seo_audit_url' ],
			'content-analyze'          => [ 'sd-ai-agent/content-analyze', 'sd_ai_agent_tool_content_analyze' ],
			'markdown-to-blocks'       => [ 'sd-ai-agent/markdown-to-blocks', 'sd_ai_agent_tool_markdown_to_blocks' ],
			'stock-image'              => [ 'sd-ai-agent/stock-image', 'sd_ai_agent_tool_stock_image' ],
			'generate-image'           => [ 'sd-ai-agent/generate-image', 'sd_ai_agent_tool_generate_image' ],
			'custom-tool-with-slashes' => [ 'sd-ai-agent-custom/my-tool', 'sd_ai_agent_tool_sd_ai_agent_custom_my_tool' ],
			'sd-ai-agent/memory-save'     => [ 'sd-ai-agent/memory-save', 'sd_ai_agent_tool_memory_save' ],
			'sd-ai-agent/create-post'     => [ 'sd-ai-agent/create-post', 'sd_ai_agent_tool_create_post' ],
			'sd-ai-agent/update-post'     => [ 'sd-ai-agent/update-post', 'sd_ai_agent_tool_update_post' ],
			'sd-ai-agent/list-posts'      => [ 'sd-ai-agent/list-posts', 'sd_ai_agent_tool_list_posts' ],
		];
	}

	/**
	 * Test capability_exists returns false when capability is not in any role.
	 */
	public function test_capability_exists_returns_false_for_unknown_cap(): void {
		$this->assertFalse( ToolCapabilities::capability_exists( 'sd_ai_agent_tool_nonexistent_xyz_12345' ) );
	}

	/**
	 * Test capability_exists returns true after adding capability to a role.
	 */
	public function test_capability_exists_returns_true_after_adding_to_role(): void {
		$cap  = 'sd_ai_agent_tool_test_cap_' . uniqid();
		$role = get_role( 'administrator' );
		$this->assertNotNull( $role );

		$role->add_cap( $cap, true );
		$this->assertTrue( ToolCapabilities::capability_exists( $cap ) );

		// Clean up.
		$role->remove_cap( $cap );
	}

	/**
	 * Test current_user_can falls back to manage_options when capability doesn't exist.
	 */
	public function test_current_user_can_falls_back_to_manage_options(): void {
		// Create a user with manage_options.
		$admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		// Use an ability ID whose capability has never been registered.
		$this->assertTrue( ToolCapabilities::current_user_can( 'sd-ai-agent/nonexistent-tool-xyz' ) );

		// Create a subscriber (no manage_options).
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$this->assertFalse( ToolCapabilities::current_user_can( 'sd-ai-agent/nonexistent-tool-xyz' ) );
	}

	/**
	 * Test current_user_can uses the specific capability when it exists.
	 */
	public function test_current_user_can_uses_specific_cap_when_registered(): void {
		$ability_id = 'sd-ai-agent/test-specific-tool-' . uniqid();
		$cap        = ToolCapabilities::cap_name( $ability_id );

		// Grant the capability to the editor role.
		$editor_role = get_role( 'editor' );
		$this->assertNotNull( $editor_role );
		$editor_role->add_cap( $cap, true );

		// Editor should now have access.
		$editor_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );
		$this->assertTrue( ToolCapabilities::current_user_can( $ability_id ) );

		// Subscriber should not have access.
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );
		$this->assertFalse( ToolCapabilities::current_user_can( $ability_id ) );

		// Clean up.
		$editor_role->remove_cap( $cap );
	}

	/**
	 * Test the sd_ai_agent_tool_capability filter overrides the capability name.
	 */
	public function test_filter_overrides_capability_name(): void {
		$ability_id   = 'sd-ai-agent/memory-save';
		$override_cap = 'edit_posts';

		add_filter(
			'sd_ai_agent_tool_capability',
			function ( string $cap, string $id ) use ( $ability_id, $override_cap ): string {
				if ( $id === $ability_id ) {
					return $override_cap;
				}
				return $cap;
			},
			10,
			2
		);

		// Grant edit_posts to editor role (it already has it by default).
		$editor_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );

		// The capability 'edit_posts' exists in roles, so it should be used directly.
		$this->assertTrue( ToolCapabilities::current_user_can( $ability_id ) );

		remove_all_filters( 'sd_ai_agent_tool_capability' );
	}

	/**
	 * Test register_capabilities adds capabilities to the administrator role.
	 */
	public function test_register_capabilities_adds_to_admin_role(): void {
		$test_ids = [
			'sd-ai-agent/test-reg-tool-a-' . uniqid(),
			'sd-ai-agent/test-reg-tool-b-' . uniqid(),
		];

		ToolCapabilities::register_capabilities( $test_ids );

		$admin_role = get_role( 'administrator' );
		$this->assertNotNull( $admin_role );

		foreach ( $test_ids as $id ) {
			$cap = ToolCapabilities::cap_name( $id );
			$this->assertArrayHasKey( $cap, $admin_role->capabilities );
			$this->assertTrue( $admin_role->capabilities[ $cap ] );

			// Clean up.
			$admin_role->remove_cap( $cap );
		}
	}

	/**
	 * Test all_ability_ids returns a non-empty array of strings.
	 *
		 * Abilities use the canonical "sd-ai-agent/" prefix.
	 */
	public function test_all_ability_ids_returns_non_empty_array(): void {
		$ids = ToolCapabilities::all_ability_ids();
		$this->assertIsArray( $ids );
		$this->assertNotEmpty( $ids );

		foreach ( $ids as $id ) {
			$this->assertIsString( $id );
			$this->assertStringStartsWith( 'sd-ai-agent/', $id, "Ability ID '{$id}' must start with 'sd-ai-agent/'" );
		}
	}

	/**
	 * Test all_ability_ids contains expected core abilities.
	 *
		 * Memory, skill, knowledge, post, menu, and global-styles abilities are
		 * registered under the canonical "sd-ai-agent/" prefix.
	 */
	public function test_all_ability_ids_contains_core_abilities(): void {
		$ids = ToolCapabilities::all_ability_ids();

		$expected = [
			// Canonical sd-ai-agent/ prefix abilities.
			'sd-ai-agent/memory-save',
			'sd-ai-agent/memory-list',
			'sd-ai-agent/memory-delete',
			'sd-ai-agent/create-post',
			'sd-ai-agent/update-post',
			'sd-ai-agent/list-posts',
			'sd-ai-agent/create-menu',
			'sd-ai-agent/add-menu-item',
			'sd-ai-agent/remove-menu-item',
			'sd-ai-agent/assign-menu-location',
			'sd-ai-agent/db-query',
			'sd-ai-agent/run-php',
			'sd-ai-agent/file-read',
			'sd-ai-agent/file-write',
			'sd-ai-agent/navigate',
		];

		foreach ( $expected as $id ) {
			$this->assertContains( $id, $ids, "Expected ability ID '{$id}' not found in all_ability_ids()" );
		}
	}
}
