<?php
/**
 * Test case for BlockMutator (GH#1708).
 *
 * Covers the acceptance criteria from the issue:
 *   AC1: All 9 ops implemented and addressable by ref, path, flat_index.
 *   AC2: wp_kses_post runs on innerHTML in update-html, replace-block, insert-child.
 *   AC3: move rejects cycles with invalid_destination.
 *   AC4: unwrap-group rejects blocks with no innerBlocks (no_inner_blocks).
 *   AC5: wrap-in-group accepts optional attributes.
 *   AC6: duplicate JSON-clones and fails closed on invalid data.
 *   AC9: each op has >= 3 tests (happy, missing target, type-mismatch).
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1708
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1713
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\BlockMutator;
use SdAiAgent\Core\BlockReferences;
use SdAiAgent\Core\BlockTreeAddress;
use SdAiAgent\Core\DualStorageRegistry;
use WP_UnitTestCase;

/**
 * Integration tests for BlockMutator.
 *
 * Uses WP_UnitTestCase so wp_kses_post() and other WP functions are available.
 */
class BlockMutatorTest extends WP_UnitTestCase {

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Build a minimal parsed-block array.
	 *
	 * @param string              $name        Block name (e.g. 'core/paragraph').
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
	 * @return array<string,mixed>
	 */
	private function make_ref_block( string $name, string $ref, array $attrs = [] ): array {
		$attrs['metadata'][ BlockReferences::REF_KEY ] = $ref;
		return $this->make_block( $name, $attrs );
	}

	// ── Invalid op ────────────────────────────────────────────────────────

	/**
	 * An unknown op name returns WP_Error with code invalid_op.
	 */
	public function test_unknown_op_returns_error(): void {
		$blocks = [ $this->make_block( 'core/paragraph' ) ];
		$result = BlockMutator::apply( $blocks, 'nuke-everything', [ 'path' => [ 0 ] ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_op', $result->get_error_code() );
	}

	// ── update-attrs ──────────────────────────────────────────────────────

	/**
	 * update-attrs merges new attributes into the existing ones (default).
	 */
	public function test_update_attrs_merge_happy(): void {
		$blocks = [
			$this->make_block( 'core/heading', [ 'level' => 2 ] ),
		];

		$result = BlockMutator::apply( $blocks, 'update-attrs', [
			'path'       => [ 0 ],
			'attributes' => [ 'level' => 3, 'textAlign' => 'center' ],
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 3, $result[0]['attrs']['level'] );
		$this->assertSame( 'center', $result[0]['attrs']['textAlign'] );
	}

	/**
	 * update-attrs with merge:false replaces all attributes.
	 */
	public function test_update_attrs_replace_happy(): void {
		$blocks = [
			$this->make_block( 'core/heading', [ 'level' => 2, 'textAlign' => 'left' ] ),
		];

		$result = BlockMutator::apply( $blocks, 'update-attrs', [
			'path'       => [ 0 ],
			'attributes' => [ 'level' => 4 ],
			'merge'      => false,
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 4, $result[0]['attrs']['level'] );
		$this->assertArrayNotHasKey( 'textAlign', $result[0]['attrs'] );
	}

	/**
	 * update-attrs on a missing block path returns block_not_found.
	 */
	public function test_update_attrs_missing_block(): void {
		$blocks = [ $this->make_block( 'core/paragraph' ) ];
		$result = BlockMutator::apply( $blocks, 'update-attrs', [
			'path'       => [ 99 ],
			'attributes' => [ 'level' => 2 ],
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_not_found', $result->get_error_code() );
	}

	/**
	 * update-attrs without attributes key returns missing_attributes error.
	 */
	public function test_update_attrs_missing_attributes_key(): void {
		$blocks = [ $this->make_block( 'core/paragraph' ) ];
		$result = BlockMutator::apply( $blocks, 'update-attrs', [
			'path' => [ 0 ],
			// no 'attributes' key
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_attributes', $result->get_error_code() );
	}

	/**
	 * update-attrs addressed by ref works.
	 */
	public function test_update_attrs_addressed_by_ref(): void {
		$ref    = 'blk_testref1';
		$blocks = [ $this->make_ref_block( 'core/paragraph', $ref ) ];

		$result = BlockMutator::apply( $blocks, 'update-attrs', [
			'ref'        => $ref,
			'attributes' => [ 'dropCap' => true ],
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result[0]['attrs']['dropCap'] );
	}

	// ── update-html ───────────────────────────────────────────────────────

	/**
	 * update-html replaces innerHTML with sanitized content.
	 */
	public function test_update_html_happy(): void {
		$blocks = [ $this->make_block( 'core/paragraph' ) ];

		$result = BlockMutator::apply( $blocks, 'update-html', [
			'path'      => [ 0 ],
			'innerHTML' => '<p>New content</p>',
		] );

		$this->assertIsArray( $result );
		$this->assertSame( '<p>New content</p>', $result[0]['innerHTML'] );
	}

	/**
	 * update-html strips script tags via wp_kses_post (AC2).
	 */
	public function test_update_html_strips_script(): void {
		$blocks = [ $this->make_block( 'core/paragraph' ) ];

		$result = BlockMutator::apply( $blocks, 'update-html', [
			'path'      => [ 0 ],
			'innerHTML' => '<p>Text</p><script>alert(1)</script>',
		] );

		$this->assertIsArray( $result );
		$this->assertStringNotContainsString( '<script>', $result[0]['innerHTML'] );
		$this->assertStringContainsString( '<p>Text</p>', $result[0]['innerHTML'] );
	}

	/**
	 * update-html without innerHTML key returns missing_inner_html error.
	 */
	public function test_update_html_missing_key(): void {
		$blocks = [ $this->make_block( 'core/paragraph' ) ];
		$result = BlockMutator::apply( $blocks, 'update-html', [
			'path' => [ 0 ],
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_inner_html', $result->get_error_code() );
	}

	/**
	 * update-html addressed by flat_index works.
	 */
	public function test_update_html_addressed_by_flat_index(): void {
		$blocks = [
			$this->make_block( 'core/paragraph', [], [], '<p>First</p>' ),
			$this->make_block( 'core/paragraph', [], [], '<p>Second</p>' ),
		];

		$result = BlockMutator::apply( $blocks, 'update-html', [
			'flat_index' => 1,
			'innerHTML'  => '<p>Updated</p>',
		] );

		$this->assertIsArray( $result );
		$this->assertSame( '<p>Updated</p>', $result[1]['innerHTML'] );
		$this->assertSame( '<p>First</p>', $result[0]['innerHTML'] );
	}

	// ── replace-block ─────────────────────────────────────────────────────

	/**
	 * replace-block swaps a block with a new definition.
	 */
	public function test_replace_block_happy(): void {
		$blocks = [ $this->make_block( 'core/paragraph' ) ];

		$new_block = $this->make_block( 'core/heading', [ 'level' => 2 ], [], '<h2>New</h2>' );
		$result    = BlockMutator::apply( $blocks, 'replace-block', [
			'path'      => [ 0 ],
			'block_def' => $new_block,
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'core/heading', $result[0]['blockName'] );
		$this->assertSame( 2, $result[0]['attrs']['level'] );
	}

	/**
	 * replace-block sanitizes innerHTML in the new block (AC2).
	 */
	public function test_replace_block_sanitizes_html(): void {
		$blocks    = [ $this->make_block( 'core/paragraph' ) ];
		$new_block = $this->make_block( 'core/html', [], [], '<p>ok</p><script>evil()</script>' );

		$result = BlockMutator::apply( $blocks, 'replace-block', [
			'path'      => [ 0 ],
			'block_def' => $new_block,
		] );

		$this->assertIsArray( $result );
		$this->assertStringNotContainsString( '<script>', $result[0]['innerHTML'] );
	}

	/**
	 * replace-block without block_def returns missing_block_def error.
	 */
	public function test_replace_block_missing_def(): void {
		$blocks = [ $this->make_block( 'core/paragraph' ) ];
		$result = BlockMutator::apply( $blocks, 'replace-block', [
			'path' => [ 0 ],
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_block_def', $result->get_error_code() );
	}

	/**
	 * replace-block on non-existent path returns block_not_found.
	 */
	public function test_replace_block_not_found(): void {
		$blocks    = [ $this->make_block( 'core/paragraph' ) ];
		$new_block = $this->make_block( 'core/heading' );
		$result    = BlockMutator::apply( $blocks, 'replace-block', [
			'path'      => [ 5 ],
			'block_def' => $new_block,
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_not_found', $result->get_error_code() );
	}

	// ── remove-block ──────────────────────────────────────────────────────

	/**
	 * remove-block deletes the target block from root level.
	 */
	public function test_remove_block_happy(): void {
		$blocks = [
			$this->make_block( 'core/paragraph', [], [], '<p>Keep</p>' ),
			$this->make_block( 'core/heading', [], [], '<h2>Remove</h2>' ),
		];

		$result = BlockMutator::apply( $blocks, 'remove-block', [ 'path' => [ 1 ] ] );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertSame( 'core/paragraph', $result[0]['blockName'] );
	}

	/**
	 * remove-block from within innerBlocks reduces innerBlocks count.
	 */
	public function test_remove_block_inner(): void {
		$child1 = $this->make_block( 'core/paragraph', [], [], '<p>Child 1</p>' );
		$child2 = $this->make_block( 'core/paragraph', [], [], '<p>Child 2</p>' );
		$group  = $this->make_block( 'core/group', [], [ $child1, $child2 ], '' );

		$result = BlockMutator::apply( [ $group ], 'remove-block', [ 'path' => [ 0, 0 ] ] );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result[0]['innerBlocks'] );
		$this->assertSame( '<p>Child 2</p>', $result[0]['innerBlocks'][0]['innerHTML'] );
	}

	/**
	 * remove-block on missing path returns block_not_found.
	 */
	public function test_remove_block_not_found(): void {
		$blocks = [ $this->make_block( 'core/paragraph' ) ];
		$result = BlockMutator::apply( $blocks, 'remove-block', [ 'path' => [ 7 ] ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_not_found', $result->get_error_code() );
	}

	// ── wrap-in-group ─────────────────────────────────────────────────────

	/**
	 * wrap-in-group wraps target in a core/group block.
	 */
	public function test_wrap_in_group_happy(): void {
		$blocks = [ $this->make_block( 'core/paragraph' ) ];
		$result = BlockMutator::apply( $blocks, 'wrap-in-group', [ 'path' => [ 0 ] ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'core/group', $result[0]['blockName'] );
		$this->assertCount( 1, $result[0]['innerBlocks'] );
		$this->assertSame( 'core/paragraph', $result[0]['innerBlocks'][0]['blockName'] );
	}

	/**
	 * wrap-in-group accepts optional attributes for the wrapper (AC5).
	 */
	public function test_wrap_in_group_with_attributes(): void {
		$blocks = [ $this->make_block( 'core/paragraph' ) ];
		$result = BlockMutator::apply( $blocks, 'wrap-in-group', [
			'path'       => [ 0 ],
			'attributes' => [ 'layout' => [ 'type' => 'flex' ] ],
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'core/group', $result[0]['blockName'] );
		$this->assertArrayHasKey( 'layout', $result[0]['attrs'] );
		$this->assertSame( 'flex', $result[0]['attrs']['layout']['type'] );
	}

	/**
	 * wrap-in-group on missing block returns block_not_found.
	 */
	public function test_wrap_in_group_not_found(): void {
		$blocks = [ $this->make_block( 'core/paragraph' ) ];
		$result = BlockMutator::apply( $blocks, 'wrap-in-group', [ 'path' => [ 3 ] ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_not_found', $result->get_error_code() );
	}

	// ── unwrap-group ──────────────────────────────────────────────────────

	/**
	 * unwrap-group replaces a group with its innerBlocks.
	 */
	public function test_unwrap_group_happy(): void {
		$child1 = $this->make_block( 'core/paragraph', [], [], '<p>A</p>' );
		$child2 = $this->make_block( 'core/paragraph', [], [], '<p>B</p>' );
		$group  = $this->make_block( 'core/group', [], [ $child1, $child2 ], '' );

		$result = BlockMutator::apply( [ $group ], 'unwrap-group', [ 'path' => [ 0 ] ] );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertSame( 'core/paragraph', $result[0]['blockName'] );
		$this->assertSame( '<p>A</p>', $result[0]['innerHTML'] );
		$this->assertSame( '<p>B</p>', $result[1]['innerHTML'] );
	}

	/**
	 * unwrap-group on a block with no innerBlocks returns no_inner_blocks error (AC4).
	 */
	public function test_unwrap_group_no_inner_blocks(): void {
		$blocks = [ $this->make_block( 'core/paragraph' ) ];
		$result = BlockMutator::apply( $blocks, 'unwrap-group', [ 'path' => [ 0 ] ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'no_inner_blocks', $result->get_error_code() );
	}

	/**
	 * unwrap-group on missing path returns block_not_found.
	 */
	public function test_unwrap_group_not_found(): void {
		$blocks = [ $this->make_block( 'core/paragraph' ) ];
		$result = BlockMutator::apply( $blocks, 'unwrap-group', [ 'path' => [ 9 ] ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_not_found', $result->get_error_code() );
	}

	// ── insert-child ──────────────────────────────────────────────────────

	/**
	 * insert-child appends a child to innerBlocks by default.
	 */
	public function test_insert_child_append(): void {
		$child1 = $this->make_block( 'core/paragraph', [], [], '<p>Existing</p>' );
		$group  = $this->make_block( 'core/group', [], [ $child1 ], '' );

		$new_child = $this->make_block( 'core/image', [], [], '<figure></figure>' );
		$result    = BlockMutator::apply( [ $group ], 'insert-child', [
			'path'      => [ 0 ],
			'block_def' => $new_child,
		] );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result[0]['innerBlocks'] );
		$this->assertSame( 'core/image', $result[0]['innerBlocks'][1]['blockName'] );
	}

	/**
	 * insert-child at position 0 inserts before existing children.
	 */
	public function test_insert_child_at_position_zero(): void {
		$child1 = $this->make_block( 'core/paragraph', [], [], '<p>Original</p>' );
		$group  = $this->make_block( 'core/group', [], [ $child1 ], '' );

		$new_child = $this->make_block( 'core/heading', [], [], '<h2>New First</h2>' );
		$result    = BlockMutator::apply( [ $group ], 'insert-child', [
			'path'      => [ 0 ],
			'block_def' => $new_child,
			'position'  => 0,
		] );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result[0]['innerBlocks'] );
		$this->assertSame( 'core/heading', $result[0]['innerBlocks'][0]['blockName'] );
		$this->assertSame( 'core/paragraph', $result[0]['innerBlocks'][1]['blockName'] );
	}

	/**
	 * insert-child without block_def returns missing_block_def error.
	 */
	public function test_insert_child_missing_block_def(): void {
		$group  = $this->make_block( 'core/group' );
		$result = BlockMutator::apply( [ $group ], 'insert-child', [ 'path' => [ 0 ] ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_block_def', $result->get_error_code() );
	}

	/**
	 * insert-child syncs innerContent null count to match new innerBlocks count.
	 */
	public function test_insert_child_syncs_inner_content(): void {
		$child1 = $this->make_block( 'core/paragraph' );
		$group  = $this->make_block( 'core/group', [], [ $child1 ], '' );

		$new_child = $this->make_block( 'core/paragraph' );
		$result    = BlockMutator::apply( [ $group ], 'insert-child', [
			'path'      => [ 0 ],
			'block_def' => $new_child,
		] );

		$this->assertIsArray( $result );
		// innerContent should have 2 nulls for 2 innerBlocks.
		$null_count = count( array_filter( $result[0]['innerContent'], 'is_null' ) );
		$this->assertSame( 2, $null_count );
	}

	// ── duplicate ─────────────────────────────────────────────────────────

	/**
	 * duplicate inserts a clone immediately after the original.
	 */
	public function test_duplicate_happy(): void {
		$blocks = [
			$this->make_block( 'core/paragraph', [ 'content' => 'Hello' ], [], '<p>Hello</p>' ),
			$this->make_block( 'core/image' ),
		];

		$result = BlockMutator::apply( $blocks, 'duplicate', [ 'path' => [ 0 ] ] );

		$this->assertIsArray( $result );
		$this->assertCount( 3, $result );
		$this->assertSame( 'core/paragraph', $result[0]['blockName'] );
		$this->assertSame( 'core/paragraph', $result[1]['blockName'] );
		$this->assertSame( 'core/image', $result[2]['blockName'] );
	}

	/**
	 * duplicate strips sd_ref from the clone so refs stay unique (AC6).
	 */
	public function test_duplicate_strips_ref(): void {
		$ref    = 'blk_dup00001';
		$blocks = [ $this->make_ref_block( 'core/paragraph', $ref ) ];

		$result = BlockMutator::apply( $blocks, 'duplicate', [ 'path' => [ 0 ] ] );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );

		// Original keeps ref.
		$orig_ref = $result[0]['attrs']['metadata'][ BlockReferences::REF_KEY ] ?? null;
		$this->assertSame( $ref, $orig_ref );

		// Clone has no ref (stripped).
		$clone_ref = $result[1]['attrs']['metadata'][ BlockReferences::REF_KEY ] ?? null;
		$this->assertNull( $clone_ref );
	}

	/**
	 * duplicate on missing block returns block_not_found.
	 */
	public function test_duplicate_not_found(): void {
		$blocks = [ $this->make_block( 'core/paragraph' ) ];
		$result = BlockMutator::apply( $blocks, 'duplicate', [ 'path' => [ 4 ] ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_not_found', $result->get_error_code() );
	}

	// ── move ──────────────────────────────────────────────────────────────

	/**
	 * move relocates a block after the destination block.
	 */
	public function test_move_after_happy(): void {
		$blocks = [
			$this->make_block( 'core/paragraph', [], [], '<p>A</p>' ),
			$this->make_block( 'core/paragraph', [], [], '<p>B</p>' ),
			$this->make_block( 'core/paragraph', [], [], '<p>C</p>' ),
		];

		// Move block 0 (A) after block 2 (C). Result: B, C, A.
		$result = BlockMutator::apply( $blocks, 'move', [
			'path'        => [ 0 ],
			'destination' => [ 'path' => [ 2 ] ],
			'position'    => 'after',
		] );

		$this->assertIsArray( $result );
		$this->assertCount( 3, $result );
		$this->assertSame( '<p>B</p>', $result[0]['innerHTML'] );
		$this->assertSame( '<p>C</p>', $result[1]['innerHTML'] );
		$this->assertSame( '<p>A</p>', $result[2]['innerHTML'] );
	}

	/**
	 * move before destination positions the block correctly.
	 */
	public function test_move_before_happy(): void {
		$blocks = [
			$this->make_block( 'core/paragraph', [], [], '<p>A</p>' ),
			$this->make_block( 'core/paragraph', [], [], '<p>B</p>' ),
			$this->make_block( 'core/paragraph', [], [], '<p>C</p>' ),
		];

		// Move block 2 (C) before block 0 (A). Result: C, A, B.
		$result = BlockMutator::apply( $blocks, 'move', [
			'path'        => [ 2 ],
			'destination' => [ 'path' => [ 0 ] ],
			'position'    => 'before',
		] );

		$this->assertIsArray( $result );
		$this->assertCount( 3, $result );
		$this->assertSame( '<p>C</p>', $result[0]['innerHTML'] );
		$this->assertSame( '<p>A</p>', $result[1]['innerHTML'] );
		$this->assertSame( '<p>B</p>', $result[2]['innerHTML'] );
	}

	/**
	 * move into own descendant returns invalid_destination (AC3).
	 */
	public function test_move_cycle_detection(): void {
		$child = $this->make_block( 'core/paragraph' );
		$group = $this->make_block( 'core/group', [], [ $child ], '' );

		$result = BlockMutator::apply( [ $group ], 'move', [
			'path'        => [ 0 ],           // source: group at root
			'destination' => [ 'path' => [ 0, 0 ] ], // destination: inside group
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_destination', $result->get_error_code() );
	}

	/**
	 * move without destination returns missing_destination error.
	 */
	public function test_move_missing_destination(): void {
		$blocks = [
			$this->make_block( 'core/paragraph', [], [], '<p>A</p>' ),
			$this->make_block( 'core/paragraph', [], [], '<p>B</p>' ),
		];
		$result = BlockMutator::apply( $blocks, 'move', [ 'path' => [ 0 ] ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_destination', $result->get_error_code() );
	}

	/**
	 * move same-block (src == dst) returns invalid_destination.
	 */
	public function test_move_same_block(): void {
		$blocks = [ $this->make_block( 'core/paragraph' ) ];
		$result = BlockMutator::apply( $blocks, 'move', [
			'path'        => [ 0 ],
			'destination' => [ 'path' => [ 0 ] ],
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_destination', $result->get_error_code() );
	}

	// ── BlockTreeAddress (resolve / flat_index / is_strict_ancestor) ──────

	/**
	 * BlockTreeAddress::resolve returns correct path for ref addressing.
	 */
	public function test_address_resolve_by_ref(): void {
		$ref    = 'blk_resolve1';
		$blocks = [ $this->make_ref_block( 'core/paragraph', $ref ) ];

		$path = BlockTreeAddress::resolve( $blocks, [ 'ref' => $ref ] );
		$this->assertSame( [ 0 ], $path );
	}

	/**
	 * BlockTreeAddress::resolve returns correct path for flat_index addressing.
	 */
	public function test_address_resolve_by_flat_index(): void {
		$blocks = [
			$this->make_block( 'core/heading' ),
			$this->make_block( 'core/paragraph' ),
		];

		$path = BlockTreeAddress::resolve( $blocks, [ 'flat_index' => 1 ] );
		$this->assertSame( [ 1 ], $path );
	}

	/**
	 * BlockTreeAddress::resolve with invalid ref returns block_not_found.
	 */
	public function test_address_resolve_invalid_ref(): void {
		$blocks = [ $this->make_block( 'core/paragraph' ) ];
		$result = BlockTreeAddress::resolve( $blocks, [ 'ref' => 'blk_nonexist' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_not_found', $result->get_error_code() );
	}

	/**
	 * BlockTreeAddress::is_strict_ancestor returns true for prefix paths.
	 */
	public function test_is_strict_ancestor_true(): void {
		$this->assertTrue( BlockTreeAddress::is_strict_ancestor( [ 0 ], [ 0, 1 ] ) );
		$this->assertTrue( BlockTreeAddress::is_strict_ancestor( [ 0, 1 ], [ 0, 1, 2 ] ) );
	}

	/**
	 * BlockTreeAddress::is_strict_ancestor returns false for non-prefix paths.
	 */
	public function test_is_strict_ancestor_false(): void {
		$this->assertFalse( BlockTreeAddress::is_strict_ancestor( [ 1 ], [ 0, 1 ] ) );
		$this->assertFalse( BlockTreeAddress::is_strict_ancestor( [ 0, 1 ], [ 0, 1 ] ) ); // Equal, not strict ancestor.
		$this->assertFalse( BlockTreeAddress::is_strict_ancestor( [ 0, 1, 2 ], [ 0, 1 ] ) );
	}

	// ── Dual-storage enforcement (GH#1713) ────────────────────────────────

	/**
	 * update-attrs on a dual-storage block without innerHTML returns dual_storage_requires_both (AC1).
	 */
	public function test_update_attrs_dual_storage_without_html_returns_error(): void {
		$block  = $this->make_block( 'yoast/faq-block', [ 'questions' => [] ] );
		$result = BlockMutator::apply(
			[ $block ],
			'update-attrs',
			[
				'path'       => [ 0 ],
				'attributes' => [ 'questions' => [ [ 'id' => 'q1', 'question' => 'Q?', 'answer' => 'A.' ] ] ],
				// No innerHTML — must be rejected.
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'dual_storage_requires_both', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 'yoast/faq-block', $data['block_name'] );
		$this->assertSame( 400, $data['status'] );
	}

	/**
	 * update-html on a dual-storage block without attributes returns dual_storage_requires_both (AC2).
	 */
	public function test_update_html_dual_storage_without_attrs_returns_error(): void {
		$block  = $this->make_block( 'yoast/faq-block', [ 'questions' => [] ], [], '<div class="schema-faq"></div>' );
		$result = BlockMutator::apply(
			[ $block ],
			'update-html',
			[
				'path'      => [ 0 ],
				'innerHTML' => '<div class="schema-faq"><p>Updated</p></div>',
				// No attributes — must be rejected.
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'dual_storage_requires_both', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 'yoast/faq-block', $data['block_name'] );
		$this->assertSame( 400, $data['status'] );
	}

	/**
	 * update-attrs + innerHTML on a dual-storage block succeeds and updates both sides (AC3).
	 */
	public function test_update_attrs_dual_storage_with_both_sides_succeeds(): void {
		$block  = $this->make_block( 'yoast/faq-block', [ 'questions' => [] ], [], '<div class="schema-faq"></div>' );
		$result = BlockMutator::apply(
			[ $block ],
			'update-attrs',
			[
				'path'       => [ 0 ],
				'attributes' => [ 'questions' => [ [ 'id' => 'q1', 'question' => 'Q?', 'answer' => 'A.' ] ] ],
				'innerHTML'  => '<div class="schema-faq"><p>A.</p></div>',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'yoast/faq-block', $result[0]['blockName'] );
		$this->assertNotEmpty( $result[0]['attrs']['questions'] );
		$this->assertStringContainsString( 'A.', $result[0]['innerHTML'] );
	}

	/**
	 * update-html + attributes on a dual-storage block succeeds and updates both sides (AC3).
	 */
	public function test_update_html_dual_storage_with_both_sides_succeeds(): void {
		$block  = $this->make_block( 'yoast/faq-block', [ 'questions' => [] ], [], '<div class="schema-faq"></div>' );
		$result = BlockMutator::apply(
			[ $block ],
			'update-html',
			[
				'path'       => [ 0 ],
				'innerHTML'  => '<div class="schema-faq"><p>Updated answer</p></div>',
				'attributes' => [ 'questions' => [ [ 'id' => 'q1', 'question' => 'Q?', 'answer' => 'Updated answer' ] ] ],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'yoast/faq-block', $result[0]['blockName'] );
		$this->assertStringContainsString( 'Updated answer', $result[0]['innerHTML'] );
		$this->assertNotEmpty( $result[0]['attrs']['questions'] );
	}

	/**
	 * update-attrs on a non-dual-storage block (core/heading) is unaffected (AC4).
	 */
	public function test_update_attrs_non_dual_storage_block_unaffected(): void {
		$block  = $this->make_block( 'core/heading', [ 'level' => 2 ] );
		$result = BlockMutator::apply(
			[ $block ],
			'update-attrs',
			[
				'path'       => [ 0 ],
				'attributes' => [ 'level' => 3 ],
				// No innerHTML — fine for non-dual-storage blocks.
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 3, $result[0]['attrs']['level'] );
	}

	/**
	 * update-html on a non-dual-storage block without attributes is unaffected (AC4).
	 */
	public function test_update_html_non_dual_storage_block_unaffected(): void {
		$block  = $this->make_block( 'core/paragraph' );
		$result = BlockMutator::apply(
			[ $block ],
			'update-html',
			[
				'path'      => [ 0 ],
				'innerHTML' => '<p>New text</p>',
				// No attributes — fine for non-dual-storage blocks.
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( '<p>New text</p>', $result[0]['innerHTML'] );
	}

	/**
	 * yoast/how-to-block also triggers dual-storage enforcement (hard-coded list).
	 */
	public function test_how_to_block_also_enforced(): void {
		$block  = $this->make_block( 'yoast/how-to-block' );
		$result = BlockMutator::apply(
			[ $block ],
			'update-attrs',
			[
				'path'       => [ 0 ],
				'attributes' => [ 'steps' => [] ],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'dual_storage_requires_both', $result->get_error_code() );
	}

	/**
	 * error data block_name is populated correctly.
	 */
	public function test_dual_storage_error_data_contains_block_name(): void {
		$block  = $this->make_block( 'yoast/how-to-block' );
		$result = BlockMutator::apply(
			[ $block ],
			'update-html',
			[
				'path'      => [ 0 ],
				'innerHTML' => '<div></div>',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertSame( 'yoast/how-to-block', $data['block_name'] );
	}
}
