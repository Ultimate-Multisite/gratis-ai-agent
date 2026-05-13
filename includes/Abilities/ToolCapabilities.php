<?php

declare(strict_types=1);
/**
 * Per-tool WordPress capability checks for Superdav AI Agent abilities.
 *
 * Provides granular capability checks so each ability can be granted
 * independently via user roles. Capability names follow the pattern
 * `sd_ai_agent_tool_{name}` where `{name}` is derived from the ability ID
 * (e.g. `sd-ai-agent/memory-save` → `sd_ai_agent_tool_memory_save`).
 *
 * Fallback: if the capability has not been granted to any role, the check
 * falls back to `manage_options` (admin only) so the default behaviour is
 * unchanged until an administrator explicitly delegates a capability.
 *
 * Filter: `sd_ai_agent_tool_capability` allows overriding the resolved
 * capability name per tool.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ToolCapabilities
 *
 * Static helper that resolves and checks per-tool WordPress capabilities.
 */
class ToolCapabilities {

	/**
	 * Fallback capability used when a tool-specific capability has not been
	 * granted to any role.
	 */
	public const FALLBACK_CAP = 'manage_options';

	/**
	 * Derive the tool-specific capability name from an ability ID.
	 *
	 * Examples:
	 *   sd-ai-agent/memory-save → sd_ai_agent_tool_memory_save
	 *   sd-ai-agent/db-query    → sd_ai_agent_tool_db_query
	 *   sd-ai-agent/run-php     → sd_ai_agent_tool_run_php
	 *
	 * @param string $ability_id The ability ID (e.g. "sd-ai-agent/memory-save" or "sd-ai-agent/create-post").
	 * @return string The derived capability name.
	 */
	public static function cap_name( string $ability_id ): string {
		// Strip the canonical Superdav AI Agent ability namespace prefix.
		$name = str_replace( 'sd-ai-agent/', '', $ability_id );

		// Replace hyphens and slashes with underscores.
		$name = str_replace( [ '-', '/' ], '_', $name );

		return 'sd_ai_agent_tool_' . $name;
	}

	/**
	 * Check whether the current user can execute a given ability.
	 *
	 * Resolution order:
	 * 1. Apply the `sd_ai_agent_tool_capability` filter to allow overrides.
	 * 2. If the resolved capability exists in any role, use it.
	 * 3. Otherwise fall back to `manage_options`.
	 *
	 * @param string $ability_id The ability ID (e.g. "sd-ai-agent/memory-save").
	 * @return bool True if the current user has permission.
	 */
	public static function current_user_can( string $ability_id ): bool {
		$tool_cap = self::cap_name( $ability_id );

		/**
		 * Filter the capability name used to gate a specific Superdav AI Agent tool.
		 *
		 * @param string $tool_cap   The derived capability name (e.g. "sd_ai_agent_tool_memory_save").
		 * @param string $ability_id The full ability ID (e.g. "sd-ai-agent/memory-save").
		 */
		$resolved_cap = (string) apply_filters( 'sd_ai_agent_tool_capability', $tool_cap, $ability_id );

		// If the capability has been granted to at least one role, use it.
		// Otherwise fall back to manage_options so the default is admin-only.
		if ( self::capability_exists( $resolved_cap ) ) {
			return current_user_can( $resolved_cap );
		}

		return current_user_can( self::FALLBACK_CAP );
	}

	/**
	 * Check whether a capability has been granted to at least one role.
	 *
	 * This is used to distinguish between "capability exists but user lacks it"
	 * and "capability has never been registered/granted", so we can fall back
	 * to manage_options in the latter case.
	 *
	 * @param string $cap Capability name.
	 * @return bool True if the capability is present in at least one role.
	 */
	public static function capability_exists( string $cap ): bool {
		global $wp_roles;

		if ( ! isset( $wp_roles ) ) {
			return false;
		}

		foreach ( $wp_roles->roles as $role ) {
			if ( ! empty( $role['capabilities'][ $cap ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Register all Superdav AI Agent tool capabilities on the Administrator role.
	 *
	 * Called on plugin activation and `admin_init` so that the capabilities
	 * are available for role-management plugins (e.g. Members, User Role Editor)
	 * to discover and assign.
	 *
	 * The capabilities are added to the Administrator role with `true` so that
	 * admins retain full access. Other roles start with no access and must be
	 * explicitly granted capabilities.
	 *
	 * @param string[] $ability_ids List of all ability IDs to register.
	 */
	public static function register_capabilities( array $ability_ids ): void {
		$admin_role = get_role( 'administrator' );

		if ( ! $admin_role instanceof \WP_Role ) {
			return;
		}

		foreach ( $ability_ids as $ability_id ) {
			$cap = self::cap_name( $ability_id );

			/**
			 * Allow overriding the capability name at registration time.
			 *
			 * @param string $cap        The derived capability name.
			 * @param string $ability_id The full ability ID.
			 */
			$cap = (string) apply_filters( 'sd_ai_agent_tool_capability', $cap, $ability_id );

			if ( ! isset( $admin_role->capabilities[ $cap ] ) ) {
				$admin_role->add_cap( $cap, true );
			}
		}
	}

	/**
	 * Return the list of all Superdav AI Agent ability IDs that have tool capabilities.
	 *
	 * This list is used both for capability registration and for documentation.
	 *
	 * @return string[]
	 */
	public static function all_ability_ids(): array {
		return [
			// Posts (registered under the WP core "sd-ai-agent/" prefix).
			'sd-ai-agent/list-posts',
			'sd-ai-agent/get-post',
			'sd-ai-agent/create-post',
			'sd-ai-agent/update-post',
			'sd-ai-agent/delete-post',
			// Global styles (registered under the WP core "sd-ai-agent/" prefix).
			'sd-ai-agent/get-global-styles',
			'sd-ai-agent/update-global-styles',
			'sd-ai-agent/get-theme-json',
			'sd-ai-agent/reset-global-styles',
			// Memory (registered under the WP core "sd-ai-agent/" prefix).
			'sd-ai-agent/memory-save',
			'sd-ai-agent/memory-list',
			'sd-ai-agent/memory-delete',
			// Skills (registered under the WP core "sd-ai-agent/" prefix).
			'sd-ai-agent/skill-load',
			'sd-ai-agent/skill-list',
			// Knowledge (registered under the WP core "sd-ai-agent/" prefix).
			'sd-ai-agent/knowledge-search',
			// Nav menus (registered under the WP core "sd-ai-agent/" prefix).
			'sd-ai-agent/list-menus',
			'sd-ai-agent/get-menu',
			'sd-ai-agent/create-menu',
			'sd-ai-agent/delete-menu',
			'sd-ai-agent/add-menu-item',
			'sd-ai-agent/remove-menu-item',
			'sd-ai-agent/assign-menu-location',
			// Images.
			'sd-ai-agent/stock-image',
			'sd-ai-agent/generate-image',
			// SEO (registered under the WP core "sd-ai-agent/" prefix).
			'sd-ai-agent/seo-audit-url',
			'sd-ai-agent/seo-analyze-content',
			// Content (registered under the WP core "sd-ai-agent/" prefix).
			'sd-ai-agent/content-analyze',
			'sd-ai-agent/content-performance-report',
			// Marketing (registered under the WP core "sd-ai-agent/" prefix).
			'sd-ai-agent/fetch-url',
			'sd-ai-agent/analyze-headers',
			// Blocks (registered under the WP core "sd-ai-agent/" prefix).
			'sd-ai-agent/markdown-to-blocks',
			'sd-ai-agent/list-block-types',
			'sd-ai-agent/get-block-type',
			'sd-ai-agent/list-block-patterns',
			'sd-ai-agent/list-block-templates',
			'sd-ai-agent/create-block-content',
			'sd-ai-agent/parse-block-content',
			// Files.
			'sd-ai-agent/file-read',
			'sd-ai-agent/file-write',
			'sd-ai-agent/file-edit',
			'sd-ai-agent/file-delete',
			'sd-ai-agent/file-list',
			'sd-ai-agent/file-search',
			'sd-ai-agent/content-search',
			// Database.
			'sd-ai-agent/db-query',
			// WordPress management.
			'sd-ai-agent/get-plugins',
			'sd-ai-agent/get-themes',
			'sd-ai-agent/install-plugin',
			'sd-ai-agent/run-php',
			// Options management.
			'sd-ai-agent/get-option',
			'sd-ai-agent/update-option',
			'sd-ai-agent/delete-option',
			'sd-ai-agent/list-options',
			// Navigation.
			'sd-ai-agent/navigate',
			'sd-ai-agent/get-page-html',
			// Git.
			'sd-ai-agent/git-list',
			'sd-ai-agent/git-diff',
			'sd-ai-agent/git-snapshot',
			'sd-ai-agent/git-restore',
			'sd-ai-agent/git-revert-package',
			'sd-ai-agent/git-package-summary',
			// Site health.
			'sd-ai-agent/site-health-summary',
			'sd-ai-agent/check-plugin-updates',
			'sd-ai-agent/check-security',
			'sd-ai-agent/check-performance',
			'sd-ai-agent/check-disk-space',
			'sd-ai-agent/scan-php-error-log',
			// Google Analytics.
			'sd-ai-agent/ga-traffic-summary',
			'sd-ai-agent/ga-top-pages',
			'sd-ai-agent/ga-realtime',
		];
	}
}
