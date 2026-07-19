<?php

declare(strict_types=1);
/**
 * Tests for pure design-token compilation.
 *
 * @package SdAiAgent\Tests\Services
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Services;

use SdAiAgent\DesignSystem\ArtifactManifest;
use SdAiAgent\Services\DesignTokenCompiler;
use SdAiAgent\Services\PaletteValidator;
use WP_Theme_JSON;
use WP_UnitTestCase;

/**
 * Proves WordPress-compatible, deterministic token compilation with governed output.
 */
class DesignTokenCompilerTest extends WP_UnitTestCase {

	/**
	 * A valid contract emits all WordPress payloads and survives core theme.json processing.
	 */
	public function test_compile_emits_v3_theme_global_styles_variation_and_governance(): void {
		$compiled = ( new DesignTokenCompiler() )->compile( $this->valid_contract() );

		$this->assertNotWPError( $compiled );
		$this->assertSame( 3, $compiled['theme_json']['version'] );
		$this->assertSame( 'https://schemas.wp.org/trunk/theme.json', $compiled['theme_json']['$schema'] );
		$this->assertCount( 10, $compiled['theme_json']['settings']['color']['palette'] );
		$this->assertCount( 2, $compiled['theme_json']['settings']['typography']['fontFamilies'] );
		$this->assertCount( 2, $compiled['theme_json']['settings']['typography']['fontSizes'] );
		$this->assertCount( 2, $compiled['theme_json']['settings']['spacing']['spacingSizes'] );
		$this->assertSame(
			'var(--wp--preset--color--canvas)',
			$compiled['theme_json']['settings']['custom']['sdAiAgent']['semantic']['color']['background']
		);
		$this->assertSame( $compiled['theme_json']['settings'], $compiled['global_styles']['settings'] );
		$this->assertSame( $compiled['theme_json']['styles'], $compiled['global_styles']['styles'] );
		$this->assertSame( 'night', $compiled['style_variation']['slug'] );
		$this->assertNotWPError( ArtifactManifest::normalize( $compiled['artifact_manifest'] ) );
		foreach ( $compiled['artifact_manifest']['artifacts'] as $artifact ) {
			$this->assertSame( [], $artifact['payload']['records'], 'Compiled artifacts must not create a second wp_global_styles record.' );
		}

		$theme_json = new WP_Theme_JSON( $compiled['theme_json'], 'theme' );
		$stylesheet = $theme_json->get_stylesheet();
		$this->assertStringContainsString( '--wp--custom--sd-ai-agent--semantic--color--background', $stylesheet );
		$this->assertStringContainsString( '--wp--preset--color--canvas', $stylesheet );
	}

	/**
	 * Identical inputs must produce byte-identical ordered outputs.
	 */
	public function test_compile_is_deterministic(): void {
		$compiler = new DesignTokenCompiler();
		$first    = $compiler->compile( $this->valid_contract() );
		$second   = $compiler->compile( $this->valid_contract() );

		$this->assertNotWPError( $first );
		$this->assertNotWPError( $second );
		$this->assertSame( $first, $second );
	}

	/**
	 * Variation settings remap aliases while every consumer continues using the same references.
	 */
	public function test_compile_variation_remaps_semantic_values_without_rewriting_consumers(): void {
		$compiled = ( new DesignTokenCompiler() )->compile( $this->valid_contract() );

		$this->assertNotWPError( $compiled );
		$this->assertSame(
			'var(--wp--preset--color--canvas)',
			$compiled['theme_json']['settings']['custom']['sdAiAgent']['semantic']['color']['background']
		);
		$this->assertSame(
			'var(--wp--preset--color--night-canvas)',
			$compiled['style_variation']['settings']['custom']['sdAiAgent']['semantic']['color']['background']
		);
		$this->assertSame( $compiled['theme_json']['styles'], $compiled['style_variation']['styles'] );
		$this->assertSame(
			'var(--wp--custom--sd-ai-agent--semantic--color--background)',
			$compiled['theme_json']['styles']['color']['background']
		);
	}

	/**
	 * The compiler delegates WCAG validation to PaletteValidator rather than duplicating its math.
	 */
	public function test_compile_returns_the_validator_compatible_semantic_palette(): void {
		$compiled = ( new DesignTokenCompiler() )->compile( $this->valid_contract() );

		$this->assertNotWPError( $compiled );
		$validation = ( new PaletteValidator( $compiled['palette'] ) )->check();
		$this->assertTrue( $validation['passed'] );
		$this->assertSame( 4, $validation['pairs_checked'] );
	}

	/**
	 * A contrast failure returns one WP_Error rather than partial artifact data.
	 */
	public function test_compile_returns_no_partial_output_for_an_invalid_palette(): void {
		$contract                                   = $this->valid_contract();
		$contract['semantics']['colors']['accent'] = 'colors.canvas';

		$result = ( new DesignTokenCompiler() )->compile( $contract );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_design_token_palette_invalid', $result->get_error_code() );
		$this->assertSame( 'semantics.colors', $result->get_error_data()['path'] );
	}

	/**
	 * Return a valid complete contract fixture.
	 *
	 * @return array<string,mixed>
	 */
	private function valid_contract(): array {
		return [
			'version'    => 1,
			'governance' => [
				'id'            => 'sd-ai-agent/token_set/demo',
				'version'       => '1.0.0',
				'maturity'      => 'candidate',
				'provenance'    => [
					'generator_version' => '1.19.0',
					'source_type'       => 'agent',
					'source_reference'  => 'test-suite',
					'generated_at'      => '2026-07-19T00:00:00Z',
				],
				'compatibility' => [
					'wordpress'        => [ 'min' => '7.0', 'max' => null ],
					'theme_json'       => [ 'min' => 3, 'max' => 3 ],
					'required_blocks'   => [],
					'required_features' => [],
					'theme_constraints' => [],
				],
			],
			'primitives' => [
				'colors'        => [
					[ 'slug' => 'canvas', 'color' => '#ffffff', 'name' => 'Canvas' ],
					[ 'slug' => 'ink', 'color' => '#1a1a1a', 'name' => 'Ink' ],
					[ 'slug' => 'surface', 'color' => '#f5f5f5', 'name' => 'Surface' ],
					[ 'slug' => 'brand', 'color' => '#005fcc', 'name' => 'Brand' ],
					[ 'slug' => 'on-brand', 'color' => '#ffffff', 'name' => 'On Brand' ],
					[ 'slug' => 'border', 'color' => '#d0d7de', 'name' => 'Border' ],
					[ 'slug' => 'night-canvas', 'color' => '#101820', 'name' => 'Night Canvas' ],
					[ 'slug' => 'night-ink', 'color' => '#f5f7fa', 'name' => 'Night Ink' ],
					[ 'slug' => 'night-surface', 'color' => '#1c2733', 'name' => 'Night Surface' ],
					[ 'slug' => 'night-brand', 'color' => '#7ab8ff', 'name' => 'Night Brand' ],
				],
				'font_families' => [
					[ 'slug' => 'body', 'fontFamily' => 'system-ui, sans-serif', 'name' => 'Body' ],
					[ 'slug' => 'heading', 'fontFamily' => 'Georgia, serif', 'name' => 'Heading' ],
				],
				'font_sizes'    => [
					[ 'slug' => 'body', 'size' => '1rem', 'name' => 'Body' ],
					[ 'slug' => 'heading', 'size' => '2.5rem', 'name' => 'Heading' ],
				],
				'spacing'       => [
					[ 'slug' => 'content', 'size' => '1rem', 'name' => 'Content' ],
					[ 'slug' => 'section', 'size' => '3rem', 'name' => 'Section' ],
				],
				'radii'         => [
					[ 'slug' => 'control', 'size' => '0.5rem', 'name' => 'Control' ],
				],
				'shadows'       => [
					[ 'slug' => 'control', 'shadow' => '0 2px 8px rgb(0 0 0 / 0.12)', 'name' => 'Control Shadow' ],
				],
			],
			'semantics'  => [
				'colors'     => [
					'background' => 'colors.canvas',
					'foreground' => 'colors.ink',
					'surface'    => 'colors.surface',
					'primary'    => 'colors.brand',
					'on-primary' => 'colors.on-brand',
					'accent'     => 'semantics.colors.primary',
					'on-accent'  => 'semantics.colors.on-primary',
					'border'     => 'colors.border',
				],
				'typography' => [
					'body'    => [
						'font_family' => 'font_families.body',
						'font_size'   => 'font_sizes.body',
					],
					'heading' => [
						'font_family' => 'font_families.heading',
						'font_size'   => 'font_sizes.heading',
					],
				],
				'spacing'    => [
					'content' => 'spacing.content',
					'section' => 'spacing.section',
				],
				'radius'     => [
					'control' => 'radii.control',
				],
				'shadows'    => [
					'control' => 'shadows.control',
				],
			],
			'style_variation' => [
				'slug'   => 'night',
				'title'  => 'Night',
				'colors' => [
					'background' => 'colors.night-canvas',
					'foreground' => 'colors.night-ink',
					'surface'    => 'colors.night-surface',
					'primary'    => 'colors.night-brand',
					'on-primary' => 'colors.night-canvas',
					'accent'     => 'semantics.colors.primary',
					'on-accent'  => 'semantics.colors.on-primary',
					'border'     => 'colors.night-ink',
				],
			],
		];
	}
}
