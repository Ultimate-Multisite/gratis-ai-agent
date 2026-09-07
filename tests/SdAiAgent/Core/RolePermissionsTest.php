<?php
/**
 * Test case for RolePermissions class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\RolePermissions;
use WP_UnitTestCase;

/**
 * Test RolePermissions functionality.
 */
class RolePermissionsTest extends WP_UnitTestCase {

	/**
	 * Clean up options after each test.
	 */
	public function tear_down(): void {
		parent::tear_down();
		delete_option( RolePermissions::OPTION_NAME );
	}

	// ── constants ─────────────────────────────────────────────────────────

	/**
	 * Test OPTION_NAME constant.
	 */
	public function test_option_name_constant(): void {
		$this->assertSame( 'sd_ai_agent_role_permissions', RolePermissions::OPTION_NAME );
	}

	/**
	 * Test ALWAYS_ALLOWED_ROLES contains administrator.
	 */
	public function test_always_allowed_roles_contains_administrator(): void {
		$this->assertContains( 'administrator', RolePermissions::ALWAYS_ALLOWED_ROLES );
	}

	// ── get_defaults ──────────────────────────────────────────────────────

	/**
	 * Test get_defaults returns array with expected roles.
	 */
	public function test_get_defaults_returns_expected_roles(): void {
		$defaults = RolePermissions::get_defaults();

		$this->assertIsArray( $defaults );
		$this->assertArrayHasKey( 'editor', $defaults );
		$this->assertArrayHasKey( 'author', $defaults );
		$this->assertArrayHasKey( 'contributor', $defaults );
		$this->assertArrayHasKey( 'subscriber', $defaults );
	}

	/**
	 * Test editor has chat_access true by default.
	 */
	public function test_editor_has_chat_access_by_default(): void {
		$defaults = RolePermissions::get_defaults();
		$this->assertTrue( $defaults['editor']['chat_access'] );
	}

	/**
	 * Test author has chat_access true by default.
	 */
	public function test_author_has_chat_access_by_default(): void {
		$defaults = RolePermissions::get_defaults();
		$this->assertTrue( $defaults['author']['chat_access'] );
	}

	/**
	 * Test contributor has chat_access false by default.
	 */
	public function test_contributor_has_no_chat_access_by_default(): void {
		$defaults = RolePermissions::get_defaults();
		$this->assertFalse( $defaults['contributor']['chat_access'] );
	}

	/**
	 * Test subscriber has chat_access false by default.
	 */
	public function test_subscriber_has_no_chat_access_by_default(): void {
		$defaults = RolePermissions::get_defaults();
		$this->assertFalse( $defaults['subscriber']['chat_access'] );
	}

	/**
	 * Test defaults have empty allowed_abilities (unrestricted).
	 */
	public function test_defaults_have_empty_allowed_abilities(): void {
		$defaults = RolePermissions::get_defaults();
		$this->assertSame( [], $defaults['editor']['allowed_abilities'] );
		$this->assertSame( [], $defaults['author']['allowed_abilities'] );
	}

	// ── get ───────────────────────────────────────────────────────────────

	/**
	 * Test get returns defaults when no option saved.
	 */
	public function test_get_returns_defaults_when_no_option(): void {
		delete_option( RolePermissions::OPTION_NAME );
		$result = RolePermissions::get();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'editor', $result );
		$this->assertArrayHasKey( 'contributor', $result );
	}

	/**
	 * Test get merges saved values over defaults.
	 */
	public function test_get_merges_saved_over_defaults(): void {
		update_option(
			RolePermissions::OPTION_NAME,
			[
				'editor' => [
					'chat_access'       => false,
					'allowed_abilities' => [ 'sd-ai-agent/memory-save' ],
				],
			]
		);

		$result = RolePermissions::get();

		// Editor should have the saved value.
		$this->assertFalse( $result['editor']['chat_access'] );
		$this->assertSame( [ 'sd-ai-agent/memory-save' ], $result['editor']['allowed_abilities'] );

		// Author should still have defaults.
		$this->assertTrue( $result['author']['chat_access'] );
	}

	/**
	 * Test get returns defaults when saved option is not an array.
	 */
	public function test_get_returns_defaults_when_option_not_array(): void {
		update_option( RolePermissions::OPTION_NAME, 'invalid' );
		$result = RolePermissions::get();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'editor', $result );
	}

	/**
	 * Test get preserves extra roles not in defaults.
	 */
	public function test_get_preserves_extra_roles(): void {
		update_option(
			RolePermissions::OPTION_NAME,
			[
				'shop_manager' => [
					'chat_access'       => true,
					'allowed_abilities' => [],
				],
			]
		);

		$result = RolePermissions::get();

		$this->assertArrayHasKey( 'shop_manager', $result );
		$this->assertTrue( $result['shop_manager']['chat_access'] );
	}

	// ── update ────────────────────────────────────────────────────────────

	/**
	 * Test update saves sanitized data.
	 */
	public function test_update_saves_data(): void {
		$data = [
			'editor' => [
				'chat_access'       => true,
				'allowed_abilities' => [ 'sd-ai-agent/memory-save' ],
			],
		];

		$result = RolePermissions::update( $data );

		$this->assertTrue( $result );
		$saved = get_option( RolePermissions::OPTION_NAME );
		$this->assertIsArray( $saved );
		$this->assertArrayHasKey( 'editor', $saved );
	}

	/**
	 * Test update skips administrator role.
	 */
	public function test_update_skips_administrator(): void {
		$data = [
			'administrator' => [
				'chat_access'       => false,
				'allowed_abilities' => [],
			],
			'editor'        => [
				'chat_access'       => true,
				'allowed_abilities' => [],
			],
		];

		RolePermissions::update( $data );

		$saved = get_option( RolePermissions::OPTION_NAME );
		$this->assertIsArray( $saved );
		$this->assertArrayNotHasKey( 'administrator', $saved );
		$this->assertArrayHasKey( 'editor', $saved );
	}

	/**
	 * Test update skips non-string role keys.
	 */
	public function test_update_skips_non_string_keys(): void {
		$data = [
			'editor'  => [
				'chat_access'       => true,
				'allowed_abilities' => [],
			],
			// Numeric key — should be skipped.
			0         => [
				'chat_access'       => true,
				'allowed_abilities' => [],
			],
		];

		RolePermissions::update( $data );

		$saved = get_option( RolePermissions::OPTION_NAME );
		$this->assertIsArray( $saved );
		$this->assertArrayNotHasKey( 0, $saved );
	}

	/**
	 * Test update sanitizes allowed_abilities to strings only.
	 */
	public function test_update_sanitizes_allowed_abilities(): void {
		$data = [
			'editor' => [
				'chat_access'       => true,
				'allowed_abilities' => [ 'valid-ability', 123, null, 'another-ability' ],
			],
		];

		RolePermissions::update( $data );

		$saved = get_option( RolePermissions::OPTION_NAME );
		// Only string values should be kept.
		$this->assertIsArray( $saved['editor']['allowed_abilities'] );
		foreach ( $saved['editor']['allowed_abilities'] as $ability ) {
			$this->assertIsString( $ability );
		}
	}

	// ── current_user_has_chat_access ──────────────────────────────────────

	/**
	 * Test logged-out user has no chat access.
	 */
	public function test_logged_out_user_has_no_chat_access(): void {
		wp_set_current_user( 0 );
		$this->assertFalse( RolePermissions::current_user_has_chat_access() );
	}

	/**
	 * Test administrator always has chat access.
	 */
	public function test_administrator_always_has_chat_access(): void {
		$admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$this->assertTrue( RolePermissions::current_user_has_chat_access() );
	}

	/**
	 * Test editor has chat access by default.
	 */
	public function test_editor_has_chat_access(): void {
		$editor_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );

		// Default config gives editors chat access.
		$this->assertTrue( RolePermissions::current_user_has_chat_access() );
	}

	/**
	 * Test contributor has no chat access by default.
	 */
	public function test_contributor_has_no_chat_access(): void {
		$contributor_id = $this->factory->user->create( [ 'role' => 'contributor' ] );
		wp_set_current_user( $contributor_id );

		$this->assertFalse( RolePermissions::current_user_has_chat_access() );
	}

	/**
	 * Test editor loses chat access when config disables it.
	 */
	public function test_editor_loses_chat_access_when_disabled(): void {
		update_option(
			RolePermissions::OPTION_NAME,
			[
				'editor' => [
					'chat_access'       => false,
					'allowed_abilities' => [],
				],
			]
		);

		$editor_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );

		$this->assertFalse( RolePermissions::current_user_has_chat_access() );
	}

	// ── get_allowed_abilities_for_current_user ────────────────────────────

	/**
	 * Test administrator gets null (unrestricted).
	 */
	public function test_administrator_gets_null_abilities(): void {
		$admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$this->assertNull( RolePermissions::get_allowed_abilities_for_current_user() );
	}

	/**
	 * Test editor with empty allowed_abilities gets null (unrestricted).
	 */
	public function test_editor_with_empty_abilities_gets_null(): void {
		$editor_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );

		// Default: empty allowed_abilities = unrestricted.
		$this->assertNull( RolePermissions::get_allowed_abilities_for_current_user() );
	}

	/**
	 * Test role with specific abilities returns that list.
	 */
	public function test_role_with_specific_abilities_returns_list(): void {
		update_option(
			RolePermissions::OPTION_NAME,
			[
				'author' => [
					'chat_access'       => true,
					'allowed_abilities' => [ 'sd-ai-agent/memory-save', 'sd-ai-agent/post-create' ],
				],
			]
		);

		$author_id = $this->factory->user->create( [ 'role' => 'author' ] );
		wp_set_current_user( $author_id );

		$allowed = RolePermissions::get_allowed_abilities_for_current_user();

		$this->assertIsArray( $allowed );
		$this->assertContains( 'sd-ai-agent/memory-save', $allowed );
		$this->assertContains( 'sd-ai-agent/post-create', $allowed );
	}

	/**
	 * Test user with no matching role config gets empty array (deny all).
	 */
	public function test_user_with_no_role_config_gets_empty_array(): void {
		// Use a custom role that exists in neither defaults nor saved options.
		// get_allowed_abilities_for_current_user() returns [] (deny all) when
		// no role config is found for the current user's roles.
		$custom_role = 'custom_no_config_role';
		add_role( $custom_role, 'Custom No Config Role' );

		$user_id = $this->factory->user->create( [ 'role' => $custom_role ] );
		wp_set_current_user( $user_id );

		$allowed = RolePermissions::get_allowed_abilities_for_current_user();

		// No matching role config → deny-all: must return an empty array, not null.
		$this->assertSame( [], $allowed );

		// Clean up the custom role.
		remove_role( $custom_role );
	}

	// ── current_user_can_use_ability ──────────────────────────────────────

	/**
	 * Test administrator can use any ability.
	 */
	public function test_administrator_can_use_any_ability(): void {
		$admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$this->assertTrue( RolePermissions::current_user_can_use_ability( 'sd-ai-agent/any-ability' ) );
	}

	/**
	 * Test user with specific abilities can use allowed ability.
	 */
	public function test_user_can_use_allowed_ability(): void {
		update_option(
			RolePermissions::OPTION_NAME,
			[
				'author' => [
					'chat_access'       => true,
					'allowed_abilities' => [ 'sd-ai-agent/memory-save' ],
				],
			]
		);

		$author_id = $this->factory->user->create( [ 'role' => 'author' ] );
		wp_set_current_user( $author_id );

		$this->assertTrue( RolePermissions::current_user_can_use_ability( 'sd-ai-agent/memory-save' ) );
	}

	/**
	 * Test user with specific abilities cannot use disallowed ability.
	 */
	public function test_user_cannot_use_disallowed_ability(): void {
		update_option(
			RolePermissions::OPTION_NAME,
			[
				'author' => [
					'chat_access'       => true,
					'allowed_abilities' => [ 'sd-ai-agent/memory-save' ],
				],
			]
		);

		$author_id = $this->factory->user->create( [ 'role' => 'author' ] );
		wp_set_current_user( $author_id );

		$this->assertFalse( RolePermissions::current_user_can_use_ability( 'sd-ai-agent/file-write' ) );
	}

	// ── get_all_roles ─────────────────────────────────────────────────────

	/**
	 * Test get_all_roles returns array.
	 */
	public function test_get_all_roles_returns_array(): void {
		$roles = RolePermissions::get_all_roles();
		$this->assertIsArray( $roles );
	}

	/**
	 * Test get_all_roles includes administrator.
	 */
	public function test_get_all_roles_includes_administrator(): void {
		$roles = RolePermissions::get_all_roles();
		$this->assertArrayHasKey( 'administrator', $roles );
	}

	/**
	 * Test get_all_roles values are strings (display names).
	 */
	public function test_get_all_roles_values_are_strings(): void {
		$roles = RolePermissions::get_all_roles();
		foreach ( $roles as $slug => $name ) {
			$this->assertIsString( $slug );
			$this->assertIsString( $name );
		}
	}
}
