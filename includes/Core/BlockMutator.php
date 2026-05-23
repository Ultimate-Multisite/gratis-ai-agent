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
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1713
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
	 * Maximum block nesting depth.
	 *
	 * Trees deeper than this return a `block_depth_exceeded` WP_Error.
	 * Matches the upstream limit from gk-block-api (32 levels is well above
	 * any editor-produced tree and below PHP stack-overflow risk).
	 *
	 * Declared public so BlockReferences and other callers can reference
	 * `BlockMutator::MAX_BLOCK_DEPTH` instead of hard-coding the value.
	 *
	 * @var int
	 */
	public const MAX_BLOCK_DEPTH = 32;

	/**
	 * Maximum number of updates in a single batch call.
	 *
	 * Declared public so BlockAbilities and future callers can reference
	 * `BlockMutator::MAX_BATCH_SIZE` instead of hard-coding the value.
	 *
	 * @var int
	 */
	public const MAX_BATCH_SIZE = 50;

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

	/**
	 * Apply a batch of mutation operations atomically.
	 *
	 * All-or-nothing semantics: every update is validated against an
	 * in-memory clone of the block tree. If any single update fails
	 * (invalid op, stale ref, out-of-range index, duplicate target, or
	 * op-specific validation), the entire batch is rejected with per-item
	 * errors in `data.errors[]`. Nothing hits disk.
	 *
	 * On full success, returns the mutated block tree after all operations
	 * have been applied sequentially.
	 *
	 * @param array<int,mixed> $blocks  Parsed block tree (parse_blocks() output).
	 * @param array<int,mixed> $updates Array of update specs. Each must include
	 *                                  'op' (string) and a block address (ref,
	 *                                  path, or flat_index), plus op-specific args.
	 * @return array<int|string,mixed>|\WP_Error Mutated block tree on success, or
	 *                                           WP_Error with code 'empty_batch',
	 *                                           'batch_too_large', or
	 *                                           'batch_validation_failed'.
	 */
	public static function apply_batch( array $blocks, array $updates ) {
		// ── Guard: empty batch ──────────────────────────────────────────
		if ( empty( $updates ) ) {
			return new \WP_Error(
				'empty_batch',
				'updates array must not be empty.',
				[ 'status' => 400 ]
			);
		}

		// ── Guard: size cap ─────────────────────────────────────────────
		if ( count( $updates ) > self::MAX_BATCH_SIZE ) {
			return new \WP_Error(
				'batch_too_large',
				sprintf(
					'Batch contains %d updates; maximum is %d.',
					count( $updates ),
					self::MAX_BATCH_SIZE
				),
				[
					'status'         => 400,
					'max_batch_size' => self::MAX_BATCH_SIZE,
				]
			);
		}

		// ── Phase 1: pre-flight — resolve addresses, detect duplicates ──
		$errors         = [];
		$resolved_paths = [];

		foreach ( $updates as $idx => $update ) {
			if ( ! is_array( $update ) ) {
				$errors[] = [
					'index'   => $idx,
					'code'    => 'invalid_update',
					'message' => 'Each update must be an object/array.',
				];
				continue;
			}

			$op = isset( $update['op'] ) && is_string( $update['op'] ) ? $update['op'] : '';

			// Validate op name.
			if ( ! in_array( $op, self::VALID_OPS, true ) ) {
				$errors[] = [
					'index'   => $idx,
					'code'    => 'invalid_op',
					'message' => sprintf(
						"Unknown operation '%s'. Valid ops: %s.",
						$op,
						implode( ', ', self::VALID_OPS )
					),
				];
				continue;
			}

			// Resolve target address against the ORIGINAL tree.
			$path = BlockTreeAddress::resolve( $blocks, $update );
			if ( is_wp_error( $path ) ) {
				$errors[] = [
					'index'   => $idx,
					'code'    => $path->get_error_code(),
					'message' => $path->get_error_message(),
				];
				continue;
			}

			// Duplicate target detection: two ops on the same resolved path.
			$path_key = implode( ',', $path );
			if ( isset( $resolved_paths[ $path_key ] ) ) {
				$errors[] = [
					'index'   => $idx,
					'code'    => 'duplicate_target',
					'message' => sprintf(
						'Block at path [%s] is already targeted by update %d. Last-write-wins is rejected; split into separate calls.',
						$path_key,
						$resolved_paths[ $path_key ]
					),
				];
				continue;
			}

			$resolved_paths[ $path_key ] = $idx;
		}

		if ( ! empty( $errors ) ) {
			return new \WP_Error(
				'batch_validation_failed',
				'One or more updates failed pre-flight validation.',
				[
					'status' => 400,
					'errors' => $errors,
				]
			);
		}

		// ── Phase 2: apply on a deep clone ──────────────────────────────
		$json = wp_json_encode( $blocks );

		if ( false === $json ) {
			return new \WP_Error(
				'batch_clone_failed',
				'Could not clone the block tree (JSON encode failed).',
				[ 'status' => 500 ]
			);
		}

		$working_tree = json_decode( $json, true );

		if ( ! is_array( $working_tree ) ) {
			return new \WP_Error(
				'batch_clone_failed',
				'Could not clone the block tree (JSON decode failed).',
				[ 'status' => 500 ]
			);
		}

		// Ensure integer keys so apply() receives array<int, mixed>.
		$working_tree = array_values( $working_tree );

		foreach ( $updates as $idx => $update ) {
			// Phase 1 already validated that each $update is an array.
			$update_arr = is_array( $update ) ? $update : [];
			$op         = (string) ( $update_arr['op'] ?? '' );
			$args       = $update_arr;
			unset( $args['op'] );

			$result = self::apply( $working_tree, $op, $args );

			if ( is_wp_error( $result ) ) {
				$errors[] = [
					'index'   => $idx,
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				];
			} else {
				// Carry forward mutations: subsequent ops see this op's result.
				$working_tree = array_values( $result );
			}
		}

		if ( ! empty( $errors ) ) {
			return new \WP_Error(
				'batch_validation_failed',
				'One or more updates failed during dry-run application.',
				[
					'status' => 400,
					'errors' => $errors,
				]
			);
		}

		return $working_tree;
	}

	// ── Structural validators ─────────────────────────────────────────────

	/**
	 * Validate that a block tree does not exceed MAX_BLOCK_DEPTH nesting levels.
	 *
	 * Walks the tree recursively. Returns true when the depth is within bounds.
	 * Returns a `block_depth_exceeded` WP_Error (HTTP 400) when any branch
	 * exceeds the limit. The WP_Error data array includes `max_depth` so
	 * callers can surface the cap value in REST responses.
	 *
	 * Usage: call with depth=0 (default) on the top-level blocks array.
	 * The same constant (`BlockMutator::MAX_BLOCK_DEPTH`) is used by
	 * BlockReferences so both walkers share a single canonical cap.
	 *
	 * @param array<int|string,mixed> $blocks Parsed block tree at the current level.
	 * @param int                     $depth  Current recursion depth (0 = root level). Internal use only.
	 * @return true|\WP_Error True when depth is within bounds; WP_Error on violation.
	 */
	public static function validate_tree_depth( array $blocks, int $depth = 0 ): true|\WP_Error {
		if ( $depth > self::MAX_BLOCK_DEPTH ) {
			return new \WP_Error(
				'block_depth_exceeded',
				sprintf(
					'Block tree depth exceeded the maximum of %d levels.',
					self::MAX_BLOCK_DEPTH
				),
				[
					'status'    => 400,
					'max_depth' => self::MAX_BLOCK_DEPTH,
				]
			);
		}

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];

			if ( ! empty( $inner ) ) {
				$result = self::validate_tree_depth( $inner, $depth + 1 );

				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		return true;
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

		// Dual-storage guard: blocks that duplicate state across attributes and
		// innerHTML must have both sides updated together to prevent silent corruption.
		$target = BlockTreeAddress::get_block_at_path( $blocks, $path );

		if ( is_array( $target ) ) {
			$block_name = isset( $target['blockName'] ) && is_string( $target['blockName'] ) ? $target['blockName'] : '';

			if ( '' !== $block_name && DualStorageRegistry::is_dual_storage( $block_name ) ) {
				if ( ! isset( $args['innerHTML'] ) || ! is_string( $args['innerHTML'] ) ) {
					return new \WP_Error(
						'dual_storage_requires_both',
						sprintf(
							"'%s' stores data in both attributes and innerHTML. Supply both 'attributes' and 'innerHTML' in a single update to avoid silent corruption.",
							$block_name
						),
						[
							'status'     => 400,
							'block_name' => $block_name,
						]
					);
				}
			}
		}

		$merge = isset( $args['merge'] ) ? (bool) $args['merge'] : true;

		// When innerHTML is also supplied (required for dual-storage blocks),
		// sanitize it now so the closure captures the safe value.
		$safe_html = isset( $args['innerHTML'] ) && is_string( $args['innerHTML'] )
			? wp_kses_post( $args['innerHTML'] )
			: null;

		return self::mutate_at_path(
			$blocks,
			$path,
			static function ( array $siblings, int $idx ) use ( $args, $merge, $safe_html ) {
				$block = $siblings[ $idx ];

				if ( ! is_array( $block ) ) {
					return $siblings;
				}

				$existing = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];

				if ( $merge ) {
					$block['attrs'] = array_merge( $existing, $args['attributes'] );
				} else {
					$block['attrs'] = $args['attributes'];
				}

				// Apply the HTML side when explicitly supplied (dual-storage blocks require both).
				// When the caller provides innerHTML directly, they own both sides — skip HtmlTransformer.
				if ( null !== $safe_html ) {
					$block['innerHTML'] = $safe_html;
					$inner_blocks       = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];

					if ( empty( $inner_blocks ) ) {
						$block['innerContent'] = [ $safe_html ];
					} else {
						$block['innerContent'] = self::rebuild_inner_content_with_html( $block, $safe_html );
					}
				} elseif ( HtmlTransformer::is_supported( isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '' ) ) {
					// Auto-transform innerHTML when attribute changes imply HTML structure changes.
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

		// Dual-storage guard: blocks that duplicate state across attributes and
		// innerHTML must have both sides updated together to prevent silent corruption.
		$target = BlockTreeAddress::get_block_at_path( $blocks, $path );

		if ( is_array( $target ) ) {
			$block_name = isset( $target['blockName'] ) && is_string( $target['blockName'] ) ? $target['blockName'] : '';

			if ( '' !== $block_name && DualStorageRegistry::is_dual_storage( $block_name ) ) {
				if ( ! isset( $args['attributes'] ) || ! is_array( $args['attributes'] ) ) {
					return new \WP_Error(
						'dual_storage_requires_both',
						sprintf(
							"'%s' stores data in both attributes and innerHTML. Supply both 'attributes' and 'innerHTML' in a single update to avoid silent corruption.",
							$block_name
						),
						[
							'status'     => 400,
							'block_name' => $block_name,
						]
					);
				}
			}
		}

		$safe_html = wp_kses_post( $args['innerHTML'] );

		// When attributes are also supplied (required for dual-storage blocks),
		// capture the merge flag and attrs for the closure.
		$extra_attrs = isset( $args['attributes'] ) && is_array( $args['attributes'] ) ? $args['attributes'] : null;
		$merge       = isset( $args['merge'] ) ? (bool) $args['merge'] : true;

		return self::mutate_at_path(
			$blocks,
			$path,
			static function ( array $siblings, int $idx ) use ( $safe_html, $extra_attrs, $merge ) {
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

				// Apply the attributes side when supplied (dual-storage blocks require both).
				if ( null !== $extra_attrs ) {
					$existing       = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
					$block['attrs'] = $merge ? array_merge( $existing, $extra_attrs ) : $extra_attrs;
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
