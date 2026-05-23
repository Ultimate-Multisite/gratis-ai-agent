<?php

declare(strict_types=1);
/**
 * Optimistic-concurrency guard for block-write endpoints.
 *
 * Provides a thin, stateless utility for wiring optimistic-concurrency
 * control into REST and Ability write paths. Callers supply an
 * `If-Match` header or an `expected_revision` body field; the guard
 * verifies the post's current revision still matches before the write
 * proceeds.  Missing header → skip check → backward compatible.
 *
 * Usage (REST route handler):
 *
 *   $guard = RevisionGuard::check( $post_id, RevisionGuard::parse_if_match( $request ) );
 *   if ( is_wp_error( $guard ) ) {
 *       return $guard; // 412 or 400
 *   }
 *   // … proceed with write …
 *   return [ ..., 'revision_id' => RevisionGuard::current_revision_id( $post_id ) ];
 *
 * Usage (Ability handler — no WP_REST_Request available):
 *
 *   $expected = isset( $input['expected_revision'] ) ? (string) $input['expected_revision'] : '';
 *   $guard    = RevisionGuard::check( $post_id, RevisionGuard::parse_raw( $expected ) );
 *   if ( is_wp_error( $guard ) ) {
 *       return $guard;
 *   }
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1710
 */

namespace SdAiAgent\Core;

use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless optimistic-concurrency helper.
 *
 * All methods are static so callers do not need a DI container instance.
 */
class RevisionGuard {

	/**
	 * Return the latest revision post-ID for a post.
	 *
	 * Uses `wp_get_post_revisions()` ordered DESC, limit 1.
	 * Returns 0 when the post has no revisions yet (e.g. auto-draft).
	 *
	 * @param int $post_id Post being inspected.
	 * @return int Revision post ID, or 0 when no revisions exist.
	 */
	public static function current_revision_id( int $post_id ): int {
		$revisions = wp_get_post_revisions(
			$post_id,
			[
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		// wp_get_post_revisions() returns an array keyed by revision post IDs
		// when no 'fields' override is used. key() retrieves the first array
		// key without the WP_Post cast ambiguity that reset() + (int) triggers.
		if ( is_array( $revisions ) && ! empty( $revisions ) ) {
			return (int) key( $revisions );
		}

		return 0;
	}

	/**
	 * Extract and parse an expected-revision value from a REST request.
	 *
	 * Precedence: `If-Match` header → `expected_revision` body/query param.
	 * Delegates to {@see self::parse_raw()} for validation.
	 *
	 * Returns null  when neither source is present (no-op / back-compat).
	 * Returns int   when the value is valid.
	 * Returns WP_Error (400 `invalid_if_match`) when a value is present but malformed.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return int|null|WP_Error Parsed revision ID, null (absent), or 400 error.
	 */
	public static function parse_if_match( WP_REST_Request $request ): int|null|WP_Error {
		$raw = '';

		// If-Match header takes precedence.
		$header = $request->get_header( 'if_match' );
		if ( is_string( $header ) && '' !== $header ) {
			$raw = $header;
		}

		// Body / query param fallback for transports that cannot set headers.
		if ( '' === $raw ) {
			$body_field = $request->get_param( 'expected_revision' );
			if ( null !== $body_field && '' !== (string) $body_field ) {
				$raw = (string) $body_field;
			}
		}

		if ( '' === $raw ) {
			return null; // No If-Match — back-compat pass-through.
		}

		return self::parse_raw( $raw );
	}

	/**
	 * Parse a raw If-Match string (no WP_REST_Request context).
	 *
	 * Accepts: bare integer `"123"`, weak ETag `W/"123"`, or strong ETag `"123"`.
	 * Returns null for empty string, int for valid form, WP_Error 400 for garbage.
	 *
	 * @param string $raw Raw header / field value.
	 * @return int|null|WP_Error
	 */
	public static function parse_raw( string $raw ): int|null|WP_Error {
		if ( '' === $raw ) {
			return null;
		}

		// Strip RFC 7232 ETag wrappers: W/"123" → 123, "123" → 123.
		$normalized = trim( $raw );
		if ( str_starts_with( $normalized, 'W/' ) ) {
			$normalized = trim( substr( $normalized, 2 ) );
		}
		$normalized = trim( $normalized, '"' );

		if ( ! preg_match( '/^[0-9]+$/', $normalized ) ) {
			return new WP_Error(
				'invalid_if_match',
				__( 'If-Match must be a revision ID (integer), optionally wrapped as W/"<id>" or "<id>".', 'superdav-ai-agent' ),
				[ 'status' => 400 ]
			);
		}

		return (int) $normalized;
	}

	/**
	 * Precondition check for a write operation.
	 *
	 * When `$expected` is null, the check is skipped (opt-in, back-compat).
	 * When `$expected` matches the post's current revision, returns true.
	 * When `$expected` does not match, returns a 412 WP_Error with
	 * `data.current_revision_id` so the caller can re-fetch and retry.
	 *
	 * @param int      $post_id  Post being written.
	 * @param int|null $expected Parsed revision ID, or null to skip.
	 * @return true|WP_Error true = proceed; WP_Error 412 = stale; WP_Error 400 = invalid.
	 */
	public static function check( int $post_id, int|null|WP_Error $expected ): true|WP_Error {
		// Surface upstream parse errors (400 invalid_if_match) immediately.
		if ( is_wp_error( $expected ) ) {
			return $expected;
		}

		// Null → no If-Match supplied → back-compat pass-through.
		if ( null === $expected ) {
			return true;
		}

		$current = self::current_revision_id( $post_id );

		if ( $expected !== $current ) {
			return new WP_Error(
				'stale_revision',
				__( 'The post has changed since you fetched it. Re-fetch with get-page-blocks and retry.', 'superdav-ai-agent' ),
				[
					'status'              => 412,
					'current_revision_id' => $current,
					'expected_revision'   => $expected,
				]
			);
		}

		return true;
	}
}
