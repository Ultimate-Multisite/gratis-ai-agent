<?php
/**
 * Test case for CacheStrategyResolver.
 *
 * The resolver only owns strategies the plugin ships directly
 * (Anthropic + Gemini). Any other provider is the responsibility of a
 * third-party connector plugin that can register its own strategy via
 * the `sd_ai_agent_resolve_cache_strategy` filter.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core\PromptCache;

use SdAiAgent\Core\PromptCache\AnthropicCacheStrategy;
use SdAiAgent\Core\PromptCache\CacheStrategyInterface;
use SdAiAgent\Core\PromptCache\CacheStrategyResolver;
use SdAiAgent\Core\PromptCache\GeminiCacheStrategy;
use SdAiAgent\Core\PromptCache\NoopCacheStrategy;
use WP_UnitTestCase;

/**
 * @covers \SdAiAgent\Core\PromptCache\CacheStrategyResolver
 */
class CacheStrategyResolverTest extends WP_UnitTestCase {

	public function test_resolves_anthropic_for_anthropic_api(): void {
		$resolver = new CacheStrategyResolver();
		$strategy = $resolver->resolve( 'https://api.anthropic.com/v1/messages' );

		$this->assertInstanceOf( AnthropicCacheStrategy::class, $strategy );
	}

	public function test_resolves_gemini_for_generate_content_url(): void {
		$resolver = new CacheStrategyResolver();
		$strategy = $resolver->resolve(
			'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent'
		);

		$this->assertInstanceOf( GeminiCacheStrategy::class, $strategy );
	}

	public function test_returns_null_for_gemini_cached_contents_endpoint(): void {
		// The cachedContents URL should NOT be treated as an LLM endpoint —
		// it's an internal management API call made by GeminiCacheManager.
		$resolver = new CacheStrategyResolver();
		$strategy = $resolver->resolve(
			'https://generativelanguage.googleapis.com/v1beta/cachedContents?key=abc'
		);

		$this->assertNull( $strategy );
	}

	public function test_returns_null_for_unknown_host(): void {
		$resolver = new CacheStrategyResolver();
		$strategy = $resolver->resolve( 'https://example.com/api/something' );

		$this->assertNull( $strategy );
	}

	/**
	 * Endpoints the plugin does NOT integrate with directly — they are
	 * reached only through third-party connector plugins — must NOT be
	 * matched by the built-in strategy set. Disclosure and per-provider
	 * cache handling for those endpoints is the connector's
	 * responsibility, not this plugin's.
	 *
	 * @dataProvider third_party_compatible_endpoints
	 */
	public function test_returns_null_for_third_party_compatible_endpoint( string $url ): void {
		$resolver = new CacheStrategyResolver();
		$this->assertNull( $resolver->resolve( $url ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function third_party_compatible_endpoints(): array {
		return array(
			'openai-compatible' => array( 'https://api.openai.com/v1/chat/completions' ),
			'azure-openai'      => array(
				'https://my-resource.openai.azure.com/openai/deployments/gpt-4o/chat/completions?api-version=2024-02-01',
			),
			'openrouter'        => array( 'https://openrouter.ai/api/v1/chat/completions' ),
		);
	}

	public function test_filter_can_provide_custom_strategy(): void {
		$resolver = new CacheStrategyResolver();
		$stub     = new class() implements CacheStrategyInterface {
			public function matches( string $url ): bool {
				return false;
			}
			public function apply( array $body ): array {
				return $body;
			}
			public function id(): string {
				return 'stub';
			}
		};

		$filter = static function ( $current, string $url ) use ( $stub ) {
			if ( str_contains( $url, 'example.com' ) ) {
				return $stub;
			}
			return $current;
		};
		add_filter( 'sd_ai_agent_resolve_cache_strategy', $filter, 10, 2 );

		$resolved = $resolver->resolve( 'https://example.com/api/something' );
		remove_filter( 'sd_ai_agent_resolve_cache_strategy', $filter, 10 );

		$this->assertSame( $stub, $resolved );
	}

	public function test_noop_strategy_is_pass_through(): void {
		$noop = new NoopCacheStrategy();
		$body = array(
			'model'    => 'gpt-4o',
			'messages' => array(),
		);

		$this->assertSame( $body, $noop->apply( $body ) );
		$this->assertSame( 'noop', $noop->id() );
	}

	public function test_resolves_anthropic_for_vertex_raw_predict(): void {
		// Vertex AI's Anthropic endpoint relays the standard Anthropic request
		// body verbatim, so cache_control markers work identically.
		$resolver = new CacheStrategyResolver();
		$strategy = $resolver->resolve(
			'https://us-central1-aiplatform.googleapis.com/v1/projects/p/locations/us-central1/publishers/anthropic/models/claude-3-5-sonnet:rawPredict'
		);

		$this->assertInstanceOf( AnthropicCacheStrategy::class, $strategy );
	}

	public function test_resolves_anthropic_for_vertex_stream_raw_predict(): void {
		// Streaming variant of the Vertex AI Anthropic endpoint.
		$resolver = new CacheStrategyResolver();
		$strategy = $resolver->resolve(
			'https://europe-west4-aiplatform.googleapis.com/v1/projects/my-project/locations/europe-west4/publishers/anthropic/models/claude-3-5-haiku:streamRawPredict'
		);

		$this->assertInstanceOf( AnthropicCacheStrategy::class, $strategy );
	}

	public function test_returns_null_for_vertex_non_anthropic_publisher(): void {
		// A Vertex endpoint for a non-Anthropic publisher should NOT match
		// the AnthropicCacheStrategy.
		$resolver = new CacheStrategyResolver();
		$strategy = $resolver->resolve(
			'https://us-central1-aiplatform.googleapis.com/v1/projects/p/locations/us-central1/publishers/google/models/gemini-2.0-flash:generateContent'
		);

		$this->assertNotInstanceOf( AnthropicCacheStrategy::class, $strategy );
	}
}
