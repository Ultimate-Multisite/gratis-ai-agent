<?php

declare(strict_types=1);
/**
 * Role-based AI permissions management.
 *
 * Stores per-role access configuration in a dedicated WordPress option and
 * provides server-side enforcement helpers used by the REST controller and
 * AgentLoop. Admins always retain full access regardless of configuration.
 *
 * Option schema (sd_ai_agent_role_permissions):
 * {
 *   "editor": {
 *     "chat_access": true,
 *     "allowed_abilities": ["sd-ai-agent/content-analyze", ...]
 *                          // empty array = all abilities allowed for this role
 *   },
 *   "author": {
 *     "chat_access": false,
 *     "allowed_abilities": []
 *   }
 * }
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RolePermissions {

	/**
	 * Option name in the wp_options table.
	 */
	const OPTION_NAME = 'sd_ai_agent_role_permissions';

	/**
	 * WordPress roles that always have full access (cannot be restricted).
	 */
	const ALWAYS_ALLOWED_ROLES = [ 'administrator' ];

	/**
	 * Get the default role permissions configuration.
	 *
	 * By default:
	 *  - administrator: full access (enforced in code, not stored)
	 *  - editor: chat access, all abilities
	 *  - author: chat access, all abilities
	 *  - contributor: no chat access
	 *  - subscriber: no chat access
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_defaults(): array {
		return [
			'editor'      => [
				'chat_access'       => true,
				'allowed_abilities' => [],
			],
			'author'      => [
				'chat_access'       => true,
				'allowed_abilities' => [],
			],
			'contributor' => [
				'chat_access'       => false,
				'allowed_abilities' => [],
			],
			'subscriber'  => [
				'chat_access'       => false,
				'allowed_abilities' => [],
			],
		];
	}

	/**
	 * Get all role permissions, merged with defaults.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get(): array {
		$saved    = get_option( self::OPTION_NAME, [] );
		$defaults = self::get_defaults();

		if ( ! is_array( $saved ) ) {
			return $defaults;
		}

		// Merge saved values over defaults, preserving any extra roles.
		$merged = $defaults;
		foreach ( $saved as $role => $config ) {
			if ( ! is_string( $role ) || ! is_array( $config ) ) {
				continue;
			}
			$merged[ $role ] = [
				'chat_access'       => (bool) ( $config['chat_access'] ?? false ),
				'allowed_abilities' => array_values(
					array_filter(
						(array) ( $config['allowed_abilities'] ?? [] ),
						'is_string'
					)
				),
			];
		}

		return $merged;
	}

	/**
	 * Persist role permissions.
	 *
	 * @param array<string, array<string, mixed>> $data Role slug => config map.
	 * @return bool True on success.
	 */
	public static function update( array $data ): bool {
		$sanitized = [];

		foreach ( $data as $role => $config ) {
			if ( ! is_string( $role ) || ! is_array( $config ) ) {
				continue;
			}

			// Skip the always-allowed roles — they cannot be restricted.
			if ( in_array( $role, self::ALWAYS_ALLOWED_ROLES, true ) ) {
				continue;
			}

			$sanitized[ sanitize_key( $role ) ] = [
				'chat_access'       => (bool) ( $config['chat_access'] ?? false ),
				'allowed_abilities' => array_values(
					array_filter(
						// @phpstan-ignore-next-line
						array_map( 'sanitize_text_field', (array) ( $config['allowed_abilities'] ?? [] ) ),
						'is_string'
					)
				),
			];
		}

		return update_option( self::OPTION_NAME, $sanitized );
	}

	/**
	 * Check whether the current user has chat access.
	 *
	 * Administrators always have access. For other roles, the first matching
	 * role config with chat_access=true grants access.
	 *
	 * @return bool
	 */
	public static function current_user_has_chat_access(): bool {
		// Must be logged in.
		if ( ! is_user_logged_in() ) {
			return false;
		}

		// Administrators always have full access.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$user        = wp_get_current_user();
		$permissions = self::get();

		foreach ( (array) $user->roles as $role ) {
			if ( isset( $permissions[ $role ] ) && true === $permissions[ $role ]['chat_access'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the set of ability names allowed for the current user.
	 *
	 * Returns null when there is no restriction (all abilities allowed).
	 * Returns an array of ability name strings when restrictions apply.
	 *
	 * Administrators always receive null (unrestricted).
	 *
	 * @return string[]|null Null = unrestricted; array = allowed ability names.
	 */
	public static function get_allowed_abilities_for_current_user(): ?array {
		// Administrators are unrestricted.
		if ( current_user_can( 'manage_options' ) ) {
			return null;
		}

		$user        = wp_get_current_user();
		$permissions = self::get();

		// Collect the union of allowed abilities across all user roles.
		// An empty allowed_abilities array for a role means "all abilities".
		$has_restriction = false;
		$allowed         = [];

		foreach ( (array) $user->roles as $role ) {
			if ( ! isset( $permissions[ $role ] ) ) {
				continue;
			}

			$role_config = $permissions[ $role ];

			// If any role grants unrestricted abilities, the user is unrestricted.
			if ( empty( $role_config['allowed_abilities'] ) ) {
				return null;
			}

			$has_restriction = true;
			// @phpstan-ignore-next-line
			$allowed = array_merge( $allowed, $role_config['allowed_abilities'] );
		}

		if ( ! $has_restriction ) {
			// No matching role config found — deny all abilities by default.
			return [];
		}

		// @phpstan-ignore-next-line
		return array_values( array_unique( $allowed ) );
	}

	/**
	 * Check whether the current user can invoke a specific ability.
	 *
	 * @param string $ability_name The ability name (e.g. 'sd-ai-agent/memory-save').
	 * @return bool
	 */
	public static function current_user_can_use_ability( string $ability_name ): bool {
		$allowed = self::get_allowed_abilities_for_current_user();

		// null = unrestricted.
		if ( null === $allowed ) {
			return true;
		}

		return in_array( $ability_name, $allowed, true );
	}

	/**
	 * Get all registered WordPress roles with their display names.
	 *
	 * @return array<string, string> Role slug => display name.
	 */
	public static function get_all_roles(): array {
		$wp_roles = wp_roles();
		$roles    = [];

		foreach ( $wp_roles->roles as $slug => $role_data ) {
			$roles[ (string) $slug ] = translate_user_role( (string) ( $role_data['name'] ?? '' ) );
		}

		return $roles;
	}
}
