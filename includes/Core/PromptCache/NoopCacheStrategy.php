<?php
/**
 * No-op prompt cache strategy.
 *
 * Pass-through strategy provided for any OpenAI-compatible endpoint that
 * performs prompt caching automatically server-side. Such endpoints do
 * not require client-side cache markers — they hash the input prefix
 * automatically and apply the cache discount when they see a known
 * prefix within the cache TTL window.
 *
 * This strategy is not registered by default. Third-party connector
 * plugins that want to opt their custom endpoint into the prompt-cache
 * pipeline (for example to attach future telemetry hooks) can return
 * an instance via the `sd_ai_agent_resolve_cache_strategy` filter on
 * {@see CacheStrategyResolver}.
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
 * Pass-through strategy for providers with automatic prompt caching.
 */
final class NoopCacheStrategy implements CacheStrategyInterface {

	/**
	 * The resolver does not register this strategy by default; third-party
	 * integrators select it explicitly via the
	 * `sd_ai_agent_resolve_cache_strategy` filter, so {@see matches()}
	 * never returns true on its own.
	 *
	 * @inheritDoc
	 */
	public function matches( string $url ): bool {
		unset( $url ); // Unused — matching is delegated to the filter.
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function apply( array $body ): array {
		return $body;
	}

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'noop';
	}
}
