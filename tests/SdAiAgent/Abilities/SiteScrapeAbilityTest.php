<?php

declare(strict_types=1);
/**
 * Test case for SiteScrapeAbility and SiteScraper.
 *
 * All tests that exercise HTTP fetch, robots.txt, caching, and parsing
 * mock at the WordPress HTTP layer via the `pre_http_request` filter so
 * they never make real network requests. This is the recommended approach
 * from the WordPress testing handbook and avoids the URL-validator problem
 * that caused the previous PR #1537 failures (the validator was rejecting
 * fixture URLs like https://example.com before the cache/robots/parser
 * code was ever reached).
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\SiteScrapeAbility;
use SdAiAgent\Services\SiteScraper;
use WP_UnitTestCase;

/**
 * Test SiteScrapeAbility and SiteScraper.
 */
class SiteScrapeAbilityTest extends WP_UnitTestCase {

	/**
	 * Remove any pre_http_request filters added during tests.
	 */
	public function tearDown(): void {
		parent::tearDown();
		remove_all_filters( 'pre_http_request' );
		// Clear any transients set during tests.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sd_ai_agent_scrape_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_sd_ai_agent_scrape_%'" );
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Build a SiteScraper instance.
	 *
	 * @return SiteScraper
	 */
	private function make_scraper(): SiteScraper {
		return new SiteScraper();
	}

	/**
	 * Build a SiteScrapeAbility instance for testing.
	 *
	 * @return SiteScrapeAbility
	 */
	private function make_ability(): SiteScrapeAbility {
		return new SiteScrapeAbility(
			'sd-ai-agent/site-scrape',
			[
				'label'       => 'Scrape Existing Site',
				'description' => 'Fetch and parse an existing website.',
			]
		);
	}

	/**
	 * Register a pre_http_request filter that intercepts requests to a host
	 * and returns the given HTML body with a 200 status.
	 *
	 * @param string $host_fragment Substring of the URL to intercept (e.g. 'example.com').
	 * @param string $html          HTML response body to return.
	 */
	private function mock_http_html( string $host_fragment, string $html ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $host_fragment, $html ) {
				if ( false !== strpos( $url, $host_fragment ) ) {
					return [
						'headers'  => [ 'content-type' => 'text/html; charset=UTF-8' ],
						'body'     => $html,
						'response' => [ 'code' => 200, 'message' => 'OK' ],
						'cookies'  => [],
						'filename' => '',
					];
				}
				return $preempt;
			},
			10,
			3
		);
	}

	/**
	 * Register a pre_http_request filter that intercepts robots.txt requests
	 * and returns the given robots.txt content with a 200 status, while
	 * intercepting all other requests to the host with the given HTML.
	 *
	 * @param string $host_fragment Substring of the URL to intercept.
	 * @param string $robots_txt   robots.txt content.
	 * @param string $html         HTML response body for non-robots requests.
	 */
	private function mock_http_with_robots( string $host_fragment, string $robots_txt, string $html ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $host_fragment, $robots_txt, $html ) {
				if ( false === strpos( $url, $host_fragment ) ) {
					return $preempt;
				}

				if ( false !== strpos( $url, '/robots.txt' ) ) {
					return [
						'headers'  => [ 'content-type' => 'text/plain' ],
						'body'     => $robots_txt,
						'response' => [ 'code' => 200, 'message' => 'OK' ],
						'cookies'  => [],
						'filename' => '',
					];
				}

				return [
					'headers'  => [ 'content-type' => 'text/html; charset=UTF-8' ],
					'body'     => $html,
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'cookies'  => [],
					'filename' => '',
				];
			},
			10,
			3
		);
	}

	// ── URL validation ────────────────────────────────────────────────────

	/**
	 * is_valid_url() accepts a well-formed https URL.
	 */
	public function test_is_valid_url_accepts_https_url(): void {
		$scraper = $this->make_scraper();
		$this->assertTrue( $scraper->is_valid_url( 'https://example.com/' ) );
	}

	/**
	 * is_valid_url() accepts a well-formed http URL.
	 */
	public function test_is_valid_url_accepts_http_url(): void {
		$scraper = $this->make_scraper();
		$this->assertTrue( $scraper->is_valid_url( 'http://example.com/page' ) );
	}

	/**
	 * is_valid_url() accepts a URL with a non-standard port.
	 */
	public function test_is_valid_url_accepts_url_with_port(): void {
		$scraper = $this->make_scraper();
		$this->assertTrue( $scraper->is_valid_url( 'https://example.com:8080/path' ) );
	}

	/**
	 * is_valid_url() rejects an empty string.
	 */
	public function test_is_valid_url_rejects_empty_string(): void {
		$scraper = $this->make_scraper();
		$this->assertFalse( $scraper->is_valid_url( '' ) );
	}

	/**
	 * is_valid_url() rejects a relative URL.
	 */
	public function test_is_valid_url_rejects_relative_url(): void {
		$scraper = $this->make_scraper();
		$this->assertFalse( $scraper->is_valid_url( '/some/path' ) );
	}

	/**
	 * is_valid_url() rejects a ftp:// URL.
	 */
	public function test_is_valid_url_rejects_ftp_url(): void {
		$scraper = $this->make_scraper();
		$this->assertFalse( $scraper->is_valid_url( 'ftp://example.com/file' ) );
	}

	// ── Schema.org parser ─────────────────────────────────────────────────

	/**
	 * Schema.org JSON-LD parser extracts LocalBusiness data.
	 *
	 * Regression: PR #1537 failed with "Undefined array key 0" because the
	 * ability rejected example.com before reaching this code. The URL validator
	 * now accepts any valid http/https URL, and this test verifies the parser
	 * independently via parse_page().
	 */
	public function test_schema_org_parser_extracts_local_business_data(): void {
		$html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<title>Bramble &amp; Bean Coffee Co.</title>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "CafeOrCoffeeShop",
  "name": "Bramble & Bean Coffee Co.",
  "slogan": "Slow down. Sip something good.",
  "telephone": "+44 131 555 0147",
  "email": "hello@brambleandbean.example",
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "47 Mill Lane",
    "addressLocality": "Hawthornden",
    "addressRegion": "Edinburgh",
    "postalCode": "EH18 1AA"
  },
  "logo": {
    "@type": "ImageObject",
    "url": "https://brambleandbean.example/logo.png"
  },
  "sameAs": [
    "https://instagram.com/brambleandbean",
    "https://facebook.com/brambleandbeanEdinburgh"
  ],
  "openingHoursSpecification": [
    {
      "@type": "OpeningHoursSpecification",
      "dayOfWeek": ["Monday","Tuesday","Wednesday","Thursday","Friday"],
      "opens": "07:00",
      "closes": "16:00"
    },
    {
      "@type": "OpeningHoursSpecification",
      "dayOfWeek": ["Saturday"],
      "opens": "08:00",
      "closes": "14:00"
    }
  ]
}
</script>
</head>
<body><p>Welcome to Bramble &amp; Bean.</p></body>
</html>
HTML;

		$scraper = $this->make_scraper();
		$page    = $scraper->parse_page( 'https://brambleandbean.example/', $html );

		$this->assertIsArray( $page );
		$extracted = $page['extracted'];

		// Brand.
		$this->assertSame( 'Bramble & Bean Coffee Co.', $extracted['brand']['name'] );
		$this->assertSame( 'Slow down. Sip something good.', $extracted['brand']['tagline'] );
		$this->assertSame( 'https://brambleandbean.example/logo.png', $extracted['brand']['logo_url'] );

		// Contact.
		$this->assertSame( '+44 131 555 0147', $extracted['contact']['phone'] );
		$this->assertSame( 'hello@brambleandbean.example', $extracted['contact']['email'] );
		$this->assertStringContainsString( 'Hawthornden', $extracted['contact']['address'] );

		// Social.
		$this->assertSame( 'https://instagram.com/brambleandbean', $extracted['social']['instagram'] );
		$this->assertSame( 'https://facebook.com/brambleandbeanEdinburgh', $extracted['social']['facebook'] );

		// Hours — 5 weekday entries + 1 Saturday.
		$this->assertCount( 6, $extracted['hours'] );
		$this->assertSame( 'Mon', $extracted['hours'][0]['day'] );
		$this->assertSame( '07:00', $extracted['hours'][0]['open'] );
		$this->assertSame( '16:00', $extracted['hours'][0]['close'] );
		$this->assertSame( 'Sat', $extracted['hours'][5]['day'] );
		$this->assertSame( '08:00', $extracted['hours'][5]['open'] );
	}

	/**
	 * Schema.org JSON-LD parser extracts @graph arrays.
	 */
	public function test_schema_org_parser_extracts_graph_array(): void {
		$html = <<<'HTML'
<!DOCTYPE html>
<html><head>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "WebSite",
      "name": "Graph Site",
      "url": "https://graphsite.example"
    },
    {
      "@type": "Organization",
      "name": "Graph Org",
      "telephone": "555-1234"
    }
  ]
}
</script>
</head><body></body></html>
HTML;

		$scraper = $this->make_scraper();
		$page    = $scraper->parse_page( 'https://graphsite.example/', $html );
		$extracted = $page['extracted'];

		$this->assertSame( 'Graph Site', $extracted['brand']['name'] );
		$this->assertSame( '555-1234', $extracted['contact']['phone'] );
	}

	// ── OpenGraph parser ──────────────────────────────────────────────────

	/**
	 * OpenGraph meta tags are parsed for brand data.
	 */
	public function test_opengraph_parser_extracts_brand(): void {
		$html = <<<'HTML'
<!DOCTYPE html>
<html><head>
<meta property="og:site_name" content="OG Brand Name" />
<meta property="og:description" content="OG tagline here" />
<meta property="og:image" content="https://og.example/hero.jpg" />
</head><body></body></html>
HTML;

		$scraper = $this->make_scraper();
		$page    = $scraper->parse_page( 'https://og.example/', $html );
		$extracted = $page['extracted'];

		$this->assertSame( 'OG Brand Name', $extracted['brand']['name'] );
		$this->assertSame( 'OG tagline here', $extracted['brand']['tagline'] );
		$this->assertSame( 'https://og.example/hero.jpg', $extracted['brand']['logo_url'] );
	}

	// ── Hours heuristic ───────────────────────────────────────────────────

	/**
	 * Hours heuristic extracts table-based opening hours.
	 */
	public function test_hours_heuristic_extracts_table_hours(): void {
		$html = <<<'HTML'
<!DOCTYPE html>
<html><body>
<table>
<tr><td>Monday</td><td>09:00 - 17:00</td></tr>
<tr><td>Tuesday</td><td>09:00 - 17:00</td></tr>
<tr><td>Saturday</td><td>10:00 - 14:00</td></tr>
<tr><td>Sunday</td><td>Closed</td></tr>
</table>
</body></html>
HTML;

		$scraper = $this->make_scraper();
		$hours   = $scraper->extract_hours_heuristic( $html );

		$this->assertIsArray( $hours );
		$this->assertGreaterThanOrEqual( 2, count( $hours ) );

		$days = array_column( $hours, 'day' );
		$this->assertContains( 'Mon', $days );
		$this->assertContains( 'Sat', $days );
	}

	// ── Phone heuristic ───────────────────────────────────────────────────

	/**
	 * Heuristic extraction finds a phone number in plain text.
	 */
	public function test_phone_heuristic_extracts_phone_number(): void {
		$html = '<html><body><p>Call us on 0131 555 0147 during office hours.</p></body></html>';

		$scraper   = $this->make_scraper();
		$page      = $scraper->parse_page( 'https://phone.example/', $html );
		$extracted = $page['extracted'];

		$this->assertNotNull( $extracted['contact']['phone'] ?? null );
		$this->assertStringContainsString( '0131', $extracted['contact']['phone'] );
	}

	// ── Address heuristic — covered by Schema.org test above ─────────────

	/**
	 * Heuristic extraction finds an email address in plain text.
	 */
	public function test_email_heuristic_extracts_email(): void {
		$html = '<html><body><p>Email us at info@contacttest.example for enquiries.</p></body></html>';

		$scraper   = $this->make_scraper();
		$page      = $scraper->parse_page( 'https://contacttest.example/', $html );
		$extracted = $page['extracted'];

		$this->assertSame( 'info@contacttest.example', $extracted['contact']['email'] );
	}

	// ── Cache layer ───────────────────────────────────────────────────────

	/**
	 * scrape() uses transient cache to prevent redundant fetches.
	 *
	 * Regression: PR #1537 failed with sd_ai_agent_invalid_scrape_url
	 * before reaching the cache check. The validator now accepts https://example.com.
	 */
	public function test_scrape_uses_transient_cache_to_prevent_redundant_fetches(): void {
		$url  = 'https://cached.example/';
		$html = <<<'HTML'
<!DOCTYPE html>
<html><head>
<script type="application/ld+json">
{"@type":"Organization","name":"Cached Org","telephone":"999-0000"}
</script>
</head><body></body></html>
HTML;

		// First request: mock HTTP to return the HTML.
		$this->mock_http_html( 'cached.example', $html );

		$scraper = $this->make_scraper();

		// First call — should fetch via HTTP and cache.
		$result1 = $scraper->scrape( $url );
		$this->assertIsArray( $result1, 'First scrape should return an array.' );
		$this->assertSame( 'Cached Org', $result1['brand']['name'] );

		// Remove the HTTP mock — second call must use cache, not make a request.
		remove_all_filters( 'pre_http_request' );

		$result2 = $scraper->scrape( $url );
		$this->assertIsArray( $result2, 'Second scrape should return cached array.' );
		$this->assertSame( 'Cached Org', $result2['brand']['name'], 'Cache should return same brand name.' );
	}

	// ── robots.txt respect ────────────────────────────────────────────────

	/**
	 * scrape() respects robots.txt Disallow directives.
	 *
	 * Regression: PR #1537 returned sd_ai_agent_invalid_scrape_url instead of
	 * sd_ai_agent_site_scrape_robots_disallowed. The validator now accepts the URL,
	 * so the robots check is reached.
	 */
	public function test_scrape_respects_robots_txt_disallow(): void {
		$robots_txt = "User-agent: *\nDisallow: /\n";

		$this->mock_http_with_robots(
			'robots-blocked.example',
			$robots_txt,
			'<html><body>content</body></html>'
		);

		$scraper = $this->make_scraper();
		$result  = $scraper->scrape( 'https://robots-blocked.example/page' );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_site_scrape_robots_disallowed', $result->get_error_code() );
	}

	/**
	 * parse_robots_txt() returns true when the path is not disallowed.
	 */
	public function test_parse_robots_txt_allows_non_disallowed_path(): void {
		$robots_txt = "User-agent: *\nDisallow: /admin/\n";
		$scraper    = $this->make_scraper();

		$this->assertTrue( $scraper->parse_robots_txt( $robots_txt, '/about' ) );
	}

	/**
	 * parse_robots_txt() returns false when the path is explicitly disallowed.
	 */
	public function test_parse_robots_txt_rejects_disallowed_path(): void {
		$robots_txt = "User-agent: *\nDisallow: /private/\n";
		$scraper    = $this->make_scraper();

		$this->assertFalse( $scraper->parse_robots_txt( $robots_txt, '/private/page' ) );
	}

	/**
	 * parse_robots_txt() returns true when robots.txt is empty (permissive default).
	 */
	public function test_parse_robots_txt_allows_when_empty(): void {
		$scraper = $this->make_scraper();
		$this->assertTrue( $scraper->parse_robots_txt( '', '/any/path' ) );
	}

	/**
	 * parse_robots_txt() returns true when Disallow: / has an Allow: override for the path.
	 */
	public function test_parse_robots_txt_allow_overrides_disallow(): void {
		$robots_txt = "User-agent: *\nDisallow: /\nAllow: /public/\n";
		$scraper    = $this->make_scraper();

		$this->assertTrue( $scraper->parse_robots_txt( $robots_txt, '/public/page' ) );
	}

	// ── Full ability output shape ─────────────────────────────────────────

	/**
	 * scrape() returns the complete optional shape with all fields present.
	 *
	 * Regression: PR #1537 returned sd_ai_agent_invalid_scrape_url before
	 * any data was extracted. The validator now accepts https://example.com.
	 */
	public function test_site_scrape_ability_returns_complete_optional_shape(): void {
		$url  = 'https://shape.example/';
		$html = <<<'HTML'
<!DOCTYPE html>
<html><head>
<title>Shape Test Site</title>
<script type="application/ld+json">
{
  "@type": "LocalBusiness",
  "name": "Shape Business",
  "telephone": "+1 555 123 4567",
  "email": "info@shape.example",
  "address": {"@type": "PostalAddress", "streetAddress": "1 Main St", "addressLocality": "Testville"},
  "openingHoursSpecification": [
    {"dayOfWeek":["Monday"],"opens":"09:00","closes":"18:00"}
  ],
  "sameAs": ["https://instagram.com/shapebusiness"]
}
</script>
</head><body><p>Welcome to Shape Business.</p></body></html>
HTML;

		$this->mock_http_html( 'shape.example', $html );

		$scraper = $this->make_scraper();
		$result  = $scraper->scrape( $url );

		$this->assertIsArray( $result, 'scrape() must return an array for a valid URL.' );

		// All top-level keys must be present.
		$this->assertArrayHasKey( 'brand', $result );
		$this->assertArrayHasKey( 'contact', $result );
		$this->assertArrayHasKey( 'hours', $result );
		$this->assertArrayHasKey( 'social', $result );
		$this->assertArrayHasKey( 'pages', $result );

		// Brand sub-keys.
		$this->assertArrayHasKey( 'name', $result['brand'] );
		$this->assertArrayHasKey( 'tagline', $result['brand'] );
		$this->assertArrayHasKey( 'logo_url', $result['brand'] );

		// Contact sub-keys.
		$this->assertArrayHasKey( 'address', $result['contact'] );
		$this->assertArrayHasKey( 'phone', $result['contact'] );
		$this->assertArrayHasKey( 'email', $result['contact'] );

		// Extracted data.
		$this->assertSame( 'Shape Business', $result['brand']['name'] );
		$this->assertSame( '+1 555 123 4567', $result['contact']['phone'] );
		$this->assertSame( 'info@shape.example', $result['contact']['email'] );
		$this->assertStringContainsString( 'Main St', $result['contact']['address'] );
		$this->assertNotEmpty( $result['hours'] );
		$this->assertSame( 'https://instagram.com/shapebusiness', $result['social']['instagram'] );
	}

	// ── Invalid URL returns WP_Error ──────────────────────────────────────

	/**
	 * scrape() returns WP_Error for an empty URL.
	 */
	public function test_scrape_returns_wp_error_for_empty_url(): void {
		$scraper = $this->make_scraper();
		$result  = $scraper->scrape( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_invalid_scrape_url', $result->get_error_code() );
	}

	/**
	 * scrape() returns WP_Error for a relative URL.
	 */
	public function test_scrape_returns_wp_error_for_relative_url(): void {
		$scraper = $this->make_scraper();
		$result  = $scraper->scrape( '/not-absolute' );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_invalid_scrape_url', $result->get_error_code() );
	}

	// ── Ability registration ──────────────────────────────────────────────

	/**
	 * SiteScrapeAbility has the expected input schema with required url.
	 */
	public function test_ability_has_url_as_required(): void {
		$ability = $this->make_ability();
		$schema  = $ability->get_input_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'url', $schema['required'] );
	}

	/**
	 * SiteScrapeAbility execute_callback returns WP_Error for empty url.
	 */
	public function test_ability_returns_wp_error_for_missing_url(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$ability = $this->make_ability();
		$result  = $ability->run( [] );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_invalid_scrape_url', $result->get_error_code() );
	}

	/**
	 * SiteScrapeAbility execute_callback returns array for valid url with mocked HTTP.
	 */
	public function test_ability_returns_array_for_valid_url(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$url  = 'https://ability-test.example/';
		$html = '<html><head><title>Ability Test</title></head><body>Ability test content</body></html>';

		$this->mock_http_html( 'ability-test.example', $html );

		$ability = $this->make_ability();
		$result  = $ability->run( [ 'url' => $url ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'brand', $result );
	}
}
