<?php
/**
 * Test case for the sd-ai-agent/edit-block-tree ability handler (GH#1708).
 *
 * Covers the REST/ability surface:
 *   - handle_edit_block_tree: post loading, mutation dispatch, dry_run, DB write.
 *   - Error paths: missing post_id, missing op, post not found, mutator error.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1708
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\BlockAbilities;
use WP_UnitTestCase;

/**
 * Integration tests for BlockAbilities::handle_edit_block_tree.
 *
 * Uses WP_UnitTestCase so real posts and parse_blocks()/serialize_blocks() are available.
 */
class EditBlockTreeAbilityTest extends WP_UnitTestCase {

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Create a post with simple block content (two named blocks, no whitespace fillers).
	 *
	 * Uses serialize_blocks() so the resulting content has no freeform whitespace
	 * nodes between named blocks, keeping path indices predictable for tests.
	 *
	 * @param string $content Serialized block content (leave empty for default).
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
					'innerHTML'    => '<h2>Section</h2>',
					'innerContent' => [ '<h2>Section</h2>' ],
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

	// ── Validation errors ─────────────────────────────────────────────────

	/**
	 * Missing post_id returns WP_Error missing_post_id.
	 */
	public function test_missing_post_id_returns_error(): void {
		$result = BlockAbilities::handle_edit_block_tree( [
			'op'   => 'update-attrs',
			'path' => [ 0 ],
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_post_id', $result->get_error_code() );
	}

	/**
	 * Zero post_id returns WP_Error missing_post_id.
	 */
	public function test_zero_post_id_returns_error(): void {
		$result = BlockAbilities::handle_edit_block_tree( [
			'post_id' => 0,
			'op'      => 'update-attrs',
			'path'    => [ 0 ],
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_post_id', $result->get_error_code() );
	}

	/**
	 * Missing op returns WP_Error missing_op.
	 */
	public function test_missing_op_returns_error(): void {
		$post_id = $this->create_post_with_blocks();
		$result  = BlockAbilities::handle_edit_block_tree( [
			'post_id' => $post_id,
			'path'    => [ 0 ],
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_op', $result->get_error_code() );
	}

	/**
	 * Non-existent post_id returns WP_Error post_not_found.
	 */
	public function test_post_not_found_returns_error(): void {
		$result = BlockAbilities::handle_edit_block_tree( [
			'post_id' => 999999,
			'op'      => 'update-attrs',
			'path'    => [ 0 ],
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	// ── dry_run ───────────────────────────────────────────────────────────

	/**
	 * dry_run: true returns the mutated tree but does not persist (AC7).
	 */
	public function test_dry_run_does_not_persist(): void {
		$post_id = $this->create_post_with_blocks();
		$original_content = get_post( $post_id )->post_content;

		$result = BlockAbilities::handle_edit_block_tree( [
			'post_id'    => $post_id,
			'op'         => 'update-attrs',
			'path'       => [ 0 ],
			'attributes' => [ 'dropCap' => true ],
			'dry_run'    => true,
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['dry_run'] );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'block_tree', $result );

		// Post content must not have changed.
		$after_content = get_post( $post_id )->post_content;
		$this->assertSame( $original_content, $after_content );
	}

	/**
	 * dry_run: false persists changes to the post.
	 */
	public function test_live_run_persists(): void {
		$post_id = $this->create_post_with_blocks();

		$result = BlockAbilities::handle_edit_block_tree( [
			'post_id'    => $post_id,
			'op'         => 'remove-block',
			'path'       => [ 1 ],
			'dry_run'    => false,
		] );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['dry_run'] );
		$this->assertTrue( $result['success'] );

		// Reload from DB — should have one fewer block.
		$updated = get_post( $post_id );
		$blocks  = parse_blocks( $updated->post_content );
		// Filter to named blocks only.
		$named = array_values( array_filter( $blocks, static fn( $b ) => ! empty( $b['blockName'] ) ) );
		$this->assertCount( 1, $named );
	}

	// ── update-attrs integration ───────────────────────────────────────────

	/**
	 * update-attrs on a real post updates the heading level.
	 */
	public function test_update_attrs_integration(): void {
		$post_id = $this->create_post_with_blocks();

		$result = BlockAbilities::handle_edit_block_tree( [
			'post_id'    => $post_id,
			'op'         => 'update-attrs',
			'path'       => [ 1 ],  // heading block (index 1)
			'attributes' => [ 'level' => 3 ],
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// Reload and check attrs.
		$updated = get_post( $post_id );
		$blocks  = parse_blocks( $updated->post_content );
		$named   = array_values( array_filter( $blocks, static fn( $b ) => ! empty( $b['blockName'] ) ) );
		$this->assertSame( 3, $named[1]['attrs']['level'] ?? null );
	}

	// ── duplicate integration ──────────────────────────────────────────────

	/**
	 * duplicate on a real post creates a sibling clone.
	 */
	public function test_duplicate_integration(): void {
		$post_id = $this->create_post_with_blocks();

		$result = BlockAbilities::handle_edit_block_tree( [
			'post_id' => $post_id,
			'op'      => 'duplicate',
			'path'    => [ 0 ],
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// Reload and verify count increased.
		$updated = get_post( $post_id );
		$blocks  = parse_blocks( $updated->post_content );
		$named   = array_values( array_filter( $blocks, static fn( $b ) => ! empty( $b['blockName'] ) ) );
		$this->assertCount( 3, $named );
	}

	// ── invalid op integration ────────────────────────────────────────────

	/**
	 * An invalid op propagates the mutator WP_Error through the handler.
	 */
	public function test_invalid_op_propagates_error(): void {
		$post_id = $this->create_post_with_blocks();

		$result = BlockAbilities::handle_edit_block_tree( [
			'post_id' => $post_id,
			'op'      => 'destroy-everything',
			'path'    => [ 0 ],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_op', $result->get_error_code() );
	}

	// ── response structure ─────────────────────────────────────────────────

	/**
	 * Successful response contains all expected keys.
	 */
	public function test_response_structure(): void {
		$post_id = $this->create_post_with_blocks();

		$result = BlockAbilities::handle_edit_block_tree( [
			'post_id'    => $post_id,
			'op'         => 'update-attrs',
			'path'       => [ 0 ],
			'attributes' => [ 'dropCap' => true ],
			'dry_run'    => true,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'dry_run', $result );
		$this->assertArrayHasKey( 'op', $result );
		$this->assertArrayHasKey( 'post_id', $result );
		$this->assertArrayHasKey( 'block_tree', $result );
		$this->assertSame( 'update-attrs', $result['op'] );
		$this->assertSame( $post_id, $result['post_id'] );
	}

	/**
	 * block_tree key contains the raw parsed block array.
	 */
	public function test_block_tree_key_is_array(): void {
		$post_id = $this->create_post_with_blocks();

		$result = BlockAbilities::handle_edit_block_tree( [
			'post_id'    => $post_id,
			'op'         => 'update-attrs',
			'path'       => [ 0 ],
			'attributes' => [],
			'dry_run'    => true,
		] );

		$this->assertIsArray( $result );
		$this->assertIsArray( $result['block_tree'] );
	}
}
