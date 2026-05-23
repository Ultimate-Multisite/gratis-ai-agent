<?php
/**
 * Test case for BlockAbilities class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\BlockAbilities;
use WP_UnitTestCase;

/**
 * Test BlockAbilities handler methods.
 */
class BlockAbilitiesTest extends WP_UnitTestCase {

	// ─── markdown-to-blocks ───────────────────────────────────────

	/**
	 * Test handle_markdown_to_blocks with valid markdown.
	 */
	public function test_handle_markdown_to_blocks_valid() {
		$result = BlockAbilities::handle_markdown_to_blocks( [
			'markdown' => "# Hello World\n\nThis is a paragraph.",
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'block_content', $result );
		$this->assertArrayHasKey( 'block_count', $result );
		$this->assertIsString( $result['block_content'] );
		$this->assertIsInt( $result['block_count'] );
		$this->assertGreaterThan( 0, $result['block_count'] );
	}

	/**
	 * Test handle_markdown_to_blocks output contains block markup.
	 */
	public function test_handle_markdown_to_blocks_contains_block_markup() {
		$result = BlockAbilities::handle_markdown_to_blocks( [
			'markdown' => "# Test Heading\n\nTest paragraph content.",
		] );

		$this->assertStringContainsString( '<!-- wp:', $result['block_content'] );
	}

	/**
	 * Test handle_markdown_to_blocks with empty markdown returns WP_Error.
	 */
	public function test_handle_markdown_to_blocks_empty_markdown() {
		$result = BlockAbilities::handle_markdown_to_blocks( [
			'markdown' => '',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test handle_markdown_to_blocks with missing markdown returns WP_Error.
	 */
	public function test_handle_markdown_to_blocks_missing_markdown() {
		$result = BlockAbilities::handle_markdown_to_blocks( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test handle_markdown_to_blocks with heading markdown.
	 *
	 * WordPress block serialization uses short names (e.g. "heading" not "core/heading").
	 */
	public function test_handle_markdown_to_blocks_heading() {
		$result = BlockAbilities::handle_markdown_to_blocks( [
			'markdown' => '## Section Title',
		] );

		$this->assertStringContainsString( 'wp:heading', $result['block_content'] );
	}

	/**
	 * Test handle_markdown_to_blocks with list markdown.
	 */
	public function test_handle_markdown_to_blocks_list() {
		$result = BlockAbilities::handle_markdown_to_blocks( [
			'markdown' => "- Item one\n- Item two\n- Item three",
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'block_content', $result );
		$this->assertGreaterThan( 0, $result['block_count'] );
	}

	// ─── list-block-types ─────────────────────────────────────────

	/**
	 * Test handle_list_block_types returns block list.
	 */
	public function test_handle_list_block_types_returns_array() {
		$result = BlockAbilities::handle_list_block_types( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'block_types', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'page', $result );
		$this->assertArrayHasKey( 'per_page', $result );
		$this->assertArrayHasKey( 'categories', $result );
	}

	/**
	 * Test handle_list_block_types default pagination.
	 */
	public function test_handle_list_block_types_default_pagination() {
		$result = BlockAbilities::handle_list_block_types( [] );

		$this->assertSame( 1, $result['page'] );
		$this->assertSame( 20, $result['per_page'] );
		$this->assertLessThanOrEqual( 20, count( $result['block_types'] ) );
	}

	/**
	 * Test handle_list_block_types each block has required fields.
	 */
	public function test_handle_list_block_types_block_structure() {
		$result = BlockAbilities::handle_list_block_types( [] );

		if ( ! empty( $result['block_types'] ) ) {
			$block = $result['block_types'][0];
			$this->assertArrayHasKey( 'name', $block );
			$this->assertArrayHasKey( 'title', $block );
			$this->assertArrayHasKey( 'description', $block );
			$this->assertArrayHasKey( 'category', $block );
			$this->assertArrayHasKey( 'keywords', $block );
		}
	}

	/**
	 * Test handle_list_block_types with search filter.
	 */
	public function test_handle_list_block_types_search_filter() {
		$result = BlockAbilities::handle_list_block_types( [
			'search' => 'paragraph',
		] );

		$this->assertIsArray( $result );
		// All results should contain 'paragraph' in name/title/keywords.
		foreach ( $result['block_types'] as $block ) {
			$searchable = strtolower( $block['name'] . ' ' . $block['title'] . ' ' . implode( ' ', $block['keywords'] ) );
			$this->assertStringContainsString( 'paragraph', $searchable );
		}
	}

	/**
	 * Test handle_list_block_types with per_page limit.
	 */
	public function test_handle_list_block_types_per_page() {
		$result = BlockAbilities::handle_list_block_types( [
			'per_page' => 5,
		] );

		$this->assertLessThanOrEqual( 5, count( $result['block_types'] ) );
		$this->assertSame( 5, $result['per_page'] );
	}

	/**
	 * Test handle_list_block_types includes tier and score fields.
	 */
	public function test_handle_list_block_types_includes_tier_and_score() {
		$result = BlockAbilities::handle_list_block_types( [] );

		if ( ! empty( $result['block_types'] ) ) {
			$block = $result['block_types'][0];
			$this->assertArrayHasKey( 'score', $block );
			$this->assertArrayHasKey( 'tier', $block );
			$this->assertIsInt( $block['score'] );
			$this->assertIsString( $block['tier'] );
			$this->assertContains( $block['tier'], [ 'preferred', 'acceptable', 'avoid', 'legacy' ] );
		}
	}

	/**
	 * Test handle_list_block_types includes suggested_replacement for legacy blocks.
	 */
	public function test_handle_list_block_types_legacy_block_has_replacement() {
		$result = BlockAbilities::handle_list_block_types( [] );

		// Find a legacy block (core/freeform is known to be legacy).
		$legacy_block = null;
		foreach ( $result['block_types'] as $block ) {
			if ( 'core/freeform' === $block['name'] ) {
				$legacy_block = $block;
				break;
			}
		}

		if ( $legacy_block ) {
			$this->assertSame( 'legacy', $legacy_block['tier'] );
			$this->assertArrayHasKey( 'suggested_replacement', $legacy_block );
			$this->assertNotNull( $legacy_block['suggested_replacement'] );
			$this->assertSame( 'core/group', $legacy_block['suggested_replacement'] );
		}
	}

	/**
	 * Test handle_list_block_types with tier filter.
	 */
	public function test_handle_list_block_types_tier_filter() {
		$result = BlockAbilities::handle_list_block_types( [
			'tier' => 'preferred',
		] );

		$this->assertIsArray( $result );
		// All results should have tier 'preferred'.
		foreach ( $result['block_types'] as $block ) {
			$this->assertSame( 'preferred', $block['tier'] );
		}
	}

	/**
	 * Test handle_list_block_types with avoid tier filter.
	 */
	public function test_handle_list_block_types_avoid_tier_filter() {
		$result = BlockAbilities::handle_list_block_types( [
			'tier' => 'avoid',
		] );

		$this->assertIsArray( $result );
		// All results should have tier 'avoid'.
		foreach ( $result['block_types'] as $block ) {
			$this->assertSame( 'avoid', $block['tier'] );
		}
	}

	// ─── get-block-type ───────────────────────────────────────────

	/**
	 * Test handle_get_block_type with valid block name.
	 */
	public function test_handle_get_block_type_valid() {
		$result = BlockAbilities::handle_get_block_type( [
			'name' => 'core/paragraph',
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'title', $result );
		$this->assertArrayHasKey( 'category', $result );
		$this->assertSame( 'core/paragraph', $result['name'] );
	}

	/**
	 * Test handle_get_block_type with non-existent block returns WP_Error.
	 */
	public function test_handle_get_block_type_not_found() {
		$result = BlockAbilities::handle_get_block_type( [
			'name' => 'nonexistent/block-xyz',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'not found', $result->get_error_message() );
	}

	/**
	 * Test handle_get_block_type with empty name returns WP_Error.
	 */
	public function test_handle_get_block_type_empty_name() {
		$result = BlockAbilities::handle_get_block_type( [
			'name' => '',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test handle_get_block_type returns attributes.
	 */
	public function test_handle_get_block_type_has_attributes() {
		$result = BlockAbilities::handle_get_block_type( [
			'name' => 'core/paragraph',
		] );

		$this->assertArrayHasKey( 'attributes', $result );
		$this->assertIsArray( $result['attributes'] );
	}

	// ─── list-block-patterns ──────────────────────────────────────

	/**
	 * Test handle_list_block_patterns returns array.
	 */
	public function test_handle_list_block_patterns_returns_array() {
		$result = BlockAbilities::handle_list_block_patterns( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'patterns', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'categories', $result );
		$this->assertIsArray( $result['patterns'] );
		$this->assertIsInt( $result['total'] );
	}

	/**
	 * Test handle_list_block_patterns default per_page is 10.
	 */
	public function test_handle_list_block_patterns_default_per_page() {
		$result = BlockAbilities::handle_list_block_patterns( [] );

		$this->assertLessThanOrEqual( 10, count( $result['patterns'] ) );
	}

	// ─── create-block-content ─────────────────────────────────────

	/**
	 * Test handle_create_block_content with paragraph block.
	 *
	 * WordPress block serialization uses short names (e.g. "wp:paragraph" not "core/paragraph").
	 */
	public function test_handle_create_block_content_paragraph() {
		$result = BlockAbilities::handle_create_block_content( [
			'blocks' => [
				[
					'blockName' => 'core/paragraph',
					'content'   => 'Hello world',
				],
			],
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'block_content', $result );
		$this->assertArrayHasKey( 'block_count', $result );
		$this->assertStringContainsString( 'wp:paragraph', $result['block_content'] );
		$this->assertStringContainsString( 'Hello world', $result['block_content'] );
	}

	/**
	 * Test handle_create_block_content with heading block.
	 *
	 * WordPress block serialization uses short names (e.g. "wp:heading" not "core/heading").
	 */
	public function test_handle_create_block_content_heading() {
		$result = BlockAbilities::handle_create_block_content( [
			'blocks' => [
				[
					'blockName' => 'core/heading',
					'attrs'     => [ 'level' => 2 ],
					'content'   => 'My Heading',
				],
			],
		] );

		$this->assertStringContainsString( 'wp:heading', $result['block_content'] );
		$this->assertStringContainsString( 'My Heading', $result['block_content'] );
	}

	/**
	 * Test handle_create_block_content with empty blocks returns WP_Error.
	 */
	public function test_handle_create_block_content_empty_blocks() {
		$result = BlockAbilities::handle_create_block_content( [
			'blocks' => [],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test handle_create_block_content with missing blocks returns WP_Error.
	 */
	public function test_handle_create_block_content_missing_blocks() {
		$result = BlockAbilities::handle_create_block_content( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test handle_create_block_content block_count matches input.
	 */
	public function test_handle_create_block_content_block_count() {
		$result = BlockAbilities::handle_create_block_content( [
			'blocks' => [
				[ 'blockName' => 'core/paragraph', 'content' => 'Para 1' ],
				[ 'blockName' => 'core/paragraph', 'content' => 'Para 2' ],
				[ 'blockName' => 'core/paragraph', 'content' => 'Para 3' ],
			],
		] );

		$this->assertSame( 3, $result['block_count'] );
	}

	// ─── parse-block-content ──────────────────────────────────────

	/**
	 * Test handle_parse_block_content with raw content.
	 */
	public function test_handle_parse_block_content_raw_content() {
		$content = '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->';

		$result = BlockAbilities::handle_parse_block_content( [
			'content' => $content,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'blocks', $result );
		$this->assertArrayHasKey( 'block_count', $result );
		$this->assertIsArray( $result['blocks'] );
		$this->assertGreaterThan( 0, $result['block_count'] );
	}

	/**
	 * Test handle_parse_block_content with post_id.
	 */
	public function test_handle_parse_block_content_post_id() {
		$post_id = $this->factory->post->create( [
			'post_content' => '<!-- wp:paragraph --><p>Test content</p><!-- /wp:paragraph -->',
			'post_status'  => 'publish',
		] );

		$result = BlockAbilities::handle_parse_block_content( [
			'post_id' => $post_id,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'blocks', $result );
		$this->assertGreaterThan( 0, $result['block_count'] );
	}

	/**
	 * Test handle_parse_block_content with non-existent post returns WP_Error.
	 */
	public function test_handle_parse_block_content_post_not_found() {
		$result = BlockAbilities::handle_parse_block_content( [
			'post_id' => 999999,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( '999999', $result->get_error_message() );
	}

	/**
	 * Test handle_parse_block_content with no input returns WP_Error.
	 */
	public function test_handle_parse_block_content_no_input() {
		$result = BlockAbilities::handle_parse_block_content( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test handle_parse_block_content parsed block has expected structure.
	 */
	public function test_handle_parse_block_content_block_structure() {
		$content = '<!-- wp:paragraph --><p>Test</p><!-- /wp:paragraph -->';

		$result = BlockAbilities::handle_parse_block_content( [
			'content' => $content,
		] );

		if ( ! empty( $result['blocks'] ) ) {
			$block = $result['blocks'][0];
			$this->assertArrayHasKey( 'blockName', $block );
			$this->assertArrayHasKey( 'attrs', $block );
			$this->assertArrayHasKey( 'innerHTML', $block );
		}
	}

	// ─── validate-block-content ───────────────────────────────────

	/**
	 * Test handle_validate_block_content emits the diff-interpretation hint
	 * when at least one block is invalid (GH#1589).
	 *
	 * Uses a heading block whose comment declares level:3 but whose HTML uses
	 * <h2>, which BlockValidator flags as structurally invalid.
	 */
	public function test_validate_block_content_hint_present_when_invalid() {
		$invalid_content = "<!-- wp:heading {\"level\":3} -->\n<h2 class=\"wp-block-heading\">x</h2>\n<!-- /wp:heading -->";

		$result = BlockAbilities::handle_validate_block_content( [
			'content' => $invalid_content,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'hint', $result );
		$this->assertStringContainsString( 'Expected/Actual diff is a structural change', $result['hint'] );
	}

	/**
	 * Test handle_validate_block_content does NOT emit the hint when all
	 * blocks are valid — no false positives (GH#1589).
	 */
	public function test_validate_block_content_hint_absent_when_valid() {
		$valid_content = "<!-- wp:paragraph -->\n<p>Hello world</p>\n<!-- /wp:paragraph -->";

		$result = BlockAbilities::handle_validate_block_content( [
			'content' => $valid_content,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'hint', $result );
	}

	// ─── get-page-blocks ──────────────────────────────────────────────────

	/**
	 * handle_get_page_blocks() returns WP_Error for missing post_id.
	 *
	 * @see GH#1707
	 */
	public function test_handle_get_page_blocks_missing_post_id(): void {
		$result = BlockAbilities::handle_get_page_blocks( [] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_post_id', $result->get_error_code() );
	}

	/**
	 * handle_get_page_blocks() returns WP_Error for a non-existent post.
	 *
	 * @see GH#1707
	 */
	public function test_handle_get_page_blocks_nonexistent_post(): void {
		$result = BlockAbilities::handle_get_page_blocks( [ 'post_id' => 999999 ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	/**
	 * handle_get_page_blocks() returns a flat block list with refs and text_preview.
	 *
	 * @see GH#1707
	 */
	public function test_handle_get_page_blocks_returns_block_list(): void {
		$content = "<!-- wp:paragraph -->\n<p>Hello world</p>\n<!-- /wp:paragraph -->\n<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">Title</h2>\n<!-- /wp:heading -->";

		$post_id = $this->factory()->post->create( [
			'post_content' => $content,
			'post_status'  => 'publish',
		] );

		$result = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => false,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'blocks', $result );
		$this->assertArrayHasKey( 'block_count', $result );
		$this->assertArrayHasKey( 'refs_stored', $result );
		$this->assertCount( 2, $result['blocks'] );
		$this->assertSame( 2, $result['block_count'] );
	}

	/**
	 * Each block entry from handle_get_page_blocks() contains the required keys.
	 *
	 * @see GH#1707
	 */
	public function test_handle_get_page_blocks_block_entry_shape(): void {
		$content = "<!-- wp:paragraph -->\n<p>Test paragraph</p>\n<!-- /wp:paragraph -->";

		$post_id = $this->factory()->post->create( [
			'post_content' => $content,
			'post_status'  => 'publish',
		] );

		$result = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => false,
		] );

		$this->assertIsArray( $result );
		$block = $result['blocks'][0];

		$this->assertArrayHasKey( 'flat_index', $block );
		$this->assertArrayHasKey( 'path', $block );
		$this->assertArrayHasKey( 'name', $block );
		$this->assertArrayHasKey( 'attributes', $block );
		$this->assertArrayHasKey( 'ref', $block );
		$this->assertArrayHasKey( 'text_preview', $block );

		$this->assertSame( 0, $block['flat_index'] );
		$this->assertSame( [ 0 ], $block['path'] );
		$this->assertSame( 'core/paragraph', $block['name'] );
		$this->assertMatchesRegularExpression( '/^blk_[A-Za-z0-9\-_]{8}$/', $block['ref'] );
		$this->assertStringContainsString( 'Test paragraph', $block['text_preview'] );
	}

	/**
	 * AC4 (ability): persist_refs: false does not write refs to the post.
	 *
	 * The response includes refs but the post_content on disk must remain
	 * unchanged (no sd_ref in the serialised markup).
	 *
	 * @see GH#1707
	 */
	public function test_handle_get_page_blocks_persist_refs_false_does_not_write(): void {
		$content = "<!-- wp:paragraph -->\n<p>No persist test</p>\n<!-- /wp:paragraph -->";

		$post_id = $this->factory()->post->create( [
			'post_content' => $content,
			'post_status'  => 'publish',
		] );

		$result = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => false,
		] );

		// Response must include a ref (assigned in memory).
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'ref', $result['blocks'][0] );
		$this->assertFalse( $result['refs_stored'], 'refs_stored must be false when persist_refs is false' );

		// Post content must not have been written.
		$updated = get_post( $post_id );
		$this->assertStringNotContainsString( '"sd_ref"', $updated->post_content );
	}

	/**
	 * AC1 (ability): calling with persist_refs: true assigns refs and
	 * writes them to the post without creating a revision.
	 *
	 * @see GH#1707
	 */
	public function test_handle_get_page_blocks_persist_refs_true_stores_and_no_revision(): void {
		$content = "<!-- wp:paragraph -->\n<p>Persist test</p>\n<!-- /wp:paragraph -->";

		$post_id = $this->factory()->post->create( [
			'post_content' => $content,
			'post_status'  => 'publish',
		] );

		$revisions_before = count( wp_get_post_revisions( $post_id ) );

		$result = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => true,
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['refs_stored'], 'refs_stored must be true after persist' );

		// Post content now has sd_ref.
		$updated = get_post( $post_id );
		$this->assertStringContainsString( '"sd_ref"', $updated->post_content );

		// No revision created.
		$revisions_after = count( wp_get_post_revisions( $post_id ) );
		$this->assertSame(
			$revisions_before,
			$revisions_after,
			'handle_get_page_blocks() must not create a revision'
		);
	}

	/**
	 * A second call (refs already present) returns refs_stored: false.
	 *
	 * When all blocks already carry refs, no DB write is needed.
	 *
	 * @see GH#1707
	 */
	public function test_handle_get_page_blocks_second_call_refs_stored_false(): void {
		$content = "<!-- wp:paragraph -->\n<p>Idempotent ability test</p>\n<!-- /wp:paragraph -->";

		$post_id = $this->factory()->post->create( [
			'post_content' => $content,
			'post_status'  => 'publish',
		] );

		// First call: persist refs.
		BlockAbilities::handle_get_page_blocks( [ 'post_id' => $post_id, 'persist_refs' => true ] );

		// Second call: refs already present, so refs_stored must be false.
		$result = BlockAbilities::handle_get_page_blocks( [ 'post_id' => $post_id, 'persist_refs' => true ] );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['refs_stored'], 'refs_stored must be false when refs are already present' );
	}

	// ─── outline parameter ────────────────────────────────────────

	/**
	 * AC1 (outline): outline: true returns only flat_index, path, name, heading_text.
	 *
	 * @see GH#1738
	 */
	public function test_handle_get_page_blocks_outline_mode(): void {
		$content = "<!-- wp:heading {\"level\":2} -->\n<h2>Section Title</h2>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>Paragraph content here</p>\n<!-- /wp:paragraph -->";

		$post_id = $this->factory()->post->create( [
			'post_content' => $content,
			'post_status'  => 'publish',
		] );

		$result = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => false,
			'outline'      => true,
		] );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['blocks'] );

		// Check heading block.
		$heading = $result['blocks'][0];
		$this->assertArrayHasKey( 'flat_index', $heading );
		$this->assertArrayHasKey( 'path', $heading );
		$this->assertArrayHasKey( 'name', $heading );
		$this->assertArrayHasKey( 'heading_text', $heading );
		$this->assertSame( 'core/heading', $heading['name'] );
		$this->assertSame( 'Section Title', $heading['heading_text'] );

		// Outline mode should NOT include attributes or text_preview.
		$this->assertArrayNotHasKey( 'attributes', $heading );
		$this->assertArrayNotHasKey( 'text_preview', $heading );

		// Check paragraph block (no heading_text for non-heading blocks).
		$paragraph = $result['blocks'][1];
		$this->assertArrayNotHasKey( 'heading_text', $paragraph );
		$this->assertArrayNotHasKey( 'attributes', $paragraph );
	}

	// ─── summary_only parameter ───────────────────────────────────

	/**
	 * AC2 (summary_only): summary_only: true returns block_counts, headings, section_markers, max_depth.
	 *
	 * @see GH#1738
	 */
	public function test_handle_get_page_blocks_summary_only(): void {
		$content = "<!-- wp:heading {\"level\":2} -->\n<h2>Title</h2>\n<!-- /wp:heading -->\n<!-- wp:heading {\"level\":3} -->\n<h3>Subtitle</h3>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>Content</p>\n<!-- /wp:paragraph -->";

		$post_id = $this->factory()->post->create( [
			'post_content' => $content,
			'post_status'  => 'publish',
		] );

		$result = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => false,
			'summary_only' => true,
		] );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result['blocks'], 'summary_only should return empty blocks array' );
		$this->assertArrayHasKey( 'summary', $result );

		$summary = $result['summary'];
		$this->assertArrayHasKey( 'block_counts', $summary );
		$this->assertArrayHasKey( 'headings', $summary );
		$this->assertArrayHasKey( 'section_markers', $summary );
		$this->assertArrayHasKey( 'max_depth', $summary );

		// Check block counts exist and have expected types.
		$this->assertIsArray( $summary['block_counts'] );
		$this->assertGreaterThanOrEqual( 2, count( $summary['block_counts'] ) );

		// Check headings list exists and has expected types.
		$this->assertIsArray( $summary['headings'] );
		$this->assertGreaterThanOrEqual( 2, count( $summary['headings'] ) );

		// Verify max_depth is an integer.
		$this->assertIsInt( $summary['max_depth'] );
	}

	// ─── search parameter ─────────────────────────────────────────

	/**
	 * AC3 (search): search: 'text' filters blocks by text_preview substring.
	 *
	 * @see GH#1738
	 */
	public function test_handle_get_page_blocks_search_filter(): void {
		$content = "<!-- wp:paragraph -->\n<p>Pricing information here</p>\n<!-- /wp:paragraph -->\n<!-- wp:paragraph -->\n<p>Other content</p>\n<!-- /wp:paragraph -->";

		$post_id = $this->factory()->post->create( [
			'post_content' => $content,
			'post_status'  => 'publish',
		] );

		$result = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => false,
			'search'       => 'Pricing',
		] );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['blocks'] );
		$this->assertStringContainsString( 'Pricing', $result['blocks'][0]['text_preview'] );
	}

	/**
	 * AC3b (search): search is case-insensitive.
	 *
	 * @see GH#1738
	 */
	public function test_handle_get_page_blocks_search_case_insensitive(): void {
		$content = "<!-- wp:paragraph -->\n<p>PRICING information</p>\n<!-- /wp:paragraph -->";

		$post_id = $this->factory()->post->create( [
			'post_content' => $content,
			'post_status'  => 'publish',
		] );

		$result = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => false,
			'search'       => 'pricing',
		] );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['blocks'] );
	}

	// ─── block_name parameter ─────────────────────────────────────

	/**
	 * AC4 (block_name): block_name: 'core/heading' filters by exact block name.
	 *
	 * @see GH#1738
	 */
	public function test_handle_get_page_blocks_block_name_filter(): void {
		$content = "<!-- wp:heading {\"level\":2} -->\n<h2>Title</h2>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>Content</p>\n<!-- /wp:paragraph -->\n<!-- wp:heading {\"level\":3} -->\n<h3>Subtitle</h3>\n<!-- /wp:heading -->";

		$post_id = $this->factory()->post->create( [
			'post_content' => $content,
			'post_status'  => 'publish',
		] );

		$result = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => false,
			'block_name'   => 'core/heading',
		] );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['blocks'] );
		foreach ( $result['blocks'] as $block ) {
			$this->assertSame( 'core/heading', $block['name'] );
		}
	}

	// ─── fields parameter ─────────────────────────────────────────

	/**
	 * AC6 (fields): fields: 'name,ref,path' returns only those keys per block.
	 *
	 * @see GH#1738
	 */
	public function test_handle_get_page_blocks_fields_allowlist(): void {
		$content = "<!-- wp:paragraph -->\n<p>Test content</p>\n<!-- /wp:paragraph -->";

		$post_id = $this->factory()->post->create( [
			'post_content' => $content,
			'post_status'  => 'publish',
		] );

		$result = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => false,
			'fields'       => 'name,ref,path',
		] );

		$this->assertIsArray( $result );
		$block = $result['blocks'][0];

		// Should have only the requested fields.
		$this->assertArrayHasKey( 'name', $block );
		$this->assertArrayHasKey( 'ref', $block );
		$this->assertArrayHasKey( 'path', $block );

		// Should NOT have other fields.
		$this->assertArrayNotHasKey( 'attributes', $block );
		$this->assertArrayNotHasKey( 'text_preview', $block );
		$this->assertArrayNotHasKey( 'flat_index', $block );
	}

	// ─── combined parameters ──────────────────────────────────────

	/**
	 * AC7 (combined): outline + search filters and returns minimal response.
	 *
	 * @see GH#1738
	 */
	public function test_handle_get_page_blocks_outline_with_search(): void {
		$content = "<!-- wp:heading {\"level\":2} -->\n<h2>Pricing</h2>\n<!-- /wp:heading -->\n<!-- wp:heading {\"level\":2} -->\n<h2>Features</h2>\n<!-- /wp:heading -->";

		$post_id = $this->factory()->post->create( [
			'post_content' => $content,
			'post_status'  => 'publish',
		] );

		$result = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => false,
			'outline'      => true,
			'search'       => 'Pricing',
		] );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['blocks'] );
		$this->assertSame( 'Pricing', $result['blocks'][0]['heading_text'] );
		$this->assertArrayNotHasKey( 'attributes', $result['blocks'][0] );
	}

	/**
	 * AC7b (combined): block_name + fields filters and returns only specified fields.
	 *
	 * @see GH#1738
	 */
	public function test_handle_get_page_blocks_block_name_with_fields(): void {
		$content = "<!-- wp:heading {\"level\":2} -->\n<h2>Title</h2>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>Content</p>\n<!-- /wp:paragraph -->";

		$post_id = $this->factory()->post->create( [
			'post_content' => $content,
			'post_status'  => 'publish',
		] );

		$result = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => false,
			'block_name'   => 'core/heading',
			'fields'       => 'name,path',
		] );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['blocks'] );
		$block = $result['blocks'][0];

		$this->assertArrayHasKey( 'name', $block );
		$this->assertArrayHasKey( 'path', $block );
		$this->assertArrayNotHasKey( 'ref', $block );
		$this->assertArrayNotHasKey( 'attributes', $block );
	}
}
