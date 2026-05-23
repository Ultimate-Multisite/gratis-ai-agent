<?php
/**
 * Test case for block depth cap and structural caps (GH#1714).
 *
 * Covers the acceptance criteria from t251:
 *   AC1: A block tree exactly MAX_BLOCK_DEPTH levels deep is accepted.
 *   AC2: A tree MAX_BLOCK_DEPTH+1 levels deep returns block_depth_exceeded with data.max_depth.
 *   AC3: update_blocks with updates.length > MAX_BATCH_SIZE returns batch_too_large with data.max_batch_size.
 *   AC4: Constants are publicly accessible (BlockMutator::MAX_BLOCK_DEPTH, BlockMutator::MAX_BATCH_SIZE).
 *   AC5: Wide-but-shallow tree passes depth validation without error.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1714
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\BlockMutator;
use SdAiAgent\Core\BlockReferences;
use WP_UnitTestCase;

/**
 * Integration tests for block depth cap (validate_tree_depth) and batch size cap (MAX_BATCH_SIZE).
 *
 * Uses WP_UnitTestCase so wp_kses_post() and other WP functions are available.
 */
class BlockDepthTest extends WP_UnitTestCase {

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Build a minimal parsed-block array.
	 *
	 * @param string             $name        Block name.
	 * @param array<int,mixed>   $inner_blocks Inner block array.
	 * @return array<string,mixed>
	 */
	private function make_block( string $name, array $inner_blocks = [] ): array {
		$inner_content = empty( $inner_blocks ) ? [ '<p>Content</p>' ] : array_fill( 0, count( $inner_blocks ), null );

		return [
			'blockName'    => $name,
			'attrs'        => [],
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => '<p>Content</p>',
			'innerContent' => $inner_content,
		];
	}

	/**
	 * Build a linearly-nested block tree $levels deep.
	 *
	 * make_nested_block(0) → a single leaf paragraph (no inner blocks).
	 * make_nested_block(n) → a core/group wrapping make_nested_block(n-1).
	 *
	 * So make_nested_block(MAX_BLOCK_DEPTH) creates a root group whose
	 * innerBlocks chain is MAX_BLOCK_DEPTH levels deep, terminating with a
	 * leaf that has no innerBlocks. This matches the fixture used in
	 * BlockReferencesTest and the "exactly MAX_BLOCK_DEPTH" acceptance criterion.
	 *
	 * @param int $levels Number of nesting levels below the root.
	 * @return array<string,mixed> Root block.
	 */
	private function make_nested_block( int $levels ): array {
		if ( $levels <= 0 ) {
			return $this->make_block( 'core/paragraph' );
		}

		return $this->make_block( 'core/group', [ $this->make_nested_block( $levels - 1 ) ] );
	}

	// ── AC4: Constants are publicly accessible ────────────────────────────

	/**
	 * AC4: BlockMutator::MAX_BLOCK_DEPTH is a public integer constant equal to 32.
	 */
	public function test_max_block_depth_constant_is_public_and_correct(): void {
		$this->assertSame( 32, BlockMutator::MAX_BLOCK_DEPTH, 'MAX_BLOCK_DEPTH must equal 32' );
	}

	/**
	 * AC4: BlockMutator::MAX_BATCH_SIZE is a public integer constant equal to 50.
	 */
	public function test_max_batch_size_constant_is_public_and_correct(): void {
		$this->assertSame( 50, BlockMutator::MAX_BATCH_SIZE, 'MAX_BATCH_SIZE must equal 50' );
	}

	/**
	 * BlockReferences::MAX_DEPTH aliases BlockMutator::MAX_BLOCK_DEPTH.
	 */
	public function test_block_references_max_depth_aliases_block_mutator(): void {
		$this->assertSame(
			BlockMutator::MAX_BLOCK_DEPTH,
			BlockReferences::MAX_DEPTH,
			'BlockReferences::MAX_DEPTH must equal BlockMutator::MAX_BLOCK_DEPTH'
		);
	}

	// ── AC1: Exactly MAX_BLOCK_DEPTH levels deep is accepted ──────────────

	/**
	 * AC1: validate_tree_depth() returns true for a tree exactly MAX_BLOCK_DEPTH levels deep.
	 */
	public function test_validate_tree_depth_at_max_depth_returns_true(): void {
		// make_nested_block(MAX_BLOCK_DEPTH) creates a chain where recursion
		// descends to depth=MAX_BLOCK_DEPTH before hitting a leaf with no innerBlocks.
		$root   = $this->make_nested_block( BlockMutator::MAX_BLOCK_DEPTH );
		$blocks = [ $root ];

		$result = BlockMutator::validate_tree_depth( $blocks );

		$this->assertTrue( $result, 'A tree exactly MAX_BLOCK_DEPTH levels deep must be accepted.' );
	}

	/**
	 * AC5: Wide-but-shallow tree passes depth validation.
	 *
	 * A flat list of 100 sibling blocks at depth=0 (no nesting) must not
	 * trigger the depth cap.
	 */
	public function test_validate_tree_depth_wide_shallow_tree_passes(): void {
		$blocks = array_fill( 0, 100, $this->make_block( 'core/paragraph' ) );

		$result = BlockMutator::validate_tree_depth( $blocks );

		$this->assertTrue( $result, 'A wide, non-nested tree must pass depth validation.' );
	}

	/**
	 * A tree with moderate nesting (10 levels) also passes.
	 */
	public function test_validate_tree_depth_moderate_nesting_passes(): void {
		$root   = $this->make_nested_block( 10 );
		$blocks = [ $root ];

		$result = BlockMutator::validate_tree_depth( $blocks );

		$this->assertTrue( $result, 'A 10-level nested tree must pass depth validation.' );
	}

	// ── AC2: MAX_BLOCK_DEPTH+1 levels returns block_depth_exceeded ────────

	/**
	 * AC2: validate_tree_depth() returns block_depth_exceeded for a tree one level deeper than the cap.
	 */
	public function test_validate_tree_depth_over_max_depth_returns_wp_error(): void {
		$root   = $this->make_nested_block( BlockMutator::MAX_BLOCK_DEPTH + 1 );
		$blocks = [ $root ];

		$result = BlockMutator::validate_tree_depth( $blocks );

		$this->assertInstanceOf(
			\WP_Error::class,
			$result,
			'A tree MAX_BLOCK_DEPTH+1 levels deep must return a WP_Error.'
		);
		$this->assertSame(
			'block_depth_exceeded',
			$result->get_error_code(),
			'Error code must be block_depth_exceeded.'
		);
	}

	/**
	 * AC2: The WP_Error data includes max_depth equal to MAX_BLOCK_DEPTH.
	 */
	public function test_validate_tree_depth_error_includes_max_depth_data(): void {
		$root   = $this->make_nested_block( BlockMutator::MAX_BLOCK_DEPTH + 1 );
		$blocks = [ $root ];

		$result = BlockMutator::validate_tree_depth( $blocks );

		$this->assertInstanceOf( \WP_Error::class, $result );

		$data = $result->get_error_data( 'block_depth_exceeded' );
		$this->assertIsArray( $data, 'Error data must be an array.' );
		$this->assertArrayHasKey( 'max_depth', $data, 'Error data must contain max_depth key.' );
		$this->assertSame(
			BlockMutator::MAX_BLOCK_DEPTH,
			$data['max_depth'],
			'data.max_depth must equal MAX_BLOCK_DEPTH.'
		);
		$this->assertSame( 400, $data['status'] ?? null, 'HTTP status must be 400.' );
	}

	/**
	 * validate_tree_depth() rejects trees significantly over the cap.
	 */
	public function test_validate_tree_depth_deeply_nested_tree_rejected(): void {
		$root   = $this->make_nested_block( BlockMutator::MAX_BLOCK_DEPTH + 10 );
		$blocks = [ $root ];

		$result = BlockMutator::validate_tree_depth( $blocks );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_depth_exceeded', $result->get_error_code() );
	}

	// ── AC3: Batch size cap ────────────────────────────────────────────────

	/**
	 * AC3: BlockMutator::apply_batch() returns batch_too_large when updates exceeds MAX_BATCH_SIZE.
	 *
	 * Tests the canonical enforcement point directly — the error is raised in
	 * apply_batch() before any tree walking, so no real block tree is needed.
	 */
	public function test_apply_batch_over_limit_returns_batch_too_large(): void {
		// Build MAX_BATCH_SIZE+1 minimal update stubs.
		$updates = array_fill(
			0,
			BlockMutator::MAX_BATCH_SIZE + 1,
			[
				'op'         => 'update-attrs',
				'flat_index' => 0,
				'attributes' => [],
			]
		);

		$result = BlockMutator::apply_batch( [], $updates );

		$this->assertInstanceOf(
			\WP_Error::class,
			$result,
			'Exceeding MAX_BATCH_SIZE must return a WP_Error.'
		);
		$this->assertSame(
			'batch_too_large',
			$result->get_error_code(),
			'Error code must be batch_too_large.'
		);

		$data = $result->get_error_data( 'batch_too_large' );
		$this->assertIsArray( $data, 'Error data must be an array.' );
		$this->assertArrayHasKey( 'max_batch_size', $data, 'Error data must contain max_batch_size.' );
		$this->assertSame(
			BlockMutator::MAX_BATCH_SIZE,
			$data['max_batch_size'],
			'data.max_batch_size must equal MAX_BATCH_SIZE.'
		);
		$this->assertSame( 400, $data['status'] ?? null, 'HTTP status must be 400.' );
	}

	/**
	 * BlockMutator::apply_batch() returns empty_batch for an empty updates array.
	 */
	public function test_apply_batch_empty_returns_error(): void {
		$result = BlockMutator::apply_batch( [], [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'empty_batch', $result->get_error_code() );
	}

	/**
	 * BlockMutator::apply_batch() accepts exactly MAX_BATCH_SIZE updates without batch_too_large.
	 *
	 * Passes a single-block tree with MAX_BATCH_SIZE identical update-attrs
	 * operations. The size gate must NOT fire. The duplicate-target pre-flight
	 * may raise batch_validation_failed (duplicate_target), which is acceptable —
	 * the important assertion is that batch_too_large is NOT the error.
	 */
	public function test_apply_batch_at_exact_limit_passes_size_check(): void {
		// A single-block flat tree.
		$blocks = [
			$this->make_block( 'core/paragraph' ),
		];

		// MAX_BATCH_SIZE updates all targeting the same block.
		$updates = array_fill(
			0,
			BlockMutator::MAX_BATCH_SIZE,
			[
				'op'         => 'update-attrs',
				'flat_index' => 0,
				'attributes' => [ 'align' => 'center' ],
			]
		);

		$result = BlockMutator::apply_batch( $blocks, $updates );

		// The batch-size gate must NOT fire.
		if ( is_wp_error( $result ) ) {
			$this->assertNotSame(
				'batch_too_large',
				$result->get_error_code(),
				'Exactly MAX_BATCH_SIZE updates must not trigger batch_too_large.'
			);
		} else {
			// All ops succeeded (unlikely with duplicates, but acceptable).
			$this->assertIsArray( $result );
		}
	}
}
