<?php
/**
 * Test case for BlockReferences (GH#1707).
 *
 * Covers the acceptance criteria from the issue:
 *
 * AC2: UUID format is `blk_` + 8 URL-safe base64url chars.
 * AC3: Existing refs are preserved across subsequent assign_refs() calls.
 * AC4: all_have_refs() correctly detects complete vs partial trees.
 * AC5: find_by_ref() correctly resolves nested blocks at depth >= 5.
 * AC6: Tree walks raise `block_depth_exceeded` WP_Error beyond MAX_DEPTH.
 * AC7: assign_refs() happy-path, missing-ref, nested-block, and
 *      persist_refs_for_post() no-revision coverage.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1707
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\BlockReferences;
use WP_UnitTestCase;

/**
 * Integration tests for BlockReferences.
 *
 * Uses WP_UnitTestCase so real parse_blocks() / serialize_blocks() /
 * database calls are available.
 */
class BlockReferencesTest extends WP_UnitTestCase {

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Build a minimal parsed-block array (simulating parse_blocks() output).
	 *
	 * @param string               $name        Block name (e.g. 'core/paragraph').
	 * @param array<string,mixed>  $attrs       Block attributes.
	 * @param array<int,mixed>     $inner_blocks Nested inner blocks.
	 * @return array<string,mixed>
	 */
	private function make_block( string $name, array $attrs = [], array $inner_blocks = [] ): array {
		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => '<p>Content</p>',
			'innerContent' => [ '<p>Content</p>' ],
		];
	}

	/**
	 * Build a nested block tree of depth $depth (root at depth 0).
	 *
	 * Each level wraps the next as an inner block of 'core/group'.
	 *
	 * @param int $depth Target nesting depth.
	 * @return array<string,mixed> Root block.
	 */
	private function make_nested_block( int $depth ): array {
		if ( $depth <= 0 ) {
			return $this->make_block( 'core/paragraph' );
		}

		return $this->make_block( 'core/group', [], [ $this->make_nested_block( $depth - 1 ) ] );
	}

	// ── assign_refs: happy path ────────────────────────────────────────────

	/**
	 * assign_refs() assigns a ref to a single block that lacks one.
	 */
	public function test_assign_refs_adds_ref_to_block_without_one(): void {
		$blocks = [ $this->make_block( 'core/paragraph' ) ];
		$result = BlockReferences::assign_refs( $blocks );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );

		$ref = $result[0]['attrs']['metadata'][ BlockReferences::REF_KEY ] ?? null;
		$this->assertNotNull( $ref, 'sd_ref should be set' );
		$this->assertMatchesRegularExpression( '/^blk_[A-Za-z0-9\-_]{8}$/', (string) $ref, 'UUID format' );
	}

	/**
	 * assign_refs() assigns refs to multiple blocks in a flat tree.
	 */
	public function test_assign_refs_adds_refs_to_multiple_blocks(): void {
		$blocks = [
			$this->make_block( 'core/paragraph' ),
			$this->make_block( 'core/heading' ),
			$this->make_block( 'core/image' ),
		];

		$result = BlockReferences::assign_refs( $blocks );
		$this->assertIsArray( $result );
		$this->assertCount( 3, $result );

		$refs = [];
		foreach ( $result as $block ) {
			$ref = $block['attrs']['metadata'][ BlockReferences::REF_KEY ] ?? null;
			$this->assertNotNull( $ref );
			$this->assertMatchesRegularExpression( '/^blk_[A-Za-z0-9\-_]{8}$/', (string) $ref );
			$refs[] = $ref;
		}

		// All refs must be unique.
		$this->assertCount( count( $result ), array_unique( $refs ), 'Refs must be unique within the document' );
	}

	// ── assign_refs: existing refs preserved ──────────────────────────────

	/**
	 * AC3: Existing refs are preserved across subsequent assign_refs() calls.
	 */
	public function test_assign_refs_preserves_existing_refs(): void {
		$original_ref = 'blk_existXXX';

		$blocks = [
			$this->make_block( 'core/paragraph', [
				'metadata' => [ BlockReferences::REF_KEY => $original_ref ],
			] ),
		];

		$result = BlockReferences::assign_refs( $blocks );
		$this->assertIsArray( $result );

		$ref = $result[0]['attrs']['metadata'][ BlockReferences::REF_KEY ] ?? null;
		$this->assertSame( $original_ref, $ref, 'Existing ref must be preserved' );
	}

	/**
	 * A block with an existing ref and a sibling without one: sibling gets a new ref.
	 */
	public function test_assign_refs_partial_existing_refs(): void {
		$original_ref = 'blk_existABC';

		$blocks = [
			$this->make_block( 'core/paragraph', [
				'metadata' => [ BlockReferences::REF_KEY => $original_ref ],
			] ),
			$this->make_block( 'core/heading' ),
		];

		$result = BlockReferences::assign_refs( $blocks );
		$this->assertIsArray( $result );

		// First block retains its original ref.
		$ref0 = $result[0]['attrs']['metadata'][ BlockReferences::REF_KEY ] ?? null;
		$this->assertSame( $original_ref, $ref0 );

		// Second block gets a new ref that is different from the original.
		$ref1 = $result[1]['attrs']['metadata'][ BlockReferences::REF_KEY ] ?? null;
		$this->assertNotNull( $ref1 );
		$this->assertNotSame( $original_ref, $ref1 );
	}

	// ── assign_refs: nested blocks ────────────────────────────────────────

	/**
	 * assign_refs() recurses into inner blocks.
	 */
	public function test_assign_refs_recurses_into_inner_blocks(): void {
		$inner  = $this->make_block( 'core/paragraph' );
		$blocks = [ $this->make_block( 'core/group', [], [ $inner ] ) ];

		$result = BlockReferences::assign_refs( $blocks );
		$this->assertIsArray( $result );

		// Inner block should also get a ref.
		$inner_ref = $result[0]['innerBlocks'][0]['attrs']['metadata'][ BlockReferences::REF_KEY ] ?? null;
		$this->assertNotNull( $inner_ref, 'Inner block must get a ref' );
		$this->assertMatchesRegularExpression( '/^blk_[A-Za-z0-9\-_]{8}$/', (string) $inner_ref );
	}

	// ── assign_refs: depth cap ────────────────────────────────────────────

	/**
	 * AC6: Trees deeper than MAX_DEPTH raise block_depth_exceeded WP_Error.
	 */
	public function test_assign_refs_exceeds_depth_cap_returns_wp_error(): void {
		// Build a tree one level deeper than the cap.
		$deep_block = $this->make_nested_block( BlockReferences::MAX_DEPTH + 1 );
		$result     = BlockReferences::assign_refs( [ $deep_block ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_depth_exceeded', $result->get_error_code() );
	}

	/**
	 * Trees at exactly MAX_DEPTH do NOT raise a depth error.
	 */
	public function test_assign_refs_at_max_depth_succeeds(): void {
		$deep_block = $this->make_nested_block( BlockReferences::MAX_DEPTH );
		$result     = BlockReferences::assign_refs( [ $deep_block ] );

		$this->assertIsArray( $result, 'Must succeed at exactly MAX_DEPTH' );
	}

	// ── all_have_refs ─────────────────────────────────────────────────────

	/**
	 * all_have_refs() returns false when a block is missing a ref.
	 */
	public function test_all_have_refs_returns_false_when_missing(): void {
		$blocks = [ $this->make_block( 'core/paragraph' ) ];
		$this->assertFalse( BlockReferences::all_have_refs( $blocks ) );
	}

	/**
	 * all_have_refs() returns true when every block has a ref.
	 */
	public function test_all_have_refs_returns_true_when_complete(): void {
		$blocks = [
			$this->make_block( 'core/paragraph', [ 'metadata' => [ BlockReferences::REF_KEY => 'blk_aaaaaaaa' ] ] ),
			$this->make_block( 'core/heading',   [ 'metadata' => [ BlockReferences::REF_KEY => 'blk_bbbbbbbb' ] ] ),
		];

		$this->assertTrue( BlockReferences::all_have_refs( $blocks ) );
	}

	/**
	 * all_have_refs() returns false when an inner block is missing a ref.
	 */
	public function test_all_have_refs_false_when_inner_block_missing(): void {
		$inner  = $this->make_block( 'core/paragraph' ); // no ref.
		$outer  = $this->make_block( 'core/group', [ 'metadata' => [ BlockReferences::REF_KEY => 'blk_aaaaaaaa' ] ], [ $inner ] );
		$blocks = [ $outer ];

		$this->assertFalse( BlockReferences::all_have_refs( $blocks ) );
	}

	// ── find_by_ref ───────────────────────────────────────────────────────

	/**
	 * find_by_ref() returns null for an unknown ref.
	 */
	public function test_find_by_ref_returns_null_for_unknown_ref(): void {
		$blocks = [ $this->make_block( 'core/paragraph', [ 'metadata' => [ BlockReferences::REF_KEY => 'blk_aaaaaaaa' ] ] ) ];
		$result = BlockReferences::find_by_ref( $blocks, 'blk_notexist' );
		$this->assertNull( $result );
	}

	/**
	 * find_by_ref() finds a top-level block.
	 */
	public function test_find_by_ref_finds_top_level_block(): void {
		$ref    = 'blk_toplevel';
		$blocks = [
			$this->make_block( 'core/heading' ),
			$this->make_block( 'core/paragraph', [ 'metadata' => [ BlockReferences::REF_KEY => $ref ] ] ),
		];

		$result = BlockReferences::find_by_ref( $blocks, $ref );
		$this->assertIsArray( $result );
		$this->assertSame( $ref, $result['block']['attrs']['metadata'][ BlockReferences::REF_KEY ] );
		$this->assertSame( [ 1 ], $result['path'] );
		$this->assertSame( 1, $result['flat_index'] );
	}

	/**
	 * AC5: find_by_ref() correctly resolves nested blocks at depth >= 5.
	 */
	public function test_find_by_ref_resolves_nested_block_at_depth_5(): void {
		$target_ref = 'blk_deepfnd5';

		// Build a 5-level-deep block tree. The target is the innermost block.
		$leaf = $this->make_block( 'core/paragraph', [ 'metadata' => [ BlockReferences::REF_KEY => $target_ref ] ] );

		$level = $leaf;
		for ( $d = 0; $d < 5; $d++ ) {
			$level = $this->make_block( 'core/group', [], [ $level ] );
		}

		$blocks = [ $level ];
		$result = BlockReferences::find_by_ref( $blocks, $target_ref );

		$this->assertIsArray( $result );
		$this->assertSame( $target_ref, $result['block']['attrs']['metadata'][ BlockReferences::REF_KEY ] );
		// Path length should be depth + 1 (root index + one per nesting level).
		$this->assertCount( 6, $result['path'], 'Path should have 6 elements (5 groups + leaf)' );
	}

	// ── persist_refs_for_post ─────────────────────────────────────────────

	/**
	 * persist_refs_for_post() returns false for a non-existent post ID.
	 */
	public function test_persist_refs_for_post_false_for_nonexistent_post(): void {
		$this->assertFalse( BlockReferences::persist_refs_for_post( 999999 ) );
	}

	/**
	 * AC1 / AC9: persist_refs_for_post() writes refs without creating a revision.
	 *
	 * Creates a post with serialised block content, calls persist_refs_for_post(),
	 * then asserts:
	 * - The return value is true.
	 * - The updated post_content contains sd_ref attributes.
	 * - No revision was created by the write.
	 */
	public function test_persist_refs_for_post_writes_refs_without_revision(): void {
		$content = "<!-- wp:paragraph -->\n<p>Hello world</p>\n<!-- /wp:paragraph -->";

		$post_id = $this->factory()->post->create( [
			'post_content' => $content,
			'post_status'  => 'publish',
		] );

		// Capture revision count before.
		$revisions_before = count( wp_get_post_revisions( $post_id ) );

		$result = BlockReferences::persist_refs_for_post( $post_id );
		$this->assertTrue( $result, 'persist_refs_for_post() should return true on success' );

		// Verify the post_content now contains the sd_ref attribute.
		$updated_post = get_post( $post_id );
		$this->assertNotNull( $updated_post );
		$this->assertStringContainsString(
			'"sd_ref"',
			$updated_post->post_content,
			'Persisted content should contain sd_ref attribute'
		);

		// AC9: no new revision was created.
		$revisions_after = count( wp_get_post_revisions( $post_id ) );
		$this->assertSame(
			$revisions_before,
			$revisions_after,
			'persist_refs_for_post() must not create a revision'
		);
	}

	/**
	 * Calling persist_refs_for_post() twice is idempotent (refs are preserved).
	 *
	 * On the second call, all refs are already present. The content should not
	 * change on the second call and no double-write is required.
	 */
	public function test_persist_refs_for_post_idempotent(): void {
		$content = "<!-- wp:paragraph -->\n<p>Idempotent test</p>\n<!-- /wp:paragraph -->";

		$post_id = $this->factory()->post->create( [
			'post_content' => $content,
			'post_status'  => 'publish',
		] );

		// First call assigns refs.
		BlockReferences::persist_refs_for_post( $post_id );
		$content_after_first = get_post( $post_id )->post_content;

		// Second call should return true but the content should be unchanged
		// because refs are already present.
		BlockReferences::persist_refs_for_post( $post_id );
		$content_after_second = get_post( $post_id )->post_content;

		// Both calls produce the same sd_ref values.
		preg_match_all( '/"sd_ref":"(blk_[A-Za-z0-9\-_]{8})"/', $content_after_first, $m1 );
		preg_match_all( '/"sd_ref":"(blk_[A-Za-z0-9\-_]{8})"/', $content_after_second, $m2 );

		$this->assertSame(
			$m1[1],
			$m2[1],
			'Refs must be stable across multiple persist_refs_for_post() calls'
		);
	}
}
