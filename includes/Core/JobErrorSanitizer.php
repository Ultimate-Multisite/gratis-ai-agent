<?php

declare(strict_types=1);
/**
 * Customer-safe summaries for low-level background-job failures.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use SdAiAgent\Abilities\OptionsAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes technical and secret-bearing fragments before job data is exposed.
 */
final class JobErrorSanitizer {

	/** Default maximum length for a customer-visible runtime value. */
	public const DEFAULT_MAX_LENGTH = 180;

	/**
	 * Return a compact, customer-safe summary, or an empty string when redaction
	 * removes all useful content.
	 */
	public static function sanitize( string $detail, int $max_length = self::DEFAULT_MAX_LENGTH ): string {
		$detail = trim( wp_strip_all_tags( $detail ) );
		if ( '' === $detail ) {
			return '';
		}

		foreach ( OptionsAbilities::get_secret_read_blocklist() as $secret_option ) {
			if ( preg_match( '/\b' . preg_quote( $secret_option, '/' ) . '\b/i', $detail ) ) {
				return '';
			}
		}

		$detail = preg_replace( '/#[0-9]+\s+[^;]+/', '[stack_trace]', $detail ) ?? $detail;
		$detail = preg_replace( '#\b(?:/[^\s;:]+){2,}(?::[0-9]+)?#', '[path]', $detail ) ?? $detail;
		$detail = preg_replace( '#\b[A-Za-z]:\\\\[^\s;]+#', '[path]', $detail ) ?? $detail;
		$detail = preg_replace( '/\b(?:api[_-]?key|token|secret|password|credential|authorization)\s*[:=]\s*[^\s;]+/i', '[redacted_credential]', $detail ) ?? $detail;
		$detail = preg_replace( '/\bsk-[A-Za-z0-9_-]{8,}\b/', '[redacted_token]', $detail ) ?? $detail;
		$detail = preg_replace( '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/', '[redacted_email]', $detail ) ?? $detail;
		$detail = sanitize_text_field( $detail );
		$detail = preg_replace( '/\s+/', ' ', $detail ) ?? $detail;
		$detail = trim( $detail );

		if ( '' === $detail || preg_match( '/^\[(?:path|stack_trace|redacted_credential|redacted_token|redacted_email)\]$/', $detail ) ) {
			return '';
		}

		$max_length = max( 1, $max_length );
		if ( strlen( $detail ) > $max_length ) {
			$detail = substr( $detail, 0, max( 1, $max_length - 3 ) ) . '...';
		}

		return $detail;
	}
}
