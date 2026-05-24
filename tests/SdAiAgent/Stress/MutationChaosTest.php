<?php
// SPDX-License-Identifier: MIT
// SPDX-FileCopyrightText: 2025-2026 Marcus Quinn
/**
 * Scenario 7: mutation-chaos walk (ported from block-mcp).
 *
 * Property-based test for the 9 path-based mutation operations.
 * Generates a randomized sequence of mutations against a seed post and
 * asserts that NO operation produces:
 *   - a WP_Error with no code,
 *   - a parse/serialize round-trip mismatch,
 *   - a broken `innerContent` placeholder count (null entries must equal
 *     count of innerBlocks),
 *   - duplicate `sd_ref` values within the post.
 *
 * The PRNG seed is deterministic (PHP's `mt_srand` with a fixed seed)
 * so failures can be reproduced exactly. Set `SD_AI_AGENT_CHAOS_SEED`
 * env var to a specific integer to reproduce a failure run.
 *
 * Ported one-for-one from block-mcp tests/Stress/MutationChaosTest.php
 * (GPL-2.0-or-later). Namespace, ref key, and op argument names updated
 * per AGENTS.md canonical naming rules.
 *
 * Budget: 60 ops, each under ~1s → total < 30s on a dev workstation.
 *
 * @package SdAiAgent\Tests\Stress
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1788
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Stress;

use SdAiAgent\Core\BlockMutator;
use SdAiAgent\Core\BlockReferences;
use WP_UnitTestCase;

/**
 * Chaos walk: randomized sequence of 60 mutation ops against a seed post.
 *
 * Invariants asserted after every successful op:
 *   1. innerContent null count == innerBlocks count for every block.
 *   2. sd_ref values are unique within the post (no duplicates).
 *   3. parse → serialize → parse is idempotent (structural skeleton preserved).
 */
class MutationChaosTest extends WP_UnitTestCase {

	/** @var int WP post ID used throughout the test. */
	private int $post_id;

	// ── Setup ─────────────────────────────────────────────────────────────

	public function set_up(): void {
		parent::set_up();

		// Seed with a small but interesting starter tree (WP internal format).
		$seed_blocks = [
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [],
				'innerHTML'    => '<p>seed-0</p>',
				'innerContent' => [ '<p>seed-0</p>' ],
				'innerBlocks'  => [],
			],
			[
				'blockName'    => 'core/group',
				'attrs'        => [],
				'innerHTML'    => '<div></div>',
				'innerContent' => [ null ],
				'innerBlocks'  => [
					[
						'blockName'    => 'core/paragraph',
						'attrs'        => [],
						'innerHTML'    => '<p>g0.0</p>',
						'innerContent' => [ '<p>g0.0</p>' ],
						'innerBlocks'  => [],
					],
				],
			],
			[
				'blockName'    => 'core/heading',
				'attrs'        => [ 'level' => 2 ],
				'innerHTML'    => '<h2>seed-h</h2>',
				'innerContent' => [ '<h2>seed-h</h2>' ],
				'innerBlocks'  => [],
			],
		];

		$this->post_id = self::factory()->post->create( [
			'post_content' => serialize_blocks( $seed_blocks ),
			'post_status'  => 'publish',
		] );
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Recursively collect every named block paired with its path.
	 *
	 * @param array<int,mixed> $blocks Parsed block tree.
	 * @param int[]            $prefix Accumulated path to the current level.
	 * @return array<int,array{path:int[],block:array<string,mixed>}>
	 */
	private function collect_paths( array $blocks, array $prefix = [] ): array {
		$out = [];
		foreach ( $blocks as $i => $block ) {
			if ( ! is_array( $block ) || null === ( $block['blockName'] ?? null ) ) {
				continue;
			}
			$path    = array_merge( $prefix, [ (int) $i ] );
			$out[]   = [ 'path' => $path, 'block' => $block ];
			$inner   = $block['innerBlocks'] ?? [];
			if ( ! empty( $inner ) ) {
				foreach ( $this->collect_paths( $inner, $path ) as $entry ) {
					$out[] = $entry;
				}
			}
		}
		return $out;
	}

	/**
	 * Assert tree well-formedness after each mutation.
	 *
	 * Checks innerContent placeholders, sd_ref uniqueness, and
	 * parse → serialize → parse idempotency.
	 *
	 * @param int $iteration Current chaos iteration (for failure messages).
	 */
	private function assert_tree_well_formed( int $iteration ): void {
		$content = (string) get_post_field( 'post_content', $this->post_id );
		$blocks  = parse_blocks( $content );

		$refs_seen = [];
		$walker    = function ( array $blks, string $where ) use ( $iteration, &$walker, &$refs_seen ): void {
			foreach ( $blks as $i => $block ) {
				if ( ! is_array( $block ) || null === ( $block['blockName'] ?? null ) ) {
					continue;
				}
				$here  = $where . "[$i]";
				// innerContent nulls must equal innerBlocks count.
				$nulls = 0;
				foreach ( $block['innerContent'] as $piece ) {
					if ( null === $piece ) {
						$nulls++;
					}
				}
				$this->assertSame(
					count( $block['innerBlocks'] ),
					$nulls,
					"iter $iteration $here: innerContent null count ($nulls) must equal innerBlocks count (" . count( $block['innerBlocks'] ) . ')'
				);
				// sd_ref must be unique within the post.
				$ref = $block['attrs']['metadata'][ BlockReferences::REF_KEY ] ?? null;
				if ( null !== $ref ) {
					$this->assertArrayNotHasKey(
						$ref,
						$refs_seen,
						"iter $iteration: ref $ref appears at $here AND " . ( $refs_seen[ $ref ] ?? '?' )
					);
					$refs_seen[ $ref ] = $here;
				}
				$walker( $block['innerBlocks'], $here );
			}
		};
		$walker( $blocks, '' );

		// parse → serialize → parse must be structurally idempotent.
		$re_serialized = serialize_blocks( $blocks );
		$re_parsed     = parse_blocks( $re_serialized );
		$this->assertSame(
			$this->canonicalize( $blocks ),
			$this->canonicalize( $re_parsed ),
			"iter $iteration: parse→serialize→parse must be idempotent"
		);
	}

	/**
	 * Strip volatile fields, retaining only the structural skeleton.
	 *
	 * @param array<int,mixed> $blocks
	 * @return array<int,mixed>
	 */
	private function canonicalize( array $blocks ): array {
		$out = [];
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || null === ( $block['blockName'] ?? null ) ) {
				continue;
			}
			$out[] = [
				'name'  => $block['blockName'],
				'attrs' => $block['attrs'],
				'inner' => $this->canonicalize( $block['innerBlocks'] ),
			];
		}
		return $out;
	}

	/**
	 * Build a minimal block definition for insert/replace ops.
	 *
	 * @param string $name  Block name.
	 * @param string $html  innerHTML value.
	 * @return array<string,mixed>
	 */
	private function make_block_def( string $name, string $html ): array {
		return [
			'blockName'    => $name,
			'attrs'        => [],
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
			'innerBlocks'  => [],
		];
	}

	// ── Test ──────────────────────────────────────────────────────────────

	/**
	 * Apply 60 random mutations and assert tree invariants after each success.
	 *
	 * Deterministic seed: set SD_AI_AGENT_CHAOS_SEED env var to reproduce a
	 * specific failure run.
	 *
	 * Budget: ~60 ops × <0.5s = well under 30s.
	 */
	public function test_random_mutation_walk_preserves_invariants(): void {
		$seed_env = (string) getenv( 'SD_AI_AGENT_CHAOS_SEED' );
		$seed     = '' !== $seed_env && ctype_digit( $seed_env ) ? (int) $seed_env : 1337;
		mt_srand( $seed );

		// Op pool weighted toward tree-shape-changing ops.
		$op_weights = [
			'update-attrs'  => 3,
			'update-html'   => 3,
			'replace-block' => 2,
			'wrap-in-group' => 2,
			'insert-child'  => 2,
			'duplicate'     => 2,
			'move'          => 1,
			'unwrap-group'  => 1,
			'remove-block'  => 1,
		];
		$op_pool = [];
		foreach ( $op_weights as $op => $w ) {
			for ( $j = 0; $j < $w; $j++ ) {
				$op_pool[] = $op;
			}
		}

		$iterations = 60;
		$ok_count   = 0;

		for ( $i = 0; $i < $iterations; $i++ ) {
			$content    = (string) get_post_field( 'post_content', $this->post_id );
			$parsed     = parse_blocks( $content );
			$candidates = $this->collect_paths( $parsed );

			if ( empty( $candidates ) ) {
				// Tree emptied by remove ops — reseed.
				$reseed = [
					[
						'blockName'    => 'core/paragraph',
						'attrs'        => [],
						'innerHTML'    => '<p>reseed</p>',
						'innerContent' => [ '<p>reseed</p>' ],
						'innerBlocks'  => [],
					],
				];
				wp_update_post( [
					'ID'           => $this->post_id,
					'post_content' => serialize_blocks( $reseed ),
				] );
				continue;
			}

			$pick = $candidates[ mt_rand( 0, count( $candidates ) - 1 ) ];
			$op   = $op_pool[ mt_rand( 0, count( $op_pool ) - 1 ) ];
			$path = $pick['path'];
			$args = [ 'path' => $path ];

			switch ( $op ) {
				case 'update-attrs':
					$args['attributes'] = [ 'className' => 'chaos-' . $i ];
					break;

				case 'update-html':
					$args['innerHTML'] = "<p>chaos-html-$i</p>";
					break;

				case 'replace-block':
					$args['block_def'] = $this->make_block_def( 'core/paragraph', "<p>replaced-$i</p>" );
					break;

				case 'wrap-in-group':
					// No extra args needed.
					break;

				case 'insert-child':
					// Only valid for container blocks.
					$has_inner = ! empty( $pick['block']['innerBlocks'] );
					if ( ! $has_inner ) {
						// Fall back to a safe op.
						$op              = 'update-attrs';
						$args['attributes'] = [ 'className' => 'chaos-fallback-' . $i ];
					} else {
						$args['block_def'] = $this->make_block_def( 'core/paragraph', "<p>child-$i</p>" );
					}
					break;

				case 'duplicate':
					// No extra args needed.
					break;

				case 'remove-block':
					// Avoid emptying the tree.
					if ( count( $candidates ) <= 1 ) {
						$op              = 'update-attrs';
						$args['attributes'] = [ 'className' => 'chaos-fallback-' . $i ];
					}
					break;

				case 'move':
					// Move path math is covered by dedicated unit tests; fall through.
					$op              = 'update-attrs';
					$args['attributes'] = [ 'className' => 'chaos-move-skip-' . $i ];
					break;

				case 'unwrap-group':
					if ( 'core/group' !== ( $pick['block']['blockName'] ?? '' ) ) {
						$op              = 'update-attrs';
						$args['attributes'] = [ 'className' => 'chaos-fallback-' . $i ];
					}
					break;
			}

			$result = BlockMutator::apply( $parsed, $op, $args );

			if ( is_wp_error( $result ) ) {
				// Clean errors are acceptable — they prove validation catches
				// non-applicable ops. Assert the code is a known one.
				$this->assertContains(
					$result->get_error_code(),
					[
						'invalid_path',
						'invalid_op',
						'path_not_container',
						'missing_block',
						'missing_attributes',
						'missing_inner_html',
						'missing_block_def',
						'block_depth_exceeded',
						'rate_limit_exceeded',
						'no_inner_blocks',
						'invalid_destination',
						'bound_attribute',
						'legacy_block',
						'dual_storage_update_required',
					],
					"iter $i: $op at path " . json_encode( $path ) . ' produced unexpected error: ' . $result->get_error_code()
				);
				continue;
			}

			// Write mutated tree back to the post.
			wp_update_post( [
				'ID'           => $this->post_id,
				'post_content' => serialize_blocks( $result ),
			] );

			$ok_count++;
			$this->assert_tree_well_formed( $i );
		}

		// At least half the ops should land cleanly; otherwise the generator
		// is producing too many rejections to be useful.
		$this->assertGreaterThan(
			$iterations / 2,
			$ok_count,
			"only $ok_count / $iterations chaos ops succeeded — too many rejections"
		);
	}
}
