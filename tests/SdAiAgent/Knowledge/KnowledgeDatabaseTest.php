<?php
/**
 * Test case for KnowledgeDatabase class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Knowledge;

use SdAiAgent\Core\Database;
use SdAiAgent\Knowledge\KnowledgeDatabase;
use WP_UnitTestCase;

/**
 * Test KnowledgeDatabase CRUD operations.
 */
class KnowledgeDatabaseTest extends WP_UnitTestCase {

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

	// ── Table names ───────────────────────────────────────────────────────

	/**
	 * Test collections_table returns correct prefixed name.
	 */
	public function test_collections_table_name(): void {
		global $wpdb;
		$expected = $wpdb->prefix . 'sd_ai_agent_knowledge_collections';
		$this->assertSame( $expected, KnowledgeDatabase::collections_table() );
	}

	/**
	 * Test sources_table returns correct prefixed name.
	 */
	public function test_sources_table_name(): void {
		global $wpdb;
		$expected = $wpdb->prefix . 'sd_ai_agent_knowledge_sources';
		$this->assertSame( $expected, KnowledgeDatabase::sources_table() );
	}

	/**
	 * Test chunks_table returns correct prefixed name.
	 */
	public function test_chunks_table_name(): void {
		global $wpdb;
		$expected = $wpdb->prefix . 'sd_ai_agent_knowledge_chunks';
		$this->assertSame( $expected, KnowledgeDatabase::chunks_table() );
	}

	// ── get_schema ────────────────────────────────────────────────────────

	/**
	 * Test get_schema returns a non-empty string.
	 */
	public function test_get_schema_returns_string(): void {
		$schema = KnowledgeDatabase::get_schema( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
		$this->assertIsString( $schema );
		$this->assertNotEmpty( $schema );
	}

	/**
	 * Test get_schema contains all three table CREATE statements.
	 */
	public function test_get_schema_contains_all_tables(): void {
		$schema = KnowledgeDatabase::get_schema( 'DEFAULT CHARACTER SET utf8mb4' );

		$this->assertStringContainsString( 'sd_ai_agent_knowledge_collections', $schema );
		$this->assertStringContainsString( 'sd_ai_agent_knowledge_sources', $schema );
		$this->assertStringContainsString( 'sd_ai_agent_knowledge_chunks', $schema );
	}

	// ── Collections CRUD ──────────────────────────────────────────────────

	/**
	 * Test create_collection returns integer ID.
	 */
	public function test_create_collection_returns_id(): void {
		$id = KnowledgeDatabase::create_collection( [
			'name' => 'Test Collection',
			'slug' => 'test-collection',
		] );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	/**
	 * Test get_collection returns null for non-existent ID.
	 */
	public function test_get_collection_returns_null_for_nonexistent(): void {
		$result = KnowledgeDatabase::get_collection( 999999 );
		$this->assertNull( $result );
	}

	/**
	 * Test get_collection returns correct data.
	 */
	public function test_get_collection_returns_data(): void {
		$id  = KnowledgeDatabase::create_collection( [
			'name'        => 'My Collection',
			'slug'        => 'my-collection',
			'description' => 'A test collection',
		] );
		$col = KnowledgeDatabase::get_collection( $id );

		$this->assertNotNull( $col );
		$this->assertSame( 'My Collection', $col->name );
		$this->assertSame( 'my-collection', $col->slug );
		$this->assertSame( 'A test collection', $col->description );
	}

	/**
	 * Test get_collection decodes source_config JSON.
	 */
	public function test_get_collection_decodes_source_config(): void {
		$config = [ 'post_types' => [ 'post', 'page' ] ];
		$id     = KnowledgeDatabase::create_collection( [
			'name'          => 'Config Collection',
			'slug'          => 'config-collection',
			'source_config' => $config,
		] );
		$col    = KnowledgeDatabase::get_collection( $id );

		$this->assertIsArray( $col->source_config );
		$this->assertArrayHasKey( 'post_types', $col->source_config );
		$this->assertContains( 'post', $col->source_config['post_types'] );
	}

	/**
	 * Test get_collection_by_slug returns null for non-existent slug.
	 */
	public function test_get_collection_by_slug_returns_null_for_nonexistent(): void {
		$result = KnowledgeDatabase::get_collection_by_slug( 'nonexistent-slug-xyz' );
		$this->assertNull( $result );
	}

	/**
	 * Test get_collection_by_slug returns correct collection.
	 */
	public function test_get_collection_by_slug_returns_collection(): void {
		KnowledgeDatabase::create_collection( [
			'name' => 'Slug Test',
			'slug' => 'slug-test-collection',
		] );

		$col = KnowledgeDatabase::get_collection_by_slug( 'slug-test-collection' );

		$this->assertNotNull( $col );
		$this->assertSame( 'Slug Test', $col->name );
	}

	/**
	 * Test update_collection returns false for empty data.
	 */
	public function test_update_collection_returns_false_for_empty_data(): void {
		$id     = KnowledgeDatabase::create_collection( [
			'name' => 'Update Empty Test',
			'slug' => 'update-empty-test',
		] );
		$result = KnowledgeDatabase::update_collection( $id, [] );

		$this->assertFalse( $result );
	}

	/**
	 * Test update_collection updates allowed fields.
	 */
	public function test_update_collection_updates_fields(): void {
		$id = KnowledgeDatabase::create_collection( [
			'name' => 'Original Name',
			'slug' => 'original-name',
		] );

		KnowledgeDatabase::update_collection( $id, [ 'name' => 'Updated Name' ] );

		$col = KnowledgeDatabase::get_collection( $id );
		$this->assertSame( 'Updated Name', $col->name );
	}

	/**
	 * Test update_collection ignores disallowed fields.
	 */
	public function test_update_collection_ignores_disallowed_fields(): void {
		$id = KnowledgeDatabase::create_collection( [
			'name' => 'Guard Test',
			'slug' => 'guard-test',
		] );

		// 'id' is not in the allowed list — should be ignored.
		$result = KnowledgeDatabase::update_collection( $id, [ 'id' => 99999 ] );

		// Returns false because no allowed fields were provided.
		$this->assertFalse( $result );
	}

	/**
	 * Test delete_collection removes collection and cascades to sources and chunks.
	 */
	public function test_delete_collection_cascades(): void {
		$col_id    = KnowledgeDatabase::create_collection( [
			'name' => 'Delete Cascade Test',
			'slug' => 'delete-cascade-test',
		] );
		$source_id = KnowledgeDatabase::create_source( [
			'collection_id' => $col_id,
			'source_type'   => 'post',
			'source_id'     => 1,
			'title'         => 'Test Source',
		] );
		KnowledgeDatabase::insert_chunks( $col_id, $source_id, [
			[ 'text' => 'Chunk text', 'index' => 0 ],
		] );

		$result = KnowledgeDatabase::delete_collection( $col_id );

		$this->assertTrue( $result );
		$this->assertNull( KnowledgeDatabase::get_collection( $col_id ) );
		$this->assertNull( KnowledgeDatabase::get_source( $source_id ) );
	}

	/**
	 * Test list_collections returns array.
	 */
	public function test_list_collections_returns_array(): void {
		$result = KnowledgeDatabase::list_collections();
		$this->assertIsArray( $result );
	}

	/**
	 * Test list_collections returns all collections.
	 */
	public function test_list_collections_returns_all(): void {
		KnowledgeDatabase::create_collection( [ 'name' => 'Col A', 'slug' => 'col-a' ] );
		KnowledgeDatabase::create_collection( [ 'name' => 'Col B', 'slug' => 'col-b' ] );

		$collections = KnowledgeDatabase::list_collections();
		$this->assertCount( 2, $collections );
	}

	/**
	 * Test list_collections filters by status.
	 */
	public function test_list_collections_filters_by_status(): void {
		$id = KnowledgeDatabase::create_collection( [ 'name' => 'Active Col', 'slug' => 'active-col' ] );
		KnowledgeDatabase::update_collection( $id, [ 'status' => 'archived' ] );

		$active   = KnowledgeDatabase::list_collections( 'active' );
		$archived = KnowledgeDatabase::list_collections( 'archived' );

		$this->assertCount( 0, $active );
		$this->assertCount( 1, $archived );
	}

	// ── Sources CRUD ──────────────────────────────────────────────────────

	/**
	 * Test create_source returns integer ID.
	 */
	public function test_create_source_returns_id(): void {
		$col_id = KnowledgeDatabase::create_collection( [ 'name' => 'Source Test Col', 'slug' => 'source-test-col' ] );
		$id     = KnowledgeDatabase::create_source( [
			'collection_id' => $col_id,
			'source_type'   => 'post',
			'source_id'     => 42,
			'title'         => 'Test Post',
		] );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	/**
	 * Test get_source returns null for non-existent ID.
	 */
	public function test_get_source_returns_null_for_nonexistent(): void {
		$result = KnowledgeDatabase::get_source( 999999 );
		$this->assertNull( $result );
	}

	/**
	 * Test get_source returns correct data.
	 */
	public function test_get_source_returns_data(): void {
		$col_id    = KnowledgeDatabase::create_collection( [ 'name' => 'Get Source Col', 'slug' => 'get-source-col' ] );
		$source_id = KnowledgeDatabase::create_source( [
			'collection_id' => $col_id,
			'source_type'   => 'post',
			'source_id'     => 99,
			'title'         => 'My Post',
			'content_hash'  => md5( 'test content' ),
		] );

		$source = KnowledgeDatabase::get_source( $source_id );

		$this->assertNotNull( $source );
		$this->assertSame( 'My Post', $source->title );
		$this->assertSame( 'post', $source->source_type );
		$this->assertSame( 'pending', $source->status );
	}

	/**
	 * Test find_source returns null when not found.
	 */
	public function test_find_source_returns_null_when_not_found(): void {
		$col_id = KnowledgeDatabase::create_collection( [ 'name' => 'Find Source Col', 'slug' => 'find-source-col' ] );
		$result = KnowledgeDatabase::find_source( $col_id, 'post', 999 );

		$this->assertNull( $result );
	}

	/**
	 * Test find_source returns existing source.
	 */
	public function test_find_source_returns_existing_source(): void {
		$col_id = KnowledgeDatabase::create_collection( [ 'name' => 'Find Source Col 2', 'slug' => 'find-source-col-2' ] );
		KnowledgeDatabase::create_source( [
			'collection_id' => $col_id,
			'source_type'   => 'post',
			'source_id'     => 77,
			'title'         => 'Findable Post',
		] );

		$source = KnowledgeDatabase::find_source( $col_id, 'post', 77 );

		$this->assertNotNull( $source );
		$this->assertSame( 'Findable Post', $source->title );
	}

	/**
	 * Test update_source returns false for empty data.
	 */
	public function test_update_source_returns_false_for_empty_data(): void {
		$col_id    = KnowledgeDatabase::create_collection( [ 'name' => 'Update Source Col', 'slug' => 'update-source-col' ] );
		$source_id = KnowledgeDatabase::create_source( [
			'collection_id' => $col_id,
			'source_type'   => 'post',
			'source_id'     => 1,
			'title'         => 'Update Empty Test',
		] );

		$result = KnowledgeDatabase::update_source( $source_id, [] );
		$this->assertFalse( $result );
	}

	/**
	 * Test update_source updates status.
	 */
	public function test_update_source_updates_status(): void {
		$col_id    = KnowledgeDatabase::create_collection( [ 'name' => 'Status Update Col', 'slug' => 'status-update-col' ] );
		$source_id = KnowledgeDatabase::create_source( [
			'collection_id' => $col_id,
			'source_type'   => 'post',
			'source_id'     => 1,
			'title'         => 'Status Test',
		] );

		KnowledgeDatabase::update_source( $source_id, [ 'status' => 'indexed' ] );

		$source = KnowledgeDatabase::get_source( $source_id );
		$this->assertSame( 'indexed', $source->status );
	}

	/**
	 * Test get_sources_for_collection returns array.
	 */
	public function test_get_sources_for_collection_returns_array(): void {
		$col_id = KnowledgeDatabase::create_collection( [ 'name' => 'Sources List Col', 'slug' => 'sources-list-col' ] );
		KnowledgeDatabase::create_source( [
			'collection_id' => $col_id,
			'source_type'   => 'post',
			'source_id'     => 1,
			'title'         => 'Source 1',
		] );
		KnowledgeDatabase::create_source( [
			'collection_id' => $col_id,
			'source_type'   => 'post',
			'source_id'     => 2,
			'title'         => 'Source 2',
		] );

		$sources = KnowledgeDatabase::get_sources_for_collection( $col_id );

		$this->assertIsArray( $sources );
		$this->assertCount( 2, $sources );
	}

	/**
	 * Test delete_source removes source and its chunks.
	 */
	public function test_delete_source_removes_source_and_chunks(): void {
		$col_id    = KnowledgeDatabase::create_collection( [ 'name' => 'Delete Source Col', 'slug' => 'delete-source-col' ] );
		$source_id = KnowledgeDatabase::create_source( [
			'collection_id' => $col_id,
			'source_type'   => 'post',
			'source_id'     => 1,
			'title'         => 'Delete Me',
		] );
		KnowledgeDatabase::insert_chunks( $col_id, $source_id, [
			[ 'text' => 'Chunk 1', 'index' => 0 ],
			[ 'text' => 'Chunk 2', 'index' => 1 ],
		] );

		$result = KnowledgeDatabase::delete_source( $source_id );

		$this->assertTrue( $result );
		$this->assertNull( KnowledgeDatabase::get_source( $source_id ) );
	}

	// ── Chunks ────────────────────────────────────────────────────────────

	/**
	 * Test insert_chunks returns count of inserted chunks.
	 */
	public function test_insert_chunks_returns_count(): void {
		$col_id    = KnowledgeDatabase::create_collection( [ 'name' => 'Chunks Col', 'slug' => 'chunks-col' ] );
		$source_id = KnowledgeDatabase::create_source( [
			'collection_id' => $col_id,
			'source_type'   => 'post',
			'source_id'     => 1,
			'title'         => 'Chunks Source',
		] );

		$inserted = KnowledgeDatabase::insert_chunks( $col_id, $source_id, [
			[ 'text' => 'First chunk', 'index' => 0 ],
			[ 'text' => 'Second chunk', 'index' => 1 ],
			[ 'text' => 'Third chunk', 'index' => 2 ],
		] );

		$this->assertSame( 3, $inserted );
	}

	/**
	 * Test insert_chunks returns 0 for empty array.
	 */
	public function test_insert_chunks_returns_zero_for_empty(): void {
		$col_id    = KnowledgeDatabase::create_collection( [ 'name' => 'Empty Chunks Col', 'slug' => 'empty-chunks-col' ] );
		$source_id = KnowledgeDatabase::create_source( [
			'collection_id' => $col_id,
			'source_type'   => 'post',
			'source_id'     => 1,
			'title'         => 'Empty Chunks Source',
		] );

		$inserted = KnowledgeDatabase::insert_chunks( $col_id, $source_id, [] );

		$this->assertSame( 0, $inserted );
	}

	/**
	 * Test insert_chunks stores metadata as JSON.
	 */
	public function test_insert_chunks_stores_metadata(): void {
		global $wpdb;

		$col_id    = KnowledgeDatabase::create_collection( [ 'name' => 'Meta Chunks Col', 'slug' => 'meta-chunks-col' ] );
		$source_id = KnowledgeDatabase::create_source( [
			'collection_id' => $col_id,
			'source_type'   => 'post',
			'source_id'     => 1,
			'title'         => 'Meta Chunks Source',
		] );

		KnowledgeDatabase::insert_chunks( $col_id, $source_id, [
			[
				'text'     => 'Chunk with metadata',
				'index'    => 0,
				'metadata' => [ 'post_type' => 'post', 'categories' => [ 'news' ] ],
			],
		] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-only query.
		$chunk = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE source_id = %d',
				KnowledgeDatabase::chunks_table(),
				$source_id
			)
		);

		$this->assertNotNull( $chunk );
		$metadata = json_decode( $chunk->metadata, true );
		$this->assertIsArray( $metadata );
		$this->assertSame( 'post', $metadata['post_type'] );
	}

	/**
	 * Test delete_chunks_for_source returns count of deleted rows.
	 */
	public function test_delete_chunks_for_source_returns_count(): void {
		$col_id    = KnowledgeDatabase::create_collection( [ 'name' => 'Delete Chunks Col', 'slug' => 'delete-chunks-col' ] );
		$source_id = KnowledgeDatabase::create_source( [
			'collection_id' => $col_id,
			'source_type'   => 'post',
			'source_id'     => 1,
			'title'         => 'Delete Chunks Source',
		] );
		KnowledgeDatabase::insert_chunks( $col_id, $source_id, [
			[ 'text' => 'Chunk A', 'index' => 0 ],
			[ 'text' => 'Chunk B', 'index' => 1 ],
		] );

		$deleted = KnowledgeDatabase::delete_chunks_for_source( $source_id );

		$this->assertSame( 2, $deleted );
	}

	// ── get_total_chunk_count ─────────────────────────────────────────────

	/**
	 * Test get_total_chunk_count returns integer.
	 */
	public function test_get_total_chunk_count_returns_integer(): void {
		$count = KnowledgeDatabase::get_total_chunk_count();
		$this->assertIsInt( $count );
	}

	/**
	 * Test get_total_chunk_count increases after inserting chunks.
	 */
	public function test_get_total_chunk_count_increases(): void {
		$before    = KnowledgeDatabase::get_total_chunk_count();
		$col_id    = KnowledgeDatabase::create_collection( [ 'name' => 'Count Col', 'slug' => 'count-col' ] );
		$source_id = KnowledgeDatabase::create_source( [
			'collection_id' => $col_id,
			'source_type'   => 'post',
			'source_id'     => 1,
			'title'         => 'Count Source',
		] );
		KnowledgeDatabase::insert_chunks( $col_id, $source_id, [
			[ 'text' => 'Count chunk 1', 'index' => 0 ],
			[ 'text' => 'Count chunk 2', 'index' => 1 ],
		] );

		$after = KnowledgeDatabase::get_total_chunk_count();
		$this->assertSame( $before + 2, $after );
	}

	// ── recalculate_collection_chunk_count ────────────────────────────────

	/**
	 * Test recalculate_collection_chunk_count updates collection chunk_count.
	 */
	public function test_recalculate_collection_chunk_count(): void {
		$col_id    = KnowledgeDatabase::create_collection( [ 'name' => 'Recalc Col', 'slug' => 'recalc-col' ] );
		$source_id = KnowledgeDatabase::create_source( [
			'collection_id' => $col_id,
			'source_type'   => 'post',
			'source_id'     => 1,
			'title'         => 'Recalc Source',
		] );
		KnowledgeDatabase::insert_chunks( $col_id, $source_id, [
			[ 'text' => 'Recalc chunk 1', 'index' => 0 ],
			[ 'text' => 'Recalc chunk 2', 'index' => 1 ],
			[ 'text' => 'Recalc chunk 3', 'index' => 2 ],
		] );

		KnowledgeDatabase::recalculate_collection_chunk_count( $col_id );

		$col = KnowledgeDatabase::get_collection( $col_id );
		$this->assertSame( '3', $col->chunk_count );
	}

	// ── search_chunks ─────────────────────────────────────────────────────

	/**
	 * Test search_chunks returns array.
	 */
	public function test_search_chunks_returns_array(): void {
		$results = KnowledgeDatabase::search_chunks( 'test query' );
		$this->assertIsArray( $results );
	}

	/**
	 * Test search_chunks returns empty array for short single-character query.
	 */
	public function test_search_chunks_empty_for_short_query(): void {
		$results = KnowledgeDatabase::search_chunks( 'a' );
		$this->assertIsArray( $results );
		$this->assertEmpty( $results );
	}

	/**
	 * Test search_chunks returns empty array for empty query.
	 */
	public function test_search_chunks_empty_for_empty_query(): void {
		$results = KnowledgeDatabase::search_chunks( '' );
		$this->assertIsArray( $results );
		$this->assertEmpty( $results );
	}

	/**
	 * Test search_chunks handles natural-language questions with stopwords.
	 */
	public function test_search_chunks_handles_natural_language_question(): void {
		$col_id    = KnowledgeDatabase::create_collection( [ 'name' => 'Docs', 'slug' => 'docs' ] );
		$source_id = KnowledgeDatabase::create_source( [
			'collection_id' => $col_id,
			'source_type'   => 'static_file',
			'source_id'     => 101,
			'title'         => 'Managing Sites',
		] );

		KnowledgeDatabase::insert_chunks( $col_id, $source_id, [
			[
				'text'  => 'Sites are the core of your WaaS business. Ultimate Multisite can create new customer sites from templates.',
				'index' => 0,
			],
		] );

		$results = KnowledgeDatabase::search_chunks( 'how does Ultimate Multisite create new sites?', $col_id, 3 );

		$this->assertNotEmpty( $results );
		$this->assertSame( 'Managing Sites', $results[0]->source_title );
	}
}
