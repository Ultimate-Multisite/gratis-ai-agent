<?php

declare(strict_types=1);
/**
 * Knowledge base database operations.
 *
 * Manages three tables: collections, sources, chunks.
 * Provides static CRUD methods and FULLTEXT search.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Knowledge;

class KnowledgeDatabase {

	/**
	 * Get the collections table name.
	 */
	public static function collections_table(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_knowledge_collections';
	}

	/**
	 * Get the sources table name.
	 */
	public static function sources_table(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_knowledge_sources';
	}

	/**
	 * Get the chunks table name.
	 */
	public static function chunks_table(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_knowledge_chunks';
	}

	/**
	 * Get the SQL for creating all knowledge tables.
	 *
	 * @param string $charset The charset collation string.
	 * @return string SQL statements for dbDelta.
	 */
	public static function get_schema( string $charset ): string {
		$collections = self::collections_table();
		$sources     = self::sources_table();
		$chunks      = self::chunks_table();

		return "CREATE TABLE {$collections} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			slug varchar(255) NOT NULL,
			description text NOT NULL DEFAULT '',
			auto_index tinyint(1) NOT NULL DEFAULT 0,
			source_config longtext NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			chunk_count int(10) unsigned NOT NULL DEFAULT 0,
			last_indexed_at datetime NULL DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset};

		CREATE TABLE {$sources} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			collection_id bigint(20) unsigned NOT NULL,
			source_type varchar(50) NOT NULL,
			source_id bigint(20) unsigned NULL DEFAULT NULL,
			source_url varchar(2048) NULL DEFAULT NULL,
			title varchar(500) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			chunk_count int(10) unsigned NOT NULL DEFAULT 0,
			content_hash char(32) NULL DEFAULT NULL,
			error_message text NULL DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_collection (collection_id),
			KEY idx_source (source_type, source_id)
		) {$charset};

		CREATE TABLE {$chunks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			collection_id bigint(20) unsigned NOT NULL,
			source_id bigint(20) unsigned NOT NULL,
			chunk_index int(10) unsigned NOT NULL DEFAULT 0,
			chunk_text text NOT NULL,
			metadata longtext NULL DEFAULT NULL,
			embedding varbinary(2048) NULL DEFAULT NULL,
			embedding_binary binary(64) NULL DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_collection (collection_id),
			KEY idx_source (source_id),
			FULLTEXT KEY ft_chunk_text (chunk_text)
		) {$charset};";
	}

	// ── Collections ──────────────────────────────────────────────────────

	/**
	 * Create a knowledge collection.
	 *
	 * @param array<string, mixed> $data Collection data: name, slug, description, auto_index, source_config.
	 * @return int|false Inserted row ID or false on failure.
	 */
	public static function create_collection( array $data ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query; caching not applicable.
		$result = $wpdb->insert(
			self::collections_table(),
			[
				// @phpstan-ignore-next-line
				'name'          => sanitize_text_field( $data['name'] ?? '' ),
				// @phpstan-ignore-next-line
				'slug'          => sanitize_title( $data['slug'] ?? '' ),
				// @phpstan-ignore-next-line
				'description'   => sanitize_textarea_field( $data['description'] ?? '' ),
				'auto_index'    => ! empty( $data['auto_index'] ) ? 1 : 0,
				'source_config' => wp_json_encode( $data['source_config'] ?? [] ),
				'status'        => 'active',
				'created_at'    => $now,
				'updated_at'    => $now,
			],
			[ '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Get a single collection by ID.
	 *
	 * @param int $id Collection ID.
	 * @return object|null
	 */
	public static function get_collection( int $id ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				self::collections_table(),
				$id
			)
		);

		if ( $row && ! empty( $row->source_config ) ) {
			$row->source_config = json_decode( $row->source_config, true ) ?: [];
		}

		return $row;
	}

	/**
	 * Get a single collection by slug.
	 *
	 * @param string $slug Collection slug.
	 * @return object|null
	 */
	public static function get_collection_by_slug( string $slug ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE slug = %s',
				self::collections_table(),
				$slug
			)
		);

		if ( $row && ! empty( $row->source_config ) ) {
			$row->source_config = json_decode( $row->source_config, true ) ?: [];
		}

		return $row;
	}

	/**
	 * Update a collection.
	 *
	 * @param int                  $id   Collection ID.
	 * @param array<string, mixed> $data Fields to update.
	 * @return bool
	 */
	public static function update_collection( int $id, array $data ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$allowed = [ 'name', 'slug', 'description', 'auto_index', 'source_config', 'status', 'chunk_count', 'last_indexed_at' ];
		$update  = [];
		$formats = [];

		foreach ( $data as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}

			if ( 'source_config' === $key && is_array( $value ) ) {
				$value = wp_json_encode( $value );
			}

			if ( 'auto_index' === $key ) {
				$value = ! empty( $value ) ? 1 : 0;
			}

			$update[ $key ] = $value;
			$formats[]      = in_array( $key, [ 'auto_index', 'chunk_count' ], true ) ? '%d' : '%s';
		}

		if ( empty( $update ) ) {
			return false;
		}

		$update['updated_at'] = current_time( 'mysql', true );
		$formats[]            = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->update(
			self::collections_table(),
			$update,
			[ 'id' => $id ],
			$formats,
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Delete a collection and all its sources and chunks.
	 *
	 * @param int $id Collection ID.
	 * @return bool
	 */
	public static function delete_collection( int $id ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// Delete chunks first, then sources, then collection.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$wpdb->delete( self::chunks_table(), [ 'collection_id' => $id ], [ '%d' ] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$wpdb->delete( self::sources_table(), [ 'collection_id' => $id ], [ '%d' ] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->delete( self::collections_table(), [ 'id' => $id ], [ '%d' ] );

		return $result !== false;
	}

	/**
	 * List all collections.
	 *
	 * @param string|null $status Optional status filter.
	 * @return list<object>|null
	 */
	public static function list_collections( ?string $status = null ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = self::collections_table();

		if ( $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
			$results = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE status = %s ORDER BY name ASC',
					$table,
					$status
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
			$results = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i ORDER BY name ASC',
					$table
				)
			);
		}

		foreach ( $results as $row ) {
			if ( ! empty( $row->source_config ) ) {
				$row->source_config = json_decode( $row->source_config, true ) ?: [];
			}
		}

		return $results ?: [];
	}

	// ── Sources ──────────────────────────────────────────────────────────

	/**
	 * Create a source record.
	 *
	 * @param array<string, mixed> $data Source data.
	 * @return int|false Inserted row ID or false.
	 */
	public static function create_source( array $data ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query; caching not applicable.
		$result = $wpdb->insert(
			self::sources_table(),
			[
				// @phpstan-ignore-next-line
				'collection_id' => (int) ( $data['collection_id'] ?? 0 ),
				// @phpstan-ignore-next-line
				'source_type'   => sanitize_text_field( $data['source_type'] ?? '' ),
				// @phpstan-ignore-next-line
				'source_id'     => isset( $data['source_id'] ) ? (int) $data['source_id'] : null,
				// @phpstan-ignore-next-line
				'source_url'    => isset( $data['source_url'] ) ? esc_url_raw( $data['source_url'] ) : null,
				// @phpstan-ignore-next-line
				'title'         => sanitize_text_field( $data['title'] ?? '' ),
				'status'        => 'pending',
				'content_hash'  => $data['content_hash'] ?? null,
				'created_at'    => $now,
				'updated_at'    => $now,
			],
			[ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Get a single source by ID.
	 *
	 * @param int $id Source ID.
	 * @return object|null
	 */
	public static function get_source( int $id ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				self::sources_table(),
				$id
			)
		);
	}

	/**
	 * Find an existing source by type and source_id within a collection.
	 *
	 * @param int    $collection_id Collection ID.
	 * @param string $source_type   Source type (post, attachment, url).
	 * @param int    $source_id     The WordPress object ID.
	 * @return object|null
	 */
	public static function find_source( int $collection_id, string $source_type, int $source_id ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE collection_id = %d AND source_type = %s AND source_id = %d',
				self::sources_table(),
				$collection_id,
				$source_type,
				$source_id
			)
		);
	}

	/**
	 * Update a source record.
	 *
	 * @param int                  $id   Source ID.
	 * @param array<string, mixed> $data Fields to update.
	 * @return bool
	 */
	public static function update_source( int $id, array $data ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$allowed = [ 'title', 'source_url', 'status', 'chunk_count', 'content_hash', 'error_message' ];
		$update  = [];
		$formats = [];

		foreach ( $data as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}
			$update[ $key ] = $value;
			$formats[]      = 'chunk_count' === $key ? '%d' : '%s';
		}

		if ( empty( $update ) ) {
			return false;
		}

		$update['updated_at'] = current_time( 'mysql', true );
		$formats[]            = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->update(
			self::sources_table(),
			$update,
			[ 'id' => $id ],
			$formats,
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Get all sources for a collection.
	 *
	 * @param int $collection_id Collection ID.
	 * @return list<object>|null
	 */
	public static function get_sources_for_collection( int $collection_id ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE collection_id = %d ORDER BY updated_at DESC',
				self::sources_table(),
				$collection_id
			)
		);

		return $results ?: [];
	}

	/**
	 * Delete a source and its chunks.
	 *
	 * @param int $source_id Source ID.
	 * @return bool
	 */
	public static function delete_source( int $source_id ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// Get the source to update collection chunk_count later.
		$source = self::get_source( $source_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$wpdb->delete( self::chunks_table(), [ 'source_id' => $source_id ], [ '%d' ] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->delete( self::sources_table(), [ 'id' => $source_id ], [ '%d' ] );

		// Update collection chunk_count.
		if ( $source ) {
			self::recalculate_collection_chunk_count( (int) $source->collection_id );
		}

		return $result !== false;
	}

	// ── Chunks ───────────────────────────────────────────────────────────

	/**
	 * Bulk insert chunks for a source.
	 *
	 * @param int                        $collection_id Collection ID.
	 * @param int                        $source_id     Source ID.
	 * @param list<array<string, mixed>> $chunks        Array of chunk arrays with 'text', 'index', and optional 'metadata'.
	 * @return int Number of chunks inserted.
	 */
	public static function insert_chunks( int $collection_id, int $source_id, array $chunks ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table    = self::chunks_table();
		$now      = current_time( 'mysql', true );
		$inserted = 0;

		foreach ( $chunks as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query; caching not applicable.
			$result = $wpdb->insert(
				$table,
				[
					'collection_id' => $collection_id,
					'source_id'     => $source_id,
					// @phpstan-ignore-next-line
					'chunk_index'   => (int) ( $chunk['index'] ?? 0 ),
					'chunk_text'    => $chunk['text'] ?? '',
					'metadata'      => isset( $chunk['metadata'] ) ? wp_json_encode( $chunk['metadata'] ) : null,
					'created_at'    => $now,
					'updated_at'    => $now,
				],
				[ '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
			);

			if ( $result ) {
				++$inserted;
			}
		}

		return $inserted;
	}

	/**
	 * Delete all chunks for a source.
	 *
	 * @param int $source_id Source ID.
	 * @return int Number of rows deleted.
	 */
	public static function delete_chunks_for_source( int $source_id ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE source_id = %d',
				self::chunks_table(),
				$source_id
			)
		);

		return $result !== false ? (int) $result : 0;
	}

	/**
	 * Search chunks using MySQL FULLTEXT in BOOLEAN MODE.
	 *
	 * @param string   $query         Search query.
	 * @param int|null $collection_id Optional collection filter.
	 * @param int      $limit         Max results.
	 * @return list<object>|null Array of chunk objects with relevance score.
	 */
	public static function search_chunks( string $query, ?int $collection_id = null, int $limit = 10 ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$chunks_table      = self::chunks_table();
		$sources_table     = self::sources_table();
		$collections_table = self::collections_table();

		$search_expr = self::build_boolean_search_expression( $query, true );
		if ( '' === $search_expr ) {
			return [];
		}

		$results = self::run_chunk_search_query( $search_expr, $collection_id, $limit, $chunks_table, $sources_table, $collections_table );

		if ( empty( $results ) ) {
			$fallback_expr = self::build_boolean_search_expression( $query, false );
			if ( '' !== $fallback_expr && $fallback_expr !== $search_expr ) {
				$results = self::run_chunk_search_query( $fallback_expr, $collection_id, $limit, $chunks_table, $sources_table, $collections_table );
			}
		}

		if ( empty( $results ) ) {
			$results = self::run_chunk_like_search_query( self::extract_search_terms( $query ), $collection_id, $limit, $chunks_table, $sources_table, $collections_table );
		}

		return $results ?: [];
	}

	/**
	 * Build a MySQL boolean fulltext expression from a natural-language query.
	 *
	 * @param string $query    User query.
	 * @param bool   $required Whether every term should be required.
	 * @return string
	 */
	private static function build_boolean_search_expression( string $query, bool $required ): string {
		$terms = self::extract_search_terms( $query );
		if ( empty( $terms ) ) {
			return '';
		}

		$prefix = $required ? '+' : '';
		return implode(
			' ',
			array_map(
				static fn( string $term ): string => $prefix . $term . '*',
				$terms
			)
		);
	}

	/**
	 * Extract useful fulltext terms from a natural-language query.
	 *
	 * @param string $query User query.
	 * @return list<string>
	 */
	private static function extract_search_terms( string $query ): array {
		$stopwords = array_fill_keys(
			[
				'a',
				'an',
				'and',
				'are',
				'as',
				'at',
				'be',
				'by',
				'can',
				'do',
				'does',
				'for',
				'from',
				'how',
				'i',
				'in',
				'is',
				'it',
				'of',
				'on',
				'or',
				'so',
				'that',
				'the',
				'this',
				'to',
				'what',
				'when',
				'where',
				'which',
				'who',
				'why',
				'with',
				'you',
				'your',
			],
			true
		);
		$terms     = [];

		foreach ( preg_split( '/\s+/', strtolower( trim( $query ) ) ) ?: [] as $word ) {
			$word = (string) preg_replace( '/[^a-z0-9_]/', '', (string) $word );
			if ( strlen( $word ) < 4 || isset( $stopwords[ $word ] ) ) {
				continue;
			}

			$terms[] = $word;
		}

		return array_values( array_unique( $terms ) );
	}

	/**
	 * Execute a chunk search query.
	 *
	 * @param string $search_expr       Boolean fulltext search expression.
	 * @param ?int   $collection_id     Optional collection ID.
	 * @param int    $limit             Max results.
	 * @param string $chunks_table      Chunks table name.
	 * @param string $sources_table     Sources table name.
	 * @param string $collections_table Collections table name.
	 * @return list<object>
	 */
	private static function run_chunk_search_query( string $search_expr, ?int $collection_id, int $limit, string $chunks_table, string $sources_table, string $collections_table ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( $collection_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table query; caching not applicable.
			$results = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT c.id, c.chunk_text, c.chunk_index, c.metadata,
						s.title AS source_title, s.source_type, s.source_id, s.source_url,
						col.name AS collection_name, col.slug AS collection_slug,
						MATCH(c.chunk_text) AGAINST(%s IN BOOLEAN MODE) AS relevance
					FROM %i c
					INNER JOIN %i s ON c.source_id = s.id
					INNER JOIN %i col ON c.collection_id = col.id
					WHERE MATCH(c.chunk_text) AGAINST(%s IN BOOLEAN MODE)
						AND c.collection_id = %d
					ORDER BY relevance DESC
					LIMIT %d',
					$search_expr,
					$chunks_table,
					$sources_table,
					$collections_table,
					$search_expr,
					$collection_id,
					$limit
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table query; caching not applicable.
			$results = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT c.id, c.chunk_text, c.chunk_index, c.metadata,
						s.title AS source_title, s.source_type, s.source_id, s.source_url,
						col.name AS collection_name, col.slug AS collection_slug,
						MATCH(c.chunk_text) AGAINST(%s IN BOOLEAN MODE) AS relevance
					FROM %i c
					INNER JOIN %i s ON c.source_id = s.id
					INNER JOIN %i col ON c.collection_id = col.id
					WHERE MATCH(c.chunk_text) AGAINST(%s IN BOOLEAN MODE)
					ORDER BY relevance DESC
					LIMIT %d',
					$search_expr,
					$chunks_table,
					$sources_table,
					$collections_table,
					$search_expr,
					$limit
				)
			);
		}

		return $results ?: [];
	}

	/**
	 * Execute a LIKE fallback query when FULLTEXT returns no hits.
	 *
	 * @param string[] $terms Query terms.
	 * @param ?int     $collection_id Optional collection ID.
	 * @param int      $limit         Max results.
	 * @param string   $chunks_table  Chunks table name.
	 * @param string   $sources_table Sources table name.
	 * @param string   $collections_table Collections table name.
	 * @return list<object>
	 */
	private static function run_chunk_like_search_query( array $terms, ?int $collection_id, int $limit, string $chunks_table, string $sources_table, string $collections_table ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( empty( $terms ) ) {
			return [];
		}

		$where_parts     = [];
		$relevance_parts = [];
		$values          = [];

		foreach ( $terms as $term ) {
			$like              = '%' . $wpdb->esc_like( $term ) . '%';
			$where_parts[]     = 'c.chunk_text LIKE %s';
			$relevance_parts[] = 'CASE WHEN c.chunk_text LIKE %s THEN 1 ELSE 0 END';
			$values[]          = $like;
		}

		$sql = 'SELECT c.id, c.chunk_text, c.chunk_index, c.metadata,
				s.title AS source_title, s.source_type, s.source_id, s.source_url,
				col.name AS collection_name, col.slug AS collection_slug,
				(' . implode( ' + ', $relevance_parts ) . ') AS relevance
			FROM %i c
			INNER JOIN %i s ON c.source_id = s.id
			INNER JOIN %i col ON c.collection_id = col.id
			WHERE (' . implode( ' OR ', $where_parts ) . ')';

		$query_values = array_merge( $values, [ $chunks_table, $sources_table, $collections_table ], $values );
		if ( $collection_id ) {
			$sql           .= ' AND c.collection_id = %d';
			$query_values[] = $collection_id;
		}

		$sql           .= ' ORDER BY relevance DESC LIMIT %d';
		$query_values[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table query; caching not applicable.
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $query_values ) );

		return $results ?: [];
	}

	/**
	 * Get total chunk count across all collections.
	 *
	 * @return int
	 */
	public static function get_total_chunk_count(): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i',
				self::chunks_table()
			)
		);
	}

	/**
	 * Recalculate and update the chunk_count for a collection.
	 *
	 * @param int $collection_id Collection ID.
	 */
	public static function recalculate_collection_chunk_count( int $collection_id ): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE collection_id = %d',
				self::chunks_table(),
				$collection_id
			)
		);

		self::update_collection( $collection_id, [ 'chunk_count' => $count ] );
	}
}
