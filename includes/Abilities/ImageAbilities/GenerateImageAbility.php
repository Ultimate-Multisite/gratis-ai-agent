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
				'prompt'                   => [
					'type'        => 'string',
					'description' => 'Detailed description of the image to generate. Be specific about style, subject, composition, and lighting for best results.',
				],
				'title'                    => [
					'type'        => 'string',
					'description' => 'Optional media library title. Defaults to a truncated version of the prompt.',
				],
				'size'                     => [
					'type'        => 'string',
					'description' => 'Optional provider-supported image size such as 1024x1024, 1024x1792, or 1792x1024. Unsupported providers may ignore it.',
				],
				'style'                    => [
					'type'        => 'string',
					'description' => 'Optional provider-supported style hint such as natural, vivid, illustration, photographic, or branded pattern. Unsupported providers may ignore it.',
				],
				'variations'               => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 4,
					'description' => 'Number of image variations to generate. Clamped to 1-4 for predictable media-library imports.',
				],
				'reference_attachment_ids' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'integer' ],
					'description' => 'Optional media-library attachment IDs to use as reference images where the configured provider supports image-to-image generation.',
				],
				'post_id'                  => [
					'type'        => 'integer',
					'description' => 'Optional post ID to attach the generated image to in the media library.',
				],
				'site_url'                 => [
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
				'attachments'   => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
				'url'           => [ 'type' => 'string' ],
				'title'         => [ 'type' => 'string' ],
				'alt'           => [ 'type' => 'string' ],
				'width'         => [ 'type' => 'integer' ],
				'height'        => [ 'type' => 'integer' ],
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
		// @phpstan-ignore-next-line
		$size = sanitize_text_field( $input['size'] ?? '' );
		// @phpstan-ignore-next-line
		$style = sanitize_text_field( $input['style'] ?? '' );
		// @phpstan-ignore-next-line
		$variations = max( 1, min( 4, (int) ( $input['variations'] ?? 1 ) ) );
		// @phpstan-ignore-next-line
		$reference_attachment_ids = $this->sanitize_reference_attachment_ids( $input['reference_attachment_ids'] ?? [] );

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

		$options     = $this->build_generation_options( $size, $style, $reference_attachment_ids );
		$attachments = [];

		for ( $index = 1; $index <= $variations; $index++ ) {
			$file = $this->generate_image_file( $prompt, $options );

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

			$validation = $this->validate_temp_image( $tmp_file );
			if ( is_wp_error( $validation ) ) {
				if ( file_exists( $tmp_file ) ) {
					unlink( $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
				if ( $switched ) {
					restore_current_blog();
				}
				return [
					'attachment_id' => 0,
					'url'           => '',
					'title'         => '',
					'alt'           => '',
					'error'         => $validation->get_error_message(),
				];
			}

			$variation_title = $variations > 1 ? sprintf( '%s %d', $title, $index ) : $title;
			$result          = $this->import_from_temp( $tmp_file, $variation_title, $post_id, $prompt, $options, $validation, $file );

			if ( is_wp_error( $result ) ) {
				if ( $switched ) {
					restore_current_blog();
				}
				return [
					'attachment_id' => 0,
					'url'           => '',
					'title'         => '',
					'alt'           => '',
					'error'         => $result->get_error_message(),
				];
			}

			$attachments[] = $result;
		}

		if ( $switched ) {
			restore_current_blog();
		}

		$primary = $attachments[0] ?? [
			'attachment_id' => 0,
			'url'           => '',
			'width'         => 0,
			'height'        => 0,
		];

		return [
			'attachment_id' => $primary['attachment_id'],
			'attachments'   => $attachments,
			'url'           => $primary['url'],
			'title'         => $title,
			'alt'           => $title,
			'width'         => $primary['width'],
			'height'        => $primary['height'],
			'tip'           => 'Use attachment_id as featured_image_id when calling create-post or update-post.',
		];
	}

	/**
	 * Build provider options while keeping unsupported fields optional.
	 *
	 * @param string     $size                     Requested size.
	 * @param string     $style                    Requested style.
	 * @param array<int> $reference_attachment_ids Reference attachment IDs.
	 * @return array<string,mixed>
	 */
	private function build_generation_options( string $size, string $style, array $reference_attachment_ids ): array {
		$options = [];

		if ( '' !== $size ) {
			$options['size'] = $size;
		}

		if ( '' !== $style ) {
			$options['style'] = $style;
		}

		if ( [] !== $reference_attachment_ids ) {
			$options['reference_attachment_ids'] = $reference_attachment_ids;
		}

		return $options;
	}

	/**
	 * Generate one image file, retrying without options when a provider rejects them.
	 *
	 * @param string              $prompt  Image prompt.
	 * @param array<string,mixed> $options Optional provider arguments.
	 * @return mixed|WP_Error
	 */
	private function generate_image_file( string $prompt, array $options ) {
		$filtered = apply_filters( 'sd_ai_agent_generate_image_result', null, $prompt, $options );
		if ( null !== $filtered ) {
			return $filtered;
		}

		$client = wp_ai_client_prompt( $prompt );
		$client = $this->apply_builder_options( $client, $options );

		try {
			return $client->generate_image();
		} catch ( \Throwable $throwable ) {
			return new WP_Error( 'generation_failed', $throwable->getMessage() );
		}
	}

	/**
	 * Apply optional image-generation settings when the prompt builder supports them.
	 *
	 * @param mixed               $client  Prompt builder.
	 * @param array<string,mixed> $options Optional provider arguments.
	 * @return mixed
	 */
	private function apply_builder_options( mixed $client, array $options ): mixed {
		if ( ! is_object( $client ) ) {
			return $client;
		}

		$method_map = [
			'size'                     => [ 'set_size', 'with_size', 'size' ],
			'style'                    => [ 'set_style', 'with_style', 'style' ],
			'reference_attachment_ids' => [ 'set_reference_attachment_ids', 'with_reference_attachment_ids', 'reference_attachment_ids' ],
		];

		foreach ( $method_map as $option => $methods ) {
			if ( ! array_key_exists( $option, $options ) ) {
				continue;
			}

			foreach ( $methods as $method ) {
				if ( method_exists( $client, $method ) ) {
					$updated = $client->{$method}( $options[ $option ] );
					$client  = is_object( $updated ) ? $updated : $client;
					break;
				}
			}
		}

		return $client;
	}

	/**
	 * Sanitize optional reference image attachment IDs.
	 *
	 * @param mixed $value Raw value.
	 * @return list<int>
	 */
	private function sanitize_reference_attachment_ids( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$ids = array_filter(
			array_map(
				static function ( mixed $id ): int {
					return absint( $id );
				},
				$value
			)
		);

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Validate a generated temp file before media-library import.
	 *
	 * @param string $tmp_file Temp image path.
	 * @return array{mime:string,width:int,height:int}|WP_Error
	 */
	private function validate_temp_image( string $tmp_file ): array|WP_Error {
		$finfo = new \finfo( FILEINFO_MIME_TYPE );
		$mime  = (string) $finfo->file( $tmp_file );

		if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ], true ) ) {
			return new WP_Error( 'invalid_generated_image_mime', 'Generated file is not a supported image type.' );
		}

		$dimensions = getimagesize( $tmp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Local generated file validation.
		if ( false === $dimensions ) {
			return new WP_Error( 'invalid_generated_image_dimensions', 'Generated image dimensions could not be read.' );
		}

		return [
			'mime'   => $mime,
			'width'  => (int) $dimensions[0],
			'height' => (int) $dimensions[1],
		];
	}

	/**
	 * Extract optional safety/moderation data from a provider file object.
	 *
	 * @param mixed $file Provider file object.
	 * @return array<string,mixed>
	 */
	private function get_safety_metadata( mixed $file ): array {
		if ( ! is_object( $file ) ) {
			return [];
		}

		foreach ( [ 'getSafetyFlags', 'getModerationFlags', 'getModerationResults' ] as $method ) {
			if ( method_exists( $file, $method ) ) {
				$value = $file->{$method}();
				if ( ! is_array( $value ) ) {
					return [ 'value' => $value ];
				}

				$metadata = [];
				foreach ( $value as $key => $item ) {
					$metadata[ (string) $key ] = $item;
				}

				return $metadata;
			}
		}

		return [];
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
	 * @param string                                  $tmp_file   Path to the temp image file.
	 * @param string                                  $title      Attachment title and alt text.
	 * @param int                                     $post_id    Post ID to attach to (0 = unattached).
	 * @param string                                  $prompt     Generation prompt.
	 * @param array<string,mixed>                     $options    Provider options.
	 * @param array{mime:string,width:int,height:int} $validation Validated file details.
	 * @param mixed                                   $file       Provider file object.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function import_from_temp( string $tmp_file, string $title, int $post_id, string $prompt, array $options, array $validation, mixed $file ): array|\WP_Error {
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
		update_post_meta(
			$attachment_id,
			'_sd_ai_agent_image_provenance',
			[
				'provider'     => 'wp-ai-client',
				'model'        => is_object( $file ) && method_exists( $file, 'getModel' ) ? (string) $file->getModel() : '',
				'prompt'       => $prompt,
				'options'      => $options,
				'mime'         => $validation['mime'],
				'width'        => $validation['width'],
				'height'       => $validation['height'],
				'safety_flags' => $this->get_safety_metadata( $file ),
				'generated_at' => current_time( 'mysql', true ),
			]
		);

		return [
			'attachment_id' => $attachment_id,
			'url'           => wp_get_attachment_url( $attachment_id ),
			'title'         => $title,
			'alt'           => $title,
			'width'         => $validation['width'],
			'height'        => $validation['height'],
			'mime'          => $validation['mime'],
		];
	}
}
