<?php

declare(strict_types=1);
/**
 * Mutating user-management abilities (create user, change role).
 *
 * Split out of UserAbilities.php so that:
 *
 *  1. The read-only `sd-ai-agent/list-users` ability can continue to ship in
 *     every build (including WordPress.org) without exposing user creation
 *     or role escalation surface.
 *  2. Mutating user-management tools can live only in the separately
 *     distributed Superdav AI Agent Advanced companion plugin, keeping the core
 *     WordPress.org package free of user create/login surface in line with the
 *     WordPress.org plugin review feedback that custom user creation routes can
 *     bypass security plugins (e.g. login throttling, password-policy
 *     enforcers) that hook the native register/login flow.
 *
 * Hardening summary (applies on every build that still includes this file):
 *
 *  - `create-user` requires BOTH `create_users` AND `promote_users` at the
 *    permission_callback layer, then re-verifies inside the handler so the
 *    ability cannot be reached via direct PHP invocation that skips the REST
 *    permission layer.
 *  - The target role is validated against `get_editable_roles()` (the same
 *    filter WP core uses on wp-admin/user-new.php), which means a non-admin
 *    granted this ability can only assign roles whose capabilities are a
 *    subset of their own. In particular, a non-administrator cannot create
 *    an administrator account even if a privileged user delegated the tool.
 *  - On multisite, super-admin role assignment is refused outright; the
 *    native Network Admin → Users screen is the supported path.
 *  - `update-user-role` requires `promote_users` AND `edit_users`, refuses
 *    self-modification (prevents self-elevation), refuses to touch a
 *    super-admin, and applies the same `get_editable_roles()` ceiling so
 *    the current user cannot grant a role they could not assign in the
 *    native WP admin.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use WP_Error;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the two mutating user-management abilities.
 *
 * Read-only user discovery (`sd-ai-agent/list-users`) lives in
 * `UserAbilities` because it is safe enough to ship in the WordPress.org
 * build. Anything that creates a user or changes a role lives here.
 */
class UserManagementAbilities {

	/**
	 * Register the create-user and update-user-role abilities.
	 *
	 * Gated upstream by `Features::is_enabled( Features::USER_MANAGEMENT )` and
	 * registered only by the advanced companion plugin.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/create-user',
			[
				'label'               => __( 'Create User', 'superdav-ai-agent' ),
				'description'         => __( 'Create a new WordPress user with the specified username, email, role, and optional display name. Requires the create_users + promote_users capabilities; the assignable role is bounded by the current user\'s own role (a non-administrator cannot create administrators). Returns the new user ID.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'username'     => [
							'type'        => 'string',
							'description' => 'The login username for the new user.',
						],
						'email'        => [
							'type'        => 'string',
							'description' => 'The email address for the new user.',
						],
						'role'         => [
							'type'        => 'string',
							'description' => 'The role to assign (e.g. "subscriber", "author", "editor"). Must be one of get_editable_roles() for the current user. Defaults to the site default role.',
						],
						'display_name' => [
							'type'        => 'string',
							'description' => 'Optional display name. Defaults to the username.',
						],
						'send_email'   => [
							'type'        => 'boolean',
							'description' => 'Whether to send a new-user notification email (default: false).',
						],
					],
					'required'   => [ 'username', 'email' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'user_id'      => [ 'type' => 'integer' ],
						'username'     => [ 'type' => 'string' ],
						'email'        => [ 'type' => 'string' ],
						'role'         => [ 'type' => 'string' ],
						'display_name' => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_create_user' ],
				'permission_callback' => [ __CLASS__, 'can_create_user' ],
			]
		);

		wp_register_ability(
			'sd-ai-agent/update-user-role',
			[
				'label'               => __( 'Update User Role', 'superdav-ai-agent' ),
				'description'         => __( 'Change the role of an existing WordPress user. Provide either user_id or user_email to identify the user. Requires promote_users + edit_users; refuses self-modification, refuses super-admin targets, and the assignable role is bounded by the current user\'s own role.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'user_id'    => [
							'type'        => 'integer',
							'description' => 'The ID of the user to update.',
						],
						'user_email' => [
							'type'        => 'string',
							'description' => 'The email of the user to update (alternative to user_id).',
						],
						'role'       => [
							'type'        => 'string',
							'description' => 'The new role slug (e.g. "editor", "author", "subscriber"). Must be one of get_editable_roles() for the current user.',
						],
					],
					'required'   => [ 'role' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'user_id'       => [ 'type' => 'integer' ],
						'username'      => [ 'type' => 'string' ],
						'previous_role' => [ 'type' => 'string' ],
						'new_role'      => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_update_user_role' ],
				'permission_callback' => [ __CLASS__, 'can_update_user_role' ],
			]
		);
	}

	/**
	 * Permission callback for create-user.
	 *
	 * Requires BOTH create_users (the WP cap that gates user creation) AND
	 * promote_users (the WP cap that gates role assignment) because this
	 * ability always sets a role as part of creation.
	 *
	 * @return bool
	 */
	public static function can_create_user(): bool {
		return current_user_can( 'create_users' ) && current_user_can( 'promote_users' );
	}

	/**
	 * Permission callback for update-user-role.
	 *
	 * @return bool
	 */
	public static function can_update_user_role(): bool {
		return current_user_can( 'promote_users' ) && current_user_can( 'edit_users' );
	}

	/**
	 * Handle the create-user ability.
	 *
	 * @param array<string, mixed> $input Input with username, email, optional role, display_name, send_email.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_create_user( array $input ) {
		// Defence-in-depth: re-check caps inside the handler so direct PHP
		// invocation (e.g. via `wp_get_ability(...)->execute(...)` from
		// privileged code paths) cannot bypass the REST permission layer.
		if ( ! self::can_create_user() ) {
			return new WP_Error(
				'ai_agent_forbidden',
				__( 'You do not have permission to create users.', 'superdav-ai-agent' ),
				[ 'status' => 403 ]
			);
		}

		// @phpstan-ignore-next-line
		$username = sanitize_user( $input['username'] ?? '' );
		// @phpstan-ignore-next-line
		$email = sanitize_email( $input['email'] ?? '' );
		// @phpstan-ignore-next-line
		$role = sanitize_text_field( $input['role'] ?? get_option( 'default_role', 'subscriber' ) );
		// @phpstan-ignore-next-line
		$display_name = sanitize_text_field( $input['display_name'] ?? $username );
		$send_email   = (bool) ( $input['send_email'] ?? false );

		if ( empty( $username ) ) {
			return new WP_Error( 'ai_agent_empty_username', __( 'Username is required.', 'superdav-ai-agent' ) );
		}

		if ( empty( $email ) || ! is_email( $email ) ) {
			return new WP_Error( 'ai_agent_invalid_email', __( 'A valid email address is required.', 'superdav-ai-agent' ) );
		}

		if ( username_exists( $username ) ) {
			return new WP_Error(
				'ai_agent_username_exists',
				/* translators: %s: username */
				sprintf( __( 'Username "%s" is already taken.', 'superdav-ai-agent' ), $username )
			);
		}

		if ( email_exists( $email ) ) {
			return new WP_Error(
				'ai_agent_email_exists',
				/* translators: %s: email address */
				sprintf( __( 'Email "%s" is already registered.', 'superdav-ai-agent' ), $email )
			);
		}

		// Role-escalation guard: only allow roles the *current user* is
		// permitted to assign. get_editable_roles() is filtered by WP core
		// (and by Multisite / Members / Role Manager / etc.) to exclude
		// roles whose capabilities exceed the current user's. This is the
		// same gate wp-admin/user-new.php uses, so a non-administrator
		// granted this ability cannot create administrators.
		$editable_roles = function_exists( 'get_editable_roles' ) ? get_editable_roles() : wp_roles()->roles;
		if ( ! is_array( $editable_roles ) || ! array_key_exists( $role, $editable_roles ) ) {
			return new WP_Error(
				'ai_agent_role_not_editable',
				/* translators: %s: role slug */
				sprintf( __( 'Role "%s" is not assignable by the current user.', 'superdav-ai-agent' ), $role )
			);
		}

		// Multisite: refuse super-admin assignment via this ability. The
		// supported path is Network Admin → Users. (Super-admin is not a
		// role but a meta-capability; this is just a belt-and-braces guard
		// in case a hostile filter adds it to get_editable_roles().)
		if ( 'super-admin' === $role || 'superadmin' === $role ) {
			return new WP_Error(
				'ai_agent_super_admin_blocked',
				__( 'Super-admin assignment is not permitted via this ability.', 'superdav-ai-agent' )
			);
		}

		$password = wp_generate_password( 24, true, true );

		$user_id = wp_create_user( $username, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$user = new WP_User( $user_id );
		$user->set_role( $role );

		wp_update_user(
			[
				'ID'           => $user_id,
				'display_name' => $display_name,
			]
		);

		if ( $send_email ) {
			wp_new_user_notification( $user_id, null, 'both' );
		}

		return [
			'user_id'      => $user_id,
			'username'     => $username,
			'email'        => $email,
			'role'         => $role,
			'display_name' => $display_name,
		];
	}

	/**
	 * Handle the update-user-role ability.
	 *
	 * @param array<string, mixed> $input Input with user_id or user_email, and role.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_update_user_role( array $input ) {
		// Defence-in-depth permission re-check (see handle_create_user).
		if ( ! self::can_update_user_role() ) {
			return new WP_Error(
				'ai_agent_forbidden',
				__( 'You do not have permission to change user roles.', 'superdav-ai-agent' ),
				[ 'status' => 403 ]
			);
		}

		// @phpstan-ignore-next-line
		$user_id = (int) ( $input['user_id'] ?? 0 );
		// @phpstan-ignore-next-line
		$user_email = sanitize_email( $input['user_email'] ?? '' );
		// @phpstan-ignore-next-line
		$new_role = sanitize_text_field( $input['role'] ?? '' );

		if ( empty( $new_role ) ) {
			return new WP_Error( 'ai_agent_empty_role', __( 'Role is required.', 'superdav-ai-agent' ) );
		}

		// Role-escalation guard: only allow roles the *current user* is
		// permitted to assign (see handle_create_user for rationale).
		$editable_roles = function_exists( 'get_editable_roles' ) ? get_editable_roles() : wp_roles()->roles;
		if ( ! is_array( $editable_roles ) || ! array_key_exists( $new_role, $editable_roles ) ) {
			return new WP_Error(
				'ai_agent_role_not_editable',
				/* translators: %s: role slug */
				sprintf( __( 'Role "%s" is not assignable by the current user.', 'superdav-ai-agent' ), $new_role )
			);
		}

		// Resolve user.
		$user = null;
		if ( $user_id > 0 ) {
			$user = get_user_by( 'id', $user_id );
		} elseif ( ! empty( $user_email ) ) {
			$user = get_user_by( 'email', $user_email );
		}

		if ( ! ( $user instanceof WP_User ) ) {
			return new WP_Error( 'ai_agent_user_not_found', __( 'User not found. Provide a valid user_id or user_email.', 'superdav-ai-agent' ) );
		}

		// Refuse self-modification — prevents self-elevation and matches the
		// wp-admin/user-edit.php behaviour that hides the role dropdown when
		// editing your own profile.
		if ( get_current_user_id() === (int) $user->ID ) {
			return new WP_Error(
				'ai_agent_cannot_modify_self',
				__( 'You cannot change your own role via this ability.', 'superdav-ai-agent' )
			);
		}

		// Multisite: refuse modifying a super-admin via this ability.
		if ( function_exists( 'is_super_admin' ) && is_super_admin( (int) $user->ID ) ) {
			return new WP_Error(
				'ai_agent_super_admin_target',
				__( 'Cannot change the role of a super-admin via this ability.', 'superdav-ai-agent' )
			);
		}

		// Prevent demoting the last administrator.
		if ( in_array( 'administrator', $user->roles, true ) && $new_role !== 'administrator' ) {
			$admin_count = (int) ( new \WP_User_Query(
				[
					'role'        => 'administrator',
					'count_total' => true,
				]
			) )->get_total();
			if ( $admin_count <= 1 ) {
				return new WP_Error(
					'ai_agent_last_admin',
					__( 'Cannot change the role of the last administrator.', 'superdav-ai-agent' )
				);
			}
		}

		$previous_role = ! empty( $user->roles ) ? $user->roles[0] : '';

		$user->set_role( $new_role );

		return [
			'user_id'       => $user->ID,
			'username'      => $user->user_login,
			'previous_role' => $previous_role,
			'new_role'      => $new_role,
		];
	}
}
