<?php
/**
 * Resolves the prompt-cache strategy to apply for a given outgoing
 * HTTP request.
 *
 * Lookup order:
 *   1. {@see CacheStrategyInterface::matches()} — strategies that need
 *      explicit body mutation (currently only Anthropic).
 *   2. Known automatic-cache hosts → {@see NoopCacheStrategy}.
 *   3. Filter `sd_ai_agent_resolve_cache_strategy` for custom providers.
 *   4. Null when no strategy applies (URL is not an LLM endpoint).
 *
 * @package SdAiAgent\Core\PromptCache
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Core\PromptCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picks a {@see CacheStrategyInterface} for an outgoing request URL.
 */
final class CacheStrategyResolver {

	/**
	 * Hosts where the provider performs server-side caching automatically.
	 *
	 * These providers do not require client-side `cache_control` markers —
	 * they cache prefixes server-side based on hash of the request and
	 * apply discounts via the response usage block.
	 *
	 * Sources (verified 2026-05):
	 *   - OpenAI: GPT-4o / 4.1 / o-series automatic caching for >=1024 tok.
	 *   - DeepSeek: automatic context caching, discount on hit.
	 *   - xAI Grok: automatic prompt caching since late 2025.
	 *   - Groq / Cerebras / Together / Fireworks: most have automatic
	 *     caching; surface in usage varies but no client opt-in needed.
	 *
	 * @var array<int,string>
	 */
	private const AUTO_CACHE_HOSTS = array(
		'api.openai.com',
		'api.deepseek.com',
		'api.x.ai',
		'api.groq.com',
		'api.cerebras.ai',
		'api.together.xyz',
		'api.fireworks.ai',
	);

	/**
	 * Ordered list of explicit-marker strategies.
	 *
	 * @var list<CacheStrategyInterface>
	 */
	private array $strategies;

	/**
	 * Fallback strategy when an LLM host is known to auto-cache.
	 *
	 * @var NoopCacheStrategy
	 */
	private NoopCacheStrategy $noop;

	/**
	 * @param list<CacheStrategyInterface>|null $strategies Optional
	 *        explicit list — primarily for tests. Production code should
	 *        leave this null so the default strategy set is used.
	 */
	public function __construct( ?array $strategies = null ) {
		if ( null === $strategies ) {
			$strategies = array(
				new AnthropicCacheStrategy(),
			);
		}
		$this->strategies = $strategies;
		$this->noop       = new NoopCacheStrategy();
	}

	/**
	 * Resolve a strategy for an outgoing HTTP request URL.
	 *
	 * @param string $url Fully-qualified URL.
	 * @return CacheStrategyInterface|null Strategy to apply, or null when
	 *                                     the URL is not a known LLM host.
	 */
	public function resolve( string $url ): ?CacheStrategyInterface {
		foreach ( $this->strategies as $strategy ) {
			if ( $strategy->matches( $url ) ) {
				return $strategy;
			}
		}

		if ( $this->is_auto_cache_host( $url ) ) {
			return $this->noop;
		}

		/**
		 * Filter to provide a custom cache strategy for an unknown URL.
		 *
		 * Return a {@see CacheStrategyInterface} instance to opt in,
		 * or null/false to skip caching for this request.
		 *
		 * @param CacheStrategyInterface|null $strategy Default: null.
		 * @param string                      $url      The request URL.
		 */
		$custom = apply_filters( 'sd_ai_agent_resolve_cache_strategy', null, $url );
		if ( $custom instanceof CacheStrategyInterface ) {
			return $custom;
		}

		return null;
	}

	/**
	 * Whether the URL's host is a known auto-caching LLM endpoint.
	 *
	 * @param string $url Fully-qualified URL.
	 * @return bool
	 */
	private function is_auto_cache_host( string $url ): bool {
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return false;
		}

		$host = strtolower( (string) $parsed['host'] );

		foreach ( self::AUTO_CACHE_HOSTS as $known ) {
			if ( $host === $known || str_ends_with( $host, '.' . $known ) ) {
				return true;
			}
		}

		return false;
	}
}
