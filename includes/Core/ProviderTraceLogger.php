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
	 * Runtime-selected provider/model for the synchronous SDK request in flight.
	 *
	 * @var array{
	 *     provider_id:string,
	 *     model_id:string,
	 *     session_id:int,
	 *     journey_id:string,
	 *     idempotency_key:string,
	 *     retry_baseline_request_bytes:int,
	 *     request_bytes:int,
	 *     request_tokens_estimate:int,
	 *     request_provider_limit_bytes:int,
	 *     request_budget_bytes:int,
	 *     request_safety_margin_bytes:int,
	 *     operation:string,
	 *     attempt:int,
	 *     phase:string,
	 *     correlation_id:string,
	 *     failure_status_code:int,
	 *     failure_class:string,
	 *     failure_source:string
	 * }
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- Project property naming guidance requires camelCase.
	private static array $runtimeContext = array(
		'provider_id'                  => '',
		'model_id'                     => '',
		'session_id'                   => 0,
		'journey_id'                   => '',
		'idempotency_key'              => '',
		'retry_baseline_request_bytes' => 0,
		'request_bytes'                => 0,
		'request_tokens_estimate'      => 0,
		'request_provider_limit_bytes' => 0,
		'request_budget_bytes'         => 0,
		'request_safety_margin_bytes'  => 0,
		'operation'                    => '',
		'attempt'                      => 0,
		'phase'                        => '',
		'correlation_id'               => '',
		'failure_status_code'          => 0,
		'failure_class'                => '',
		'failure_source'               => '',
	);

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
	 * Set safe runtime attribution for the next synchronous provider request.
	 *
	 * @param string $provider_id                  Runtime-selected provider ID.
	 * @param string $model_id                     Runtime-selected model ID.
	 * @param int    $session_id                   Owning chat session, if any.
	 * @param int    $retry_baseline_request_bytes Full-envelope bytes from an upstream 413 retry baseline.
	 * @param string $journey_id                   Validated managed journey ID, if active.
	 * @param string $idempotency_key              Stable logical-request UUID, if active.
	 * @param string $operation                    Content-sensitive operation category, if any.
	 * @param int    $attempt                      One-based provider attempt, if available.
	 * @param string $job_id                       Active job UUID, if available.
	 * @param string $phase                        Safe provider invocation phase, if available.
	 */
	public static function set_runtime_context(
		string $provider_id,
		string $model_id,
		int $session_id = 0,
		int $retry_baseline_request_bytes = 0,
		string $journey_id = '',
		string $idempotency_key = '',
		string $operation = '',
		int $attempt = 0,
		string $job_id = '',
		string $phase = ''
	): void {
		$has_valid_managed_attribution = SuperdavManagedRequestIdentifiers::is_journey_id( $journey_id )
			&& SuperdavManagedRequestIdentifiers::is_idempotency_key( $idempotency_key );
		$allowed_phases                 = array( 'initial_provider_call', 'client_tool_resume', 'provider_followup_call' );
		self::$runtimeContext          = array(
			'provider_id'                  => sanitize_key( $provider_id ),
			'model_id'                     => sanitize_text_field( $model_id ),
			'session_id'                   => max( 0, $session_id ),
			'journey_id'                   => $has_valid_managed_attribution ? strtolower( $journey_id ) : '',
			'idempotency_key'              => $has_valid_managed_attribution ? strtolower( $idempotency_key ) : '',
			'retry_baseline_request_bytes' => max( 0, $retry_baseline_request_bytes ),
			'request_bytes'                => 0,
			'request_tokens_estimate'      => 0,
			'request_provider_limit_bytes' => 0,
			'request_budget_bytes'         => 0,
			'request_safety_margin_bytes'  => 0,
			'operation'                    => 'speech' === $operation ? 'speech' : '',
			'attempt'                      => min( 10, max( 0, $attempt ) ),
			'phase'                        => in_array( $phase, $allowed_phases, true ) ? $phase : '',
			'correlation_id'               => self::job_correlation_id( $job_id ),
			'failure_status_code'          => 0,
			'failure_class'                => '',
			'failure_source'               => '',
		);
	}

	/** Return the non-secret chat session ID for the active provider request. */
	public static function get_runtime_session_id(): int {
		return self::$runtimeContext['session_id'];
	}

	/**
	 * Return non-secret managed request attribution for the synchronous request.
	 *
	 * @return array{journey_id:string, idempotency_key:string}
	 */
	public static function get_runtime_managed_request_attribution(): array {
		return array(
			'journey_id'      => self::$runtimeContext['journey_id'],
			'idempotency_key' => self::$runtimeContext['idempotency_key'],
		);
	}

	/** Clear runtime provider attribution after a synchronous request. */
	public static function clear_runtime_context(): void {
		self::$runtimeContext = array(
			'provider_id'                  => '',
			'model_id'                     => '',
			'session_id'                   => 0,
			'journey_id'                   => '',
			'idempotency_key'              => '',
			'retry_baseline_request_bytes' => 0,
			'request_bytes'                => 0,
			'request_tokens_estimate'      => 0,
			'request_provider_limit_bytes' => 0,
			'request_budget_bytes'         => 0,
			'request_safety_margin_bytes'  => 0,
			'operation'                    => '',
			'attempt'                      => 0,
			'phase'                        => '',
			'correlation_id'               => '',
			'failure_status_code'          => 0,
			'failure_class'                => '',
			'failure_source'               => '',
		);
	}

	/**
	 * Return only scalar measurements captured for the current full request envelope.
	 *
	 * The transport hook is the first provider-neutral point that sees the SDK's
	 * complete serialized body. Never return the body, headers, URL, tool schema,
	 * attachment, or prompt content from this method.
	 *
	 * @return array<string, int|string>
	 */
	public static function get_runtime_envelope_metrics(): array {
		if ( self::$runtimeContext['request_bytes'] <= 0 ) {
			return array();
		}

		return array(
			'request_bytes'                => self::$runtimeContext['request_bytes'],
			'request_tokens_estimate'      => self::$runtimeContext['request_tokens_estimate'],
			'request_provider_limit_bytes' => self::$runtimeContext['request_provider_limit_bytes'],
			'request_budget_bytes'         => self::$runtimeContext['request_budget_bytes'],
			'request_safety_margin_bytes'  => self::$runtimeContext['request_safety_margin_bytes'],
			'request_size_class'           => self::classify_request_size( self::$runtimeContext['request_bytes'] ),
			'request_size_source'          => 'complete_envelope',
		);
	}

	/**
	 * Return safe metadata captured from a provider HTTP failure.
	 *
	 * Response bodies and provider messages are deliberately excluded. The
	 * returned scalars can be copied onto the terminal error for the durable-job
	 * diagnostic after the runtime context is cleared.
	 *
	 * @return array<string, int|string> Prompt-free failure metadata.
	 */
	public static function get_runtime_failure_metadata(): array {
		$metadata = array();
		if ( self::$runtimeContext['failure_status_code'] > 0 ) {
			$metadata['status_code'] = self::$runtimeContext['failure_status_code'];
		}
		if ( '' !== self::$runtimeContext['failure_class'] ) {
			$metadata['failure_class'] = self::$runtimeContext['failure_class'];
		}
		if ( '' !== self::$runtimeContext['failure_source'] ) {
			$metadata['failure_source'] = self::$runtimeContext['failure_source'];
		}
		if ( self::$runtimeContext['attempt'] > 0 ) {
			$metadata['attempts'] = self::$runtimeContext['attempt'];
		}
		if ( '' !== self::$runtimeContext['correlation_id'] ) {
			$metadata['correlation_id'] = self::$runtimeContext['correlation_id'];
		}

		return $metadata;
	}

	/**
	 * Hook: pre_http_request — capture outgoing request details.
	 *
	 * @param false|array<string, mixed>|\WP_Error $response    A preemptive return value of an HTTP request. Default false.
	 * @param array<string, mixed>                 $parsed_args HTTP request arguments.
	 * @param string                               $url         The request URL.
	 * @return false|array<string, mixed>|\WP_Error Unchanged response, or a safe local size rejection.
	 */
	public static function on_pre_http_request( false|array|\WP_Error $response, array $parsed_args, string $url ): false|array|\WP_Error {
		if ( false !== $response ) {
			return $response;
		}

		$trace_enabled = ProviderTrace::is_enabled();
		$has_context   = '' !== self::$runtimeContext['provider_id'];

		if ( ! $trace_enabled && ! $has_context ) {
			return $response;
		}

		$request_body = is_string( $parsed_args['body'] ?? null )
			? $parsed_args['body']
			: (string) wp_json_encode( $parsed_args['body'] ?? '' );

		// Runtime attribution is set only around the synchronous SDK provider
		// invocation. Trust it for local enforcement so a trace callback cannot
		// inspect prompt content or veto the request-size guard.
		$provider_id = $has_context ? self::$runtimeContext['provider_id'] : '';
		$model_id    = self::$runtimeContext['model_id'];
		if ( '' === $model_id ) {
			$model_id = self::extract_model_id( $request_body );
		}

		$request_bytes        = strlen( $request_body );
		$request_tokens       = (int) ceil( $request_bytes / 4 );
		$provider_limit_bytes = ConversationTrimmer::get_request_byte_budget( $provider_id, $model_id );
		$byte_budget          = ConversationTrimmer::get_request_envelope_byte_budget( $provider_id, $model_id );
		$safety_margin_bytes  = max( 0, $provider_limit_bytes - $byte_budget );

		if ( $has_context ) {
			self::$runtimeContext['request_bytes']                = $request_bytes;
			self::$runtimeContext['request_tokens_estimate']      = $request_tokens;
			self::$runtimeContext['request_provider_limit_bytes'] = $provider_limit_bytes;
			self::$runtimeContext['request_budget_bytes']         = $byte_budget;
			self::$runtimeContext['request_safety_margin_bytes']  = $safety_margin_bytes;
		}

		// Speech HTTP bodies contain transcripts, synthesis text, or audio. Even
		// explicit provider tracing must never persist those payloads or raw
		// upstream responses. Scalar request-size enforcement above remains active.
		if ( 'speech' === self::$runtimeContext['operation'] ) {
			return $response;
		}

		$fallback_attempted = $has_context && self::$runtimeContext['retry_baseline_request_bytes'] > 0;

		if ( $has_context && $request_bytes > $byte_budget ) {
			self::record_payload_limit(
				$provider_id,
				$model_id,
				413,
				$request_bytes,
				$request_tokens,
				$byte_budget,
				true,
				$fallback_attempted,
				false,
				self::local_payload_recovery_outcome()
			);

			return new \WP_Error(
				'sd_ai_agent_provider_payload_budget_exceeded',
				__( 'This request is too large to send safely. Compact the conversation or shorten the latest message and remove large attachments before retrying.', 'superdav-ai-agent' ),
				array(
					'status_code'                  => 413,
					'provider_id'                  => $provider_id,
					'model_id'                     => $model_id,
					'request_bytes'                => $request_bytes,
					'request_tokens_estimate'      => $request_tokens,
					'request_provider_limit_bytes' => $provider_limit_bytes,
					'request_budget_bytes'         => $byte_budget,
					'request_safety_margin_bytes'  => $safety_margin_bytes,
					'request_size_class'           => self::classify_request_size( $request_bytes ),
					'request_size_source'          => 'complete_envelope',
					'local_rejection'              => true,
					'fallback_attempted'           => $fallback_attempted,
					'recovery_outcome'             => self::local_payload_recovery_outcome(),
				)
			);
		}

		$retry_baseline_bytes = self::$runtimeContext['retry_baseline_request_bytes'];
		if ( $has_context && $retry_baseline_bytes > 0 && ! self::is_materially_smaller_envelope( $request_bytes, $retry_baseline_bytes ) ) {
			self::record_payload_limit(
				$provider_id,
				$model_id,
				413,
				$request_bytes,
				$request_tokens,
				$byte_budget,
				true,
				true,
				false,
				'retry_not_materially_smaller'
			);

			return new \WP_Error(
				'sd_ai_agent_provider_payload_budget_exceeded',
				__( 'This request is too large to send safely. Compact the conversation or shorten the latest message and remove large attachments before retrying.', 'superdav-ai-agent' ),
				array(
					'status_code'                  => 413,
					'provider_id'                  => $provider_id,
					'model_id'                     => $model_id,
					'request_bytes'                => $request_bytes,
					'request_tokens_estimate'      => $request_tokens,
					'request_provider_limit_bytes' => $provider_limit_bytes,
					'request_budget_bytes'         => $byte_budget,
					'request_safety_margin_bytes'  => $safety_margin_bytes,
					'request_size_class'           => self::classify_request_size( $request_bytes ),
					'request_size_source'          => 'complete_envelope',
					'local_rejection'              => true,
					'recovery_outcome'             => 'retry_not_materially_smaller',
				)
			);
		}

		if ( ! $trace_enabled ) {
			return $response;
		}

		// Only explicit debug tracing may pass a request body through the wider
		// provider matcher and its extension filter. Payload enforcement above is
		// deliberately independent of this trace-only resolution result.
		$matched_provider_id = self::resolve_provider_for_trace( $url, $request_body );
		if ( '' === $matched_provider_id ) {
			return $response;
		}
		if ( ! $has_context ) {
			$provider_id = $matched_provider_id;
		}

		// Store in-flight data for correlation with the response.
		self::$inflight[ $url ] = [
			'provider_id'     => $provider_id,
			'model_id'        => $model_id,
			'session_id'      => self::$runtimeContext['session_id'],
			'attempt'         => self::$runtimeContext['attempt'],
			'phase'           => self::$runtimeContext['phase'],
			'correlation_id'  => self::$runtimeContext['correlation_id'],
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
		$trace_enabled          = ProviderTrace::is_enabled();
		$has_context            = '' !== self::$runtimeContext['provider_id'];
		$canonical_provider_id  = self::match_provider( $url );
		if ( ! $trace_enabled && ! $has_context && '' === $canonical_provider_id ) {
			return $response;
		}

		$request_body = is_string( $parsed_args['body'] ?? null )
			? $parsed_args['body']
			: (string) wp_json_encode( $parsed_args['body'] ?? '' );
		$status_code  = (int) wp_remote_retrieve_response_code( $response );
		$response_body_for_classification = $status_code >= 400
			? (string) wp_remote_retrieve_body( $response )
			: '';
		$failure_class = $status_code >= 400 && ProviderErrorClassifier::is_gateway_rejection_response( $status_code, $response_body_for_classification )
			? ProviderErrorClassifier::FAILURE_CLASS_GATEWAY_REJECTION
			: '';

		if ( $has_context && $status_code >= 400 ) {
			self::$runtimeContext['failure_status_code'] = $status_code;
			self::$runtimeContext['failure_class']       = $failure_class;
			self::$runtimeContext['failure_source']      = 'http';
		}

		// Lightweight error-log path: emit a greppable line for 4xx/5xx
		// responses from canonical AI providers regardless of debug mode.
		// Uses the strict allowlist so unrelated 4xx responses (update
		// checks, WP.org, etc.) never produce noise here.
		$request_provider_id = $has_context ? self::$runtimeContext['provider_id'] : $canonical_provider_id;
		if ( '' !== $request_provider_id && $status_code >= 400 ) {
			$model_id_for_log = self::$runtimeContext['model_id'];
			if ( '' === $model_id_for_log ) {
				$model_id_for_log = self::extract_model_id( $request_body );
			}
			$request_bytes = strlen( $request_body );
			$byte_budget   = ConversationTrimmer::get_request_envelope_byte_budget( $request_provider_id, $model_id_for_log );

			if ( 413 === $status_code ) {
				self::record_payload_limit(
					$request_provider_id,
					$model_id_for_log,
					$status_code,
					$request_bytes,
					(int) ceil( $request_bytes / 4 ),
					$byte_budget,
					false,
					false,
					false,
					'upstream_413'
				);
			} else {
				$log_context = array(
					'provider_id'             => $request_provider_id,
					'model_id'                => $model_id_for_log,
					'status_code'             => $status_code,
					'request_bytes'           => $request_bytes,
					'request_tokens_estimate' => (int) ceil( $request_bytes / 4 ),
					'request_size_class'      => self::classify_request_size( $request_bytes ),
					'request_size_source'     => 'http_body',
				);
				if ( $has_context && self::$runtimeContext['attempt'] > 0 ) {
					$log_context['attempts'] = self::$runtimeContext['attempt'];
				}
				if ( $has_context && '' !== self::$runtimeContext['correlation_id'] ) {
					$log_context['correlation_id'] = self::$runtimeContext['correlation_id'];
				}
				if ( '' !== $failure_class ) {
					$log_context['reason'] = $failure_class;
				}

				AgentEventLog::log( 'provider_http_error', AgentEventLog::SEVERITY_ERROR, $log_context );
			}
		}

		if ( ! $trace_enabled ) {
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

		$response_body    = '' !== $response_body_for_classification
			? $response_body_for_classification
			: (string) wp_remote_retrieve_body( $response );
		$response_headers = wp_remote_retrieve_headers( $response );

		// Extract model_id from request body if possible.
		$model_id = (string) ( $inflight['model_id'] ?? '' );
		if ( '' === $model_id ) {
			$model_id = self::extract_model_id( $inflight['request_body'] ?? '' );
		}

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

		$trace_url              = $inflight['url'] ?? $url;
		$trace_request_headers  = $inflight['request_headers'] ?? '{}';
		$trace_request_body     = $inflight['request_body'] ?? '';
		$trace_response_headers = $response_headers_json;
		$trace_response_body    = $response_body;
		if ( 413 === $status_code || ProviderErrorClassifier::FAILURE_CLASS_GATEWAY_REJECTION === $failure_class ) {
			$request_bytes          = strlen( (string) $trace_request_body );
			$trace_url              = self::safe_trace_url( (string) $trace_url );
			$trace_request_headers  = '{}';
			$trace_request_body     = (string) wp_json_encode(
				array(
					'request_bytes'           => $request_bytes,
					'request_tokens_estimate' => (int) ceil( $request_bytes / 4 ),
					'request_size_class'      => self::classify_request_size( $request_bytes ),
				)
			);
			$trace_response_headers = '{}';
			$trace_response_body    = '';

			if ( ProviderErrorClassifier::FAILURE_CLASS_GATEWAY_REJECTION === $failure_class ) {
				$gateway_diagnostic = array(
					'event'           => 'provider_gateway_rejection',
					'failure_class'   => $failure_class,
					'failure_source'  => 'http',
					'status_code'     => $status_code,
					'session_id'      => max( 0, (int) ( $inflight['session_id'] ?? 0 ) ),
				);
				if ( (int) ( $inflight['attempt'] ?? 0 ) > 0 ) {
					$gateway_diagnostic['attempts'] = min( 10, (int) $inflight['attempt'] );
				}
				if ( '' !== (string) ( $inflight['phase'] ?? '' ) ) {
					$gateway_diagnostic['phase'] = (string) $inflight['phase'];
				}
				if ( '' !== (string) ( $inflight['correlation_id'] ?? '' ) ) {
					$gateway_diagnostic['correlation_id'] = (string) $inflight['correlation_id'];
				}
				$trace_response_body = (string) wp_json_encode( $gateway_diagnostic );
				$error               = 'provider_gateway_rejection';
			} else {
				$error = 'HTTP 413';
			}
		}

		ProviderTrace::insert(
			[
				'provider_id'           => $inflight['provider_id'] ?? '',
				'model_id'              => $model_id,
				'url'                   => $trace_url,
				'method'                => $inflight['method'] ?? 'POST',
				'status_code'           => $status_code,
				'duration_ms'           => $duration_ms,
				'cache_creation_tokens' => $cache_tokens['creation'],
				'cache_read_tokens'     => $cache_tokens['read'],
				'request_headers'       => $trace_request_headers,
				'request_body'          => $trace_request_body,
				'response_headers'      => $trace_response_headers,
				'response_body'         => $trace_response_body,
				'error'                 => $error,
			]
		);

		return $response;
	}

	/**
	 * Emit prompt-free payload-limit diagnostics.
	 *
	 * @param string $provider_id       Runtime-selected provider ID.
	 * @param string $model_id          Runtime-selected model ID.
	 * @param int    $status_code       HTTP-like status code.
	 * @param int    $request_bytes     Actual or estimated request bytes.
	 * @param int    $request_tokens    Estimated request tokens.
	 * @param int    $request_budget    Configured request byte budget.
	 * @param bool   $local_rejection   Whether dispatch was blocked locally.
	 * @param bool   $fallback_attempted Whether a reduced fallback was attempted.
	 * @param bool   $bytes_estimated   Whether request_bytes is a history estimate.
	 * @param string $recovery_outcome  Safe recovery outcome token.
	 */
	public static function record_payload_limit(
		string $provider_id,
		string $model_id,
		int $status_code,
		int $request_bytes,
		int $request_tokens,
		int $request_budget,
		bool $local_rejection,
		bool $fallback_attempted,
		bool $bytes_estimated = false,
		string $recovery_outcome = ''
	): void {
		$context               = array(
			'provider_id'             => sanitize_key( $provider_id ),
			'model_id'                => sanitize_text_field( $model_id ),
			'status_code'             => $status_code,
			'request_tokens_estimate' => max( 0, $request_tokens ),
			'request_budget_bytes'    => max( 0, $request_budget ),
			'request_size_class'      => self::classify_request_size( $request_bytes ),
			'request_size_source'     => $bytes_estimated ? 'history_estimate' : 'complete_envelope',
			'local_rejection'         => $local_rejection,
			'fallback_attempted'      => $fallback_attempted,
		);
		$bytes_key             = $bytes_estimated ? 'request_bytes_estimate' : 'request_bytes';
		$context[ $bytes_key ] = max( 0, $request_bytes );
		if ( '' !== $recovery_outcome ) {
			$context['recovery_outcome'] = sanitize_key( $recovery_outcome );
		}
		if ( ! $bytes_estimated && self::$runtimeContext['request_provider_limit_bytes'] > 0 ) {
			$context['request_provider_limit_bytes'] = self::$runtimeContext['request_provider_limit_bytes'];
			$context['request_safety_margin_bytes']  = self::$runtimeContext['request_safety_margin_bytes'];
		}

		AgentEventLog::log( 'provider_payload_limit', AgentEventLog::SEVERITY_ERROR, $context );
	}

	/** Whether a retried envelope is at least ten percent smaller than its upstream-413 baseline. */
	private static function is_materially_smaller_envelope( int $request_bytes, int $baseline_bytes ): bool {
		if ( $request_bytes >= $baseline_bytes || $baseline_bytes <= 0 ) {
			return false;
		}

		return ( $baseline_bytes - $request_bytes ) >= (int) ceil( $baseline_bytes * 0.1 );
	}

	/** Return a safe local-rejection outcome without exposing request content. */
	private static function local_payload_recovery_outcome(): string {
		return self::$runtimeContext['session_id'] > 0
			? 'compact_session_available'
			: 'compact_session_unavailable';
	}

	/**
	 * Emit prompt-free model discovery diagnostics even when HTTP tracing is disabled.
	 *
	 * @param string $provider_id Provider ID.
	 * @param string $category    Normalized failure category.
	 * @param int    $status_code HTTP status code, or 0 when unavailable.
	 * @param int    $attempts    Number of model listing attempts.
	 * @param int    $duration_ms Discovery duration in milliseconds.
	 */
	public static function record_model_discovery_failure( string $provider_id, string $category, int $status_code, int $attempts, int $duration_ms ): void {
		$allowed_categories = array( 'client', 'transport', 'unauthorized', 'unknown', 'upstream', 'wp_error' );
		$category           = sanitize_key( $category );
		if ( ! in_array( $category, $allowed_categories, true ) ) {
			$category = 'unknown';
		}

		$context = array(
			'provider_id' => sanitize_key( $provider_id ),
			'code'        => $category,
			'attempts'    => max( 1, $attempts ),
			'duration_ms' => max( 0, $duration_ms ),
		);
		if ( $status_code > 0 ) {
			$context['status_code'] = $status_code;
		}

		AgentEventLog::log( 'provider_model_discovery_failed', AgentEventLog::SEVERITY_ERROR, $context );
	}

	/**
	 * Persist one bounded terminal trace for a retry-exhausted provider call.
	 *
	 * WordPress's HTTP response filter does not run when a transport returns a
	 * WP_Error, and SDK exceptions do not dispatch an After event. This summary
	 * deliberately records the invocation rather than another raw request: it
	 * gives operators one correlated failure row without retaining a prompt,
	 * provider error message, URL, header, or credential.
	 *
	 * @param string                    $provider_id         Runtime provider ID.
	 * @param string                    $model_id            Runtime model ID.
	 * @param \WP_Error|\Throwable|null $error               Last provider error.
	 * @param int                       $status_code         HTTP status, or 0 when unavailable.
	 * @param int                       $attempts            Total attempts in this invocation.
	 * @param int                       $elapsed_ms          Total invocation elapsed time.
	 * @param int|null                  $retry_after_seconds Retry-After from the last response, if available.
	 * @param array<int>                $backoff_delays      Delays applied before retries.
	 * @param string                    $phase               Safe invocation phase.
	 * @param int                       $session_id          Correlating chat session, if any.
	 * @param string                    $job_id              Correlating background job, if any.
	 * @param array<string, int|string> $request_metrics     Prompt-free full-envelope measurements.
	 */
	public static function record_retry_exhausted_failure(
		string $provider_id,
		string $model_id,
		$error,
		int $status_code,
		int $attempts,
		int $elapsed_ms,
		?int $retry_after_seconds,
		array $backoff_delays,
		string $phase,
		int $session_id = 0,
		string $job_id = '',
		array $request_metrics = array()
	): void {
		if ( ! ProviderTrace::is_enabled() ) {
			return;
		}

		$allowed_phases = array( 'initial_provider_call', 'client_tool_resume', 'provider_followup_call' );
		if ( ! in_array( $phase, $allowed_phases, true ) ) {
			$phase = 'initial_provider_call';
		}

		$metrics = array(
			'request_size_class' => 'unknown',
		);
		foreach ( array( 'request_bytes', 'request_tokens_estimate', 'request_provider_limit_bytes', 'request_budget_bytes', 'request_safety_margin_bytes' ) as $key ) {
			if ( isset( $request_metrics[ $key ] ) && is_numeric( $request_metrics[ $key ] ) ) {
				$metrics[ $key ] = max( 0, (int) $request_metrics[ $key ] );
			}
		}
		if ( isset( $request_metrics['request_size_class'] ) && is_string( $request_metrics['request_size_class'] ) ) {
			$metrics['request_size_class'] = sanitize_key( $request_metrics['request_size_class'] );
		}

		$diagnostics = array(
			'event'                  => 'provider_retry_exhausted',
			'error_code'             => ProviderErrorClassifier::get_safe_error_code( $error, $status_code ),
			'failure_source'         => $status_code >= 400 ? 'http' : 'transport',
			'attempts'               => max( 1, $attempts ),
			'elapsed_ms'             => max( 0, $elapsed_ms ),
			'phase'                  => $phase,
			'session_id'             => max( 0, $session_id ),
			'backoff_delays_seconds' => array_values( array_map( static fn( $delay ): int => min( 60, max( 0, (int) $delay ) ), array_slice( $backoff_delays, 0, 10 ) ) ),
		);
		if ( null !== $retry_after_seconds ) {
			$diagnostics['retry_after_seconds'] = min( 60, max( 0, $retry_after_seconds ) );
		}
		$failure_class = ProviderErrorClassifier::get_safe_failure_class( $error, $status_code );
		if ( '' !== $failure_class ) {
			$diagnostics['failure_class'] = $failure_class;
		}
		if ( '' !== $job_id ) {
			$diagnostics['job_id'] = sanitize_text_field( $job_id );
			$diagnostics['correlation_id'] = self::job_correlation_id( $job_id );
		}

		ProviderTrace::insert(
			array(
				'provider_id'      => sanitize_key( $provider_id ),
				'model_id'         => sanitize_text_field( $model_id ),
				'url'              => '',
				'method'           => 'SDK',
				'status_code'      => max( 0, $status_code ),
				'duration_ms'      => max( 0, $elapsed_ms ),
				'request_headers'  => '{}',
				'request_body'     => (string) wp_json_encode( $metrics ),
				'response_headers' => '{}',
				'response_body'    => (string) wp_json_encode( $diagnostics ),
				'error'            => (string) $diagnostics['error_code'],
			)
		);
	}

	/** Classify request size without retaining request content. */
	public static function classify_request_size( int $request_bytes ): string {
		if ( $request_bytes < 65536 ) {
			return 'small';
		}
		if ( $request_bytes < 262144 ) {
			return 'medium';
		}
		if ( $request_bytes < 1048576 ) {
			return 'large';
		}

		return 'very_large';
	}

	/** Build a stable, non-reversible support correlation ID for an active job. */
	private static function job_correlation_id( string $job_id ): string {
		$job_id = sanitize_text_field( $job_id );

		return '' === $job_id ? '' : 'job-' . substr( hash( 'sha256', $job_id ), 0, 12 );
	}

	/**
	 * Remove query/user-info fragments that can carry credentials from a trace URL.
	 *
	 * @param string $url Provider URL.
	 */
	private static function safe_trace_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$safe = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$safe .= ':' . (int) $parts['port'];
		}
		$safe .= $parts['path'] ?? '';

		return $safe;
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
