<?php
/**
 * Anthropic prompt-cache strategy.
 *
 * Anthropic is the only major LLM provider that requires explicit
 * `cache_control` markers in the request body to opt into prompt caching.
 * (OpenAI, DeepSeek, xAI Grok, and most others cache automatically based
 * on prefix stability.)
 *
 * This strategy adds `cache_control: { type: "ephemeral" }` markers at
 * two stable-prefix boundaries:
 *
 *   1. End of the `tools` array — caches the full tools definition block.
 *      Tools are byte-stable across turns of a single conversation as long
 *      as the abilities don't change.
 *
 *   2. End of the `system` array — caches the system prompt. The
 *      anthropic-max provider already builds `system` as a 2-block array
 *      (Claude Code identifier + user system instruction); we mark the
 *      LAST block so cache scope covers both blocks plus the tools above.
 *      The vanilla anthropic provider sets `system` as a string; we
 *      promote it to a one-block array in that case before marking.
 *
 * Anthropic's cache scope semantics: marking a block as `cache_control`
 * caches everything from the start of the request up to and including
 * that block. So a single marker at the end of `system` would already
 * cache `tools` too (since `tools` precedes `system` in the canonical
 * Anthropic message order). However, we mark BOTH boundaries because:
 *
 *   - Two markers give Anthropic two separate cache-breakpoint candidates,
 *     so partial reuse is possible if (e.g.) tools change but system doesn't.
 *   - Marking is free up to 4 breakpoints per request.
 *
 * We deliberately do NOT mark messages — the message history changes
 * every turn, so the marginal cache value is lower and the marker
 * placement is harder to keep correct as the conversation grows. Revisit
 * if telemetry shows meaningful re-use of mid-conversation prefixes.
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
 * Mutates Anthropic /v1/messages request bodies to enable prompt caching.
 */
final class AnthropicCacheStrategy implements CacheStrategyInterface {

	/**
	 * Anthropic's ephemeral cache control marker.
	 *
	 * `type: "ephemeral"` is the only supported value as of the 2025-10
	 * messages API. The default TTL is 5 minutes; passing `ttl: "1h"`
	 * extends it to one hour but is a separate pricing tier.
	 *
	 * @var array{type: string}
	 */
	private const MARKER = array( 'type' => 'ephemeral' );

	/**
	 * Minimum total tokens before applying cache markers.
	 *
	 * Anthropic only actually caches a region when it meets a minimum
	 * token count (1024 for Sonnet/Opus, 2048 for Haiku). Sending the
	 * marker below that threshold is harmless — Anthropic silently
	 * ignores it — but counts against the per-request marker budget.
	 *
	 * We use a conservative estimate of `strlen / 3` as a token proxy
	 * (Anthropic averages ~3.5 chars/token for English/code) to gate
	 * marker injection. Sending markers on tiny requests would burn
	 * marker budget without producing cache hits.
	 *
	 * @var int
	 */
	private const MIN_CHARS_FOR_CACHE = 4096;

	/**
	 * @inheritDoc
	 *
	 * Matches:
	 *   - Direct Anthropic API: `api.anthropic.com` and regional subdomains
	 *     such as `eu.api.anthropic.com`.
	 *   - Vertex AI Anthropic endpoints: hosts ending in
	 *     `-aiplatform.googleapis.com` with a path containing
	 *     `/publishers/anthropic/models/`. Vertex relays the request body
	 *     verbatim to Anthropic so the same `cache_control` markers apply.
	 *     Example URL:
	 *     `https://us-central1-aiplatform.googleapis.com/v1/projects/p/locations/us-central1/publishers/anthropic/models/claude-3-5-sonnet:rawPredict`
	 */
	public function matches( string $url ): bool {
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return false;
		}

		$host = strtolower( (string) $parsed['host'] );

		// Direct Anthropic API and regional subdomains (e.g. eu.api.anthropic.com).
		if ( 'api.anthropic.com' === $host || str_ends_with( $host, '.api.anthropic.com' ) ) {
			return true;
		}

		// Vertex AI Anthropic endpoints relay the standard Anthropic request
		// body verbatim, so cache_control markers work identically.
		// Host pattern: {region}-aiplatform.googleapis.com
		// Path pattern:  .../publishers/anthropic/models/...
		if ( str_ends_with( $host, '-aiplatform.googleapis.com' ) ) {
			$path = (string) ( $parsed['path'] ?? '' );
			return str_contains( $path, '/publishers/anthropic/models/' );
		}

		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function apply( array $body ): array {
		// Skip when the request is below the minimum cacheable size —
		// caching tiny requests burns marker budget for no benefit.
		if ( ! $this->is_large_enough( $body ) ) {
			return $body;
		}

		$body = $this->mark_tools( $body );
		$body = $this->mark_system( $body );

		return $body;
	}

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'anthropic';
	}

	/**
	 * Estimate whether the request is large enough to benefit from caching.
	 *
	 * @param array<string,mixed> $body Decoded request body.
	 * @return bool
	 */
	private function is_large_enough( array $body ): bool {
		$char_count = 0;

		// System is the bulk of the cacheable prefix in most cases.
		$system = $body['system'] ?? null;
		if ( is_string( $system ) ) {
			$char_count += strlen( $system );
		} elseif ( is_array( $system ) ) {
			foreach ( $system as $block ) {
				if ( is_array( $block ) && isset( $block['text'] ) && is_string( $block['text'] ) ) {
					$char_count += strlen( $block['text'] );
				}
			}
		}

		// Tools also contribute heavily — descriptions and JSON schemas.
		$tools = $body['tools'] ?? null;
		if ( is_array( $tools ) ) {
			foreach ( $tools as $tool ) {
				if ( ! is_array( $tool ) ) {
					continue;
				}
				$char_count += strlen( (string) ( $tool['name'] ?? '' ) );
				$char_count += strlen( (string) ( $tool['description'] ?? '' ) );
				if ( isset( $tool['input_schema'] ) ) {
					$char_count += strlen( (string) wp_json_encode( $tool['input_schema'] ) );
				}
			}
		}

		return $char_count >= self::MIN_CHARS_FOR_CACHE;
	}

	/**
	 * Add a cache marker to the last tool definition.
	 *
	 * Idempotent — if the last tool already has cache_control we leave
	 * it alone.
	 *
	 * @param array<string,mixed> $body Decoded request body.
	 * @return array<string,mixed>
	 */
	private function mark_tools( array $body ): array {
		if ( ! isset( $body['tools'] ) || ! is_array( $body['tools'] ) || empty( $body['tools'] ) ) {
			return $body;
		}

		// Reindex defensively — the array may have non-sequential keys.
		$tools     = array_values( $body['tools'] );
		$last_idx  = count( $tools ) - 1;
		$last_tool = $tools[ $last_idx ];

		if ( ! is_array( $last_tool ) ) {
			return $body;
		}

		if ( isset( $last_tool['cache_control'] ) ) {
			// Already marked — preserve caller intent.
			return $body;
		}

		$last_tool['cache_control'] = self::MARKER;
		$tools[ $last_idx ]         = $last_tool;
		$body['tools']              = $tools;

		return $body;
	}

	/**
	 * Add a cache marker to the last system block.
	 *
	 * If `system` is a string, promote it to a single-block array first
	 * so the marker has something to attach to.
	 *
	 * Idempotent — if the last block already has cache_control we leave
	 * it alone.
	 *
	 * @param array<string,mixed> $body Decoded request body.
	 * @return array<string,mixed>
	 */
	private function mark_system( array $body ): array {
		if ( ! isset( $body['system'] ) ) {
			return $body;
		}

		$system = $body['system'];

		// String form: promote to single-block array so we can attach
		// cache_control. Anthropic accepts both shapes.
		if ( is_string( $system ) ) {
			if ( '' === $system ) {
				return $body;
			}
			$system         = array(
				array(
					'type'          => 'text',
					'text'          => $system,
					'cache_control' => self::MARKER,
				),
			);
			$body['system'] = $system;
			return $body;
		}

		if ( ! is_array( $system ) || empty( $system ) ) {
			return $body;
		}

		$system     = array_values( $system );
		$last_idx   = count( $system ) - 1;
		$last_block = $system[ $last_idx ];

		if ( ! is_array( $last_block ) ) {
			return $body;
		}

		if ( isset( $last_block['cache_control'] ) ) {
			return $body;
		}

		$last_block['cache_control'] = self::MARKER;
		$system[ $last_idx ]         = $last_block;
		$body['system']              = $system;

		return $body;
	}
}
