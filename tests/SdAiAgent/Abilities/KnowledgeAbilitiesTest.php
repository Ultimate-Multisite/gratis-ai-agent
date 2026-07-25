<?php
/**
 * Test case for KnowledgeAbilities class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\KnowledgeAbilities;
use SdAiAgent\Core\Database;
use SdAiAgent\Knowledge\KnowledgeDatabase;
use WP_UnitTestCase;

/**
 * Test KnowledgeAbilities handler methods.
 */
class KnowledgeAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Ensure custom tables exist before tests run.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Database::install();
	}

	/**
	 * Clean up knowledge data and public mode after each test.
	 */
	public function tear_down(): void {
		KnowledgeAbilities::clear_public_collection_allowlist();
		parent::tear_down();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE 1=1', KnowledgeDatabase::chunks_table() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE 1=1', KnowledgeDatabase::sources_table() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE 1=1', KnowledgeDatabase::collections_table() ) );
	}

	/**
	 * Test handle_knowledge_search with empty query returns WP_Error.
	 */
	public function test_handle_knowledge_search_empty_query() {
		$result = KnowledgeAbilities::handle_knowledge_search( [
			'query' => '',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'required', $result->get_error_message() );
	}

	/**
	 * Test handle_knowledge_search with missing query returns WP_Error.
	 */
	public function test_handle_knowledge_search_missing_query() {
		$result = KnowledgeAbilities::handle_knowledge_search( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test handle_knowledge_search with valid query returns array or WP_Error.
	 */
	public function test_handle_knowledge_search_valid_query() {
		$result = KnowledgeAbilities::handle_knowledge_search( [
			'query' => 'test search query',
		] );

		// Should return either an array with results/message, or a WP_Error.
		$this->assertTrue(
			is_array( $result ) || is_wp_error( $result ),
			'Result should be an array or WP_Error.'
		);

		if ( is_array( $result ) ) {
			$this->assertTrue(
				isset( $result['results'] ) || isset( $result['message'] ),
				'Array result should have results or message key.'
			);
		}
	}

	/**
	 * Test handle_knowledge_search with collection filter.
	 */
	public function test_handle_knowledge_search_with_collection() {
		$result = KnowledgeAbilities::handle_knowledge_search( [
			'query'      => 'test query',
			'collection' => 'nonexistent-collection',
		] );

		$this->assertIsArray( $result );
		// Should not throw an exception even with non-existent collection.
	}

	/**
	 * Test public chat search args hydrate from the customer turn when a fast model emits empty args.
	 */
	public function test_public_search_args_hydrate_from_default_query() {
		KnowledgeAbilities::set_public_collection_allowlist( [ 'docs' ], 'How do I embed the widget?' );

		$args = KnowledgeAbilities::hydrate_public_search_args( [] );

		$this->assertSame( 'How do I embed the widget?', $args['query'] );
		$this->assertArrayNotHasKey( 'collection', $args );
	}

	/**
	 * Test public chat searches every allowlisted collection when no collection is requested.
	 */
	public function test_public_search_without_collection_searches_allowlist() {
		$docs_id = KnowledgeDatabase::create_collection( [ 'name' => 'Docs', 'slug' => 'docs' ] );
		$code_id = KnowledgeDatabase::create_collection( [ 'name' => 'Code Reference', 'slug' => 'code-reference' ] );

		$docs_source = KnowledgeDatabase::create_source( [
			'collection_id' => $docs_id,
			'source_type'   => 'static_file',
			'source_id'     => 1,
			'title'         => 'General Docs',
		] );
		$code_source = KnowledgeDatabase::create_source( [
			'collection_id' => $code_id,
			'source_type'   => 'static_file',
			'source_id'     => 2,
			'title'         => 'Site Creation Reference',
		] );

		KnowledgeDatabase::insert_chunks( $docs_id, $docs_source, [
			[ 'text' => 'Ultimate Multisite overview and installation notes.', 'index' => 0 ],
		] );
		KnowledgeDatabase::insert_chunks( $code_id, $code_source, [
			[ 'text' => 'Ultimate Multisite creates new sites from templates during checkout.', 'index' => 0 ],
		] );

		KnowledgeAbilities::set_public_collection_allowlist( [ 'docs', 'code-reference' ], 'How are sites created?' );

		$result = KnowledgeAbilities::handle_knowledge_search( [
			'query' => 'how does Ultimate Multisite create new sites?',
		] );

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['count'] );
		$this->assertContains( 'Site Creation Reference', wp_list_pluck( $result['results'], 'source' ) );
	}

	/** Empty customer/public collection allowlists must not fall back to all knowledge. */
	public function test_empty_public_collection_allowlist_remains_an_active_deny_all_policy() {
		$collection_id = KnowledgeDatabase::create_collection( [ 'name' => 'Private Docs', 'slug' => 'private-docs' ] );
		$source_id     = KnowledgeDatabase::create_source(
			[
				'collection_id' => $collection_id,
				'source_type'   => 'static_file',
				'source_id'     => 20,
				'title'         => 'Private Docs',
			]
		);
		KnowledgeDatabase::insert_chunks(
			$collection_id,
			$source_id,
			[
				[ 'text' => 'This private-only document must not be returned.', 'index' => 0 ],
			]
		);

		KnowledgeAbilities::set_public_collection_allowlist( [], 'Can you find private docs?' );

		$this->assertTrue( KnowledgeAbilities::is_public_collection_mode() );
		$this->assertSame(
			'Can you find private docs?',
			KnowledgeAbilities::hydrate_public_search_args( [] )['query']
		);

		$result = KnowledgeAbilities::handle_knowledge_search( [ 'query' => 'private document' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['count'] );
	}

	/**
	 * Test public chat uses overview fallback for contextual/vague questions.
	 */
	public function test_public_search_uses_overview_fallback_for_vague_question() {
		$docs_id = KnowledgeDatabase::create_collection( [ 'name' => 'Docs', 'slug' => 'docs' ] );
		$source_id = KnowledgeDatabase::create_source( [
			'collection_id' => $docs_id,
			'source_type'   => 'static_file',
			'source_id'     => 3,
			'title'         => 'Ultimate Multisite Overview',
		] );

		KnowledgeDatabase::insert_chunks( $docs_id, $source_id, [
			[ 'text' => 'Overview: Ultimate Multisite is a platform for creating hosted WordPress multisite networks.', 'index' => 0 ],
		] );

		KnowledgeAbilities::set_public_collection_allowlist( [ 'docs' ], 'What is this thing?' );

		$result = KnowledgeAbilities::handle_knowledge_search( [
			'query' => 'what is this thing?',
		] );

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['count'] );
		$this->assertSame( 'Ultimate Multisite Overview', $result['results'][0]['source'] );
	}

	/**
	 * Test handle_knowledge_search result structure when results exist.
	 */
	public function test_handle_knowledge_search_result_structure() {
		$result = KnowledgeAbilities::handle_knowledge_search( [
			'query' => 'WordPress',
		] );

		$this->assertIsArray( $result );

		// If results key exists, verify its structure.
		if ( isset( $result['results'] ) ) {
			$this->assertIsArray( $result['results'] );
		}
	}
}
