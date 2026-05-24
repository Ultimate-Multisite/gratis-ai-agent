<?php

declare(strict_types=1);
/**
 * Block storage-mode cataloguer.
 *
 * Walks published posts and classifies each unique block name by how it
 * stores its data:
 *
 * - `attrs_only`      — `attrs` non-empty AND `innerHTML` empty/whitespace.
 * - `inner_html_only` — `attrs` empty AND `innerHTML` non-empty.
 * - `dual`            — both populated; a dual-storage candidate.
 * - `unknown`         — both empty (rare; usually placeholder blocks).
 *
 * The modal storage mode across all occurrences is chosen per block name.
 * Tie-breaks favour `dual` (most conservative).
 *
 * Used by the `sd-ai-agent/scan-storage-modes` ability so an agent can
 * enumerate the safe-vs-dangerous block surface before attempting bulk
 * mutations. Closes a parity gap with block-mcp's `/storage-modes/scan`
 * REST route.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1781
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure block-storage catalogue helper.
 *
 * No WP-API side effects; `scan()` returns an array of items and never
 * writes to the database.  All public methods are static; the class holds
 * no per-instance state.
 */
class BlockStorageScanner {

	/**
	 * Maximum value allowed for the `limit` argument.
	 *
	 * @var int
	 */
	const MAX_LIMIT = 1000;

	/**
	 * Default maximum number of posts to scan.
	 *
	 * @var int
	 */
	const DEFAULT_LIMIT = 200;

	/**
	 * Chunk size for the batched WP_Query walk.
	 *
	 * @var int
	 */
	const SCAN_BATCH_SIZE = 100;

	/**
	 * Ordered list of storage modes from most to least specific.
	 *
	 * Used for tie-break resolution (dual is preferred).
	 *
	 * @var string[]
	 */
	const MODE_PRIORITY = [ 'dual', 'inner_html_only', 'attrs_only', 'unknown' ];

	// ── Public API ────────────────────────────────────────────────────────

	/**
	 * Scan published posts and classify each unique block name by storage mode.
	 *
	 * @param array<string,mixed> $args {
	 *     Optional scan arguments.
	 *
	 *     @type string[] $post_status           Post statuses to include. Default ['publish'].
	 *     @type string[] $post_types            Post types to include. Default all public types.
	 *     @type int      $limit                 Maximum posts to scan. Default 200, max 1000.
	 *     @type bool     $include_registry_known Include blocks already in DualStorageRegistry.
	 *                                            Default false.
	 * }
	 * @return array<string,mixed>|\WP_Error {
	 *     @type int                    $posts_scanned  Number of posts actually walked.
	 *     @type int                    $unique_blocks  Count of distinct block names in items.
	 *     @type array<int,array>       $items          Per-block classification rows.
	 *     @type bool                   $truncated      True when limit was reached.
	 * }
	 */
	public static function scan( array $args = [] ) {
		$post_statuses          = isset( $args['post_status'] ) && is_array( $args['post_status'] )
			? array_map( static fn( $v ): string => (string) $v, $args['post_status'] )
			: [ 'publish' ];
		$post_types             = isset( $args['post_types'] ) && is_array( $args['post_types'] )
			? array_map( static fn( $v ): string => (string) $v, $args['post_types'] )
			: array_values( get_post_types( [ 'public' => true ], 'names' ) );
		$include_registry_known = ! empty( $args['include_registry_known'] );

		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : self::DEFAULT_LIMIT;
		if ( $limit > self::MAX_LIMIT ) {
			return new \WP_Error(
				'limit_too_large',
				sprintf(
					/* translators: %d: maximum allowed limit */
					__( 'limit must not exceed %d.', 'superdav-ai-agent' ),
					self::MAX_LIMIT
				)
			);
		}
		$limit = max( 1, $limit );

		// known blocks to optionally exclude from items.
		$registry_known = DualStorageRegistry::get_blocks();

		/*
		 * Per-block tally: block_name => [ mode => count, ... ].
		 * Built in two passes:
		 *   1. Walk posts, classify every block instance, accumulate per-mode counts.
		 *   2. After the walk, resolve each block name to its modal mode.
		 */
		/** @var array<string,array<string,int>> $tally */
		$tally = [];

		/** @var array<string,int> $first_post */
		$first_post = [];

		/** @var array<string,array<string,mixed>> $evidence_map */
		$evidence_map = [];

		$posts_scanned = 0;
		$truncated     = false;
		$paged         = 1;

		// Parsed-block cache: post_id => blocks[].  Prevents double parse_blocks()
		// when the same post content is re-queried across batch boundaries (rare but
		// covers AC7 for memoised tree-walk within a single request).
		/** @var array<int,array<int|string,mixed>> $parse_cache */
		$parse_cache = [];

		do {
			$remaining = $limit - $posts_scanned;
			$per_page  = min( self::SCAN_BATCH_SIZE, $remaining );

			$batch = get_posts(
				[
					'post_type'           => $post_types,
					'post_status'         => $post_statuses,
					'posts_per_page'      => $per_page,
					'paged'               => $paged,
					'fields'              => 'ids',
					'no_found_rows'       => true,
					'orderby'             => 'date',
					'order'               => 'DESC',
					'ignore_sticky_posts' => true,
				]
			);

			$batch_count = count( $batch );

			foreach ( $batch as $post_id ) {
				$post_id = (int) $post_id;

				if ( isset( $parse_cache[ $post_id ] ) ) {
					$blocks = $parse_cache[ $post_id ];
				} else {
					$raw = get_post_field( 'post_content', $post_id, 'raw' );
					if ( ! is_string( $raw ) || '' === $raw || ! has_blocks( $raw ) ) {
						continue;
					}
					$parsed = parse_blocks( $raw );
					if ( ! is_array( $parsed ) ) {
						continue;
					}
					$blocks                  = $parsed;
					$parse_cache[ $post_id ] = $blocks;
				}

				self::walk_tree( $blocks, $post_id, $tally, $first_post, $evidence_map );
				++$posts_scanned;
			}

			// Flush per-request object cache to bound memory growth on large sites.
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}

			++$paged;
		} while ( $batch_count >= $per_page && $posts_scanned < $limit );

		// Determine truncation.
		if ( $posts_scanned >= $limit ) {
			$extra     = get_posts(
				[
					'post_type'           => $post_types,
					'post_status'         => $post_statuses,
					'posts_per_page'      => 1,
					'offset'              => $limit,
					'fields'              => 'ids',
					'no_found_rows'       => true,
					'orderby'             => 'date',
					'order'               => 'DESC',
					'ignore_sticky_posts' => true,
				]
			);
			$truncated = ! empty( $extra );
		}

		// Build the items list from the tally.
		$items = [];
		foreach ( $tally as $block_name => $mode_counts ) {
			// Optionally skip blocks already known to the DualStorageRegistry.
			if ( ! $include_registry_known && in_array( $block_name, $registry_known, true ) ) {
				continue;
			}

			$storage_mode = self::modal_mode( $mode_counts );
			$total        = array_sum( $mode_counts );
			$in_registry  = in_array( $block_name, $registry_known, true );

			$evidence = $evidence_map[ $block_name ] ?? [
				'attr_keys'        => [],
				'inner_html_chars' => 0,
			];

			$items[] = [
				'block_name'    => $block_name,
				'storage_mode'  => $storage_mode,
				'in_registry'   => $in_registry,
				'occurrences'   => $total,
				'first_post_id' => $first_post[ $block_name ] ?? 0,
				'evidence'      => $evidence,
			];
		}

		// Sort by occurrences descending for convenience.
		usort(
			$items,
			static function ( array $a, array $b ): int {
				return $b['occurrences'] <=> $a['occurrences'];
			}
		);

		return [
			'posts_scanned' => $posts_scanned,
			'unique_blocks' => count( $items ),
			'items'         => $items,
			'truncated'     => $truncated,
		];
	}

	// ── Private helpers ───────────────────────────────────────────────────

	/**
	 * Recursively walk a parsed block tree and accumulate per-block-name tallies.
	 *
	 * @param array<int|string,mixed>           $blocks       parse_blocks() output.
	 * @param int                               $post_id      Source post ID (for first_post_id).
	 * @param array<string,array<string,int>>   &$tally       Accumulated mode counts per name.
	 * @param array<string,int>                 &$first_post  First post_id per block name.
	 * @param array<string,array<string,mixed>> &$evidence    Accumulated evidence per name.
	 * @return void
	 */
	private static function walk_tree(
		array $blocks,
		int $post_id,
		array &$tally,
		array &$first_post,
		array &$evidence
	): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = isset( $block['blockName'] ) && is_string( $block['blockName'] )
				? $block['blockName']
				: '';

			if ( '' === $name ) {
				// Freeform / null block — still recurse.
				if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					self::walk_tree( $block['innerBlocks'], $post_id, $tally, $first_post, $evidence );
				}
				continue;
			}

			$attrs      = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
			$inner_html = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] )
				? $block['innerHTML']
				: '';

			$mode = self::classify( $attrs, $inner_html );

			// Accumulate mode counts.
			if ( ! isset( $tally[ $name ] ) ) {
				$tally[ $name ] = [];
			}
			$tally[ $name ][ $mode ] = ( $tally[ $name ][ $mode ] ?? 0 ) + 1;

			// Record first post ID.
			if ( ! isset( $first_post[ $name ] ) ) {
				$first_post[ $name ] = $post_id;
			}

			// Accumulate evidence (merge attr_keys; max inner_html_chars).
			if ( ! isset( $evidence[ $name ] ) ) {
				$evidence[ $name ] = [
					'attr_keys'        => [],
					'inner_html_chars' => 0,
				];
			}
			/** @var string[] $existing_keys */
			$existing_keys = $evidence[ $name ]['attr_keys'];
			/** @var string[] $new_keys */
			$new_keys                              = array_keys( $attrs );
			$merged_keys                           = array_values( array_unique( array_merge( $existing_keys, $new_keys ) ) );
			$evidence[ $name ]['attr_keys']        = $merged_keys;
			$evidence[ $name ]['inner_html_chars'] = max(
				$evidence[ $name ]['inner_html_chars'],
				strlen( $inner_html )
			);

			// Recurse into innerBlocks.
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::walk_tree( $block['innerBlocks'], $post_id, $tally, $first_post, $evidence );
			}
		}
	}

	/**
	 * Classify a single block instance by storage mode.
	 *
	 * @param array<string,mixed> $attrs      Block attributes.
	 * @param string              $inner_html Block innerHTML.
	 * @return string One of 'attrs_only', 'inner_html_only', 'dual', or 'unknown'.
	 */
	private static function classify( array $attrs, string $inner_html ): string {
		$has_attrs = ! empty( $attrs );
		$has_html  = '' !== trim( $inner_html );

		if ( $has_attrs && $has_html ) {
			return 'dual';
		}
		if ( $has_attrs ) {
			return 'attrs_only';
		}
		if ( $has_html ) {
			return 'inner_html_only';
		}
		return 'unknown';
	}

	/**
	 * Return the modal storage mode across all observed occurrences.
	 *
	 * Tie-break: when two modes have equal counts, the mode with higher
	 * priority in MODE_PRIORITY wins ('dual' is the most conservative).
	 *
	 * @param array<string,int> $mode_counts Mode => count map.
	 * @return string Modal storage mode string.
	 */
	private static function modal_mode( array $mode_counts ): string {
		if ( empty( $mode_counts ) ) {
			return 'unknown';
		}

		$best_mode  = 'unknown';
		$best_count = -1;
		$best_prio  = count( self::MODE_PRIORITY ); // higher index = lower priority.

		foreach ( $mode_counts as $mode => $count ) {
			$prio = array_search( $mode, self::MODE_PRIORITY, true );
			if ( false === $prio ) {
				$prio = count( self::MODE_PRIORITY );
			}

			// Prefer higher count; on tie, prefer lower priority index (= more conservative mode).
			if ( $count > $best_count || ( $count === $best_count && $prio < $best_prio ) ) {
				$best_mode  = $mode;
				$best_count = $count;
				$best_prio  = (int) $prio;
			}
		}

		return $best_mode;
	}
}
