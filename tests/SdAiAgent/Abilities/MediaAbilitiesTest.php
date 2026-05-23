<?php
/**
 * Test case for MediaAbilities class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\MediaAbilities;
use WP_UnitTestCase;

/**
 * Test MediaAbilities handler methods.
 */
class MediaAbilitiesTest extends WP_UnitTestCase {

	// ─── handle_list_media ────────────────────────────────────────

	/**
	 * Test handle_list_media returns expected structure.
	 */
	public function test_handle_list_media_returns_structure() {
		$result = MediaAbilities::handle_list_media( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
	}

	/**
	 * Test handle_list_media total matches items array count.
	 */
	public function test_handle_list_media_total_matches_count() {
		$result = MediaAbilities::handle_list_media( [] );

		$this->assertSame( count( $result['items'] ), $result['total'] );
	}

	/**
	 * Test handle_list_media with attachment returns item structure.
	 */
	public function test_handle_list_media_item_structure() {
		// Create a test attachment.
		$attachment_id = $this->factory->attachment->create( [
			'post_title'     => 'Test Image',
			'post_mime_type' => 'image/jpeg',
			'post_status'    => 'inherit',
		] );

		$result = MediaAbilities::handle_list_media( [] );

		$this->assertNotEmpty( $result['items'] );
		$item = $result['items'][0];
		$this->assertArrayHasKey( 'id', $item );
		$this->assertArrayHasKey( 'url', $item );
		$this->assertArrayHasKey( 'title', $item );
		$this->assertArrayHasKey( 'alt_text', $item );
		$this->assertArrayHasKey( 'mime_type', $item );
		$this->assertArrayHasKey( 'date', $item );
		$this->assertArrayHasKey( 'file_size', $item );
	}

	/**
	 * Test handle_list_media with mime_type filter returns only matching items.
	 */
	public function test_handle_list_media_mime_type_filter() {
		$this->factory->attachment->create( [
			'post_mime_type' => 'image/jpeg',
			'post_status'    => 'inherit',
		] );

		$result = MediaAbilities::handle_list_media( [ 'mime_type' => 'image/jpeg' ] );

		$this->assertIsArray( $result );
		foreach ( $result['items'] as $item ) {
			$this->assertSame( 'image/jpeg', $item['mime_type'] );
		}
	}

	/**
	 * Test handle_list_media limit is respected.
	 */
	public function test_handle_list_media_limit() {
		$this->factory->attachment->create_many( 5, [
			'post_mime_type' => 'image/jpeg',
			'post_status'    => 'inherit',
		] );

		$result = MediaAbilities::handle_list_media( [ 'limit' => 2 ] );

		$this->assertLessThanOrEqual( 2, $result['total'] );
	}

	/**
	 * Test handle_list_media limit is clamped to 100 maximum.
	 */
	public function test_handle_list_media_limit_clamped_max() {
		$result = MediaAbilities::handle_list_media( [ 'limit' => 9999 ] );

		$this->assertIsArray( $result );
		$this->assertLessThanOrEqual( 100, $result['total'] );
	}

	// ─── handle_upload_media_from_url ─────────────────────────────

	/**
	 * Test handle_upload_media_from_url with empty URL returns WP_Error.
	 */
	public function test_handle_upload_media_from_url_empty_url() {
		$this->setExpectedIncorrectUsage( 'sd-ai-agent/upload-media-from-url' );
		$result = MediaAbilities::handle_upload_media_from_url( [ 'url' => '' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_empty_url', $result->get_error_code() );
	}

	/**
	 * Test handle_upload_media_from_url with missing URL returns WP_Error.
	 */
	public function test_handle_upload_media_from_url_missing_url() {
		$this->setExpectedIncorrectUsage( 'sd-ai-agent/upload-media-from-url' );
		$result = MediaAbilities::handle_upload_media_from_url( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_empty_url', $result->get_error_code() );
	}

	/**
	 * Test handle_upload_media_from_url with unreachable URL returns WP_Error.
	 *
	 * In the test environment, HTTP requests to external URLs will fail.
	 * The handler should return a WP_Error, not throw an exception.
	 */
	public function test_handle_upload_media_from_url_unreachable_url() {
		$this->setExpectedIncorrectUsage( 'sd-ai-agent/upload-media-from-url' );
		$result = MediaAbilities::handle_upload_media_from_url( [
			'url' => 'https://nonexistent-domain-xyz-12345.example.com/image.jpg',
		] );

		// Should return WP_Error on download failure.
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	// ─── handle_delete_media ──────────────────────────────────────

	/**
	 * Test handle_delete_media with missing attachment_id returns WP_Error.
	 */
	public function test_handle_delete_media_missing_attachment_id() {
		$result = MediaAbilities::handle_delete_media( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_empty_attachment_id', $result->get_error_code() );
	}

	/**
	 * Test handle_delete_media with zero attachment_id returns WP_Error.
	 */
	public function test_handle_delete_media_zero_attachment_id() {
		$result = MediaAbilities::handle_delete_media( [ 'attachment_id' => 0 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_empty_attachment_id', $result->get_error_code() );
	}

	/**
	 * Test handle_delete_media with non-existent attachment_id returns WP_Error.
	 */
	public function test_handle_delete_media_not_found() {
		$result = MediaAbilities::handle_delete_media( [ 'attachment_id' => 999999 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_attachment_not_found', $result->get_error_code() );
	}

	/**
	 * Test handle_delete_media with a regular post ID (not attachment) returns WP_Error.
	 */
	public function test_handle_delete_media_non_attachment_post() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );

		$result = MediaAbilities::handle_delete_media( [ 'attachment_id' => $post_id ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_attachment_not_found', $result->get_error_code() );
	}

	/**
	 * Test handle_delete_media with valid attachment deletes it and returns structure.
	 */
	public function test_handle_delete_media_deletes_attachment() {
		$attachment_id = $this->factory->attachment->create( [
			'post_title'     => 'Deletable Image',
			'post_mime_type' => 'image/jpeg',
			'post_status'    => 'inherit',
		] );

		$result = MediaAbilities::handle_delete_media( [ 'attachment_id' => $attachment_id ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertArrayHasKey( 'title', $result );
		$this->assertArrayHasKey( 'deleted', $result );
		$this->assertSame( $attachment_id, $result['attachment_id'] );
		$this->assertTrue( $result['deleted'] );
	}
}
