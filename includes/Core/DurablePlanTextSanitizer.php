<?php

declare(strict_types=1);
/**
 * Shared secret scrubber for compact durable-plan records and evidence.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DurablePlanTextSanitizer {

	public const REDACTED_PLACEHOLDER = '[redacted]';

	/**
	 * Strip markup, redact common credentials, and cap stored plan text.
	 */
	public static function sanitize( string $value, int $max_length ): string {
		$value = sanitize_textarea_field( wp_strip_all_tags( $value ) );

		$decoded = json_decode( $value, true );
		if ( is_array( $decoded ) ) {
			$encoded = wp_json_encode( self::redact_array( $decoded ) );
			if ( is_string( $encoded ) ) {
				$value = $encoded;
			}
		}

		// Preserve a consistently redacted JSON authorization field before the
		// generic text rules process the remaining credential labels.
		$value = (string) preg_replace( '/"authorization"\s*:\s*"(?:\\\\.|[^"\\\\])*"/i', '"authorization": "[redacted]"', $value );
		$value = (string) preg_replace( '/\b(authorization)\b\s*[:=]\s*(?:bearer\s+)?[^\s,;}\]]+/i', '$1: [redacted]', $value );
		$value = (string) preg_replace(
			'/(?:"|\')?(api[_-]?key|apikey|access[_-]?token|client[_-]?secret|credential(?:s)?|private[_-]?key|password|secret|token)(?:"|\')?\s*[:=]\s*(?:"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|[^\s,;}\]]+)/i',
			'$1: [redacted]',
			$value
		);
		$value = (string) preg_replace( '/\bBearer\s+[A-Za-z0-9._~+\/=:-]+/i', 'Bearer [redacted]', $value );

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max_length ) : substr( $value, 0, $max_length );
	}

	/**
	 * Recursively redact secret-bearing keys in a JSON document.
	 *
	 * @param array<string|int, mixed> $value JSON-decoded map.
	 * @return array<string|int, mixed>
	 */
	private static function redact_array( array $value ): array {
		foreach ( $value as $key => $item ) {
			if ( self::is_sensitive_key( (string) $key ) ) {
				$value[ $key ] = self::REDACTED_PLACEHOLDER;
				continue;
			}

			if ( is_array( $item ) ) {
				$value[ $key ] = self::redact_array( $item );
			} elseif ( is_string( $item ) ) {
				$value[ $key ] = (string) preg_replace( '/\bBearer\s+[A-Za-z0-9._~+\/=:-]+/i', 'Bearer [redacted]', $item );
			}
		}

		return $value;
	}

	/**
	 * Identify an exact key or a conventional compound key that holds a secret.
	 */
	private static function is_sensitive_key( string $key ): bool {
		return 1 === preg_match(
			'/(?:^|[_-])(?:api[_-]?key|apikey|access[_-]?token|authorization|auth|bearer|client[_-]?secret|credential(?:s)?|private[_-]?key|password|secret|token)(?:$|[_-])/i',
			$key
		);
	}
}
