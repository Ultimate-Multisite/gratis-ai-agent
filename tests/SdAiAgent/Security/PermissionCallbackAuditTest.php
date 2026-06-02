<?php

declare(strict_types=1);
/**
 * Static source audit for REST route and ability permission callbacks.
 *
 * @package SdAiAgent
 * @subpackage Tests\Security
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Security;

use WP_UnitTestCase;

/**
 * Verifies executable route/ability registrations declare permission gates.
 */
class PermissionCallbackAuditTest extends WP_UnitTestCase {

	/**
	 * register_rest_route() calls must explicitly declare permission_callback.
	 */
	public function test_register_rest_route_calls_define_permission_callback(): void {
		$missing = [];

		foreach ( $this->get_php_files( 'includes' ) as $file ) {
			$source = $this->strip_comments( (string) file_get_contents( $file ) );
			foreach ( $this->extract_calls( $source, 'register_rest_route' ) as $call ) {
				if ( ! str_contains( $call['body'], 'permission_callback' ) ) {
					$missing[] = $this->relative_path( $file ) . ':' . $call['line'];
				}
			}
		}

		$this->assertSame( [], $missing, 'Every register_rest_route() call must define permission_callback.' );
	}

	/**
	 * wp_register_ability() calls must declare a direct permission callback or an ability_class.
	 */
	public function test_wp_register_ability_calls_define_permission_callback_or_ability_class(): void {
		$missing = [];

		foreach ( $this->get_php_files( 'includes' ) as $file ) {
			$source = $this->strip_comments( (string) file_get_contents( $file ) );
			foreach ( $this->extract_calls( $source, 'wp_register_ability' ) as $call ) {
				$body = $call['body'];
				if ( ! str_contains( $body, 'permission_callback' ) && ! str_contains( $body, 'ability_class' ) ) {
					$missing[] = $this->relative_path( $file ) . ':' . $call['line'];
				}
			}
		}

		$this->assertSame(
			[],
			$missing,
			'Every wp_register_ability() call must define permission_callback or use AbstractAbility via ability_class.'
		);
	}

	/**
	 * x-wp/di REST_Route attributes must define a guard callback.
	 */
	public function test_rest_route_attributes_define_guard(): void {
		$missing = [];

		foreach ( $this->get_php_files( 'includes/REST' ) as $file ) {
			$source = $this->strip_comments( (string) file_get_contents( $file ) );
			foreach ( $this->extract_calls( $source, 'REST_Route' ) as $call ) {
				if ( ! str_contains( $call['body'], 'guard:' ) ) {
					$missing[] = $this->relative_path( $file ) . ':' . $call['line'];
				}
			}
		}

		$this->assertSame( [], $missing, 'Every REST_Route attribute must define a guard callback.' );
	}

	/**
	 * Return PHP files under a repository-relative directory.
	 *
	 * @param string $relative_dir Directory relative to the repository root.
	 * @return list<string> Absolute file paths.
	 */
	private function get_php_files( string $relative_dir ): array {
		$dir   = $this->repo_root() . '/' . $relative_dir;
		$files = [];

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir ) );
		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$files[] = $file->getPathname();
		}

		sort( $files );

		return $files;
	}

	/**
	 * Strip PHP comments while preserving executable attributes and line numbers.
	 *
	 * @param string $source PHP source.
	 * @return string Source without comments.
	 */
	private function strip_comments( string $source ): string {
		$output = '';

		foreach ( token_get_all( $source ) as $token ) {
			if ( ! is_array( $token ) ) {
				$output .= $token;
				continue;
			}

			if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
				$output .= str_repeat( "\n", substr_count( $token[1], "\n" ) );
				continue;
			}

			$output .= $token[1];
		}

		return $output;
	}

	/**
	 * Extract top-level function/attribute calls by balanced parentheses.
	 *
	 * @param string $source Source code.
	 * @param string $name   Function or attribute class basename.
	 * @return list<array{line:int, body:string}> Call locations and source bodies.
	 */
	private function extract_calls( string $source, string $name ): array {
		$calls  = [];
		$offset = 0;
		$needle = $name . '(';

		while ( false !== ( $start = strpos( $source, $needle, $offset ) ) ) {
			if ( $start > 0 && preg_match( '/[A-Za-z0-9_]/', $source[ $start - 1 ] ) ) {
				$offset = $start + 1;
				continue;
			}

			$body = $this->read_balanced_call( $source, $start + strlen( $needle ) - 1 );
			if ( null !== $body ) {
				$calls[] = [
					'line' => substr_count( substr( $source, 0, $start ), "\n" ) + 1,
					'body' => substr( $source, $start, strlen( $needle ) - 1 ) . $body,
				];
				$offset  = $start + strlen( $body );
				continue;
			}

			$offset = $start + 1;
		}

		return $calls;
	}

	/**
	 * Read a balanced parenthesized call body from an opening parenthesis offset.
	 *
	 * @param string $source Source code.
	 * @param int    $start  Offset of the opening parenthesis.
	 * @return string|null Balanced body, or null when no complete call is found.
	 */
	private function read_balanced_call( string $source, int $start ): ?string {
		$length = strlen( $source );
		$depth  = 0;
		$quote  = null;
		$escape = false;

		for ( $i = $start; $i < $length; $i++ ) {
			$char = $source[ $i ];

			if ( null !== $quote ) {
				if ( $escape ) {
					$escape = false;
				} elseif ( '\\' === $char ) {
					$escape = true;
				} elseif ( $quote === $char ) {
					$quote = null;
				}
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				continue;
			}

			if ( '(' === $char ) {
				$depth++;
				continue;
			}

			if ( ')' === $char ) {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $source, $start, $i - $start + 1 );
				}
			}
		}

		return null;
	}

	/**
	 * Return a repository-relative path.
	 *
	 * @param string $file Absolute file path.
	 * @return string Repository-relative path.
	 */
	private function relative_path( string $file ): string {
		return ltrim( str_replace( $this->repo_root(), '', $file ), '/' );
	}

	/**
	 * Return the repository root.
	 *
	 * @return string Absolute repository root.
	 */
	private function repo_root(): string {
		return dirname( __DIR__, 3 );
	}
}
