<?php

declare(strict_types=1);
/**
 * WordPress management abilities for the AI agent.
 *
 * Provides plugin/theme listing, plugin installation, and whitelisted WP function calls.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Core\AbilityPluginRegistry;
use SdAiAgent\Core\Features;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WordPressAbilities {

	// ─── Static proxy methods (for backwards-compatible test access) ─────────

	/**
	 * List all installed plugins.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_get_plugins( array $input = [] ) {
		$ability = new GetPluginsAbility(
			'sd-ai-agent/get-plugins',
			[
				'label'       => __( 'List Plugins', 'superdav-ai-agent' ),
				'description' => __( 'List all installed WordPress plugins with their status (active/inactive).', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * List all installed themes.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_get_themes( array $input = [] ) {
		$ability = new GetThemesAbility(
			'sd-ai-agent/get-themes',
			[
				'label'       => __( 'List Themes', 'superdav-ai-agent' ),
				'description' => __( 'List all installed WordPress themes with their status.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Install a plugin from WordPress.org.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_install_plugin( array $input = [] ) {
		$ability = new InstallPluginAbility(
			'sd-ai-agent/install-plugin',
			[
				'label'       => __( 'Install Plugin', 'superdav-ai-agent' ),
				'description' => __( 'Install a plugin from the WordPress.org plugin directory by slug. Optionally activate after installation.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Update an installed plugin to the latest version.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_update_plugin( array $input = [] ) {
		$ability = new UpdatePluginAbility(
			'sd-ai-agent/update-plugin',
			[
				'label'       => __( 'Update Plugin', 'superdav-ai-agent' ),
				'description' => __( 'Update an installed plugin to the latest version available from its source.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Install a plugin from any URL (GitHub releases, direct ZIPs, etc.).
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_install_plugin_from_url( array $input = [] ) {
		$ability = new InstallPluginFromUrlAbility(
			'sd-ai-agent/install-plugin-from-url',
			[
				'label'       => __( 'Install Plugin from URL', 'superdav-ai-agent' ),
				'description' => __( 'Install a plugin from any direct ZIP URL, including GitHub release assets. Optionally activate after installation.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Activate an installed plugin.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_activate_plugin( array $input = [] ) {
		$ability = new ActivatePluginAbility(
			'sd-ai-agent/activate-plugin',
			[
				'label'       => __( 'Activate Plugin', 'superdav-ai-agent' ),
				'description' => __( 'Activate an installed WordPress plugin by slug or plugin file.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Deactivate an active plugin.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_deactivate_plugin( array $input = [] ) {
		$ability = new DeactivatePluginAbility(
			'sd-ai-agent/deactivate-plugin',
			[
				'label'       => __( 'Deactivate Plugin', 'superdav-ai-agent' ),
				'description' => __( 'Deactivate an active WordPress plugin by slug or plugin file.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Delete an inactive plugin.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_delete_plugin( array $input = [] ) {
		$ability = new DeletePluginAbility(
			'sd-ai-agent/delete-plugin',
			[
				'label'       => __( 'Delete Plugin', 'superdav-ai-agent' ),
				'description' => __( 'Permanently delete an inactive WordPress plugin. The plugin must be deactivated first.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * List available plugin updates.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_list_plugin_updates( array $input = [] ) {
		$ability = new ListPluginUpdatesAbility(
			'sd-ai-agent/list-plugin-updates',
			[
				'label'       => __( 'List Plugin Updates', 'superdav-ai-agent' ),
				'description' => __( 'List all installed plugins that have updates available.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Search the WordPress.org plugin directory.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_search_plugin_directory( array $input = [] ) {
		$ability = new SearchPluginDirectoryAbility(
			'sd-ai-agent/search-plugin-directory',
			[
				'label'       => __( 'Search Plugin Directory', 'superdav-ai-agent' ),
				'description' => __( 'Search the official WordPress.org plugin directory by keyword.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Switch plugins: activate one, deactivate others, with rollback on failure.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_switch_plugin( array $input = [] ) {
		$ability = new SwitchPluginAbility(
			'sd-ai-agent/switch-plugin',
			[
				'label'       => __( 'Switch Plugin', 'superdav-ai-agent' ),
				'description' => __( 'Activate one plugin and optionally deactivate one or more others. Rolls back if activation fails.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Recommend plugins for a given need category.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_recommend_plugin( array $input = [] ) {
		$ability = new RecommendPluginAbility(
			'sd-ai-agent/recommend-plugin',
			[
				'label'       => __( 'Recommend Plugin', 'superdav-ai-agent' ),
				'description' => __( 'Given a need category, return ranked plugin recommendations from the curated abilities registry. Preference order: has abilities > has blocks > popular.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Call a whitelisted WordPress function by name with arguments.
	 *
	 * @param array<string,mixed> $input Input args (function, args).
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_run_php( array $input = [] ) {
		$ability = new RunPhpAbility(
			'sd-ai-agent/run-php',
			[
				'label'       => __( 'Call WordPress Function', 'superdav-ai-agent' ),
				'description' => __( 'Low-level fallback: call a whitelisted WordPress function directly. Use ONLY when no dedicated ability exists. For posts, users, options, plugins, themes, and other common operations, call `sd-ai-agent/ability-search` first — dedicated abilities have typed schemas and better error recovery.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Register all WordPress management abilities.
	 *
	 * Plugin-management abilities are split across two feature flags so the
	 * WordPress.org distribution can comply with the "Changing Active
	 * Plugins" guideline (no autonomous activate/deactivate/delete/switch/
	 * update) and the WP.org-directory-only install rule (no installs from
	 * arbitrary ZIP URLs) without removing all plugin tooling:
	 *
	 *  - Always registered (read-only or WP.org-directory only):
	 *    get-plugins, get-themes, recommend-plugin, search-plugin-directory,
	 *    list-plugin-updates, install-plugin (WP.org slug),
	 *    run-php (whitelisted function calls only).
	 *  - Gated by SD_AI_AGENT_FEATURE_PLUGIN_STATE_CHANGES:
	 *    activate-plugin, deactivate-plugin, delete-plugin, switch-plugin,
	 *    update-plugin.
	 *  - Gated by SD_AI_AGENT_FEATURE_PLUGIN_INSTALL_FROM_URL:
	 *    install-plugin-from-url.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		// ─── Always-registered: read-only / discovery / WP.org-only install ────

		wp_register_ability(
			'sd-ai-agent/get-plugins',
			[
				'label'         => __( 'List Plugins', 'superdav-ai-agent' ),
				'description'   => __( 'List all installed WordPress plugins with their status (active/inactive).', 'superdav-ai-agent' ),
				'ability_class' => GetPluginsAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/get-themes',
			[
				'label'         => __( 'List Themes', 'superdav-ai-agent' ),
				'description'   => __( 'List all installed WordPress themes with their status.', 'superdav-ai-agent' ),
				'ability_class' => GetThemesAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/install-plugin',
			[
				'label'         => __( 'Install Plugin', 'superdav-ai-agent' ),
				'description'   => __( 'Install a plugin from the WordPress.org plugin directory by slug. Optionally activate after installation.', 'superdav-ai-agent' ),
				'ability_class' => InstallPluginAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/recommend-plugin',
			[
				'label'         => __( 'Recommend Plugin', 'superdav-ai-agent' ),
				'description'   => __( 'Given a need category, return ranked plugin recommendations from the curated abilities registry. Preference order: has abilities > has blocks > popular.', 'superdav-ai-agent' ),
				'ability_class' => RecommendPluginAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/list-plugin-updates',
			[
				'label'         => __( 'List Plugin Updates', 'superdav-ai-agent' ),
				'description'   => __( 'List all installed plugins that have updates available.', 'superdav-ai-agent' ),
				'ability_class' => ListPluginUpdatesAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/search-plugin-directory',
			[
				'label'         => __( 'Search Plugin Directory', 'superdav-ai-agent' ),
				'description'   => __( 'Search the official WordPress.org plugin directory by keyword.', 'superdav-ai-agent' ),
				'ability_class' => SearchPluginDirectoryAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/run-php',
			[
				'label'         => __( 'Call WordPress Function', 'superdav-ai-agent' ),
				'description'   => __( 'Low-level fallback: call a whitelisted WordPress function directly. Use ONLY when no dedicated ability exists for the task. For posts (use `sd-ai-agent/create-post`), users, options, plugins, themes, and other common operations, call `sd-ai-agent/ability-search` first to find a purpose-built tool — dedicated abilities have typed schemas and better error recovery than passing positional args through `run-php`.', 'superdav-ai-agent' ),
				'ability_class' => RunPhpAbility::class,
			]
		);

		// ─── Gated: changes the active plugin set ──────────────────────────────

		if ( Features::is_enabled( Features::PLUGIN_STATE_CHANGES ) ) {
			wp_register_ability(
				'sd-ai-agent/update-plugin',
				[
					'label'         => __( 'Update Plugin', 'superdav-ai-agent' ),
					'description'   => __( 'Update an installed plugin to the latest version available from its source.', 'superdav-ai-agent' ),
					'ability_class' => UpdatePluginAbility::class,
				]
			);

			wp_register_ability(
				'sd-ai-agent/activate-plugin',
				[
					'label'         => __( 'Activate Plugin', 'superdav-ai-agent' ),
					'description'   => __( 'Activate an installed WordPress plugin by slug or plugin file.', 'superdav-ai-agent' ),
					'ability_class' => ActivatePluginAbility::class,
				]
			);

			wp_register_ability(
				'sd-ai-agent/deactivate-plugin',
				[
					'label'         => __( 'Deactivate Plugin', 'superdav-ai-agent' ),
					'description'   => __( 'Deactivate an active WordPress plugin by slug or plugin file.', 'superdav-ai-agent' ),
					'ability_class' => DeactivatePluginAbility::class,
				]
			);

			wp_register_ability(
				'sd-ai-agent/delete-plugin',
				[
					'label'         => __( 'Delete Plugin', 'superdav-ai-agent' ),
					'description'   => __( 'Permanently delete an inactive WordPress plugin. The plugin must be deactivated first.', 'superdav-ai-agent' ),
					'ability_class' => DeletePluginAbility::class,
				]
			);

			wp_register_ability(
				'sd-ai-agent/switch-plugin',
				[
					'label'         => __( 'Switch Plugin', 'superdav-ai-agent' ),
					'description'   => __( 'Activate one plugin and optionally deactivate one or more others. Rolls back if activation fails.', 'superdav-ai-agent' ),
					'ability_class' => SwitchPluginAbility::class,
				]
			);
		}

		// ─── Gated: install from arbitrary ZIP URLs / GitHub ──────────────────

		if ( Features::is_enabled( Features::PLUGIN_INSTALL_FROM_URL ) ) {
			wp_register_ability(
				'sd-ai-agent/install-plugin-from-url',
				[
					'label'         => __( 'Install Plugin from URL', 'superdav-ai-agent' ),
					'description'   => __( 'Install a plugin from any direct ZIP URL, including GitHub release assets. Optionally activate after installation.', 'superdav-ai-agent' ),
					'ability_class' => InstallPluginFromUrlAbility::class,
				]
			);
		}
	}
}

/**
 * Get Plugins ability.
 *
 * @since 1.0.0
 */
class GetPluginsAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'List Plugins', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'List all installed WordPress plugins with their status (active/inactive).', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => (object) [],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'plugins'      => [ 'type' => 'array' ],
				'total'        => [ 'type' => 'integer' ],
				'active_count' => [ 'type' => 'integer' ],
			],
		];
	}

	protected function execute_callback( $input = null ) {
		/** @var array<string, mixed> $input */
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', [] );

		$plugins = [];
		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$plugins[] = [
				'file'        => $plugin_file,
				'name'        => $plugin_data['Name'],
				'version'     => $plugin_data['Version'],
				'description' => $plugin_data['Description'],
				'author'      => $plugin_data['Author'],
				// @phpstan-ignore-next-line
				'active'      => in_array( $plugin_file, $active_plugins, true ),
			];
		}

		return [
			'plugins'      => $plugins,
			'total'        => count( $plugins ),
			// @phpstan-ignore-next-line
			'active_count' => count( $active_plugins ),
		];
	}

	protected function permission_callback( $input = null ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}

/**
 * Get Themes ability.
 *
 * @since 1.0.0
 */
class GetThemesAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'List Themes', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'List all installed WordPress themes with their status.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => (object) [],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'themes' => [ 'type' => 'array' ],
				'total'  => [ 'type' => 'integer' ],
				'active' => [ 'type' => 'string' ],
			],
		];
	}

	protected function execute_callback( $input = null ) {
		/** @var array<string, mixed> $input */
		$all_themes   = wp_get_themes();
		$active_theme = get_stylesheet();

		$themes = [];
		foreach ( $all_themes as $theme_slug => $theme ) {
			$themes[] = [
				'slug'        => $theme_slug,
				'name'        => $theme->get( 'Name' ),
				'version'     => $theme->get( 'Version' ),
				'description' => $theme->get( 'Description' ),
				'author'      => $theme->get( 'Author' ),
				'active'      => $theme_slug === $active_theme,
			];
		}

		return [
			'themes' => $themes,
			'total'  => count( $themes ),
			'active' => $active_theme,
		];
	}

	protected function permission_callback( $input = null ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}

/**
 * Install Plugin ability.
 *
 * @since 1.0.0
 */
class InstallPluginAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Install Plugin', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Install a plugin from the WordPress.org plugin directory by slug. Optionally activate after installation.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'slug'     => [
					'type'        => 'string',
					'description' => 'The plugin slug from wordpress.org (e.g., "akismet", "contact-form-7")',
				],
				'activate' => [
					'type'        => 'boolean',
					'description' => 'Whether to activate the plugin after installation (default: false)',
				],
			],
			'required'   => [ 'slug' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'status'      => [ 'type' => 'string' ],
				'message'     => [ 'type' => 'string' ],
				'plugin_file' => [ 'type' => 'string' ],
				'active'      => [ 'type' => 'boolean' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$slug     = $input['slug'] ?? '';
		$activate = (bool) ( $input['activate'] ?? false );

		if ( empty( $slug ) ) {
			return new WP_Error( 'sd_ai_agent_empty_slug', __( 'Plugin slug is required.', 'superdav-ai-agent' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		// Check if already installed.
		$installed_plugins = get_plugins();
		foreach ( $installed_plugins as $plugin_file => $plugin_data ) {
			// @phpstan-ignore-next-line
			if ( strpos( $plugin_file, $slug . '/' ) === 0 || $plugin_file === $slug . '.php' ) {
				$is_active = is_plugin_active( $plugin_file );

				if ( $activate && ! $is_active ) {
					$result = activate_plugin( $plugin_file );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					return [
						'status'      => 'activated',
						// @phpstan-ignore-next-line
						'message'     => sprintf( 'Plugin "%s" was already installed and has been activated.', $slug ),
						'plugin_file' => $plugin_file,
						'active'      => true,
					];
				}

				return [
					'status'      => 'already_installed',
					// @phpstan-ignore-next-line
					'message'     => sprintf( 'Plugin "%s" is already installed%s.', $slug, $is_active ? ' and active' : '' ),
					'plugin_file' => $plugin_file,
					'active'      => $is_active,
				];
			}
		}

		// Get plugin info from wordpress.org.
		$api = plugins_api(
			'plugin_information',
			// @phpstan-ignore-next-line
			[
				'slug'   => $slug,
				'fields' => [
					'sections'          => false,
					'short_description' => true,
				],
			]
		);

		if ( is_wp_error( $api ) ) {
			return $api;
		}

		// Install the plugin.
		$skin          = new \WP_Ajax_Upgrader_Skin();
		$upgrader      = new \Plugin_Upgrader( $skin );
		$download_link = is_object( $api ) ? $api->download_link : '';
		$result        = $upgrader->install( $download_link );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result ) {
			$errors = $skin->get_errors();
			if ( is_wp_error( $errors ) && $errors->has_errors() ) {
				return $errors;
			}
			return new WP_Error( 'sd_ai_agent_install_failed', __( 'Installation failed for unknown reason.', 'superdav-ai-agent' ) );
		}

		$plugin_file = $upgrader->plugin_info();

		if ( $activate && $plugin_file ) {
			$activate_result = activate_plugin( $plugin_file );
			if ( is_wp_error( $activate_result ) ) {
				return [
					'status'      => 'installed',
					// @phpstan-ignore-next-line
					'message'     => sprintf( 'Plugin "%s" installed but activation failed: %s', $slug, $activate_result->get_error_message() ),
					'plugin_file' => $plugin_file,
					'active'      => false,
				];
			}
			return [
				'status'      => 'installed_and_activated',
				// @phpstan-ignore-next-line
				'message'     => sprintf( 'Plugin "%s" installed and activated successfully.', $slug ),
				'plugin_file' => $plugin_file,
				'active'      => true,
			];
		}

		return [
			'status'      => 'installed',
			// @phpstan-ignore-next-line
			'message'     => sprintf( 'Plugin "%s" installed successfully.', $slug ),
			'plugin_file' => $plugin_file ?: '',
			'active'      => false,
		];
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'annotations'  => [
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			],
			'show_in_rest' => true,
		];
	}
}

/**
 * Update Plugin ability.
 *
 * Updates an installed plugin to the latest available version using the
 * core Plugin_Upgrader. The plugin can be identified by either its slug
 * (directory name) or its plugin file (e.g. "akismet/akismet.php").
 *
 * @since 1.1.0
 */
class UpdatePluginAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Update Plugin', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Update an installed plugin to the latest version available from its source.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'slug'        => [
					'type'        => 'string',
					'description' => 'The plugin directory slug (e.g. "akismet"). Either slug or plugin_file is required.',
				],
				'plugin_file' => [
					'type'        => 'string',
					'description' => 'The plugin file relative to the plugins directory (e.g. "akismet/akismet.php"). Either slug or plugin_file is required.',
				],
			],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'status'      => [ 'type' => 'string' ],
				'message'     => [ 'type' => 'string' ],
				'plugin_file' => [ 'type' => 'string' ],
				'from'        => [ 'type' => 'string' ],
				'to'          => [ 'type' => 'string' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$slug        = isset( $input['slug'] ) ? (string) $input['slug'] : '';
		$plugin_file = isset( $input['plugin_file'] ) ? (string) $input['plugin_file'] : '';

		if ( '' === $slug && '' === $plugin_file ) {
			return new WP_Error( 'sd_ai_agent_missing_plugin', __( 'Either "slug" or "plugin_file" is required.', 'superdav-ai-agent' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$installed = get_plugins();

		// Resolve plugin_file from slug if needed.
		if ( '' === $plugin_file ) {
			foreach ( $installed as $file => $_data ) {
				if ( strpos( $file, $slug . '/' ) === 0 || $file === $slug . '.php' ) {
					$plugin_file = $file;
					break;
				}
			}
		}

		if ( '' === $plugin_file || ! isset( $installed[ $plugin_file ] ) ) {
			return new WP_Error(
				'sd_ai_agent_plugin_not_installed',
				sprintf(
					/* translators: %s: plugin identifier */
					__( 'Plugin not installed: %s', 'superdav-ai-agent' ),
					'' !== $slug ? $slug : $plugin_file
				)
			);
		}

		$from_version = isset( $installed[ $plugin_file ]['Version'] ) ? (string) $installed[ $plugin_file ]['Version'] : '';

		// Force a fresh update check so wp_update_plugins has current data.
		wp_clean_plugins_cache( false );
		wp_update_plugins();

		$updates    = get_site_transient( 'update_plugins' );
		$has_update = is_object( $updates ) && isset( $updates->response[ $plugin_file ] );

		if ( ! $has_update ) {
			return [
				'status'      => 'up_to_date',
				'message'     => sprintf(
					/* translators: 1: plugin file, 2: version */
					__( 'Plugin "%1$s" is already at the latest version (%2$s).', 'superdav-ai-agent' ),
					$plugin_file,
					$from_version
				),
				'plugin_file' => $plugin_file,
				'from'        => $from_version,
				'to'          => $from_version,
			];
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $plugin_file );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result ) {
			$errors = $skin->get_errors();
			if ( is_wp_error( $errors ) && $errors->has_errors() ) {
				return $errors;
			}
			return new WP_Error( 'sd_ai_agent_update_failed', __( 'Plugin update failed for unknown reason.', 'superdav-ai-agent' ) );
		}

		// Re-read version post-upgrade.
		wp_clean_plugins_cache( false );
		$installed_after = get_plugins();
		$to_version      = isset( $installed_after[ $plugin_file ]['Version'] ) ? (string) $installed_after[ $plugin_file ]['Version'] : '';

		return [
			'status'      => 'updated',
			'message'     => sprintf(
				/* translators: 1: plugin file, 2: old version, 3: new version */
				__( 'Plugin "%1$s" updated from %2$s to %3$s.', 'superdav-ai-agent' ),
				$plugin_file,
				$from_version,
				$to_version
			),
			'plugin_file' => $plugin_file,
			'from'        => $from_version,
			'to'          => $to_version,
		];
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'annotations'  => [
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}

/**
 * Call a whitelisted WordPress function by name with arguments.
 *
 * Replaces the former RunPhpAbility (eval-based) with a safe, whitelisted
 * approach that only allows calling pre-approved WordPress functions via
 * call_user_func_array().
 *
 * @since 1.1.0
 */
class RunPhpAbility extends AbstractAbility {

	/**
	 * Allowed WordPress functions that the AI agent may call.
	 *
	 * Only side-effect-free read functions and common write functions with
	 * well-understood behaviour are included.  Extend via the
	 * `sd_ai_agent_allowed_wp_functions` filter.
	 *
	 * @var string[]
	 */
	private const ALLOWED_FUNCTIONS = [
		// Options.
		'get_option',
		'update_option',
		'delete_option',
		// Posts / Pages.
		'get_post',
		'get_posts',
		'wp_insert_post',
		'wp_update_post',
		'wp_delete_post',
		'get_post_meta',
		'update_post_meta',
		'delete_post_meta',
		// Terms / Taxonomies.
		'get_terms',
		'get_term',
		'wp_insert_term',
		'wp_update_term',
		'wp_delete_term',
		'wp_set_post_terms',
		'wp_get_post_terms',
		// Users.
		'get_user_by',
		'get_users',
		'get_current_user_id',
		'get_user_meta',
		'update_user_meta',
		// Comments.
		'get_comments',
		'get_comment',
		'wp_insert_comment',
		'wp_update_comment',
		'wp_delete_comment',
		// Queries.
		'wp_count_posts',
		'wp_count_terms',
		'count_users',
		// Transients.
		'get_transient',
		'set_transient',
		'delete_transient',
		// Site info.
		'get_bloginfo',
		'home_url',
		'site_url',
		'admin_url',
		'wp_upload_dir',
		'is_multisite',
		// Plugins / Themes.
		'get_plugins',
		'is_plugin_active',
		'wp_get_theme',
		'wp_get_themes',
		// Shortcodes.
		'do_shortcode',
		'shortcode_exists',
		// Menus.
		'wp_get_nav_menus',
		'wp_get_nav_menu_items',
		// Misc.
		'wp_remote_get',
		'wp_remote_post',
		'current_time',
		'wp_date',
		'wp_create_nonce',
	];

	protected function label(): string {
		return __( 'Call WordPress Function', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Low-level fallback: call a whitelisted WordPress function directly. Use ONLY when no dedicated ability exists for the task. For posts (use `sd-ai-agent/create-post`), users, options, plugins, themes, and other common operations, call `sd-ai-agent/ability-search` first to find a purpose-built tool — dedicated abilities have typed schemas and better error recovery than guessing positional args here. When you do use this, pass the function name via `function` and an ordered array via `args`.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'function' => [
					'type'        => 'string',
					'description' => 'The WordPress function name to call, e.g. "get_option", "wp_insert_post".',
				],
				'args'     => [
					'type'        => 'array',
					'description' => 'Ordered array of arguments to pass to the function. Defaults to an empty array.',
					'items'       => array(
						'type' => array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ),
					),
				],
			],
			'required'   => [ 'function' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'result' => [],
				'output' => [ 'type' => 'string' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$function = $input['function'] ?? '';
		$args     = $input['args'] ?? [];

		if ( empty( $function ) || ! is_string( $function ) ) {
			return new WP_Error(
				'sd_ai_agent_empty_function',
				__( 'A function name is required.', 'superdav-ai-agent' )
			);
		}

		if ( ! is_array( $args ) ) {
			return new WP_Error(
				'sd_ai_agent_invalid_args',
				__( 'The "args" parameter must be an array.', 'superdav-ai-agent' )
			);
		}

		// Build the runtime allowlist (static defaults + user extensions).
		$allowed = self::get_allowed_functions();

		if ( ! in_array( $function, $allowed, true ) ) {
			return new WP_Error(
				'sd_ai_agent_disallowed_function',
				sprintf(
					/* translators: %s: function name */
					__( 'The function "%s" is not in the allowed list. Use the sd_ai_agent_allowed_wp_functions filter to extend it.', 'superdav-ai-agent' ),
					$function
				)
			);
		}

		if ( ! function_exists( $function ) ) {
			return new WP_Error(
				'sd_ai_agent_undefined_function',
				sprintf(
					/* translators: %s: function name */
					__( 'The function "%s" does not exist in this WordPress environment.', 'superdav-ai-agent' ),
					$function
				)
			);
		}

		ob_start();
		$error  = null;
		$result = null;

		try {
			$result = call_user_func_array( $function, array_values( $args ) );
		} catch ( \Throwable $e ) {
			$error = $e->getMessage();
		}

		$output = ob_get_clean();

		if ( null !== $error ) {
			return new WP_Error(
				'sd_ai_agent_php_error',
				sprintf( 'PHP error: %s', $error )
			);
		}

		return [
			'result' => $result,
			'output' => $output,
		];
	}

	/**
	 * Get the full list of allowed functions (built-in + filtered).
	 *
	 * @return string[]
	 */
	private static function get_allowed_functions(): array {
		/**
		 * Filters the list of WordPress functions the AI agent is allowed to call.
		 *
		 * @since 1.1.0
		 *
		 * @param string[] $functions List of allowed function names.
		 */
		$functions = apply_filters( 'sd_ai_agent_allowed_wp_functions', self::ALLOWED_FUNCTIONS );

		// Ensure the list is a flat array of strings (defensive).
		return array_values( array_filter( (array) $functions, 'is_string' ) );
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'annotations'  => [
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			],
			'show_in_rest' => true,
		];
	}
}

/**
 * Recommend Plugin ability.
 *
 * Returns ranked plugin recommendations from the curated AbilityPluginRegistry
 * based on a need category. Preference order: has_abilities > has_blocks > active_installs.
 *
 * @since 1.2.0
 */
class RecommendPluginAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Recommend Plugin', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Given a need category (e.g. "ecommerce", "forms", "seo"), return ranked plugin recommendations from the curated abilities registry. Plugins that register WordPress Abilities are ranked highest, followed by those with blocks, then by popularity. Use this before install-plugin to discover the best plugin for a task.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		$categories = AbilityPluginRegistry::get_categories();
		sort( $categories );
		return [
			'type'       => 'object',
			'properties' => [
				'category'        => [
					'type'        => 'string',
					'description' => 'The need category to search for. One of the values in `enum`. Set `list_categories: true` to enumerate them dynamically.',
					'enum'        => $categories,
				],
				'limit'           => [
					'type'        => 'integer',
					'description' => 'Maximum number of recommendations to return (default: 5, max: 20).',
					'minimum'     => 1,
					'maximum'     => 20,
				],
				'list_categories' => [
					'type'        => 'boolean',
					'description' => 'If true, return all available categories instead of recommendations. Useful for discovery.',
				],
			],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'recommendations' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'slug'            => [ 'type' => 'string' ],
							'name'            => [ 'type' => 'string' ],
							'description'     => [ 'type' => 'string' ],
							'ability_count'   => [ 'type' => 'integer' ],
							'has_abilities'   => [ 'type' => 'boolean' ],
							'has_blocks'      => [ 'type' => 'boolean' ],
							'active_installs' => [ 'type' => 'integer' ],
							'categories'      => [ 'type' => 'array' ],
						],
					],
				],
				'total'           => [ 'type' => 'integer' ],
				'category'        => [ 'type' => 'string' ],
				'categories'      => [ 'type' => 'array' ],
			],
		];
	}

	protected function execute_callback( $input = null ) {
		/** @var array<string, mixed> $input */
		$list_categories = (bool) ( $input['list_categories'] ?? false );

		if ( $list_categories ) {
			$categories = AbilityPluginRegistry::get_categories();
			sort( $categories );
			return [
				'categories' => $categories,
				'total'      => count( $categories ),
			];
		}

		$category = isset( $input['category'] ) ? (string) $input['category'] : '';
		$limit    = isset( $input['limit'] ) ? min( 20, max( 1, (int) $input['limit'] ) ) : 5;

		if ( '' === $category ) {
			return new WP_Error(
				'sd_ai_agent_missing_category',
				__( 'A "category" is required, or set "list_categories" to true to see all available categories.', 'superdav-ai-agent' )
			);
		}

		$matches = AbilityPluginRegistry::get_by_category( $category );

		if ( empty( $matches ) ) {
			$all     = AbilityPluginRegistry::get_categories();
			$cat_low = strtolower( trim( $category ) );
			$near    = [];
			foreach ( $all as $candidate ) {
				$lev = levenshtein( $cat_low, strtolower( $candidate ) );
				if ( $lev <= 4 || str_contains( strtolower( $candidate ), $cat_low ) || str_contains( $cat_low, strtolower( $candidate ) ) ) {
					$near[] = [
						'category' => $candidate,
						'distance' => $lev,
					];
				}
			}
			usort(
				$near,
				static function ( array $a, array $b ): int {
					return $a['distance'] - $b['distance'];
				}
			);
			$suggestions = array_slice( array_column( $near, 'category' ), 0, 5 );
			sort( $all );
			return [
				'recommendations'      => [],
				'total'                => 0,
				'category'             => $category,
				'available_categories' => $all,
				'suggested_categories' => $suggestions,
				'hint'                 => empty( $suggestions )
					? 'No close match. Choose a category from `available_categories` and call again.'
					: 'No exact match. Closest categories are in `suggested_categories`.',
			];
		}

		$ranked = AbilityPluginRegistry::rank( $matches );
		$top    = array_slice( $ranked, 0, $limit );

		return [
			'recommendations' => $top,
			'total'           => count( $top ),
			'category'        => $category,
		];
	}

	protected function permission_callback( $input = null ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}

/**
 * Install Plugin from URL ability.
 *
 * Installs a plugin from any direct ZIP URL — GitHub release assets,
 * self-hosted ZIPs, or any other publicly accessible download link.
 * Uses the same Plugin_Upgrader path as core WordPress, so it handles
 * unzip, directory placement, and activation identically to the admin UI.
 *
 * @since 1.3.0
 */
class InstallPluginFromUrlAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Install Plugin from URL', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Install a plugin from any direct ZIP URL, including GitHub release assets (e.g. https://github.com/owner/repo/releases/latest/download/plugin.zip). Optionally activate after installation.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'url'      => [
					'type'        => 'string',
					'description' => 'Direct URL to the plugin ZIP file. GitHub example: https://github.com/bjornfix/mcp-expose-abilities/releases/latest/download/mcp-expose-abilities.zip',
				],
				'activate' => [
					'type'        => 'boolean',
					'description' => 'Whether to activate the plugin after installation (default: false).',
				],
			],
			'required'   => [ 'url' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'status'      => [ 'type' => 'string' ],
				'message'     => [ 'type' => 'string' ],
				'plugin_file' => [ 'type' => 'string' ],
				'active'      => [ 'type' => 'boolean' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$url      = isset( $input['url'] ) ? (string) $input['url'] : '';
		$activate = (bool) ( $input['activate'] ?? false );

		if ( '' === $url ) {
			return new WP_Error( 'sd_ai_agent_empty_url', __( 'A plugin ZIP URL is required.', 'superdav-ai-agent' ) );
		}

		// Basic URL validation — must be http(s).
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return new WP_Error(
				'sd_ai_agent_invalid_url',
				__( 'URL must begin with http:// or https://.', 'superdav-ai-agent' )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $url );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result ) {
			$errors = $skin->get_errors();
			if ( is_wp_error( $errors ) && $errors->has_errors() ) {
				return $errors;
			}
			return new WP_Error( 'sd_ai_agent_install_failed', __( 'Installation failed for unknown reason.', 'superdav-ai-agent' ) );
		}

		$plugin_file = $upgrader->plugin_info();

		if ( $activate && $plugin_file ) {
			$activate_result = activate_plugin( $plugin_file );
			if ( is_wp_error( $activate_result ) ) {
				return [
					'status'      => 'installed',
					'message'     => sprintf(
						/* translators: 1: plugin file, 2: error message */
						__( 'Plugin "%1$s" installed from URL but activation failed: %2$s', 'superdav-ai-agent' ),
						$plugin_file,
						$activate_result->get_error_message()
					),
					'plugin_file' => (string) $plugin_file,
					'active'      => false,
				];
			}
			return [
				'status'      => 'installed_and_activated',
				'message'     => sprintf(
					/* translators: %s: plugin file */
					__( 'Plugin "%s" installed from URL and activated successfully.', 'superdav-ai-agent' ),
					$plugin_file
				),
				'plugin_file' => (string) $plugin_file,
				'active'      => true,
			];
		}

		return [
			'status'      => 'installed',
			'message'     => sprintf(
				/* translators: %s: plugin file */
				__( 'Plugin "%s" installed from URL successfully.', 'superdav-ai-agent' ),
				$plugin_file ?? ''
			),
			'plugin_file' => (string) ( $plugin_file ?? '' ),
			'active'      => false,
		];
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'annotations'  => [
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			],
			'show_in_rest' => true,
		];
	}
}

/**
 * Activate Plugin ability.
 *
 * Activates an installed plugin identified by slug or plugin file path.
 *
 * @since 1.3.0
 */
class ActivatePluginAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Activate Plugin', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Activate an installed WordPress plugin by slug (directory name) or plugin file (e.g. "akismet/akismet.php").', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'slug'        => [
					'type'        => 'string',
					'description' => 'The plugin directory slug (e.g. "akismet"). Either slug or plugin_file is required.',
				],
				'plugin_file' => [
					'type'        => 'string',
					'description' => 'The plugin file relative to the plugins directory (e.g. "akismet/akismet.php"). Either slug or plugin_file is required.',
				],
			],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'status'      => [ 'type' => 'string' ],
				'message'     => [ 'type' => 'string' ],
				'plugin_file' => [ 'type' => 'string' ],
				'active'      => [ 'type' => 'boolean' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$slug        = isset( $input['slug'] ) ? (string) $input['slug'] : '';
		$plugin_file = isset( $input['plugin_file'] ) ? (string) $input['plugin_file'] : '';

		if ( '' === $slug && '' === $plugin_file ) {
			return new WP_Error( 'sd_ai_agent_missing_plugin', __( 'Either "slug" or "plugin_file" is required.', 'superdav-ai-agent' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$installed = get_plugins();

		if ( '' === $plugin_file ) {
			foreach ( $installed as $file => $_data ) {
				if ( strpos( $file, $slug . '/' ) === 0 || $file === $slug . '.php' ) {
					$plugin_file = $file;
					break;
				}
			}
		}

		if ( '' === $plugin_file || ! isset( $installed[ $plugin_file ] ) ) {
			return new WP_Error(
				'sd_ai_agent_plugin_not_installed',
				sprintf(
					/* translators: %s: plugin identifier */
					__( 'Plugin not installed: %s', 'superdav-ai-agent' ),
					'' !== $slug ? $slug : $plugin_file
				)
			);
		}

		if ( is_plugin_active( $plugin_file ) ) {
			return [
				'status'      => 'already_active',
				'message'     => sprintf(
					/* translators: %s: plugin file */
					__( 'Plugin "%s" is already active.', 'superdav-ai-agent' ),
					$plugin_file
				),
				'plugin_file' => $plugin_file,
				'active'      => true,
			];
		}

		$result = activate_plugin( $plugin_file );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'status'       => 'activated',
			'message'      => sprintf(
				/* translators: %s: plugin file */
				__( 'Plugin "%s" activated successfully.', 'superdav-ai-agent' ),
				$plugin_file
			),
			'plugin_file'  => $plugin_file,
			'active'       => true,
			'verification' => [
				'active_plugins' => (array) get_option( 'active_plugins', [] ),
			],
		];
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'annotations'  => [
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}

/**
 * Deactivate Plugin ability.
 *
 * Deactivates an active plugin identified by slug or plugin file path.
 *
 * @since 1.3.0
 */
class DeactivatePluginAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Deactivate Plugin', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Deactivate an active WordPress plugin by slug (directory name) or plugin file (e.g. "akismet/akismet.php").', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'slug'        => [
					'type'        => 'string',
					'description' => 'The plugin directory slug (e.g. "akismet"). Either slug or plugin_file is required.',
				],
				'plugin_file' => [
					'type'        => 'string',
					'description' => 'The plugin file relative to the plugins directory (e.g. "akismet/akismet.php"). Either slug or plugin_file is required.',
				],
			],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'status'      => [ 'type' => 'string' ],
				'message'     => [ 'type' => 'string' ],
				'plugin_file' => [ 'type' => 'string' ],
				'active'      => [ 'type' => 'boolean' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$slug        = isset( $input['slug'] ) ? (string) $input['slug'] : '';
		$plugin_file = isset( $input['plugin_file'] ) ? (string) $input['plugin_file'] : '';

		if ( '' === $slug && '' === $plugin_file ) {
			return new WP_Error( 'sd_ai_agent_missing_plugin', __( 'Either "slug" or "plugin_file" is required.', 'superdav-ai-agent' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$installed = get_plugins();

		if ( '' === $plugin_file ) {
			foreach ( $installed as $file => $_data ) {
				if ( strpos( $file, $slug . '/' ) === 0 || $file === $slug . '.php' ) {
					$plugin_file = $file;
					break;
				}
			}
		}

		if ( '' === $plugin_file || ! isset( $installed[ $plugin_file ] ) ) {
			return new WP_Error(
				'sd_ai_agent_plugin_not_installed',
				sprintf(
					/* translators: %s: plugin identifier */
					__( 'Plugin not installed: %s', 'superdav-ai-agent' ),
					'' !== $slug ? $slug : $plugin_file
				)
			);
		}

		if ( ! is_plugin_active( $plugin_file ) ) {
			return [
				'status'      => 'already_inactive',
				'message'     => sprintf(
					/* translators: %s: plugin file */
					__( 'Plugin "%s" is already inactive.', 'superdav-ai-agent' ),
					$plugin_file
				),
				'plugin_file' => $plugin_file,
				'active'      => false,
			];
		}

		deactivate_plugins( $plugin_file );

		return [
			'status'       => 'deactivated',
			'message'      => sprintf(
				/* translators: %s: plugin file */
				__( 'Plugin "%s" deactivated successfully.', 'superdav-ai-agent' ),
				$plugin_file
			),
			'plugin_file'  => $plugin_file,
			'active'       => false,
			'verification' => [
				'active_plugins' => (array) get_option( 'active_plugins', [] ),
			],
		];
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'annotations'  => [
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}

/**
 * Delete Plugin ability.
 *
 * Permanently removes an inactive plugin from the filesystem.
 * The plugin must be deactivated before deletion.
 *
 * @since 1.3.0
 */
class DeletePluginAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Delete Plugin', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Permanently delete an inactive WordPress plugin. Deactivate it first with deactivate-plugin if needed.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'slug'        => [
					'type'        => 'string',
					'description' => 'The plugin directory slug (e.g. "akismet"). Either slug or plugin_file is required.',
				],
				'plugin_file' => [
					'type'        => 'string',
					'description' => 'The plugin file relative to the plugins directory (e.g. "akismet/akismet.php"). Either slug or plugin_file is required.',
				],
			],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'status'      => [ 'type' => 'string' ],
				'message'     => [ 'type' => 'string' ],
				'plugin_file' => [ 'type' => 'string' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$slug        = isset( $input['slug'] ) ? (string) $input['slug'] : '';
		$plugin_file = isset( $input['plugin_file'] ) ? (string) $input['plugin_file'] : '';

		if ( '' === $slug && '' === $plugin_file ) {
			return new WP_Error( 'sd_ai_agent_missing_plugin', __( 'Either "slug" or "plugin_file" is required.', 'superdav-ai-agent' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$installed = get_plugins();

		if ( '' === $plugin_file ) {
			foreach ( $installed as $file => $_data ) {
				if ( strpos( $file, $slug . '/' ) === 0 || $file === $slug . '.php' ) {
					$plugin_file = $file;
					break;
				}
			}
		}

		if ( '' === $plugin_file || ! isset( $installed[ $plugin_file ] ) ) {
			return new WP_Error(
				'sd_ai_agent_plugin_not_installed',
				sprintf(
					/* translators: %s: plugin identifier */
					__( 'Plugin not installed: %s', 'superdav-ai-agent' ),
					'' !== $slug ? $slug : $plugin_file
				)
			);
		}

		if ( is_plugin_active( $plugin_file ) ) {
			return new WP_Error(
				'sd_ai_agent_plugin_active',
				sprintf(
					/* translators: %s: plugin file */
					__( 'Plugin "%s" is currently active. Deactivate it first before deleting.', 'superdav-ai-agent' ),
					$plugin_file
				)
			);
		}

		if ( ! function_exists( 'delete_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$result = delete_plugins( [ $plugin_file ] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result ) {
			return new WP_Error( 'sd_ai_agent_delete_failed', __( 'Plugin deletion failed for unknown reason.', 'superdav-ai-agent' ) );
		}

		return [
			'status'      => 'deleted',
			'message'     => sprintf(
				/* translators: %s: plugin file */
				__( 'Plugin "%s" deleted successfully.', 'superdav-ai-agent' ),
				$plugin_file
			),
			'plugin_file' => $plugin_file,
		];
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'annotations'  => [
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			],
			'show_in_rest' => true,
		];
	}
}

/**
 * List Plugin Updates ability.
 *
 * Returns all installed plugins that have updates available, forcing a
 * fresh check against the WordPress.org update API before returning.
 *
 * @since 1.3.0
 */
class ListPluginUpdatesAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'List Plugin Updates', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'List all installed plugins that have updates available. Forces a fresh check against the update API.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => (object) [],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'updates' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'plugin_file'     => [ 'type' => 'string' ],
							'name'            => [ 'type' => 'string' ],
							'current_version' => [ 'type' => 'string' ],
							'new_version'     => [ 'type' => 'string' ],
							'update_url'      => [ 'type' => 'string' ],
						],
					],
				],
				'count'   => [ 'type' => 'integer' ],
			],
		];
	}

	protected function execute_callback( $input = null ) {
		/** @var array<string, mixed>|null $input */
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		// Force a fresh update check.
		wp_clean_plugins_cache( false );
		wp_update_plugins();

		$installed = get_plugins();
		$updates   = get_site_transient( 'update_plugins' );
		$response  = is_object( $updates ) && isset( $updates->response ) ? (array) $updates->response : [];

		$result = [];
		foreach ( $response as $plugin_file => $update_data ) {
			$plugin_file = (string) $plugin_file;
			$name        = isset( $installed[ $plugin_file ]['Name'] ) ? (string) $installed[ $plugin_file ]['Name'] : $plugin_file;
			$current     = isset( $installed[ $plugin_file ]['Version'] ) ? (string) $installed[ $plugin_file ]['Version'] : '';
			$new_version = is_object( $update_data ) && isset( $update_data->new_version ) ? (string) $update_data->new_version : '';
			$update_url  = is_object( $update_data ) && isset( $update_data->package ) ? (string) $update_data->package : '';

			$result[] = [
				'plugin_file'     => $plugin_file,
				'name'            => $name,
				'current_version' => $current,
				'new_version'     => $new_version,
				'update_url'      => $update_url,
			];
		}

		return [
			'updates' => $result,
			'count'   => count( $result ),
		];
	}

	protected function permission_callback( $input = null ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}

/**
 * Search Plugin Directory ability.
 *
 * Queries the WordPress.org plugin API for plugins matching a keyword.
 * Returns name, slug, short description, active installs, and rating.
 *
 * @since 1.3.0
 */
class SearchPluginDirectoryAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Search Plugin Directory', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Search the official WordPress.org plugin directory by keyword. Returns matching plugins with slug, description, active installs, and rating.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'search'   => [
					'type'        => 'string',
					'description' => 'Search keyword(s) to query the WordPress.org plugin directory.',
				],
				'per_page' => [
					'type'        => 'integer',
					'description' => 'Number of results to return (default: 10, max: 25).',
					'minimum'     => 1,
					'maximum'     => 25,
				],
			],
			'required'   => [ 'search' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'plugins' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'slug'              => [ 'type' => 'string' ],
							'name'              => [ 'type' => 'string' ],
							'short_description' => [ 'type' => 'string' ],
							'version'           => [ 'type' => 'string' ],
							'active_installs'   => [ 'type' => 'integer' ],
							'rating'            => [ 'type' => 'number' ],
							'author'            => [ 'type' => 'string' ],
						],
					],
				],
				'total'   => [ 'type' => 'integer' ],
				'query'   => [ 'type' => 'string' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$search   = isset( $input['search'] ) ? (string) $input['search'] : '';
		$per_page = isset( $input['per_page'] ) ? min( 25, max( 1, (int) $input['per_page'] ) ) : 10;

		if ( '' === $search ) {
			return new WP_Error( 'sd_ai_agent_empty_search', __( 'A search keyword is required.', 'superdav-ai-agent' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$api = plugins_api(
			'query_plugins',
			// @phpstan-ignore-next-line
			[
				'search'   => $search,
				'per_page' => $per_page,
				'fields'   => [
					'short_description' => true,
					'sections'          => false,
					'tags'              => false,
					'icons'             => false,
					'banners'           => false,
				],
			]
		);

		if ( is_wp_error( $api ) ) {
			return $api;
		}

		$plugins = [];
		$raw     = is_object( $api ) && isset( $api->plugins ) ? (array) $api->plugins : [];

		foreach ( $raw as $plugin ) {
			// The API can return either objects or arrays depending on the response shape.
			if ( is_object( $plugin ) ) {
				$plugins[] = [
					'slug'              => (string) ( $plugin->slug ?? '' ),
					'name'              => (string) ( $plugin->name ?? '' ),
					'short_description' => (string) ( $plugin->short_description ?? '' ),
					'version'           => (string) ( $plugin->version ?? '' ),
					'active_installs'   => (int) ( $plugin->active_installs ?? 0 ),
					'rating'            => (float) ( $plugin->rating ?? 0 ),
					'author'            => (string) ( $plugin->author ?? '' ),
				];
			} elseif ( is_array( $plugin ) ) {
				$plugins[] = [
					'slug'              => (string) ( $plugin['slug'] ?? '' ),
					'name'              => (string) ( $plugin['name'] ?? '' ),
					'short_description' => (string) ( $plugin['short_description'] ?? '' ),
					'version'           => (string) ( $plugin['version'] ?? '' ),
					'active_installs'   => (int) ( $plugin['active_installs'] ?? 0 ),
					'rating'            => (float) ( $plugin['rating'] ?? 0 ),
					'author'            => (string) ( $plugin['author'] ?? '' ),
				];
			}
		}

		$total = is_object( $api ) && isset( $api->info['results'] ) ? (int) $api->info['results'] : count( $plugins );

		return [
			'plugins' => $plugins,
			'total'   => $total,
			'query'   => $search,
		];
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}

/**
 * Switch Plugin ability.
 *
 * Activates one plugin and optionally deactivates one or more others in a
 * single atomic operation. If activation fails, any plugins that were
 * deactivated in this call are re-activated (rollback).
 *
 * @since 1.3.0
 */
class SwitchPluginAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Switch Plugin', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Activate one plugin and optionally deactivate one or more others atomically. Rolls back deactivations if activation fails. Useful for switching between competing plugins (e.g. SEO plugins, caching plugins).', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'activate'   => [
					'type'        => 'string',
					'description' => 'Slug or plugin file of the plugin to activate.',
				],
				'deactivate' => [
					'type'        => 'array',
					'description' => 'Array of slugs or plugin files to deactivate before activating the target.',
					'items'       => [ 'type' => 'string' ],
				],
			],
			'required'   => [ 'activate' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'status'      => [ 'type' => 'string' ],
				'message'     => [ 'type' => 'string' ],
				'activated'   => [ 'type' => 'string' ],
				'deactivated' => [ 'type' => 'array' ],
				'rolled_back' => [ 'type' => 'array' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$activate_target = isset( $input['activate'] ) ? (string) $input['activate'] : '';
		$deactivate_list = isset( $input['deactivate'] ) && is_array( $input['deactivate'] ) ? $input['deactivate'] : [];

		if ( '' === $activate_target ) {
			return new WP_Error( 'sd_ai_agent_missing_activate', __( '"activate" is required.', 'superdav-ai-agent' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$installed = get_plugins();

		/** @var array<string, array<string, mixed>> $installed */
		// Resolve activate target to plugin_file.
		$activate_file = $this->resolve_plugin_file( $activate_target, $installed );
		if ( null === $activate_file ) {
			return new WP_Error(
				'sd_ai_agent_plugin_not_installed',
				sprintf(
					/* translators: %s: plugin identifier */
					__( 'Plugin to activate not found: %s', 'superdav-ai-agent' ),
					$activate_target
				)
			);
		}

		// Resolve deactivate targets.
		$deactivate_files = [];
		foreach ( $deactivate_list as $target ) {
			$file = $this->resolve_plugin_file( (string) $target, $installed );
			if ( null !== $file ) {
				$deactivate_files[] = $file;
			}
		}

		// Deactivate the requested plugins.
		$actually_deactivated = [];
		foreach ( $deactivate_files as $file ) {
			if ( is_plugin_active( $file ) ) {
				deactivate_plugins( $file );
				$actually_deactivated[] = $file;
			}
		}

		// Activate the target.
		$result = activate_plugin( $activate_file );

		if ( is_wp_error( $result ) ) {
			// Rollback: re-activate anything we deactivated.
			$rolled_back = [];
			foreach ( $actually_deactivated as $file ) {
				$rb = activate_plugin( $file );
				if ( ! is_wp_error( $rb ) ) {
					$rolled_back[] = $file;
				}
			}

			return [
				'status'      => 'failed',
				'message'     => sprintf(
					/* translators: 1: plugin file, 2: error message, 3: rollback count */
					__( 'Failed to activate "%1$s": %2$s. Rolled back %3$d deactivation(s).', 'superdav-ai-agent' ),
					$activate_file,
					$result->get_error_message(),
					count( $rolled_back )
				),
				'activated'   => '',
				'deactivated' => [],
				'rolled_back' => $rolled_back,
			];
		}

		return [
			'status'      => 'switched',
			'message'     => sprintf(
				/* translators: 1: activated plugin, 2: count of deactivated plugins */
				__( 'Activated "%1$s" and deactivated %2$d plugin(s).', 'superdav-ai-agent' ),
				$activate_file,
				count( $actually_deactivated )
			),
			'activated'   => $activate_file,
			'deactivated' => $actually_deactivated,
			'rolled_back' => [],
		];
	}

	/**
	 * Resolve a slug or plugin_file string to the installed plugin file key.
	 *
	 * @param string                              $target    Slug or plugin file.
	 * @param array<string, array<string, mixed>> $installed Installed plugins map.
	 * @return string|null
	 */
	private function resolve_plugin_file( string $target, array $installed ): ?string {
		// Exact match (already a plugin file).
		if ( isset( $installed[ $target ] ) ) {
			return $target;
		}
		// Slug match.
		foreach ( $installed as $file => $_data ) {
			if ( strpos( $file, $target . '/' ) === 0 || $file === $target . '.php' ) {
				return $file;
			}
		}
		return null;
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'annotations'  => [
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			],
			'show_in_rest' => true,
		];
	}
}
