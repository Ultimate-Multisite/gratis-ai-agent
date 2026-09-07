<?php

declare(strict_types=1);

namespace SdAiAgent\Core;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes provider errors without retaining provider response content.
 */
final class ProviderErrorClassifier {

	/** Retryable upstream/network statuses. */
	public const RETRYABLE_STATUS_CODES = array( 408, 429, 500, 502, 503, 504 );

	/** Stable, prompt-free class for an upstream security-gateway rejection. */
	public const FAILURE_CLASS_GATEWAY_REJECTION = 'gateway_rejection';

	/** Maximum transient error evidence inspected for gateway classification. */
	private const GATEWAY_EVIDENCE_MAX_BYTES = 8192;

	/**
	 * Extract an HTTP status code from provider errors produced by SDK layers.
	 *
	 * @param WP_Error|\Throwable|null $error Provider error.
	 * @return int HTTP status code, or 0 when unavailable.
	 */
	public static function extract_status_code( $error ): int {
		if ( $error instanceof WP_Error ) {
			$code = $error->get_error_code();
			if ( is_numeric( $code ) ) {
				return (int) $code;
			}

			$data = $error->get_error_data();
			if ( is_array( $data ) ) {
				foreach ( array( 'status', 'status_code', 'code' ) as $key ) {
					if ( isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ) {
						return (int) $data[ $key ];
					}
				}
			}
		}

		if ( $error instanceof \Throwable ) {
			$code = $error->getCode();
			if ( $code >= 400 && $code <= 599 ) {
				return (int) $code;
			}
		}

		$message = self::get_message( $error );
		if ( preg_match( '/\((\d{3})\)|\bHTTP\s+(\d{3})\b|\bstatus\s*(?:code)?\s*[:=]?\s*(\d{3})\b/i', $message, $matches ) ) {
			foreach ( array_slice( $matches, 1 ) as $match ) {
				if ( '' !== $match ) {
					return (int) $match;
				}
			}
		}

		return 0;
	}

	/**
	 * Determine whether a provider failure is safe to retry once.
	 *
	 * @param WP_Error|\Throwable|null $error       Provider error.
	 * @param int                      $status_code HTTP status code, or 0 when unknown.
	 * @return bool Whether the failure is retryable.
	 */
	public static function is_retryable( $error, int $status_code = 0 ): bool {
		if ( 0 === $status_code ) {
			$status_code = self::extract_status_code( $error );
		}

		if ( self::is_gateway_rejection( $error, $status_code ) ) {
			return false;
		}

		if ( in_array( $status_code, self::RETRYABLE_STATUS_CODES, true ) ) {
			return true;
		}

		if ( $status_code >= 400 ) {
			return false;
		}

		$message = self::get_message( $error );
		if ( '' === $message ) {
			return false;
		}

		return (bool) preg_match( '/\b(timeout|timed out|connection reset|connection refused|network|cURL error|internal server error|bad gateway|service unavailable|gateway timeout|too many requests|rate limit)\b/i', $message );
	}

	/**
	 * Determine whether a provider error represents an unauthorized response.
	 *
	 * @param WP_Error|\Throwable|null $error       Provider error.
	 * @param int                      $status_code HTTP status code, or 0 when unknown.
	 * @return bool Whether the error is unauthorized.
	 */
	public static function is_unauthorized( $error, int $status_code = 0 ): bool {
		if ( 0 === $status_code ) {
			$status_code = self::extract_status_code( $error );
		}

		return 401 === $status_code || str_contains( self::get_message( $error ), 'Unauthorized (401)' );
	}

	/**
	 * Return a scrubbed category that is safe for REST and diagnostic output.
	 *
	 * @param WP_Error|\Throwable|null $error       Provider error.
	 * @param int                      $status_code HTTP status code, or 0 when unknown.
	 * @return string Normalized failure category.
	 */
	public static function get_failure_category( $error, int $status_code = 0 ): string {
		if ( 0 === $status_code ) {
			$status_code = self::extract_status_code( $error );
		}

		if ( self::is_unauthorized( $error, $status_code ) ) {
			return 'unauthorized';
		}

		if ( $status_code >= 500 ) {
			return 'upstream';
		}

		if ( $status_code >= 400 ) {
			return 'client';
		}

		if ( self::is_retryable( $error, $status_code ) ) {
			return 'transport';
		}

		if ( $error instanceof WP_Error ) {
			return 'wp_error';
		}

		return 'unknown';
	}

	/**
	 * Return a prompt-free error code suitable for persisted trace diagnostics.
	 *
	 * The source error message is used only to select a bounded code and must
	 * never be written to a trace row: connector and transport libraries can
	 * include request URLs, credentials, or provider response fragments.
	 *
	 * @param WP_Error|\Throwable|null $error       Provider error.
	 * @param int                      $status_code HTTP status code, or 0 when unknown.
	 * @return string Normalized safe diagnostic code.
	 */
	public static function get_safe_error_code( $error, int $status_code = 0 ): string {
		if ( 0 === $status_code ) {
			$status_code = self::extract_status_code( $error );
		}

		if ( self::is_gateway_rejection( $error, $status_code ) ) {
			return 'provider_gateway_rejection';
		}

		if ( 429 === $status_code ) {
			return 'provider_rate_limited';
		}
		if ( 408 === $status_code ) {
			return 'provider_timeout';
		}
		if ( $status_code >= 400 && $status_code <= 599 ) {
			return 'provider_http_' . $status_code;
		}

		$message = self::get_message( $error );
		if ( (bool) preg_match( '/\b(timeout|timed out|operation timed out)\b/i', $message ) ) {
			return 'provider_timeout';
		}
		if ( (bool) preg_match( '/\b(could not resolve|name or service not known|dns)\b/i', $message ) ) {
			return 'provider_dns_failure';
		}
		if ( (bool) preg_match( '/\b(connection reset|connection refused|could not connect|connect\(\)|network)\b/i', $message ) ) {
			return 'provider_connection_failure';
		}
		if ( $error instanceof WP_Error ) {
			return 'provider_wp_error';
		}
		if ( $error instanceof \Throwable ) {
			return 'provider_exception';
		}

		return 'provider_error';
	}

	/**
	 * Return the bounded gateway-rejection class, if transient error evidence
	 * identifies a known upstream security gateway.
	 *
	 * Provider messages may contain request content. This method uses them only
	 * while the error is in memory; callers must persist only the returned token.
	 *
	 * @param WP_Error|\Throwable|null $error       Provider error.
	 * @param int                      $status_code HTTP status code, or 0 when unavailable.
	 * @return string Safe failure class, or an empty string when unclassified.
	 */
	public static function get_safe_failure_class( $error, int $status_code = 0 ): string {
		if ( self::is_gateway_rejection( $error, $status_code ) ) {
			return self::FAILURE_CLASS_GATEWAY_REJECTION;
		}

		return '';
	}

	/**
	 * Determine whether a provider error identifies a security-gateway block.
	 *
	 * @param WP_Error|\Throwable|null $error       Provider error.
	 * @param int                      $status_code HTTP status code, or 0 when unavailable.
	 * @return bool Whether the error is a recognized gateway rejection.
	 */
	public static function is_gateway_rejection( $error, int $status_code = 0 ): bool {
		if ( $error instanceof WP_Error ) {
			$data = $error->get_error_data();
			if ( is_array( $data ) && self::FAILURE_CLASS_GATEWAY_REJECTION === ( $data['failure_class'] ?? '' ) ) {
				return true;
			}
		}

		if ( 0 === $status_code ) {
			$status_code = self::extract_status_code( $error );
		}

		return self::has_gateway_rejection_evidence( $status_code, self::get_message( $error ) );
	}

	/**
	 * Classify a raw HTTP response body without retaining it.
	 *
	 * @param int    $status_code   HTTP status code.
	 * @param string $response_body Transient response body.
	 * @return bool Whether the body identifies a recognized gateway rejection.
	 */
	public static function is_gateway_rejection_response( int $status_code, string $response_body ): bool {
		return self::has_gateway_rejection_evidence( $status_code, $response_body );
	}

	/**
	 * Inspect bounded transient evidence for a recognized WAF/gateway marker.
	 *
	 * The vendor-specific Imunify360 marker remains useful even when an SDK lost
	 * the HTTP status. Broader WAF phrases require a 4xx/5xx status so a provider
	 * cannot cause a false classification merely by echoing request text.
	 */
	private static function has_gateway_rejection_evidence( int $status_code, string $evidence ): bool {
		$evidence = strtolower( substr( $evidence, 0, self::GATEWAY_EVIDENCE_MAX_BYTES ) );
		if ( str_contains( $evidence, 'imunify360' ) ) {
			return true;
		}

		if ( $status_code < 400 || $status_code > 599 ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(?:web\s+application\s+firewall|security\s+gateway|waf\s+(?:block|denied)|blocked\s+by\s+(?:a\s+)?(?:waf|firewall)|access\s+denied\s+by\s+(?:a\s+)?(?:waf|firewall))\b/i',
			$evidence
		);
	}

	/**
	 * Return the provider error message only for local classification.
	 *
	 * Callers must never return or log this value.
	 *
	 * @param WP_Error|\Throwable|null $error Provider error.
	 * @return string Error message.
	 */
	private static function get_message( $error ): string {
		if ( $error instanceof WP_Error ) {
			return $error->get_error_message();
		}

		if ( $error instanceof \Throwable ) {
			return $error->getMessage();
		}

		return '';
	}
}
