<?php

declare(strict_types=1);
/**
 * Openverse image source implementation.
 *
 * Openverse (WordPress.org) provides CC0/CC-BY images from Flickr,
 * Wikimedia, NASA, SpaceX, and other openly-licensed sources.
 * No API key required - free to use.
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
 * Openverse image source.
 *
 * @since 1.5.0
 */
class OpenverseImageSource implements ImageSourceInterface {

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private const API_BASE = 'https://api.openverse.org/v1';

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'openverse';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return 'Openverse';
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_available(): bool {
		// Openverse is always available - no API key needed.
		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string               $keyword  Search term.
	 * @param int                  $per_page Number of results to return.
	 * @param array<string, mixed> $filters  Optional filters (orientation, colour).
	 * @return array{hits: array<int, array<string, mixed>>, total: int, source: string}|\WP_Error
	 */
	public function search( string $keyword, int $per_page = 10, array $filters = [] ): array|\WP_Error {
		$query_args = [
			'q'         => $keyword,
			'page_size' => min( $per_page, 50 ),
		];

		// Orientation: Openverse uses tall|wide|square.
		$orientation = (string) ( $filters['orientation'] ?? '' );
		if ( '' !== $orientation ) {
			$orientation_map            = [
				'portrait'  => 'tall',
				'landscape' => 'wide',
				'squarish'  => 'square',
			];
			$query_args['aspect_ratio'] = $orientation_map[ $orientation ] ?? $orientation;
		}

		// Colour filter.
		$colour = (string) ( $filters['colour'] ?? '' );
		if ( '' !== $colour ) {
			$query_args['color'] = $colour;
		}

		$url = add_query_arg( $query_args, self::API_BASE . '/images/' );

		$response = wp_remote_get( $url, [ 'timeout' => 30 ] );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'openverse_error', 'Failed to search Openverse: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'openverse_error', "Openverse API returned status {$code}" );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['results'] ) ) {
			return [
				'hits'   => [],
				'total'  => 0,
				'source' => 'openverse',
			];
		}

		$hits = array_map(
			static function ( array $item ): array {
				// Build URLs - API returns 'url' for original, 'thumbnail' for preview.
				$full    = $item['url'] ?? '';
				$medium  = $item['thumbnail'] ?? '';
				$preview = $item['thumbnail'] ?? $full;

				return [
					'id'          => $item['id'],
					'preview'     => $preview,
					'medium'      => $medium,
					'full'        => $full,
					'width'       => $item['width'] ?? 0,
					'height'      => $item['height'] ?? 0,
					'title'       => $item['title'] ?? '',
					'author'      => $item['creator'] ?? '',
					'author_url'  => $item['creator_url'] ?? '',
					'license'     => $item['license'] ?? '',
					'license_url' => $item['license_url'] ?? '',
					'source'      => $item['source'] ?? $item['provider'] ?? 'openverse',
					'attribution' => $item['attribution'] ?? '',
				];
			},
			$body['results']
		);

		return [
			'hits'   => $hits,
			'total'  => $body['result_count'] ?? count( $hits ),
			'source' => 'openverse',
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_image( string $image_id ): array|\WP_Error {
		$url = self::API_BASE . '/images/' . $image_id . '/';

		$response = wp_remote_get( $url, [ 'timeout' => 20 ] );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'openverse_error', 'Failed to get image: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'openverse_error', "Openverse API returned status {$code}" );
		}

		$item = json_decode( wp_remote_retrieve_body( $response ), true );

		return [
			'url'        => $item['url'] ?? '',
			'width'      => $item['width'] ?? 0,
			'height'     => $item['height'] ?? 0,
			'author'     => $item['creator'] ?? '',
			'author_url' => $item['creator_url'] ?? '',
			'license'    => $item['license'] ?? '',
			'source'     => $item['source'] ?? $item['provider'] ?? 'openverse',
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
			return new WP_Error( 'openverse_error', 'No image URL available.' );
		}

		// Use WordPress download to temp file.
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
}
