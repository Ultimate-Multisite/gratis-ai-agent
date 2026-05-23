<?php

declare(strict_types=1);
/**
 * Image AI abilities for the AI agent.
 *
 * Provides AI-powered image abilities ported from the WordPress/ai experiments
 * plugin (https://github.com/WordPress/ai):
 *
 *  - sd-ai-agent/generate-alt-text      — Generate descriptive alt text for an image
 *                                      using a vision model.
 *  - sd-ai-agent/generate-image-prompt  — Generate an image-generation prompt from
 *                                      post content or arbitrary text.
 *  - sd-ai-agent/import-base64-image    — Import a base64-encoded image into the
 *                                      WordPress media library.
 *
 * @package SdAiAgent
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Core\Net\SafeHttpClient;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers all image AI abilities.
 *
 * @since 1.1.0
 */
class ImageAbilities {

	/**
	 * Maximum character length for generated alt text.
	 *
	 * @since 1.1.0
	 */
	private const MAX_ALT_TEXT_LENGTH = 125;

	/**
	 * Register all image abilities.
	 *
	 * @since 1.1.0
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::register_generate_alt_text();
		self::register_generate_image_prompt();
		self::register_import_base64_image();
	}

	// ─── Alt Text Generation ─────────────────────────────────────────────────

	/**
	 * Register the generate-alt-text ability.
	 *
	 * @since 1.1.0
	 */
	private static function register_generate_alt_text(): void {
		wp_register_ability(
			'sd-ai-agent/generate-alt-text',
			[
				'label'               => __( 'Generate Alt Text', 'superdav-ai-agent' ),
				'description'         => __( 'Use a vision AI model to generate descriptive, accessibility-compliant alt text for an image. Accepts an attachment ID or image URL.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'attachment_id' => [
							'type'        => 'integer',
							'description' => __( 'Attachment ID of the image to generate alt text for.', 'superdav-ai-agent' ),
						],
						'image_url'     => [
							'type'        => 'string',
							'description' => __( 'URL or data URI of the image. Used when attachment_id is not provided.', 'superdav-ai-agent' ),
						],
						'context'       => [
							'type'        => 'string',
							'description' => __( 'Optional context about the image or surrounding content to improve alt text relevance.', 'superdav-ai-agent' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'alt_text' => [
							'type'        => 'string',
							'description' => __( 'Generated alt text for the image (≤ 125 characters).', 'superdav-ai-agent' ),
						],
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_generate_alt_text' ],
				'permission_callback' => [ __CLASS__, 'permission_upload_files' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Execute the generate-alt-text ability.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array{alt_text: string}|\WP_Error
	 */
	public static function handle_generate_alt_text( array $input ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error( 'ai_client_unavailable', __( 'wp_ai_client_prompt() is not available.', 'superdav-ai-agent' ) );
		}

		// @phpstan-ignore-next-line
		$context = sanitize_textarea_field( $input['context'] ?? '' );

		// Resolve the image to a data URI.
		$image_reference = self::resolve_image_reference( $input );
		if ( is_wp_error( $image_reference ) ) {
			return $image_reference;
		}

		$system_instruction = <<<'INSTRUCTION'
You are an accessibility expert that generates alt text for images on websites.

Goal: Analyse the provided image and generate concise, descriptive alt text that accurately describes the image content for users who cannot see it. The alt text should be optimised for screen readers and accessibility compliance. If additional context is provided, use it to generate a more relevant alt text.

Requirements for the alt text:

- Be concise: Keep it under 125 characters when possible
- Be descriptive: Describe what is visually present in the image
- Be objective: Describe what you see, not interpretations or assumptions
- Avoid redundancy: Do not start with "Image of", "Picture of", or "Photo of"
- Include relevant details: People, objects, actions, colours, and context when meaningful
- Consider context: If context is provided, ensure the alt text is relevant to the surrounding content
- Plain text only: No markdown, quotes, or special formatting

For images containing text, include the text in your description if it is essential to understanding the image.

Respond with only the alt text, nothing else.
INSTRUCTION;

		$prompt = __( 'Generate alt text for this image.', 'superdav-ai-agent' );
		if ( ! empty( $context ) ) {
			$prompt .= "\n\n<additional-context>" . $context . '</additional-context>';
		}

		$builder = wp_ai_client_prompt( $prompt )
			->with_file( $image_reference )
			->using_system_instruction( $system_instruction )
			->using_temperature( 0.3 );

		$model = self::get_configured_model();
		if ( ! empty( $model ) ) {
			$builder = $builder->using_model_preference( $model );
		}

		$result = $builder->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result ) ) {
			return new WP_Error( 'no_results', __( 'No alt text was generated.', 'superdav-ai-agent' ) );
		}

		$alt_text = trim( (string) $result, '"\'.' );
		$alt_text = trim( $alt_text );

		if ( mb_strlen( $alt_text, 'UTF-8' ) > self::MAX_ALT_TEXT_LENGTH ) {
			$alt_text = mb_substr( $alt_text, 0, self::MAX_ALT_TEXT_LENGTH - 3, 'UTF-8' ) . '...';
		}

		return [ 'alt_text' => sanitize_text_field( $alt_text ) ];
	}

	// ─── Image Prompt Generation ─────────────────────────────────────────────

	/**
	 * Register the generate-image-prompt ability.
	 *
	 * @since 1.1.0
	 */
	private static function register_generate_image_prompt(): void {
		wp_register_ability(
			'sd-ai-agent/generate-image-prompt',
			[
				'label'               => __( 'Generate Image Prompt', 'superdav-ai-agent' ),
				'description'         => __( 'Generate a self-contained image-generation prompt from post content or arbitrary text. Suitable for passing directly to an image generation model.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'content' => [
							'type'        => 'string',
							'description' => __( 'Content to use as inspiration for the generated image.', 'superdav-ai-agent' ),
						],
						'context' => [
							'type'        => 'string',
							'description' => __( 'Additional context or a post ID to enrich the prompt.', 'superdav-ai-agent' ),
						],
						'style'   => [
							'type'        => 'string',
							'description' => __( 'Optional style instructions to apply to the generated image (e.g. "photorealistic", "watercolour painting").', 'superdav-ai-agent' ),
						],
					],
					'required'   => [ 'content' ],
				],
				'output_schema'       => [
					'type'        => 'string',
					'description' => __( 'The image generation prompt.', 'superdav-ai-agent' ),
				],
				'execute_callback'    => [ __CLASS__, 'handle_generate_image_prompt' ],
				'permission_callback' => [ __CLASS__, 'permission_edit_posts' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Execute the generate-image-prompt ability.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return string|\WP_Error
	 */
	public static function handle_generate_image_prompt( array $input ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error( 'ai_client_unavailable', __( 'wp_ai_client_prompt() is not available.', 'superdav-ai-agent' ) );
		}

		// @phpstan-ignore-next-line
		$raw_content = sanitize_text_field( $input['content'] ?? '' );
		// @phpstan-ignore-next-line
		$style = sanitize_text_field( $input['style'] ?? '' );

		// Resolve content and context.
		$context_input = $input['context'] ?? '';
		if ( is_numeric( $context_input ) && (int) $context_input > 0 ) {
			$post = get_post( (int) $context_input );
			if ( ! $post ) {
				return new WP_Error(
					'post_not_found',
					/* translators: %d: Post ID. */
					sprintf( __( 'Post with ID %d not found.', 'superdav-ai-agent' ), absint( $context_input ) )
				);
			}
			$content = ! empty( $raw_content ) ? wp_strip_all_tags( $raw_content ) : wp_strip_all_tags( $post->post_content );
			$context = implode(
				"\n",
				array_filter(
					[
						$post->post_title ? 'Title: ' . $post->post_title : '',
						$post->post_excerpt ? 'Excerpt: ' . $post->post_excerpt : '',
					]
				)
			);
		} else {
			$content = wp_strip_all_tags( $raw_content );
			// @phpstan-ignore-next-line
			$context = sanitize_text_field( $context_input );
		}

		if ( empty( $content ) ) {
			return new WP_Error( 'content_not_provided', __( 'Content is required to generate an image prompt.', 'superdav-ai-agent' ) );
		}

		$system_instruction = <<<'INSTRUCTION'
You are a helpful assistant that generates a single, self-contained image generation prompt suitable for use with an image generation LLM.

You will be given:
- Some content to use as inspiration for the final generated image
- Additional context, provided in a structured format
- Some optional style instructions to apply to the final generated image

Your task is to synthesise this information into a single, complete image generation prompt that can be passed directly to another LLM to immediately generate an image.

Requirements:
- Incorporate relevant context faithfully and accurately
- Do not reference the existence or structure of the input context
- Do not include explanations, headings, or commentary
- Output only the final image generation prompt text

The generated prompt should describe an image that visually represents the content's core topic and tone. Use the provided content as factual grounding, but do not include text, captions, logos, or branding in the image unless explicitly specified.

The prompt should:
- Be written as a direct instruction to an image generation model
- Clearly describe the subject, setting, and visual style
- Reflect the content's theme and context without being overly literal
- Avoid mentioning the content, author, or website
- Be concise but descriptive enough to produce a high-quality, editorial-style image

Output only the final image generation prompt, with no explanations or additional commentary.
INSTRUCTION;

		$prompt = '<content>' . $content . '</content>';
		if ( ! empty( $context ) ) {
			$prompt .= "\n\n<additional-context>" . $context . '</additional-context>';
		}
		if ( ! empty( $style ) ) {
			// phpcs:ignore WordPress.WP.EnqueuedResources -- This is XML markup in an AI prompt string, not an HTML <style> element. No CSS is enqueued or rendered to the frontend.
			$prompt .= "\n\n<style>" . $style . '</style>';
		}

		$builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system_instruction )
			->using_temperature( 0.9 );

		$model = self::get_configured_model();
		if ( ! empty( $model ) ) {
			$builder = $builder->using_model_preference( $model );
		}

		$result = $builder->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result ) ) {
			return new WP_Error( 'no_results', __( 'No image prompt was generated.', 'superdav-ai-agent' ) );
		}

		return sanitize_text_field( trim( (string) $result ) );
	}

	// ─── Import Base64 Image ─────────────────────────────────────────────────

	/**
	 * Register the import-base64-image ability.
	 *
	 * @since 1.1.0
	 */
	private static function register_import_base64_image(): void {
		wp_register_ability(
			'sd-ai-agent/import-base64-image',
			[
				'label'               => __( 'Import Base64 Image', 'superdav-ai-agent' ),
				'description'         => __( 'Import a base64-encoded image into the WordPress media library. Returns the attachment ID, URL, and metadata.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'data'        => [
							'type'        => 'string',
							'description' => __( 'Base64-encoded image data to import.', 'superdav-ai-agent' ),
						],
						'filename'    => [
							'type'        => 'string',
							'description' => __( 'Filename for the imported image (without extension).', 'superdav-ai-agent' ),
						],
						'title'       => [
							'type'        => 'string',
							'description' => __( 'Title for the media library attachment.', 'superdav-ai-agent' ),
						],
						'description' => [
							'type'        => 'string',
							'description' => __( 'Description for the media library attachment.', 'superdav-ai-agent' ),
						],
						'alt_text'    => [
							'type'        => 'string',
							'description' => __( 'Alt text for the image.', 'superdav-ai-agent' ),
						],
						'mime_type'   => [
							'type'        => 'string',
							'description' => __( 'MIME type of the image (e.g. image/png, image/jpeg). Auto-detected if omitted.', 'superdav-ai-agent' ),
						],
					],
					'required'   => [ 'data' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'          => [
							'type'        => 'integer',
							'description' => __( 'Attachment ID.', 'superdav-ai-agent' ),
						],
						'url'         => [
							'type'        => 'string',
							'description' => __( 'Attachment URL.', 'superdav-ai-agent' ),
						],
						'filename'    => [
							'type'        => 'string',
							'description' => __( 'Attachment filename.', 'superdav-ai-agent' ),
						],
						'title'       => [
							'type'        => 'string',
							'description' => __( 'Attachment title.', 'superdav-ai-agent' ),
						],
						'description' => [
							'type'        => 'string',
							'description' => __( 'Attachment description.', 'superdav-ai-agent' ),
						],
						'alt_text'    => [
							'type'        => 'string',
							'description' => __( 'Attachment alt text.', 'superdav-ai-agent' ),
						],
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_import_base64_image' ],
				'permission_callback' => [ __CLASS__, 'permission_upload_files' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Execute the import-base64-image ability.
	 *
	 * @deprecated 1.10.0 Use sd-ai-agent/upload-media with source "base64" instead.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_import_base64_image( array $input ) {
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- _doing_it_wrong() handles its own escaping internally.
		_doing_it_wrong(
			'sd-ai-agent/import-base64-image',
			__( 'Use sd-ai-agent/upload-media with source "base64" instead of sd-ai-agent/import-base64-image.', 'superdav-ai-agent' ),
			'1.10.0'
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		$result = self::sideload_from_base64( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Return the legacy response shape (uses 'id' instead of 'attachment_id',
		// omits 'mime_type' — preserved for backward compatibility).
		return [
			'id'          => $result['attachment_id'],
			'url'         => $result['url'],
			'filename'    => $result['filename'],
			'title'       => $result['title'],
			'description' => $result['description'],
			'alt_text'    => $result['alt_text'],
		];
	}

	/**
	 * Core base64-to-media-library sideload logic.
	 *
	 * Decodes base64 image data, validates the declared MIME type against
	 * the actual binary (returning WP_Error('mime_data_mismatch') on mismatch),
	 * writes a temp file, and sideloads via WordPress media functions.
	 *
	 * Accepts either the 'data_base64' key (unified upload-media ability) or
	 * the legacy 'data' key (import-base64-image); 'data_base64' takes priority.
	 *
	 * @since 1.10.0
	 *
	 * @param array<string,mixed> $input Input args: data_base64|data, mime_type, filename, title,
	 *                                  description, caption, alt_text, post_id.
	 * @return array{attachment_id: int, url: string, filename: string, title: string, description: string, alt_text: string, mime_type: string}|\WP_Error Array with 'attachment_id' on success, WP_Error on failure.
	 */
	public static function sideload_from_base64( array $input ) {
		// Accept 'data_base64' (unified ability) or 'data' (legacy ability).
		// @phpstan-ignore-next-line
		$data = (string) ( $input['data_base64'] ?? $input['data'] ?? '' );

		if ( '' === $data ) {
			return new WP_Error( 'missing_data', __( 'Base64 image data is required.', 'superdav-ai-agent' ) );
		}

		// Strip data URI prefix if present (e.g. "data:image/png;base64,").
		// @phpstan-ignore-next-line
		$declared_mime = sanitize_mime_type( $input['mime_type'] ?? '' );
		if ( str_contains( $data, ',' ) ) {
			[ $header, $data ] = explode( ',', $data, 2 );
			// Extract MIME type from header if not explicitly provided.
			if ( empty( $declared_mime ) && preg_match( '/data:([^;]+);/', $header, $m ) ) {
				$declared_mime = sanitize_mime_type( $m[1] );
			}
		}

		$decoded = base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding user-supplied image data, not obfuscation
		if ( false === $decoded ) {
			return new WP_Error( 'invalid_base64', __( 'Failed to decode base64 image data.', 'superdav-ai-agent' ) );
		}

		// Detect actual MIME type from binary magic bytes.
		$detected_mime = self::detect_mime_type_from_binary( $decoded );

		// Validate declared mime_type against detected type when both are known.
		if ( ! empty( $declared_mime ) && ! empty( $detected_mime ) && $declared_mime !== $detected_mime ) {
			return new WP_Error(
				'mime_data_mismatch',
				sprintf(
					/* translators: 1: declared MIME type, 2: detected MIME type from binary */
					__( 'Declared MIME type "%1$s" does not match the actual image data "%2$s".', 'superdav-ai-agent' ),
					$declared_mime,
					$detected_mime
				)
			);
		}

		$mime_type = ! empty( $declared_mime ) ? $declared_mime : $detected_mime;

		if ( empty( $mime_type ) || ! str_starts_with( $mime_type, 'image/' ) ) {
			return new WP_Error( 'invalid_image', __( 'The provided data is not a recognised image type.', 'superdav-ai-agent' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Write decoded bytes to a temporary file.
		$temp_file = wp_tempnam( 'ai-image' );
		$written   = file_put_contents( $temp_file, $decoded ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_ops_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing decoded image bytes to a temp file; WP_Filesystem does not support binary writes to arbitrary temp paths

		if ( false === $written ) {
			wp_delete_file( $temp_file );
			return new WP_Error( 'write_failed', __( 'Failed to write image data to a temporary file.', 'superdav-ai-agent' ) );
		}

		$extension = wp_get_default_extension_for_mime_type( $mime_type ) ?: 'bin';
		// @phpstan-ignore-next-line
		$base_name = sanitize_file_name( $input['filename'] ?? ( 'ai-image-' . time() ) );
		// @phpstan-ignore-next-line
		$post_id = (int) ( $input['post_id'] ?? 0 );
		// @phpstan-ignore-next-line
		$title = sanitize_text_field( $input['title'] ?? '' );
		// @phpstan-ignore-next-line
		$description = sanitize_textarea_field( $input['description'] ?? '' );
		// @phpstan-ignore-next-line
		$caption = sanitize_textarea_field( $input['caption'] ?? '' );
		// @phpstan-ignore-next-line
		$alt_text = sanitize_text_field( $input['alt_text'] ?? '' );

		$file_array = [
			'name'     => $base_name . '.' . $extension,
			'type'     => $mime_type,
			'tmp_name' => $temp_file,
		];

		$attachment_id = media_handle_sideload(
			$file_array,
			$post_id,
			$title ?: $description,
			[
				'post_title'     => $title,
				'post_content'   => $description,
				'post_excerpt'   => $caption,
				'post_mime_type' => $mime_type,
				'meta_input'     => [
					'_wp_attachment_image_alt' => $alt_text,
				],
			]
		);

		// Clean up temp file if still present.
		if ( file_exists( $temp_file ) ) {
			wp_delete_file( $temp_file );
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$attachment    = get_post( $attachment_id );
		$attached_file = get_attached_file( $attachment_id );

		return [
			'attachment_id' => $attachment_id,
			'url'           => wp_get_attachment_url( $attachment_id ) ?: '',
			'filename'      => $attached_file ? basename( $attached_file ) : '',
			'title'         => $attachment ? $attachment->post_title : $title,
			'description'   => $attachment ? $attachment->post_content : $description,
			'alt_text'      => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'mime_type'     => $attachment ? $attachment->post_mime_type : $mime_type,
		];
	}

	// ─── Shared helpers ──────────────────────────────────────────────────────

	/**
	 * Permission callback: requires upload_files capability.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $input Input args (unused).
	 * @return bool|\WP_Error
	 */
	public static function permission_upload_files( $input ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'insufficient_capabilities',
				__( 'You do not have permission to use AI image abilities.', 'superdav-ai-agent' )
			);
		}
		return true;
	}

	/**
	 * Permission callback: requires edit_posts capability.
	 *
	 * When $input['context'] is a numeric post ID, also performs an object-level
	 * check via current_user_can( 'edit_post', $post_id ) to prevent one author
	 * from accessing another user's draft or private post content.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $input Input args.
	 * @return bool|\WP_Error
	 */
	public static function permission_edit_posts( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'insufficient_capabilities',
				__( 'You do not have permission to use AI image abilities.', 'superdav-ai-agent' )
			);
		}

		if ( is_array( $input ) && isset( $input['context'] ) && is_numeric( $input['context'] ) ) {
			$post_id = (int) $input['context'];
			if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error(
					'insufficient_capabilities',
					__( 'You do not have permission to use that post as AI image context.', 'superdav-ai-agent' )
				);
			}
		}

		return true;
	}

	/**
	 * Resolve an image to a data URI string for use with wp_ai_client_prompt()->with_file().
	 *
	 * Accepts attachment_id or image_url (URL or data URI).
	 *
	 * @since 1.1.0
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return string|\WP_Error Data URI string or WP_Error.
	 */
	private static function resolve_image_reference( array $input ) {
		if ( ! empty( $input['attachment_id'] ) ) {
			// @phpstan-ignore-next-line
			return self::attachment_to_data_uri( absint( $input['attachment_id'] ) );
		}

		if ( ! empty( $input['image_url'] ) ) {
			$url = $input['image_url'];

			// Data URI — pass through directly.
			// @phpstan-ignore-next-line
			if ( str_starts_with( $url, 'data:' ) ) {
				// @phpstan-ignore-next-line
				return $url;
			}

			// Try to map to a local file first.
			// @phpstan-ignore-next-line
			$local_path = self::url_to_local_path( $url );
			if ( $local_path ) {
				$data_uri = self::file_to_data_uri( $local_path );
				if ( $data_uri ) {
					return $data_uri;
				}
			}

			// Download remote image.
			// @phpstan-ignore-next-line
			return self::download_to_data_uri( $url );
		}

		return new WP_Error( 'no_image_provided', __( 'Either attachment_id or image_url must be provided.', 'superdav-ai-agent' ) );
	}

	/**
	 * Convert a WordPress attachment to a data URI.
	 *
	 * @since 1.1.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|\WP_Error Data URI or WP_Error.
	 */
	private static function attachment_to_data_uri( int $attachment_id ) {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'invalid_attachment',
				/* translators: %d: Attachment ID. */
				sprintf( __( 'Attachment with ID %d not found.', 'superdav-ai-agent' ), $attachment_id )
			);
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error( 'not_an_image', __( 'The specified attachment is not an image.', 'superdav-ai-agent' ) );
		}

		$file_path = get_attached_file( $attachment_id );
		if ( $file_path && file_exists( $file_path ) ) {
			$data_uri = self::file_to_data_uri( $file_path );
			if ( $data_uri ) {
				return $data_uri;
			}
		}

		// Fall back to downloading from URL.
		$image_src = wp_get_attachment_image_src( $attachment_id, 'large' )
			?: wp_get_attachment_image_src( $attachment_id, 'full' );

		if ( ! $image_src || empty( $image_src[0] ) ) {
			return new WP_Error( 'image_url_not_found', __( 'Could not retrieve image URL from attachment.', 'superdav-ai-agent' ) );
		}

		return self::download_to_data_uri( $image_src[0] );
	}

	/**
	 * Convert a file path to a base64 data URI.
	 *
	 * @since 1.1.0
	 *
	 * @param string $file_path Absolute file path.
	 * @return string|null Data URI or null on failure.
	 */
	private static function file_to_data_uri( string $file_path ): ?string {
		$mime_type = wp_check_filetype( $file_path )['type'] ?? '';
		if ( empty( $mime_type ) ) {
			return null;
		}

		$contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
		if ( false === $contents ) {
			return null;
		}

		return 'data:' . $mime_type . ';base64,' . base64_encode( $contents ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding image binary for data URI, not obfuscation
	}

	/**
	 * Download a remote image URL and return it as a data URI.
	 *
	 * @since 1.1.0
	 *
	 * @param string $url Remote image URL.
	 * @return string|\WP_Error Data URI or WP_Error.
	 */
	private static function download_to_data_uri( string $url ) {
		$temp_file = SafeHttpClient::instance()->safe_download_url( $url );
		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		$data_uri = self::file_to_data_uri( $temp_file );
		wp_delete_file( $temp_file );

		if ( ! $data_uri ) {
			return new WP_Error( 'file_read_error', __( 'Could not read the downloaded image file.', 'superdav-ai-agent' ) );
		}

		return $data_uri;
	}

	/**
	 * Attempt to map an uploads URL to a local filesystem path.
	 *
	 * @since 1.1.0
	 *
	 * @param string $url URL to map.
	 * @return string|null Local path or null if not mappable.
	 */
	private static function url_to_local_path( string $url ): ?string {
		$uploads = wp_get_upload_dir();

		if ( empty( $uploads['baseurl'] ) || empty( $uploads['basedir'] ) ) {
			return null;
		}

		// Normalise both URLs by stripping scheme and trailing slashes.
		$strip_scheme = static fn( string $u ) => rtrim( (string) preg_replace( '#^https?://#i', '', $u ), '/' );

		$norm_url     = $strip_scheme( $url );
		$norm_baseurl = $strip_scheme( $uploads['baseurl'] );

		if ( ! str_starts_with( $norm_url, $norm_baseurl ) ) {
			return null;
		}

		$relative = ltrim( substr( $norm_url, strlen( $norm_baseurl ) ), '/' );
		if ( '' === $relative || str_contains( $relative, '..' ) ) {
			return null;
		}

		$base_dir  = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
		$full_path = $base_dir . $relative;
		$real_path = realpath( $full_path );

		if ( false === $real_path ) {
			return null;
		}

		$real_path = wp_normalize_path( $real_path );

		// Ensure resolved path is within the uploads directory.
		if ( ! str_starts_with( $real_path, $base_dir ) ) {
			return null;
		}

		return ( file_exists( $real_path ) && is_file( $real_path ) ) ? $real_path : null;
	}

	/**
	 * Detect MIME type from binary data using magic bytes.
	 *
	 * @since 1.1.0
	 *
	 * @param string $data Binary image data.
	 * @return string MIME type or empty string if unrecognised.
	 */
	private static function detect_mime_type_from_binary( string $data ): string {
		// JPEG: FF D8 FF
		if ( str_starts_with( $data, "\xFF\xD8\xFF" ) ) {
			return 'image/jpeg';
		}
		// PNG: 89 50 4E 47 0D 0A 1A 0A
		if ( str_starts_with( $data, "\x89PNG\r\n\x1A\n" ) ) {
			return 'image/png';
		}
		// GIF: GIF87a or GIF89a
		if ( str_starts_with( $data, 'GIF87a' ) || str_starts_with( $data, 'GIF89a' ) ) {
			return 'image/gif';
		}
		// WebP: RIFF....WEBP
		if ( strlen( $data ) >= 12 && str_starts_with( $data, 'RIFF' ) && substr( $data, 8, 4 ) === 'WEBP' ) {
			return 'image/webp';
		}
		// AVIF / HEIC: ftyp box
		if ( strlen( $data ) >= 12 && substr( $data, 4, 4 ) === 'ftyp' ) {
			$brand = substr( $data, 8, 4 );
			if ( in_array( $brand, [ 'avif', 'avis', 'heic', 'heix', 'mif1', 'msf1' ], true ) ) {
				return str_starts_with( $brand, 'hei' ) ? 'image/heic' : 'image/avif';
			}
		}
		return '';
	}

	/**
	 * Get the configured model ID from plugin settings.
	 *
	 * @since 1.1.0
	 *
	 * @return string Model ID or empty string.
	 */
	private static function get_configured_model(): string {
		if ( class_exists( \SdAiAgent\Core\Settings::class ) ) {
			$model = \SdAiAgent\Core\Settings::instance()->get( 'default_model' );
			return is_string( $model ) ? $model : '';
		}
		return '';
	}
}
