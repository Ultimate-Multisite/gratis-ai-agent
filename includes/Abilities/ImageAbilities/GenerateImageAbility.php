<?php

declare(strict_types=1);
/**
 * Generate image ability using the WordPress AI Client SDK.
 *
 * Routes through wp_ai_client_prompt()->generate_image() so any provider
 * configured in WordPress core Settings > AI that supports image generation
 * (OpenAI DALL-E, Stability AI, Google Imagen, etc.) will be used automatically.
 *
 * Supports size, style, quality, and variations inputs where the configured
 * provider supports them. Unknown or unsupported option values are silently
 * ignored (graceful no-op/fallback).
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities\ImageAbilities;

use SdAiAgent\Abilities\ToolCapabilities;
use SdAiAgent\Core\Net\SafeHttpClient;
use SdAiAgent\Core\Settings;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiProvider;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates AI images via the WP AI Client SDK and imports them into WordPress.
 *
 * Supports multiple variations and saves provenance metadata (prompt, dimensions,
 * style, creation timestamp) on each generated attachment.
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
				'description'   => __( 'Generate unique AI images from a text prompt and import them into the media library. Supports size, style, quality, and multiple variations. Use for brand-specific imagery, concept illustrations, pattern backgrounds, and product visualisations — not for generic photography (use stock-image instead).', 'superdav-ai-agent' ),
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
		return __( 'Generate unique AI images from a text prompt and import them into the media library. Supports size, style, quality, and multiple variations. Use for brand-specific imagery, concept illustrations, pattern backgrounds, and product visualisations — not for generic photography (use stock-image instead).', 'superdav-ai-agent' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'prompt'     => [
					'type'        => 'string',
					'description' => 'Detailed description of the image to generate. Be specific about style, subject, composition, and lighting for best results. Use for brand-specific imagery, illustrations, and pattern backgrounds — not for generic photography.',
				],
				'title'      => [
					'type'        => 'string',
					'description' => 'Optional media library title. Defaults to a truncated version of the prompt.',
				],
				'size'       => [
					'type'        => 'string',
					'description' => 'Image dimensions. Common values: "1024x1024" (square), "1792x1024" (landscape), "1024x1792" (portrait). Unsupported values are silently ignored.',
				],
				'style'      => [
					'type'        => 'string',
					'description' => 'Visual style hint. Common values: "vivid" (hyper-real, dramatic) or "natural" (realistic, softer). Provider-specific; unsupported values are silently ignored.',
				],
				'quality'    => [
					'type'        => 'string',
					'description' => 'Output quality. Common values: "standard" or "hd". Provider-specific; unsupported values are silently ignored.',
				],
				'variations' => [
					'type'        => 'integer',
					'description' => 'Number of image variations to generate (1–4). Defaults to 1. When greater than 1, all attachments are returned in the "attachments" array.',
					'minimum'     => 1,
					'maximum'     => 4,
				],
				'post_id'    => [
					'type'        => 'integer',
					'description' => 'Optional post ID to attach the generated image to in the media library.',
				],
				'site_url'   => [
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
				'attachment_id' => [
					'type'        => 'integer',
					'description' => 'Attachment ID of the first generated image.',
				],
				'url'           => [
					'type'        => 'string',
					'description' => 'URL of the first generated image.',
				],
				'title'         => [ 'type' => 'string' ],
				'alt'           => [ 'type' => 'string' ],
				'attachments'   => [
					'type'        => 'array',
					'description' => 'All generated attachments. Contains one item when variations=1; multiple items when variations>1.',
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'attachment_id' => [ 'type' => 'integer' ],
							'url'           => [ 'type' => 'string' ],
							'title'         => [ 'type' => 'string' ],
							'alt'           => [ 'type' => 'string' ],
						],
					],
				],
				'error'         => [ 'type' => 'string' ],
				'tip'           => [ 'type' => 'string' ],
			],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	protected function meta(): array {
		$meta                = parent::meta();
		$meta['annotations'] = [
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		];

		return $meta;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Dual gate: routed through {@see ToolCapabilities::current_user_can()},
	 * which checks the per-tool capability (`sd_ai_agent_tool_generate_image`)
	 * AND the core capability (`upload_files`) listed in
	 * `ToolCapabilities::CORE_CAP_MAP`. On multisite with an explicit
	 * `site_url`, the check is performed in the context of the target blog
	 * so the same dual-gate applies to subsite uploads.
	 */
	protected function permission_callback( mixed $input = null ): bool {
		$site_url = is_array( $input ) ? (string) ( $input['site_url'] ?? '' ) : '';

		if ( '' === $site_url || ! is_multisite() ) {
			return ToolCapabilities::current_user_can( 'sd-ai-agent/generate-image' );
		}

		$blog_id = get_blog_id_from_url(
			(string) ( wp_parse_url( $site_url, PHP_URL_HOST ) ?? '' ),
			(string) ( wp_parse_url( $site_url, PHP_URL_PATH ) ?: '/' )
		);

		if ( ! $blog_id ) {
			return false;
		}

		if ( (int) $blog_id === get_current_blog_id() ) {
			return ToolCapabilities::current_user_can( 'sd-ai-agent/generate-image' );
		}

		switch_to_blog( $blog_id );
		$allowed = ToolCapabilities::current_user_can( 'sd-ai-agent/generate-image' );
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
		$site_url = sanitize_text_field( $input['site_url'] ?? '' ); // @phpstan-ignore-line

		// Clamp variations to 1–4.
		// @phpstan-ignore-next-line
		$variations = max( 1, min( 4, (int) ( $input['variations'] ?? 1 ) ) );

		// Build provider-specific options; only include non-empty values.
		$options = array_filter(
			[
				'size'    => sanitize_text_field( $input['size'] ?? '' ),    // @phpstan-ignore-line
				'style'   => sanitize_text_field( $input['style'] ?? '' ),   // @phpstan-ignore-line
				'quality' => sanitize_text_field( $input['quality'] ?? '' ), // @phpstan-ignore-line
			]
		);

		if ( empty( $prompt ) ) {
			return new WP_Error( 'missing_prompt', 'prompt is required.' );
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

		$attachments = [];
		$last_error  = null;

		for ( $i = 0; $i < $variations; $i++ ) {
			$variation_title = $variations > 1
				? $title . ' (variation ' . ( $i + 1 ) . ')'
				: $title;

			$result = $this->generate_and_import( $prompt, $variation_title, $post_id, $options );

			if ( is_wp_error( $result ) ) {
				$last_error = $result;
				// No provider → stop immediately; retrying variations won't help.
				if ( 'provider_unavailable' === $result->get_error_code() ) {
					break;
				}
			} else {
				$attachments[] = [
					'attachment_id' => $result['attachment_id'],
					'url'           => $result['url'],
					'title'         => $variation_title,
					'alt'           => $variation_title,
				];
			}
		}

		if ( $switched ) {
			restore_current_blog();
		}

		if ( empty( $attachments ) ) {
			$error_message = 'All image generation attempts failed.';
			if ( $last_error instanceof WP_Error ) {
				$error_message = 'provider_unavailable' === $last_error->get_error_code()
					? 'AI image generation is not available. Configure an image-capable provider in Settings > AI.'
					: $last_error->get_error_message();
			}

			return [
				'attachment_id' => 0,
				'url'           => '',
				'title'         => '',
				'alt'           => '',
				'error'         => $error_message,
			];
		}

		$first = $attachments[0];

		return [
			'attachment_id' => $first['attachment_id'],
			'url'           => $first['url'],
			'title'         => $first['title'],
			'alt'           => $first['alt'],
			'attachments'   => $attachments,
			'tip'           => count( $attachments ) > 1
				? 'Multiple variations generated. Use attachment_id from each item in the "attachments" array.'
				: 'Use attachment_id as featured_image_id when calling create-post or update-post.',
		];
	}

	/**
	 * Generate one image via the WP AI Client SDK and import it into the media library.
	 *
	 * Extracted as a protected method so it can be partially mocked in unit tests.
	 * Saves provenance metadata (prompt, timestamp, size, style) as post meta on
	 * the generated attachment.
	 *
	 * @param string               $prompt  Image generation prompt.
	 * @param string               $title   Media library title / alt text.
	 * @param int                  $post_id Post ID to attach to (0 = unattached).
	 * @param array<string,string> $options Provider-specific options (size, style, quality).
	 *                                      Unsupported options are silently ignored.
	 * @return array<string,mixed>|\WP_Error Array with attachment_id and url, or WP_Error.
	 */
	protected function generate_and_import(
		string $prompt,
		string $title,
		int $post_id,
		array $options = []
	): array|\WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' )
			|| ! wp_ai_client_prompt()->is_supported_for_image_generation() ) {
			return new WP_Error(
				'provider_unavailable',
				'AI image generation is not available. Configure an image-capable provider in Settings > AI.'
			);
		}

		$file = $this->create_image_prompt_builder( $prompt, $options )->generate_image();

		if ( is_wp_error( $file ) ) {
			return new WP_Error(
				'generation_failed',
				'Image generation failed: ' . $file->get_error_message()
			);
		}

		$tmp_file = $this->file_to_temp( $file );

		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		$result = $this->import_from_temp( $tmp_file, $title, $post_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Save provenance metadata on the generated attachment.
		$attachment_id = (int) $result['attachment_id'];
		update_post_meta( $attachment_id, '_sd_ai_agent_generated_prompt', $prompt );
		update_post_meta( $attachment_id, '_sd_ai_agent_generated_at', gmdate( 'Y-m-d\TH:i:s\Z' ) );
		if ( ! empty( $options['size'] ) ) {
			update_post_meta( $attachment_id, '_sd_ai_agent_generated_size', $options['size'] );
		}
		if ( ! empty( $options['style'] ) ) {
			update_post_meta( $attachment_id, '_sd_ai_agent_generated_style', $options['style'] );
		}
		if ( ! empty( $options['quality'] ) ) {
			update_post_meta( $attachment_id, '_sd_ai_agent_generated_quality', $options['quality'] );
		}

		return [
			'attachment_id' => $attachment_id,
			'url'           => $result['url'],
		];
	}

	/**
	 * Create the AI Client prompt builder for image generation.
	 *
	 * @param string               $prompt  Image generation prompt.
	 * @param array<string,string> $options Provider-specific options.
	 * @return \WP_AI_Client_Prompt_Builder Configured prompt builder.
	 */
	protected function create_image_prompt_builder( string $prompt, array $options = [] ): \WP_AI_Client_Prompt_Builder {
		$builder = wp_ai_client_prompt( $prompt );

		$model_preferences = $this->resolve_image_model_preferences();
		if ( ! empty( $model_preferences ) ) {
			$builder = $builder->using_model_preference( ...$model_preferences );
		}

		$model_config = $this->create_image_model_config( $options );
		if ( $model_config instanceof ModelConfig ) {
			$builder = $builder->using_model_config( $model_config );
		}

		return $builder;
	}

	/**
	 * Resolve preferred image models for the SDK prompt builder.
	 *
	 * The plugin-wide default model is normally a text model. When the bundled
	 * Superdav provider is selected, append its image model alias so the SDK can
	 * fall through from `superdav-chat-*` to `superdav-image` for image requests.
	 *
	 * @return list<string|array{0:string,1:string}> Model IDs or provider/model tuples.
	 */
	protected function resolve_image_model_preferences(): array {
		$provider = '';
		$model    = $this->get_configured_model();

		if ( class_exists( Settings::class ) ) {
			try {
				$settings       = Settings::instance();
				$saved_provider = $settings->get( 'default_provider' );
				$provider       = is_string( $saved_provider ) ? $saved_provider : '';

				$resolved_provider = $settings->get_default_provider();
				if ( '' !== $resolved_provider ) {
					$provider = $resolved_provider;
				}

				$resolved_model = $settings->get_default_model();
				if ( '' !== $resolved_model ) {
					$model = $resolved_model;
				}
			} catch ( \Throwable $e ) {
				// Fall back to the raw configured model when registry validation is unavailable.
			}
		}

		$preferences = [];
		if ( '' !== $model ) {
			$preferences[] = $model;
		}

		if ( $this->should_prefer_superdav_image_model( $provider, $model ) ) {
			$preferences[] = [ SuperdavAiProvider::PROVIDER_ID, SuperdavAiProvider::IMAGE_MODEL_ID ];
		}

		return $this->unique_image_model_preferences( $preferences );
	}

	/**
	 * Build an SDK model config for OpenAI-compatible image options.
	 *
	 * @param array<string,string> $options Provider-specific options.
	 * @return ModelConfig|null Model config, or null when no options are set.
	 */
	protected function create_image_model_config( array $options ): ?ModelConfig {
		$custom_options = [];
		foreach ( [ 'size', 'style', 'quality' ] as $key ) {
			$value = isset( $options[ $key ] ) ? trim( (string) $options[ $key ] ) : '';
			if ( '' !== $value ) {
				$custom_options[ $key ] = $value;
			}
		}

		if ( empty( $custom_options ) ) {
			return null;
		}

		$config = new ModelConfig();
		$config->setCustomOptions( $custom_options );

		return $config;
	}

	/**
	 * Whether Superdav image generation should be explicitly preferred.
	 *
	 * @param string $provider Configured provider ID.
	 * @param string $model    Configured/resolved model ID.
	 * @return bool True when the selected provider/model belongs to Superdav.
	 */
	private function should_prefer_superdav_image_model( string $provider, string $model ): bool {
		if ( SuperdavAiProvider::PROVIDER_ID === $provider ) {
			return true;
		}

		return in_array(
			$model,
			[
				SuperdavAiProvider::FAST_MODEL_ID,
				SuperdavAiProvider::DEFAULT_MODEL_ID,
				SuperdavAiProvider::STRONG_MODEL_ID,
				SuperdavAiProvider::IMAGE_MODEL_ID,
			],
			true
		);
	}

	/**
	 * Remove duplicate model preferences while preserving order.
	 *
	 * @param list<string|array{0:string,1:string}> $preferences Preferences.
	 * @return list<string|array{0:string,1:string}> Unique preferences.
	 */
	private function unique_image_model_preferences( array $preferences ): array {
		$seen   = [];
		$unique = [];

		foreach ( $preferences as $preference ) {
			$key = is_array( $preference )
				? 'provider:' . $preference[0] . ':model:' . $preference[1]
				: 'model:' . $preference;

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$unique[]     = $preference;
		}

		return $unique;
	}

	/**
	 * Save a File object from the AI SDK to a local temp file.
	 *
	 * @param mixed $file File object returned by generate_image().
	 * @return string|\WP_Error Temp file path or WP_Error on failure.
	 */
	protected function file_to_temp( mixed $file ): string|\WP_Error {
		// Remote URL — let SSRF-safe client download it.
		if ( method_exists( $file, 'isRemote' ) && $file->isRemote() ) {
			$url = $file->getUrl();
			if ( empty( $url ) ) {
				return new WP_Error( 'generation_failed', 'Generated image has no URL.' );
			}
			$tmp = SafeHttpClient::instance()->safe_download_url( $url, 60 );
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
	protected function import_from_temp( string $tmp_file, string $title, int $post_id = 0 ): array|\WP_Error {
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
