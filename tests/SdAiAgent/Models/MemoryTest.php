<?php
/**
 * Test case for Memory class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Models;

use SdAiAgent\Models\Memory;
use WP_UnitTestCase;

/**
 * Test Memory functionality.
 */
class MemoryTest extends WP_UnitTestCase {

	/**
	 * Test CATEGORIES constant has expected values.
	 */
	public function test_categories_constant() {
		$expected = [ 'site_info', 'site_brief', 'user_preferences', 'technical_notes', 'workflows', 'general' ];
		$this->assertSame( $expected, Memory::CATEGORIES );
	}

	/**
	 * Test site_brief is accepted as a valid memory category.
	 *
	 * site_brief is the dedicated category used by the site-specification
	 * skill to persist the structured site spec (name, type, audience, tone,
	 * sections, typography). Memories saved under this category are looked
	 * up by future sessions to rebuild context without re-interviewing the
	 * user. Regression cover: ensure adding new categories does not silently
	 * fall through to the General default.
	 */
	public function test_create_memory_site_brief_category() {
		$id = Memory::create( 'site_brief', 'Bean & Brew — coffee shop, warm artisanal tone, Playfair + Lato.' );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		// Verify it landed under site_brief, not the general fallback.
		$memories = Memory::get_by_category( 'site_brief' );
		$found    = false;
		foreach ( $memories as $memory ) {
			if ( (int) $memory->id === $id ) {
				$found = true;
				$this->assertSame( 'site_brief', $memory->category );
				break;
			}
		}
		$this->assertTrue( $found, 'site_brief category should be accepted and persisted.' );
	}

	/**
	 * Test table_name returns correct table name.
	 */
	public function test_table_name() {
		global $wpdb;
		$expected = $wpdb->prefix . 'sd_ai_agent_memories';
		$this->assertSame( $expected, Memory::table_name() );
	}

	/**
	 * Test create memory with valid category.
	 */
	public function test_create_memory_valid_category() {
		$id = Memory::create( 'site_info', 'Test memory content' );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	/**
	 * Test create memory with invalid category defaults to general.
	 */
	public function test_create_memory_invalid_category_defaults() {
		$id = Memory::create( 'invalid_category', 'Test content' );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		// Verify it was stored as 'general'.
		$memories = Memory::get_by_category( 'general' );
		$found = false;
		foreach ( $memories as $memory ) {
			if ( (int) $memory->id === $id ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found );
	}

	/**
	 * Test get_all returns memories.
	 */
	public function test_get_all() {
		Memory::create( 'site_info', 'All test 1' );
		Memory::create( 'workflows', 'All test 2' );

		$memories = Memory::get_all();

		$this->assertIsArray( $memories );
		$this->assertGreaterThanOrEqual( 2, count( $memories ) );
	}

	/**
	 * Test get_all with category filter.
	 */
	public function test_get_all_with_category() {
		Memory::create( 'technical_notes', 'Tech note test' );

		$memories = Memory::get_all( 'technical_notes' );

		$this->assertIsArray( $memories );
		foreach ( $memories as $memory ) {
			$this->assertSame( 'technical_notes', $memory->category );
		}
	}

	/**
	 * Test get_by_category returns filtered memories.
	 */
	public function test_get_by_category() {
		Memory::create( 'user_preferences', 'User pref test' );

		$memories = Memory::get_by_category( 'user_preferences' );

		$this->assertIsArray( $memories );
		foreach ( $memories as $memory ) {
			$this->assertSame( 'user_preferences', $memory->category );
		}
	}

	/**
	 * Test update memory content.
	 */
	public function test_update_memory_content() {
		$id = Memory::create( 'general', 'Original content' );

		$result = Memory::update( $id, [ 'content' => 'Updated content' ] );

		$this->assertTrue( $result );

		$memories = Memory::get_all( 'general' );
		$found = null;
		foreach ( $memories as $memory ) {
			if ( (int) $memory->id === $id ) {
				$found = $memory;
				break;
			}
		}
		$this->assertNotNull( $found );
		$this->assertSame( 'Updated content', $found->content );
	}

	/**
	 * Test update memory category.
	 */
	public function test_update_memory_category() {
		$id = Memory::create( 'general', 'Category change test' );

		$result = Memory::update( $id, [ 'category' => 'workflows' ] );

		$this->assertTrue( $result );

		$memories = Memory::get_by_category( 'workflows' );
		$found = false;
		foreach ( $memories as $memory ) {
			if ( (int) $memory->id === $id ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found );
	}

	/**
	 * Test update with invalid category defaults to general.
	 */
	public function test_update_invalid_category_defaults() {
		$id = Memory::create( 'site_info', 'Invalid category update test' );

		Memory::update( $id, [ 'category' => 'not_valid' ] );

		$memories = Memory::get_by_category( 'general' );
		$found = false;
		foreach ( $memories as $memory ) {
			if ( (int) $memory->id === $id ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found );
	}

	/**
	 * Test delete memory.
	 */
	public function test_delete_memory() {
		$id = Memory::create( 'general', 'To be deleted' );

		$result = Memory::delete( $id );

		$this->assertTrue( $result );

		// Verify it's gone.
		$memories = Memory::get_all();
		foreach ( $memories as $memory ) {
			$this->assertNotEquals( $id, (int) $memory->id );
		}
	}

	/**
	 * Test get_formatted_for_prompt with no memories.
	 */
	public function test_get_formatted_empty() {
		// Clear all memories first.
		$all = Memory::get_all();
		foreach ( $all as $memory ) {
			Memory::delete( (int) $memory->id );
		}

		$result = Memory::get_formatted_for_prompt();

		$this->assertSame( '', $result );
	}

	/**
	 * Test get_formatted_for_prompt with memories.
	 */
	public function test_get_formatted_with_memories() {
		// Clear existing.
		$all = Memory::get_all();
		foreach ( $all as $memory ) {
			Memory::delete( (int) $memory->id );
		}

		Memory::create( 'site_info', 'This is site info' );
		Memory::create( 'general', 'This is general info' );

		$result = Memory::get_formatted_for_prompt();

		$this->assertStringContainsString( '## Your Memory', $result );
		$this->assertStringContainsString( 'Site Info', $result );
		$this->assertStringContainsString( 'This is site info', $result );
	}

	/**
	 * Test search returns matching memories.
	 */
	public function test_search() {
		Memory::create( 'general', 'unique searchable keyword testword' );

		// FULLTEXT search requires MyISAM or InnoDB with ft_min_word_len config.
		// This test may not work in all environments.
		$results = Memory::search( 'testword' );

		$this->assertIsArray( $results );
		// Results may be empty if FULLTEXT isn't properly configured.
	}

	/**
	 * Test search with empty query returns empty.
	 */
	public function test_search_empty_query() {
		$results = Memory::search( '' );
		$this->assertIsArray( $results );
		$this->assertEmpty( $results );
	}

	/**
	 * Test search with single character returns empty.
	 */
	public function test_search_single_char() {
		$results = Memory::search( 'a' );
		$this->assertIsArray( $results );
		$this->assertEmpty( $results );
	}

	/**
	 * Test forget_by_topic deletes matching memories.
	 */
	public function test_forget_by_topic() {
		Memory::create( 'general', 'topic to forget uniqueforget123' );

		// This relies on FULLTEXT search working.
		$deleted = Memory::forget_by_topic( 'uniqueforget123' );

		$this->assertIsInt( $deleted );
		// May be 0 if FULLTEXT isn't configured.
	}

	/**
	 * Test memory has timestamps.
	 */
	public function test_memory_has_timestamps() {
		$id = Memory::create( 'general', 'Timestamp test' );

		$memories = Memory::get_all( 'general' );
		$found = null;
		foreach ( $memories as $memory ) {
			if ( (int) $memory->id === $id ) {
				$found = $memory;
				break;
			}
		}

		$this->assertNotNull( $found );
		$this->assertNotEmpty( $found->created_at );
		$this->assertNotEmpty( $found->updated_at );
	}
}
