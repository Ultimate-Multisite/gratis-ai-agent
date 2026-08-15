<?php

declare(strict_types=1);
/**
 * Feedback report payload builder.
 *
 * Collects session messages, tool calls, token usage, model/provider IDs, and
 * environment information. The resulting array is passed to ReportSanitizer
 * before transmission.
 *
 * @package SdAiAgent\Feedback
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Feedback;

use SdAiAgent\Core\Database;

class ReportBuilder {

	/**
	 * Slice the messages array to the targeted message ± 2 surrounding messages.
	 *
	 * Used to scope thumbs-down reports to a relevant context window rather than
	 * sending the full conversation (t186).
	 *
	 * @param array<int, array<string, mixed>> $messages     Full messages array.
	 * @param int                              $message_index Zero-based index of the target message.
	 * @return array<int, array<string, mixed>> Sliced array (values re-indexed).
	 */
	private static function slice_message_context( array $messages, int $message_index ): array {
		$total = count( $messages );
		$start = max( 0, $message_index - 2 );
		$end   = min( $total - 1, $message_index + 2 );

		return array_values( array_slice( $messages, $start, $end - $start + 1 ) );
	}

	/**
	 * Keep only logged tool entries referenced by the scoped message context.
	 *
	 * Function-call and function-response parts share the same ID as their
	 * corresponding tool log entries. Matching by ID keeps each call/response
	 * pair together without leaking unrelated activity from the wider session.
	 * If the scoped messages contain no tool IDs, the targeted report omits the
	 * session-wide tool log rather than attaching misleading history.
	 *
	 * @param array<int, array<string, mixed>> $tool_calls Logged session tool entries.
	 * @param array<int, array<string, mixed>> $messages   Scoped messages array.
	 * @return array<int, array<string, mixed>> Matching tool entries, re-indexed.
	 */
	private static function scope_tool_calls_to_messages( array $tool_calls, array $messages ): array {
		$tool_ids = array();

		foreach ( $messages as $message ) {
			$parts = $message['parts'] ?? $message['content'] ?? array();
			if ( ! is_array( $parts ) ) {
				continue;
			}

			foreach ( $parts as $part ) {
				if ( ! is_array( $part ) ) {
					continue;
				}

				foreach ( array( 'functionCall', 'function_call', 'functionResponse', 'function_response' ) as $key ) {
					$tool_part = $part[ $key ] ?? null;
					if ( ! is_array( $tool_part ) || ! isset( $tool_part['id'] ) ) {
						continue;
					}

					$id = (string) $tool_part['id'];
					if ( '' !== $id ) {
						$tool_ids[ $id ] = true;
					}
				}
			}
		}

		return array_values(
			array_filter(
				$tool_calls,
				static function ( array $entry ) use ( $tool_ids ): bool {
					$id = isset( $entry['id'] ) ? (string) $entry['id'] : '';

					return '' !== $id && isset( $tool_ids[ $id ] );
				}
			)
		);
	}

	/**
	 * Build a feedback report payload from a session.
	 *
	 * @param int    $session_id        Session to report on.
	 * @param string $report_type       Caller-supplied category (e.g. 'user_reported').
	 * @param string $user_description  Optional free-text description from the user.
	 * @param bool   $strip_tool_results When true, tool result content is redacted but
	 *                                  tool names/arguments are retained.
	 * @param int    $message_index     When >= 0, only the targeted message ± 2
	 *                                  surrounding messages are included. Pass -1 to
	 *                                  include all messages (default).
	 * @return array<string, mixed>|null Structured payload or null when the session does
	 *                                  not exist or does not belong to the current user.
	 */
	public static function build(
		int $session_id,
		string $report_type,
		string $user_description = '',
		bool $strip_tool_results = false,
		int $message_index = -1
	): ?array {
		$current_user_id = get_current_user_id();
		$session         = Database::get_session( $session_id );

		if ( ! $session || (int) $session->user_id !== $current_user_id ) {
			return null;
		}

		$decoded_messages   = json_decode( $session->messages ?? '[]', true );
		$decoded_tool_calls = json_decode( $session->tool_calls ?? '[]', true );

		/** @var array<int, array<string, mixed>> $messages */
		$messages = is_array( $decoded_messages ) ? $decoded_messages : [];
		/** @var array<int, array<string, mixed>> $tool_calls */
		$tool_calls = is_array( $decoded_tool_calls ) ? $decoded_tool_calls : [];

		// Scope to a context window around the target message when requested (t186).
		if ( $message_index >= 0 ) {
			$messages   = self::slice_message_context( $messages, $message_index );
			$tool_calls = self::scope_tool_calls_to_messages( $tool_calls, $messages );
		}

		if ( $strip_tool_results ) {
			$tool_calls = self::strip_tool_results( $tool_calls );
			$messages   = self::strip_tool_result_messages( $messages );
		}

		$session_data = array(
			'id'                => $session_id,
			'title'             => $session->title ?? '',
			'provider_id'       => $session->provider_id ?? '',
			'model_id'          => $session->model_id ?? '',
			'prompt_tokens'     => (int) ( $session->prompt_tokens ?? 0 ),
			'completion_tokens' => (int) ( $session->completion_tokens ?? 0 ),
			'messages'          => $messages,
			'tool_calls'        => $tool_calls,
			'message_count'     => count( $messages ),
			'tool_call_count'   => count( $tool_calls ),
		);

		$environment = self::collect_environment();

		return array(
			'report_type'      => $report_type,
			'user_description' => $user_description,
			// Top-level fields for server-side indexing (model, provider, site).
			'model_id'         => $session->model_id ?? '',
			'provider_id'      => $session->provider_id ?? '',
			'site_url'         => $environment['site_host'] ?? '',
			'session_data'     => $session_data,
			'environment'      => $environment,
			'generated_at'     => gmdate( 'c' ),
		);
	}

	/**
	 * Build a lightweight summary (no message content) for the modal preview header.
	 *
	 * @param int  $session_id       Session to summarise.
	 * @param bool $strip_tool_results When true, reflect stripped count.
	 * @param int  $message_index    When >= 0, only count messages in the context window.
	 * @return array<string, mixed>|null Summary or null when the session is not found.
	 */
	public static function build_summary( int $session_id, bool $strip_tool_results = false, int $message_index = -1 ): ?array {
		$current_user_id = get_current_user_id();
		$session         = Database::get_session( $session_id );

		if ( ! $session || (int) $session->user_id !== $current_user_id ) {
			return null;
		}

		$decoded_messages   = json_decode( $session->messages ?? '[]', true );
		$decoded_tool_calls = json_decode( $session->tool_calls ?? '[]', true );
		$messages           = is_array( $decoded_messages ) ? $decoded_messages : [];
		$tool_calls         = is_array( $decoded_tool_calls ) ? $decoded_tool_calls : [];

		// Scope both counts to the same context window used by the report payload.
		if ( $message_index >= 0 ) {
			$messages   = self::slice_message_context( $messages, $message_index );
			$tool_calls = self::scope_tool_calls_to_messages( $tool_calls, $messages );
		}

		return array(
			'message_count'      => count( $messages ),
			'tool_call_count'    => count( $tool_calls ),
			'strip_tool_results' => $strip_tool_results,
			'environment_keys'   => array_keys( self::collect_environment() ),
			'model_id'           => $session->model_id ?? '',
			'provider_id'        => $session->provider_id ?? '',
		);
	}

	/**
	 * Collect safe environment metadata.
	 *
	 * Only allowlisted keys are included — no credentials, no file paths, no PII.
	 *
	 * @return array<string, mixed>
	 */
	private static function collect_environment(): array {
		$plugin_version = defined( 'SD_AI_AGENT_VERSION' ) ? SD_AI_AGENT_VERSION : '';

		// Active plugins: folder slug only, no paths.
		$raw_plugins    = get_option( 'active_plugins', [] );
		$active_plugins = array_map(
			static function ( string $plugin_path ): string {
				return (string) strtok( $plugin_path, '/' );
			},
			is_array( $raw_plugins ) ? $raw_plugins : []
		);

		// Site URL: scheme + host only, no path.
		$site_url  = get_site_url();
		$parsed    = wp_parse_url( $site_url );
		$site_host = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' );

		return array(
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'plugin_version' => $plugin_version,
			'theme'          => get_stylesheet(),
			'site_locale'    => get_locale(),
			'is_multisite'   => is_multisite(),
			'site_host'      => $site_host,
			'active_plugins' => $active_plugins,
		);
	}

	/**
	 * Redact tool result content from a tool_calls array.
	 *
	 * Tool names and arguments are preserved so the triage engineer can see
	 * what was attempted; only the response content is replaced.
	 *
	 * @param array<int, array<string, mixed>> $tool_calls Original tool call log.
	 * @return array<int, array<string, mixed>> Sanitized copy.
	 */
	private static function strip_tool_results( array $tool_calls ): array {
		return array_map(
			static function ( array $entry ): array {
				if ( isset( $entry['result'] ) ) {
					$entry['result'] = '[redacted — strip_tool_results enabled]';
				}
				return $entry;
			},
			$tool_calls
		);
	}

	/**
	 * Redact tool_result role messages from the messages array.
	 *
	 * @param array<int, array<string, mixed>> $messages Original messages.
	 * @return array<int, array<string, mixed>> Messages with tool_result content redacted.
	 */
	private static function strip_tool_result_messages( array $messages ): array {
		return array_map(
			static function ( array $msg ): array {
				if ( ( $msg['role'] ?? '' ) !== 'tool' ) {
					return $msg;
				}

				if ( is_array( $msg['content'] ?? null ) ) {
					$msg['content'] = array_map(
						static function ( mixed $part ): mixed {
							if ( ! is_array( $part ) ) {
								return $part;
							}
							if ( ( $part['type'] ?? '' ) === 'tool_result' ) {
								$part['content'] = '[redacted — strip_tool_results enabled]';
							}
							return $part;
						},
						$msg['content']
					);
				} elseif ( is_string( $msg['content'] ?? null ) ) {
					$msg['content'] = '[redacted — strip_tool_results enabled]';
				}

				return $msg;
			},
			$messages
		);
	}
}
