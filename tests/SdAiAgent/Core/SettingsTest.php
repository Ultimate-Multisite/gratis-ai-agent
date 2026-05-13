<?php
/**
 * Test case for Settings class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\Settings;
use WP_UnitTestCase;

/**
 * Test double used to satisfy Settings::maybe_auto_enable_woo_abilities().
 */
final class WooCommerceTestDouble {}

/**
 * Test Settings functionality.
 */
class SettingsTest extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		parent::tear_down();
		delete_option( Settings::OPTION_NAME );
		delete_option( Settings::WOO_AUTO_ENABLED_OPTION );
	}

	/**
	 * Test get_defaults returns expected keys.
	 */
	public function test_get_defaults_returns_expected_keys() {
		$defaults = Settings::instance()->get_defaults();

		$this->assertIsArray( $defaults );
		$this->assertArrayHasKey( 'default_provider', $defaults );
		$this->assertArrayHasKey( 'default_model', $defaults );
		$this->assertArrayHasKey( 'max_iterations', $defaults );
		$this->assertArrayHasKey( 'greeting_message', $defaults );
		$this->assertArrayHasKey( 'system_prompt', $defaults );
		$this->assertArrayHasKey( 'auto_memory', $defaults );
		$this->assertArrayHasKey( 'temperature', $defaults );
		$this->assertArrayHasKey( 'max_output_tokens', $defaults );
		$this->assertArrayHasKey( 'max_history_turns', $defaults );
	}

	/**
	 * Test get_defaults returns expected default values.
	 */
	public function test_get_defaults_returns_expected_values() {
		$defaults = Settings::instance()->get_defaults();

		$this->assertSame( 50, $defaults['max_iterations'] );
		$this->assertSame( true, $defaults['auto_memory'] );
		$this->assertSame( 0.2, $defaults['temperature'] );
		// 0 is the "auto / per-model" sentinel — see Settings::MAX_OUTPUT_TOKENS_AUTO
		// and Settings::get_max_output_tokens_for_model(). Was 4096 until sd-ai-7rl.
		$this->assertSame( 0, $defaults['max_output_tokens'] );
		$this->assertSame( 20, $defaults['max_history_turns'] );
	}

	/**
	 * Test get returns all settings merged with defaults.
	 */
	public function test_get_returns_merged_settings() {
		$settings = Settings::instance()->get();

		$this->assertIsArray( $settings );
		$this->assertArrayHasKey( 'max_iterations', $settings );
		$this->assertArrayHasKey( 'temperature', $settings );
	}

	/**
	 * Test get returns single setting when key provided.
	 */
	public function test_get_returns_single_setting() {
		$max_iterations = Settings::instance()->get( 'max_iterations' );

		$this->assertSame( 50, $max_iterations );
	}

	/**
	 * Test get returns null for unknown key.
	 */
	public function test_get_returns_null_for_unknown_key() {
		$result = Settings::instance()->get( 'nonexistent_setting' );

		$this->assertNull( $result );
	}

	/**
	 * Test update saves settings.
	 */
	public function test_update_saves_settings() {
		$result = Settings::instance()->update( [ 'max_iterations' => 50 ] );

		$this->assertTrue( $result );
		$this->assertSame( 50, Settings::instance()->get( 'max_iterations' ) );
	}

	/**
	 * Test update only allows known keys.
	 */
	public function test_update_only_allows_known_keys() {
		Settings::instance()->update( [ 'unknown_key' => 'test_value' ] );

		$settings = Settings::instance()->get();
		$this->assertArrayNotHasKey( 'unknown_key', $settings );
	}

	/**
	 * Test update merges with existing settings.
	 */
	public function test_update_merges_with_existing_settings() {
		Settings::instance()->update( [ 'max_iterations' => 30 ] );
		Settings::instance()->update( [ 'temperature' => 0.5 ] );

		$this->assertSame( 30, Settings::instance()->get( 'max_iterations' ) );
		$this->assertSame( 0.5, Settings::instance()->get( 'temperature' ) );
	}

	/**
	 * Test OPTION_NAME constant.
	 */
	public function test_option_name_constant() {
		$this->assertSame( 'sd_ai_agent_settings', Settings::OPTION_NAME );
	}

	/**
	 * Test tool_permissions default is empty array.
	 */
	public function test_tool_permissions_default_is_empty_array() {
		$defaults = Settings::instance()->get_defaults();

		$this->assertIsArray( $defaults['tool_permissions'] );
		$this->assertEmpty( $defaults['tool_permissions'] );
	}

	/**
	 * Test update can save array values (using tool_permissions, the
	 * remaining curated ability-gating setting).
	 */
	public function test_update_can_save_array_values() {
		$perms = [ 'tool1' => 'disabled', 'tool2' => 'confirm' ];
		Settings::instance()->update( [ 'tool_permissions' => $perms ] );

		$result = Settings::instance()->get( 'tool_permissions' );
		$this->assertSame( $perms, $result );
	}

	/**
	 * Test WooCommerce auto-enable grants native WooCommerce REST abilities.
	 */
	public function test_maybe_auto_enable_woo_abilities_grants_native_woocommerce_abilities(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			class_alias( WooCommerceTestDouble::class, 'WooCommerce' );
		}

		Settings::instance()->update(
			[
				'default_provider'  => 'openai',
				'tool_permissions' => [ 'sd-ai-agent/create-post' => 'confirm' ],
			]
		);

		Settings::instance()->maybe_auto_enable_woo_abilities();

		$permissions = Settings::instance()->get( 'tool_permissions' );
		$this->assertSame( 'confirm', $permissions['sd-ai-agent/create-post'] );
		$this->assertSame( 'auto', $permissions['woocommerce/products-list'] );
		$this->assertSame( 'auto', $permissions['woocommerce/products-create'] );
		$this->assertSame( 'auto', $permissions['woocommerce/orders-list'] );
		$this->assertArrayNotHasKey( 'sd-ai-agent/woo-get-products', $permissions );
		$this->assertTrue( (bool) get_option( Settings::WOO_AUTO_ENABLED_OPTION ) );
	}

	// ─────────────────────────────────────────────────────────────────────
	// get_max_output_tokens_for_model() — sd-ai-7rl
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Empty model id falls back to the safe default, not 0.
	 */
	public function test_max_output_tokens_for_empty_model_returns_fallback(): void {
		$this->assertSame(
			Settings::MAX_OUTPUT_TOKENS_FALLBACK,
			Settings::get_max_output_tokens_for_model( '' )
		);
	}

	/**
	 * Unknown model id falls back to the safe default rather than 0 or the
	 * ceiling — protects against the SDK refusing requests when a third-party
	 * connector advertises an ID we don't catalogue.
	 */
	public function test_max_output_tokens_for_unknown_model_returns_fallback(): void {
		$this->assertSame(
			Settings::MAX_OUTPUT_TOKENS_FALLBACK,
			Settings::get_max_output_tokens_for_model( 'wholly-made-up-model-9000' )
		);
	}

	/**
	 * Exact-match Claude Opus 4.x resolves to its 32K family entry.
	 *
	 * Specifically guards the session-16 regression: claude-opus-4-7 was
	 * silently using the 4096 default, which truncated tool_use input JSON
	 * mid-payload and caused the agent loop to spin.
	 */
	public function test_max_output_tokens_resolves_claude_opus_4_family(): void {
		// Bare family prefix.
		$this->assertSame(
			32000,
			Settings::get_max_output_tokens_for_model( 'claude-opus-4' )
		);
		// Dated variant — longest-prefix match must still resolve.
		$this->assertSame(
			32000,
			Settings::get_max_output_tokens_for_model( 'claude-opus-4-7' )
		);
		$this->assertSame(
			32000,
			Settings::get_max_output_tokens_for_model( 'claude-opus-4-7-20260513' )
		);
	}

	/**
	 * GPT-4.1 family and o-series resolve via longest-prefix matching.
	 */
	public function test_max_output_tokens_resolves_openai_families(): void {
		$this->assertSame( 32000, Settings::get_max_output_tokens_for_model( 'gpt-4.1' ) );
		$this->assertSame( 32000, Settings::get_max_output_tokens_for_model( 'gpt-4.1-mini' ) );
		$this->assertSame( 32000, Settings::get_max_output_tokens_for_model( 'gpt-4.1-nano' ) );
		$this->assertSame( 16000, Settings::get_max_output_tokens_for_model( 'gpt-4o' ) );
		$this->assertSame( 16000, Settings::get_max_output_tokens_for_model( 'gpt-4o-mini' ) );
		$this->assertSame( 32000, Settings::get_max_output_tokens_for_model( 'o3-mini' ) );
		$this->assertSame( 32000, Settings::get_max_output_tokens_for_model( 'o4-mini' ) );
	}

	/**
	 * Gemini Flash family is appropriately conservative at 8K.
	 */
	public function test_max_output_tokens_resolves_gemini_families(): void {
		$this->assertSame( 32000, Settings::get_max_output_tokens_for_model( 'gemini-2.5-pro' ) );
		$this->assertSame( 8192, Settings::get_max_output_tokens_for_model( 'gemini-2.5-flash' ) );
		$this->assertSame( 8192, Settings::get_max_output_tokens_for_model( 'gemini-2.0-flash' ) );
		$this->assertSame( 8192, Settings::get_max_output_tokens_for_model( 'gemini-1.5-pro' ) );
	}

	/**
	 * The filter can raise or lower the value, but the result is clamped to
	 * [1, MAX_OUTPUT_TOKENS_CEILING]. Bad filter returns fall back to the
	 * safe default.
	 */
	public function test_max_output_tokens_filter_is_clamped(): void {
		add_filter(
			'sd_ai_agent_max_output_tokens_for_model',
			static function () {
				return 9999999;
			}
		);
		$this->assertSame(
			Settings::MAX_OUTPUT_TOKENS_CEILING,
			Settings::get_max_output_tokens_for_model( 'gpt-4o' )
		);
		remove_all_filters( 'sd_ai_agent_max_output_tokens_for_model' );

		add_filter(
			'sd_ai_agent_max_output_tokens_for_model',
			static function () {
				return -50;
			}
		);
		$this->assertSame(
			Settings::MAX_OUTPUT_TOKENS_FALLBACK,
			Settings::get_max_output_tokens_for_model( 'gpt-4o' )
		);
		remove_all_filters( 'sd_ai_agent_max_output_tokens_for_model' );
	}
}
