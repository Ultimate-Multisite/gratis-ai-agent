<?php
/**
 * Resolves the prompt-cache strategy to apply for a given outgoing
 * HTTP request.
 *
 * The resolver only knows about strategies that need explicit body
 * mutation (currently Anthropic and Gemini). Their `matches()`
 * implementations gate on host names belonging to the AI providers the
 * plugin discloses in its readme. Any other provider — including all
 * OpenAI-compatible endpoints that perform server-side prompt caching
 * automatically — is the responsibility of the third-party connector
 * plugin that ships its provider integration; this plugin does not
 * maintain a list of those hostnames.
 *
 * Third-party integrators that need to inject a strategy for a custom
 * endpoint can hook the `sd_ai_agent_resolve_cache_strategy` filter.
 *
 * Lookup order:
 *   1. {@see CacheStrategyInterface::matches()} — strategies that need
 *      explicit body mutation (Anthropic and Gemini).
 *   2. Filter `sd_ai_agent_resolve_cache_strategy` for custom providers.
 *   3. Null when no strategy applies (caller leaves the request unchanged).
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
	 * Ordered list of explicit-marker strategies.
	 *
	 * @var list<CacheStrategyInterface>
	 */
	private array $strategies;

	/**
	 * @param list<CacheStrategyInterface>|null $strategies Optional
	 *        explicit list — primarily for tests. Production code should
	 *        leave this null so the default strategy set is used.
	 */
	public function __construct( ?array $strategies = null ) {
		if ( null === $strategies ) {
			$strategies = array(
				new AnthropicCacheStrategy(),
				new GeminiCacheStrategy(),
			);
		}
		$this->strategies = $strategies;
	}

	/**
	 * Resolve a strategy for an outgoing HTTP request URL.
	 *
	 * @param string $url Fully-qualified URL.
	 * @return CacheStrategyInterface|null Strategy to apply, or null when
	 *                                     no strategy claims the URL.
	 */
	public function resolve( string $url ): ?CacheStrategyInterface {
		foreach ( $this->strategies as $strategy ) {
			if ( $strategy->matches( $url ) ) {
				return $strategy;
			}
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
}
