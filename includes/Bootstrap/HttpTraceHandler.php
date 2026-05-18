<?php
/**
 * DI handler for LLM provider HTTP trace hooks.
 *
 * Replaces the `ProviderTraceLogger::register()` call in CoreServicesHandler
 * by wiring the two WordPress HTTP-API filters directly via `#[Filter]`
 * attributes.
 *
 * The underlying recording logic lives in
 * {@see \SdAiAgent\Core\ProviderTraceLogger}. This handler is a thin
 * DI bridge — its sole responsibility is hook registration and arg forwarding.
 *
 * @package SdAiAgent\Bootstrap
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Bootstrap;

use SdAiAgent\Core\PromptCache\CacheStrategyResolver;
use SdAiAgent\Core\PromptCache\RequestContextAwareCacheStrategyInterface;
use SdAiAgent\Core\ProviderTraceLogger;
use SdAiAgent\Core\Settings;
use SdAiAgent\Infrastructure\Schema\EmptyJsonObject;
use SdAiAgent\Infrastructure\Schema\SchemaNormalizer;
use SdAiAgent\Models\ProviderTrace;
use XWP\DI\Decorators\Filter;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures outgoing HTTP requests and responses for LLM provider tracing,
 * and injects provider-specific prompt-cache markers into outgoing bodies.
 *
 * CTX_GLOBAL ensures the filters are active in every request context — AI
 * calls can originate from admin (manual runs), REST (webhook triggers), CLI,
 * and cron (scheduled tasks).
 *
 * The trace callbacks are no-ops when WP_DEBUG is not active; the cache
 * marker injection is always active (cache hints are a runtime cost
 * optimisation, not a debug feature) but is gated on the
 * `prompt_caching_enabled` setting so operators can opt out.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_GLOBAL,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class HttpTraceHandler {

	/**
	 * Resolves the prompt-cache strategy for a given URL. Lazily
	 * instantiated on first use so unit tests can replace the wiring
	 * via the `sd_ai_agent_resolve_cache_strategy` filter without
	 * having to provide a constructor.
	 *
	 * @var CacheStrategyResolver|null
	 */
	private ?CacheStrategyResolver $cache_resolver = null;

	/**
	 * Capture outgoing request details before the HTTP call is made.
	 *
	 * Returns `$preempt` unchanged — this filter is used only for its
	 * side-effect of recording in-flight request metadata. No-op when
	 * WP_DEBUG is not active.
	 *
	 * @param false|array<string,mixed>|\WP_Error $preempt     A preemptive return value. Default false.
	 * @param array<string,mixed>                 $parsed_args HTTP request arguments.
	 * @param string                              $url         The request URL.
	 * @return false|array<string,mixed>|\WP_Error Unchanged $preempt.
	 */
	#[Filter( tag: 'pre_http_request', priority: 10 )]
	public function on_pre_http_request( mixed $preempt, array $parsed_args, string $url ): mixed {
		if ( ! ProviderTrace::is_debug_mode() ) {
			return $preempt;
		}
		return ProviderTraceLogger::on_pre_http_request( $preempt, $parsed_args, $url );
	}

	/**
	 * Capture response details and write a trace record.
	 *
	 * Returns `$response` unchanged — this filter is used only for its
	 * side-effect of persisting the completed trace row. No-op when
	 * WP_DEBUG is not active.
	 *
	 * @param array<string,mixed> $response    HTTP response array.
	 * @param array<string,mixed> $parsed_args HTTP request arguments.
	 * @param string              $url         The request URL.
	 * @return array<string,mixed> Unchanged $response.
	 */
	#[Filter( tag: 'http_response', priority: 10 )]
	public function on_http_response( array $response, array $parsed_args, string $url ): array {
		if ( ! ProviderTrace::is_debug_mode() ) {
			return $response;
		}
		return ProviderTraceLogger::on_http_response( $response, $parsed_args, $url );
	}

	/**
	 * Inject prompt-cache markers into outgoing LLM provider requests.
	 *
	 * Runs at priority 9 — one priority earlier than the trace logger's
	 * `pre_http_request` so that traced requests reflect what is actually
	 * sent on the wire. The resolver picks a strategy by URL host; if
	 * no strategy matches (request is not an LLM endpoint, or
	 * `prompt_caching_enabled` is off), the args pass through unchanged.
	 *
	 * Failures during JSON decode/encode degrade silently: any exception
	 * or invalid body shape returns `$parsed_args` untouched so a
	 * malformed cache hint never breaks a working request.
	 *
	 * @param array<string,mixed> $parsed_args HTTP request arguments.
	 * @param string              $url         The request URL.
	 * @return array<string,mixed> Possibly-mutated arguments.
	 */
	#[Filter( tag: 'http_request_args', priority: 9 )]
	public function on_http_request_args( array $parsed_args, string $url ): array {
		if ( ! Settings::is_prompt_caching_enabled() ) {
			return $parsed_args;
		}

		$strategy = $this->resolver()->resolve( $url );
		if ( null === $strategy ) {
			return $parsed_args;
		}

		// Only mutate when there's a body shaped like JSON — we don't
		// know how to decorate form-encoded or multipart bodies and
		// shouldn't try.
		$body = $parsed_args['body'] ?? null;
		if ( ! is_string( $body ) || '' === $body ) {
			return $parsed_args;
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return $parsed_args;
		}

		// Strategies that need HTTP context (headers, URL) to resolve their
		// API key or other request-level data must implement
		// RequestContextAwareCacheStrategyInterface so we can supply that
		// context before body mutation happens.
		if ( $strategy instanceof RequestContextAwareCacheStrategyInterface ) {
			$headers = is_array( $parsed_args['headers'] ?? null ) ? $parsed_args['headers'] : array();
			$strategy->set_request_context( $headers, $url );
		}

		$mutated = $strategy->apply( $decoded );
		if ( $mutated === $decoded ) {
			// Strategy chose not to mutate — skip the re-encode.
			return $parsed_args;
		}

		// Repair empty JSON-object positions BEFORE re-encoding. The
		// json_decode / json_encode round-trip above collapses every JSON
		// `{}` into a PHP empty array, which then re-serialises as `[]` —
		// a different JSON type that triggers Anthropic 400 responses.
		//
		// `repair_tool_schemas()` restores the canonical empty
		// `properties` / `items` objects required by JSON Schema
		// draft-2020-12 in tool *definitions* (sd-ai-0nm,
		// `tools.N.custom.input_schema: JSON schema is invalid`).
		//
		// `repair_message_tool_uses()` restores the empty `tool_use.input`
		// object required for parameterless tool calls in conversation
		// history (sd-ai-mtu,
		// `messages.N.content.M.tool_use.input: Input should be an object`).
		$mutated = $this->repair_tool_schemas( $mutated );
		$mutated = $this->repair_message_tool_uses( $mutated );

		$encoded = wp_json_encode( $mutated );
		if ( false === $encoded ) {
			return $parsed_args;
		}

		$parsed_args['body'] = $encoded;
		return $parsed_args;
	}

	/**
	 * Restore JSON-Schema empty-object encoding in tool input schemas.
	 *
	 * The provider-cache decorator decodes the outgoing body with
	 * `json_decode($body, true)`, mutates a few keys, and re-encodes via
	 * `wp_json_encode()`. The decode step destroys every `{}` (empty
	 * object) by collapsing it to a PHP `[]` (empty array), which then
	 * re-encodes as `[]` — a different JSON type that breaks JSON Schema
	 * draft-2020-12 validators in Anthropic and others.
	 *
	 * This walker reapplies {@see SchemaNormalizer::to_json_safe()} to the
	 * known schema-bearing positions across all major provider body shapes:
	 *
	 *   - Anthropic: `tools[*].input_schema`
	 *   - OpenAI:    `tools[*].function.parameters`
	 *   - Gemini:    `tools[*].functionDeclarations[*].parameters`
	 *
	 * Other body regions are left untouched, so this is safe to call
	 * regardless of which strategy ran (or whether one ran at all).
	 *
	 * @param array<string,mixed> $body Decoded request body.
	 * @return array<string,mixed> Body with empty-object placeholders restored.
	 */
	private function repair_tool_schemas( array $body ): array {
		if ( ! isset( $body['tools'] ) || ! is_array( $body['tools'] ) ) {
			return $body;
		}

		foreach ( $body['tools'] as $i => $tool ) {
			if ( ! is_array( $tool ) ) {
				continue;
			}

			// Anthropic shape: tools[*].input_schema is a JSON Schema.
			if ( isset( $tool['input_schema'] ) && is_array( $tool['input_schema'] ) ) {
				$tool['input_schema'] = SchemaNormalizer::to_json_safe( $tool['input_schema'] );
			}

			// OpenAI shape: tools[*].function.parameters is a JSON Schema.
			if (
				isset( $tool['function'] ) && is_array( $tool['function'] ) &&
				isset( $tool['function']['parameters'] ) && is_array( $tool['function']['parameters'] )
			) {
				$tool['function']['parameters'] = SchemaNormalizer::to_json_safe(
					$tool['function']['parameters']
				);
			}

			// Gemini shape: tools[*].functionDeclarations[*].parameters.
			if ( isset( $tool['functionDeclarations'] ) && is_array( $tool['functionDeclarations'] ) ) {
				foreach ( $tool['functionDeclarations'] as $j => $decl ) {
					if (
						is_array( $decl ) &&
						isset( $decl['parameters'] ) && is_array( $decl['parameters'] )
					) {
						$decl['parameters']                 = SchemaNormalizer::to_json_safe( $decl['parameters'] );
						$tool['functionDeclarations'][ $j ] = $decl;
					}
				}
			}

			$body['tools'][ $i ] = $tool;
		}

		return $body;
	}

	/**
	 * Restore JSON-object encoding for `tool_use.input` across assistant
	 * message history.
	 *
	 * Anthropic's API requires every `tool_use.input` to be a JSON object
	 * (matching the corresponding tool's `input_schema`, which itself
	 * must be a JSON Schema draft-2020-12 `object`). Even parameterless
	 * tools must emit `"input": {}` and never `"input": []`.
	 *
	 * The text-generation models in both
	 * `WordPress/ai-provider-for-anthropic` (PR #23) and
	 * `Ultimate-Multisite/ai-provider-for-anthropic-max` already
	 * normalise `null` / empty PHP arrays returned by
	 * `FunctionCall::getArgs()` to `stdClass` when building the request
	 * body, so the body that reaches WordPress's HTTP API correctly
	 * encodes parameterless tool calls as `"input": {}`. When prompt
	 * caching is enabled, however, this filter then decodes the body
	 * with `json_decode($body, true)`, collapsing the empty object back
	 * to a PHP `[]`, and re-encodes with `wp_json_encode()` which emits
	 * `[]`. Anthropic rejects the next request with:
	 *
	 *   400 Bad Request — messages.N.content.M.tool_use.input: Input should be an object
	 *
	 * Reproduces deterministically on the second turn of any
	 * conversation that previously invoked a parameterless ability
	 * (`memory-list`, `ability-search`, `skill-list`, etc.) — the
	 * assistant turn carrying the prior `tool_use` block is replayed
	 * and the round-trip corruption fires.
	 *
	 * This walker visits every `messages[*].content[*]` block of type
	 * `tool_use` and, if `input` is an empty PHP array, restores an
	 * `EmptyJsonObject` placeholder so re-encoding produces `{}` on the
	 * wire.
	 *
	 * Scope: only the top-level `input` is repaired here, matching the
	 * single path Anthropic's API enforces as "must be an object".
	 * Empty objects nested inside a non-empty input (e.g.
	 * `{"filters": {}}`) would require a parallel-decode pass that
	 * tracks the original JSON type of every empty container and are
	 * out of scope here — they are not currently known to trigger
	 * Anthropic 400s when nested inside a non-empty object.
	 *
	 * @param array<string,mixed> $body Decoded request body.
	 * @return array<string,mixed> Body with empty `tool_use.input` placeholders restored.
	 */
	private function repair_message_tool_uses( array $body ): array {
		if ( ! isset( $body['messages'] ) || ! is_array( $body['messages'] ) ) {
			return $body;
		}

		foreach ( $body['messages'] as $mi => $msg ) {
			if ( ! is_array( $msg ) || ! isset( $msg['content'] ) || ! is_array( $msg['content'] ) ) {
				continue;
			}

			foreach ( $msg['content'] as $ci => $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}

				if ( 'tool_use' !== ( $block['type'] ?? '' ) ) {
					continue;
				}

				if ( ! array_key_exists( 'input', $block ) ) {
					continue;
				}

				$input = $block['input'];

				if ( is_array( $input ) && array() === $input ) {
					$block['input']                            = new EmptyJsonObject();
					$body['messages'][ $mi ]['content'][ $ci ] = $block;
				}
			}
		}

		return $body;
	}

	/**
	 * Lazily get (and cache) the strategy resolver.
	 *
	 * @return CacheStrategyResolver
	 */
	private function resolver(): CacheStrategyResolver {
		if ( null === $this->cache_resolver ) {
			$this->cache_resolver = new CacheStrategyResolver();
		}
		return $this->cache_resolver;
	}
}
