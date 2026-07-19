<?php
declare(strict_types=1);

/**
 * Test case for ToolCapabilities class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\ToolCapabilities;
use SdAiAgent\Core\ToolPermissionResolver;
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
			'file-outline'             => [ 'sd-ai-agent/file-outline', 'sd_ai_agent_tool_file_outline' ],
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
			'sd-ai-agent/list-posts'         => [ 'sd-ai-agent/list-posts', 'sd_ai_agent_tool_list_posts' ],
			'contact-phone-lookup'           => [ 'sd-ai-agent/contact-phone-lookup', 'sd_ai_agent_tool_contact_phone_lookup' ],
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
	 *
	 * Dual-gate semantics: per-tool cap AND core cap (from CORE_CAP_MAP, or
	 * `manage_options` fallback for unmapped ad-hoc IDs) must both be held.
	 */
	public function test_current_user_can_uses_specific_cap_when_registered(): void {
		$ability_id = 'sd-ai-agent/test-specific-tool-' . uniqid();
		$cap        = ToolCapabilities::cap_name( $ability_id );

		// Grant the per-tool cap to the editor role.
		$editor_role = get_role( 'editor' );
		$this->assertNotNull( $editor_role );
		$editor_role->add_cap( $cap, true );

		// Override CORE_CAP_MAP for this ad-hoc ID so the core-cap layer
		// is satisfied by a cap the editor role holds by default.
		$core_filter = static function ( array $caps, string $id ) use ( $ability_id ): array {
			if ( $id === $ability_id ) {
				return [ 'edit_posts' ];
			}
			return $caps;
		};
		add_filter( 'sd_ai_agent_tool_required_core_cap', $core_filter, 10, 2 );

		// Editor should now have access (per-tool granted, edit_posts held).
		$editor_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );
		$this->assertTrue( ToolCapabilities::current_user_can( $ability_id ) );

		// Subscriber lacks both per-tool and edit_posts → false.
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );
		$this->assertFalse( ToolCapabilities::current_user_can( $ability_id ) );

		// Clean up.
		remove_filter( 'sd_ai_agent_tool_required_core_cap', $core_filter, 10 );
		$editor_role->remove_cap( $cap );
	}

	/**
	 * Test the sd_ai_agent_tool_capability filter overrides the capability name.
	 *
	 * Dual-gate: the per-tool filter changes layer 1, but layer 2 still
	 * requires the CORE_CAP_MAP-resolved core cap. memory-save maps to
	 * manage_options, so we also override the core-cap layer for the test.
	 */
	public function test_filter_overrides_capability_name(): void {
		$ability_id   = 'sd-ai-agent/memory-save';
		$override_cap = 'edit_posts';

		add_filter(
			'sd_ai_agent_tool_capability',
			static function ( string $cap, string $id ) use ( $ability_id, $override_cap ): string {
				if ( $id === $ability_id ) {
					return $override_cap;
				}
				return $cap;
			},
			10,
			2
		);

		// Lower the required core cap to one editor holds.
		$core_filter = static function ( array $caps, string $id ) use ( $ability_id ): array {
			if ( $id === $ability_id ) {
				return [ 'edit_posts' ];
			}
			return $caps;
		};
		add_filter( 'sd_ai_agent_tool_required_core_cap', $core_filter, 10, 2 );

		// Grant edit_posts to editor role (it already has it by default).
		$editor_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );

		// Both gates satisfied → true.
		$this->assertTrue( ToolCapabilities::current_user_can( $ability_id ) );

		remove_filter( 'sd_ai_agent_tool_required_core_cap', $core_filter, 10 );
		remove_all_filters( 'sd_ai_agent_tool_capability' );
	}

	/**
	 * Dual-gate: core cap held but per-tool cap missing → false until confirmed.
	 *
	 * Proves the per-tool layer is enforced even when the user holds the
	 * mapped WordPress core cap. The site administrator who manages role
	 * caps via a plugin must explicitly grant the per-tool cap, or the user must
	 * explicitly approve the tool for the current agent turn.
	 */
	public function test_dual_gate_core_cap_alone_is_not_sufficient(): void {
		// list-posts is mapped to edit_posts in CORE_CAP_MAP. Editor has
		// edit_posts by default but no plugin-specific per-tool cap, and
		// the per-tool cap is not registered (so falls back to
		// manage_options, which editor lacks).
		$editor_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );

		$this->assertFalse( ToolCapabilities::current_user_can( 'sd-ai-agent/list-posts' ) );
		$error = ToolCapabilities::permission_denial_error( 'sd-ai-agent/list-posts' );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'fallback', $error->get_error_data()['permission_layer'] );
		$this->assertStringNotContainsString( 'approved for this turn', $error->get_error_message() );

		ToolPermissionResolver::set_one_turn_approved_abilities( [ 'sd-ai-agent/list-posts' ] );

		try {
			$this->assertTrue( ToolCapabilities::current_user_can( 'sd-ai-agent/list-posts' ) );
			$this->assertNull( ToolCapabilities::permission_denial_error( 'sd-ai-agent/list-posts' ) );
		} finally {
			ToolPermissionResolver::clear_one_turn_approved_abilities();
		}
	}

	/**
	 * Dual-gate: per-tool cap held but core cap missing → false.
	 *
	 * Proves the core-cap layer is enforced even when a role plugin
	 * grants the per-tool cap to a low-privilege role.
	 */
	public function test_dual_gate_per_tool_cap_alone_is_not_sufficient(): void {
		$ability_id = 'sd-ai-agent/delete-plugin'; // CORE_CAP_MAP → delete_plugins.
		$tool_cap   = ToolCapabilities::cap_name( $ability_id );

		// Pretend a role plugin granted the per-tool cap to subscribers.
		$role = get_role( 'subscriber' );
		$this->assertNotNull( $role );
		$role->add_cap( $tool_cap, true );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		// Subscribers have neither delete_plugins nor manage_options →
		// the core-cap layer must reject regardless of the per-tool cap.
		$this->assertFalse( ToolCapabilities::current_user_can( $ability_id ) );

		// Clean up.
		$role->remove_cap( $tool_cap );
	}

	/**
	 * One-turn approval never bypasses the required WordPress core capability.
	 */
	public function test_one_turn_approval_does_not_bypass_core_capability(): void {
		$ability_id = 'sd-ai-agent/delete-plugin'; // CORE_CAP_MAP → delete_plugins.

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );
		ToolPermissionResolver::set_one_turn_approved_abilities( [ $ability_id ] );

		try {
			$this->assertFalse( ToolCapabilities::current_user_can( $ability_id ) );

			$error = ToolCapabilities::permission_denial_error( $ability_id );
			$this->assertInstanceOf( \WP_Error::class, $error );
			$this->assertSame( 'ability_invalid_permissions', $error->get_error_code() );
			$this->assertSame( 'delete_plugins', $error->get_error_data()['missing_capability'] );
			$this->assertSame( 'core', $error->get_error_data()['permission_layer'] );
			$this->assertTrue( $error->get_error_data()['approved_for_turn'] );
			$this->assertStringContainsString( 'approved for this turn', $error->get_error_message() );
		} finally {
			ToolPermissionResolver::clear_one_turn_approved_abilities();
		}
	}

	/**
	 * Denial diagnostics identify the missing core capability after a tool grant.
	 */
	public function test_permission_denial_error_identifies_missing_core_capability(): void {
		$ability_id = 'sd-ai-agent/file-edit';
		$tool_cap   = ToolCapabilities::cap_name( $ability_id );

		$role = get_role( 'subscriber' );
		$this->assertNotNull( $role );
		$role->add_cap( $tool_cap, true );
		$role->add_cap( 'manage_options', true );

		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		$error = ToolCapabilities::permission_denial_error( $ability_id );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'ability_invalid_permissions', $error->get_error_code() );
		$this->assertSame( 'edit_files', $error->get_error_data()['missing_capability'] );
		$this->assertSame( 'core', $error->get_error_data()['permission_layer'] );
		$this->assertStringNotContainsString( 'approved for this turn', $error->get_error_message() );

		$role->remove_cap( $tool_cap );
		$role->remove_cap( 'manage_options' );
	}

	/**
	 * Dual-gate: administrator holds both per-tool and core caps → true.
	 */
	public function test_dual_gate_administrator_passes_for_mapped_ability(): void {
		$admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$this->assertTrue( ToolCapabilities::current_user_can( 'sd-ai-agent/delete-plugin' ) );
		$this->assertTrue( ToolCapabilities::current_user_can( 'sd-ai-agent/list-posts' ) );
		$this->assertTrue( ToolCapabilities::current_user_can( 'sd-ai-agent/run-php' ) );
	}

	/**
	 * CORE_CAP_OPTOUT abilities skip the core-cap layer entirely.
	 *
	 * `sd-ai-agent/report-inability` is the only opted-out ability — any
	 * logged-in user may report an AI failure. Per-tool layer still applies.
	 */
	public function test_core_cap_optout_skips_core_layer(): void {
		$tool_cap = ToolCapabilities::cap_name( 'sd-ai-agent/report-inability' );
		$role     = get_role( 'subscriber' );
		$this->assertNotNull( $role );
		// Add the per-tool cap to the role BEFORE creating the user so the
		// user's allcaps cache picks it up.
		$role->add_cap( $tool_cap, true );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		// Per-tool granted, core-cap layer skipped → true.
		$this->assertTrue( ToolCapabilities::current_user_can( 'sd-ai-agent/report-inability' ) );

		$role->remove_cap( $tool_cap );
	}

	/**
	 * run-php requires the strictest cap set: manage_options, update_core,
	 * unfiltered_html — even though SD_AI_AGENT_FEATURE_RUN_PHP also gates
	 * registration. This is the in-code belt for the belt-and-braces
	 * defence-in-depth: a misconfigured role plugin must not expose run-php.
	 */
	public function test_run_php_requires_strictest_cap_set(): void {
		$caps = ToolCapabilities::resolve_core_caps( 'sd-ai-agent/run-php' );

		$this->assertContains( 'manage_options', $caps );
		$this->assertContains( 'update_core', $caps );
		$this->assertContains( 'unfiltered_html', $caps );
	}

	/**
	 * file-outline uses the same core cap as file-read.
	 */
	public function test_file_outline_uses_file_read_core_cap(): void {
		$this->assertSame(
			ToolCapabilities::resolve_core_caps( 'sd-ai-agent/file-read' ),
			ToolCapabilities::resolve_core_caps( 'sd-ai-agent/file-outline' )
		);
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
			'sd-ai-agent/file-outline',
			'sd-ai-agent/file-write',
			'sd-ai-agent/navigate',
			'sd-ai-agent/compile-design-tokens',
			'sd-ai-agent/validate-block-theme-project',
		];

		foreach ( $expected as $id ) {
			$this->assertContains( $id, $ids, "Expected ability ID '{$id}' not found in all_ability_ids()" );
		}
	}

	/**
	 * Token compilation remains behind the standard theme-options core gate.
	 */
	public function test_compile_design_tokens_requires_edit_theme_options(): void {
		$this->assertSame(
			[ 'edit_theme_options' ],
			ToolCapabilities::resolve_core_caps( 'sd-ai-agent/compile-design-tokens' )
		);
	}

	/**
	 * Project validation remains behind the standard theme-options core gate.
	 */
	public function test_validate_block_theme_project_requires_edit_theme_options(): void {
		$this->assertSame(
			[ 'edit_theme_options' ],
			ToolCapabilities::resolve_core_caps( 'sd-ai-agent/validate-block-theme-project' )
		);
	}
}
