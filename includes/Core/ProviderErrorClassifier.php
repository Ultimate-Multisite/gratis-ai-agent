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
