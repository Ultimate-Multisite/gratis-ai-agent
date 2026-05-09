<?php
/**
 * Plugin Name: Superdav AI Agent
 * Plugin URI:  https://github.com/Ultimate-Multisite/superdav-ai-agent
 * Description: Agentic AI loop for WordPress — chat with an AI that can call WordPress abilities (tools) autonomously.
 * Version:     1.11.1
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

define( 'SD_AI_AGENT_VERSION', '1.11.1' );
define( 'SD_AI_AGENT_DIR', __DIR__ );
define( 'SD_AI_AGENT_URL', plugin_dir_url( __FILE__ ) );

/**
 * Built-in fallback model ID used when no model is configured in settings
 * and no connector default is available.
 *
 * Developers can override the effective default at runtime via the
 * `sd_ai_agent_default_model` filter rather than changing this constant.
 */
define( 'SD_AI_AGENT_DEFAULT_MODEL', 'claude-sonnet-4' );

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
