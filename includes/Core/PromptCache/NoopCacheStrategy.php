<?php
/**
 * No-op prompt cache strategy.
 *
 * Used for providers that perform prompt caching automatically server-side
 * (OpenAI, DeepSeek, xAI Grok, Groq, Cerebras, Together, Fireworks, and
 * any OpenAI-compatible endpoint that implements the standard pricing tier
 * for prompt caching). These providers do NOT require client-side cache
 * markers — they hash the input prefix automatically and apply the cache
 * discount when they see a known prefix within the cache TTL window.
 *
 * The strategy still exists (rather than returning null from the resolver)
 * so that future server-side cache telemetry can be hooked in here without
 * the caller having to special-case "no strategy" vs "strategy with no
 * request-body mutation".
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
	 * The resolver matches NoopCacheStrategy explicitly via its own list
	 * of "automatic-cache" providers, so this strategy never matches a
	 * URL on its own — the resolver picks it as a fallback.
	 *
	 * @inheritDoc
	 */
	public function matches( string $url ): bool {
		unset( $url ); // Unused — matching is delegated to the resolver.
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
