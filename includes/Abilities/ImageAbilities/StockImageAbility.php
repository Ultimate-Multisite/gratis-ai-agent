<?php

declare(strict_types=1);
/**
 * Stock image ability — search candidates and/or import free stock photos.
 *
 * Supports two actions:
 * - search: Returns a list of candidate stock images (no import). Use when
 *   presenting choices to the user before deciding which image to import.
 * - import: Downloads and imports a specific image (by provider + image_id)
 *   or auto-picks the first viable hit from any available free source.
 *
 * Never falls back to AI generation. Use sd-ai-agent/generate-image for that.
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
				'description'   => __( 'Search for free stock photos (Openverse CC0 or Pixabay) and optionally import a selected result into the media library. Use action=search to get candidates with thumbnails and attribution, then action=import to import a specific one. Returns attachment ID and local URL after import.', 'superdav-ai-agent' ),
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
		return __( 'Search for free stock photos (Openverse CC0 or Pixabay) and optionally import a selected result into the media library. Use action=search to get candidates with thumbnails and attribution, then action=import to import a specific one. Returns attachment ID and local URL after import.', 'superdav-ai-agent' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'keyword'     => [
					'type'        => 'string',
					'description' => 'Search term for finding a relevant stock photo (e.g. "mountain landscape", "coffee shop", "team meeting").',
				],
				'action'      => [
					'type'        => 'string',
					'enum'        => [ 'search', 'import' ],
					'description' => 'Use "search" to retrieve a list of candidate images with thumbnails and attribution (no import). Use "import" to download and add a specific image to the media library. If omitted, the first available result is automatically imported (backward-compatible).',
				],
				'image_id'    => [
					'type'        => 'string',
					'description' => 'Provider image ID returned by a previous search. Required when action=import with a specific provider.',
				],
				'provider'    => [
					'type'        => 'string',
					'enum'        => [ 'openverse', 'pixabay' ],
					'description' => 'Restrict search or import to a specific provider. When action=import, this identifies which provider image_id belongs to.',
				],
				'limit'       => [
					'type'        => 'integer',
					'description' => 'Maximum number of candidates to return in search mode (default: 5, max: 20).',
				],
				'orientation' => [
					'type'        => 'string',
					'enum'        => [ 'landscape', 'portrait', 'squarish' ],
					'description' => 'Preferred image orientation.',
				],
				'colour'      => [
					'type'        => 'string',
					'description' => 'Dominant colour filter (e.g. "blue", "green", "red"). Provider-specific; not all providers support every colour.',
				],
				'min_width'   => [
					'type'        => 'integer',
					'description' => 'Minimum image width in pixels.',
				],
				'min_height'  => [
					'type'        => 'integer',
					'description' => 'Minimum image height in pixels.',
				],
				'width'       => [
					'type'        => 'integer',
					'description' => 'Desired image width in pixels for import (default: 1200).',
				],
				'height'      => [
					'type'        => 'integer',
					'description' => 'Desired image height in pixels for import (default: 800).',
				],
				'site_url'    => [
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
				// Search-mode output.
				'candidates'    => [
					'type'        => 'array',
					'description' => 'List of candidate images returned in search mode.',
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'image_id'    => [ 'type' => 'string' ],
							'provider'    => [ 'type' => 'string' ],
							'thumbnail'   => [ 'type' => 'string' ],
							'width'       => [ 'type' => 'integer' ],
							'height'      => [ 'type' => 'integer' ],
							'licence'     => [ 'type' => 'string' ],
							'attribution' => [ 'type' => 'string' ],
							'title'       => [ 'type' => 'string' ],
						],
					],
				],
				'total'         => [ 'type' => 'integer' ],
				// Import-mode output.
				'attachment_id' => [ 'type' => 'integer' ],
				'url'           => [ 'type' => 'string' ],
				'alt'           => [ 'type' => 'string' ],
				'title'         => [ 'type' => 'string' ],
				'source'        => [ 'type' => 'string' ],
				'attribution'   => [ 'type' => 'string' ],
				// Shared.
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
		$action   = sanitize_key( $input['action'] ?? '' );
		$image_id = sanitize_text_field( $input['image_id'] ?? '' );
		$provider = sanitize_key( $input['provider'] ?? '' );
		$limit    = min( (int) ( $input['limit'] ?? 5 ), 20 );
		$width    = (int) ( $input['width'] ?? 1200 );
		$height   = (int) ( $input['height'] ?? 800 );
		$site_url = sanitize_text_field( $input['site_url'] ?? '' );

		if ( empty( $keyword ) ) {
			return new WP_Error( 'missing_keyword', 'keyword is required.' );
		}

		$filters = array_filter(
			[
				'orientation' => sanitize_key( $input['orientation'] ?? '' ),
				'colour'      => sanitize_text_field( $input['colour'] ?? '' ),
				'min_width'   => (int) ( $input['min_width'] ?? 0 ),
				'min_height'  => (int) ( $input['min_height'] ?? 0 ),
			],
			static fn( mixed $v ): bool => ( is_int( $v ) ? $v > 0 : '' !== $v )
		);

		// ── action=search: return candidates without importing ────────────────
		if ( 'search' === $action ) {
			return $this->handle_search( $keyword, $limit, $provider, $filters );
		}

		// ── action=import with specific provider + image_id ───────────────────
		if ( 'import' === $action && '' !== $image_id && '' !== $provider ) {
			return $this->handle_import_by_id( $keyword, $provider, $image_id, $width, $height, $site_url );
		}

		// ── Default (auto) / action=import without image_id: original behavior ─
		// Verify at least one free source is configured before attempting import.
		$has_free = false;
		foreach ( ImageSourceFactory::get_available() as $s ) {
			if ( 'free' === $s->get_cost_type() ) {
				$has_free = true;
				break;
			}
		}

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
				'attribution'   => '',
				'error'         => 'No free stock image source is available. Configure Openverse or Pixabay.',
			];
			if ( $can_generate ) {
				$response['tip'] = $generate_tip;
			}
			return $response;
		}

		$options = [
			'site_url'             => $site_url,
			'no_generate_fallback' => true,
			'filters'              => $filters,
		];

		$result = ImageSourceFactory::import_image( $keyword, '', $width, $height, $options );

		if ( is_wp_error( $result ) ) {
			$response = [
				'attachment_id' => 0,
				'url'           => '',
				'alt'           => '',
				'title'         => '',
				'source'        => '',
				'attribution'   => '',
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

	/**
	 * Return candidate images without importing.
	 *
	 * Always returns an array: on factory error, the WP_Error is converted to
	 * an array with an 'error' key so the agent loop can surface the message.
	 *
	 * @param string               $keyword  Search keyword.
	 * @param int                  $limit    Maximum candidates.
	 * @param string               $provider Provider restriction (empty = all free sources).
	 * @param array<string, mixed> $filters  Optional search filters.
	 * @return array<string, mixed> Candidates array (with optional 'error' key on failure).
	 */
	private function handle_search(
		string $keyword,
		int $limit,
		string $provider,
		array $filters
	): array {
		$result = ImageSourceFactory::search_candidates( $keyword, $limit, $provider, $filters );

		if ( is_wp_error( $result ) ) {
			return [
				'candidates' => [],
				'total'      => 0,
				'error'      => $result->get_error_message(),
				'tip'        => 'Call again with action=import and image_id + provider to import a specific image.',
			];
		}

		$result['tip'] = 'Present these candidates to the user, then call again with action=import and image_id + provider to import the selected image.';

		return $result;
	}

	/**
	 * Import a specific image by provider and image ID.
	 *
	 * Always returns an array: on factory error, the WP_Error is converted to
	 * an array with an 'error' key so the agent loop can surface the message.
	 *
	 * @param string $keyword   Original search keyword (used as alt/title base).
	 * @param string $provider  Provider ID.
	 * @param string $image_id  Provider-specific image ID.
	 * @param int    $width     Desired width.
	 * @param int    $height    Desired height.
	 * @param string $site_url  Multisite subsite URL.
	 * @return array<string, mixed> Result (with optional 'error' key on failure).
	 */
	private function handle_import_by_id(
		string $keyword,
		string $provider,
		string $image_id,
		int $width,
		int $height,
		string $site_url
	): array {
		$options = [
			'site_url' => $site_url,
			'keyword'  => $keyword,
		];

		$result = ImageSourceFactory::import_by_provider_id( $provider, $image_id, $width, $height, $options );

		if ( is_wp_error( $result ) ) {
			$can_generate = function_exists( 'wp_ai_client_prompt' )
				&& wp_ai_client_prompt()->is_supported_for_image_generation();

			$response = [
				'attachment_id' => 0,
				'url'           => '',
				'alt'           => '',
				'title'         => '',
				'source'        => '',
				'attribution'   => '',
				'error'         => $result->get_error_message(),
			];
			if ( $can_generate ) {
				$response['tip'] = 'Use sd-ai-agent/generate-image to create an AI-generated image instead.';
			}
			return $response;
		}

		$result['tip'] = 'Use attachment_id as featured_image_id when calling create-post or update-post.';

		return $result;
	}
}
