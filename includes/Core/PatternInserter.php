<?php

declare(strict_types=1);
/**
 * Pattern inserter — resolve and prepare pattern blocks for insertion.
 *
 * Handles both registered patterns (inline expansion) and synced patterns
 * (wp_block reference insertion). Provides a clean API for the
 * `sd-ai-agent/insert-pattern` ability.
 *
 * Registered patterns: expanded server-side via WP_Block_Patterns_Registry,
 * parsed into block trees, and inlined at the anchor position.
 *
 * Synced patterns: inserted as a single `core/block {"ref": N}` reference
 * block. The editor renders them transcluded — content is NOT inlined.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1748
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless pattern insertion helper.
 *
 * All methods are static. No per-instance state.
 */
class PatternInserter {

	/**
	 * Maximum number of nearest-match suggestions returned on pattern_not_found.
	 *
	 * @var int
	 */
	const MAX_SUGGESTIONS = 10;

	// ── Public API ────────────────────────────────────────────────────────

	/**
	 * Parse a pattern identifier and determine whether it is synced or registered.
	 *
	 * Accepts:
	 *   - Numeric int/string (42, "42")  → synced wp_block ID 42.
	 *   - "wp-block:42"                  → synced wp_block ID 42.
	 *   - "synced:42"                    → synced wp_block ID 42.
	 *   - String slug ("core/quote")     → registered pattern name.
	 *
	 * @param int|string $pattern Raw pattern identifier from ability input.
	 * @return array{type: 'synced'|'registered', id: int|string}|\WP_Error
	 *         Parsed identifier, or WP_Error with code `bad_pattern_id`.
	 */
	public static function parse_pattern_id( int|string $pattern ): array|\WP_Error {
		// Numeric int → synced.
		if ( is_int( $pattern ) ) {
			if ( $pattern <= 0 ) {
				return new \WP_Error(
					'bad_pattern_id',
					'Pattern ID must be a positive integer for synced patterns.',
					[ 'status' => 400 ]
				);
			}
			return [
				'type' => 'synced',
				'id'   => $pattern,
			];
		}

		// Numeric string → synced.
		if ( is_numeric( $pattern ) ) {
			$id = (int) $pattern;
			if ( $id <= 0 ) {
				return new \WP_Error(
					'bad_pattern_id',
					'Pattern ID must be a positive integer for synced patterns.',
					[ 'status' => 400 ]
				);
			}
			return [
				'type' => 'synced',
				'id'   => $id,
			];
		}

		// "wp-block:N" prefix → synced.
		if ( str_starts_with( $pattern, 'wp-block:' ) ) {
			$id_str = substr( $pattern, 9 );
			if ( ! is_numeric( $id_str ) || (int) $id_str <= 0 ) {
				return new \WP_Error(
					'bad_pattern_id',
					'wp-block: prefix requires a positive integer ID (e.g. "wp-block:42").',
					[ 'status' => 400 ]
				);
			}
			return [
				'type' => 'synced',
				'id'   => (int) $id_str,
			];
		}

		// "synced:N" prefix → synced.
		if ( str_starts_with( $pattern, 'synced:' ) ) {
			$id_str = substr( $pattern, 7 );
			if ( ! is_numeric( $id_str ) || (int) $id_str <= 0 ) {
				return new \WP_Error(
					'bad_pattern_id',
					'synced: prefix requires a positive integer ID (e.g. "synced:42").',
					[ 'status' => 400 ]
				);
			}
			return [
				'type' => 'synced',
				'id'   => (int) $id_str,
			];
		}

		// Non-numeric string → registered pattern slug.
		if ( '' === $pattern ) {
			return new \WP_Error(
				'bad_pattern_id',
				'Pattern identifier must not be empty.',
				[ 'status' => 400 ]
			);
		}

		return [
			'type' => 'registered',
			'id'   => $pattern,
		];
	}

	/**
	 * Validate that a pattern exists (either registered or synced).
	 *
	 * On failure, returns a `pattern_not_found` WP_Error with up to
	 * MAX_SUGGESTIONS nearest-match suggestions in `data.suggestions`.
	 *
	 * @param string     $type 'synced' or 'registered'.
	 * @param int|string $id Pattern post ID (synced) or slug (registered).
	 * @return true|\WP_Error True if exists, WP_Error otherwise.
	 */
	public static function validate_pattern_exists( string $type, int|string $id ): true|\WP_Error {
		if ( 'synced' === $type ) {
			$post = get_post( (int) $id );

			if ( ! $post || 'wp_block' !== $post->post_type ) {
				return new \WP_Error(
					'pattern_not_found',
					sprintf(
						'Synced pattern (wp_block) with ID %d not found.',
						(int) $id
					),
					[
						'status'      => 404,
						'suggestions' => self::get_synced_suggestions(),
					]
				);
			}

			if ( 'publish' !== $post->post_status ) {
				return new \WP_Error(
					'pattern_not_found',
					sprintf(
						'Synced pattern %d exists but is not published (status: %s).',
						(int) $id,
						$post->post_status
					),
					[
						'status'      => 404,
						'suggestions' => self::get_synced_suggestions(),
					]
				);
			}

			return true;
		}

		// Registered pattern.
		if ( ! class_exists( '\WP_Block_Patterns_Registry' ) ) {
			return new \WP_Error(
				'pattern_not_found',
				'Block patterns registry is not available.',
				[ 'status' => 404 ]
			);
		}

		$registry = \WP_Block_Patterns_Registry::get_instance();

		if ( ! $registry->is_registered( (string) $id ) ) {
			return new \WP_Error(
				'pattern_not_found',
				sprintf(
					'Registered pattern "%s" not found.',
					(string) $id
				),
				[
					'status'      => 404,
					'suggestions' => self::get_registered_suggestions( (string) $id ),
				]
			);
		}

		return true;
	}

	/**
	 * Expand a registered pattern into a parsed block tree.
	 *
	 * Retrieves the pattern content from WP_Block_Patterns_Registry,
	 * parses it into blocks, and filters out empty freeform nodes.
	 *
	 * @param string $slug Registered pattern name (e.g. "core/quote").
	 * @return array<int,array<string,mixed>>|\WP_Error Parsed block tree or error.
	 */
	public static function expand_registered( string $slug ): array|\WP_Error {
		$registry = \WP_Block_Patterns_Registry::get_instance();
		$pattern  = $registry->get_registered( $slug );

		if ( null === $pattern || false === $pattern ) {
			return new \WP_Error(
				'pattern_not_found',
				sprintf( 'Registered pattern "%s" not found.', $slug ),
				[ 'status' => 404 ]
			);
		}

		$content = $pattern['content'] ?? '';

		if ( '' === $content ) {
			return new \WP_Error(
				'pattern_empty',
				sprintf( 'Registered pattern "%s" has empty content.', $slug ),
				[ 'status' => 422 ]
			);
		}

		$blocks = parse_blocks( $content );

		if ( ! is_array( $blocks ) ) {
			return new \WP_Error(
				'pattern_parse_failed',
				sprintf( 'Failed to parse content for pattern "%s".', $slug ),
				[ 'status' => 500 ]
			);
		}

		// Filter out empty freeform blocks (whitespace between named blocks).
		$filtered = [];
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( empty( $block['blockName'] ) ) {
				$inner = trim( (string) ( $block['innerHTML'] ?? '' ) );
				if ( '' === $inner ) {
					continue;
				}
			}
			$filtered[] = $block;
		}

		if ( empty( $filtered ) ) {
			return new \WP_Error(
				'pattern_empty',
				sprintf( 'Registered pattern "%s" produced no named blocks.', $slug ),
				[ 'status' => 422 ]
			);
		}

		return $filtered;
	}

	/**
	 * Build a synced pattern reference block (core/block { "ref": N }).
	 *
	 * The returned block is a self-closing `core/block` with the wp_block
	 * post ID as its `ref` attribute. The editor renders it transcluded.
	 *
	 * @param int $wp_block_id Post ID of the wp_block (synced pattern).
	 * @return array<string,mixed> Parsed block array ready for insertion.
	 */
	public static function make_synced_ref( int $wp_block_id ): array {
		return [
			'blockName'    => 'core/block',
			'attrs'        => [ 'ref' => $wp_block_id ],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];
	}

	// ── Suggestion helpers ────────────────────────────────────────────────

	/**
	 * Get nearest-match suggestions from the registered pattern registry.
	 *
	 * Computes Levenshtein distance between the query and all registered
	 * pattern names, returning the closest matches.
	 *
	 * @param string $query The pattern name that was not found.
	 * @return string[] Up to MAX_SUGGESTIONS nearest pattern names.
	 */
	private static function get_registered_suggestions( string $query ): array {
		if ( ! class_exists( '\WP_Block_Patterns_Registry' ) ) {
			return [];
		}

		$registry = \WP_Block_Patterns_Registry::get_instance();
		$all      = $registry->get_all_registered();

		if ( empty( $all ) ) {
			return [];
		}

		$scored = [];
		foreach ( $all as $pattern ) {
			$name = $pattern['name'] ?? '';
			if ( '' === $name ) {
				continue;
			}
			$scored[] = [
				'name'     => $name,
				'distance' => levenshtein( strtolower( $query ), strtolower( $name ) ),
			];
		}

		usort( $scored, static fn( array $a, array $b ): int => $a['distance'] <=> $b['distance'] );

		$suggestions = [];
		$count       = min( self::MAX_SUGGESTIONS, count( $scored ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$suggestions[] = (string) $scored[ $i ]['name'];
		}

		return $suggestions;
	}

	/**
	 * Get a list of published synced pattern IDs and titles for suggestions.
	 *
	 * @return string[] Up to MAX_SUGGESTIONS suggestions in "ID: Title" format.
	 */
	private static function get_synced_suggestions(): array {
		$posts = get_posts(
			[
				'post_type'      => 'wp_block',
				'post_status'    => 'publish',
				'posts_per_page' => self::MAX_SUGGESTIONS,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			]
			);

		$suggestions = [];
		foreach ( $posts as $post ) {
			$suggestions[] = sprintf( '%d: %s', $post->ID, $post->post_title );
		}

		return $suggestions;
	}
}
