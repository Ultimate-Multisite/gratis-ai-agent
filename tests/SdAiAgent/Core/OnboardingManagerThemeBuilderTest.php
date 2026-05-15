<?php

declare(strict_types=1);
/**
 * Test case for OnboardingManager theme-builder REST endpoint.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\Database;
use SdAiAgent\Core\OnboardingManager;
use SdAiAgent\Models\Agent;
use WP_UnitTestCase;

/**
 * Test OnboardingManager::rest_theme_builder_start() functionality.
 */
class OnboardingManagerThemeBuilderTest extends WP_UnitTestCase {

	/**
	 * Reset onboarding state before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		OnboardingManager::reset();
		delete_option( OnboardingManager::THEME_BUILDER_SESSION_OPTION );
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i',
				$wpdb->prefix . 'sd_ai_agent_sessions'
			)
		);
	}

	/**
	 * Reset onboarding state after each test.
	 */
	public function tear_down(): void {
		OnboardingManager::reset();
		delete_option( OnboardingManager::THEME_BUILDER_SESSION_OPTION );
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i',
				$wpdb->prefix . 'sd_ai_agent_sessions'
			)
		);
		parent::tear_down();
	}

	// ── register_rest_routes ──────────────────────────────────────────────

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

	// ── rest_theme_builder_start ──────────────────────────────────────────

	/**
	 * rest_theme_builder_start() creates a session on first call.
	 */
	public function test_rest_theme_builder_start_creates_session_on_first_call(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$response = OnboardingManager::rest_theme_builder_start();

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertIsInt( $data['session_id'] );
		$this->assertGreaterThan( 0, $data['session_id'] );
		$this->assertIsString( $data['kickoff_message'] );
	}

	/**
	 * rest_theme_builder_start() returns the theme-builder agent ID.
	 */
	public function test_rest_theme_builder_start_returns_agent_id(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$response = OnboardingManager::rest_theme_builder_start();
		$data     = $response->get_data();

		// The agent ID should be present (may be 0 if the agent doesn't exist yet).
		$this->assertArrayHasKey( 'agent_id', $data );
		$this->assertIsInt( $data['agent_id'] );
	}

	/**
	 * rest_theme_builder_start() persists the session ID.
	 */
	public function test_rest_theme_builder_start_persists_session_id(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$response = OnboardingManager::rest_theme_builder_start();
		$data     = $response->get_data();

		$persisted_id = get_option( OnboardingManager::THEME_BUILDER_SESSION_OPTION );
		$this->assertSame( $data['session_id'], $persisted_id );
	}

	/**
	 * rest_theme_builder_start() returns the same session ID on repeat calls.
	 */
	public function test_rest_theme_builder_start_returns_same_session_on_repeat(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$response1 = OnboardingManager::rest_theme_builder_start();
		$data1     = $response1->get_data();

		$response2 = OnboardingManager::rest_theme_builder_start();
		$data2     = $response2->get_data();

		$this->assertSame( $data1['session_id'], $data2['session_id'] );
	}

	/**
	 * rest_theme_builder_start() does NOT mark onboarding complete.
	 */
	public function test_rest_theme_builder_start_does_not_mark_onboarding_complete(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		OnboardingManager::rest_theme_builder_start();

		// The COMPLETE_OPTION should NOT be set.
		$this->assertFalse( (bool) get_option( OnboardingManager::COMPLETE_OPTION ) );
	}

	/**
	 * rest_theme_builder_start() does NOT set onboarding_complete in Settings.
	 */
	public function test_rest_theme_builder_start_does_not_set_settings_onboarding_complete(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		OnboardingManager::rest_theme_builder_start();

		// The Settings store should NOT have onboarding_complete set.
		$settings = \SdAiAgent\Core\Settings::instance();
		$all      = $settings->get();
		$this->assertEmpty( $all['onboarding_complete'] ?? null );
	}

	/**
	 * rest_theme_builder_start() returns expected JSON shape.
	 */
	public function test_rest_theme_builder_start_returns_expected_shape(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$response = OnboardingManager::rest_theme_builder_start();
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'success', $data );
		$this->assertArrayHasKey( 'session_id', $data );
		$this->assertArrayHasKey( 'agent_id', $data );
		$this->assertArrayHasKey( 'kickoff_message', $data );

		// Should NOT have these bootstrap-specific keys.
		$this->assertArrayNotHasKey( 'onboarding_complete', $data );
		$this->assertArrayNotHasKey( 'woo_detected', $data );
		$this->assertArrayNotHasKey( 'already_complete', $data );
	}

	/**
	 * rest_theme_builder_start() rejects unauthenticated requests.
	 */
	public function test_rest_theme_builder_start_rejects_unauthenticated(): void {
		wp_set_current_user( 0 );

		$result = OnboardingManager::rest_permission();

		$this->assertWPError( $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * rest_theme_builder_start() rejects non-admin users.
	 */
	public function test_rest_theme_builder_start_rejects_non_admin(): void {
		$subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = OnboardingManager::rest_permission();

		$this->assertWPError( $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * rest_theme_builder_start() creates a session with the correct title.
	 */
	public function test_rest_theme_builder_start_creates_session_with_correct_title(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$response = OnboardingManager::rest_theme_builder_start();
		$data     = $response->get_data();

		// Verify the session was created with the expected title.
		global $wpdb;
		$session_row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$wpdb->prefix . 'sd_ai_agent_sessions',
				$data['session_id']
			)
		);

		$this->assertNotNull( $session_row );
		$this->assertStringContainsString( 'Theme Builder', $session_row->title );
	}
}
