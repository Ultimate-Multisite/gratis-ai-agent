<?php

declare(strict_types=1);
/**
 * Generate image ability using the WordPress AI Client SDK.
 *
 * Routes through wp_ai_client_prompt()->generate_image() so any provider
 * configured in WordPress core Settings > AI that supports image generation
 * (OpenAI DALL-E, Stability AI, Google Imagen, etc.) will be used automatically.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities\ImageAbilities;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates an AI image via the WP AI Client SDK and imports it into WordPress.
 *
 * @since 1.6.0
 */
class GenerateImageAbility extends \SdAiAgent\Abilities\AbstractAbility {

	/**
	 * Register this ability.
	 *
	 * Only registers when an image-capable AI provider is actually configured.
	 * Without one, exposing the ability would mislead the model into calling a
	 * tool that can only return an error.
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		if ( ! function_exists( 'wp_ai_client_prompt' )
			|| ! wp_ai_client_prompt()->is_supported_for_image_generation() ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/generate-image',
			[
				'label'         => __( 'Generate Image', 'superdav-ai-agent' ),
				'description'   => __( 'Generate a unique AI image from a text prompt and import it into the media library. Uses whichever image-capable provider is configured in Settings > AI (e.g. DALL-E, Stable Diffusion, Google Imagen). Use this when stock photos are not suitable.', 'superdav-ai-agent' ),
				'ability_class' => self::class,
			]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function label(): string {
		return __( 'Generate Image', 'superdav-ai-agent' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function description(): string {
		return __( 'Generate a unique AI image from a text prompt and import it into the media library. Uses whichever image-capable provider is configured in Settings > AI (e.g. DALL-E, Stable Diffusion, Google Imagen). Use this when stock photos are not suitable.', 'superdav-ai-agent' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'prompt'   => [
					'type'        => 'string',
					'description' => 'Detailed description of the image to generate. Be specific about style, subject, composition, and lighting for best results.',
				],
				'title'    => [
					'type'        => 'string',
					'description' => 'Optional media library title. Defaults to a truncated version of the prompt.',
				],
				'post_id'  => [
					'type'        => 'integer',
					'description' => 'Optional post ID to attach the generated image to in the media library.',
				],
				'site_url' => [
					'type'        => 'string',
					'description' => 'Subsite URL to import into on multisite (e.g. "https://example.com/mysite"). Omit for the main site.',
				],
			],
			'required'   => [ 'prompt' ],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'attachment_id' => [ 'type' => 'integer' ],
				'url'           => [ 'type' => 'string' ],
				'title'         => [ 'type' => 'string' ],
				'alt'           => [ 'type' => 'string' ],
				'error'         => [ 'type' => 'string' ],
				'tip'           => [ 'type' => 'string' ],
			],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	protected function permission_callback( mixed $input = null ): bool {
		$site_url = is_array( $input ) ? (string) ( $input['site_url'] ?? '' ) : '';

		if ( '' === $site_url || ! is_multisite() ) {
			return current_user_can( 'upload_files' );
		}

		$blog_id = get_blog_id_from_url(
			(string) ( wp_parse_url( $site_url, PHP_URL_HOST ) ?? '' ),
			(string) ( wp_parse_url( $site_url, PHP_URL_PATH ) ?: '/' )
		);

		if ( ! $blog_id ) {
			return false;
		}

		if ( (int) $blog_id === get_current_blog_id() ) {
			return current_user_can( 'upload_files' );
		}

		switch_to_blog( $blog_id );
		$allowed = current_user_can( 'upload_files' );
		restore_current_blog();

		return $allowed;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function execute_callback( mixed $input ): array|\WP_Error {
		// @phpstan-ignore-next-line
		$prompt = sanitize_textarea_field( $input['prompt'] ?? '' );
		// @phpstan-ignore-next-line
		$title = sanitize_text_field( $input['title'] ?? '' );
		// @phpstan-ignore-next-line
		$post_id  = (int) ( $input['post_id'] ?? 0 );
		$site_url = sanitize_text_field( $input['site_url'] ?? '' );

		if ( empty( $prompt ) ) {
			return new WP_Error( 'missing_prompt', 'prompt is required.' );
		}

		if ( ! function_exists( 'wp_ai_client_prompt' )
			|| ! wp_ai_client_prompt()->is_supported_for_image_generation() ) {
			return [
				'attachment_id' => 0,
				'url'           => '',
				'title'         => '',
				'alt'           => '',
				'error'         => 'AI image generation is not available. Configure an image-capable provider in Settings > AI.',
			];
		}

		if ( empty( $title ) ) {
			$title = mb_substr( $prompt, 0, 80 );
		}

		// Switch to subsite if requested.
		$switched = false;
		if ( ! empty( $site_url ) && is_multisite() ) {
			$blog_id = get_blog_id_from_url(
				(string) ( wp_parse_url( $site_url, PHP_URL_HOST ) ?? '' ),
				(string) ( wp_parse_url( $site_url, PHP_URL_PATH ) ?: '/' )
			);

			if ( ! $blog_id ) {
				return [
					'attachment_id' => 0,
					'url'           => '',
					'title'         => '',
					'alt'           => '',
					'error'         => "Could not find a site matching URL: {$site_url}",
				];
			}

			if ( (int) $blog_id !== get_current_blog_id() ) {
				switch_to_blog( $blog_id );
				$switched = true;
			}
		}

		$file = wp_ai_client_prompt( $prompt )->generate_image();

		if ( is_wp_error( $file ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return [
				'attachment_id' => 0,
				'url'           => '',
				'title'         => '',
				'alt'           => '',
				'error'         => 'Image generation failed: ' . $file->get_error_message(),
			];
		}

		$tmp_file = $this->file_to_temp( $file );

		if ( is_wp_error( $tmp_file ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return [
				'attachment_id' => 0,
				'url'           => '',
				'title'         => '',
				'alt'           => '',
				'error'         => $tmp_file->get_error_message(),
			];
		}

		$result = $this->import_from_temp( $tmp_file, $title, $post_id );

		if ( $switched ) {
			restore_current_blog();
		}

		if ( is_wp_error( $result ) ) {
			return [
				'attachment_id' => 0,
				'url'           => '',
				'title'         => '',
				'alt'           => '',
				'error'         => $result->get_error_message(),
			];
		}

		return [
			'attachment_id' => $result['attachment_id'],
			'url'           => $result['url'],
			'title'         => $title,
			'alt'           => $title,
			'tip'           => 'Use attachment_id as featured_image_id when calling create-post or update-post.',
		];
	}

	/**
	 * Save a File object from the AI SDK to a local temp file.
	 *
	 * @param mixed $file File object returned by generate_image().
	 * @return string|\WP_Error Temp file path or WP_Error on failure.
	 */
	private function file_to_temp( $file ): string|\WP_Error {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Remote URL — let WordPress download it.
		if ( method_exists( $file, 'isRemote' ) && $file->isRemote() ) {
			$url = $file->getUrl();
			if ( empty( $url ) ) {
				return new WP_Error( 'generation_failed', 'Generated image has no URL.' );
			}
			$tmp = download_url( $url, 60 );
			if ( is_wp_error( $tmp ) ) {
				return new WP_Error( 'download_failed', 'Failed to download generated image: ' . $tmp->get_error_message() );
			}
			return $tmp;
		}

		// Inline base64 — write directly to a temp file.
		$base64 = method_exists( $file, 'getBase64Data' ) ? $file->getBase64Data() : null;
		if ( null === $base64 || '' === $base64 ) {
			return new WP_Error( 'generation_failed', 'Generated image returned no data.' );
		}

		$image_data = base64_decode( $base64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $image_data ) {
			return new WP_Error( 'generation_failed', 'Failed to decode generated image data.' );
		}

		$mime     = method_exists( $file, 'getMimeType' ) ? $file->getMimeType() : 'image/png';
		$ext_map  = [
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		];
		$ext      = $ext_map[ $mime ] ?? 'png';
		$tmp_file = get_temp_dir() . 'sd-ai-' . uniqid() . '.' . $ext;

		$written = file_put_contents( $tmp_file, $image_data ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $written ) {
			return new WP_Error( 'generation_failed', 'Failed to write temp image file.' );
		}

		return $tmp_file;
	}

	/**
	 * Import a temp file into the WordPress media library.
	 *
	 * @param string $tmp_file Path to the temp image file.
	 * @param string $title    Attachment title and alt text.
	 * @param int    $post_id  Post ID to attach to (0 = unattached).
	 * @return array<string,mixed>|\WP_Error
	 */
	private function import_from_temp( string $tmp_file, string $title, int $post_id = 0 ): array|\WP_Error {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$finfo    = new \finfo( FILEINFO_MIME_TYPE );
		$mime     = $finfo->file( $tmp_file );
		$ext_map  = [
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		];
		$ext      = $ext_map[ $mime ] ?? 'png';
		$filename = sanitize_file_name( $title ) . '-ai-generated.' . $ext;

		$file_array = [
			'name'     => $filename,
			'tmp_name' => $tmp_file,
		];

		$attachment_id = media_handle_sideload( $file_array, $post_id, $title );

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp_file ) ) {
				unlink( $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			return $attachment_id;
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $title );

		return [
			'attachment_id' => $attachment_id,
			'url'           => wp_get_attachment_url( $attachment_id ),
		];
	}
}
