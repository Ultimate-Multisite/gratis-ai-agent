<?php
/**
 * Provider Trace Logger — hooks into WordPress HTTP API to capture LLM provider traffic.
 *
 * Hooks `pre_http_request` to record outgoing request details and `http_response`
 * to capture the corresponding response. Only logs requests to known AI provider
 * endpoints when tracing is enabled.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Core;

use SdAiAgent\Core\AgentEventLog;
use SdAiAgent\Core\PromptCache\CacheUsageExtractor;
use SdAiAgent\Models\ProviderTrace;

/**
 * Prevents direct access to the file.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProviderTraceLogger {

	/**
	 * In-flight request data keyed by URL for correlation.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $inflight = [];

	/**
	 * Known AI provider URL patterns and their canonical provider IDs.
	 *
	 * These IDs are used by the lightweight error-log path that runs even
	 * when tracing is disabled, and by the trace UI's provider filter to
	 * group rows under stable provider names. Connector plugins that proxy
	 * to other backends (HuggingFace Inference, OpenRouter, custom OpenAI-
	 * compatible endpoints, etc.) are matched by host heuristic in
	 * {@see self::resolve_provider_for_trace()} when tracing is enabled.
	 *
	 * @var array<string, string>
	 */
	private static array $provider_patterns = [
		'api.anthropic.com'                 => 'anthropic',
		'api.openai.com'                    => 'openai',
		'generativelanguage.googleapis.com' => 'google',
		'localhost:11434'                   => 'ollama',
		'127.0.0.1:11434'                   => 'ollama',
	];

	/**
	 * URL path fragments that indicate an LLM/inference endpoint.
	 *
	 * Used only when tracing is explicitly enabled — see
	 * {@see self::resolve_provider_for_trace()}. Matched case-insensitively
	 * against the URL path so connector-plugin endpoints that forward to
	 * arbitrary OpenAI-compatible hosts still get captured.
	 *
	 * Kept deliberately conservative: catching `/chat/completions`,
	 * `/completions`, `/messages`, `/responses`, `/embeddings`,
	 * `/generateContent`, `/generate`, `/predict`, plus the HuggingFace
	 * Inference path `/models/` covers every shape the connector plugins
	 * we ship with use today, without sweeping in unrelated REST traffic.
	 *
	 * @var list<string>
	 */
	private static array $llm_path_fragments = [
		'/chat/completions',
		'/completions',
		'/messages',
		'/responses',
		'/embeddings',
		'/generatecontent',
		'/generate',
		'/predict',
		'/models/',
	];

	/**
	 * Register WordPress hooks for HTTP traffic capture.
	 */
	public static function register(): void {
		add_filter( 'pre_http_request', [ self::class, 'on_pre_http_request' ], 10, 3 );
		add_filter( 'http_response', [ self::class, 'on_http_response' ], 10, 3 );
	}

	/**
	 * Hook: pre_http_request — capture outgoing request details.
	 *
	 * @param false|array<string, mixed>|\WP_Error $response    A preemptive return value of an HTTP request. Default false.
	 * @param array<string, mixed>                 $parsed_args HTTP request arguments.
	 * @param string                               $url         The request URL.
	 * @return false|array<string, mixed>|\WP_Error Unchanged response (we never short-circuit).
	 */
	public static function on_pre_http_request( $response, array $parsed_args, string $url ) {
		if ( ! ProviderTrace::is_enabled() ) {
			return $response;
		}

		$request_body = is_string( $parsed_args['body'] ?? null )
			? $parsed_args['body']
			: (string) wp_json_encode( $parsed_args['body'] ?? '' );

		// When tracing is enabled we cast a wider net than the canonical
		// allowlist so connector plugins that proxy to HuggingFace,
		// OpenRouter, custom OpenAI-compatible endpoints, etc. are also
		// captured. The error-log path on `on_http_response()` still uses
		// the strict allowlist so we don't spam logs for unrelated 4xx
		// responses.
		$provider_id = self::resolve_provider_for_trace( $url, $request_body );
		if ( '' === $provider_id ) {
			return $response;
		}

		// Store in-flight data for correlation with the response.
		self::$inflight[ $url ] = [
			'provider_id'     => $provider_id,
			'url'             => $url,
			'method'          => strtoupper( $parsed_args['method'] ?? 'POST' ),
			'request_headers' => self::extract_headers( $parsed_args['headers'] ?? [] ),
			'request_body'    => $request_body,
			'start_time'      => microtime( true ),
		];

		return $response;
	}

	/**
	 * Hook: http_response — capture response and write trace record.
	 *
	 * Two-tier logging:
	 * - When {@see ProviderTrace::is_enabled()} (debug mode), the full
	 *   request/response is written to the `provider_trace` DB table.
	 * - When the response is a 4xx/5xx, a single greppable line is emitted
	 *   to PHP `error_log` via {@see AgentEventLog} **regardless of debug
	 *   mode**, so operators on production multisite installs can still
	 *   diagnose provider issues without enabling `WP_DEBUG`.
	 *
	 * @param array<string, mixed> $response    HTTP response array.
	 * @param array<string, mixed> $parsed_args HTTP request arguments.
	 * @param string               $url         The request URL.
	 * @return array<string, mixed> Unchanged response.
	 */
	public static function on_http_response( array $response, array $parsed_args, string $url ): array {
		// Lightweight error-log path: emit a greppable line for 4xx/5xx
		// responses from canonical AI providers regardless of debug mode.
		// Uses the strict allowlist so unrelated 4xx responses (update
		// checks, WP.org, etc.) never produce noise here.
		$canonical_provider_id = self::match_provider( $url );
		if ( '' !== $canonical_provider_id ) {
			$status_code_for_log = (int) wp_remote_retrieve_response_code( $response );
			if ( $status_code_for_log >= 400 ) {
				$model_id_for_log = '';
				if ( isset( $parsed_args['body'] ) ) {
					$body_for_log     = is_string( $parsed_args['body'] )
						? $parsed_args['body']
						: (string) wp_json_encode( $parsed_args['body'] );
					$model_id_for_log = self::extract_model_id( $body_for_log );
				}

				AgentEventLog::log(
					'provider_http_error',
					AgentEventLog::SEVERITY_ERROR,
					array(
						'provider_id' => $canonical_provider_id,
						'model_id'    => $model_id_for_log,
						'status_code' => $status_code_for_log,
					)
				);
			}
		}

		if ( ! ProviderTrace::is_enabled() ) {
			return $response;
		}

		// Trace persistence path: look up the in-flight entry recorded by
		// `on_pre_http_request()`. Presence of the entry means the broader
		// `resolve_provider_for_trace()` matcher already approved the URL;
		// absence means this request is not an LLM call we care about.
		if ( ! isset( self::$inflight[ $url ] ) ) {
			return $response;
		}

		$inflight = self::$inflight[ $url ];
		unset( self::$inflight[ $url ] );

		$start_time  = (float) ( $inflight['start_time'] ?? microtime( true ) );
		$duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );

		$status_code      = (int) wp_remote_retrieve_response_code( $response );
		$response_body    = wp_remote_retrieve_body( $response );
		$response_headers = wp_remote_retrieve_headers( $response );

		// Extract model_id from request body if possible.
		$model_id = self::extract_model_id( $inflight['request_body'] ?? '' );

		$decoded_response = null;
		if ( $status_code >= 200 && $status_code < 300 ) {
			$decoded_response = json_decode( $response_body, true );
		}

		// Detect errors and provider-side truncation events.
		$error = '';
		if ( $status_code < 200 || $status_code >= 300 ) {
			$decoded = json_decode( $response_body, true );
			if ( is_array( $decoded ) ) {
				// Anthropic error format.
				if ( isset( $decoded['error']['message'] ) ) {
					$error = (string) $decoded['error']['message'];
				} elseif ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
					// OpenAI error format.
					$error = $decoded['error'];
				}
			}
			if ( '' === $error ) {
				$error = "HTTP {$status_code}";
			}
		} else {
			$classification = self::classify_truncation( $decoded_response );
			if ( '' !== $classification ) {
				$error = $classification;
			}
		}

		// Format response headers as JSON.
		$response_headers_json = '{}';
		if ( $response_headers instanceof \WpOrg\Requests\Utility\CaseInsensitiveDictionary
			|| ( class_exists( 'Requests_Utility_CaseInsensitiveDictionary' ) && $response_headers instanceof \Requests_Utility_CaseInsensitiveDictionary )
		) {
			$response_headers_json = (string) wp_json_encode( $response_headers->getAll() );
		} elseif ( is_array( $response_headers ) ) {
			$response_headers_json = (string) wp_json_encode( $response_headers );
		}

		// Extract provider-agnostic cache token counts from the response
		// usage block (Anthropic / OpenAI / DeepSeek / Google all use
		// different field names — see CacheUsageExtractor). Returns
		// zeroes on error responses or providers that don't report.
		$cache_tokens = array(
			'creation' => 0,
			'read'     => 0,
		);
		if ( $status_code >= 200 && $status_code < 300 ) {
			$cache_tokens = CacheUsageExtractor::extract(
				(string) ( $inflight['provider_id'] ?? '' ),
				$decoded_response
			);
		}

		ProviderTrace::insert(
			[
				'provider_id'           => $inflight['provider_id'] ?? '',
				'model_id'              => $model_id,
				'url'                   => $inflight['url'] ?? $url,
				'method'                => $inflight['method'] ?? 'POST',
				'status_code'           => $status_code,
				'duration_ms'           => $duration_ms,
				'cache_creation_tokens' => $cache_tokens['creation'],
				'cache_read_tokens'     => $cache_tokens['read'],
				'request_headers'       => $inflight['request_headers'] ?? '{}',
				'request_body'          => $inflight['request_body'] ?? '',
				'response_headers'      => $response_headers_json,
				'response_body'         => $response_body,
				'error'                 => $error,
			]
		);

		return $response;
	}

	/**
	 * Match a URL against known AI provider patterns.
	 *
	 * @param string $url The request URL.
	 * @return string Provider ID or empty string if no match.
	 */
	public static function match_provider( string $url ): string {
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return '';
		}

		$host = strtolower( $parsed['host'] );
		$port = $parsed['port'] ?? null;

		// Check host:port combinations first (for local services like Ollama).
		if ( null !== $port ) {
			$host_port = $host . ':' . $port;
			if ( isset( self::$provider_patterns[ $host_port ] ) ) {
				return self::$provider_patterns[ $host_port ];
			}
		}

		// Check host-only patterns.
		foreach ( self::$provider_patterns as $pattern => $provider_id ) {
			if ( str_contains( $pattern, ':' ) ) {
				continue; // Skip host:port patterns already checked.
			}
			if ( $host === $pattern || str_ends_with( $host, '.' . $pattern ) ) {
				return $provider_id;
			}
		}

		/**
		 * Filter to add custom provider URL patterns.
		 *
		 * @param string $provider_id The matched provider ID (empty if no match).
		 * @param string $url         The request URL.
		 * @param string $host        The parsed hostname.
		 */
		return (string) apply_filters( 'sd_ai_agent_trace_match_provider', '', $url, $host );
	}

	/**
	 * Resolve a provider ID for a request when tracing is enabled.
	 *
	 * Wider matcher than {@see self::match_provider()}. Used only when
	 * provider tracing is explicitly enabled by the operator, so capturing
	 * a non-LLM HTTP request occasionally is preferable to silently
	 * dropping a stalled Kimi / OpenRouter / HuggingFace call.
	 *
	 * Resolution precedence:
	 *   1. Canonical pattern match (`anthropic`, `openai`, `google`, `ollama`).
	 *   2. The `sd_ai_agent_trace_match_provider` filter (extension point).
	 *   3. Heuristic: the URL path matches a known LLM endpoint fragment
	 *      (`/chat/completions`, `/messages`, `/generateContent`, etc.)
	 *      OR the JSON body contains a `model` field. When matched, the
	 *      provider ID is derived from the hostname as
	 *      `host:<hostname>` so rows from the same backend group together
	 *      in the trace UI without colliding with canonical IDs.
	 *
	 * A final filter `sd_ai_agent_trace_resolve_provider` allows operators
	 * to override the resolved ID or veto a match entirely by returning
	 * an empty string.
	 *
	 * @param string $url  The request URL.
	 * @param string $body The (already-stringified) request body.
	 * @return string Provider ID, or empty string when the request should not be traced.
	 */
	public static function resolve_provider_for_trace( string $url, string $body ): string {
		// Step 1 + 2: canonical pattern match (includes filter hook).
		$canonical = self::match_provider( $url );
		if ( '' !== $canonical ) {
			return self::apply_resolve_filter( $canonical, $url, $body );
		}

		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return '';
		}

		$host = strtolower( (string) $parsed['host'] );
		$path = strtolower( (string) ( $parsed['path'] ?? '' ) );

		// Step 3a: path heuristic.
		$path_match = false;
		foreach ( self::$llm_path_fragments as $fragment ) {
			if ( '' !== $fragment && str_contains( $path, $fragment ) ) {
				$path_match = true;
				break;
			}
		}

		// Step 3b: body heuristic — JSON body with a `model` field is a
		// near-certain LLM call regardless of endpoint shape.
		$body_match = false;
		if ( '' !== $body ) {
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) && array_key_exists( 'model', $decoded ) ) {
				$body_match = true;
			}
		}

		if ( ! $path_match && ! $body_match ) {
			return self::apply_resolve_filter( '', $url, $body );
		}

		$derived = 'host:' . $host;
		return self::apply_resolve_filter( $derived, $url, $body );
	}

	/**
	 * Apply the resolve filter consistently across return paths.
	 *
	 * @param string $provider_id Provider ID resolved by the matcher (may be empty).
	 * @param string $url         The request URL.
	 * @param string $body        Request body string.
	 * @return string Possibly-overridden provider ID.
	 */
	private static function apply_resolve_filter( string $provider_id, string $url, string $body ): string {
		/**
		 * Filter to override the resolved provider ID for tracing, or to
		 * veto a match entirely by returning an empty string.
		 *
		 * Runs after the canonical allowlist, the legacy
		 * `sd_ai_agent_trace_match_provider` filter, and the path/body
		 * heuristics. Receives the URL and request body so operators can
		 * inspect either when deciding.
		 *
		 * @param string $provider_id The resolved provider ID (may be empty).
		 * @param string $url         The request URL.
		 * @param string $body        The request body (JSON string when applicable).
		 */
		return (string) apply_filters( 'sd_ai_agent_trace_resolve_provider', $provider_id, $url, $body );
	}

	/**
	 * Extract headers from the parsed args format to a JSON string.
	 *
	 * @param mixed $headers Headers array or string.
	 * @return string JSON-encoded headers.
	 */
	private static function extract_headers( $headers ): string {
		if ( is_string( $headers ) ) {
			return $headers;
		}

		if ( ! is_array( $headers ) ) {
			return '{}';
		}

		$result = wp_json_encode( $headers );
		return false !== $result ? $result : '{}';
	}

	/**
	 * Classify a successful provider response that ended at the output cap.
	 *
	 * Returns one of:
	 *   - 'truncated_tool_call'        : finish=length AND a tool call had started
	 *                                    (its JSON arguments are incomplete and unsafe).
	 *   - 'truncated_before_tool_call' : finish=length AND no tool call AND the
	 *                                    assistant emitted some text (a preamble
	 *                                    that exhausted the cap before a tool
	 *                                    call could begin — the model wanted to
	 *                                    continue but couldn't).
	 *   - ''                           : no truncation event of interest.
	 *
	 * @param mixed $decoded Decoded JSON response body.
	 */
	public static function classify_truncation( $decoded ): string {
		if ( ! is_array( $decoded ) ) {
			return '';
		}

		$candidates = [];
		if ( isset( $decoded['choices'] ) && is_array( $decoded['choices'] ) ) {
			$candidates = $decoded['choices'];
		} elseif ( isset( $decoded['candidates'] ) && is_array( $decoded['candidates'] ) ) {
			$candidates = $decoded['candidates'];
		} elseif ( isset( $decoded['content'] ) && is_array( $decoded['content'] ) ) {
			$candidates = [ $decoded ];
		}

		$saw_preamble_truncation = false;

		foreach ( $candidates as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$reason = self::extract_finish_reason( $candidate );
			if ( ! in_array( $reason, [ 'max_tokens', 'length', 'max_output_tokens' ], true ) ) {
				continue;
			}

			// Partial tool call wins outright — its arguments JSON is unsafe to execute.
			if ( self::candidate_has_tool_call( $candidate ) ) {
				return 'truncated_tool_call';
			}

			// No tool call, but the model emitted *some* text before hitting the cap.
			// This is the Kimi-style preamble-only stall: model wanted to continue
			// but ran out of output budget before opening a tool call.
			if ( self::candidate_has_text( $candidate ) ) {
				$saw_preamble_truncation = true;
			}
		}

		return $saw_preamble_truncation ? 'truncated_before_tool_call' : '';
	}

	/**
	 * Extract a normalized finish reason from a provider response candidate.
	 *
	 * @param array<string, mixed> $candidate Provider response candidate.
	 */
	private static function extract_finish_reason( array $candidate ): string {
		foreach ( [ 'finish_reason', 'stop_reason', 'finishReason' ] as $key ) {
			$reason = $candidate[ $key ] ?? null;
			if ( is_string( $reason ) && '' !== $reason ) {
				return strtolower( str_replace( [ '-', ' ' ], '_', $reason ) );
			}
		}

		return '';
	}

	/**
	 * Detect common OpenAI, Anthropic, and Gemini tool-call payload shapes.
	 *
	 * @param array<string, mixed> $candidate Provider response candidate.
	 */
	private static function candidate_has_tool_call( array $candidate ): bool {
		$message = $candidate['message'] ?? [];
		if ( is_array( $message ) && ! empty( $message['tool_calls'] ) ) {
			return true;
		}

		$content = $candidate['content'] ?? ( is_array( $message ) ? ( $message['content'] ?? [] ) : [] );
		if ( is_array( $content ) ) {
			foreach ( $content as $part ) {
				if ( is_array( $part ) && in_array( $part['type'] ?? '', [ 'tool_use', 'function_call' ], true ) ) {
					return true;
				}
			}
		}

		$parts = $candidate['content']['parts'] ?? $candidate['parts'] ?? [];
		if ( is_array( $parts ) ) {
			foreach ( $parts as $part ) {
				if ( is_array( $part ) && isset( $part['functionCall'] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Detect whether a response candidate contains any non-empty assistant text.
	 *
	 * Used by {@see classify_truncation()} to distinguish "model wrote a
	 * preamble then ran out of tokens" from "empty/garbage response that
	 * happened to report finish=length". An empty response with a length
	 * finish is almost always a provider bug and should not trigger the
	 * preamble-truncation guidance path.
	 *
	 * Handles OpenAI (`message.content` string), Anthropic
	 * (`content[].type === 'text'` parts), and Gemini
	 * (`content.parts[].text` parts) shapes.
	 *
	 * @param array<string, mixed> $candidate Provider response candidate.
	 */
	private static function candidate_has_text( array $candidate ): bool {
		// OpenAI-compatible: choices[].message.content as a string.
		$message = $candidate['message'] ?? null;
		if ( is_array( $message ) ) {
			$content = $message['content'] ?? null;
			if ( is_string( $content ) && '' !== trim( $content ) ) {
				return true;
			}
		}

		// Anthropic: content[] array with type=text blocks.
		$content = $candidate['content'] ?? null;
		if ( is_array( $content ) ) {
			foreach ( $content as $part ) {
				if ( ! is_array( $part ) ) {
					continue;
				}
				if ( ( $part['type'] ?? '' ) === 'text' ) {
					$text = $part['text'] ?? '';
					if ( is_string( $text ) && '' !== trim( $text ) ) {
						return true;
					}
				}
			}
		}

		// Gemini: candidate.content.parts[].text.
		$parts = $candidate['content']['parts'] ?? $candidate['parts'] ?? [];
		if ( is_array( $parts ) ) {
			foreach ( $parts as $part ) {
				if ( ! is_array( $part ) ) {
					continue;
				}
				$text = $part['text'] ?? '';
				if ( is_string( $text ) && '' !== trim( $text ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Extract the model ID from a request body.
	 *
	 * @param string $body Request body (JSON).
	 * @return string Model ID or empty string.
	 */
	private static function extract_model_id( string $body ): string {
		if ( '' === $body ) {
			return '';
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}

		$model = $decoded['model'] ?? '';
		return is_string( $model ) ? $model : '';
	}
}
