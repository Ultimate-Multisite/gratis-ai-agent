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

		$this->remove_benchmark_fixture_plugin( 'sd-ai-agent-init-cpt-fixture' );
		if ( post_type_exists( 'sd_test_event' ) && function_exists( 'unregister_post_type' ) ) {
			unregister_post_type( 'sd_test_event' );
		}

		parent::tear_down();
	}

	/**
	 * Test activation replays init callbacks added when activate_plugin() includes the plugin.
	 */
	public function test_plugin_activation_replays_init_callbacks_registered_during_activation_include(): void {
		$this->write_benchmark_fixture_plugin(
			'sd-ai-agent-init-cpt-fixture',
			<<<'PHP'
<?php
/**
 * Plugin Name: SD AI Agent Init CPT Fixture
 */

add_action( 'init', 'sd_ai_agent_fixture_register_post_type' );

function sd_ai_agent_fixture_register_post_type(): void {
	register_post_type(
		'sd_test_event',
		array(
			'public' => true,
			'label'  => 'SD Test Events',
		)
	);
}
PHP
		);

		$result = AssertionEngine::run(
			array(
				array(
					'type' => 'plugin_activates',
				),
				array(
					'type'      => 'post_type_registered',
					'post_type' => 'sd_test_event',
				),
			),
			array(
				'plugin_slug' => 'sd-ai-agent-init-cpt-fixture',
			)
		);

		$this->assertSame( 2, $result['passed'], (string) wp_json_encode( $result ) );
		$this->assertSame( 0, $result['failed'], (string) wp_json_encode( $result ) );
		$this->assertTrue( $result['results'][1]['pass'] );
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

	/**
	 * Write a single-file benchmark fixture plugin.
	 *
	 * @param string $slug    Plugin slug.
	 * @param string $content Plugin file contents.
	 */
	private function write_benchmark_fixture_plugin( string $slug, string $content ): void {
		$directory = WP_PLUGIN_DIR . '/' . $slug;
		if ( ! is_dir( $directory ) ) {
			mkdir( $directory, 0777, true );
		}

		file_put_contents( $directory . '/' . $slug . '.php', $content );
	}

	/**
	 * Remove a benchmark fixture plugin and clear activation state.
	 *
	 * @param string $slug Plugin slug.
	 */
	private function remove_benchmark_fixture_plugin( string $slug ): void {
		$plugin_file = $slug . '/' . $slug . '.php';
		if ( function_exists( 'deactivate_plugins' ) ) {
			deactivate_plugins( $plugin_file, true );
		}

		$active_plugins = array_filter(
			(array) get_option( 'active_plugins', array() ),
			static fn( $plugin ): bool => $plugin_file !== $plugin
		);
		update_option( 'active_plugins', array_values( $active_plugins ) );

		$file      = WP_PLUGIN_DIR . '/' . $plugin_file;
		$directory = WP_PLUGIN_DIR . '/' . $slug;
		if ( is_file( $file ) ) {
			unlink( $file );
		}
		if ( is_dir( $directory ) ) {
			rmdir( $directory );
		}
	}
}
