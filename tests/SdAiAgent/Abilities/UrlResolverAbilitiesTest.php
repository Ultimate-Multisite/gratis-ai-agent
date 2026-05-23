<?php
/**
 * Test case for UrlResolverAbilities class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\UrlResolverAbilities;
use WP_UnitTestCase;

/**
 * Tests for the sd-ai-agent/resolve-url ability handler.
 *
 * Coverage:
 *  - AC2: Bare slug → get_page_by_path, matched_via "slug_lookup"
 *  - AC3: ?p=N / ?page_id=N query string → url_to_postid, matched_via "url_to_postid"
 *  - AC4: Cross-host URL → WP_Error('external_host')
 *  - AC5: Unknown URL → WP_Error('not_found') with data.attempts
 *  - AC6: Draft post + edit_post cap → resolved; draft + no cap → not_found
 */
class UrlResolverAbilitiesTest extends WP_UnitTestCase {

	// ─── validation ───────────────────────────────────────────────

	/**
	 * Missing url key → WP_Error('missing_url').
	 */
	public function test_handle_resolve_url_missing_url_returns_error(): void {
		$result = UrlResolverAbilities::handle_resolve_url( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_url', $result->get_error_code() );
	}

	/**
	 * Empty/whitespace url → WP_Error('missing_url').
	 */
	public function test_handle_resolve_url_empty_url_returns_error(): void {
		$result = UrlResolverAbilities::handle_resolve_url( [ 'url' => '   ' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_url', $result->get_error_code() );
	}

	// ─── AC4: external host ──────────────────────────────────────

	/**
	 * AC4: Cross-host absolute URL → WP_Error('external_host').
	 */
	public function test_handle_resolve_url_cross_host_returns_external_host_error(): void {
		$result = UrlResolverAbilities::handle_resolve_url(
			[ 'url' => 'https://completely-different-external-site.example/foo/bar' ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'external_host', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'host', $data );
		$this->assertArrayHasKey( 'site_host', $data );
		$this->assertSame( 'completely-different-external-site.example', $data['host'] );
	}

	// ─── AC5: not_found with attempts ────────────────────────────

	/**
	 * AC5: Unknown bare slug → WP_Error('not_found') with slug_lookup in attempts.
	 */
	public function test_handle_resolve_url_unknown_slug_returns_not_found_with_attempts(): void {
		$result = UrlResolverAbilities::handle_resolve_url(
			[ 'url' => 'this-slug-absolutely-does-not-exist-xyz987' ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'attempts', $data );
		$this->assertContains( 'slug_lookup', $data['attempts'] );
	}

	/**
	 * AC5: Unknown absolute URL → WP_Error('not_found') with url_to_postid in attempts.
	 */
	public function test_handle_resolve_url_unknown_absolute_url_returns_not_found_with_attempts(): void {
		$unknown = home_url( '/this-page-does-not-exist-xyz987/' );

		$result = UrlResolverAbilities::handle_resolve_url( [ 'url' => $unknown ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'attempts', $data );
		$this->assertContains( 'url_to_postid', $data['attempts'] );
	}

	// ─── AC2: bare slug → slug_lookup ────────────────────────────

	/**
	 * AC2: Bare slug resolves via get_page_by_path; matched_via is "slug_lookup".
	 */
	public function test_handle_resolve_url_bare_slug_resolves_via_slug_lookup(): void {
		$post_id = $this->factory->post->create( [
			'post_type'   => 'page',
			'post_name'   => 'about-us-test-page',
			'post_title'  => 'About Us',
			'post_status' => 'publish',
		] );

		$result = UrlResolverAbilities::handle_resolve_url( [ 'url' => 'about-us-test-page' ] );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( 'slug_lookup', $result['matched_via'] );
	}

	/**
	 * AC2: Bare slug result contains all expected output keys with correct values.
	 */
	public function test_handle_resolve_url_bare_slug_returns_expected_structure(): void {
		$post_id = $this->factory->post->create( [
			'post_type'   => 'page',
			'post_name'   => 'structure-test-page',
			'post_title'  => 'Structure Test',
			'post_status' => 'publish',
		] );

		$result = UrlResolverAbilities::handle_resolve_url( [ 'url' => 'structure-test-page' ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'post_id', $result );
		$this->assertArrayHasKey( 'post_type', $result );
		$this->assertArrayHasKey( 'post_status', $result );
		$this->assertArrayHasKey( 'edit_link', $result );
		$this->assertArrayHasKey( 'permalink', $result );
		$this->assertArrayHasKey( 'title', $result );
		$this->assertArrayHasKey( 'matched_via', $result );

		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( 'page', $result['post_type'] );
		$this->assertSame( 'publish', $result['post_status'] );
		$this->assertSame( 'Structure Test', $result['title'] );
	}

	/**
	 * Bare slug resolves any public post type, not just pages.
	 */
	public function test_handle_resolve_url_bare_slug_resolves_post_post_type(): void {
		$post_id = $this->factory->post->create( [
			'post_name'   => 'my-test-article',
			'post_title'  => 'My Test Article',
			'post_status' => 'publish',
			'post_type'   => 'post',
		] );

		$result = UrlResolverAbilities::handle_resolve_url( [ 'url' => 'my-test-article' ] );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( 'post', $result['post_type'] );
		$this->assertSame( 'slug_lookup', $result['matched_via'] );
	}

	// ─── AC3: ?p=N / ?page_id=N → url_to_postid ─────────────────

	/**
	 * AC3: Absolute URL with ?p=N query string resolves via url_to_postid.
	 */
	public function test_handle_resolve_url_query_string_url_resolves_via_url_to_postid(): void {
		$post_id = $this->factory->post->create( [
			'post_title'  => 'Query String Post',
			'post_status' => 'publish',
		] );

		$url    = home_url( '?p=' . $post_id );
		$result = UrlResolverAbilities::handle_resolve_url( [ 'url' => $url ] );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( 'url_to_postid', $result['matched_via'] );
	}

	/**
	 * AC3: Relative ?p=N (without scheme/host) resolves via url_to_postid.
	 */
	public function test_handle_resolve_url_relative_query_string_resolves(): void {
		$post_id = $this->factory->post->create( [
			'post_title'  => 'Relative Query Post',
			'post_status' => 'publish',
		] );

		$result = UrlResolverAbilities::handle_resolve_url( [ 'url' => '?p=' . $post_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( 'url_to_postid', $result['matched_via'] );
	}

	/**
	 * AC3: Absolute URL with ?page_id=N resolves via url_to_postid.
	 */
	public function test_handle_resolve_url_page_id_query_string_resolves(): void {
		$page_id = $this->factory->post->create( [
			'post_type'   => 'page',
			'post_title'  => 'Page ID Test',
			'post_status' => 'publish',
		] );

		$url    = home_url( '?page_id=' . $page_id );
		$result = UrlResolverAbilities::handle_resolve_url( [ 'url' => $url ] );

		$this->assertIsArray( $result );
		$this->assertSame( $page_id, $result['post_id'] );
		$this->assertSame( 'url_to_postid', $result['matched_via'] );
	}

	// ─── AC6: draft visibility ────────────────────────────────────

	/**
	 * AC6: Draft post is resolved when current user has edit_post capability.
	 */
	public function test_handle_resolve_url_draft_post_visible_to_editor(): void {
		$editor_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );

		$post_id = $this->factory->post->create( [
			'post_type'   => 'page',
			'post_name'   => 'my-draft-page',
			'post_title'  => 'My Draft',
			'post_status' => 'draft',
		] );

		$result = UrlResolverAbilities::handle_resolve_url( [ 'url' => 'my-draft-page' ] );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( 'draft', $result['post_status'] );
	}

	/**
	 * AC6: Draft post is hidden from a subscriber who lacks edit_post.
	 */
	public function test_handle_resolve_url_draft_post_hidden_from_subscriber(): void {
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$this->factory->post->create( [
			'post_type'   => 'page',
			'post_name'   => 'hidden-draft-page',
			'post_title'  => 'Hidden Draft',
			'post_status' => 'draft',
		] );

		$result = UrlResolverAbilities::handle_resolve_url( [ 'url' => 'hidden-draft-page' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	/**
	 * Published post is accessible regardless of capability level.
	 */
	public function test_handle_resolve_url_published_post_accessible_to_subscriber(): void {
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$post_id = $this->factory->post->create( [
			'post_type'   => 'page',
			'post_name'   => 'public-page-subscriber',
			'post_title'  => 'Public Page',
			'post_status' => 'publish',
		] );

		$result = UrlResolverAbilities::handle_resolve_url( [ 'url' => 'public-page-subscriber' ] );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['post_id'] );
	}

	// ─── edit_link ────────────────────────────────────────────────

	/**
	 * edit_link is a non-empty string containing the post ID when user is admin.
	 */
	public function test_handle_resolve_url_edit_link_is_non_empty_string(): void {
		$admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );

		$result = UrlResolverAbilities::handle_resolve_url( [ 'url' => '?p=' . $post_id ] );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['edit_link'] );
		$this->assertStringContainsString( (string) $post_id, $result['edit_link'] );
	}
}
