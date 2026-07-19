<?php

declare(strict_types=1);
/**
 * Tests for the read-only design-token compiler ability.
 *
 * @package SdAiAgent\Tests\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\CompileDesignTokensAbility;
use WP_UnitTestCase;

/**
 * Verifies the public compiler ability delegates to the strict pure compiler.
 */
class CompileDesignTokensAbilityTest extends WP_UnitTestCase {

	private function ability(): CompileDesignTokensAbility {
		return new CompileDesignTokensAbility( 'sd-ai-agent/compile-design-tokens' );
	}

	public function test_registered_ability_is_public_readonly_and_rest_visible(): void {
		$ability = wp_get_ability( 'sd-ai-agent/compile-design-tokens' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'sd-ai-agent/compile-design-tokens', $ability->get_name() );
		$this->assertTrue( $ability->get_meta()['mcp']['public'] );
		$this->assertTrue( $ability->get_meta()['annotations']['readonly'] );
		$this->assertFalse( $ability->get_meta()['annotations']['destructive'] );
		$this->assertTrue( $ability->get_meta()['annotations']['idempotent'] );
		$this->assertTrue( $ability->get_meta()['show_in_rest'] );
		$this->assertSame( [ 'contract' ], $ability->get_input_schema()['required'] );
	}

	public function test_input_schema_documents_the_complete_bounded_contract(): void {
		$schema   = $this->ability()->get_input_schema();
		$contract = $schema['properties']['contract'];

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertFalse( $contract['additionalProperties'] );
		$this->assertSame( [ 'version', 'governance', 'primitives', 'semantics', 'style_variation' ], $contract['required'] );
		$this->assertSame( [ 1 ], $contract['properties']['version']['enum'] );
		$this->assertSame( 64, $contract['properties']['primitives']['properties']['colors']['maxItems'] );
		$this->assertFalse( $contract['properties']['primitives']['properties']['colors']['items']['additionalProperties'] );
		$this->assertSame(
			[ 'background', 'foreground', 'surface', 'primary', 'on-primary', 'accent', 'on-accent', 'border' ],
			$contract['properties']['semantics']['properties']['colors']['required']
		);
		$this->assertSame( 64, $contract['properties']['semantics']['properties']['colors']['maxProperties'] );
		$this->assertFalse( $contract['properties']['style_variation']['additionalProperties'] );
	}

	public function test_run_compiles_a_complete_contract_without_creating_global_styles_posts(): void {
		$before_global_styles = get_posts(
			[
				'post_type'      => 'wp_global_styles',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);
		$result = $this->ability()->run( [ 'contract' => $this->valid_contract() ] );
		$after_global_styles = get_posts(
			[
				'post_type'      => 'wp_global_styles',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 3, $result['theme_json']['version'] );
		$this->assertSame( $result['theme_json']['settings'], $result['global_styles']['settings'] );
		$this->assertSame( $result['theme_json']['styles'], $result['global_styles']['styles'] );
		$this->assertSame( 'base', $result['style_variation']['slug'] );
		$this->assertArrayHasKey( 'artifacts', $result['artifact_manifest'] );
		$this->assertSame( $before_global_styles, $after_global_styles );
	}

	public function test_run_propagates_strict_contract_errors(): void {
		$result = $this->ability()->run( [ 'contract' => [ 'version' => 99 ] ] );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_design_token_unsupported_version', $result->get_error_code() );
		$this->assertSame( 'version', $result->get_error_data()['path'] );
	}

	/**
	 * Return a small but complete valid contract for the public ability surface.
	 *
	 * @return array<string,mixed>
	 */
	private function valid_contract(): array {
		return [
			'version'    => 1,
			'governance' => [
				'id'            => 'sd-ai-agent/token_set/ability-test',
				'version'       => '1.0.0',
				'maturity'      => 'candidate',
				'provenance'    => [
					'generator_version' => '1.19.0',
					'source_type'       => 'agent',
					'source_reference'  => 'ability-test',
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
			],
			'style_variation' => [
				'slug'   => 'base',
				'title'  => 'Base',
				'colors' => [
					'background' => 'colors.canvas',
					'foreground' => 'colors.ink',
					'surface'    => 'colors.surface',
					'primary'    => 'colors.brand',
					'on-primary' => 'colors.on-brand',
					'accent'     => 'semantics.colors.primary',
					'on-accent'  => 'semantics.colors.on-primary',
					'border'     => 'colors.border',
				],
			],
		];
	}
}
