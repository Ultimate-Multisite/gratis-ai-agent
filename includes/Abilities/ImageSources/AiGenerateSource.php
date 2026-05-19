<?php

declare(strict_types=1);
/**
 * AI Image Generation source using the WordPress AI Client SDK.
 *
 * Uses wp_ai_client_prompt()->generate_image() so any provider configured
 * in WordPress core Settings > AI that supports image generation will be used.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities\ImageSources;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Image Generation source via the WP AI Client SDK.
 *
 * @since 1.5.0
 */
class AiGenerateSource implements ImageSourceInterface {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'generate';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return 'AI Generate';
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_available(): bool {
		return function_exists( 'wp_ai_client_prompt' )
			&& wp_ai_client_prompt()->is_supported_for_image_generation();
	}

	/**
	 * {@inheritdoc}
	 *
	 * For AI generation, search returns a single synthetic hit used to
	 * trigger generation via download(). Filters are not applicable here.
	 *
	 * @param string               $keyword  Search term (used as generation prompt).
	 * @param int                  $per_page Number of results (always 1 for generation).
	 * @param array<string, mixed> $filters  Not used; accepted for interface compliance.
	 * @return array{hits: array<int, array<string, mixed>>, total: int, source: string}
	 */
	public function search( string $keyword, int $per_page = 10, array $filters = [] ): array|\WP_Error {
		return [
			'hits'   => [
				[
					'id'      => 'generate:' . rawurlencode( $keyword ),
					'preview' => '',
					'prompt'  => $keyword,
					'source'  => 'generate',
				],
			],
			'total'  => 1,
			'source' => 'generate',
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_image( string $image_id ): array|\WP_Error {
		return new WP_Error( 'not_applicable', 'Use download() for AI generation.' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * Generates an image via the WP AI Client SDK and returns a local temp file path.
	 */
	public function download( string $prompt, int $width = 0, int $height = 0 ): string|\WP_Error {
		// Strip the generate: prefix if present (from search() synthetic hit).
		if ( str_starts_with( $prompt, 'generate:' ) ) {
			$prompt = rawurldecode( substr( $prompt, 9 ) );
		}

		if ( empty( $prompt ) ) {
			return new WP_Error( 'missing_prompt', 'Prompt is required for image generation.' );
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error( 'no_ai_client', 'wp_ai_client_prompt() is not available.' );
		}

		$file = wp_ai_client_prompt( $prompt )->generate_image();

		if ( is_wp_error( $file ) ) {
			return new WP_Error( 'generation_failed', $file->get_error_message() );
		}

		return $this->file_to_temp( $file );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_cost_type(): string {
		return 'api';
	}

	/**
	 * Save an AI SDK File object to a local temp file.
	 *
	 * @param mixed $file File object returned by generate_image().
	 * @return string|\WP_Error Temp file path or WP_Error.
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

		// Inline base64 — write directly to temp file.
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
}
