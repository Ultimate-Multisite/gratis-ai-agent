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
	 * Known AI provider URL patterns and their provider IDs.
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

		$provider_id = self::match_provider( $url );
		if ( '' === $provider_id ) {
			return $response;
		}

		// Store in-flight data for correlation with the response.
		self::$inflight[ $url ] = [
			'provider_id'     => $provider_id,
			'url'             => $url,
			'method'          => strtoupper( $parsed_args['method'] ?? 'POST' ),
			'request_headers' => self::extract_headers( $parsed_args['headers'] ?? [] ),
			'request_body'    => is_string( $parsed_args['body'] ?? null ) ? $parsed_args['body'] : (string) wp_json_encode( $parsed_args['body'] ?? '' ),
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
		// Always check whether this URL is a known AI provider — even when
		// trace is disabled — so we can emit the lightweight error line.
		$provider_id = self::match_provider( $url );
		if ( '' === $provider_id ) {
			return $response;
		}

		// Emit lightweight error line independent of debug mode. No body,
		// no headers — just status + provider + model.
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
					'provider_id' => $provider_id,
					'model_id'    => $model_id_for_log,
					'status_code' => $status_code_for_log,
				)
			);
		}

		if ( ! ProviderTrace::is_enabled() ) {
			return $response;
		}

		// Look up in-flight data.
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

		// Detect errors.
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

		ProviderTrace::insert(
			[
				'provider_id'      => $inflight['provider_id'] ?? '',
				'model_id'         => $model_id,
				'url'              => $inflight['url'] ?? $url,
				'method'           => $inflight['method'] ?? 'POST',
				'status_code'      => $status_code,
				'duration_ms'      => $duration_ms,
				'request_headers'  => $inflight['request_headers'] ?? '{}',
				'request_body'     => $inflight['request_body'] ?? '',
				'response_headers' => $response_headers_json,
				'response_body'    => $response_body,
				'error'            => $error,
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
