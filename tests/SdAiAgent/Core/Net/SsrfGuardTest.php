<?php

declare(strict_types=1);
/**
 * Tests for SsrfGuard SSRF protection.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core\Net;

use SdAiAgent\Core\Net\SsrfGuard;
use WP_UnitTestCase;

/**
 * Test SSRF guard URL validation.
 *
 * @since 1.9.0
 */
class SsrfGuardTest extends WP_UnitTestCase {

	/**
	 * Guard instance (uses a mock that controls DNS resolution).
	 *
	 * @var SsrfGuard|TestSsrfGuard
	 */
	private SsrfGuard $guard;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->guard = new TestSsrfGuard();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		parent::tear_down();
		remove_all_filters( 'sd_ai_agent_ssrf_blocked_ranges' );
		remove_all_filters( 'sd_ai_agent_ssrf_allow_hosts' );
		remove_all_filters( 'sd_ai_agent_ssrf_check_ip' );
	}

	// ── Public URL tests ─────────────────────────────────────────────────────

	/**
	 * Test that a public HTTPS URL is allowed.
	 */
	public function test_public_https_url_is_allowed(): void {
		$this->guard->set_dns_result( [ '93.184.216.34' ] );
		$result = $this->guard->assert_safe_url( 'https://example.com/' );
		$this->assertTrue( $result );
	}

	/**
	 * Test that a public HTTP URL is allowed.
	 */
	public function test_public_http_url_is_allowed(): void {
		$this->guard->set_dns_result( [ '93.184.216.34' ] );
		$result = $this->guard->assert_safe_url( 'http://example.com/page' );
		$this->assertTrue( $result );
	}

	// ── Cloud metadata tests ─────────────────────────────────────────────────

	/**
	 * Test that AWS/GCP metadata IP is blocked.
	 */
	public function test_aws_metadata_ip_is_blocked(): void {
		$result = $this->guard->assert_safe_url( 'http://169.254.169.254/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that AWS metadata with path is blocked.
	 */
	public function test_aws_metadata_with_path_is_blocked(): void {
		$result = $this->guard->assert_safe_url( 'http://169.254.169.254/latest/meta-data/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that GCP metadata hostname is blocked.
	 */
	public function test_gcp_metadata_hostname_is_blocked(): void {
		$this->guard->set_dns_result( [ '169.254.169.254' ] );
		$result = $this->guard->assert_safe_url( 'http://metadata.google.internal/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	// ── RFC1918 private range tests ──────────────────────────────────────────

	/**
	 * Test that 10.x.x.x is blocked.
	 */
	public function test_rfc1918_10_range_is_blocked(): void {
		$result = $this->guard->assert_safe_url( 'http://10.0.0.5/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that 10.255.255.255 is blocked.
	 */
	public function test_rfc1918_10_range_upper_bound_is_blocked(): void {
		$result = $this->guard->assert_safe_url( 'http://10.255.255.255/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that 172.16.x.x is blocked.
	 */
	public function test_rfc1918_172_16_range_is_blocked(): void {
		$result = $this->guard->assert_safe_url( 'http://172.16.0.1/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that 172.31.255.255 is blocked.
	 */
	public function test_rfc1918_172_31_range_upper_bound_is_blocked(): void {
		$result = $this->guard->assert_safe_url( 'http://172.31.255.255/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that 172.32.0.0 (outside /12) is NOT blocked.
	 */
	public function test_rfc1918_172_32_is_not_blocked(): void {
		$result = $this->guard->assert_safe_url( 'http://172.32.0.1/' );
		$this->assertTrue( $result );
	}

	/**
	 * Test that 192.168.x.x is blocked.
	 */
	public function test_rfc1918_192_168_range_is_blocked(): void {
		$result = $this->guard->assert_safe_url( 'http://192.168.0.1/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that 192.168.255.255 is blocked.
	 */
	public function test_rfc1918_192_168_upper_bound_is_blocked(): void {
		$result = $this->guard->assert_safe_url( 'http://192.168.255.255/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	// ── Loopback tests ───────────────────────────────────────────────────────

	/**
	 * Test that 127.0.0.1 is blocked.
	 */
	public function test_loopback_127_0_0_1_is_blocked(): void {
		$result = $this->guard->assert_safe_url( 'http://127.0.0.1/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that 127.0.0.2 is blocked (entire /8).
	 */
	public function test_loopback_127_range_is_blocked(): void {
		$result = $this->guard->assert_safe_url( 'http://127.0.0.2/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that localhost is blocked.
	 */
	public function test_localhost_hostname_is_blocked(): void {
		$result = $this->guard->assert_safe_url( 'http://localhost/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that 0.0.0.0 is blocked.
	 */
	public function test_zero_address_is_blocked(): void {
		$result = $this->guard->assert_safe_url( 'http://0.0.0.0/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	// ── IPv6 tests ───────────────────────────────────────────────────────────

	/**
	 * Test that IPv6 loopback is blocked.
	 */
	public function test_ipv6_loopback_is_blocked(): void {
		$result = $this->guard->assert_safe_url( 'http://[::1]/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that IPv6 link-local is blocked.
	 */
	public function test_ipv6_link_local_is_blocked(): void {
		$result = $this->guard->assert_safe_url( 'http://[fe80::1]/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that IPv6 ULA (fc00::/7) is blocked.
	 */
	public function test_ipv6_ula_is_blocked(): void {
		$result = $this->guard->assert_safe_url( 'http://[fd00::1]/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that IPv6 multicast is blocked.
	 */
	public function test_ipv6_multicast_is_blocked(): void {
		$result = $this->guard->assert_safe_url( 'http://[ff02::1]/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	// ── Link-local tests ─────────────────────────────────────────────────────

	/**
	 * Test that 169.254.x.x link-local is blocked.
	 */
	public function test_link_local_is_blocked(): void {
		$result = $this->guard->assert_safe_url( 'http://169.254.1.1/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	// ── Multicast tests ──────────────────────────────────────────────────────

	/**
	 * Test that IPv4 multicast is blocked.
	 */
	public function test_ipv4_multicast_is_blocked(): void {
		$result = $this->guard->assert_safe_url( 'http://224.0.0.1/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	// ── TOCTOU rebinding test ────────────────────────────────────────────────

	/**
	 * Test that a hostname resolving to both public and private IPs is blocked.
	 *
	 * Simulates a TOCTOU DNS rebinding attack where the attacker's hostname
	 * returns a public IP on first lookup and an RFC1918 IP in the A records.
	 */
	public function test_toctou_rebinding_mixed_ips_is_blocked(): void {
		// First IP is public, second is private — the guard must block.
		$this->guard->set_dns_result( [ '93.184.216.34', '10.0.0.1' ] );
		$result = $this->guard->assert_safe_url( 'https://evil.example.com/payload' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
		$data = $result->get_error_data( 'ssrf_blocked' );
		$this->assertSame( '10.0.0.1', $data['ip'] );
	}

	// ── Scheme tests ─────────────────────────────────────────────────────────

	/**
	 * Test that non-HTTP schemes are blocked.
	 */
	public function test_ftp_scheme_is_blocked(): void {
		$result = $this->guard->assert_safe_url( 'ftp://example.com/file' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that file:// scheme is blocked.
	 */
	public function test_file_scheme_is_blocked(): void {
		$result = $this->guard->assert_safe_url( 'file:///etc/passwd' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that URL with no host is blocked.
	 */
	public function test_no_host_is_blocked(): void {
		$result = $this->guard->assert_safe_url( 'http://' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	// ── Filter tests ─────────────────────────────────────────────────────────

	/**
	 * Test that sd_ai_agent_ssrf_blocked_ranges filter adds a custom CIDR.
	 */
	public function test_blocked_ranges_filter_adds_custom_cidr(): void {
		add_filter(
			'sd_ai_agent_ssrf_blocked_ranges',
			static function ( array $ranges ): array {
				$ranges[] = '203.0.113.0/24'; // TEST-NET-3.
				return $ranges;
			}
		);

		$result = $this->guard->assert_safe_url( 'http://203.0.113.5/' );
		$this->assertWPError( $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Test that sd_ai_agent_ssrf_allow_hosts filter allows an internal hostname.
	 */
	public function test_allow_hosts_filter_whitelists_hostname(): void {
		add_filter(
			'sd_ai_agent_ssrf_allow_hosts',
			static function ( array $hosts ): array {
				$hosts[] = 'internal.test';
				return $hosts;
			}
		);

		// Normally 10.x.x.x would be blocked, but the allow-host filter lets it through.
		$this->guard->set_dns_result( [ '10.0.0.50' ] );
		$result = $this->guard->assert_safe_url( 'http://internal.test/api' );

		// The allow_hosts filter skips the IP range check.
		$this->assertTrue( $result );
	}

	/**
	 * Test that WP_Error data includes the offending IP.
	 */
	public function test_error_data_includes_ip(): void {
		$result = $this->guard->assert_safe_url( 'http://10.0.0.5/' );
		$this->assertWPError( $result );
		$data = $result->get_error_data( 'ssrf_blocked' );
		$this->assertArrayHasKey( 'ip', $data );
		$this->assertSame( '10.0.0.5', $data['ip'] );
	}

	// ── DNS failure test ─────────────────────────────────────────────────────

	/**
	 * Test that DNS failure returns a WP_Error.
	 */
	public function test_dns_failure_returns_error(): void {
		$this->guard->set_dns_result( [] );
		$result = $this->guard->assert_safe_url( 'https://nonexistent.invalid/' );
		$this->assertWPError( $result );
	}
}

/**
 * Test-only subclass that allows controlling DNS resolution results.
 *
 * @internal
 */
class TestSsrfGuard extends SsrfGuard {

	/**
	 * Mocked DNS results.
	 *
	 * @var list<string>|null
	 */
	private ?array $dns_result = null;

	/**
	 * Set the DNS result that dns_get_record will return.
	 *
	 * @param list<string> $ips IP addresses to return.
	 */
	public function set_dns_result( array $ips ): void {
		$this->dns_result = $ips;
	}

	/**
	 * Override DNS resolution for testing.
	 *
	 * @param string $host Hostname.
	 * @return list<string>|\WP_Error
	 */
	protected function dns_get_record( string $host ): array|\WP_Error {
		if ( null !== $this->dns_result ) {
			if ( empty( $this->dns_result ) ) {
				return new \WP_Error(
					'ssrf_dns_failed',
					sprintf( 'Failed to resolve DNS for %s.', $host ),
					[ 'host' => $host ]
				);
			}
			return $this->dns_result;
		}

		return parent::dns_get_record( $host );
	}
}
