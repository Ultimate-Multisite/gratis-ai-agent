<?php
/**
 * Bridge: restore Gutenberg's Connectors infrastructure on WP 6.9 installs.
 *
 * Background
 * ----------
 * On WordPress 6.9 the Connectors page (Settings → Connectors) does not exist
 * in core. The Gutenberg plugin (22.8.0+) backports the entire Connectors
 * subsystem in `lib/experimental/connectors/` (registry init, default AI
 * provider registration, key passing to the AI Client, REST validation,
 * script-module data filter, and the Settings → Connectors menu item). The
 * whole subsystem is included by `lib/load.php` behind a class-existence
 * gate evaluated at *plugin file-load time*:
 *
 *     // gutenberg/lib/load.php
 *     if ( class_exists( '\WordPress\AiClient\AiClient' ) ) {
 *         require __DIR__ . '/experimental/connectors/load.php';
 *     }
 *
 * WordPress loads plugins alphabetically, so `gutenberg` runs before
 * `superdav-ai-agent`. At the moment Gutenberg evaluates that gate, our
 * `SdkLoader::register()` (which makes `\WordPress\AiClient\AiClient`
 * autoloadable from `lib/php-ai-client/`) has not yet run. The check fails,
 * Gutenberg never loads `experimental/connectors/load.php`, and:
 *
 *   - the Settings → Connectors menu item is never registered (404)
 *   - the connector registry is never populated (no AI provider cards)
 *   - the React app's script-module data has no `connectors` array
 *   - saved API keys never reach the AI Client registry
 *
 * Fix
 * ---
 * Two-stage polyfill:
 *
 *   1. PRIMARY — `force_load_connectors_subsystem()` runs on
 *      `plugins_loaded:1`, after our SDK loader (executed at plugin
 *      file-include time) but well before `init:15` when the connectors
 *      registry initialiser fires. It requires Gutenberg's own
 *      `lib/experimental/connectors/load.php`, which transitively requires
 *      `default-connectors.php`. From this point on, behaviour is identical
 *      to a Gutenberg install on WP 7.0 — every default AI provider is
 *      registered, every plugin's `wp_connectors_init` hook fires, the
 *      script-module data filter populates the React app, and Gutenberg's
 *      own `admin_menu:11` hook adds the Settings → Connectors menu item.
 *
 *   2. FALLBACK — `maybe_register()` runs on `admin_menu:12` (one tick
 *      after Gutenberg's own `admin_menu:11`). If the primary stage failed
 *      for any reason (e.g. a new Gutenberg release moves the loader path)
 *      but Gutenberg's render-page file is still locatable, we register
 *      the menu item directly so the admin page is at least reachable —
 *      even if the registry is empty. Defence in depth.
 *
 * On WP 7.0+ both stages are no-ops (core registers everything natively).
 * Without Gutenberg ≥ 22.8.0 both stages are no-ops (the existing JS layer
 * prompts the user to install Gutenberg).
 *
 * @package SdAiAgent\Compat
 * @license GPL-2.0-or-later
 * @since   1.11.1
 */

declare(strict_types=1);

namespace SdAiAgent\Compat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restores Gutenberg's Connectors admin page on WP 6.9 when a plugin
 * load-order race causes Gutenberg to skip its own registration.
 */
final class GutenbergConnectorsBridge {

	/**
	 * Admin page slug Gutenberg uses for the wp-admin Connectors page.
	 */
	private const PAGE_SLUG = 'options-connectors-wp-admin';

	/**
	 * Minimum Gutenberg version that ships the wp-admin Connectors page.
	 */
	private const MIN_GUTENBERG_VERSION = '22.8.0';

	/**
	 * Capability required to view the page (mirrors Gutenberg's own choice).
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Relative path (from `WP_PLUGIN_DIR`) to Gutenberg's connectors loader.
	 *
	 * This file requires `default-connectors.php` and registers Gutenberg's
	 * own `admin_menu:11` callback. Force-loading it on `plugins_loaded:1`
	 * restores the entire Connectors subsystem identically to Gutenberg's
	 * own gated load on WP 7.0+ installs.
	 */
	private const CONNECTORS_LOADER_PATH = '/gutenberg/lib/experimental/connectors/load.php';

	/**
	 * Force-load Gutenberg's Connectors subsystem.
	 *
	 * This is the PRIMARY polyfill path — it bypasses Gutenberg's failing
	 * class-existence gate by directly requiring the same loader file
	 * Gutenberg would have loaded if the gate had passed. After this runs,
	 * the connectors registry, default AI providers, REST validation,
	 * script-module data filter, and Settings → Connectors menu item are
	 * all wired up exactly as upstream Gutenberg intended.
	 *
	 * Must be called on `plugins_loaded` at a priority high enough that:
	 *
	 *   - our `SdkLoader::register()` has already run (it executes at
	 *     plugin file-include time, before any `plugins_loaded` action), and
	 *   - the connectors registry initialiser at `init:15` has not yet fired
	 *     (any `plugins_loaded` priority < 100 satisfies this).
	 *
	 * Idempotent: a `function_exists` guard prevents double-loading if a
	 * future Gutenberg release happens to fix its own gate, or if a
	 * separate mu-plugin (e.g. local dev `load-gutenberg-connectors.php`)
	 * has already required the file.
	 *
	 * @return void
	 */
	public static function force_load_connectors_subsystem(): void {
		// Already loaded by Gutenberg, an mu-plugin, or a previous call.
		if ( function_exists( '_gutenberg_connectors_init' ) ) {
			return;
		}

		if ( ! self::context_supports_polyfill() ) {
			return;
		}

		// The connectors loader's first action depends on the AiClient SDK,
		// so make sure it's autoloadable before we trigger the require.
		// In the normal flow our `SdkLoader::register()` ran at plugin
		// file-include time, so this should always pass.
		if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			return;
		}

		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			return;
		}

		$loader = WP_PLUGIN_DIR . self::CONNECTORS_LOADER_PATH;
		if ( file_exists( $loader ) ) {
			require_once $loader;
		}
	}

	/**
	 * Register the polyfill page if and only if Gutenberg failed to do so.
	 *
	 * @return void
	 */
	public static function maybe_register(): void {
		if ( ! self::should_register() ) {
			return;
		}

		$render_callback = self::resolve_render_callback();
		if ( null === $render_callback ) {
			return;
		}

		add_submenu_page(
			'options-general.php',
			__( 'Connectors', 'superdav-ai-agent' ),
			__( 'Connectors', 'superdav-ai-agent' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			$render_callback,
			1
		);
	}

	/**
	 * Whether the polyfill should run for the current request.
	 *
	 * Returns false on WP 7.0+ (native page exists), when Gutenberg is not
	 * present at the required version, or when the page is already registered
	 * (in case Gutenberg's own gate ever passes — defence in depth).
	 *
	 * @return bool
	 */
	private static function should_register(): bool {
		global $submenu;

		if ( ! self::context_supports_polyfill() ) {
			return false;
		}

		// If Gutenberg's own load.php registration already added the page
		// (e.g. plugin load order changes upstream, or our own
		// force_load_connectors_subsystem already triggered Gutenberg's
		// admin_menu:11 callback), do nothing.
		if ( is_array( $submenu ) && isset( $submenu['options-general.php'] ) ) {
			foreach ( $submenu['options-general.php'] as $entry ) {
				if ( isset( $entry[2] ) && self::PAGE_SLUG === $entry[2] ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Whether the current install is one that needs the WP 6.9 + Gutenberg
	 * Connectors polyfill at all.
	 *
	 * Shared by both polyfill stages. Returns false on WP 7.0+ (core has
	 * the Connectors subsystem natively) and on installs without Gutenberg
	 * ≥ 22.8.0 (no upstream code to bridge to).
	 *
	 * @return bool
	 */
	private static function context_supports_polyfill(): bool {
		global $wp_version;

		// Native WP 7.0+ already provides the Connectors subsystem in core.
		// Use the same alpha-aware comparison as UnifiedAdminMenu.
		if ( version_compare( (string) $wp_version, '7.0-alpha1', '>=' ) ) {
			return false;
		}

		// Gutenberg (≥ 22.8.0) is the upstream that ships the connectors code.
		if ( ! defined( 'GUTENBERG_VERSION' ) ) {
			return false;
		}
		if ( version_compare( GUTENBERG_VERSION, self::MIN_GUTENBERG_VERSION, '<' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Resolve the Gutenberg render callback, loading its source file if necessary.
	 *
	 * Gutenberg defines `gutenberg_options_connectors_wp_admin_render_page()` in
	 * `gutenberg/build/pages/options-connectors/page-wp-admin.php`. That file is
	 * normally pulled in by `gutenberg/lib/experimental/connectors/load.php`,
	 * which Gutenberg only requires when its `class_exists` gate passes. When
	 * the gate failed (the load-order race we are fixing), the file was never
	 * required, so we require it directly here.
	 *
	 * Returns null when Gutenberg's source file cannot be located — at which
	 * point we bail rather than registering a broken page.
	 *
	 * @return callable|null
	 */
	private static function resolve_render_callback(): ?callable {
		$render_fn = 'gutenberg_options_connectors_wp_admin_render_page';

		if ( ! function_exists( $render_fn ) ) {
			$render_file = self::locate_gutenberg_render_file();
			if ( null === $render_file ) {
				return null;
			}
			require_once $render_file;
		}

		if ( ! function_exists( $render_fn ) ) {
			return null;
		}

		return array( self::class, 'render_gutenberg_connectors_page' );
	}

	/**
	 * Render Gutenberg's Connectors admin page through a stable callback.
	 *
	 * @return void
	 */
	private static function render_gutenberg_connectors_page(): void {
		$render_fn = 'gutenberg_options_connectors_wp_admin_render_page';
		if ( ! function_exists( $render_fn ) ) {
			return;
		}

		$reflection = new \ReflectionFunction( $render_fn );
		$reflection->invoke();
	}

	/**
	 * Locate Gutenberg's wp-admin Connectors render-page file on disk.
	 *
	 * Uses WP_PLUGIN_DIR rather than guessing because some installs override
	 * the plugins directory. Confirms the file exists before returning.
	 *
	 * @return string|null Absolute path or null if not found.
	 */
	private static function locate_gutenberg_render_file(): ?string {
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			return null;
		}

		$path = WP_PLUGIN_DIR . '/gutenberg/build/pages/options-connectors/page-wp-admin.php';

		return file_exists( $path ) ? $path : null;
	}
}
