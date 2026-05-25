<?php

declare(strict_types=1);
/**
 * File modification gate for enforcing wp_is_file_mod_allowed per context.
 *
 * Resolves file paths to their modification context (plugin_files, theme_files,
 * or upload_files) and gates mutations with wp_is_file_mod_allowed().
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core\Filesystem;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File modification gate.
 *
 * Provides context resolution and permission checking for file operations.
 *
 * @since 1.0.0
 */
class FileModGate {

	/**
	 * Resolve the file modification context for a given path.
	 *
	 * Determines whether a path falls under plugin_files, theme_files, or
	 * upload_files by checking against the respective root directories.
	 *
	 * For paths that don't exist yet, walks up the directory tree to find
	 * the nearest existing ancestor and resolves from there.
	 *
	 * @param string $path Absolute file path.
	 * @return string One of 'plugin_files', 'theme_files', 'upload_files'.
	 */
	public static function context_for_path( string $path ): string {
		// Resolve the real path, walking up if the path doesn't exist yet.
		$real_path = self::resolve_real_path( $path );

		if ( false === $real_path ) {
			// Fallback to upload_files if we can't resolve the path.
			return 'upload_files';
		}

		// Get the real paths of the root directories.
		$plugin_dir = realpath( WP_PLUGIN_DIR );
		$theme_root = realpath( get_theme_root() );
		$upload_dir = realpath( wp_upload_dir()['basedir'] );

		// Check if the path is inside the plugin directory.
		if ( false !== $plugin_dir && self::path_is_inside( $real_path, $plugin_dir ) ) {
			return 'plugin_files';
		}

		// Check if the path is inside the theme directory.
		if ( false !== $theme_root && self::path_is_inside( $real_path, $theme_root ) ) {
			return 'theme_files';
		}

		// Check if the path is inside the uploads directory.
		if ( false !== $upload_dir && self::path_is_inside( $real_path, $upload_dir ) ) {
			return 'upload_files';
		}

		// Default to upload_files for paths under ABSPATH but not in the above roots.
		// This matches Haydi's pattern.
		return 'upload_files';
	}

	/**
	 * Assert that file modifications are allowed for a given path.
	 *
	 * Calls wp_is_file_mod_allowed() with the resolved context and returns
	 * a WP_Error if modifications are not allowed.
	 *
	 * @param string $path Absolute file path.
	 * @return true|WP_Error True if allowed, WP_Error with status 403 if not.
	 */
	public static function assert_allowed( string $path ) {
		$context = self::context_for_path( $path );

		if ( ! wp_is_file_mod_allowed( $context ) ) {
			return new WP_Error(
				'file_mod_not_allowed',
				sprintf(
					'File modifications are not allowed on this site (context: %s).',
					$context
				),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Resolve the real path of a file, walking up the directory tree if needed.
	 *
	 * If the file doesn't exist, walks up to the nearest existing ancestor
	 * and returns its real path.
	 *
	 * @param string $path Absolute file path.
	 * @return string|false Real path on success, false on failure.
	 */
	private static function resolve_real_path( string $path ) {
		// If the path exists, return its real path.
		if ( file_exists( $path ) ) {
			return realpath( $path );
		}

		// Walk up the directory tree to find an existing ancestor.
		$parent = dirname( $path );
		while ( ! file_exists( $parent ) && $parent !== dirname( $parent ) ) {
			$parent = dirname( $parent );
		}

		// Return the real path of the nearest existing ancestor.
		return realpath( $parent );
	}

	/**
	 * Check if a path is inside a given directory.
	 *
	 * @param string $path      The path to check.
	 * @param string $directory The directory to check against.
	 * @return bool True if $path is inside $directory, false otherwise.
	 */
	private static function path_is_inside( string $path, string $directory ): bool {
		// Normalize paths to use forward slashes and remove trailing slashes.
		$path      = rtrim( str_replace( '\\', '/', $path ), '/' );
		$directory = rtrim( str_replace( '\\', '/', $directory ), '/' );

		// Check if the path starts with the directory.
		return strpos( $path, $directory ) === 0 && (
			strlen( $path ) === strlen( $directory ) ||
			$path[ strlen( $directory ) ] === '/'
		);
	}
}
