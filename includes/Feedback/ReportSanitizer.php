<?php

declare(strict_types=1);
/**
 * Sender-side feedback report sanitizer.
 *
 * Strips credentials, absolute server paths, and other sensitive data from a
 * report payload before it leaves the site. This mirrors the defense-in-depth
 * sanitization that runs on the receiving side of the feedback system.
 *
 * @package SdAiAgent\Feedback
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Feedback;

class ReportSanitizer {

	/**
	 * Patterns that look like credentials or secrets.
	 *
	 * Keys are human-readable labels; values are regex patterns matched against
	 * message/tool-call content strings.
	 *
	 * @var array<string, string>
	 */
	private const CREDENTIAL_PATTERNS = array(
		'bearer_token'   => '/\bBearer\s+[A-Za-z0-9\-._~+\/]{20,}=*/i',
		'basic_auth'     => '/\bBasic\s+[A-Za-z0-9+\/]+=*/i',
		'api_key_param'  => '/\b(?:api[_-]?key|apikey|access[_-]?token|auth[_-]?token|secret[_-]?key|token)\s*[=:]\s*[^\s&"\']{8,}/i',
		'password_param' => '/\b(?:password|passwd|pwd)\s*[=:]\s*[^\s&"\']{3,}/i',
		'aws_key_id'     => '/\bAKIA[0-9A-Z]{16}\b/',
		'high_entropy'   => '/(?:(?<=[=:]\s)|\b)([A-Za-z0-9+\/_=-]{40,})(?=\b|\s|$)/',
		'openai_key'     => '/\bsk-(?:proj-)?[A-Za-z0-9_\-]{20,}\b/',
		'anthropic_key'  => '/\bsk-ant-[A-Za-z0-9\-]{20,}\b/',
		'jwt_token'      => '/\beyJ[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\b/',
	);

	/**
	 * Sanitize a complete report payload in-place.
	 *
	 * @param array<string, mixed> $payload Report payload from ReportBuilder::build().
	 * @return array<string, mixed> Sanitized copy — the original is not mutated.
	 */
	public static function sanitize( array $payload ): array {
		$session_data = $payload['session_data'] ?? null;
		if ( is_array( $session_data ) ) {
			if ( isset( $session_data['messages'] ) && is_array( $session_data['messages'] ) ) {
				/** @var array<int, array<string, mixed>> $messages */
				$messages                            = $session_data['messages'];
				$payload['session_data']['messages'] = self::sanitize_messages( $messages );
			}

			if ( isset( $session_data['session_messages'] ) && is_array( $session_data['session_messages'] ) ) {
				/** @var array<int, array<string, mixed>> $session_messages */
				$session_messages                            = $session_data['session_messages'];
				$payload['session_data']['session_messages'] = self::sanitize_messages( $session_messages );
			}

			if ( isset( $session_data['tool_calls'] ) && is_array( $session_data['tool_calls'] ) ) {
				/** @var array<int, array<string, mixed>> $tool_calls */
				$tool_calls                            = $session_data['tool_calls'];
				$payload['session_data']['tool_calls'] = self::sanitize_tool_calls( $tool_calls );
			}
		}

		if ( isset( $payload['user_description'] ) && is_string( $payload['user_description'] ) ) {
			$payload['user_description'] = self::sanitize_string( $payload['user_description'] );
		}

		return $payload;
	}

	/**
	 * Sanitize an array of chat messages.
	 *
	 * @param array<int, array<string, mixed>> $messages Messages to sanitize.
	 * @return array<int, array<string, mixed>> Sanitized messages.
	 */
	private static function sanitize_messages( array $messages ): array {
		return array_map(
			static function ( array $msg ): array {
				if ( is_string( $msg['content'] ?? null ) ) {
					$msg['content'] = self::sanitize_string( $msg['content'] );
				} elseif ( is_array( $msg['content'] ?? null ) ) {
					$msg['content'] = array_map(
						static function ( mixed $part ): mixed {
							if ( ! is_array( $part ) ) {
								return $part;
							}
							if ( is_string( $part['text'] ?? null ) ) {
								$part['text'] = self::sanitize_string( $part['text'] );
							}
							if ( is_string( $part['content'] ?? null ) ) {
								$part['content'] = self::sanitize_string( $part['content'] );
							}
							return $part;
						},
						$msg['content']
					);
				}
				return $msg;
			},
			$messages
		);
	}

	/**
	 * Sanitize tool call log entries.
	 *
	 * @param array<int, array<string, mixed>> $tool_calls Tool call entries to sanitize.
	 * @return array<int, array<string, mixed>> Sanitized entries.
	 */
	private static function sanitize_tool_calls( array $tool_calls ): array {
		return array_map(
			static function ( array $entry ): array {
				// Sanitize input arguments (may contain user-supplied data).
				if ( is_array( $entry['input'] ?? null ) ) {
					$entry['input'] = array_map(
						static function ( mixed $value ): mixed {
							return self::sanitize_value( $value );
						},
						$entry['input']
					);
				}

				// Sanitize tool result output.
				if ( is_string( $entry['result'] ?? null ) ) {
					$entry['result'] = self::sanitize_string( $entry['result'] );
				}

				return $entry;
			},
			$tool_calls
		);
	}

	/**
	 * Sanitize a mixed payload value recursively.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return mixed Sanitized value.
	 */
	private static function sanitize_value( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			return self::sanitize_string( $value );
		}

		if ( is_array( $value ) ) {
			return array_map(
				static function ( mixed $nested_value ): mixed {
					return self::sanitize_value( $nested_value );
				},
				$value
			);
		}

		return $value;
	}

	/**
	 * Sanitize a single string value.
	 *
	 * Replaces credential patterns and absolute server paths with redaction
	 * markers so the structure of the conversation is preserved for triage.
	 *
	 * @param string $value Input string.
	 * @return string Sanitized string.
	 */
	private static function sanitize_string( string $value ): string {
		// Redact credential patterns.
		foreach ( self::CREDENTIAL_PATTERNS as $label => $pattern ) {
			$value = (string) preg_replace( $pattern, "[REDACTED:{$label}]", $value );
		}

		// Redact absolute server file paths (Unix-style).
		// ABSPATH is always an absolute path in WordPress (e.g. /var/www/html/).
		$abspath = defined( 'ABSPATH' ) ? ABSPATH : '/var/www/';
		if ( '' !== $abspath ) {
			$escaped = preg_quote( $abspath, '/' );
			$value   = (string) preg_replace( '/' . $escaped . '[^\s\'"]+/i', '[REDACTED:server_path]', $value );
		}

		// Also redact common absolute paths even if they don't start with ABSPATH.
		$value = (string) preg_replace( '/\/(?:var|home|srv|etc|usr|opt|tmp)\/[^\s\'"<>]{3,}/i', '[REDACTED:server_path]', $value );

		return $value;
	}
}
