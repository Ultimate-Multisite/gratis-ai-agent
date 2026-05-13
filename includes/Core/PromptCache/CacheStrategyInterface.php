<?php
/**
 * Prompt cache strategy contract — provider-aware mutation of outgoing
 * LLM HTTP requests so that providers requiring explicit cache markers
 * (currently only Anthropic) can opt into prompt caching.
 *
 * Strategies operate on the parsed JSON body of `$parsed_args['body']`
 * via the `http_request_args` filter. Each provider has its own concrete
 * strategy; the {@see CacheStrategyResolver} picks one per request.
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
 * Contract for per-provider prompt cache strategies.
 *
 * Each strategy is responsible for:
 *   1. Reporting whether it applies to a given URL (so the resolver can
 *      hand off without parsing bodies unnecessarily).
 *   2. Mutating the decoded request body in-place to add cache markers
 *      in the shape that provider expects.
 *
 * Strategies MUST be deterministic for byte-stable inputs — any randomness
 * (timestamps, random IDs) injected into cached regions would defeat the
 * cache by changing the prefix hash on every turn.
 */
interface CacheStrategyInterface {

	/**
	 * Whether this strategy applies to a given outgoing HTTP request.
	 *
	 * Implementations should match on the host (e.g. `api.anthropic.com`)
	 * and return false otherwise — the resolver iterates strategies and
	 * uses the first match.
	 *
	 * @param string $url The fully-qualified request URL.
	 * @return bool True when this strategy should be invoked.
	 */
	public function matches( string $url ): bool;

	/**
	 * Mutate a decoded request body to add cache markers.
	 *
	 * Implementations MUST NOT throw — they receive untrusted input shapes
	 * and must degrade silently when fields they expect are missing. The
	 * goal is to never break a working request; cache hints are advisory.
	 *
	 * Implementations MUST be idempotent — calling apply() twice on the
	 * same body must produce the same result as one call. This protects
	 * against the unlikely case of filter re-entry.
	 *
	 * @param array<string,mixed> $body Decoded request body (associative).
	 * @return array<string,mixed> Mutated body. Returned by value; callers
	 *                             re-encode to JSON and re-assign to
	 *                             `$parsed_args['body']`.
	 */
	public function apply( array $body ): array;

	/**
	 * Stable identifier for telemetry/logging.
	 *
	 * @return string Lowercase ASCII slug (e.g. `anthropic`, `noop`).
	 */
	public function id(): string;
}
