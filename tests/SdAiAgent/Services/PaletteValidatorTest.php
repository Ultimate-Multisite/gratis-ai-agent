<?php

declare(strict_types=1);
/**
 * Tests for PaletteValidator — WCAG AA contrast validation (GH#1535).
 *
 * Covers:
 *  - Hex normalisation (#fff, #FFFFFF, ffffff, malformed) via normalise_hex().
 *  - Relative luminance edge cases (pure black, pure white, mid-grey).
 *  - Contrast ratio symmetry and known reference pairs (#000/#fff = 21:1).
 *  - passes() threshold semantics including the 1e-6 boundary tolerance.
 *  - check() against a known-passing palette returns passed=true, no failures.
 *  - check() against a known-failing palette (low-contrast accent) returns
 *    the failure with correct fg/bg slugs and ratio.
 *  - check() skips pairs whose slugs are missing from the palette.
 *  - Suggestions returned for a failing pair have a higher contrast ratio
 *    than the original AND pass the required threshold.
 *  - Custom pair overrides are accepted by the constructor.
 *  - Default pair set covers the four Theme Builder fundamentals.
 *
 * @package SdAiAgent\Tests\Services
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Services;

use SdAiAgent\Services\PaletteValidator;
use WP_UnitTestCase;

/**
 * Test PaletteValidator behaviour.
 */
class PaletteValidatorTest extends WP_UnitTestCase {

	// ── hex normalisation ─────────────────────────────────────────────────

	public function test_normalise_hex_accepts_six_digit_with_hash(): void {
		$this->assertSame( '#ffffff', PaletteValidator::normalise_hex( '#FFFFFF' ) );
		$this->assertSame( '#1a2b3c', PaletteValidator::normalise_hex( '#1A2B3C' ) );
	}

	public function test_normalise_hex_accepts_six_digit_without_hash(): void {
		$this->assertSame( '#abcdef', PaletteValidator::normalise_hex( 'ABCDEF' ) );
	}

	public function test_normalise_hex_expands_three_digit_shorthand(): void {
		$this->assertSame( '#ffffff', PaletteValidator::normalise_hex( '#fff' ) );
		$this->assertSame( '#aabbcc', PaletteValidator::normalise_hex( 'abc' ) );
	}

	public function test_normalise_hex_returns_null_for_invalid_input(): void {
		$this->assertNull( PaletteValidator::normalise_hex( '' ) );
		$this->assertNull( PaletteValidator::normalise_hex( '#xyzxyz' ) );
		$this->assertNull( PaletteValidator::normalise_hex( '#12345' ) );
		$this->assertNull( PaletteValidator::normalise_hex( 'not-a-colour' ) );
	}

	// ── relative luminance ───────────────────────────────────────────────

	public function test_relative_luminance_pure_black_is_zero(): void {
		$this->assertEqualsWithDelta(
			0.0,
			PaletteValidator::relative_luminance( '#000000' ),
			1e-9,
			'Pure black should have luminance 0.'
		);
	}

	public function test_relative_luminance_pure_white_is_one(): void {
		$this->assertEqualsWithDelta(
			1.0,
			PaletteValidator::relative_luminance( '#ffffff' ),
			1e-9,
			'Pure white should have luminance 1.'
		);
	}

	public function test_relative_luminance_mid_grey_in_range(): void {
		$lum = PaletteValidator::relative_luminance( '#777777' );
		$this->assertGreaterThan( 0.1, $lum );
		$this->assertLessThan( 0.3, $lum, 'Mid grey luminance should be in the 0.1–0.3 sRGB range.' );
	}

	public function test_relative_luminance_invalid_hex_returns_zero(): void {
		$this->assertSame( 0.0, PaletteValidator::relative_luminance( 'garbage' ) );
	}

	// ── contrast ratio ────────────────────────────────────────────────────

	public function test_contrast_ratio_black_on_white_is_twenty_one(): void {
		$this->assertEqualsWithDelta(
			21.0,
			PaletteValidator::contrast_ratio( '#000000', '#ffffff' ),
			0.01,
			'Black on white is the WCAG maximum contrast: 21:1.'
		);
	}

	public function test_contrast_ratio_is_symmetric(): void {
		$a = PaletteValidator::contrast_ratio( '#1a1a1a', '#ffffff' );
		$b = PaletteValidator::contrast_ratio( '#ffffff', '#1a1a1a' );
		$this->assertEqualsWithDelta( $a, $b, 1e-9, 'contrast_ratio must be symmetric.' );
	}

	public function test_contrast_ratio_same_colour_is_one(): void {
		$this->assertEqualsWithDelta(
			1.0,
			PaletteValidator::contrast_ratio( '#3858e9', '#3858e9' ),
			1e-9,
			'Identical colours have ratio 1:1.'
		);
	}

	// ── passes() threshold ────────────────────────────────────────────────

	public function test_passes_at_threshold_is_inclusive(): void {
		$this->assertTrue( PaletteValidator::passes( 4.5, PaletteValidator::RATIO_AA_NORMAL ) );
		$this->assertTrue( PaletteValidator::passes( 3.0, PaletteValidator::RATIO_AA_LARGE ) );
	}

	public function test_passes_below_threshold_fails(): void {
		$this->assertFalse( PaletteValidator::passes( 4.49, PaletteValidator::RATIO_AA_NORMAL ) );
		$this->assertFalse( PaletteValidator::passes( 2.99, PaletteValidator::RATIO_AA_LARGE ) );
	}

	// ── check() with passing palette ──────────────────────────────────────

	public function test_check_passes_for_canonical_high_contrast_palette(): void {
		$validator = new PaletteValidator(
			[
				[ 'slug' => 'foreground', 'color' => '#1a1a1a' ],
				[ 'slug' => 'background', 'color' => '#ffffff' ],
				[ 'slug' => 'accent', 'color' => '#005fcc' ],
			]
		);

		$result = $validator->check();

		$this->assertTrue( $result['passed'], 'High-contrast palette must pass all default pairs.' );
		$this->assertSame( [], $result['failures'], 'No failures expected.' );
		$this->assertSame( [], $result['suggestions'], 'No suggestions expected when palette passes.' );
		$this->assertGreaterThan( 0, $result['pairs_checked'], 'pairs_checked must report > 0 for a populated palette.' );
	}

	// ── check() with failing palette ──────────────────────────────────────

	public function test_check_reports_failure_for_low_contrast_accent(): void {
		// Sage green foreground (#7a8a6b) on parchment (#f4ecd8) — the actual
		// charming-but-failing combo from the post-#1522 walkthrough.
		$validator = new PaletteValidator(
			[
				[ 'slug' => 'foreground', 'color' => '#2a2a2a' ],
				[ 'slug' => 'background', 'color' => '#f4ecd8' ],
				[ 'slug' => 'accent', 'color' => '#7a8a6b' ],
			]
		);

		$result = $validator->check();

		$this->assertFalse( $result['passed'], 'Low-contrast accent should fail.' );
		$this->assertNotEmpty( $result['failures'] );

		$accent_failures = array_filter(
			$result['failures'],
			static fn( array $f ): bool => 'link-on-background' === $f['pair_id']
		);
		$this->assertNotEmpty( $accent_failures, 'link-on-background failure must be reported.' );

		$failure = array_values( $accent_failures )[0];
		$this->assertSame( 'accent', $failure['fg_slug'] );
		$this->assertSame( 'background', $failure['bg_slug'] );
		$this->assertSame( '#7a8a6b', $failure['fg_hex'] );
		$this->assertSame( '#f4ecd8', $failure['bg_hex'] );
		$this->assertLessThan( PaletteValidator::RATIO_AA_NORMAL, $failure['ratio'] );
		$this->assertSame( PaletteValidator::RATIO_AA_NORMAL, $failure['required'] );
	}

	public function test_check_skips_pairs_with_missing_slugs(): void {
		// Only foreground + background — accent missing means link &
		// button-text pairs should be silently skipped.
		$validator = new PaletteValidator(
			[
				[ 'slug' => 'foreground', 'color' => '#000000' ],
				[ 'slug' => 'background', 'color' => '#ffffff' ],
			]
		);

		$result = $validator->check();

		$this->assertTrue( $result['passed'] );
		$this->assertSame( [], $result['failures'] );
	}

	public function test_check_uses_short_hex_after_normalisation(): void {
		$validator = new PaletteValidator(
			[
				[ 'slug' => 'foreground', 'color' => '#000' ],
				[ 'slug' => 'background', 'color' => 'FFF' ],
				[ 'slug' => 'accent', 'color' => '#00f' ],
			]
		);

		$result = $validator->check();

		$this->assertTrue( $result['passed'], 'Short and unprefixed hex must be normalised before validation.' );
	}

	// ── suggestions ──────────────────────────────────────────────────────

	public function test_suggestion_improves_failing_pair_contrast(): void {
		$validator = new PaletteValidator(
			[
				[ 'slug' => 'foreground', 'color' => '#2a2a2a' ],
				[ 'slug' => 'background', 'color' => '#f4ecd8' ],
				[ 'slug' => 'accent', 'color' => '#7a8a6b' ],
			]
		);

		$result = $validator->check();
		$this->assertNotEmpty( $result['suggestions'] );

		$accent_suggestion = null;
		foreach ( $result['suggestions'] as $s ) {
			if ( 'link-on-background' === $s['pair_id'] ) {
				$accent_suggestion = $s;
				break;
			}
		}
		$this->assertIsArray( $accent_suggestion, 'link-on-background suggestion must be present.' );

		$original_ratio  = PaletteValidator::contrast_ratio( '#7a8a6b', '#f4ecd8' );
		$suggested_ratio = PaletteValidator::contrast_ratio(
			$accent_suggestion['suggested_fg'],
			$accent_suggestion['original_bg']
		);
		$this->assertGreaterThan(
			$original_ratio,
			$suggested_ratio,
			'Suggested foreground must improve contrast over the original.'
		);
		$this->assertTrue(
			PaletteValidator::passes( $suggested_ratio, PaletteValidator::RATIO_AA_NORMAL ),
			'Suggested foreground must meet WCAG AA normal threshold.'
		);
	}

	public function test_suggestion_preserves_pair_metadata(): void {
		$validator = new PaletteValidator(
			[
				[ 'slug' => 'foreground', 'color' => '#cccccc' ],
				[ 'slug' => 'background', 'color' => '#ffffff' ],
				[ 'slug' => 'accent', 'color' => '#cccccc' ],
			]
		);

		$result = $validator->check();
		$this->assertNotEmpty( $result['suggestions'] );

		$suggestion = $result['suggestions'][0];
		$this->assertArrayHasKey( 'pair_id', $suggestion );
		$this->assertArrayHasKey( 'label', $suggestion );
		$this->assertArrayHasKey( 'required', $suggestion );
		$this->assertArrayHasKey( 'original_fg', $suggestion );
		$this->assertArrayHasKey( 'original_bg', $suggestion );
		$this->assertArrayHasKey( 'suggested_fg', $suggestion );
		$this->assertArrayHasKey( 'suggested_fg_step', $suggestion );
		$this->assertArrayHasKey( 'suggested_bg', $suggestion );
		$this->assertArrayHasKey( 'suggested_bg_step', $suggestion );
	}

	// ── default pair set ──────────────────────────────────────────────────

	public function test_default_pairs_include_the_four_theme_builder_fundamentals(): void {
		$validator = new PaletteValidator(
			[
				[ 'slug' => 'foreground', 'color' => '#000' ],
				[ 'slug' => 'background', 'color' => '#fff' ],
				[ 'slug' => 'accent', 'color' => '#3858e9' ],
			]
		);

		$ids = array_column( $validator->get_pairs(), 'id' );

		$this->assertContains( 'body-on-background', $ids );
		$this->assertContains( 'heading-on-background', $ids );
		$this->assertContains( 'link-on-background', $ids );
		$this->assertContains( 'button-text-on-accent', $ids );
	}

	// ── custom pair overrides ─────────────────────────────────────────────

	public function test_custom_pair_overrides_replace_default_set(): void {
		$validator = new PaletteValidator(
			[
				[ 'slug' => 'foreground', 'color' => '#000' ],
				[ 'slug' => 'background', 'color' => '#fff' ],
				[ 'slug' => 'kicker', 'color' => '#888' ],
			],
			[
				[
					'id'       => 'kicker-on-background',
					'fg_slug'  => 'kicker',
					'bg_slug'  => 'background',
					'required' => PaletteValidator::RATIO_AA_NORMAL,
					'label'    => 'Kicker text on background',
				],
			]
		);

		$pairs = $validator->get_pairs();
		$this->assertCount( 1, $pairs );
		$this->assertSame( 'kicker-on-background', $pairs[0]['id'] );

		$result = $validator->check();
		$this->assertFalse( $result['passed'], '#888 on #fff fails 4.5:1.' );
		$this->assertCount( 1, $result['failures'] );
		$this->assertSame( 'kicker-on-background', $result['failures'][0]['pair_id'] );
	}

	public function test_custom_pair_override_drops_malformed_entries(): void {
		$validator = new PaletteValidator(
			[
				[ 'slug' => 'foreground', 'color' => '#000' ],
				[ 'slug' => 'background', 'color' => '#fff' ],
			],
			[
				[ 'id' => 'no-slugs' ], // missing fg_slug / bg_slug — must drop.
				[
					'id'      => 'good',
					'fg_slug' => 'foreground',
					'bg_slug' => 'background',
				],
			]
		);

		$pairs = $validator->get_pairs();
		$this->assertCount( 1, $pairs, 'Malformed pair entries must be silently dropped.' );
		$this->assertSame( 'good', $pairs[0]['id'] );
	}

	// ── input robustness ─────────────────────────────────────────────────

	public function test_palette_with_invalid_hex_entries_is_silently_skipped(): void {
		$validator = new PaletteValidator(
			[
				[ 'slug' => 'foreground', 'color' => '#000' ],
				[ 'slug' => 'background', 'color' => 'NOT-A-COLOUR' ], // dropped.
				[ 'slug' => 'accent', 'color' => '#3858e9' ],
			]
		);

		$indexed = $validator->get_palette();
		$this->assertArrayHasKey( 'foreground', $indexed );
		$this->assertArrayHasKey( 'accent', $indexed );
		$this->assertArrayNotHasKey( 'background', $indexed, 'Invalid hex must be dropped from indexed palette.' );
	}
}
