<?php

declare(strict_types=1);
/**
 * Cache bridge between the PHP {@see BlockValidator} and the browser-side
 * `wp.blocks.validateBlock()` runner (`src/block-validator/index.js`).
 *
 * The flow is:
 *
 *  1. Any admin page that has the block-editor scripts enqueued (the hidden
 *     {@see \SdAiAgent\Admin\BlockValidatorPage}, the chat UI, etc.) calls
 *     `window.sdAiAgentValidateBlocks( content )` from JS, which runs the
 *     real `wp.blocks.validateBlock()` recursively and POSTs the resulting
 *     Studio-shaped report to `/sd-ai-agent/v1/blocks/validate-cache`.
 *
 *  2. {@see \SdAiAgent\REST\BlockValidatorController::handle_cache_put()}
 *     calls {@see store()} with the original content and the report. The
 *     cache key is `sha256( content )`, retention is 30 minutes (transient).
 *
 *  3. The next time {@see BlockValidator::validate()} is called for the
 *     same content, it picks up the live JS results via {@see get_cached()}
 *     and returns them instead of the PHP report. The PHP report is still
 *     a perfectly reasonable fallback when no cache entry exists.
 *
 * This gives Phase 1 (GH#1584) a working end-to-end live validator without
 * forcing every PHP call site into an async/poll loop: the JS validator
 * primes the cache, the PHP validator drains it.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 * @since   1.11.0
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1584
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static cache bridge for JS-side validation results.
 *
 * @since 1.11.0
 */
final class BlockValidatorBridge {

	/**
	 * Transient name prefix. Suffix is `sha256( content )`.
	 *
	 * @since 1.11.0
	 */
	public const TRANSIENT_PREFIX = 'sd_ai_agent_blkval_';

	/**
	 * In-memory cache for the current PHP request. Spared so tests / repeat
	 * calls within the same request do not hit the transient API.
	 *
	 * @since 1.11.0
	 * @var array<string, array<int|string, mixed>>
	 */
	private static array $memory = [];

	/**
	 * Default time-to-live for cached entries (seconds). 30 minutes is enough
	 * for the AI loop's tool-call → validate-content → fix cycle without
	 * letting stale results accumulate.
	 *
	 * @since 1.11.0
	 */
	private const TTL_SECONDS = 1800;

	/**
	 * Build the transient/memory cache key for the given content.
	 *
	 * @since 1.11.0
	 *
	 * @param string $content Raw block content.
	 * @return string Cache key.
	 */
	private static function key( string $content ): string {
		return self::TRANSIENT_PREFIX . hash( 'sha256', $content );
	}

	/**
	 * Store a validation report keyed by content hash. Overwrites any
	 * previous cache entry for the same content.
	 *
	 * @since 1.11.0
	 *
	 * @param string               $content Raw block content.
	 * @param array<string, mixed> $report  Studio-shaped report.
	 * @return void
	 */
	public static function store( string $content, array $report ): void {
		$key                  = self::key( $content );
		self::$memory[ $key ] = $report;

		if ( function_exists( 'set_transient' ) ) {
			set_transient( $key, $report, self::TTL_SECONDS );
		}
	}

	/**
	 * Return the cached validation report for the given content, if any.
	 *
	 * Reports are stored as flat arrays with string keys (`totalBlocks`,
	 * `validBlocks`, `invalidBlocks`, `results`, `source`) but PHPStan can
	 * only narrow `get_transient()` to a generic array, so the return type
	 * uses the broader `array<int|string, mixed>` shape and downstream
	 * callers should treat results conservatively (e.g. via `?? []`).
	 *
	 * @since 1.11.0
	 *
	 * @param string $content Raw block content.
	 * @return array<int|string, mixed>|null Cached report, or null when not cached.
	 */
	public static function get_cached( string $content ): ?array {
		$key = self::key( $content );

		if ( isset( self::$memory[ $key ] ) ) {
			return self::$memory[ $key ];
		}

		if ( function_exists( 'get_transient' ) ) {
			$stored = get_transient( $key );
			if ( is_array( $stored ) ) {
				self::$memory[ $key ] = $stored;
				return $stored;
			}
		}

		return null;
	}

	/**
	 * Clear all in-memory cache entries (test helper).
	 *
	 * @since 1.11.0
	 *
	 * @return void
	 */
	public static function reset_memory_cache(): void {
		self::$memory = [];
	}
}
