<?php

declare(strict_types=1);
/**
 * Knowledge manager — facade for the knowledge base system.
 *
 * Orchestrates indexing, search, and context retrieval.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Knowledge;

use SdAiAgent\Models\Chunker;
use SdAiAgent\Models\DocumentParser;
use WP_Error;

class Knowledge {

	private const STATIC_FILE_SOURCE_TYPE = 'static_file';

	/**
	 * Index a WordPress post into a collection.
	 *
	 * @param int $post_id       The post ID to index.
	 * @param int $collection_id The target collection ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function index_post( int $post_id, int $collection_id ) {
		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return new WP_Error( 'invalid_post', __( 'Post not found or not published.', 'superdav-ai-agent' ) );
		}

		// Build text content: title + plain-text body.
		$content = $post->post_title . "\n\n" . wp_strip_all_tags( $post->post_content );
		$content = trim( $content );

		if ( empty( $content ) ) {
			return new WP_Error( 'empty_content', __( 'Post has no text content to index.', 'superdav-ai-agent' ) );
		}

		// Compute hash for change detection.
		$hash = md5( $content );

		// Check for existing source.
		$existing = KnowledgeDatabase::find_source( $collection_id, 'post', $post_id );

		if ( $existing && $existing->content_hash === $hash ) {
			// Content unchanged — skip.
			return true;
		}

		// Create or update source record.
		if ( $existing ) {
			$source_id = (int) $existing->id;
			KnowledgeDatabase::delete_chunks_for_source( $source_id );
			KnowledgeDatabase::update_source(
				$source_id,
				[
					'title'        => $post->post_title,
					'content_hash' => $hash,
					'status'       => 'pending',
				]
			);
		} else {
			$source_id = KnowledgeDatabase::create_source(
				[
					'collection_id' => $collection_id,
					'source_type'   => 'post',
					'source_id'     => $post_id,
					'title'         => $post->post_title,
					'content_hash'  => $hash,
				]
			);

			if ( ! $source_id ) {
				return new WP_Error( 'db_error', __( 'Failed to create source record.', 'superdav-ai-agent' ) );
			}
		}

		// Build metadata.
		$metadata = [
			'post_type' => $post->post_type,
		];

		$categories = wp_get_post_categories( $post_id, [ 'fields' => 'names' ] );
		if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
			$metadata['categories'] = $categories;
		}

		$tags = wp_get_post_tags( $post_id, [ 'fields' => 'names' ] );
		if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
			$metadata['tags'] = $tags;
		}

		// Chunk the content.
		$chunks = Chunker::chunk( $content );

		// Add metadata to each chunk.
		foreach ( $chunks as &$chunk ) {
			$chunk['metadata'] = $metadata;
		}
		unset( $chunk );

		// Insert chunks.
		$inserted = KnowledgeDatabase::insert_chunks( $collection_id, $source_id, $chunks );

		// Update source.
		KnowledgeDatabase::update_source(
			$source_id,
			[
				'chunk_count' => $inserted,
				'status'      => 'indexed',
			]
		);

		// Update collection.
		KnowledgeDatabase::recalculate_collection_chunk_count( $collection_id );
		KnowledgeDatabase::update_collection(
			$collection_id,
			[
				'last_indexed_at' => current_time( 'mysql', true ),
			]
		);

		return true;
	}

	/**
	 * Index a WordPress attachment into a collection.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @param int $collection_id The target collection ID.
	 * @return bool|WP_Error
	 */
	public static function index_attachment( int $attachment_id, int $collection_id ) {
		$content = DocumentParser::extract_from_attachment( $attachment_id );

		if ( is_wp_error( $content ) ) {
			// Record the error in the source.
			$existing = KnowledgeDatabase::find_source( $collection_id, 'attachment', $attachment_id );
			if ( $existing ) {
				KnowledgeDatabase::update_source(
					(int) $existing->id,
					[
						'status'        => 'error',
						'error_message' => $content->get_error_message(),
					]
				);
			}
			return $content;
		}

		$hash  = md5( $content );
		$title = get_the_title( $attachment_id ) ?: basename( (string) get_attached_file( $attachment_id ) );

		// Check for existing source.
		$existing = KnowledgeDatabase::find_source( $collection_id, 'attachment', $attachment_id );

		if ( $existing && $existing->content_hash === $hash ) {
			return true;
		}

		if ( $existing ) {
			$source_id = (int) $existing->id;
			KnowledgeDatabase::delete_chunks_for_source( $source_id );
			KnowledgeDatabase::update_source(
				$source_id,
				[
					'title'        => $title,
					'content_hash' => $hash,
					'status'       => 'pending',
				]
			);
		} else {
			$source_id = KnowledgeDatabase::create_source(
				[
					'collection_id' => $collection_id,
					'source_type'   => 'attachment',
					'source_id'     => $attachment_id,
					'title'         => $title,
					'content_hash'  => $hash,
				]
			);

			if ( ! $source_id ) {
				return new WP_Error( 'db_error', __( 'Failed to create source record.', 'superdav-ai-agent' ) );
			}
		}

		$chunks   = Chunker::chunk( $content );
		$inserted = KnowledgeDatabase::insert_chunks( $collection_id, $source_id, $chunks );

		KnowledgeDatabase::update_source(
			$source_id,
			[
				'chunk_count' => $inserted,
				'status'      => 'indexed',
			]
		);

		KnowledgeDatabase::recalculate_collection_chunk_count( $collection_id );
		KnowledgeDatabase::update_collection(
			$collection_id,
			[
				'last_indexed_at' => current_time( 'mysql', true ),
			]
		);

		return true;
	}

	/**
	 * Import static documentation records from a manifest.
	 *
	 * @param int                        $collection_id Collection ID.
	 * @param list<array<string, mixed>> $records       Manifest records.
	 * @param array<string, bool|int>    $options       Import options. Supports prune/stale_removed.
	 * @return array{imported: int, updated: int, skipped: int, pruned: int, stale: int, errors: int}|WP_Error
	 */
	public static function import_static_docs_manifest( int $collection_id, array $records, array $options = [] ) {
		$collection = KnowledgeDatabase::get_collection( $collection_id );
		if ( ! $collection ) {
			return new WP_Error( 'not_found', __( 'Collection not found.', 'superdav-ai-agent' ) );
		}

		$stats = [
			'imported' => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'pruned'   => 0,
			'stale'    => 0,
			'errors'   => 0,
		];
		$seen  = [];

		foreach ( $records as $record ) {
			$result = self::index_static_doc_record( $collection_id, $record );
			if ( is_wp_error( $result ) ) {
				++$stats['errors'];
				continue;
			}

			$seen[] = $result['source_key'];
			++$stats[ $result['action'] ];
		}

		if ( ! empty( $options['prune'] ) || ! empty( $options['stale_removed'] ) ) {
			$removed = self::handle_removed_static_docs( $collection_id, $seen, ! empty( $options['prune'] ) );
			if ( ! empty( $options['prune'] ) ) {
				$stats['pruned'] = $removed;
			} else {
				$stats['stale'] = $removed;
			}
		}

		KnowledgeDatabase::recalculate_collection_chunk_count( $collection_id );
		KnowledgeDatabase::update_collection( $collection_id, [ 'last_indexed_at' => current_time( 'mysql', true ) ] );

		return $stats;
	}

	/**
	 * Import static documentation records from a JSON or JSONL manifest file.
	 *
	 * @param int                     $collection_id Collection ID.
	 * @param string                  $manifest_path Manifest path for trusted CLI/admin workflows.
	 * @param array<string, bool|int> $options       Import options.
	 * @return array{imported: int, updated: int, skipped: int, pruned: int, stale: int, errors: int}|WP_Error
	 */
	public static function import_static_docs_manifest_file( int $collection_id, string $manifest_path, array $options = [] ) {
		if ( ! is_readable( $manifest_path ) ) {
			return new WP_Error( 'file_not_found', __( 'Manifest file not found or not readable.', 'superdav-ai-agent' ) );
		}

		$max_bytes = (int) ( $options['max_bytes'] ?? 25 * 1024 * 1024 );
		$size      = filesize( $manifest_path );
		if ( false !== $size && $size > $max_bytes ) {
			return new WP_Error( 'manifest_too_large', __( 'Manifest file exceeds the allowed size.', 'superdav-ai-agent' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Trusted CLI/admin manifest import path.
		$raw = file_get_contents( $manifest_path );
		if ( false === $raw ) {
			return new WP_Error( 'read_error', __( 'Could not read manifest file.', 'superdav-ai-agent' ) );
		}

		$records = self::decode_static_docs_manifest( $raw );
		if ( is_wp_error( $records ) ) {
			return $records;
		}

		return self::import_static_docs_manifest( $collection_id, $records, $options );
	}

	/**
	 * Re-index all posts matching a collection's source_config.
	 *
	 * @param int $collection_id Collection ID.
	 * @return array{indexed: int, skipped: int, errors: int}|WP_Error
	 */
	public static function reindex_collection( int $collection_id ) {
		$collection = KnowledgeDatabase::get_collection( $collection_id );

		if ( ! $collection ) {
			return new WP_Error( 'not_found', __( 'Collection not found.', 'superdav-ai-agent' ) );
		}

		$config     = $collection->source_config;
		$post_types = $config['post_types'] ?? [ 'post', 'page' ];

		$posts = get_posts(
			[
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
		);

		$stats = [
			'indexed' => 0,
			'skipped' => 0,
			'errors'  => 0,
		];

		foreach ( $posts as $post_id ) {
			$result = self::index_post( $post_id, $collection_id );

			if ( is_wp_error( $result ) ) {
				++$stats['errors'];
			} elseif ( true === $result ) {
				++$stats['indexed'];
			} else {
				++$stats['skipped'];
			}
		}

		KnowledgeDatabase::update_collection(
			$collection_id,
			[
				'last_indexed_at' => current_time( 'mysql', true ),
			]
		);

		return $stats;
	}

	/**
	 * Search the knowledge base.
	 *
	 * @param string               $query   Search query.
	 * @param array<string, mixed> $options Optional: collection_id, collection (slug), limit.
	 * @return list<array<string, mixed>> Search results.
	 */
	public static function search( string $query, array $options = [] ): array {
		$collection_id = $options['collection_id'] ?? null;
		$limit         = $options['limit'] ?? 10;

		// Resolve collection slug to ID if provided.
		if ( ! $collection_id && ! empty( $options['collection'] ) ) {
			// @phpstan-ignore-next-line
			$col = KnowledgeDatabase::get_collection_by_slug( $options['collection'] );
			if ( $col ) {
				$collection_id = (int) $col->id;
			}
		}

		// @phpstan-ignore-next-line
		$raw_results = KnowledgeDatabase::search_chunks( $query, $collection_id, $limit );

		$results = [];
		foreach ( $raw_results as $row ) {
			// @phpstan-ignore-next-line
			$source_url = $row->source_url;

			// Build URL for post sources.
			// @phpstan-ignore-next-line
			if ( 'post' === $row->source_type && $row->source_id ) {
				// @phpstan-ignore-next-line
				$source_url = get_permalink( (int) $row->source_id ) ?: $source_url;
			}

			$results[] = [
				// @phpstan-ignore-next-line
				'chunk_text'      => $row->chunk_text,
				// @phpstan-ignore-next-line
				'source_title'    => $row->source_title,
				'source_url'      => $source_url,
				// @phpstan-ignore-next-line
				'source_type'     => $row->source_type,
				// @phpstan-ignore-next-line
				'collection_name' => $row->collection_name,
				// @phpstan-ignore-next-line
				'score'           => (float) $row->relevance,
				// @phpstan-ignore-next-line
				'metadata'        => $row->metadata ? json_decode( $row->metadata, true ) : null,
			];
		}

		return $results;
	}

	/**
	 * Index one static docs manifest record.
	 *
	 * @param int                  $collection_id Collection ID.
	 * @param array<string, mixed> $record        Manifest record.
	 * @return array{action: 'imported'|'updated'|'skipped', source_key: int}|WP_Error
	 */
	private static function index_static_doc_record( int $collection_id, array $record ) {
		$content = isset( $record['content'] ) ? (string) $record['content'] : '';
		if ( '' === trim( $content ) ) {
			return new WP_Error( 'empty_content', __( 'Static documentation record has no content.', 'superdav-ai-agent' ) );
		}

		$identity = self::get_static_doc_identity( $record );
		if ( '' === $identity ) {
			return new WP_Error( 'missing_identity', __( 'Static documentation record requires an id, path, or URL.', 'superdav-ai-agent' ) );
		}

		$parsed   = DocumentParser::extract_markdown_content( $content );
		$text     = trim( $parsed['text'] );
		$metadata = self::build_static_doc_metadata( $record, $parsed['metadata'], $parsed['headings'], $identity );
		$title    = self::get_static_doc_title( $record, $metadata, $parsed['headings'] );
		$url      = self::get_static_doc_public_url( $record );

		if ( '' === $text ) {
			return new WP_Error( 'empty_content', __( 'Static documentation record has no visible text content.', 'superdav-ai-agent' ) );
		}

		$source_key = self::stable_static_doc_source_key( $identity );
		$hash       = md5( wp_json_encode( [ $text, $title, $url, $metadata ] ) ?: $text );
		$existing   = KnowledgeDatabase::find_source( $collection_id, self::STATIC_FILE_SOURCE_TYPE, $source_key );

		if ( $existing && $existing->content_hash === $hash ) {
			return [
				'action'     => 'skipped',
				'source_key' => $source_key,
			];
		}

		$action = $existing ? 'updated' : 'imported';
		if ( $existing ) {
			$source_id = (int) $existing->id;
			KnowledgeDatabase::delete_chunks_for_source( $source_id );
			KnowledgeDatabase::update_source(
				$source_id,
				[
					'title'        => $title,
					'source_url'   => $url,
					'content_hash' => $hash,
					'status'       => 'pending',
				]
			);
		} else {
			$source_id = KnowledgeDatabase::create_source(
				[
					'collection_id' => $collection_id,
					'source_type'   => self::STATIC_FILE_SOURCE_TYPE,
					'source_id'     => $source_key,
					'source_url'    => $url,
					'title'         => $title,
					'content_hash'  => $hash,
				]
			);

			if ( ! $source_id ) {
				return new WP_Error( 'db_error', __( 'Failed to create source record.', 'superdav-ai-agent' ) );
			}
		}

		$chunks = Chunker::chunk( $text );
		foreach ( $chunks as &$chunk ) {
			$chunk['metadata'] = $metadata;
		}
		unset( $chunk );

		$inserted = KnowledgeDatabase::insert_chunks( $collection_id, (int) $source_id, $chunks );
		KnowledgeDatabase::update_source(
			(int) $source_id,
			[
				'chunk_count' => $inserted,
				'status'      => 'indexed',
			]
		);

		return [
			'action'     => $action,
			'source_key' => $source_key,
		];
	}

	/**
	 * Decode JSON array/object or JSONL static docs manifest data.
	 *
	 * @param string $raw Raw manifest data.
	 * @return list<array<string, mixed>>|WP_Error
	 */
	private static function decode_static_docs_manifest( string $raw ) {
		$trimmed = trim( $raw );
		if ( '' === $trimmed ) {
			return new WP_Error( 'empty_manifest', __( 'Manifest is empty.', 'superdav-ai-agent' ) );
		}

		$decoded = json_decode( $trimmed, true );
		if ( is_array( $decoded ) ) {
			$records = isset( $decoded['records'] ) && is_array( $decoded['records'] ) ? $decoded['records'] : $decoded;
			return self::normalize_static_docs_records( $records );
		}

		$records = [];
		foreach ( preg_split( '/\n+/', $trimmed ) ?: [] as $line ) {
			$record = json_decode( trim( $line ), true );
			if ( ! is_array( $record ) ) {
				return new WP_Error( 'invalid_manifest', __( 'Manifest contains invalid JSON.', 'superdav-ai-agent' ) );
			}
			$normalized = self::normalize_static_docs_records( [ $record ] );
			if ( ! empty( $normalized ) ) {
				$records[] = $normalized[0];
			}
		}

		return $records;
	}

	/**
	 * Normalize decoded manifest records to string-keyed arrays.
	 *
	 * @param array<mixed> $records Raw decoded records.
	 * @return list<array<string, mixed>>
	 */
	private static function normalize_static_docs_records( array $records ): array {
		$normalized = [];
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$item = [];
			foreach ( $record as $key => $value ) {
				if ( is_string( $key ) ) {
					$item[ $key ] = $value;
				}
			}

			$normalized[] = $item;
		}

		return $normalized;
	}

	/**
	 * Handle static docs records no longer present in an imported manifest.
	 *
	 * @param int             $collection_id Collection ID.
	 * @param array<int, int> $seen          Source keys present in the import.
	 * @param bool            $prune         Whether to delete instead of stale-mark.
	 * @return int Number of removed records handled.
	 */
	private static function handle_removed_static_docs( int $collection_id, array $seen, bool $prune ): int {
		$sources = KnowledgeDatabase::get_sources_for_collection( $collection_id ) ?: [];
		$count   = 0;
		foreach ( $sources as $source ) {
			if ( self::STATIC_FILE_SOURCE_TYPE !== $source->source_type || in_array( (int) $source->source_id, $seen, true ) ) {
				continue;
			}

			if ( $prune ) {
				KnowledgeDatabase::delete_source( (int) $source->id );
			} else {
				KnowledgeDatabase::delete_chunks_for_source( (int) $source->id );
				KnowledgeDatabase::update_source(
					(int) $source->id,
					[
						'status'      => 'stale',
						'chunk_count' => 0,
					]
				);
			}
			++$count;
		}

		return $count;
	}

	/**
	 * Build stable numeric key for static docs source identity.
	 */
	private static function stable_static_doc_source_key( string $identity ): int {
		return (int) sprintf( '%u', crc32( $identity ) );
	}

	/**
	 * Get static docs source identity.
	 *
	 * @param array<string, mixed> $record Manifest record.
	 */
	private static function get_static_doc_identity( array $record ): string {
		foreach ( [ 'id', 'path', 'url', 'route' ] as $key ) {
			if ( ! empty( $record[ $key ] ) && is_scalar( $record[ $key ] ) ) {
				return sanitize_text_field( (string) $record[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Get public source URL/route without exposing local filesystem paths.
	 *
	 * @param array<string, mixed> $record Manifest record.
	 */
	private static function get_static_doc_public_url( array $record ): string {
		foreach ( [ 'url', 'route', 'docs_route' ] as $key ) {
			if ( ! empty( $record[ $key ] ) && is_scalar( $record[ $key ] ) ) {
				return esc_url_raw( (string) $record[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Build searchable metadata for static docs chunks.
	 *
	 * @param array<string, mixed> $record           Manifest record.
	 * @param array<string, mixed> $frontmatter      Parsed frontmatter.
	 * @param array<int, string>   $headings         Parsed headings.
	 * @param string               $source_identity Source identity.
	 * @return array<string, mixed>
	 */
	private static function build_static_doc_metadata( array $record, array $frontmatter, array $headings, string $source_identity ): array {
		$metadata = [];
		if ( is_array( $record['metadata'] ?? null ) ) {
			foreach ( $record['metadata'] as $key => $value ) {
				if ( is_string( $key ) ) {
					$metadata[ $key ] = $value;
				}
			}
		}
		foreach ( [ 'locale', 'addon', 'product', 'section', 'version', 'path', 'route', 'docs_route' ] as $key ) {
			if ( isset( $record[ $key ] ) && is_scalar( $record[ $key ] ) ) {
				$metadata[ $key ] = sanitize_text_field( (string) $record[ $key ] );
			}
		}

		$metadata['source_type'] = self::STATIC_FILE_SOURCE_TYPE;
		$metadata['source_id']   = $source_identity;
		$metadata['frontmatter'] = $frontmatter;
		$metadata['headings']    = $headings;

		return $metadata;
	}

	/**
	 * Resolve title from manifest, frontmatter, or first heading.
	 *
	 * @param array<string, mixed> $record   Manifest record.
	 * @param array<string, mixed> $metadata Built metadata.
	 * @param array<int, string>   $headings Parsed headings.
	 */
	private static function get_static_doc_title( array $record, array $metadata, array $headings ): string {
		foreach ( [ $record['title'] ?? null, $metadata['title'] ?? null, $metadata['frontmatter']['title'] ?? null, $headings[0] ?? null ] as $title ) {
			if ( is_scalar( $title ) && '' !== trim( (string) $title ) ) {
				return sanitize_text_field( (string) $title );
			}
		}

		return __( 'Untitled documentation file', 'superdav-ai-agent' );
	}

	/**
	 * Get formatted context for inclusion in a system prompt.
	 *
	 * @param string $query      The user's query.
	 * @param int    $max_tokens Approximate token budget for the context.
	 * @return string Formatted context string.
	 */
	public static function get_context_for_query( string $query, int $max_tokens = 2000 ): string {
		$results = self::search( $query, [ 'limit' => 10 ] );

		if ( empty( $results ) ) {
			return '';
		}

		$max_chars = $max_tokens * 4;
		$output    = '';
		$chars     = 0;

		foreach ( $results as $result ) {
			$source_label = $result['source_title'] ?? 'Unknown';
			if ( ! empty( $result['source_url'] ) ) {
				// @phpstan-ignore-next-line
				$source_label .= ' (' . $result['source_url'] . ')';
			}

			// @phpstan-ignore-next-line
			$entry = "**Source: {$source_label}**\n{$result['chunk_text']}\n\n";

			if ( $chars + strlen( $entry ) > $max_chars ) {
				break;
			}

			$output .= $entry;
			$chars  += strlen( $entry );
		}

		return trim( $output );
	}

	/**
	 * Delete a source and its chunks.
	 *
	 * @param int $source_id Source ID.
	 * @return bool
	 */
	public static function delete_source( int $source_id ): bool {
		return KnowledgeDatabase::delete_source( $source_id );
	}
}
