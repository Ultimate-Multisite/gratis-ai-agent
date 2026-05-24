<?php
// SPDX-License-Identifier: MIT
// SPDX-FileCopyrightText: 2025-2026 Marcus Quinn
/**
 * Scenario 6: sd_ref collision sweep under high churn (ported from block-mcp).
 *
 * The plugin's per-post uniqueness invariant: every block in a post has
 * a `sd_ref` that no other block in that post shares. That's what makes
 * ref-based addressing work across mutations.
 *
 * BlockReferences::assign_refs() emits `blk_` + 8 base64url-char refs
 * (48 bits of entropy). The recursive assigner threads an in-use set
 * through the recursion, re-rolling on collision, making the per-post
 * invariant deterministic.
 *
 * Ported one-for-one from block-mcp tests/Stress/RefCollisionStressTest.php
 * (GPL-2.0-or-later). Block_CRUD→BlockReferences, gk_ref→sd_ref, and
 * static-vs-instance adaptations applied per AGENTS.md.
 *
 * Note: Block_CRUD::generate_ref() and generate_unique_ref() were public
 * static methods in block-mcp. In our codebase generate_ref() is private
 * and generation is exposed only via assign_refs(). Tests 4-6 from the
 * original are adapted to test the same invariants via the public API.
 *
 * @package SdAiAgent\Tests\Stress
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1788
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Stress;

use SdAiAgent\Core\BlockReferences;
use WP_UnitTestCase;

/**
 * Ref collision stress tests.
 *
 * Uses WP_UnitTestCase so the full WordPress environment is available.
 */
class RefCollisionStressTest extends WP_UnitTestCase {

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Build a flat array of $count plain paragraphs with no refs.
	 *
	 * @param int $count Number of blocks.
	 * @return array<int,array<string,mixed>>
	 */
	private function make_plain_blocks( int $count ): array {
		$blocks = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$blocks[] = [
				'blockName'    => 'core/paragraph',
				'attrs'        => [],
				'innerHTML'    => "<p>$i</p>",
				'innerContent' => [ "<p>$i</p>" ],
				'innerBlocks'  => [],
			];
		}
		return $blocks;
	}

	/**
	 * Extract all sd_ref values from a flat block array.
	 *
	 * @param array<int,array<string,mixed>> $blocks
	 * @return string[]
	 */
	private function extract_refs( array $blocks ): array {
		$refs = [];
		foreach ( $blocks as $block ) {
			$ref = $block['attrs']['metadata'][ BlockReferences::REF_KEY ] ?? null;
			if ( null !== $ref ) {
				$refs[] = (string) $ref;
			}
		}
		return $refs;
	}

	// ── Tests ──────────────────────────────────────────────────────────────

	/**
	 * 2000 sibling blocks — well above any plausible page size. The
	 * assign_refs() assigner must produce zero collisions.
	 */
	public function test_per_post_assignment_produces_unique_refs_at_realistic_scale(): void {
		$count  = 2000;
		$blocks = $this->make_plain_blocks( $count );

		$result = BlockReferences::assign_refs( $blocks );
		$this->assertIsArray( $result, 'assign_refs() must return an array for a valid tree' );

		$seen = [];
		foreach ( $result as $i => $block ) {
			$ref = $block['attrs']['metadata'][ BlockReferences::REF_KEY ] ?? null;
			$this->assertNotEmpty( $ref, "block $i did not receive a ref" );
			$ref = (string) $ref;
			if ( isset( $seen[ $ref ] ) ) {
				$this->fail( "ref collision: block $i and block {$seen[$ref]} share $ref" );
			}
			$seen[ $ref ] = $i;
		}
		$this->assertCount( $count, $seen );
	}

	/**
	 * Build a 100-block tree, assign refs, strip them, then re-assign.
	 * The two ref sets must be completely disjoint and both internally unique.
	 *
	 * (Mirrors block-mcp test_fresh_refs_clone_invariant, using the
	 * strip-and-reassign pattern that reseed_for_post() implements.)
	 */
	public function test_fresh_refs_assignment_produces_disjoint_sets(): void {
		$blocks = $this->make_plain_blocks( 100 );

		// First assignment.
		$first_result = BlockReferences::assign_refs( $blocks );
		$this->assertIsArray( $first_result );
		$first_refs = $this->extract_refs( $first_result );
		$this->assertCount( 100, $first_refs, 'all blocks must receive refs' );

		// Strip refs to simulate a fresh-clone scenario.
		$stripped = array_map( function ( array $block ): array {
			if ( isset( $block['attrs']['metadata'][ BlockReferences::REF_KEY ] ) ) {
				unset( $block['attrs']['metadata'][ BlockReferences::REF_KEY ] );
			}
			return $block;
		}, $first_result );

		// Second assignment on the stripped tree.
		$second_result = BlockReferences::assign_refs( $stripped );
		$this->assertIsArray( $second_result );
		$second_refs = $this->extract_refs( $second_result );
		$this->assertCount( 100, $second_refs, 'all stripped blocks must receive fresh refs' );

		// The two sets must be disjoint (fresh refs, not recycled ones).
		$intersect = array_intersect( $first_refs, $second_refs );
		$this->assertEmpty( $intersect, 'fresh assignment must produce refs disjoint from the original set' );

		// Each set is internally unique.
		$this->assertSame( count( $first_refs ), count( array_unique( $first_refs ) ) );
		$this->assertSame( count( $second_refs ), count( array_unique( $second_refs ) ) );
	}

	/**
	 * Seed half the blocks with pre-assigned refs, half without.
	 * assign_refs() must skip seeded ones AND not mint a ref matching any
	 * already in the tree.
	 */
	public function test_assign_refs_does_not_collide_with_existing(): void {
		$blocks = [];

		// 50 blocks with pre-assigned refs.
		for ( $i = 0; $i < 50; $i++ ) {
			$seeded_ref = 'blk_seed' . sprintf( '%04d', $i );
			$blocks[]   = [
				'blockName'    => 'core/paragraph',
				'attrs'        => [ 'metadata' => [ BlockReferences::REF_KEY => $seeded_ref ] ],
				'innerHTML'    => "<p>seeded-$i</p>",
				'innerContent' => [ "<p>seeded-$i</p>" ],
				'innerBlocks'  => [],
			];
		}

		// 50 blocks without refs.
		for ( $i = 0; $i < 50; $i++ ) {
			$blocks[] = [
				'blockName'    => 'core/paragraph',
				'attrs'        => [],
				'innerHTML'    => "<p>unseeded-$i</p>",
				'innerContent' => [ "<p>unseeded-$i</p>" ],
				'innerBlocks'  => [],
			];
		}

		$result = BlockReferences::assign_refs( $blocks );
		$this->assertIsArray( $result );

		// Seeded refs survive untouched.
		for ( $i = 0; $i < 50; $i++ ) {
			$expected = 'blk_seed' . sprintf( '%04d', $i );
			$actual   = $result[ $i ]['attrs']['metadata'][ BlockReferences::REF_KEY ] ?? null;
			$this->assertSame(
				$expected,
				$actual,
				"seeded ref at index $i must not have been overwritten"
			);
		}

		// All 100 refs are unique.
		$refs = $this->extract_refs( $result );
		$this->assertSame( count( $refs ), count( array_unique( $refs ) ) );
	}

	/**
	 * Every ref produced by assign_refs() must match the URL-safe regex
	 * `^blk_[A-Za-z0-9\-_]{8}$` so it can be embedded in REST route paths.
	 *
	 * Generates 500 refs via repeated single-block assign_refs() calls.
	 */
	public function test_refs_match_url_safe_regex(): void {
		for ( $i = 0; $i < 500; $i++ ) {
			$result = BlockReferences::assign_refs( $this->make_plain_blocks( 1 ) );
			$this->assertIsArray( $result );
			$ref = $result[0]['attrs']['metadata'][ BlockReferences::REF_KEY ] ?? null;
			$this->assertNotEmpty( $ref, "iteration $i: block must receive a ref" );
			$this->assertMatchesRegularExpression(
				'/^blk_[A-Za-z0-9\-_]{8}$/',
				(string) $ref,
				"iteration $i: ref '$ref' must be URL-safe (blk_ + 8 base64url chars)"
			);
		}
	}

	/**
	 * All refs are prefixed with `blk_` for human readability and visibility
	 * in block comment markers.
	 */
	public function test_refs_prefixed_with_blk_for_visibility(): void {
		for ( $i = 0; $i < 100; $i++ ) {
			$result = BlockReferences::assign_refs( $this->make_plain_blocks( 1 ) );
			$this->assertIsArray( $result );
			$ref = $result[0]['attrs']['metadata'][ BlockReferences::REF_KEY ] ?? null;
			$this->assertStringStartsWith( 'blk_', (string) $ref );
		}
	}

	/**
	 * Ref collision is guarded even when the existing-ref set is artificially
	 * pre-populated. assign_refs() must produce refs absent from the existing
	 * set by using its internal collision-avoidance loop.
	 *
	 * We seed blocks with manually crafted refs and verify that any new
	 * blocks added get unique refs not in the seeded set.
	 */
	public function test_assign_refs_avoids_collisions_with_dense_existing_set(): void {
		// Seed 200 blocks with known refs.
		$blocks = [];
		for ( $i = 0; $i < 200; $i++ ) {
			$seeded_ref = 'blk_' . str_pad( (string) $i, 8, '0', STR_PAD_LEFT );
			$blocks[]   = [
				'blockName'    => 'core/paragraph',
				'attrs'        => [ 'metadata' => [ BlockReferences::REF_KEY => $seeded_ref ] ],
				'innerHTML'    => "<p>$i</p>",
				'innerContent' => [ "<p>$i</p>" ],
				'innerBlocks'  => [],
			];
		}
		// Add 50 unseeded blocks that need fresh refs.
		for ( $i = 0; $i < 50; $i++ ) {
			$blocks[] = [
				'blockName'    => 'core/paragraph',
				'attrs'        => [],
				'innerHTML'    => "<p>new-$i</p>",
				'innerContent' => [ "<p>new-$i</p>" ],
				'innerBlocks'  => [],
			];
		}

		$result = BlockReferences::assign_refs( $blocks );
		$this->assertIsArray( $result );

		// All 250 refs must be unique.
		$all_refs = $this->extract_refs( $result );
		$this->assertCount( 250, $all_refs );
		$this->assertSame( 250, count( array_unique( $all_refs ) ), 'all refs including newly generated ones must be unique' );
	}
}
