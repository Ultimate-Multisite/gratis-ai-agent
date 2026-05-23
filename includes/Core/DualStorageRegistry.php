<?php

declare(strict_types=1);
/**
 * Dual-storage block registry.
 *
 * Maintains a list of block names that store the same data in **both**
 * `attributes` and `innerHTML`. Updating only one side leaves the block in a
 * permanently inconsistent state, so any `update-attrs` or `update-html`
 * operation on a dual-storage block must supply both sides.
 *
 * The list is hard-coded with known offenders and is extensible via the
 * `sd_ai_agent_block_dual_storage_blocks` filter. A stretch scan helper can
 * walk published posts and auto-detect additional candidates.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1713
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dual-storage block registry.
 *
 * All public methods are static. The class holds no per-instance state.
 */
class DualStorageRegistry {

	/**
	 * WordPress option key for the cached scan result.
	 *
	 * @var string
	 */
	const SCAN_OPTION = 'sd_ai_agent_dual_storage_detected';

	/**
	 * Hard-coded list of known dual-storage block names.
	 *
	 * These blocks duplicate their data across both `attributes` and `innerHTML`.
	 * Updating only one side silently corrupts the block until the user
	 * manually fixes it in the editor.
	 *
	 * @var string[]
	 */
	const KNOWN_BLOCKS = [
		'yoast/faq-block',
		'yoast/how-to-block',
	];

	// ── Public API ────────────────────────────────────────────────────────

	/**
	 * Return the full list of dual-storage block names.
	 *
	 * Applies the `sd_ai_agent_block_dual_storage_blocks` filter so third-party
	 * plugins can register their own dual-storage blocks.
	 *
	 * @return string[] Unique list of block names.
	 */
	public static function get_blocks(): array {
		/**
		 * Filter the list of dual-storage block names.
		 *
		 * A dual-storage block stores the same data in both `attributes` and
		 * `innerHTML`. Blocks added here will be subject to the same enforcement
		 * as the hard-coded list: any `update-attrs` or `update-html` operation
		 * must supply both sides or it will be rejected with `dual_storage_requires_both`.
		 *
		 * @param string[] $blocks Array of block names (e.g. 'yoast/faq-block').
		 */
		$blocks = (array) apply_filters( 'sd_ai_agent_block_dual_storage_blocks', self::KNOWN_BLOCKS );

		// Ensure all entries are non-empty strings and deduplicate.
		$blocks = array_values(
			array_unique(
				array_filter( $blocks, static fn( $v ) => is_string( $v ) && '' !== $v )
			)
		);

		return $blocks;
	}

	/**
	 * Return true when $block_name is a dual-storage block.
	 *
	 * @param string $block_name Block name (e.g. 'yoast/faq-block').
	 * @return bool
	 */
	public static function is_dual_storage( string $block_name ): bool {
		return in_array( $block_name, self::get_blocks(), true );
	}

	// ── Scan helper (stretch) ─────────────────────────────────────────────

	/**
	 * Scan all published posts and detect dual-storage candidates.
	 *
	 * A block is flagged as a candidate when at least one of its non-empty
	 * attribute string values also appears verbatim inside its `innerHTML`.
	 * This heuristic catches blocks that embed the same text in both stores.
	 *
	 * The detected list is stored in a site option and can be retrieved via
	 * {@see DualStorageRegistry::get_detected_blocks()}. The hard-coded list
	 * is kept separate — detected blocks are _additional_ candidates that may
	 * require manual review before enforcement.
	 *
	 * @param int $batch_size Maximum number of posts to scan. Default 500.
	 * @return string[] Detected block names (unique, sorted).
	 */
	public static function scan( int $batch_size = 500 ): array {
		$offset   = 0;
		$detected = [];
		$known    = self::get_blocks();

		while ( true ) {
			$posts = get_posts(
				[
					'post_status'    => 'publish',
					'post_type'      => 'any',
					'posts_per_page' => min( $batch_size, 50 ),
					'offset'         => $offset,
					'fields'         => 'all',
					'no_found_rows'  => true,
				]
			);

			if ( empty( $posts ) ) {
				break;
			}

			foreach ( $posts as $post ) {
				if ( ! has_blocks( $post->post_content ) ) {
					continue;
				}

				$blocks   = parse_blocks( $post->post_content );
				$detected = array_merge( $detected, self::detect_in_tree( $blocks, $known ) );
			}

			$offset += count( $posts );

			if ( $offset >= $batch_size ) {
				break;
			}
		}

		$detected = array_values( array_unique( $detected ) );
		sort( $detected );

		update_option( self::SCAN_OPTION, $detected, false );

		return $detected;
	}

	/**
	 * Return previously cached scan results (empty array if scan not yet run).
	 *
	 * @return string[]
	 */
	public static function get_detected_blocks(): array {
		$stored = get_option( self::SCAN_OPTION, [] );

		if ( ! is_array( $stored ) ) {
			return [];
		}

		// Ensure the returned list is always string[].
		return array_values( array_filter( $stored, 'is_string' ) );
	}

	/**
	 * Clear the stored scan cache.
	 *
	 * @return void
	 */
	public static function delete_scan_cache(): void {
		delete_option( self::SCAN_OPTION );
	}

	// ── Private helpers ───────────────────────────────────────────────────

	/**
	 * Recursively walk a parsed block tree and collect dual-storage candidates
	 * not already in $known.
	 *
	 * @param array<array-key,mixed> $blocks Parse-blocks output (may have int|string keys).
	 * @param string[]               $known  Already-known dual-storage block names to skip.
	 * @return string[] Newly detected block names.
	 */
	private static function detect_in_tree( array $blocks, array $known ): array {
		$found = [];

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name       = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
			$attrs      = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
			$inner_html = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';

			if ( '' !== $name && ! in_array( $name, $known, true ) && ! in_array( $name, $found, true ) ) {
				if ( self::attrs_overlap_html( $attrs, $inner_html ) ) {
					$found[] = $name;
				}
			}

			// Recurse into innerBlocks.
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$child_found = self::detect_in_tree( $block['innerBlocks'], array_merge( $known, $found ) );
				$found       = array_merge( $found, $child_found );
			}
		}

		return $found;
	}

	/**
	 * Return true when any non-empty string attribute value appears in $html.
	 *
	 * @param array<string,mixed> $attrs      Block attributes (may be nested).
	 * @param string              $inner_html Block innerHTML.
	 * @return bool
	 */
	private static function attrs_overlap_html( array $attrs, string $inner_html ): bool {
		if ( '' === $inner_html ) {
			return false;
		}

		foreach ( self::flatten_strings( $attrs ) as $value ) {
			if ( '' !== $value && str_contains( $inner_html, $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Recursively collect all non-empty string leaf values from a nested array.
	 *
	 * @param mixed $data Array or scalar to flatten.
	 * @return string[]
	 */
	private static function flatten_strings( mixed $data ): array {
		if ( is_string( $data ) ) {
			return [ $data ];
		}

		if ( ! is_array( $data ) ) {
			return [];
		}

		$strings = [];

		foreach ( $data as $value ) {
			$strings = array_merge( $strings, self::flatten_strings( $value ) );
		}

		return $strings;
	}
}
