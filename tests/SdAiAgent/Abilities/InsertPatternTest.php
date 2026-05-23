<?php
/**
 * Test case for the sd-ai-agent/insert-pattern ability handler (GH#1748).
 *
 * Covers:
 *   AC1: Registered pattern inline expansion at top level.
 *   AC2: Synced pattern → single core/block ref (NOT inlined).
 *   AC3: Synced pattern accepts numeric, "wp-block:N", "synced:N".
 *   AC4: Unknown pattern → pattern_not_found with suggestions.
 *   AC5: first_child_of_ref with non-container → not_a_container.
 *   AC6: expected_revision_id mismatch → stale_revision.
 *   AC7: Refs assigned to every inserted block.
 *   AC8: Validation errors (missing post_id, missing pattern, invalid anchor).
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1748
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\BlockAbilities;
use SdAiAgent\Core\BlockReferences;
use SdAiAgent\Core\RevisionGuard;
use WP_UnitTestCase;

/**
 * Integration tests for BlockAbilities::handle_insert_pattern.
 *
 * Uses WP_UnitTestCase so real posts and pattern registration are available.
 */
class InsertPatternTest extends WP_UnitTestCase {

	/**
	 * Test pattern name used across tests.
	 *
	 * @var string
	 */
	private const TEST_PATTERN = 'sd-ai-agent-test/insert-test';

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		// Register a test pattern for all tests.
		if ( ! \WP_Block_Patterns_Registry::get_instance()->is_registered( self::TEST_PATTERN ) ) {
			register_block_pattern(
				self::TEST_PATTERN,
				[
					'title'   => 'Insert Test Pattern',
					'content' => '<!-- wp:quote --><blockquote class="wp-block-quote"><p>Test quote</p></blockquote><!-- /wp:quote -->',
				]
			);
		}
	}

	/**
	 * Clean up test fixtures.
	 */
	public function tear_down(): void {
		if ( \WP_Block_Patterns_Registry::get_instance()->is_registered( self::TEST_PATTERN ) ) {
			unregister_block_pattern( self::TEST_PATTERN );
		}

		parent::tear_down();
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Create a post with two blocks using serialize_blocks for clean indices.
	 *
	 * @param string $content Optional custom content.
	 * @return int Post ID.
	 */
	private function create_post_with_blocks( string $content = '' ): int {
		if ( '' === $content ) {
			$content = serialize_blocks( [
				[
					'blockName'    => 'core/paragraph',
					'attrs'        => [],
					'innerBlocks'  => [],
					'innerHTML'    => '<p>Hello world</p>',
					'innerContent' => [ '<p>Hello world</p>' ],
				],
				[
					'blockName'    => 'core/heading',
					'attrs'        => [ 'level' => 2 ],
					'innerBlocks'  => [],
					'innerHTML'    => '<h2 class="wp-block-heading">Section</h2>',
					'innerContent' => [ '<h2 class="wp-block-heading">Section</h2>' ],
				],
			] );
		}

		$post_id = self::factory()->post->create( [
			'post_content' => $content,
			'post_status'  => 'publish',
		] );

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		return $post_id;
	}

	/**
	 * Create a post and assign refs so ref-based addressing works.
	 *
	 * @return array{post_id: int, refs: string[]} Post ID and assigned ref values.
	 */
	private function create_post_with_refs(): array {
		$post_id = $this->create_post_with_blocks();

		// Assign refs via the get-page-blocks handler.
		$result = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => true,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'blocks', $result );

		$refs = [];
		foreach ( $result['blocks'] as $block ) {
			if ( isset( $block['ref'] ) ) {
				$refs[] = $block['ref'];
			}
		}

		return [ 'post_id' => $post_id, 'refs' => $refs ];
	}

	/**
	 * Create a published wp_block (synced pattern).
	 *
	 * @return int Post ID.
	 */
	private function create_synced_pattern(): int {
		$post_id = self::factory()->post->create( [
			'post_type'    => 'wp_block',
			'post_status'  => 'publish',
			'post_title'   => 'Test Synced Pattern',
			'post_content' => '<!-- wp:paragraph --><p>Synced content</p><!-- /wp:paragraph -->',
		] );

		$this->assertIsInt( $post_id );

		return $post_id;
	}

	// ── Validation errors ─────────────────────────────────────────────────

	/**
	 * Missing post_id returns WP_Error.
	 */
	public function test_missing_post_id_returns_error(): void {
		$result = BlockAbilities::handle_insert_pattern( [
			'pattern' => 'core/quote',
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_post_id', $result->get_error_code() );
	}

	/**
	 * Missing pattern returns WP_Error.
	 */
	public function test_missing_pattern_returns_error(): void {
		$post_id = $this->create_post_with_blocks();
		$result  = BlockAbilities::handle_insert_pattern( [
			'post_id' => $post_id,
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_pattern', $result->get_error_code() );
	}

	/**
	 * Invalid anchor returns WP_Error.
	 */
	public function test_invalid_anchor_returns_error(): void {
		$post_id = $this->create_post_with_blocks();
		$result  = BlockAbilities::handle_insert_pattern( [
			'post_id' => $post_id,
			'pattern' => self::TEST_PATTERN,
			'anchor'  => 'invalid_anchor',
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_anchor', $result->get_error_code() );
	}

	/**
	 * Ref-based anchor without ref returns missing_ref.
	 */
	public function test_ref_anchor_without_ref_returns_error(): void {
		$post_id = $this->create_post_with_blocks();
		$result  = BlockAbilities::handle_insert_pattern( [
			'post_id' => $post_id,
			'pattern' => self::TEST_PATTERN,
			'anchor'  => 'after_ref',
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_ref', $result->get_error_code() );
	}

	/**
	 * Nonexistent post returns post_not_found.
	 */
	public function test_nonexistent_post_returns_error(): void {
		$result = BlockAbilities::handle_insert_pattern( [
			'post_id' => 999999,
			'pattern' => self::TEST_PATTERN,
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	/**
	 * Unknown registered pattern returns pattern_not_found with suggestions.
	 */
	public function test_unknown_pattern_returns_error_with_suggestions(): void {
		$post_id = $this->create_post_with_blocks();
		$result  = BlockAbilities::handle_insert_pattern( [
			'post_id' => $post_id,
			'pattern' => 'nonexistent/pattern-slug',
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'pattern_not_found', $result->get_error_code() );

		$data = $result->get_error_data( 'pattern_not_found' );
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'suggestions', $data );
	}

	// ── AC1: Registered pattern inline expansion ──────────────────────────

	/**
	 * Registered pattern inserted at after_top_level expands inline.
	 */
	public function test_registered_pattern_after_top_level(): void {
		$post_id = $this->create_post_with_blocks();

		$result = BlockAbilities::handle_insert_pattern( [
			'post_id' => $post_id,
			'pattern' => self::TEST_PATTERN,
			'anchor'  => 'after_top_level',
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'registered', $result['pattern_type'] );
		$this->assertSame( 1, $result['blocks_inserted'] );

		// Verify the post content now has 3 named blocks (2 original + 1 pattern).
		$post    = get_post( $post_id );
		$blocks  = parse_blocks( $post->post_content );
		$named   = array_filter( $blocks, fn( $b ) => ! empty( $b['blockName'] ) );
		$this->assertCount( 3, $named );

		// Last named block should be core/quote.
		$last = end( $named );
		$this->assertSame( 'core/quote', $last['blockName'] );
	}

	/**
	 * Registered pattern inserted at before_top_level prepends.
	 */
	public function test_registered_pattern_before_top_level(): void {
		$post_id = $this->create_post_with_blocks();

		$result = BlockAbilities::handle_insert_pattern( [
			'post_id' => $post_id,
			'pattern' => self::TEST_PATTERN,
			'anchor'  => 'before_top_level',
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// First named block should be core/quote.
		$post   = get_post( $post_id );
		$blocks = parse_blocks( $post->post_content );
		$named  = array_values( array_filter( $blocks, fn( $b ) => ! empty( $b['blockName'] ) ) );
		$this->assertSame( 'core/quote', $named[0]['blockName'] );
	}

	// ── AC2: Synced pattern → core/block ref ──────────────────────────────

	/**
	 * Synced pattern inserts a single core/block reference, NOT inline.
	 */
	public function test_synced_pattern_inserts_reference(): void {
		$post_id    = $this->create_post_with_blocks();
		$pattern_id = $this->create_synced_pattern();

		$result = BlockAbilities::handle_insert_pattern( [
			'post_id' => $post_id,
			'pattern' => $pattern_id,
			'anchor'  => 'after_top_level',
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'synced', $result['pattern_type'] );
		$this->assertSame( 1, $result['blocks_inserted'] );

		// Verify the inserted block is core/block with correct ref.
		$post   = get_post( $post_id );
		$blocks = parse_blocks( $post->post_content );
		$named  = array_values( array_filter( $blocks, fn( $b ) => ! empty( $b['blockName'] ) ) );
		$last   = end( $named );
		$this->assertSame( 'core/block', $last['blockName'] );
		$this->assertSame( $pattern_id, $last['attrs']['ref'] );
	}

	// ── AC3: Multiple synced pattern ID formats ───────────────────────────

	/**
	 * "wp-block:N" format works for synced patterns.
	 */
	public function test_synced_wp_block_prefix_format(): void {
		$post_id    = $this->create_post_with_blocks();
		$pattern_id = $this->create_synced_pattern();

		$result = BlockAbilities::handle_insert_pattern( [
			'post_id' => $post_id,
			'pattern' => 'wp-block:' . $pattern_id,
			'anchor'  => 'after_top_level',
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'synced', $result['pattern_type'] );
	}

	/**
	 * "synced:N" format works for synced patterns.
	 */
	public function test_synced_prefix_format(): void {
		$post_id    = $this->create_post_with_blocks();
		$pattern_id = $this->create_synced_pattern();

		$result = BlockAbilities::handle_insert_pattern( [
			'post_id' => $post_id,
			'pattern' => 'synced:' . $pattern_id,
			'anchor'  => 'after_top_level',
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'synced', $result['pattern_type'] );
	}

	// ── AC5: first_child_of_ref ───────────────────────────────────────────

	/**
	 * first_child_of_ref inserts inside a container block (core/group).
	 */
	public function test_first_child_of_ref_in_container(): void {
		// Create a post with a core/group containing a paragraph.
		$content = serialize_blocks( [
			[
				'blockName'    => 'core/group',
				'attrs'        => [],
				'innerBlocks'  => [
					[
						'blockName'    => 'core/paragraph',
						'attrs'        => [],
						'innerBlocks'  => [],
						'innerHTML'    => '<p>Inside group</p>',
						'innerContent' => [ '<p>Inside group</p>' ],
					],
				],
				'innerHTML'    => '<div class="wp-block-group"></div>',
				'innerContent' => [ '<div class="wp-block-group">', null, '</div>' ],
			],
		] );

		$post_id = self::factory()->post->create( [
			'post_content' => $content,
			'post_status'  => 'publish',
		] );
		$this->assertIsInt( $post_id );

		// Assign refs.
		$page_result = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => true,
		] );
		$this->assertIsArray( $page_result );

		// Get the group block's ref.
		$group_ref = '';
		foreach ( $page_result['blocks'] as $block ) {
			if ( 'core/group' === $block['name'] ) {
				$group_ref = $block['ref'];
				break;
			}
		}
		$this->assertNotEmpty( $group_ref, 'Group block should have a ref' );

		$result = BlockAbilities::handle_insert_pattern( [
			'post_id' => $post_id,
			'pattern' => self::TEST_PATTERN,
			'anchor'  => 'first_child_of_ref',
			'ref'     => $group_ref,
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}

	// ── AC6: Optimistic concurrency ───────────────────────────────────────

	/**
	 * Stale expected_revision_id returns stale_revision error.
	 */
	public function test_stale_revision_returns_error(): void {
		$post_id = $this->create_post_with_blocks();

		$result = BlockAbilities::handle_insert_pattern( [
			'post_id'              => $post_id,
			'pattern'              => self::TEST_PATTERN,
			'anchor'               => 'after_top_level',
			'expected_revision_id' => 999999,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stale_revision', $result->get_error_code() );
	}

	// ── AC7: Refs assigned to every inserted block ────────────────────────

	/**
	 * All inserted blocks receive sd_ref values.
	 */
	public function test_refs_assigned_to_inserted_blocks(): void {
		$post_id = $this->create_post_with_blocks();

		$result = BlockAbilities::handle_insert_pattern( [
			'post_id' => $post_id,
			'pattern' => self::TEST_PATTERN,
			'anchor'  => 'after_top_level',
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// Read back the blocks and check all have refs.
		$page_result = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => false,
		] );
		$this->assertIsArray( $page_result );

		foreach ( $page_result['blocks'] as $block ) {
			$this->assertArrayHasKey( 'ref', $block, "Block {$block['name']} should have a ref." );
			$this->assertNotEmpty( $block['ref'] );
		}
	}

	// ── Ref-based anchors ─────────────────────────────────────────────────

	/**
	 * after_ref inserts pattern after a specific block.
	 */
	public function test_after_ref_inserts_after_target(): void {
		$setup   = $this->create_post_with_refs();
		$post_id = $setup['post_id'];
		$refs    = $setup['refs'];

		$this->assertGreaterThanOrEqual( 2, count( $refs ), 'Need at least 2 refs' );

		// Insert after the first block (paragraph).
		$result = BlockAbilities::handle_insert_pattern( [
			'post_id' => $post_id,
			'pattern' => self::TEST_PATTERN,
			'anchor'  => 'after_ref',
			'ref'     => $refs[0],
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// Verify order: paragraph → quote → heading.
		$post   = get_post( $post_id );
		$blocks = parse_blocks( $post->post_content );
		$named  = array_values( array_filter( $blocks, fn( $b ) => ! empty( $b['blockName'] ) ) );

		$this->assertCount( 3, $named );
		$this->assertSame( 'core/paragraph', $named[0]['blockName'] );
		$this->assertSame( 'core/quote', $named[1]['blockName'] );
		$this->assertSame( 'core/heading', $named[2]['blockName'] );
	}

	/**
	 * before_ref inserts pattern before a specific block.
	 */
	public function test_before_ref_inserts_before_target(): void {
		$setup   = $this->create_post_with_refs();
		$post_id = $setup['post_id'];
		$refs    = $setup['refs'];

		$this->assertGreaterThanOrEqual( 2, count( $refs ), 'Need at least 2 refs' );

		// Insert before the heading (second block).
		$result = BlockAbilities::handle_insert_pattern( [
			'post_id' => $post_id,
			'pattern' => self::TEST_PATTERN,
			'anchor'  => 'before_ref',
			'ref'     => $refs[1],
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// Verify order: paragraph → quote → heading.
		$post   = get_post( $post_id );
		$blocks = parse_blocks( $post->post_content );
		$named  = array_values( array_filter( $blocks, fn( $b ) => ! empty( $b['blockName'] ) ) );

		$this->assertCount( 3, $named );
		$this->assertSame( 'core/paragraph', $named[0]['blockName'] );
		$this->assertSame( 'core/quote', $named[1]['blockName'] );
		$this->assertSame( 'core/heading', $named[2]['blockName'] );
	}

	// ── Dry run ───────────────────────────────────────────────────────────

	/**
	 * dry_run returns success but does not persist.
	 */
	public function test_dry_run_does_not_persist(): void {
		$post_id      = $this->create_post_with_blocks();
		$original_post = get_post( $post_id );

		$result = BlockAbilities::handle_insert_pattern( [
			'post_id' => $post_id,
			'pattern' => self::TEST_PATTERN,
			'anchor'  => 'after_top_level',
			'dry_run' => true,
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['dry_run'] );

		// Verify content unchanged.
		$post_after = get_post( $post_id );
		$this->assertSame( $original_post->post_content, $post_after->post_content );
	}

	// ── Multi-block pattern expansion ─────────────────────────────────────

	/**
	 * A multi-block pattern inserts all blocks.
	 */
	public function test_multi_block_pattern_inserts_all(): void {
		$pattern_name = 'sd-ai-agent-test/multi-insert';
		register_block_pattern(
			$pattern_name,
			[
				'title'   => 'Multi Block Insert',
				'content' => '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Sub-heading</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Body text</p><!-- /wp:paragraph -->',
			]
		);

		$post_id = $this->create_post_with_blocks();

		$result = BlockAbilities::handle_insert_pattern( [
			'post_id' => $post_id,
			'pattern' => $pattern_name,
			'anchor'  => 'after_top_level',
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['blocks_inserted'] );

		// Should now have 4 named blocks total.
		$post   = get_post( $post_id );
		$blocks = parse_blocks( $post->post_content );
		$named  = array_filter( $blocks, fn( $b ) => ! empty( $b['blockName'] ) );
		$this->assertCount( 4, $named );

		// Cleanup.
		unregister_block_pattern( $pattern_name );
	}

	// ── Nonexistent synced pattern ────────────────────────────────────────

	/**
	 * Nonexistent synced pattern returns pattern_not_found.
	 */
	public function test_nonexistent_synced_pattern_returns_error(): void {
		$post_id = $this->create_post_with_blocks();

		$result = BlockAbilities::handle_insert_pattern( [
			'post_id' => $post_id,
			'pattern' => 999999,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'pattern_not_found', $result->get_error_code() );
	}
}
