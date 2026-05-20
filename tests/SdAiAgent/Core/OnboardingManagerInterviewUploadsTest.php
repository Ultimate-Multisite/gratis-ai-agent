<?php

declare(strict_types=1);
/**
 * Tests for OnboardingManager interview-uploads REST endpoints (GH#1534).
 *
 * Covers route registration, the upload endpoint's handling of valid and
 * invalid input, and the listing endpoint shape. File-upload-side effects
 * are simulated by constructing $_FILES with a real temp file, since
 * wp_handle_upload's `test_form` check requires a multipart-style POST.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\InterviewUploadStore;
use SdAiAgent\Core\OnboardingManager;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Test the interview-uploads REST endpoints.
 */
class OnboardingManagerInterviewUploadsTest extends WP_UnitTestCase {

	/**
	 * Temp files created during the test so we can clean them up.
	 *
	 * @var list<string>
	 */
	private array $tmp_files = [];

	public function tear_down(): void {
		foreach ( $this->tmp_files as $f ) {
			if ( file_exists( $f ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $f );
			}
		}
		$this->tmp_files = [];
		// Reset the global $_FILES so it does not leak into other tests.
		$_FILES = [];

		// Clean any uploaded attachments tagged as interview uploads.
		$ids = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_key'       => InterviewUploadStore::META_FLAG, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			]
		);
		foreach ( $ids as $id ) {
			wp_delete_attachment( (int) $id, true );
		}

		parent::tear_down();
	}

	/**
	 * Create a small PNG temp file and return its path.
	 *
	 * Uses the 1x1 transparent PNG signature so MIME sniffing recognises
	 * it as an image — `media_handle_upload()` rejects unknown MIME types.
	 */
	private function make_temp_png( string $filename = 'shopfront-test.png' ): array {
		$png = base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgAAIAAAUAAen63NgAAAAASUVORK5CYII='
		);
		$path = wp_tempnam( $filename );
		file_put_contents( $path, $png );
		$this->tmp_files[] = $path;

		return [
			'name'     => $filename,
			'type'     => 'image/png',
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $png ),
		];
	}

	/**
	 * No-op kept for symmetry with prior implementation. The endpoint uses
	 * `wp_handle_sideload()` under the hood, which does not call
	 * `is_uploaded_file()`, so synthetic $_FILES entries in tests work
	 * without any additional filter scaffolding.
	 */
	private function allow_test_uploads(): void {
		// Intentionally empty.
	}

	// ── route registration ────────────────────────────────────────────────

	public function test_register_rest_routes_registers_interview_uploads_route(): void {
		do_action( 'rest_api_init' );
		OnboardingManager::register_rest_routes();

		$server = rest_get_server();
		$routes = $server->get_routes();

		$this->assertArrayHasKey( '/sd-ai-agent/v1/onboarding/interview-uploads', $routes );
	}

	// ── permission gating ─────────────────────────────────────────────────

	public function test_upload_permission_requires_upload_files_cap(): void {
		// Subscriber lacks upload_files.
		$subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$this->assertWPError( OnboardingManager::rest_upload_permission() );
	}

	public function test_upload_permission_passes_for_administrator(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$this->assertTrue( OnboardingManager::rest_upload_permission() );
	}

	// ── upload endpoint behaviour ─────────────────────────────────────────

	public function test_upload_endpoint_returns_error_when_no_files(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$_FILES   = [];
		$request  = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/onboarding/interview-uploads' );
		$response = OnboardingManager::rest_interview_upload( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'sd_ai_agent_no_files', $response->get_error_code() );
	}

	public function test_upload_endpoint_stores_attachment_and_tags_meta(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$this->allow_test_uploads();

		$file   = $this->make_temp_png( 'shopfront-interior.png' );
		$_FILES = [ 'files' => $file ];

		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/onboarding/interview-uploads' );
		$request->set_param( 'session_id', 42 );

		$response = OnboardingManager::rest_interview_upload( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertCount( 1, $data['uploads'], 'Expected one tagged upload.' );

		$attachment_id = $data['uploads'][0]['attachment_id'];
		$this->assertGreaterThan( 0, $attachment_id );
		$this->assertSame( '1', (string) get_post_meta( $attachment_id, InterviewUploadStore::META_FLAG, true ) );
		// Filename contains both "shopfront" and "interior" — first match wins,
		// and SPACE keywords come first in the categoriser, so SPACE is expected.
		$this->assertSame( 'space', get_post_meta( $attachment_id, InterviewUploadStore::META_CATEGORY, true ) );
		$this->assertSame( '42', (string) get_post_meta( $attachment_id, InterviewUploadStore::META_SESSION, true ) );
	}

	public function test_upload_endpoint_rejects_non_image_mime(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		// Synthesise a text file in $_FILES.
		$path = wp_tempnam( 'notes.txt' );
		file_put_contents( $path, 'hello world' );
		$this->tmp_files[] = $path;

		$_FILES = [
			'files' => [
				'name'     => 'notes.txt',
				'type'     => 'text/plain',
				'tmp_name' => $path,
				'error'    => UPLOAD_ERR_OK,
				'size'     => 11,
			],
		];

		$request  = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/onboarding/interview-uploads' );
		$response = OnboardingManager::rest_interview_upload( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertSame( [], $data['uploads'] );
		$this->assertNotEmpty( $data['errors'] );
		$this->assertSame( 'notes.txt', $data['errors'][0]['filename'] );
	}

	public function test_upload_endpoint_accepts_category_override(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$this->allow_test_uploads();

		// Filename matches the SPACE heuristic, but the explicit category
		// override pins it to TEAM.
		$file   = $this->make_temp_png( 'shopfront-override.png' );
		$_FILES = [ 'files' => $file ];

		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/onboarding/interview-uploads' );
		$request->set_param( 'category', 'team' );

		$response = OnboardingManager::rest_interview_upload( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertCount( 1, $data['uploads'] );
		$this->assertSame( 'team', $data['uploads'][0]['category'] );
	}

	public function test_upload_endpoint_ignores_unknown_category_override(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$this->allow_test_uploads();

		$file   = $this->make_temp_png( 'latte-cup.png' );
		$_FILES = [ 'files' => $file ];

		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/onboarding/interview-uploads' );
		$request->set_param( 'category', 'mystery' );

		$response = OnboardingManager::rest_interview_upload( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertCount( 1, $data['uploads'] );
		// Filename heuristic wins because the override was rejected.
		$this->assertSame( 'product', $data['uploads'][0]['category'] );
	}

	public function test_upload_endpoint_handles_multi_file_array_shape(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$this->allow_test_uploads();

		$png_a = base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgAAIAAAUAAen63NgAAAAASUVORK5CYII='
		);
		$path_a = wp_tempnam( 'venue-exterior.png' );
		$path_b = wp_tempnam( 'latte-cup.png' );
		file_put_contents( $path_a, $png_a );
		file_put_contents( $path_b, $png_a );
		$this->tmp_files[] = $path_a;
		$this->tmp_files[] = $path_b;

		$_FILES = [
			'files' => [
				'name'     => [ 'venue-exterior.png', 'latte-cup.png' ],
				'type'     => [ 'image/png', 'image/png' ],
				'tmp_name' => [ $path_a, $path_b ],
				'error'    => [ UPLOAD_ERR_OK, UPLOAD_ERR_OK ],
				'size'     => [ strlen( $png_a ), strlen( $png_a ) ],
			],
		];

		$request  = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/onboarding/interview-uploads' );
		$response = OnboardingManager::rest_interview_upload( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertCount( 2, $data['uploads'] );
		$categories = array_column( $data['uploads'], 'category' );
		sort( $categories );
		$this->assertSame( [ 'product', 'space' ], $categories );
	}

	// ── list endpoint ─────────────────────────────────────────────────────

	public function test_list_endpoint_returns_uploads_filtered_by_session(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		// Create a tagged attachment via the store directly (no upload needed).
		$attachment_id = self::factory()->attachment->create(
			[
				'post_title'     => 'shopfront-session-only',
				'post_mime_type' => 'image/png',
			]
		);
		update_post_meta( (int) $attachment_id, '_wp_attached_file', '2026/05/shopfront-session-only.png' );
		InterviewUploadStore::tag_attachment( (int) $attachment_id, [ 'session_id' => 7 ] );

		$request = new WP_REST_Request( 'GET', '/sd-ai-agent/v1/onboarding/interview-uploads' );
		$request->set_param( 'session_id', 7 );

		$response = OnboardingManager::rest_list_interview_uploads( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertSame( 1, $data['total'] );
		$this->assertSame( $attachment_id, $data['items'][0]['attachment_id'] );
	}
}
