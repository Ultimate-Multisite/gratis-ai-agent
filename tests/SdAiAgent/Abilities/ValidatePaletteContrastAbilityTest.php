<?php

declare(strict_types=1);
/**
 * Smoke tests for ValidatePaletteContrastAbility — GH#1535.
 *
 * The full WCAG maths are covered by PaletteValidatorTest. This file
 * exercises just the ability-layer contract:
 *   - run() with a passing palette returns passed=true
 *   - run() with a failing palette returns failures and suggestions
 *   - run() with an empty palette returns WP_Error
 *   - run() respects custom pair overrides
 *
 * @package SdAiAgent\Tests\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\ValidatePaletteContrastAbility;
use WP_Error;
use WP_UnitTestCase;

/**
 * Test ValidatePaletteContrastAbility surface contract.
 */
class ValidatePaletteContrastAbilityTest extends WP_UnitTestCase {

	private function ability(): ValidatePaletteContrastAbility {
		return new ValidatePaletteContrastAbility( 'sd-ai-agent/validate-palette-contrast' );
	}

	public function test_run_returns_passed_true_for_high_contrast_palette(): void {
		$result = $this->ability()->run(
			[
				'palette' => [
					[ 'slug' => 'foreground', 'color' => '#1a1a1a' ],
					[ 'slug' => 'background', 'color' => '#ffffff' ],
					[ 'slug' => 'accent', 'color' => '#005fcc' ],
				],
			]
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( $result['passed'] );
		$this->assertSame( [], $result['failures'] );
		$this->assertSame( [], $result['suggestions'] );
	}

	public function test_run_returns_failures_and_suggestions_for_low_contrast_palette(): void {
		$result = $this->ability()->run(
			[
				'palette' => [
					[ 'slug' => 'foreground', 'color' => '#2a2a2a' ],
					[ 'slug' => 'background', 'color' => '#f4ecd8' ],
					[ 'slug' => 'accent', 'color' => '#7a8a6b' ],
				],
			]
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertFalse( $result['passed'] );
		$this->assertNotEmpty( $result['failures'] );
		$this->assertNotEmpty( $result['suggestions'] );
	}

	public function test_run_returns_wp_error_for_empty_palette(): void {
		$result = $this->ability()->run( [ 'palette' => [] ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_palette_required', $result->get_error_code() );
	}

	public function test_run_respects_custom_pair_overrides(): void {
		$result = $this->ability()->run(
			[
				'palette' => [
					[ 'slug' => 'kicker', 'color' => '#888888' ],
					[ 'slug' => 'background', 'color' => '#ffffff' ],
				],
				'pairs'   => [
					[
						'id'       => 'kicker-on-background',
						'fg_slug'  => 'kicker',
						'bg_slug'  => 'background',
						'required' => 4.5,
						'label'    => 'Kicker on background',
					],
				],
			]
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertFalse( $result['passed'], '#888 on #fff must fail 4.5:1.' );
		$this->assertSame( 1, $result['pairs_checked'] );
		$this->assertCount( 1, $result['failures'] );
		$this->assertSame( 'kicker-on-background', $result['failures'][0]['pair_id'] );
	}
}
