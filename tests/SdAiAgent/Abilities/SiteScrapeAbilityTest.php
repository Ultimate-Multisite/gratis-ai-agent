<?php

declare(strict_types=1);
/**
 * Tests for the site-scrape Theme Builder ability.
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
 * Covers parsers, heuristics, caching, and robots behaviour.
 */
class SiteScrapeAbilityTest extends WP_UnitTestCase {

	private array $responses = [];

	public function setUp(): void {
		parent::setUp();
		$this->responses = [];
		add_filter( 'pre_http_request', [ $this, 'mock_http' ], 10, 3 );
		add_filter( 'sd_ai_agent_site_scraper_rate_limit_seconds', [ $this, 'disable_rate_limit' ] );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'mock_http' ], 10 );
		remove_filter( 'sd_ai_agent_site_scraper_rate_limit_seconds', [ $this, 'disable_rate_limit' ] );
		parent::tearDown();
	}

	public function disable_rate_limit(): int {
		return 0;
	}

	/**
	 * @param false|array<string,mixed>|\WP_Error $preempt Preempted value.
	 * @param array<string,mixed>                 $args    Request args.
	 * @param string                              $url     Request URL.
	 * @return array<string,mixed>|false
	 */
	public function mock_http( $preempt, array $args, string $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! array_key_exists( $url, $this->responses ) ) {
			return false;
		}

		$body = $this->responses[ $url ];
		return [
			'headers'  => [ 'content-type' => str_ends_with( $url, 'robots.txt' ) ? 'text/plain' : 'text/html' ],
			'body'     => $body,
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	public function test_schema_org_parser_extracts_local_business_data(): void {
		$scraper = new SiteScraper();
		$html    = (string) file_get_contents( __DIR__ . '/../fixtures/site-scrape/local-business.html' );

		$result = $scraper->extract_from_html( 'https://bramble.example/', $html, wp_strip_all_tags( $html ) );

		$this->assertSame( 'Bramble & Bean Coffee Co.', $result['brand']['name'] );
		$this->assertSame( 'Slow down. Sip something good.', $result['brand']['tagline'] );
		$this->assertSame( '0131 555 0147', $result['contact']['phone'] );
		$this->assertSame( 'hello@bramble.example', $result['contact']['email'] );
		$this->assertStringContainsString( '47 Mill Lane', $result['contact']['address'] );
		$this->assertSame( 'https://instagram.com/bramblebean', $result['social']['instagram'] );
		$this->assertSame( 'Tue', $result['hours'][0]['day'] );
	}

	public function test_opengraph_parser_extracts_common_brand_metadata(): void {
		$scraper = new SiteScraper();
		$html    = (string) file_get_contents( __DIR__ . '/../fixtures/site-scrape/opengraph.html' );

		$result = $scraper->extract_from_html( 'https://studio.example/', $html, '' );

		$this->assertSame( 'North Pier Studio', $result['brand']['name'] );
		$this->assertSame( 'Thoughtful interiors by the coast.', $result['brand']['tagline'] );
		$this->assertSame( 'https://studio.example/og-logo.png', $result['brand']['logo_url'] );
	}

	public function test_heuristics_extract_phone_email_address_and_hours(): void {
		$scraper = new SiteScraper();
		$html    = (string) file_get_contents( __DIR__ . '/../fixtures/site-scrape/heuristics-cafe.html' );

		$result = $scraper->extract_from_html( 'https://heuristic.example/', $html, wp_strip_all_tags( $html ) );

		$this->assertSame( 'hello@heuristic.example', $result['contact']['email'] );
		$this->assertSame( '+44 131 555 0188', $result['contact']['phone'] );
		$this->assertStringContainsString( '12 Market Street', $result['contact']['address'] );
		$this->assertSame( 'Mon', $result['hours'][0]['day'] );
		$this->assertSame( '08:00', $result['hours'][0]['open'] );
		$this->assertSame( '17:30', $result['hours'][0]['close'] );
	}

	public function test_scrape_uses_transient_cache_to_prevent_redundant_fetches(): void {
		$this->responses = [
			'https://cached.example/robots.txt' => '',
			'https://cached.example/'           => (string) file_get_contents( __DIR__ . '/../fixtures/site-scrape/opengraph.html' ),
		];
		$scraper         = new SiteScraper();

		$first = $scraper->scrape( 'https://cached.example/', 1, [], 'structured_only' );
		$this->assertIsArray( $first );
		$this->assertFalse( $first['cached'] );

		$this->responses = [ 'https://cached.example/robots.txt' => '' ];
		$second          = $scraper->scrape( 'https://cached.example/', 1, [], 'structured_only' );
		$this->assertIsArray( $second );
		$this->assertTrue( $second['cached'] );
		$this->assertSame( 'North Pier Studio', $second['brand']['name'] );
	}

	public function test_scrape_respects_robots_txt_disallow(): void {
		$this->responses = [
			'https://blocked.example/robots.txt' => "User-agent: *\nDisallow: /",
		];

		$result = ( new SiteScraper() )->scrape( 'https://blocked.example/', 1, [], 'structured_only' );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_site_scrape_robots_disallowed', $result->get_error_code() );
	}

	public function test_site_scrape_ability_returns_complete_optional_shape(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->responses = [
			'https://ability.example/robots.txt' => '',
			'https://ability.example/'           => (string) file_get_contents( __DIR__ . '/../fixtures/site-scrape/local-business.html' ),
		];
		$ability         = new SiteScrapeAbility( 'sd-ai-agent/site-scrape' );

		$result = $ability->run(
			[
				'url'                 => 'https://ability.example/',
				'max_pages'           => 1,
				'extract_preferences' => 'structured_only',
			]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'brand', $result );
		$this->assertArrayHasKey( 'name', $result['brand'] );
		$this->assertArrayHasKey( 'contact', $result );
		$this->assertArrayHasKey( 'address', $result['contact'] );
		$this->assertArrayHasKey( 'social', $result );
		$this->assertArrayHasKey( 'facebook', $result['social'] );
		$this->assertSame( 'Bramble & Bean Coffee Co.', $result['brand']['name'] );
	}
}
