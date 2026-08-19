<?php

declare(strict_types=1);
/**
 * Sanitizes conversation history for user-facing display surfaces.
 *
 * Provider adapters may preserve hidden reasoning as thought-channel message
 * parts so later provider requests can satisfy reasoning round-trip contracts.
 * Those parts must never be returned to the browser, exports, or transcript
 * renderers as visible assistant text.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

class ConversationDisplaySanitizer {

	/** Complete textual reasoning wrappers emitted in otherwise-content parts. */
	private const THINKING_BLOCK_PATTERN = '/<thinking\b[^>]*>.*?<\/thinking\s*>/is';

	/** An unfinished textual reasoning wrapper and the remainder of its content. */
	private const UNTERMINATED_THINKING_PATTERN = '/<thinking\b[^>]*>.*/is';

	/**
	 * Remove provider textual reasoning wrappers from browser-facing text.
	 *
	 * Structured thought-channel parts remain in the persisted conversation so
	 * providers that require reasoning round trips retain their continuation
	 * context. This method is deliberately for display projections only.
	 *
	 * @param string $text Provider-generated display text.
	 * @return string Text without complete or unfinished thinking blocks.
	 */
	public static function sanitize_display_text( string $text ): string {
		$text = preg_replace( self::THINKING_BLOCK_PATTERN, '', $text ) ?? '';

		return preg_replace( self::UNTERMINATED_THINKING_PATTERN, '', $text ) ?? '';
	}

	/**
	 * Sanitize textual activity messages returned with live job progress.
	 *
	 * @param array<mixed> $messages Job activity entries.
	 * @return list<array<string, mixed>> Display-safe activity entries.
	 */
	public static function sanitize_activity_messages( array $messages ): array {
		$sanitized = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$sanitized_message = array();
			foreach ( $message as $key => $value ) {
				if ( is_string( $key ) ) {
					$sanitized_message[ $key ] = 'text' === $key && is_string( $value )
						? self::sanitize_display_text( $value )
						: $value;
				}
			}

			$sanitized[] = $sanitized_message;
		}

		return $sanitized;
	}

	/**
	 * Strip hidden/non-content message parts from a list of serialized messages.
	 *
	 * Messages are kept even when all parts are removed so UI message indices stay
	 * aligned with the persisted conversation used by feedback/report endpoints.
	 *
	 * @param array<mixed> $messages Serialized conversation messages.
	 * @return list<array<string, mixed>> Display-safe messages.
	 */
	public static function sanitize_messages( array $messages ): array {
		$sanitized = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$sanitized_message = array();
			foreach ( $message as $key => $value ) {
				if ( is_string( $key ) ) {
					$sanitized_message[ $key ] = $value;
				}
			}

			$parts = $sanitized_message['parts'] ?? null;
			if ( is_array( $parts ) ) {
				$sanitized_message['parts'] = self::sanitize_parts( $parts );
			}

			$sanitized[] = $sanitized_message;
		}

		return $sanitized;
	}

	/**
	 * Extract visible text from one serialized message.
	 *
	 * @param array<string, mixed> $message Serialized conversation message.
	 * @return string Visible content-channel text.
	 */
	public static function extract_text( array $message ): string {
		$parts = $message['parts'] ?? null;
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$text = '';
		foreach ( self::sanitize_parts( $parts ) as $part ) {
			$part_text = $part['text'] ?? null;
			if ( is_string( $part_text ) ) {
				$text .= $part_text;
			}
		}

		return $text;
	}

	/**
	 * Strip hidden/non-content parts from serialized message parts.
	 *
	 * @param array<mixed> $parts Serialized message parts.
	 * @return list<array<string, mixed>> Display-safe message parts.
	 */
	private static function sanitize_parts( array $parts ): array {
		$sanitized = array();

		foreach ( $parts as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}

			$sanitized_part = array();
			foreach ( $part as $key => $value ) {
				if ( is_string( $key ) ) {
					$sanitized_part[ $key ] = $value;
				}
			}

			if ( ! self::is_content_channel( $sanitized_part['channel'] ?? null ) ) {
				continue;
			}

			if ( is_string( $sanitized_part['text'] ?? null ) ) {
				$sanitized_part['text'] = self::sanitize_display_text( $sanitized_part['text'] );
			}

			$sanitized[] = $sanitized_part;
		}

		return $sanitized;
	}

	/**
	 * Whether a serialized part belongs to the user-visible content channel.
	 *
	 * Older stored messages may omit the channel; the AI Client SDK defaults such
	 * parts to content, so missing/null/empty channel remains visible. Explicit
	 * `thought` and any future non-content channels are hidden.
	 *
	 * @param mixed $channel Serialized channel value.
	 */
	private static function is_content_channel( $channel ): bool {
		if ( null === $channel || '' === $channel ) {
			return true;
		}

		return is_string( $channel ) && 'content' === strtolower( $channel );
	}
}
