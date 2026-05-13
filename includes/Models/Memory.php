<?php

declare(strict_types=1);
/**
 * Memory system — persistent storage for agent knowledge.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Models;

use SdAiAgent\Models\DTO\MemoryRow;

class Memory {

	/**
	 * Valid memory categories.
	 *
	 * `site_brief` is a dedicated category for the structured site
	 * specification captured by the `site-specification` skill: site
	 * type, audience, tone, brand keywords, sections, typography. It is
	 * kept separate from `site_info` so future sessions can re-load the
	 * full brief without scanning every site_info memory.
	 */
	const CATEGORIES = [ 'site_info', 'site_brief', 'user_preferences', 'technical_notes', 'workflows', 'general' ];

	/**
	 * Get the memories table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_memories';
	}

	/**
	 * Get all memories, optionally filtered by category.
	 *
	 * @param string|null $category Optional category filter.
	 * @return list<MemoryRow>|null
	 */
	public static function get_all( ?string $category = null ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = self::table_name();

		if ( $category ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE category = %s ORDER BY updated_at DESC',
					$table,
					$category
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i ORDER BY category ASC, updated_at DESC',
					$table
				)
			);
		}

		if ( null === $rows ) {
			return null;
		}

		return array_map( [ MemoryRow::class, 'from_row' ], $rows );
	}

	/**
	 * Get memories by category.
	 *
	 * @param string $category Category name.
	 * @return list<MemoryRow>|null
	 */
	public static function get_by_category( string $category ): ?array {
		return self::get_all( $category );
	}

	/**
	 * Create a new memory.
	 *
	 * @param string $category Memory category.
	 * @param string $content  Memory content.
	 * @return int|false Inserted row ID or false.
	 */
	public static function create( string $category, string $content ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( ! in_array( $category, self::CATEGORIES, true ) ) {
			$category = 'general';
		}

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query; caching not applicable.
		$result = $wpdb->insert(
			self::table_name(),
			[
				'category'   => $category,
				'content'    => $content,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%s', '%s', '%s', '%s' ]
		);

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update an existing memory.
	 *
	 * @param int                  $id   Memory ID.
	 * @param array<string, mixed> $data Fields to update (category, content).
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$allowed = [ 'category', 'content' ];
		$data    = array_intersect_key( $data, array_flip( $allowed ) );

		if ( isset( $data['category'] ) && ! in_array( $data['category'], self::CATEGORIES, true ) ) {
			$data['category'] = 'general';
		}

		$data['updated_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->update(
			self::table_name(),
			$data,
			[ 'id' => $id ],
			array_fill( 0, count( $data ), '%s' ),
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Delete a memory by ID.
	 *
	 * @param int $id Memory ID.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->delete(
			self::table_name(),
			[ 'id' => $id ],
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Search memories using FULLTEXT.
	 *
	 * @param string $query Search query.
	 * @param int    $limit Max results.
	 * @return list<MemoryRow>
	 */
	public static function search( string $query, int $limit = 20 ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = self::table_name();

		// Build boolean mode search terms.
		$words         = preg_split( '/\s+/', trim( $query ) );
		$boolean_terms = [];
		foreach ( $words ?: [] as $word ) {
			$word = (string) preg_replace( '/[^\w]/', '', $word );
			if ( strlen( $word ) > 1 ) {
				$boolean_terms[] = '+' . $word . '*';
			}
		}

		if ( empty( $boolean_terms ) ) {
			return [];
		}

		$search_expr = implode( ' ', $boolean_terms );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT *, MATCH(content) AGAINST(%s IN BOOLEAN MODE) AS relevance
				FROM %i
				WHERE MATCH(content) AGAINST(%s IN BOOLEAN MODE)
				ORDER BY relevance DESC
				LIMIT %d',
				$search_expr,
				$table,
				$search_expr,
				$limit
			)
		);

		return array_map( [ MemoryRow::class, 'from_row' ], $results ?: [] );
	}

	/**
	 * Delete memories matching a topic (FULLTEXT search + delete).
	 *
	 * @param string $topic The topic to forget.
	 * @return int Number of memories deleted.
	 */
	public static function forget_by_topic( string $topic ): int {
		$matches = self::search( $topic, 50 );

		if ( empty( $matches ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $matches as $memory ) {
			if ( self::delete( $memory->id ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Get memories formatted for inclusion in a system prompt.
	 *
	 * Groups by category. Applies a ~2000-token soft cap by truncating
	 * the oldest entries first when the total exceeds the limit.
	 *
	 * @return string Formatted memory string.
	 */
	public static function get_formatted_for_prompt(): string {
		$all = self::get_all();

		if ( empty( $all ) ) {
			return '';
		}

		// Group by category.
		$grouped = [];
		foreach ( $all as $memory ) {
			$grouped[ $memory->category ][] = $memory->content;
		}

		// Build output per category.
		$sections   = [];
		$word_count = 0;
		$soft_cap   = 1540; // ~2000 tokens at 1.3 words/token

		foreach ( self::CATEGORIES as $category ) {
			if ( empty( $grouped[ $category ] ) ) {
				continue;
			}

			$label   = ucwords( str_replace( '_', ' ', $category ) );
			$entries = $grouped[ $category ];
			$lines   = [];

			foreach ( $entries as $entry ) {
				$entry_words = str_word_count( $entry );

				if ( $word_count + $entry_words > $soft_cap ) {
					break 2; // Stop adding entries across all categories.
				}

				$lines[]     = '- ' . $entry;
				$word_count += $entry_words;
			}

			if ( ! empty( $lines ) ) {
				$sections[] = "### $label\n" . implode( "\n", $lines );
			}
		}

		if ( empty( $sections ) ) {
			return '';
		}

		return "## Your Memory\n\n" . implode( "\n\n", $sections );
	}
}
