<?php

declare(strict_types=1);
/**
 * Advanced WordPress management abilities for Superdav AI Agent.
 *
 * @package SdAiAgent\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers advanced WordPress management abilities supplied by the companion plugin.
 */
class WordPressAdvancedAbilities {

	/**
	 * Register advanced WordPress management abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/run-php',
			[
				'label'         => __( 'Call WordPress Function', 'superdav-ai-agent' ),
				'description'   => __( 'Low-level fallback: call a whitelisted WordPress function directly. Use ONLY when no dedicated ability exists for the task. For posts (use `sd-ai-agent/create-post`), users, options, plugins, themes, and other common operations, call `sd-ai-agent/ability-search` first to find a purpose-built tool — dedicated abilities have typed schemas and better error recovery than passing positional args through `run-php`.', 'superdav-ai-agent' ),
				'ability_class' => RunPhpAbility::class,
			]
		);

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
				'description'   => __( 'Preview or perform a plugin switch: activate one plugin and optionally deactivate one or more others. Set dry_run=true to exercise or inspect the switch without changing active plugins.', 'superdav-ai-agent' ),
				'ability_class' => SwitchPluginAbility::class,
			]
		);

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
			'mcp'          => [ 'public' => true ],
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
			'mcp'          => [ 'public' => true ],
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
			'mcp'          => [ 'public' => true ],
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
			'mcp'          => [ 'public' => true ],
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
		return __( 'Preview or perform a plugin switch atomically. Set dry_run=true to exercise the ability, inspect a proposed replacement, or verify what would change without activating or deactivating plugins. Useful for switching between competing plugins (e.g. SEO, caching, or anti-spam plugins).', 'superdav-ai-agent' );
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
				'dry_run'    => [
					'type'        => 'boolean',
					'description' => 'When true, preview the switch and return what would be activated/deactivated without changing active plugins. Use this for benchmark prompts or safety checks that say not to actually switch.',
					'default'     => false,
				],
			],
			'required'   => [ 'activate' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'status'           => [ 'type' => 'string' ],
				'message'          => [ 'type' => 'string' ],
				'activated'        => [ 'type' => 'string' ],
				'deactivated'      => [ 'type' => 'array' ],
				'rolled_back'      => [ 'type' => 'array' ],
				'dry_run'          => [ 'type' => 'boolean' ],
				'would_activate'   => [ 'type' => 'string' ],
				'would_deactivate' => [ 'type' => 'array' ],
				'target_installed' => [ 'type' => 'boolean' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$activate_target = isset( $input['activate'] ) ? (string) $input['activate'] : '';
		$deactivate_list = isset( $input['deactivate'] ) && is_array( $input['deactivate'] ) ? $input['deactivate'] : [];
		$dry_run         = ! empty( $input['dry_run'] );

		if ( '' === $activate_target ) {
			return new WP_Error( 'sd_ai_agent_missing_activate', __( '"activate" is required.', 'superdav-ai-agent' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$installed = get_plugins();

		/** @var array<string, array<string, mixed>> $installed */
		// Resolve activate target to plugin_file.
		$activate_file = $this->resolve_plugin_file( $activate_target, $installed );
		if ( $dry_run ) {
			$deactivate_files = [];
			foreach ( $deactivate_list as $target ) {
				$file = $this->resolve_plugin_file( (string) $target, $installed );
				if ( null !== $file ) {
					$deactivate_files[] = $file;
				}
			}

			return [
				'status'           => 'preview',
				'message'          => sprintf(
					/* translators: 1: plugin target, 2: count of deactivation targets */
					__( 'Dry run only: would activate "%1$s" and deactivate %2$d plugin(s). No plugins were changed.', 'superdav-ai-agent' ),
					$activate_file ?? $activate_target,
					count( $deactivate_files )
				),
				'activated'        => '',
				'deactivated'      => [],
				'rolled_back'      => [],
				'dry_run'          => true,
				'would_activate'   => $activate_file ?? $activate_target,
				'would_deactivate' => $deactivate_files,
				'target_installed' => null !== $activate_file,
			];
		}
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
