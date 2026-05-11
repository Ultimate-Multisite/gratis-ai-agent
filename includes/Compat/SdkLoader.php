<?php

declare(strict_types=1);
/**
 * Bundled php-ai-client SDK autoloader (Phase 1 — WP 6.9 compat).
 *
 * On WordPress 7.0+ the WordPress\AiClient SDK is shipped as part of core
 * (wp-includes/php-ai-client/) and loaded via wp-settings.php before any
 * plugin runs. This autoloader is therefore a no-op on WP 7.0+.
 *
 * On WordPress 6.9 the SDK is absent from core. This class registers a
 * lightweight spl_autoload_register handler that maps the two SDK namespaces
 * to our vendored copies in lib/php-ai-client/:
 *
 *   WordPress\AiClient\*            → lib/php-ai-client/src/
 *   WordPress\AiClientDependencies\ → lib/php-ai-client/third-party/
 *
 * The handler is prepended (third argument true) so it runs before Composer's
 * class-map, matching the load order that wp-settings.php uses for the real
 * php-ai-client package.
 *
 * Lifecycle:
 *   1. register() is called from sd-ai-agent.php, after the Composer vendor
 *      autoloader, before AiBridgeLoader::maybe_load().
 *   2. When AiBridgeLoader checks class_exists('WordPress\AiClient\AiClient'),
 *      this autoloader resolves the class on WP 6.9.
 *   3. On WP 7.0 the class already exists and this file's autoloader is
 *      registered but never triggered for those classes.
 *
 * @package SdAiAgent\Compat
 * @license GPL-2.0-or-later
 * @since   1.8.0
 */

namespace SdAiAgent\Compat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Idempotent include guard. The Jetpack autoloader's PHP_Autoloader uses
// `require` (not `require_once`) on line 102 of class-php-autoloader.php, so
// if this file is autoloaded through two distinct path representations of
// the same on-disk file (a real risk when the plugin is symlinked into
// `wp-content/plugins/` and `__FILE__` realpath differs from the include
// path used by Composer/Jetpack autoload manifests), PHP fatals with
// "Cannot redeclare class". The guard makes the file safe to evaluate
// twice without changing observable behaviour: the class was already
// declared on the first pass, so we have nothing to do on the second.
if ( class_exists( __NAMESPACE__ . '\\SdkLoader', false ) ) {
	return;
}

/**
 * Registers the bundled wordpress/php-ai-client SDK autoloader.
 *
 * @since 1.8.0
 */
final class SdkLoader {

	/**
	 * Path to the bundled SDK files.
	 * Set during register() and captured by the autoloader closure.
	 *
	 * @since 1.8.0
	 * @var string
	 */
	private static string $sdk_dir = '';

	/**
	 * Whether the autoloader has already been registered in this request.
	 *
	 * @since 1.8.0
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Register the SDK autoloader if the SDK is not already provided by core.
	 *
	 * Safe to call multiple times — subsequent calls are no-ops.
	 *
	 * @since 1.8.0
	 *
	 * @param string $plugin_dir Absolute path to the plugin root directory.
	 * @return void
	 */
	public static function register( string $plugin_dir ): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		// On WP 7.0+ the SDK is already loaded by core. Nothing to do.
		if ( class_exists( 'WordPress\\AiClient\\AiClient', false ) ) {
			return;
		}

		$sdk_dir = $plugin_dir . '/lib/php-ai-client';

		if ( ! is_dir( $sdk_dir ) ) {
			return;
		}

		self::$sdk_dir = $sdk_dir;

		spl_autoload_register(
			static function ( string $class_name ): void {
				$sdk_dir = self::$sdk_dir;

				$client_prefix     = 'WordPress\\AiClient\\';
				$client_prefix_len = 19;
				$deps_prefix       = 'WordPress\\AiClientDependencies\\';
				$deps_prefix_len   = 31;

				if ( 0 === strncmp( $class_name, $client_prefix, $client_prefix_len ) ) {
					$relative = substr( $class_name, $client_prefix_len );
					$file     = $sdk_dir . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
					if ( file_exists( $file ) ) {
						require_once $file;
					}
					return;
				}

				if ( 0 === strncmp( $class_name, $deps_prefix, $deps_prefix_len ) ) {
					$relative = substr( $class_name, $deps_prefix_len );
					$file     = $sdk_dir . '/third-party/' . str_replace( '\\', '/', $relative ) . '.php';
					if ( file_exists( $file ) ) {
						require_once $file;
					}
					return;
				}
			},
			true,   // throw on error
			true    // prepend — mirrors how wp-settings.php loads the SDK on WP 7.0
		);
	}
}
