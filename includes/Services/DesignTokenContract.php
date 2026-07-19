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
	 * Normalize a complete version-1 token contract.
	 *
	 * @param array<string,mixed> $contract Raw caller contract.
	 * @return array<string,mixed>|WP_Error Normalized contract or a path-specific error.
	 */
	public static function normalize( array $contract ): array|WP_Error {
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
	 */
	private static function normalize_primitives( mixed $raw ): array|WP_Error {
		if ( ! is_array( $raw ) || array_is_list( $raw ) ) {
			return self::error(
				'invalid_collection',
				'primitives',
				__( 'Design-token primitives must be an object of named collections.', 'superdav-ai-agent' )
			);
		}

		$definitions = [
			'colors'        => [ 'key' => 'color', 'required' => true, 'kind' => 'color' ],
			'font_families' => [ 'key' => 'fontFamily', 'required' => true, 'kind' => 'css' ],
			'font_sizes'    => [ 'key' => 'size', 'required' => true, 'kind' => 'size' ],
			'spacing'       => [ 'key' => 'size', 'required' => true, 'kind' => 'size' ],
			'radii'         => [ 'key' => 'size', 'required' => true, 'kind' => 'size' ],
			'shadows'       => [ 'key' => 'shadow', 'required' => false, 'kind' => 'css' ],
		];

		$normalized = [];
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

			$entries = [];
			foreach ( $value as $index => $entry ) {
				$path = 'primitives.' . $collection . '.' . $index;
				if ( ! is_array( $entry ) || array_is_list( $entry ) ) {
					return self::error(
						'invalid_primitive',
						$path,
						__( 'Each design-token primitive must be an object.', 'superdav-ai-agent' )
					);
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
					$token = self::normalize_css_value( $token, $path . '.' . $value_key, 'size' === $definition['kind'] );
					if ( is_wp_error( $token ) ) {
						return $token;
					}
				}

				$name = self::normalize_label( $entry['name'] ?? null, $slug, $path . '.name' );
				if ( is_wp_error( $name ) ) {
					return $name;
				}

				$entries[ $slug ] = [
					'slug'       => $slug,
					$value_key    => $token,
					'name'       => $name,
				];
			}

			ksort( $entries, SORT_STRING );
			$normalized[ $collection ] = $entries;
		}

		return $normalized;
	}

	/**
	 * Resolve required semantic aliases and their primitive references.
	 *
	 * @param mixed                                            $raw        Raw semantic block.
	 * @param array<string,array<string,array<string,string>>> $primitives Normalized primitive maps.
	 * @return array<string,mixed>|WP_Error Normalized semantics or an error.
	 */
	private static function normalize_semantics( mixed $raw, array $primitives ): array|WP_Error {
		if ( ! is_array( $raw ) || array_is_list( $raw ) ) {
			return self::error(
				'invalid_semantics',
				'semantics',
				__( 'Design-token semantics must be an object.', 'superdav-ai-agent' )
			);
		}

		$raw_colors = self::associative_array( $raw['colors'] ?? null, 'semantics.colors' );
		if ( is_wp_error( $raw_colors ) ) {
			return $raw_colors;
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

		$shadows = self::normalize_simple_semantics(
			$raw['shadows'] ?? [],
			'semantics.shadows',
			[],
			'shadows',
			$primitives
		);
		if ( is_wp_error( $shadows ) ) {
			return $shadows;
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
	 */
	private static function normalize_typography_semantics( mixed $raw, array $primitives ): array|WP_Error {
		$roles = self::associative_array( $raw, 'semantics.typography' );
		if ( is_wp_error( $roles ) ) {
			return $roles;
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
	 * @param list<string>                                     $required   Required role names.
	 * @param string                                           $collection Primitive collection name.
	 * @param array<string,array<string,array<string,string>>> $primitives Normalized primitive maps.
	 * @return array<string,string>|WP_Error Normalized role-to-slug map or an error.
	 */
	private static function normalize_simple_semantics( mixed $raw, string $path, array $required, string $collection, array $primitives ): array|WP_Error {
		$roles = self::associative_array( $raw, $path );
		if ( is_wp_error( $roles ) ) {
			return $roles;
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
	 * @param mixed                            $raw         Raw variation block.
	 * @param array<string,array<string,array<string,string>>> $primitives  Normalized primitive maps.
	 * @param array<string,string>             $base_colors Resolved base semantic colours.
	 * @return array<string,mixed>|WP_Error Normalized variation or an error.
	 */
	private static function normalize_variation( mixed $raw, array $primitives, array $base_colors ): array|WP_Error {
		$variation = self::associative_array( $raw, 'style_variation' );
		if ( is_wp_error( $variation ) ) {
			return $variation;
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
	 * @param string               $role            Semantic role currently resolving.
	 * @param array<string,mixed>  $raw_roles       Raw role-to-reference map.
	 * @param array<string,array<string,string>> $colors Normalized colour primitive map.
	 * @param array<string,string> $resolved        Resolved role-to-primitive map.
	 * @param array<string,bool>   $visiting        Current resolution stack.
	 * @param string               $path            Base contract path.
	 * @param array<string,string> $base_colors     Optional base role map for variations.
	 * @return string|WP_Error Primitive colour slug or an error.
	 */
	private static function resolve_color_reference( string $role, array $raw_roles, array $colors, array &$resolved, array &$visiting, string $path, array $base_colors = [] ): string|WP_Error {
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
			$result = self::resolve_color_reference( $target, $raw_roles, $colors, $resolved, $visiting, $path, $base_colors );
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
	 */
	private static function normalize_governance( mixed $raw, array $hash_source ): array|WP_Error {
		if ( ! is_array( $raw ) || array_is_list( $raw ) ) {
			return self::error(
				'invalid_governance',
				'governance',
				__( 'Design-token governance metadata must be an object.', 'superdav-ai-agent' )
			);
		}
		if ( isset( $raw['kind'] ) || isset( $raw['integrity'] ) ) {
			return self::error(
				'unexpected_value',
				'governance',
				__( 'The compiler derives artifact kind and integrity metadata.', 'superdav-ai-agent' )
			);
		}
		if ( isset( $raw['provenance'] ) && is_array( $raw['provenance'] ) && array_key_exists( 'input_hash', $raw['provenance'] ) ) {
			return self::error(
				'unexpected_value',
				'governance.provenance.input_hash',
				__( 'The compiler derives the governance input hash from the normalized contract.', 'superdav-ai-agent' )
			);
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

		$provenance = $raw['provenance'] ?? null;
		if ( ! is_array( $provenance ) ) {
			return self::error(
				'invalid_governance',
				'governance.provenance',
				__( 'Design-token governance requires a provenance object.', 'superdav-ai-agent' )
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

		return [
			'id'            => $artifact['id'],
			'version'       => $artifact['version'],
			'maturity'      => $artifact['maturity'],
			'provenance'    => $artifact['provenance'],
			'compatibility' => $artifact['compatibility'],
			'deprecation'   => $artifact['deprecation'],
		];
	}

	/**
	 * Validate and normalize an arbitrary CSS-safe primitive value.
	 *
	 * @param mixed  $value       Raw primitive value.
	 * @param string $path        Contract path.
	 * @param bool   $require_size Whether a CSS length expression is required.
	 * @return string|WP_Error Normalized value or an error.
	 */
	private static function normalize_css_value( mixed $value, string $path, bool $require_size ): string|WP_Error {
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
		if ( $require_size && ! self::is_size_value( $value ) ) {
			return self::error(
				'invalid_value',
				$path,
				__( 'Size primitives must use a supported CSS length or clamp/calc expression.', 'superdav-ai-agent' )
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

		return $value;
	}

	/**
	 * Order required roles first and optional roles deterministically afterwards.
	 *
	 * @param array<string,mixed> $roles    Raw roles.
	 * @param list<string>        $required Required role order.
	 * @return list<string> Ordered role names.
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
		return 1 === preg_match(
			'/^(?:0|-?(?:\d+(?:\.\d+)?|\.\d+)(?:px|rem|em|%|vw|vh|ch|ex)|(?:clamp|min|max|calc)\([^{};]+\))$/',
			$value
		);
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
