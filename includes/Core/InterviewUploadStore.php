<?php

declare(strict_types=1);
/**
 * Interview Upload Store — persistence layer for Theme Builder photo uploads.
 *
 * Owns the three meta keys used to tag attachments that came in through the
 * Theme Builder interview:
 *
 *   _sd_ai_agent_interview_upload          (1)
 *   _sd_ai_agent_interview_upload_category (space|product|team|event|other)
 *   _sd_ai_agent_interview_upload_session  (int session id)
 *
 * Used by:
 *  - {@see \SdAiAgent\Core\OnboardingManager::rest_interview_upload()} to tag
 *    new uploads after `media_handle_upload()` returns an attachment ID.
 *  - {@see \SdAiAgent\Abilities\ListInterviewUploadsAbility} to surface those
 *    photos back to the agent when it plans section imagery.
 *
 * Filename categorisation is delegated to
 * {@see \SdAiAgent\Services\InterviewUploadCategoriser}, which is a pure
 * function on the filename so it can be unit-tested without WordPress.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use SdAiAgent\Services\InterviewUploadCategoriser;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static persistence helper for Theme Builder interview photo uploads.
 *
 * @since 1.16.0
 */
final class InterviewUploadStore {

	/** Meta key flag (value: 1) marking an attachment as an interview upload. */
	public const META_FLAG = '_sd_ai_agent_interview_upload';

	/** Meta key for the heuristic category guess. */
	public const META_CATEGORY = '_sd_ai_agent_interview_upload_category';

	/** Meta key for the originating session id. */
	public const META_SESSION = '_sd_ai_agent_interview_upload_session';

	/** Maximum number of uploads returned by list_uploads(). */
	public const MAX_LIMIT = 200;

	/** Default number of uploads returned when no limit is provided. */
	public const DEFAULT_LIMIT = 50;

	/**
	 * Tag a freshly-uploaded attachment as an interview upload.
	 *
	 * Resolves the category from the supplied override (when the caller has
	 * a stronger signal than the filename) or falls back to the
	 * filename-based heuristic. Stores the session id when provided so the
	 * agent can scope queries per Theme Builder session.
	 *
	 * @param int                                                             $attachment_id     The newly-created attachment ID.
	 * @param array{filename?:string, category?:string, session_id?:int|null} $args              Tagging context. `filename` defaults to the attachment's source filename. `category` overrides the heuristic when valid. `session_id` is stored when > 0.
	 * @return string The resolved category that was persisted.
	 */
	public static function tag_attachment( int $attachment_id, array $args = [] ): string {
		$filename = isset( $args['filename'] ) && is_string( $args['filename'] )
			? $args['filename']
			: (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

		$category = isset( $args['category'] ) && is_string( $args['category'] )
			&& InterviewUploadCategoriser::is_valid_category( $args['category'] )
			? $args['category']
			: InterviewUploadCategoriser::categorise( $filename );

		update_post_meta( $attachment_id, self::META_FLAG, 1 );
		update_post_meta( $attachment_id, self::META_CATEGORY, $category );

		if ( isset( $args['session_id'] ) && (int) $args['session_id'] > 0 ) {
			update_post_meta( $attachment_id, self::META_SESSION, (int) $args['session_id'] );
		}

		return $category;
	}

	/**
	 * List interview uploads, optionally filtered by category and session id.
	 *
	 * Returns a shape compatible with both the REST upload-confirmation
	 * response and the `sd-ai-agent/list-interview-uploads` ability response.
	 *
	 * @param array{category?:string, session_id?:int, limit?:int} $args Optional filters.
	 * @return array{items:list<array<string,mixed>>, total:int, by_category:array<string,int>}
	 */
	public static function list_uploads( array $args = [] ): array {
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : self::DEFAULT_LIMIT;
		if ( $limit < 1 ) {
			$limit = self::DEFAULT_LIMIT;
		}
		if ( $limit > self::MAX_LIMIT ) {
			$limit = self::MAX_LIMIT;
		}

		$meta_query = [
			[
				'key'     => self::META_FLAG,
				'compare' => 'EXISTS',
			],
		];

		if ( isset( $args['category'] )
			&& is_string( $args['category'] )
			&& InterviewUploadCategoriser::is_valid_category( $args['category'] )
		) {
			$meta_query[] = [
				'key'   => self::META_CATEGORY,
				'value' => $args['category'],
			];
		}

		if ( isset( $args['session_id'] ) && (int) $args['session_id'] > 0 ) {
			$meta_query[] = [
				'key'   => self::META_SESSION,
				'value' => (int) $args['session_id'],
			];
		}

		$query_args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- intentional, this query lists tagged interview uploads only.
		];

		$attachments = get_posts( $query_args );

		$items       = [];
		$by_category = [];

		foreach ( $attachments as $attachment ) {
			if ( ! ( $attachment instanceof WP_Post ) ) {
				continue;
			}

			$item = self::format_attachment( $attachment );
			if ( null === $item ) {
				continue;
			}

			$items[] = $item;

			$cat                 = (string) $item['category'];
			$by_category[ $cat ] = ( $by_category[ $cat ] ?? 0 ) + 1;
		}

		return [
			'items'       => $items,
			'total'       => count( $items ),
			'by_category' => $by_category,
		];
	}

	/**
	 * Format a single attachment as a list-uploads item.
	 *
	 * @param WP_Post $attachment Attachment post.
	 * @return array<string,mixed>|null Null when the attachment is not an interview upload.
	 */
	public static function format_attachment( WP_Post $attachment ): ?array {
		if ( 'attachment' !== $attachment->post_type ) {
			return null;
		}

		$flag = get_post_meta( $attachment->ID, self::META_FLAG, true );
		if ( ! $flag ) {
			return null;
		}

		$category = (string) get_post_meta( $attachment->ID, self::META_CATEGORY, true );
		if ( ! InterviewUploadCategoriser::is_valid_category( $category ) ) {
			$category = InterviewUploadCategoriser::CATEGORY_OTHER;
		}

		$url       = wp_get_attachment_url( $attachment->ID );
		$thumb_src = wp_get_attachment_image_src( $attachment->ID, 'thumbnail' );
		$thumbnail = is_array( $thumb_src ) && ! empty( $thumb_src[0] )
			? (string) $thumb_src[0]
			: ( $url ?: '' );

		$metadata = wp_get_attachment_metadata( $attachment->ID );
		$width    = is_array( $metadata ) && isset( $metadata['width'] ) ? (int) $metadata['width'] : 0;
		$height   = is_array( $metadata ) && isset( $metadata['height'] ) ? (int) $metadata['height'] : 0;

		$filename = (string) get_post_meta( $attachment->ID, '_wp_attached_file', true );
		if ( '' !== $filename ) {
			$filename = basename( $filename );
		}

		return [
			'attachment_id' => $attachment->ID,
			'url'           => $url ?: '',
			'thumbnail'     => $thumbnail,
			'title'         => $attachment->post_title,
			'filename'      => $filename,
			'category'      => $category,
			'mime_type'     => $attachment->post_mime_type,
			'width'         => $width,
			'height'        => $height,
		];
	}
}
