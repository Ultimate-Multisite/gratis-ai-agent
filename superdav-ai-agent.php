<?php
/**
 * Plugin Name: SD AI Agent
 * Plugin URI:  https://github.com/Ultimate-Multisite/superdav-ai-agent
 * Description: Agentic AI loop for WordPress — chat with an AI that can call WordPress abilities (tools) autonomously.
 * Version:     1.23.0
 * Author:      superdav42
 * Author URI:  https://github.com/superdav42
 * License:     GPL-2.0-or-later
 * Requires at least: 7.0
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

define( 'SD_AI_AGENT_VERSION', '1.23.0' );
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
define( 'SD_AI_AGENT_DEFAULT_MODEL', 'gpt-5.6-terra' );

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
	$sd_ai_agent_autoload_available = class_exists( \SdAiAgent\Plugin::class ) && function_exists( 'xwp_load_app' );
}

if ( ! $sd_ai_agent_autoload_available ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'SD AI Agent is missing its Composer dependencies. Please run "composer install" in the plugin directory or root Composer project.',
					'superdav-ai-agent',
				),
			);
		},
	);
	return;
}

use SdAiAgent\Bootstrap\LifecycleHandler;
use SdAiAgent\Contracts\CustomerAgentRuntimeInterface;
use SdAiAgent\Core\ActiveJobsCleanupService;
use SdAiAgent\Plugin;
use SdAiAgent\Services\CustomerAgentRuntimeService;

/**
 * Return the stable PHP API for trusted same-install customer-agent consumers.
 *
 * The API intentionally has no unauthenticated REST equivalent. Consumers must
 * register a constrained profile through the documented server-side filter.
 */
if ( ! function_exists( 'sd_ai_agent_customer_agent_runtime' ) ) {
	function sd_ai_agent_customer_agent_runtime(): CustomerAgentRuntimeInterface {
		return CustomerAgentRuntimeService::instance();
	}
}

// In repository checkouts, load the sibling advanced companion plugin before
// bootstrapping the DI container so local development gets both plugins with
// one WordPress activation. Distribution zips exclude /advanced-plugin, so
// production users install Superdav AI Agent Advanced separately.
$sd_ai_agent_advanced_plugin = SD_AI_AGENT_DIR . '/advanced-plugin/superdav-ai-agent-advanced.php';
if ( file_exists( $sd_ai_agent_advanced_plugin ) ) {
	require_once $sd_ai_agent_advanced_plugin;
}

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
