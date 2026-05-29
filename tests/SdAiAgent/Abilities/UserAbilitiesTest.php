<?php
/**
 * Test case for UserAbilities class.
 *
 * Only covers the read-only `sd-ai-agent/list-users` ability. The mutating
 * `create-user` and `update-user-role` abilities were moved to
 * {@see \SdAiAgent\Abilities\UserManagementAbilities} so the wp.org
 * distribution build can exclude them; tests for those handlers live in
 * `UserManagementAbilitiesTest`.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\UserAbilities;
use WP_UnitTestCase;

/**
 * Test UserAbilities handler methods.
 */
class UserAbilitiesTest extends WP_UnitTestCase {

	// ─── handle_list_users ────────────────────────────────────────

	/**
	 * Test handle_list_users returns expected structure.
	 */
	public function test_handle_list_users_returns_structure() {
		$result = UserAbilities::handle_list_users( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'users', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertIsArray( $result['users'] );
		$this->assertIsInt( $result['total'] );
	}

	/**
	 * Test handle_list_users total matches users array count.
	 */
	public function test_handle_list_users_total_matches_count() {
		$result = UserAbilities::handle_list_users( [] );

		$this->assertSame( count( $result['users'] ), $result['total'] );
	}

	/**
	 * Test handle_list_users each user has required fields.
	 */
	public function test_handle_list_users_user_structure() {
		// Ensure at least one user exists.
		$this->factory->user->create( [ 'role' => 'subscriber' ] );

		$result = UserAbilities::handle_list_users( [] );

		$this->assertNotEmpty( $result['users'] );
		$user = $result['users'][0];
		$this->assertArrayHasKey( 'id', $user );
		$this->assertArrayHasKey( 'username', $user );
		$this->assertArrayHasKey( 'email', $user );
		$this->assertArrayHasKey( 'display_name', $user );
		$this->assertArrayHasKey( 'roles', $user );
		$this->assertArrayHasKey( 'registered', $user );
	}

	/**
	 * Test handle_list_users with role filter returns only that role.
	 */
	public function test_handle_list_users_role_filter() {
		$this->factory->user->create( [ 'role' => 'editor' ] );

		$result = UserAbilities::handle_list_users( [ 'role' => 'editor' ] );

		$this->assertIsArray( $result );
		foreach ( $result['users'] as $user ) {
			$this->assertContains( 'editor', $user['roles'] );
		}
	}

	/**
	 * Test handle_list_users limit is respected.
	 */
	public function test_handle_list_users_limit() {
		$this->factory->user->create_many( 5 );

		$result = UserAbilities::handle_list_users( [ 'limit' => 2 ] );

		$this->assertLessThanOrEqual( 2, $result['total'] );
	}

	/**
	 * Test handle_list_users limit is clamped to 100 maximum.
	 */
	public function test_handle_list_users_limit_clamped_max() {
		$result = UserAbilities::handle_list_users( [ 'limit' => 9999 ] );

		// Should not error — limit is clamped internally.
		$this->assertIsArray( $result );
		$this->assertLessThanOrEqual( 100, $result['total'] );
	}

	/**
	 * Test handle_list_users with search term filters results.
	 */
	public function test_handle_list_users_search() {
		$unique = 'uniqueuser_' . uniqid();
		$this->factory->user->create( [
			'user_login' => $unique,
			'user_email' => $unique . '@example.com',
		] );

		$result = UserAbilities::handle_list_users( [ 'search' => $unique ] );

		$this->assertGreaterThanOrEqual( 1, $result['total'] );
	}
}
