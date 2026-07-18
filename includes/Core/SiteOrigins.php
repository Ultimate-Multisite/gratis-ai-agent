<?php

declare(strict_types=1);
/**
 * Site origin helpers for browser-executed abilities.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SiteOrigins {

	/**
	 * Maximum network sites included in the browser hint payload.
	 */
	private const MAX_NETWORK_SITES = 100;

	/**
	 * Return origins that belong to this WordPress site/network.
	 *
	 * The browser screenshot ability still cannot iframe and inspect a different
	 * origin. This list lets the client distinguish an unknown third-party URL
	 * from a valid multisite subsite and return actionable same-origin guidance.
	 *
	 * @return string[] Origin strings such as https://example.com.
	 */
	public static function screenshot_allowed_origins(): array {
		$origins = array();

		self::add_origin( $origins, home_url( '/' ) );
		self::add_origin( $origins, site_url( '/' ) );
		self::add_origin( $origins, admin_url() );

		if ( is_multisite() && function_exists( 'get_sites' ) ) {
			$sites = get_sites(
				array(
					'number' => self::MAX_NETWORK_SITES,
					'fields' => 'ids',
				)
			);

			foreach ( $sites as $site_id ) {
				$site_id = (int) $site_id;
				if ( $site_id <= 0 ) {
					continue;
				}

				self::add_origin( $origins, get_home_url( $site_id, '/' ) );
				self::add_origin( $origins, get_site_url( $site_id, '/' ) );
			}
		}

		return array_values( array_unique( $origins ) );
	}

	/**
	 * Add a parsed origin from a URL to the accumulator.
	 *
	 * @param string[] $origins Origin accumulator.
	 * @param string   $url     URL to parse.
	 * @return void
	 */
	private static function add_origin( array &$origins, string $url ): void {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );
		$port   = wp_parse_url( $url, PHP_URL_PORT );

		if ( ! is_string( $scheme ) || '' === $scheme || ! is_string( $host ) || '' === $host ) {
			return;
		}

		$origin = strtolower( $scheme ) . '://' . strtolower( $host );
		if ( is_int( $port ) && ! in_array( $port, array( 80, 443 ), true ) ) {
			$origin .= ':' . $port;
		}

		$origins[] = $origin;
	}
}
