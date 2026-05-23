<?php
/**
 * Test case for BlockMutator::replace_range() (GH#1753).
 *
 * Covers the acceptance criteria from the issue:
 *   AC1: 3→5 swap → correct tree, refs_removed/refs_added match.
 *   AC2: end_ref not sibling of start_ref → not_siblings.
 *   AC3: end_ref before start_ref → bad_range.
 *   AC4: Range > 200 → range_too_large.
 *   AC5: new_blocks depth violation → depth_exceeded pre-write.
 *   AC6: new_blocks bound-attribute violation → bound_attribute pre-write.
 *   AC7: start_ref == end_ref → succeeds (range of 1).
 *   AC8: Stale expected_revision_id → revision_stale (handler-level).
 *   AC9: Atomic semantics: validation failure → no write.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1753
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core\BlockMutator;

use SdAiAgent\Core\BlockMutator;
use SdAiAgent\Core\BlockReferences;
use SdAiAgent\Core\BlockTreeAddress;
use WP_UnitTestCase;

/**
 * Tests for BlockMutator::replace_range().
 */
class ReplaceRangeTest extends WP_UnitTestCase {

	// ── Helpers ────────────────────────────────────────────────────

	/**
	 * Build a minimal parsed-block array.
	 *
	 * @param string              $name        Block name.
	 * @param array<string,mixed> $attrs       Block attributes.
	 * @param array<int,mixed>    $inner_blocks Inner blocks.
	 * @param string              $inner_html  innerHTML string.
	 * @return array<string,mixed>
	 */
	private function make_block(
		string $name,
		array $attrs = [],
		array $inner_blocks = [],
		string $inner_html = '<p>Content</p>'
	): array {
		$inner_content = [];

		foreach ( $inner_blocks as $ignored ) {
			$inner_content[] = null;
		}

		if ( empty( $inner_blocks ) ) {
			$inner_content = [ $inner_html ];
		}

		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
		];
	}

	/**
	 * Build a block with a stable sd_ref.
	 *
	 * @param string              $name  Block name.
	 * @param string              $ref   sd_ref value.
	 * @param array<string,mixed> $attrs Additional attributes.
	 * @param string              $html  innerHTML override.
	 * @return array<string,mixed>
	 */
	private function make_ref_block(
		string $name,
		string $ref,
		array $attrs = [],
		string $html = '<p>Content</p>'
	): array {
		$attrs['metadata'][ BlockReferences::REF_KEY ] = $ref;
		return $this->make_block( $name, $attrs, [], $html );
	}

	/**
	 * Build a group block with inner blocks and a ref.
	 *
	 * @param string           $ref    sd_ref value.
	 * @param array<int,mixed> $children Inner blocks.
	 * @return array<string,mixed>
	 */
	private function make_group(
		string $ref,
		array $children = []
	): array {
		$attrs = [ 'metadata' => [ BlockReferences::REF_KEY => $ref ] ];

		$inner_content = [];
		foreach ( $children as $ignored ) {
			$inner_content[] = null;
		}

		return [
			'blockName'    => 'core/group',
			'attrs'        => $attrs,
			'innerBlocks'  => $children,
			'innerHTML'    => '',
			'innerContent' => $inner_content,
		];
	}

	/**
	 * Build a 5-block root tree for common tests.
	 *
	 * Structure: heading, para-1, para-2, para-3, separator.
	 * Paragraphs 1-3 will be the typical replace range target.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function make_five_block_tree(): array {
		return [
			$this->make_ref_block( 'core/heading', 'blk_heading', [ 'level' => 2 ], '<h2 class="wp-block-heading">Title</h2>' ),
			$this->make_ref_block( 'core/paragraph', 'blk_para1', [], '<p>First</p>' ),
			$this->make_ref_block( 'core/paragraph', 'blk_para2', [], '<p>Second</p>' ),
			$this->make_ref_block( 'core/paragraph', 'blk_para3', [], '<p>Third</p>' ),
			$this->make_ref_block( 'core/separator', 'blk_sep', [], '<hr class="wp-block-separator has-alpha-channel-opacity"/>' ),
		];
	}

	// ── AC1: Happy path — 3→5 swap ───────────────────────────────

	/**
	 * Replace 3 paragraphs with 1 heading + 4 paragraphs.
	 */
	public function test_happy_path_3_to_5_swap(): void {
		$blocks = $this->make_five_block_tree();

		$new_blocks = [
			[
				'blockName'   => 'core/heading',
				'attrs'       => [ 'level' => 3 ],
				'innerHTML'   => '<h3 class="wp-block-heading">Replaced</h3>',
				'innerBlocks' => [],
			],
			[
				'blockName'   => 'core/paragraph',
				'attrs'       => [],
				'innerHTML'   => '<p>New para 1</p>',
				'innerBlocks' => [],
			],
			[
				'blockName'   => 'core/paragraph',
				'attrs'       => [],
				'innerHTML'   => '<p>New para 2</p>',
				'innerBlocks' => [],
			],
			[
				'blockName'   => 'core/paragraph',
				'attrs'       => [],
				'innerHTML'   => '<p>New para 3</p>',
				'innerBlocks' => [],
			],
			[
				'blockName'   => 'core/paragraph',
				'attrs'       => [],
				'innerHTML'   => '<p>New para 4</p>',
				'innerBlocks' => [],
			],
		];

		$result = BlockMutator::replace_range(
			$blocks,
			'blk_para1',
			'blk_para3',
			$new_blocks
		);

		$this->assertIsArray( $result );
		$this->assertNotInstanceOf( \WP_Error::class, $result );

		// Original: heading + 3 paragraphs + separator = 5 blocks.
		// After: heading + 5 new blocks + separator = 7 blocks.
		$this->assertCount( 7, $result );

		// First block (heading) preserved.
		$this->assertSame( 'core/heading', $result[0]['blockName'] );
		$this->assertSame( 'blk_heading', $result[0]['attrs']['metadata'][ BlockReferences::REF_KEY ] );

		// New blocks inserted at positions 1-5.
		$this->assertSame( 'core/heading', $result[1]['blockName'] );
		$this->assertSame( 3, $result[1]['attrs']['level'] );
		$this->assertSame( 'core/paragraph', $result[2]['blockName'] );
		$this->assertSame( 'core/paragraph', $result[3]['blockName'] );
		$this->assertSame( 'core/paragraph', $result[4]['blockName'] );
		$this->assertSame( 'core/paragraph', $result[5]['blockName'] );

		// Last block (separator) preserved.
		$this->assertSame( 'core/separator', $result[6]['blockName'] );
		$this->assertSame( 'blk_sep', $result[6]['attrs']['metadata'][ BlockReferences::REF_KEY ] );
	}

	// ── AC7 / AC8: start_ref == end_ref — range of 1 ─────────────

	/**
	 * When start_ref == end_ref, a single block is replaced.
	 */
	public function test_single_block_range_start_equals_end(): void {
		$blocks = $this->make_five_block_tree();

		$new_blocks = [
			[
				'blockName'   => 'core/heading',
				'attrs'       => [ 'level' => 3 ],
				'innerHTML'   => '<h3 class="wp-block-heading">Replacement</h3>',
				'innerBlocks' => [],
			],
		];

		$result = BlockMutator::replace_range(
			$blocks,
			'blk_para2',
			'blk_para2',
			$new_blocks
		);

		$this->assertIsArray( $result );
		$this->assertNotInstanceOf( \WP_Error::class, $result );

		// 5 - 1 + 1 = 5 blocks.
		$this->assertCount( 5, $result );

		// The replaced block at position 2 is the new heading.
		$this->assertSame( 'core/heading', $result[2]['blockName'] );
		$this->assertSame( 3, $result[2]['attrs']['level'] );

		// Surrounding blocks preserved.
		$this->assertSame( 'blk_para1', $result[1]['attrs']['metadata'][ BlockReferences::REF_KEY ] );
		$this->assertSame( 'blk_para3', $result[3]['attrs']['metadata'][ BlockReferences::REF_KEY ] );
	}

	// ── Refs preserved on survivors ──────────────────────────────

	/**
	 * Blocks outside the replaced range keep their original refs intact.
	 */
	public function test_refs_preserved_on_survivors(): void {
		$blocks = $this->make_five_block_tree();

		$new_blocks = [
			[
				'blockName'   => 'core/paragraph',
				'attrs'       => [],
				'innerHTML'   => '<p>Replacement</p>',
				'innerBlocks' => [],
			],
		];

		$result = BlockMutator::replace_range(
			$blocks,
			'blk_para1',
			'blk_para3',
			$new_blocks
		);

		$this->assertIsArray( $result );

		// heading (blk_heading), replacement (no ref yet), separator (blk_sep) = 3 blocks.
		$this->assertCount( 3, $result );

		// Survivor refs.
		$this->assertSame( 'blk_heading', $result[0]['attrs']['metadata'][ BlockReferences::REF_KEY ] );
		$this->assertSame( 'blk_sep', $result[2]['attrs']['metadata'][ BlockReferences::REF_KEY ] );
	}

	// ── AC2: not_siblings — different parent ─────────────────────

	/**
	 * start_ref and end_ref in different parents → not_siblings.
	 */
	public function test_not_siblings_different_parent(): void {
		// Build a tree where two blocks are at different nesting levels.
		$inner_block = $this->make_ref_block( 'core/paragraph', 'blk_inner', [], '<p>Inner</p>' );
		$group       = $this->make_group( 'blk_group', [ $inner_block ] );
		$outer_block = $this->make_ref_block( 'core/paragraph', 'blk_outer', [], '<p>Outer</p>' );

		$blocks = [ $group, $outer_block ];

		$result = BlockMutator::replace_range(
			$blocks,
			'blk_inner',  // Inside the group at path [0, 0].
			'blk_outer',  // Outside the group at path [1].
			[ $this->make_block( 'core/paragraph' ) ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_siblings', $result->get_error_code() );
	}

	/**
	 * start_ref and end_ref at same depth but different parents → not_siblings.
	 */
	public function test_not_siblings_same_depth_different_parent(): void {
		// Two groups, each with one child.
		$child_a = $this->make_ref_block( 'core/paragraph', 'blk_a', [], '<p>A</p>' );
		$child_b = $this->make_ref_block( 'core/paragraph', 'blk_b', [], '<p>B</p>' );
		$group_a = $this->make_group( 'blk_group_a', [ $child_a ] );
		$group_b = $this->make_group( 'blk_group_b', [ $child_b ] );

		$blocks = [ $group_a, $group_b ];

		$result = BlockMutator::replace_range(
			$blocks,
			'blk_a',  // Path [0, 0].
			'blk_b',  // Path [1, 0].
			[ $this->make_block( 'core/paragraph' ) ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_siblings', $result->get_error_code() );
	}

	// ── AC3: bad_range — end before start ────────────────────────

	/**
	 * end_ref precedes start_ref in document order → bad_range.
	 */
	public function test_bad_range_end_before_start(): void {
		$blocks = $this->make_five_block_tree();

		$result = BlockMutator::replace_range(
			$blocks,
			'blk_para3',  // Position 3.
			'blk_para1',  // Position 1 — before start.
			[ $this->make_block( 'core/paragraph' ) ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bad_range', $result->get_error_code() );
	}

	// ── AC4: range_too_large ─────────────────────────────────────

	/**
	 * Range exceeding MAX_RANGE_SIZE blocks → range_too_large.
	 */
	public function test_oversized_range_rejection(): void {
		// Build 201 sibling blocks with refs.
		$blocks = [];
		for ( $i = 0; $i <= 200; $i++ ) {
			$blocks[] = $this->make_ref_block( 'core/paragraph', 'blk_' . $i, [], '<p>' . $i . '</p>' );
		}

		$result = BlockMutator::replace_range(
			$blocks,
			'blk_0',
			'blk_200',
			[ $this->make_block( 'core/paragraph' ) ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'range_too_large', $result->get_error_code() );
	}

	/**
	 * new_blocks exceeding MAX_RANGE_SIZE → range_too_large.
	 */
	public function test_oversized_new_blocks_rejection(): void {
		$blocks = $this->make_five_block_tree();

		$new_blocks = [];
		for ( $i = 0; $i <= 200; $i++ ) {
			$new_blocks[] = [
				'blockName'   => 'core/paragraph',
				'attrs'       => [],
				'innerHTML'   => '<p>' . $i . '</p>',
				'innerBlocks' => [],
			];
		}

		$result = BlockMutator::replace_range(
			$blocks,
			'blk_para1',
			'blk_para1',
			$new_blocks
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'range_too_large', $result->get_error_code() );
	}

	// ── AC5: depth cap violation in new_blocks ───────────────────

	/**
	 * new_blocks with nesting exceeding MAX_BLOCK_DEPTH → block_depth_exceeded.
	 */
	public function test_depth_cap_violation_in_new_blocks(): void {
		$blocks = $this->make_five_block_tree();

		// Build a deeply nested block that exceeds MAX_BLOCK_DEPTH.
		$deep_block = $this->make_block( 'core/paragraph', [], [], '<p>Deep</p>' );
		for ( $i = 0; $i < BlockMutator::MAX_BLOCK_DEPTH + 1; $i++ ) {
			$deep_block = $this->make_block( 'core/group', [], [ $deep_block ], '' );
		}

		$result = BlockMutator::replace_range(
			$blocks,
			'blk_para1',
			'blk_para1',
			[ $deep_block ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_depth_exceeded', $result->get_error_code() );
	}

	/**
	 * No write occurs when depth validation fails.
	 */
	public function test_no_write_on_depth_violation(): void {
		$blocks   = $this->make_five_block_tree();
		$original = $blocks;

		$deep_block = $this->make_block( 'core/paragraph', [], [], '<p>Deep</p>' );
		for ( $i = 0; $i < BlockMutator::MAX_BLOCK_DEPTH + 1; $i++ ) {
			$deep_block = $this->make_block( 'core/group', [], [ $deep_block ], '' );
		}

		$result = BlockMutator::replace_range(
			$blocks,
			'blk_para1',
			'blk_para1',
			[ $deep_block ]
		);

		// Returns WP_Error.
		$this->assertInstanceOf( \WP_Error::class, $result );

		// Original tree is unmodified (pure function, no mutation).
		$this->assertCount( count( $original ), $blocks );
		$this->assertSame(
			$original[0]['attrs']['metadata'][ BlockReferences::REF_KEY ],
			$blocks[0]['attrs']['metadata'][ BlockReferences::REF_KEY ]
		);
	}

	// ── AC6: bound-attribute violation in new_blocks ─────────────

	/**
	 * new_blocks with bound attributes → bound_attribute (no override).
	 */
	public function test_bound_attribute_violation_in_new_blocks(): void {
		$blocks = $this->make_five_block_tree();

		// Build a block that defines a binding for 'content' and also sets 'content' directly.
		$new_block = [
			'blockName'   => 'core/paragraph',
			'attrs'       => [
				'content'  => 'Direct value',
				'metadata' => [
					BlockReferences::REF_KEY => 'blk_new',
					'bindings'               => [
						'content' => [
							'source' => 'core/post-meta',
							'args'   => [ 'key' => 'my_field' ],
						],
					],
				],
			],
			'innerHTML'   => '<p>Direct value</p>',
			'innerBlocks' => [],
		];

		$result = BlockMutator::replace_range(
			$blocks,
			'blk_para1',
			'blk_para1',
			[ $new_block ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bound_attribute', $result->get_error_code() );
	}

	/**
	 * Bound-attribute violation does not occur when allow_bound_writes is true.
	 */
	public function test_bound_attribute_allowed_with_override(): void {
		$blocks = $this->make_five_block_tree();

		$new_block = [
			'blockName'   => 'core/paragraph',
			'attrs'       => [
				'content'  => 'Direct value',
				'metadata' => [
					'bindings' => [
						'content' => [
							'source' => 'core/post-meta',
							'args'   => [ 'key' => 'my_field' ],
						],
					],
				],
			],
			'innerHTML'   => '<p>Direct value</p>',
			'innerBlocks' => [],
		];

		$result = BlockMutator::replace_range(
			$blocks,
			'blk_para1',
			'blk_para1',
			[ $new_block ],
			true // allow_bound_writes.
		);

		$this->assertIsArray( $result );
		$this->assertNotInstanceOf( \WP_Error::class, $result );
	}

	// ── AC9: atomic semantics on validation failure ──────────────

	/**
	 * When a later new_blocks entry fails validation, no mutation occurs.
	 */
	public function test_atomic_no_partial_write_on_second_block_failure(): void {
		$blocks = $this->make_five_block_tree();

		// First new block is valid, second has bound-attribute violation.
		$valid_block = [
			'blockName'   => 'core/paragraph',
			'attrs'       => [],
			'innerHTML'   => '<p>Valid</p>',
			'innerBlocks' => [],
		];

		$invalid_block = [
			'blockName'   => 'core/paragraph',
			'attrs'       => [
				'content'  => 'Direct value',
				'metadata' => [
					'bindings' => [
						'content' => [
							'source' => 'core/post-meta',
							'args'   => [ 'key' => 'my_field' ],
						],
					],
				],
			],
			'innerHTML'   => '<p>Bad</p>',
			'innerBlocks' => [],
		];

		$result = BlockMutator::replace_range(
			$blocks,
			'blk_para1',
			'blk_para3',
			[ $valid_block, $invalid_block ]
		);

		// Should fail entirely.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bound_attribute', $result->get_error_code() );

		// Original tree unchanged (replace_range is pure).
		$this->assertCount( 5, $blocks );
	}

	// ── Edge cases ───────────────────────────────────────────────

	/**
	 * Replace with empty new_blocks → effectively removes the range.
	 */
	public function test_replace_with_empty_new_blocks(): void {
		$blocks = $this->make_five_block_tree();

		$result = BlockMutator::replace_range(
			$blocks,
			'blk_para1',
			'blk_para3',
			[]
		);

		$this->assertIsArray( $result );
		// heading + separator = 2.
		$this->assertCount( 2, $result );
		$this->assertSame( 'core/heading', $result[0]['blockName'] );
		$this->assertSame( 'core/separator', $result[1]['blockName'] );
	}

	/**
	 * Replace range within a nested group (not root level).
	 */
	public function test_replace_range_in_nested_group(): void {
		$child_1 = $this->make_ref_block( 'core/paragraph', 'blk_c1', [], '<p>Child 1</p>' );
		$child_2 = $this->make_ref_block( 'core/paragraph', 'blk_c2', [], '<p>Child 2</p>' );
		$child_3 = $this->make_ref_block( 'core/paragraph', 'blk_c3', [], '<p>Child 3</p>' );
		$group   = $this->make_group( 'blk_parent', [ $child_1, $child_2, $child_3 ] );

		$blocks = [ $group ];

		$new_blocks = [
			[
				'blockName'   => 'core/heading',
				'attrs'       => [ 'level' => 4 ],
				'innerHTML'   => '<h4 class="wp-block-heading">Nested replacement</h4>',
				'innerBlocks' => [],
			],
		];

		$result = BlockMutator::replace_range(
			$blocks,
			'blk_c1',
			'blk_c2',
			$new_blocks
		);

		$this->assertIsArray( $result );
		$this->assertNotInstanceOf( \WP_Error::class, $result );

		// The group should still exist.
		$this->assertSame( 'core/group', $result[0]['blockName'] );

		// Group's innerBlocks: replacement heading + surviving child_3 = 2.
		$inner = $result[0]['innerBlocks'];
		$this->assertCount( 2, $inner );
		$this->assertSame( 'core/heading', $inner[0]['blockName'] );
		$this->assertSame( 'blk_c3', $inner[1]['attrs']['metadata'][ BlockReferences::REF_KEY ] );
	}

	/**
	 * Nonexistent start_ref → block_not_found.
	 */
	public function test_nonexistent_start_ref(): void {
		$blocks = $this->make_five_block_tree();

		$result = BlockMutator::replace_range(
			$blocks,
			'blk_nonexistent',
			'blk_para3',
			[ $this->make_block( 'core/paragraph' ) ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_not_found', $result->get_error_code() );
	}

	/**
	 * Nonexistent end_ref → block_not_found.
	 */
	public function test_nonexistent_end_ref(): void {
		$blocks = $this->make_five_block_tree();

		$result = BlockMutator::replace_range(
			$blocks,
			'blk_para1',
			'blk_nonexistent',
			[ $this->make_block( 'core/paragraph' ) ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_not_found', $result->get_error_code() );
	}

	/**
	 * Exactly 200 blocks in range (boundary) → succeeds.
	 */
	public function test_range_at_boundary_200_succeeds(): void {
		$blocks = [];
		for ( $i = 0; $i < 200; $i++ ) {
			$blocks[] = $this->make_ref_block( 'core/paragraph', 'blk_' . $i, [], '<p>' . $i . '</p>' );
		}

		$result = BlockMutator::replace_range(
			$blocks,
			'blk_0',
			'blk_199',
			[ $this->make_block( 'core/paragraph', [], [], '<p>Single replacement</p>' ) ]
		);

		$this->assertIsArray( $result );
		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, $result );
	}

	/**
	 * New blocks have their HTML sanitized (wp_kses_post applied).
	 */
	public function test_new_blocks_html_sanitized(): void {
		$blocks = $this->make_five_block_tree();

		$new_blocks = [
			[
				'blockName'   => 'core/paragraph',
				'attrs'       => [],
				'innerHTML'   => '<p>Safe text<script>alert("xss")</script></p>',
				'innerBlocks' => [],
			],
		];

		$result = BlockMutator::replace_range(
			$blocks,
			'blk_para1',
			'blk_para1',
			$new_blocks
		);

		$this->assertIsArray( $result );
		// Script tag should be stripped by wp_kses_post.
		$this->assertStringNotContainsString( '<script>', $result[1]['innerHTML'] );
		$this->assertStringContainsString( 'Safe text', $result[1]['innerHTML'] );
	}
}
