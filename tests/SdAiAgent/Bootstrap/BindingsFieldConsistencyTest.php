<?php
/**
 * Regression guard for Block Bindings field shape consistency (t275).
 *
 * Ensures every read path that emits a block dict always includes both:
 * - `bindings`         (object|null)  — null when the block has no bindings.
 * - `bound_attributes` (string[])     — empty array when the block has no bindings.
 *
 * Covers at least 4 read paths per AC-5:
 *   1. get-page-blocks (nested mode)
 *   2. get-page-blocks (flat DFS mode via include_inner_blocks)
 *   3. parse-block-content
 *   4. edit-block-tree dry-run
 *   5. update-blocks dry-run
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1791
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Bootstrap;

use SdAiAgent\Abilities\BlockAbilities;
use SdAiAgent\Core\BlockReferences;
use WP_UnitTestCase;

/**
 * Regression guard: bindings + bound_attributes always present on every block dict.
 */
class BindingsFieldConsistencyTest extends WP_UnitTestCase {

	/**
	 * Post ID for tests that need a post with mixed bound/unbound blocks.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Block content with one bound and one unbound paragraph.
	 *
	 * @var string
	 */
	private string $mixed_content;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		// Grant current user edit_posts capability.
		$user_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		// Block content: one bound paragraph, one unbound paragraph.
		$this->mixed_content = '<!-- wp:paragraph {"metadata":{"'
			. BlockReferences::REF_KEY . '":"blk_bndtest1","bindings":{"content":{"source":"core/post-meta","args":{"key":"subtitle"}}}}} -->'
			. "\n<p>Bound paragraph</p>\n"
			. '<!-- /wp:paragraph -->'
			. "\n\n"
			. '<!-- wp:paragraph {"metadata":{"'
			. BlockReferences::REF_KEY . '":"blk_unbtest1"}} -->'
			. "\n<p>Unbound paragraph</p>\n"
			. '<!-- /wp:paragraph -->';

		$this->post_id = self::factory()->post->create( [
			'post_content' => $this->mixed_content,
			'post_status'  => 'publish',
		] );
	}

	// ── Helper ────────────────────────────────────────────────────

	/**
	 * Assert that a block dict contains both bindings fields with correct types.
	 *
	 * @param array<string,mixed> $block      Block dict to check.
	 * @param bool                $expect_bound Whether the block should have bindings.
	 * @param string              $context    Human-readable context for assertion messages.
	 */
	private function assert_bindings_shape( array $block, bool $expect_bound, string $context ): void {
		$this->assertArrayHasKey( 'bindings', $block, "{$context}: 'bindings' key must always be present." );
		$this->assertArrayHasKey( 'bound_attributes', $block, "{$context}: 'bound_attributes' key must always be present." );

		if ( $expect_bound ) {
			$this->assertIsArray( $block['bindings'], "{$context}: bound block's 'bindings' must be an array (object)." );
			$this->assertNotEmpty( $block['bindings'], "{$context}: bound block's 'bindings' must not be empty." );
			$this->assertIsArray( $block['bound_attributes'], "{$context}: 'bound_attributes' must be an array." );
			$this->assertNotEmpty( $block['bound_attributes'], "{$context}: bound block's 'bound_attributes' must not be empty." );

			// Verify bound_attributes matches bindings keys.
			$expected_keys = array_keys( $block['bindings'] );
			sort( $expected_keys );
			$actual_keys = $block['bound_attributes'];
			sort( $actual_keys );
			$this->assertSame( $expected_keys, $actual_keys, "{$context}: bound_attributes must match bindings keys." );
		} else {
			$this->assertNull( $block['bindings'], "{$context}: unbound block's 'bindings' must be null." );
			$this->assertSame( [], $block['bound_attributes'], "{$context}: unbound block's 'bound_attributes' must be []." );
		}
	}

	// ── Read path 1: get-page-blocks (nested) ────────────────────

	/**
	 * @testdox get-page-blocks (nested mode) emits canonical bindings shape on both bound and unbound blocks.
	 */
	public function test_get_page_blocks_nested_bindings_shape(): void {
		$result = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $this->post_id,
			'persist_refs' => false,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'blocks', $result );
		$this->assertCount( 2, $result['blocks'], 'Expected 2 blocks (1 bound + 1 unbound).' );

		$this->assert_bindings_shape( $result['blocks'][0], true, 'get-page-blocks nested: bound block' );
		$this->assert_bindings_shape( $result['blocks'][1], false, 'get-page-blocks nested: unbound block' );
	}

	// ── Read path 2: get-page-blocks (flat DFS) ──────────────────

	/**
	 * @testdox get-page-blocks (flat DFS mode via include_inner_blocks) emits canonical bindings shape.
	 */
	public function test_get_page_blocks_flat_dfs_bindings_shape(): void {
		$result = BlockAbilities::handle_get_page_blocks( [
			'post_id'              => $this->post_id,
			'persist_refs'         => false,
			'include_inner_blocks' => true,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'blocks', $result );
		$this->assertCount( 2, $result['blocks'], 'Expected 2 blocks in flat DFS output.' );

		$this->assert_bindings_shape( $result['blocks'][0], true, 'get-page-blocks flat DFS: bound block' );
		$this->assert_bindings_shape( $result['blocks'][1], false, 'get-page-blocks flat DFS: unbound block' );
	}

	// ── Read path 3: parse-block-content ─────────────────────────

	/**
	 * @testdox parse-block-content emits canonical bindings shape on all blocks.
	 */
	public function test_parse_block_content_bindings_shape(): void {
		$result = BlockAbilities::handle_parse_block_content( [
			'post_id' => $this->post_id,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'blocks', $result );
		$this->assertCount( 2, $result['blocks'], 'Expected 2 blocks from parse-block-content.' );

		$this->assert_bindings_shape( $result['blocks'][0], true, 'parse-block-content: bound block' );
		$this->assert_bindings_shape( $result['blocks'][1], false, 'parse-block-content: unbound block' );
	}

	// ── Read path 4: edit-block-tree dry-run ─────────────────────

	/**
	 * @testdox edit-block-tree dry-run block_tree includes canonical bindings shape.
	 */
	public function test_edit_block_tree_dry_run_bindings_shape(): void {
		$result = BlockAbilities::handle_edit_block_tree( [
			'post_id' => $this->post_id,
			'op'      => 'update-attrs',
			'ref'     => 'blk_unbtest1',
			'attributes' => [ 'className' => 'test-class' ],
			'dry_run' => true,
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'block_tree', $result );

		// Walk the block_tree and check both named blocks.
		$named_blocks = $this->extract_named_blocks( $result['block_tree'] );
		$this->assertGreaterThanOrEqual( 2, count( $named_blocks ), 'Expected at least 2 named blocks in block_tree.' );

		// Find bound and unbound blocks by ref.
		$bound_block   = $this->find_block_by_ref( $named_blocks, 'blk_bndtest1' );
		$unbound_block = $this->find_block_by_ref( $named_blocks, 'blk_unbtest1' );

		$this->assertNotNull( $bound_block, 'Bound block must exist in dry-run result.' );
		$this->assertNotNull( $unbound_block, 'Unbound block must exist in dry-run result.' );

		$this->assert_bindings_shape( $bound_block, true, 'edit-block-tree dry-run: bound block' );
		$this->assert_bindings_shape( $unbound_block, false, 'edit-block-tree dry-run: unbound block' );
	}

	// ── Read path 5: update-blocks dry-run ───────────────────────

	/**
	 * @testdox update-blocks dry-run block_tree includes canonical bindings shape.
	 */
	public function test_update_blocks_dry_run_bindings_shape(): void {
		$result = BlockAbilities::handle_update_blocks( [
			'post_id' => $this->post_id,
			'updates' => [
				[
					'op'         => 'update-attrs',
					'ref'        => 'blk_unbtest1',
					'attributes' => [ 'className' => 'test-class' ],
				],
			],
			'dry_run' => true,
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'block_tree', $result );

		$named_blocks = $this->extract_named_blocks( $result['block_tree'] );
		$this->assertGreaterThanOrEqual( 2, count( $named_blocks ), 'Expected at least 2 named blocks in block_tree.' );

		$bound_block   = $this->find_block_by_ref( $named_blocks, 'blk_bndtest1' );
		$unbound_block = $this->find_block_by_ref( $named_blocks, 'blk_unbtest1' );

		$this->assertNotNull( $bound_block, 'Bound block must exist in update-blocks dry-run.' );
		$this->assertNotNull( $unbound_block, 'Unbound block must exist in update-blocks dry-run.' );

		$this->assert_bindings_shape( $bound_block, true, 'update-blocks dry-run: bound block' );
		$this->assert_bindings_shape( $unbound_block, false, 'update-blocks dry-run: unbound block' );
	}

	// ── annotate_bindings_tree unit test ─────────────────────────

	/**
	 * @testdox annotate_bindings_tree adds canonical shape to raw parse_blocks output.
	 */
	public function test_annotate_bindings_tree_adds_shape(): void {
		$blocks = parse_blocks( $this->mixed_content );

		$annotated = BlockAbilities::annotate_bindings_tree( $blocks );

		// Filter to named blocks only.
		$named = array_filter( $annotated, fn( $b ) => ! empty( $b['blockName'] ) );
		$named = array_values( $named );
		$this->assertCount( 2, $named );

		$this->assert_bindings_shape( $named[0], true, 'annotate_bindings_tree: bound block' );
		$this->assert_bindings_shape( $named[1], false, 'annotate_bindings_tree: unbound block' );
	}

	// ── Helpers ──────────────────────────────────────────────────

	/**
	 * Extract all named blocks (non-null blockName) from a raw block tree.
	 *
	 * @param array<int,mixed> $blocks Raw block tree.
	 * @return array<int,array<string,mixed>> Named blocks (flat list).
	 */
	private function extract_named_blocks( array $blocks ): array {
		$result = [];

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			$result[] = $block;

			$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
			if ( ! empty( $inner ) ) {
				$result = array_merge( $result, $this->extract_named_blocks( $inner ) );
			}
		}

		return $result;
	}

	/**
	 * Find a block by its sd_ref in a flat list of block dicts.
	 *
	 * @param array<int,array<string,mixed>> $blocks Flat list of block dicts.
	 * @param string                          $ref    Target ref.
	 * @return array<string,mixed>|null
	 */
	private function find_block_by_ref( array $blocks, string $ref ): ?array {
		foreach ( $blocks as $block ) {
			$block_ref = $block['attrs']['metadata'][ BlockReferences::REF_KEY ] ?? null;

			if ( $block_ref === $ref ) {
				return $block;
			}
		}

		return null;
	}
}
