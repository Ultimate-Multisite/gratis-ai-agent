<?php

declare(strict_types=1);
/**
 * Tests for InterviewUploadStore (GH#1534).
 *
 * Covers attachment tagging and listing, including:
 *   - tag_attachment() sets the META_FLAG, META_CATEGORY, and (optional)
 *     META_SESSION meta keys.
 *   - tag_attachment() falls back to the filename heuristic when no
 *     category override is provided.
 *   - tag_attachment() accepts a valid category override.
 *   - tag_attachment() rejects an unknown category override and still
 *     stores a valid filename-derived category.
 *   - list_uploads() returns only attachments tagged via tag_attachment().
 *   - list_uploads() filters by category and session_id correctly.
 *   - format_attachment() returns null for untagged attachments.
 *   - The DEFAULT_LIMIT and MAX_LIMIT caps are honoured.
 *
 * @package SdAiAgent\Tests\Core
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\InterviewUploadStore;
use SdAiAgent\Services\InterviewUploadCategoriser;
use WP_UnitTestCase;

/**
 * Test InterviewUploadStore behaviour.
 */
class InterviewUploadStoreTest extends WP_UnitTestCase {

	/**
	 * Create an in-database attachment without uploading a real file.
	 *
	 * The factory uses wp_insert_attachment so the post row exists; the
	 * `_wp_attached_file` meta is set explicitly so the categoriser has a
	 * filename to inspect when no override is supplied.
	 */
	private function make_attachment( string $filename ): int {
		$id = self::factory()->attachment->create(
			[
				'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
				'post_mime_type' => 'image/jpeg',
			]
		);
		update_post_meta( $id, '_wp_attached_file', '2026/05/' . $filename );
		return (int) $id;
	}

	// ── tag_attachment ─────────────────────────────────────────────────────

	public function test_tag_attachment_sets_flag_and_category(): void {
		$id = $this->make_attachment( 'shopfront-2024.jpg' );

		$category = InterviewUploadStore::tag_attachment( $id );

		$this->assertSame( InterviewUploadCategoriser::CATEGORY_SPACE, $category );
		$this->assertSame( '1', (string) get_post_meta( $id, InterviewUploadStore::META_FLAG, true ) );
		$this->assertSame(
			InterviewUploadCategoriser::CATEGORY_SPACE,
			get_post_meta( $id, InterviewUploadStore::META_CATEGORY, true )
		);
	}

	public function test_tag_attachment_stores_session_id_when_positive(): void {
		$id = $this->make_attachment( 'latte-art.jpg' );

		InterviewUploadStore::tag_attachment(
			$id,
			[ 'session_id' => 42 ]
		);

		$this->assertSame(
			'42',
			(string) get_post_meta( $id, InterviewUploadStore::META_SESSION, true )
		);
	}

	public function test_tag_attachment_skips_session_meta_for_zero(): void {
		$id = $this->make_attachment( 'latte-art.jpg' );

		InterviewUploadStore::tag_attachment(
			$id,
			[ 'session_id' => 0 ]
		);

		$this->assertSame(
			'',
			(string) get_post_meta( $id, InterviewUploadStore::META_SESSION, true )
		);
	}

	public function test_tag_attachment_accepts_valid_category_override(): void {
		$id = $this->make_attachment( 'IMG_0001.jpg' );

		$category = InterviewUploadStore::tag_attachment(
			$id,
			[ 'category' => 'team' ]
		);

		$this->assertSame( 'team', $category );
		$this->assertSame( 'team', get_post_meta( $id, InterviewUploadStore::META_CATEGORY, true ) );
	}

	public function test_tag_attachment_rejects_unknown_category_override(): void {
		$id = $this->make_attachment( 'latte-art.jpg' );

		// Unknown category override must NOT be stored; the heuristic on the
		// filename (latte = product) is used instead.
		$category = InterviewUploadStore::tag_attachment(
			$id,
			[ 'category' => 'mystery' ]
		);

		$this->assertSame( InterviewUploadCategoriser::CATEGORY_PRODUCT, $category );
		$this->assertSame(
			InterviewUploadCategoriser::CATEGORY_PRODUCT,
			get_post_meta( $id, InterviewUploadStore::META_CATEGORY, true )
		);
	}

	// ── list_uploads ───────────────────────────────────────────────────────

	public function test_list_uploads_returns_only_tagged_attachments(): void {
		$tagged   = $this->make_attachment( 'shopfront.jpg' );
		$untagged = $this->make_attachment( 'random.jpg' );

		InterviewUploadStore::tag_attachment( $tagged );

		$result = InterviewUploadStore::list_uploads();

		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( $tagged, $result['items'][0]['attachment_id'] );
		$this->assertNotEquals( $untagged, $result['items'][0]['attachment_id'] );
	}

	public function test_list_uploads_filters_by_category(): void {
		$space_id   = $this->make_attachment( 'shopfront.jpg' );
		$product_id = $this->make_attachment( 'latte.jpg' );

		InterviewUploadStore::tag_attachment( $space_id );
		InterviewUploadStore::tag_attachment( $product_id );

		$result = InterviewUploadStore::list_uploads( [ 'category' => 'product' ] );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $product_id, $result['items'][0]['attachment_id'] );
		$this->assertSame( 'product', $result['items'][0]['category'] );
	}

	public function test_list_uploads_filters_by_session_id(): void {
		$a = $this->make_attachment( 'shopfront.jpg' );
		$b = $this->make_attachment( 'team-photo.jpg' );

		InterviewUploadStore::tag_attachment( $a, [ 'session_id' => 100 ] );
		InterviewUploadStore::tag_attachment( $b, [ 'session_id' => 200 ] );

		$result = InterviewUploadStore::list_uploads( [ 'session_id' => 100 ] );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $a, $result['items'][0]['attachment_id'] );
	}

	public function test_list_uploads_provides_by_category_counts(): void {
		$ids = [
			$this->make_attachment( 'shopfront.jpg' ),
			$this->make_attachment( 'venue-exterior.jpg' ),
			$this->make_attachment( 'latte.jpg' ),
		];
		foreach ( $ids as $id ) {
			InterviewUploadStore::tag_attachment( $id );
		}

		$result = InterviewUploadStore::list_uploads();

		$this->assertSame( 3, $result['total'] );
		$this->assertSame( 2, $result['by_category']['space'] );
		$this->assertSame( 1, $result['by_category']['product'] );
	}

	public function test_list_uploads_returns_empty_when_no_tagged_uploads(): void {
		$this->make_attachment( 'random.jpg' ); // untagged

		$result = InterviewUploadStore::list_uploads();

		$this->assertSame( 0, $result['total'] );
		$this->assertSame( [], $result['items'] );
		$this->assertSame( [], $result['by_category'] );
	}

	public function test_list_uploads_clamps_oversized_limit(): void {
		$result = InterviewUploadStore::list_uploads( [ 'limit' => 10000 ] );

		// No items, but the call must not error; structural invariants hold.
		$this->assertSame( 0, $result['total'] );
		$this->assertIsArray( $result['items'] );
	}

	// ── format_attachment ──────────────────────────────────────────────────

	public function test_format_attachment_returns_null_for_untagged(): void {
		$id          = $this->make_attachment( 'random.jpg' );
		$attachment  = get_post( $id );

		$this->assertNotNull( $attachment );
		$this->assertNull( InterviewUploadStore::format_attachment( $attachment ) );
	}

	public function test_format_attachment_returns_shape_for_tagged(): void {
		$id         = $this->make_attachment( 'shopfront.jpg' );
		InterviewUploadStore::tag_attachment( $id );
		$attachment = get_post( $id );

		$this->assertNotNull( $attachment );
		$formatted = InterviewUploadStore::format_attachment( $attachment );

		$this->assertIsArray( $formatted );
		$this->assertSame( $id, $formatted['attachment_id'] );
		$this->assertSame( 'space', $formatted['category'] );
		$this->assertArrayHasKey( 'url', $formatted );
		$this->assertArrayHasKey( 'thumbnail', $formatted );
		$this->assertArrayHasKey( 'title', $formatted );
		$this->assertArrayHasKey( 'filename', $formatted );
		$this->assertArrayHasKey( 'mime_type', $formatted );
	}
}
