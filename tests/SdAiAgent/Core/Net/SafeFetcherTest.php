<?php

declare(strict_types=1);
/**
 * Tests for SafeFetcher SSRF-hardened URL fetcher.
 *
 * @package SdAiAgent\Tests\Core\Net
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core\Net;

use SdAiAgent\Core\Net\SafeFetcher;
use SdAiAgent\Core\Net\SsrfGuard;
use WP_UnitTestCase;

/**
 * Test SafeFetcher SSRF protections and response handling.
 *
 * @since 1.9.0
 */
class SafeFetcherTest extends WP_UnitTestCase {

	/**
	 * SafeFetcher instance.
	 *
	 * @var SafeFetcher
	 */
	private SafeFetcher $fetcher;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->fetcher = new SafeFetcher();
	}

	/**
	 * Test that invalid URLs are rejected.
	 */
	public function test_invalid_url_rejected(): void {
		$result = $this->fetcher->fetch( 'not a url' );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	/**
	 * Test that non-http/https schemes are rejected.
	 */
	public function test_invalid_scheme_rejected(): void {
		$result = $this->fetcher->fetch( 'file:///etc/passwd' );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_scheme', $result->get_error_code() );
	}

	/**
	 * Test that gopher:// scheme is rejected.
	 */
	public function test_gopher_scheme_rejected(): void {
		$result = $this->fetcher->fetch( 'gopher://example.com' );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_scheme', $result->get_error_code() );
	}

	/**
	 * Test that bare IPv4 loopback is rejected.
	 */
	public function test_bare_ipv4_loopback_rejected(): void {
		$result = $this->fetcher->fetch( 'http://127.0.0.1/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that bare IPv4 private range is rejected.
	 */
	public function test_bare_ipv4_private_rejected(): void {
		$result = $this->fetcher->fetch( 'http://192.168.1.1/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that bare IPv4 link-local is rejected.
	 */
	public function test_bare_ipv4_link_local_rejected(): void {
		$result = $this->fetcher->fetch( 'http://169.254.169.254/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that IPv6 loopback is rejected.
	 */
	public function test_ipv6_loopback_rejected(): void {
		$result = $this->fetcher->fetch( 'http://[::1]/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that IPv6 link-local is rejected.
	 */
	public function test_ipv6_link_local_rejected(): void {
		$result = $this->fetcher->fetch( 'http://[fe80::1]/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that IPv6 ULA is rejected.
	 */
	public function test_ipv6_ula_rejected(): void {
		$result = $this->fetcher->fetch( 'http://[fc00::1]/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that IPv6 IPv4-mapped private is rejected.
	 */
	public function test_ipv6_ipv4_mapped_private_rejected(): void {
		$result = $this->fetcher->fetch( 'http://[::ffff:127.0.0.1]/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that 3xx redirects are blocked.
	 */
	public function test_redirect_blocked(): void {
		// Mock DNS resolution and HTTP response for a 302.
		add_filter(
			'pre_http_request',
			function ( $preempt, $r, $url ) {
				if ( strpos( $url, 'example.com' ) !== false ) {
					return [
						'headers'       => [],
						'body'          => '',
						'response'      => [ 'code' => 302 ],
						'cookies'       => [],
						'http_response' => null,
					];
				}
				return $preempt;
			},
			10,
			3
		);

		// Mock DNS to return a public IP.
		add_filter(
			'sd_ai_agent_ssrf_allow_hosts',
			function ( $hosts ) {
				$hosts[] = 'example.com';
				return $hosts;
			}
		);

		$result = $this->fetcher->fetch( 'http://example.com/' );
		$this->assertWPError( $result );
		$this->assertSame( 'fetch_redirect_blocked', $result->get_error_code() );

		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'sd_ai_agent_ssrf_allow_hosts' );
	}

	/**
	 * Test that 4xx errors are returned.
	 */
	public function test_4xx_error_returned(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $r, $url ) {
				if ( strpos( $url, 'example.com' ) !== false ) {
					return [
						'headers'       => [],
						'body'          => '',
						'response'      => [ 'code' => 404 ],
						'cookies'       => [],
						'http_response' => null,
					];
				}
				return $preempt;
			},
			10,
			3
		);

		add_filter(
			'sd_ai_agent_ssrf_allow_hosts',
			function ( $hosts ) {
				$hosts[] = 'example.com';
				return $hosts;
			}
		);

		$result = $this->fetcher->fetch( 'http://example.com/' );
		$this->assertWPError( $result );
		$this->assertSame( 'fetch_error', $result->get_error_code() );

		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'sd_ai_agent_ssrf_allow_hosts' );
	}

	/**
	 * Test that response body is truncated at 100 KB.
	 */
	public function test_response_truncated_at_100kb(): void {
		// Create a large response body.
		$large_body = str_repeat( 'x', 150000 );

		add_filter(
			'pre_http_request',
			function ( $preempt, $r, $url ) use ( $large_body ) {
				if ( strpos( $url, 'example.com' ) !== false ) {
					return [
						'headers'       => [],
						'body'          => $large_body,
						'response'      => [ 'code' => 200 ],
						'cookies'       => [],
						'http_response' => null,
					];
				}
				return $preempt;
			},
			10,
			3
		);

		add_filter(
			'sd_ai_agent_ssrf_allow_hosts',
			function ( $hosts ) {
				$hosts[] = 'example.com';
				return $hosts;
			}
		);

		$result = $this->fetcher->fetch( 'http://example.com/' );
		$this->assertIsString( $result );
		$this->assertStringContainsString( '[…truncated at 102400 bytes]', $result );

		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'sd_ai_agent_ssrf_allow_hosts' );
	}

	/**
	 * Test that HTML tags are stripped from response.
	 */
	public function test_html_tags_stripped(): void {
		$html_body = '<html><head><title>Test</title></head><body><p>Hello World</p></body></html>';

		add_filter(
			'pre_http_request',
			function ( $preempt, $r, $url ) use ( $html_body ) {
				if ( strpos( $url, 'example.com' ) !== false ) {
					return [
						'headers'       => [],
						'body'          => $html_body,
						'response'      => [ 'code' => 200 ],
						'cookies'       => [],
						'http_response' => null,
					];
				}
				return $preempt;
			},
			10,
			3
		);

		add_filter(
			'sd_ai_agent_ssrf_allow_hosts',
			function ( $hosts ) {
				$hosts[] = 'example.com';
				return $hosts;
			}
		);

		$result = $this->fetcher->fetch( 'http://example.com/' );
		$this->assertIsString( $result );
		$this->assertStringNotContainsString( '<html>', $result );
		$this->assertStringNotContainsString( '<p>', $result );
		$this->assertStringContainsString( 'Hello World', $result );

		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'sd_ai_agent_ssrf_allow_hosts' );
	}

	/**
	 * Test that happy path with valid public URL works.
	 */
	public function test_valid_public_url_succeeds(): void {
		$body = 'Test content from public URL';

		add_filter(
			'pre_http_request',
			function ( $preempt, $r, $url ) use ( $body ) {
				if ( strpos( $url, 'example.com' ) !== false ) {
					return [
						'headers'       => [],
						'body'          => $body,
						'response'      => [ 'code' => 200 ],
						'cookies'       => [],
						'http_response' => null,
					];
				}
				return $preempt;
			},
			10,
			3
		);

		add_filter(
			'sd_ai_agent_ssrf_allow_hosts',
			function ( $hosts ) {
				$hosts[] = 'example.com';
				return $hosts;
			}
		);

		$result = $this->fetcher->fetch( 'http://example.com/' );
		$this->assertIsString( $result );
		$this->assertSame( $body, $result );

		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'sd_ai_agent_ssrf_allow_hosts' );
	}

	/**
	 * Test that custom guard instance is used.
	 */
	public function test_custom_guard_instance(): void {
		$guard   = new SsrfGuard();
		$fetcher = new SafeFetcher( $guard );

		$result = $fetcher->fetch( 'http://127.0.0.1/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test singleton instance.
	 */
	public function test_singleton_instance(): void {
		$instance1 = SafeFetcher::instance();
		$instance2 = SafeFetcher::instance();

		$this->assertSame( $instance1, $instance2 );
	}
}
