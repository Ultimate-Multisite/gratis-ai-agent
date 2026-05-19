<?php

declare(strict_types=1);
/**
 * Pixabay image source implementation.
 *
 * Pixabay provides 2.7M+ free images and videos under CC0 license.
 * Requires free API key - free for commercial use, no attribution required.
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
 * Pixabay image source.
 *
 * @since 1.5.0
 */
class PixabayImageSource implements ImageSourceInterface {

	/**
	 * Option key for storing API key.
	 *
	 * @var string
	 */
	private const API_KEY_OPTION = 'sd_ai_agent_pixabay_api_key';

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private const API_BASE = 'https://pixabay.com/api';

	/**
	 * Get stored API key.
	 *
	 * @return string
	 */
	private function get_api_key(): string {
		return get_option( self::API_KEY_OPTION, '' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'pixabay';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return 'Pixabay';
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_available(): bool {
		$key = $this->get_api_key();
		return ! empty( $key );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string               $keyword  Search term.
	 * @param int                  $per_page Number of results to return.
	 * @param array<string, mixed> $filters  Optional filters (orientation, colour, min_width, min_height).
	 * @return array{hits: array<int, array<string, mixed>>, total: int, source: string}|\WP_Error
	 */
	public function search( string $keyword, int $per_page = 10, array $filters = [] ): array|\WP_Error {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error( 'pixabay_not_configured', 'Pixabay API key not configured.' );
		}

		$query_args = [
			'key'         => $api_key,
			'q'           => rawurlencode( $keyword ),
			'per_page'    => min( $per_page, 200 ),
			'image_type'  => 'photo',
			'safe_search' => 'true',
		];

		// Orientation: Pixabay uses horizontal|vertical.
		$orientation = (string) ( $filters['orientation'] ?? '' );
		if ( '' !== $orientation ) {
			$orientation_map = [
				'landscape' => 'horizontal',
				'portrait'  => 'vertical',
			];
			if ( isset( $orientation_map[ $orientation ] ) ) {
				$query_args['orientation'] = $orientation_map[ $orientation ];
			}
		}

		// Colour filter (Pixabay uses 'colors' parameter).
		$colour = (string) ( $filters['colour'] ?? '' );
		if ( '' !== $colour ) {
			$query_args['colors'] = $colour;
		}

		// Minimum dimensions.
		$min_width = (int) ( $filters['min_width'] ?? 0 );
		if ( $min_width > 0 ) {
			$query_args['min_width'] = $min_width;
		}

		$min_height = (int) ( $filters['min_height'] ?? 0 );
		if ( $min_height > 0 ) {
			$query_args['min_height'] = $min_height;
		}

		$url = add_query_arg( $query_args, self::API_BASE );

		$response = wp_remote_get( $url, [ 'timeout' => 30 ] );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'pixabay_error', 'Failed to search Pixabay: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'pixabay_error', "Pixabay API returned status {$code}" );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['hits'] ) ) {
			return [
				'hits'   => [],
				'total'  => 0,
				'source' => 'pixabay',
			];
		}

		$hits = array_map(
			static function ( array $item ): array {
				return [
					'id'         => (string) $item['id'],
					'preview'    => $item['webformatURL'] ?? '',
					'medium'     => $item['largeImageURL'] ?? $item['webformatURL'] ?? '',
					'full'       => $item['largeImageURL'] ?? '',
					'width'      => $item['imageWidth'] ?? 0,
					'height'     => $item['imageHeight'] ?? 0,
					'title'      => $item['tags'] ?? '',
					'author'     => $item['user'] ?? '',
					'author_url' => 'https://pixabay.com/users/' . ( $item['user'] ?? '' ),
					'license'    => 'CC0',
					'source'     => 'pixabay',
				];
			},
			$body['hits']
		);

		return [
			'hits'   => $hits,
			'total'  => $body['totalHits'] ?? count( $hits ),
			'source' => 'pixabay',
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_image( string $image_id ): array|\WP_Error {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error( 'pixabay_not_configured', 'Pixabay API key not configured.' );
		}

		$url = add_query_arg(
			[
				'key' => $api_key,
				'id'  => $image_id,
			],
			self::API_BASE
		);

		$response = wp_remote_get( $url, [ 'timeout' => 20 ] );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'pixabay_error', 'Failed to get image: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'pixabay_error', "Pixabay API returned status {$code}" );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['hits'] ) ) {
			return new WP_Error( 'pixabay_not_found', 'Image not found.' );
		}

		$item = $body['hits'][0];

		return [
			'url'        => $item['largeImageURL'] ?? '',
			'width'      => $item['imageWidth'] ?? 0,
			'height'     => $item['imageHeight'] ?? 0,
			'author'     => $item['user'] ?? '',
			'author_url' => 'https://pixabay.com/users/' . ( $item['user'] ?? '' ),
			'license'    => 'CC0',
			'source'     => 'pixabay',
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function download( string $image_id, int $width = 0, int $height = 0 ): string|\WP_Error {
		$image = $this->get_image( $image_id );

		if ( is_wp_error( $image ) ) {
			return $image;
		}

		$image_url = $image['url'];

		if ( empty( $image_url ) ) {
			return new WP_Error( 'pixabay_error', 'No image URL available.' );
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp_file = download_url( $image_url, 60 );

		if ( is_wp_error( $tmp_file ) ) {
			return new WP_Error( 'download_error', 'Failed to download image: ' . $tmp_file->get_error_message() );
		}

		return $tmp_file;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_cost_type(): string {
		return 'free';
	}

	/**
	 * Save the API key.
	 *
	 * @param string $api_key API key.
	 * @return bool Success.
	 */
	public function save_api_key( string $api_key ): bool {
		return update_option( self::API_KEY_OPTION, $api_key );
	}
}
