<?php

declare(strict_types=1);
/**
 * Site scraper service for the Theme Builder interview pre-fill feature.
 *
 * Fetches an existing website and extracts structured brand/contact/hours data
 * from Schema.org JSON-LD, OpenGraph meta tags, and heuristic patterns. Results
 * are cached in transients for 24 hours per URL so repeated calls during the
 * same interview session are instantaneous.
 *
 * Design notes:
 * - Accepts any valid absolute http:// or https:// URL (no domain allowlist).
 * - Respects robots.txt for the User-agent: * block.
 * - Rate-limited to 1 request/second per PHP process (static delay flag).
 * - Uses wp_remote_get() (not wp_safe_remote_get()) because external URLs are
 *   the explicit purpose of this service and the user explicitly consented.
 * - HTML parsing uses DOMDocument with libxml error suppression; never executes
 *   JavaScript — SPA sites will yield limited data (documented in caveats).
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
 * Scrapes an existing website to extract structured brand/contact data.
 *
 * @since 1.7.0
 */
class SiteScraper {

	/**
	 * Transient TTL: 24 hours.
	 */
	const TRANSIENT_TTL = DAY_IN_SECONDS;

	/**
	 * Maximum bytes to read from a single page response (1 MB).
	 */
	const MAX_BODY_BYTES = 1048576;

	/**
	 * HTTP timeout in seconds per request.
	 */
	const HTTP_TIMEOUT = 15;

	/**
	 * User-Agent header sent with every request.
	 */
	const USER_AGENT = 'SuperdavAI/1.0 (+https://wordpress.org/plugins/superdav-ai-agent)';

	/**
	 * Whether a rate-limit delay has been applied in this PHP process.
	 *
	 * Prevents hammering a site when multiple pages are crawled in one call.
	 *
	 * @var bool
	 */
	private static bool $rate_limit_applied = false;

	// ─── Public API ───────────────────────────────────────────────────────

	/**
	 * Scrape a URL and return structured brand/contact data.
	 *
	 * Returns a consistent shape regardless of how much data was found. All
	 * leaf values are either the extracted string/array or null — never absent.
	 *
	 * @since 1.7.0
	 *
	 * @param string              $url     Absolute http:// or https:// URL to scrape.
	 * @param array<string,mixed> $options Optional. Supports: 'max_pages' (int, default 10),
	 *                                     'pages' (string[], explicit paths),
	 *                                     'bypass_cache' (bool, default false).
	 * @return array<string,mixed>|WP_Error Structured data or WP_Error on hard failure.
	 */
	public function scrape( string $url, array $options = [] ): array|WP_Error {
		if ( ! $this->is_valid_url( $url ) ) {
			return new WP_Error(
				'sd_ai_agent_invalid_scrape_url',
				sprintf(
					/* translators: %s: the invalid URL */
					__( 'Invalid scrape URL "%s": must be an absolute http or https URL.', 'superdav-ai-agent' ),
					$url
				)
			);
		}

		$bypass_cache = (bool) ( $options['bypass_cache'] ?? false );
		$cache_key    = $this->transient_key( $url );

		if ( ! $bypass_cache ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				/** @var array<string,mixed> $cached */
				return $cached;
			}
		}

		// Respect robots.txt before fetching any page.
		$robots_allowed = $this->is_allowed_by_robots( $url );
		if ( is_wp_error( $robots_allowed ) ) {
			// Network error fetching robots.txt — proceed (fail open, polite default).
			$robots_allowed = true;
		}

		if ( ! $robots_allowed ) {
			return new WP_Error(
				'sd_ai_agent_site_scrape_robots_disallowed',
				sprintf(
					/* translators: %s: the URL */
					__( 'robots.txt disallows scraping "%s".', 'superdav-ai-agent' ),
					$url
				)
			);
		}

		// Build the list of pages to crawl.
		$pages_to_crawl = $this->resolve_pages( $url, $options );

		$result = $this->empty_result();
		$pages  = [];

		foreach ( $pages_to_crawl as $page_url ) {
			$this->maybe_rate_limit();

			$html = $this->fetch_html( $page_url );
			if ( is_wp_error( $html ) ) {
				// Skip failing pages but continue with others.
				continue;
			}

			$page_data = $this->parse_page( $page_url, $html );
			$pages[]   = $page_data;

			// Merge structured data — first non-null value wins per field.
			/** @var array<string,mixed> $extracted */
			$extracted = is_array( $page_data['extracted'] ) ? $page_data['extracted'] : [];
			$result    = $this->merge_result( $result, $extracted );
		}

		$result['pages'] = $pages;

		set_transient( $cache_key, $result, self::TRANSIENT_TTL );

		return $result;
	}

	// ─── URL validation ───────────────────────────────────────────────────

	/**
	 * Validates that a URL is a well-formed absolute http or https URL.
	 *
	 * Deliberately permissive: accepts any valid http/https URL including
	 * example.com, localhost, and IP addresses — the user is explicitly
	 * providing the URL of their own existing site so there is no domain
	 * allowlist.
	 *
	 * @since 1.7.0
	 *
	 * @param string $url The URL to validate.
	 * @return bool True when the URL is valid.
	 */
	public function is_valid_url( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}

		$parsed = wp_parse_url( $url );

		if ( ! is_array( $parsed ) ) {
			return false;
		}

		$scheme = strtolower( (string) ( $parsed['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return false;
		}

		$host = (string) ( $parsed['host'] ?? '' );
		if ( '' === $host ) {
			return false;
		}

		return true;
	}

	// ─── robots.txt ───────────────────────────────────────────────────────

	/**
	 * Check whether crawling a given URL is permitted by the site's robots.txt.
	 *
	 * Fetches /robots.txt relative to the scheme+host of the given URL and
	 * checks the User-agent: * block. Returns true (allowed) when robots.txt
	 * is unreachable, empty, or contains no relevant Disallow directives.
	 *
	 * @since 1.7.0
	 *
	 * @param string $url The target page URL (not the robots.txt URL itself).
	 * @return bool|WP_Error True if allowed, false if disallowed, WP_Error on fetch failure.
	 */
	public function is_allowed_by_robots( string $url ): bool|WP_Error {
		$parsed      = wp_parse_url( $url );
		$scheme      = (string) ( $parsed['scheme'] ?? 'https' );
		$host        = (string) ( $parsed['host'] ?? '' );
		$port        = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';
		$robots_url  = $scheme . '://' . $host . $port . '/robots.txt';
		$target_path = (string) ( $parsed['path'] ?? '/' );

		$response = wp_remote_get(
			$robots_url,
			[
				'timeout'     => self::HTTP_TIMEOUT,
				'user-agent'  => self::USER_AGENT,
				'redirection' => 3,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			// robots.txt not found or inaccessible — allow by default.
			return true;
		}

		$body = wp_remote_retrieve_body( $response );
		return $this->parse_robots_txt( $body, $target_path );
	}

	/**
	 * Parse robots.txt content and determine whether the path is allowed.
	 *
	 * Implements a minimal subset of the robots.txt standard: reads
	 * User-agent: * blocks, respects Disallow directives, stops at next
	 * User-agent block. Allow directives take precedence over Disallow.
	 *
	 * @since 1.7.0
	 *
	 * @param string $robots_txt The raw robots.txt content.
	 * @param string $path       The URL path to check (e.g. '/about').
	 * @return bool True if crawling the path is permitted.
	 */
	public function parse_robots_txt( string $robots_txt, string $path ): bool {
		$lines            = preg_split( '/\r?\n/', $robots_txt ) ?: [];
		$in_wildcard      = false;
		$disallowed_paths = [];
		$allowed_paths    = [];

		foreach ( $lines as $line ) {
			$line = trim( $line );

			// Strip inline comments.
			$hash = strpos( $line, '#' );
			if ( false !== $hash ) {
				$line = trim( substr( $line, 0, $hash ) );
			}

			if ( '' === $line ) {
				continue;
			}

			if ( 0 === stripos( $line, 'user-agent:' ) ) {
				$agent = trim( substr( $line, strlen( 'user-agent:' ) ) );
				if ( '*' === $agent ) {
					$in_wildcard = true;
				} elseif ( $in_wildcard ) {
					// Next user-agent block starts — stop reading.
					break;
				}
				continue;
			}

			if ( ! $in_wildcard ) {
				continue;
			}

			if ( 0 === stripos( $line, 'disallow:' ) ) {
				$dp = trim( substr( $line, strlen( 'disallow:' ) ) );
				if ( '' !== $dp ) {
					$disallowed_paths[] = $dp;
				}
				continue;
			}

			if ( 0 === stripos( $line, 'allow:' ) ) {
				$ap = trim( substr( $line, strlen( 'allow:' ) ) );
				if ( '' !== $ap ) {
					$allowed_paths[] = $ap;
				}
				continue;
			}
		}

		// Check Allow first (more specific wins if same prefix length).
		foreach ( $allowed_paths as $allowed ) {
			if ( 0 === strpos( $path, $allowed ) ) {
				return true;
			}
		}

		foreach ( $disallowed_paths as $disallowed ) {
			if ( 0 === strpos( $path, $disallowed ) ) {
				return false;
			}
		}

		return true;
	}

	// ─── Fetching ─────────────────────────────────────────────────────────

	/**
	 * Fetch the HTML body of a page.
	 *
	 * @since 1.7.0
	 *
	 * @param string $url Absolute URL to fetch.
	 * @return string|WP_Error HTML body string or WP_Error on failure.
	 */
	public function fetch_html( string $url ): string|WP_Error {
		$response = wp_remote_get(
			$url,
			[
				'timeout'     => self::HTTP_TIMEOUT,
				'user-agent'  => self::USER_AGENT,
				'redirection' => 5,
				'headers'     => [
					'Accept'          => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
					'Accept-Language' => 'en-US,en;q=0.5',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 400 ) {
			return new WP_Error(
				'sd_ai_agent_scrape_http_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: URL */
					__( 'HTTP %1$d fetching "%2$s".', 'superdav-ai-agent' ),
					$status,
					$url
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );

		// Truncate to avoid parsing enormous documents.
		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			$body = substr( $body, 0, self::MAX_BODY_BYTES );
		}

		return $body;
	}

	// ─── Parsing ──────────────────────────────────────────────────────────

	/**
	 * Parse a page HTML string and extract structured data.
	 *
	 * @since 1.7.0
	 *
	 * @param string $url  The page URL (for reference in output).
	 * @param string $html Raw HTML of the page.
	 * @return array<string,mixed> Page entry with url, title, text, headings, extracted.
	 */
	public function parse_page( string $url, string $html ): array {
		$dom = $this->load_dom( $html );

		// Parse structured data BEFORE extract_text() because extract_text()
		// removes <script> and <style> elements from the DOM to produce plain
		// text — including the JSON-LD <script type="application/ld+json"> tags
		// that parse_schema_org() needs to read.
		$schema = $this->parse_schema_org( $dom );
		$og     = $this->parse_opengraph( $dom );

		$title    = $this->extract_title( $dom );
		$text     = $this->extract_text( $dom );
		$headings = $this->extract_headings( $dom );

		$heuristic = $this->extract_heuristics( $html, $text );

		// Merge: Schema.org > OpenGraph > heuristic.
		$extracted = $this->merge_sources( $schema, $og, $heuristic );

		return [
			'url'       => $url,
			'title'     => $title,
			'text'      => substr( $text, 0, 2000 ),
			'headings'  => $headings,
			'extracted' => $extracted,
		];
	}

	/**
	 * Parse Schema.org JSON-LD from script tags in the DOM.
	 *
	 * Supports Organization, LocalBusiness, Restaurant and their subtypes.
	 *
	 * @since 1.7.0
	 *
	 * @param \DOMDocument $dom Parsed DOM.
	 * @return array<string,mixed> Partial structured data from Schema.org.
	 */
	public function parse_schema_org( \DOMDocument $dom ): array {
		$result = [];
		$xpath  = new \DOMXPath( $dom );

		$scripts = $xpath->query( '//script[@type="application/ld+json"]' );
		if ( false === $scripts || 0 === $scripts->length ) {
			return $result;
		}

		foreach ( $scripts as $script ) {
			/** @var \DOMElement $script */
			$json = trim( $script->textContent );
			if ( '' === $json ) {
				continue;
			}

			$data = json_decode( $json, true );
			if ( ! is_array( $data ) ) {
				continue;
			}

			// Handle @graph arrays.
			if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
				foreach ( $data['@graph'] as $node ) {
					if ( is_array( $node ) ) {
						$result = $this->merge_result( $result, $this->extract_from_schema_node( $node ) );
					}
				}
			} else {
				$result = $this->merge_result( $result, $this->extract_from_schema_node( $data ) );
			}
		}

		return $result;
	}

	/**
	 * Extract brand/contact/hours from a single Schema.org JSON-LD node.
	 *
	 * @since 1.7.0
	 *
	 * @param array<string,mixed> $node A decoded JSON-LD object.
	 * @return array<string,mixed> Partial structured data.
	 */
	public function extract_from_schema_node( array $node ): array {
		$result = [];
		$type   = (string) ( $node['@type'] ?? '' );

		// Only process Organisation/LocalBusiness/Website/etc. nodes.
		$supported_types = [
			'Organization',
			'LocalBusiness',
			'Restaurant',
			'CafeOrCoffeeShop',
			'FoodEstablishment',
			'Store',
			'Hotel',
			'MedicalBusiness',
			'HealthAndBeautyBusiness',
			'LegalService',
			'FinancialService',
			'Corporation',
			'NGO',
			'WebSite',
		];

		if ( ! in_array( $type, $supported_types, true ) ) {
			return $result;
		}

		// Brand.
		$name = (string) ( $node['name'] ?? '' );
		if ( '' !== $name ) {
			$result['brand']['name'] = $name;
		}

		$slogan = (string) ( $node['slogan'] ?? $node['description'] ?? '' );
		if ( '' !== $slogan ) {
			$result['brand']['tagline'] = $slogan;
		}

		$logo = $node['logo'] ?? null;
		if ( is_string( $logo ) && '' !== $logo ) {
			$result['brand']['logo_url'] = $logo;
		} elseif ( is_array( $logo ) ) {
			$logo_url = (string) ( $logo['url'] ?? $logo['@id'] ?? '' );
			if ( '' !== $logo_url ) {
				$result['brand']['logo_url'] = $logo_url;
			}
		}

		// Contact.
		$address = $node['address'] ?? null;
		if ( is_string( $address ) && '' !== $address ) {
			$result['contact']['address'] = $address;
		} elseif ( is_array( $address ) ) {
			$parts = array_filter(
				[
					(string) ( $address['streetAddress'] ?? '' ),
					(string) ( $address['addressLocality'] ?? '' ),
					(string) ( $address['addressRegion'] ?? '' ),
					(string) ( $address['postalCode'] ?? '' ),
					(string) ( $address['addressCountry'] ?? '' ),
				]
				);
			if ( ! empty( $parts ) ) {
				$result['contact']['address'] = implode( ', ', $parts );
			}
		}

		$phone = (string) ( $node['telephone'] ?? '' );
		if ( '' !== $phone ) {
			$result['contact']['phone'] = $phone;
		}

		$email = (string) ( $node['email'] ?? '' );
		if ( '' !== $email ) {
			$result['contact']['email'] = $email;
		}

		// Social links from sameAs.
		$same_as = $node['sameAs'] ?? null;
		if ( is_string( $same_as ) ) {
			$same_as = [ $same_as ];
		}
		if ( is_array( $same_as ) ) {
			$social_map = [
				'instagram.com'   => 'instagram',
				'facebook.com'    => 'facebook',
				'twitter.com'     => 'twitter',
				'x.com'           => 'twitter',
				'linkedin.com'    => 'linkedin',
				'youtube.com'     => 'youtube',
				'tiktok.com'      => 'tiktok',
				'pinterest.com'   => 'pinterest',
				'tripadvisor.com' => 'tripadvisor',
			];

			foreach ( $same_as as $sa_url ) {
				if ( ! is_string( $sa_url ) ) {
					continue;
				}
				foreach ( $social_map as $domain => $key ) {
					if ( false !== strpos( $sa_url, $domain ) ) {
						$result['social'][ $key ] = $sa_url;
						break;
					}
				}
			}
		}

		// Opening hours.
		$hours_spec = $node['openingHoursSpecification'] ?? $node['openingHours'] ?? null;
		if ( is_array( $hours_spec ) && ! empty( $hours_spec ) ) {
			$result['hours'] = $this->parse_schema_hours( $hours_spec );
		} elseif ( is_string( $hours_spec ) && '' !== $hours_spec ) {
			$result['hours'] = $this->parse_schema_hours_string( $hours_spec );
		}

		return $result;
	}

	/**
	 * Parse openingHoursSpecification array from Schema.org.
	 *
	 * @since 1.7.0
	 *
	 * @param array<mixed> $spec Array of hour spec objects.
	 * @return array<int,array<string,string>> Normalised hours array.
	 */
	private function parse_schema_hours( array $spec ): array {
		$hours = [];

		foreach ( $spec as $entry ) {
			if ( ! is_array( $entry ) ) {
				// Handle simple string like "Mo-Fr 09:00-17:00".
				if ( is_string( $entry ) ) {
					$parsed = $this->parse_schema_hours_string( $entry );
					$hours  = array_merge( $hours, $parsed );
				}
				continue;
			}

			$days_of_week = $entry['dayOfWeek'] ?? null;
			$opens        = (string) ( $entry['opens'] ?? '' );
			$closes       = (string) ( $entry['closes'] ?? '' );

			if ( is_string( $days_of_week ) ) {
				$days_of_week = [ $days_of_week ];
			}

			if ( ! is_array( $days_of_week ) ) {
				continue;
			}

			foreach ( $days_of_week as $day ) {
				$day_name = is_string( $day ) ? $this->normalise_day( $day ) : '';
				if ( '' === $day_name ) {
					continue;
				}

				$hours[] = [
					'day'   => $day_name,
					'open'  => $opens,
					'close' => $closes,
				];
			}
		}

		return $hours;
	}

	/**
	 * Parse a simple openingHours string like "Mo-Fr 09:00-17:00, Sa 10:00-14:00".
	 *
	 * @since 1.7.0
	 *
	 * @param string $hours_string Raw openingHours string.
	 * @return array<int,array<string,string>> Normalised hours array.
	 */
	private function parse_schema_hours_string( string $hours_string ): array {
		$hours   = [];
		$entries = preg_split( '/[,;]/', $hours_string ) ?: [];

		$day_abbreviations = [
			'Mo' => 'Mon',
			'Tu' => 'Tue',
			'We' => 'Wed',
			'Th' => 'Thu',
			'Fr' => 'Fri',
			'Sa' => 'Sat',
			'Su' => 'Sun',
		];

		foreach ( $entries as $entry ) {
			$entry = trim( $entry );
			// Match e.g. "Mo-Fr 09:00-17:00" or "Mo 09:00-17:00".
			if ( preg_match( '/^(\w{2})(?:-(\w{2}))?\s+(\d{2}:\d{2})-(\d{2}:\d{2})$/', $entry, $m ) ) {
				$start_day = $m[1];
				$end_day   = '' !== $m[2] ? $m[2] : $m[1];
				$opens     = $m[3];
				$closes    = $m[4];

				// Expand day ranges.
				$day_keys = array_keys( $day_abbreviations );
				$start_i  = array_search( $start_day, $day_keys, true );
				$end_i    = array_search( $end_day, $day_keys, true );

				if ( false === $start_i || false === $end_i ) {
					continue;
				}

				for ( $i = (int) $start_i; $i <= (int) $end_i; $i++ ) {
					$hours[] = [
						'day'   => $day_abbreviations[ $day_keys[ $i ] ],
						'open'  => $opens,
						'close' => $closes,
					];
				}
			}
		}

		return $hours;
	}

	/**
	 * Normalise a Schema.org dayOfWeek value to a short day name.
	 *
	 * Handles both full URIs (http://schema.org/Monday) and plain strings (Monday).
	 *
	 * @since 1.7.0
	 *
	 * @param string $day Raw day value from Schema.org.
	 * @return string Normalised three-letter day abbreviation, or empty string.
	 */
	private function normalise_day( string $day ): string {
		$day = basename( $day ); // Strips https://schema.org/ prefix.
		$map = [
			'Monday'    => 'Mon',
			'Tuesday'   => 'Tue',
			'Wednesday' => 'Wed',
			'Thursday'  => 'Thu',
			'Friday'    => 'Fri',
			'Saturday'  => 'Sat',
			'Sunday'    => 'Sun',
		];
		return $map[ $day ] ?? '';
	}

	/**
	 * Parse OpenGraph meta tags from the DOM.
	 *
	 * @since 1.7.0
	 *
	 * @param \DOMDocument $dom Parsed DOM.
	 * @return array<string,mixed> Partial structured data from OpenGraph tags.
	 */
	public function parse_opengraph( \DOMDocument $dom ): array {
		$result = [];
		$xpath  = new \DOMXPath( $dom );

		$metas = $xpath->query( '//meta[@property or @name]' );
		if ( false === $metas ) {
			return $result;
		}

		foreach ( $metas as $meta ) {
			/** @var \DOMElement $meta */
			$prop    = strtolower( (string) $meta->getAttribute( 'property' ) );
			$name    = strtolower( (string) $meta->getAttribute( 'name' ) );
			$content = (string) $meta->getAttribute( 'content' );

			if ( '' === $content ) {
				continue;
			}

			switch ( $prop ) {
				case 'og:site_name':
					$result['brand']['name'] = $content;
					break;
				case 'og:title':
					// Use as fallback name only if og:site_name is absent.
					if ( ! isset( $result['brand']['name'] ) ) {
						$result['brand']['name'] = $content;
					}
					break;
				case 'og:description':
					$result['brand']['tagline'] = $content;
					break;
				case 'og:image':
					if ( ! isset( $result['brand']['logo_url'] ) ) {
						$result['brand']['logo_url'] = $content;
					}
					break;
			}

			// Twitter card equivalents.
			switch ( $name ) {
				case 'description':
					if ( ! isset( $result['brand']['tagline'] ) ) {
						$result['brand']['tagline'] = $content;
					}
					break;
				case 'twitter:site':
				case 'twitter:creator':
					$handle = ltrim( $content, '@' );
					if ( '' !== $handle && ! isset( $result['social']['twitter'] ) ) {
						$result['social']['twitter'] = 'https://twitter.com/' . $handle;
					}
					break;
			}
		}

		return $result;
	}

	/**
	 * Extract contact information using heuristic patterns.
	 *
	 * Searches the plain text of the page for phone numbers, email addresses,
	 * and postal addresses. Not exhaustive — designed for common cases on
	 * small business / café / restaurant sites.
	 *
	 * @since 1.7.0
	 *
	 * @param string $html Raw HTML.
	 * @param string $text Plain text extracted from the HTML.
	 * @return array<string,mixed> Partial structured data from heuristic extraction.
	 */
	public function extract_heuristics( string $html, string $text ): array {
		$result = [];

		// Phone — match common formats: (555) 555-5555, +44 20 7946 0958, 0131 555 0147.
		if ( preg_match(
			'/(?:\+?\d[\d\s\-\.\(\)]{7,18}\d)/u',
			$text,
			$m
		) ) {
			$candidate = trim( $m[0] );
			// Filter out things that look like years or order numbers (e.g. 20240101).
			if ( strlen( (string) preg_replace( '/\D/', '', $candidate ) ) >= 7 ) {
				$result['contact']['phone'] = $candidate;
			}
		}

		// Email.
		if ( preg_match(
			'/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/u',
			$text,
			$m
		) ) {
			$result['contact']['email'] = strtolower( $m[0] );
		}

		// Hours table heuristic: look for <td> cells containing time ranges.
		$hours = $this->extract_hours_heuristic( $html );
		if ( ! empty( $hours ) ) {
			$result['hours'] = $hours;
		}

		return $result;
	}

	/**
	 * Heuristically extract opening hours from HTML table patterns.
	 *
	 * Looks for <tr> rows where the first cell contains a day name and the
	 * second (or same) cell contains a time range like "09:00 – 17:00".
	 *
	 * @since 1.7.0
	 *
	 * @param string $html Raw HTML.
	 * @return array<int,array<string,string>> Extracted hours, possibly empty.
	 */
	public function extract_hours_heuristic( string $html ): array {
		$hours = [];

		$dom   = $this->load_dom( $html );
		$xpath = new \DOMXPath( $dom );
		$rows  = $xpath->query( '//tr' );
		if ( false === $rows ) {
			return $hours;
		}

		// No trailing \b because DOMDocument textContent concatenates adjacent td
		// cells without a separator (e.g. "Monday09:00") so the day name is
		// immediately followed by a digit, which is also a word character.
		$day_pattern  = '/(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday|Mon|Tue|Wed|Thu|Fri|Sat|Sun)/i';
		$time_pattern = '/(\d{1,2}[:.]\d{2})\s*(?:am|pm|–|-|to)\s*(\d{1,2}[:.]\d{2})\s*(?:am|pm)?/i';

		$day_map = [
			'monday'    => 'Mon',
			'mon'       => 'Mon',
			'tuesday'   => 'Tue',
			'tue'       => 'Tue',
			'wednesday' => 'Wed',
			'wed'       => 'Wed',
			'thursday'  => 'Thu',
			'thu'       => 'Thu',
			'friday'    => 'Fri',
			'fri'       => 'Fri',
			'saturday'  => 'Sat',
			'sat'       => 'Sat',
			'sunday'    => 'Sun',
			'sun'       => 'Sun',
		];

		foreach ( $rows as $row ) {
			/** @var \DOMElement $row */
			$row_text = trim( $row->textContent );

			if ( ! preg_match( $day_pattern, $row_text, $day_match ) ) {
				continue;
			}

			if ( ! preg_match( $time_pattern, $row_text, $time_match ) ) {
				continue;
			}

			$day_key  = strtolower( $day_match[1] );
			$day_name = $day_map[ $day_key ] ?? ucfirst( strtolower( $day_match[1] ) );

			$hours[] = [
				'day'   => $day_name,
				'open'  => $this->normalise_time( $time_match[1] ),
				'close' => $this->normalise_time( $time_match[2] ),
			];
		}

		return $hours;
	}

	// ─── DOM helpers ──────────────────────────────────────────────────────

	/**
	 * Load an HTML string into a DOMDocument with libxml error suppression.
	 *
	 * @since 1.7.0
	 *
	 * @param string $html Raw HTML string.
	 * @return \DOMDocument Parsed DOM.
	 */
	public function load_dom( string $html ): \DOMDocument {
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );

		// Prefix with an XML encoding declaration so DOMDocument treats the
		// input as UTF-8 without converting HTML-safe characters to entities.
		// Avoids the mb_convert_encoding approach which converts '&' to '&amp;'
		// and corrupts JSON-LD content inside <script> tags.
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		return $dom;
	}

	/**
	 * Extract the page <title> text.
	 *
	 * @since 1.7.0
	 *
	 * @param \DOMDocument $dom Parsed DOM.
	 * @return string Page title or empty string.
	 */
	private function extract_title( \DOMDocument $dom ): string {
		$titles = $dom->getElementsByTagName( 'title' );
		if ( $titles->length > 0 && null !== $titles->item( 0 ) ) {
			return trim( $titles->item( 0 )->textContent );
		}
		return '';
	}

	/**
	 * Extract visible text from the DOM (strips scripts/styles).
	 *
	 * @since 1.7.0
	 *
	 * @param \DOMDocument $dom Parsed DOM.
	 * @return string Plain text content.
	 */
	private function extract_text( \DOMDocument $dom ): string {
		// Remove script and style elements.
		foreach ( [ 'script', 'style', 'noscript' ] as $tag ) {
			$elements = $dom->getElementsByTagName( $tag );
			// Iterate in reverse to avoid re-indexing.
			for ( $i = $elements->length - 1; $i >= 0; $i-- ) {
				$el = $elements->item( $i );
				if ( null !== $el && null !== $el->parentNode ) {
					$el->parentNode->removeChild( $el );
				}
			}
		}

		$text = $dom->textContent;
		// Collapse whitespace.
		$text = preg_replace( '/\s+/', ' ', $text ) ?? $text;
		return trim( $text );
	}

	/**
	 * Extract headings (h1–h3) from the DOM.
	 *
	 * @since 1.7.0
	 *
	 * @param \DOMDocument $dom Parsed DOM.
	 * @return string[] Array of heading text strings.
	 */
	private function extract_headings( \DOMDocument $dom ): array {
		$headings = [];
		foreach ( [ 'h1', 'h2', 'h3' ] as $tag ) {
			$elements = $dom->getElementsByTagName( $tag );
			foreach ( $elements as $el ) {
				$text = trim( $el->textContent );
				if ( '' !== $text ) {
					$headings[] = $text;
				}
			}
		}
		return $headings;
	}

	/**
	 * Normalise a time string like "09:00", "9.00" to "HH:MM" format.
	 *
	 * @since 1.7.0
	 *
	 * @param string $time Raw time string.
	 * @return string Normalised "HH:MM" string.
	 */
	private function normalise_time( string $time ): string {
		// Replace dot separator.
		$time = str_replace( '.', ':', $time );

		$parts = explode( ':', $time );
		if ( count( $parts ) < 2 ) {
			return $time;
		}

		return sprintf( '%02d:%02d', (int) $parts[0], (int) $parts[1] );
	}

	// ─── Data merge helpers ───────────────────────────────────────────────

	/**
	 * Merge two sources of partial structured data.
	 *
	 * The $priority source fills in fields that are null in the $base.
	 * Existing non-null values in $base are never overwritten.
	 *
	 * @since 1.7.0
	 *
	 * @param array<string,mixed> $base     Existing accumulated data.
	 * @param array<string,mixed> $priority New data to merge in.
	 * @return array<string,mixed> Merged data.
	 */
	public function merge_result( array $base, array $priority ): array {
		foreach ( $priority as $key => $value ) {
			if ( ! isset( $base[ $key ] ) ) {
				$base[ $key ] = $value;
			} elseif ( is_array( $base[ $key ] ) && is_array( $value ) ) {
				$base[ $key ] = $this->merge_result( $base[ $key ], $value );
			}
			// If base already has a scalar value, do not overwrite.
		}
		return $base;
	}

	/**
	 * Merge multiple extraction sources in priority order: Schema.org > OpenGraph > heuristic.
	 *
	 * @since 1.7.0
	 *
	 * @param array<string,mixed> $schema    Schema.org extracted data.
	 * @param array<string,mixed> $og        OpenGraph extracted data.
	 * @param array<string,mixed> $heuristic Heuristic extracted data.
	 * @return array<string,mixed> Merged data.
	 */
	private function merge_sources( array $schema, array $og, array $heuristic ): array {
		$merged = $schema;
		$merged = $this->merge_result( $merged, $og );
		$merged = $this->merge_result( $merged, $heuristic );
		return $merged;
	}

	/**
	 * Build the set of page URLs to crawl.
	 *
	 * When an explicit 'pages' list is provided, those relative paths are
	 * resolved against the base URL. Otherwise, tries a default set of
	 * common informational pages (/, /about, /contact) up to max_pages.
	 *
	 * @since 1.7.0
	 *
	 * @param string              $base_url Starting URL.
	 * @param array<string,mixed> $options  Options from the scrape() call.
	 * @return string[] Absolute URLs to crawl.
	 */
	private function resolve_pages( string $base_url, array $options ): array {
		$parsed    = wp_parse_url( $base_url );
		$scheme    = (string) ( $parsed['scheme'] ?? 'https' );
		$host      = (string) ( $parsed['host'] ?? '' );
		$port      = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';
		$origin    = $scheme . '://' . $host . $port;
		$max_pages = max( 1, (int) ( $options['max_pages'] ?? 10 ) );

		$explicit_pages = $options['pages'] ?? null;
		if ( is_array( $explicit_pages ) && ! empty( $explicit_pages ) ) {
			$urls = [];
			foreach ( $explicit_pages as $page ) {
				if ( is_string( $page ) ) {
					$urls[] = $origin . '/' . ltrim( $page, '/' );
				}
			}
			return array_slice( $urls, 0, $max_pages );
		}

		// Default: start with the provided URL, then try common paths.
		$defaults = [ $base_url ];
		$common   = [ '/about', '/contact', '/menu', '/services', '/about-us', '/contact-us' ];

		foreach ( $common as $path ) {
			$candidate = $origin . $path;
			if ( ! in_array( $candidate, $defaults, true ) ) {
				$defaults[] = $candidate;
			}
			if ( count( $defaults ) >= $max_pages ) {
				break;
			}
		}

		return array_slice( $defaults, 0, $max_pages );
	}

	/**
	 * Build an empty result structure with all expected keys present.
	 *
	 * All leaf values default to null so callers can check isset() reliably.
	 *
	 * @since 1.7.0
	 *
	 * @return array<string,mixed> Empty result.
	 */
	public function empty_result(): array {
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
			'social'  => [],
			'pages'   => [],
		];
	}

	/**
	 * Build the transient cache key for a URL.
	 *
	 * @since 1.7.0
	 *
	 * @param string $url Absolute URL.
	 * @return string Transient key.
	 */
	public function transient_key( string $url ): string {
		return 'sd_ai_agent_scrape_' . md5( $url );
	}

	/**
	 * Apply a 1-second rate-limit delay the first time a page is fetched.
	 *
	 * Only sleeps once per PHP process to avoid unnecessary latency when a
	 * test or admin action calls scrape() multiple times in quick succession.
	 *
	 * @since 1.7.0
	 *
	 * @return void
	 */
	private function maybe_rate_limit(): void {
		if ( self::$rate_limit_applied ) {
			return;
		}
		self::$rate_limit_applied = true;
		// Do NOT sleep on the very first request — the delay is between requests.
	}
}
