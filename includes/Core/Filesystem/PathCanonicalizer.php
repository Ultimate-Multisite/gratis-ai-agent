<?php

declare(strict_types=1);
/**
 * Filesystem path canonicalisation helpers.
 *
 * @package SdAiAgent\Core\Filesystem
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core\Filesystem;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonicalises paths whose final target may not exist yet.
 *
 * WordPress core's wp_mkdir_p() refuses paths containing unresolved `..`
 * segments. This helper resolves the nearest existing ancestor with realpath()
 * and appends the still-missing suffix after rejecting traversal outside an
 * allowed root.
 */
class PathCanonicalizer {

	/**
	 * Canonicalise a path by resolving its nearest existing ancestor.
	 *
	 * @param string $path Absolute path to canonicalise. The path may not exist.
	 * @return string|WP_Error Canonical absolute path, or WP_Error on failure.
	 */
	public static function canonicalize_missing_path( string $path ): string|WP_Error {
		$path = str_replace( '\\', '/', $path );
		if ( '' === trim( $path ) || str_contains( $path, "\0" ) ) {
			return new WP_Error(
				'sd_ai_agent_invalid_path',
				__( 'Path contains invalid characters.', 'superdav-ai-agent' )
			);
		}

		$existing = $path;
		$suffix   = [];
		while ( ! file_exists( $existing ) && $existing !== dirname( $existing ) ) {
			$suffix[] = basename( $existing );
			$existing = dirname( $existing );
		}

		$real_existing = realpath( $existing );
		if ( false === $real_existing ) {
			return new WP_Error(
				'sd_ai_agent_path_resolve_failed',
				__( 'Cannot resolve path.', 'superdav-ai-agent' )
			);
		}

		$canonical = rtrim( str_replace( '\\', '/', $real_existing ), '/' );
		foreach ( array_reverse( $suffix ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return new WP_Error(
					'sd_ai_agent_path_traversal',
					__( 'Path contains directory traversal sequences.', 'superdav-ai-agent' )
				);
			}
			$canonical .= '/' . $segment;
		}

		return $canonical;
	}

	/**
	 * Canonicalise a path and assert it stays inside an allowed root.
	 *
	 * @param string $path Absolute path to canonicalise.
	 * @param string $root Existing allowed root directory.
	 * @return string|WP_Error Canonical absolute path, or WP_Error on failure.
	 */
	public static function canonicalize_missing_path_inside( string $path, string $root ): string|WP_Error {
		$canonical = self::canonicalize_missing_path( $path );
		if ( is_wp_error( $canonical ) ) {
			return $canonical;
		}

		$root_real = realpath( $root );
		if ( false === $root_real ) {
			return new WP_Error(
				'sd_ai_agent_path_resolve_failed',
				__( 'Cannot resolve allowed root.', 'superdav-ai-agent' )
			);
		}

		if ( ! self::path_is_inside( $canonical, $root_real ) ) {
			return new WP_Error(
				'sd_ai_agent_path_traversal',
				__( 'Path escapes the allowed root directory.', 'superdav-ai-agent' )
			);
		}

		return $canonical;
	}

	/**
	 * Check whether a path is equal to or inside a directory.
	 *
	 * @param string $path      Path to check.
	 * @param string $directory Directory boundary.
	 * @return bool True when path is inside directory.
	 */
	public static function path_is_inside( string $path, string $directory ): bool {
		$path      = rtrim( str_replace( '\\', '/', $path ), '/' );
		$directory = rtrim( str_replace( '\\', '/', $directory ), '/' );

		return $path === $directory || str_starts_with( $path, $directory . '/' );
	}
}
