<?php

declare(strict_types=1);
/**
 * Stable per-block UUID references (sd_ref).
 *
 * Assigns a stable `blk_XXXXXXXX` UUID to every block in a post's block
 * tree via `attrs.metadata.sd_ref`. Once assigned, refs are preserved
 * across subsequent reads, so multi-turn agent edits can address the same
 * block reliably even when sibling indices shift due to inserts or deletes.
 *
 * Adapted from ~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-reader.php
 * (GPL-2.0-or-later — compatible). The upstream `gk_ref` metadata slot and
 * `GravityKit\BlockAPI\` namespace have been renamed to `sd_ref` and
 * `SdAiAgent\Core\` per the canonical naming rules in AGENTS.md.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1707
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-block stable UUID reference manager.
 *
 * All public entry points are static. No per-request instance state is
 * maintained — the class is a pure namespace for the three public methods.
 *
 * UUID format: `blk_` + 8 URL-safe base64url characters. Collision-checked
 * within the document during assign_refs().
 *
 * Depth cap: trees deeper than MAX_DEPTH raise a `block_depth_exceeded`
 * WP_Error. This constant is intentionally public so t251 (shared depth cap)
 * can reference it without hard-coding the value.
 */
class BlockReferences {

	/**
	 * Metadata key used to store the stable ref in block attrs.
	 *
	 * Stored as `attrs.metadata.sd_ref` (following WordPress block metadata
	 * conventions) to keep refs alongside other editor-only metadata.
	 *
	 * @var string
	 */
	const REF_KEY = 'sd_ref';

	/**
	 * Hard depth cap for block tree walks.
	 *
	 * Trees deeper than this raise a `block_depth_exceeded` WP_Error.
	 * See t251 for the planned shared-constant migration.
	 *
	 * @var int
	 */
	const MAX_DEPTH = 32;

	/**
	 * Length of the random suffix portion of a ref (before base64url encoding).
	 *
	 * 6 raw bytes → 8 base64url chars = 48 bits of entropy per ref.
	 *
	 * @var int
	 */
	const REF_RANDOM_BYTES = 6;

	// ── Public API ────────────────────────────────────────────────────────

	/**
	 * Walk a block tree and ensure every block has a stable sd_ref.
	 *
	 * Blocks that already carry an `attrs.metadata.sd_ref` value are left
	 * unchanged. New refs are generated as `blk_` + 8 URL-safe base64url
	 * characters and are collision-checked within the document.
	 *
	 * @param array<int|string,mixed> $blocks Parsed block tree (output of parse_blocks()).
	 * @param int                     $depth  Current recursion depth (internal use).
	 * @return array<int|string,mixed>|\WP_Error Updated block tree, or WP_Error on depth violation.
	 */
	public static function assign_refs( array $blocks, int $depth = 0 ) {
		if ( $depth > self::MAX_DEPTH ) {
			return new \WP_Error(
				'block_depth_exceeded',
				sprintf(
					'Block tree depth exceeded the maximum of %d levels.',
					self::MAX_DEPTH
				)
			);
		}

		// Collect all existing refs so new ones do not collide.
		$existing_refs = self::collect_existing_refs( $blocks );

		return self::assign_refs_recursive( $blocks, $existing_refs, $depth );
	}

	/**
	 * Find a block by its sd_ref within a block tree.
	 *
	 * Searches recursively. Returns a map with:
	 * - `path`       (int[]) — array of integer indices from root to target block.
	 * - `flat_index` (int)   — zero-based position in a depth-first flat walk.
	 * - `block`      (array) — the matching block array.
	 *
	 * @param array<int|string,mixed> $blocks Block tree (output of parse_blocks() + assign_refs()).
	 * @param string                  $ref    The `blk_XXXXXXXX` ref to locate.
	 * @return array{path:int[],flat_index:int,block:array<int|string,mixed>}|null Match or null.
	 */
	public static function find_by_ref( array $blocks, string $ref ): ?array {
		$flat_index = 0;
		return self::find_by_ref_recursive( $blocks, $ref, [], $flat_index );
	}

	/**
	 * Return true only when every block (recursively) already has a sd_ref.
	 *
	 * Used by the handler to skip a no-op DB write when refs are complete.
	 *
	 * @param array<int|string,mixed> $blocks Block tree.
	 * @return bool True when all blocks carry a ref, false when any is missing.
	 */
	public static function all_have_refs( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$attrs    = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
			$metadata = isset( $attrs['metadata'] ) && is_array( $attrs['metadata'] ) ? $attrs['metadata'] : [];
			$ref      = $metadata[ self::REF_KEY ] ?? null;

			if ( ! is_string( $ref ) || '' === $ref ) {
				return false;
			}

			$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];

			if ( ! empty( $inner ) && ! self::all_have_refs( $inner ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Re-serialise post_content with assigned refs and write directly to DB.
	 *
	 * The write bypasses `wp_update_post()` to avoid creating a revision — refs
	 * are editor-only metadata, not user-authored content. Uses `$wpdb->update()`
	 * + `clean_post_cache()` (the same pattern used by the upstream block-reader).
	 *
	 * Returns false if the post does not exist or serialize_blocks() is not
	 * available (< WP 5.3 — never true in practice with WP 7.0+ requirement).
	 *
	 * @param int $post_id Post ID whose block tree has already had refs assigned.
	 * @return bool True on success, false on failure.
	 */
	public static function persist_refs_for_post( int $post_id ): bool {
		global $wpdb;

		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$blocks = parse_blocks( $post->post_content );
		// @phpstan-ignore-next-line
		$result = self::assign_refs( $blocks );

		if ( is_wp_error( $result ) ) {
			return false;
		}

		// @phpstan-ignore-next-line
		$new_content = serialize_blocks( $result );

		// Direct DB write — no revision, no filters, no post-save hooks.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$wpdb->posts,
			[ 'post_content' => $new_content ],
			[ 'ID' => $post_id ],
			[ '%s' ],
			[ '%d' ]
		);

		if ( false === $updated ) {
			return false;
		}

		clean_post_cache( $post_id );

		return true;
	}

	// ── Internal helpers ──────────────────────────────────────────────────

	/**
	 * Collect all existing sd_ref values in a block tree (pre-flight dedup).
	 *
	 * @param array<int|string,mixed> $blocks Block tree.
	 * @return array<string,true>             Map of ref => true.
	 */
	private static function collect_existing_refs( array $blocks ): array {
		$refs = [];

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$attrs    = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
			$metadata = isset( $attrs['metadata'] ) && is_array( $attrs['metadata'] ) ? $attrs['metadata'] : [];
			$ref      = $metadata[ self::REF_KEY ] ?? null;

			if ( is_string( $ref ) && '' !== $ref ) {
				$refs[ $ref ] = true;
			}

			$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];

			if ( ! empty( $inner ) ) {
				$inner_refs = self::collect_existing_refs( $inner );
				$refs       = array_merge( $refs, $inner_refs );
			}
		}

		return $refs;
	}

	/**
	 * Recursive ref-assignment walk.
	 *
	 * @param array<int|string,mixed> $blocks        Block tree at the current level.
	 * @param array<string,true>      $existing_refs Mutable map of all known refs (passed by reference).
	 * @param int                     $depth         Current nesting depth.
	 * @return array<int|string,mixed>|\WP_Error Updated block tree or WP_Error on depth violation.
	 */
	private static function assign_refs_recursive( array $blocks, array &$existing_refs, int $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			return new \WP_Error(
				'block_depth_exceeded',
				sprintf(
					'Block tree depth exceeded the maximum of %d levels.',
					self::MAX_DEPTH
				)
			);
		}

		$result = [];

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			// Ensure the attrs and metadata keys exist.
			if ( ! isset( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
				$block['attrs'] = [];
			}

			if ( ! isset( $block['attrs']['metadata'] ) || ! is_array( $block['attrs']['metadata'] ) ) {
				$block['attrs']['metadata'] = [];
			}

			// Only assign a new ref if none exists.
			if ( empty( $block['attrs']['metadata'][ self::REF_KEY ] ) ) {
				$new_ref                                     = self::generate_ref( $existing_refs );
				$block['attrs']['metadata'][ self::REF_KEY ] = $new_ref;
				// Register the new ref so subsequent siblings can't collide.
				$existing_refs[ $new_ref ] = true;
			}

			// Recurse into inner blocks.
			$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];

			if ( ! empty( $inner ) ) {
				$walked = self::assign_refs_recursive( $inner, $existing_refs, $depth + 1 );

				if ( is_wp_error( $walked ) ) {
					return $walked;
				}

				$block['innerBlocks'] = $walked;
			}

			$result[] = $block;
		}

		return $result;
	}

	/**
	 * Generate a collision-free `blk_XXXXXXXX` ref.
	 *
	 * Uses random_bytes() for cryptographic randomness and base64url encoding
	 * (URL-safe: `+` → `-`, `/` → `_`, `=` stripped). Retries up to 32 times
	 * before returning the last candidate (collision probability is negligible
	 * at document scale).
	 *
	 * @param array<string,true> $existing_refs Currently known refs in this document.
	 * @return string Generated ref, e.g. `blk_a3f2c1q9`.
	 */
	private static function generate_ref( array $existing_refs ): string {
		for ( $i = 0; $i < 32; $i++ ) {
			$raw = random_bytes( self::REF_RANDOM_BYTES );
			// Base64url: replace +/ with -_ and strip padding =.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- used for URL-safe random ID generation, not obfuscation.
			$slug = rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
			$ref  = 'blk_' . $slug;

			if ( ! isset( $existing_refs[ $ref ] ) ) {
				return $ref;
			}
		}

		// Extremely unlikely; return the last candidate.
		return $ref; // @phpstan-ignore-line
	}

	/**
	 * Recursive depth-first search for a block by ref.
	 *
	 * @param array<int|string,mixed> $blocks     Block tree at the current level.
	 * @param string                  $ref        Target ref to find.
	 * @param int[]                   $path       Index path accumulated so far.
	 * @param int                     $flat_index Running flat position (passed by reference).
	 * @return array{path:int[],flat_index:int,block:array<int|string,mixed>}|null
	 */
	private static function find_by_ref_recursive( array $blocks, string $ref, array $path, int &$flat_index ): ?array {
		foreach ( $blocks as $local_index => $block ) {
			if ( ! is_array( $block ) ) {
				++$flat_index;
				continue;
			}

			$current_path       = array_merge( $path, [ (int) $local_index ] );
			$current_flat_index = $flat_index;
			++$flat_index;

			$attrs     = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
			$metadata  = isset( $attrs['metadata'] ) && is_array( $attrs['metadata'] ) ? $attrs['metadata'] : [];
			$block_ref = $metadata[ self::REF_KEY ] ?? null;

			if ( $block_ref === $ref ) {
				return [
					'path'       => $current_path,
					'flat_index' => $current_flat_index,
					'block'      => $block,
				];
			}

			// Recurse into inner blocks.
			$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];

			if ( ! empty( $inner ) ) {
				$found = self::find_by_ref_recursive( $inner, $ref, $current_path, $flat_index );

				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}
}
