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
		$this->assertSame( 4096, $defaults['max_output_tokens'] );
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
}
