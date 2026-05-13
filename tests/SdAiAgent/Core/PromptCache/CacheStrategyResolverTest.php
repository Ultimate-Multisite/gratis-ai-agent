<?php
/**
 * Test case for CacheStrategyResolver.
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

	public function test_resolves_noop_for_openai(): void {
		$resolver = new CacheStrategyResolver();
		$strategy = $resolver->resolve( 'https://api.openai.com/v1/chat/completions' );

		$this->assertInstanceOf( NoopCacheStrategy::class, $strategy );
	}

	public function test_resolves_noop_for_deepseek(): void {
		$resolver = new CacheStrategyResolver();
		$strategy = $resolver->resolve( 'https://api.deepseek.com/chat/completions' );

		$this->assertInstanceOf( NoopCacheStrategy::class, $strategy );
	}

	public function test_resolves_noop_for_xai(): void {
		$resolver = new CacheStrategyResolver();
		$strategy = $resolver->resolve( 'https://api.x.ai/v1/chat/completions' );

		$this->assertInstanceOf( NoopCacheStrategy::class, $strategy );
	}

	public function test_returns_null_for_unknown_host(): void {
		$resolver = new CacheStrategyResolver();
		$strategy = $resolver->resolve( 'https://example.com/api/something' );

		$this->assertNull( $strategy );
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
		$body = array( 'model' => 'gpt-4o', 'messages' => array() );

		$this->assertSame( $body, $noop->apply( $body ) );
		$this->assertSame( 'noop', $noop->id() );
	}
}
