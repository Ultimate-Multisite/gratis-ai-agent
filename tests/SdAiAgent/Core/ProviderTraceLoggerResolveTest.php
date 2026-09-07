<?php

declare(strict_types=1);
/**
 * Test case for ProviderTraceLogger::resolve_provider_for_trace().
 *
 * Covers the wider matcher that runs when provider tracing is enabled,
 * including the canonical allowlist, the legacy `sd_ai_agent_trace_match_provider`
 * filter, path heuristics, body heuristics, and the new
 * `sd_ai_agent_trace_resolve_provider` override filter.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Bootstrap\HttpTraceHandler;
use SdAiAgent\Core\ConversationTrimmer;
use SdAiAgent\Core\ProviderTraceLogger;
use SdAiAgent\Models\ProviderTrace;
use WP_UnitTestCase;

/**
 * @covers \SdAiAgent\Core\ProviderTraceLogger::resolve_provider_for_trace
 * @covers \SdAiAgent\Core\ProviderTraceLogger::match_provider
 */
class ProviderTraceLoggerResolveTest extends WP_UnitTestCase {

	public function tear_down(): void {
		ProviderTraceLogger::clear_runtime_context();
		ProviderTrace::clear();
		ProviderTrace::set_enabled( false );
		remove_all_filters( 'sd_ai_agent_trace_match_provider' );
		remove_all_filters( 'sd_ai_agent_trace_resolve_provider' );
		remove_all_filters( 'sd_ai_agent_provider_trace_enabled' );
		remove_all_filters( 'sd_ai_agent_provider_request_max_bytes' );
		remove_all_filters( 'sd_ai_agent_provider_request_safety_margin_bytes' );
		parent::tear_down();
	}

	public function test_canonical_anthropic_returns_anthropic(): void {
		$this->assertSame(
			'anthropic',
			ProviderTraceLogger::resolve_provider_for_trace(
				'https://api.anthropic.com/v1/messages',
				''
			)
		);
	}

	public function test_canonical_openai_returns_openai(): void {
		$this->assertSame(
			'openai',
			ProviderTraceLogger::resolve_provider_for_trace(
				'https://api.openai.com/v1/chat/completions',
				''
			)
		);
	}

	public function test_canonical_google_returns_google(): void {
		$this->assertSame(
			'google',
			ProviderTraceLogger::resolve_provider_for_trace(
				'https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent',
				''
			)
		);
	}

	public function test_canonical_ollama_localhost_returns_ollama(): void {
		$this->assertSame(
			'ollama',
			ProviderTraceLogger::resolve_provider_for_trace(
				'http://localhost:11434/api/chat',
				''
			)
		);
	}

	public function test_huggingface_inference_endpoint_matched_by_path_heuristic(): void {
		// Real-world stalled-session case: Kimi served via HuggingFace
		// Inference through the ai-provider-for-any-openai-compatible
		// connector plugin.
		$url    = 'https://api-inference.huggingface.co/models/moonshotai/Kimi-K2.6';
		$result = ProviderTraceLogger::resolve_provider_for_trace( $url, '' );

		$this->assertSame( 'host:api-inference.huggingface.co', $result );
	}

	public function test_openai_compatible_endpoint_matched_by_chat_completions_path(): void {
		$url    = 'https://example.openrouter.ai/api/v1/chat/completions';
		$result = ProviderTraceLogger::resolve_provider_for_trace( $url, '' );

		$this->assertSame( 'host:example.openrouter.ai', $result );
	}

	public function test_body_with_model_field_matches_unknown_endpoint(): void {
		$url  = 'https://my-private-llm.example.com/v1/infer';
		$body = (string) wp_json_encode( array( 'model' => 'custom-foundation-7b', 'prompt' => 'hi' ) );

		$result = ProviderTraceLogger::resolve_provider_for_trace( $url, $body );

		$this->assertSame( 'host:my-private-llm.example.com', $result );
	}

	public function test_non_llm_endpoint_returns_empty(): void {
		// WP.org plugin update check — should not be traced even when
		// tracing is enabled.
		$this->assertSame(
			'',
			ProviderTraceLogger::resolve_provider_for_trace(
				'https://api.wordpress.org/plugins/info/1.2/',
				''
			)
		);
	}

	public function test_invalid_url_returns_empty(): void {
		$this->assertSame(
			'',
			ProviderTraceLogger::resolve_provider_for_trace( 'not-a-url', '' )
		);
	}

	public function test_legacy_match_filter_overrides_unknown_host(): void {
		add_filter(
			'sd_ai_agent_trace_match_provider',
			static function ( string $provider_id, string $url, string $host ): string {
				if ( 'proxy.custom.test' === $host ) {
					return 'custom-proxy';
				}
				return $provider_id;
			},
			10,
			3
		);

		$this->assertSame(
			'custom-proxy',
			ProviderTraceLogger::resolve_provider_for_trace(
				'https://proxy.custom.test/foo',
				''
			)
		);
	}

	public function test_resolve_filter_can_veto_match(): void {
		add_filter(
			'sd_ai_agent_trace_resolve_provider',
			static function ( string $provider_id, string $url, string $body ): string {
				// Veto all anthropic traces.
				if ( 'anthropic' === $provider_id ) {
					return '';
				}
				return $provider_id;
			},
			10,
			3
		);

		$this->assertSame(
			'',
			ProviderTraceLogger::resolve_provider_for_trace(
				'https://api.anthropic.com/v1/messages',
				''
			)
		);
	}

	public function test_resolve_filter_can_rewrite_provider_id(): void {
		add_filter(
			'sd_ai_agent_trace_resolve_provider',
			static function ( string $provider_id ): string {
				if ( str_starts_with( $provider_id, 'host:api-inference.huggingface.co' ) ) {
					return 'huggingface';
				}
				return $provider_id;
			}
		);

		$this->assertSame(
			'huggingface',
			ProviderTraceLogger::resolve_provider_for_trace(
				'https://api-inference.huggingface.co/models/moonshotai/Kimi-K2.6',
				''
			)
		);
	}

	public function test_match_provider_strict_path_still_only_returns_canonical(): void {
		// Verify the narrow matcher used by the error-log path is unaffected
		// by the wider trace matcher. Unknown hosts must still return ''.
		$this->assertSame(
			'',
			ProviderTraceLogger::match_provider(
				'https://api-inference.huggingface.co/models/moonshotai/Kimi-K2.6'
			)
		);
		$this->assertSame(
			'anthropic',
			ProviderTraceLogger::match_provider( 'https://api.anthropic.com/v1/messages' )
		);
	}

	/**
	 * Runtime payload enforcement must not expose prompts to trace callbacks or
	 * allow a trace veto to disable the local byte guard.
	 */
	public function test_runtime_payload_guard_is_independent_of_disabled_tracing(): void {
		add_filter( 'sd_ai_agent_provider_trace_enabled', '__return_false' );
		add_filter( 'sd_ai_agent_provider_request_max_bytes', static fn(): int => 1024 );

		$body_seen = null;
		add_filter(
			'sd_ai_agent_trace_resolve_provider',
			static function ( string $provider_id, string $url, string $body ) use ( &$body_seen ): string {
				$body_seen = $body;
				return '';
			},
			10,
			3
		);

		ProviderTraceLogger::set_runtime_context( 'openai_compat', 'gpt-test' );
		$request_body = (string) wp_json_encode(
			array(
				'model'  => 'gpt-test',
				'prompt' => str_repeat( 'PRIVATE_PROMPT_CONTENT', 100 ),
			)
		);
		$result       = ProviderTraceLogger::on_pre_http_request(
			false,
			array( 'body' => $request_body ),
			'https://private-provider.example/v1/infer'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_provider_payload_budget_exceeded', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['local_rejection'] );
		$this->assertNull( $body_seen, 'Disabled tracing must not pass prompt bodies to trace resolution filters.' );
	}

	/**
	 * The local guard measures the final serialized envelope rather than only the
	 * history that fit before the SDK added system, tool, attachment, and option data.
	 */
	public function test_full_envelope_preflight_rejects_non_history_overhead_without_leaking_it(): void {
		add_filter( 'sd_ai_agent_provider_request_max_bytes', static fn(): int => 4096 );
		add_filter( 'sd_ai_agent_provider_request_safety_margin_bytes', static fn(): int => 512 );

		$history = str_repeat( 'history ', 200 );
		$history_body = (string) wp_json_encode(
			array(
				'messages' => array( array( 'content' => $history ) ),
			)
		);
		$private_tool_argument = 'PRIVATE_TOOL_ARGUMENT_MUST_NOT_ESCAPE';
		$request_body = (string) wp_json_encode(
			array(
				'messages'           => array( array( 'content' => $history ) ),
				'system_instruction' => str_repeat( 'system ', 220 ),
				'tools'              => array(
					array(
						'name'       => 'site-update',
						'parameters' => str_repeat( 'schema ', 220 ) . $private_tool_argument,
					)
				),
				'attachments'        => array( array( 'metadata' => str_repeat( 'attachment ', 120 ) ) ),
				'model_options'      => array( 'response_format' => str_repeat( 'option ', 80 ) ),
			)
		);
		$budget = ConversationTrimmer::get_request_envelope_byte_budget( 'openai', 'gpt-test' );

		$this->assertLessThanOrEqual( $budget, strlen( $history_body ) );
		$this->assertGreaterThan( $budget, strlen( $request_body ) );

		ProviderTraceLogger::set_runtime_context( 'openai', 'gpt-test', 73 );
		$result = ProviderTraceLogger::on_pre_http_request(
			false,
			array( 'body' => $request_body ),
			'https://provider.example/v1/responses'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'complete_envelope', $data['request_size_source'] );
		$this->assertSame( strlen( $request_body ), $data['request_bytes'] );
		$this->assertSame( $budget, $data['request_budget_bytes'] );
		$this->assertSame( 512, $data['request_safety_margin_bytes'] );
		$this->assertSame( 'compact_session_available', $data['recovery_outcome'] );
		$this->assertStringNotContainsString( $private_tool_argument, (string) wp_json_encode( $data ) );
	}

	/** A reduced retry is blocked before dispatch unless its complete envelope is materially smaller. */
	public function test_retry_preflight_rejects_envelope_that_is_not_materially_smaller(): void {
		add_filter( 'sd_ai_agent_provider_request_max_bytes', static fn(): int => 4096 );
		add_filter( 'sd_ai_agent_provider_request_safety_margin_bytes', static fn(): int => 0 );

		$private_request_text = 'PRIVATE_RETRY_REQUEST_MUST_NOT_ESCAPE';
		$request_body = (string) wp_json_encode(
			array( 'messages' => array( array( 'content' => str_repeat( $private_request_text, 50 ) ) ) )
		);
		$baseline_bytes = (int) ceil( strlen( $request_body ) / 0.95 );

		ProviderTraceLogger::set_runtime_context( 'openai', 'gpt-test', 73, $baseline_bytes );
		$result = ProviderTraceLogger::on_pre_http_request(
			false,
			array( 'body' => $request_body ),
			'https://provider.example/v1/responses'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 413, $data['status_code'] );
		$this->assertSame( 'retry_not_materially_smaller', $data['recovery_outcome'] );
		$this->assertTrue( $data['local_rejection'] );
		$this->assertStringNotContainsString( $private_request_text, (string) wp_json_encode( $data ) );
	}

	/** A locally blocked reduced retry retains its fallback attribution in safe telemetry. */
	public function test_retry_preflight_marks_local_rejection_as_fallback_attempted(): void {
		add_filter( 'sd_ai_agent_provider_request_max_bytes', static fn(): int => 1024 );
		add_filter( 'sd_ai_agent_provider_request_safety_margin_bytes', static fn(): int => 0 );

		$request_body = (string) wp_json_encode(
			array( 'messages' => array( array( 'content' => str_repeat( 'reduced request ', 100 ) ) ) )
		);

		ProviderTraceLogger::set_runtime_context( 'openai', 'gpt-test', 73, strlen( $request_body ) + 1024 );
		$result = ProviderTraceLogger::on_pre_http_request(
			false,
			array( 'body' => $request_body ),
			'https://provider.example/v1/responses'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertTrue( $data['local_rejection'] );
		$this->assertTrue( $data['fallback_attempted'] );
		$this->assertSame( 'compact_session_available', $data['recovery_outcome'] );
	}

	/** A traced provider context attributes 413 diagnostics without invoking trace filters when disabled. */
	public function test_runtime_http_error_attribution_does_not_expose_body_when_tracing_is_disabled(): void {
		add_filter( 'sd_ai_agent_provider_trace_enabled', '__return_false' );

		$body_seen = null;
		add_filter(
			'sd_ai_agent_trace_resolve_provider',
			static function ( string $provider_id, string $url, string $body ) use ( &$body_seen ): string {
				$body_seen = $body;
				return $provider_id;
			},
			10,
			3
		);

		ProviderTraceLogger::set_runtime_context( 'anthropic', 'claude-test' );
		$request_body = (string) wp_json_encode(
			array(
				'model'    => 'claude-test',
				'messages' => array( array( 'content' => 'PRIVATE_HTTP_PROMPT' ) ),
			)
		);
		$response     = array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( array( 'error' => array( 'message' => 'Payload too large' ) ) ),
			'response' => array(
				'code'    => 413,
				'message' => 'Payload Too Large',
			),
			'cookies'  => array(),
			'filename' => '',
		);

		$this->assertSame(
			$response,
			ProviderTraceLogger::on_http_response(
				$response,
				array( 'body' => $request_body ),
				'https://private-provider.example/v1/infer'
			)
		);
		$this->assertNull( $body_seen, 'Disabled tracing must not pass 413 prompt bodies to trace resolution filters.' );
	}

	public function test_gateway_response_writes_only_bounded_terminal_trace_metadata(): void {
		ProviderTrace::set_enabled( true );
		ProviderTrace::clear();
		$job_id       = '99999999-aaaa-bbbb-cccc-dddddddddddd';
		$request_body = (string) wp_json_encode(
			array(
				'model'    => 'gpt-test',
				'messages' => array( array( 'content' => 'PRIVATE_HTTP_PROMPT' ) ),
			)
		);
		$url          = 'https://api.openai.com/v1/chat/completions';
		$response     = array(
			'headers'  => array( 'server' => 'imunify360', 'x-private-token' => 'PRIVATE_HEADER_TOKEN' ),
			'body'     => '<html><title>Imunify360</title>Security gateway blocked PRIVATE_PROVIDER_RESPONSE Authorization: Bearer PRIVATE_TOKEN</html>',
			'response' => array( 'code' => 403, 'message' => 'Forbidden' ),
			'cookies'  => array(),
			'filename' => '',
		);

		ProviderTraceLogger::set_runtime_context(
			'openai',
			'gpt-test',
			73,
			0,
			'',
			'',
			'',
			1,
			$job_id,
			'initial_provider_call'
		);
		ProviderTraceLogger::on_pre_http_request( false, array( 'body' => $request_body ), $url );
		ProviderTraceLogger::on_http_response( $response, array( 'body' => $request_body ), $url );

		$metadata = ProviderTraceLogger::get_runtime_failure_metadata();
		$this->assertSame( 403, $metadata['status_code'] );
		$this->assertSame( 'gateway_rejection', $metadata['failure_class'] );
		$this->assertSame( 'http', $metadata['failure_source'] );
		$this->assertSame( 1, $metadata['attempts'] );
		$this->assertMatchesRegularExpression( '/^job-[a-f0-9]{12}$/', $metadata['correlation_id'] );

		$rows = ProviderTrace::list( array( 'limit' => 1 ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 403, $rows[0]->status_code );
		$this->assertSame( 'provider_gateway_rejection', $rows[0]->error );
		$trace = ProviderTrace::get( $rows[0]->id );
		$this->assertNotNull( $trace );
		$this->assertSame( '{}', $trace->request_headers );
		$this->assertSame( '{}', $trace->response_headers );
		$terminal_metadata = json_decode( $trace->response_body, true );
		$this->assertIsArray( $terminal_metadata );
		$this->assertSame( 'provider_gateway_rejection', $terminal_metadata['event'] );
		$this->assertSame( 'gateway_rejection', $terminal_metadata['failure_class'] );
		$this->assertSame( 'http', $terminal_metadata['failure_source'] );
		$this->assertSame( 1, $terminal_metadata['attempts'] );
		$this->assertMatchesRegularExpression( '/^job-[a-f0-9]{12}$/', $terminal_metadata['correlation_id'] );
		$persisted_trace = (string) wp_json_encode( $trace );
		$this->assertStringNotContainsString( 'PRIVATE_HTTP_PROMPT', $persisted_trace );
		$this->assertStringNotContainsString( 'PRIVATE_PROVIDER_RESPONSE', $persisted_trace );
		$this->assertStringNotContainsString( 'PRIVATE_HEADER_TOKEN', $persisted_trace );
		$this->assertStringNotContainsString( 'PRIVATE_TOKEN', $persisted_trace );
	}

	public function test_http_trace_handler_captures_gateway_metadata_without_debug_tracing(): void {
		ProviderTrace::set_enabled( false );
		ProviderTraceLogger::set_runtime_context(
			'openai',
			'gpt-test',
			73,
			0,
			'',
			'',
			'',
			2,
			'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
			'provider_followup_call'
		);

		$handler = new HttpTraceHandler();
		$handler->on_http_response(
			array(
				'headers'  => array(),
				'body'     => 'Imunify360 rejected PRIVATE_PROVIDER_RESPONSE.',
				'response' => array( 'code' => 403, 'message' => 'Forbidden' ),
				'cookies'  => array(),
				'filename' => '',
			),
			array( 'body' => '{"model":"gpt-test"}' ),
			'https://api.openai.com/v1/chat/completions'
		);

		$this->assertSame(
			array(
				'status_code'    => 403,
				'failure_class'  => 'gateway_rejection',
				'failure_source' => 'http',
				'attempts'       => 2,
				'correlation_id' => 'job-' . substr( hash( 'sha256', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' ), 0, 12 ),
			),
			ProviderTraceLogger::get_runtime_failure_metadata()
		);
		$this->assertCount( 0, ProviderTrace::list( array( 'limit' => 1 ) ) );
	}

	/** Retry exhaustion has one prompt-free terminal trace even when transport callbacks never run. */
	public function test_retry_exhaustion_writes_safe_terminal_trace(): void {
		ProviderTrace::set_enabled( true );
		ProviderTrace::clear();

		ProviderTraceLogger::record_retry_exhausted_failure(
			'openai_compat',
			'gpt-test',
			new \WP_Error( 'http_request_failed', 'cURL error: Could not resolve host: PRIVATE_HOST' ),
			0,
			4,
			12000,
			null,
			array( 1, 2, 4 ),
			'client_tool_resume',
			73,
			'job-123',
			array(
				'request_bytes'      => 2048,
				'request_size_class' => 'small',
			)
		);

		$rows = ProviderTrace::list( array( 'limit' => 1 ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 0, $rows[0]->status_code );
		$this->assertSame( 'provider_dns_failure', $rows[0]->error );

		$trace = ProviderTrace::get( $rows[0]->id );
		$this->assertNotNull( $trace );
		$diagnostics = json_decode( $trace->response_body, true );
		$this->assertIsArray( $diagnostics );
		$this->assertSame( 'provider_retry_exhausted', $diagnostics['event'] );
		$this->assertSame( 4, $diagnostics['attempts'] );
		$this->assertSame( 'client_tool_resume', $diagnostics['phase'] );
		$this->assertSame( 73, $diagnostics['session_id'] );
		$this->assertSame( 'job-123', $diagnostics['job_id'] );
		$this->assertStringNotContainsString( 'PRIVATE_HOST', $trace->response_body );
	}

	/** Timeout, connection, and rate-limit failures retain only bounded retry diagnostics. */
	public function test_retry_exhaustion_classifies_transport_and_rate_limit_failures(): void {
		ProviderTrace::set_enabled( true );
		ProviderTrace::clear();

		ProviderTraceLogger::record_retry_exhausted_failure(
			'openai',
			'gpt-test',
			new \WP_Error( 'http_request_failed', 'cURL error: Operation timed out after 30000 milliseconds' ),
			0,
			2,
			31000,
			null,
			array( 1 ),
			'initial_provider_call'
		);
		ProviderTraceLogger::record_retry_exhausted_failure(
			'openai',
			'gpt-test',
			new \WP_Error( 'http_request_failed', 'cURL error: Connection refused' ),
			0,
			2,
			2000,
			null,
			array( 1 ),
			'initial_provider_call'
		);
		ProviderTraceLogger::record_retry_exhausted_failure(
			'openai',
			'gpt-test',
			new \WP_Error( '429', 'Rate limit response' ),
			429,
			4,
			12000,
			12,
			array( 12, 12, 12 ),
			'initial_provider_call'
		);

		$rows = ProviderTrace::list( array( 'limit' => 3 ) );
		$this->assertCount( 3, $rows );
		$errors = array_column( $rows, 'error' );
		$this->assertContains( 'provider_timeout', $errors );
		$this->assertContains( 'provider_connection_failure', $errors );
		$this->assertContains( 'provider_rate_limited', $errors );

		$rate_limit = ProviderTrace::get( $rows[0]->id );
		$this->assertNotNull( $rate_limit );
		$this->assertSame( 12, json_decode( $rate_limit->response_body, true )['retry_after_seconds'] );
	}
}
