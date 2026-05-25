<?php

declare(strict_types=1);
/**
 * SSRF-hardened URL fetcher with DNS pinning and response processing.
 *
 * Fetches public URLs with comprehensive SSRF defences: scheme allowlist,
 * DNS pre-resolution + validation, DNS pinning via CURLOPT_RESOLVE,
 * redirect blocking, response-size caps, and HTML stripping.
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
 * Safe fetcher for AI-callable fetch-url ability.
 *
 * @since 1.9.0
 */
class SafeFetcher {

	/**
	 * Max response body size returned to the AI (100 KB).
	 */
	private const MAX_FETCH_BYTES = 102400;

	/**
	 * Hard cap on bytes downloaded from upstream before truncation logic runs (200 KB).
	 */
	private const MAX_FETCH_RESPONSE_BYTES = 204800;

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
	 * Fetch a public URL and return its text content.
	 *
	 * Security controls:
	 *  - Scheme must be http or https.
	 *  - Hostname is resolved to every A and AAAA record; each is checked
	 *    against private/loopback/link-local ranges before the request is made.
	 *  - The cURL handle is pinned to those validated IPs (CURLOPT_RESOLVE) so
	 *    a DNS-rebinding attacker cannot swap in a private IP between the SSRF
	 *    check and the actual fetch.
	 *  - Redirects are disabled; otherwise a public host could 302 to an
	 *    internal address that would not be re-validated.
	 *  - Response body is capped server-side (limit_response_size), then
	 *    stripped of HTML tags and truncated again before returning.
	 *
	 * @since 1.9.0
	 *
	 * @param string               $url  URL to fetch.
	 * @param array<string, mixed> $opts Optional fetch options (timeout, etc).
	 * @return string|WP_Error Response text or WP_Error.
	 */
	public function fetch( string $url, array $opts = [] ): string|WP_Error {
		// Basic format check.
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new WP_Error( 'invalid_url', __( 'Not a valid URL.', 'superdav-ai-agent' ) );
		}

		$parsed = wp_parse_url( $url );
		if ( ! $parsed ) {
			return new WP_Error( 'invalid_url', __( 'Could not parse URL.', 'superdav-ai-agent' ) );
		}

		// Scheme allow-list.
		$scheme = strtolower( $parsed['scheme'] ?? '' );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return new WP_Error(
				'invalid_scheme',
				sprintf(
					/* translators: %s: the invalid scheme */
					__( 'Only http and https URLs are allowed (got \'%s\').', 'superdav-ai-agent' ),
					$scheme
				)
			);
		}

		$host = $parsed['host'] ?? '';
		if ( '' === $host ) {
			return new WP_Error( 'invalid_url', __( 'URL has no host.', 'superdav-ai-agent' ) );
		}

		// Run SSRF guard check.
		$safe = $this->guard->assert_safe_url( $url );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		// Resolve every A and AAAA record and validate each against the
		// private-range allowlist. Returning the resolved set lets us pin
		// cURL's DNS resolution below, defeating rebinding attacks.
		$ips = $this->resolve_and_validate_host( $host );
		if ( is_wp_error( $ips ) ) {
			return $ips;
		}

		// Pin DNS resolution for this single request to the IPs we just
		// validated. Without this, wp_remote_get would re-resolve the
		// hostname inside cURL and a hostile DNS server could return a
		// different (private) address than the one we checked.
		//
		// The closure also re-checks the cURL handle's URL host before applying
		// CURLOPT_RESOLVE — if anything else triggers an outbound request while
		// the action is registered (filters firing nested wp_remote_*), we
		// must not redirect that unrelated request to our pinned IPs.
		$lookup_host = ltrim( rtrim( $host, ']' ), '[' );
		$port        = $parsed['port'] ?? ( 'https' === $scheme ? 443 : 80 );
		$resolve_arg = $lookup_host . ':' . (int) $port . ':' . implode( ',', $ips );
		$pin         = static function ( $handle, $r = null, $url_arg = null ) use ( $resolve_arg, $lookup_host ) {
			if ( ! is_resource( $handle ) && ! ( $handle instanceof \CurlHandle ) ) {
				return;
			}
			$handle_host = is_string( $url_arg ) ? wp_parse_url( $url_arg, PHP_URL_HOST ) : null;
			if ( null !== $handle_host ) {
				$handle_host = ltrim( rtrim( (string) $handle_host, ']' ), '[' );
				if ( 0 !== strcasecmp( $handle_host, $lookup_host ) ) {
					return;
				}
			}
			if ( is_resource( $handle ) || $handle instanceof \CurlHandle ) {
				/** @var \CurlHandle|resource $handle */
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- need raw cURL to set CURLOPT_RESOLVE; wp_remote_get does not expose this option
				curl_setopt( $handle, CURLOPT_RESOLVE, [ $resolve_arg ] );
			}
		};
		add_action( 'http_api_curl', $pin, 10, 3 );

		$timeout  = (int) ( $opts['timeout'] ?? 15 );
		$response = wp_remote_get(
			$url,
			[
				'timeout'             => $timeout,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_FETCH_RESPONSE_BYTES,
				'user-agent'          => 'Superdav-AI-Agent/1.0 (WordPress site; fetch_url ability)',
				'sslverify'           => true,
				'reject_unsafe_urls'  => true,
			]
		); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get

		remove_action( 'http_api_curl', $pin, 10 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 300 && $code < 400 ) {
			return new WP_Error(
				'fetch_redirect_blocked',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'URL returned HTTP %d (redirects are not followed; supply the final URL).', 'superdav-ai-agent' ),
					$code
				)
			);
		}
		if ( $code < 200 || $code >= 400 ) {
			return new WP_Error(
				'fetch_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'URL returned HTTP %d.', 'superdav-ai-agent' ),
					$code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );

		// Strip HTML tags; collapse whitespace; truncate.
		$text = wp_strip_all_tags( $body );
		$text = (string) preg_replace( '/\s{3,}/', "\n\n", $text ); // Collapse blank lines.
		$text = trim( $text );

		if ( strlen( $text ) > self::MAX_FETCH_BYTES ) {
			$text  = substr( $text, 0, self::MAX_FETCH_BYTES );
			$text .= "\n\n[…truncated at " . self::MAX_FETCH_BYTES . ' bytes]';
		}

		return $text;
	}

	/**
	 * Resolve $host to every A and AAAA address and ensure none are private.
	 *
	 * Returning the full list lets the caller pin cURL's resolver to exactly
	 * those IPs. gethostbyname() — which older implementations used —
	 * only returns a single IPv4, so a host with a private AAAA could slip
	 * through and a rebinding attacker could swap addresses between the check
	 * and the request.
	 *
	 * @since 1.9.0
	 *
	 * @param string $host Hostname to resolve.
	 * @return string[]|WP_Error Array of IP addresses or WP_Error.
	 */
	private function resolve_and_validate_host( string $host ): array|WP_Error {
		$lookup_host = ltrim( rtrim( $host, ']' ), '[' );

		// Bare IP — no DNS lookup, just validate directly.
		if ( filter_var( $lookup_host, FILTER_VALIDATE_IP ) ) {
			if ( $this->is_private_ip( $lookup_host ) ) {
				return new WP_Error(
					'ssrf_blocked',
					__( 'Requests to private/internal addresses are not allowed.', 'superdav-ai-agent' )
				);
			}
			return [ $lookup_host ];
		}

		// Single combined query for A and AAAA records — one DNS round-trip
		// instead of two. Suppress notices on resolution failure; we treat
		// empty result as failure below.
		$ips     = [];
		$records = @dns_get_record( $lookup_host, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $records ) ) {
			foreach ( $records as $rec ) {
				if ( ! empty( $rec['ip'] ) ) {
					$ips[] = (string) $rec['ip'];
				} elseif ( ! empty( $rec['ipv6'] ) ) {
					$ips[] = (string) $rec['ipv6'];
				}
			}
		}

		/** @var list<string> */
		$ips = array_values( array_unique( $ips ) );

		if ( empty( $ips ) ) {
			return new WP_Error(
				'dns_failure',
				sprintf(
					/* translators: %s: hostname */
					__( 'Could not resolve host \'%s\'.', 'superdav-ai-agent' ),
					$host
				)
			);
		}

		foreach ( $ips as $ip ) {
			if ( $this->is_private_ip( $ip ) ) {
				return new WP_Error(
					'ssrf_blocked',
					sprintf(
						/* translators: %s: IP address */
						__( 'Requests to private/internal addresses are not allowed (resolved to %s).', 'superdav-ai-agent' ),
						$ip
					)
				);
			}
		}

		return $ips;
	}

	/**
	 * Return true if $ip is a private, loopback, link-local, or reserved address.
	 * Handles both IPv4 and IPv6, since PHP's FILTER_FLAG_NO_RES_RANGE does not
	 * cover all IPv6 reserved ranges (e.g. ::1 loopback, fe80::/10 link-local).
	 *
	 * @since 1.9.0
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return bool True if the IP is private/reserved.
	 */
	private function is_private_ip( string $ip ): bool {
		// IPv4: FILTER_FLAG_NO_PRIV_RANGE blocks 10.x, 172.16-31.x, 192.168.x
		// FILTER_FLAG_NO_RES_RANGE  blocks 127.x, 0.x, 169.254.x, 240.x, etc.
		$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
		if ( filter_var( $ip, FILTER_VALIDATE_IP, $flags ) === false ) {
			return true;
		}

		// PHP's filter flags do not reliably cover IPv6 reserved ranges.
		// Explicitly block known private/reserved IPv6 ranges.
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );
			if ( false === $packed ) {
				return true; // Unparseable — treat as unsafe.
			}

			// ::1 — loopback.
			if ( inet_pton( '::1' ) === $packed ) {
				return true;
			}
			// ::ffff:0:0/96 — IPv4-mapped addresses (could map to private IPv4).
			if ( substr( $packed, 0, 12 ) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" ) {
				return true;
			}
			// fe80::/10 — link-local.
			if ( ( ord( $packed[0] ) & 0xc0 ) === 0x80 && ( ord( $packed[0] ) & 0xfe ) === 0xfe ) {
				return true;
			}
			// fc00::/7 — unique local (ULA), analogous to RFC-1918 for IPv6.
			if ( ( ord( $packed[0] ) & 0xfe ) === 0xfc ) {
				return true;
			}
		}

		return false;
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
}
