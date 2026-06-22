<?php
/**
 * Test case for GlobalStylesAbilities class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\GlobalStylesAbilities;
use WP_UnitTestCase;

/**
 * Test GlobalStylesAbilities handler methods.
 */
class GlobalStylesAbilitiesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Test update-global-styles schema nudges agents toward non-empty styles.
	 */
	public function test_update_global_styles_schema_requires_styles_argument(): void {
		$this->ensure_global_styles_ability_registered();

		$ability = wp_get_ability( 'sd-ai-agent/update-global-styles' );
		$this->assertInstanceOf( \WP_Ability::class, $ability );

		$schema = $ability->get_input_schema();

		$this->assertSame( [ 'styles' ], $schema['required'] );
		$this->assertSame( 1, $schema['properties']['styles']['minProperties'] );
		$this->assertStringContainsString( 'never call this with empty styles/settings', $ability->get_description() );
	}

	/**
	 * Test update-global-styles returns an affected descriptor.
	 */
	public function test_handle_update_global_styles_returns_affected_payload(): void {
		$result = GlobalStylesAbilities::handle_update_global_styles(
			[
				'styles' => [
					'color' => [
						'text' => '#111111',
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'affected', $result );
		$this->assertSame( 'global_styles', $result['affected']['kind'] );
		$this->assertNotEmpty( $result['affected']['url'] );
		$this->assertContains( 'styles', $result['affected']['fields'] );
	}

	/**
	 * Test reset-global-styles returns an affected descriptor after deleting customizations.
	 */
	public function test_handle_reset_global_styles_returns_affected_payload(): void {
		$update = GlobalStylesAbilities::handle_update_global_styles(
			[
				'settings' => [
					'color' => [
						'custom' => true,
					],
				],
			]
		);
		$this->assertIsArray( $update );

		$result = GlobalStylesAbilities::handle_reset_global_styles( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'affected', $result );
		$this->assertSame( 'global_styles', $result['affected']['kind'] );
		$this->assertNotEmpty( $result['affected']['url'] );
		$this->assertContains( 'reset', $result['affected']['fields'] );
	}

	/**
	 * Register the global styles ability when the full bootstrap has not already done it.
	 */
	private function ensure_global_styles_ability_registered(): void {
		if ( null !== wp_get_ability( 'sd-ai-agent/update-global-styles' ) ) {
			return;
		}

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init';
		GlobalStylesAbilities::register_abilities();
		array_pop( $wp_current_filter );
	}
}
