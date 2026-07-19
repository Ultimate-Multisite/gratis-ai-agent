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
	 * Object-shaped contract fields cannot retain non-string keys after normalization.
	 */
	public function test_normalize_rejects_non_string_object_keys(): void {
		$contract = $this->valid_contract();
		$contract['semantics']['colors'][2] = 'colors.canvas';

		$result = DesignTokenContract::normalize( $contract );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_design_token_invalid_value', $result->get_error_code() );
		$this->assertSame( 'semantics.colors', $result->get_error_data()['path'] );
	}

	/**
	 * Unknown fields fail closed instead of being silently discarded.
	 */
	public function test_normalize_rejects_unknown_fields_with_exact_paths(): void {
		$contract                  = $this->valid_contract();
		$contract['raw_css']       = 'body { display: none; }';
		$result                    = DesignTokenContract::normalize( $contract );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_design_token_unexpected_value', $result->get_error_code() );
		$this->assertSame( 'raw_css', $result->get_error_data()['path'] );

		$contract                                      = $this->valid_contract();
		$contract['primitives']['colors'][0]['selector'] = ':root';
		$result                                        = DesignTokenContract::normalize( $contract );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_design_token_unexpected_value', $result->get_error_code() );
		$this->assertSame( 'primitives.colors.0.selector', $result->get_error_data()['path'] );

		$contract                                                    = $this->valid_contract();
		$contract['semantics']['typography']['body']['font_weight'] = '700';
		$result                                                      = DesignTokenContract::normalize( $contract );

		$this->assertWPError( $result );
		$this->assertSame( 'semantics.typography.body.font_weight', $result->get_error_data()['path'] );

		$contract                                           = $this->valid_contract();
		$contract['governance']['provenance']['input_hash'] = str_repeat( 'a', 64 );
		$result                                             = DesignTokenContract::normalize( $contract );

		$this->assertWPError( $result );
		$this->assertSame( 'governance.provenance.input_hash', $result->get_error_data()['path'] );
	}

	/**
	 * REST-visible compilation has deterministic collection and graph bounds.
	 */
	public function test_normalize_rejects_oversized_collections_and_alias_graphs(): void {
		$contract = $this->valid_contract();
		while ( count( $contract['primitives']['colors'] ) <= DesignTokenContract::MAX_PRIMITIVES_PER_COLLECTION ) {
			$index = count( $contract['primitives']['colors'] );
			$contract['primitives']['colors'][] = [
				'slug'  => 'extra-' . $index,
				'color' => '#123456',
				'name'  => 'Extra ' . $index,
			];
		}

		$result = DesignTokenContract::normalize( $contract );
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_design_token_too_many_primitives', $result->get_error_code() );
		$this->assertSame( 'primitives.colors', $result->get_error_data()['path'] );

		$contract = $this->valid_contract();
		while ( count( $contract['semantics']['colors'] ) <= DesignTokenContract::MAX_SEMANTIC_ROLES ) {
			$index = count( $contract['semantics']['colors'] );
			$contract['semantics']['colors'][ 'extra-role-' . $index ] = 'colors.canvas';
		}

		$result = DesignTokenContract::normalize( $contract );
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_design_token_too_many_roles', $result->get_error_code() );
		$this->assertSame( 'semantics.colors', $result->get_error_data()['path'] );

		$contract = $this->valid_contract();
		for ( $index = 0; $index <= DesignTokenContract::MAX_REFERENCE_DEPTH; ++$index ) {
			$contract['semantics']['colors'][ 'alias-' . $index ] = $index === DesignTokenContract::MAX_REFERENCE_DEPTH
				? 'colors.canvas'
				: 'semantics.colors.alias-' . ( $index + 1 );
		}
		$contract['semantics']['colors']['primary'] = 'semantics.colors.alias-0';

		$result = DesignTokenContract::normalize( $contract );
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_design_token_reference_depth_exceeded', $result->get_error_code() );
		$this->assertStringStartsWith( 'semantics.colors.', $result->get_error_data()['path'] );
	}

	/**
	 * Malformed font, size, and shadow primitives never reach theme.json output.
	 */
	public function test_normalize_rejects_malformed_css_primitive_grammars(): void {
		$cases = [
			[ 'font_families', 0, 'fontFamily', 'system-ui, sans-serif)' ],
			[ 'font_sizes', 0, 'size', 'calc(1rem))' ],
			[ 'font_sizes', 0, 'size', 'calc(1rem 2rem)' ],
			[ 'font_sizes', 0, 'size', 'clamp(1rem)' ],
			[ 'radii', 0, 'size', '-0.5rem' ],
			[ 'shadows', 0, 'shadow', '0 2px 8px rgb(0 0 0 / 0.12))' ],
			[ 'shadows', 0, 'shadow', '0 2px 8px rgb(foo)' ],
			[ 'shadows', 0, 'shadow', '0 2px 8px rgb(999 999 999)' ],
			[ 'shadows', 0, 'shadow', '0 2px 8px rgb(0 / 0 / 0)' ],
			[ 'shadows', 0, 'shadow', '0 2px 8px hsl(20 30 40)' ],
			[ 'shadows', 0, 'shadow', '0 2px 8px rgba(0 0 0 / 2)' ],
		];

		foreach ( $cases as [ $collection, $index, $key, $value ] ) {
			$contract                                              = $this->valid_contract();
			$contract['primitives'][ $collection ][ $index ][ $key ] = $value;
			$result                                                = DesignTokenContract::normalize( $contract );

			$this->assertWPError( $result );
			$this->assertSame( 'sd_ai_agent_design_token_invalid_value', $result->get_error_code() );
			$this->assertSame( 'primitives.' . $collection . '.' . $index . '.' . $key, $result->get_error_data()['path'] );
		}
	}

	/**
	 * Supported responsive sizes and multiple shadows remain available.
	 */
	public function test_normalize_accepts_well_formed_css_primitive_grammars(): void {
		$contract                                              = $this->valid_contract();
		$contract['primitives']['font_sizes'][1]['size']       = 'clamp(2rem, 4vw, 3.5rem)';
		$contract['primitives']['shadows'][0]['shadow']        = '0 2px 8px rgb(0 0 0 / 0.12), inset 0 0 1px hsl(20 30% 40% / 50%)';

		$result = DesignTokenContract::normalize( $contract );

		$this->assertNotWPError( $result );
		$this->assertSame( 'clamp(2rem, 4vw, 3.5rem)', $result['primitives']['font_sizes']['heading']['size'] );
		$this->assertSame( '0 2px 8px rgb(0 0 0 / 0.12), inset 0 0 1px hsl(20 30% 40% / 50%)', $result['primitives']['shadows']['control']['shadow'] );
	}

	/**
	 * Shadows are optional primitive and semantic aliases rather than a hidden requirement.
	 */
	public function test_normalize_accepts_contracts_without_shadow_tokens(): void {
		$contract = $this->valid_contract();
		unset( $contract['primitives']['shadows'], $contract['semantics']['shadows'] );

		$result = DesignTokenContract::normalize( $contract );

		$this->assertNotWPError( $result );
		$this->assertSame( [], $result['primitives']['shadows'] );
		$this->assertSame( [], $result['semantics']['shadows'] );
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
