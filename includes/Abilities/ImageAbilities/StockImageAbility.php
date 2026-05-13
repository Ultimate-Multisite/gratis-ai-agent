<?php

declare(strict_types=1);
/**
 * Stock image ability — search and import free stock photos.
 *
 * Searches Openverse (CC0) or Pixabay for a keyword and imports the result
 * into the WordPress media library. Never falls back to AI generation.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities\ImageAbilities;

use SdAiAgent\Abilities\ImageSources\ImageSourceFactory;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Searches free stock image APIs and imports the result into WordPress.
 *
 * @since 1.6.0
 */
class StockImageAbility extends \SdAiAgent\Abilities\AbstractAbility {

	/**
	 * Register this ability.
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/stock-image',
			[
				'label'         => __( 'Stock Image', 'superdav-ai-agent' ),
				'description'   => __( 'Search for a free stock photo by keyword (Openverse CC0 or Pixabay) and import it into the media library. Returns attachment ID and URL. Use this when you need a real photograph or illustration from existing stock libraries.', 'superdav-ai-agent' ),
				'ability_class' => self::class,
			]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function label(): string {
		return __( 'Stock Image', 'superdav-ai-agent' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function description(): string {
		return __( 'Search for a free stock photo by keyword (Openverse CC0 or Pixabay) and import it into the media library. Returns attachment ID and URL. Use this when you need a real photograph or illustration from existing stock libraries.', 'superdav-ai-agent' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'keyword'  => [
					'type'        => 'string',
					'description' => 'Search term for finding a relevant stock photo (e.g. "mountain landscape", "coffee shop", "team meeting").',
				],
				'width'    => [
					'type'        => 'integer',
					'description' => 'Desired image width in pixels (default: 1200).',
				],
				'height'   => [
					'type'        => 'integer',
					'description' => 'Desired image height in pixels (default: 800).',
				],
				'site_url' => [
					'type'        => 'string',
					'description' => 'Subsite URL to import into on multisite (e.g. "https://example.com/mysite"). Omit for the main site.',
				],
			],
			'required'   => [ 'keyword' ],
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
				'alt'           => [ 'type' => 'string' ],
				'title'         => [ 'type' => 'string' ],
				'source'        => [ 'type' => 'string' ],
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
		$keyword  = sanitize_text_field( $input['keyword'] ?? '' );
		$width    = (int) ( $input['width'] ?? 1200 );
		$height   = (int) ( $input['height'] ?? 800 );
		$site_url = sanitize_text_field( $input['site_url'] ?? '' );

		if ( empty( $keyword ) ) {
			return new WP_Error( 'missing_keyword', 'keyword is required.' );
		}

		// Verify at least one free source is configured before attempting import.
		$has_free = false;
		foreach ( ImageSourceFactory::get_available() as $s ) {
			if ( 'free' === $s->get_cost_type() ) {
				$has_free = true;
				break;
			}
		}

		// Only suggest the AI-generation fallback when an image-capable
		// provider is actually configured; otherwise the tip points at a
		// dead end. Mirrors the gate used inside GenerateImageAbility itself.
		$can_generate = function_exists( 'wp_ai_client_prompt' )
			&& wp_ai_client_prompt()->is_supported_for_image_generation();
		$generate_tip = 'Use sd-ai-agent/generate-image to create an AI-generated image instead.';

		if ( ! $has_free ) {
			$response = [
				'attachment_id' => 0,
				'url'           => '',
				'alt'           => '',
				'title'         => '',
				'source'        => '',
				'error'         => 'No free stock image source is available. Configure Openverse or Pixabay.',
			];
			if ( $can_generate ) {
				$response['tip'] = $generate_tip;
			}
			return $response;
		}

		// Let the factory try all available free sources in priority order
		// (openverse → pixabay) before giving up. Never fall back to AI generation —
		// this ability is explicitly for stock images only.
		$options = [
			'site_url'             => $site_url,
			'no_generate_fallback' => true,
		];

		$result = ImageSourceFactory::import_image( $keyword, '', $width, $height, $options );

		if ( is_wp_error( $result ) ) {
			$response = [
				'attachment_id' => 0,
				'url'           => '',
				'alt'           => '',
				'title'         => '',
				'source'        => '',
				// Error message from the factory lists each source tried and why it failed.
				'error'         => $result->get_error_message(),
			];
			if ( $can_generate ) {
				$response['tip'] = $generate_tip;
			}
			return $response;
		}

		$result['tip'] = 'Use attachment_id as featured_image_id when calling create-post or update-post.';

		return $result;
	}
}
