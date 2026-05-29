<?php

declare(strict_types=1);
/**
 * Read-only user discovery ability for the AI agent.
 *
 * Holds only the `sd-ai-agent/list-users` ability, which is safe enough to
 * ship in every build (including the WordPress.org distribution) because it
 * is read-only, capability-gated on `list_users`, and exposes no user
 * creation or login surface.
 *
 * The previous `sd-ai-agent/create-user` and `sd-ai-agent/update-user-role`
 * abilities live in {@see \SdAiAgent\Abilities\UserManagementAbilities},
 * which is feature-flag gated (`SD_AI_AGENT_FEATURE_USER_MANAGEMENT`) and
 * physically removed from the wp.org distribution build. That separation
 * is the explicit fix for the WordPress.org plugin review feedback that
 * custom user-creation routes can bypass security plugins (login
 * throttling, password-policy enforcers) which hook the native register
 * and login flow.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UserAbilities {

	/**
	 * Register the read-only user-listing ability.
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
				// Dual gate: per-tool cap (sd_ai_agent_tool_list_users) AND
				// core cap (list_users) per CORE_CAP_MAP. See
				// includes/Abilities/ToolCapabilities.php for the layered
				// resolution order.
				'permission_callback' => static function (): bool {
					return ToolCapabilities::current_user_can( 'sd-ai-agent/list-users' );
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
}
