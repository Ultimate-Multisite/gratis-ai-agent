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
	 * register_rest_routes() registers the unified onboarding/start route.
	 *
	 * The split Theme Builder route is removed; bootstrap-start remains only as
	 * a compatibility alias for existing callers.
	 */
	public function test_register_rest_routes_registers_unified_start_route(): void {
		do_action( 'rest_api_init' );
		OnboardingManager::register_rest_routes();

		$server = rest_get_server();
		$routes = $server->get_routes();

		$this->assertArrayHasKey( '/sd-ai-agent/v1/onboarding/start', $routes );
		$this->assertArrayHasKey( '/sd-ai-agent/v1/onboarding/bootstrap-start', $routes );
		$this->assertArrayNotHasKey( '/sd-ai-agent/v1/onboarding/theme-builder-start', $routes );
		$this->assertArrayNotHasKey( '/sd-ai-agent/v1/onboarding/bootstrap', $routes );
		$this->assertArrayNotHasKey( '/sd-ai-agent/v1/onboarding/interview', $routes );
	}

	/**
	 * register_rest_routes() registers the onboarding/reset route used by the
	 * Settings → Advanced "Restart Setup Assistant" button.
	 */
	public function test_register_rest_routes_registers_reset_route(): void {
		do_action( 'rest_api_init' );
		OnboardingManager::register_rest_routes();

		$server = rest_get_server();
		$routes = $server->get_routes();

		$this->assertArrayHasKey( '/sd-ai-agent/v1/onboarding/reset', $routes );
	}

	// ── reset() — v2 cleanup ──────────────────────────────────────────────

	/**
	 * reset() clears the persisted onboarding session ID plus retired
	 * empty-install state so the direct-routing gate creates a fresh session on
	 * the next mount.
	 */
	public function test_reset_clears_persisted_session_options(): void {
		update_option( OnboardingManager::COMPLETE_OPTION, true );
		update_option( OnboardingManager::BOOTSTRAP_SESSION_OPTION, 42 );
		update_option( OnboardingManager::THEME_BUILDER_SESSION_OPTION, 99 );
		update_option( OnboardingManager::THEME_BUILDER_STARTED_OPTION, time() );

		OnboardingManager::reset();

		$this->assertFalse( get_option( OnboardingManager::COMPLETE_OPTION ) );
		$this->assertFalse( get_option( OnboardingManager::BOOTSTRAP_SESSION_OPTION ) );
		$this->assertFalse( get_option( OnboardingManager::THEME_BUILDER_SESSION_OPTION ) );
		$this->assertFalse( get_option( OnboardingManager::THEME_BUILDER_STARTED_OPTION ) );
	}

	// ── rest_start ─────────────────────────────────────────────────────────

	/**
	 * rest_start() returns a unified Setup Assistant session shape.
	 */
	public function test_rest_start_returns_expected_shape(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$response = OnboardingManager::rest_start();

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'session_id', $data );
		$this->assertArrayHasKey( 'agent_id', $data );
		$this->assertArrayHasKey( 'kickoff_message', $data );
		$this->assertArrayHasKey( 'onboarding_complete', $data );
		$this->assertArrayNotHasKey( 'is_fresh_start', $data );
		$this->assertArrayNotHasKey( 'started_at', $data );
	}

	/**
	 * rest_start() persists the unified onboarding session ID and completion flag.
	 */
	public function test_rest_start_persists_session_and_marks_complete(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$response = OnboardingManager::rest_start();
		$data     = $response->get_data();

		$this->assertSame( $data['session_id'], get_option( OnboardingManager::BOOTSTRAP_SESSION_OPTION ) );
		$this->assertTrue( (bool) get_option( OnboardingManager::COMPLETE_OPTION ) );
		$this->assertNotNull( \SdAiAgent\Core\Database::get_shared_session( (int) $data['session_id'] ) );

		$settings = \SdAiAgent\Core\Settings::instance()->get();
		$this->assertTrue( (bool) ( $settings['onboarding_complete'] ?? false ) );
	}

	/**
	 * rest_start() is idempotent and reuses the existing onboarding session.
	 */
	public function test_rest_start_is_idempotent(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$first  = OnboardingManager::rest_start()->get_data();
		$second = OnboardingManager::rest_start()->get_data();

		$this->assertSame( $first['session_id'], $second['session_id'] );
		$this->assertTrue( $second['already_complete'] );
	}

	/**
	 * rest_start() shares the reused site-scoped onboarding session so a second
	 * administrator can open the persisted Setup Assistant chat.
	 */
	public function test_rest_start_shares_reused_session_for_second_admin(): void {
		$first_admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $first_admin_id );

		$first      = OnboardingManager::rest_start()->get_data();
		$session_id = (int) $first['session_id'];

		\SdAiAgent\Core\Database::unshare_session( $session_id );
		$this->assertNull( \SdAiAgent\Core\Database::get_shared_session( $session_id ) );

		$second_admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $second_admin_id );

		$second = OnboardingManager::rest_start()->get_data();

		$this->assertSame( $session_id, (int) $second['session_id'] );
		$this->assertTrue( $second['already_complete'] );
		$this->assertNotNull( \SdAiAgent\Core\Database::get_shared_session( $session_id ) );
	}

	/**
	 * rest_start() force mode creates a setup session when completed state exists
	 * without a persisted onboarding session.
	 */
	public function test_rest_start_force_creates_session_when_completed_without_persisted_session(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );
		\SdAiAgent\Core\Settings::instance()->update( [ 'onboarding_complete' => true ] );
		update_option( OnboardingManager::COMPLETE_OPTION, true );
		delete_option( OnboardingManager::BOOTSTRAP_SESSION_OPTION );

		$request = new \WP_REST_Request( 'POST', '/sd-ai-agent/v1/onboarding/start' );
		$request->set_param( 'force', true );

		$response = OnboardingManager::rest_start( $request );
		$data     = $response->get_data();

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $data['session_id'] );
		$this->assertSame( $data['session_id'], get_option( OnboardingManager::BOOTSTRAP_SESSION_OPTION ) );
		$this->assertArrayNotHasKey( 'already_complete', $data );
	}

	// ── rest_reset ────────────────────────────────────────────────────────

	/**
	 * rest_reset() returns a success response with a chat URL the frontend
	 * can use to drop the user back into the v2 direct-routing gate.
	 */
	public function test_rest_reset_returns_success_with_chat_url(): void {
		$response = OnboardingManager::rest_reset();

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'chat_url', $data );
		$this->assertStringContainsString( 'page=sd-ai-agent', (string) $data['chat_url'] );
		$this->assertStringContainsString( '#/chat', (string) $data['chat_url'] );
	}

	/**
	 * rest_reset() flips settings.onboarding_complete back to false. The
	 * React admin-page gates the onboarding flow on
	 * `settings.onboarding_complete !== false`, so the option-only reset is
	 * not enough on its own — the Settings store must also be updated.
	 */
	public function test_rest_reset_sets_settings_onboarding_complete_false(): void {
		\SdAiAgent\Core\Settings::instance()->update( [ 'onboarding_complete' => true ] );

		OnboardingManager::rest_reset();

		$settings = \SdAiAgent\Core\Settings::instance()->get();
		$this->assertFalse( (bool) ( $settings['onboarding_complete'] ?? true ) );
	}

}
