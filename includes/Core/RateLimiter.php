<?php

declare(strict_types=1);
/**
 * Per-post, per-bucket rate limiter.
 *
 * Two buckets:
 *   - `write`:   10 ops / 60s / post (edit-block-tree, update-blocks, update-post, etc.)
 *   - `rewrite`: 2 ops / 60s / post  (rewrite-post-blocks)
 *
 * Storage: WP transient `sd_ai_agent_rl_{bucket}_{entity_id}` containing a
 * JSON array of Unix timestamps for ops within the last 60s. Old entries are
 * pruned on each check.
 *
 * Adapted from ~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-writer.php:111-180
 * (GPL-2.0-or-later — compatible).
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1756
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rate limiter for per-post write operations.
 *
 * All methods are static. The class holds no per-instance state.
 */
class RateLimiter {

	/**
	 * Default rate limits per bucket (ops per window).
	 *
	 * @var array<string, int>
	 */
	private const DEFAULT_LIMITS = [
		'write'   => 10,
		'rewrite' => 2,
	];

	/**
	 * Rolling window size in seconds.
	 *
	 * @var int
	 */
	public const WINDOW_SECONDS = 60;

	/**
	 * Transient key prefix.
	 *
	 * @var string
	 */
	private const TRANSIENT_PREFIX = 'sd_ai_agent_rl_';

	/**
	 * Check whether a new operation is within the rate limit.
	 *
	 * Reads the transient for the given bucket + entity, prunes entries older
	 * than WINDOW_SECONDS, and compares the remaining count against the limit.
	 *
	 * Returns `true` when the operation is allowed. Returns a `rate_limit_exceeded`
	 * WP_Error when the limit has been reached.
	 *
	 * @param string $bucket    Bucket name ('write' or 'rewrite').
	 * @param int    $entity_id Post ID (or user ID for create-post).
	 * @return true|\WP_Error True when allowed, WP_Error when rate-limited.
	 */
	public static function check( string $bucket, int $entity_id ): true|\WP_Error {
		$limits = self::get_limits();
		$limit  = $limits[ $bucket ] ?? 0;

		if ( $limit <= 0 ) {
			// Unknown bucket or zero limit — allow by default.
			return true;
		}

		$key    = self::transient_key( $bucket, $entity_id );
		$now    = time();
		$window = self::WINDOW_SECONDS;
		$cutoff = $now - $window;
		$raw    = get_transient( $key );
		$ticks  = self::parse_ticks( $raw, $cutoff );

		if ( count( $ticks ) < $limit ) {
			return true;
		}

		// Limit exceeded — compute retry_after_seconds.
		// The oldest tick still in the window determines when a slot opens.
		$oldest_tick = min( $ticks );
		$retry_after = ( $oldest_tick + $window ) - $now;
		$retry_after = max( 1, $retry_after );

		return new \WP_Error(
			'rate_limit_exceeded',
			__( 'Rate limit exceeded for this post.', 'superdav-ai-agent' ),
			[
				'status'              => 429,
				'bucket'              => $bucket,
				'limit'               => $limit,
				'window_seconds'      => $window,
				'retry_after_seconds' => $retry_after,
				'post_id'             => $entity_id,
			]
		);
	}

	/**
	 * Record a successful operation tick.
	 *
	 * Appends the current timestamp to the transient for the given bucket + entity.
	 * Prunes entries older than WINDOW_SECONDS before storing.
	 *
	 * @param string $bucket    Bucket name ('write' or 'rewrite').
	 * @param int    $entity_id Post ID (or user ID for create-post).
	 */
	public static function record( string $bucket, int $entity_id ): void {
		$key    = self::transient_key( $bucket, $entity_id );
		$now    = time();
		$cutoff = $now - self::WINDOW_SECONDS;
		$raw    = get_transient( $key );
		$ticks  = self::parse_ticks( $raw, $cutoff );

		$ticks[] = $now;

		// Store with a TTL of WINDOW_SECONDS so stale transients auto-expire.
		$json = wp_json_encode( $ticks );

		if ( false !== $json ) {
			set_transient( $key, $json, self::WINDOW_SECONDS );
		}
	}

	/**
	 * Get the current rate limits, applying the `sd_ai_agent_rate_limits` filter.
	 *
	 * @return array<string, int> Bucket name => max ops per window.
	 */
	public static function get_limits(): array {
		/**
		 * Filter rate limits per bucket.
		 *
		 * Return an associative array of bucket name => max ops per window.
		 * Only 'write' and 'rewrite' buckets are currently checked.
		 *
		 * @param array<string, int> $limits Default limits.
		 */
		$limits = apply_filters( 'sd_ai_agent_rate_limits', self::DEFAULT_LIMITS );

		if ( ! is_array( $limits ) ) {
			return self::DEFAULT_LIMITS;
		}

		// Ensure all values are positive integers.
		$sanitized = [];
		foreach ( $limits as $bucket => $value ) {
			if ( is_string( $bucket ) && is_int( $value ) && $value > 0 ) {
				$sanitized[ $bucket ] = $value;
			}
		}

		return ! empty( $sanitized ) ? $sanitized : self::DEFAULT_LIMITS;
	}

	/**
	 * Build the transient key for a bucket + entity pair.
	 *
	 * @param string $bucket    Bucket name.
	 * @param int    $entity_id Entity (post or user) ID.
	 * @return string Transient key.
	 */
	private static function transient_key( string $bucket, int $entity_id ): string {
		return self::TRANSIENT_PREFIX . $bucket . '_' . $entity_id;
	}

	/**
	 * Parse stored tick data from a transient value.
	 *
	 * Decodes the JSON array and prunes entries older than $cutoff.
	 *
	 * @param mixed $raw    Raw transient value (false when absent, string when present).
	 * @param int   $cutoff Unix timestamp cutoff — entries at or before this are discarded.
	 * @return int[] Array of valid timestamps within the window.
	 */
	private static function parse_ticks( mixed $raw, int $cutoff ): array {
		if ( false === $raw || ! is_string( $raw ) ) {
			return [];
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$valid = [];

		foreach ( $decoded as $ts ) {
			if ( is_int( $ts ) && $ts > $cutoff ) {
				$valid[] = $ts;
			}
		}

		return $valid;
	}
}
