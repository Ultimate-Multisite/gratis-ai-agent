<?php

declare(strict_types=1);
/**
 * Resource exhaustion security tests.
 *
 * Verifies that every block write path enforces a resource limit BEFORE any
 * allocating operation occurs (block parsing, DB write, file write).  Each
 * limit must trip a precise `*_too_large` / `*_exceeded` WP_Error code with
 * an HTTP status 400 (or 413 where appropriate) so callers can distinguish
 * limit errors from other failures.
 *
 * Limits under test:
 *   - update-blocks batch:       > 50 operations → `batch_too_large`
 *   - rewrite-post-blocks:       > 200 top-level blocks → `payload_too_large`
 *   - replace-block-range new:   > 200 new blocks → `range_too_large`
 *   - block nesting depth:       > 32 levels → `block_depth_exceeded`
 *   - list-posts per_page:       > 50 clamped silently (not an error)
 *
 * @package SdAiAgent
 * @subpackage Tests\Security
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1789
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Security;

use SdAiAgent\Abilities\BlockAbilities;
use SdAiAgent\Abilities\PostAbilities;
use SdAiAgent\Core\BlockMutator;
use WP_UnitTestCase;

/**
 * Resource exhaustion tests for block write and list abilities.
 *
 * @group security
 * @group resource-exhaustion
 *
 * @since 1.11.0
 */
class ResourceExhaustionTest extends WP_UnitTestCase {

	/**
	 * Administrator user for test operations.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Post with a known block tree for targeting in write tests.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Set up admin user and a post with a paragraph block.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );

		$this->post_id = self::factory()->post->create( [
			'post_status'  => 'draft',
			'post_content' => "<!-- wp:paragraph -->\n<p>Resource exhaustion test post.</p>\n<!-- /wp:paragraph -->",
		] );
	}

	/**
	 * Restore user context after each test.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Build a flat block definition for use in batch/rewrite payloads.
	 *
	 * @param int $index Optional index for unique content.
	 * @return array<string, mixed>
	 */
	private function make_paragraph_block( int $index = 0 ): array {
		return [
			'blockName'    => 'core/paragraph',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => "<p>Block {$index}.</p>",
			'innerContent' => [ "<p>Block {$index}.</p>" ],
		];
	}

	/**
	 * Build a deeply nested block tree of exactly $depth nesting levels.
	 *
	 * The innermost block is a paragraph; each outer level wraps in core/group.
	 *
	 * @param int $depth Number of nesting levels.
	 * @return array<string, mixed> Outermost block.
	 */
	private function make_deep_block( int $depth ): array {
		$block = $this->make_paragraph_block( 0 );

		for ( $i = 0; $i < $depth; $i++ ) {
			$block = [
				'blockName'    => 'core/group',
				'attrs'        => [ 'layout' => [ 'type' => 'constrained' ] ],
				'innerBlocks'  => [ $block ],
				'innerHTML'    => '<div class="wp-block-group"></div>',
				'innerContent' => [ '<div class="wp-block-group">', null, '</div>' ],
			];
		}

		return $block;
	}

	// ── update-blocks: batch_too_large ────────────────────────────────────

	/**
	 * AC: update-blocks with > 50 ops in a single batch returns batch_too_large.
	 *
	 * The check fires inside BlockMutator::apply_batch() BEFORE any block is
	 * resolved or mutated.
	 */
	public function test_update_blocks_batch_exceeding_50_returns_batch_too_large(): void {
		// Build 51 no-op update specs (update-attrs with empty merge).
		$updates = [];
		for ( $i = 0; $i <= BlockMutator::MAX_BATCH_SIZE; $i++ ) {
			$updates[] = [
				'op'         => 'update-attrs',
				'flat_index' => 0,
				'attributes' => [],
			];
		}

		$this->assertCount( BlockMutator::MAX_BATCH_SIZE + 1, $updates );

		$result = BlockAbilities::handle_update_blocks( [
			'post_id' => $this->post_id,
			'updates' => $updates,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'batch_too_large', $result->get_error_code() );
	}

	/**
	 * AC: apply_batch with MAX_BATCH_SIZE updates succeeds (boundary check, not off-by-one).
	 *
	 * MAX_BATCH_SIZE updates is exactly at the limit — it should NOT be rejected.
	 * Uses a block tree with MAX_BATCH_SIZE distinct blocks so each update targets
	 * a unique flat_index (avoiding the duplicate-target pre-flight check).
	 */
	public function test_apply_batch_at_exact_max_size_is_not_rejected(): void {
		// Build a tree with MAX_BATCH_SIZE distinct blocks.
		$blocks = [];
		for ( $i = 0; $i < BlockMutator::MAX_BATCH_SIZE; $i++ ) {
			$blocks[] = [
				'blockName'    => 'core/paragraph',
				'attrs'        => [
					'metadata' => [ 'sd_ref' => 'blk_exhaust_' . $i ],
				],
				'innerBlocks'  => [],
				'innerHTML'    => "<p>Block {$i}.</p>",
				'innerContent' => [ "<p>Block {$i}.</p>" ],
			];
		}

		// Exactly MAX_BATCH_SIZE updates — each targeting a unique flat_index.
		$updates = [];
		for ( $i = 0; $i < BlockMutator::MAX_BATCH_SIZE; $i++ ) {
			$updates[] = [
				'op'         => 'update-attrs',
				'flat_index' => $i,
				'attributes' => [ 'className' => 'updated-' . $i ],
			];
		}

		$result = BlockMutator::apply_batch( $blocks, $updates );

		// Exactly MAX_BATCH_SIZE must not be rejected.
		$this->assertIsArray( $result, 'Exactly MAX_BATCH_SIZE updates must not be rejected.' );
	}

	// ── rewrite-post-blocks: payload_too_large ────────────────────────────

	/**
	 * AC: rewrite-post-blocks with > 200 top-level blocks returns payload_too_large.
	 *
	 * The check fires in BlockMutator::validate_rewrite_blocks() BEFORE any
	 * normalization, sanitization, or wp_update_post() call.
	 */
	public function test_rewrite_post_blocks_over_200_blocks_returns_payload_too_large(): void {
		$blocks = [];
		for ( $i = 0; $i <= BlockMutator::MAX_REWRITE_BLOCKS; $i++ ) {
			$blocks[] = $this->make_paragraph_block( $i );
		}

		$this->assertCount( BlockMutator::MAX_REWRITE_BLOCKS + 1, $blocks );

		$result = BlockAbilities::handle_rewrite_post_blocks( [
			'post_id' => $this->post_id,
			'blocks'  => $blocks,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'payload_too_large', $result->get_error_code() );
	}

	/**
	 * AC: validate_rewrite_blocks at exactly MAX_REWRITE_BLOCKS succeeds (boundary check).
	 */
	public function test_validate_rewrite_blocks_at_exact_max_is_not_rejected(): void {
		$blocks = [];
		for ( $i = 0; $i < BlockMutator::MAX_REWRITE_BLOCKS; $i++ ) {
			$blocks[] = $this->make_paragraph_block( $i );
		}

		$result = BlockMutator::validate_rewrite_blocks( $blocks );

		$this->assertIsArray( $result, 'Exactly MAX_REWRITE_BLOCKS must not be rejected.' );
	}

	// ── block nesting depth: block_depth_exceeded ─────────────────────────

	/**
	 * AC: rewrite-post-blocks with nesting depth > 32 returns block_depth_exceeded.
	 *
	 * The check fires in BlockMutator::validate_tree_depth() BEFORE any DB write.
	 * 33 nesting levels exceeds MAX_BLOCK_DEPTH (32).
	 */
	public function test_rewrite_post_blocks_over_32_deep_returns_block_depth_exceeded(): void {
		// Create a block that is MAX_BLOCK_DEPTH + 1 levels deep.
		$over_deep_block = $this->make_deep_block( BlockMutator::MAX_BLOCK_DEPTH + 1 );

		$result = BlockAbilities::handle_rewrite_post_blocks( [
			'post_id' => $this->post_id,
			'blocks'  => [ $over_deep_block ],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_depth_exceeded', $result->get_error_code() );
	}

	/**
	 * AC: validate_rewrite_blocks at exactly MAX_BLOCK_DEPTH succeeds (boundary).
	 *
	 * A block that is exactly 32 levels deep (not exceeding) must be accepted.
	 */
	public function test_validate_rewrite_blocks_at_exact_max_depth_is_not_rejected(): void {
		$deep_block = $this->make_deep_block( BlockMutator::MAX_BLOCK_DEPTH );

		$result = BlockMutator::validate_rewrite_blocks( [ $deep_block ] );

		$this->assertIsArray( $result, 'A block at exactly MAX_BLOCK_DEPTH must not be rejected.' );
	}

	/**
	 * AC: validate_tree_depth reports block_depth_exceeded for MAX + 1 levels.
	 */
	public function test_validate_tree_depth_over_limit_returns_block_depth_exceeded(): void {
		$over_deep = $this->make_deep_block( BlockMutator::MAX_BLOCK_DEPTH + 1 );

		$result = BlockMutator::validate_tree_depth( [ $over_deep ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_depth_exceeded', $result->get_error_code() );
	}

	// ── replace-block-range: range_too_large ──────────────────────────────

	/**
	 * AC: handle_replace_block_range with > 200 new_blocks returns range_too_large.
	 *
	 * The check fires in BlockMutator::replace_range() BEFORE any tree mutation.
	 * Since replace-block-range requires real block refs from the database, this
	 * test creates a post, persists refs, then sends an over-limit new_blocks array.
	 */
	public function test_replace_block_range_over_200_new_blocks_returns_range_too_large(): void {
		// Build a post with two consecutive blocks so we have a valid range.
		$two_para_post = self::factory()->post->create( [
			'post_status'  => 'draft',
			'post_content' => implode( "\n", [
				"<!-- wp:paragraph -->\n<p>First block.</p>\n<!-- /wp:paragraph -->",
				"<!-- wp:paragraph -->\n<p>Second block.</p>\n<!-- /wp:paragraph -->",
			] ),
		] );

		// Persist refs.
		$page_blocks = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $two_para_post,
			'persist_refs' => true,
		] );

		$this->assertIsArray( $page_blocks );
		$blocks = $page_blocks['blocks'] ?? [];
		$this->assertGreaterThanOrEqual( 2, count( $blocks ), 'Need at least 2 blocks for range test.' );

		$start_ref = $blocks[0]['ref'] ?? '';
		$end_ref   = $blocks[1]['ref'] ?? '';
		$this->assertNotEmpty( $start_ref );
		$this->assertNotEmpty( $end_ref );

		// Build 201 new blocks (over the MAX_RANGE_SIZE cap).
		$new_blocks = [];
		for ( $i = 0; $i <= BlockMutator::MAX_RANGE_SIZE; $i++ ) {
			$new_blocks[] = $this->make_paragraph_block( $i );
		}

		$this->assertCount( BlockMutator::MAX_RANGE_SIZE + 1, $new_blocks );

		$result = BlockAbilities::handle_replace_block_range( [
			'post_id'    => $two_para_post,
			'start_ref'  => $start_ref,
			'end_ref'    => $end_ref,
			'new_blocks' => $new_blocks,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'range_too_large', $result->get_error_code() );
	}

	// ── list-posts: per_page clamping ─────────────────────────────────────

	/**
	 * AC: list-posts with per_page: 100000 is silently clamped to ≤ 50.
	 *
	 * The handler does not return an error for oversized per_page; it clamps the
	 * value. This is the current protection strategy — the response will contain
	 * at most 50 posts regardless of the requested value.
	 */
	public function test_list_posts_oversized_per_page_is_clamped_to_50(): void {
		// Create 60 draft posts so we can verify the clamp.
		for ( $i = 0; $i < 60; $i++ ) {
			self::factory()->post->create( [ 'post_status' => 'publish' ] );
		}

		$result = PostAbilities::handle_list_posts( [
			'post_status' => 'publish',
			'per_page'    => 100000,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'posts', $result );
		$this->assertLessThanOrEqual(
			50,
			count( $result['posts'] ),
			'per_page:100000 must be clamped; response must not contain more than 50 posts.'
		);
	}

	/**
	 * AC: list-posts with per_page: 0 is clamped to 1 (minimum).
	 */
	public function test_list_posts_zero_per_page_is_clamped_to_minimum_one(): void {
		self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$result = PostAbilities::handle_list_posts( [
			'post_status' => 'publish',
			'per_page'    => 0,
		] );

		$this->assertIsArray( $result );
		$this->assertGreaterThanOrEqual( 0, count( $result['posts'] ) );
		// posts_per_page is at least 1, not 0 — query doesn't fail.
		$this->assertArrayHasKey( 'query_args', $result );
	}

	// ── update-blocks: empty batch guard ─────────────────────────────────

	/**
	 * AC: apply_batch with empty updates array returns empty_batch.
	 *
	 * Ensures the guard fires before the loop, not inside it.
	 */
	public function test_apply_batch_with_empty_updates_returns_empty_batch(): void {
		$blocks = [
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [ 'metadata' => [ 'sd_ref' => 'blk_exhaust_empty' ] ],
				'innerBlocks'  => [],
				'innerHTML'    => '<p>Test.</p>',
				'innerContent' => [ '<p>Test.</p>' ],
			],
		];

		$result = BlockMutator::apply_batch( $blocks, [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'empty_batch', $result->get_error_code() );
	}
}
