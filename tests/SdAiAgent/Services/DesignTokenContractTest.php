<?php

declare(strict_types=1);
/**
 * Tests for the versioned design-token contract.
 *
 * @package SdAiAgent\Tests\Services
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Services;

use SdAiAgent\Services\DesignTokenContract;
use WP_UnitTestCase;

/**
 * Proves strict token validation, reference resolution, and governance normalization.
 */
class DesignTokenContractTest extends WP_UnitTestCase {

	/**
	 * A complete contract resolves semantic aliases and derives governance provenance.
	 */
	public function test_normalize_resolves_primitives_semantics_and_governance(): void {
		$normalized = DesignTokenContract::normalize( $this->valid_contract() );

		$this->assertNotWPError( $normalized );
		$this->assertSame( DesignTokenContract::VERSION, $normalized['version'] );
		$this->assertSame( 'brand', $normalized['semantics']['colors']['primary'] );
		$this->assertSame( 'brand', $normalized['semantics']['colors']['accent'] );
		$this->assertSame( 'on-brand', $normalized['semantics']['colors']['on-accent'] );
		$this->assertSame( 'body', $normalized['semantics']['typography']['body']['font_family'] );
		$this->assertSame( 'section', $normalized['semantics']['spacing']['section'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $normalized['governance']['provenance']['input_hash'] );
		$this->assertSame( 'sd-ai-agent/token_set/demo', $normalized['governance']['id'] );
	}

	/**
	 * Unknown contract versions cannot be interpreted as version one.
	 */
	public function test_normalize_rejects_unsupported_versions_with_a_path(): void {
		$contract            = $this->valid_contract();
		$contract['version'] = 2;

		$result = DesignTokenContract::normalize( $contract );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_design_token_unsupported_version', $result->get_error_code() );
		$this->assertSame( 'version', $result->get_error_data()['path'] );
	}

	/**
	 * Duplicate primitive slugs fail before an ambiguous preset can be compiled.
	 */
	public function test_normalize_rejects_duplicate_primitive_slugs_with_a_path(): void {
		$contract                           = $this->valid_contract();
		$contract['primitives']['colors'][] = [
			'slug'  => 'canvas',
			'color' => '#eeeeee',
			'name'  => 'Duplicate Canvas',
		];

		$result = DesignTokenContract::normalize( $contract );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_design_token_duplicate_slug', $result->get_error_code() );
		$this->assertSame( 'primitives.colors.10.slug', $result->get_error_data()['path'] );
	}

	/**
	 * Every public consumer alias requires the full stable colour-role contract.
	 */
	public function test_normalize_rejects_missing_required_semantic_roles_with_a_path(): void {
		$contract = $this->valid_contract();
		unset( $contract['semantics']['colors']['border'] );

		$result = DesignTokenContract::normalize( $contract );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_design_token_missing_required_role', $result->get_error_code() );
		$this->assertSame( 'semantics.colors.border', $result->get_error_data()['path'] );
	}

	/**
	 * Semantic aliases fail closed rather than emitting a cyclic custom-property graph.
	 */
	public function test_normalize_rejects_circular_semantic_references_with_a_path(): void {
		$contract                                          = $this->valid_contract();
		$contract['semantics']['colors']['primary']        = 'semantics.colors.accent';
		$contract['semantics']['colors']['accent']         = 'semantics.colors.primary';

		$result = DesignTokenContract::normalize( $contract );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_design_token_circular_reference', $result->get_error_code() );
		$this->assertSame( 'semantics.colors.primary', $result->get_error_data()['path'] );
	}

	/**
	 * CSS declarations cannot enter a primitive that should only be a bounded size token.
	 */
	public function test_normalize_rejects_malformed_primitive_values_with_a_path(): void {
		$contract                                      = $this->valid_contract();
		$contract['primitives']['font_sizes'][0]['size'] = '1rem; color: red';

		$result = DesignTokenContract::normalize( $contract );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_design_token_invalid_value', $result->get_error_code() );
		$this->assertSame( 'primitives.font_sizes.0.size', $result->get_error_data()['path'] );
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
