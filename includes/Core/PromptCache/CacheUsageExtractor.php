<?php
/**
 * Universal cache-usage extractor for LLM provider responses.
 *
 * Each provider reports prompt cache hit/miss counts in a different shape:
 *
 *   Anthropic:
 *     usage.cache_creation_input_tokens (write to cache)
 *     usage.cache_read_input_tokens     (read from cache)
 *
 *   OpenAI (and most OpenAI-compatible providers):
 *     usage.prompt_tokens_details.cached_tokens (read from cache only — write is implicit)
 *
 *   DeepSeek:
 *     usage.prompt_cache_hit_tokens   (read)
 *     usage.prompt_cache_miss_tokens  (write — fresh tokens added to cache)
 *
 *   Google Gemini:
 *     usageMetadata.cachedContentTokenCount (read from explicit cache)
 *
 * This extractor normalises all of them to a `{creation, read}` pair so
 * the rest of the plugin (DB schema, viewer, telemetry) can stay
 * provider-agnostic.
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
 * Parses provider-specific response usage blocks into normalised cache counts.
 */
final class CacheUsageExtractor {

	/**
	 * Extract normalised cache-token counts from a decoded response body.
	 *
	 * Returns zeroes when the response does not carry cache telemetry —
	 * either because the provider doesn't report it, the request was an
	 * error, or this URL isn't an LLM endpoint.
	 *
	 * @param string $provider_id Provider slug (e.g. `anthropic`, `openai`).
	 * @param mixed  $response    Decoded response body. Typically array,
	 *                            but defensively handles any input — this
	 *                            is called from the HTTP filter chain
	 *                            where shapes can be unusual.
	 * @return array{creation:int, read:int}
	 */
	public static function extract( string $provider_id, $response ): array {
		$zero = array(
			'creation' => 0,
			'read'     => 0,
		);

		if ( ! is_array( $response ) ) {
			return $zero;
		}

		$usage = $response['usage'] ?? $response['usageMetadata'] ?? null;
		if ( ! is_array( $usage ) ) {
			return $zero;
		}

		switch ( $provider_id ) {
			case 'anthropic':
				return array(
					'creation' => self::clamp_non_negative( $usage['cache_creation_input_tokens'] ?? 0 ),
					'read'     => self::clamp_non_negative( $usage['cache_read_input_tokens'] ?? 0 ),
				);

			case 'deepseek':
				return array(
					'creation' => self::clamp_non_negative( $usage['prompt_cache_miss_tokens'] ?? 0 ),
					'read'     => self::clamp_non_negative( $usage['prompt_cache_hit_tokens'] ?? 0 ),
				);

			case 'google':
				return array(
					'creation' => 0,
					'read'     => self::clamp_non_negative( $usage['cachedContentTokenCount'] ?? 0 ),
				);

			case 'openai':
			case 'xai':
			case 'groq':
			case 'cerebras':
			case 'together':
			case 'fireworks':
			default:
				// OpenAI shape — used by OpenAI itself and most
				// OpenAI-compatible providers (DeepSeek's own API uses
				// the non-standard prompt_cache_*_tokens fields, handled
				// above). Fall through for unknown providers since the
				// OpenAI shape is the de-facto standard.
				$details = $usage['prompt_tokens_details'] ?? array();
				if ( ! is_array( $details ) ) {
					$details = array();
				}
				return array(
					'creation' => 0,
					'read'     => self::clamp_non_negative( $details['cached_tokens'] ?? 0 ),
				);
		}
	}

	/**
	 * Coerce a mixed value to a non-negative int.
	 *
	 * Providers occasionally report bogus shapes (string, null, float).
	 * Anything that doesn't cast cleanly to a positive int returns zero.
	 *
	 * @param mixed $value Raw value from response usage block.
	 * @return int Non-negative integer.
	 */
	private static function clamp_non_negative( $value ): int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : 0;
		}
		if ( is_numeric( $value ) ) {
			$int = (int) $value;
			return $int > 0 ? $int : 0;
		}
		return 0;
	}
}
