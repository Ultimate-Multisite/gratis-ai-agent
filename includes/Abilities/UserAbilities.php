<?php

declare(strict_types=1);
/**
 * User management abilities for the AI agent.
 *
 * Provides user listing, creation, role management, and profile updates.
 * Ported from the WordPress/ai experiments plugin pattern.
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

class UserAbilities {

	/**
	 * Register all user management abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/list-users',
			[
				'label'               => __( 'List Users', 'superdav-ai-agent' ),
				'description'         => __( 'List WordPress users with optional filtering by role, search term, or number. Returns ID, login, email, display name, roles, and registration date.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'role'   => [
							'type'        => 'string',
							'description' => 'Filter by role slug (e.g. "administrator", "editor", "author", "subscriber"). Omit for all roles.',
						],
						'search' => [
							'type'        => 'string',
							'description' => 'Search term matched against login, email, URL, or display name.',
						],
						'limit'  => [
							'type'        => 'integer',
							'description' => 'Maximum number of users to return (default: 20, max: 100).',
						],
					],
					'required'   => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'users' => [ 'type' => 'array' ],
						'total' => [ 'type' => 'integer' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'   => true,
						'idempotent' => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_list_users' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'list_users' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/create-user',
			[
				'label'               => __( 'Create User', 'superdav-ai-agent' ),
				'description'         => __( 'Create a new WordPress user with the specified username, email, role, and optional display name. Returns the new user ID.', 'superdav-ai-agent' ),
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
							'description' => 'The role to assign (e.g. "subscriber", "author", "editor"). Defaults to the site default role.',
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
				'permission_callback' => function (): bool {
					return current_user_can( 'create_users' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/update-user-role',
			[
				'label'               => __( 'Update User Role', 'superdav-ai-agent' ),
				'description'         => __( 'Change the role of an existing WordPress user. Provide either user_id or user_email to identify the user.', 'superdav-ai-agent' ),
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
							'description' => 'The new role slug (e.g. "editor", "author", "subscriber", "administrator").',
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
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_users' );
				},
			]
		);
	}

	/**
	 * Handle the list-users ability.
	 *
	 * @param array<string, mixed> $input Input with optional role, search, limit.
	 * @return array<string, mixed>
	 */
	public static function handle_list_users( array $input ): array {
		// @phpstan-ignore-next-line
		$role = sanitize_text_field( $input['role'] ?? '' );
		// @phpstan-ignore-next-line
		$search = sanitize_text_field( $input['search'] ?? '' );
		// @phpstan-ignore-next-line
		$limit = min( 100, max( 1, (int) ( $input['limit'] ?? 20 ) ) );

		$args = [
			'number'  => $limit,
			'fields'  => 'all',
			'orderby' => 'registered',
			'order'   => 'DESC',
		];

		if ( ! empty( $role ) ) {
			$args['role'] = $role;
		}

		if ( ! empty( $search ) ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
		}

		$user_query = new \WP_User_Query( $args );
		$wp_users   = $user_query->get_results();

		$users = [];
		foreach ( $wp_users as $user ) {
			if ( ! ( $user instanceof WP_User ) ) {
				continue;
			}
			$users[] = [
				'id'           => $user->ID,
				'username'     => $user->user_login,
				'email'        => $user->user_email,
				'display_name' => $user->display_name,
				'roles'        => $user->roles,
				'registered'   => $user->user_registered,
				'url'          => $user->user_url,
			];
		}

		return [
			'users' => $users,
			'total' => count( $users ),
		];
	}

	/**
	 * Handle the create-user ability.
	 *
	 * @param array<string, mixed> $input Input with username, email, optional role, display_name, send_email.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_create_user( array $input ) {
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

		// Validate role exists.
		$editable_roles = wp_roles()->get_names();
		if ( ! array_key_exists( $role, $editable_roles ) ) {
			return new WP_Error(
				'ai_agent_invalid_role',
				/* translators: %s: role slug */
				sprintf( __( 'Role "%s" does not exist.', 'superdav-ai-agent' ), $role )
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
		// @phpstan-ignore-next-line
		$user_id = (int) ( $input['user_id'] ?? 0 );
		// @phpstan-ignore-next-line
		$user_email = sanitize_email( $input['user_email'] ?? '' );
		// @phpstan-ignore-next-line
		$new_role = sanitize_text_field( $input['role'] ?? '' );

		if ( empty( $new_role ) ) {
			return new WP_Error( 'ai_agent_empty_role', __( 'Role is required.', 'superdav-ai-agent' ) );
		}

		// Validate role exists.
		$editable_roles = wp_roles()->get_names();
		if ( ! array_key_exists( $new_role, $editable_roles ) ) {
			return new WP_Error(
				'ai_agent_invalid_role',
				/* translators: %s: role slug */
				sprintf( __( 'Role "%s" does not exist.', 'superdav-ai-agent' ), $new_role )
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
