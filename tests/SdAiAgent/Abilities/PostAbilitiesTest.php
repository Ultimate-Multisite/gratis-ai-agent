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
use SdAiAgent\Core\ChangeLogger;
use WP_UnitTestCase;

/**
 * Test PostAbilities handler methods.
 */
class PostAbilitiesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// Create an admin user and set as current user for capability checks.
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		add_filter( 'theme_page_templates', [ $this, 'register_test_page_templates' ] );
	}

	public function tear_down(): void {
		remove_filter( 'theme_page_templates', [ $this, 'register_test_page_templates' ] );

		parent::tear_down();
	}

	/**
	 * Register synthetic page templates used by page-template assignment tests.
	 *
	 * WordPress trunk validates `page_template` during `wp_insert_post()` and
	 * `wp_update_post()`, so tests must expose the template slugs they assign.
	 *
	 * @param array<string, string> $post_templates Existing template map.
	 * @return array<string, string>
	 */
	public function register_test_page_templates( array $post_templates ): array {
		$post_templates['templates/full-width.php'] = 'Full Width';
		$post_templates['templates/landing.php']    = 'Landing';

		return $post_templates;
	}

	// ─── handle_get_post ──────────────────────────────────────────

	/**
	 * Test handle_get_post with empty input returns missing_input WP_Error.
	 *
	 * AC5: get-post {} → WP_Error('missing_input').
	 */
	public function test_handle_get_post_missing_post_id() {
		$result = PostAbilities::handle_get_post( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_input', $result->get_error_code() );
	}

	/**
	 * Test handle_get_post with zero id/post_id returns missing_input WP_Error.
	 *
	 * Zero is falsy and not a valid post ID; treated as missing input.
	 */
	public function test_handle_get_post_zero_post_id() {
		$result = PostAbilities::handle_get_post( [ 'post_id' => 0 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_input', $result->get_error_code() );
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
	 *
	 * AC7: existing id path returns the pre-change shape PLUS resolved_via: "id".
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
		$this->assertArrayHasKey( 'resolved_via', $result );
		$this->assertSame( $post_id, $result['id'] );
		$this->assertSame( 'Test Post', $result['title'] );
		$this->assertSame( 'id', $result['resolved_via'] );
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

	// ─── handle_get_post — new input forms (t268) ─────────────────

	/**
	 * AC1: get-post via URL (query-string form) returns resolved_via "url_to_postid".
	 *
	 * Uses ?p=N which is reliably resolved by url_to_postid() even in unit tests.
	 */
	public function test_handle_get_post_via_url_resolves_and_returns_resolved_via_url_to_postid() {
		$post_id = $this->factory->post->create( [
			'post_title'  => 'URL-resolved post',
			'post_status' => 'publish',
		] );

		$url    = add_query_arg( 'p', $post_id, home_url( '/' ) );
		$result = PostAbilities::handle_get_post( [ 'url' => $url ] );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['id'] );
		$this->assertSame( 'url_to_postid', $result['resolved_via'] );
	}

	/**
	 * AC2: get-post via slug+post_type returns correct post and resolved_via "slug_lookup".
	 */
	public function test_handle_get_post_via_slug_and_post_type_resolves_correctly() {
		$post_id = $this->factory->post->create( [
			'post_title'   => 'About Page',
			'post_name'    => 't268-about-slug-test',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		] );

		$result = PostAbilities::handle_get_post( [
			'slug'      => 't268-about-slug-test',
			'post_type' => 'page',
		] );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['id'] );
		$this->assertSame( 'slug_lookup', $result['resolved_via'] );
	}

	/**
	 * AC3: get-post with slug but no post_type returns missing_post_type WP_Error.
	 */
	public function test_handle_get_post_slug_without_post_type_returns_missing_post_type() {
		$result = PostAbilities::handle_get_post( [ 'slug' => 'some-slug' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_post_type', $result->get_error_code() );
	}

	/**
	 * AC4: get-post with more than one of id/url/slug returns too_many_inputs WP_Error.
	 */
	public function test_handle_get_post_too_many_inputs_returns_too_many_inputs() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );

		$result = PostAbilities::handle_get_post( [
			'id'  => $post_id,
			'url' => home_url( '/?p=' . $post_id ),
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'too_many_inputs', $result->get_error_code() );
	}

	/**
	 * AC6: get-post with a cross-host URL returns external_host WP_Error.
	 */
	public function test_handle_get_post_cross_host_url_returns_external_host_error() {
		$result = PostAbilities::handle_get_post( [
			'url' => 'https://other.example.invalid/about/',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'external_host', $result->get_error_code() );
	}

	/**
	 * AC7: deprecated post_id key is still accepted (backward compatibility).
	 */
	public function test_handle_get_post_deprecated_post_id_key_still_works() {
		$post_id = $this->factory->post->create( [
			'post_title'  => 'Legacy post_id key',
			'post_status' => 'publish',
		] );

		$result = PostAbilities::handle_get_post( [ 'post_id' => $post_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['id'] );
		$this->assertSame( 'id', $result['resolved_via'] );
	}

	/**
	 * New 'id' key works as canonical alias.
	 */
	public function test_handle_get_post_new_id_key_works() {
		$post_id = $this->factory->post->create( [
			'post_title'  => 'New id key post',
			'post_status' => 'publish',
		] );

		$result = PostAbilities::handle_get_post( [ 'id' => $post_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['id'] );
		$this->assertSame( 'id', $result['resolved_via'] );
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

	/**
	 * Test handle_create_post records an AI changes-log row when logging is active.
	 */
	public function test_handle_create_post_records_change_log_entry_when_active(): void {
		ChangeLogger::begin( 123 );
		try {
			$result = PostAbilities::handle_create_post( [
				'title'   => 'Logged Agent Draft',
				'content' => 'Created through the create-post ability.',
				'status'  => 'draft',
			] );
		} finally {
			ChangeLogger::end();
		}

		$this->assertIsArray( $result );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE object_id = %d AND field_name = %s',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				$result['post_id'],
				'post_created'
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( '123', (string) $row->session_id );
		$this->assertSame( 'post', $row->object_type );
		$this->assertSame( 'Logged Agent Draft', $row->object_title );
		$this->assertSame( 'sd-ai-agent/create-post', $row->ability_name );
		$this->assertSame( '', $row->before_value );
		$this->assertSame( 'Logged Agent Draft', $row->after_value );
		$this->assertSame( '1', (string) $row->revertable );
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

	// ─── affected payload (frontend live-preview bus, Phase 1 spike) ────

	/**
	 * Test handle_update_post returns an `affected` descriptor for the
	 * frontend reflection bus when fields are changed. Fields list must
	 * reflect exactly what the input mutated (post_title here).
	 */
	public function test_handle_update_post_returns_affected_payload() {
		$post_id = $this->factory->post->create( [
			'post_status' => 'publish',
			'post_title'  => 'Original title',
		] );

		$result = PostAbilities::handle_update_post( [
			'post_id' => $post_id,
			'title'   => 'Updated title',
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'affected', $result );
		$this->assertIsArray( $result['affected'] );
		$this->assertSame( 'post', $result['affected']['kind'] );
		$this->assertSame( $post_id, $result['affected']['post_id'] );
		$this->assertSame( 'post', $result['affected']['post_type'] );
		$this->assertNotEmpty( $result['affected']['url'] );
		$this->assertContains( 'post_title', $result['affected']['fields'] );
		$this->assertNotContains( 'post_content', $result['affected']['fields'] );
	}

	/**
	 * Test that taxonomy + featured-image + meta inputs are reported in
	 * `affected.fields` even though wp_update_post() does not touch them.
	 */
	public function test_handle_update_post_affected_lists_taxonomy_and_meta_fields() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		$thumb   = $this->factory->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg',
			$post_id
		);

		$result = PostAbilities::handle_update_post( [
			'post_id'           => $post_id,
			'content'           => 'New body',
			'tags'              => [ 'live-preview', 'spike' ],
			'featured_image_id' => $thumb,
			'meta'              => [ 'sd_test_key' => 'value' ],
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'affected', $result );
		$fields = $result['affected']['fields'];
		$this->assertContains( 'post_content', $fields );
		$this->assertContains( 'tags', $fields );
		$this->assertContains( 'featured_image', $fields );
		$this->assertContains( 'meta', $fields );
	}

	/**
	 * Test handle_create_post returns an affected descriptor.
	 */
	public function test_handle_create_post_returns_affected_payload() {
		$result = PostAbilities::handle_create_post( [
			'title'   => 'Affected create',
			'content' => 'Created body',
			'status'  => 'publish',
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'affected', $result );
		$this->assertSame( 'post', $result['affected']['kind'] );
		$this->assertSame( $result['post_id'], $result['affected']['post_id'] );
		$this->assertNotEmpty( $result['affected']['url'] );
		$this->assertContains( 'post_content', $result['affected']['fields'] );
	}

	/**
	 * Test handle_append_post_content returns an affected descriptor.
	 */
	public function test_handle_append_post_content_returns_affected_payload() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );

		$result = PostAbilities::handle_append_post_content( [
			'post_id' => $post_id,
			'content' => '<!-- wp:paragraph --><p>New section.</p><!-- /wp:paragraph -->',
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'affected', $result );
		$this->assertSame( 'post', $result['affected']['kind'] );
		$this->assertSame( $post_id, $result['affected']['post_id'] );
		$this->assertNotEmpty( $result['affected']['url'] );
		$this->assertContains( 'post_content', $result['affected']['fields'] );
	}

	/**
	 * Test handle_batch_create_posts returns affected descriptors for successes.
	 */
	public function test_handle_batch_create_posts_returns_affected_payload() {
		$result = PostAbilities::handle_batch_create_posts( [
			'posts' => [
				[ 'title' => 'Affected batch one' ],
				[ 'title' => 'Affected batch two' ],
			],
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'affected', $result );
		$this->assertCount( 2, $result['affected'] );
		$this->assertSame( 'post', $result['affected'][0]['kind'] );
		$this->assertNotEmpty( $result['affected'][0]['url'] );
		$this->assertContains( 'post_title', $result['affected'][0]['fields'] );
	}

	/**
	 * Test handle_delete_post returns an affected descriptor.
	 */
	public function test_handle_delete_post_returns_affected_payload() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );

		$result = PostAbilities::handle_delete_post( [ 'post_id' => $post_id ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'affected', $result );
		$this->assertSame( 'post', $result['affected']['kind'] );
		$this->assertSame( $post_id, $result['affected']['post_id'] );
		$this->assertNotEmpty( $result['affected']['url'] );
		$this->assertContains( 'post_status', $result['affected']['fields'] );
	}

	/**
	 * Test handle_set_featured_image returns an affected descriptor.
	 */
	public function test_handle_set_featured_image_returns_affected_payload() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );

		$result = PostAbilities::handle_set_featured_image( [
			'post_id'           => $post_id,
			'featured_image_id' => 0,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'affected', $result );
		$this->assertSame( 'post', $result['affected']['kind'] );
		$this->assertSame( $post_id, $result['affected']['post_id'] );
		$this->assertNotEmpty( $result['affected']['url'] );
		$this->assertContains( 'featured_image', $result['affected']['fields'] );
	}

	// ─── block_validation save-time gate (GH#1584 follow-up) ────────

	/**
	 * Content without block markup should omit the block_validation key — the
	 * helper short-circuits when `<!-- wp:` is not present.
	 */
	public function test_handle_create_post_omits_block_validation_for_markdown() {
		$result = PostAbilities::handle_create_post( [
			'title'   => 'Markdown post',
			'content' => "## Heading\n\nParagraph.",
		] );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'block_validation', $result );
	}

	/**
	 * Content with valid block markup should attach block_validation with
	 * isValid=true and invalidBlocks=0.
	 */
	public function test_handle_create_post_attaches_valid_block_validation() {
		$valid_blocks = "<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">Hello</h2>\n<!-- /wp:heading -->";

		$result = PostAbilities::handle_create_post( [
			'title'   => 'Valid blocks page',
			'content' => $valid_blocks,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'block_validation', $result );
		$this->assertTrue( $result['block_validation']['isValid'] );
		$this->assertSame( 0, $result['block_validation']['invalidBlocks'] );
		$this->assertGreaterThanOrEqual( 1, $result['block_validation']['totalBlocks'] );
	}

	/**
	 * Heading level mismatch in created post should:
	 *  - still save (post_id is returned)
	 *  - attach block_validation with invalidBlocks > 0
	 *  - expose firstInvalid.expectedContent so the model can self-repair.
	 */
	public function test_handle_create_post_flags_heading_level_mismatch_without_blocking_save() {
		$bad_blocks = "<!-- wp:heading {\"level\":3} -->\n<h2 class=\"wp-block-heading\">Wrong level</h2>\n<!-- /wp:heading -->";

		$result = PostAbilities::handle_create_post( [
			'title'   => 'Bad heading page',
			'content' => $bad_blocks,
		] );

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['post_id'], 'Save must succeed even when blocks are invalid.' );

		$this->assertArrayHasKey( 'block_validation', $result );
		$this->assertFalse( $result['block_validation']['isValid'] );
		$this->assertSame( 1, $result['block_validation']['invalidBlocks'] );
		$this->assertArrayHasKey( 'firstInvalid', $result['block_validation'] );
		$this->assertSame( 'core/heading', $result['block_validation']['firstInvalid']['blockName'] );

		$expected = $result['block_validation']['firstInvalid']['expectedContent'];
		$this->assertStringContainsString( '<h3', $expected );
		$this->assertStringContainsString( '</h3>', $expected );

		$this->assertArrayHasKey( 'recommendation', $result['block_validation'] );
		$this->assertNotEmpty( $result['block_validation']['recommendation'] );
	}

	/**
	 * Updating a post with invalid block markup attaches block_validation to
	 * the update_post response so the model can detect and self-repair.
	 */
	public function test_handle_update_post_flags_invalid_blocks_in_response() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'draft' ] );

		$result = PostAbilities::handle_update_post( [
			'post_id' => $post_id,
			'content' => "<!-- wp:quote -->\n<div class=\"wp-block-quote\"><p>Wisdom.</p></div>\n<!-- /wp:quote -->",
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'block_validation', $result );
		$this->assertFalse( $result['block_validation']['isValid'] );
		$this->assertGreaterThanOrEqual( 1, $result['block_validation']['invalidBlocks'] );
		$this->assertArrayHasKey( 'firstInvalid', $result['block_validation'] );
		$this->assertSame( 'core/quote', $result['block_validation']['firstInvalid']['blockName'] );
		$this->assertStringContainsString( '<blockquote', $result['block_validation']['firstInvalid']['expectedContent'] );
	}

	/**
	 * update_post without a content field should not attach block_validation
	 * (we only validate content we just wrote).
	 */
	public function test_handle_update_post_no_content_no_block_validation() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'draft' ] );

		$result = PostAbilities::handle_update_post( [
			'post_id' => $post_id,
			'title'   => 'Title only update',
		] );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'block_validation', $result );
	}

	// ─── handle_append_post_content ──────────────────────────────────

	/**
	 * Test handle_append_post_content with missing post_id returns WP_Error.
	 */
	public function test_handle_append_post_content_missing_post_id() {
		$result = PostAbilities::handle_append_post_content( [ 'content' => 'x' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_empty_post_id', $result->get_error_code() );
	}

	/**
	 * Test handle_append_post_content with empty content returns WP_Error.
	 */
	public function test_handle_append_post_content_empty_content() {
		$post_id = wp_insert_post(
			[
				'post_title'   => 'Append Target',
				'post_content' => 'existing',
				'post_status'  => 'draft',
			]
		);

		$result = PostAbilities::handle_append_post_content(
			[
				'post_id' => $post_id,
				'content' => '   ',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_empty_content', $result->get_error_code() );
	}

	/**
	 * Test handle_append_post_content with non-existent post returns WP_Error.
	 */
	public function test_handle_append_post_content_post_not_found() {
		$result = PostAbilities::handle_append_post_content(
			[
				'post_id' => 999999,
				'content' => 'x',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_post_not_found', $result->get_error_code() );
	}

	/**
	 * Test handle_append_post_content concatenates content without re-sending
	 * the full document, and reports byte counts.
	 */
	public function test_handle_append_post_content_appends_and_reports_bytes() {
		$initial = '<!-- wp:paragraph --><p>Hero.</p><!-- /wp:paragraph -->';
		$post_id = wp_insert_post(
			[
				'post_title'   => 'Append Flow',
				'post_content' => $initial,
				'post_status'  => 'draft',
			]
		);

		$chunk_1 = '<!-- wp:heading --><h2 class="wp-block-heading">Features</h2><!-- /wp:heading -->';
		$r1      = PostAbilities::handle_append_post_content(
			[
				'post_id' => $post_id,
				'content' => $chunk_1,
			]
		);

		$this->assertIsArray( $r1 );
		$this->assertSame( $post_id, $r1['post_id'] );
		$this->assertSame( strlen( $chunk_1 ), $r1['appended_bytes'] );
		$this->assertGreaterThan( strlen( $initial ), $r1['total_bytes'] );

		$chunk_2 = '<!-- wp:paragraph --><p>Feature one.</p><!-- /wp:paragraph -->';
		$r2      = PostAbilities::handle_append_post_content(
			[
				'post_id' => $post_id,
				'content' => $chunk_2,
			]
		);

		$this->assertIsArray( $r2 );
		$this->assertSame( strlen( $chunk_2 ), $r2['appended_bytes'] );
		$this->assertGreaterThan( $r1['total_bytes'], $r2['total_bytes'] );

		$post = get_post( $post_id );
		$this->assertStringContainsString( $initial, $post->post_content );
		$this->assertStringContainsString( $chunk_1, $post->post_content );
		$this->assertStringContainsString( $chunk_2, $post->post_content );
	}

	// ─── handle_batch_create_posts ──────────────────────────────────

	/**
	 * Test handle_batch_create_posts creates multiple posts and reports counts.
	 */
	public function test_handle_batch_create_posts_creates_multiple_posts() {
		$result = PostAbilities::handle_batch_create_posts( [
			'posts' => [
				[
					'title'  => 'Batch Draft',
					'status' => 'draft',
				],
				[
					'title'     => 'Batch Page',
					'post_type' => 'page',
					'status'    => 'publish',
				],
			],
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['created_count'] );
		$this->assertSame( 0, $result['error_count'] );
		$this->assertCount( 2, $result['results'] );
		$this->assertSame( 'Batch Draft', get_the_title( $result['results'][0]['post_id'] ) );
		$this->assertSame( 'page', get_post_type( $result['results'][1]['post_id'] ) );
	}

	/**
	 * Test handle_batch_create_posts captures per-item errors without failing the whole batch.
	 */
	public function test_handle_batch_create_posts_returns_partial_errors() {
		$result = PostAbilities::handle_batch_create_posts( [
			'posts' => [
				[ 'title' => '' ],
				[ 'title' => 'Valid Batch Post' ],
			],
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['created_count'] );
		$this->assertSame( 1, $result['error_count'] );
		$this->assertSame( 0, $result['results'][0]['post_id'] );
		$this->assertNotEmpty( $result['results'][0]['error'] );
		$this->assertGreaterThan( 0, $result['results'][1]['post_id'] );
	}

	/**
	 * Test handle_batch_create_posts requires a non-empty posts array.
	 */
	public function test_handle_batch_create_posts_requires_posts() {
		$result = PostAbilities::handle_batch_create_posts( [ 'posts' => [] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_batch_empty', $result->get_error_code() );
	}

	// ─── handle_set_featured_image ──────────────────────────────────

	/**
	 * Test handle_set_featured_image requires post_id.
	 */
	public function test_handle_set_featured_image_requires_post_id() {
		$result = PostAbilities::handle_set_featured_image( [ 'featured_image_id' => 0 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_empty_post_id', $result->get_error_code() );
	}

	/**
	 * Test handle_set_featured_image removes thumbnails idempotently.
	 */
	public function test_handle_set_featured_image_removes_without_existing_thumbnail() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );

		$result = PostAbilities::handle_set_featured_image( [
			'post_id'           => $post_id,
			'featured_image_id' => 0,
		] );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( 0, $result['featured_image_id'] );
		$this->assertSame( 'removed', $result['result'] );
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

	// ─── handle_list_posts ────────────────────────────────────────

	/**
	 * Multi-status array: draft + publish posts both returned when post_status is an array.
	 */
	public function test_handle_list_posts_multi_status() {
		$draft_id   = $this->factory->post->create( [ 'post_status' => 'draft' ] );
		$publish_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );

		$result = PostAbilities::handle_list_posts( [
			'post_status' => [ 'draft', 'publish' ],
			'per_page'    => 50,
		] );

		$this->assertIsArray( $result );
		$ids = array_column( $result['posts'], 'id' );
		$this->assertContains( $draft_id, $ids );
		$this->assertContains( $publish_id, $ids );
	}

	/**
	 * date_after excludes posts older than the given date.
	 */
	public function test_handle_list_posts_date_after() {
		$old_id = $this->factory->post->create( [
			'post_status' => 'publish',
			'post_date'   => '2024-01-15 12:00:00',
		] );
		$new_id = $this->factory->post->create( [
			'post_status' => 'publish',
			'post_date'   => '2026-03-20 12:00:00',
		] );

		$result = PostAbilities::handle_list_posts( [
			'date_after' => '2025-12-31',
			'per_page'   => 50,
		] );

		$this->assertIsArray( $result );
		$ids = array_column( $result['posts'], 'id' );
		$this->assertContains( $new_id, $ids );
		$this->assertNotContains( $old_id, $ids );
	}

	/**
	 * tax_query with operator IN returns only posts in the specified category.
	 */
	public function test_handle_list_posts_tax_query_in() {
		$cat_id       = $this->factory->category->create( [ 'name' => 'ListPostsTaxCat' ] );
		$other_cat_id = $this->factory->category->create( [ 'name' => 'ListPostsOtherCat' ] );

		$in_cat_id  = $this->factory->post->create( [
			'post_status'   => 'publish',
			'post_category' => [ $cat_id ],
		] );
		$out_cat_id = $this->factory->post->create( [
			'post_status'   => 'publish',
			'post_category' => [ $other_cat_id ],
		] );

		$result = PostAbilities::handle_list_posts( [
			'tax_query' => [
				[
					'taxonomy' => 'category',
					'terms'    => [ $cat_id ],
					'operator' => 'IN',
				],
			],
			'per_page' => 50,
		] );

		$this->assertIsArray( $result );
		$ids = array_column( $result['posts'], 'id' );
		$this->assertContains( $in_cat_id, $ids );
		$this->assertNotContains( $out_cat_id, $ids );
	}

	/**
	 * meta_query with compare EXISTS returns only posts that have the meta key.
	 */
	public function test_handle_list_posts_meta_query_exists() {
		$with_meta_id    = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		$without_meta_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $with_meta_id, '_thumbnail_id', 123 );

		$result = PostAbilities::handle_list_posts( [
			'post_status' => 'publish',
			'meta_query'  => [
				[
					'key'     => '_thumbnail_id',
					'compare' => 'EXISTS',
				],
			],
			'per_page' => 50,
		] );

		$this->assertIsArray( $result );
		$ids = array_column( $result['posts'], 'id' );
		$this->assertContains( $with_meta_id, $ids );
		$this->assertNotContains( $without_meta_id, $ids );
	}

	/**
	 * meta_query with compare LIKE returns WP_Error (operator not in allowlist).
	 */
	public function test_handle_list_posts_invalid_meta_compare_returns_error() {
		$result = PostAbilities::handle_list_posts( [
			'meta_query' => [
				[
					'key'     => 'some_key',
					'compare' => 'LIKE',
					'value'   => '%test%',
				],
			],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_meta_compare', $result->get_error_code() );
	}

	/**
	 * tax_query with operator REGEXP returns WP_Error (operator not in allowlist).
	 */
	public function test_handle_list_posts_invalid_tax_operator_returns_error() {
		$result = PostAbilities::handle_list_posts( [
			'tax_query' => [
				[
					'taxonomy' => 'category',
					'terms'    => [ 1 ],
					'operator' => 'REGEXP',
				],
			],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_tax_operator', $result->get_error_code() );
	}

	/**
	 * orderby title + order ASC returns Alpha before Zeta.
	 */
	public function test_handle_list_posts_orderby_title_asc() {
		$this->factory->post->create( [ 'post_title' => 'Zeta Post', 'post_status' => 'publish' ] );
		$this->factory->post->create( [ 'post_title' => 'Alpha Post', 'post_status' => 'publish' ] );

		$result = PostAbilities::handle_list_posts( [
			'orderby'  => 'title',
			'order'    => 'ASC',
			'per_page' => 50,
		] );

		$this->assertIsArray( $result );
		$titles    = array_column( $result['posts'], 'title' );
		$alpha_idx = array_search( 'Alpha Post', $titles, true );
		$zeta_idx  = array_search( 'Zeta Post', $titles, true );
		$this->assertNotFalse( $alpha_idx );
		$this->assertNotFalse( $zeta_idx );
		$this->assertLessThan( $zeta_idx, $alpha_idx );
	}

	/**
	 * Legacy string form post_status: "draft" (single string) is auto-wrapped and works.
	 */
	public function test_handle_list_posts_post_status_string_backward_compat() {
		$draft_id   = $this->factory->post->create( [ 'post_status' => 'draft' ] );
		$publish_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );

		$result = PostAbilities::handle_list_posts( [
			'post_status' => 'draft',
			'per_page'    => 50,
		] );

		$this->assertIsArray( $result );
		$ids = array_column( $result['posts'], 'id' );
		$this->assertContains( $draft_id, $ids );
		$this->assertNotContains( $publish_id, $ids );
	}

	/**
	 * Legacy status field (old schema name) is still honoured for backward compat.
	 */
	public function test_handle_list_posts_legacy_status_field_backward_compat() {
		$draft_id   = $this->factory->post->create( [ 'post_status' => 'draft' ] );
		$publish_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );

		$result = PostAbilities::handle_list_posts( [
			'status'   => 'draft',
			'per_page' => 50,
		] );

		$this->assertIsArray( $result );
		$ids = array_column( $result['posts'], 'id' );
		$this->assertContains( $draft_id, $ids );
		$this->assertNotContains( $publish_id, $ids );
	}

	/**
	 * Response includes query_args mirror so agents can self-correct.
	 */
	public function test_handle_list_posts_returns_query_args() {
		$this->factory->post->create( [ 'post_status' => 'publish' ] );

		$result = PostAbilities::handle_list_posts( [
			'orderby'     => 'title',
			'order'       => 'ASC',
			'post_status' => 'publish',
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'query_args', $result );
		$this->assertIsArray( $result['query_args'] );
		$this->assertArrayHasKey( 'orderby', $result['query_args'] );
		$this->assertSame( 'title', $result['query_args']['orderby'] );
		$this->assertSame( 'ASC', $result['query_args']['order'] );
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

	/**
	 * list-posts must not leak private posts just because post_status allows them.
	 */
	public function test_handle_list_posts_filters_private_posts_by_read_capability(): void {
		$admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		wp_set_current_user( $admin_id );

		$private_post_id = self::factory()->post->create(
			[
				'post_title'  => 'Private list-posts fixture',
				'post_status' => 'private',
				'post_type'   => 'post',
			]
		);

		wp_set_current_user( $subscriber_id );

		$result = PostAbilities::handle_list_posts(
			[
				'post_type'   => 'post',
				'post_status' => [ 'private' ],
				'per_page'    => 10,
			]
		);

		wp_delete_post( $private_post_id, true );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['total'] );
		$this->assertSame( [], $result['posts'] );
	}
}
