<?php

declare(strict_types=1);
/**
 * Test case for OnboardingManager class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\OnboardingManager;
use SdAiAgent\Core\SiteScanner;
use WP_UnitTestCase;

/**
 * Test OnboardingManager functionality.
 */
class OnboardingManagerTest extends WP_UnitTestCase {

	/**
	 * Reset onboarding state before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		OnboardingManager::reset();
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i',
				$wpdb->prefix . 'sd_ai_agent_memories'
			)
		);
	}

	/**
	 * Reset onboarding state after each test.
	 */
	public function tear_down(): void {
		OnboardingManager::reset();
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i',
				$wpdb->prefix . 'sd_ai_agent_memories'
			)
		);
		parent::tear_down();
	}

	// ── constants ─────────────────────────────────────────────────────────

	/**
	 * TRIGGERED_OPTION constant is defined.
	 */
	public function test_triggered_option_constant_is_defined(): void {
		$this->assertSame( 'sd_ai_agent_onboarding_triggered', OnboardingManager::TRIGGERED_OPTION );
	}

	// ── register ──────────────────────────────────────────────────────────

	/**
	 * register() hooks maybe_trigger to admin_init.
	 */
	public function test_register_hooks_maybe_trigger_to_admin_init(): void {
		OnboardingManager::register();

		$this->assertNotFalse( has_action( 'admin_init', [ OnboardingManager::class, 'maybe_trigger' ] ) );
	}

	/**
	 * register() hooks register_rest_routes to rest_api_init.
	 */
	public function test_register_hooks_rest_routes_to_rest_api_init(): void {
		OnboardingManager::register();

		$this->assertNotFalse( has_action( 'rest_api_init', [ OnboardingManager::class, 'register_rest_routes' ] ) );
	}

	// ── trigger ───────────────────────────────────────────────────────────

	/**
	 * trigger() sets the triggered option.
	 */
	public function test_trigger_sets_triggered_option(): void {
		OnboardingManager::trigger();

		$this->assertTrue( (bool) get_option( OnboardingManager::TRIGGERED_OPTION ) );
	}

	/**
	 * trigger() schedules the site scan cron event.
	 */
	public function test_trigger_schedules_site_scan(): void {
		// Clear any existing scheduled event first.
		$ts = wp_next_scheduled( SiteScanner::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, SiteScanner::CRON_HOOK );
		}

		OnboardingManager::trigger();

		$this->assertNotFalse( wp_next_scheduled( SiteScanner::CRON_HOOK ) );
	}

	// ── on_activation ─────────────────────────────────────────────────────

	/**
	 * on_activation() triggers onboarding.
	 */
	public function test_on_activation_triggers_onboarding(): void {
		OnboardingManager::on_activation();

		$this->assertTrue( (bool) get_option( OnboardingManager::TRIGGERED_OPTION ) );
	}

	// ── maybe_trigger ─────────────────────────────────────────────────────

	/**
	 * maybe_trigger() does nothing when already triggered.
	 */
	public function test_maybe_trigger_skips_when_already_triggered(): void {
		update_option( OnboardingManager::TRIGGERED_OPTION, true );

		// Clear the cron so we can detect if it gets scheduled.
		$ts = wp_next_scheduled( SiteScanner::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, SiteScanner::CRON_HOOK );
		}

		OnboardingManager::maybe_trigger();

		// Should not have scheduled a new scan.
		$this->assertFalse( wp_next_scheduled( SiteScanner::CRON_HOOK ) );
	}

	/**
	 * maybe_trigger() skips when scan is already complete.
	 */
	public function test_maybe_trigger_skips_when_scan_complete(): void {
		update_option( SiteScanner::STATUS_OPTION, [ 'status' => 'complete' ] );

		// Clear cron.
		$ts = wp_next_scheduled( SiteScanner::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, SiteScanner::CRON_HOOK );
		}

		OnboardingManager::maybe_trigger();

		// Scan was already complete — should not schedule a new one.
		$this->assertFalse( wp_next_scheduled( SiteScanner::CRON_HOOK ) );
	}

	/**
	 * maybe_trigger() skips when scan is pending.
	 */
	public function test_maybe_trigger_skips_when_scan_pending(): void {
		update_option( SiteScanner::STATUS_OPTION, [ 'status' => 'pending' ] );

		// Clear cron.
		$ts = wp_next_scheduled( SiteScanner::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, SiteScanner::CRON_HOOK );
		}

		OnboardingManager::maybe_trigger();

		// Scan was already pending — should not schedule a new one.
		$this->assertFalse( wp_next_scheduled( SiteScanner::CRON_HOOK ) );
	}

	/**
	 * maybe_trigger() marks as triggered when existing memories are present.
	 */
	public function test_maybe_trigger_marks_triggered_when_memories_exist(): void {
		global $wpdb;

		// Insert a memory directly.
		$wpdb->insert(
			$wpdb->prefix . 'sd_ai_agent_memories',
			[
				'category'   => 'site_info',
				'content'    => 'Test memory',
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			]
		);

		OnboardingManager::maybe_trigger();

		$this->assertTrue( (bool) get_option( OnboardingManager::TRIGGERED_OPTION ) );
	}

	/**
	 * maybe_trigger() triggers scan when no memories and not yet triggered.
	 */
	public function test_maybe_trigger_triggers_scan_when_fresh(): void {
		// Ensure no memories, no triggered flag, no scan status.
		delete_option( OnboardingManager::TRIGGERED_OPTION );
		delete_option( SiteScanner::STATUS_OPTION );

		// Clear cron.
		$ts = wp_next_scheduled( SiteScanner::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, SiteScanner::CRON_HOOK );
		}

		OnboardingManager::maybe_trigger();

		$this->assertTrue( (bool) get_option( OnboardingManager::TRIGGERED_OPTION ) );
		$this->assertNotFalse( wp_next_scheduled( SiteScanner::CRON_HOOK ) );
	}

	// ── reset ─────────────────────────────────────────────────────────────

	/**
	 * reset() clears the triggered option.
	 */
	public function test_reset_clears_triggered_option(): void {
		update_option( OnboardingManager::TRIGGERED_OPTION, true );

		OnboardingManager::reset();

		$this->assertFalse( (bool) get_option( OnboardingManager::TRIGGERED_OPTION ) );
	}

	/**
	 * reset() clears the scan status option.
	 */
	public function test_reset_clears_scan_status(): void {
		update_option( SiteScanner::STATUS_OPTION, [ 'status' => 'complete' ] );

		OnboardingManager::reset();

		$this->assertEmpty( SiteScanner::get_status() );
	}

	/**
	 * reset() unschedules the cron event.
	 */
	public function test_reset_unschedules_cron(): void {
		SiteScanner::schedule();
		$this->assertNotFalse( wp_next_scheduled( SiteScanner::CRON_HOOK ) );

		OnboardingManager::reset();

		$this->assertFalse( wp_next_scheduled( SiteScanner::CRON_HOOK ) );
	}

	// ── rest_permission ───────────────────────────────────────────────────

	/**
	 * rest_permission() returns WP_Error for non-admin users.
	 */
	public function test_rest_permission_returns_wp_error_for_non_admin(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		$result = OnboardingManager::rest_permission();

		$this->assertWPError( $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * rest_permission() returns true for admin users.
	 */
	public function test_rest_permission_returns_true_for_admin(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$result = OnboardingManager::rest_permission();

		$this->assertTrue( $result );
	}

	// ── rest_get_status ───────────────────────────────────────────────────

	/**
	 * rest_get_status() returns a WP_REST_Response with expected keys.
	 * Phase 2 (t223): response now contains onboarding_complete instead of interview keys.
	 */
	public function test_rest_get_status_returns_expected_shape(): void {
		$response = OnboardingManager::rest_get_status();

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'triggered', $data );
		$this->assertArrayHasKey( 'scan', $data );
		$this->assertArrayHasKey( 'scheduled', $data );
		$this->assertArrayHasKey( 'onboarding_complete', $data );
		$this->assertArrayNotHasKey( 'interview_ready', $data );
		$this->assertArrayNotHasKey( 'interview_done', $data );
	}

	/**
	 * rest_get_status() triggered field reflects option state.
	 */
	public function test_rest_get_status_triggered_reflects_option(): void {
		update_option( OnboardingManager::TRIGGERED_OPTION, true );

		$response = OnboardingManager::rest_get_status();
		$data     = $response->get_data();

		$this->assertTrue( $data['triggered'] );
	}

	// ── rest_rescan ───────────────────────────────────────────────────────

	/**
	 * rest_rescan() returns success response.
	 */
	public function test_rest_rescan_returns_success(): void {
		$response = OnboardingManager::rest_rescan();

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
	}

	/**
	 * rest_rescan() re-triggers onboarding.
	 */
	public function test_rest_rescan_re_triggers_onboarding(): void {
		// Start with a completed scan.
		update_option( OnboardingManager::TRIGGERED_OPTION, true );
		update_option( SiteScanner::STATUS_OPTION, [ 'status' => 'complete' ] );

		OnboardingManager::rest_rescan();

		// After rescan, triggered should be set again.
		$this->assertTrue( (bool) get_option( OnboardingManager::TRIGGERED_OPTION ) );
	}

	// ── register_rest_routes ──────────────────────────────────────────────

	/**
	 * register_rest_routes() registers the onboarding/status route.
	 */
	public function test_register_rest_routes_registers_status_route(): void {
		do_action( 'rest_api_init' );
		OnboardingManager::register_rest_routes();

		$server = rest_get_server();
		$routes = $server->get_routes();

		$this->assertArrayHasKey( '/sd-ai-agent/v1/onboarding/status', $routes );
	}

	/**
	 * register_rest_routes() registers the onboarding/rescan route.
	 */
	public function test_register_rest_routes_registers_rescan_route(): void {
		do_action( 'rest_api_init' );
		OnboardingManager::register_rest_routes();

		$server = rest_get_server();
		$routes = $server->get_routes();

		$this->assertArrayHasKey( '/sd-ai-agent/v1/onboarding/rescan', $routes );
	}

	/**
	 * register_rest_routes() registers the onboarding/bootstrap-start route.
	 *
	 * The interview route and the deprecated /onboarding/bootstrap route were
	 * both removed when the Setup Assistant agent took over the discovery flow.
	 */
	public function test_register_rest_routes_registers_bootstrap_start_route(): void {
		do_action( 'rest_api_init' );
		OnboardingManager::register_rest_routes();

		$server = rest_get_server();
		$routes = $server->get_routes();

		$this->assertArrayHasKey( '/sd-ai-agent/v1/onboarding/bootstrap-start', $routes );
		$this->assertArrayNotHasKey( '/sd-ai-agent/v1/onboarding/bootstrap', $routes );
		$this->assertArrayNotHasKey( '/sd-ai-agent/v1/onboarding/interview', $routes );
	}
}
