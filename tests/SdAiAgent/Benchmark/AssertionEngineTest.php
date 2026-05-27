<?php

declare(strict_types=1);
/**
 * Test case for benchmark assertion engine.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Benchmark;

use SdAiAgent\Benchmark\AssertionEngine;
use ReflectionMethod;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Test benchmark assertion behavior.
 */
class AssertionEngineTest extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down(): void {
		global $wp_rest_server;

		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Test REST endpoint registration assertions require an exact route match.
	 */
	public function test_rest_endpoint_registered_rejects_partial_route_matches(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				\register_rest_route(
					'sd-ai-agent-test-partial/v1',
					'/events-old',
					array(
						'methods'             => 'GET',
						'callback'            => static function (): WP_REST_Response {
							return new WP_REST_Response( array( 'ok' => true ) );
						},
						'permission_callback' => '__return_true',
					)
				);
			}
		);

		$result = AssertionEngine::run(
			array(
				array(
					'type'   => 'rest_endpoint_registered',
					'method' => 'GET',
					'path'   => '/sd-ai-agent-test-partial/v1/events',
				),
			)
		);

		$this->assertSame( 0, $result['passed'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertFalse( $result['results'][0]['pass'] );
		$this->assertSame( 'route not found in REST server', $result['results'][0]['actual'] );
	}

	/**
	 * Test REST endpoint registration assertions accept the exact normalized route.
	 */
	public function test_rest_endpoint_registered_accepts_exact_normalized_route(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				\register_rest_route(
					'sd-ai-agent-test-exact/v1',
					'/events',
					array(
						'methods'             => 'GET',
						'callback'            => static function (): WP_REST_Response {
							return new WP_REST_Response( array( 'ok' => true ) );
						},
						'permission_callback' => '__return_true',
					)
				);
			}
		);

		$result = AssertionEngine::run(
			array(
				array(
					'type'   => 'rest_endpoint_registered',
					'method' => 'GET',
					'path'   => 'sd-ai-agent-test-exact/v1/events/',
				),
			)
		);

		$this->assertSame( 1, $result['passed'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertTrue( $result['results'][0]['pass'] );
		$this->assertSame( 'found at route: /sd-ai-agent-test-exact/v1/events', $result['results'][0]['actual'] );
	}

	/**
	 * Test WP-CLI assertions target the active local site instead of old fixture URLs.
	 */
	public function test_wp_cli_benchmark_url_normalizes_historical_fixture_host(): void {
		$method = new ReflectionMethod( AssertionEngine::class, 'normalize_wp_cli_benchmark_url' );
		$method->setAccessible( true );

		$command    = 'post list --post_type=page --url=wp-multisite-waas.test';
		$normalized = (string) $method->invoke( null, $command );
		$site_url   = untrailingslashit( (string) preg_replace( '#^https?://#', '', home_url( '/' ) ) );

		$this->assertStringNotContainsString( 'wp-multisite-waas.test', $normalized );
		$this->assertStringContainsString( '--url=' . $site_url, $normalized );
	}

	/**
	 * Test WP-CLI URL normalization only rewrites --url option values.
	 */
	public function test_wp_cli_benchmark_url_leaves_non_url_occurrences_untouched(): void {
		$method = new ReflectionMethod( AssertionEngine::class, 'normalize_wp_cli_benchmark_url' );
		$method->setAccessible( true );

		$command    = 'option get wp-multisite-waas.test --url=wp-multisite-waas.test';
		$normalized = (string) $method->invoke( null, $command );
		$site_url   = untrailingslashit( (string) preg_replace( '#^https?://#', '', home_url( '/' ) ) );

		$this->assertSame( 'option get wp-multisite-waas.test --url=' . $site_url, $normalized );
	}

	/**
	 * Test WP-CLI URL normalization handles separate quoted --url option values.
	 */
	public function test_wp_cli_benchmark_url_normalizes_separate_quoted_url_values(): void {
		$method = new ReflectionMethod( AssertionEngine::class, 'normalize_wp_cli_benchmark_url' );
		$method->setAccessible( true );

		$command    = 'post list --url "https://wp-multisite-waas.test/subsite" --field=ID';
		$normalized = (string) $method->invoke( null, $command );
		$site_url   = untrailingslashit( (string) preg_replace( '#^https?://#', '', home_url( '/' ) ) );

		$this->assertSame( 'post list --url "https://' . $site_url . '/subsite" --field=ID', $normalized );
	}

	/**
	 * Test WP-CLI URL normalization does not rewrite fixture text in URL paths.
	 */
	public function test_wp_cli_benchmark_url_only_rewrites_fixture_host(): void {
		$method = new ReflectionMethod( AssertionEngine::class, 'normalize_wp_cli_benchmark_url' );
		$method->setAccessible( true );

		$command    = 'post list --url=example.test/wp-multisite-waas.test';
		$normalized = (string) $method->invoke( null, $command );

		$this->assertSame( $command, $normalized );
	}
}
