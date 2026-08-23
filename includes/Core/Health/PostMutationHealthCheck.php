<?php

declare(strict_types=1);
/**
 * PostMutationHealthCheck — fires a loopback request to the dedicated health
 * endpoint after a mutating operation to confirm WordPress still loads, and
 * runs a per-operation undo callback when it doesn't.
 *
 * URL discovery tries the registered REST health route first, then
 * 127.0.0.1 with the port parsed from the current request, then common fallback ports.
 * The first reachable URL is cached in a transient. A filter lets site owners
 * override the URL or skip the check entirely on hosts with unusual networking.
 *
 * @package SdAiAgent
 * @since   1.2.0
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core\Health;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post-mutation health check service.
 *
 * Reuses the existing GitTrackerManager snapshot as the undo callable.
 *
 * @since 1.2.0
 */
class PostMutationHealthCheck {

	/**
	 * Health status: site is healthy.
	 */
	private const STATUS_HEALTHY = 'healthy';

	/**
	 * Health status: site is broken.
	 */
	private const STATUS_BROKEN = 'broken';

	/**
	 * Health status: loopback is unreachable.
	 */
	private const STATUS_UNREACHABLE = 'unreachable';

	/**
	 * Perform the loopback request and return one of three states:
	 *   healthy     — got a 2xx response with our success token
	 *   broken      — got a 5xx response, or a 2xx response with a bad token
	 *   unreachable — could not connect, or received a redirect/client error
	 *
	 * @return string One of STATUS_HEALTHY, STATUS_BROKEN, or STATUS_UNREACHABLE.
	 */
	private function check(): string {
		/**
		 * Filter to skip health check entirely.
		 *
		 * @param bool $skip Default false. Return true to skip the check.
		 */
		if ( apply_filters( 'sd_ai_agent_skip_health_check', false ) ) {
			return self::STATUS_HEALTHY;
		}

		/**
		 * Filter to override the health check URL.
		 *
		 * @param string|null $url The health check URL, or null to auto-discover.
		 */
		$discovered_url = $this->discover_health_url();
		$health_url     = apply_filters( 'sd_ai_agent_health_url', $discovered_url );

		if ( null === $health_url ) {
			return self::STATUS_UNREACHABLE;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
		$response = wp_remote_get(
			$health_url,
			[
				'timeout'     => 10,
				'redirection' => 0,
				'sslverify'   => false,
			]
		);

		$status = is_wp_error( $response )
			? self::STATUS_UNREACHABLE
			: $this->classify_response( $response );
		$cached = get_transient( 'sd_ai_agent_health_url' );

		if (
			self::STATUS_UNREACHABLE !== $status
			|| ! is_string( $cached )
			|| $health_url !== $cached
			|| $health_url !== $discovered_url
		) {
			return $status;
		}

		// A stale cached route may no longer reach this site; retry all candidates before giving up.
		delete_transient( 'sd_ai_agent_health_url' );
		$health_url = apply_filters( 'sd_ai_agent_health_url', $this->discover_health_url() );

		if ( null === $health_url ) {
			return self::STATUS_UNREACHABLE;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
		$response = wp_remote_get(
			$health_url,
			[
				'timeout'     => 10,
				'redirection' => 0,
				'sslverify'   => false,
			]
		);

		return is_wp_error( $response ) ? self::STATUS_UNREACHABLE : $this->classify_response( $response );
	}

	/**
	 * Classify a connected health response by status before inspecting its body.
	 *
	 * @param array<string, mixed> $response WordPress HTTP API response.
	 * @return string One of STATUS_HEALTHY, STATUS_BROKEN, or STATUS_UNREACHABLE.
	 */
	private function classify_response( array $response ): string {
		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code >= 300 && $status_code < 500 ) {
			return self::STATUS_UNREACHABLE;
		}

		if ( $status_code >= 500 && $status_code < 600 ) {
			return self::STATUS_BROKEN;
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			return self::STATUS_UNREACHABLE;
		}

		return str_contains( wp_remote_retrieve_body( $response ), '"success":true' )
			? self::STATUS_HEALTHY
			: self::STATUS_BROKEN;
	}

	/**
	 * Get the current loopback health status.
	 *
	 * @return string One of STATUS_HEALTHY, STATUS_BROKEN, or STATUS_UNREACHABLE.
	 */
	public function get_status(): string {
		return $this->check();
	}

	/**
	 * Walk candidate loopback URLs until one succeeds, cache successful URLs,
	 * and retain only server-error responses as evidence of a broken site.
	 *
	 * Only 127.0.0.1 addresses are tried: the health endpoint rejects requests
	 * whose REMOTE_ADDR is not loopback, so home_url() would always fail there.
	 * Discovery caches only a full success response. Redirects and client errors
	 * are treated as unreachable because mapped-domain and proxy configurations can
	 * legitimately reject a public health request even while WordPress is healthy.
	 * A 5xx response remains authoritative evidence that the candidate connected
	 * but WordPress failed to boot.
	 *
	 * @return string|null The discovered health URL, or null if discovery failed.
	 */
	private function discover_health_url(): ?string {
		$cached = get_transient( 'sd_ai_agent_health_url' );
		if ( false !== $cached ) {
			return $cached;
		}

		$rest_url    = rest_url( 'sd-ai-agent/v1/_health' );
		$path        = wp_parse_url( $rest_url, PHP_URL_PATH );
		$path        = is_string( $path ) && '' !== $path ? $path : '/wp-json/sd-ai-agent/v1/_health';
		$query       = wp_parse_url( $rest_url, PHP_URL_QUERY );
		$query       = is_string( $query ) && '' !== $query ? '?' . $query : '';
		$server_port = isset( $_SERVER['SERVER_PORT'] ) ? (int) $_SERVER['SERVER_PORT'] : 80;

		$candidates = array_unique(
			[
				// WordPress's canonical REST URL for normal loopback-capable sites.
				$rest_url,
				// Port PHP is actually receiving requests on in this process.
				'http://127.0.0.1:' . $server_port . $path . $query,
				// Standard fallbacks.
				'http://127.0.0.1' . $path . $query,
				'http://127.0.0.1:8080' . $path . $query,
			]
		);

		$connected_url = null;

		foreach ( $candidates as $url ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
			$response = wp_remote_get(
				$url,
				[
					'timeout'     => 5,
					'redirection' => 0,
					'sslverify'   => false,
				]
			);
			if ( is_wp_error( $response ) ) {
				continue;
			}

			$status = $this->classify_response( $response );
			if ( self::STATUS_HEALTHY === $status ) {
				set_transient( 'sd_ai_agent_health_url', $url, HOUR_IN_SECONDS );
				return $url;
			}

			if ( self::STATUS_BROKEN === $status ) {
				$connected_url ??= $url;
			}
		}

		return $connected_url;
	}

	/**
	 * Convenience wrapper: true when the site is healthy.
	 *
	 * @return bool True if the site is healthy, false otherwise.
	 */
	public function verify(): bool {
		return self::STATUS_HEALTHY === $this->check();
	}

	/**
	 * Run the post-mutation health check and, on failure, invoke $undo to
	 * roll back the operation.
	 *
	 * Loopback-unreachable is treated as "can't tell" — the revert is skipped
	 * and null is returned rather than rolling back a perfectly good change.
	 *
	 * @param callable $undo    Callable that reverts the mutation. Returns true on success or WP_Error on failure.
	 * @param string   $context Human-readable label inserted into the error message
	 *                          (e.g. "File write", "Plugin activation").
	 * @return WP_Error|null    null when the site is healthy (or unverifiable);
	 *                          WP_Error when the site is confirmed broken.
	 */
	public function verify_or_revert( callable $undo, string $context ): ?WP_Error {
		$status = $this->check();

		if ( self::STATUS_HEALTHY === $status ) {
			return null;
		}

		if ( self::STATUS_UNREACHABLE === $status ) {
			// Loopback could not connect — networking issue, not a site error.
			return null;
		}

		$restored = $undo();
		$detail   = is_wp_error( $restored )
			? ' Restore also failed: ' . $restored->get_error_message()
			: ' Change reverted from backup.';

		return new WP_Error(
			'site_unhealthy',
			$context . ' caused a site error after being applied.' . $detail
		);
	}

	/**
	 * Detect-only variant for operations with no automatic undo (e.g. wp_cli/execute).
	 * Surfaces a loud message so the user can use WP core's Fatal Error Recovery Mode
	 * if wp-admin is no longer reachable.
	 *
	 * Loopback-unreachable is treated as "can't tell" — no warning is raised
	 * since the site may be perfectly fine.
	 *
	 * @param string $context Human-readable label inserted into the error message.
	 * @return WP_Error|null  null when the site is healthy (or unverifiable);
	 *                        WP_Error when the site is confirmed broken.
	 */
	public function verify_or_warn( string $context ): ?WP_Error {
		$status = $this->check();

		if ( self::STATUS_HEALTHY === $status ) {
			return null;
		}

		if ( self::STATUS_UNREACHABLE === $status ) {
			return null;
		}

		return new WP_Error(
			'site_unhealthy',
			$context . ' caused a site error and there is no automatic revert. ' .
			'If wp-admin is unreachable, check the admin email for a WordPress recovery-mode link.'
		);
	}
}
