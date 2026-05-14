<?php
/**
 * Contract for Gemini `cachedContents` resource management.
 *
 * Separating the interface from the {@see GeminiCacheManager} implementation
 * lets unit tests supply a lightweight test double without needing to mock a
 * `final` class or make real HTTP calls.
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
 * Manages the Gemini `cachedContents` resource lifecycle.
 */
interface GeminiCacheManagerInterface {

	/**
	 * Find an existing cache resource or create a new one.
	 *
	 * Returns null on any failure; callers must degrade gracefully.
	 *
	 * @param string           $api_key  API key for the Gemini endpoint.
	 * @param string           $model    Model ID.
	 * @param array<int,mixed> $contents Stable-prefix content items to cache.
	 * @param array<int,mixed> $tools    Tool definitions (stable across turns).
	 * @param string           $system   System instruction text.
	 * @return string|null Resource name like `cachedContents/abc123`, or null.
	 */
	public function find_or_create(
		string $api_key,
		string $model,
		array $contents,
		array $tools,
		string $system
	): ?string;

	/**
	 * Invalidate the stored resource name for a given hash.
	 *
	 * Call this when a `generateContent` response signals that the cached
	 * content resource has expired server-side, then retry `find_or_create()`.
	 *
	 * @param string $hash Hash produced by {@see build_hash()}.
	 * @return void
	 */
	public function invalidate( string $hash ): void;

	/**
	 * Build a deterministic hash of the cacheable-prefix inputs.
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
	): string;
}
