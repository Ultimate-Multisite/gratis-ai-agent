<?php

declare(strict_types=1);

namespace SdAiAgent\Abilities\ImageSources;

use SdAiAgent\Core\Net\SafeHttpClient;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Wikimedia Commons source; no key is required and files carry explicit licences. */
class WikimediaImageSource implements ImageSourceInterface {
	private const API = 'https://commons.wikimedia.org/w/api.php';

	public function get_id(): string {
		return 'wikimedia'; }
	public function get_name(): string {
		return 'Wikimedia Commons'; }
	public function is_available(): bool {
		return true; }

	public function search( string $keyword, int $per_page = 10, array $filters = [] ): array|\WP_Error {
		$args     = array(
			'action'        => 'query',
			'format'        => 'json',
			'formatversion' => 2,
			'generator'     => 'search',
			'gsrsearch'     => $keyword,
			'gsrnamespace'  => 6,
			'gsrlimit'      => min( 20, max( 1, $per_page ) ),
			'prop'          => 'imageinfo',
			'iiprop'        => 'url|size|extmetadata',
			'iiurlwidth'    => 1600,
		);
		$response = SafeHttpClient::instance()->safe_remote_get( add_query_arg( $args, self::API ), array( 'timeout' => 30 ) );
		if ( is_wp_error( $response ) ) {
			return $response; }
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'wikimedia_error', 'Wikimedia API returned a non-success status.' ); }
		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$pages = $body['query']['pages'] ?? array();
		$hits  = array();
		foreach ( $pages as $page ) {
			$info = $page['imageinfo'][0] ?? array();
			$url  = (string) ( $info['url'] ?? '' );
			if ( '' === $url || empty( $info['width'] ) || empty( $info['height'] ) ) {
				continue; }
			$meta    = $info['extmetadata'] ?? array();
			$license = (string) ( $meta['LicenseShortName']['value'] ?? 'CC BY-SA' );
			$author  = wp_strip_all_tags( (string) ( $meta['Artist']['value'] ?? '' ) );
			$hits[]  = array(
				'id'          => (string) ( $page['title'] ?? '' ),
				'preview'     => (string) ( $info['thumburl'] ?? $url ),
				'medium'      => (string) ( $info['thumburl'] ?? $url ),
				'full'        => $url,
				'width'       => (int) $info['width'],
				'height'      => (int) $info['height'],
				'title'       => (string) ( $page['title'] ?? '' ),
				'author'      => $author,
				'author_url'  => '',
				'license'     => $license,
				'license_url' => 'https://creativecommons.org/licenses/',
				'source'      => 'wikimedia',
				'attribution' => trim( $author . ' / ' . $license ),
			);
		}
		return array(
			'hits'   => $hits,
			'total'  => count( $hits ),
			'source' => 'wikimedia',
		);
	}

	public function get_image( string $image_id ): array|\WP_Error {
		$args     = array(
			'action'        => 'query',
			'format'        => 'json',
			'formatversion' => 2,
			'titles'        => $image_id,
			'prop'          => 'imageinfo',
			'iiprop'        => 'url|size|extmetadata',
		);
		$response = SafeHttpClient::instance()->safe_remote_get( add_query_arg( $args, self::API ), array( 'timeout' => 30 ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'wikimedia_error', 'Failed to retrieve Wikimedia image.' ); }
		$page = ( json_decode( wp_remote_retrieve_body( $response ), true )['query']['pages'][0] ?? array() );
		$info = $page['imageinfo'][0] ?? array();
		if ( empty( $info['url'] ) ) {
			return new WP_Error( 'wikimedia_error', 'No Wikimedia image URL available.' ); }
		return array(
			'url'         => $info['url'],
			'width'       => (int) ( $info['width'] ?? 0 ),
			'height'      => (int) ( $info['height'] ?? 0 ),
			'title'       => $image_id,
			'author'      => '',
			'author_url'  => '',
			'license'     => 'CC BY-SA',
			'license_url' => 'https://creativecommons.org/licenses/',
			'attribution' => 'Wikimedia Commons',
			'source'      => 'wikimedia',
		);
	}

	public function download( string $image_id, int $width = 0, int $height = 0 ): string|\WP_Error {
		$image = $this->get_image( $image_id );
		if ( is_wp_error( $image ) ) {
			return $image; }
		return SafeHttpClient::instance()->safe_download_url( (string) $image['url'], 60 );
	}

	public function get_cost_type(): string {
		return 'free'; }
}
