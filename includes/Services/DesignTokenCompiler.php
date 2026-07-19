<?php

declare(strict_types=1);
/**
 * Pure compiler from the design-token contract to WordPress theme artifacts.
 *
 * @package SdAiAgent\Services
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Services;

use SdAiAgent\DesignSystem\ArtifactManifest;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compiles one normalized design direction without reading or mutating WordPress state.
 */
final class DesignTokenCompiler {

	/**
	 * Compile a versioned contract into complete theme.json and Global Styles payloads.
	 *
	 * @param array<string,mixed> $contract Raw design-token contract.
	 * @return array<string,mixed>|WP_Error Compiled artifacts or an error with no partial output.
	 */
	public function compile( array $contract ): array|WP_Error {
		$contract = DesignTokenContract::normalize( $contract );
		if ( is_wp_error( $contract ) ) {
			return $contract;
		}

		$palette = self::semantic_palette( $contract['semantics']['colors'], $contract['primitives']['colors'] );
		$checked = ( new PaletteValidator( $palette ) )->check();
		if ( ! $checked['passed'] ) {
			return new WP_Error(
				'sd_ai_agent_design_token_palette_invalid',
				__( 'The semantic design-token palette does not meet the shared contrast contract.', 'superdav-ai-agent' ),
				[
					'path'       => 'semantics.colors',
					'validation' => $checked,
				]
			);
		}

		$variation_palette = self::semantic_palette( $contract['style_variation']['colors'], $contract['primitives']['colors'] );
		$variation_checked = ( new PaletteValidator( $variation_palette ) )->check();
		if ( ! $variation_checked['passed'] ) {
			return new WP_Error(
				'sd_ai_agent_design_token_variation_palette_invalid',
				__( 'The style-variation semantic palette does not meet the shared contrast contract.', 'superdav-ai-agent' ),
				[
					'path'       => 'style_variation.colors',
					'validation' => $variation_checked,
				]
			);
		}

		$settings   = self::build_settings( $contract );
		$styles     = self::build_styles();
		$theme_json = [
			'$schema'  => 'https://schemas.wp.org/trunk/theme.json',
			'version'  => 3,
			'settings' => $settings,
			'styles'   => $styles,
		];

		$variation_custom                                   = $settings['custom'];
		$variation_custom['sdAiAgent']['semantic']['color'] = self::semantic_color_custom_values( $contract['style_variation']['colors'] );
		$style_variation                                    = [
			'$schema'  => 'https://schemas.wp.org/trunk/theme.json',
			'version'  => 3,
			'title'    => $contract['style_variation']['title'],
			'slug'     => $contract['style_variation']['slug'],
			'settings' => [
				'custom' => $variation_custom,
			],
			'styles'   => $styles,
		];
		$global_styles = [
			'settings' => $settings,
			'styles'   => $styles,
		];

		$artifact_manifest = self::build_artifact_manifest( $contract['governance'], $theme_json, $global_styles, $style_variation );
		if ( is_wp_error( $artifact_manifest ) ) {
			return $artifact_manifest;
		}

		return [
			'theme_json'       => $theme_json,
			'global_styles'    => $global_styles,
			'palette'          => $palette,
			'style_variation'  => $style_variation,
			'artifact_manifest' => $artifact_manifest,
		];
	}

	/**
	 * Build WordPress-native presets and custom semantic aliases.
	 *
	 * @param array<string,mixed> $contract Normalized contract.
	 * @return array<string,mixed> Theme settings.
	 */
	private static function build_settings( array $contract ): array {
		$primitives = $contract['primitives'];

		$palette = [];
		foreach ( $primitives['colors'] as $token ) {
			$palette[] = [
				'slug'  => $token['slug'],
				'color' => $token['color'],
				'name'  => $token['name'],
			];
		}

		$font_families = [];
		foreach ( $primitives['font_families'] as $token ) {
			$font_families[] = [
				'slug'       => $token['slug'],
				'fontFamily' => $token['fontFamily'],
				'name'       => $token['name'],
			];
		}

		$font_sizes = [];
		foreach ( $primitives['font_sizes'] as $token ) {
			$font_sizes[] = [
				'slug' => $token['slug'],
				'size' => $token['size'],
				'name' => $token['name'],
			];
		}

		$spacing_sizes = [];
		foreach ( $primitives['spacing'] as $token ) {
			$spacing_sizes[] = [
				'slug' => $token['slug'],
				'size' => $token['size'],
				'name' => $token['name'],
			];
		}

		return [
			'appearanceTools' => true,
			'color'           => [
				'palette'         => $palette,
				'defaultPalette'  => false,
				'defaultGradients' => false,
			],
			'typography'      => [
				'fluid'        => true,
				'fontFamilies' => $font_families,
				'fontSizes'    => $font_sizes,
			],
			'spacing'         => [
				'spacingSizes' => $spacing_sizes,
				'units'        => [ 'px', 'em', 'rem', '%', 'vw', 'vh' ],
			],
			'custom'          => self::semantic_custom_values( $contract ),
		];
	}

	/**
	 * Compile root, element, and block styles only through stable semantic aliases.
	 *
	 * @return array<string,mixed> Theme styles.
	 */
	private static function build_styles(): array {
		$color = static fn( string $role ): string => self::custom_var( [ 'color', $role ] );
		$type  = static fn( string $role, string $property ): string => self::custom_var( [ 'typography', $role, $property ] );
		$space = static fn( string $role ): string => self::custom_var( [ 'spacing', $role ] );
		$radius = self::custom_var( [ 'radius', 'control' ] );
		$shadow = self::custom_var( [ 'shadow', 'control' ] );

		$button = [
			'color'      => [
				'background' => $color( 'accent' ),
				'text'       => $color( 'on-accent' ),
			],
			'border'     => [
				'color'  => $color( 'border' ),
				'radius' => $radius,
			],
			'typography' => [
				'fontFamily' => $type( 'body', 'font-family' ),
			],
			'shadow'     => $shadow,
		];

		return [
			'color'      => [
				'background' => $color( 'background' ),
				'text'       => $color( 'foreground' ),
			],
			'typography' => [
				'fontFamily' => $type( 'body', 'font-family' ),
				'fontSize'   => $type( 'body', 'font-size' ),
			],
			'spacing'    => [
				'blockGap' => $space( 'content' ),
			],
			'elements'   => [
				'heading' => [
					'color'      => [ 'text' => $color( 'foreground' ) ],
					'typography' => [
						'fontFamily' => $type( 'heading', 'font-family' ),
						'fontSize'   => $type( 'heading', 'font-size' ),
					],
				],
				'link'    => [
					'color' => [ 'text' => $color( 'accent' ) ],
				],
				'button'  => $button,
			],
			'blocks'     => [
				'core/button'    => $button,
				'core/quote'     => [
					'border' => [
						'color' => $color( 'border' ),
					],
				],
				'core/separator' => [
					'color' => [
						'background' => $color( 'border' ),
					],
				],
			],
		];
	}

	/**
	 * Compile settings.custom aliases from resolved semantic references.
	 *
	 * @param array<string,mixed> $contract Normalized contract.
	 * @return array<string,mixed> Custom settings tree.
	 */
	private static function semantic_custom_values( array $contract ): array {
		$semantics  = $contract['semantics'];
		$primitives = $contract['primitives'];

		$typography = [];
		foreach ( $semantics['typography'] as $role => $references ) {
			$typography[ $role ] = [
				'fontFamily' => self::preset_var( 'font-family', $references['font_family'] ),
				'fontSize'   => self::preset_var( 'font-size', $references['font_size'] ),
			];
		}

		$spacing = [];
		foreach ( $semantics['spacing'] as $role => $slug ) {
			$spacing[ $role ] = self::preset_var( 'spacing', $slug );
		}

		$radius = [];
		foreach ( $semantics['radius'] as $role => $slug ) {
			$radius[ $role ] = $primitives['radii'][ $slug ]['size'];
		}

		$shadow = [];
		foreach ( $semantics['shadows'] as $role => $slug ) {
			$shadow[ $role ] = $primitives['shadows'][ $slug ]['shadow'];
		}
		if ( [] === $shadow ) {
			$shadow['control'] = 'none';
		}

		return [
			'sdAiAgent' => [
				'semantic' => [
					'color'      => self::semantic_color_custom_values( $semantics['colors'] ),
					'typography' => $typography,
					'spacing'    => $spacing,
					'radius'     => $radius,
					'shadow'     => $shadow,
				],
			],
		];
	}

	/**
	 * Build semantic colour aliases that point at native colour presets.
	 *
	 * @param array<string,string> $colors Resolved semantic role-to-primitive map.
	 * @return array<string,string> Role-to-CSS-variable map.
	 */
	private static function semantic_color_custom_values( array $colors ): array {
		$custom = [];
		foreach ( $colors as $role => $slug ) {
			$custom[ $role ] = self::preset_var( 'color', $slug );
		}

		return $custom;
	}

	/**
	 * Convert semantic colours to the #2246 palette validator shape.
	 *
	 * @param array<string,string>               $semantics Resolved role-to-primitive map.
	 * @param array<string,array<string,string>> $primitives Normalized colour primitive map.
	 * @return list<array{slug:string,color:string,name:string}> Semantic palette.
	 */
	private static function semantic_palette( array $semantics, array $primitives ): array {
		$palette = [];
		foreach ( DesignTokenContract::REQUIRED_COLOR_ROLES as $role ) {
			$slug      = $semantics[ $role ];
			$primitive = $primitives[ $slug ];
			$palette[] = [
				'slug'  => $role,
				'color' => $primitive['color'],
				'name'  => ucwords( str_replace( '-', ' ', $role ) ),
			];
		}

		return $palette;
	}

	/**
	 * Build valid #2248 token-set and style-variation artifact records.
	 *
	 * @param array<string,mixed> $governance      Normalized governance metadata.
	 * @param array<string,mixed> $theme_json      Complete theme.json output.
	 * @param array<string,mixed> $global_styles   Global Styles partial output.
	 * @param array<string,mixed> $style_variation Style variation partial output.
	 * @return array<string,mixed>|WP_Error Valid manifest fragment or an error.
	 */
	private static function build_artifact_manifest( array $governance, array $theme_json, array $global_styles, array $style_variation ): array|WP_Error {
		$theme_content = ArtifactManifest::canonical_json( $theme_json );
		$global_content = ArtifactManifest::canonical_json(
			[
				'version'                     => 3,
				'isGlobalStylesUserThemeJSON' => true,
				'settings'                    => $global_styles['settings'],
				'styles'                      => $global_styles['styles'],
			]
		);
		$variation_content = ArtifactManifest::canonical_json( $style_variation );
		if ( is_wp_error( $theme_content ) || is_wp_error( $global_content ) || is_wp_error( $variation_content ) ) {
			return new WP_Error(
				'sd_ai_agent_design_token_artifact_encoding_failed',
				__( 'The compiled design-token artifacts could not be canonically encoded.', 'superdav-ai-agent' ),
				[ 'path' => 'governance' ]
			);
		}

		$token_set = self::create_artifact(
			$governance,
			'token_set',
			$governance['id'],
			[
				'files'   => [
					[
						'path'    => 'theme.json',
						'content' => $theme_content,
					],
				],
				'records' => [
					[
						'id'           => 'global-styles',
						'post_type'    => 'wp_global_styles',
						'post_title'   => 'Generated Design Tokens',
						'post_excerpt' => '',
						'post_name'    => 'sd-ai-agent-design-tokens',
						'post_status'  => 'publish',
						'post_content' => $global_content,
					],
				],
			]
		);
		if ( is_wp_error( $token_set ) ) {
			return $token_set;
		}

		$variation_id = self::variation_artifact_id( $governance['id'], $style_variation['slug'] );
		$variation    = self::create_artifact(
			$governance,
			'style_variation',
			$variation_id,
			[
				'files'   => [
					[
						'path'    => 'styles/' . $style_variation['slug'] . '.json',
						'content' => $variation_content,
					],
				],
				'records' => [],
			]
		);
		if ( is_wp_error( $variation ) ) {
			return $variation;
		}

		$manifest = ArtifactManifest::normalize(
			[
				'schema_version' => ArtifactManifest::SCHEMA_VERSION,
				'artifacts'      => [ $token_set, $variation ],
			]
		);

		return is_wp_error( $manifest )
			? new WP_Error(
				'sd_ai_agent_design_token_artifact_invalid',
				$manifest->get_error_message(),
				[
					'path'  => 'governance',
					'cause' => $manifest->get_error_code(),
				]
			)
			: $manifest;
	}

	/**
	 * Create one governed artifact without any mutation.
	 *
	 * @param array<string,mixed> $governance Normalized governance metadata.
	 * @param string              $kind       Artifact kind.
	 * @param string              $id         Artifact identifier.
	 * @param array<string,mixed> $payload    Artifact payload.
	 * @return array<string,mixed>|WP_Error Valid artifact or an error.
	 */
	private static function create_artifact( array $governance, string $kind, string $id, array $payload ): array|WP_Error {
		$artifact = ArtifactManifest::create_artifact(
			[
				'id'            => $id,
				'kind'          => $kind,
				'version'       => $governance['version'],
				'maturity'      => $governance['maturity'],
				'provenance'    => $governance['provenance'],
				'compatibility' => $governance['compatibility'],
				'deprecation'   => $governance['deprecation'],
				'payload'       => $payload,
			]
		);

		return is_wp_error( $artifact )
			? new WP_Error(
				'sd_ai_agent_design_token_artifact_invalid',
				$artifact->get_error_message(),
				[
					'path'  => 'governance',
					'cause' => $artifact->get_error_code(),
				]
			)
			: $artifact;
	}

	/**
	 * Derive a stable style-variation ID from the governed token-set ID.
	 */
	private static function variation_artifact_id( string $token_set_id, string $variation_slug ): string {
		$name = substr( $token_set_id, strlen( 'sd-ai-agent/token_set/' ) );

		return 'sd-ai-agent/style_variation/' . $name . '-' . $variation_slug;
	}

	/**
	 * Return a native WordPress preset CSS variable reference.
	 */
	private static function preset_var( string $kind, string $slug ): string {
		return 'var(--wp--preset--' . $kind . '--' . $slug . ')';
	}

	/**
	 * Return a stable custom semantic CSS variable reference.
	 *
	 * @param list<string> $segments Semantic variable path.
	 */
	private static function custom_var( array $segments ): string {
		return 'var(--wp--custom--sd-ai-agent--semantic--' . implode( '--', $segments ) . ')';
	}
}
