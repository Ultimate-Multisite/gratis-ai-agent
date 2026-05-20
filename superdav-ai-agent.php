<?php
/**
 * Plugin Name: Superdav AI Agent
 * Plugin URI:  https://github.com/Ultimate-Multisite/superdav-ai-agent
 * Description: Agentic AI loop for WordPress — chat with an AI that can call WordPress abilities (tools) autonomously.
 * Version:     1.16.1
 * Author:      superdav42
 * Author URI:  https://github.com/superdav42
 * License:     GPL-2.0-or-later
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Text Domain: superdav-ai-agent
 *
 * @package SdAiAgent
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SD_AI_AGENT_VERSION', '1.16.1' );
define( 'SD_AI_AGENT_DIR', __DIR__ );

// Allow the plugin to load from a symlinked path. Without this, WordPress
// resolves `__FILE__` to the realpath outside `WP_PLUGIN_DIR` and
// `plugin_dir_url()` returns a malformed URL containing the absolute
// filesystem path — admin asset URLs then 404 and the React chat panel
// fails to mount. The function is a no-op when the plugin is not loaded
// via a symlink (standard production installs).
if ( function_exists( 'wp_register_plugin_realpath' ) ) {
	wp_register_plugin_realpath( __FILE__ );
}

define( 'SD_AI_AGENT_URL', plugin_dir_url( __FILE__ ) );

/**
 * Built-in fallback model ID used when no model is configured in settings
 * and no connector default is available.
 *
 * Developers can override the effective default at runtime via the
 * `sd_ai_agent_default_model` filter rather than changing this constant.
 */
define( 'SD_AI_AGENT_DEFAULT_MODEL', 'claude-sonnet-4' );

/**
 * Absolute filesystem path to the WP-CLI binary (`wp` wrapper or `wp-cli.phar`).
 *
 * When empty (default), the plugin auto-discovers WP-CLI by checking common
 * system locations, the WordPress install root (`ABSPATH`), `wp-content/`,
 * and every directory in `$PATH`. On shared hosting where `wp` is not in
 * `$PATH`, drop `wp-cli.phar` into the WordPress root (next to `wp-config.php`)
 * and the plugin will find it automatically.
 *
 * Download URL:
 *   https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
 *
 * To pin an exact path, define this in `wp-config.php` before WordPress loads
 * plugins, e.g.:
 *
 *   define( 'SD_AI_AGENT_WP_CLI_PATH', '/home/user/bin/wp-cli.phar' );
 *
 * `.phar` files are detected by extension and executed via `PHP_BINARY` (the
 * same PHP that runs WordPress), so they do not need to be marked executable
 * and do not need a `php` interpreter on `$PATH`.
 *
 * The `sd_ai_agent_wp_cli_binary` runtime filter takes precedence over this
 * constant when both are set.
 */
defined( 'SD_AI_AGENT_WP_CLI_PATH' ) || define( 'SD_AI_AGENT_WP_CLI_PATH', '' );

// ── Feature flags ─────────────────────────────────────────────────────────────
// Each constant defaults to `true` (enabled) when not defined.
// Resellers / site owners can disable individual features by adding
// `define( 'SD_AI_AGENT_FEATURE_<NAME>', false );` to wp-config.php
// before the plugin loads.

/**
 * Feature: white-label branding — agent name, brand colours, logo URL.
 * When false, the Branding section is hidden and branding CSS vars are not set.
 */
defined( 'SD_AI_AGENT_FEATURE_BRANDING' ) || define( 'SD_AI_AGENT_FEATURE_BRANDING', true );

/**
 * Feature: role-based access control — who can access the AI agent.
 * When false, the Role Permissions manager and its REST routes are disabled.
 */
defined( 'SD_AI_AGENT_FEATURE_ACCESS_CONTROL' ) || define( 'SD_AI_AGENT_FEATURE_ACCESS_CONTROL', true );

/**
 * Feature: AI plugin builder — generate, sandbox-test, activate, and update
 * WordPress plugins from natural-language descriptions. When false, all six
 * plugin-builder abilities are skipped during registration and the related
 * `init` hook (`auto_deactivate_fatal_plugins`) becomes a no-op.
 *
 * This constant is forced to `false` in the WordPress.org distribution zip
 * built by `bin/build.sh --target=wporg` because the WP.org plugin
 * guidelines prohibit plugins that allow arbitrary PHP code insertion.
 * The full GitHub release zip leaves it `true`.
 */
defined( 'SD_AI_AGENT_FEATURE_PLUGIN_BUILDER' ) || define( 'SD_AI_AGENT_FEATURE_PLUGIN_BUILDER', true );

/**
 * Feature: WP-CLI custom-tool type — lets administrators register custom
 * tools that execute `wp` CLI commands via PHP `exec()`. When false,
 * `cli`-type custom tools are not registered as abilities and any attempt
 * to execute one returns a `WP_Error`. HTTP and Action custom tools are
 * unaffected. Forced to `false` in the WordPress.org distribution build.
 */
defined( 'SD_AI_AGENT_FEATURE_CUSTOM_TOOLS_CLI' ) || define( 'SD_AI_AGENT_FEATURE_CUSTOM_TOOLS_CLI', true );

/**
 * Feature: autonomous changes to the active plugin set. When false, the
 * activate-plugin, deactivate-plugin, delete-plugin, switch-plugin, and
 * update-plugin abilities are not registered, so the agent cannot
 * change which plugins are active without the user clicking through the
 * WP admin Plugins screen. Forced to `false` in the WordPress.org
 * distribution build per the WP.org "Changing Active Plugins" guideline.
 */
defined( 'SD_AI_AGENT_FEATURE_PLUGIN_STATE_CHANGES' ) || define( 'SD_AI_AGENT_FEATURE_PLUGIN_STATE_CHANGES', true );

/**
 * Feature: install plugins from arbitrary ZIP URLs (including GitHub).
 * When false, the install-plugin-from-url ability is not registered;
 * the WP.org-directory `install-plugin` ability remains available.
 * Forced to `false` in the WordPress.org distribution build.
 */
defined( 'SD_AI_AGENT_FEATURE_PLUGIN_INSTALL_FROM_URL' ) || define( 'SD_AI_AGENT_FEATURE_PLUGIN_INSTALL_FROM_URL', true );

/**
 * Feature: arbitrary filesystem writes inside wp-content. When false, the
 * file-write, file-edit, file-delete, git-restore, and git-revert-package
 * abilities are not registered; read-only file operations (file-read,
 * file-list, file-search, content-search, git-list, git-diff,
 * git-package-summary, git-snapshot) remain available. Forced to `false`
 * in the WordPress.org distribution build because direct writes to
 * `wp-content/plugins/` and `wp-content/themes/` constitute arbitrary
 * code modification of other plugins/themes — the same class of risk
 * covered by the WP.org "Changing Active Plugins" guideline.
 */
defined( 'SD_AI_AGENT_FEATURE_FILE_WRITE' ) || define( 'SD_AI_AGENT_FEATURE_FILE_WRITE', true );

// Load Jetpack Autoloader for PSR-4 autoloading with version conflict resolution.
// Jetpack Autoloader ensures the newest version of shared packages (like php-ai-client) is used.
if ( file_exists( SD_AI_AGENT_DIR . '/vendor/autoload_packages.php' ) ) {
	require_once SD_AI_AGENT_DIR . '/vendor/autoload_packages.php';
} elseif ( file_exists( SD_AI_AGENT_DIR . '/vendor/autoload.php' ) ) {
	require_once SD_AI_AGENT_DIR . '/vendor/autoload.php';
} else {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'Superdav AI Agent is missing its vendor dependencies. Please run "composer install" in the plugin directory.',
					'superdav-ai-agent',
				),
			);
		},
	);
	return;
}

use SdAiAgent\Bootstrap\LifecycleHandler;
use SdAiAgent\Compat\AiBridgeLoader;
use SdAiAgent\Compat\GutenbergConnectorsBridge;
use SdAiAgent\Compat\SdkLoader;
use SdAiAgent\Plugin;

// Phase 1 (t227): Register the bundled wordpress/php-ai-client SDK autoloader.
// On WP 7.0+ the SDK is already in core and this call is a no-op.
// On WP 6.9 the SDK is not in core; our bundled copy in lib/php-ai-client/ is
// registered here so that AiBridgeLoader (below) can find the SDK classes.
SdkLoader::register( SD_AI_AGENT_DIR );

// Phase 2 (t228): Load the WP AI Client bridge polyfill on WordPress < 7.0.
// On WP 7.0+ this is a no-op — core's definitions take precedence.
// Requires the wordpress/php-ai-client SDK to be available (registered above).
AiBridgeLoader::maybe_load();

// Phase 3 (t229): Load Connectors API polyfills on WordPress < 7.0.
// Provides _wp_connectors_get_provider_settings() and _wp_connectors_get_real_api_key()
// using the same connectors_ai_{provider}_api_key option names as WP 7.0.
// On WP 7.0+ the function_exists() guards in the file prevent double-definition.
require_once SD_AI_AGENT_DIR . '/includes/Compat/wp-connectors-polyfill.php';

// Phase 4 (#1311): Force-load Gutenberg's Connectors subsystem on WP 6.9.
// Gutenberg gates the entire `lib/experimental/connectors/` subsystem on a
// `class_exists('\WordPress\AiClient\AiClient')` check evaluated at
// plugin file-load time. Because plugins load alphabetically, our
// `SdkLoader::register()` above runs *after* Gutenberg's gate, so the gate
// always fails and the connectors registry is never populated. Hooking
// `plugins_loaded:1` runs after every plugin's main file but well before
// Gutenberg's `init:15` registry initialiser — exactly the window where
// directly requiring Gutenberg's loader restores the full subsystem.
// On WP 7.0+ (or without Gutenberg ≥ 22.8.0) this hook is a no-op.
add_action( 'plugins_loaded', [ GutenbergConnectorsBridge::class, 'force_load_connectors_subsystem' ], 1 );

// Activation / deactivation hooks fire *before* `plugins_loaded`, so they
// cannot be wired through the DI container. `LifecycleHandler` consolidates
// the handful of static calls that used to live inline here.
register_activation_hook( __FILE__, [ LifecycleHandler::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ LifecycleHandler::class, 'deactivate' ] );

// Bootstrap the DI container.
//
// `xwp_load_app()` schedules the container build at its default
// `plugins_loaded:PHP_INT_MIN` so it runs *before* the `Plugin` module's
// own `#[Module(hook: 'plugins_loaded', priority: 1)]` registration fires.
//
// All hook wiring — REST controllers, abilities, admin menus, core services,
// frontend assets — is managed by `#[Handler]` classes registered in
// `SdAiAgent\Plugin::$handlers`. Nothing else needs to live in this file.
xwp_load_app(
	[
		'id'            => 'sd-ai-agent',
		'module'        => Plugin::class,
		'autowiring'    => true,
		'compile'       => true,
		// The default `compile_class` is `CompiledContainer` + uppercased ID,
		// which produces invalid PHP class names when the ID contains hyphens.
		'compile_class' => 'CompiledContainerSdAiAgent',
		'compile_dir'   => SD_AI_AGENT_DIR . '/build/di-cache/' . SD_AI_AGENT_VERSION,
	],
);
