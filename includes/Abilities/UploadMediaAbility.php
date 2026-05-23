<?php

declare(strict_types=1);
/**
 * Unified upload-media ability (Wave 2.9 — t263).
 *
 * Provides a single sd-ai-agent/upload-media ability with a `source`
 * discriminator that supersedes the ambiguous choice between
 * `upload-media-from-url` and `import-base64-image`. Adds a new
 * `source: "path"` branch guarded by AbsPathGuard.
 *
 * Legacy abilities remain registered and functional; each emits a
 * _doing_it_wrong() notice directing callers to this unified surface.
 *
 * @package SdAiAgent
 * @since   1.10.0
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Core\Net\AbsPathGuard;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unified media upload ability with source discriminator.
 *
 * @since 1.10.0
 */
class UploadMediaAbility {

	/**
	 * Register the sd-ai-agent/upload-media ability.
	 *
	 * @since 1.10.0
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/upload-media',
			[
				'label'               => __( 'Upload Media', 'superdav-ai-agent' ),
				'description'         => __( 'Upload a file to the WordPress media library from a remote URL, a base64-encoded string, or a local server path. Use the `source` field to select the input type. Supersedes upload-media-from-url and import-base64-image.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'source'      => [
							'type'        => 'string',
							'enum'        => [ 'url', 'base64', 'path' ],
							'description' => __( 'Input type discriminator: "url" downloads from a remote URL (SSRF-guarded), "base64" decodes inline data, "path" imports an existing server-side file (must be inside ABSPATH).', 'superdav-ai-agent' ),
						],
						'url'         => [
							'type'        => 'string',
							'description' => __( 'Remote URL to download (required when source is "url").', 'superdav-ai-agent' ),
						],
						'data_base64' => [
							'type'        => 'string',
							'description' => __( 'Base64-encoded image data, with optional data-URI prefix (required when source is "base64").', 'superdav-ai-agent' ),
						],
						'path'        => [
							'type'        => 'string',
							'description' => __( 'Absolute server-side filesystem path (required when source is "path"). Must resolve inside ABSPATH; path traversal attempts are blocked.', 'superdav-ai-agent' ),
						],
						'mime_type'   => [
							'type'        => 'string',
							'description' => __( 'Declared MIME type (required for "base64"; optional for "url" and "path" — auto-detected when omitted). A mismatch between declared and actual MIME type returns an error.', 'superdav-ai-agent' ),
						],
						'filename'    => [
							'type'        => 'string',
							'description' => __( 'Filename to use in the media library (without extension). Sniffed from the URL or generated when omitted.', 'superdav-ai-agent' ),
						],
						'post_id'     => [
							'type'        => 'integer',
							'description' => __( 'Optional post ID to set as the attachment post_parent.', 'superdav-ai-agent' ),
						],
						'title'       => [
							'type'        => 'string',
							'description' => __( 'Title for the attachment. Derived from the filename when omitted.', 'superdav-ai-agent' ),
						],
						'alt_text'    => [
							'type'        => 'string',
							'description' => __( 'Alt text for image attachments.', 'superdav-ai-agent' ),
						],
						'caption'     => [
							'type'        => 'string',
							'description' => __( 'Caption for the attachment.', 'superdav-ai-agent' ),
						],
						'description' => [
							'type'        => 'string',
							'description' => __( 'Description for the attachment.', 'superdav-ai-agent' ),
						],
					],
					'required'   => [ 'source' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'attachment_id'  => [
							'type'        => 'integer',
							'description' => __( 'WordPress attachment post ID.', 'superdav-ai-agent' ),
						],
						'url'            => [
							'type'        => 'string',
							'description' => __( 'Public URL of the uploaded attachment.', 'superdav-ai-agent' ),
						],
						'mime_type'      => [
							'type'        => 'string',
							'description' => __( 'MIME type of the uploaded file.', 'superdav-ai-agent' ),
						],
						'filesize_bytes' => [
							'type'        => 'integer',
							'description' => __( 'File size in bytes.', 'superdav-ai-agent' ),
						],
						'width'          => [
							'type'        => 'integer',
							'description' => __( 'Image width in pixels (0 for non-image files).', 'superdav-ai-agent' ),
						],
						'height'         => [
							'type'        => 'integer',
							'description' => __( 'Image height in pixels (0 for non-image files).', 'superdav-ai-agent' ),
						],
						'source'         => [
							'type'        => 'string',
							'description' => __( 'Echoes the input source discriminator.', 'superdav-ai-agent' ),
						],
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
				'execute_callback'    => [ __CLASS__, 'handle_upload_media' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'upload_files' );
				},
			]
		);
	}

	/**
	 * Execute the upload-media ability.
	 *
	 * Dispatches to the appropriate source handler based on the `source`
	 * discriminator and returns a unified response shape.
	 *
	 * @since 1.10.0
	 *
	 * @param array<string, mixed> $input Input args; must include `source`.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_upload_media( array $input ) {
		// @phpstan-ignore-next-line
		$source = sanitize_key( $input['source'] ?? '' );

		if ( '' === $source ) {
			return new WP_Error(
				'source_required',
				__( 'The "source" field is required. Use "url", "base64", or "path".', 'superdav-ai-agent' )
			);
		}

		switch ( $source ) {
			case 'url':
				return self::handle_url_source( $input );
			case 'base64':
				return self::handle_base64_source( $input );
			case 'path':
				return self::handle_path_source( $input );
			default:
				return new WP_Error(
					'source_required',
					sprintf(
						/* translators: %s: the provided source value */
						__( 'Unknown source "%s". Must be one of: url, base64, path.', 'superdav-ai-agent' ),
						$source
					)
				);
		}
	}

	// ─── Source handlers ─────────────────────────────────────────────────────

	/**
	 * Handle source=url upload via SSRF-safe HTTP download.
	 *
	 * @since 1.10.0
	 *
	 * @param array<string, mixed> $input Input args.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function handle_url_source( array $input ) {
		$result = MediaAbilities::sideload_from_url( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::build_response( $result['attachment_id'], 'url' );
	}

	/**
	 * Handle source=base64 upload with MIME-validation.
	 *
	 * @since 1.10.0
	 *
	 * @param array<string, mixed> $input Input args; must include `data_base64`.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function handle_base64_source( array $input ) {
		$result = ImageAbilities::sideload_from_base64( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::build_response( $result['attachment_id'], 'base64' );
	}

	/**
	 * Handle source=path upload from a server-side file.
	 *
	 * The original file is preserved; a copy is passed to
	 * media_handle_sideload() which moves the copy into the uploads directory.
	 *
	 * @since 1.10.0
	 *
	 * @param array<string, mixed> $input Input args; must include `path`.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function handle_path_source( array $input ) {
		// @phpstan-ignore-next-line
		$path = (string) ( $input['path'] ?? '' );

		if ( '' === $path ) {
			return new WP_Error(
				'path_escape',
				__( '"path" is required when source is "path".', 'superdav-ai-agent' )
			);
		}

		// Path-traversal guard: realpath must stay inside ABSPATH.
		$guard = AbsPathGuard::assert_inside_abspath( $path );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$real_path = realpath( $path );
		if ( false === $real_path || ! is_file( $real_path ) ) {
			return new WP_Error(
				'path_escape',
				/* translators: %s: the provided path */
				sprintf( __( 'Path "%s" is not a readable file.', 'superdav-ai-agent' ), $path )
			);
		}

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// @phpstan-ignore-next-line
		$post_id = (int) ( $input['post_id'] ?? 0 );
		// @phpstan-ignore-next-line
		$filename  = sanitize_text_field( $input['filename'] ?? '' );
		$base_name = ! empty( $filename ) ? $filename : pathinfo( $real_path, PATHINFO_FILENAME );
		// @phpstan-ignore-next-line
		$title = sanitize_text_field( $input['title'] ?? '' );
		// @phpstan-ignore-next-line
		$alt_text = sanitize_text_field( $input['alt_text'] ?? '' );
		// @phpstan-ignore-next-line
		$caption = sanitize_textarea_field( $input['caption'] ?? '' );
		// @phpstan-ignore-next-line
		$description = sanitize_textarea_field( $input['description'] ?? '' );

		if ( '' === $title ) {
			$title = ucwords( str_replace( [ '-', '_' ], ' ', $base_name ) );
		}

		// Copy to a temporary file so media_handle_sideload() can move (not
		// rename) it into the uploads directory without touching the original.
		$tmp_file = wp_tempnam( basename( $real_path ) );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- copy() returns false on failure; error is handled below.
		if ( ! @copy( $real_path, $tmp_file ) ) {
			wp_delete_file( $tmp_file );
			return new WP_Error(
				'copy_failed',
				/* translators: %s: the provided path */
				sprintf( __( 'Failed to copy file "%s" to a temporary location.', 'superdav-ai-agent' ), $path )
			);
		}

		$file_array = [
			'name'     => basename( $real_path ),
			'tmp_name' => $tmp_file,
		];

		$attachment_id = media_handle_sideload( $file_array, $post_id, $title );

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp_file ) ) {
				wp_delete_file( $tmp_file );
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

		if ( '' !== $alt_text ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		}

		return self::build_response( $attachment_id, 'path' );
	}

	// ─── Shared helpers ──────────────────────────────────────────────────────

	/**
	 * Build the unified response array for a successfully sideloaded attachment.
	 *
	 * Reads attachment metadata to include image dimensions and file size.
	 *
	 * @since 1.10.0
	 *
	 * @param int    $attachment_id The new attachment post ID.
	 * @param string $source        The source discriminator used ('url', 'base64', or 'path').
	 * @return array<string, mixed>
	 */
	private static function build_response( int $attachment_id, string $source ): array {
		$attachment = get_post( $attachment_id );
		$mime_type  = $attachment instanceof WP_Post ? $attachment->post_mime_type : '';
		$file_path  = get_attached_file( $attachment_id );
		$metadata   = wp_get_attachment_metadata( $attachment_id );

		return [
			'attachment_id'  => $attachment_id,
			'url'            => wp_get_attachment_url( $attachment_id ) ?: '',
			'mime_type'      => $mime_type,
			'filesize_bytes' => ( $file_path && file_exists( $file_path ) ) ? (int) filesize( $file_path ) : 0,
			'width'          => is_array( $metadata ) ? (int) ( $metadata['width'] ?? 0 ) : 0,
			'height'         => is_array( $metadata ) ? (int) ( $metadata['height'] ?? 0 ) : 0,
			'source'         => $source,
		];
	}
}
