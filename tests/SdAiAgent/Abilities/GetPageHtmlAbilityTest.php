<?php

declare(strict_types=1);
/**
 * Test case for GetPageHtmlAbility class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\GetPageHtmlAbility;
use WP_UnitTestCase;

/**
 * Test GetPageHtmlAbility functionality.
 */
class GetPageHtmlAbilityTest extends WP_UnitTestCase {

	/**
	 * Build a GetPageHtmlAbility instance for testing.
	 *
	 * @return GetPageHtmlAbility
	 */
	private function make_ability(): GetPageHtmlAbility {
		return new GetPageHtmlAbility(
			'sd-ai-agent/get-page-html',
			[
				'label'       => 'Get Page HTML',
				'description' => 'Get the HTML content of elements on the current page.',
			]
		);
	}

	// ── execute_callback — empty selector ─────────────────────────────────

	/**
	 * execute_callback() returns WP_Error for empty selector.
	 */
	public function test_execute_returns_wp_error_for_empty_selector(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$ability = $this->make_ability();
		$result  = $ability->run( [ 'selector' => '' ] );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_empty_selector', $result->get_error_code() );
	}

	/**
	 * execute_callback() returns WP_Error when selector is missing.
	 */
	public function test_execute_returns_wp_error_for_missing_selector(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$ability = $this->make_ability();
		$result  = $ability->run( [] );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_empty_selector', $result->get_error_code() );
	}

	/**
	 * The model-facing schema explains how to recover when the exact selector is unknown.
	 */
	public function test_schema_exposes_required_selector_with_fallback_guidance(): void {
		$ability = $this->make_ability();
		$schema  = $ability->get_input_schema();

		$this->assertSame( [ 'selector' ], $schema['required'] );
		$this->assertStringContainsString( 'Required CSS selector', $schema['properties']['selector']['description'] );
		$this->assertStringContainsString( 'Use "body"', $schema['properties']['selector']['description'] );
	}

	// ── execute_callback — valid selector ─────────────────────────────────

	/**
	 * execute_callback() returns array with expected keys for valid selector.
	 */
	public function test_execute_returns_expected_shape_for_valid_selector(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$ability = $this->make_ability();
		$result  = $ability->run( [ 'selector' => '#main-content' ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'selector', $result );
		$this->assertArrayHasKey( 'max_length', $result );
		$this->assertArrayHasKey( 'action', $result );
		$this->assertArrayHasKey( 'message', $result );
	}

	/**
	 * execute_callback() returns correct selector in result.
	 */
	public function test_execute_returns_correct_selector(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$ability = $this->make_ability();
		$result  = $ability->run( [ 'selector' => '.entry-title' ] );

		$this->assertIsArray( $result );
		$this->assertSame( '.entry-title', $result['selector'] );
	}

	/**
	 * execute_callback() returns 'get_page_html' as action.
	 */
	public function test_execute_returns_get_page_html_action(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$ability = $this->make_ability();
		$result  = $ability->run( [ 'selector' => 'body' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'get_page_html', $result['action'] );
	}

	/**
	 * execute_callback() uses default max_length of 5000 when not specified.
	 */
	public function test_execute_uses_default_max_length(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$ability = $this->make_ability();
		$result  = $ability->run( [ 'selector' => 'article' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 5000, $result['max_length'] );
	}

	/**
	 * execute_callback() uses provided max_length.
	 */
	public function test_execute_uses_provided_max_length(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$ability = $this->make_ability();
		$result  = $ability->run( [
			'selector'   => 'article',
			'max_length' => 2000,
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 2000, $result['max_length'] );
	}

	/**
	 * execute_callback() includes selector in message.
	 */
	public function test_execute_includes_selector_in_message(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$ability = $this->make_ability();
		$result  = $ability->run( [ 'selector' => '#content' ] );

		$this->assertIsArray( $result );
		$this->assertStringContainsString( '#content', $result['message'] );
	}

	// ── input_schema ──────────────────────────────────────────────────────

	/**
	 * Ability has 'selector' as required field.
	 */
	public function test_ability_has_selector_as_required(): void {
		$ability = $this->make_ability();
		$schema  = $ability->get_input_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'selector', $schema['required'] );
	}

	/**
	 * Ability has 'max_length' as optional property.
	 */
	public function test_ability_has_max_length_property(): void {
		$ability = $this->make_ability();
		$schema  = $ability->get_input_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'max_length', $schema['properties'] );
	}
}
