<?php

declare(strict_types=1);
/**
 * Path-traversal guard for source=path uploads.
 *
 * Validates that a caller-supplied filesystem path resolves (via realpath)
 * to a location inside ABSPATH, preventing "../../etc/passwd"-style escapes.
 *
 * @package SdAiAgent\Core\Net
 * @since   1.10.0
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core\Net;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guards against path-traversal attacks for file-source uploads.
 *
 * @since 1.10.0
 */
class AbsPathGuard {

	/**
	 * Assert that $path resolves to a location inside ABSPATH.
	 *
	 * Uses realpath() to follow symlinks and eliminate ".." segments before
	 * comparing, so paths like "/var/www/../etc/passwd" are always rejected.
	 *
	 * Trailing DIRECTORY_SEPARATOR is appended to both sides of the comparison
	 * so that a path equal to ABSPATH itself is accepted while a directory
	 * that merely shares the same prefix (e.g. "/var/www/html-evil") is not.
	 *
	 * @since 1.10.0
	 *
	 * @param string $path Filesystem path supplied by the caller.
	 * @return true|\WP_Error true when safe; WP_Error('path_escape', ...) when outside ABSPATH.
	 */
	public static function assert_inside_abspath( string $path ): true|\WP_Error {
		if ( '' === $path ) {
			return new WP_Error(
				'path_escape',
				__( 'Path must not be empty.', 'superdav-ai-agent' )
			);
		}

		$real_path = realpath( $path );
		if ( false === $real_path ) {
			return new WP_Error(
				'path_escape',
				sprintf(
					/* translators: %s: the supplied path */
					__( 'Path "%s" does not exist or cannot be resolved.', 'superdav-ai-agent' ),
					$path
				)
			);
		}

		$real_abspath = realpath( ABSPATH );
		if ( false === $real_abspath ) {
			// Should never happen in a running WordPress install.
			return new WP_Error(
				'path_escape',
				__( 'ABSPATH cannot be resolved.', 'superdav-ai-agent' )
			);
		}

		/*
		 * Normalise both paths (forward slashes on all platforms) and append
		 * a trailing slash so that "/abspath-adjacent" cannot match "/abspath/".
		 * wp_normalize_path() converts backslashes on Windows.
		 */
		$norm_path    = rtrim( wp_normalize_path( $real_path ), '/' ) . '/';
		$norm_abspath = rtrim( wp_normalize_path( $real_abspath ), '/' ) . '/';

		if ( ! str_starts_with( $norm_path, $norm_abspath ) ) {
			return new WP_Error(
				'path_escape',
				sprintf(
					/* translators: %s: the supplied path */
					__( 'Path "%s" is outside the allowed directory.', 'superdav-ai-agent' ),
					$path
				)
			);
		}

		return true;
	}
}
