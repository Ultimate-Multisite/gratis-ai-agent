<?php

declare(strict_types=1);
/**
 * Existing-site scraper for Theme Builder pre-fill data.
 *
 * @package SdAiAgent\Services
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches a small, polite crawl of an existing site and extracts brand data.
 */
class SiteScraper {

	private const CACHE_GROUP = 'sd_ai_agent_site_scrape_';

	/**
	 * Scrape a site into the Theme Builder pre-fill shape.
	 *
	 * @param string        $url          Absolute site URL.
	 * @param int           $max_pages    Maximum pages to crawl.
	 * @param array<string> $target_pages Optional explicit paths/URLs.
	 * @param string        $extract_mode structured_only, full_text, or auto.
	 * @return array<string,mixed>|WP_Error
	 */
	public function scrape( string $url, int $max_pages = 10, array $target_pages = [], string $extract_mode = 'auto' ): array|WP_Error {
		$url       = $this->normalize_url( $url );
		$max_pages = max( 1, min( 25, $max_pages ) );

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error(
				'sd_ai_agent_invalid_scrape_url',
				__( 'Site scrape requires a valid absolute HTTP or HTTPS URL.', 'superdav-ai-agent' )
			);
		}

		$cache_key = $this->cache_key( $url, $max_pages, $target_pages, $extract_mode );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $this->normalize_cached_result( $cached );
		}

		if ( ! $this->is_allowed_by_robots( $url ) ) {
			return new WP_Error(
				'sd_ai_agent_site_scrape_robots_disallowed',
				__( 'robots.txt disallows fetching this URL for the site-scrape ability.', 'superdav-ai-agent' )
			);
		}

		$result = $this->empty_result();
		$queue  = $this->initial_queue( $url, $target_pages );
		$seen   = [];

		$pages_count = count( $result['pages'] );
		while ( ! empty( $queue ) && $pages_count < $max_pages ) {
			$current = array_shift( $queue );
			if ( ! is_string( $current ) ) {
				continue;
			}

			$current = $this->normalize_url( $current );
			if ( '' === $current || isset( $seen[ $current ] ) || ! $this->same_origin( $url, $current ) ) {
				continue;
			}

			$seen[ $current ] = true;
			if ( ! $this->is_allowed_by_robots( $current ) ) {
				continue;
			}

			$response = $this->fetch( $current );
			if ( is_wp_error( $response ) ) {
				$errors           = is_array( $result['errors'] ) ? $result['errors'] : [];
				$errors[]         = [
					'url'     => $current,
					'code'    => $response->get_error_code(),
					'message' => $response->get_error_message(),
				];
				$result['errors'] = $errors;
				continue;
			}

			$page            = $this->parse_page( $current, $response );
			$page_text       = is_string( $page['text'] ?? null ) ? $page['text'] : '';
			$pages           = is_array( $result['pages'] ) ? $result['pages'] : [];
			$pages[]         = $page;
			$result['pages'] = $pages;
			$result          = $this->merge_extracted( $result, $this->extract_from_html( $current, $response, $page_text ) );
			$pages_count     = count( $pages );

			if ( empty( $target_pages ) ) {
				foreach ( $this->discover_links( $url, $response ) as $link ) {
					if ( count( $queue ) + $pages_count >= $max_pages ) {
						break;
					}
					$queue[] = $link;
				}
			}

			$this->rate_limit();
		}

		if ( 'structured_only' !== $extract_mode ) {
			$result = $this->maybe_ai_complete( $result );
		}

		$result['cached'] = false;
		set_transient( $cache_key, $result, DAY_IN_SECONDS );

		return $result;
	}

	/**
	 * Fetch one URL using WordPress' safe HTTP API.
	 */
	public function fetch( string $url ): string|WP_Error {
		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'     => 12,
				'redirection' => 3,
				'user-agent'  => $this->user_agent(),
				'headers'     => [
					'Accept' => 'text/html,application/xhtml+xml',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'sd_ai_agent_site_scrape_http_error',
				/* translators: %d: HTTP response status code */
				sprintf( __( 'The site returned HTTP %d while scraping.', 'superdav-ai-agent' ), $status )
			);
		}

		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( is_array( $content_type ) ) {
			$content_type = implode( ',', array_filter( $content_type, 'is_string' ) );
		}
		if ( '' !== $content_type && ! str_contains( strtolower( $content_type ), 'html' ) ) {
			return new WP_Error(
				'sd_ai_agent_site_scrape_not_html',
				__( 'The fetched URL did not return HTML.', 'superdav-ai-agent' )
			);
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Parse title, visible text, and headings from a page.
	 *
	 * @return array<string,mixed>
	 */
	public function parse_page( string $url, string $html ): array {
		$title    = $this->first_match( '/<title[^>]*>(.*?)<\/title>/is', $html );
		$headings = [];
		if ( preg_match_all( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $html, $matches ) ) {
			foreach ( $matches[1] as $heading ) {
				$clean = $this->clean_text( $heading );
				if ( '' !== $clean ) {
					$headings[] = $clean;
				}
			}
		}

		$text = preg_replace( '/<(script|style|noscript|svg)[\s\S]*?<\/\1>/i', ' ', $html );
		$text = $this->clean_text( (string) $text );

		return [
			'url'      => $url,
			'title'    => '' !== $title ? $this->clean_text( $title ) : null,
			'text'     => mb_substr( $text, 0, 12000 ),
			'headings' => $headings,
		];
	}

	/**
	 * Extract structured, OpenGraph, and heuristic data from HTML.
	 *
	 * @return array<string,mixed>
	 */
	public function extract_from_html( string $url, string $html, string $text = '' ): array {
		$result = $this->empty_result();
		$result = $this->merge_extracted( $result, $this->extract_json_ld( $html ) );
		$result = $this->merge_extracted( $result, $this->extract_meta( $html ) );
		$result = $this->merge_extracted( $result, $this->extract_logo( $url, $html ) );
		$result = $this->merge_extracted( $result, $this->extract_social_links( $html ) );
		$result = $this->merge_extracted( $result, $this->extract_heuristics( $text ) );

		return $result;
	}

	/**
	 * Return whether robots.txt permits this URL.
	 */
	public function is_allowed_by_robots( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		$robots_url = $parts['scheme'] . '://' . $parts['host'] . '/robots.txt';
		$response   = wp_safe_remote_get(
			$robots_url,
			[
				'timeout'    => 5,
				'user-agent' => $this->user_agent(),
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return true;
		}

		$path     = $parts['path'] ?? '/';
		$applies  = false;
		$disallow = [];
		foreach ( preg_split( '/\R/', (string) wp_remote_retrieve_body( $response ) ) ?: [] as $line ) {
			$line = trim( (string) preg_replace( '/#.*/', '', $line ) );
			if ( '' === $line || ! str_contains( $line, ':' ) ) {
				continue;
			}
			[ $field, $value ] = array_map( 'trim', explode( ':', $line, 2 ) );
			$field             = strtolower( $field );
			$value             = strtolower( $value );
			if ( 'user-agent' === $field ) {
				$applies = '*' === $value || str_contains( 'superdavai', $value );
				continue;
			}
			if ( $applies && 'disallow' === $field && '' !== $value ) {
				$disallow[] = $value;
			}
		}

		foreach ( $disallow as $rule ) {
			if ( '/' === $rule || str_starts_with( $path, $rule ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function empty_result(): array {
		return [
			'brand'   => [
				'name'     => null,
				'tagline'  => null,
				'logo_url' => null,
			],
			'contact' => [
				'address' => null,
				'phone'   => null,
				'email'   => null,
			],
			'hours'   => [],
			'social'  => [
				'instagram' => null,
				'facebook'  => null,
				'linkedin'  => null,
				'x'         => null,
				'youtube'   => null,
				'tiktok'    => null,
			],
			'pages'   => [],
			'errors'  => [],
			'cached'  => false,
		];
	}

	/**
	 * @param array<string,mixed> $base Base result.
	 * @param array<string,mixed> $next New result.
	 * @return array<string,mixed>
	 */
	private function merge_extracted( array $base, array $next ): array {
		foreach ( [ 'brand', 'contact', 'social' ] as $section ) {
			if ( empty( $next[ $section ] ) || ! is_array( $next[ $section ] ) ) {
				continue;
			}
			foreach ( $next[ $section ] as $key => $value ) {
				$existing = $base[ $section ][ $key ] ?? null;
				if ( ( null === $existing || '' === $existing ) && ! empty( $value ) ) {
					$base[ $section ][ $key ] = $value;
				}
			}
		}

		if ( ! empty( $next['hours'] ) && is_array( $next['hours'] ) && empty( $base['hours'] ) ) {
			$base['hours'] = $next['hours'];
		}

		return $base;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function extract_json_ld( string $html ): array {
		$result = $this->empty_result();
		if ( ! preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches ) ) {
			return $result;
		}

		foreach ( $matches[1] as $json ) {
			$decoded = json_decode( html_entity_decode( trim( $json ) ), true );
			foreach ( $this->json_ld_nodes( $decoded ) as $node ) {
				$type = $node['@type'] ?? '';
				$type = is_array( $type ) ? implode( ' ', $type ) : (string) $type;
				if ( ! preg_match( '/Organization|LocalBusiness|Restaurant|Store|CafeOrCoffeeShop|FoodEstablishment/i', $type ) ) {
					continue;
				}

				$result['brand']['name']     = $this->string_value( $node['name'] ?? null ) ?: $result['brand']['name'];
				$result['brand']['tagline']  = $this->string_value( $node['slogan'] ?? ( $node['description'] ?? null ) ) ?: $result['brand']['tagline'];
				$result['brand']['logo_url'] = $this->string_value( $node['logo'] ?? ( $node['image'] ?? null ) ) ?: $result['brand']['logo_url'];
				$result['contact']['phone']  = $this->string_value( $node['telephone'] ?? null ) ?: $result['contact']['phone'];
				$result['contact']['email']  = $this->string_value( $node['email'] ?? null ) ?: $result['contact']['email'];

				if ( ! empty( $node['address'] ) ) {
					$result['contact']['address'] = $this->format_address( $node['address'] );
				}

				if ( ! empty( $node['sameAs'] ) && is_array( $node['sameAs'] ) ) {
					$result = $this->merge_extracted( $result, $this->classify_social_urls( $node['sameAs'] ) );
				}

				$result['hours'] = $this->parse_opening_hours( $node['openingHours'] ?? ( $node['openingHoursSpecification'] ?? null ) );
			}
		}

		return $result;
	}

	/**
	 * @param mixed $decoded JSON-LD decoded value.
	 * @return list<array<string,mixed>>
	 */
	private function json_ld_nodes( mixed $decoded ): array {
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$nodes = [];
		if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
			foreach ( $decoded['@graph'] as $node ) {
				if ( is_array( $node ) ) {
					$nodes[] = $this->string_keyed_array( $node );
				}
			}
		} elseif ( array_is_list( $decoded ) ) {
			foreach ( $decoded as $node ) {
				if ( is_array( $node ) ) {
					$nodes[] = $this->string_keyed_array( $node );
				}
			}
		} else {
			$nodes[] = $this->string_keyed_array( $decoded );
		}

		return $nodes;
	}

	/**
	 * @param array<mixed> $node Raw associative array.
	 * @return array<string,mixed>
	 */
	private function string_keyed_array( array $node ): array {
		$result = [];
		foreach ( $node as $key => $value ) {
			$result[ (string) $key ] = $value;
		}

		return $result;
	}

	/**
	 * @param array<mixed> $cached Cached transient payload.
	 * @return array<string,mixed>
	 */
	private function normalize_cached_result( array $cached ): array {
		$result           = $this->merge_extracted( $this->empty_result(), $cached );
		$result['pages']  = isset( $cached['pages'] ) && is_array( $cached['pages'] ) ? $cached['pages'] : [];
		$result['errors'] = isset( $cached['errors'] ) && is_array( $cached['errors'] ) ? $cached['errors'] : [];
		$result['cached'] = true;

		return $result;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function extract_meta( string $html ): array {
		$result = $this->empty_result();
		$meta   = [];
		if ( preg_match_all( '/<meta\s+([^>]+)>/i', $html, $matches ) ) {
			foreach ( $matches[1] as $attrs ) {
				$name    = strtolower( $this->attr_value( $attrs, 'property' ) ?: $this->attr_value( $attrs, 'name' ) );
				$content = $this->attr_value( $attrs, 'content' );
				if ( '' !== $name && '' !== $content ) {
					$meta[ $name ] = html_entity_decode( $content );
				}
			}
		}

		$result['brand']['name']     = $meta['og:site_name'] ?? null;
		$result['brand']['tagline']  = $meta['og:description'] ?? ( $meta['description'] ?? null );
		$result['brand']['logo_url'] = $meta['og:image'] ?? null;

		return $result;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function extract_logo( string $url, string $html ): array {
		$result = $this->empty_result();
		if ( preg_match( '/<img\s+[^>]*(?:class|alt|src)=["\'][^"\']*logo[^"\']*["\'][^>]*>/i', $html, $match ) ) {
			$src = $this->attr_value( $match[0], 'src' );
			if ( '' !== $src ) {
				$result['brand']['logo_url'] = $this->resolve_url( $url, $src );
			}
		}

		return $result;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function extract_social_links( string $html ): array {
		$urls = [];
		if ( preg_match_all( '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches ) ) {
			$urls = $matches[1];
		}

		return $this->classify_social_urls( $urls );
	}

	/**
	 * @param array<mixed> $urls Social URL candidates.
	 * @return array<string,mixed>
	 */
	private function classify_social_urls( array $urls ): array {
		$result = $this->empty_result();
		foreach ( $urls as $url ) {
			$url = (string) $url;
			$map = [
				'instagram.com' => 'instagram',
				'facebook.com'  => 'facebook',
				'linkedin.com'  => 'linkedin',
				'twitter.com'   => 'x',
				'x.com'         => 'x',
				'youtube.com'   => 'youtube',
				'tiktok.com'    => 'tiktok',
			];
			foreach ( $map as $host => $key ) {
				if ( str_contains( strtolower( $url ), $host ) && empty( $result['social'][ $key ] ) ) {
					$result['social'][ $key ] = esc_url_raw( $url );
				}
			}
		}

		return $result;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function extract_heuristics( string $text ): array {
		$result = $this->empty_result();
		if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $match ) ) {
			$result['contact']['email'] = sanitize_email( $match[0] );
		}

		if ( preg_match( '/(?:\+?\d[\d\s().\-]{7,}\d)/', $text, $match ) ) {
			$result['contact']['phone'] = trim( $match[0] );
		}

		if ( preg_match( '/\b\d{1,5}\s+[A-Z][A-Za-z0-9 .\'-]+\s(?:Street|St|Road|Rd|Lane|Ln|Avenue|Ave|Drive|Dr|Way|High Street|Mill Lane)[^\n,]*(?:,\s*[^\n]+)?/i', $text, $match ) ) {
			$result['contact']['address'] = trim( $match[0], " \t\n\r\0\x0B,." );
		}

		$hours = [];
		if ( preg_match_all( '/\b(Mon|Tue|Wed|Thu|Fri|Sat|Sun)(?:day)?(?:\s*-\s*(Mon|Tue|Wed|Thu|Fri|Sat|Sun)(?:day)?)?\s*:?\s*(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)\s*[-–]\s*(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)/i', $text, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$hours[] = [
					'day'   => ucfirst( strtolower( substr( $match[1], 0, 3 ) ) ),
					'open'  => $this->normalize_time( $match[3] ),
					'close' => $this->normalize_time( $match[4] ),
				];
			}
		}
		$result['hours'] = $hours;

		return $result;
	}

	/**
	 * @param mixed $hours Hours value from JSON-LD.
	 * @return list<array<string,string|null>>
	 */
	private function parse_opening_hours( mixed $hours ): array {
		$parsed = [];
		if ( is_string( $hours ) ) {
			$hours = [ $hours ];
		}
		if ( ! is_array( $hours ) ) {
			return [];
		}

		foreach ( $hours as $entry ) {
			if ( is_string( $entry ) && preg_match( '/^([A-Za-z]{2})\s+(\d{1,2}:\d{2})-(\d{1,2}:\d{2})/', $entry, $match ) ) {
				$parsed[] = [
					'day'   => ucfirst( strtolower( $match[1] ) ),
					'open'  => $match[2],
					'close' => $match[3],
				];
			} elseif ( is_array( $entry ) ) {
				$days = $entry['dayOfWeek'] ?? [];
				$days = is_array( $days ) ? $days : [ $days ];
				foreach ( $days as $day ) {
					$parsed[] = [
						'day'   => substr( basename( (string) $day ), 0, 3 ),
						'open'  => $this->string_value( $entry['opens'] ?? null ),
						'close' => $this->string_value( $entry['closes'] ?? null ),
					];
				}
			}
		}

		return $parsed;
	}

	/**
	 * @param mixed $address JSON-LD address value.
	 */
	private function format_address( mixed $address ): ?string {
		if ( is_string( $address ) ) {
			return $address;
		}
		if ( ! is_array( $address ) ) {
			return null;
		}

		$parts = array_filter(
			[
				$this->string_value( $address['streetAddress'] ?? null ),
				$this->string_value( $address['addressLocality'] ?? null ),
				$this->string_value( $address['addressRegion'] ?? null ),
				$this->string_value( $address['postalCode'] ?? null ),
				$this->string_value( $address['addressCountry'] ?? null ),
			]
		);

		return ! empty( $parts ) ? implode( ', ', $parts ) : null;
	}

	/**
	 * @param mixed $value Input value.
	 */
	private function string_value( mixed $value ): ?string {
		if ( is_array( $value ) ) {
			if ( isset( $value['url'] ) ) {
				return $this->string_value( $value['url'] );
			}
			$value = reset( $value );
		}

		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return '' !== $value ? $value : null;
	}

	private function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}

		return esc_url_raw( $url );
	}

	/**
	 * @param string        $url          Base URL.
	 * @param array<string> $target_pages Target pages.
	 * @return list<string>
	 */
	private function initial_queue( string $url, array $target_pages ): array {
		if ( empty( $target_pages ) ) {
			return [ $url ];
		}

		$queue = [];
		foreach ( $target_pages as $page ) {
			$queue[] = $this->resolve_url( $url, $page );
		}

		return array_values( array_unique( $queue ) );
	}

	/**
	 * @return list<string>
	 */
	private function discover_links( string $base_url, string $html ): array {
		$links = [];
		if ( preg_match_all( '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches ) ) {
			foreach ( $matches[1] as $href ) {
				$absolute = $this->resolve_url( $base_url, html_entity_decode( $href ) );
				if ( $this->same_origin( $base_url, $absolute ) && preg_match( '#/(about|contact|hours|menu|team|visit)#i', $absolute ) ) {
					$links[] = $absolute;
				}
			}
		}

		return array_values( array_unique( $links ) );
	}

	private function resolve_url( string $base_url, string $href ): string {
		if ( preg_match( '#^https?://#i', $href ) ) {
			return esc_url_raw( $href );
		}

		$parts = wp_parse_url( $base_url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$root = $parts['scheme'] . '://' . $parts['host'];
		return esc_url_raw( $root . '/' . ltrim( $href, '/' ) );
	}

	private function same_origin( string $a, string $b ): bool {
		$a_parts = wp_parse_url( $a );
		$b_parts = wp_parse_url( $b );

		return strtolower( (string) ( $a_parts['host'] ?? '' ) ) === strtolower( (string) ( $b_parts['host'] ?? '' ) );
	}

	private function attr_value( string $attrs, string $name ): string {
		if ( preg_match( '/\b' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/i', $attrs, $match ) ) {
			return trim( html_entity_decode( $match[2] ) );
		}

		return '';
	}

	private function first_match( string $pattern, string $subject ): string {
		return preg_match( $pattern, $subject, $match ) ? (string) $match[1] : '';
	}

	private function clean_text( string $text ): string {
		$text = wp_strip_all_tags( html_entity_decode( $text ), true );
		$text = preg_replace( '/\s+/', ' ', $text );

		return trim( (string) $text );
	}

	private function normalize_time( string $time ): string {
		$timestamp = strtotime( $time );
		return false !== $timestamp ? gmdate( 'H:i', $timestamp ) : trim( $time );
	}

	/**
	 * @param string        $url          URL being scraped.
	 * @param int           $max_pages    Maximum pages.
	 * @param array<string> $target_pages Target pages.
	 * @param string        $extract_mode Extraction mode.
	 */
	private function cache_key( string $url, int $max_pages, array $target_pages, string $extract_mode ): string {
		return self::CACHE_GROUP . md5( wp_json_encode( [ $url, $max_pages, $target_pages, $extract_mode ] ) ?: $url );
	}

	private function rate_limit(): void {
		$seconds = (int) apply_filters( 'sd_ai_agent_site_scraper_rate_limit_seconds', 1 );
		if ( $seconds > 0 ) {
			sleep( min( 3, $seconds ) );
		}
	}

	private function user_agent(): string {
		$version = defined( 'SD_AI_AGENT_VERSION' ) ? (string) SD_AI_AGENT_VERSION : '1.0.0';
		return sprintf( 'SuperdavAI/%s (+%s)', $version, home_url( '/' ) );
	}

	/**
	 * @param array<string,mixed> $result Existing result.
	 * @return array<string,mixed>
	 */
	private function maybe_ai_complete( array $result ): array {
		if ( ! function_exists( 'wp_ai_client_prompt' ) || empty( $result['pages'] ) ) {
			return $result;
		}

		$has_gaps = empty( $result['brand']['name'] ) || empty( $result['contact']['address'] );
		if ( ! $has_gaps ) {
			return $result;
		}

		$pages_json = wp_json_encode( $result['pages'] );
		$prompt     = "Extract brand, contact, hours, and social profile data from this website text. Return only JSON matching {brand:{name,tagline,logo_url},contact:{address,phone,email},hours:[{day,open,close}],social:{instagram,facebook,linkedin,x,youtube,tiktok}}.\n\n" . (string) $pages_json;
		$raw        = wp_ai_client_prompt( $prompt )->generate_text();
		if ( is_wp_error( $raw ) || ! is_string( $raw ) ) {
			return $result;
		}

		$json = $this->first_match( '/\{[\s\S]*\}/', $raw );
		$data = json_decode( '' !== $json ? $json : $raw, true );
		if ( ! is_array( $data ) ) {
			return $result;
		}

		return $this->merge_extracted( $result, $data );
	}
}
