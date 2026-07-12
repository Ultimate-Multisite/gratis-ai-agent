<?php
/**
 * Test case for SiteHealthAbilities class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\SiteHealthAbilities;
use SdAiAgent\Core\OnboardingManager;
use SdAiAgent\Core\SiteScanner;
use WP_UnitTestCase;

/**
 * Test SiteHealthAbilities handler methods.
 */
class SiteHealthAbilitiesTest extends WP_UnitTestCase {

	// ─── handle_check_plugin_updates ──────────────────────────────

	/**
	 * Test handle_check_plugin_updates returns expected structure.
	 */
	public function test_handle_check_plugin_updates_returns_expected_structure() {
		$result = SiteHealthAbilities::handle_check_plugin_updates( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'updates_available', $result );
		$this->assertArrayHasKey( 'plugins', $result );
		$this->assertArrayHasKey( 'checked_at', $result );
	}

	/**
	 * Test handle_check_plugin_updates updates_available is integer.
	 */
	public function test_handle_check_plugin_updates_count_is_integer() {
		$result = SiteHealthAbilities::handle_check_plugin_updates( [] );

		$this->assertIsArray( $result );
		$this->assertIsInt( $result['updates_available'] );
		$this->assertGreaterThanOrEqual( 0, $result['updates_available'] );
	}

	/**
	 * Test handle_check_plugin_updates plugins is array.
	 */
	public function test_handle_check_plugin_updates_plugins_is_array() {
		$result = SiteHealthAbilities::handle_check_plugin_updates( [] );

		$this->assertIsArray( $result );
		$this->assertIsArray( $result['plugins'] );
	}

	/**
	 * Test handle_check_plugin_updates count matches plugins array length.
	 */
	public function test_handle_check_plugin_updates_count_matches_plugins_length() {
		$result = SiteHealthAbilities::handle_check_plugin_updates( [] );

		$this->assertIsArray( $result );
		$this->assertSame( count( $result['plugins'] ), $result['updates_available'] );
	}

	/**
	 * Test handle_check_plugin_updates with force_refresh=false does not throw.
	 */
	public function test_handle_check_plugin_updates_no_force_refresh() {
		$result = SiteHealthAbilities::handle_check_plugin_updates( [
			'force_refresh' => false,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'updates_available', $result );
	}

	// ─── handle_scan_php_error_log ────────────────────────────────

	/**
	 * Test handle_scan_php_error_log returns expected structure.
	 */
	public function test_handle_scan_php_error_log_returns_expected_structure() {
		$result = SiteHealthAbilities::handle_scan_php_error_log( [] );

		$this->assertTrue(
			is_array( $result ) || is_wp_error( $result ),
			'Result should be an array or WP_Error.'
		);

		if ( is_array( $result ) ) {
			$this->assertArrayHasKey( 'log_path', $result );
			$this->assertArrayHasKey( 'log_size_kb', $result );
			$this->assertArrayHasKey( 'entries', $result );
			$this->assertArrayHasKey( 'total_found', $result );
			$this->assertArrayHasKey( 'debug_log_enabled', $result );
		}
	}

	/**
	 * Test handle_scan_php_error_log entries is array.
	 */
	public function test_handle_scan_php_error_log_entries_is_array() {
		$result = SiteHealthAbilities::handle_scan_php_error_log( [] );

		if ( is_wp_error( $result ) ) {
			$this->markTestSkipped( 'Log scan returned WP_Error — log file not accessible.' );
		}

		$this->assertIsArray( $result['entries'] );
	}

	/**
	 * Test handle_scan_php_error_log total_found matches entries count.
	 */
	public function test_handle_scan_php_error_log_total_found_matches_entries() {
		$result = SiteHealthAbilities::handle_scan_php_error_log( [] );

		if ( is_wp_error( $result ) ) {
			$this->markTestSkipped( 'Log scan returned WP_Error — log file not accessible.' );
		}

		$this->assertSame( count( $result['entries'] ), $result['total_found'] );
	}

	/**
	 * Test handle_scan_php_error_log with level filter.
	 */
	public function test_handle_scan_php_error_log_with_level_filter() {
		$result = SiteHealthAbilities::handle_scan_php_error_log( [
			'level' => 'error',
			'limit' => 10,
		] );

		$this->assertTrue(
			is_array( $result ) || is_wp_error( $result ),
			'Result should be an array or WP_Error.'
		);
	}

	/**
	 * Test handle_scan_php_error_log limit is respected.
	 */
	public function test_handle_scan_php_error_log_limit_respected() {
		$result = SiteHealthAbilities::handle_scan_php_error_log( [
			'limit' => 5,
		] );

		if ( is_wp_error( $result ) ) {
			$this->markTestSkipped( 'Log scan returned WP_Error — log file not accessible.' );
		}

		$this->assertLessThanOrEqual( 5, count( $result['entries'] ) );
	}

	// ─── handle_check_disk_space ──────────────────────────────────

	/**
	 * Test handle_check_disk_space returns expected structure.
	 */
	public function test_handle_check_disk_space_returns_expected_structure() {
		$result = SiteHealthAbilities::handle_check_disk_space( [] );

		$this->assertTrue(
			is_array( $result ) || is_wp_error( $result ),
			'Result should be an array or WP_Error.'
		);

		if ( is_array( $result ) ) {
			$this->assertArrayHasKey( 'disk_total_gb', $result );
			$this->assertArrayHasKey( 'disk_free_gb', $result );
			$this->assertArrayHasKey( 'disk_used_gb', $result );
			$this->assertArrayHasKey( 'disk_used_percent', $result );
			$this->assertArrayHasKey( 'wp_content_size_mb', $result );
			$this->assertArrayHasKey( 'uploads_size_mb', $result );
			$this->assertArrayHasKey( 'status', $result );
		}
	}

	/**
	 * Test handle_check_disk_space status is one of ok/warning/critical.
	 */
	public function test_handle_check_disk_space_status_is_valid() {
		$result = SiteHealthAbilities::handle_check_disk_space( [] );

		if ( is_wp_error( $result ) ) {
			$this->markTestSkipped( 'Disk check returned WP_Error.' );
		}

		$this->assertContains(
			$result['status'],
			[ 'ok', 'warning', 'critical' ],
			'Status should be ok, warning, or critical.'
		);
	}

	/**
	 * Test handle_check_disk_space disk values are non-negative.
	 */
	public function test_handle_check_disk_space_values_are_non_negative() {
		$result = SiteHealthAbilities::handle_check_disk_space( [] );

		if ( is_wp_error( $result ) ) {
			$this->markTestSkipped( 'Disk check returned WP_Error.' );
		}

		$this->assertGreaterThanOrEqual( 0, $result['disk_total_gb'] );
		$this->assertGreaterThanOrEqual( 0, $result['disk_free_gb'] );
		$this->assertGreaterThanOrEqual( 0, $result['disk_used_gb'] );
		$this->assertGreaterThanOrEqual( 0, $result['disk_used_percent'] );
	}

	// ─── handle_check_security ────────────────────────────────────

	/**
	 * Test handle_check_security returns expected structure.
	 */
	public function test_handle_check_security_returns_expected_structure() {
		$result = SiteHealthAbilities::handle_check_security( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'issues', $result );
		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertArrayHasKey( 'passed', $result );
		$this->assertArrayHasKey( 'score', $result );
		$this->assertArrayHasKey( 'agent_guidance', $result );
	}

	/**
	 * Test handle_check_security issues, warnings, passed are arrays.
	 */
	public function test_handle_check_security_fields_are_arrays() {
		$result = SiteHealthAbilities::handle_check_security( [] );

		$this->assertIsArray( $result['issues'] );
		$this->assertIsArray( $result['warnings'] );
		$this->assertIsArray( $result['passed'] );
	}

	/**
	 * Test handle_check_security score is integer between 0 and 100.
	 */
	public function test_handle_check_security_score_is_valid_integer() {
		$result = SiteHealthAbilities::handle_check_security( [] );

		$this->assertIsInt( $result['score'] );
		$this->assertGreaterThanOrEqual( 0, $result['score'] );
		$this->assertLessThanOrEqual( 100, $result['score'] );
	}

	/**
	 * Test handle_check_security score decreases with issues.
	 *
	 * Score = 100 - (issues * 20) - (warnings * 5). With no issues and no
	 * warnings the score should be 100.
	 */
	public function test_handle_check_security_score_formula() {
		$result = SiteHealthAbilities::handle_check_security( [] );

		$expected_score = 100
			- ( count( $result['issues'] ) * 20 )
			- ( count( $result['warnings'] ) * 5 );
		$expected_score = max( 0, $expected_score );

		$this->assertSame( $expected_score, $result['score'] );
	}

	/**
	 * Security diagnostics must guide the model to summarize instead of remediate.
	 */
	public function test_handle_check_security_includes_read_only_guidance() {
		$result = SiteHealthAbilities::handle_check_security( [] );

		$this->assertIsString( $result['agent_guidance'] );
		$this->assertStringContainsString( 'Summarize these diagnostic findings', $result['agent_guidance'] );
		$this->assertStringContainsString( 'Do not install plugins', $result['agent_guidance'] );
		$this->assertStringContainsString( 'navigate away unless the user explicitly requested remediation', $result['agent_guidance'] );
	}

	// ─── handle_check_performance ─────────────────────────────────

	/**
	 * Test handle_check_performance returns expected structure.
	 */
	public function test_handle_check_performance_returns_expected_structure() {
		$result = SiteHealthAbilities::handle_check_performance( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'autoloaded_size_kb', $result );
		$this->assertArrayHasKey( 'autoloaded_count', $result );
		$this->assertArrayHasKey( 'expired_transients', $result );
		$this->assertArrayHasKey( 'total_transients', $result );
		$this->assertArrayHasKey( 'post_revisions', $result );
		$this->assertArrayHasKey( 'object_cache_enabled', $result );
		$this->assertArrayHasKey( 'recommendations', $result );
	}

	/**
	 * Test handle_check_performance numeric fields are non-negative.
	 */
	public function test_handle_check_performance_numeric_fields_are_non_negative() {
		$result = SiteHealthAbilities::handle_check_performance( [] );

		$this->assertGreaterThanOrEqual( 0, $result['autoloaded_size_kb'] );
		$this->assertGreaterThanOrEqual( 0, $result['autoloaded_count'] );
		$this->assertGreaterThanOrEqual( 0, $result['expired_transients'] );
		$this->assertGreaterThanOrEqual( 0, $result['total_transients'] );
		$this->assertGreaterThanOrEqual( 0, $result['post_revisions'] );
	}

	/**
	 * Test handle_check_performance object_cache_enabled is boolean.
	 */
	public function test_handle_check_performance_object_cache_is_boolean() {
		$result = SiteHealthAbilities::handle_check_performance( [] );

		$this->assertIsBool( $result['object_cache_enabled'] );
	}

	/**
	 * Test handle_check_performance recommendations is array.
	 */
	public function test_handle_check_performance_recommendations_is_array() {
		$result = SiteHealthAbilities::handle_check_performance( [] );

		$this->assertIsArray( $result['recommendations'] );
	}

	// ─── handle_site_health_summary ───────────────────────────────

	/**
	 * Test handle_site_health_summary returns expected top-level structure.
	 */
	public function test_handle_site_health_summary_returns_expected_structure() {
		$result = SiteHealthAbilities::handle_site_health_summary( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'overall_status', $result );
		$this->assertArrayHasKey( 'plugin_updates', $result );
		$this->assertArrayHasKey( 'error_log', $result );
		$this->assertArrayHasKey( 'disk_space', $result );
		$this->assertArrayHasKey( 'security', $result );
		$this->assertArrayHasKey( 'performance', $result );
		$this->assertArrayHasKey( 'generated_at', $result );
		$this->assertArrayHasKey( 'agent_guidance', $result );
	}

	/**
	 * Test handle_site_health_summary overall_status is a valid value.
	 */
	public function test_handle_site_health_summary_overall_status_is_valid() {
		$result = SiteHealthAbilities::handle_site_health_summary( [] );

		$this->assertContains(
			$result['overall_status'],
			[ 'healthy', 'needs_attention', 'critical' ],
			'overall_status should be healthy, needs_attention, or critical.'
		);
	}

	/**
	 * Test handle_site_health_summary generated_at is a date string.
	 */
	public function test_handle_site_health_summary_generated_at_is_date_string() {
		$result = SiteHealthAbilities::handle_site_health_summary( [] );

		$this->assertIsString( $result['generated_at'] );
		$this->assertNotEmpty( $result['generated_at'] );
		// Should be parseable as a date.
		$this->assertNotFalse( strtotime( $result['generated_at'] ) );
	}

	/**
	 * Test handle_site_health_summary with force_refresh=false.
	 */
	public function test_handle_site_health_summary_no_force_refresh() {
		$result = SiteHealthAbilities::handle_site_health_summary( [
			'force_refresh' => false,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'overall_status', $result );
	}

	/**
	 * Site-health summaries must not encourage immediate plugin installs or navigation.
	 */
	public function test_handle_site_health_summary_includes_read_only_guidance() {
		$result = SiteHealthAbilities::handle_site_health_summary( [] );

		$this->assertIsArray( $result );
		$this->assertIsString( $result['agent_guidance'] );
		$this->assertStringContainsString( 'Do not install plugins', $result['agent_guidance'] );
		$this->assertStringContainsString( 'activate plugins', $result['agent_guidance'] );
		$this->assertStringContainsString( 'unless the user explicitly requested remediation', $result['agent_guidance'] );
	}

	/**
	 * A public WordPress unavailable page must make availability critical.
	 */
	public function test_availability_reports_frontend_404_wp_die_as_critical() {
		add_filter(
			'pre_http_request',
			static function () {
				return [
					'headers'  => [ 'server' => 'test' ],
					'body'     => '<html><head><title>Not available</title></head><body>This site is not available at this time.</body></html>',
					'response' => [ 'code' => 404, 'message' => 'Not Found' ],
					'cookies'  => [],
					'filename' => null,
				];
			}
		);

		try {
			$result = SiteHealthAbilities::diagnose_site_availability();
			$this->assertSame( 'critical', $result['status'] );
			$this->assertSame( 404, $result['frontend']['status_code'] );
			$this->assertTrue( $result['frontend']['wp_die_page'] );
			$this->assertSame( 'Not available', $result['frontend']['title'] );
		} finally {
			remove_all_filters( 'pre_http_request' );
		}
	}

	/**
	 * Frontend transport failures must make availability critical.
	 */
	public function test_availability_reports_transport_failure_as_critical() {
		add_filter(
			'pre_http_request',
			static function ( mixed $preempt, array $parsed_args ): WP_Error {
				unset( $preempt );
				self::assertSame( 5, $parsed_args['timeout'] );
				self::assertSame( 2, $parsed_args['redirection'] );

				return new WP_Error( 'http_request_failed', 'Connection refused' );
			},
			10,
			2
		);

		try {
			$result = SiteHealthAbilities::diagnose_site_availability();
			$this->assertSame( 'critical', $result['status'] );
			$this->assertSame( 'Connection refused', $result['frontend']['transport_error'] );
			$this->assertSame( 0, $result['frontend']['status_code'] );
		} finally {
			remove_all_filters( 'pre_http_request' );
		}
	}

	/**
	 * A normal frontend response without a tenant lock must remain healthy.
	 */
	public function test_availability_reports_healthy_frontend() {
		add_filter(
			'pre_http_request',
			static function () {
				return [
					'headers'  => [],
					'body'     => '<html><head><title>Healthy site</title></head><body>Welcome</body></html>',
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'cookies'  => [],
					'filename' => null,
				];
			}
		);

		try {
			$result = SiteHealthAbilities::diagnose_site_availability();
			$this->assertSame( 'healthy', $result['status'] );
			$this->assertSame( 200, $result['frontend']['status_code'] );
			$this->assertFalse( $result['frontend']['wp_die_page'] );
			$this->assertArrayNotHasKey( 'transport_error', $result['frontend'] );
			$this->assertArrayNotHasKey( 'effective_url', $result['frontend'] );
		} finally {
			remove_all_filters( 'pre_http_request' );
		}
	}

	/**
	 * Ultimate Multisite visit overages must expose the frontend lock reason.
	 */
	public function test_availability_reports_tenant_visit_limit_lock() {
		add_filter(
			'pre_http_request',
			static function () {
				return [
					'headers'  => [],
					'body'     => '<html><head><title>Site</title></head><body>OK</body></html>',
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'cookies'  => [],
					'filename' => null,
				];
			}
		);
		add_filter(
			'sd_ai_agent_site_availability_tenant_context',
			static fn() => [
				'visits_enabled'      => true,
				'visit_limit'         => 2,
				'current_month_visits' => 3,
			]
		);

		try {
			$result = SiteHealthAbilities::diagnose_site_availability();
			$this->assertSame( 'critical', $result['status'] );
			$this->assertTrue( $result['tenant']['would_lock_frontend'] );
			$this->assertSame( 'visits_exceeded', $result['tenant']['lock_reason'] );
		} finally {
			remove_all_filters( 'pre_http_request' );
			remove_all_filters( 'sd_ai_agent_site_availability_tenant_context' );
		}
	}

	// ─── handle_detect_fresh_install ───────────────────────────────

	/**
	 * Test handle_detect_fresh_install returns expected structure.
	 */
	public function test_handle_detect_fresh_install_returns_expected_structure() {
		$result = SiteHealthAbilities::handle_detect_fresh_install( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'is_fresh_install', $result );
		$this->assertArrayHasKey( 'memory_count', $result );
		$this->assertArrayHasKey( 'session_count', $result );
		$this->assertArrayHasKey( 'onboarding_complete', $result );
		$this->assertArrayHasKey( 'onboarding_triggered', $result );
		$this->assertArrayHasKey( 'site_scan_status', $result );
	}

	/**
	 * Test handle_detect_fresh_install exposes typed values.
	 */
	public function test_handle_detect_fresh_install_field_types_are_valid() {
		$result = SiteHealthAbilities::handle_detect_fresh_install( [] );

		$this->assertIsBool( $result['is_fresh_install'] );
		$this->assertIsInt( $result['memory_count'] );
		$this->assertIsInt( $result['session_count'] );
		$this->assertIsBool( $result['onboarding_complete'] );
		$this->assertIsBool( $result['onboarding_triggered'] );
		$this->assertIsString( $result['site_scan_status'] );
	}

	/**
	 * Test handle_detect_fresh_install includes onboarding option context.
	 */
	public function test_handle_detect_fresh_install_reports_onboarding_context() {
		update_option( OnboardingManager::COMPLETE_OPTION, true, false );
		update_option( OnboardingManager::TRIGGERED_OPTION, true, false );
		update_option( SiteScanner::STATUS_OPTION, [ 'status' => 'complete' ], false );

		try {
			$result = SiteHealthAbilities::handle_detect_fresh_install( [] );

			$this->assertTrue( $result['onboarding_complete'] );
			$this->assertTrue( $result['onboarding_triggered'] );
			$this->assertSame( 'complete', $result['site_scan_status'] );
		} finally {
			delete_option( OnboardingManager::COMPLETE_OPTION );
			delete_option( OnboardingManager::TRIGGERED_OPTION );
			delete_option( SiteScanner::STATUS_OPTION );
		}
	}
}
