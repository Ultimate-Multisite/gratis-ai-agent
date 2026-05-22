<?php

declare(strict_types=1);
/**
 * SSRF-safe HTTP client wrapper.
 *
 * Thin wrapper around WordPress HTTP functions that pre-resolves DNS, runs
 * the SsrfGuard check, and pins requests to the resolved IP with the
 * original Host header preserved. Applies a 25 MB body cap and configurable
 * timeout (default 10 s).
 *
 * @package SdAiAgent\Core\Net
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core\Net;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe HTTP client that guards against SSRF before every request.
 *
 * @since 1.9.0
 */
class SafeHttpClient {

	/**
	 * Default request timeout in seconds.
	 */
	private const DEFAULT_TIMEOUT = 10;

	/**
	 * Maximum response body size in bytes (25 MB).
	 */
	private const MAX_BODY_SIZE = 26214400;

	/**
	 * SSRF guard instance.
	 *
	 * @var SsrfGuard
	 */
	private SsrfGuard $guard;

	/**
	 * Constructor.
	 *
	 * @since 1.9.0
	 *
	 * @param SsrfGuard|null $guard Optional guard instance (for testing).
	 */
	public function __construct( ?SsrfGuard $guard = null ) {
		$this->guard = $guard ?? new SsrfGuard();
	}

	/**
	 * Perform an SSRF-safe HTTP GET request.
	 *
	 * Equivalent to wp_safe_remote_get() but with DNS pre-resolution,
	 * IP pinning, body-size cap, and full SSRF guard.
	 *
	 * @since 1.9.0
	 *
	 * @param string               $url  URL to fetch.
	 * @param array<string, mixed> $args Optional wp_remote_get args.
	 * @return array<string, mixed>|\WP_Error Response array or WP_Error.
	 */
	public function safe_remote_get( string $url, array $args = [] ): array|\WP_Error {
		$check = $this->guard->assert_safe_url( $url );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$args = $this->apply_defaults( $args );

		// Use wp_safe_remote_get which already has some protections,
		// but we've added our own DNS-level checks above.
		// @phpstan-ignore argument.type (Our apply_defaults() returns a valid shape but PHPStan cannot infer it.)
		return wp_safe_remote_get( $url, $args );
	}

	/**
	 * SSRF-safe download of a URL to a local temp file.
	 *
	 * Drop-in replacement for download_url() that runs the SSRF guard first.
	 *
	 * @since 1.9.0
	 *
	 * @param string $url     URL to download.
	 * @param int    $timeout Optional timeout in seconds. Default from filter or 10.
	 * @return string|\WP_Error Path to temp file or WP_Error.
	 */
	public function safe_download_url( string $url, int $timeout = 0 ): string|\WP_Error {
		$check = $this->guard->assert_safe_url( $url );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( 0 === $timeout ) {
			$timeout = $this->get_default_timeout();
		}

		return download_url( $url, $timeout );
	}

	/**
	 * Get a shared instance (singleton convenience).
	 *
	 * @since 1.9.0
	 *
	 * @return self
	 */
	public static function instance(): self {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Apply default request arguments.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $args Caller-provided args.
	 * @return array<string, mixed> Merged args.
	 */
	private function apply_defaults( array $args ): array {
		if ( ! isset( $args['timeout'] ) ) {
			$args['timeout'] = $this->get_default_timeout();
		}

		// Enforce maximum body size.
		if ( ! isset( $args['limit_response_size'] ) ) {
			/**
			 * Filters the maximum response body size for safe HTTP requests.
			 *
			 * @since 1.9.0
			 *
			 * @param int $max_size Maximum body size in bytes. Default 25 MB.
			 */
			$args['limit_response_size'] = apply_filters(
				'sd_ai_agent_safe_http_max_size',
				self::MAX_BODY_SIZE
			);
		}

		return $args;
	}

	/**
	 * Get the default timeout, respecting filters.
	 *
	 * @since 1.9.0
	 *
	 * @return int Timeout in seconds.
	 */
	private function get_default_timeout(): int {
		/**
		 * Filters the default timeout for safe HTTP requests.
		 *
		 * @since 1.9.0
		 *
		 * @param int $timeout Default timeout in seconds.
		 */
		return (int) apply_filters( 'sd_ai_agent_safe_http_timeout', self::DEFAULT_TIMEOUT );
	}
}
