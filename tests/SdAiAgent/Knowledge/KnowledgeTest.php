<?php
/**
 * Test case for Knowledge class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Knowledge;

use SdAiAgent\Core\Database;
use SdAiAgent\Knowledge\Knowledge;
use SdAiAgent\Knowledge\KnowledgeDatabase;
use WP_UnitTestCase;

/**
 * Test Knowledge facade methods.
 */
class KnowledgeTest extends WP_UnitTestCase {

	/**
	 * Ensure tables exist before tests run.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Database::install();
	}

	/**
	 * Clean up knowledge data after each test.
	 */
	public function tear_down(): void {
		parent::tear_down();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE 1=1', KnowledgeDatabase::chunks_table() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE 1=1', KnowledgeDatabase::sources_table() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE 1=1', KnowledgeDatabase::collections_table() ) );
	}

	// ── index_post ────────────────────────────────────────────────────────

	/**
	 * Test index_post returns WP_Error for non-existent post.
	 */
	public function test_index_post_returns_wp_error_for_nonexistent_post(): void {
		$col_id = KnowledgeDatabase::create_collection( [
			'name' => 'Test Collection',
			'slug' => 'test-collection',
		] );

		$result = Knowledge::index_post( 999999, $col_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_post', $result->get_error_code() );
	}

	/**
	 * Test index_post returns WP_Error for draft post.
	 */
	public function test_index_post_returns_wp_error_for_draft_post(): void {
		$post_id = $this->factory->post->create( [
			'post_status'  => 'draft',
			'post_title'   => 'Draft Post',
			'post_content' => 'Draft content',
		] );
		$col_id  = KnowledgeDatabase::create_collection( [
			'name' => 'Draft Test Collection',
			'slug' => 'draft-test-collection',
		] );

		$result = Knowledge::index_post( $post_id, $col_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_post', $result->get_error_code() );
	}

	/**
	 * Test index_post returns WP_Error for post with empty content.
	 */
	public function test_index_post_returns_wp_error_for_empty_content(): void {
		$post_id = $this->factory->post->create( [
			'post_status'  => 'publish',
			'post_title'   => '',
			'post_content' => '',
		] );
		$col_id  = KnowledgeDatabase::create_collection( [
			'name' => 'Empty Content Collection',
			'slug' => 'empty-content-collection',
		] );

		$result = Knowledge::index_post( $post_id, $col_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'empty_content', $result->get_error_code() );
	}

	/**
	 * Test index_post returns true for valid published post.
	 */
	public function test_index_post_returns_true_for_valid_post(): void {
		$post_id = $this->factory->post->create( [
			'post_status'  => 'publish',
			'post_title'   => 'Test Post Title',
			'post_content' => 'This is the test post content with enough text to be indexed.',
		] );
		$col_id  = KnowledgeDatabase::create_collection( [
			'name' => 'Valid Post Collection',
			'slug' => 'valid-post-collection',
		] );

		$result = Knowledge::index_post( $post_id, $col_id );

		$this->assertTrue( $result );
	}

	/**
	 * Test index_post creates source record.
	 */
	public function test_index_post_creates_source_record(): void {
		$post_id = $this->factory->post->create( [
			'post_status'  => 'publish',
			'post_title'   => 'Source Record Post',
			'post_content' => 'Content for source record test.',
		] );
		$col_id  = KnowledgeDatabase::create_collection( [
			'name' => 'Source Record Collection',
			'slug' => 'source-record-collection',
		] );

		Knowledge::index_post( $post_id, $col_id );

		$source = KnowledgeDatabase::find_source( $col_id, 'post', $post_id );

		$this->assertNotNull( $source );
		$this->assertSame( 'indexed', $source->status );
	}

	/**
	 * Test index_post is idempotent — re-indexing unchanged post returns true without re-inserting.
	 */
	public function test_index_post_is_idempotent_for_unchanged_content(): void {
		$post_id = $this->factory->post->create( [
			'post_status'  => 'publish',
			'post_title'   => 'Idempotent Post',
			'post_content' => 'Idempotent content that does not change.',
		] );
		$col_id  = KnowledgeDatabase::create_collection( [
			'name' => 'Idempotent Collection',
			'slug' => 'idempotent-collection',
		] );

		// First index.
		Knowledge::index_post( $post_id, $col_id );
		$source_after_first = KnowledgeDatabase::find_source( $col_id, 'post', $post_id );

		// Second index — content unchanged, should skip.
		$result = Knowledge::index_post( $post_id, $col_id );

		$source_after_second = KnowledgeDatabase::find_source( $col_id, 'post', $post_id );

		$this->assertTrue( $result );
		// Source ID should be the same (not re-created).
		$this->assertSame( $source_after_first->id, $source_after_second->id );
	}

	/**
	 * Test index_post re-indexes when content changes.
	 */
	public function test_index_post_reindexes_when_content_changes(): void {
		$post_id = $this->factory->post->create( [
			'post_status'  => 'publish',
			'post_title'   => 'Changing Post',
			'post_content' => 'Original content for change detection.',
		] );
		$col_id  = KnowledgeDatabase::create_collection( [
			'name' => 'Change Detection Collection',
			'slug' => 'change-detection-collection',
		] );

		Knowledge::index_post( $post_id, $col_id );

		// Update post content.
		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => 'Updated content that is different from the original.',
		] );

		$result = Knowledge::index_post( $post_id, $col_id );

		$this->assertTrue( $result );
	}

	// ── search ────────────────────────────────────────────────────────────

	/**
	 * Test search returns array.
	 */
	public function test_search_returns_array(): void {
		$results = Knowledge::search( 'test query' );
		$this->assertIsArray( $results );
	}

	/**
	 * Test search with non-existent collection slug returns empty array.
	 */
	public function test_search_with_nonexistent_collection_returns_empty(): void {
		$results = Knowledge::search( 'test', [ 'collection' => 'nonexistent-collection-xyz' ] );
		$this->assertIsArray( $results );
		$this->assertEmpty( $results );
	}

	/**
	 * Test search result structure when results exist.
	 */
	public function test_search_result_structure(): void {
		// Insert a post and index it.
		$post_id = $this->factory->post->create( [
			'post_status'  => 'publish',
			'post_title'   => 'Searchable Post',
			'post_content' => 'This post contains unique searchable content for testing purposes.',
		] );
		$col_id  = KnowledgeDatabase::create_collection( [
			'name' => 'Search Structure Collection',
			'slug' => 'search-structure-collection',
		] );

		Knowledge::index_post( $post_id, $col_id );

		// FULLTEXT search may not work in all test environments.
		$results = Knowledge::search( 'searchable', [ 'collection_id' => $col_id ] );

		$this->assertIsArray( $results );

		if ( ! empty( $results ) ) {
			$first = $results[0];
			$this->assertArrayHasKey( 'chunk_text', $first );
			$this->assertArrayHasKey( 'source_title', $first );
			$this->assertArrayHasKey( 'source_type', $first );
			$this->assertArrayHasKey( 'collection_name', $first );
			$this->assertArrayHasKey( 'score', $first );
		}
	}

	/**
	 * Test search resolves collection slug to ID.
	 */
	public function test_search_resolves_collection_slug(): void {
		$col_id = KnowledgeDatabase::create_collection( [
			'name' => 'Slug Resolve Collection',
			'slug' => 'slug-resolve-collection',
		] );

		// Should not throw; returns empty array when no chunks match.
		$results = Knowledge::search( 'test', [ 'collection' => 'slug-resolve-collection' ] );

		$this->assertIsArray( $results );
	}

	// ── static docs manifest import ────────────────────────────────────────

	/**
	 * Test static documentation manifest import creates searchable file sources.
	 */
	public function test_import_static_docs_manifest_creates_sources_and_chunks(): void {
		$col_id = KnowledgeDatabase::create_collection( [
			'name' => 'Docs Collection',
			'slug' => 'docs-collection',
		] );

		$stats = Knowledge::import_static_docs_manifest(
			$col_id,
			[
				[
					'id'       => 'docs/addons/example/setup.mdx',
					'path'     => 'docs/addons/example/setup.mdx',
					'title'    => 'Addon setup',
					'url'      => '/addons/example/setup',
					'locale'   => 'en',
					'product'  => 'Ultimate Multisite',
					'addon'    => 'example',
					'metadata' => [ 'section' => 'addons' ],
					'content'  => "---\ntitle: Frontmatter title\n---\nimport Tabs from '@theme/Tabs';\n\n# Addon setup\n\n<Note>Visible docs copy.</Note>\n\n```php\nwp option get blogname\n```",
				],
			]
		);

		$this->assertIsArray( $stats );
		$this->assertSame( 1, $stats['imported'] );
		$this->assertSame( 0, $stats['errors'] );

		$sources = KnowledgeDatabase::get_sources_for_collection( $col_id );
		$this->assertCount( 1, $sources );
		$this->assertSame( 'static_file', $sources[0]->source_type );
		$this->assertSame( '/addons/example/setup', $sources[0]->source_url );
		$this->assertSame( 'Addon setup', $sources[0]->title );

		global $wpdb;
		$chunk = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT chunk_text, metadata FROM %i WHERE source_id = %d',
				KnowledgeDatabase::chunks_table(),
				$sources[0]->id
			)
		);

		$this->assertNotNull( $chunk );
		$this->assertStringContainsString( 'Visible docs copy.', $chunk->chunk_text );
		$this->assertStringContainsString( 'wp option get blogname', $chunk->chunk_text );
		$this->assertStringNotContainsString( 'import Tabs', $chunk->chunk_text );

		$metadata = json_decode( $chunk->metadata, true );
		$this->assertSame( 'en', $metadata['locale'] );
		$this->assertSame( 'example', $metadata['addon'] );
		$this->assertSame( 'addons', $metadata['section'] );
		$this->assertSame( [ 'Addon setup' ], $metadata['headings'] );
	}

	/**
	 * Test static documentation manifest imports skip unchanged records.
	 */
	public function test_import_static_docs_manifest_skips_unchanged_records(): void {
		$col_id = KnowledgeDatabase::create_collection( [
			'name' => 'Docs Skip Collection',
			'slug' => 'docs-skip-collection',
		] );
		$record = [
			'id'      => 'docs/setup.md',
			'title'   => 'Setup',
			'url'     => '/setup',
			'content' => '# Setup\n\nInstall the addon.',
		];

		Knowledge::import_static_docs_manifest( $col_id, [ $record ] );
		$stats = Knowledge::import_static_docs_manifest( $col_id, [ $record ] );

		$this->assertIsArray( $stats );
		$this->assertSame( 1, $stats['skipped'] );
		$this->assertSame( 0, $stats['updated'] );
	}

	/**
	 * Test static documentation manifest imports update changed records.
	 */
	public function test_import_static_docs_manifest_updates_changed_records(): void {
		$col_id = KnowledgeDatabase::create_collection( [
			'name' => 'Docs Update Collection',
			'slug' => 'docs-update-collection',
		] );

		Knowledge::import_static_docs_manifest(
			$col_id,
			[
				[
					'id'      => 'docs/setup.md',
					'title'   => 'Setup',
					'url'     => '/setup',
					'content' => '# Setup\n\nOriginal copy.',
				],
			]
		);

		$stats = Knowledge::import_static_docs_manifest(
			$col_id,
			[
				[
					'id'      => 'docs/setup.md',
					'title'   => 'Setup updated',
					'url'     => '/setup-updated',
					'content' => '# Setup\n\nUpdated copy.',
				],
			]
		);

		$this->assertIsArray( $stats );
		$this->assertSame( 1, $stats['updated'] );

		$sources = KnowledgeDatabase::get_sources_for_collection( $col_id );
		$this->assertCount( 1, $sources );
		$this->assertSame( 'Setup updated', $sources[0]->title );
		$this->assertSame( '/setup-updated', $sources[0]->source_url );
	}

	/**
	 * Test static documentation manifest prune removes missing records.
	 */
	public function test_import_static_docs_manifest_prunes_removed_records(): void {
		$col_id = KnowledgeDatabase::create_collection( [
			'name' => 'Docs Prune Collection',
			'slug' => 'docs-prune-collection',
		] );

		Knowledge::import_static_docs_manifest(
			$col_id,
			[
				[ 'id' => 'docs/a.md', 'title' => 'A', 'content' => '# A\n\nAlpha.' ],
				[ 'id' => 'docs/b.md', 'title' => 'B', 'content' => '# B\n\nBravo.' ],
			]
		);

		$stats = Knowledge::import_static_docs_manifest(
			$col_id,
			[
				[ 'id' => 'docs/a.md', 'title' => 'A', 'content' => '# A\n\nAlpha.' ],
			],
			[ 'prune' => true ]
		);

		$this->assertIsArray( $stats );
		$this->assertSame( 1, $stats['pruned'] );
		$this->assertCount( 1, KnowledgeDatabase::get_sources_for_collection( $col_id ) );
	}

	/**
	 * Test static documentation manifest prune preserves present records that fail re-indexing.
	 */
	public function test_import_static_docs_manifest_prune_preserves_errored_present_records(): void {
		$col_id = KnowledgeDatabase::create_collection( [
			'name' => 'Docs Error Prune Collection',
			'slug' => 'docs-error-prune-collection',
		] );

		Knowledge::import_static_docs_manifest(
			$col_id,
			[
				[ 'id' => 'docs/a.md', 'title' => 'A', 'content' => '# A\n\nAlpha.' ],
				[ 'id' => 'docs/b.md', 'title' => 'B', 'content' => '# B\n\nBravo.' ],
			]
		);

		$stats = Knowledge::import_static_docs_manifest(
			$col_id,
			[
				[ 'id' => 'docs/b.md', 'title' => 'B', 'content' => '' ],
			],
			[ 'prune' => true ]
		);

		$this->assertIsArray( $stats );
		$this->assertSame( 1, $stats['errors'] );
		$this->assertSame( 1, $stats['pruned'] );

		$sources = KnowledgeDatabase::get_sources_for_collection( $col_id );
		$this->assertCount( 1, $sources );
		$this->assertSame( 'B', $sources[0]->title );
	}

	// ── get_context_for_query ─────────────────────────────────────────────

	/**
	 * Test get_context_for_query returns empty string when no results.
	 */
	public function test_get_context_for_query_returns_empty_when_no_results(): void {
		$result = Knowledge::get_context_for_query( 'xyzzy_nonexistent_query_12345' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test get_context_for_query returns string.
	 */
	public function test_get_context_for_query_returns_string(): void {
		$result = Knowledge::get_context_for_query( 'test query' );
		$this->assertIsString( $result );
	}

	// ── delete_source ─────────────────────────────────────────────────────

	/**
	 * Test delete_source removes source and its chunks.
	 */
	public function test_delete_source_removes_source(): void {
		$col_id    = KnowledgeDatabase::create_collection( [
			'name' => 'Delete Source Test Collection',
			'slug' => 'delete-source-test-collection',
		] );
		$source_id = KnowledgeDatabase::create_source( [
			'collection_id' => $col_id,
			'source_type'   => 'post',
			'source_id'     => 1,
			'title'         => 'Source to Delete',
		] );
		KnowledgeDatabase::insert_chunks( $col_id, $source_id, [
			[ 'text' => 'Chunk to delete', 'index' => 0 ],
		] );

		$result = Knowledge::delete_source( $source_id );

		$this->assertTrue( $result );
		$this->assertNull( KnowledgeDatabase::get_source( $source_id ) );
	}

	// ── reindex_collection ────────────────────────────────────────────────

	/**
	 * Test reindex_collection returns WP_Error for non-existent collection.
	 */
	public function test_reindex_collection_returns_wp_error_for_nonexistent(): void {
		$result = Knowledge::reindex_collection( 999999 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	/**
	 * Test reindex_collection returns stats array.
	 */
	public function test_reindex_collection_returns_stats(): void {
		$col_id = KnowledgeDatabase::create_collection( [
			'name'          => 'Reindex Collection',
			'slug'          => 'reindex-collection',
			'source_config' => [ 'post_types' => [ 'post' ] ],
		] );

		$result = Knowledge::reindex_collection( $col_id );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'indexed', $result );
		$this->assertArrayHasKey( 'skipped', $result );
		$this->assertArrayHasKey( 'errors', $result );
	}

	/**
	 * Test reindex_collection indexes published posts.
	 */
	public function test_reindex_collection_indexes_published_posts(): void {
		$this->factory->post->create( [
			'post_status'  => 'publish',
			'post_title'   => 'Reindex Post 1',
			'post_content' => 'Content for reindex test post one.',
		] );
		$this->factory->post->create( [
			'post_status'  => 'publish',
			'post_title'   => 'Reindex Post 2',
			'post_content' => 'Content for reindex test post two.',
		] );

		$col_id = KnowledgeDatabase::create_collection( [
			'name'          => 'Reindex Posts Collection',
			'slug'          => 'reindex-posts-collection',
			'source_config' => [ 'post_types' => [ 'post' ] ],
		] );

		$stats = Knowledge::reindex_collection( $col_id );

		$this->assertIsArray( $stats );
		$this->assertGreaterThanOrEqual( 2, $stats['indexed'] );
		$this->assertSame( 0, $stats['errors'] );
	}
}
