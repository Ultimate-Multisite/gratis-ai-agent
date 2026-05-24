<?php

declare(strict_types=1);
/**
 * Uploads disabled security tests.
 *
 * Verifies that upload paths honour site-level upload restrictions.
 *
 * Two distinct restriction surfaces are under test:
 *
 * 1. **WP_DISABLE_UPLOADS constant** — defined in wp-config.php to disable
 *    all file uploads site-wide.  Every upload handler must reject with
 *    `WP_Error('uploads_disabled', ...)` BEFORE any file operation.
 *    Tracked in GH#1803 (not yet enforced by handlers).
 *
 * 2. **Missing `upload_files` capability** — a user without `upload_files`
 *    cannot upload media even when uploads are globally enabled.  The
 *    `upload-media` ability has the correct `permission_callback`, but the
 *    handler itself does not re-check the capability internally.
 *    Tracked in GH#1803 (not yet enforced by handlers).
 *
 * Tests marked `markTestIncomplete` document the gap; passing tests confirm
 * current input-validation behaviour (source discriminator, empty inputs).
 *
 * @package SdAiAgent
 * @subpackage Tests\Security
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1789
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1803
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Security;

use SdAiAgent\Abilities\MediaAbilities;
use SdAiAgent\Abilities\UploadMediaAbility;
use WP_UnitTestCase;

/**
 * Upload security tests — disabled uploads and capability checks.
 *
 * @group security
 * @group uploads-disabled
 *
 * @since 1.11.0
 */
class UploadsDisabledTest extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Contributor user ID (has `edit_posts` but NOT `upload_files`).
	 *
	 * @var int
	 */
	private int $contributor_id;

	/**
	 * Set up users before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id       = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->contributor_id = self::factory()->user->create( [ 'role' => 'contributor' ] );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Restore user context after each test.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// ── WP_DISABLE_UPLOADS (GH#1803) ─────────────────────────────────────

	/**
	 * WP_DISABLE_UPLOADS: upload-media source=url must return uploads_disabled.
	 *
	 * Fixed in GH#1803 (PR #1805): handle_upload_media now calls check_uploads_disabled()
	 * before any source processing. This test is a regression guard for that fix.
	 *
	 * @see https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1803
	 */
	public function test_upload_media_url_rejected_when_uploads_disabled(): void {
		if ( ! defined( 'WP_DISABLE_UPLOADS' ) ) {
			define( 'WP_DISABLE_UPLOADS', true );
		}

		$result = UploadMediaAbility::handle_upload_media( [
			'source' => 'url',
			'url'    => 'https://example.com/image.jpg',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'uploads_disabled', $result->get_error_code() );
	}

	/**
	 * WP_DISABLE_UPLOADS: upload-media source=base64 must return uploads_disabled.
	 *
	 * Fixed in GH#1803 (PR #1805).
	 *
	 * @see https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1803
	 */
	public function test_upload_media_base64_rejected_when_uploads_disabled(): void {
		if ( ! defined( 'WP_DISABLE_UPLOADS' ) ) {
			define( 'WP_DISABLE_UPLOADS', true );
		}

		// Tiny valid PNG (1×1 white pixel).
		$png_base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI6QAAAABJRU5ErkJggg==';

		$result = UploadMediaAbility::handle_upload_media( [
			'source'      => 'base64',
			'data_base64' => $png_base64,
			'mime_type'   => 'image/png',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'uploads_disabled', $result->get_error_code() );
	}

	/**
	 * WP_DISABLE_UPLOADS: upload-media source=path must return uploads_disabled.
	 *
	 * Fixed in GH#1803 (PR #1805).
	 *
	 * @see https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1803
	 */
	public function test_upload_media_path_rejected_when_uploads_disabled(): void {
		if ( ! defined( 'WP_DISABLE_UPLOADS' ) ) {
			define( 'WP_DISABLE_UPLOADS', true );
		}

		$result = UploadMediaAbility::handle_upload_media( [
			'source' => 'path',
			'path'   => ABSPATH . 'wp-includes/images/w-logo-blue.png',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'uploads_disabled', $result->get_error_code() );
	}

	/**
	 * WP_DISABLE_UPLOADS: legacy upload-media-from-url (sideload_from_url) must return uploads_disabled.
	 *
	 * Fixed in GH#1803 (PR #1805): MediaAbilities::sideload_from_url now checks the constant.
	 *
	 * @see https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1803
	 */
	public function test_upload_media_from_url_rejected_when_uploads_disabled(): void {
		if ( ! defined( 'WP_DISABLE_UPLOADS' ) ) {
			define( 'WP_DISABLE_UPLOADS', true );
		}

		$this->setExpectedIncorrectUsage( 'sd-ai-agent/upload-media-from-url' );
		$result = MediaAbilities::handle_upload_media_from_url( [
			'url' => 'https://example.com/image.jpg',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'uploads_disabled', $result->get_error_code() );
	}

	/**
	 * WP_DISABLE_UPLOADS: import-base64-image (sideload_from_base64) must return uploads_disabled.
	 *
	 * Fixed in GH#1803 (PR #1805): ImageAbilities::sideload_from_base64 now checks the constant.
	 *
	 * @see https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1803
	 */
	public function test_import_base64_image_rejected_when_uploads_disabled(): void {
		if ( ! defined( 'WP_DISABLE_UPLOADS' ) ) {
			define( 'WP_DISABLE_UPLOADS', true );
		}

		$png_base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI6QAAAABJRU5ErkJggg==';

		$result = \SdAiAgent\Abilities\ImageAbilities::sideload_from_base64( [
			'data_base64' => $png_base64,
			'mime_type'   => 'image/png',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'uploads_disabled', $result->get_error_code() );
	}

	// ── Contributor without upload_files (GH#1803) ────────────────────────

	/**
	 * Contributor without upload_files calling upload-media must be rejected.
	 *
	 * The permission_callback for the `upload-media` ability correctly checks
	 * `current_user_can('upload_files')`.  However, the handler itself does not
	 * re-check the capability, so when called directly (bypassing the ability
	 * API), a contributor can invoke it.
	 *
	 * Once GH#1803 is fixed, this test verifies that the handler enforces the
	 * capability internally.
	 *
	 * @see https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1803
	 */
	public function test_upload_media_contributor_without_upload_files_is_rejected(): void {
		$this->markTestIncomplete(
			'GH#1803: handle_upload_media does not re-check current_user_can("upload_files") internally. ' .
			'A contributor calling the handler directly (bypassing permission_callback) can proceed. ' .
			'Fix: add an internal capability check at the top of handle_upload_media.'
		);

		wp_set_current_user( $this->contributor_id );
		$this->assertFalse(
			current_user_can( 'upload_files' ),
			'Sanity check: contributor must not have upload_files.'
		);

		// Tiny valid PNG (1×1 white pixel).
		$png_base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI6QAAAABJRU5ErkJggg==';

		$result = UploadMediaAbility::handle_upload_media( [
			'source'      => 'base64',
			'data_base64' => $png_base64,
			'mime_type'   => 'image/png',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertContains(
			$result->get_error_code(),
			[ 'uploads_disabled', 'insufficient_capability' ],
			'Contributor without upload_files must be rejected.'
		);
	}

	// ── Input validation (currently passing) ─────────────────────────────

	/**
	 * handle_upload_media returns source_required when no source is provided.
	 *
	 * This confirms the source discriminator validation is the first check and
	 * is not bypassed by WP_DISABLE_UPLOADS considerations.
	 */
	public function test_upload_media_returns_source_required_when_source_missing(): void {
		if ( defined( 'WP_DISABLE_UPLOADS' ) && WP_DISABLE_UPLOADS ) {
			// When WP_DISABLE_UPLOADS is set, uploads_disabled fires before source validation.
			// Source validation is only reachable when uploads are enabled.
			$this->markTestSkipped( 'WP_DISABLE_UPLOADS is defined; source validation is bypassed by uploads_disabled check.' );
		}

		$result = UploadMediaAbility::handle_upload_media( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'source_required', $result->get_error_code() );
	}

	/**
	 * handle_upload_media returns source_required for an unknown source value.
	 */
	public function test_upload_media_returns_source_required_for_unknown_source(): void {
		if ( defined( 'WP_DISABLE_UPLOADS' ) && WP_DISABLE_UPLOADS ) {
			// When WP_DISABLE_UPLOADS is set, uploads_disabled fires before source validation.
			$this->markTestSkipped( 'WP_DISABLE_UPLOADS is defined; source validation is bypassed by uploads_disabled check.' );
		}

		$result = UploadMediaAbility::handle_upload_media( [
			'source' => 'ftp',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'source_required', $result->get_error_code() );
	}

	/**
	 * Sanity check: contributor role does NOT have the upload_files capability.
	 *
	 * This verifies the WordPress role mapping so that IDOR/capability tests
	 * built on contributor role remain valid.
	 */
	public function test_contributor_role_does_not_have_upload_files_capability(): void {
		wp_set_current_user( $this->contributor_id );
		$this->assertFalse(
			current_user_can( 'upload_files' ),
			'WordPress contributor role must not have upload_files capability.'
		);
	}

	/**
	 * Sanity check: administrator role DOES have the upload_files capability.
	 */
	public function test_admin_role_has_upload_files_capability(): void {
		wp_set_current_user( $this->admin_id );
		$this->assertTrue(
			current_user_can( 'upload_files' ),
			'WordPress administrator role must have upload_files capability.'
		);
	}
}
