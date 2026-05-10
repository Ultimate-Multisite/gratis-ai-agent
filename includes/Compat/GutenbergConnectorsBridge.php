<?php
/**
 * Bridge: register the Connectors admin page on WP 6.9 + Gutenberg installs.
 *
 * Background
 * ----------
 * On WordPress 6.9 the Connectors page (Settings → Connectors) does not exist
 * in core. The Gutenberg plugin (22.8.0+) backports it, but its registration
 * is gated on a class-existence check that runs at *plugin file-load time*:
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
 * Gutenberg never loads `experimental/connectors/load.php`, and the
 * `options-general.php?page=options-connectors-wp-admin` admin page is never
 * registered — producing a 404.
 *
 * Fix
 * ---
 * On `admin_menu` priority 12 (one tick after Gutenberg's own priority 11
 * registration), if (a) WP < 7.0, (b) Gutenberg ≥ 22.8.0 is active, and
 * (c) the page has not been registered, we manually require Gutenberg's
 * own render-page file and call `add_submenu_page()` with its render
 * callback — restoring the official Gutenberg-backed Connectors UI that
 * Gutenberg intended to provide.
 *
 * On WP 7.0+ this bridge is a no-op (core registers the page natively).
 * Without Gutenberg this bridge is a no-op (the existing JS layer prompts
 * the user to install Gutenberg).
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
		global $wp_version, $submenu;

		// Native WP 7.0+ already registers the Connectors page in core.
		// Use the same alpha-aware comparison as UnifiedAdminMenu.
		if ( version_compare( (string) $wp_version, '7.0-alpha1', '>=' ) ) {
			return false;
		}

		// Gutenberg (≥ 22.8.0) is the upstream that ships the page assets.
		if ( ! defined( 'GUTENBERG_VERSION' ) ) {
			return false;
		}
		if ( version_compare( GUTENBERG_VERSION, self::MIN_GUTENBERG_VERSION, '<' ) ) {
			return false;
		}

		// If Gutenberg's own load.php registration already added the page
		// (e.g. plugin load order changes upstream), do nothing.
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

		return $render_fn;
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
