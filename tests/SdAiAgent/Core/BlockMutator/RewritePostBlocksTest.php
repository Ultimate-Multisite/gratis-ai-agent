<?php
/**
 * Test case for the sd-ai-agent/rewrite-post-blocks ability (GH#1754).
 *
 * Covers:
 *   - BlockMutator::validate_rewrite_blocks() — pure validation/normalization.
 *   - BlockAbilities::handle_rewrite_post_blocks() — full handler integration.
 *
 * Acceptance criteria from t262-brief.md:
 *   AC1: 50 blocks → revision created, all get fresh sd_ref.
 *   AC2: Empty blocks: [] → empty_payload.
 *   AC3: > 200 top-level → payload_too_large.
 *   AC4: Depth > 32 → block_depth_exceeded.
 *   AC5: Tier violation → legacy block rejected.
 *   AC6: Bound attribute write without override → bound_attribute.
 *   AC7: Stale expected_revision_id → stale_revision (or 412).
 *   AC8: Response has revision_id, refs_count, block_count.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1754
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core\BlockMutator;

use SdAiAgent\Abilities\BlockAbilities;
use SdAiAgent\Core\BlockMutator;
use SdAiAgent\Core\BlockReferences;
use WP_UnitTestCase;

/**
 * Integration tests for rewrite-post-blocks.
 */
class RewritePostBlocksTest extends WP_UnitTestCase {

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Create a post with initial block content for rewrite tests.
	 *
	 * @return int Post ID.
	 */
	private function create_post_with_blocks(): int {
		$content = serialize_blocks( [
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '<p>Original content</p>',
				'innerContent' => [ '<p>Original content</p>' ],
			],
			[
				'blockName'    => 'core/heading',
				'attrs'        => [ 'level' => 2 ],
				'innerBlocks'  => [],
				'innerHTML'    => '<h2 class="wp-block-heading">Original heading</h2>',
				'innerContent' => [ '<h2 class="wp-block-heading">Original heading</h2>' ],
			],
		] );

		$post_id = self::factory()->post->create( [
			'post_content' => $content,
			'post_status'  => 'publish',
		] );

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		return $post_id;
	}

	/**
	 * Generate N simple paragraph block definitions for the agent input format.
	 *
	 * @param int $count Number of blocks to generate.
	 * @return array<int,array<string,mixed>> Block definitions.
	 */
	private function make_blocks( int $count ): array {
		$blocks = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$blocks[] = [
				'blockName'    => 'core/paragraph',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '<p>Block ' . $i . '</p>',
				'innerContent' => [ '<p>Block ' . $i . '</p>' ],
			];
		}
		return $blocks;
	}

	/**
	 * Build a deeply nested block tree to the specified depth.
	 *
	 * @param int $depth Target nesting depth.
	 * @return array<string,mixed> Single block with nested innerBlocks.
	 */
	private function make_deep_block( int $depth ): array {
		$block = [
			'blockName'    => 'core/paragraph',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => '<p>Leaf</p>',
			'innerContent' => [ '<p>Leaf</p>' ],
		];

		for ( $i = 0; $i < $depth; $i++ ) {
			$block = [
				'blockName'    => 'core/group',
				'attrs'        => [],
				'innerBlocks'  => [ $block ],
				'innerHTML'    => '',
				'innerContent' => [ null ],
			];
		}

		return $block;
	}

	// ── BlockMutator::validate_rewrite_blocks unit tests ──────────────────

	/**
	 * AC2: Empty blocks array → empty_payload.
	 */
	public function test_validate_empty_blocks_returns_empty_payload(): void {
		$result = BlockMutator::validate_rewrite_blocks( [] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'empty_payload', $result->get_error_code() );
	}

	/**
	 * AC3: > 200 top-level blocks → payload_too_large.
	 */
	public function test_validate_oversized_payload_returns_payload_too_large(): void {
		$blocks = $this->make_blocks( 201 );
		$result = BlockMutator::validate_rewrite_blocks( $blocks );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'payload_too_large', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 201, $data['block_count'] );
		$this->assertSame( 200, $data['max_rewrite_blocks'] );
	}

	/**
	 * Exactly 200 blocks is allowed.
	 */
	public function test_validate_exact_limit_succeeds(): void {
		$blocks = $this->make_blocks( 200 );
		$result = BlockMutator::validate_rewrite_blocks( $blocks );
		$this->assertIsArray( $result );
		$this->assertCount( 200, $result );
	}

	/**
	 * AC4: Depth > 32 → block_depth_exceeded.
	 */
	public function test_validate_deep_tree_returns_depth_exceeded(): void {
		$deep   = $this->make_deep_block( 33 );
		$result = BlockMutator::validate_rewrite_blocks( [ $deep ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_depth_exceeded', $result->get_error_code() );
	}

	/**
	 * Depth exactly 32 is allowed.
	 */
	public function test_validate_depth_at_limit_succeeds(): void {
		$deep   = $this->make_deep_block( 31 );
		$result = BlockMutator::validate_rewrite_blocks( [ $deep ] );
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );
	}

	/**
	 * AC6: Bound attribute write without override → bound_attribute.
	 */
	public function test_validate_bound_attribute_returns_error(): void {
		$blocks = [
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [
					'content'  => 'Direct write to bound attr',
					'metadata' => [
						'bindings' => [
							'content' => [
								'source' => 'core/post-meta',
								'args'   => [ 'key' => 'my_field' ],
							],
						],
					],
				],
				'innerBlocks'  => [],
				'innerHTML'    => '<p>Direct write to bound attr</p>',
				'innerContent' => [ '<p>Direct write to bound attr</p>' ],
			],
		];

		$result = BlockMutator::validate_rewrite_blocks( $blocks, false );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bound_attribute', $result->get_error_code() );
	}

	/**
	 * Bound attribute write WITH override succeeds.
	 */
	public function test_validate_bound_attribute_with_override_succeeds(): void {
		$blocks = [
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [
					'content'  => 'Direct write to bound attr',
					'metadata' => [
						'bindings' => [
							'content' => [
								'source' => 'core/post-meta',
								'args'   => [ 'key' => 'my_field' ],
							],
						],
					],
				],
				'innerBlocks'  => [],
				'innerHTML'    => '<p>Direct write to bound attr</p>',
				'innerContent' => [ '<p>Direct write to bound attr</p>' ],
			],
		];

		$result = BlockMutator::validate_rewrite_blocks( $blocks, true );
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );
	}

	/**
	 * Valid simple payload passes validation and returns normalized blocks.
	 */
	public function test_validate_simple_payload_succeeds(): void {
		$blocks = [
			[
				'blockName'    => 'core/heading',
				'attrs'        => [ 'level' => 1 ],
				'innerBlocks'  => [],
				'innerHTML'    => '<h1>Fresh start</h1>',
				'innerContent' => [ '<h1>Fresh start</h1>' ],
			],
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '<p>New content.</p>',
				'innerContent' => [ '<p>New content.</p>' ],
			],
		];

		$result = BlockMutator::validate_rewrite_blocks( $blocks );
		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertSame( 'core/heading', $result[0]['blockName'] );
		$this->assertSame( 'core/paragraph', $result[1]['blockName'] );
	}

	// ── Handler integration tests ─────────────────────────────────────────

	/**
	 * Missing post_id returns WP_Error missing_post_id.
	 */
	public function test_handler_missing_post_id(): void {
		$result = BlockAbilities::handle_rewrite_post_blocks( [
			'blocks' => $this->make_blocks( 1 ),
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_post_id', $result->get_error_code() );
	}

	/**
	 * Non-existent post_id returns WP_Error post_not_found.
	 */
	public function test_handler_post_not_found(): void {
		$result = BlockAbilities::handle_rewrite_post_blocks( [
			'post_id' => 999999,
			'blocks'  => $this->make_blocks( 1 ),
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	/**
	 * AC2: Empty blocks through handler → empty_payload.
	 */
	public function test_handler_empty_blocks(): void {
		$post_id = $this->create_post_with_blocks();
		$result  = BlockAbilities::handle_rewrite_post_blocks( [
			'post_id' => $post_id,
			'blocks'  => [],
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'empty_payload', $result->get_error_code() );
	}

	/**
	 * AC3: Oversized payload through handler → payload_too_large.
	 */
	public function test_handler_oversized_payload(): void {
		$post_id = $this->create_post_with_blocks();
		$result  = BlockAbilities::handle_rewrite_post_blocks( [
			'post_id' => $post_id,
			'blocks'  => $this->make_blocks( 201 ),
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'payload_too_large', $result->get_error_code() );
	}

	/**
	 * AC1: Rewrite with 50 blocks → revision created, all 50 get fresh sd_ref.
	 */
	public function test_handler_happy_path_50_blocks(): void {
		$post_id = $this->create_post_with_blocks();
		$blocks  = $this->make_blocks( 50 );

		$result = BlockAbilities::handle_rewrite_post_blocks( [
			'post_id' => $post_id,
			'blocks'  => $blocks,
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( 50, $result['block_count'] );
		$this->assertSame( 50, $result['refs_count'] );
		$this->assertArrayHasKey( 'revision_id', $result );
		$this->assertGreaterThan( 0, $result['revision_id'] );

		// Verify the post content was replaced.
		$post    = get_post( $post_id );
		$parsed  = parse_blocks( $post->post_content );
		$named   = array_values( array_filter( $parsed, static fn( $b ) => ! empty( $b['blockName'] ) ) );
		$this->assertCount( 50, $named );

		// All blocks have fresh sd_ref values.
		$refs = [];
		foreach ( $named as $block ) {
			$ref = $block['attrs']['metadata'][ BlockReferences::REF_KEY ] ?? null;
			$this->assertNotNull( $ref, 'Every block must have an sd_ref after rewrite.' );
			$this->assertStringStartsWith( 'blk_', $ref );
			$refs[] = $ref;
		}

		// All refs are unique.
		$this->assertCount( 50, array_unique( $refs ) );
	}

	/**
	 * Rewrite replaces all previous content — no leftover blocks.
	 */
	public function test_handler_replaces_all_content(): void {
		$post_id = $this->create_post_with_blocks();

		// Verify original has 2 blocks.
		$original = get_post( $post_id );
		$orig_blocks = parse_blocks( $original->post_content );
		$orig_named  = array_values( array_filter( $orig_blocks, static fn( $b ) => ! empty( $b['blockName'] ) ) );
		$this->assertCount( 2, $orig_named );

		// Rewrite with 3 different blocks.
		$new_blocks = [
			[
				'blockName'    => 'core/heading',
				'attrs'        => [ 'level' => 1 ],
				'innerBlocks'  => [],
				'innerHTML'    => '<h1 class="wp-block-heading">New Page</h1>',
				'innerContent' => [ '<h1 class="wp-block-heading">New Page</h1>' ],
			],
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '<p>First paragraph.</p>',
				'innerContent' => [ '<p>First paragraph.</p>' ],
			],
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '<p>Second paragraph.</p>',
				'innerContent' => [ '<p>Second paragraph.</p>' ],
			],
		];

		$result = BlockAbilities::handle_rewrite_post_blocks( [
			'post_id' => $post_id,
			'blocks'  => $new_blocks,
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 3, $result['block_count'] );

		// Verify DB content is only the new blocks.
		$post   = get_post( $post_id );
		$parsed = parse_blocks( $post->post_content );
		$named  = array_values( array_filter( $parsed, static fn( $b ) => ! empty( $b['blockName'] ) ) );
		$this->assertCount( 3, $named );
		$this->assertSame( 'core/heading', $named[0]['blockName'] );

		// Original content is gone.
		$this->assertStringNotContainsString( 'Original content', $post->post_content );
		$this->assertStringNotContainsString( 'Original heading', $post->post_content );
	}

	/**
	 * AC8: Stale expected_revision_id → revision_stale (412).
	 */
	public function test_handler_stale_revision(): void {
		$post_id = $this->create_post_with_blocks();

		// First update to create a revision.
		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => '<!-- wp:paragraph --><p>Updated</p><!-- /wp:paragraph -->',
		] );

		// Use a definitely stale revision ID.
		$result = BlockAbilities::handle_rewrite_post_blocks( [
			'post_id'              => $post_id,
			'blocks'               => $this->make_blocks( 1 ),
			'expected_revision_id' => 1, // Almost certainly stale.
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stale_revision', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 412, $data['status'] );
	}

	/**
	 * AC4: Depth > 32 through handler → block_depth_exceeded.
	 */
	public function test_handler_depth_exceeded(): void {
		$post_id = $this->create_post_with_blocks();
		$deep    = $this->make_deep_block( 33 );

		$result = BlockAbilities::handle_rewrite_post_blocks( [
			'post_id' => $post_id,
			'blocks'  => [ $deep ],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_depth_exceeded', $result->get_error_code() );
	}

	/**
	 * AC6: Bound-attribute violation through handler → bound_attribute.
	 */
	public function test_handler_bound_attribute_violation(): void {
		$post_id = $this->create_post_with_blocks();

		$blocks = [
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [
					'content'  => 'Direct write',
					'metadata' => [
						'bindings' => [
							'content' => [
								'source' => 'core/post-meta',
								'args'   => [ 'key' => 'my_field' ],
							],
						],
					],
				],
				'innerBlocks'  => [],
				'innerHTML'    => '<p>Direct write</p>',
				'innerContent' => [ '<p>Direct write</p>' ],
			],
		];

		$result = BlockAbilities::handle_rewrite_post_blocks( [
			'post_id' => $post_id,
			'blocks'  => $blocks,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bound_attribute', $result->get_error_code() );
	}

	/**
	 * Rewrite with nested blocks counts refs across all levels.
	 */
	public function test_handler_nested_blocks_ref_count(): void {
		$post_id = $this->create_post_with_blocks();

		$blocks = [
			[
				'blockName'    => 'core/group',
				'attrs'        => [],
				'innerBlocks'  => [
					[
						'blockName'    => 'core/paragraph',
						'attrs'        => [],
						'innerBlocks'  => [],
						'innerHTML'    => '<p>Child 1</p>',
						'innerContent' => [ '<p>Child 1</p>' ],
					],
					[
						'blockName'    => 'core/paragraph',
						'attrs'        => [],
						'innerBlocks'  => [],
						'innerHTML'    => '<p>Child 2</p>',
						'innerContent' => [ '<p>Child 2</p>' ],
					],
				],
				'innerHTML'    => '',
				'innerContent' => [ null, null ],
			],
		];

		$result = BlockAbilities::handle_rewrite_post_blocks( [
			'post_id' => $post_id,
			'blocks'  => $blocks,
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['block_count'] ); // 1 top-level.
		$this->assertSame( 3, $result['refs_count'] );  // group + 2 paragraphs.
	}

	/**
	 * Invalid blocks input (not an array) → invalid_blocks error.
	 */
	public function test_handler_invalid_blocks_type(): void {
		$post_id = $this->create_post_with_blocks();
		$result  = BlockAbilities::handle_rewrite_post_blocks( [
			'post_id' => $post_id,
			'blocks'  => 'not an array',
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_blocks', $result->get_error_code() );
	}

	/**
	 * Response structure contains all expected keys.
	 */
	public function test_handler_response_structure(): void {
		$post_id = $this->create_post_with_blocks();

		$result = BlockAbilities::handle_rewrite_post_blocks( [
			'post_id' => $post_id,
			'blocks'  => $this->make_blocks( 2 ),
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'post_id', $result );
		$this->assertArrayHasKey( 'revision_id', $result );
		$this->assertArrayHasKey( 'block_count', $result );
		$this->assertArrayHasKey( 'refs_count', $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( 2, $result['block_count'] );
		$this->assertSame( 2, $result['refs_count'] );
	}

	/**
	 * Existing refs on incoming blocks are stripped and replaced with fresh ones.
	 */
	public function test_handler_strips_existing_refs(): void {
		$post_id   = $this->create_post_with_blocks();
		$stale_ref = 'blk_stale12345678';

		$blocks = [
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [
					'metadata' => [
						BlockReferences::REF_KEY => $stale_ref,
					],
				],
				'innerBlocks'  => [],
				'innerHTML'    => '<p>Has stale ref</p>',
				'innerContent' => [ '<p>Has stale ref</p>' ],
			],
		];

		$result = BlockAbilities::handle_rewrite_post_blocks( [
			'post_id' => $post_id,
			'blocks'  => $blocks,
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['refs_count'] );

		// The persisted ref must NOT be the stale one.
		$post   = get_post( $post_id );
		$parsed = parse_blocks( $post->post_content );
		$named  = array_values( array_filter( $parsed, static fn( $b ) => ! empty( $b['blockName'] ) ) );

		$new_ref = $named[0]['attrs']['metadata'][ BlockReferences::REF_KEY ] ?? null;
		$this->assertNotNull( $new_ref );
		$this->assertNotSame( $stale_ref, $new_ref, 'Stale refs must be replaced with fresh ones.' );
		$this->assertStringStartsWith( 'blk_', $new_ref );
	}
}
