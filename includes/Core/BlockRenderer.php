<?php

declare(strict_types=1);
/**
 * Block renderer for the get-page-blocks ability.
 *
 * When the `render: true` parameter is set on `sd-ai-agent/get-page-blocks`,
 * this class walks the parsed block tree and populates each block entry with:
 *
 * - `rendered_html`              — the server-rendered HTML output.
 * - `render_error`               — error message when rendering fails for a block.
 * - `rendered_synced_pattern_id` — the wp_block post ID for synced patterns.
 *
 * Rendering runs under the post's global context (`setup_postdata`) so
 * shortcodes, dynamic blocks, and template tags resolve correctly. Each
 * block is rendered inside `ob_start` / `ob_get_clean` to capture any
 * leaked output. Exceptions are caught per-block — they set `render_error`
 * but do not propagate or affect sibling blocks.
 *
 * A configurable time budget (default 5 seconds) prevents runaway renders.
 * Blocks that exceed the budget receive `render_error: "render_timeout"`.
 *
 * Adapted from ~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-reader.php:285-510
 * (GPL-2.0-or-later — compatible).
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1752
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side block renderer for the get-page-blocks ability.
 *
 * All public entry points are static. The class carries no per-request
 * instance state — it is a pure namespace for the rendering logic.
 */
class BlockRenderer {

	/**
	 * Default render budget in seconds.
	 *
	 * @var int
	 */
	public const DEFAULT_BUDGET_SECONDS = 5;

	/**
	 * Render every block in the tree and attach rendered_html (+ metadata).
	 *
	 * Must be called after refs are assigned and the block tree is parsed.
	 * The caller is responsible for passing the post ID so that global post
	 * context can be set up.
	 *
	 * @param int                     $post_id        Post ID for global context setup.
	 * @param array<int|string,mixed> $blocks         Parsed block tree (from parse_blocks()).
	 * @param int                     $budget_seconds Total render budget in seconds.
	 * @return array<int|string,mixed> The same block tree with render fields attached.
	 */
	public static function render_block_tree( int $post_id, array $blocks, int $budget_seconds = self::DEFAULT_BUDGET_SECONDS ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $blocks;
		}

		// Save and set up post context.
		$original_post   = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional: sets post context for render_block() and do_shortcode(); restored in the finally block below.
		setup_postdata( $post );

		$deadline = microtime( true ) + (float) $budget_seconds;

		try {
			self::render_blocks_recursive( $blocks, $deadline );
		} finally {
			// Restore original post context unconditionally.
			$GLOBALS['post'] = $original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring original post context after render pass.
			if ( $original_post instanceof \WP_Post ) {
				setup_postdata( $original_post );
			} else {
				wp_reset_postdata();
			}
		}

		return $blocks;
	}

	/**
	 * Recursively walk the block tree and render each block.
	 *
	 * @param array<int|string,mixed> $blocks   Block tree (modified in place by reference).
	 * @param float                   $deadline Unix microtime deadline.
	 */
	private static function render_blocks_recursive( array &$blocks, float $deadline ): void {
		foreach ( $blocks as &$block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			// Check time budget before rendering.
			if ( microtime( true ) >= $deadline ) {
				$block['render_error'] = 'render_timeout';
				// Mark all remaining blocks as timed out too.
				self::mark_remaining_timeout( $blocks, $block );
				return;
			}

			$block_name = (string) $block['blockName'];

			// Handle synced patterns (core/block).
			if ( 'core/block' === $block_name ) {
				self::render_synced_pattern( $block, $deadline );
				continue;
			}

			// Render the block.
			self::render_single_block( $block );

			// Recurse into inner blocks.
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				if ( microtime( true ) >= $deadline ) {
					// @phpstan-ignore-next-line
					self::mark_all_timeout( $block['innerBlocks'] );
					return;
				}
				// @phpstan-ignore-next-line
				self::render_blocks_recursive( $block['innerBlocks'], $deadline );
			}
		}
		unset( $block );
	}

	/**
	 * Render a single block, capturing output and catching exceptions.
	 *
	 * @param array<string,mixed> $block Block array (modified in place by reference).
	 */
	private static function render_single_block( array &$block ): void {
		try {
			ob_start();
			// @phpstan-ignore-next-line
			$rendered = render_block( $block );
			$leaked   = ob_get_clean();

			// If render_block returned empty but there was leaked output, use that.
			if ( '' === $rendered && is_string( $leaked ) && '' !== $leaked ) {
				$rendered = $leaked;
			}

			$block['rendered_html'] = $rendered;
		} catch ( \Throwable $e ) {
			// Clean up output buffer if still open.
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}

			$block['rendered_html'] = '';
			$block['render_error']  = get_class( $e ) . ': ' . $e->getMessage();
		}
	}

	/**
	 * Render a synced pattern (core/block) by resolving its referenced wp_block post.
	 *
	 * Sets `rendered_synced_pattern_id` on the block and recursively renders
	 * the referenced pattern's block tree.
	 *
	 * @param array<string,mixed> $block    Block array (modified in place by reference).
	 * @param float               $deadline Unix microtime deadline.
	 */
	private static function render_synced_pattern( array &$block, float $deadline ): void {
		$ref_id = isset( $block['attrs']['ref'] ) ? (int) $block['attrs']['ref'] : 0;

		if ( $ref_id <= 0 ) {
			$block['rendered_html'] = '';
			$block['render_error']  = 'missing_pattern_ref';
			return;
		}

		$block['rendered_synced_pattern_id'] = $ref_id;

		// Look up the referenced wp_block post.
		$pattern_post = get_post( $ref_id );

		if ( ! $pattern_post || 'wp_block' !== $pattern_post->post_type ) {
			$block['rendered_html'] = '';
			$block['render_error']  = 'pattern_not_found';
			return;
		}

		$pattern_content = $pattern_post->post_content;
		if ( ! is_string( $pattern_content ) || '' === trim( $pattern_content ) ) {
			$block['rendered_html'] = '';
			return;
		}

		// Parse and render the pattern's blocks.
		$pattern_blocks = parse_blocks( $pattern_content );
		if ( ! is_array( $pattern_blocks ) ) {
			$pattern_blocks = [];
		}

		// Check deadline before recursive render.
		if ( microtime( true ) >= $deadline ) {
			$block['render_error'] = 'render_timeout';
			return;
		}

		// Render the entire pattern using render_block on the core/block itself,
		// which WordPress handles natively (it resolves the pattern and renders
		// the inner blocks). This ensures shortcodes, dynamic blocks, and
		// nested patterns all resolve correctly.
		try {
			ob_start();
			// @phpstan-ignore-next-line
			$rendered = render_block( $block );
			$leaked   = ob_get_clean();

			if ( '' === $rendered && is_string( $leaked ) && '' !== $leaked ) {
				$rendered = $leaked;
			}

			$block['rendered_html'] = $rendered;
		} catch ( \Throwable $e ) {
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}

			$block['rendered_html'] = '';
			$block['render_error']  = get_class( $e ) . ': ' . $e->getMessage();
		}
	}

	/**
	 * Mark all remaining siblings after the current block as timed out.
	 *
	 * Called when the deadline is reached mid-iteration. Marks all
	 * subsequent siblings after the current block.
	 *
	 * @param array<int|string,mixed> $blocks        Block array at current level.
	 * @param array<string,mixed>     $current_block The block that triggered timeout.
	 */
	private static function mark_remaining_timeout( array &$blocks, array &$current_block ): void {
		$found_current = false;

		foreach ( $blocks as &$sibling ) {
			if ( ! is_array( $sibling ) || empty( $sibling['blockName'] ) ) {
				continue;
			}

			// Skip until we find the block after the current one.
			if ( ! $found_current ) {
				// Check if this is the current block by reference identity.
				if ( $sibling === $current_block ) {
					$found_current = true;
					continue;
				}
				continue;
			}

			$sibling['render_error'] = 'render_timeout';

			// Also mark inner blocks.
			if ( ! empty( $sibling['innerBlocks'] ) && is_array( $sibling['innerBlocks'] ) ) {
				// @phpstan-ignore-next-line
				self::mark_all_timeout( $sibling['innerBlocks'] );
			}
		}
		unset( $sibling );
	}

	/**
	 * Mark all blocks in a tree as timed out.
	 *
	 * @param array<int|string,mixed> $blocks Block tree to mark.
	 */
	private static function mark_all_timeout( array &$blocks ): void {
		foreach ( $blocks as &$block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			$block['render_error'] = 'render_timeout';

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				// @phpstan-ignore-next-line
				self::mark_all_timeout( $block['innerBlocks'] );
			}
		}
		unset( $block );
	}
}
