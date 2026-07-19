<?php

declare(strict_types=1);
/**
 * Strict, versioned design-token contract normalization.
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
 * Validates primitive tokens and resolves the semantic aliases consumed by the compiler.
 *
 * The contract deliberately keeps input values declarative. It accepts named primitive
 * collections, semantic references to those primitives, and a style-variation colour
 * remap; it never accepts selectors, declarations, paths, or arbitrary CSS payloads.
 *
 * @phpstan-type PrimitiveToken array<string,string>
 * @phpstan-type PrimitiveCollections array{
 *     colors:array<string,PrimitiveToken>,
 *     font_families:array<string,PrimitiveToken>,
 *     font_sizes:array<string,PrimitiveToken>,
 *     spacing:array<string,PrimitiveToken>,
 *     radii:array<string,PrimitiveToken>,
 *     shadows:array<string,PrimitiveToken>
 * }
 * @phpstan-type TypographyRoles array<string,array{font_family:string,font_size:string}>
 * @phpstan-type Semantics array{
 *     colors:array<string,string>,
 *     typography:TypographyRoles,
 *     spacing:array<string,string>,
 *     radius:array<string,string>,
 *     shadows:array<string,string>
 * }
 * @phpstan-type StyleVariation array{slug:string,title:string,colors:array<string,string>}
 * @phpstan-type Governance array{
 *     id:string,
 *     version:string,
 *     maturity:string,
 *     provenance:array<string,mixed>,
 *     compatibility:array<string,mixed>,
 *     deprecation:array<string,mixed>|null
 * }
 * @phpstan-type NormalizedContract array{
 *     version:int,
 *     primitives:PrimitiveCollections,
 *     semantics:Semantics,
 *     style_variation:StyleVariation,
 *     governance:Governance
 * }
 */
final class DesignTokenContract {

	/**
	 * Current supported contract version.
	 */
	public const VERSION = 1;

	/**
	 * Stable colour roles generated themes always expose.
	 *
	 * @var list<string>
	 */
	public const REQUIRED_COLOR_ROLES = [
		'background',
		'foreground',
		'surface',
		'primary',
		'on-primary',
		'accent',
		'on-accent',
		'border',
	];

	/**
	 * Stable typography roles generated themes always expose.
	 *
	 * @var list<string>
	 */
	public const REQUIRED_TYPOGRAPHY_ROLES = [ 'body', 'heading' ];

	/**
	 * Stable spacing roles generated themes always expose.
	 *
	 * @var list<string>
	 */
	public const REQUIRED_SPACING_ROLES = [ 'content', 'section' ];

	/**
	 * Stable radius roles generated themes always expose.
	 *
	 * @var list<string>
	 */
	public const REQUIRED_RADIUS_ROLES = [ 'control' ];

	/**
	 * Resource limits for REST-visible contract compilation.
	 */
	public const MAX_PRIMITIVES_PER_COLLECTION = 64;
	public const MAX_TOTAL_PRIMITIVES          = 192;
	public const MAX_SEMANTIC_ROLES            = 64;
	public const MAX_REFERENCE_DEPTH           = 16;

	/**
	 * Return the complete public JSON Schema for a version-1 contract.
	 *
	 * @return array<string,mixed>
	 */
	public static function schema(): array {
		$slug  = [
			'type'        => 'string',
			'pattern'     => '^[a-z0-9]+(?:-[a-z0-9]+)*$',
			'description' => 'Lowercase CSS-safe identifier.',
		];
		$label = [
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 120,
		];

		$typography_role = [
			'type'                 => 'object',
			'properties'           => [
				'font_family' => [
					'type'    => 'string',
					'pattern' => '^font_families\.[a-z0-9]+(?:-[a-z0-9]+)*$',
				],
				'font_size'   => [
					'type'    => 'string',
					'pattern' => '^font_sizes\.[a-z0-9]+(?:-[a-z0-9]+)*$',
				],
			],
			'required'             => [ 'font_family', 'font_size' ],
			'additionalProperties' => false,
		];

		return [
			'type'                 => 'object',
			'properties'           => [
				'version'         => [
					'type'        => 'integer',
					'enum'        => [ self::VERSION ],
					'description' => 'Design-token contract schema version.',
				],
				'governance'      => [
					'type'                 => 'object',
					'properties'           => [
						'id'            => [
							'type'    => 'string',
							'pattern' => '^sd-ai-agent/token_set/[a-z0-9][a-z0-9._-]*$',
						],
						'version'       => [
							'type'    => 'string',
							'pattern' => '^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$',
						],
						'maturity'      => [
							'type' => 'string',
							'enum' => [ 'stable', 'candidate', 'experimental', 'deprecated' ],
						],
						'provenance'    => [
							'type'                 => 'object',
							'properties'           => [
								'generator_version' => [
									'type'        => 'string',
									'description' => 'Semantic Versioning generator version.',
								],
								'source_type'       => [
									'type'      => 'string',
									'minLength' => 1,
									'maxLength' => 120,
								],
								'source_reference'  => [
									'type'      => 'string',
									'minLength' => 1,
									'maxLength' => 500,
								],
								'generated_at'      => [
									'type'        => 'string',
									'description' => 'RFC 3339 timestamp.',
								],
							],
							'required'             => [ 'generator_version', 'source_type', 'source_reference', 'generated_at' ],
							'additionalProperties' => false,
						],
						'compatibility' => [
							'type'                 => 'object',
							'properties'           => [
								'wordpress'         => self::version_range_schema( 'string' ),
								'theme_json'        => self::version_range_schema( 'integer' ),
								'required_blocks'   => self::string_list_schema(),
								'required_features' => self::string_list_schema(),
								'theme_constraints' => self::string_list_schema(),
							],
							'required'             => [ 'wordpress', 'theme_json', 'required_blocks', 'required_features', 'theme_constraints' ],
							'additionalProperties' => false,
						],
						'deprecation'   => [
							'type'                 => [ 'object', 'null' ],
							'properties'           => [
								'reason'         => [
									'type'      => 'string',
									'minLength' => 1,
									'maxLength' => 500,
								],
								'replacement'    => [
									'type'      => 'string',
									'minLength' => 1,
									'maxLength' => 200,
								],
								'removal_target' => [
									'type'      => 'string',
									'minLength' => 1,
									'maxLength' => 120,
								],
							],
							'required'             => [ 'reason', 'replacement', 'removal_target' ],
							'additionalProperties' => false,
						],
					],
					'required'             => [ 'id', 'version', 'maturity', 'provenance', 'compatibility' ],
					'additionalProperties' => false,
				],
				'primitives'      => [
					'type'                 => 'object',
					'properties'           => [
						'colors'        => self::primitive_collection_schema(
							'color',
							[
								'type'    => 'string',
								'pattern' => '^#[0-9A-Fa-f]{3}(?:[0-9A-Fa-f]{3})?$',
							],
							$slug,
							$label
							),
						'font_families' => self::primitive_collection_schema(
							'fontFamily',
							[
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 200,
							],
							$slug,
							$label
							),
						'font_sizes'    => self::primitive_collection_schema(
							'size',
							[
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 200,
							],
							$slug,
							$label
							),
						'spacing'       => self::primitive_collection_schema(
							'size',
							[
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 200,
							],
							$slug,
							$label
							),
						'radii'         => self::primitive_collection_schema(
							'size',
							[
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 200,
							],
							$slug,
							$label
							),
						'shadows'       => self::primitive_collection_schema(
							'shadow',
							[
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 200,
							],
							$slug,
							$label,
							false
							),
					],
					'required'             => [ 'colors', 'font_families', 'font_sizes', 'spacing', 'radii' ],
					'additionalProperties' => false,
				],
				'semantics'       => [
					'type'                 => 'object',
					'properties'           => [
						'colors'     => self::semantic_map_schema( self::REQUIRED_COLOR_ROLES, '^(?:colors|semantics\.colors)\.[a-z0-9]+(?:-[a-z0-9]+)*$' ),
						'typography' => [
							'type'                 => 'object',
							'properties'           => [
								'body'    => $typography_role,
								'heading' => $typography_role,
							],
							'required'             => self::REQUIRED_TYPOGRAPHY_ROLES,
							'additionalProperties' => false,
						],
						'spacing'    => self::semantic_map_schema( self::REQUIRED_SPACING_ROLES, '^spacing\.[a-z0-9]+(?:-[a-z0-9]+)*$' ),
						'radius'     => self::semantic_map_schema( self::REQUIRED_RADIUS_ROLES, '^radii\.[a-z0-9]+(?:-[a-z0-9]+)*$' ),
						'shadows'    => self::semantic_map_schema( [], '^shadows\.[a-z0-9]+(?:-[a-z0-9]+)*$' ),
					],
					'required'             => [ 'colors', 'typography', 'spacing', 'radius' ],
					'additionalProperties' => false,
				],
				'style_variation' => [
					'type'                 => 'object',
					'properties'           => [
						'slug'   => $slug,
						'title'  => $label,
						'colors' => self::semantic_map_schema( self::REQUIRED_COLOR_ROLES, '^(?:colors|semantics\.colors|base\.colors)\.[a-z0-9]+(?:-[a-z0-9]+)*$' ),
					],
					'required'             => [ 'slug', 'title', 'colors' ],
					'additionalProperties' => false,
				],
			],
			'required'             => [ 'version', 'governance', 'primitives', 'semantics', 'style_variation' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * @param string              $value_key    Primitive value property name.
	 * @param array<string,mixed> $value_schema Primitive value schema.
	 * @param array<string,mixed> $slug_schema  Slug schema.
	 * @param array<string,mixed> $label_schema Label schema.
	 * @param bool                $required     Whether the collection must be non-empty.
	 * @return array<string,mixed>
	 */
	private static function primitive_collection_schema( string $value_key, array $value_schema, array $slug_schema, array $label_schema, bool $required = true ): array {
		return [
			'type'     => 'array',
			'minItems' => $required ? 1 : 0,
			'maxItems' => self::MAX_PRIMITIVES_PER_COLLECTION,
			'items'    => [
				'type'                 => 'object',
				'properties'           => [
					'slug'     => $slug_schema,
					$value_key => $value_schema,
					'name'     => $label_schema,
				],
				'required'             => [ 'slug', $value_key ],
				'additionalProperties' => false,
			],
		];
	}

	/**
	 * @param array  $required Required semantic roles.
	 * @param string $pattern  Reference pattern.
	 * @return array<string,mixed>
	 * @phpstan-param list<string> $required
	 */
	private static function semantic_map_schema( array $required, string $pattern ): array {
		$properties = [];
		foreach ( $required as $role ) {
			$properties[ $role ] = [
				'type'    => 'string',
				'pattern' => $pattern,
			];
		}

		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'maxProperties'        => self::MAX_SEMANTIC_ROLES,
			'additionalProperties' => [
				'type'    => 'string',
				'pattern' => $pattern,
			],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function version_range_schema( string $type ): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'min' => [ 'type' => $type ],
				'max' => [ 'type' => [ $type, 'null' ] ],
			],
			'required'             => [ 'min', 'max' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function string_list_schema(): array {
		return [
			'type'     => 'array',
			'maxItems' => self::MAX_SEMANTIC_ROLES,
			'items'    => [
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 200,
			],
		];
	}

	/**
	 * Normalize a complete version-1 token contract.
	 *
	 * @param array<string,mixed> $contract Raw caller contract.
	 * @return array<string,mixed>|WP_Error Normalized contract or a path-specific error.
	 * @phpstan-return NormalizedContract|WP_Error
	 */
	public static function normalize( array $contract ): array|WP_Error {
		$contract = self::associative_array( $contract, 'contract' );
		if ( is_wp_error( $contract ) ) {
			return $contract;
		}
		$unknown = self::reject_unknown_keys( $contract, [ 'version', 'governance', 'primitives', 'semantics', 'style_variation' ], '' );
		if ( is_wp_error( $unknown ) ) {
			return $unknown;
		}

		if ( self::VERSION !== ( $contract['version'] ?? null ) ) {
			return self::error(
				'unsupported_version',
				'version',
				__( 'This design-token contract version is not supported.', 'superdav-ai-agent' )
			);
		}

		$primitives = self::normalize_primitives( $contract['primitives'] ?? null );
		if ( is_wp_error( $primitives ) ) {
			return $primitives;
		}

		$semantics = self::normalize_semantics( $contract['semantics'] ?? null, $primitives );
		if ( is_wp_error( $semantics ) ) {
			return $semantics;
		}

		$variation = self::normalize_variation( $contract['style_variation'] ?? null, $primitives, $semantics['colors'] );
		if ( is_wp_error( $variation ) ) {
			return $variation;
		}

		$governance = self::normalize_governance(
			$contract['governance'] ?? null,
			[
				'version'         => self::VERSION,
				'primitives'      => $primitives,
				'semantics'       => $semantics,
				'style_variation' => $variation,
			]
		);
		if ( is_wp_error( $governance ) ) {
			return $governance;
		}

		return [
			'version'         => self::VERSION,
			'primitives'      => $primitives,
			'semantics'       => $semantics,
			'style_variation' => $variation,
			'governance'      => $governance,
		];
	}

	/**
	 * Normalize every primitive collection into a deterministic slug map.
	 *
	 * @param mixed $raw Raw primitive block.
	 * @return array<string,array<string,array<string,string>>>|WP_Error Normalized maps or an error.
	 * @phpstan-return PrimitiveCollections|WP_Error
	 */
	private static function normalize_primitives( mixed $raw ): array|WP_Error {
		$raw = self::associative_array( $raw, 'primitives' );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$definitions = [
			'colors'        => [
				'key'      => 'color',
				'required' => true,
				'kind'     => 'color',
			],
			'font_families' => [
				'key'      => 'fontFamily',
				'required' => true,
				'kind'     => 'font_family',
			],
			'font_sizes'    => [
				'key'      => 'size',
				'required' => true,
				'kind'     => 'size',
			],
			'spacing'       => [
				'key'      => 'size',
				'required' => true,
				'kind'     => 'size',
			],
			'radii'         => [
				'key'      => 'size',
				'required' => true,
				'kind'     => 'size',
			],
			'shadows'       => [
				'key'      => 'shadow',
				'required' => false,
				'kind'     => 'shadow',
			],
		];
		$unknown     = self::reject_unknown_keys( $raw, array_keys( $definitions ), 'primitives' );
		if ( is_wp_error( $unknown ) ) {
			return $unknown;
		}

		$normalized = [];
		$total      = 0;
		foreach ( $definitions as $collection => $definition ) {
			$value = $raw[ $collection ] ?? [];
			if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
				return self::error(
					'invalid_collection',
					'primitives.' . $collection,
					__( 'Each design-token primitive collection must be a list.', 'superdav-ai-agent' )
				);
			}
			if ( $definition['required'] && [] === $value ) {
				return self::error(
					'missing_primitives',
					'primitives.' . $collection,
					__( 'This design-token primitive collection cannot be empty.', 'superdav-ai-agent' )
				);
			}
			if ( count( $value ) > self::MAX_PRIMITIVES_PER_COLLECTION ) {
				return self::error(
					'too_many_primitives',
					'primitives.' . $collection,
					__( 'A design-token primitive collection exceeds the supported item limit.', 'superdav-ai-agent' ),
					[ 'maximum' => self::MAX_PRIMITIVES_PER_COLLECTION ]
				);
			}
			$total += count( $value );
			if ( $total > self::MAX_TOTAL_PRIMITIVES ) {
				return self::error(
					'too_many_primitives',
					'primitives',
					__( 'The design-token contract exceeds the supported total primitive limit.', 'superdav-ai-agent' ),
					[ 'maximum' => self::MAX_TOTAL_PRIMITIVES ]
				);
			}

			$entries = [];
			foreach ( $value as $index => $entry ) {
				$path  = 'primitives.' . $collection . '.' . $index;
				$entry = self::associative_array( $entry, $path );
				if ( is_wp_error( $entry ) ) {
					return $entry;
				}
				$unknown = self::reject_unknown_keys( $entry, [ 'slug', $definition['key'], 'name' ], $path );
				if ( is_wp_error( $unknown ) ) {
					return $unknown;
				}

				$slug = $entry['slug'] ?? null;
				if ( ! is_string( $slug ) || ! self::is_safe_slug( $slug ) ) {
					return self::error(
						'invalid_slug',
						$path . '.slug',
						__( 'Design-token primitive slugs must be lowercase CSS-safe identifiers.', 'superdav-ai-agent' )
					);
				}
				if ( isset( $entries[ $slug ] ) ) {
					return self::error(
						'duplicate_slug',
						$path . '.slug',
						__( 'Design-token primitive slugs must be unique within their collection.', 'superdav-ai-agent' ),
						[ 'slug' => $slug ]
					);
				}

				$value_key = $definition['key'];
				$token     = $entry[ $value_key ] ?? null;
				if ( 'color' === $definition['kind'] ) {
					$token = is_string( $token ) ? PaletteValidator::normalise_hex( $token ) : null;
					if ( null === $token ) {
						return self::error(
							'invalid_value',
							$path . '.' . $value_key,
							__( 'Colour primitives must use a valid hexadecimal colour value.', 'superdav-ai-agent' )
						);
					}
				} else {
					$token = self::normalize_css_value( $token, $path . '.' . $value_key, $definition['kind'] );
					if ( is_wp_error( $token ) ) {
						return $token;
					}
				}

				$name = self::normalize_label( $entry['name'] ?? null, $slug, $path . '.name' );
				if ( is_wp_error( $name ) ) {
					return $name;
				}

				$entries[ $slug ] = [
					'slug'     => $slug,
					$value_key => $token,
					'name'     => $name,
				];
			}

			ksort( $entries, SORT_STRING );
			$normalized[ $collection ] = $entries;
		}

		return [
			'colors'        => $normalized['colors'],
			'font_families' => $normalized['font_families'],
			'font_sizes'    => $normalized['font_sizes'],
			'spacing'       => $normalized['spacing'],
			'radii'         => $normalized['radii'],
			'shadows'       => $normalized['shadows'],
		];
	}

	/**
	 * Resolve required semantic aliases and their primitive references.
	 *
	 * @param mixed                                            $raw        Raw semantic block.
	 * @param array<string,array<string,array<string,string>>> $primitives Normalized primitive maps.
	 * @return array<string,mixed>|WP_Error Normalized semantics or an error.
	 * @phpstan-param PrimitiveCollections $primitives
	 * @phpstan-return Semantics|WP_Error
	 */
	private static function normalize_semantics( mixed $raw, array $primitives ): array|WP_Error {
		$raw = self::associative_array( $raw, 'semantics' );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$unknown = self::reject_unknown_keys( $raw, [ 'colors', 'typography', 'spacing', 'radius', 'shadows' ], 'semantics' );
		if ( is_wp_error( $unknown ) ) {
			return $unknown;
		}

		$raw_colors = self::associative_array( $raw['colors'] ?? null, 'semantics.colors' );
		if ( is_wp_error( $raw_colors ) ) {
			return $raw_colors;
		}
		$limit = self::enforce_role_limit( $raw_colors, 'semantics.colors' );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		foreach ( self::REQUIRED_COLOR_ROLES as $role ) {
			if ( ! array_key_exists( $role, $raw_colors ) ) {
				return self::error(
					'missing_required_role',
					'semantics.colors.' . $role,
					__( 'The design-token contract is missing a required semantic colour role.', 'superdav-ai-agent' ),
					[ 'role' => $role ]
				);
			}
		}

		$colors   = [];
		$visiting = [];
		foreach ( self::ordered_roles( $raw_colors, self::REQUIRED_COLOR_ROLES ) as $role ) {
			if ( ! self::is_safe_slug( $role ) ) {
				return self::error(
					'invalid_role',
					'semantics.colors.' . $role,
					__( 'Semantic colour role names must be lowercase CSS-safe identifiers.', 'superdav-ai-agent' )
				);
			}

			$resolved = self::resolve_color_reference(
				$role,
				$raw_colors,
				$primitives['colors'],
				$colors,
				$visiting,
				'semantics.colors'
			);
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$colors[ $role ] = $resolved;
		}

		$typography = self::normalize_typography_semantics( $raw['typography'] ?? null, $primitives );
		if ( is_wp_error( $typography ) ) {
			return $typography;
		}

		$spacing = self::normalize_simple_semantics(
			$raw['spacing'] ?? null,
			'semantics.spacing',
			self::REQUIRED_SPACING_ROLES,
			'spacing',
			$primitives
		);
		if ( is_wp_error( $spacing ) ) {
			return $spacing;
		}

		$radius = self::normalize_simple_semantics(
			$raw['radius'] ?? null,
			'semantics.radius',
			self::REQUIRED_RADIUS_ROLES,
			'radii',
			$primitives
		);
		if ( is_wp_error( $radius ) ) {
			return $radius;
		}

		$raw_shadows = $raw['shadows'] ?? [];
		$shadows     = [];
		if ( [] !== $raw_shadows ) {
			$shadows = self::normalize_simple_semantics(
				$raw_shadows,
				'semantics.shadows',
				[],
				'shadows',
				$primitives
			);
			if ( is_wp_error( $shadows ) ) {
				return $shadows;
			}
		}

		return [
			'colors'     => $colors,
			'typography' => $typography,
			'spacing'    => $spacing,
			'radius'     => $radius,
			'shadows'    => $shadows,
		];
	}

	/**
	 * Normalize body and heading family/size aliases.
	 *
	 * @param mixed                                            $raw        Raw typography block.
	 * @param array<string,array<string,array<string,string>>> $primitives Normalized primitive maps.
	 * @return array<string,array<string,string>>|WP_Error Normalized typography or an error.
	 * @phpstan-param PrimitiveCollections $primitives
	 * @phpstan-return TypographyRoles|WP_Error
	 */
	private static function normalize_typography_semantics( mixed $raw, array $primitives ): array|WP_Error {
		$roles = self::associative_array( $raw, 'semantics.typography' );
		if ( is_wp_error( $roles ) ) {
			return $roles;
		}
		$unknown = self::reject_unknown_keys( $roles, self::REQUIRED_TYPOGRAPHY_ROLES, 'semantics.typography' );
		if ( is_wp_error( $unknown ) ) {
			return $unknown;
		}

		$normalized = [];
		foreach ( self::REQUIRED_TYPOGRAPHY_ROLES as $role ) {
			if ( ! array_key_exists( $role, $roles ) ) {
				return self::error(
					'missing_required_role',
					'semantics.typography.' . $role,
					__( 'The design-token contract is missing a required semantic typography role.', 'superdav-ai-agent' ),
					[ 'role' => $role ]
				);
			}
			$entry = self::associative_array( $roles[ $role ], 'semantics.typography.' . $role );
			if ( is_wp_error( $entry ) ) {
				return $entry;
			}
			$unknown = self::reject_unknown_keys( $entry, [ 'font_family', 'font_size' ], 'semantics.typography.' . $role );
			if ( is_wp_error( $unknown ) ) {
				return $unknown;
			}

			$font_family = self::resolve_primitive_reference(
				$entry['font_family'] ?? null,
				'font_families',
				$primitives,
				'semantics.typography.' . $role . '.font_family'
			);
			if ( is_wp_error( $font_family ) ) {
				return $font_family;
			}
			$font_size = self::resolve_primitive_reference(
				$entry['font_size'] ?? null,
				'font_sizes',
				$primitives,
				'semantics.typography.' . $role . '.font_size'
			);
			if ( is_wp_error( $font_size ) ) {
				return $font_size;
			}

			$normalized[ $role ] = [
				'font_family' => $font_family,
				'font_size'   => $font_size,
			];
		}

		return $normalized;
	}

	/**
	 * Normalize a semantic role map whose values directly reference one primitive collection.
	 *
	 * @param mixed                                            $raw        Raw role map.
	 * @param string                                           $path       Contract path.
	 * @param array                                            $required   Required role names.
	 * @param string                                           $collection Primitive collection name.
	 * @param array<string,array<string,array<string,string>>> $primitives Normalized primitive maps.
	 * @return array<string,string>|WP_Error Normalized role-to-slug map or an error.
	 * @phpstan-param list<string> $required
	 * @phpstan-param PrimitiveCollections $primitives
	 */
	private static function normalize_simple_semantics( mixed $raw, string $path, array $required, string $collection, array $primitives ): array|WP_Error {
		$roles = self::associative_array( $raw, $path );
		if ( is_wp_error( $roles ) ) {
			return $roles;
		}
		$limit = self::enforce_role_limit( $roles, $path );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}

		foreach ( $required as $role ) {
			if ( ! array_key_exists( $role, $roles ) ) {
				return self::error(
					'missing_required_role',
					$path . '.' . $role,
					__( 'The design-token contract is missing a required semantic role.', 'superdav-ai-agent' ),
					[ 'role' => $role ]
				);
			}
		}

		$normalized = [];
		foreach ( self::ordered_roles( $roles, $required ) as $role ) {
			if ( ! self::is_safe_slug( $role ) ) {
				return self::error(
					'invalid_role',
					$path . '.' . $role,
					__( 'Semantic role names must be lowercase CSS-safe identifiers.', 'superdav-ai-agent' )
				);
			}
			$reference = self::resolve_primitive_reference( $roles[ $role ], $collection, $primitives, $path . '.' . $role );
			if ( is_wp_error( $reference ) ) {
				return $reference;
			}
			$normalized[ $role ] = $reference;
		}

		return $normalized;
	}

	/**
	 * Normalize the required semantic-colour variation remap.
	 *
	 * @param mixed                                            $raw         Raw variation block.
	 * @param array<string,array<string,array<string,string>>> $primitives  Normalized primitive maps.
	 * @param array<string,string>                             $base_colors Resolved base semantic colours.
	 * @return array<string,mixed>|WP_Error Normalized variation or an error.
	 * @phpstan-param PrimitiveCollections $primitives
	 * @phpstan-return StyleVariation|WP_Error
	 */
	private static function normalize_variation( mixed $raw, array $primitives, array $base_colors ): array|WP_Error {
		$variation = self::associative_array( $raw, 'style_variation' );
		if ( is_wp_error( $variation ) ) {
			return $variation;
		}
		$unknown = self::reject_unknown_keys( $variation, [ 'slug', 'title', 'colors' ], 'style_variation' );
		if ( is_wp_error( $unknown ) ) {
			return $unknown;
		}

		$slug = $variation['slug'] ?? null;
		if ( ! is_string( $slug ) || ! self::is_safe_slug( $slug ) ) {
			return self::error(
				'invalid_slug',
				'style_variation.slug',
				__( 'Style-variation slugs must be lowercase CSS-safe identifiers.', 'superdav-ai-agent' )
			);
		}
		$title = self::normalize_label( $variation['title'] ?? null, $slug, 'style_variation.title' );
		if ( is_wp_error( $title ) ) {
			return $title;
		}

		$raw_colors = self::associative_array( $variation['colors'] ?? null, 'style_variation.colors' );
		if ( is_wp_error( $raw_colors ) ) {
			return $raw_colors;
		}
		$limit = self::enforce_role_limit( $raw_colors, 'style_variation.colors' );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		foreach ( self::REQUIRED_COLOR_ROLES as $role ) {
			if ( ! array_key_exists( $role, $raw_colors ) ) {
				return self::error(
					'missing_required_role',
					'style_variation.colors.' . $role,
					__( 'The style variation must remap every required semantic colour role.', 'superdav-ai-agent' ),
					[ 'role' => $role ]
				);
			}
		}

		$colors   = [];
		$visiting = [];
		foreach ( self::ordered_roles( $raw_colors, self::REQUIRED_COLOR_ROLES ) as $role ) {
			if ( ! self::is_safe_slug( $role ) ) {
				return self::error(
					'invalid_role',
					'style_variation.colors.' . $role,
					__( 'Style-variation colour role names must be lowercase CSS-safe identifiers.', 'superdav-ai-agent' )
				);
			}
			$resolved = self::resolve_color_reference(
				$role,
				$raw_colors,
				$primitives['colors'],
				$colors,
				$visiting,
				'style_variation.colors',
				$base_colors
			);
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$colors[ $role ] = $resolved;
		}

		return [
			'slug'   => $slug,
			'title'  => $title,
			'colors' => $colors,
		];
	}

	/**
	 * Resolve a colour primitive or semantic alias while detecting cycles.
	 *
	 * @param string                             $role        Semantic role currently resolving.
	 * @param array<string,mixed>                $raw_roles   Raw role-to-reference map.
	 * @param array<string,array<string,string>> $colors      Normalized colour primitive map.
	 * @param array<string,string>               $resolved    Resolved role-to-primitive map.
	 * @param array<string,true>                 $visiting    Current resolution stack.
	 * @param string                             $path        Base contract path.
	 * @param array<string,string>               $base_colors Optional base role map for variations.
	 * @param int                                $depth       Current alias depth.
	 * @return string|WP_Error Primitive colour slug or an error.
	 */
	private static function resolve_color_reference( string $role, array $raw_roles, array $colors, array &$resolved, array &$visiting, string $path, array $base_colors = [], int $depth = 0 ): string|WP_Error {
		if ( $depth >= self::MAX_REFERENCE_DEPTH ) {
			return self::error(
				'reference_depth_exceeded',
				$path . '.' . $role,
				__( 'Semantic colour references exceed the supported alias depth.', 'superdav-ai-agent' ),
				[ 'maximum' => self::MAX_REFERENCE_DEPTH ]
			);
		}
		if ( isset( $resolved[ $role ] ) ) {
			return $resolved[ $role ];
		}
		if ( isset( $visiting[ $role ] ) ) {
			return self::error(
				'circular_reference',
				$path . '.' . $role,
				__( 'Semantic colour references cannot form a cycle.', 'superdav-ai-agent' ),
				[ 'role' => $role ]
			);
		}
		if ( ! array_key_exists( $role, $raw_roles ) ) {
			return self::error(
				'missing_reference',
				$path . '.' . $role,
				__( 'The design-token contract references a missing semantic colour role.', 'superdav-ai-agent' ),
				[ 'role' => $role ]
			);
		}

		$reference = $raw_roles[ $role ];
		if ( ! is_string( $reference ) || '' === $reference ) {
			return self::error(
				'invalid_reference',
				$path . '.' . $role,
				__( 'Semantic colour references must be non-empty strings.', 'superdav-ai-agent' )
			);
		}

		$visiting[ $role ] = true;
		if ( str_starts_with( $reference, 'colors.' ) ) {
			$slug = substr( $reference, strlen( 'colors.' ) );
			if ( ! isset( $colors[ $slug ] ) ) {
				unset( $visiting[ $role ] );
				return self::error(
					'missing_reference',
					$path . '.' . $role,
					__( 'A semantic colour role references a missing colour primitive.', 'superdav-ai-agent' ),
					[ 'reference' => $reference ]
				);
			}
			$result = $slug;
		} elseif ( str_starts_with( $reference, 'semantics.colors.' ) ) {
			$target = substr( $reference, strlen( 'semantics.colors.' ) );
			$result = self::resolve_color_reference( $target, $raw_roles, $colors, $resolved, $visiting, $path, $base_colors, $depth + 1 );
		} elseif ( str_starts_with( $reference, 'base.colors.' ) ) {
			$target = substr( $reference, strlen( 'base.colors.' ) );
			if ( ! isset( $base_colors[ $target ] ) ) {
				unset( $visiting[ $role ] );
				return self::error(
					'missing_reference',
					$path . '.' . $role,
					__( 'A style-variation colour role references a missing base semantic colour.', 'superdav-ai-agent' ),
					[ 'reference' => $reference ]
				);
			}
			$result = $base_colors[ $target ];
		} else {
			unset( $visiting[ $role ] );
			return self::error(
				'invalid_reference',
				$path . '.' . $role,
				__( 'Semantic colour references must point to colors.*, semantics.colors.*, or base.colors.*.', 'superdav-ai-agent' ),
				[ 'reference' => $reference ]
			);
		}

		unset( $visiting[ $role ] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$resolved[ $role ] = $result;

		return $result;
	}

	/**
	 * Resolve a direct primitive reference of the expected collection.
	 *
	 * @param mixed                                            $reference  Raw reference.
	 * @param string                                           $collection Expected collection.
	 * @param array<string,array<string,array<string,string>>> $primitives Normalized primitive maps.
	 * @param string                                           $path       Contract path.
	 * @return string|WP_Error Primitive slug or an error.
	 * @phpstan-param PrimitiveCollections $primitives
	 */
	private static function resolve_primitive_reference( mixed $reference, string $collection, array $primitives, string $path ): string|WP_Error {
		if ( ! is_string( $reference ) || ! str_starts_with( $reference, $collection . '.' ) ) {
			return self::error(
				'invalid_reference',
				$path,
				__( 'This semantic role must reference the matching primitive collection.', 'superdav-ai-agent' )
			);
		}

		$slug = substr( $reference, strlen( $collection . '.' ) );
		if ( ! isset( $primitives[ $collection ][ $slug ] ) ) {
			return self::error(
				'missing_reference',
				$path,
				__( 'This semantic role references a missing primitive.', 'superdav-ai-agent' ),
				[ 'reference' => $reference ]
			);
		}

		return $slug;
	}

	/**
	 * Complete #2248 governance metadata with a canonical input hash.
	 *
	 * @param mixed               $raw         Raw governance metadata.
	 * @param array<string,mixed> $hash_source Normalized contract contents.
	 * @return array<string,mixed>|WP_Error Valid governance metadata or an error.
	 * @phpstan-return Governance|WP_Error
	 */
	private static function normalize_governance( mixed $raw, array $hash_source ): array|WP_Error {
		$raw = self::associative_array( $raw, 'governance' );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$unknown = self::reject_unknown_keys( $raw, [ 'id', 'version', 'maturity', 'provenance', 'compatibility', 'deprecation' ], 'governance' );
		if ( is_wp_error( $unknown ) ) {
			return $unknown;
		}

		$provenance = self::associative_array( $raw['provenance'] ?? null, 'governance.provenance' );
		if ( is_wp_error( $provenance ) ) {
			return $provenance;
		}
		$unknown = self::reject_unknown_keys( $provenance, [ 'generator_version', 'source_type', 'source_reference', 'generated_at' ], 'governance.provenance' );
		if ( is_wp_error( $unknown ) ) {
			return $unknown;
		}

		$compatibility = self::associative_array( $raw['compatibility'] ?? null, 'governance.compatibility' );
		if ( is_wp_error( $compatibility ) ) {
			return $compatibility;
		}
		$unknown = self::reject_unknown_keys( $compatibility, [ 'wordpress', 'theme_json', 'required_blocks', 'required_features', 'theme_constraints' ], 'governance.compatibility' );
		if ( is_wp_error( $unknown ) ) {
			return $unknown;
		}
		foreach ( [ 'wordpress', 'theme_json' ] as $range_name ) {
			$range = self::associative_array( $compatibility[ $range_name ] ?? null, 'governance.compatibility.' . $range_name );
			if ( is_wp_error( $range ) ) {
				return $range;
			}
			$unknown = self::reject_unknown_keys( $range, [ 'min', 'max' ], 'governance.compatibility.' . $range_name );
			if ( is_wp_error( $unknown ) ) {
				return $unknown;
			}
		}

		if ( array_key_exists( 'deprecation', $raw ) && null !== $raw['deprecation'] ) {
			$deprecation = self::associative_array( $raw['deprecation'], 'governance.deprecation' );
			if ( is_wp_error( $deprecation ) ) {
				return $deprecation;
			}
			$unknown = self::reject_unknown_keys( $deprecation, [ 'reason', 'replacement', 'removal_target' ], 'governance.deprecation' );
			if ( is_wp_error( $unknown ) ) {
				return $unknown;
			}
		}

		$hash_source['governance'] = $raw;
		$input_hash                = ArtifactManifest::hash_payload( $hash_source );
		if ( is_wp_error( $input_hash ) ) {
			return self::error(
				'invalid_governance',
				'governance',
				__( 'The design-token governance metadata cannot be canonically encoded.', 'superdav-ai-agent' ),
				[ 'cause' => $input_hash->get_error_code() ]
			);
		}

		$provenance['input_hash'] = $input_hash;

		$artifact = $raw;

		$artifact['kind']       = 'token_set';
		$artifact['provenance'] = $provenance;
		$artifact['payload']    = [
			'files'   => [],
			'records' => [],
		];

		$artifact = ArtifactManifest::create_artifact( $artifact );
		if ( is_wp_error( $artifact ) ) {
			return self::error(
				'invalid_governance',
				'governance',
				$artifact->get_error_message(),
				[ 'cause' => $artifact->get_error_code() ]
			);
		}

		$id            = $artifact['id'] ?? null;
		$version       = $artifact['version'] ?? null;
		$maturity      = $artifact['maturity'] ?? null;
		$provenance    = self::associative_array( $artifact['provenance'] ?? null, 'governance.provenance' );
		$compatibility = self::associative_array( $artifact['compatibility'] ?? null, 'governance.compatibility' );
		$deprecation   = $artifact['deprecation'] ?? null;

		if ( ! is_string( $id ) || ! is_string( $version ) || ! is_string( $maturity ) || is_wp_error( $provenance ) || is_wp_error( $compatibility ) ) {
			return self::error(
				'invalid_governance',
				'governance',
				__( 'The normalized governance metadata has an unexpected shape.', 'superdav-ai-agent' )
			);
		}
		if ( null !== $deprecation ) {
			$deprecation = self::associative_array( $deprecation, 'governance.deprecation' );
			if ( is_wp_error( $deprecation ) ) {
				return self::error(
					'invalid_governance',
					'governance.deprecation',
					__( 'The normalized governance metadata has an unexpected shape.', 'superdav-ai-agent' )
				);
			}
		}

		return [
			'id'            => $id,
			'version'       => $version,
			'maturity'      => $maturity,
			'provenance'    => $provenance,
			'compatibility' => $compatibility,
			'deprecation'   => $deprecation,
		];
	}

	/**
	 * Validate and normalize an arbitrary CSS-safe primitive value.
	 *
	 * @param mixed  $value       Raw primitive value.
	 * @param string $path        Contract path.
	 * @param string $kind  Primitive value kind.
	 * @return string|WP_Error Normalized value or an error.
	 */
	private static function normalize_css_value( mixed $value, string $path, string $kind ): string|WP_Error {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return self::error(
				'invalid_value',
				$path,
				__( 'Design-token primitive values must be non-empty strings.', 'superdav-ai-agent' )
			);
		}
		$value = trim( $value );
		if ( strlen( $value ) > 200 || 1 === preg_match( '/[;{}<>]|(?:url\s*\(|expression\s*\(|@import|javascript:)/i', $value ) ) {
			return self::error(
				'invalid_value',
				$path,
				__( 'Design-token primitive values cannot contain declarations, selectors, URLs, or executable content.', 'superdav-ai-agent' )
			);
		}
		$valid = match ( $kind ) {
			'size'        => self::is_size_value( $value ),
			'font_family' => self::is_font_family_value( $value ),
			'shadow'      => self::is_shadow_value( $value ),
			default       => false,
		};
		if ( ! $valid ) {
			return self::error(
				'invalid_value',
				$path,
				__( 'This primitive does not use a supported, well-formed CSS value.', 'superdav-ai-agent' )
			);
		}

		return $value;
	}

	/**
	 * Normalize a human-readable token or variation label.
	 *
	 * @param mixed  $value    Raw label.
	 * @param string $fallback Slug-derived fallback.
	 * @param string $path     Contract path.
	 * @return string|WP_Error Label or an error.
	 */
	private static function normalize_label( mixed $value, string $fallback, string $path ): string|WP_Error {
		if ( null === $value ) {
			return ucwords( str_replace( '-', ' ', $fallback ) );
		}
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > 120 || 1 === preg_match( '/[\x00-\x1F\x7F<>]/', $value ) ) {
			return self::error(
				'invalid_value',
				$path,
				__( 'Design-token labels must be short plain-text strings.', 'superdav-ai-agent' )
			);
		}

		return trim( $value );
	}

	/**
	 * Require an associative object-like array at one contract path.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $path  Contract path.
	 * @return array<string,mixed>|WP_Error Object or an error.
	 */
	private static function associative_array( mixed $value, string $path ): array|WP_Error {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			return self::error(
				'invalid_value',
				$path,
				__( 'This design-token field must be an object.', 'superdav-ai-agent' )
			);
		}

		$normalized = [];
		foreach ( $value as $key => $item ) {
			if ( ! is_string( $key ) ) {
				return self::error(
					'invalid_value',
					$path,
					__( 'This design-token field must be an object with string keys.', 'superdav-ai-agent' )
				);
			}
			$normalized[ $key ] = $item;
		}

		return $normalized;
	}

	/**
	 * Reject fields outside a strict object contract.
	 *
	 * @param array<string,mixed> $value   Object value.
	 * @param array               $allowed Allowed keys.
	 * @param string              $path    Object path.
	 * @return true|WP_Error
	 * @phpstan-param list<string> $allowed
	 */
	private static function reject_unknown_keys( array $value, array $allowed, string $path ): true|WP_Error {
		foreach ( array_keys( $value ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				$unexpected_path = '' === $path ? $key : $path . '.' . $key;

				return self::error(
					'unexpected_value',
					$unexpected_path,
					__( 'The design-token contract contains an unexpected field.', 'superdav-ai-agent' )
				);
			}
		}

		return true;
	}

	/**
	 * Bound semantic role maps before recursion or output expansion.
	 *
	 * @param array<string,mixed> $roles Role map.
	 * @return true|WP_Error
	 */
	private static function enforce_role_limit( array $roles, string $path ): true|WP_Error {
		if ( count( $roles ) > self::MAX_SEMANTIC_ROLES ) {
			return self::error(
				'too_many_roles',
				$path,
				__( 'The design-token semantic role map exceeds the supported item limit.', 'superdav-ai-agent' ),
				[ 'maximum' => self::MAX_SEMANTIC_ROLES ]
			);
		}

		return true;
	}

	/**
	 * Order required roles first and optional roles deterministically afterwards.
	 *
	 * @param array<string,mixed> $roles    Raw roles.
	 * @param array               $required Required role order.
	 * @return list<string> Ordered role names.
	 * @phpstan-param list<string> $required
	 */
	private static function ordered_roles( array $roles, array $required ): array {
		$optional = array_values( array_diff( array_keys( $roles ), $required ) );
		sort( $optional, SORT_STRING );

		return array_merge( $required, $optional );
	}

	/**
	 * Check CSS-safe primitive/semantic slug syntax.
	 */
	private static function is_safe_slug( string $slug ): bool {
		return 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug );
	}

	/**
	 * Check the bounded CSS length forms accepted by the contract.
	 */
	private static function is_size_value( string $value ): bool {
		$length = '(?:0|(?:\d+(?:\.\d+)?|\.\d+)(?:px|rem|em|%|vw|vh|ch|ex))';
		if ( 1 === preg_match( '~^' . $length . '$~', $value ) ) {
			return true;
		}
		if ( ! self::has_balanced_parentheses( $value ) ) {
			return false;
		}

		return 1 === preg_match( '~^calc\(\s*' . $length . '(?:(?:\s*[+*/]\s*|\s+-\s+)' . $length . ')+\s*\)$~', $value )
			|| 1 === preg_match( '~^(?:min|max)\(\s*' . $length . '(?:\s*,\s*' . $length . ')+\s*\)$~', $value )
			|| 1 === preg_match( '~^clamp\(\s*' . $length . '\s*,\s*' . $length . '\s*,\s*' . $length . '\s*\)$~', $value );
	}

	/**
	 * Validate a bounded CSS font-family list without functions or escapes.
	 */
	private static function is_font_family_value( string $value ): bool {
		return 1 === preg_match(
			"~^(?:\"[^\"\r\n]+\"|'[^'\r\n]+'|[A-Za-z0-9][A-Za-z0-9 _-]*)(?:\\s*,\\s*(?:\"[^\"\r\n]+\"|'[^'\r\n]+'|[A-Za-z0-9][A-Za-z0-9 _-]*))*$~",
			$value
		);
	}

	/**
	 * Validate bounded WordPress-compatible box-shadow syntax.
	 */
	private static function is_shadow_value( string $value ): bool {
		if ( 'none' === $value ) {
			return true;
		}
		if ( ! self::has_balanced_parentheses( $value ) || 1 === preg_match( '/[^#A-Za-z0-9%.,()\/\s+\-]/', $value ) ) {
			return false;
		}
		$functions = [];
		preg_match_all( '/\b(?:rgb|rgba|hsl|hsla)\([^()]*\)/i', $value, $functions );
		foreach ( $functions[0] as $function ) {
			if ( ! is_string( $function ) || ! self::is_color_function_value( $function ) ) {
				return false;
			}
		}

		$length = '(?:0|-?(?:\d+(?:\.\d+)?|\.\d+)(?:px|rem|em|ch|ex|vw|vh|%))';
		$color  = '(?:#[0-9A-Fa-f]{3}(?:[0-9A-Fa-f](?:[0-9A-Fa-f]{2}(?:[0-9A-Fa-f]{2})?)?)?|(?:rgba?|hsla?)\([^()]*\)|transparent|currentColor|black|white)';
		$shadow = '(?:inset\s+)?' . $length . '\s+' . $length . '(?:\s+' . $length . '){0,2}(?:\s+' . $color . ')?';

		return 1 === preg_match( '~^' . $shadow . '(?:\s*,\s*' . $shadow . ')*$~', $value );
	}

	/**
	 * Validate RGB/HSL channels, separators, and alpha ranges.
	 */
	private static function is_color_function_value( string $value ): bool {
		if ( 1 !== preg_match( '/^(rgb|rgba|hsl|hsla)\((.*)\)$/i', $value, $matches ) ) {
			return false;
		}
		$name           = strtolower( $matches[1] );
		$arguments      = trim( $matches[2] );
		$requires_alpha = str_ends_with( $name, 'a' );
		$alpha          = null;

		if ( str_contains( $arguments, ',' ) ) {
			if ( str_contains( $arguments, '/' ) ) {
				return false;
			}
			$channels = array_map( 'trim', explode( ',', $arguments ) );
			if ( count( $channels ) !== ( $requires_alpha ? 4 : 3 ) ) {
				return false;
			}
			if ( $requires_alpha ) {
				$alpha = array_pop( $channels );
			}
		} else {
			$sections = preg_split( '/\s*\/\s*/', $arguments );
			if ( false === $sections || count( $sections ) > 2 ) {
				return false;
			}
			$channels = preg_split( '/\s+/', trim( $sections[0] ) );
			if ( false === $channels || 3 !== count( $channels ) ) {
				return false;
			}
			$alpha = $sections[1] ?? null;
			if ( $requires_alpha && null === $alpha ) {
				return false;
			}
		}

		if ( str_starts_with( $name, 'rgb' ) ) {
			$percentage = str_ends_with( $channels[0], '%' );
			foreach ( $channels as $channel ) {
				if ( str_ends_with( $channel, '%' ) !== $percentage || ! self::numeric_channel_in_range( $channel, 255.0, $percentage ) ) {
					return false;
				}
			}
		} elseif (
			! self::numeric_channel_in_range( $channels[0], 360.0, false )
			|| ! self::numeric_channel_in_range( $channels[1], 100.0, true )
			|| ! self::numeric_channel_in_range( $channels[2], 100.0, true )
		) {
			return false;
		}

		return null === $alpha || self::numeric_channel_in_range( trim( $alpha ), 1.0, str_ends_with( trim( $alpha ), '%' ) );
	}

	/**
	 * Validate one non-negative numeric or percentage channel.
	 */
	private static function numeric_channel_in_range( string $value, float $numeric_maximum, bool $percentage ): bool {
		$is_percentage = str_ends_with( $value, '%' );
		if ( $is_percentage !== $percentage ) {
			return false;
		}
		$number = $is_percentage ? substr( $value, 0, -1 ) : $value;
		if ( 1 !== preg_match( '/^(?:\d+(?:\.\d+)?|\.\d+)$/', $number ) ) {
			return false;
		}

		return (float) $number <= ( $is_percentage ? 100.0 : $numeric_maximum );
	}

	/**
	 * Check parentheses without accepting early closing delimiters.
	 */
	private static function has_balanced_parentheses( string $value ): bool {
		$depth = 0;
		for ( $index = 0, $length = strlen( $value ); $index < $length; ++$index ) {
			if ( '(' === $value[ $index ] ) {
				++$depth;
			} elseif ( ')' === $value[ $index ] ) {
				--$depth;
				if ( $depth < 0 ) {
					return false;
				}
			}
		}

		return 0 === $depth;
	}

	/**
	 * Build a consistently namespaced, path-specific contract error.
	 *
	 * @param string              $reason  Stable failure reason.
	 * @param string              $path    Contract path.
	 * @param string              $message Human-readable error.
	 * @param array<string,mixed> $data    Additional structured data.
	 */
	private static function error( string $reason, string $path, string $message, array $data = [] ): WP_Error {
		return new WP_Error(
			'sd_ai_agent_design_token_' . $reason,
			$message,
			array_merge( [ 'path' => $path ], $data )
		);
	}
}
