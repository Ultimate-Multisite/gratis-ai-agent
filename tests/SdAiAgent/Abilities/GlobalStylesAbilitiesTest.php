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
}
