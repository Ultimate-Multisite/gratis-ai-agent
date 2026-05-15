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

use SdAiAgent\Core\ProviderTraceLogger;
use WP_UnitTestCase;

/**
 * @covers \SdAiAgent\Core\ProviderTraceLogger::resolve_provider_for_trace
 * @covers \SdAiAgent\Core\ProviderTraceLogger::match_provider
 */
class ProviderTraceLoggerResolveTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'sd_ai_agent_trace_match_provider' );
		remove_all_filters( 'sd_ai_agent_trace_resolve_provider' );
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
}
