<?php

declare(strict_types=1);
/**
 * Test case for ThirdPartyAbilityNoticeHandler.
 *
 * @package SdAiAgent
 * @subpackage Tests\Admin
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Admin;

use SdAiAgent\Admin\ThirdPartyAbilityNoticeHandler;
use SdAiAgent\Core\AbilityVisibility;
use WP_UnitTestCase;

/**
 * Tests for the third-party ability notice handler.
 *
 * @group admin
 */
class ThirdPartyAbilityNoticeHandlerTest extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private int $admin_id = 0;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		// Create an admin user for testing.
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );

		// Clear the dismissed notice option.
		delete_option( 'sd_ai_agent_third_party_notice_dismissed' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		parent::tear_down();
		delete_option( 'sd_ai_agent_third_party_notice_dismissed' );
	}

	/**
	 * Test that notice is not displayed when Abilities API is unavailable.
	 */
	public function test_notice_not_displayed_when_abilities_api_unavailable(): void {
		if ( function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API is available.' );
		}

		// Capture output.
		ob_start();
		ThirdPartyAbilityNoticeHandler::maybe_display_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test that notice is not displayed when no unclassified abilities exist.
	 */
	public function test_notice_not_displayed_when_no_unclassified_abilities(): void {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		// Capture output.
		ob_start();
		ThirdPartyAbilityNoticeHandler::maybe_display_notice();
		$output = ob_get_clean();

		// Should not contain the notice HTML.
		$this->assertStringNotContainsString( 'sd-ai-agent-third-party-notice', $output );
	}

	/**
	 * Test that notice is not displayed when dismissed.
	 */
	public function test_notice_not_displayed_when_dismissed(): void {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		// Mark the notice as dismissed.
		update_option( 'sd_ai_agent_third_party_notice_dismissed', '1' );

		// Capture output.
		ob_start();
		ThirdPartyAbilityNoticeHandler::maybe_display_notice();
		$output = ob_get_clean();

		// Should not contain the notice HTML.
		$this->assertStringNotContainsString( 'sd-ai-agent-third-party-notice', $output );
	}

	/**
	 * Test that notice is not displayed for non-admin users.
	 */
	public function test_notice_not_displayed_for_non_admin(): void {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		// Switch to a subscriber user.
		$subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		// Capture output.
		ob_start();
		ThirdPartyAbilityNoticeHandler::maybe_display_notice();
		$output = ob_get_clean();

		// Should not contain the notice HTML.
		$this->assertStringNotContainsString( 'sd-ai-agent-third-party-notice', $output );
	}

	/**
	 * Test that namespace decisions override the heuristic.
	 */
	public function test_namespace_decision_overrides_heuristic(): void {
		if ( ! class_exists( '\WP_Ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		// Set the mode to 'auto' so the heuristic applies.
		$option = get_option( 'sd_ai_agent_settings', array() );
		if ( ! is_array( $option ) ) {
			$option = array();
		}
		$option['third_party_mode'] = 'auto';
		update_option( 'sd_ai_agent_settings', $option );

		// Create a mock ability with a non-partner category and no explicit public flag.
		// This simulates an ability that would be classified as private-unknown
		// (no description+category heuristic match, no partner namespace/category).
		$ability = new \WP_Ability(
			'test-plugin/test-ability',
			[
				'label'               => 'Test Ability',
				'description'         => 'A test ability',
				'category'            => 'test-category',
				'input_schema'        => [ 'type' => 'object', 'properties' => [] ],
				'execute_callback'    => static function () {
					return [];
				},
				'permission_callback' => static function () {
					return true;
				},
			]
		);

		// Without a decision, it should be public-heuristic (has description + category).
		$this->assertSame(
			AbilityVisibility::CLASSIFICATION_PUBLIC_HEURISTIC,
			AbilityVisibility::classify( $ability )
		);

		// Set an 'allow' decision for the namespace.
		$option = get_option( 'sd_ai_agent_settings', array() );
		if ( ! is_array( $option ) ) {
			$option = array();
		}
		$option['third_party_namespace_decisions'] = [ 'test-plugin' => 'allow' ];
		update_option( 'sd_ai_agent_settings', $option );

		// Now it should be public-explicit (namespace decision overrides heuristic).
		$this->assertSame(
			AbilityVisibility::CLASSIFICATION_PUBLIC_EXPLICIT,
			AbilityVisibility::classify( $ability )
		);

		// Set a 'block' decision.
		$option['third_party_namespace_decisions'] = [ 'test-plugin' => 'block' ];
		update_option( 'sd_ai_agent_settings', $option );

		// Now it should be private-explicit (namespace decision overrides heuristic).
		$this->assertSame(
			AbilityVisibility::CLASSIFICATION_PRIVATE_EXPLICIT,
			AbilityVisibility::classify( $ability )
		);
	}
}
