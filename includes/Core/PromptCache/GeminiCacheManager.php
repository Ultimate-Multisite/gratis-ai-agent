<?php
/**
 * Manages the Gemini `cachedContents` resource lifecycle.
 *
 * Gemini prompt caching is a two-step process:
 *
 *  1. POST `https://generativelanguage.googleapis.com/v1beta/cachedContents`
 *     with the stable prefix (system instruction + tools + early messages),
 *     which returns a resource name like `cachedContents/abc123`.
 *  2. Subsequent `POST /v1beta/models/{model}:generateContent` calls set
 *     `cachedContent: "cachedContents/abc123"` in the body instead of
 *     repeating the prefix inline.
 *
 * This manager handles step 1 — including the create / lookup / expire
 * cycle — using WordPress transients for persistence across requests and
 * `wp_cache_add()` for idempotency under concurrent load.
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
 * Creates, looks up, and invalidates Gemini `cachedContents` resources.
 */
final class GeminiCacheManager implements GeminiCacheManagerInterface {

	/**
	 * Gemini REST endpoint for creating cached content resources.
	 *
	 * @var string
	 */
	private const CACHE_CREATE_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/cachedContents';

	/**
	 * TTL requested for the Gemini cache resource (1 hour).
	 *
	 * Gemini's default is also 1 h; being explicit future-proofs against
	 * the default changing.
	 *
	 * @var string
	 */
	private const RESOURCE_TTL = '3600s';

	/**
	 * Safety margin subtracted from the resource TTL before storing in
	 * transients. Prevents stale-resource 404s on `generateContent` by
	 * expiring our local reference a few minutes before Gemini does.
	 *
	 * @var int Seconds.
	 */
	private const TTL_SAFETY_MARGIN_SECONDS = 300;

	/**
	 * WordPress object-cache group used for the idempotency lock.
	 *
	 * @var string
	 */
	private const LOCK_GROUP = 'sd_ai_agent_gemini_cache_lock';

	/**
	 * Duration the idempotency lock is held. A concurrent request for the
	 * same prefix waits this long before checking the transient written by
	 * the winning requester.
	 *
	 * @var int Seconds.
	 */
	private const LOCK_TTL_SECONDS = 30;

	/**
	 * HTTP timeout for `cachedContents` API calls.
	 *
	 * @var int Seconds.
	 */
	private const API_TIMEOUT_SECONDS = 15;

	/**
	 * Find an existing cache resource or create a new one.
	 *
	 * @param string           $api_key  API key for the Gemini endpoint.
	 * @param string           $model    Model ID (e.g. `gemini-2.5-pro-preview-05-06`).
	 * @param array<int,mixed> $contents The stable-prefix content items to cache.
	 * @param array<int,mixed> $tools    Tool definitions (stable across turns).
	 * @param string           $system   System instruction text.
	 * @return string|null Resource name like `cachedContents/abc123`, or null on failure.
	 */
	public function find_or_create(
		string $api_key,
		string $model,
		array $contents,
		array $tools,
		string $system
	): ?string {
		if ( '' === $api_key ) {
			return null;
		}

		$hash = $this->build_hash( $model, $contents, $tools, $system );

		// Fast path: transient hit — resource was already created.
		$cached = $this->get_cached_resource( $hash );
		if ( null !== $cached ) {
			return $cached;
		}

		// Idempotency lock: only one concurrent request creates the resource.
		if ( ! $this->acquire_lock( $hash ) ) {
			// Another request is in the process of creating it. Wait briefly,
			// then re-check the transient rather than double-creating.
			// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda
			usleep( 500_000 ); // 500 ms.
			return $this->get_cached_resource( $hash );
		}

		try {
			$name = $this->create_resource( $api_key, $model, $contents, $tools, $system );
			if ( null !== $name ) {
				$this->store_resource( $hash, $name );
			}
			return $name;
		} finally {
			$this->release_lock( $hash );
		}
	}

	/**
	 * Invalidate the stored resource for the given hash.
	 *
	 * Call this when a `generateContent` response returns 404 with a
	 * "RESOURCE_EXHAUSTED" or cache-not-found error, indicating the
	 * resource expired server-side before our transient did. After calling
	 * this, invoke `find_or_create()` again to transparently re-create.
	 *
	 * @param string $hash Hash produced by {@see build_hash()}.
	 * @return void
	 */
	public function invalidate( string $hash ): void {
		delete_transient( $this->transient_key( $hash ) );
	}

	/**
	 * Build a deterministic hash of the cacheable-prefix inputs.
	 *
	 * Any change in model, contents, tools, or system instruction produces
	 * a different hash and therefore a different cache resource — which is
	 * the correct behaviour since the stable prefix has changed.
	 *
	 * @param string           $model    Model ID.
	 * @param array<int,mixed> $contents Content items (stable portion).
	 * @param array<int,mixed> $tools    Tool definitions.
	 * @param string           $system   System instruction.
	 * @return string 32-character hex MD5 hash.
	 */
	public function build_hash(
		string $model,
		array $contents,
		array $tools,
		string $system
	): string {
		$payload = array(
			'model'    => $model,
			'contents' => $contents,
			'tools'    => $tools,
			'system'   => $system,
		);
		return md5( (string) wp_json_encode( $payload ) );
	}

	/**
	 * Read the cached resource name from transients.
	 *
	 * @param string $hash Prefix hash.
	 * @return string|null Resource name, or null when the transient is absent or malformed.
	 */
	private function get_cached_resource( string $hash ): ?string {
		$data = get_transient( $this->transient_key( $hash ) );
		if ( ! is_array( $data ) || ! isset( $data['name'] ) || ! is_string( $data['name'] ) || '' === $data['name'] ) {
			return null;
		}
		return $data['name'];
	}

	/**
	 * Persist a resource name in the transient store.
	 *
	 * TTL = resource TTL (3600 s) minus safety margin (300 s) = 3300 s
	 * ≈ 55 minutes. This ensures our reference expires before Gemini's
	 * server-side resource, avoiding 404s from stale cache names.
	 *
	 * @param string $hash Resource prefix hash.
	 * @param string $name Resource name like `cachedContents/abc123`.
	 * @return void
	 */
	private function store_resource( string $hash, string $name ): void {
		$ttl = 3600 - self::TTL_SAFETY_MARGIN_SECONDS; // 3300 s / 55 min.
		set_transient(
			$this->transient_key( $hash ),
			array(
				'name' => $name,
				'hash' => $hash,
			),
			$ttl
		);
	}

	/**
	 * Call the Gemini `cachedContents` API to create a cache resource.
	 *
	 * Returns null silently on any API failure — callers degrade to sending
	 * the full request body without a cache reference.
	 *
	 * @param string           $api_key  Gemini API key.
	 * @param string           $model    Model ID (bare, without `models/` prefix).
	 * @param array<int,mixed> $contents Content items to cache.
	 * @param array<int,mixed> $tools    Tool definitions.
	 * @param string           $system   System instruction text.
	 * @return string|null Created resource name, or null on failure.
	 */
	private function create_resource(
		string $api_key,
		string $model,
		array $contents,
		array $tools,
		string $system
	): ?string {
		// Gemini requires the model in `models/{id}` form.
		$model_uri = 'models/' . ( str_starts_with( $model, 'models/' ) ? substr( $model, 7 ) : $model );

		$body = array(
			'model'    => $model_uri,
			'ttl'      => self::RESOURCE_TTL,
			'contents' => $contents,
		);

		if ( '' !== $system ) {
			$body['system_instruction'] = array(
				'parts' => array(
					array( 'text' => $system ),
				),
			);
		}

		if ( ! empty( $tools ) ) {
			$body['tools'] = $tools;
		}

		$encoded = wp_json_encode( $body );
		if ( false === $encoded ) {
			return null;
		}

		$response = wp_remote_post(
			self::CACHE_CREATE_ENDPOINT . '?key=' . rawurlencode( $api_key ),
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => $encoded,
				'timeout' => self::API_TIMEOUT_SECONDS,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return null;
		}

		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) || ! isset( $data['name'] ) || ! is_string( $data['name'] ) || '' === $data['name'] ) {
			return null;
		}

		return $data['name'];
	}

	/**
	 * Attempt to acquire the idempotency lock for this hash.
	 *
	 * `wp_cache_add()` is atomic — only the first caller wins when multiple
	 * requests race to create the same cache resource.
	 *
	 * @param string $hash Prefix hash.
	 * @return bool True when this caller holds the lock.
	 */
	private function acquire_lock( string $hash ): bool {
		return (bool) wp_cache_add(
			$hash,
			1,
			self::LOCK_GROUP,
			self::LOCK_TTL_SECONDS
		);
	}

	/**
	 * Release the idempotency lock so other requests can proceed.
	 *
	 * @param string $hash Prefix hash.
	 * @return void
	 */
	private function release_lock( string $hash ): void {
		wp_cache_delete( $hash, self::LOCK_GROUP );
	}

	/**
	 * Derive the WordPress transient key for a given prefix hash.
	 *
	 * WordPress transient keys are limited internally; MD5 is 32 chars so
	 * the combined key stays well within the 172-character limit.
	 *
	 * @param string $hash MD5 hash string.
	 * @return string Transient key.
	 */
	private function transient_key( string $hash ): string {
		return 'sd_ai_agent_gemini_cache_' . $hash;
	}
}
