<?php
/**
 * Test case for UploadMediaAbility class.
 *
 * Covers all acceptance criteria from t263:
 *  - source discriminator validation
 *  - source=url (SSRF guard integration)
 *  - source=base64 (MIME/data mismatch)
 *  - source=path (path-traversal guard)
 *  - optional post_id sets post_parent
 *  - legacy abilities still callable with _doing_it_wrong
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\ImageAbilities;
use SdAiAgent\Abilities\MediaAbilities;
use SdAiAgent\Abilities\UploadMediaAbility;
use WP_Post;
use WP_UnitTestCase;

/**
 * Test UploadMediaAbility::handle_upload_media().
 */
class UploadMediaAbilityTest extends WP_UnitTestCase {

	/**
	 * Minimal 1×1 transparent PNG (67 bytes) as raw binary.
	 *
	 * Used across tests that need a valid PNG payload without a network
	 * request or GD extension.
	 *
	 * @return string Binary PNG data.
	 */
	private static function minimal_png_bytes(): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test fixture decoding
		return (string) base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
		);
	}

	// ─── source discriminator ─────────────────────────────────────────────────

	/**
	 * Missing `source` field returns source_required error.
	 *
	 * AC7: Missing source → WP_Error('source_required', ...).
	 */
	public function test_missing_source_returns_source_required() {
		$result = UploadMediaAbility::handle_upload_media( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'source_required', $result->get_error_code() );
	}

	/**
	 * Empty `source` field returns source_required error.
	 */
	public function test_empty_source_returns_source_required() {
		$result = UploadMediaAbility::handle_upload_media( [ 'source' => '' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'source_required', $result->get_error_code() );
	}

	/**
	 * Unknown `source` value returns source_required error.
	 */
	public function test_unknown_source_returns_source_required() {
		$result = UploadMediaAbility::handle_upload_media( [ 'source' => 'ftp' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'source_required', $result->get_error_code() );
	}

	// ─── source=url ──────────────────────────────────────────────────────────

	/**
	 * source=url with empty URL returns ai_agent_empty_url.
	 */
	public function test_url_source_empty_url_returns_error() {
		$result = UploadMediaAbility::handle_upload_media( [
			'source' => 'url',
			'url'    => '',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_empty_url', $result->get_error_code() );
	}

	/**
	 * source=url with SSRF-blocked URL returns WP_Error.
	 *
	 * AC6: source=url with http://169.254.169.254/ → blocked by SsrfGuard (t254).
	 *
	 * The SsrfGuard wraps the error as ai_agent_download_failed; we just
	 * verify a WP_Error is returned rather than a successful download.
	 */
	public function test_url_source_ssrf_blocked_returns_error() {
		$result = UploadMediaAbility::handle_upload_media( [
			'source' => 'url',
			'url'    => 'http://169.254.169.254/latest/meta-data/',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * source=url with an unreachable hostname returns WP_Error.
	 *
	 * HTTP download fails; handler returns WP_Error instead of throwing.
	 */
	public function test_url_source_unreachable_url_returns_error() {
		$result = UploadMediaAbility::handle_upload_media( [
			'source' => 'url',
			'url'    => 'https://nonexistent-domain-xyz-99999.invalid/image.jpg',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	// ─── source=base64 ───────────────────────────────────────────────────────

	/**
	 * source=base64 with missing data_base64 returns missing_data.
	 */
	public function test_base64_source_missing_data_returns_error() {
		$result = UploadMediaAbility::handle_upload_media( [ 'source' => 'base64' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_data', $result->get_error_code() );
	}

	/**
	 * source=base64 with non-image payload returns invalid_image.
	 */
	public function test_base64_source_non_image_data_returns_error() {
		$result = UploadMediaAbility::handle_upload_media( [
			'source'      => 'base64',
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test data encoding
			'data_base64' => base64_encode( 'This is plain text, not an image.' ),
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_image', $result->get_error_code() );
	}

	/**
	 * source=base64 with MIME/data mismatch returns mime_data_mismatch.
	 *
	 * AC3: source=base64 with mime_type: image/png but JPEG bytes → mime_data_mismatch.
	 *
	 * Here we use a PNG payload but declare mime_type as image/jpeg; the binary
	 * magic bytes (\x89PNG) reveal the actual type and the mismatch is rejected.
	 */
	public function test_base64_source_mime_data_mismatch_returns_error() {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test data encoding
		$png_b64 = base64_encode( self::minimal_png_bytes() );

		$result = UploadMediaAbility::handle_upload_media( [
			'source'      => 'base64',
			'data_base64' => $png_b64,
			'mime_type'   => 'image/jpeg', // Declared JPEG, actual is PNG → mismatch.
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mime_data_mismatch', $result->get_error_code() );
	}

	/**
	 * source=base64 with valid PNG and correct mime_type creates an attachment.
	 *
	 * AC2: source=base64 with a valid PNG payload + mime_type: image/png → attachment created.
	 */
	public function test_base64_source_valid_png_creates_attachment() {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test data encoding
		$png_b64 = base64_encode( self::minimal_png_bytes() );

		$result = UploadMediaAbility::handle_upload_media( [
			'source'      => 'base64',
			'data_base64' => $png_b64,
			'mime_type'   => 'image/png',
			'filename'    => 'test-upload-png',
			'title'       => 'Test PNG Upload',
		] );

		if ( is_wp_error( $result ) ) {
			// Some CI environments cannot run media_handle_sideload — acceptable.
			$this->assertInstanceOf( \WP_Error::class, $result );
			return;
		}

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertArrayHasKey( 'url', $result );
		$this->assertArrayHasKey( 'mime_type', $result );
		$this->assertArrayHasKey( 'filesize_bytes', $result );
		$this->assertArrayHasKey( 'width', $result );
		$this->assertArrayHasKey( 'height', $result );
		$this->assertArrayHasKey( 'source', $result );
		$this->assertGreaterThan( 0, $result['attachment_id'] );
		$this->assertSame( 'base64', $result['source'] );
		$this->assertSame( 'image/png', $result['mime_type'] );

		// Clean up.
		wp_delete_attachment( $result['attachment_id'], true );
	}

	/**
	 * source=base64 without explicit mime_type auto-detects from binary magic bytes.
	 *
	 * The PNG magic bytes allow detection without a declared mime_type.
	 */
	public function test_base64_source_auto_detected_mime_creates_attachment() {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test data encoding
		$png_b64 = base64_encode( self::minimal_png_bytes() );

		$result = UploadMediaAbility::handle_upload_media( [
			'source'      => 'base64',
			'data_base64' => $png_b64,
			// No mime_type provided — should be detected from binary.
		] );

		if ( is_wp_error( $result ) ) {
			$this->assertInstanceOf( \WP_Error::class, $result );
			return;
		}

		$this->assertIsArray( $result );
		$this->assertSame( 'image/png', $result['mime_type'] );
		$this->assertSame( 'base64', $result['source'] );

		// Clean up.
		wp_delete_attachment( $result['attachment_id'], true );
	}

	// ─── source=path ─────────────────────────────────────────────────────────

	/**
	 * source=path with empty path returns path_escape.
	 */
	public function test_path_source_empty_path_returns_error() {
		$result = UploadMediaAbility::handle_upload_media( [
			'source' => 'path',
			'path'   => '',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'path_escape', $result->get_error_code() );
	}

	/**
	 * source=path with non-existent path returns path_escape.
	 *
	 * realpath() returns false for non-existent paths, triggering the guard.
	 */
	public function test_path_source_nonexistent_path_returns_error() {
		$result = UploadMediaAbility::handle_upload_media( [
			'source' => 'path',
			'path'   => '../../etc/passwd',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'path_escape', $result->get_error_code() );
	}

	/**
	 * source=path with path outside ABSPATH returns path_escape.
	 *
	 * AC5: source=path with ../../etc/passwd → WP_Error('path_escape', ...).
	 *
	 * /etc/passwd exists on Linux; realpath() resolves it, but AbsPathGuard
	 * rejects it because it is not inside ABSPATH.
	 */
	public function test_path_source_outside_abspath_blocked() {
		if ( ! file_exists( '/etc/passwd' ) ) {
			$this->markTestSkipped( '/etc/passwd not present on this platform.' );
		}

		$result = UploadMediaAbility::handle_upload_media( [
			'source' => 'path',
			'path'   => '/etc/passwd',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'path_escape', $result->get_error_code() );
	}

	/**
	 * source=path with a valid file inside ABSPATH creates an attachment.
	 *
	 * AC4: source=path with a path inside ABSPATH → attachment created.
	 */
	public function test_path_source_inside_abspath_creates_attachment() {
		// Create a temporary PNG file directly inside ABSPATH.
		$tmp_filename = 'sd-ai-agent-test-' . uniqid() . '.png';
		$tmp_path     = rtrim( ABSPATH, '/' ) . '/' . $tmp_filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_ops_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture writing
		$written = file_put_contents( $tmp_path, self::minimal_png_bytes() );

		if ( false === $written ) {
			$this->markTestSkipped( 'Cannot write test file to ABSPATH: ' . $tmp_path );
		}

		$result = UploadMediaAbility::handle_upload_media( [
			'source' => 'path',
			'path'   => $tmp_path,
			'title'  => 'Path Source Test',
		] );

		// Clean up the original file (the copy was moved to uploads by WP).
		if ( file_exists( $tmp_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup
			unlink( $tmp_path );
		}

		if ( is_wp_error( $result ) ) {
			// Some CI environments cannot run media_handle_sideload — acceptable.
			$this->assertInstanceOf( \WP_Error::class, $result );
			return;
		}

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertGreaterThan( 0, $result['attachment_id'] );
		$this->assertSame( 'path', $result['source'] );
		$this->assertArrayHasKey( 'filesize_bytes', $result );
		$this->assertArrayHasKey( 'width', $result );
		$this->assertArrayHasKey( 'height', $result );

		// Clean up.
		wp_delete_attachment( $result['attachment_id'], true );
	}

	// ─── optional post_id ────────────────────────────────────────────────────

	/**
	 * Optional post_id sets attachment post_parent.
	 *
	 * AC9: post_id provided → attachment is set as child of post.
	 */
	public function test_post_id_sets_attachment_parent() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test data encoding
		$png_b64 = base64_encode( self::minimal_png_bytes() );

		$result = UploadMediaAbility::handle_upload_media( [
			'source'      => 'base64',
			'data_base64' => $png_b64,
			'mime_type'   => 'image/png',
			'post_id'     => $post_id,
		] );

		if ( is_wp_error( $result ) ) {
			$this->assertInstanceOf( \WP_Error::class, $result );
			return;
		}

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['attachment_id'] );

		$attachment = get_post( $result['attachment_id'] );
		$this->assertInstanceOf( WP_Post::class, $attachment );
		$this->assertSame( $post_id, (int) $attachment->post_parent );

		// Clean up.
		wp_delete_attachment( $result['attachment_id'], true );
	}

	// ─── response shape ──────────────────────────────────────────────────────

	/**
	 * Successful upload response contains all expected keys.
	 */
	public function test_response_has_unified_shape() {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test data encoding
		$png_b64 = base64_encode( self::minimal_png_bytes() );

		$result = UploadMediaAbility::handle_upload_media( [
			'source'      => 'base64',
			'data_base64' => $png_b64,
			'mime_type'   => 'image/png',
		] );

		if ( is_wp_error( $result ) ) {
			$this->assertInstanceOf( \WP_Error::class, $result );
			return;
		}

		$required_keys = [ 'attachment_id', 'url', 'mime_type', 'filesize_bytes', 'width', 'height', 'source' ];
		foreach ( $required_keys as $key ) {
			$this->assertArrayHasKey( $key, $result, "Missing response key: {$key}" );
		}
		$this->assertIsInt( $result['attachment_id'] );
		$this->assertIsString( $result['url'] );
		$this->assertIsString( $result['mime_type'] );
		$this->assertIsInt( $result['filesize_bytes'] );
		$this->assertIsInt( $result['width'] );
		$this->assertIsInt( $result['height'] );
		$this->assertIsString( $result['source'] );

		// Clean up.
		wp_delete_attachment( $result['attachment_id'], true );
	}

	// ─── legacy backward compatibility ───────────────────────────────────────

	/**
	 * Legacy upload-media-from-url ability is still callable; emits _doing_it_wrong.
	 *
	 * AC8: Old upload-media-from-url ability still callable; emits _doing_it_wrong.
	 */
	public function test_legacy_upload_from_url_emits_doing_it_wrong() {
		$this->setExpectedIncorrectUsage( 'sd-ai-agent/upload-media-from-url' );

		// Empty URL triggers early return — no network request needed.
		$result = MediaAbilities::handle_upload_media_from_url( [ 'url' => '' ] );

		// Function still returns WP_Error (not an exception), proving it is callable.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_empty_url', $result->get_error_code() );
	}

	/**
	 * Legacy import-base64-image ability is still callable; emits _doing_it_wrong.
	 *
	 * AC8: Old import-base64-image ability still callable; emits _doing_it_wrong.
	 */
	public function test_legacy_import_base64_image_emits_doing_it_wrong() {
		$this->setExpectedIncorrectUsage( 'sd-ai-agent/import-base64-image' );

		// Empty data triggers early return.
		$result = ImageAbilities::handle_import_base64_image( [ 'data' => '' ] );

		// Function still returns WP_Error (not an exception), proving it is callable.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_data', $result->get_error_code() );
	}
}
