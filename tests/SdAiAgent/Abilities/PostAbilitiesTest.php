<?php
/**
 * Test case for PostAbilities class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\PostAbilities;
use WP_UnitTestCase;

/**
 * Test PostAbilities handler methods.
 */
class PostAbilitiesTest extends WP_UnitTestCase {

	// ─── handle_get_post ──────────────────────────────────────────

	/**
	 * Test handle_get_post with missing post_id returns WP_Error.
	 */
	public function test_handle_get_post_missing_post_id() {
		$result = PostAbilities::handle_get_post( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_empty_post_id', $result->get_error_code() );
	}

	/**
	 * Test handle_get_post with zero post_id returns WP_Error.
	 */
	public function test_handle_get_post_zero_post_id() {
		$result = PostAbilities::handle_get_post( [ 'post_id' => 0 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_empty_post_id', $result->get_error_code() );
	}

	/**
	 * Test handle_get_post with non-existent post_id returns WP_Error.
	 */
	public function test_handle_get_post_not_found() {
		$result = PostAbilities::handle_get_post( [ 'post_id' => 999999 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_post_not_found', $result->get_error_code() );
	}

	/**
	 * Test handle_get_post with valid post_id returns expected structure.
	 */
	public function test_handle_get_post_returns_structure() {
		$post_id = $this->factory->post->create( [
			'post_title'   => 'Test Post',
			'post_content' => 'Test content.',
			'post_status'  => 'publish',
		] );

		$result = PostAbilities::handle_get_post( [ 'post_id' => $post_id ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertArrayHasKey( 'title', $result );
		$this->assertArrayHasKey( 'content', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'post_type', $result );
		$this->assertArrayHasKey( 'author_id', $result );
		$this->assertArrayHasKey( 'categories', $result );
		$this->assertArrayHasKey( 'tags', $result );
		$this->assertArrayHasKey( 'featured_image', $result );
		$this->assertSame( $post_id, $result['id'] );
		$this->assertSame( 'Test Post', $result['title'] );
	}

	/**
	 * Test handle_get_post with post_type mismatch returns WP_Error.
	 */
	public function test_handle_get_post_type_mismatch() {
		$post_id = $this->factory->post->create( [
			'post_type'   => 'post',
			'post_status' => 'publish',
		] );

		$result = PostAbilities::handle_get_post( [
			'post_id'   => $post_id,
			'post_type' => 'page',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_post_type_mismatch', $result->get_error_code() );
	}

	/**
	 * Test handle_get_post with matching post_type succeeds.
	 */
	public function test_handle_get_post_type_match() {
		$post_id = $this->factory->post->create( [
			'post_type'   => 'post',
			'post_status' => 'publish',
		] );

		$result = PostAbilities::handle_get_post( [
			'post_id'   => $post_id,
			'post_type' => 'post',
		] );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['id'] );
	}

	/**
	 * Test handle_get_post categories and tags are arrays.
	 */
	public function test_handle_get_post_categories_tags_are_arrays() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );

		$result = PostAbilities::handle_get_post( [ 'post_id' => $post_id ] );

		$this->assertIsArray( $result );
		$this->assertIsArray( $result['categories'] );
		$this->assertIsArray( $result['tags'] );
	}

	// ─── handle_create_post ───────────────────────────────────────

	/**
	 * Test handle_create_post with empty title returns WP_Error.
	 */
	public function test_handle_create_post_empty_title() {
		$result = PostAbilities::handle_create_post( [ 'title' => '' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_empty_title', $result->get_error_code() );
	}

	/**
	 * Test handle_create_post with missing title returns WP_Error.
	 */
	public function test_handle_create_post_missing_title() {
		$result = PostAbilities::handle_create_post( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_empty_title', $result->get_error_code() );
	}

	/**
	 * Test handle_create_post with valid title creates post and returns structure.
	 */
	public function test_handle_create_post_returns_structure() {
		$result = PostAbilities::handle_create_post( [
			'title'   => 'New Test Post',
			'content' => 'Some content.',
			'status'  => 'draft',
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'post_id', $result );
		$this->assertArrayHasKey( 'permalink', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'post_type', $result );
		$this->assertIsInt( $result['post_id'] );
		$this->assertGreaterThan( 0, $result['post_id'] );
	}

	/**
	 * Test handle_create_post default status is draft.
	 */
	public function test_handle_create_post_default_status_is_draft() {
		$result = PostAbilities::handle_create_post( [ 'title' => 'Draft Post' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['status'] );
	}

	/**
	 * Test handle_create_post with publish status creates published post.
	 */
	public function test_handle_create_post_publish_status() {
		$result = PostAbilities::handle_create_post( [
			'title'  => 'Published Post',
			'status' => 'publish',
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'publish', $result['status'] );
	}

	/**
	 * Test handle_create_post with invalid status falls back to draft.
	 */
	public function test_handle_create_post_invalid_status_falls_back_to_draft() {
		$result = PostAbilities::handle_create_post( [
			'title'  => 'Post With Bad Status',
			'status' => 'invalid_status',
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['status'] );
	}

	/**
	 * Test handle_create_post with page post_type creates a page.
	 */
	public function test_handle_create_post_page_post_type() {
		$result = PostAbilities::handle_create_post( [
			'title'     => 'New Page',
			'post_type' => 'page',
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'page', $result['post_type'] );
	}

	/**
	 * Test handle_create_post assigns a page template when provided.
	 */
	public function test_handle_create_post_assigns_page_template() {
		$result = PostAbilities::handle_create_post( [
			'title'         => 'Templated Page',
			'post_type'     => 'page',
			'page_template' => 'templates/full-width.php',
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'templates/full-width.php', get_page_template_slug( $result['post_id'] ) );
	}

	/**
	 * Test handle_create_post with meta sets post meta.
	 */
	public function test_handle_create_post_sets_meta() {
		$result = PostAbilities::handle_create_post( [
			'title' => 'Post With Meta',
			'meta'  => [ 'custom_key' => 'custom_value' ],
		] );

		$this->assertIsArray( $result );
		$meta_value = get_post_meta( $result['post_id'], 'custom_key', true );
		$this->assertSame( 'custom_value', $meta_value );
	}

	// ─── handle_update_post ───────────────────────────────────────

	/**
	 * Test handle_update_post with missing post_id returns WP_Error.
	 */
	public function test_handle_update_post_missing_post_id() {
		$result = PostAbilities::handle_update_post( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_empty_post_id', $result->get_error_code() );
	}

	/**
	 * Test handle_update_post with non-existent post_id returns WP_Error.
	 */
	public function test_handle_update_post_not_found() {
		$result = PostAbilities::handle_update_post( [ 'post_id' => 999999 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_post_not_found', $result->get_error_code() );
	}

	/**
	 * Test handle_update_post updates title.
	 */
	public function test_handle_update_post_updates_title() {
		$post_id = $this->factory->post->create( [
			'post_title'  => 'Original Title',
			'post_status' => 'publish',
		] );

		$result = PostAbilities::handle_update_post( [
			'post_id' => $post_id,
			'title'   => 'Updated Title',
		] );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['post_id'] );

		$updated_post = get_post( $post_id );
		$this->assertSame( 'Updated Title', $updated_post->post_title );
	}

	/**
	 * Test handle_update_post updates status.
	 */
	public function test_handle_update_post_updates_status() {
		$post_id = $this->factory->post->create( [
			'post_status' => 'draft',
		] );

		$result = PostAbilities::handle_update_post( [
			'post_id' => $post_id,
			'status'  => 'publish',
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'publish', $result['status'] );
	}

	/**
	 * Test handle_update_post assigns a page template when provided.
	 */
	public function test_handle_update_post_assigns_page_template() {
		$post_id = $this->factory->post->create( [
			'post_type'   => 'page',
			'post_status' => 'draft',
		] );

		$result = PostAbilities::handle_update_post( [
			'post_id'       => $post_id,
			'page_template' => 'templates/landing.php',
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'templates/landing.php', get_page_template_slug( $post_id ) );
	}

	/**
	 * Test handle_update_post returns post_id, permalink, status.
	 */
	public function test_handle_update_post_returns_structure() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'draft' ] );

		$result = PostAbilities::handle_update_post( [ 'post_id' => $post_id ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'post_id', $result );
		$this->assertArrayHasKey( 'permalink', $result );
		$this->assertArrayHasKey( 'status', $result );
	}

	// ─── handle_delete_post ───────────────────────────────────────

	/**
	 * Test handle_delete_post with missing post_id returns WP_Error.
	 */
	public function test_handle_delete_post_missing_post_id() {
		$result = PostAbilities::handle_delete_post( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_empty_post_id', $result->get_error_code() );
	}

	/**
	 * Test handle_delete_post with non-existent post_id returns WP_Error.
	 */
	public function test_handle_delete_post_not_found() {
		$result = PostAbilities::handle_delete_post( [ 'post_id' => 999999 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_post_not_found', $result->get_error_code() );
	}

	/**
	 * Test handle_delete_post trashes post by default.
	 */
	public function test_handle_delete_post_trashes_by_default() {
		$post_id = $this->factory->post->create( [
			'post_title'  => 'Post To Trash',
			'post_status' => 'publish',
		] );

		$result = PostAbilities::handle_delete_post( [ 'post_id' => $post_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( 'trashed', $result['action'] );
		$this->assertFalse( $result['force_delete'] );
	}

	/**
	 * Test handle_delete_post with force_delete permanently deletes.
	 */
	public function test_handle_delete_post_force_delete() {
		$post_id = $this->factory->post->create( [
			'post_title'  => 'Post To Delete',
			'post_status' => 'publish',
		] );

		$result = PostAbilities::handle_delete_post( [
			'post_id'      => $post_id,
			'force_delete' => true,
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'permanently_deleted', $result['action'] );
		$this->assertTrue( $result['force_delete'] );
		$this->assertNull( get_post( $post_id ) );
	}

	/**
	 * Test handle_delete_post returns title in result.
	 */
	public function test_handle_delete_post_returns_title() {
		$post_id = $this->factory->post->create( [
			'post_title'  => 'My Titled Post',
			'post_status' => 'publish',
		] );

		$result = PostAbilities::handle_delete_post( [ 'post_id' => $post_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'My Titled Post', $result['title'] );
	}

	// ─── maybe_convert_markdown ───────────────────────────────────

	/**
	 * Invoke the private maybe_convert_markdown() method via reflection.
	 *
	 * @param string $content Content to pass.
	 * @return string Processed content.
	 */
	private function call_maybe_convert_markdown( string $content ): string {
		$method = new \ReflectionMethod( PostAbilities::class, 'maybe_convert_markdown' );
		$method->setAccessible( true );
		return (string) $method->invoke( null, $content );
	}

	/**
	 * Test that empty content is returned unchanged.
	 */
	public function test_maybe_convert_markdown_empty_content() {
		$result = $this->call_maybe_convert_markdown( '' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test that plain text without markdown signals is returned unchanged.
	 */
	public function test_maybe_convert_markdown_plain_text_unchanged() {
		$plain = 'This is a plain sentence with no markdown.';
		$result = $this->call_maybe_convert_markdown( $plain );
		$this->assertSame( $plain, $result );
	}

	/**
	 * Test that pure markdown content (≥2 signals) is converted to blocks.
	 */
	public function test_maybe_convert_markdown_pure_markdown_converted() {
		$markdown = "## Introduction\n\nThis is a paragraph.\n\n- Item one\n- Item two";
		$result   = $this->call_maybe_convert_markdown( $markdown );

		// After conversion, should contain wp: block markers.
		$this->assertStringContainsString( '<!-- wp:', $result );
		// Must not contain the raw markdown heading.
		$this->assertStringNotContainsString( '## Introduction', $result );
	}

	/**
	 * Test that pure block markup without any markdown is returned unchanged.
	 */
	public function test_maybe_convert_markdown_pure_blocks_unchanged() {
		$blocks = "<!-- wp:paragraph -->\n<p>Hello world</p>\n<!-- /wp:paragraph -->";
		$result = $this->call_maybe_convert_markdown( $blocks );

		// No markdown signals in the freeform segments, so the image block is preserved.
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $result );
	}

	/**
	 * Test that mixed content (block markup + freeform markdown) converts
	 * the markdown portions while preserving existing named blocks.
	 */
	public function test_maybe_convert_markdown_mixed_content_converts_freeform() {
		$mixed = "<!-- wp:image {\"id\":42} -->\n"
			. "<figure class=\"wp-block-image\"><img src=\"test.jpg\" /></figure>\n"
			. "<!-- /wp:image -->\n\n"
			. "## Section Heading\n\nThis paragraph follows.\n\n- Bullet one\n- Bullet two";

		$result = $this->call_maybe_convert_markdown( $mixed );

		// The original image block must be preserved.
		$this->assertStringContainsString( '<!-- wp:image', $result );
		// The raw markdown heading must not appear in the output.
		$this->assertStringNotContainsString( '## Section Heading', $result );
		// The freeform markdown must have been converted to blocks.
		$this->assertStringContainsString( '<!-- wp:heading', $result );
	}

	/**
	 * Test that mixed content with freeform HTML (non-markdown) keeps freeform
	 * blocks intact — only segments with ≥2 markdown signals are converted.
	 */
	public function test_maybe_convert_markdown_mixed_content_preserves_freeform_html() {
		$mixed = "<!-- wp:paragraph -->\n<p>Intro</p>\n<!-- /wp:paragraph -->\n\n"
			. "<p>A plain HTML paragraph without markdown signals.</p>";

		$result = $this->call_maybe_convert_markdown( $mixed );

		// The named block must be preserved.
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $result );
		// The plain HTML freeform segment has no markdown signals; it stays.
		$this->assertStringContainsString( 'A plain HTML paragraph', $result );
	}
}
