<?php

declare(strict_types=1);
/**
 * Block tree address resolver.
 *
 * Translates a caller-supplied addressing specification — one of `ref`,
 * `path` (int[]), or `flat_index` (int) — into a concrete int[] path from
 * the root of the block tree to the target block.
 *
 * Address resolution order (mirrors the block-mcp upstream):
 *   1. `ref`        — stable blk_XXXXXXXX UUID from BlockReferences::find_by_ref().
 *   2. `path`       — caller-supplied int[] walked from root via innerBlocks.
 *   3. `flat_index` — zero-based depth-first position (same order as get-page-blocks).
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1708
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static utility: resolve a block address to a concrete path.
 */
class BlockTreeAddress {

	// ── Public API ────────────────────────────────────────────────────────

	/**
	 * Resolve a caller-supplied address to a concrete int[] path.
	 *
	 * Resolution order: ref → path → flat_index.
	 * Returns a WP_Error if the address cannot be resolved.
	 *
	 * @param array<int|string,mixed> $blocks Parsed block tree.
	 * @param array<string,mixed>     $args   Address args: one of `ref`, `path`, `flat_index`.
	 * @return int[]|\WP_Error Resolved path (int[]) or WP_Error.
	 */
	public static function resolve( array $blocks, array $args ) {
		// Priority 1: stable ref.
		if ( isset( $args['ref'] ) && is_string( $args['ref'] ) && '' !== $args['ref'] ) {
			$found = BlockReferences::find_by_ref( $blocks, $args['ref'] );

			if ( null === $found ) {
				return new \WP_Error(
					'block_not_found',
					sprintf( "Block ref '%s' not found in the block tree.", $args['ref'] ),
					[ 'status' => 404 ]
				);
			}

			return $found['path'];
		}

		// Priority 2: explicit path.
		if ( isset( $args['path'] ) && is_array( $args['path'] ) ) {
			$path = array_map( fn( mixed $v ): int => (int) $v, $args['path'] );

			if ( empty( $path ) ) {
				return new \WP_Error(
					'invalid_path',
					'path must be a non-empty array of integer indices.',
					[ 'status' => 400 ]
				);
			}

			$block = self::get_block_at_path( $blocks, $path );

			if ( null === $block ) {
				return new \WP_Error(
					'block_not_found',
					sprintf( 'No block found at path [%s].', implode( ',', $path ) ),
					[ 'status' => 404 ]
				);
			}

			return $path;
		}

		// Priority 3: flat_index.
		if ( array_key_exists( 'flat_index', $args ) ) {
			$flat_index = (int) $args['flat_index'];

			if ( $flat_index < 0 ) {
				return new \WP_Error(
					'invalid_flat_index',
					'flat_index must be a non-negative integer.',
					[ 'status' => 400 ]
				);
			}

			$path = self::find_path_by_flat_index( $blocks, $flat_index );

			if ( null === $path ) {
				return new \WP_Error(
					'block_not_found',
					sprintf( 'No block found at flat_index %d.', $flat_index ),
					[ 'status' => 404 ]
				);
			}

			return $path;
		}

		return new \WP_Error(
			'missing_address',
			'Provide ref, path, or flat_index to address a block.',
			[ 'status' => 400 ]
		);
	}

	/**
	 * Retrieve the block at the given int[] path without mutating the tree.
	 *
	 * @param array<int|string,mixed> $blocks Parsed block tree.
	 * @param int[]                   $path   Path from root (each entry is a local array index).
	 * @return array<int|string,mixed>|null The block array, or null if path is invalid.
	 */
	public static function get_block_at_path( array $blocks, array $path ): ?array {
		if ( empty( $path ) ) {
			return null;
		}

		$current = $blocks;

		foreach ( $path as $depth => $idx ) {
			if ( ! isset( $current[ $idx ] ) || ! is_array( $current[ $idx ] ) ) {
				return null;
			}

			$block = $current[ $idx ];

			// If we're at the last index, this is the target.
			if ( $depth === count( $path ) - 1 ) {
				return $block;
			}

			// Descend into innerBlocks.
			$inner   = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
			$current = $inner;
		}

		return null; // Should not reach here.
	}

	/**
	 * Check whether $ancestor_path is a strict ancestor of $descendant_path.
	 *
	 * Used by BlockMutator to reject move-into-own-descendant cycles.
	 *
	 * @param int[] $ancestor_path   Candidate ancestor path.
	 * @param int[] $descendant_path Candidate descendant path.
	 * @return bool True if ancestor_path is a strict prefix of descendant_path.
	 */
	public static function is_strict_ancestor( array $ancestor_path, array $descendant_path ): bool {
		$anc_len  = count( $ancestor_path );
		$desc_len = count( $descendant_path );

		if ( $anc_len >= $desc_len ) {
			return false;
		}

		return array_slice( $descendant_path, 0, $anc_len ) === $ancestor_path;
	}

	// ── Internal helpers ──────────────────────────────────────────────────

	/**
	 * Find the int[] path to the block at flat_index using depth-first ordering.
	 *
	 * @param array<int|string,mixed> $blocks Parsed block tree.
	 * @param int                     $target Target flat index (0-based).
	 * @return int[]|null Path, or null if not found.
	 */
	private static function find_path_by_flat_index( array $blocks, int $target ): ?array {
		$flat = 0;
		return self::find_path_recursive( $blocks, $target, [], $flat );
	}

	/**
	 * Recursive depth-first flat-index search.
	 *
	 * Only named blocks (non-empty blockName) are counted, matching the flat_index
	 * produced by the sd-ai-agent/get-page-blocks ability output.
	 *
	 * @param array<int|string,mixed> $blocks  Block tree at the current level.
	 * @param int                     $target  Target flat index.
	 * @param int[]                   $path    Accumulated path so far.
	 * @param int                     $flat    Running flat counter (by reference).
	 * @return int[]|null Path to the target block, or null.
	 */
	private static function find_path_recursive( array $blocks, int $target, array $path, int &$flat ): ?array {
		foreach ( $blocks as $idx => $block ) {
			// Only named blocks are counted — matches flatten_blocks_for_response().
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			$current_path = array_merge( $path, [ (int) $idx ] );

			if ( $flat === $target ) {
				++$flat;
				return $current_path;
			}

			++$flat;

			$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];

			if ( ! empty( $inner ) ) {
				$found = self::find_path_recursive( $inner, $target, $current_path, $flat );

				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}
}
