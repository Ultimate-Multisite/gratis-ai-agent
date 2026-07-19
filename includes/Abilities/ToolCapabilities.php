<?php

declare(strict_types=1);
/**
 * Per-tool WordPress capability checks for Superdav AI Agent abilities.
 *
 * Provides a dual-gate capability check so each ability can be granted
 * independently via user roles AND is always anchored to a matching
 * WordPress core capability. Without the core-cap anchor, a misconfigured
 * role-management plugin (Members, User Role Editor, etc.) that grants the
 * per-tool capability to a low-privilege role could otherwise bypass the
 * underlying core gate (`delete_plugins`, `promote_users`, `upload_files`,
 * etc.).
 *
 * Resolution order in `current_user_can()`:
 *
 *   1. Per-tool capability layer.
 *      Capability names follow the pattern `sd_ai_agent_tool_{name}` where
 *      `{name}` is derived from the ability ID
 *      (`sd-ai-agent/memory-save` → `sd_ai_agent_tool_memory_save`).
 *      A user confirmation for the current agent turn also satisfies this
 *      plugin-specific layer so an approved prompt can continue into the
 *      ability's own permission callback.
 *      If the resolved cap is granted to any role, the current user must
 *      have it. If it is not granted to any role, the check falls back to
 *      `manage_options` so the default is admin-only when no turn approval
 *      exists.
 *
 *   2. Core-cap layer.
 *      The {@see CORE_CAP_MAP} entry for the ability ID lists one or more
 *      WordPress core capabilities the current user MUST also hold. The
 *      list is filterable via `sd_ai_agent_tool_required_core_cap`.
 *      Abilities listed in {@see CORE_CAP_OPTOUT} explicitly opt out (used
 *      for low-risk read-only public-data abilities such as the
 *      `report-inability` feedback channel).
 *
 * Final result is the per-tool layer or current-turn approval, AND the core
 * layer. The WordPress core-cap anchor is never bypassed by confirmation.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Core\ToolPermissionResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ToolCapabilities
 *
 * Static helper that resolves and checks per-tool WordPress capabilities,
 * anchored to matching WordPress core capabilities for defence-in-depth.
 */
class ToolCapabilities {

	/**
	 * Fallback capability used when a tool-specific capability has not been
	 * granted to any role.
	 */
	public const FALLBACK_CAP = 'manage_options';

	/**
	 * Map of ability ID → required WordPress core capability (or list of
	 * capabilities — all must be held).
	 *
	 * Every privileged ability MUST appear here so that even if the
	 * per-tool capability layer is misconfigured by a role-management
	 * plugin, the underlying core gate still applies. Abilities that are
	 * intentionally public-data / low-risk read-only MUST appear in
	 * {@see CORE_CAP_OPTOUT} instead — silence is not opt-out.
	 *
	 * The list is filterable per-ability via the
	 * `sd_ai_agent_tool_required_core_cap` filter.
	 *
	 * @var array<string, string|string[]>
	 */
	public const CORE_CAP_MAP = [
		// ─── Options (admin-only) ───────────────────────────────────────────
		'sd-ai-agent/get-option'                 => 'manage_options',
		'sd-ai-agent/update-option'              => 'manage_options',
		'sd-ai-agent/delete-option'              => 'manage_options',
		'sd-ai-agent/list-options'               => 'manage_options',
		'sd-ai-agent/contact-phone-lookup'       => 'manage_options',

		// ─── Users ──────────────────────────────────────────────────────────
		'sd-ai-agent/list-users'                 => 'list_users',
		// Both caps required: create_users gates user creation; promote_users
		// gates the role assignment that always accompanies a create-user call.
		// Mirrors the explicit pair enforced inside
		// UserManagementAbilities::can_create_user().
		'sd-ai-agent/create-user'                => [ 'create_users', 'promote_users' ],
		// Both caps required: promote_users is core's role-change gate;
		// edit_users gates modifying another user. Mirrors the explicit pair
		// enforced inside UserManagementAbilities::can_update_user_role().
		'sd-ai-agent/update-user-role'           => [ 'promote_users', 'edit_users' ],

		// ─── Plugins ────────────────────────────────────────────────────────
		'sd-ai-agent/get-plugins'                => 'activate_plugins',
		'sd-ai-agent/recommend-plugin'           => 'activate_plugins',
		'sd-ai-agent/search-plugin-directory'    => 'install_plugins',
		'sd-ai-agent/list-plugin-updates'        => 'update_plugins',
		'sd-ai-agent/install-plugin'             => 'install_plugins',
		'sd-ai-agent/install-plugin-from-url'    => 'install_plugins',
		'sd-ai-agent/update-plugin'              => 'update_plugins',
		'sd-ai-agent/activate-plugin'            => 'activate_plugins',
		'sd-ai-agent/deactivate-plugin'          => 'activate_plugins',
		'sd-ai-agent/switch-plugin'              => 'activate_plugins',
		'sd-ai-agent/delete-plugin'              => 'delete_plugins',
		'sd-ai-agent/list-modified-plugins'      => 'activate_plugins',
		'sd-ai-agent/get-plugin-download-url'    => 'activate_plugins',
		'sd-ai-agent/generate-plugin'            => 'install_plugins',
		'sd-ai-agent/sandbox-test-plugin'        => 'install_plugins',
		'sd-ai-agent/sandbox-activate-plugin'    => 'install_plugins',
		'sd-ai-agent/update-plugin-sandboxed'    => 'update_plugins',
		'sd-ai-agent/scan-plugin-hooks'          => 'install_plugins',
		'sd-ai-agent/scan-theme-hooks'           => 'install_plugins',

		// ─── Themes / Global styles / Design ────────────────────────────────
		'sd-ai-agent/get-themes'                 => 'edit_theme_options',
		'sd-ai-agent/activate-theme'             => 'switch_themes',
		'sd-ai-agent/get-global-styles'          => 'edit_theme_options',
		'sd-ai-agent/update-global-styles'       => 'edit_theme_options',
		'sd-ai-agent/get-theme-json'             => 'edit_theme_options',
		'sd-ai-agent/reset-global-styles'        => 'edit_theme_options',
		'sd-ai-agent/inject-custom-css'          => 'edit_theme_options',
		'sd-ai-agent/curated-block-patterns'     => 'edit_theme_options',
		'sd-ai-agent/theme-json-presets'         => 'edit_theme_options',
		'sd-ai-agent/set-site-logo'              => 'edit_theme_options',
		'sd-ai-agent/scaffold-block-theme'       => 'edit_themes',
		'sd-ai-agent/render-design-previews'     => 'edit_theme_options',
		'sd-ai-agent/validate-palette-contrast'  => 'edit_theme_options',
		'sd-ai-agent/compile-design-tokens'      => 'edit_theme_options',
		'sd-ai-agent/generate-logo-svg'          => 'upload_files',

		// ─── Menus ──────────────────────────────────────────────────────────
		'sd-ai-agent/list-menus'                 => 'edit_theme_options',
		'sd-ai-agent/get-menu'                   => 'edit_theme_options',
		'sd-ai-agent/create-menu'                => 'edit_theme_options',
		'sd-ai-agent/delete-menu'                => 'edit_theme_options',
		'sd-ai-agent/add-menu-item'              => 'edit_theme_options',
		'sd-ai-agent/remove-menu-item'           => 'edit_theme_options',
		'sd-ai-agent/assign-menu-location'       => 'edit_theme_options',
		'sd-ai-agent/generate-menu-page'         => 'edit_theme_options',

		// ─── Posts ──────────────────────────────────────────────────────────
		'sd-ai-agent/get-post'                   => 'edit_posts',
		'sd-ai-agent/list-posts'                 => 'edit_posts',
		'sd-ai-agent/create-post'                => 'edit_posts',
		'sd-ai-agent/update-post'                => 'edit_posts',
		'sd-ai-agent/append-post-content'        => 'edit_posts',
		'sd-ai-agent/batch-create-posts'         => 'edit_posts',
		'sd-ai-agent/delete-post'                => 'delete_posts',
		'sd-ai-agent/set-featured-image'         => 'edit_posts',
		'sd-ai-agent/inspect-rest-meta'          => 'edit_posts',
		'sd-ai-agent/revert-to-revision'         => 'edit_posts',
		'sd-ai-agent/generate-title'             => 'edit_posts',
		'sd-ai-agent/generate-excerpt'           => 'edit_posts',
		'sd-ai-agent/summarize-content'          => 'edit_posts',
		'sd-ai-agent/review-block'               => 'edit_posts',
		'sd-ai-agent/generate-alt-text'          => 'edit_posts',
		'sd-ai-agent/generate-image-prompt'      => 'edit_posts',
		'sd-ai-agent/create-contact-form'        => 'edit_posts',

		// ─── Blocks ─────────────────────────────────────────────────────────
		'sd-ai-agent/markdown-to-blocks'         => 'edit_posts',
		'sd-ai-agent/parse-block-content'        => 'edit_posts',
		'sd-ai-agent/validate-block-content'     => 'edit_posts',
		'sd-ai-agent/list-block-types'           => 'edit_posts',
		'sd-ai-agent/get-block-type'             => 'edit_posts',
		'sd-ai-agent/list-block-patterns'        => 'edit_posts',
		'sd-ai-agent/list-block-templates'       => 'edit_posts',
		'sd-ai-agent/create-block-content'       => 'edit_posts',
		'sd-ai-agent/get-page-blocks'            => 'edit_posts',
		'sd-ai-agent/get-site-block-usage'       => 'edit_posts',
		'sd-ai-agent/edit-block-tree'            => 'edit_posts',
		'sd-ai-agent/update-blocks'              => 'edit_posts',
		'sd-ai-agent/rewrite-post-blocks'        => 'edit_posts',
		'sd-ai-agent/insert-pattern'             => 'edit_posts',
		'sd-ai-agent/replace-block-range'        => 'edit_posts',
		'sd-ai-agent/scan-storage-modes'         => 'edit_posts',

		// ─── Media / Image generation ───────────────────────────────────────
		'sd-ai-agent/list-media'                 => 'upload_files',
		'sd-ai-agent/upload-media'               => 'upload_files',
		'sd-ai-agent/upload-media-from-url'      => 'upload_files',
		'sd-ai-agent/delete-media'               => 'upload_files',
		'sd-ai-agent/import-base64-image'        => 'upload_files',
		'sd-ai-agent/generate-image'             => 'upload_files',
		'sd-ai-agent/stock-image'                => 'upload_files',

		// ─── Taxonomies / Custom types ──────────────────────────────────────
		'sd-ai-agent/list-terms'                 => 'manage_categories',
		'sd-ai-agent/list-taxonomies'            => 'manage_options',
		'sd-ai-agent/register-taxonomy'          => 'manage_options',
		'sd-ai-agent/delete-taxonomy'            => 'manage_options',
		'sd-ai-agent/list-post-types'            => 'manage_options',
		'sd-ai-agent/register-post-type'         => 'manage_options',
		'sd-ai-agent/delete-post-type'           => 'manage_options',

		// ─── Files (read = admin; write gated separately by FILE_WRITE) ─────
		'sd-ai-agent/file-read'                  => 'manage_options',
		'sd-ai-agent/file-outline'               => 'manage_options',
		'sd-ai-agent/file-list'                  => 'manage_options',
		'sd-ai-agent/file-search'                => 'manage_options',
		'sd-ai-agent/content-search'             => 'manage_options',
		'sd-ai-agent/file-write'                 => [ 'manage_options', 'edit_files' ],
		'sd-ai-agent/file-edit'                  => [ 'manage_options', 'edit_files' ],
		'sd-ai-agent/file-delete'                => [ 'manage_options', 'edit_files' ],

		// ─── Git (snapshot/diff = admin; restore = admin + edit_files) ──────
		'sd-ai-agent/git-list'                   => 'manage_options',
		'sd-ai-agent/git-diff'                   => 'manage_options',
		'sd-ai-agent/git-package-summary'        => 'manage_options',
		'sd-ai-agent/git-snapshot'               => 'manage_options',
		'sd-ai-agent/git-restore'                => [ 'manage_options', 'edit_files' ],
		'sd-ai-agent/git-revert-package'         => [ 'manage_options', 'edit_files' ],

		// ─── Site health ────────────────────────────────────────────────────
		'sd-ai-agent/check-disk-space'           => 'manage_options',
		'sd-ai-agent/check-performance'          => 'manage_options',
		'sd-ai-agent/check-security'             => 'manage_options',
		'sd-ai-agent/check-plugin-updates'       => 'update_plugins',
		'sd-ai-agent/scan-php-error-log'         => 'manage_options',
		'sd-ai-agent/site-health-summary'        => 'manage_options',
		'sd-ai-agent/detect-fresh-install'       => 'manage_options',
		'sd-ai-agent/site-loopback-check'        => 'manage_options',

		// ─── SEO / Content marketing ────────────────────────────────────────
		'sd-ai-agent/seo-audit-url'              => 'edit_posts',
		'sd-ai-agent/seo-analyze-content'        => 'edit_posts',
		'sd-ai-agent/content-analyze'            => 'edit_posts',
		'sd-ai-agent/content-performance-report' => 'edit_posts',
		'sd-ai-agent/fetch-url'                  => 'manage_options',
		'sd-ai-agent/analyze-headers'            => 'manage_options',

		// ─── Knowledge / Memory / Skills ────────────────────────────────────
		'sd-ai-agent/knowledge-search'           => 'manage_options',
		'sd-ai-agent/public-chat-setup'          => 'manage_options',
		'sd-ai-agent/memory-save'                => 'manage_options',
		'sd-ai-agent/memory-list'                => 'manage_options',
		'sd-ai-agent/memory-delete'              => 'manage_options',
		'sd-ai-agent/skill-load'                 => 'manage_options',
		'sd-ai-agent/skill-list'                 => 'manage_options',

		// ─── Analytics / Search Console ─────────────────────────────────────
		'sd-ai-agent/ga-traffic-summary'         => 'manage_options',
		'sd-ai-agent/ga-top-pages'               => 'manage_options',
		'sd-ai-agent/ga-realtime'                => 'manage_options',
		'sd-ai-agent/gsc-site-summary'           => 'manage_options',
		'sd-ai-agent/gsc-top-queries'            => 'manage_options',
		'sd-ai-agent/gsc-query-details'          => 'manage_options',
		'sd-ai-agent/gsc-page-performance'       => 'manage_options',
		'sd-ai-agent/sms-send'                   => 'manage_options',

		// ─── Internet / URL utilities ───────────────────────────────────────
		'sd-ai-agent/internet-search'            => 'manage_options',
		'sd-ai-agent/resolve-url'                => 'manage_options',
		'sd-ai-agent/configure-search-provider'  => 'manage_options',

		// ─── Navigation / Browsing ──────────────────────────────────────────
		'sd-ai-agent/navigate'                   => 'manage_options',
		'sd-ai-agent/get-page-html'              => 'manage_options',

		// ─── Database ───────────────────────────────────────────────────────
		'sd-ai-agent/db-query'                   => [ 'manage_options', 'unfiltered_html' ],

		// ─── Strictest: low-level whitelisted-PHP dispatcher ────────────────
		// run-php is supplied by the advanced companion plugin and remains
		// feature-flag-gated (SD_AI_AGENT_FEATURE_RUN_PHP). Even when the
		// advanced plugin and feature flag are on, this dual-cap requirement gives a final
		// belt-and-braces gate so a misconfigured role-management plugin
		// cannot expose the dispatcher to a non-admin role.
		'sd-ai-agent/run-php'                    => [ 'manage_options', 'update_core', 'unfiltered_html' ],
	];

	/**
	 * Google Calendar abilities are kept in a small companion map so the long
	 * ability IDs do not force noisy alignment churn across CORE_CAP_MAP.
	 *
	 * @var array<string, string>
	 */
	private const GOOGLE_CALENDAR_CORE_CAP_MAP = [
		'sd-ai-agent/google-calendar-list-events'    => 'manage_options',
		'sd-ai-agent/google-calendar-get-event'      => 'manage_options',
		'sd-ai-agent/google-calendar-list-calendars' => 'manage_options',
		'sd-ai-agent/calendar-send-sms-reminders'    => 'manage_options',
	];

	/**
	 * Generated-artifact abilities have a dedicated map so their long IDs do
	 * not create alignment churn in the general capability map.
	 *
	 * @var array<string, string>
	 */
	private const DESIGN_ARTIFACT_CORE_CAP_MAP = [
		'sd-ai-agent/list-design-artifacts'            => 'edit_theme_options',
		'sd-ai-agent/inspect-design-artifact'          => 'edit_theme_options',
		'sd-ai-agent/resolve-design-artifacts'         => 'edit_theme_options',
		'sd-ai-agent/apply-design-artifact-release'    => 'edit_themes',
		'sd-ai-agent/rollback-design-artifact-release' => 'edit_themes',
	];

	/**
	 * Ability IDs that intentionally opt out of the core-cap layer.
	 *
	 * These abilities are gated only by the per-tool layer; the dual-gate
	 * core-cap check is skipped. Reserved for genuinely public-data /
	 * low-risk read-only abilities (e.g. anonymous feedback ingestion).
	 *
	 * Adding to this list requires an explicit security review comment in
	 * the PR — silence is not opt-out, missing entries fall through to
	 * {@see resolve_core_caps()} which returns `manage_options` by default.
	 *
	 * @var string[]
	 */
	public const CORE_CAP_OPTOUT = [
		// Feedback channel — any logged-in user may report an AI failure
		// so the data the agent collects covers all roles. Per-tool layer
		// still applies (so admins can revoke via role plugins).
		'sd-ai-agent/report-inability',
	];

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
	 * Resolve the required WordPress core capabilities for an ability.
	 *
	 * Returns an empty array when the ability is in {@see CORE_CAP_OPTOUT}.
	 * Returns the {@see CORE_CAP_MAP} entry (normalised to an array of
	 * strings) when one exists. Falls back to `[FALLBACK_CAP]` so an
	 * unmapped privileged ability fails closed (admin-only) rather than
	 * silently downgrading to the per-tool layer alone.
	 *
	 * The result is passed through the `sd_ai_agent_tool_required_core_cap`
	 * filter so admins / extension code can override per-ability.
	 *
	 * @param string $ability_id The ability ID.
	 * @return string[] Zero or more core capability names; all must be held.
	 */
	public static function resolve_core_caps( string $ability_id ): array {
		if ( in_array( $ability_id, self::CORE_CAP_OPTOUT, true ) ) {
			$caps = [];
		} elseif ( isset( self::DESIGN_ARTIFACT_CORE_CAP_MAP[ $ability_id ] ) ) {
			$raw  = self::DESIGN_ARTIFACT_CORE_CAP_MAP[ $ability_id ];
			$caps = [ $raw ];
		} elseif ( isset( self::GOOGLE_CALENDAR_CORE_CAP_MAP[ $ability_id ] ) ) {
			$raw  = self::GOOGLE_CALENDAR_CORE_CAP_MAP[ $ability_id ];
			$caps = [ $raw ];
		} elseif ( isset( self::CORE_CAP_MAP[ $ability_id ] ) ) {
			$raw  = self::CORE_CAP_MAP[ $ability_id ];
			$caps = is_array( $raw ) ? $raw : [ $raw ];
		} else {
			// Unmapped privileged ability: fail closed at admin-only.
			$caps = [ self::FALLBACK_CAP ];
		}

		/**
		 * Filter the WordPress core capabilities required to execute a
		 * Superdav AI Agent ability.
		 *
		 * Return an empty array to opt out of the core-cap layer entirely
		 * (per-tool layer still applies). Return a non-empty array to
		 * require all listed capabilities.
		 *
		 * @since 1.7.0
		 *
		 * @param string[] $caps       Default core capabilities for the ability.
		 * @param string   $ability_id The full ability ID (e.g. "sd-ai-agent/delete-plugin").
		 */
		$caps = apply_filters( 'sd_ai_agent_tool_required_core_cap', $caps, $ability_id );

		return array_values( array_filter( (array) $caps, 'is_string' ) );
	}

	/**
	 * Check whether the current user can execute a given ability.
	 *
	 * Dual-gate resolution:
	 *
	 *   1. Apply the `sd_ai_agent_tool_capability` filter to resolve the
	 *      per-tool capability name. If the cap exists in any role, the
	 *      current user must hold it. Otherwise fall back to
	 *      `manage_options` so the default is admin-only.
	 *      A current-turn user approval also satisfies this plugin prompt layer.
	 *
	 *   2. Resolve required core capabilities via
	 *      {@see resolve_core_caps()}. The current user must hold ALL of
	 *      them (or the ability must be in {@see CORE_CAP_OPTOUT}).
	 *
	 * The plugin prompt layer must pass via capability or current-turn approval;
	 * the core-cap layer must always pass.
	 *
	 * @param string $ability_id The ability ID (e.g. "sd-ai-agent/memory-save").
	 * @return bool True if the current user has permission.
	 */
	public static function current_user_can( string $ability_id ): bool {
		// ── Layer 1: per-tool capability ────────────────────────────────
		$tool_cap = self::cap_name( $ability_id );

		/**
		 * Filter the capability name used to gate a specific Superdav AI Agent tool.
		 *
		 * @param string $tool_cap   The derived capability name (e.g. "sd_ai_agent_tool_memory_save").
		 * @param string $ability_id The full ability ID (e.g. "sd-ai-agent/memory-save").
		 */
		$resolved_cap = (string) apply_filters( 'sd_ai_agent_tool_capability', $tool_cap, $ability_id );

		if ( ! self::tool_layer_passes( $ability_id, $resolved_cap ) ) {
			return false;
		}

		// ── Layer 2: required WordPress core capabilities ───────────────
		foreach ( self::resolve_core_caps( $ability_id ) as $core_cap ) {
			if ( ! current_user_can( $core_cap ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Return a precise denial error for an ability the current user cannot run.
	 *
	 * This mirrors {@see current_user_can()} but preserves which layer denied the
	 * request so meta-tools such as `sd-ai-agent/ability-call` can distinguish a
	 * user-approved tool confirmation from a missing WordPress capability such as
	 * `edit_files`.
	 *
	 * @param string $ability_id The ability ID.
	 * @return \WP_Error|null Error when denied; null when the current user passes both gates.
	 */
	public static function permission_denial_error( string $ability_id ): ?\WP_Error {
		$tool_cap = self::cap_name( $ability_id );

		/** This filter is documented in {@see current_user_can()}. */
		$resolved_cap = (string) apply_filters( 'sd_ai_agent_tool_capability', $tool_cap, $ability_id );

		$approved_for_turn = self::is_one_turn_approved( $ability_id );

		if ( ! self::tool_layer_passes( $ability_id, $resolved_cap ) ) {
			$resolved_cap_exists = self::capability_exists( $resolved_cap );
			$capability          = $resolved_cap_exists ? $resolved_cap : self::FALLBACK_CAP;
			$layer               = $resolved_cap_exists ? 'per-tool' : 'fallback';

			return self::capability_denial_error( $ability_id, $capability, $layer, false );
		}

		foreach ( self::resolve_core_caps( $ability_id ) as $core_cap ) {
			if ( ! current_user_can( $core_cap ) ) {
				return self::capability_denial_error( $ability_id, $core_cap, 'core', $approved_for_turn );
			}
		}

		return null;
	}

	/**
	 * Whether the plugin-specific permission layer passes for an ability.
	 *
	 * Current-turn approval satisfies this layer. Otherwise, the current user
	 * must hold the resolved per-tool capability when it exists, or the
	 * admin-only fallback capability when it does not.
	 *
	 * @param string $ability_id   The ability ID.
	 * @param string $resolved_cap The filtered per-tool capability name.
	 */
	private static function tool_layer_passes( string $ability_id, string $resolved_cap ): bool {
		return self::is_one_turn_approved( $ability_id )
			|| ( self::capability_exists( $resolved_cap )
				? current_user_can( $resolved_cap )
				: current_user_can( self::FALLBACK_CAP ) );
	}

	/**
	 * Whether the current confirmed agent turn approved this ability.
	 *
	 * Confirmation is intentionally narrower than WordPress authorization: it
	 * satisfies only the plugin's per-tool approval layer. The required core
	 * capabilities from {@see resolve_core_caps()} remain mandatory so a user
	 * cannot click through into operations their WordPress role cannot perform.
	 *
	 * @param string $ability_id The ability ID.
	 */
	private static function is_one_turn_approved( string $ability_id ): bool {
		return class_exists( ToolPermissionResolver::class )
			&& ToolPermissionResolver::is_one_turn_approved( $ability_id );
	}

	/**
	 * Build a user-actionable capability denial error.
	 *
	 * @param string $ability_id The denied ability ID.
	 * @param string $capability Missing capability.
	 * @param string $layer Permission layer that denied the request.
	 * @param bool   $approved_for_turn Whether current-turn approval satisfied the tool layer.
	 */
	private static function capability_denial_error( string $ability_id, string $capability, string $layer, bool $approved_for_turn ): \WP_Error {
		if ( $approved_for_turn ) {
			$message = sprintf(
				/* translators: 1: ability id, 2: WordPress capability, 3: permission layer */
				__( 'Ability "%1$s" was approved for this turn, but the current WordPress user still lacks the "%2$s" capability required by the %3$s permission layer. Grant that capability or switch to a user role that has it, then approve the tool call again.', 'superdav-ai-agent' ),
				$ability_id,
				$capability,
				$layer
			);
		} else {
			$message = sprintf(
				/* translators: 1: ability id, 2: WordPress capability, 3: permission layer */
				__( 'Ability "%1$s" cannot run because the current WordPress user lacks the "%2$s" capability required by the %3$s permission layer. Grant that capability or switch to a user role that has it, then retry the tool call.', 'superdav-ai-agent' ),
				$ability_id,
				$capability,
				$layer
			);
		}

		return new \WP_Error(
			'ability_invalid_permissions',
			$message,
			[
				'status'             => 403,
				'ability'            => $ability_id,
				'missing_capability' => $capability,
				'permission_layer'   => $layer,
				'approved_for_turn'  => $approved_for_turn,
			]
		);
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
	 * It is intentionally the union of {@see CORE_CAP_MAP} keys and
	 * {@see CORE_CAP_OPTOUT} entries plus any historically-registered
	 * abilities — kept in sync via `tests/SdAiAgent/Abilities/ToolCapabilitiesDualGateTest.php`,
	 * which fails when a new ability is registered without an entry.
	 *
	 * @return string[]
	 */
	public static function all_ability_ids(): array {
		$ids = array_merge(
			array_keys( self::CORE_CAP_MAP ),
			array_keys( self::DESIGN_ARTIFACT_CORE_CAP_MAP ),
			array_keys( self::GOOGLE_CALENDAR_CORE_CAP_MAP ),
			self::CORE_CAP_OPTOUT
		);
		sort( $ids );
		return array_values( array_unique( $ids ) );
	}
}
