<?php

declare(strict_types=1);
/**
 * HTTP If-Match / ETag header parser and formatter for optimistic concurrency.
 *
 * Parses `If-Match: W/"rev-N"` request headers and formats
 * `ETag: W/"rev-N"` response headers for block-write REST routes.
 *
 * Accepted header formats (lenient parse, following RFC 7232 §3.1 guidance):
 *   - Wildcard:            `*`           → WILDCARD (skip revision check)
 *   - Weak with prefix:    `W/"rev-N"`   → N
 *   - Strong with prefix:  `"rev-N"`     → N
 *   - Weak bare integer:   `W/"N"`       → N  (backward-compatible form)
 *   - Strong bare integer: `"N"`         → N  (backward-compatible form)
 *   - Anything else:                     → WP_Error 412 invalid_if_match
 *
 * The ETag format emitted by this plugin is always `W/"rev-N"` (weak
 * validator, because we compare by revision ID, not byte content).
 *
 * Usage (REST route handler read path):
 *
 *   $revision_id = RevisionGuard::current_revision_id( $post_id );
 *   $response->header( 'ETag', IfMatchHeader::format( $revision_id ) );
 *
 * Usage (REST route handler write path):
 *
 *   $rev = IfMatchHeader::parse( $request->get_header( 'if_match' ) ?? '' );
 *   if ( is_wp_error( $rev ) ) {
 *       return $rev; // 412 invalid_if_match
 *   }
 *   if ( $rev !== null && $rev !== IfMatchHeader::WILDCARD ) {
 *       $check = RevisionGuard::check( $post_id, $rev );
 *       if ( is_wp_error( $check ) ) {
 *           return $check; // 412 stale_revision
 *       }
 *   }
 *   // ... perform the write ...
 *   $new_revision_id = RevisionGuard::current_revision_id( $post_id );
 *   $response->header( 'ETag', IfMatchHeader::format( $new_revision_id ) );
 *
 * NOTE: Future REST routes that mutate posts should adopt the same middleware
 * pair. The `update-post` route is explicitly out of scope for t269 but should
 * use this class when implemented.
 *
 * @package SdAiAgent\REST
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1785
 */

namespace SdAiAgent\REST;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTTP If-Match / ETag parser and formatter.
 *
 * All methods are static (idempotent, no WP-API side effects).
 */
class IfMatchHeader {

	/**
	 * Sentinel return value for the RFC 7232 `*` wildcard.
	 *
	 * When {@see self::parse()} returns this value the caller MUST skip the
	 * revision precondition check entirely — any current revision is acceptable.
	 *
	 * The value -1 is used because it can never be a valid WordPress revision
	 * post-ID (which are always positive integers).
	 */
	const WILDCARD = -1;

	/**
	 * Parse an `If-Match` header value into a revision ID.
	 *
	 * Case-insensitive `W/` prefix handling. Lenient on both weak and strong
	 * ETag forms. Accepts the `rev-N` prefix introduced by {@see self::format()}
	 * and the bare-integer form for backward compatibility.
	 *
	 * @param string $header Raw header value. Pass an empty string when the
	 *                       header is absent (WP_REST_Request::get_header()
	 *                       returns null; cast to '' before calling).
	 * @return int|null|WP_Error
	 *   - null            : header absent / empty — no precondition, proceed.
	 *   - WILDCARD (= -1) : `*` — any existing revision, skip check.
	 *   - int ≥ 0         : specific revision ID to compare against current.
	 *   - WP_Error 412    : unrecognised format → `invalid_if_match`.
	 */
	public static function parse( string $header ): int|null|WP_Error {
		$header = trim( $header );

		if ( '' === $header ) {
			return null;
		}

		if ( '*' === $header ) {
			return self::WILDCARD;
		}

		// Strip optional weak-validator prefix W/ (case-insensitive per RFC 7232).
		$normalized = $header;
		if ( 0 === stripos( $normalized, 'W/' ) ) {
			$normalized = ltrim( substr( $normalized, 2 ) );
		}

		// Strip surrounding double-quotes (RFC 7232 entity-tag format).
		$normalized = trim( $normalized, '"' );

		// Strip the `rev-` prefix emitted by IfMatchHeader::format().
		// Clients that echo back the ETag verbatim will include this prefix.
		if ( str_starts_with( $normalized, 'rev-' ) ) {
			$normalized = substr( $normalized, 4 );
		}

		// The remaining string must be a non-negative integer (WordPress post ID).
		if ( ! preg_match( '/^[0-9]+$/', $normalized ) ) {
			return new WP_Error(
				'invalid_if_match',
				__( 'If-Match value is not a valid revision ETag. Expected W/"rev-N", "rev-N", or *.', 'superdav-ai-agent' ),
				[ 'status' => 412 ]
			);
		}

		return (int) $normalized;
	}

	/**
	 * Format a revision ID as a weak ETag header value.
	 *
	 * Always uses the `W/"rev-N"` form. Null revision (post with no revisions
	 * yet) is formatted as `W/"rev-0"` so clients still get a stable value
	 * they can echo back as `If-Match: W/"rev-0"`.
	 *
	 * @param int|null $revision_id Revision post ID from
	 *                              {@see RevisionGuard::current_revision_id()}.
	 *                              Pass null when the post has no revisions yet.
	 * @return string ETag header value, e.g. `W/"rev-218"`.
	 */
	public static function format( ?int $revision_id ): string {
		return sprintf( 'W/"rev-%d"', $revision_id ?? 0 );
	}
}
