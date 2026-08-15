<?php

declare(strict_types=1);

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small wrapper for WordPress location helpers used by filesystem-facing code.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 */
final class WordPressPaths {

	/**
	 * Resolve the plugin root directory that contains this plugin.
	 *
	 * WordPress does not expose a direct public helper for the plugins root. Use
	 * the WordPress-defined plugin root so symlinked development installs still
	 * resolve other installed plugins correctly. Do not derive this from
	 * SD_AI_AGENT_DIR: that constant is this plugin's physical path, which may be
	 * outside the WordPress plugins directory when the plugin is symlinked.
	 *
	 * @return string Absolute directory path without a trailing slash.
	 */
	public static function plugins_dir(): string {
		return untrailingslashit( (string) constant( 'WP_PLUGIN_DIR' ) );
	}

	/**
	 * Resolve an installed plugin path relative to the plugins root.
	 *
	 * @param string $relative_path Relative plugin path, such as akismet/akismet.php.
	 * @return string Absolute path.
	 */
	public static function plugin_path( string $relative_path ): string {
		return trailingslashit( self::plugins_dir() ) . ltrim( $relative_path, '/\\' );
	}

	/**
	 * Resolve the content directory.
	 *
	 * WP_CONTENT_DIR is WordPress' authoritative content root. It remains stable
	 * when a theme or this plugin is loaded through a symlink, whereas deriving
	 * the root from get_theme_root() can resolve a different physical directory.
	 * The helper fallbacks retain compatibility with incomplete test bootstraps.
	 *
	 * @return string Absolute directory path without a trailing slash.
	 */
	public static function content_dir(): string {
		if ( defined( 'WP_CONTENT_DIR' ) && '' !== (string) constant( 'WP_CONTENT_DIR' ) ) {
			return untrailingslashit( (string) constant( 'WP_CONTENT_DIR' ) );
		}

		$theme_root = get_theme_root();
		if ( '' !== $theme_root ) {
			return untrailingslashit( dirname( $theme_root ) );
		}

		$uploads = wp_get_upload_dir();
		$basedir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';

		return '' !== $basedir ? untrailingslashit( dirname( $basedir ) ) : '';
	}

	/**
	 * Resolve the uploads directory via WordPress' public helper.
	 *
	 * @return string Absolute directory path without a trailing slash.
	 */
	public static function uploads_dir(): string {
		$uploads = wp_get_upload_dir();
		$basedir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';

		return untrailingslashit( $basedir );
	}
}
