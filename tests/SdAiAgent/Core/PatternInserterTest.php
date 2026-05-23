<?php
/**
 * Test case for PatternInserter (GH#1748).
 *
 * Covers:
 *   - parse_pattern_id: numeric, prefixed, slug, invalid shapes.
 *   - validate_pattern_exists: synced (wp_block CPT) and registered patterns.
 *   - make_synced_ref: correct core/block structure.
 *   - expand_registered: inline expansion and empty-pattern handling.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1748
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\PatternInserter;
use WP_UnitTestCase;

/**
 * Integration tests for PatternInserter.
 *
 * Uses WP_UnitTestCase so WP functions (get_post, parse_blocks, etc.)
 * and the block patterns registry are available.
 */
class PatternInserterTest extends WP_UnitTestCase {

	// ── parse_pattern_id ──────────────────────────────────────────────────

	/**
	 * Numeric int → synced.
	 */
	public function test_parse_numeric_int_returns_synced(): void {
		$result = PatternInserter::parse_pattern_id( 42 );
		$this->assertIsArray( $result );
		$this->assertSame( 'synced', $result['type'] );
		$this->assertSame( 42, $result['id'] );
	}

	/**
	 * Numeric string → synced.
	 */
	public function test_parse_numeric_string_returns_synced(): void {
		$result = PatternInserter::parse_pattern_id( '42' );
		$this->assertIsArray( $result );
		$this->assertSame( 'synced', $result['type'] );
		$this->assertSame( 42, $result['id'] );
	}

	/**
	 * "wp-block:42" → synced with ID 42.
	 */
	public function test_parse_wp_block_prefix_returns_synced(): void {
		$result = PatternInserter::parse_pattern_id( 'wp-block:42' );
		$this->assertIsArray( $result );
		$this->assertSame( 'synced', $result['type'] );
		$this->assertSame( 42, $result['id'] );
	}

	/**
	 * "synced:99" → synced with ID 99.
	 */
	public function test_parse_synced_prefix_returns_synced(): void {
		$result = PatternInserter::parse_pattern_id( 'synced:99' );
		$this->assertIsArray( $result );
		$this->assertSame( 'synced', $result['type'] );
		$this->assertSame( 99, $result['id'] );
	}

	/**
	 * String slug → registered.
	 */
	public function test_parse_slug_returns_registered(): void {
		$result = PatternInserter::parse_pattern_id( 'core/quote' );
		$this->assertIsArray( $result );
		$this->assertSame( 'registered', $result['type'] );
		$this->assertSame( 'core/quote', $result['id'] );
	}

	/**
	 * Negative int → bad_pattern_id error.
	 */
	public function test_parse_negative_int_returns_error(): void {
		$result = PatternInserter::parse_pattern_id( -1 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bad_pattern_id', $result->get_error_code() );
	}

	/**
	 * Zero int → bad_pattern_id error.
	 */
	public function test_parse_zero_int_returns_error(): void {
		$result = PatternInserter::parse_pattern_id( 0 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bad_pattern_id', $result->get_error_code() );
	}

	/**
	 * Empty string → bad_pattern_id error.
	 */
	public function test_parse_empty_string_returns_error(): void {
		$result = PatternInserter::parse_pattern_id( '' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bad_pattern_id', $result->get_error_code() );
	}

	/**
	 * "wp-block:abc" → bad_pattern_id error.
	 */
	public function test_parse_wp_block_non_numeric_returns_error(): void {
		$result = PatternInserter::parse_pattern_id( 'wp-block:abc' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bad_pattern_id', $result->get_error_code() );
	}

	/**
	 * "synced:0" → bad_pattern_id error.
	 */
	public function test_parse_synced_zero_returns_error(): void {
		$result = PatternInserter::parse_pattern_id( 'synced:0' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bad_pattern_id', $result->get_error_code() );
	}

	// ── make_synced_ref ───────────────────────────────────────────────────

	/**
	 * make_synced_ref returns a valid core/block reference block.
	 */
	public function test_make_synced_ref_returns_core_block(): void {
		$block = PatternInserter::make_synced_ref( 42 );
		$this->assertSame( 'core/block', $block['blockName'] );
		$this->assertSame( 42, $block['attrs']['ref'] );
		$this->assertSame( [], $block['innerBlocks'] );
		$this->assertSame( '', $block['innerHTML'] );
		$this->assertSame( [], $block['innerContent'] );
	}

	// ── validate_pattern_exists ───────────────────────────────────────────

	/**
	 * Nonexistent synced pattern returns pattern_not_found.
	 */
	public function test_validate_nonexistent_synced_returns_error(): void {
		$result = PatternInserter::validate_pattern_exists( 'synced', 999999 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'pattern_not_found', $result->get_error_code() );
	}

	/**
	 * A real published wp_block post validates successfully.
	 */
	public function test_validate_existing_synced_returns_true(): void {
		$post_id = self::factory()->post->create( [
			'post_type'    => 'wp_block',
			'post_status'  => 'publish',
			'post_title'   => 'Test Pattern',
			'post_content' => '<!-- wp:paragraph --><p>Test</p><!-- /wp:paragraph -->',
		] );
		$this->assertIsInt( $post_id );

		$result = PatternInserter::validate_pattern_exists( 'synced', $post_id );
		$this->assertTrue( $result );
	}

	/**
	 * A draft wp_block post fails validation.
	 */
	public function test_validate_draft_synced_returns_error(): void {
		$post_id = self::factory()->post->create( [
			'post_type'    => 'wp_block',
			'post_status'  => 'draft',
			'post_title'   => 'Draft Pattern',
			'post_content' => '<!-- wp:paragraph --><p>Draft</p><!-- /wp:paragraph -->',
		] );
		$this->assertIsInt( $post_id );

		$result = PatternInserter::validate_pattern_exists( 'synced', $post_id );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'pattern_not_found', $result->get_error_code() );
	}

	/**
	 * A regular post (not wp_block) fails synced validation.
	 */
	public function test_validate_non_wp_block_post_returns_error(): void {
		$post_id = self::factory()->post->create( [
			'post_type'   => 'post',
			'post_status' => 'publish',
		] );
		$this->assertIsInt( $post_id );

		$result = PatternInserter::validate_pattern_exists( 'synced', $post_id );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'pattern_not_found', $result->get_error_code() );
	}

	/**
	 * Nonexistent registered pattern returns pattern_not_found with suggestions.
	 */
	public function test_validate_nonexistent_registered_returns_error_with_suggestions(): void {
		$result = PatternInserter::validate_pattern_exists( 'registered', 'nonexistent/pattern-that-does-not-exist' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'pattern_not_found', $result->get_error_code() );

		// Error data should include suggestions array.
		$data = $result->get_error_data( 'pattern_not_found' );
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'suggestions', $data );
		$this->assertIsArray( $data['suggestions'] );
	}

	// ── expand_registered ─────────────────────────────────────────────────

	/**
	 * Expanding a nonexistent pattern returns pattern_not_found.
	 */
	public function test_expand_nonexistent_pattern_returns_error(): void {
		$result = PatternInserter::expand_registered( 'nonexistent/pattern' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'pattern_not_found', $result->get_error_code() );
	}

	/**
	 * Expanding a registered pattern returns parsed blocks.
	 *
	 * Registers a test pattern, expands it, and verifies structure.
	 */
	public function test_expand_registered_returns_parsed_blocks(): void {
		// Register a test pattern.
		$pattern_name = 'sd-ai-agent-test/simple-quote';
		register_block_pattern(
			$pattern_name,
			[
				'title'   => 'Test Quote',
				'content' => '<!-- wp:quote --><blockquote class="wp-block-quote"><p>Test quote</p></blockquote><!-- /wp:quote -->',
			]
		);

		$result = PatternInserter::expand_registered( $pattern_name );
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );

		// First block should be core/quote.
		$this->assertSame( 'core/quote', $result[0]['blockName'] );

		// Cleanup.
		unregister_block_pattern( $pattern_name );
	}

	/**
	 * Expanding a pattern with multiple blocks returns all of them.
	 */
	public function test_expand_multi_block_pattern_returns_all_blocks(): void {
		$pattern_name = 'sd-ai-agent-test/multi-block';
		register_block_pattern(
			$pattern_name,
			[
				'title'   => 'Multi Block',
				'content' => '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Title</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Paragraph</p><!-- /wp:paragraph -->',
			]
		);

		$result = PatternInserter::expand_registered( $pattern_name );
		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertSame( 'core/heading', $result[0]['blockName'] );
		$this->assertSame( 'core/paragraph', $result[1]['blockName'] );

		// Cleanup.
		unregister_block_pattern( $pattern_name );
	}

	/**
	 * Expanding a registered pattern with empty content returns pattern_empty.
	 */
	public function test_expand_empty_content_pattern_returns_error(): void {
		$pattern_name = 'sd-ai-agent-test/empty';
		register_block_pattern(
			$pattern_name,
			[
				'title'   => 'Empty',
				'content' => '',
			]
		);

		$result = PatternInserter::expand_registered( $pattern_name );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'pattern_empty', $result->get_error_code() );

		// Cleanup.
		unregister_block_pattern( $pattern_name );
	}

	/**
	 * Expanding a pattern with only whitespace returns pattern_empty.
	 */
	public function test_expand_whitespace_only_pattern_returns_error(): void {
		$pattern_name = 'sd-ai-agent-test/whitespace';
		register_block_pattern(
			$pattern_name,
			[
				'title'   => 'Whitespace',
				'content' => '   ',
			]
		);

		$result = PatternInserter::expand_registered( $pattern_name );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'pattern_empty', $result->get_error_code() );

		// Cleanup.
		unregister_block_pattern( $pattern_name );
	}

	/**
	 * Validate that a known registered pattern passes validation.
	 *
	 * Registers a test pattern and verifies it validates successfully.
	 */
	public function test_validate_existing_registered_returns_true(): void {
		$pattern_name = 'sd-ai-agent-test/validate-exists';
		register_block_pattern(
			$pattern_name,
			[
				'title'   => 'Validate Exists',
				'content' => '<!-- wp:paragraph --><p>exists</p><!-- /wp:paragraph -->',
			]
		);

		$result = PatternInserter::validate_pattern_exists( 'registered', $pattern_name );
		$this->assertTrue( $result );

		// Cleanup.
		unregister_block_pattern( $pattern_name );
	}
}
