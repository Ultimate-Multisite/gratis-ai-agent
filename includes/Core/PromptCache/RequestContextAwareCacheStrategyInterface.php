<?php
/**
 * Extension of {@see CacheStrategyInterface} for strategies that need
 * HTTP request context (headers, URL) before body mutation.
 *
 * Gemini's explicit prompt-cache strategy must call the Gemini
 * `cachedContents` API with the same API key that authenticates the
 * `generateContent` request. That key is in the request headers or URL
 * query string — not in the JSON body. Strategies that require this
 * information implement this interface, and {@see HttpTraceHandler}
 * calls {@see set_request_context()} before invoking {@see apply()}.
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
 * Additional contract for cache strategies that require outgoing HTTP
 * request context before they can mutate the body.
 *
 * Implementors MUST remain safe to call `apply()` when
 * `set_request_context()` was never invoked — return `$body` unchanged in
 * that case to avoid breaking requests.
 */
interface RequestContextAwareCacheStrategyInterface extends CacheStrategyInterface {

	/**
	 * Supply the HTTP request context needed for context-dependent caching.
	 *
	 * Must be called before {@see apply()} on each request. Implementations
	 * MUST NOT throw — store what they can and degrade gracefully when
	 * expected fields are missing.
	 *
	 * @param array<string,mixed> $headers HTTP request headers (mixed values;
	 *                                    string values are the common case).
	 * @param string              $url     Full outgoing request URL.
	 * @return void
	 */
	public function set_request_context( array $headers, string $url ): void;
}
