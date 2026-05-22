<?php

declare(strict_types=1);
/**
 * Tests for SafeHttpClient SSRF-safe HTTP wrapper.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core\Net;

use SdAiAgent\Core\Net\SafeHttpClient;
use SdAiAgent\Core\Net\SsrfGuard;
use WP_Error;
use WP_UnitTestCase;

/**
 * Test SafeHttpClient functionality.
 *
 * @since 1.9.0
 */
class SafeHttpClientTest extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		parent::tear_down();
		remove_all_filters( 'sd_ai_agent_safe_http_timeout' );
		remove_all_filters( 'sd_ai_agent_safe_http_max_size' );
		remove_all_filters( 'sd_ai_agent_ssrf_blocked_ranges' );
		remove_all_filters( 'sd_ai_agent_ssrf_allow_hosts' );
		remove_all_filters( 'sd_ai_agent_ssrf_check_ip' );
		remove_all_filters( 'pre_http_request' );
	}

	// ── safe_remote_get ──────────────────────────────────────────────────────

	/**
	 * Test that safe_remote_get blocks SSRF targets.
	 */
	public function test_safe_remote_get_blocks_ssrf(): void {
		$client = new SafeHttpClient();
		$result = $client->safe_remote_get( 'http://169.254.169.254/latest/meta-data/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that safe_remote_get blocks localhost.
	 */
	public function test_safe_remote_get_blocks_localhost(): void {
		$client = new SafeHttpClient();
		$result = $client->safe_remote_get( 'http://localhost/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that safe_remote_get allows public URLs (mocked HTTP).
	 */
	public function test_safe_remote_get_allows_public_url(): void {
		// Mock the HTTP response so we don't make real requests.
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( str_contains( $url, 'example.com' ) ) {
					return [
						'response' => [ 'code' => 200, 'message' => 'OK' ],
						'body'     => '<html>OK</html>',
						'headers'  => [],
					];
				}
				return $preempt;
			},
			10,
			3
		);

		// Use a test guard that resolves to a public IP.
		$guard  = new TestSsrfGuard();
		$guard->set_dns_result( [ '93.184.216.34' ] );
		$client = new SafeHttpClient( $guard );
		$result = $client->safe_remote_get( 'https://example.com/' );

		$this->assertIsArray( $result );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $result ) );
	}

	/**
	 * Test that default timeout is applied.
	 */
	public function test_default_timeout_is_applied(): void {
		// Intercept the HTTP request to check args.
		$captured_args = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => '',
					'headers'  => [],
				];
			},
			10,
			2
		);

		$guard  = new TestSsrfGuard();
		$guard->set_dns_result( [ '93.184.216.34' ] );
		$client = new SafeHttpClient( $guard );
		$client->safe_remote_get( 'https://example.com/' );

		$this->assertNotNull( $captured_args );
		$this->assertSame( 10, $captured_args['timeout'] );
	}

	/**
	 * Test that timeout filter is respected.
	 */
	public function test_timeout_filter_is_respected(): void {
		add_filter(
			'sd_ai_agent_safe_http_timeout',
			static function (): int {
				return 30;
			}
		);

		$captured_args = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => '',
					'headers'  => [],
				];
			},
			10,
			2
		);

		$guard  = new TestSsrfGuard();
		$guard->set_dns_result( [ '93.184.216.34' ] );
		$client = new SafeHttpClient( $guard );
		$client->safe_remote_get( 'https://example.com/' );

		$this->assertNotNull( $captured_args );
		$this->assertSame( 30, $captured_args['timeout'] );
	}

	/**
	 * Test that response size limit is set.
	 */
	public function test_response_size_limit_is_set(): void {
		$captured_args = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => '',
					'headers'  => [],
				];
			},
			10,
			2
		);

		$guard  = new TestSsrfGuard();
		$guard->set_dns_result( [ '93.184.216.34' ] );
		$client = new SafeHttpClient( $guard );
		$client->safe_remote_get( 'https://example.com/' );

		$this->assertNotNull( $captured_args );
		// 25 MB = 26214400 bytes.
		$this->assertSame( 26214400, $captured_args['limit_response_size'] );
	}

	// ── safe_download_url ────────────────────────────────────────────────────

	/**
	 * Test that safe_download_url blocks SSRF targets.
	 */
	public function test_safe_download_url_blocks_ssrf(): void {
		$client = new SafeHttpClient();
		$result = $client->safe_download_url( 'http://10.0.0.1/malicious' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that safe_download_url blocks IPv6 loopback.
	 */
	public function test_safe_download_url_blocks_ipv6_loopback(): void {
		$client = new SafeHttpClient();
		$result = $client->safe_download_url( 'http://[::1]/secret' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	// ── TOCTOU rebinding via SafeHttpClient ──────────────────────────────────

	/**
	 * Test that safe_remote_get blocks DNS rebinding (TOCTOU).
	 */
	public function test_safe_remote_get_blocks_toctou_rebinding(): void {
		$guard = new TestSsrfGuard();
		// Simulate rebinding: first IP is public, second is private.
		$guard->set_dns_result( [ '93.184.216.34', '192.168.1.1' ] );
		$client = new SafeHttpClient( $guard );

		$result = $client->safe_remote_get( 'https://evil.rebind.example.com/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	// ── Singleton ────────────────────────────────────────────────────────────

	/**
	 * Test that instance() returns a SafeHttpClient.
	 */
	public function test_instance_returns_client(): void {
		$instance = SafeHttpClient::instance();
		$this->assertInstanceOf( SafeHttpClient::class, $instance );
	}

	/**
	 * Test that instance() returns the same object on repeated calls.
	 */
	public function test_instance_returns_same_object(): void {
		$a = SafeHttpClient::instance();
		$b = SafeHttpClient::instance();
		$this->assertSame( $a, $b );
	}

	// ── Caller-supplied timeout override ─────────────────────────────────────

	/**
	 * Test that caller-supplied timeout overrides the default.
	 */
	public function test_caller_timeout_overrides_default(): void {
		$captured_args = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => '',
					'headers'  => [],
				];
			},
			10,
			2
		);

		$guard  = new TestSsrfGuard();
		$guard->set_dns_result( [ '93.184.216.34' ] );
		$client = new SafeHttpClient( $guard );
		$client->safe_remote_get( 'https://example.com/', [ 'timeout' => 45 ] );

		$this->assertNotNull( $captured_args );
		$this->assertSame( 45, $captured_args['timeout'] );
	}
}
