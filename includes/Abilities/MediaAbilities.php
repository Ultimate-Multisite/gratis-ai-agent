<?php

declare(strict_types=1);
/**
 * Media library abilities for the AI agent.
 *
 * Provides media listing, sideloading from URL, and deletion.
 * Ported from the WordPress/ai experiments plugin pattern.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Core\ChangeLogger;
use SdAiAgent\Core\Net\SafeHttpClient;
use SdAiAgent\Models\ChangesLog;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MediaAbilities {

	/**
	 * Register all media library abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/list-media',
			[
				'label'               => __( 'List Media', 'superdav-ai-agent' ),
				'description'         => __( 'List items in the WordPress media library. Filter by MIME type, search term, or date. Returns attachment ID, URL, title, alt text, MIME type, and file size.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'mime_type' => [
							'type'        => 'string',
							'description' => 'Filter by MIME type prefix (e.g. "image", "image/jpeg", "video", "application/pdf").',
						],
						'search'    => [
							'type'        => 'string',
							'description' => 'Search term matched against title, caption, or description.',
						],
						'limit'     => [
							'type'        => 'integer',
							'description' => 'Maximum number of items to return (default: 20, max: 100).',
						],
						'site_url'  => [
							'type'        => 'string',
							'description' => 'Subsite URL for multisite. Omit for the main site.',
						],
					],
					'required'   => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'items' => [ 'type' => 'array' ],
						'total' => [ 'type' => 'integer' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'   => true,
						'idempotent' => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_list_media' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'upload_files' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/upload-media-from-url',
			[
				'label'               => __( 'Upload Media from URL', 'superdav-ai-agent' ),
				'description'         => __( 'Download a file from a URL and add it to the WordPress media library. Returns the new attachment ID and URL. Supports images, PDFs, and other media types.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'url'         => [
							'type'        => 'string',
							'description' => 'The URL of the file to download and import.',
						],
						'title'       => [
							'type'        => 'string',
							'description' => 'Optional title for the attachment. Defaults to the filename.',
						],
						'alt_text'    => [
							'type'        => 'string',
							'description' => 'Alt text for image attachments.',
						],
						'caption'     => [
							'type'        => 'string',
							'description' => 'Optional caption for the attachment.',
						],
						'description' => [
							'type'        => 'string',
							'description' => 'Optional description for the attachment.',
						],
						'post_id'     => [
							'type'        => 'integer',
							'description' => 'Optional post ID to attach the media to.',
						],
						'site_url'    => [
							'type'        => 'string',
							'description' => 'Subsite URL for multisite. Omit for the main site.',
						],
					],
					'required'   => [ 'url' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'attachment_id' => [ 'type' => 'integer' ],
						'url'           => [ 'type' => 'string' ],
						'title'         => [ 'type' => 'string' ],
						'mime_type'     => [ 'type' => 'string' ],
						'file_size'     => [ 'type' => 'integer' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_upload_media_from_url' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'upload_files' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/delete-media',
			[
				'label'               => __( 'Delete Media', 'superdav-ai-agent' ),
				'description'         => __( 'Permanently delete a media attachment from the WordPress media library, including all generated image sizes and metadata.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'attachment_id' => [
							'type'        => 'integer',
							'description' => 'The ID of the attachment to delete.',
						],
						'site_url'      => [
							'type'        => 'string',
							'description' => 'Subsite URL for multisite. Omit for the main site.',
						],
					],
					'required'   => [ 'attachment_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'attachment_id' => [ 'type' => 'integer' ],
						'title'         => [ 'type' => 'string' ],
						'deleted'       => [ 'type' => 'boolean' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_delete_media' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'delete_posts' );
				},
			]
		);
	}

	/**
	 * Handle the list-media ability.
	 *
	 * @param array<string, mixed> $input Input with optional mime_type, search, limit, site_url.
	 * @return array<string, mixed>
	 */
	public static function handle_list_media( array $input ): array {
		// @phpstan-ignore-next-line
		$mime_type = sanitize_text_field( $input['mime_type'] ?? '' );
		// @phpstan-ignore-next-line
		$search = sanitize_text_field( $input['search'] ?? '' );
		// @phpstan-ignore-next-line
		$limit    = min( 100, max( 1, (int) ( $input['limit'] ?? 20 ) ) );
		$site_url = $input['site_url'] ?? '';

		$switched = false;

		if ( ! empty( $site_url ) && is_multisite() ) {
			$blog_id = get_blog_id_from_url(
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_HOST ) ?? '' ),
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_PATH ) ?: '/' )
			);

			if ( $blog_id && $blog_id !== get_current_blog_id() ) {
				switch_to_blog( $blog_id );
				$switched = true;
			}
		}

		$args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		if ( ! empty( $mime_type ) ) {
			$args['post_mime_type'] = $mime_type;
		}

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$attachments = get_posts( $args );

		$items = [];
		foreach ( $attachments as $attachment ) {
			if ( ! ( $attachment instanceof WP_Post ) ) {
				continue;
			}

			$url       = wp_get_attachment_url( $attachment->ID );
			$metadata  = wp_get_attachment_metadata( $attachment->ID );
			$file_size = 0;

			if ( is_array( $metadata ) && isset( $metadata['filesize'] ) ) {
				$file_size = (int) $metadata['filesize'];
			} else {
				$file_path = get_attached_file( $attachment->ID );
				if ( $file_path && file_exists( $file_path ) ) {
					$file_size = (int) filesize( $file_path );
				}
			}

			$items[] = [
				'id'        => $attachment->ID,
				'url'       => $url,
				'title'     => $attachment->post_title,
				'alt_text'  => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
				'caption'   => $attachment->post_excerpt,
				'mime_type' => $attachment->post_mime_type,
				'date'      => $attachment->post_date,
				'file_size' => $file_size,
			];
		}

		if ( $switched ) {
			restore_current_blog();
		}

		return [
			'items' => $items,
			'total' => count( $items ),
		];
	}

	/**
	 * Handle the upload-media-from-url ability.
	 *
	 * @deprecated 1.10.0 Use sd-ai-agent/upload-media with source "url" instead.
	 *
	 * @param array<string, mixed> $input Input with url, optional title, alt_text, caption, description, post_id, site_url.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_upload_media_from_url( array $input ) {
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- _doing_it_wrong() handles its own escaping internally.
		_doing_it_wrong(
			'sd-ai-agent/upload-media-from-url',
			__( 'Use sd-ai-agent/upload-media with source "url" instead of sd-ai-agent/upload-media-from-url.', 'superdav-ai-agent' ),
			'1.10.0'
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		$site_url = $input['site_url'] ?? '';
		$switched = false;

		if ( ! empty( $site_url ) && is_multisite() ) {
			$blog_id = get_blog_id_from_url(
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_HOST ) ?? '' ),
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_PATH ) ?: '/' )
			);

			if ( $blog_id && $blog_id !== get_current_blog_id() ) {
				switch_to_blog( $blog_id );
				$switched = true;
			}
		}

		$result = self::sideload_from_url( $input );

		if ( $switched ) {
			restore_current_blog();
		}

		return $result;
	}

	/**
	 * Core URL-to-media-library sideload logic (no multisite switching).
	 *
	 * Downloads the remote file via the SSRF-safe HTTP client and sideloads
	 * it into the WordPress media library.  Callers that need multisite
	 * context switching must do so themselves around this call.
	 *
	 * @since 1.10.0
	 *
	 * @param array<string, mixed> $input Input with url, optional title, alt_text, caption, description, post_id.
	 * @return array{attachment_id: int, url: string, title: string, mime_type: string, file_size: int}|WP_Error
	 */
	public static function sideload_from_url( array $input ) {
		// @phpstan-ignore-next-line
		$url = esc_url_raw( $input['url'] ?? '' );
		// @phpstan-ignore-next-line
		$title = sanitize_text_field( $input['title'] ?? '' );
		// @phpstan-ignore-next-line
		$alt_text = sanitize_text_field( $input['alt_text'] ?? '' );
		// @phpstan-ignore-next-line
		$caption = sanitize_textarea_field( $input['caption'] ?? '' );
		// @phpstan-ignore-next-line
		$description = sanitize_textarea_field( $input['description'] ?? '' );
		// @phpstan-ignore-next-line
		$post_id = (int) ( $input['post_id'] ?? 0 );

		if ( empty( $url ) ) {
			return new WP_Error( 'ai_agent_empty_url', __( 'URL is required.', 'superdav-ai-agent' ) );
		}

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$tmp_file = SafeHttpClient::instance()->safe_download_url( $url, 30 );

		if ( is_wp_error( $tmp_file ) ) {
			return new WP_Error(
				'ai_agent_download_failed',
				/* translators: %s: error message */
				sprintf( __( 'Failed to download file: %s', 'superdav-ai-agent' ), $tmp_file->get_error_message() )
			);
		}

		// Derive filename from URL if no title given.
		$url_path = wp_parse_url( $url, PHP_URL_PATH );
		$filename = $url_path ? basename( $url_path ) : 'upload';
		if ( empty( $title ) ) {
			$title = pathinfo( $filename, PATHINFO_FILENAME );
			$title = str_replace( [ '-', '_' ], ' ', $title );
			$title = ucwords( $title );
		}

		$file_array = [
			'name'     => $filename,
			'tmp_name' => $tmp_file,
		];

		$attachment_id = media_handle_sideload( $file_array, $post_id, $title );

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp_file ) ) {
				unlink( $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			return new WP_Error(
				'ai_agent_sideload_failed',
				/* translators: %s: error message */
				sprintf( __( 'Failed to import media: %s', 'superdav-ai-agent' ), $attachment_id->get_error_message() )
			);
		}

		// Update attachment metadata.
		wp_update_post(
			[
				'ID'           => $attachment_id,
				'post_title'   => $title,
				'post_excerpt' => $caption,
				'post_content' => $description,
			]
			);

		if ( ! empty( $alt_text ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		}

		$attachment_url = wp_get_attachment_url( $attachment_id ) ?: '';
		$attachment     = get_post( $attachment_id );
		$mime_type      = $attachment instanceof WP_Post ? $attachment->post_mime_type : '';

		$file_path = get_attached_file( $attachment_id );
		$file_size = ( $file_path && file_exists( $file_path ) ) ? (int) filesize( $file_path ) : 0;

		return [
			'attachment_id' => $attachment_id,
			'url'           => $attachment_url,
			'title'         => $title,
			'mime_type'     => $mime_type,
			'file_size'     => $file_size,
		];
	}

	/**
	 * Handle the delete-media ability.
	 *
	 * @param array<string, mixed> $input Input with attachment_id and optional site_url.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_delete_media( array $input ) {
		// @phpstan-ignore-next-line
		$attachment_id = (int) ( $input['attachment_id'] ?? 0 );
		$site_url      = $input['site_url'] ?? '';

		if ( ! $attachment_id ) {
			return new WP_Error( 'ai_agent_empty_attachment_id', __( 'attachment_id is required.', 'superdav-ai-agent' ) );
		}

		$switched = false;

		if ( ! empty( $site_url ) && is_multisite() ) {
			$blog_id = get_blog_id_from_url(
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_HOST ) ?? '' ),
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_PATH ) ?: '/' )
			);

			if ( $blog_id && $blog_id !== get_current_blog_id() ) {
				switch_to_blog( $blog_id );
				$switched = true;
			}
		}

		$attachment = get_post( $attachment_id );

		if ( ! ( $attachment instanceof WP_Post ) || $attachment->post_type !== 'attachment' ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return new WP_Error(
				'ai_agent_attachment_not_found',
				/* translators: %d: attachment ID */
				sprintf( __( 'Attachment %d not found.', 'superdav-ai-agent' ), $attachment_id )
			);
		}

		$title  = $attachment->post_title;
		$result = wp_delete_attachment( $attachment_id, true );

		if ( $switched ) {
			restore_current_blog();
		}

		// Audit trail: log media deletion as unrevertable — the file and DB row are gone.
		if ( $result && ChangeLogger::is_active() ) {
			ChangesLog::record(
				[
					'session_id'   => ChangeLogger::get_session_id(),
					'object_type'  => 'media',
					'object_id'    => $attachment_id,
					'object_title' => $title,
					'ability_name' => ChangeLogger::get_ability_name() ?: 'delete_media',
					'field_name'   => 'attachment',
					'before_value' => $title,
					'after_value'  => '(deleted)',
					'revertable'   => false,
				]
			);
		}

		return [
			'attachment_id' => $attachment_id,
			'title'         => $title,
			'deleted'       => (bool) $result,
		];
	}
}
