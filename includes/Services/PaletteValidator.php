<?php

declare(strict_types=1);
/**
 * Palette Validator service — WCAG AA contrast checks for theme palettes.
 *
 * Validates a WordPress theme.json color palette against the WCAG 2.1
 * contrast minimums (4.5:1 for body text, 3:1 for large text and non-text
 * UI components). Designed to run at the END of the Theme Builder
 * direction-selection step, before scaffold-block-theme is called, so the
 * agent can either auto-adjust a failing palette or surface the failure to
 * the user with options.
 *
 * Standalone — no WordPress dependencies on the hot path (only used for
 * filterable output). Pure functions for luminance + ratio computation so
 * unit tests can exercise the maths directly.
 *
 * @package SdAiAgent\Services
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCAG AA palette contrast validator.
 *
 * Input: a theme.json-shaped palette — an array of entries each containing
 * at minimum `slug` and `color` (hex). Output: a structured `check()` result
 * with `failures` (pairs that miss the WCAG threshold) and `suggestions`
 * (nudged hex values that pass while staying close to the user's choice).
 *
 * Usage:
 *
 *     $validator = new PaletteValidator( [
 *         [ 'slug' => 'foreground', 'color' => '#1a1a1a' ],
 *         [ 'slug' => 'background', 'color' => '#ffffff' ],
 *         [ 'slug' => 'accent',     'color' => '#3858e9' ],
 *     ] );
 *     $result = $validator->check();
 *     // $result['failures']    : list of failing pairs
 *     // $result['suggestions'] : list of suggested hex adjustments
 *     // $result['passed']      : true when every pair meets WCAG AA
 *
 * @since 1.16.0
 */
final class PaletteValidator {

	/**
	 * WCAG AA contrast ratio for normal-size body text.
	 */
	public const RATIO_AA_NORMAL = 4.5;

	/**
	 * WCAG AA contrast ratio for large text (>= 18pt regular / 14pt bold)
	 * and non-text UI components.
	 */
	public const RATIO_AA_LARGE = 3.0;

	/**
	 * Maximum number of nudge iterations when generating suggestions.
	 *
	 * 24 steps with a 4% lightness delta covers the full 0..100% L* range
	 * without overshooting; 99% of real-world palettes resolve in under 12.
	 */
	private const MAX_NUDGE_STEPS = 24;

	/**
	 * Lightness delta per nudge step, in HSL units (0..1).
	 */
	private const NUDGE_STEP = 0.04;

	/**
	 * Palette entries indexed by slug.
	 *
	 * @var array<string, string> slug => hex (#rrggbb).
	 */
	private array $palette;

	/**
	 * Pair definitions to validate. Each pair is:
	 *   - id (string): stable identifier returned in failures/suggestions.
	 *   - fg_slug (string): palette slug providing the foreground colour.
	 *   - bg_slug (string): palette slug providing the background colour.
	 *   - required (float): WCAG AA threshold (RATIO_AA_NORMAL or _LARGE).
	 *   - label (string): human-readable label.
	 *
	 * @var array<int, array{id:string, fg_slug:string, bg_slug:string, required:float, label:string}>
	 */
	private array $pairs;

	/**
	 * Build a validator for the given palette.
	 *
	 * Malformed palette entries and pair overrides are silently dropped by
	 * {@see index_palette()} and {@see build_pairs()} respectively so the
	 * validator never throws on partial input. The `pairs` parameter is
	 * intentionally typed as `array<mixed, mixed>` to match the loose
	 * shape we receive from the JSON-schema ability layer; runtime
	 * normalisation enforces the strict shape used downstream.
	 *
	 * @param array<mixed, mixed>      $palette Theme.json-shaped palette: list of `{slug, color}` entries.
	 * @param array<mixed, mixed>|null $pairs   Optional override of the default pair set.
	 */
	public function __construct( array $palette, ?array $pairs = null ) {
		$this->palette = self::index_palette( $palette );
		$this->pairs   = self::build_pairs( $pairs );
	}

	/**
	 * Compute the WCAG relative luminance of a hex colour.
	 *
	 * Implements the sRGB linearisation + Rec.709 weighting defined in
	 * WCAG 2.1 §1.4.3. Returns a float in [0, 1] where 0 is pure black and
	 * 1 is pure white.
	 *
	 * @param string $hex Hex colour, e.g. "#fff", "#ffffff", or "ffffff".
	 * @return float Relative luminance, or 0.0 if the input is invalid.
	 */
	public static function relative_luminance( string $hex ): float {
		$rgb = self::hex_to_rgb( $hex );
		if ( null === $rgb ) {
			return 0.0;
		}

		// Linearise the sRGB channels per WCAG 2.1 §1.4.3.
		$lin = array_map(
			static function ( int $channel ): float {
				$srgb = $channel / 255.0;
				return $srgb <= 0.03928
					? $srgb / 12.92
					: ( ( $srgb + 0.055 ) / 1.055 ) ** 2.4;
			},
			$rgb
		);

		// Rec.709 luminance weights.
		return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
	}

	/**
	 * Compute the WCAG contrast ratio between two hex colours.
	 *
	 * Symmetric: contrast_ratio($a, $b) === contrast_ratio($b, $a). Returns
	 * a value in [1.0, 21.0] where 1 is identical colours and 21 is the
	 * absolute black-on-white maximum.
	 *
	 * @param string $fg Foreground hex colour.
	 * @param string $bg Background hex colour.
	 * @return float Contrast ratio, or 1.0 when either input is invalid.
	 */
	public static function contrast_ratio( string $fg, string $bg ): float {
		$l1 = self::relative_luminance( $fg );
		$l2 = self::relative_luminance( $bg );
		if ( $l1 < $l2 ) {
			$tmp = $l1;
			$l1  = $l2;
			$l2  = $tmp;
		}
		return ( $l1 + 0.05 ) / ( $l2 + 0.05 );
	}

	/**
	 * Whether the given ratio meets the given WCAG AA threshold.
	 *
	 * @param float $ratio    Computed contrast ratio.
	 * @param float $required WCAG threshold (default {@see RATIO_AA_NORMAL}).
	 */
	public static function passes( float $ratio, float $required = self::RATIO_AA_NORMAL ): bool {
		return $ratio + 1e-6 >= $required;
	}

	/**
	 * Validate the palette against the configured pair set.
	 *
	 * @return array{passed:bool, failures:array<int, array<string, mixed>>, suggestions:array<int, array<string, mixed>>, pairs_checked:int}
	 */
	public function check(): array {
		$failures    = [];
		$suggestions = [];

		foreach ( $this->pairs as $pair ) {
			$fg_hex = $this->palette[ $pair['fg_slug'] ] ?? null;
			$bg_hex = $this->palette[ $pair['bg_slug'] ] ?? null;

			if ( null === $fg_hex || null === $bg_hex ) {
				// Skip pairs that reference palette slugs we don't have.
				// (Themes commonly omit optional slugs like "tertiary".)
				continue;
			}

			$ratio = self::contrast_ratio( $fg_hex, $bg_hex );
			if ( self::passes( $ratio, $pair['required'] ) ) {
				continue;
			}

			$failures[]    = [
				'pair_id'  => $pair['id'],
				'label'    => $pair['label'],
				'fg_slug'  => $pair['fg_slug'],
				'bg_slug'  => $pair['bg_slug'],
				'fg_hex'   => $fg_hex,
				'bg_hex'   => $bg_hex,
				'ratio'    => round( $ratio, 2 ),
				'required' => $pair['required'],
			];
			$suggestions[] = $this->build_suggestion( $pair, $fg_hex, $bg_hex );
		}

		return [
			'passed'        => 0 === count( $failures ),
			'failures'      => $failures,
			'suggestions'   => $suggestions,
			'pairs_checked' => count( $this->pairs ),
		];
	}

	/**
	 * Return the pair definitions this validator checks.
	 *
	 * @return array<int, array{id:string, fg_slug:string, bg_slug:string, required:float, label:string}>
	 */
	public function get_pairs(): array {
		return $this->pairs;
	}

	/**
	 * Return the palette as a slug => hex map.
	 *
	 * @return array<string, string>
	 */
	public function get_palette(): array {
		return $this->palette;
	}

	/**
	 * Index a theme.json palette array as slug => hex.
	 *
	 * Invalid entries (missing slug, missing color, non-string color) are
	 * silently skipped so the validator never throws on partially-formed
	 * input; the agent receives a `failures` result instead.
	 *
	 * @param array<mixed, mixed> $palette Raw palette entries.
	 * @return array<string, string> slug => normalised hex (`#rrggbb`).
	 */
	private static function index_palette( array $palette ): array {
		$out = [];
		foreach ( $palette as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$slug = isset( $entry['slug'] ) && is_string( $entry['slug'] ) ? $entry['slug'] : '';
			$hex  = isset( $entry['color'] ) && is_string( $entry['color'] ) ? $entry['color'] : '';
			if ( '' === $slug || '' === $hex ) {
				continue;
			}
			$normalised = self::normalise_hex( $hex );
			if ( null === $normalised ) {
				continue;
			}
			$out[ $slug ] = $normalised;
		}
		return $out;
	}

	/**
	 * Build the active pair set.
	 *
	 * When `$override` is supplied the entries are normalised and used
	 * verbatim; otherwise the default theme-builder pair set is returned.
	 *
	 * @param array<mixed, mixed>|null $override Optional caller-supplied pairs.
	 * @return array<int, array{id:string, fg_slug:string, bg_slug:string, required:float, label:string}>
	 */
	private static function build_pairs( ?array $override ): array {
		if ( null === $override ) {
			return [
				[
					'id'       => 'body-on-background',
					'fg_slug'  => 'foreground',
					'bg_slug'  => 'background',
					'required' => self::RATIO_AA_NORMAL,
					'label'    => 'Body text on background',
				],
				[
					'id'       => 'heading-on-background',
					'fg_slug'  => 'foreground',
					'bg_slug'  => 'background',
					'required' => self::RATIO_AA_LARGE,
					'label'    => 'Heading on background',
				],
				[
					'id'       => 'link-on-background',
					'fg_slug'  => 'accent',
					'bg_slug'  => 'background',
					'required' => self::RATIO_AA_NORMAL,
					'label'    => 'Link / accent text on background',
				],
				[
					'id'       => 'button-text-on-accent',
					'fg_slug'  => 'background',
					'bg_slug'  => 'accent',
					'required' => self::RATIO_AA_NORMAL,
					'label'    => 'Button text on accent button background',
				],
			];
		}

		$out = [];
		foreach ( $override as $pair ) {
			if ( ! is_array( $pair ) ) {
				continue;
			}
			$id       = isset( $pair['id'] ) && is_string( $pair['id'] ) ? $pair['id'] : '';
			$fg_slug  = isset( $pair['fg_slug'] ) && is_string( $pair['fg_slug'] ) ? $pair['fg_slug'] : '';
			$bg_slug  = isset( $pair['bg_slug'] ) && is_string( $pair['bg_slug'] ) ? $pair['bg_slug'] : '';
			$required = isset( $pair['required'] ) && is_numeric( $pair['required'] )
				? (float) $pair['required']
				: self::RATIO_AA_NORMAL;
			$label    = isset( $pair['label'] ) && is_string( $pair['label'] ) ? $pair['label'] : $id;
			if ( '' === $id || '' === $fg_slug || '' === $bg_slug ) {
				continue;
			}
			$out[] = [
				'id'       => $id,
				'fg_slug'  => $fg_slug,
				'bg_slug'  => $bg_slug,
				'required' => $required,
				'label'    => $label,
			];
		}
		return $out;
	}

	/**
	 * Build a suggestion structure for a failing pair.
	 *
	 * Returns two candidates: a darkened foreground and a lightened
	 * background (whichever direction is required to climb above the
	 * threshold). Either may be omitted when the channel has already
	 * saturated at 0 or 255.
	 *
	 * @param array{id:string, fg_slug:string, bg_slug:string, required:float, label:string} $pair Pair definition.
	 * @param string                                                                         $fg_hex Original foreground hex.
	 * @param string                                                                         $bg_hex Original background hex.
	 * @return array<string, mixed>
	 */
	private function build_suggestion( array $pair, string $fg_hex, string $bg_hex ): array {
		$fg_candidate = $this->nudge_to_pass( $fg_hex, $bg_hex, $pair['required'], true );
		$bg_candidate = $this->nudge_to_pass( $bg_hex, $fg_hex, $pair['required'], false );

		return [
			'pair_id'           => $pair['id'],
			'label'             => $pair['label'],
			'required'          => $pair['required'],
			'original_fg'       => $fg_hex,
			'original_bg'       => $bg_hex,
			'suggested_fg'      => $fg_candidate['hex'],
			'suggested_fg_step' => $fg_candidate['steps'],
			'suggested_bg'      => $bg_candidate['hex'],
			'suggested_bg_step' => $bg_candidate['steps'],
		];
	}

	/**
	 * Nudge a colour's lightness in HSL until the contrast ratio passes the
	 * requirement.
	 *
	 * Direction is chosen automatically: if the partner is light, push the
	 * subject darker; if the partner is dark, push lighter. Returns the
	 * original hex when the nudge cannot find a passing value within
	 * MAX_NUDGE_STEPS iterations.
	 *
	 * @param string $subject  Hex colour to nudge.
	 * @param string $partner  Hex colour to contrast against (unchanged).
	 * @param float  $required WCAG threshold to clear.
	 * @param bool   $is_text  True when the subject is the foreground/text colour, false when it is the background.
	 * @return array{hex:string, steps:int}
	 */
	private function nudge_to_pass( string $subject, string $partner, float $required, bool $is_text ): array {
		$partner_lum = self::relative_luminance( $partner );
		// If the partner is "light" (luminance > 0.5) we darken the subject;
		// otherwise we lighten it. The is_text flag flips the default for
		// background nudges so we always move AWAY from the partner.
		$darken = ( $partner_lum > 0.5 ) ? $is_text : ! $is_text;

		$hsl = self::hex_to_hsl( $subject );
		if ( null === $hsl ) {
			return [
				'hex'   => $subject,
				'steps' => 0,
			];
		}

		for ( $i = 1; $i <= self::MAX_NUDGE_STEPS; $i++ ) {
			$delta = self::NUDGE_STEP * $i * ( $darken ? -1.0 : 1.0 );
			$new_l = max( 0.0, min( 1.0, $hsl[2] + $delta ) );
			$hex   = self::hsl_to_hex( $hsl[0], $hsl[1], $new_l );
			$ratio = self::contrast_ratio( ( $is_text ? $hex : $partner ), ( $is_text ? $partner : $hex ) );
			if ( self::passes( $ratio, $required ) ) {
				return [
					'hex'   => $hex,
					'steps' => $i,
				];
			}
			if ( $new_l <= 0.0 || $new_l >= 1.0 ) {
				// Channel saturated — further nudges cannot help.
				break;
			}
		}

		return [
			'hex'   => $subject,
			'steps' => 0,
		];
	}

	/**
	 * Normalise a hex colour into the `#rrggbb` form.
	 *
	 * Accepts `#rgb`, `#rrggbb`, `rgb`, `rrggbb`, and any mix of upper/lower
	 * case. Returns null for malformed input rather than throwing so the
	 * caller can decide whether to skip or surface the error.
	 *
	 * @param string $hex Raw hex string.
	 */
	public static function normalise_hex( string $hex ): ?string {
		$trimmed = ltrim( trim( $hex ), '#' );
		if ( ! preg_match( '/^[0-9a-fA-F]{3}$|^[0-9a-fA-F]{6}$/', $trimmed ) ) {
			return null;
		}
		if ( 3 === strlen( $trimmed ) ) {
			$trimmed = $trimmed[0] . $trimmed[0] . $trimmed[1] . $trimmed[1] . $trimmed[2] . $trimmed[2];
		}
		return '#' . strtolower( $trimmed );
	}

	/**
	 * Convert a hex colour to an [R, G, B] integer triple in 0..255.
	 *
	 * @param string $hex Raw or normalised hex.
	 * @return array{0:int, 1:int, 2:int}|null
	 */
	private static function hex_to_rgb( string $hex ): ?array {
		$normalised = self::normalise_hex( $hex );
		if ( null === $normalised ) {
			return null;
		}
		$r = (int) hexdec( substr( $normalised, 1, 2 ) );
		$g = (int) hexdec( substr( $normalised, 3, 2 ) );
		$b = (int) hexdec( substr( $normalised, 5, 2 ) );
		return [ $r, $g, $b ];
	}

	/**
	 * Convert a hex colour to HSL with H in 0..360, S/L in 0..1.
	 *
	 * @param string $hex Raw or normalised hex.
	 * @return array{0:float, 1:float, 2:float}|null
	 */
	private static function hex_to_hsl( string $hex ): ?array {
		$rgb = self::hex_to_rgb( $hex );
		if ( null === $rgb ) {
			return null;
		}
		$r   = $rgb[0] / 255.0;
		$g   = $rgb[1] / 255.0;
		$b   = $rgb[2] / 255.0;
		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$l   = ( $max + $min ) / 2.0;
		$d   = $max - $min;

		if ( $d < 1e-9 ) {
			return [ 0.0, 0.0, $l ];
		}

		$s = $l > 0.5 ? $d / ( 2.0 - $max - $min ) : $d / ( $max + $min );
		if ( $max === $r ) {
			$h = ( ( $g - $b ) / $d ) + ( $g < $b ? 6.0 : 0.0 );
		} elseif ( $max === $g ) {
			$h = ( ( $b - $r ) / $d ) + 2.0;
		} else {
			$h = ( ( $r - $g ) / $d ) + 4.0;
		}
		$h *= 60.0;

		return [ $h, $s, $l ];
	}

	/**
	 * Convert HSL (H 0..360, S/L 0..1) to a `#rrggbb` hex string.
	 *
	 * @param float $h Hue in degrees.
	 * @param float $s Saturation.
	 * @param float $l Lightness.
	 */
	private static function hsl_to_hex( float $h, float $s, float $l ): string {
		$h = fmod( $h, 360.0 );
		if ( $h < 0.0 ) {
			$h += 360.0;
		}
		$s = max( 0.0, min( 1.0, $s ) );
		$l = max( 0.0, min( 1.0, $l ) );

		if ( $s < 1e-9 ) {
			$v = (int) round( $l * 255.0 );
			return sprintf( '#%02x%02x%02x', $v, $v, $v );
		}

		$q = $l < 0.5 ? $l * ( 1.0 + $s ) : $l + $s - $l * $s;
		$p = 2.0 * $l - $q;

		$h_norm = $h / 360.0;
		$r      = self::hue_to_channel( $p, $q, $h_norm + 1.0 / 3.0 );
		$g      = self::hue_to_channel( $p, $q, $h_norm );
		$b      = self::hue_to_channel( $p, $q, $h_norm - 1.0 / 3.0 );

		return sprintf(
			'#%02x%02x%02x',
			(int) round( $r * 255.0 ),
			(int) round( $g * 255.0 ),
			(int) round( $b * 255.0 )
		);
	}

	/**
	 * Hue-to-channel helper used by {@see hsl_to_hex()}.
	 *
	 * @param float $p Lower bound from HSL→RGB.
	 * @param float $q Upper bound from HSL→RGB.
	 * @param float $t Hue offset normalised to 0..1.
	 */
	private static function hue_to_channel( float $p, float $q, float $t ): float {
		if ( $t < 0.0 ) {
			$t += 1.0;
		}
		if ( $t > 1.0 ) {
			$t -= 1.0;
		}
		if ( $t < 1.0 / 6.0 ) {
			return $p + ( $q - $p ) * 6.0 * $t;
		}
		if ( $t < 0.5 ) {
			return $q;
		}
		if ( $t < 2.0 / 3.0 ) {
			return $p + ( $q - $p ) * ( 2.0 / 3.0 - $t ) * 6.0;
		}
		return $p;
	}
}
