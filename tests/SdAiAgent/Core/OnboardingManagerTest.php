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
	 * reset() clears the persisted bootstrap and theme-builder session IDs so
	 * the v2 direct-routing gate creates fresh sessions on the next mount.
	 */
	public function test_reset_clears_persisted_session_options(): void {
		update_option( OnboardingManager::COMPLETE_OPTION, true );
		update_option( OnboardingManager::BOOTSTRAP_SESSION_OPTION, 42 );
		update_option( OnboardingManager::THEME_BUILDER_SESSION_OPTION, 99 );

		OnboardingManager::reset();

		$this->assertFalse( get_option( OnboardingManager::COMPLETE_OPTION ) );
		$this->assertFalse( get_option( OnboardingManager::BOOTSTRAP_SESSION_OPTION ) );
		$this->assertFalse( get_option( OnboardingManager::THEME_BUILDER_SESSION_OPTION ) );
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
	 * React admin-page gates the bootstrap flow on
	 * `settings.onboarding_complete !== false`, so the option-only reset is
	 * not enough on its own — the Settings store must also be updated.
	 */
	public function test_rest_reset_sets_settings_onboarding_complete_false(): void {
		\SdAiAgent\Core\Settings::instance()->update( [ 'onboarding_complete' => true ] );

		OnboardingManager::rest_reset();

		$settings = \SdAiAgent\Core\Settings::instance()->get();
		$this->assertFalse( (bool) ( $settings['onboarding_complete'] ?? true ) );
	}

	// ── rest_theme_builder_start ──────────────────────────────────────────

	/**
	 * register_rest_routes() registers the onboarding/theme-builder-start route.
	 */
	public function test_register_rest_routes_registers_theme_builder_start_route(): void {
		do_action( 'rest_api_init' );
		OnboardingManager::register_rest_routes();

		$server = rest_get_server();
		$routes = $server->get_routes();

		$this->assertArrayHasKey( '/sd-ai-agent/v1/onboarding/theme-builder-start', $routes );
	}

	/**
	 * rest_theme_builder_start() returns a WP_REST_Response with expected keys.
	 */
	public function test_rest_theme_builder_start_returns_expected_shape(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$response = OnboardingManager::rest_theme_builder_start();

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'session_id', $data );
		$this->assertArrayHasKey( 'agent_id', $data );
		$this->assertArrayHasKey( 'kickoff_message', $data );
		$this->assertArrayHasKey( 'started_at', $data );
		// is_fresh_start drives the React kickoff guard (see #1522).
		// `started_at` is retained for observability / back-compat but MUST NOT
		// be used to distinguish fresh-start from resume because it is truthy
		// on both branches.
		$this->assertArrayHasKey( 'is_fresh_start', $data );
		$this->assertIsBool( $data['is_fresh_start'] );
	}

	/**
	 * rest_theme_builder_start() sets the theme-builder started option on first call.
	 */
	public function test_rest_theme_builder_start_sets_started_option(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		OnboardingManager::rest_theme_builder_start();

		$started_at = get_option( OnboardingManager::THEME_BUILDER_STARTED_OPTION );
		$this->assertNotEmpty( $started_at );
		$this->assertIsInt( (int) $started_at );
	}

	/**
	 * rest_theme_builder_start() persists the session ID.
	 */
	public function test_rest_theme_builder_start_persists_session_id(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$response = OnboardingManager::rest_theme_builder_start();
		$data     = $response->get_data();

		$persisted_session_id = get_option( OnboardingManager::THEME_BUILDER_SESSION_OPTION );
		$this->assertSame( $data['session_id'], $persisted_session_id );
	}

	/**
	 * rest_theme_builder_start() is idempotent — repeat calls return the same
	 * session ID and started_at timestamp without creating a duplicate session.
	 */
	public function test_rest_theme_builder_start_is_idempotent(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		// First call.
		$response1 = OnboardingManager::rest_theme_builder_start();
		$data1     = $response1->get_data();

		// Second call.
		$response2 = OnboardingManager::rest_theme_builder_start();
		$data2     = $response2->get_data();

		// Both calls should return the same session ID.
		$this->assertSame( $data1['session_id'], $data2['session_id'] );

		// Both calls should return the same started_at timestamp.
		$this->assertSame( $data1['started_at'], $data2['started_at'] );
	}

	/**
	 * rest_theme_builder_start() returns the same started_at timestamp on resume.
	 *
	 * Historical note: pre-#1522 the React component used `started_at` as the
	 * kickoff guard, which was broken because the fresh-create branch also
	 * stamped a fresh timestamp before returning — so the JS check
	 * `if ( ! data.started_at )` was false on the very first call and the
	 * kickoff never fired. `is_fresh_start` is now the authoritative signal
	 * (see test_rest_theme_builder_start_distinguishes_fresh_start_from_resume
	 * below). `started_at` is retained for observability and back-compat.
	 */
	public function test_rest_theme_builder_start_returns_started_at_on_resume(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		// First call — fresh start.
		$response1 = OnboardingManager::rest_theme_builder_start();
		$data1     = $response1->get_data();

		// started_at should be set on first call.
		$this->assertNotEmpty( $data1['started_at'] );

		// Second call — resume.
		$response2 = OnboardingManager::rest_theme_builder_start();
		$data2     = $response2->get_data();

		// started_at should still be set on resume and unchanged.
		$this->assertNotEmpty( $data2['started_at'] );
		$this->assertSame( $data1['started_at'], $data2['started_at'] );
	}

	/**
	 * rest_theme_builder_start() returns is_fresh_start=true on the very first
	 * call and is_fresh_start=false on every subsequent (resume) call.
	 *
	 * Regression coverage for #1522 — the broken signal `started_at` is
	 * truthy on both branches; `is_fresh_start` is the only field that can
	 * be used to drive the React component's kickoff guard.
	 */
	public function test_rest_theme_builder_start_distinguishes_fresh_start_from_resume(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		// First call — fresh start.
		$first  = OnboardingManager::rest_theme_builder_start()->get_data();
		$this->assertTrue(
			$first['is_fresh_start'],
			'First call must report is_fresh_start=true so the React component fires the kickoff.'
		);

		// Second call — resume.
		$second = OnboardingManager::rest_theme_builder_start()->get_data();
		$this->assertFalse(
			$second['is_fresh_start'],
			'Subsequent calls must report is_fresh_start=false so the React component skips the kickoff.'
		);

		// Sanity: both calls share the same session.
		$this->assertSame( $first['session_id'], $second['session_id'] );
	}

	/**
	 * reset() clears the theme-builder started option so the next call to
	 * rest_theme_builder_start() will be treated as a fresh start.
	 */
	public function test_reset_clears_theme_builder_started_option(): void {
		update_option( OnboardingManager::THEME_BUILDER_STARTED_OPTION, time() );

		OnboardingManager::reset();

		$this->assertFalse( get_option( OnboardingManager::THEME_BUILDER_STARTED_OPTION ) );
	}
}
