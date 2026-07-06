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
use WP_UnitTestCase;

/**
 * Test KnowledgeAbilities handler methods.
 */
class KnowledgeAbilitiesTest extends WP_UnitTestCase {

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

		KnowledgeAbilities::clear_public_collection_allowlist();

		$this->assertSame( 'How do I embed the widget?', $args['query'] );
		$this->assertSame( 'docs', $args['collection'] );
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
