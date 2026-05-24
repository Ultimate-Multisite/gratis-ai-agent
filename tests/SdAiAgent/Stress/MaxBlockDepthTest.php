<?php
// SPDX-License-Identifier: MIT
// SPDX-FileCopyrightText: 2025-2026 Marcus Quinn
/**
 * Tests for BlockMutator::MAX_BLOCK_DEPTH enforcement (ported from block-mcp).
 *
 * Pins the contract that every write path validates the depth of the outgoing
 * block tree against MAX_BLOCK_DEPTH (32) and rejects with `block_depth_exceeded`
 * (HTTP 400). The limit is a hard guard against stack overflow / pcre.recursion_limit
 * failures inside parse_blocks() / serialize_blocks() and quadratic walks in
 * recursive BlockMutator helpers — not a tunable knob.
 *
 * Ported one-for-one from block-mcp tests/Stress/MaxBlockDepthTest.php
 * (GPL-2.0-or-later). Block_CRUD→BlockMutator, tree()→make_nested_block(),
 * and instance→static-call conversions applied per AGENTS.md.
 *
 * @package SdAiAgent\Tests\Stress
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1788
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Stress;

use SdAiAgent\Core\BlockMutator;
use WP_UnitTestCase;

/**
 * Block depth cap tests.
 *
 * Uses WP_UnitTestCase so parse_blocks() / serialize_blocks() / wp_update_post()
 * are available.
 */
class MaxBlockDepthTest extends WP_UnitTestCase {

	/** @var int WP post ID used throughout the test. */
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		$this->post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Build a linearly-nested block tree $levels deep (WP internal format).
	 *
	 * make_nested_block(0) → single leaf paragraph (depth 1 in count_tree_depth).
	 * make_nested_block(n) → core/group wrapping make_nested_block(n-1)
	 *                        (depth n+1 in count_tree_depth).
	 *
	 * validate_tree_depth passes make_nested_block(MAX_BLOCK_DEPTH=32)
	 * because the recursive call reaches depth=32 which is NOT > 32.
	 * make_nested_block(33) fails at depth=33 which IS > 32.
	 *
	 * @param int $levels Nesting levels below the root.
	 * @return array<string,mixed> Root block.
	 */
	private function make_nested_block( int $levels ): array {
		if ( $levels <= 0 ) {
			return [
				'blockName'    => 'core/paragraph',
				'attrs'        => [],
				'innerHTML'    => '<p>leaf</p>',
				'innerContent' => [ '<p>leaf</p>' ],
				'innerBlocks'  => [],
			];
		}
		$inner = $this->make_nested_block( $levels - 1 );
		return [
			'blockName'    => 'core/group',
			'attrs'        => [],
			'innerHTML'    => '<div></div>',
			'innerContent' => [ null ],
			'innerBlocks'  => [ $inner ],
		];
	}

	/**
	 * Count the total depth of a block tree (1-indexed: flat block = 1).
	 *
	 * @param array<int,mixed> $blocks Parsed block tree.
	 * @return int
	 */
	private static function count_tree_depth( array $blocks ): int {
		if ( empty( $blocks ) ) {
			return 0;
		}
		$max = 0;
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
			$depth = 1 + self::count_tree_depth( $inner );
			if ( $depth > $max ) {
				$max = $depth;
			}
		}
		return $max;
	}

	/**
	 * Write a block tree to a post after validating depth.
	 *
	 * Simulates the "replace all blocks" write-path in the plugin:
	 * validate depth → serialize → persist. Returns true on success or a
	 * WP_Error on depth violation (without touching the post).
	 *
	 * @param int              $post_id Post ID to write.
	 * @param array<int,mixed> $blocks  Block tree in WP internal format.
	 * @return true|\WP_Error
	 */
	private function write_blocks( int $post_id, array $blocks ) {
		$depth_check = BlockMutator::validate_tree_depth( $blocks );
		if ( is_wp_error( $depth_check ) ) {
			return $depth_check;
		}
		wp_update_post( [ 'ID' => $post_id, 'post_content' => serialize_blocks( $blocks ) ] );
		return true;
	}

	// ── Tests ──────────────────────────────────────────────────────────────

	/**
	 * Verify the count_tree_depth() helper counts correctly.
	 *
	 * Flat block = 1, group→para = 2, empty = 0.
	 */
	public function test_tree_depth_helper_counts_correctly(): void {
		$flat = [
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [],
				'innerHTML'    => '',
				'innerContent' => [],
				'innerBlocks'  => [],
			],
		];
		$this->assertSame( 1, self::count_tree_depth( $flat ) );

		$nested = [
			[
				'blockName'    => 'core/group',
				'attrs'        => [],
				'innerHTML'    => '',
				'innerContent' => [ null ],
				'innerBlocks'  => [
					[
						'blockName'    => 'core/paragraph',
						'attrs'        => [],
						'innerHTML'    => '',
						'innerContent' => [],
						'innerBlocks'  => [],
					],
				],
			],
		];
		$this->assertSame( 2, self::count_tree_depth( $nested ) );
		$this->assertSame( 0, self::count_tree_depth( [] ) );
	}

	/**
	 * Pins the constant to a value site owners can rely on.
	 * If this needs to change, callers and documentation need to too.
	 */
	public function test_max_depth_constant_is_32(): void {
		$this->assertSame( 32, BlockMutator::MAX_BLOCK_DEPTH );
	}

	/**
	 * MAX_BLOCK_DEPTH = 32. A tree of depth make_nested_block(32) must be accepted.
	 */
	public function test_replace_all_blocks_accepts_at_cap(): void {
		$result = $this->write_blocks(
			$this->post_id,
			[ $this->make_nested_block( BlockMutator::MAX_BLOCK_DEPTH ) ]
		);
		$this->assertNotInstanceOf( \WP_Error::class, $result, 'tree at MAX_BLOCK_DEPTH nesting must be accepted' );
	}

	/**
	 * Tree one level past cap must be cleanly rejected with block_depth_exceeded.
	 */
	public function test_replace_all_blocks_rejects_one_past_cap(): void {
		$result = $this->write_blocks(
			$this->post_id,
			[ $this->make_nested_block( BlockMutator::MAX_BLOCK_DEPTH + 1 ) ]
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_depth_exceeded', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 400, $data['status'] );
		$this->assertSame( 32, $data['max_depth'] );
	}

	/**
	 * Attempt to insert an over-cap tree as a child block.
	 *
	 * Constructs a deep subtree via replace-block so the final result
	 * would exceed MAX_BLOCK_DEPTH; BlockMutator must reject.
	 */
	public function test_insert_blocks_rejects_when_inserted_tree_exceeds_cap(): void {
		// Seed a flat paragraph.
		wp_update_post( [
			'ID'           => $this->post_id,
			'post_content' => serialize_blocks( [
				[
					'blockName'    => 'core/paragraph',
					'attrs'        => [],
					'innerHTML'    => '<p>flat</p>',
					'innerContent' => [ '<p>flat</p>' ],
					'innerBlocks'  => [],
				],
			] ),
		] );

		$parsed = parse_blocks( (string) get_post_field( 'post_content', $this->post_id ) );

		// Replace the flat block with a deeply nested subtree exceeding the cap.
		$result = BlockMutator::apply( $parsed, 'replace-block', [
			'path'      => [ 0 ],
			'block_def' => $this->make_nested_block( BlockMutator::MAX_BLOCK_DEPTH + 1 ),
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_depth_exceeded', $result->get_error_code() );
	}

	/**
	 * A rejected write leaves the post_content untouched.
	 */
	public function test_rejection_does_not_write(): void {
		$original = '<!-- wp:paragraph --><p>original</p><!-- /wp:paragraph -->';
		wp_update_post( [ 'ID' => $this->post_id, 'post_content' => $original ] );

		$over = $this->write_blocks(
			$this->post_id,
			[ $this->make_nested_block( BlockMutator::MAX_BLOCK_DEPTH + 5 ) ]
		);
		$this->assertInstanceOf( \WP_Error::class, $over );

		$this->assertSame(
			$original,
			(string) get_post_field( 'post_content', $this->post_id ),
			'post_content must be unchanged after a rejected write'
		);
	}

	/**
	 * Seed a tree at MAX_BLOCK_DEPTH. Wrapping any block adds 1 level —
	 * the resulting depth-exceeded tree must be rejected at apply() time.
	 */
	public function test_mutator_wrap_in_group_at_cap_is_rejected(): void {
		$at_cap_blocks = [ $this->make_nested_block( BlockMutator::MAX_BLOCK_DEPTH ) ];
		wp_update_post( [
			'ID'           => $this->post_id,
			'post_content' => serialize_blocks( $at_cap_blocks ),
		] );

		$parsed = parse_blocks( (string) get_post_field( 'post_content', $this->post_id ) );

		// Wrapping the outermost block adds one more nesting level → depth exceeded.
		$result = BlockMutator::apply( $parsed, 'wrap-in-group', [ 'path' => [ 0 ] ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_depth_exceeded', $result->get_error_code() );
	}
}
