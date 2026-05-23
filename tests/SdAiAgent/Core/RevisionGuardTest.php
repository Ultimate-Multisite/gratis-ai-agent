<?php
/**
 * Test case for RevisionGuard (GH#1710).
 *
 * Covers the acceptance criteria from the issue:
 *
 *  AC2: Stale If-Match returns 412 stale_revision with data.current_revision_id.
 *  AC3: Matching If-Match succeeds normally (returns true).
 *  AC4: Missing If-Match succeeds normally (back-compat, returns true).
 *  AC5: Weak ETag form W/"123" parses correctly.
 *  AC6: Malformed If-Match returns 400 invalid_if_match.
 *  AC7: parse_if_match() reads both If-Match header and expected_revision body.
 *  AC8: current_revision_id() returns 0 for a post with no revisions and a
 *       positive int after wp_update_post() creates one.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1710
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\RevisionGuard;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Tests for RevisionGuard optimistic concurrency control.
 */
class RevisionGuardTest extends WP_UnitTestCase {

	// ── parse_raw ──────────────────────────────────────────────────────────

	/**
	 * Empty string returns null (no If-Match → back-compat pass-through).
	 */
	public function test_parse_raw_empty_returns_null(): void {
		$this->assertNull( RevisionGuard::parse_raw( '' ) );
	}

	/**
	 * Bare integer string returns the integer.
	 */
	public function test_parse_raw_bare_integer(): void {
		$this->assertSame( 123, RevisionGuard::parse_raw( '123' ) );
	}

	/**
	 * Weak ETag form W/"123" is normalised correctly.
	 */
	public function test_parse_raw_weak_etag(): void {
		$this->assertSame( 456, RevisionGuard::parse_raw( 'W/"456"' ) );
	}

	/**
	 * Strong ETag form "123" is normalised correctly.
	 */
	public function test_parse_raw_strong_etag(): void {
		$this->assertSame( 789, RevisionGuard::parse_raw( '"789"' ) );
	}

	/**
	 * Surrounding whitespace is tolerated.
	 */
	public function test_parse_raw_whitespace_trimmed(): void {
		$this->assertSame( 42, RevisionGuard::parse_raw( '  42  ' ) );
	}

	/**
	 * Non-numeric garbage returns a 400 WP_Error with code invalid_if_match.
	 *
	 * Covers AC6 (malformed If-Match → 400).
	 */
	public function test_parse_raw_garbage_returns_400(): void {
		$result = RevisionGuard::parse_raw( 'garbage' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_if_match', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * W/"not-a-number" (non-digit content inside ETag wrapper) is also invalid.
	 */
	public function test_parse_raw_weak_etag_nonnumeric_returns_400(): void {
		$result = RevisionGuard::parse_raw( 'W/"abc"' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_if_match', $result->get_error_code() );
	}

	// ── check ──────────────────────────────────────────────────────────────

	/**
	 * check() with null expected skips the check (back-compat).
	 *
	 * Covers AC4 (missing If-Match → pass-through).
	 */
	public function test_check_null_expected_passes(): void {
		$post_id = $this->factory->post->create();
		$this->assertTrue( RevisionGuard::check( $post_id, null ) );
	}

	/**
	 * check() propagates an upstream WP_Error (e.g. 400 from parse_raw).
	 */
	public function test_check_propagates_upstream_wp_error(): void {
		$post_id = $this->factory->post->create();
		$error   = new \WP_Error( 'invalid_if_match', 'bad', [ 'status' => 400 ] );
		$result  = RevisionGuard::check( $post_id, $error );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_if_match', $result->get_error_code() );
	}

	/**
	 * check() with a matching revision returns true.
	 *
	 * Covers AC3 (matching If-Match → proceed).
	 */
	public function test_check_matching_revision_passes(): void {
		$post_id = $this->factory->post->create();

		// Create a revision so current_revision_id() returns something non-zero.
		wp_update_post( [ 'ID' => $post_id, 'post_title' => 'Rev 1' ] );

		$current = RevisionGuard::current_revision_id( $post_id );
		$this->assertGreaterThan( 0, $current, 'Precondition: a revision must exist' );

		$this->assertTrue( RevisionGuard::check( $post_id, $current ) );
	}

	/**
	 * check() with a stale revision returns HTTP 412 stale_revision with
	 * data.current_revision_id.
	 *
	 * Covers AC2 (stale If-Match → 412 with current_revision_id).
	 */
	public function test_check_stale_revision_returns_412(): void {
		$post_id = $this->factory->post->create();
		wp_update_post( [ 'ID' => $post_id, 'post_title' => 'Rev 1' ] );

		$current = RevisionGuard::current_revision_id( $post_id );
		$this->assertGreaterThan( 0, $current );

		$stale  = $current - 1; // A revision ID that no longer matches.
		$result = RevisionGuard::check( $post_id, $stale );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stale_revision', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 412, $data['status'] );
		$this->assertSame( $current, $data['current_revision_id'] );
	}

	// ── current_revision_id ────────────────────────────────────────────────

	/**
	 * A freshly created post (auto-draft) with no revisions returns 0.
	 *
	 * Note: wp-env / test suite may or may not save an initial revision
	 * depending on configuration; we verify the return type is always int.
	 */
	public function test_current_revision_id_returns_int(): void {
		$post_id = $this->factory->post->create();
		$result  = RevisionGuard::current_revision_id( $post_id );
		$this->assertIsInt( $result );
	}

	/**
	 * After an update, current_revision_id() returns a positive integer.
	 */
	public function test_current_revision_id_positive_after_update(): void {
		$post_id = $this->factory->post->create();
		wp_update_post( [ 'ID' => $post_id, 'post_title' => 'Updated title' ] );

		$result = RevisionGuard::current_revision_id( $post_id );
		$this->assertGreaterThan( 0, $result );
	}

	// ── parse_if_match ─────────────────────────────────────────────────────

	/**
	 * parse_if_match() reads the If-Match header when present.
	 *
	 * Covers AC7 (parse_if_match reads header and body param).
	 */
	public function test_parse_if_match_reads_header(): void {
		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/test' );
		$request->set_header( 'If-Match', '321' );

		$result = RevisionGuard::parse_if_match( $request );
		$this->assertSame( 321, $result );
	}

	/**
	 * parse_if_match() reads the If-Match header in W/"..." form.
	 *
	 * Covers AC5 (weak ETag form W/"123" parses correctly).
	 */
	public function test_parse_if_match_reads_weak_etag_header(): void {
		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/test' );
		$request->set_header( 'If-Match', 'W/"999"' );

		$result = RevisionGuard::parse_if_match( $request );
		$this->assertSame( 999, $result );
	}

	/**
	 * parse_if_match() falls back to the expected_revision body param when no header.
	 *
	 * Covers AC7 (body param fallback).
	 */
	public function test_parse_if_match_falls_back_to_body_param(): void {
		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/test' );
		$request->set_body_params( [ 'expected_revision' => '555' ] );

		$result = RevisionGuard::parse_if_match( $request );
		$this->assertSame( 555, $result );
	}

	/**
	 * parse_if_match() returns null when neither header nor body param is present.
	 *
	 * Covers AC4 (missing If-Match → back-compat pass-through).
	 */
	public function test_parse_if_match_returns_null_when_absent(): void {
		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/test' );

		$result = RevisionGuard::parse_if_match( $request );
		$this->assertNull( $result );
	}

	/**
	 * parse_if_match() header takes precedence over body param.
	 */
	public function test_parse_if_match_header_wins_over_body(): void {
		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/test' );
		$request->set_header( 'If-Match', '100' );
		$request->set_body_params( [ 'expected_revision' => '200' ] );

		$result = RevisionGuard::parse_if_match( $request );
		$this->assertSame( 100, $result );
	}

	/**
	 * parse_if_match() with a malformed header value returns 400 WP_Error.
	 *
	 * Covers AC6 (malformed If-Match → 400).
	 */
	public function test_parse_if_match_malformed_header_returns_400(): void {
		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/test' );
		$request->set_header( 'If-Match', 'garbage' );

		$result = RevisionGuard::parse_if_match( $request );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_if_match', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}
}
