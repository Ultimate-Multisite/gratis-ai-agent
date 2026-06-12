<?php
/**
 * Plugin Name: Superdav AI Agent
 * Plugin URI:  https://github.com/Ultimate-Multisite/superdav-ai-agent
 * Description: Agentic AI loop for WordPress — chat with an AI that can call WordPress abilities (tools) autonomously.
 * Version:     1.17.0
 * Author:      superdav42
 * Author URI:  https://github.com/superdav42
 * License:     GPL-2.0-or-later
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Text Domain: superdav-ai-agent
 *
 * @package SdAiAgent
 *
 * See AGENTS.md for REST API security and operational guidelines.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SD_AI_AGENT_VERSION', '1.17.0' );
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
 * file-write, file-edit, and file-delete abilities are not registered. The
 * WordPress.org distribution also omits the git-tracking ability source files
 * because they inspect and snapshot plugin/theme source packages. Read-only
 * file operations (file-read, file-list, file-search, content-search) remain
 * available. Forced to `false` in the WordPress.org distribution build because
 * direct writes to
 * `wp-content/plugins/` and `wp-content/themes/` constitute arbitrary
 * code modification of other plugins/themes — the same class of risk
 * covered by the WP.org "Changing Active Plugins" guideline.
 */
defined( 'SD_AI_AGENT_FEATURE_FILE_WRITE' ) || define( 'SD_AI_AGENT_FEATURE_FILE_WRITE', true );

/**
 * Feature: block-theme scaffolder ability (`sd-ai-agent/scaffold-block-theme`).
 *
 * Writes theme.json, style.css, functions.php, and a starter
 * templates/index.html into `wp-content/themes/{slug}/` so the AI agent
 * can generate a new block theme on disk. When false the ability is
 * not registered and the rest of the Theme Builder abilities
 * (activate-theme, render-design-previews, generate-menu-page,
 * validate-palette-contrast, generate-logo-svg) remain available.
 *
 * Forced to `false` in the WordPress.org distribution build because
 * writing executable PHP/CSS into the active themes directory is the
 * same class of arbitrary-theme-code modification covered by the
 * WP.org "Changing Active Plugins" guideline (which the WP.org review
 * team applies symmetrically to themes). Self-hosted users running
 * the GitHub release retain the full Theme Builder.
 */
defined( 'SD_AI_AGENT_FEATURE_SCAFFOLD_BLOCK_THEME' ) || define( 'SD_AI_AGENT_FEATURE_SCAFFOLD_BLOCK_THEME', true );

/**
 * Feature: WP REST dispatcher abilities (`wp-rest/discover`,
 * `wp-rest/inspect`, `wp-rest/execute`).
 *
 * The dispatcher lets the agent enumerate and invoke any registered
 * WordPress REST endpoint via the internal in-process dispatcher. When
 * false the three abilities and their shared `wp-rest` category are
 * not registered.
 *
 * Forced to `false` in the WordPress.org distribution build because the
 * dispatcher is a generic low-level surface that exposes every
 * registered REST endpoint to whatever caller has the agent's
 * permission set — the same arbitrary-script-dispatch risk class as
 * `run-php`. Self-hosted users running the GitHub release retain the
 * dispatcher.
 */
defined( 'SD_AI_AGENT_FEATURE_WP_REST_DISPATCHER' ) || define( 'SD_AI_AGENT_FEATURE_WP_REST_DISPATCHER', true );

/**
 * Feature: WP-CLI dispatcher ability (`wp-cli/execute`).
 *
 * Shells out to the `wp` binary via PHP `exec()` to run arbitrary
 * WP-CLI subcommands. When false the ability and its shared `wp-cli`
 * category are not registered.
 *
 * Forced to `false` in the WordPress.org distribution build for the
 * same arbitrary-command-execution reason as `PLUGIN_BUILDER` and
 * `CUSTOM_TOOLS_CLI`. Self-hosted users running the GitHub release
 * retain the dispatcher.
 */
defined( 'SD_AI_AGENT_FEATURE_WP_CLI_DISPATCHER' ) || define( 'SD_AI_AGENT_FEATURE_WP_CLI_DISPATCHER', true );

/**
 * Feature: WP-CLI functional benchmark suite (`wp sd-ai-agent benchmark …`).
 *
 * Gates registration of the `benchmark` WP-CLI subcommand, which runs the
 * full agent loop against live WordPress and writes a JSON log per question
 * to `{uploads}/sd-ai-agent/benchmark-logs/` (overridable per-run via
 * `--log-dir=<abs-path>`). Disabled in the WordPress.org distribution
 * build because the `--log-dir` override lets an administrator point log
 * writes at an arbitrary absolute filesystem path, which the WP.org
 * reviewer flagged as out-of-scope for a public-directory plugin (data
 * should land in the database, the uploads dir, or the media uploader —
 * not at an operator-supplied absolute path).
 *
 * The full GitHub release zip retains the command for self-hosted users
 * who run benchmarks as part of model evaluation. The runtime gate alone
 * is defence-in-depth: `bin/build.sh --target=wporg` also physically
 * strips `includes/Benchmark/` and `includes/CLI/BenchmarkCommand.php`
 * from the zip and forces this constant to `false` in the bundled main
 * plugin file.
 */
defined( 'SD_AI_AGENT_FEATURE_BENCHMARK' ) || define( 'SD_AI_AGENT_FEATURE_BENCHMARK', true );

/**
 * Feature: mutating user-management abilities (create-user, update-user-role).
 * When false, those two abilities are not registered and any attempt to
 * execute them returns a `WP_Error`. The read-only `list-users` ability is
 * unaffected and remains available. Forced to `false` in the WordPress.org
 * distribution build because custom user-creation routes can bypass security
 * plugins (login throttling, password-policy enforcers) that hook the native
 * register/login flow — per WP.org plugin review team feedback.
 */
defined( 'SD_AI_AGENT_FEATURE_USER_MANAGEMENT' ) || define( 'SD_AI_AGENT_FEATURE_USER_MANAGEMENT', true );

/**
 * Feature: low-level whitelisted-PHP dispatcher (`sd-ai-agent/run-php`).
 * When false, the run-php ability is not registered and the underlying
 * `RunPhpAbility` class is not loaded. Forced to `false` in the
 * WordPress.org distribution build and the `RunPhpAbility.php` source
 * file is physically stripped from the shipped zip, per WP.org Guideline
 * 4 (no arbitrary script insertion or low-level PHP dispatch surfaces).
 */
defined( 'SD_AI_AGENT_FEATURE_RUN_PHP' ) || define( 'SD_AI_AGENT_FEATURE_RUN_PHP', true );

// Load Jetpack Autoloader for PSR-4 autoloading with version conflict resolution.
// Jetpack Autoloader ensures the newest version of shared packages (like php-ai-client) is used.
// Composer source installs, such as Bedrock sites using a VCS repository, may
// already expose the plugin and its dependencies through the root project
// autoloader without copying a plugin-local vendor directory into wp-content.
$sd_ai_agent_autoload_available = false;
if ( file_exists( SD_AI_AGENT_DIR . '/vendor/autoload_packages.php' ) ) {
	require_once SD_AI_AGENT_DIR . '/vendor/autoload_packages.php';
	$sd_ai_agent_autoload_available = true;
} elseif ( file_exists( SD_AI_AGENT_DIR . '/vendor/autoload.php' ) ) {
	require_once SD_AI_AGENT_DIR . '/vendor/autoload.php';
	$sd_ai_agent_autoload_available = true;
} else {
	$sd_ai_agent_autoload_available = class_exists( \SdAiAgent\Compat\SdkLoader::class ) && function_exists( 'xwp_load_app' );
}

if ( ! $sd_ai_agent_autoload_available ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'Superdav AI Agent is missing its Composer dependencies. Please run "composer install" in the plugin directory or root Composer project.',
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
use SdAiAgent\Core\ActiveJobsCleanupService;
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

// Register the stale active-jobs cron callback directly from the plugin file so
// standalone cron runners and queue-worker subprocesses do not depend on admin
// or REST DI contexts before the hook can be executed.
add_action( ActiveJobsCleanupService::CRON_HOOK, [ ActiveJobsCleanupService::class, 'run' ] );

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
