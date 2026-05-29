<?php
/**
 * Test case for UserManagementAbilities class.
 *
 * Covers handle_create_user(), handle_update_user_role(), and the
 * capability/permission callbacks. All tests run as an administrator
 * unless explicitly testing the role-escalation / capability-gate path.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\UserManagementAbilities;
use WP_UnitTestCase;

/**
 * Test UserManagementAbilities handler + permission methods.
 */
class UserManagementAbilitiesTest extends WP_UnitTestCase {

	/**
	 * The currently impersonated user ID for each test.
	 *
	 * @var int
	 */
	private int $original_user_id = 0;

	/**
	 * Run every assertion as an administrator by default so the
	 * defence-in-depth permission re-check inside the handlers does not
	 * short-circuit the test logic. Individual tests override this to
	 * exercise the capability-gate path.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->original_user_id = (int) get_current_user_id();
		$admin_id               = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );
	}

	/**
	 * Restore the original current-user so later tests are not polluted.
	 */
	public function tear_down(): void {
		wp_set_current_user( $this->original_user_id );
		parent::tear_down();
	}

	// ─── handle_create_user ───────────────────────────────────────

	/**
	 * Test handle_create_user with empty username returns WP_Error.
	 */
	public function test_handle_create_user_empty_username() {
		$result = UserManagementAbilities::handle_create_user( [
			'username' => '',
			'email'    => 'test@example.com',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_empty_username', $result->get_error_code() );
	}

	/**
	 * Test handle_create_user with invalid email returns WP_Error.
	 */
	public function test_handle_create_user_invalid_email() {
		$result = UserManagementAbilities::handle_create_user( [
			'username' => 'testuser_' . uniqid(),
			'email'    => 'not-an-email',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_invalid_email', $result->get_error_code() );
	}

	/**
	 * Test handle_create_user with duplicate username returns WP_Error.
	 */
	public function test_handle_create_user_duplicate_username() {
		$username = 'dupuser_' . uniqid();
		$this->factory->user->create( [
			'user_login' => $username,
			'user_email' => $username . '@example.com',
		] );

		$result = UserManagementAbilities::handle_create_user( [
			'username' => $username,
			'email'    => 'other_' . uniqid() . '@example.com',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_username_exists', $result->get_error_code() );
	}

	/**
	 * Test handle_create_user with duplicate email returns WP_Error.
	 */
	public function test_handle_create_user_duplicate_email() {
		$email = 'dupemail_' . uniqid() . '@example.com';
		$this->factory->user->create( [
			'user_login' => 'user_' . uniqid(),
			'user_email' => $email,
		] );

		$result = UserManagementAbilities::handle_create_user( [
			'username' => 'newuser_' . uniqid(),
			'email'    => $email,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_email_exists', $result->get_error_code() );
	}

	/**
	 * Test handle_create_user with non-existent role returns the
	 * editable-roles error (covers both "role does not exist" and
	 * "role not editable by current user").
	 */
	public function test_handle_create_user_invalid_role() {
		$result = UserManagementAbilities::handle_create_user( [
			'username' => 'roleuser_' . uniqid(),
			'email'    => 'roleuser_' . uniqid() . '@example.com',
			'role'     => 'nonexistent_role',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_role_not_editable', $result->get_error_code() );
	}

	/**
	 * Test handle_create_user with valid data creates user and returns structure.
	 */
	public function test_handle_create_user_returns_structure() {
		$username = 'newuser_' . uniqid();
		$email    = $username . '@example.com';

		$result = UserManagementAbilities::handle_create_user( [
			'username' => $username,
			'email'    => $email,
			'role'     => 'subscriber',
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'user_id', $result );
		$this->assertArrayHasKey( 'username', $result );
		$this->assertArrayHasKey( 'email', $result );
		$this->assertArrayHasKey( 'role', $result );
		$this->assertArrayHasKey( 'display_name', $result );
		$this->assertIsInt( $result['user_id'] );
		$this->assertGreaterThan( 0, $result['user_id'] );
		$this->assertSame( $username, $result['username'] );
		$this->assertSame( $email, $result['email'] );
		$this->assertSame( 'subscriber', $result['role'] );
	}

	/**
	 * Test handle_create_user refuses when current user lacks create_users.
	 *
	 * Even an editor (who has edit_users / promote_users) does not have
	 * create_users, so the permission re-check inside the handler must
	 * return ai_agent_forbidden.
	 */
	public function test_handle_create_user_blocked_without_create_users_cap() {
		$editor_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );

		$result = UserManagementAbilities::handle_create_user( [
			'username' => 'should_not_exist_' . uniqid(),
			'email'    => 'noperm_' . uniqid() . '@example.com',
			'role'     => 'subscriber',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_forbidden', $result->get_error_code() );
	}

	/**
	 * Test can_create_user() requires both create_users AND promote_users.
	 */
	public function test_can_create_user_requires_both_caps() {
		// Administrator has both.
		$this->assertTrue( UserManagementAbilities::can_create_user() );

		// Subscriber has neither.
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );
		$this->assertFalse( UserManagementAbilities::can_create_user() );
	}

	// ─── handle_update_user_role ──────────────────────────────────

	/**
	 * Test handle_update_user_role with empty role returns WP_Error.
	 */
	public function test_handle_update_user_role_empty_role() {
		$result = UserManagementAbilities::handle_update_user_role( [ 'role' => '' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_empty_role', $result->get_error_code() );
	}

	/**
	 * Test handle_update_user_role with invalid role returns WP_Error.
	 */
	public function test_handle_update_user_role_invalid_role() {
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );

		$result = UserManagementAbilities::handle_update_user_role( [
			'user_id' => $user_id,
			'role'    => 'nonexistent_role',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_role_not_editable', $result->get_error_code() );
	}

	/**
	 * Test handle_update_user_role with no user identifier returns WP_Error.
	 */
	public function test_handle_update_user_role_user_not_found() {
		$result = UserManagementAbilities::handle_update_user_role( [
			'role' => 'editor',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_user_not_found', $result->get_error_code() );
	}

	/**
	 * Test handle_update_user_role with valid user_id updates role.
	 */
	public function test_handle_update_user_role_by_user_id() {
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );

		$result = UserManagementAbilities::handle_update_user_role( [
			'user_id' => $user_id,
			'role'    => 'editor',
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'user_id', $result );
		$this->assertArrayHasKey( 'username', $result );
		$this->assertArrayHasKey( 'previous_role', $result );
		$this->assertArrayHasKey( 'new_role', $result );
		$this->assertSame( $user_id, $result['user_id'] );
		$this->assertSame( 'subscriber', $result['previous_role'] );
		$this->assertSame( 'editor', $result['new_role'] );
	}

	/**
	 * Test handle_update_user_role can resolve user by email.
	 */
	public function test_handle_update_user_role_by_email() {
		$email   = 'rolebyemail_' . uniqid() . '@example.com';
		$user_id = $this->factory->user->create( [
			'user_email' => $email,
			'role'       => 'author',
		] );

		$result = UserManagementAbilities::handle_update_user_role( [
			'user_email' => $email,
			'role'       => 'editor',
		] );

		$this->assertIsArray( $result );
		$this->assertSame( $user_id, $result['user_id'] );
		$this->assertSame( 'editor', $result['new_role'] );
	}

	/**
	 * Test handle_update_user_role refuses to change own role
	 * (prevents self-elevation).
	 */
	public function test_handle_update_user_role_refuses_self_modification() {
		$me = get_current_user_id();

		$result = UserManagementAbilities::handle_update_user_role( [
			'user_id' => $me,
			'role'    => 'subscriber',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_cannot_modify_self', $result->get_error_code() );
	}

	/**
	 * Test handle_update_user_role refuses when current user lacks
	 * promote_users (defence-in-depth: handler re-checks permissions).
	 */
	public function test_handle_update_user_role_blocked_without_promote_users_cap() {
		$target_id     = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = UserManagementAbilities::handle_update_user_role( [
			'user_id' => $target_id,
			'role'    => 'editor',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_forbidden', $result->get_error_code() );
	}

	/**
	 * Test can_update_user_role() requires both promote_users AND edit_users.
	 */
	public function test_can_update_user_role_requires_both_caps() {
		// Administrator has both.
		$this->assertTrue( UserManagementAbilities::can_update_user_role() );

		// Subscriber has neither.
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );
		$this->assertFalse( UserManagementAbilities::can_update_user_role() );
	}
}
