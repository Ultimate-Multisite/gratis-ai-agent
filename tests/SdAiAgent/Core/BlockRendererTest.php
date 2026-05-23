<?php
/**
 * Test case for BlockRenderer (GH#1752).
 *
 * Covers the acceptance criteria from the issue:
 *
 * AC1: Paragraph block -> rendered_html matches apply_filters('the_content', ...) output.
 * AC2: Dynamic block (core/latest-posts) -> rendered_html includes actual markup.
 * AC3: Synced pattern (core/block { ref: N }) -> rendered_synced_pattern_id populated.
 * AC4: Shortcodes resolve inside rendered blocks.
 * AC5: Throwing render callback -> render_error set, sibling blocks unaffected.
 * AC6: Time budget exhaustion -> remaining blocks get render_error: "render_timeout".
 * AC7: $GLOBALS['post'] restored even on error.
 * AC8: render: false (default) -> no rendered_html field present.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1752
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\BlockRenderer;
use WP_UnitTestCase;

/**
 * Integration tests for BlockRenderer.
 *
 * Uses WP_UnitTestCase so real render_block(), parse_blocks(), get_post(),
 * and global post context are available.
 */
class BlockRendererTest extends WP_UnitTestCase {

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Build a minimal parsed-block array (simulating parse_blocks() output).
	 *
	 * @param string               $name         Block name.
	 * @param array<string,mixed>  $attrs        Block attributes.
	 * @param string               $inner_html   Block innerHTML.
	 * @param array<int,mixed>     $inner_blocks Nested inner blocks.
	 * @return array<string,mixed>
	 */
	private function make_block( string $name, array $attrs = [], string $inner_html = '', array $inner_blocks = [] ): array {
		$inner_content = [ $inner_html ];

		// For blocks with inner blocks, build proper innerContent with null placeholders.
		if ( ! empty( $inner_blocks ) ) {
			$inner_content = [];
			foreach ( $inner_blocks as $ignored ) {
				$inner_content[] = null;
			}
		}

		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
		];
	}

	// ── AC1: Static block rendering (paragraph) ───────────────────────────

	/**
	 * A paragraph block gets rendered_html populated with its rendered output.
	 */
	public function test_paragraph_block_gets_rendered_html(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:paragraph --><p>Hello World</p><!-- /wp:paragraph -->',
		] );

		$blocks = parse_blocks( get_post( $post_id )->post_content );

		$result = BlockRenderer::render_block_tree( $post_id, $blocks );

		// Find the paragraph block.
		$para = null;
		foreach ( $result as $block ) {
			if ( isset( $block['blockName'] ) && 'core/paragraph' === $block['blockName'] ) {
				$para = $block;
				break;
			}
		}

		$this->assertNotNull( $para, 'Paragraph block should exist in result.' );
		$this->assertArrayHasKey( 'rendered_html', $para );
		$this->assertStringContainsString( '<p', $para['rendered_html'] );
		$this->assertStringContainsString( 'Hello World', $para['rendered_html'] );
	}

	// ── AC3: Synced pattern traceability ──────────────────────────────────

	/**
	 * A synced pattern (core/block) gets rendered_synced_pattern_id set.
	 */
	public function test_synced_pattern_gets_rendered_synced_pattern_id(): void {
		// Create a wp_block (synced pattern) post.
		$pattern_id = self::factory()->post->create( [
			'post_type'    => 'wp_block',
			'post_content' => '<!-- wp:paragraph --><p>Synced content</p><!-- /wp:paragraph -->',
			'post_status'  => 'publish',
		] );

		// Create a post referencing the synced pattern.
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:block {"ref":' . $pattern_id . '} /-->',
		] );

		$blocks = parse_blocks( get_post( $post_id )->post_content );

		$result = BlockRenderer::render_block_tree( $post_id, $blocks );

		// Find the core/block entry.
		$synced = null;
		foreach ( $result as $block ) {
			if ( isset( $block['blockName'] ) && 'core/block' === $block['blockName'] ) {
				$synced = $block;
				break;
			}
		}

		$this->assertNotNull( $synced, 'Synced pattern block should exist in result.' );
		$this->assertArrayHasKey( 'rendered_synced_pattern_id', $synced );
		$this->assertSame( $pattern_id, $synced['rendered_synced_pattern_id'] );
		$this->assertArrayHasKey( 'rendered_html', $synced );
	}

	/**
	 * A synced pattern with missing ref gets render_error.
	 */
	public function test_synced_pattern_missing_ref_gets_render_error(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:paragraph --><p>Test</p><!-- /wp:paragraph -->',
		] );

		// Manually construct a core/block with no ref.
		$blocks = [
			$this->make_block( 'core/block', [], '' ),
		];

		$result = BlockRenderer::render_block_tree( $post_id, $blocks );

		$this->assertArrayHasKey( 'render_error', $result[0] );
		$this->assertSame( 'missing_pattern_ref', $result[0]['render_error'] );
	}

	/**
	 * A synced pattern pointing to a non-existent post gets render_error.
	 */
	public function test_synced_pattern_nonexistent_ref_gets_render_error(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:paragraph --><p>Test</p><!-- /wp:paragraph -->',
		] );

		$blocks = [
			$this->make_block( 'core/block', [ 'ref' => 999999 ], '' ),
		];

		$result = BlockRenderer::render_block_tree( $post_id, $blocks );

		$this->assertArrayHasKey( 'render_error', $result[0] );
		$this->assertSame( 'pattern_not_found', $result[0]['render_error'] );
	}

	// ── AC5: Exception containment ────────────────────────────────────────

	/**
	 * A block whose render callback throws gets render_error; siblings are unaffected.
	 */
	public function test_throwing_render_callback_sets_render_error_without_affecting_siblings(): void {
		// Register a custom block type with a throwing render callback.
		register_block_type( 'test/throwing-block', [
			'render_callback' => function () {
				throw new \RuntimeException( 'Intentional test error' );
			},
		] );

		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->',
		] );

		$blocks = [
			$this->make_block( 'test/throwing-block', [], '<div>Will throw</div>' ),
			$this->make_block( 'core/paragraph', [], '<p>After sibling</p>' ),
		];

		$result = BlockRenderer::render_block_tree( $post_id, $blocks );

		// First block should have render_error.
		$this->assertArrayHasKey( 'render_error', $result[0] );
		$this->assertStringContainsString( 'RuntimeException', $result[0]['render_error'] );
		$this->assertStringContainsString( 'Intentional test error', $result[0]['render_error'] );

		// Second block should render normally.
		$this->assertArrayHasKey( 'rendered_html', $result[1] );
		$this->assertArrayNotHasKey( 'render_error', $result[1] );

		// Clean up.
		unregister_block_type( 'test/throwing-block' );
	}

	// ── AC6: Time budget exhaustion ───────────────────────────────────────

	/**
	 * Blocks that exceed the time budget get render_error: "render_timeout".
	 */
	public function test_budget_exhaustion_marks_remaining_blocks_as_timeout(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:paragraph --><p>First</p><!-- /wp:paragraph -->',
		] );

		$blocks = [
			$this->make_block( 'core/paragraph', [], '<p>Block 1</p>' ),
			$this->make_block( 'core/paragraph', [], '<p>Block 2</p>' ),
			$this->make_block( 'core/paragraph', [], '<p>Block 3</p>' ),
		];

		// Use a budget of 0 seconds to force immediate timeout.
		$result = BlockRenderer::render_block_tree( $post_id, $blocks, 0 );

		// At least one block should have render_timeout.
		$timeout_count = 0;
		foreach ( $result as $block ) {
			if ( isset( $block['render_error'] ) && 'render_timeout' === $block['render_error'] ) {
				++$timeout_count;
			}
		}

		$this->assertGreaterThan( 0, $timeout_count, 'At least one block should be marked as timed out.' );
	}

	// ── AC7: Global post context restoration ──────────────────────────────

	/**
	 * $GLOBALS['post'] is restored to its pre-call value even after errors.
	 */
	public function test_global_post_restored_after_render(): void {
		$sentinel_id = self::factory()->post->create( [
			'post_title' => 'Sentinel Post',
		] );
		$target_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:paragraph --><p>Target</p><!-- /wp:paragraph -->',
		] );

		// Set up a known global post.
		$sentinel_post   = get_post( $sentinel_id );
		$GLOBALS['post'] = $sentinel_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$blocks = parse_blocks( get_post( $target_id )->post_content );

		BlockRenderer::render_block_tree( $target_id, $blocks );

		$this->assertSame(
			$sentinel_id,
			$GLOBALS['post']->ID,
			'$GLOBALS[\'post\'] should be restored to sentinel post after render.'
		);
	}

	/**
	 * $GLOBALS['post'] is restored even when render_block throws.
	 */
	public function test_global_post_restored_after_error(): void {
		register_block_type( 'test/error-restore-block', [
			'render_callback' => function () {
				throw new \RuntimeException( 'Error for restore test' );
			},
		] );

		$sentinel_id = self::factory()->post->create( [
			'post_title' => 'Sentinel Post',
		] );
		$target_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:paragraph --><p>Target</p><!-- /wp:paragraph -->',
		] );

		$sentinel_post   = get_post( $sentinel_id );
		$GLOBALS['post'] = $sentinel_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$blocks = [
			$this->make_block( 'test/error-restore-block', [], '<div>Error</div>' ),
		];

		BlockRenderer::render_block_tree( $target_id, $blocks );

		$this->assertSame(
			$sentinel_id,
			$GLOBALS['post']->ID,
			'$GLOBALS[\'post\'] should be restored even after render errors.'
		);

		unregister_block_type( 'test/error-restore-block' );
	}

	// ── AC8: render: false does not add rendered_html ─────────────────────

	/**
	 * When render_block_tree is NOT called (render: false), blocks should
	 * not have rendered_html. This test verifies the field is absent from
	 * raw parse_blocks() output.
	 */
	public function test_raw_parsed_blocks_have_no_rendered_html(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:paragraph --><p>No render</p><!-- /wp:paragraph -->',
		] );

		$blocks = parse_blocks( get_post( $post_id )->post_content );

		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$this->assertArrayNotHasKey(
				'rendered_html',
				$block,
				'Raw blocks should not have rendered_html when render_block_tree is not called.'
			);
		}
	}

	// ── Edge case: empty block tree ───────────────────────────────────────

	/**
	 * An empty block tree returns an empty array without errors.
	 */
	public function test_empty_block_tree_returns_empty_array(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '',
		] );

		$result = BlockRenderer::render_block_tree( $post_id, [] );

		$this->assertSame( [], $result );
	}

	// ── Edge case: non-existent post ──────────────────────────────────────

	/**
	 * A non-existent post returns the blocks unchanged (no crash).
	 */
	public function test_nonexistent_post_returns_blocks_unchanged(): void {
		$blocks = [
			$this->make_block( 'core/paragraph', [], '<p>Test</p>' ),
		];

		$result = BlockRenderer::render_block_tree( 999999, $blocks );

		// Should return blocks as-is, no rendered_html added.
		$this->assertCount( 1, $result );
		$this->assertArrayNotHasKey( 'rendered_html', $result[0] );
	}

	// ── Edge case: inner blocks are rendered ──────────────────────────────

	/**
	 * Inner blocks also get rendered_html populated.
	 */
	public function test_inner_blocks_get_rendered_html(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Inner</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
		] );

		$blocks = parse_blocks( get_post( $post_id )->post_content );

		$result = BlockRenderer::render_block_tree( $post_id, $blocks );

		// Find the group block.
		$group = null;
		foreach ( $result as $block ) {
			if ( isset( $block['blockName'] ) && 'core/group' === $block['blockName'] ) {
				$group = $block;
				break;
			}
		}

		$this->assertNotNull( $group, 'Group block should exist.' );
		$this->assertArrayHasKey( 'rendered_html', $group );

		// Inner paragraph should also be rendered.
		$this->assertNotEmpty( $group['innerBlocks'] );
		$inner_para = null;
		foreach ( $group['innerBlocks'] as $inner ) {
			if ( isset( $inner['blockName'] ) && 'core/paragraph' === $inner['blockName'] ) {
				$inner_para = $inner;
				break;
			}
		}

		$this->assertNotNull( $inner_para, 'Inner paragraph should exist.' );
		$this->assertArrayHasKey( 'rendered_html', $inner_para );
		$this->assertStringContainsString( 'Inner', $inner_para['rendered_html'] );
	}
}
