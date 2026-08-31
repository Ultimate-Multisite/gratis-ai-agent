<?php

declare(strict_types=1);
/**
 * WordPress management abilities for the AI agent.
 *
 * Provides core plugin/theme listing, WordPress.org plugin installation, and
 * compatibility proxies for advanced companion-plugin abilities.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Core\AbilityPluginRegistry;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WordPressAbilities {

	/**
	 * Return a consistent disabled response when an advanced-only WordPress ability is unavailable.
	 *
	 * @param string $ability_name Ability name.
	 * @return WP_Error
	 */
	private static function advanced_plugin_required( string $ability_name ): WP_Error {
		return new WP_Error(
			'sd_ai_agent_advanced_plugin_required',
			sprintf(
				/* translators: %s: ability name */
				__( 'The %s ability is provided by SD AI Agent Advanced. Connect a verified SD AI account in the SD AI account settings, then choose Install and activate.', 'superdav-ai-agent' ),
				$ability_name
			)
		);
	}

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
		if ( ! class_exists( UpdatePluginAbility::class ) ) {
			return self::advanced_plugin_required( 'sd-ai-agent/update-plugin' );
		}

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
		if ( ! class_exists( InstallPluginFromUrlAbility::class ) ) {
			return self::advanced_plugin_required( 'sd-ai-agent/install-plugin-from-url' );
		}

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
		if ( ! class_exists( ActivatePluginAbility::class ) ) {
			return self::advanced_plugin_required( 'sd-ai-agent/activate-plugin' );
		}

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
		if ( ! class_exists( DeactivatePluginAbility::class ) ) {
			return self::advanced_plugin_required( 'sd-ai-agent/deactivate-plugin' );
		}

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
		if ( ! class_exists( DeletePluginAbility::class ) ) {
			return self::advanced_plugin_required( 'sd-ai-agent/delete-plugin' );
		}

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
		if ( ! class_exists( SwitchPluginAbility::class ) ) {
			return self::advanced_plugin_required( 'sd-ai-agent/switch-plugin' );
		}

		$ability = new SwitchPluginAbility(
			'sd-ai-agent/switch-plugin',
			[
				'label'       => __( 'Switch Plugin', 'superdav-ai-agent' ),
				'description' => __( 'Preview or perform a plugin switch: activate one plugin and optionally deactivate one or more others. Set dry_run=true to exercise or inspect the switch without changing active plugins.', 'superdav-ai-agent' ),
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
	 * Returns a WP_Error when Superdav AI Agent Advanced is not active.
	 *
	 * @param array<string,mixed> $input Input args (function, args).
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_run_php( array $input = [] ) {
		if ( ! class_exists( RunPhpAbility::class ) ) {
			return self::advanced_plugin_required( 'sd-ai-agent/run-php' );
		}

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
	 * Core registers read/discovery and WordPress.org-directory install
	 * plugin abilities. Advanced plugin state changes, arbitrary ZIP installs,
	 * and run-php are registered by Superdav AI Agent Advanced.
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

		// Advanced-only plugin state changes, arbitrary ZIP installs, and
		// low-level run-php are registered by Superdav AI Agent Advanced.
		// Core keeps only read/discovery and WordPress.org-directory install
		// plugin abilities for WordPress.org distribution.
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
			'mcp'          => [ 'public' => true ],
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
			'mcp'          => [ 'public' => true ],
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
			'mcp'          => [ 'public' => true ],
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
			'mcp'          => [ 'public' => true ],
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
			'mcp'          => [ 'public' => true ],
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
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}
