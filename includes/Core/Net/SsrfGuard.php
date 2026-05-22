<?php

declare(strict_types=1);
/**
 * SSRF guard for URL sideload paths.
 *
 * Blocks requests to private, loopback, link-local, and cloud metadata
 * addresses. Performs DNS pre-resolution and checks every A/AAAA record
 * to mitigate TOCTOU rebinding attacks.
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
 * Validates URLs against SSRF attack vectors before HTTP requests.
 *
 * @since 1.9.0
 */
class SsrfGuard {

	/**
	 * Cloud metadata hostnames that must always be blocked.
	 *
	 * @var list<string>
	 */
	private const CLOUD_METADATA_HOSTS = [
		'169.254.169.254',          // AWS / GCP metadata endpoint.
		'metadata.google.internal', // GCP alternative.
		'metadata.google',          // GCP short name.
	];

	/**
	 * Default blocked CIDR ranges (IPv4).
	 *
	 * @var list<string>
	 */
	private const BLOCKED_IPV4_RANGES = [
		'0.0.0.0/8',       // "This" network (RFC 1122).
		'10.0.0.0/8',      // RFC 1918 private.
		'127.0.0.0/8',     // Loopback.
		'169.254.0.0/16',  // Link-local.
		'172.16.0.0/12',   // RFC 1918 private.
		'192.168.0.0/16',  // RFC 1918 private.
		'224.0.0.0/4',     // Multicast.
		'240.0.0.0/4',     // Reserved / broadcast.
	];

	/**
	 * Default blocked CIDR ranges (IPv6).
	 *
	 * @var list<string>
	 */
	private const BLOCKED_IPV6_RANGES = [
		'::1/128',     // Loopback.
		'fc00::/7',    // Unique local (ULA).
		'fe80::/10',   // Link-local.
		'ff00::/8',    // Multicast.
	];

	/**
	 * Validate that a URL is safe from SSRF vectors.
	 *
	 * Checks the hostname against known blocked hosts and ranges, then resolves
	 * DNS and re-checks each resulting IP to prevent TOCTOU rebinding.
	 *
	 * @since 1.9.0
	 *
	 * @param string $url URL to validate.
	 * @return true|\WP_Error True when safe, WP_Error with code 'ssrf_blocked' otherwise.
	 */
	public function assert_safe_url( string $url ): true|\WP_Error {
		$parsed = wp_parse_url( $url );

		if ( empty( $parsed['host'] ) ) {
			return new WP_Error(
				'ssrf_blocked',
				__( 'URL has no host component.', 'superdav-ai-agent' ),
				[ 'url' => $url ]
			);
		}

		$scheme = strtolower( $parsed['scheme'] ?? '' );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return new WP_Error(
				'ssrf_blocked',
				__( 'Only http and https schemes are allowed.', 'superdav-ai-agent' ),
				[
					'url'    => $url,
					'scheme' => $scheme,
				]
			);
		}

		$host = strtolower( $parsed['host'] );

		// Strip IPv6 brackets for checking.
		$bare_host = trim( $host, '[]' );

		// Block cloud metadata hostnames.
		if ( in_array( $bare_host, self::CLOUD_METADATA_HOSTS, true ) ) {
			return new WP_Error(
				'ssrf_blocked',
				__( 'Cloud metadata endpoint is blocked.', 'superdav-ai-agent' ),
				[
					'url'  => $url,
					'host' => $bare_host,
				]
			);
		}

		// Block 'localhost' by name.
		if ( 'localhost' === $bare_host ) {
			return new WP_Error(
				'ssrf_blocked',
				__( 'Localhost is blocked.', 'superdav-ai-agent' ),
				[
					'url'  => $url,
					'host' => $bare_host,
				]
			);
		}

		// If the host is already an IP literal, check it directly.
		if ( filter_var( $bare_host, FILTER_VALIDATE_IP ) ) {
			$result = $this->check_ip( $bare_host );
			if ( is_wp_error( $result ) ) {
				$result->add_data(
					[
						'url' => $url,
						'ip'  => $bare_host,
					]
					);
				return $result;
			}

			return true;
		}

		// Check if host is in the allow-list (skips IP range checks).
		/** @var list<string> */
		$allowed_hosts = apply_filters( 'sd_ai_agent_ssrf_allow_hosts', [] );
		if ( in_array( $bare_host, $allowed_hosts, true ) ) {
			return true;
		}

		// Resolve DNS and check every resulting IP.
		$ips = $this->resolve_host( $bare_host );
		if ( is_wp_error( $ips ) ) {
			return $ips;
		}

		foreach ( $ips as $ip ) {
			$result = $this->check_ip( $ip );
			if ( is_wp_error( $result ) ) {
				$result->add_data(
					[
						'url'  => $url,
						'ip'   => $ip,
						'host' => $bare_host,
					]
					);
				return $result;
			}
		}

		return true;
	}

	/**
	 * Check whether a single IP address falls within any blocked range.
	 *
	 * @since 1.9.0
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return true|\WP_Error True when safe, WP_Error when blocked.
	 */
	private function check_ip( string $ip ): true|\WP_Error {
		$blocked_ranges = $this->get_blocked_ranges();

		foreach ( $blocked_ranges as $cidr ) {
			if ( $this->ip_in_cidr( $ip, $cidr ) ) {
				return new WP_Error(
					'ssrf_blocked',
					/* translators: %s: CIDR range that matched */
					sprintf( __( 'IP address is in blocked range %s.', 'superdav-ai-agent' ), $cidr ),
					[
						'ip'   => $ip,
						'cidr' => $cidr,
					]
				);
			}
		}

		// Also check cloud metadata IPs directly.
		if ( in_array( $ip, self::CLOUD_METADATA_HOSTS, true ) ) {
			return new WP_Error(
				'ssrf_blocked',
				__( 'Cloud metadata IP is blocked.', 'superdav-ai-agent' ),
				[ 'ip' => $ip ]
			);
		}

		/**
		 * Filters whether a resolved IP should be allowed.
		 *
		 * Return a WP_Error to block, or true to allow. Runs after built-in
		 * range checks pass.
		 *
		 * @since 1.9.0
		 *
		 * @param true       $allowed Whether the IP is allowed.
		 * @param string     $ip      The resolved IP address.
		 */
		$filtered = apply_filters( 'sd_ai_agent_ssrf_check_ip', true, $ip );
		if ( is_wp_error( $filtered ) ) {
			return $filtered;
		}

		return true;
	}

	/**
	 * Get all blocked CIDR ranges, including any added via filter.
	 *
	 * @since 1.9.0
	 *
	 * @return list<string> Array of CIDR notation ranges.
	 */
	private function get_blocked_ranges(): array {
		$ranges = array_merge( self::BLOCKED_IPV4_RANGES, self::BLOCKED_IPV6_RANGES );

		/**
		 * Filters the list of blocked CIDR ranges for SSRF protection.
		 *
		 * @since 1.9.0
		 *
		 * @param list<string> $ranges Default blocked CIDR ranges.
		 */
		return apply_filters( 'sd_ai_agent_ssrf_blocked_ranges', $ranges );
	}

	/**
	 * Resolve a hostname to its A and AAAA records.
	 *
	 * @since 1.9.0
	 *
	 * @param string $host Hostname to resolve.
	 * @return list<string>|\WP_Error Array of IP addresses or WP_Error on failure.
	 */
	private function resolve_host( string $host ): array|\WP_Error {
		$records = $this->dns_get_record( $host );
		if ( is_wp_error( $records ) ) {
			return $records;
		}

		if ( empty( $records ) ) {
			return new WP_Error(
				'ssrf_blocked',
				/* translators: %s: hostname */
				sprintf( __( 'DNS resolution for %s returned no records.', 'superdav-ai-agent' ), $host ),
				[ 'host' => $host ]
			);
		}

		return $records;
	}

	/**
	 * Perform DNS record lookup. Extracted for testability.
	 *
	 * @since 1.9.0
	 *
	 * @param string $host Hostname.
	 * @return list<string>|\WP_Error IP addresses or WP_Error.
	 */
	protected function dns_get_record( string $host ): array|\WP_Error {
		$ips = [];

		// Try A records.
		$a_records = @dns_get_record( $host, DNS_A ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $a_records ) ) {
			foreach ( $a_records as $record ) {
				if ( ! empty( $record['ip'] ) ) {
					$ips[] = (string) $record['ip'];
				}
			}
		}

		// Try AAAA records.
		$aaaa_records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $aaaa_records ) ) {
			foreach ( $aaaa_records as $record ) {
				if ( ! empty( $record['ipv6'] ) ) {
					$ips[] = (string) $record['ipv6'];
				}
			}
		}

		if ( empty( $ips ) ) {
			// Fall back to gethostbyname for hosts that don't support dns_get_record.
			$resolved = gethostbyname( $host );
			if ( $resolved !== $host ) {
				$ips[] = $resolved;
			}
		}

		if ( empty( $ips ) ) {
			return new WP_Error(
				'ssrf_dns_failed',
				/* translators: %s: hostname */
				sprintf( __( 'Failed to resolve DNS for %s.', 'superdav-ai-agent' ), $host ),
				[ 'host' => $host ]
			);
		}

		/** @var list<string> */
		return array_values( array_unique( $ips ) );
	}

	/**
	 * Check if an IP address is within a CIDR range.
	 *
	 * @since 1.9.0
	 *
	 * @param string $ip   IP address to check.
	 * @param string $cidr CIDR notation (e.g. "10.0.0.0/8" or "::1/128").
	 * @return bool True if IP is within the CIDR range.
	 */
	private function ip_in_cidr( string $ip, string $cidr ): bool {
		if ( ! str_contains( $cidr, '/' ) ) {
			return $ip === $cidr;
		}

		[ $range_ip, $prefix_len ] = explode( '/', $cidr, 2 );
		$prefix_len                = (int) $prefix_len;

		$ip_bin    = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$range_bin = @inet_pton( $range_ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $ip_bin || false === $range_bin ) {
			return false;
		}

		// Must be same address family.
		if ( strlen( $ip_bin ) !== strlen( $range_bin ) ) {
			return false;
		}

		$byte_len   = strlen( $ip_bin );
		$full_bytes = intdiv( $prefix_len, 8 );
		$remainder  = $prefix_len % 8;

		// Compare full bytes.
		for ( $i = 0; $i < $full_bytes && $i < $byte_len; $i++ ) {
			if ( $ip_bin[ $i ] !== $range_bin[ $i ] ) {
				return false;
			}
		}

		// Compare remaining bits.
		if ( $remainder > 0 && $full_bytes < $byte_len ) {
			$mask = 0xFF << ( 8 - $remainder ) & 0xFF;
			if ( ( ord( $ip_bin[ $full_bytes ] ) & $mask ) !== ( ord( $range_bin[ $full_bytes ] ) & $mask ) ) {
				return false;
			}
		}

		return true;
	}
}
