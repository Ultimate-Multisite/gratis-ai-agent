<?php
/**
 * Per-model capability registry — transient-backed lookup with provider →
 * static-catalog → fallback resolution.
 *
 * The static catalog in {@see Settings::MODEL_MAX_OUTPUT_TOKENS} works for the
 * core OpenAI/Anthropic/Google families but cannot keep up with the long tail
 * of Synthetic-hosted Hugging Face models (Kimi K2.6, GLM, Nemotron, etc.)
 * whose advertised `max_output_length` drifts with every model bump.
 *
 * This registry caches per-model caps in a transient keyed by md5 of the
 * model ID. The transient is populated by {@see ModelCapabilityHandler}
 * which sniffs `/models` HTTP responses (where the connector strips the
 * field before it reaches the SDK). Resolution order:
 *
 *   1. Live provider transient (TTL 7d) — set by the `http_response` filter.
 *   2. Static catalog seed in {@see Settings::MODEL_MAX_OUTPUT_TOKENS} —
 *      longest-prefix match. The exact-match path keeps the existing
 *      regression coverage from sd-ai-7rl.
 *   3. {@see Settings::MAX_OUTPUT_TOKENS_FALLBACK} (8192).
 *
 * The `sd_ai_agent_max_output_tokens_for_model` filter on the resolver
 * output stays the final word — deployments can still pin a value
 * regardless of provider claims.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and retrieves per-model capability data (max output tokens,
 * context length) sourced from live provider `/models` responses.
 */
class ModelCapabilityRegistry {

	/**
	 * Transient key prefix for cached per-model capabilities. The full key
	 * is `{PREFIX}{md5(model_id)}` to keep the option name length bounded
	 * regardless of how exotic the model ID is (Synthetic models can carry
	 * long org/name slashes).
	 */
	public const TRANSIENT_PREFIX = 'sd_ai_agent_model_caps_';

	/**
	 * Default TTL for provider-sourced entries — 7 days.
	 *
	 * Provider catalogs are stable across the day; refreshing weekly is
	 * sufficient. Stale entries do not break anything (they just emit a
	 * slightly outdated cap); a wrong value is preferred over an empty
	 * cache miss because requests must succeed even when the provider
	 * `/models` endpoint is unreachable.
	 */
	public const PROVIDER_TTL_SECONDS = 7 * DAY_IN_SECONDS;

	/**
	 * Source marker for entries populated from a live provider response.
	 */
	public const SOURCE_PROVIDER = 'provider';

	/**
	 * Source marker for entries derived from {@see Settings::MODEL_MAX_OUTPUT_TOKENS}.
	 *
	 * Returned by {@see get()} when no provider entry is cached so callers
	 * can distinguish "we measured this live" from "this is the catalog".
	 */
	public const SOURCE_CATALOG = 'catalog';

	/**
	 * Source marker when neither a transient nor a catalog match exists
	 * and the caller is given the global fallback.
	 */
	public const SOURCE_FALLBACK = 'fallback';

	/**
	 * Resolve the max-output-tokens cap for a model.
	 *
	 * Consults the transient first; falls back to the static catalog via
	 * {@see Settings::resolve_max_output_tokens_from_catalog()}; finally
	 * returns {@see Settings::MAX_OUTPUT_TOKENS_FALLBACK} (the
	 * {@see Settings} resolver applies the user filter and ceiling clamp
	 * on top of whichever value we return here).
	 *
	 * @param string $model_id Provider-advertised model identifier.
	 * @return int Max output tokens. Always positive; never clamped to ceiling here.
	 */
	public static function get_max_output_tokens( string $model_id ): int {
		$model_id = trim( $model_id );
		if ( '' === $model_id ) {
			return Settings::MAX_OUTPUT_TOKENS_FALLBACK;
		}

		$entry = self::get( $model_id );
		$value = isset( $entry['max_output_tokens'] ) ? (int) $entry['max_output_tokens'] : 0;
		if ( $value > 0 ) {
			return $value;
		}

		return Settings::MAX_OUTPUT_TOKENS_FALLBACK;
	}

	/**
	 * Read a single registry entry by model id.
	 *
	 * Returns a shape with deterministic keys regardless of source so
	 * callers (CLI, REST) can introspect lookups without branching on
	 * "is this a transient hit or a catalog entry".
	 *
	 * @param string $model_id Provider-advertised model identifier.
	 * @return array{model_id: string, max_output_tokens: int, context_length: int, source: string, fetched_at: int}
	 */
	public static function get( string $model_id ): array {
		$model_id = trim( $model_id );
		if ( '' === $model_id ) {
			return self::empty_entry( $model_id, self::SOURCE_FALLBACK );
		}

		$transient = get_transient( self::transient_key( $model_id ) );
		if ( is_array( $transient ) && isset( $transient['max_output_tokens'] ) ) {
			return array(
				'model_id'          => $model_id,
				'max_output_tokens' => max( 0, (int) $transient['max_output_tokens'] ),
				'context_length'    => max( 0, (int) ( $transient['context_length'] ?? 0 ) ),
				'source'            => (string) ( $transient['source'] ?? self::SOURCE_PROVIDER ),
				'fetched_at'        => max( 0, (int) ( $transient['fetched_at'] ?? 0 ) ),
			);
		}

		$catalog = Settings::resolve_max_output_tokens_from_catalog( $model_id );
		if ( $catalog > 0 ) {
			return array(
				'model_id'          => $model_id,
				'max_output_tokens' => $catalog,
				'context_length'    => 0,
				'source'            => self::SOURCE_CATALOG,
				'fetched_at'        => 0,
			);
		}

		return self::empty_entry( $model_id, self::SOURCE_FALLBACK );
	}

	/**
	 * Persist a registry entry for a model.
	 *
	 * Called by {@see ModelCapabilityHandler} when parsing a `/models`
	 * response. Callers may also use this from CLI commands to manually
	 * seed a cap when the provider does not advertise one.
	 *
	 * @param string $model_id          Provider-advertised model identifier. Empty values are dropped.
	 * @param int    $max_output_tokens Max output tokens the provider advertises. Must be > 0.
	 * @param int    $context_length    Total context window the provider advertises. 0 if unknown.
	 * @param string $source            Source marker (defaults to provider). One of the SOURCE_* constants.
	 * @param int    $ttl_seconds       Override TTL. Defaults to PROVIDER_TTL_SECONDS.
	 * @return bool True on successful write; false on bad inputs or transient failure.
	 */
	public static function set(
		string $model_id,
		int $max_output_tokens,
		int $context_length = 0,
		string $source = self::SOURCE_PROVIDER,
		int $ttl_seconds = 0
	): bool {
		$model_id = trim( $model_id );
		if ( '' === $model_id || $max_output_tokens <= 0 ) {
			return false;
		}
		if ( $ttl_seconds <= 0 ) {
			$ttl_seconds = self::PROVIDER_TTL_SECONDS;
		}

		$entry = array(
			'model_id'          => $model_id,
			'max_output_tokens' => $max_output_tokens,
			'context_length'    => max( 0, $context_length ),
			'source'            => $source,
			'fetched_at'        => time(),
		);

		return (bool) set_transient( self::transient_key( $model_id ), $entry, $ttl_seconds );
	}

	/**
	 * Forget a single cached entry.
	 *
	 * @param string $model_id Provider-advertised model identifier.
	 * @return bool True if a transient was deleted; false if nothing to delete.
	 */
	public static function forget( string $model_id ): bool {
		$model_id = trim( $model_id );
		if ( '' === $model_id ) {
			return false;
		}
		return (bool) delete_transient( self::transient_key( $model_id ) );
	}

	/**
	 * Transient key for a model id. Exposed for tests + CLI introspection.
	 *
	 * @param string $model_id Provider-advertised model identifier.
	 * @return string Transient key.
	 */
	public static function transient_key( string $model_id ): string {
		return self::TRANSIENT_PREFIX . md5( trim( $model_id ) );
	}

	/**
	 * Build an empty entry shape for cache-miss returns.
	 *
	 * @param string $model_id Model id (echoed back for diagnostics).
	 * @param string $source   Source marker.
	 * @return array{model_id: string, max_output_tokens: int, context_length: int, source: string, fetched_at: int}
	 */
	private static function empty_entry( string $model_id, string $source ): array {
		return array(
			'model_id'          => $model_id,
			'max_output_tokens' => 0,
			'context_length'    => 0,
			'source'            => $source,
			'fetched_at'        => 0,
		);
	}
}
