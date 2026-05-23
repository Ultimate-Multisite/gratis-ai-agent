<?php
/**
 * Test case for RateLimiter (GH#1756).
 *
 * Covers the acceptance criteria:
 *   AC1: 11th edit-block-tree on the same post within 60s returns rate_limit_exceeded.
 *   AC2: Rewrite bucket independent of write bucket.
 *   AC3: Atomic batch of 30 ops counts as 1 tick.
 *   AC4: Per-post isolation: post A unaffected by post B's bucket.
 *   AC5: HTTP response carries Retry-After header when 429.
 *   AC6: Filter sd_ai_agent_rate_limits overrides the caps.
 *   AC7: Transient pruning: entries > 60s old discarded on next check.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1756
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\RateLimiter;
use WP_UnitTestCase;

/**
 * Unit tests for RateLimiter.
 *
 * Uses WP_UnitTestCase so WordPress transient functions are available.
 */
class RateLimiterTest extends WP_UnitTestCase {

	/**
	 * Clean up rate-limit transients between tests.
	 */
	public function tear_down(): void {
		// Clean up all transients used in tests.
		$buckets   = [ 'write', 'rewrite' ];
		$entity_ids = [ 156, 200, 999, 1, 2, 42 ];

		foreach ( $buckets as $bucket ) {
			foreach ( $entity_ids as $entity_id ) {
				delete_transient( 'sd_ai_agent_rl_' . $bucket . '_' . $entity_id );
			}
		}

		// Remove any filters we added.
		remove_all_filters( 'sd_ai_agent_rate_limits' );

		parent::tear_down();
	}

	// ── AC1: Write bucket limit ───────────────────────────────────────────

	/**
	 * First 10 writes on a post are allowed.
	 */
	public function test_write_bucket_allows_within_limit(): void {
		$post_id = 156;

		for ( $i = 0; $i < 10; $i++ ) {
			$check = RateLimiter::check( 'write', $post_id );
			$this->assertTrue( $check, "Write $i should be allowed" );
			RateLimiter::record( 'write', $post_id );
		}
	}

	/**
	 * 11th write on the same post within 60s returns rate_limit_exceeded.
	 */
	public function test_write_bucket_blocks_at_limit(): void {
		$post_id = 156;

		// Fill up the bucket.
		for ( $i = 0; $i < 10; $i++ ) {
			RateLimiter::record( 'write', $post_id );
		}

		$check = RateLimiter::check( 'write', $post_id );
		$this->assertWPError( $check );
		$this->assertSame( 'rate_limit_exceeded', $check->get_error_code() );

		$data = $check->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 429, $data['status'] );
		$this->assertSame( 'write', $data['bucket'] );
		$this->assertSame( 10, $data['limit'] );
		$this->assertSame( 60, $data['window_seconds'] );
		$this->assertArrayHasKey( 'retry_after_seconds', $data );
		$this->assertGreaterThan( 0, $data['retry_after_seconds'] );
		$this->assertSame( $post_id, $data['post_id'] );
	}

	// ── AC2: Bucket independence ──────────────────────────────────────────

	/**
	 * Rewrite bucket is independent of write bucket.
	 */
	public function test_rewrite_bucket_independent_of_write(): void {
		$post_id = 156;

		// Fill the write bucket to capacity.
		for ( $i = 0; $i < 10; $i++ ) {
			RateLimiter::record( 'write', $post_id );
		}

		// Write bucket should be blocked.
		$write_check = RateLimiter::check( 'write', $post_id );
		$this->assertWPError( $write_check );

		// Rewrite bucket should still be available.
		$rewrite_check = RateLimiter::check( 'rewrite', $post_id );
		$this->assertTrue( $rewrite_check );
	}

	/**
	 * Rewrite bucket has its own limit (2/60s).
	 */
	public function test_rewrite_bucket_limit(): void {
		$post_id = 156;

		// Fill the rewrite bucket.
		RateLimiter::record( 'rewrite', $post_id );
		RateLimiter::record( 'rewrite', $post_id );

		// 3rd rewrite should be blocked.
		$check = RateLimiter::check( 'rewrite', $post_id );
		$this->assertWPError( $check );
		$this->assertSame( 'rate_limit_exceeded', $check->get_error_code() );

		$data = $check->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'rewrite', $data['bucket'] );
		$this->assertSame( 2, $data['limit'] );
	}

	/**
	 * Write bucket not affected by rewrite bucket usage.
	 */
	public function test_write_bucket_independent_of_rewrite(): void {
		$post_id = 156;

		// Fill the rewrite bucket.
		RateLimiter::record( 'rewrite', $post_id );
		RateLimiter::record( 'rewrite', $post_id );

		// Write bucket should still be available.
		$check = RateLimiter::check( 'write', $post_id );
		$this->assertTrue( $check );
	}

	// ── AC4: Per-post isolation ───────────────────────────────────────────

	/**
	 * Post 200 is unaffected by post 156's bucket.
	 */
	public function test_per_post_isolation(): void {
		$post_a = 156;
		$post_b = 200;

		// Fill post A's write bucket to capacity.
		for ( $i = 0; $i < 10; $i++ ) {
			RateLimiter::record( 'write', $post_a );
		}

		// Post A should be blocked.
		$check_a = RateLimiter::check( 'write', $post_a );
		$this->assertWPError( $check_a );

		// Post B should be unaffected.
		$check_b = RateLimiter::check( 'write', $post_b );
		$this->assertTrue( $check_b );
	}

	// ── AC5: Retry-After header ───────────────────────────────────────────

	/**
	 * Rate limit error includes retry_after_seconds suitable for Retry-After header.
	 */
	public function test_retry_after_seconds_populated(): void {
		$post_id = 156;

		// Fill the write bucket.
		for ( $i = 0; $i < 10; $i++ ) {
			RateLimiter::record( 'write', $post_id );
		}

		$check = RateLimiter::check( 'write', $post_id );
		$this->assertWPError( $check );

		$data = $check->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'retry_after_seconds', $data );

		// The retry_after should be between 1 and 60 seconds (the window).
		$retry = $data['retry_after_seconds'];
		$this->assertGreaterThanOrEqual( 1, $retry );
		$this->assertLessThanOrEqual( RateLimiter::WINDOW_SECONDS, $retry );
	}

	/**
	 * RestController Retry-After header is added for 429 responses.
	 */
	public function test_rest_controller_adds_retry_after_header(): void {
		$response = new \WP_REST_Response(
			[
				'code'    => 'rate_limit_exceeded',
				'message' => 'Rate limit exceeded for this post.',
				'data'    => [
					'status'              => 429,
					'bucket'              => 'write',
					'limit'               => 10,
					'retry_after_seconds' => 42,
					'post_id'             => 156,
				],
			],
			429
		);

		// Call the filter directly (simulates rest_post_dispatch).
		$result = \SdAiAgent\REST\RestController::add_retry_after_header( $response );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$headers = $result->get_headers();
		$this->assertArrayHasKey( 'Retry-After', $headers );
		$this->assertSame( '42', $headers['Retry-After'] );
	}

	/**
	 * RestController does not add Retry-After for non-429 responses.
	 */
	public function test_rest_controller_no_retry_after_on_200(): void {
		$response = new \WP_REST_Response( [ 'success' => true ], 200 );

		$result = \SdAiAgent\REST\RestController::add_retry_after_header( $response );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$headers = $result->get_headers();
		$this->assertArrayNotHasKey( 'Retry-After', $headers );
	}

	// ── AC6: Filter overrides ─────────────────────────────────────────────

	/**
	 * sd_ai_agent_rate_limits filter raises the cap.
	 */
	public function test_filter_overrides_limits(): void {
		$post_id = 999;

		add_filter(
			'sd_ai_agent_rate_limits',
			static function (): array {
				return [ 'write' => 100, 'rewrite' => 2 ];
			}
		);

		// Fill default limit (10) — should still be allowed with filter raising to 100.
		for ( $i = 0; $i < 10; $i++ ) {
			RateLimiter::record( 'write', $post_id );
		}

		$check = RateLimiter::check( 'write', $post_id );
		$this->assertTrue( $check, 'Should be allowed: filter raised limit to 100' );
	}

	/**
	 * sd_ai_agent_rate_limits filter can lower the cap.
	 */
	public function test_filter_lowers_limits(): void {
		$post_id = 999;

		add_filter(
			'sd_ai_agent_rate_limits',
			static function (): array {
				return [ 'write' => 3, 'rewrite' => 1 ];
			}
		);

		// 3 writes should be allowed.
		for ( $i = 0; $i < 3; $i++ ) {
			RateLimiter::record( 'write', $post_id );
		}

		// 4th write should be blocked.
		$check = RateLimiter::check( 'write', $post_id );
		$this->assertWPError( $check );
		$this->assertSame( 'rate_limit_exceeded', $check->get_error_code() );
	}

	/**
	 * Invalid filter return value falls back to defaults.
	 */
	public function test_filter_invalid_returns_defaults(): void {
		$post_id = 42;

		add_filter(
			'sd_ai_agent_rate_limits',
			static function (): string {
				return 'invalid';
			}
		);

		// Default write limit (10) should apply.
		for ( $i = 0; $i < 10; $i++ ) {
			RateLimiter::record( 'write', $post_id );
		}

		$check = RateLimiter::check( 'write', $post_id );
		$this->assertWPError( $check );
	}

	// ── AC7: Transient pruning ────────────────────────────────────────────

	/**
	 * Entries older than 60s are pruned on next check.
	 */
	public function test_transient_pruning_old_entries(): void {
		$post_id = 156;
		$key     = 'sd_ai_agent_rl_write_' . $post_id;

		// Seed the transient with timestamps that are more than 60s old.
		$old_time = time() - 120; // 2 minutes ago.
		$ticks    = [];

		for ( $i = 0; $i < 10; $i++ ) {
			$ticks[] = $old_time + $i;
		}

		set_transient( $key, wp_json_encode( $ticks ), RateLimiter::WINDOW_SECONDS );

		// All old entries should be pruned — check should allow.
		$check = RateLimiter::check( 'write', $post_id );
		$this->assertTrue( $check, 'Should be allowed after old entries are pruned' );
	}

	/**
	 * Mix of old and new entries: only new entries count.
	 */
	public function test_transient_pruning_mixed_entries(): void {
		$post_id = 156;
		$key     = 'sd_ai_agent_rl_write_' . $post_id;

		$now      = time();
		$old_time = $now - 120; // 2 minutes ago.

		// 8 old entries + 2 recent entries = only 2 should remain.
		$ticks = [];

		for ( $i = 0; $i < 8; $i++ ) {
			$ticks[] = $old_time + $i;
		}

		$ticks[] = $now - 10; // 10 seconds ago.
		$ticks[] = $now - 5;  // 5 seconds ago.

		set_transient( $key, wp_json_encode( $ticks ), RateLimiter::WINDOW_SECONDS );

		// Only 2 entries within the window — should allow.
		$check = RateLimiter::check( 'write', $post_id );
		$this->assertTrue( $check, 'Should be allowed: only 2 entries within window' );
	}

	// ── AC3: Atomic batch single tick ─────────────────────────────────────

	/**
	 * Recording once per batch (not per op) — 1 record call for 30 ops.
	 */
	public function test_atomic_batch_single_tick(): void {
		$post_id = 156;

		// Simulate 10 batches of 30 ops each (but only 1 record per batch).
		for ( $i = 0; $i < 10; $i++ ) {
			$check = RateLimiter::check( 'write', $post_id );
			$this->assertTrue( $check, "Batch $i should be allowed" );
			// Batch of 30 ops records only 1 tick.
			RateLimiter::record( 'write', $post_id );
		}

		// 11th batch should be blocked.
		$check = RateLimiter::check( 'write', $post_id );
		$this->assertWPError( $check );
		$this->assertSame( 'rate_limit_exceeded', $check->get_error_code() );
	}

	// ── Edge cases ────────────────────────────────────────────────────────

	/**
	 * Unknown bucket returns true (allow by default).
	 */
	public function test_unknown_bucket_allows(): void {
		$check = RateLimiter::check( 'nonexistent', 156 );
		$this->assertTrue( $check );
	}

	/**
	 * get_limits() returns filtered limits.
	 */
	public function test_get_limits_returns_defaults(): void {
		$limits = RateLimiter::get_limits();

		$this->assertArrayHasKey( 'write', $limits );
		$this->assertArrayHasKey( 'rewrite', $limits );
		$this->assertSame( 10, $limits['write'] );
		$this->assertSame( 2, $limits['rewrite'] );
	}

	/**
	 * Record then check — single record does not block.
	 */
	public function test_single_record_does_not_block(): void {
		$post_id = 1;

		RateLimiter::record( 'write', $post_id );

		$check = RateLimiter::check( 'write', $post_id );
		$this->assertTrue( $check );
	}

	/**
	 * Check without any records returns true.
	 */
	public function test_check_empty_returns_true(): void {
		$check = RateLimiter::check( 'write', 2 );
		$this->assertTrue( $check );
	}

	/**
	 * Malformed transient data is handled gracefully.
	 */
	public function test_malformed_transient_handled(): void {
		$key = 'sd_ai_agent_rl_write_156';

		// Set a malformed value.
		set_transient( $key, 'not-json', RateLimiter::WINDOW_SECONDS );

		$check = RateLimiter::check( 'write', 156 );
		$this->assertTrue( $check, 'Malformed transient should be treated as empty' );
	}
}
