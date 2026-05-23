<?php

declare(strict_types=1);
/**
 * Block tree mutator — 9-op vocabulary.
 *
 * Pure-function tree transforms: no WP DB writes occur here. All methods
 * accept a parsed block tree (output of parse_blocks()) and return either a
 * new tree array or a WP_Error.
 *
 * Supported operations:
 *   update-attrs    — merge/replace a block's `attributes`.
 *   update-html     — replace a block's `innerHTML` (wp_kses_post applied).
 *   replace-block   — swap a block (and its descendants) for a new definition.
 *   remove-block    — delete a block from its parent.
 *   wrap-in-group   — wrap a block inside a new `core/group`.
 *   unwrap-group    — replace a group with its innerBlocks (rejects empty sets).
 *   insert-child    — append/insert a child into innerBlocks at position N.
 *   duplicate       — JSON-clone a block as a +1 sibling.
 *   move            — relocate a block to a new position (rejects cycles).
 *
 * Adapted from ~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-block-mutator.php
 * (GPL-2.0-or-later — compatible). Namespace and ref key renamed per AGENTS.md.
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
 * Block tree mutator.
 *
 * All entry points are static. The class holds no per-instance state.
 */
class BlockMutator {

	/**
	 * Valid operation names.
	 *
	 * @var string[]
	 */
	const VALID_OPS = [
		'update-attrs',
		'update-html',
		'replace-block',
		'remove-block',
		'wrap-in-group',
		'unwrap-group',
		'insert-child',
		'duplicate',
		'move',
	];

	// ── Public API ────────────────────────────────────────────────────────

	/**
	 * Apply a single mutation operation to a parsed block tree.
	 *
	 * For `update-attrs` on supported static blocks, HtmlTransformer automatically
	 * rewrites innerHTML to stay consistent with the new attribute values. For
	 * unsupported static blocks, a `_warnings` key is added to the result array
	 * containing `static_block_attrs_changed` so the caller knows innerHTML may
	 * need manual updating.
	 *
	 * @param array<int,mixed>    $blocks Parsed block tree (parse_blocks() output).
	 * @param string              $op     Operation name (one of VALID_OPS).
	 * @param array<string,mixed> $args   Operation arguments including the address.
	 * @return array<int|string,mixed>|\WP_Error Mutated block tree, or WP_Error.
	 */
	public static function apply( array $blocks, string $op, array $args ) {
		if ( ! in_array( $op, self::VALID_OPS, true ) ) {
			return new \WP_Error(
				'invalid_op',
				sprintf(
					"Unknown operation '%s'. Valid ops: %s.",
					$op,
					implode( ', ', self::VALID_OPS )
				),
				[ 'status' => 400 ]
			);
		}

		// Resolve the target block address to a concrete path.
		$path = BlockTreeAddress::resolve( $blocks, $args );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		switch ( $op ) {
			case 'update-attrs':
				$result = self::op_update_attrs( $blocks, $path, $args );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				// Emit static_block_attrs_changed warning for unsupported blocks.
				$target = BlockTreeAddress::get_block_at_path( $blocks, $path );

				if ( null !== $target
					&& isset( $target['blockName'] ) && is_string( $target['blockName'] )
					&& '' !== $target['blockName']
					&& ! HtmlTransformer::is_supported( $target['blockName'] )
				) {
					$result['_warnings'] = [ 'static_block_attrs_changed' ];
				}

				return $result;
			case 'update-html':
				return self::op_update_html( $blocks, $path, $args );
			case 'replace-block':
				return self::op_replace_block( $blocks, $path, $args );
			case 'remove-block':
				return self::op_remove_block( $blocks, $path );
			case 'wrap-in-group':
				return self::op_wrap_in_group( $blocks, $path, $args );
			case 'unwrap-group':
				return self::op_unwrap_group( $blocks, $path );
			case 'insert-child':
				return self::op_insert_child( $blocks, $path, $args );
			case 'duplicate':
				return self::op_duplicate( $blocks, $path );
			case 'move':
				return self::op_move( $blocks, $path, $args );
		}

		// Should never reach here after the in_array check above.
		return new \WP_Error( 'invalid_op', 'Unknown operation.', [ 'status' => 400 ] ); // @phpstan-ignore-line
	}

	// ── Operations ────────────────────────────────────────────────────────

	/**
	 * Merge or replace a block's attributes (update-attrs op).
	 *
	 * When `merge` is true (default), the supplied attributes are merged over
	 * the existing ones. When false, they replace the existing attrs entirely.
	 *
	 * @param array<int,mixed>    $blocks Parsed block tree.
	 * @param int[]               $path   Resolved target path.
	 * @param array<string,mixed> $args   Must include `attributes` (array).
	 *                                    Optional `merge` (bool, default true).
	 * @return array<int|string,mixed>|\WP_Error
	 */
	private static function op_update_attrs( array $blocks, array $path, array $args ) {
		if ( ! isset( $args['attributes'] ) || ! is_array( $args['attributes'] ) ) {
			return new \WP_Error(
				'missing_attributes',
				'update-attrs requires an attributes object.',
				[ 'status' => 400 ]
			);
		}

		$merge = isset( $args['merge'] ) ? (bool) $args['merge'] : true;

		return self::mutate_at_path(
			$blocks,
			$path,
			static function ( array $siblings, int $idx ) use ( $args, $merge ) {
				$block    = $siblings[ $idx ];
				$existing = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];

				if ( $merge ) {
					$block['attrs'] = array_merge( $existing, $args['attributes'] );
				} else {
					$block['attrs'] = $args['attributes'];
				}

				// Auto-transform innerHTML when attribute changes imply HTML structure changes.
				if ( is_array( $block ) && HtmlTransformer::is_supported( isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '' ) ) {
					$block = HtmlTransformer::apply( $block, $args['attributes'] );
				}

				$siblings[ $idx ] = $block;
				return $siblings;
			}
		);
	}

	/**
	 * Replace a block's innerHTML (update-html op).
	 *
	 * The wp_kses_post() function is applied to strip scripts and inline event handlers.
	 *
	 * @param array<int,mixed>    $blocks Parsed block tree.
	 * @param int[]               $path   Resolved target path.
	 * @param array<string,mixed> $args   Must include `innerHTML` (string).
	 * @return array<int|string,mixed>|\WP_Error
	 */
	private static function op_update_html( array $blocks, array $path, array $args ) {
		if ( ! isset( $args['innerHTML'] ) || ! is_string( $args['innerHTML'] ) ) {
			return new \WP_Error(
				'missing_inner_html',
				'update-html requires an innerHTML string.',
				[ 'status' => 400 ]
			);
		}

		$safe_html = wp_kses_post( $args['innerHTML'] );

		return self::mutate_at_path(
			$blocks,
			$path,
			static function ( array $siblings, int $idx ) use ( $safe_html ) {
				$block = $siblings[ $idx ];

				if ( ! is_array( $block ) ) {
					return $siblings;
				}

				$block['innerHTML'] = $safe_html;

				// Rebuild innerContent with the new HTML (single element, no inner blocks affected).
				$inner_blocks = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];

				if ( empty( $inner_blocks ) ) {
					$block['innerContent'] = [ $safe_html ];
				} else {
					// Preserve innerContent null slots for innerBlocks; replace HTML portions only.
					$block['innerContent'] = self::rebuild_inner_content_with_html( $block, $safe_html );
				}

				$siblings[ $idx ] = $block;
				return $siblings;
			}
		);
	}

	/**
	 * Swap a block (and all its descendants) for a new definition (replace-block op).
	 *
	 * @param array<int,mixed>    $blocks Parsed block tree.
	 * @param int[]               $path   Resolved target path.
	 * @param array<string,mixed> $args   Must include `block_def` (array, a parsed block array).
	 * @return array<int|string,mixed>|\WP_Error
	 */
	private static function op_replace_block( array $blocks, array $path, array $args ) {
		if ( ! isset( $args['block_def'] ) || ! is_array( $args['block_def'] ) ) {
			return new \WP_Error(
				'missing_block_def',
				'replace-block requires a block_def object.',
				[ 'status' => 400 ]
			);
		}

		$new_block = self::normalize_block( $args['block_def'] );

		// Apply wp_kses_post to innerHTML in the replacement block tree.
		$new_block = self::sanitize_block_tree( $new_block );

		return self::mutate_at_path(
			$blocks,
			$path,
			static function ( array $siblings, int $idx ) use ( $new_block ) {
				$siblings[ $idx ] = $new_block;
				return $siblings;
			}
		);
	}

	/**
	 * Delete a block from its parent (remove-block op).
	 *
	 * @param array<int,mixed> $blocks Parsed block tree.
	 * @param int[]            $path   Resolved target path.
	 * @return array<int|string,mixed>|\WP_Error
	 */
	private static function op_remove_block( array $blocks, array $path ) {
		return self::mutate_at_path(
			$blocks,
			$path,
			static function ( array $siblings, int $idx ) {
				// Remove the block and update parent's innerContent.
				array_splice( $siblings, $idx, 1 );
				return array_values( $siblings );
			}
		);
	}

	/**
	 * Wrap a block inside a new core/group (wrap-in-group op).
	 *
	 * @param array<int,mixed>    $blocks Parsed block tree.
	 * @param int[]               $path   Resolved target path.
	 * @param array<string,mixed> $args   Optional `attributes` (array) for the group.
	 * @return array<int|string,mixed>|\WP_Error
	 */
	private static function op_wrap_in_group( array $blocks, array $path, array $args ) {
		$group_attrs = isset( $args['attributes'] ) && is_array( $args['attributes'] ) ? $args['attributes'] : [];

		return self::mutate_at_path(
			$blocks,
			$path,
			static function ( array $siblings, int $idx ) use ( $group_attrs ) {
				$target = $siblings[ $idx ];

				$group = [
					'blockName'    => 'core/group',
					'attrs'        => $group_attrs,
					'innerBlocks'  => [ $target ],
					'innerHTML'    => '',
					'innerContent' => [ null ],
				];

				$siblings[ $idx ] = $group;
				return $siblings;
			}
		);
	}

	/**
	 * Replace a group with its innerBlocks (unwrap-group op).
	 *
	 * Returns a `no_inner_blocks` WP_Error when the target has no innerBlocks.
	 *
	 * @param array<int,mixed> $blocks Parsed block tree.
	 * @param int[]            $path   Resolved target path.
	 * @return array<int|string,mixed>|\WP_Error
	 */
	private static function op_unwrap_group( array $blocks, array $path ) {
		$target = BlockTreeAddress::get_block_at_path( $blocks, $path );

		if ( null === $target ) {
			return new \WP_Error( 'block_not_found', 'Target block not found.', [ 'status' => 404 ] );
		}

		$inner = isset( $target['innerBlocks'] ) && is_array( $target['innerBlocks'] ) ? $target['innerBlocks'] : [];

		if ( empty( $inner ) ) {
			return new \WP_Error(
				'no_inner_blocks',
				'unwrap-group requires a block that has innerBlocks.',
				[ 'status' => 400 ]
			);
		}

		return self::mutate_at_path(
			$blocks,
			$path,
			static function ( array $siblings, int $idx ) use ( $inner ) {
				// Replace the single block at $idx with all its children.
				array_splice( $siblings, $idx, 1, $inner );
				return array_values( $siblings );
			}
		);
	}

	/**
	 * Append or insert a child block into the target's innerBlocks (insert-child op).
	 *
	 * @param array<int,mixed>    $blocks Parsed block tree.
	 * @param int[]               $path   Resolved target path.
	 * @param array<string,mixed> $args   Must include `block_def` (array).
	 *                                    Optional `position` (int, 0-based; default: end).
	 * @return array<int|string,mixed>|\WP_Error
	 */
	private static function op_insert_child( array $blocks, array $path, array $args ) {
		if ( ! isset( $args['block_def'] ) || ! is_array( $args['block_def'] ) ) {
			return new \WP_Error(
				'missing_block_def',
				'insert-child requires a block_def object.',
				[ 'status' => 400 ]
			);
		}

		$new_child = self::normalize_block( $args['block_def'] );
		$new_child = self::sanitize_block_tree( $new_child );

		$position = isset( $args['position'] ) ? (int) $args['position'] : null;

		return self::mutate_at_path(
			$blocks,
			$path,
			static function ( array $siblings, int $idx ) use ( $new_child, $position ) {
				$block = $siblings[ $idx ];
				$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
				$icont = isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : [];

				if ( null === $position || $position >= count( $inner ) ) {
					// Append.
					$inner[] = $new_child;
					$icont[] = null;
				} else {
					$pos = max( 0, $position );
					// Insert before position — find the Nth null in innerContent and splice there.
					$null_count = 0;
					$insert_at  = count( $icont ); // Default: append.

					foreach ( $icont as $ci => $chunk ) {
						if ( null === $chunk ) {
							if ( $null_count === $pos ) {
								$insert_at = $ci;
								break;
							}

							++$null_count;
						}
					}

					array_splice( $inner, $pos, 0, [ $new_child ] );
					array_splice( $icont, $insert_at, 0, [ null ] );
				}

				$block['innerBlocks']  = $inner;
				$block['innerContent'] = $icont;
				$siblings[ $idx ]      = $block;
				return $siblings;
			}
		);
	}

	/**
	 * JSON-clone a block in place as a +1 sibling (duplicate op).
	 *
	 * Deep-clones via wp_json_encode() + json_decode(). Fails closed on
	 * encoding failures (e.g., invalid UTF-8).
	 *
	 * @param array<int,mixed> $blocks Parsed block tree.
	 * @param int[]            $path   Resolved target path.
	 * @return array<int|string,mixed>|\WP_Error
	 */
	private static function op_duplicate( array $blocks, array $path ) {
		$target = BlockTreeAddress::get_block_at_path( $blocks, $path );

		if ( null === $target ) {
			return new \WP_Error( 'block_not_found', 'Target block not found.', [ 'status' => 404 ] );
		}

		$json = wp_json_encode( $target );

		if ( false === $json ) {
			return new \WP_Error(
				'duplicate_failed',
				'Could not JSON-encode the block (invalid UTF-8 or resource type).',
				[ 'status' => 500 ]
			);
		}

		$clone = json_decode( $json, true );

		if ( ! is_array( $clone ) ) {
			return new \WP_Error(
				'duplicate_failed',
				'JSON round-trip produced unexpected output.',
				[ 'status' => 500 ]
			);
		}

		// Strip sd_refs from the clone so refs remain unique.
		$clone = self::strip_refs( $clone );

		return self::mutate_at_path(
			$blocks,
			$path,
			static function ( array $siblings, int $idx ) use ( $clone ) {
				// Insert clone immediately after the original.
				array_splice( $siblings, $idx + 1, 0, [ $clone ] );
				return array_values( $siblings );
			}
		);
	}

	/**
	 * Relocate a block to a new position in the tree (move op).
	 *
	 * The destination is specified the same way as the source (ref/path/flat_index).
	 * The block is inserted before or after the destination block, controlled
	 * by `position` ('before'|'after', default 'after').
	 *
	 * Returns `invalid_destination` WP_Error when the destination is a
	 * descendant of the source (cycle).
	 *
	 * @param array<int,mixed>    $blocks Parsed block tree.
	 * @param int[]               $src_path Source block path.
	 * @param array<string,mixed> $args     Must include destination addressing args
	 *                                      under `destination` key (object with ref/path/flat_index).
	 *                                      Optional `position` ('before'|'after', default 'after').
	 * @return array<int|string,mixed>|\WP_Error
	 */
	private static function op_move( array $blocks, array $src_path, array $args ) {
		if ( ! isset( $args['destination'] ) || ! is_array( $args['destination'] ) ) {
			return new \WP_Error(
				'missing_destination',
				'move requires a destination address object (ref, path, or flat_index).',
				[ 'status' => 400 ]
			);
		}

		$position = ( isset( $args['position'] ) && 'before' === $args['position'] ) ? 'before' : 'after';

		// Resolve destination before mutation (paths valid against original tree).
		$dst_path = BlockTreeAddress::resolve( $blocks, $args['destination'] );

		if ( is_wp_error( $dst_path ) ) {
			return $dst_path;
		}

		// Reject cycles: destination must not be inside the source subtree.
		if ( BlockTreeAddress::is_strict_ancestor( $src_path, $dst_path ) ) {
			return new \WP_Error(
				'invalid_destination',
				'Cannot move a block into its own descendant tree.',
				[ 'status' => 400 ]
			);
		}

		// Also reject src == dst.
		if ( $src_path === $dst_path ) {
			return new \WP_Error(
				'invalid_destination',
				'Source and destination are the same block.',
				[ 'status' => 400 ]
			);
		}

		// Extract the source block.
		$source_block = BlockTreeAddress::get_block_at_path( $blocks, $src_path );

		if ( null === $source_block ) {
			return new \WP_Error( 'block_not_found', 'Source block not found.', [ 'status' => 404 ] );
		}

		// Step 1: remove source from tree.
		$result = self::op_remove_block( $blocks, $src_path );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$blocks_without_source = $result;

		// Step 2: re-resolve the destination path in the updated tree (remove may
		// have shifted sibling indices if source and destination share a parent).
		$dst_path_updated = self::adjust_path_after_remove( $src_path, $dst_path );

		// Validate the updated destination path.
		if ( null === BlockTreeAddress::get_block_at_path( $blocks_without_source, $dst_path_updated ) ) {
			// Fall back to the original tree's destination (still valid if paths diverged).
			$dst_path_updated = BlockTreeAddress::resolve( $blocks_without_source, $args['destination'] );

			if ( is_wp_error( $dst_path_updated ) ) {
				return new \WP_Error(
					'invalid_destination',
					'Destination block could not be re-resolved after source removal.',
					[ 'status' => 400 ]
				);
			}
		}

		// Step 3: insert source at destination.
		$result = self::insert_sibling( $blocks_without_source, $dst_path_updated, $source_block, $position );

		return $result;
	}

	// ── Tree-walk helpers ─────────────────────────────────────────────────

	/**
	 * Functionally rebuild the block tree with a mutation applied at a path.
	 *
	 * The callback receives the sibling array and the local index of the target,
	 * and MUST return a new sibling array (or WP_Error).
	 *
	 * @param array<int|string,mixed> $blocks   Block tree.
	 * @param int[]                   $path     Path from root to target (non-empty).
	 * @param callable                $mutator  Callback: fn(array $siblings, int $idx): array|WP_Error.
	 * @return array<int|string,mixed>|\WP_Error
	 */
	private static function mutate_at_path( array $blocks, array $path, callable $mutator ) {
		if ( empty( $path ) ) {
			return new \WP_Error( 'invalid_path', 'Empty path supplied to mutate_at_path.', [ 'status' => 400 ] );
		}

		return self::mutate_at_path_recursive( $blocks, $path, $mutator );
	}

	/**
	 * Recursive engine for mutate_at_path.
	 *
	 * @param array<int|string,mixed> $blocks  Blocks at the current level.
	 * @param int[]                   $path    Remaining path (head = local index, tail = descent).
	 * @param callable                $mutator Callback: fn(siblings, idx): array|WP_Error.
	 * @return array<int|string,mixed>|\WP_Error
	 */
	private static function mutate_at_path_recursive( array $blocks, array $path, callable $mutator ) {
		$local_idx = array_shift( $path );

		if ( ! isset( $blocks[ $local_idx ] ) ) {
			return new \WP_Error(
				'block_not_found',
				sprintf( 'No block at index %d.', $local_idx ),
				[ 'status' => 404 ]
			);
		}

		if ( empty( $path ) ) {
			// We have reached the target level; invoke the mutator.
			$result = $mutator( $blocks, $local_idx );
			return $result;
		}

		// Descend into innerBlocks.
		$parent = $blocks[ $local_idx ];

		if ( ! is_array( $parent ) ) {
			return new \WP_Error( 'block_not_found', 'Block at path index is not an array.', [ 'status' => 404 ] );
		}

		$inner = isset( $parent['innerBlocks'] ) && is_array( $parent['innerBlocks'] ) ? $parent['innerBlocks'] : [];

		$new_inner = self::mutate_at_path_recursive( $inner, $path, $mutator );

		if ( is_wp_error( $new_inner ) ) {
			return $new_inner;
		}

		// Rebuild the parent with the updated innerBlocks. Also sync innerContent null count.
		$parent['innerBlocks']  = $new_inner;
		$parent['innerContent'] = self::sync_inner_content_nulls( $parent );
		$blocks[ $local_idx ]   = $parent;

		return $blocks;
	}

	/**
	 * Insert a block as a sibling of the target (before or after).
	 *
	 * @param array<int|string,mixed> $blocks   Parsed block tree.
	 * @param int[]                   $dst_path Path to the reference block.
	 * @param array<int|string,mixed> $block    Block to insert.
	 * @param string                  $position 'before' or 'after'.
	 * @return array<int|string,mixed>|\WP_Error
	 */
	private static function insert_sibling( array $blocks, array $dst_path, array $block, string $position ) {
		return self::mutate_at_path(
			$blocks,
			$dst_path,
			static function ( array $siblings, int $idx ) use ( $block, $position ) {
				$insert_at = ( 'before' === $position ) ? $idx : $idx + 1;
				array_splice( $siblings, $insert_at, 0, [ $block ] );
				return array_values( $siblings );
			}
		);
	}

	/**
	 * Adjust a destination path after removing the source block.
	 *
	 * When source and destination share the same parent, removing source shifts
	 * any destination index that is greater than the source index by −1.
	 *
	 * @param int[] $src_path Removed source path.
	 * @param int[] $dst_path Original destination path.
	 * @return int[] Adjusted destination path.
	 */
	private static function adjust_path_after_remove( array $src_path, array $dst_path ): array {
		$src_len = count( $src_path );
		$dst_len = count( $dst_path );

		if ( $src_len !== $dst_len ) {
			// Different depths — only the last element of dst at the shared level may shift.
			if ( $src_len < $dst_len ) {
				// src is ancestor of dst? Would have been caught by cycle check, but let's be safe.
				return $dst_path;
			}

			// src is deeper; shared prefix check.
			$shared_depth = $dst_len - 1;
			$src_parent   = array_slice( $src_path, 0, $shared_depth );
			$dst_parent   = array_slice( $dst_path, 0, $shared_depth );

			if ( $src_parent === $dst_parent && $src_path[ $shared_depth ] < $dst_path[ $shared_depth ] ) {
				$adjusted                   = $dst_path;
				$adjusted[ $shared_depth ] -= 1;
				return $adjusted;
			}

			return $dst_path;
		}

		// Same depth — check if they share a parent (all but last index matches).
		$src_parent = array_slice( $src_path, 0, -1 );
		$dst_parent = array_slice( $dst_path, 0, -1 );

		if ( $src_parent !== $dst_parent ) {
			return $dst_path;
		}

		$src_local = end( $src_path );
		$dst_local = end( $dst_path );

		if ( $src_local < $dst_local ) {
			$adjusted                            = $dst_path;
			$adjusted[ count( $adjusted ) - 1 ] -= 1;
			return $adjusted;
		}

		return $dst_path;
	}

	// ── Block normalization helpers ───────────────────────────────────────

	/**
	 * Ensure a block definition has the required keys.
	 *
	 * @param array<string,mixed> $block Raw block array (may be user-supplied).
	 * @return array<string,mixed> Normalized block.
	 */
	private static function normalize_block( array $block ): array {
		$name  = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
		$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
		$html  = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';
		$icont = isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : [];

		// Normalize innerBlocks recursively.
		$normalized_inner = [];

		foreach ( $inner as $ib ) {
			if ( is_array( $ib ) ) {
				$normalized_inner[] = self::normalize_block( $ib );
			}
		}

		// Build innerContent: if none supplied, create nulls matching innerBlocks count.
		if ( empty( $icont ) && ! empty( $normalized_inner ) ) {
			$icont = array_fill( 0, count( $normalized_inner ), null );
		}

		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $normalized_inner,
			'innerHTML'    => $html,
			'innerContent' => $icont,
		];
	}

	/**
	 * Recursively apply wp_kses_post to innerHTML of a block and its descendants.
	 *
	 * @param array<string,mixed> $block Block array.
	 * @return array<string,mixed> Block with sanitized HTML.
	 */
	private static function sanitize_block_tree( array $block ): array {
		if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$block['innerHTML'] = wp_kses_post( $block['innerHTML'] );
		}

		if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			foreach ( $block['innerContent'] as &$chunk ) {
				if ( is_string( $chunk ) ) {
					$chunk = wp_kses_post( $chunk );
				}
			}

			unset( $chunk );
		}

		if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$sanitized = [];

			foreach ( $block['innerBlocks'] as $ib ) {
				if ( is_array( $ib ) ) {
					$sanitized[] = self::sanitize_block_tree( $ib );
				}
			}

			$block['innerBlocks'] = $sanitized;
		}

		return $block;
	}

	/**
	 * Strip sd_ref values from a block and its descendants (used for duplicates).
	 *
	 * @param array<string,mixed> $block Block array.
	 * @return array<string,mixed> Block without sd_ref.
	 */
	private static function strip_refs( array $block ): array {
		if ( isset( $block['attrs']['metadata'][ BlockReferences::REF_KEY ] ) ) {
			unset( $block['attrs']['metadata'][ BlockReferences::REF_KEY ] );
		}

		if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$stripped = [];

			foreach ( $block['innerBlocks'] as $ib ) {
				if ( is_array( $ib ) ) {
					$stripped[] = self::strip_refs( $ib );
				}
			}

			$block['innerBlocks'] = $stripped;
		}

		return $block;
	}

	// ── innerContent helpers ──────────────────────────────────────────────

	/**
	 * Synchronize the null count in innerContent to match the innerBlocks count.
	 *
	 * When an operation adds/removes innerBlocks at a parent level (e.g., unwrap),
	 * the intermediate mutate_at_path_recursive re-syncs the parent's innerContent
	 * so that serialize_blocks() sees the correct number of null placeholders.
	 *
	 * Strategy:
	 * - Count nulls in existing innerContent.
	 * - If count matches innerBlocks length: return as-is.
	 * - If too many: strip trailing nulls down to match.
	 * - If too few: append nulls.
	 *
	 * @param array<int|string,mixed> $block Block array.
	 * @return array<mixed> Updated innerContent.
	 */
	private static function sync_inner_content_nulls( array $block ): array {
		$inner   = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
		$icont   = isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : [];
		$needed  = count( $inner );
		$current = count( array_filter( $icont, 'is_null' ) );

		if ( $current === $needed ) {
			return $icont;
		}

		if ( $current > $needed ) {
			// Remove excess trailing null slots.
			$excess  = $current - $needed;
			$icont   = array_reverse( $icont );
			$removed = 0;

			foreach ( $icont as $i => $chunk ) {
				if ( null === $chunk && $removed < $excess ) {
					unset( $icont[ $i ] );
					++$removed;
				}
			}

			$icont = array_values( array_reverse( $icont ) );
		} else {
			// Append missing null slots.
			$missing = $needed - $current;
			for ( $i = 0; $i < $missing; $i++ ) {
				$icont[] = null;
			}
		}

		return $icont;
	}

	/**
	 * Rebuild innerContent for an update-html call where innerBlocks exist.
	 *
	 * Splits the new HTML around the null placeholders, keeping null count intact.
	 * Simple approach: replace all string chunks with empty strings, keeping nulls.
	 *
	 * @param array<string,mixed> $block   Original block.
	 * @param string              $new_html New innerHTML.
	 * @return array<mixed> Rebuilt innerContent.
	 */
	private static function rebuild_inner_content_with_html( array $block, string $new_html ): array {
		$icont = isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : [];

		if ( empty( $icont ) ) {
			return [ $new_html ];
		}

		// Preserve null slots (innerBlock placeholders) and clear literal HTML chunks.
		// The new innerHTML is placed before the first null or at the start.
		$rebuilt     = [];
		$html_placed = false;

		foreach ( $icont as $chunk ) {
			if ( null === $chunk ) {
				if ( ! $html_placed ) {
					// Place the outer HTML before the first block slot.
					$rebuilt[]   = $new_html;
					$html_placed = true;
				}

				$rebuilt[] = null;
			}
			// Skip string chunks; they are replaced by new_html or cleared.
		}

		if ( ! $html_placed ) {
			$rebuilt[] = $new_html;
		}

		return $rebuilt;
	}
}
