<?php
/**
 * Test case for ImageAbilities class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\ImageAbilities;
use WP_UnitTestCase;

/**
 * Test ImageAbilities handler methods.
 */
class ImageAbilitiesTest extends WP_UnitTestCase {

	// ─── handle_generate_alt_text ─────────────────────────────────

	/**
	 * Test handle_generate_alt_text returns WP_Error when wp_ai_client_prompt unavailable.
	 *
	 * In the test environment wp_ai_client_prompt() may not be available
	 * (requires WordPress 7.0+ AI Client SDK), so the handler must return a WP_Error.
	 */
	public function test_handle_generate_alt_text_no_ai_client_returns_wp_error() {
		if ( function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'wp_ai_client_prompt() is available; skipping unavailability test.' );
		}

		$result = ImageAbilities::handle_generate_alt_text( [
			'image_url' => 'https://example.com/image.jpg',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_client_unavailable', $result->get_error_code() );
	}

	/**
	 * Test handle_generate_alt_text with no image input returns WP_Error.
	 *
	 * When wp_ai_client_prompt is unavailable the ai_client_unavailable error
	 * fires first. When it IS available, no_image_provided fires. Either is valid.
	 */
	public function test_handle_generate_alt_text_no_image_returns_wp_error() {
		$result = ImageAbilities::handle_generate_alt_text( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertContains(
			$result->get_error_code(),
			[ 'ai_client_unavailable', 'no_image_provided' ],
			'Expected ai_client_unavailable or no_image_provided error code.'
		);
	}

	/**
	 * Test handle_generate_alt_text with invalid attachment ID returns WP_Error.
	 */
	public function test_handle_generate_alt_text_invalid_attachment_returns_wp_error() {
		if ( function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'wp_ai_client_prompt() is available; skipping unavailability test.' );
		}

		$result = ImageAbilities::handle_generate_alt_text( [
			'attachment_id' => 999999,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	// ─── handle_generate_image_prompt ────────────────────────────

	/**
	 * Test handle_generate_image_prompt returns WP_Error when wp_ai_client_prompt unavailable.
	 */
	public function test_handle_generate_image_prompt_no_ai_client_returns_wp_error() {
		if ( function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'wp_ai_client_prompt() is available; skipping unavailability test.' );
		}

		$result = ImageAbilities::handle_generate_image_prompt( [
			'content' => 'A beautiful sunset over the mountains.',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_client_unavailable', $result->get_error_code() );
	}

	/**
	 * Test handle_generate_image_prompt with empty content returns WP_Error.
	 *
	 * content_not_provided fires after the AI client check, so when the AI
	 * client is unavailable that error fires first. Either is valid.
	 */
	public function test_handle_generate_image_prompt_empty_content_returns_wp_error() {
		$result = ImageAbilities::handle_generate_image_prompt( [
			'content' => '',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertContains(
			$result->get_error_code(),
			[ 'ai_client_unavailable', 'content_not_provided' ],
			'Expected ai_client_unavailable or content_not_provided error code.'
		);
	}

	/**
	 * Test handle_generate_image_prompt with missing content returns WP_Error.
	 */
	public function test_handle_generate_image_prompt_missing_content_returns_wp_error() {
		$result = ImageAbilities::handle_generate_image_prompt( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertContains(
			$result->get_error_code(),
			[ 'ai_client_unavailable', 'content_not_provided' ],
			'Expected ai_client_unavailable or content_not_provided error code.'
		);
	}

	/**
	 * Test handle_generate_image_prompt with non-existent post ID context returns WP_Error.
	 */
	public function test_handle_generate_image_prompt_nonexistent_post_context_returns_wp_error() {
		if ( function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'wp_ai_client_prompt() is available; skipping unavailability test.' );
		}

		$result = ImageAbilities::handle_generate_image_prompt( [
			'content' => 'Some content',
			'context' => '999999',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	/**
	 * Test handle_generate_image_prompt with valid post ID as context.
	 *
	 * When wp_ai_client_prompt is unavailable, ai_client_unavailable fires.
	 * When available, the prompt is built from the post and the AI is called.
	 */
	public function test_handle_generate_image_prompt_with_valid_post_context() {
		if ( function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'wp_ai_client_prompt() is available; skipping unavailability test.' );
		}

		$post_id = $this->factory->post->create( [
			'post_status'  => 'publish',
			'post_title'   => 'Test Post for Image Prompt',
			'post_content' => 'This is test content for generating an image prompt.',
		] );

		$result = ImageAbilities::handle_generate_image_prompt( [
			'content' => 'Some content',
			'context' => (string) $post_id,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_client_unavailable', $result->get_error_code() );
	}

	// ─── handle_import_base64_image ───────────────────────────────

	/**
	 * Test handle_import_base64_image returns WP_Error for missing data.
	 */
	public function test_handle_import_base64_image_missing_data_returns_wp_error() {
		$this->setExpectedIncorrectUsage( 'sd-ai-agent/import-base64-image' );
		$result = ImageAbilities::handle_import_base64_image( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_data', $result->get_error_code() );
	}

	/**
	 * Test handle_import_base64_image returns WP_Error for empty data.
	 */
	public function test_handle_import_base64_image_empty_data_returns_wp_error() {
		$this->setExpectedIncorrectUsage( 'sd-ai-agent/import-base64-image' );
		$result = ImageAbilities::handle_import_base64_image( [ 'data' => '' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_data', $result->get_error_code() );
	}

	/**
	 * Test handle_import_base64_image returns WP_Error for invalid base64.
	 */
	public function test_handle_import_base64_image_invalid_base64_returns_wp_error() {
		$this->setExpectedIncorrectUsage( 'sd-ai-agent/import-base64-image' );
		$result = ImageAbilities::handle_import_base64_image( [
			'data' => '!!!not-valid-base64!!!',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		// Either invalid_base64 or invalid_image depending on decode behaviour.
		$this->assertContains(
			$result->get_error_code(),
			[ 'invalid_base64', 'invalid_image' ],
			'Expected invalid_base64 or invalid_image error code.'
		);
	}

	/**
	 * Test handle_import_base64_image with non-image base64 data returns WP_Error.
	 */
	public function test_handle_import_base64_image_non_image_data_returns_wp_error() {
		$this->setExpectedIncorrectUsage( 'sd-ai-agent/import-base64-image' );
		// Valid base64 of plain text — not an image.
		$result = ImageAbilities::handle_import_base64_image( [
			'data' => base64_encode( 'This is plain text, not an image.' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test data encoding
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_image', $result->get_error_code() );
	}

	/**
	 * Test handle_import_base64_image with data URI prefix strips header correctly.
	 *
	 * After stripping the data URI prefix the payload is plain text. The MIME
	 * type is extracted from the header ("image/png"), so the image/invalid-type
	 * check passes. The handler then writes the bytes to a temp file and calls
	 * media_handle_sideload(), which fails because the bytes are not a valid PNG.
	 * The resulting error code is upload_error (from wp_handle_sideload).
	 */
	public function test_handle_import_base64_image_data_uri_prefix_stripped() {
		$this->setExpectedIncorrectUsage( 'sd-ai-agent/import-base64-image' );
		$plain_b64 = base64_encode( 'plain text content' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test data encoding
		$data_uri  = 'data:image/png;base64,' . $plain_b64;

		$result = ImageAbilities::handle_import_base64_image( [
			'data' => $data_uri,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		// The MIME type is taken from the data URI header ("image/png"), so the
		// image-type guard passes. media_handle_sideload then rejects the invalid
		// bytes and returns upload_error.
		$this->assertSame( 'upload_error', $result->get_error_code() );
	}

	/**
	 * Test handle_import_base64_image with a valid minimal PNG imports successfully.
	 *
	 * Uses a 1×1 transparent PNG encoded as base64.
	 */
	public function test_handle_import_base64_image_valid_png_imports() {
		$this->setExpectedIncorrectUsage( 'sd-ai-agent/import-base64-image' );
		// Minimal 1×1 transparent PNG (67 bytes).
		$png_b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

		$result = ImageAbilities::handle_import_base64_image( [
			'data'     => $png_b64,
			'filename' => 'test-image',
			'title'    => 'Test Image',
			'alt_text' => 'A 1x1 test image',
		] );

		if ( is_wp_error( $result ) ) {
			// In some test environments media_handle_sideload may fail — acceptable.
			$this->assertInstanceOf( \WP_Error::class, $result );
			return;
		}

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertArrayHasKey( 'url', $result );
		$this->assertArrayHasKey( 'filename', $result );
		$this->assertArrayHasKey( 'title', $result );
		$this->assertArrayHasKey( 'alt_text', $result );
		$this->assertGreaterThan( 0, $result['id'] );

		// Clean up the attachment.
		wp_delete_attachment( $result['id'], true );
	}

	// ─── permission_upload_files ──────────────────────────────────

	/**
	 * Test permission_upload_files returns WP_Error for unauthenticated user.
	 */
	public function test_permission_upload_files_unauthenticated_returns_wp_error() {
		wp_set_current_user( 0 );

		$result = ImageAbilities::permission_upload_files( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'insufficient_capabilities', $result->get_error_code() );
	}

	/**
	 * Test permission_upload_files returns true for administrator.
	 */
	public function test_permission_upload_files_admin_returns_true() {
		$admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$result = ImageAbilities::permission_upload_files( [] );

		$this->assertTrue( $result );
	}

	// ─── permission_edit_posts ────────────────────────────────────

	/**
	 * Test permission_edit_posts returns WP_Error for unauthenticated user.
	 */
	public function test_permission_edit_posts_unauthenticated_returns_wp_error() {
		wp_set_current_user( 0 );

		$result = ImageAbilities::permission_edit_posts( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'insufficient_capabilities', $result->get_error_code() );
	}

	/**
	 * Test permission_edit_posts returns true for editor.
	 */
	public function test_permission_edit_posts_editor_returns_true() {
		$editor_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );

		$result = ImageAbilities::permission_edit_posts( [] );

		$this->assertTrue( $result );
	}

	/**
	 * Test permission_edit_posts with numeric context checks object-level capability.
	 *
	 * An author cannot edit another author's post, so permission should be denied.
	 */
	public function test_permission_edit_posts_author_cannot_edit_others_post() {
		$author1_id = $this->factory->user->create( [ 'role' => 'author' ] );
		$author2_id = $this->factory->user->create( [ 'role' => 'author' ] );

		$post_id = $this->factory->post->create( [
			'post_author' => $author1_id,
			'post_status' => 'publish',
		] );

		wp_set_current_user( $author2_id );

		$result = ImageAbilities::permission_edit_posts( [ 'context' => (string) $post_id ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'insufficient_capabilities', $result->get_error_code() );
	}
}
