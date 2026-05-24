<?php
/**
 * Test case for CoreImageEnricher (t266).
 *
 * Covers:
 * - Populated attachment metadata → all fields present.
 * - Missing attachment (deleted) → missing: true, no URL fields.
 * - Missing attrs.id → missing: true.
 * - Non-image blocks → enricher does not fire (supports() returns false).
 * - sizeSlug override → uses intermediate size dimensions.
 * - Aspect ratio computation.
 * - Alt text from attachment meta.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Enrichers;

use SdAiAgent\Enrichers\CoreImageEnricher;
use WP_UnitTestCase;

/**
 * Integration tests for CoreImageEnricher.
 *
 * Uses WP_UnitTestCase so attachment factory and metadata functions
 * are available.
 */
class CoreImageEnricherTest extends WP_UnitTestCase {

	/**
	 * The enricher under test.
	 *
	 * @var CoreImageEnricher
	 */
	private CoreImageEnricher $enricher;

	/**
	 * Set up the enricher instance before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->enricher = new CoreImageEnricher();
	}

	// ── Interface contract ────────────────────────────────────────────────

	/**
	 * get_id() returns 'core_image'.
	 */
	public function test_get_id(): void {
		$this->assertSame( 'core_image', $this->enricher->get_id() );
	}

	/**
	 * supports() returns true for core/image.
	 */
	public function test_supports_core_image(): void {
		$this->assertTrue( $this->enricher->supports( 'core/image' ) );
	}

	/**
	 * supports() returns false for non-image blocks.
	 */
	public function test_does_not_support_other_blocks(): void {
		$this->assertFalse( $this->enricher->supports( 'core/paragraph' ) );
		$this->assertFalse( $this->enricher->supports( 'core/heading' ) );
		$this->assertFalse( $this->enricher->supports( 'core/gallery' ) );
		$this->assertFalse( $this->enricher->supports( 'core/cover' ) );
	}

	// ── Missing id attr ───────────────────────────────────────────────────

	/**
	 * Block with no attrs.id → missing: true.
	 */
	public function test_missing_id_attr(): void {
		$block = [
			'blockName'    => 'core/image',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => '<figure class="wp-block-image"><img src="foo.jpg"/></figure>',
			'innerContent' => [ '<figure class="wp-block-image"><img src="foo.jpg"/></figure>' ],
		];

		$result = $this->enricher->enrich( $block, [] );

		$this->assertTrue( $result['missing'] );
		$this->assertArrayNotHasKey( 'url', $result );
		$this->assertArrayNotHasKey( 'width', $result );
	}

	/**
	 * Block with attrs.id = 0 → missing: true.
	 */
	public function test_zero_id_attr(): void {
		$block = [
			'blockName'    => 'core/image',
			'attrs'        => [ 'id' => 0 ],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];

		$result = $this->enricher->enrich( $block, [] );

		$this->assertTrue( $result['missing'] );
	}

	// ── Deleted attachment ────────────────────────────────────────────────

	/**
	 * Block with attrs.id pointing to a deleted attachment → missing: true.
	 */
	public function test_deleted_attachment(): void {
		$block = [
			'blockName'    => 'core/image',
			'attrs'        => [ 'id' => 999999 ],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];

		$result = $this->enricher->enrich( $block, [] );

		$this->assertTrue( $result['missing'] );
		$this->assertSame( 999999, $result['attachment_id'] );
		$this->assertArrayNotHasKey( 'url', $result );
	}

	/**
	 * Block with attrs.id pointing to a non-attachment post → missing: true.
	 */
	public function test_non_attachment_post(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

		$block = [
			'blockName'    => 'core/image',
			'attrs'        => [ 'id' => $post_id ],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];

		$result = $this->enricher->enrich( $block, [] );

		$this->assertTrue( $result['missing'] );
		$this->assertSame( $post_id, $result['attachment_id'] );
	}

	// ── Populated attachment ──────────────────────────────────────────────

	/**
	 * Block with a valid attachment → all expected fields populated.
	 */
	public function test_populated_attachment(): void {
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);

		// Ensure metadata is populated.
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$this->assertIsArray( $metadata, 'Attachment metadata should be populated by upload' );

		$block = [
			'blockName'    => 'core/image',
			'attrs'        => [ 'id' => $attachment_id ],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];

		$result = $this->enricher->enrich( $block, [] );

		$this->assertFalse( $result['missing'] );
		$this->assertSame( $attachment_id, $result['attachment_id'] );
		$this->assertNotEmpty( $result['url'] );
		$this->assertIsInt( $result['width'] );
		$this->assertIsInt( $result['height'] );
		$this->assertGreaterThan( 0, $result['width'] );
		$this->assertGreaterThan( 0, $result['height'] );
		$this->assertNotEmpty( $result['aspect_ratio'] );
		$this->assertStringContainsString( ':', $result['aspect_ratio'] );
		$this->assertSame( 'image/jpeg', $result['mime_type'] );
		$this->assertArrayHasKey( 'srcset', $result );
		$this->assertArrayHasKey( 'sizes', $result );
		$this->assertArrayHasKey( 'filesize_bytes', $result );
	}

	/**
	 * Alt text is surfaced from attachment meta.
	 */
	public function test_alt_text(): void {
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Beautiful canola field' );

		$block = [
			'blockName'    => 'core/image',
			'attrs'        => [ 'id' => $attachment_id ],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];

		$result = $this->enricher->enrich( $block, [] );

		$this->assertSame( 'Beautiful canola field', $result['alt'] );
	}

	// ── sizeSlug override ─────────────────────────────────────────────────

	/**
	 * When sizeSlug is set and matches an intermediate size, dimensions
	 * reflect the intermediate size, not the original.
	 */
	public function test_size_slug_override(): void {
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);

		$metadata = wp_get_attachment_metadata( $attachment_id );
		$this->assertIsArray( $metadata );

		// Find a valid intermediate size slug.
		$size_slug = null;
		if ( ! empty( $metadata['sizes'] ) ) {
			$size_slug = array_key_first( $metadata['sizes'] );
		}

		if ( null === $size_slug ) {
			$this->markTestSkipped( 'No intermediate sizes available for test image.' );
		}

		$block = [
			'blockName'    => 'core/image',
			'attrs'        => [
				'id'       => $attachment_id,
				'sizeSlug' => $size_slug,
			],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];

		$result = $this->enricher->enrich( $block, [] );

		$this->assertFalse( $result['missing'] );
		// The returned dimensions should match the intermediate size.
		$expected_width  = (int) $metadata['sizes'][ $size_slug ]['width'];
		$expected_height = (int) $metadata['sizes'][ $size_slug ]['height'];
		$this->assertSame( $expected_width, $result['width'] );
		$this->assertSame( $expected_height, $result['height'] );
	}

	// ── Aspect ratio ──────────────────────────────────────────────────────

	/**
	 * Aspect ratio is correctly simplified.
	 */
	public function test_aspect_ratio_simplified(): void {
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);

		$block = [
			'blockName'    => 'core/image',
			'attrs'        => [ 'id' => $attachment_id ],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];

		$result = $this->enricher->enrich( $block, [] );

		// Verify aspect_ratio is a valid ratio string (e.g. "3:2", "16:9").
		$this->assertMatchesRegularExpression( '/^\d+:\d+$/', $result['aspect_ratio'] );
	}

	// ── Attachment without metadata ──────────────────────────────────────

	/**
	 * Attachment that exists but has no metadata → missing: true.
	 */
	public function test_attachment_without_metadata(): void {
		// Create an attachment without uploading a file (no metadata).
		$attachment_id = self::factory()->attachment->create( [
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'Test attachment',
		] );

		// Explicitly delete metadata if any was auto-created.
		delete_post_meta( $attachment_id, '_wp_attachment_metadata' );

		$block = [
			'blockName'    => 'core/image',
			'attrs'        => [ 'id' => $attachment_id ],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];

		$result = $this->enricher->enrich( $block, [] );

		$this->assertTrue( $result['missing'] );
		$this->assertSame( $attachment_id, $result['attachment_id'] );
	}
}
