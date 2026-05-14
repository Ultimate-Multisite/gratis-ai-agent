<?php
/**
 * Google Gemini explicit prompt-cache strategy.
 *
 * Gemini's caching model differs fundamentally from Anthropic's: instead
 * of inline `cache_control` markers, it requires a **separate resource
 * lifecycle**:
 *
 *  1. Create a `cachedContents` resource via the Gemini REST API,
 *     containing the stable prefix (system instruction + tools + early
 *     conversation turns).
 *  2. Reference that resource by name in subsequent `generateContent`
 *     requests with `cachedContent: "cachedContents/{id}"`.
 *  3. The inline `systemInstruction` and `tools` keys are removed from the
 *     request body because they are now served from the cache resource,
 *     and the `contents` array is trimmed to only the current (new) turn.
 *
 * Resource creation and transient-backed lookup are delegated to
 * {@see GeminiCacheManager}. This strategy is responsible for:
 *
 *  - Recognising Gemini `generateContent` URLs.
 *  - Estimating whether the request is large enough to benefit.
 *  - Extracting the stable prefix from the body.
 *  - Splicing the returned resource name into the body.
 *  - Stripping now-redundant inline fields.
 *
 * Because the Gemini API key lives in the request headers or URL query
 * string (not in the JSON body), this strategy implements
 * {@see RequestContextAwareCacheStrategyInterface}: the
 * {@see HttpTraceHandler} calls {@see set_request_context()} before
 * {@see apply()} on every request so the key is available when the
 * cache-manager API call is made.
 *
 * Minimum cacheable size:
 *   Gemini requires ≥ 32 768 tokens to cache a prefix cost-effectively.
 *   Using a conservative 4 chars/token estimate: 32 768 × 4 ≈ 131 072.
 *   The constant is set to 128 000 to leave a small margin.
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
 * Mutates Gemini `:generateContent` request bodies for explicit prompt caching.
 */
final class GeminiCacheStrategy implements RequestContextAwareCacheStrategyInterface {

	/**
	 * Canonical hostname for the Gemini generative language API.
	 *
	 * @var string
	 */
	private const GEMINI_HOST = 'generativelanguage.googleapis.com';

	/**
	 * Minimum combined char count of the stable cacheable prefix before
	 * caching is attempted. Below this threshold the strategy is a no-op.
	 *
	 * @var int
	 */
	private const MIN_CHARS_FOR_CACHE = 128_000;

	/**
	 * API key extracted from the most recent {@see set_request_context()} call.
	 *
	 * Reset on every `set_request_context()` call to prevent cross-request
	 * leakage when the same strategy instance handles multiple requests.
	 *
	 * @var string
	 */
	private string $api_key = '';

	/**
	 * @param GeminiCacheManagerInterface|null $manager Optional injected manager —
	 *        primarily for unit tests. Leave null in production so the
	 *        default instance is created lazily on first use.
	 */
	public function __construct(
		private ?GeminiCacheManagerInterface $manager = null
	) {}

	/**
	 * @inheritDoc
	 */
	public function matches( string $url ): bool {
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return false;
		}

		$host = strtolower( (string) $parsed['host'] );
		if ( $host !== self::GEMINI_HOST && ! str_ends_with( $host, '.' . self::GEMINI_HOST ) ) {
			return false;
		}

		$path = $parsed['path'] ?? '';
		return str_contains( (string) $path, ':generateContent' );
	}

	/**
	 * @inheritDoc
	 *
	 * Returns `$body` unchanged when:
	 *  - No API key was supplied via {@see set_request_context()}.
	 *  - The stable prefix is below {@see MIN_CHARS_FOR_CACHE}.
	 *  - The conversation has fewer than 2 turns (nothing stable to cache).
	 *  - The cache manager fails to create or retrieve a resource.
	 */
	public function apply( array $body ): array {
		if ( '' === $this->api_key ) {
			return $body;
		}

		if ( ! $this->is_large_enough( $body ) ) {
			return $body;
		}

		$stable_prefix = $this->build_cacheable_prefix( $body );
		if ( empty( $stable_prefix ) ) {
			return $body;
		}

		$model  = $this->extract_model( $body );
		$system = $this->extract_system_text( $body );
		$tools  = $body['tools'] ?? array();
		$tools  = is_array( $tools ) ? array_values( $tools ) : array();

		$cache_name = $this->cache_manager()->find_or_create(
			$this->api_key,
			$model,
			$stable_prefix,
			$tools,
			$system
		);

		if ( null === $cache_name ) {
			// Cache creation failed — degrade silently, send full body.
			return $body;
		}

		// Reference the cache resource in the inference request.
		$body['cachedContent'] = $cache_name;

		// Trim `contents` to only the current (new) turn — the stable
		// prefix is now served from the cache resource.
		$all_contents = $body['contents'] ?? array();
		if ( is_array( $all_contents ) && count( $all_contents ) >= 2 ) {
			$body['contents'] = array_values( array_slice( $all_contents, -1 ) );
		}

		// Strip inline fields already present in the cache resource.
		// Sending them again alongside `cachedContent` conflicts with
		// Gemini's cached-content contract.
		unset( $body['systemInstruction'], $body['tools'] );

		return $body;
	}

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'google';
	}

	/**
	 * @inheritDoc
	 *
	 * Extracts the API key from (in priority order):
	 *  1. `Authorization: Bearer {key}` header.
	 *  2. `key` query parameter in the URL.
	 *
	 * @param array<string,mixed> $headers HTTP request headers.
	 * @param string              $url     Full request URL.
	 */
	public function set_request_context( array $headers, string $url ): void {
		$this->api_key = $this->resolve_api_key( $headers, $url );
	}

	/**
	 * Estimate whether the request prefix is large enough to benefit from
	 * Gemini's explicit prompt caching.
	 *
	 * Counts characters in `systemInstruction`, `tools`, and stable-prefix
	 * `contents` (all messages except the last turn) as a proxy for token
	 * count, using a conservative 4 chars/token factor.
	 *
	 * @param array<string,mixed> $body Decoded request body.
	 * @return bool
	 */
	private function is_large_enough( array $body ): bool {
		$char_count = 0;

		// System instruction.
		$system = $body['systemInstruction'] ?? null;
		if ( is_array( $system ) ) {
			foreach ( (array) ( $system['parts'] ?? array() ) as $part ) {
				if ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
					$char_count += strlen( $part['text'] );
				}
			}
		}

		// Tools — JSON-encode them to get a realistic char count.
		$tools = $body['tools'] ?? null;
		if ( is_array( $tools ) ) {
			$encoded = wp_json_encode( $tools );
			if ( is_string( $encoded ) ) {
				$char_count += strlen( $encoded );
			}
		}

		// Stable message turns (everything except the final turn).
		$contents = $body['contents'] ?? null;
		if ( is_array( $contents ) && count( $contents ) > 1 ) {
			$stable_count = count( $contents ) - 1;
			for ( $i = 0; $i < $stable_count; $i++ ) {
				$c = $contents[ $i ] ?? array();
				foreach ( (array) ( $c['parts'] ?? array() ) as $part ) {
					if ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
						$char_count += strlen( $part['text'] );
					}
				}
			}
		}

		return $char_count >= self::MIN_CHARS_FOR_CACHE;
	}

	/**
	 * Build the stable content array that will be stored in the cache resource.
	 *
	 * The stable prefix is all conversation turns EXCEPT the last one,
	 * which is the current (dynamic) turn. Single-turn conversations have
	 * nothing cacheable in `contents`.
	 *
	 * @param array<string,mixed> $body Decoded request body.
	 * @return array<int,mixed> Stable content items for the cache resource.
	 */
	private function build_cacheable_prefix( array $body ): array {
		$contents = $body['contents'] ?? array();
		if ( ! is_array( $contents ) || count( $contents ) < 2 ) {
			return array();
		}
		return array_values( array_slice( $contents, 0, -1 ) );
	}

	/**
	 * Extract the bare model ID from the request body.
	 *
	 * Gemini request bodies may or may not carry a `model` key; it is
	 * also encoded in the URL path. An empty string is safe to pass to
	 * the manager — it will be hashed as-is.
	 *
	 * @param array<string,mixed> $body Decoded request body.
	 * @return string Model ID, or empty string when absent.
	 */
	private function extract_model( array $body ): string {
		$model = $body['model'] ?? '';
		return is_string( $model ) ? $model : '';
	}

	/**
	 * Extract the system instruction text from the request body.
	 *
	 * Gemini uses `systemInstruction.parts[].text` shape. Concatenates
	 * all text parts into a single string.
	 *
	 * @param array<string,mixed> $body Decoded request body.
	 * @return string System instruction text, or empty string when absent.
	 */
	private function extract_system_text( array $body ): string {
		$system = $body['systemInstruction'] ?? null;
		if ( ! is_array( $system ) ) {
			return '';
		}

		$text = '';
		foreach ( (array) ( $system['parts'] ?? array() ) as $part ) {
			if ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
				$text .= $part['text'];
			}
		}

		return $text;
	}

	/**
	 * Resolve the Gemini API key from HTTP request headers or URL query string.
	 *
	 * Priority:
	 *  1. `Authorization: Bearer {key}` header (case-insensitive name match).
	 *  2. `key` query parameter in the URL.
	 *
	 * Returns empty string when neither source yields a non-empty value.
	 *
	 * @param array<string,mixed> $headers HTTP request headers.
	 * @param string              $url     Full request URL.
	 * @return string API key.
	 */
	private function resolve_api_key( array $headers, string $url ): string {
		foreach ( $headers as $name => $value ) {
			if ( ! is_string( $name ) || 'authorization' !== strtolower( $name ) ) {
				continue;
			}
			$value = is_string( $value ) ? $value : '';
			if ( str_starts_with( $value, 'Bearer ' ) ) {
				$key = substr( $value, 7 );
				if ( '' !== $key ) {
					return $key;
				}
			}
			break;
		}

		// Fallback: `?key=` query parameter (common for Gemini API clients).
		$parsed = wp_parse_url( $url );
		if ( is_array( $parsed ) && ! empty( $parsed['query'] ) ) {
			parse_str( (string) $parsed['query'], $query );
			if ( isset( $query['key'] ) && is_string( $query['key'] ) && '' !== $query['key'] ) {
				return $query['key'];
			}
		}

		return '';
	}

	/**
	 * Lazily instantiate the cache manager.
	 *
	 * @return GeminiCacheManagerInterface
	 */
	private function cache_manager(): GeminiCacheManagerInterface {
		if ( null === $this->manager ) {
			$this->manager = new GeminiCacheManager();
		}
		return $this->manager;
	}
}
