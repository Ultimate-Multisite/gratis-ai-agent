<?php

declare(strict_types=1);
/**
 * Agent event log — emits a single greppable line to PHP `error_log` for
 * agent-loop failure modes that today are otherwise invisible outside the
 * per-subsite database.
 *
 * Goal: an operator running this plugin on a multisite install can
 * `tail -f wp-content/debug.log | grep '[Superdav AI Agent]'` and see
 * across every subsite where conversations went wrong (tool-iteration
 * limit, ability failures, provider HTTP errors, agent-loop aborts).
 *
 * Design notes:
 * - Static utility, mirrors {@see ChangeLogger}. No DI handler needed.
 * - Output format is stable, single-line, contains no secrets, and is
 *   independent of `WP_DEBUG` / `ProviderTrace::is_enabled()` so it works
 *   on production.
 * - Free-text fields are truncated; structured fields are caller-supplied.
 * - Fires `sd_ai_agent_event_logged` so external sinks (Sentry, Loki,
 *   syslog mu-plugin) can subscribe without us owning that integration.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AgentEventLog {

	/**
	 * Severity: error — agent failed in a way the user/operator should know about.
	 */
	const SEVERITY_ERROR = 'error';

	/**
	 * Severity: warning — abnormal but not fatal (e.g. iteration cap reached).
	 */
	const SEVERITY_WARNING = 'warning';

	/**
	 * Severity: info — diagnostic-only (default off; logged when callers ask for it).
	 */
	const SEVERITY_INFO = 'info';

	/**
	 * Maximum length of any free-text `message` field before truncation.
	 *
	 * Keeps log lines short and grep-friendly; full diagnostic context still
	 * lives in {@see ProviderTrace} (gated by `WP_DEBUG`) and the session row.
	 */
	const MAX_MESSAGE_LENGTH = 200;

	/**
	 * Maximum length of a redacted argument preview stored in event context.
	 */
	const MAX_PREVIEW_LENGTH = 2000;

	/**
	 * Replacement used when a known-sensitive field is redacted.
	 */
	const REDACTED_VALUE = '[redacted]';

	/**
	 * Whitelisted scalar context keys that are safe to inline into the log line.
	 *
	 * Anything not listed here is dropped. This is the single defence against
	 * accidentally logging request/response bodies, headers, or credentials.
	 *
	 * @var array<int, string>
	 */
	private const SAFE_CONTEXT_KEYS = array(
		'session_id',
		'agent_id',
		'job_id',
		'trace_id',
		'phase',
		'iterations',
		'iterations_used',
		'iterations_max',
		'provider_id',
		'model_id',
		'ability',
		'tool_call_id',
		'tool_name',
		'tool_count',
		'tool_call_count',
		'client_tool_count',
		'php_tool_count',
		'history_count',
		'code',
		'status_code',
		'reason',
		'attempts',
		'duration_ms',
		'args_hash',
		'args_keys',
		'args_preview',
		'result_hash',
		'result_preview',
		'pending_state_found',
		'paused_state_saved',
		'request_bytes',
		'request_bytes_estimate',
		'request_tokens_estimate',
		'request_budget_bytes',
		'request_size_class',
		'request_size_source',
		'local_rejection',
		'fallback_attempted',
		'payload_reduced',
		'correlation_id',
		'resume_count',
		'retryable',
		'next_action',
	);

	/**
	 * Currently-active session ID for events emitted during a loop run.
	 *
	 * Set by {@see AgentLoop} at the start of `run()` / resume entry points
	 * and cleared on exit, so call sites that don't have a session reference
	 * (e.g. {@see AbilityFunctionResolver}, {@see ProviderTraceLogger})
	 * can still attribute their events.
	 *
	 * @var int
	 */
	private static int $current_session_id = 0;

	/**
	 * Set the active session ID for subsequent {@see log()} calls.
	 *
	 * Mirrors the {@see ChangeLogger::begin()} pattern — paired with
	 * {@see clear_session()} so nested resume paths don't leak across runs.
	 *
	 * @param int $session_id Session ID (0 = unknown).
	 */
	public static function set_session( int $session_id ): void {
		self::$current_session_id = max( 0, $session_id );
	}

	/**
	 * Clear the active session ID.
	 */
	public static function clear_session(): void {
		self::$current_session_id = 0;
	}

	/**
	 * Get the active session ID.
	 *
	 * Test-only helper; production code should use {@see log()}.
	 *
	 * @return int
	 */
	public static function get_current_session_id(): int {
		return self::$current_session_id;
	}

	/**
	 * Emit a single agent-event log line.
	 *
	 * Output shape (single line, no trailing newline — `error_log()` adds it):
	 *
	 *   [Superdav AI Agent][site:42][session:123] event=tool_limit_reached severity=warning iterations_used=10 iterations_max=10
	 *
	 * `[site:N]` is omitted on single-site installs.
	 * `[session:N]` is omitted when no session context is available.
	 *
	 * @param string               $event    Event slug, e.g. `tool_limit_reached`.
	 * @param string               $severity One of {@see self::SEVERITY_ERROR}, `_WARNING`, `_INFO`.
	 * @param array<string, mixed> $context  Structured context. Only keys listed in
	 *                                       {@see SAFE_CONTEXT_KEYS} are emitted; other
	 *                                       keys (and any nested arrays) are dropped.
	 *                                       A `message` key is allowed and truncated to
	 *                                       {@see MAX_MESSAGE_LENGTH} chars.
	 */
	public static function log( string $event, string $severity, array $context = array() ): void {
		$event    = self::sanitize_token( $event );
		$severity = self::sanitize_severity( $severity );

		if ( '' === $event ) {
			return;
		}

		// Resolve session_id: explicit context wins, else thread-local default.
		$session_id = isset( $context['session_id'] )
			? (int) $context['session_id']
			: self::$current_session_id;

		// Build prefix: [Superdav AI Agent][site:N][session:N]
		$prefix = '[Superdav AI Agent]';

		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_current_blog_id' ) ) {
			$prefix .= '[site:' . (int) get_current_blog_id() . ']';
		}

		if ( $session_id > 0 ) {
			$prefix .= '[session:' . $session_id . ']';
		}

		// Build key=value pairs from whitelisted context.
		$pairs = array(
			'event=' . $event,
			'severity=' . $severity,
		);

		foreach ( self::SAFE_CONTEXT_KEYS as $key ) {
			if ( 'session_id' === $key ) {
				continue; // Already in prefix.
			}
			if ( ! array_key_exists( $key, $context ) ) {
				continue;
			}
			$value = self::format_value( $context[ $key ] );
			if ( '' === $value ) {
				continue;
			}
			$pairs[] = $key . '=' . $value;
		}

		// Free-text message: truncated, sanitised to keep it on one line.
		if ( isset( $context['message'] ) ) {
			$message = (string) $context['message'];
			if ( '' !== $message ) {
				$message = self::truncate_and_flatten( $message, self::MAX_MESSAGE_LENGTH );
				$pairs[] = 'message="' . $message . '"';
			}
		}

		$line = $prefix . ' ' . implode( ' ', $pairs );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational telemetry; this is the entire purpose of the class.
		error_log( $line );

		/**
		 * Fires after an agent event is written to `error_log`.
		 *
		 * External sinks (Sentry, Loki, syslog mu-plugin) can subscribe to
		 * forward events without us owning that integration.
		 *
		 * @param string               $event    Event slug.
		 * @param string               $severity Event severity.
		 * @param array<string, mixed> $context  Original (un-redacted) context as passed to log().
		 */
		do_action( 'sd_ai_agent_event_logged', $event, $severity, $context );
	}

	/**
	 * Build a deterministic short hash for a trace payload.
	 *
	 * The hash lets operators correlate identical failed tool arguments without
	 * persisting raw request bodies in the single-line PHP log.
	 *
	 * @param mixed $payload Payload to hash.
	 * @return string Twelve-character SHA-256 prefix, or empty string on encode failure.
	 */
	public static function payload_hash( $payload ): string {
		$encoded = wp_json_encode( $payload );
		if ( ! is_string( $encoded ) ) {
			return '';
		}

		return substr( hash( 'sha256', $encoded ), 0, 12 );
	}

	/**
	 * Return a redacted, bounded JSON preview for safe trace context.
	 *
	 * This is intentionally conservative: values under secret-looking keys are
	 * replaced, objects are converted to arrays, and the final JSON is capped.
	 * The network log sink stores this in `context` so an operator can understand
	 * what kind of tool input failed without exposing credentials.
	 *
	 * @param mixed $payload Payload to preview.
	 * @return string Redacted JSON preview, or empty string on encode failure.
	 */
	public static function payload_preview( $payload ): string {
		$redacted = self::redact_payload( $payload );
		$encoded  = wp_json_encode( $redacted );
		if ( ! is_string( $encoded ) ) {
			return '';
		}

		$encoded = self::truncate_and_flatten( $encoded, self::MAX_PREVIEW_LENGTH );
		return $encoded;
	}

	/**
	 * Redact recursively before persisting trace previews.
	 *
	 * @param mixed $payload Raw payload.
	 * @return mixed Redacted payload.
	 */
	private static function redact_payload( $payload ) {
		if ( $payload instanceof \stdClass ) {
			$payload = (array) $payload;
		}

		if ( is_array( $payload ) ) {
			$out = array();
			foreach ( $payload as $key => $value ) {
				$key_string = is_int( $key ) ? (string) $key : (string) $key;
				if ( self::is_sensitive_key( $key_string ) ) {
					$out[ $key ] = self::REDACTED_VALUE;
					continue;
				}

				$out[ $key ] = self::redact_payload( $value );
			}
			return $out;
		}

		if ( is_resource( $payload ) ) {
			return '[resource]';
		}

		return $payload;
	}

	/**
	 * Whether an array key is likely to contain credentials or secrets.
	 */
	private static function is_sensitive_key( string $key ): bool {
		return 1 === preg_match( '/(api[_-]?key|authorization|bearer|client[_-]?secret|credential|nonce|password|private[_-]?key|secret|token)/i', $key );
	}

	/**
	 * Validate a severity string; falls back to `error` for unknown input
	 * so a malformed call is still visible rather than silently dropped.
	 *
	 * @param string $severity Raw severity.
	 * @return string Sanitised severity.
	 */
	private static function sanitize_severity( string $severity ): string {
		$severity = strtolower( trim( $severity ) );
		if ( in_array( $severity, array( self::SEVERITY_ERROR, self::SEVERITY_WARNING, self::SEVERITY_INFO ), true ) ) {
			return $severity;
		}
		return self::SEVERITY_ERROR;
	}

	/**
	 * Sanitise an event-name token: lowercase ASCII alnum + underscore + hyphen.
	 *
	 * Prevents log-injection if a caller mistakenly passes user input.
	 *
	 * @param string $token Raw token.
	 * @return string Sanitised token (may be empty).
	 */
	private static function sanitize_token( string $token ): string {
		$token = strtolower( trim( $token ) );
		$token = preg_replace( '/[^a-z0-9_\-]/', '', $token );
		return is_string( $token ) ? $token : '';
	}

	/**
	 * Format a scalar context value for inclusion in the log line.
	 *
	 * Non-scalars are dropped (return ''). Strings have spaces and control
	 * characters stripped so each `key=value` pair stays tokenised.
	 *
	 * @param mixed $value Raw value.
	 * @return string Formatted value, or '' if unsuitable.
	 */
	private static function format_value( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		if ( ! is_string( $value ) ) {
			return '';
		}
		// Collapse whitespace, strip control chars, trim. Keep printable ASCII
		// + extended Latin so ability slugs (`sd-ai-agent/memory-save`),
		// model IDs (`claude-opus-4-7`) and provider IDs survive intact.
		$value = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value );
		$value = is_string( $value ) ? preg_replace( '/\s+/', ' ', $value ) : '';
		$value = is_string( $value ) ? trim( $value ) : '';
		// Cap at a sane length to keep log lines short.
		if ( strlen( $value ) > 120 ) {
			$value = substr( $value, 0, 120 );
		}
		return $value;
	}

	/**
	 * Truncate a free-text message to a hard length and flatten newlines so
	 * the emitted log line stays a single line.
	 *
	 * @param string $text Raw text.
	 * @param int    $max  Maximum length.
	 * @return string
	 */
	private static function truncate_and_flatten( string $text, int $max ): string {
		// Replace embedded quotes so the `message="…"` token round-trips.
		$text = str_replace( '"', "'", $text );
		// Flatten newlines/control chars to spaces.
		$text = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text );
		$text = is_string( $text ) ? preg_replace( '/\s+/', ' ', $text ) : '';
		$text = is_string( $text ) ? trim( $text ) : '';
		if ( strlen( $text ) > $max ) {
			$text = substr( $text, 0, $max - 1 ) . '…';
		}
		return $text;
	}
}
