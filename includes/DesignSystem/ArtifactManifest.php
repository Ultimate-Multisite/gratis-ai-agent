<?php

declare(strict_types=1);
/**
 * Canonical schema-v1 validation and hashing for generated design artifacts.
 *
 * @package SdAiAgent\DesignSystem
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\DesignSystem;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes the durable registry shared by token, pattern, and variation producers.
 */
final class ArtifactManifest {

	/**
	 * The only manifest schema understood by this release.
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Artifact kinds that can be released by this registry.
	 *
	 * @var list<string>
	 */
	public const KINDS = [ 'token_set', 'pattern', 'style_variation' ];

	/**
	 * Ordered lifecycle values supported by the selector.
	 *
	 * @var list<string>
	 */
	public const MATURITIES = [ 'stable', 'candidate', 'experimental', 'deprecated' ];

	/**
	 * Create the empty registry written with a freshly scaffolded generated theme.
	 *
	 * @param string $stylesheet Generated theme stylesheet.
	 * @return array<string,mixed> Valid schema-v1 manifest.
	 */
	public static function empty_manifest( string $stylesheet ): array {
		return [
			'schema_version' => self::SCHEMA_VERSION,
			'theme'          => [
				'stylesheet' => $stylesheet,
			],
			'artifacts'      => [],
		];
	}

	/**
	 * Complete an artifact's integrity block then validate its canonical form.
	 *
	 * Producer code should use this helper instead of hand-rolling content hashes.
	 *
	 * @param array<string,mixed> $artifact Raw producer artifact.
	 * @return array<string,mixed>|WP_Error Normalized artifact or a validation error.
	 */
	public static function create_artifact( array $artifact ): array|WP_Error {
		if ( ! array_key_exists( 'payload', $artifact ) ) {
			return self::error( 'missing_payload', __( 'A generated design artifact must include a payload.', 'superdav-ai-agent' ) );
		}
		if ( ! isset( $artifact['kind'] ) || ! is_string( $artifact['kind'] ) || ! in_array( $artifact['kind'], self::KINDS, true ) || ! is_array( $artifact['payload'] ) ) {
			return self::normalize_artifact( $artifact );
		}

		$payload = self::normalize_payload( $artifact['payload'] );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$artifact['payload'] = $payload;

		$hash = self::hash_payload( $artifact['payload'] );
		if ( is_wp_error( $hash ) ) {
			return $hash;
		}

		if ( ! isset( $artifact['integrity'] ) || ! is_array( $artifact['integrity'] ) ) {
			$artifact['integrity'] = [];
		}

		$artifact['integrity']['content_hash'] = $hash;

		return self::normalize_artifact( $artifact );
	}

	/**
	 * Parse a schema-v1 manifest while preserving additive producer fields.
	 *
	 * Unknown manifest versions deliberately fail instead of being interpreted as
	 * a compatible v1 document. Additive artifact fields remain intact because
	 * their semantics are not required by the v1 resolver.
	 *
	 * @param array<string,mixed> $manifest Raw registry document.
	 * @return array<string,mixed>|WP_Error Normalized registry or an error.
	 */
	public static function normalize( array $manifest ): array|WP_Error {
		$version = $manifest['schema_version'] ?? null;
		if ( self::SCHEMA_VERSION !== $version ) {
			return self::error(
				'unsupported_schema_version',
				__( 'This design-artifact manifest requires a newer plugin version.', 'superdav-ai-agent' )
			);
		}

		$artifacts = $manifest['artifacts'] ?? null;
		if ( ! is_array( $artifacts ) || ! array_is_list( $artifacts ) ) {
			return self::error( 'invalid_artifacts', __( 'Manifest artifacts must be a list.', 'superdav-ai-agent' ) );
		}

		$normalized_artifacts = [];
		$seen                 = [];
		foreach ( $artifacts as $artifact ) {
			if ( ! is_array( $artifact ) ) {
				return self::error( 'invalid_artifact', __( 'Each design artifact must be an object.', 'superdav-ai-agent' ) );
			}

			$normalized = self::normalize_artifact( $artifact );
			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}

			$key = self::artifact_key( $normalized );
			if ( isset( $seen[ $key ] ) ) {
				return self::error(
					'duplicate_artifact',
					/* translators: %s: artifact ID and version. */
					sprintf( __( 'The manifest contains duplicate artifact %s.', 'superdav-ai-agent' ), $key )
				);
			}

			$seen[ $key ]           = true;
			$normalized_artifacts[] = $normalized;
		}

		usort(
			$normalized_artifacts,
			static function ( array $left, array $right ): int {
				$id = strcmp( (string) $left['id'], (string) $right['id'] );
				if ( 0 !== $id ) {
					return $id;
				}

				$version = ArtifactSelector::compare_versions( (string) $left['version'], (string) $right['version'] );
				if ( 0 !== $version ) {
					return $version;
				}

				return strcmp(
					(string) $left['integrity']['content_hash'],
					(string) $right['integrity']['content_hash']
				);
			}
		);

		$normalized              = $manifest;
		$normalized['artifacts'] = $normalized_artifacts;
		$normalized['theme']     = self::normalize_theme( $manifest['theme'] ?? [] );

		if ( is_wp_error( $normalized['theme'] ) ) {
			return $normalized['theme'];
		}

		$baseline_files = self::normalize_baseline_files( $manifest['baseline_files'] ?? [] );
		if ( is_wp_error( $baseline_files ) ) {
			return $baseline_files;
		}
		if ( [] !== $baseline_files || array_key_exists( 'baseline_files', $manifest ) ) {
			$normalized['baseline_files'] = $baseline_files;
		}

		return $normalized;
	}

	/**
	 * Normalize known scaffold files that may be safely adopted by the first release.
	 *
	 * These entries are created only by the block-theme scaffold. They are not
	 * producer artifacts and cannot be used to claim arbitrary user-authored
	 * theme files.
	 *
	 * @param mixed $files Raw baseline file list.
	 * @return list<array{path:string,content:string,content_hash:string}>|WP_Error Normalized files or an error.
	 */
	public static function normalize_baseline_files( mixed $files ): array|WP_Error {
		if ( null === $files || [] === $files ) {
			return [];
		}
		if ( ! is_array( $files ) || ! array_is_list( $files ) ) {
			return self::error( 'invalid_baseline_files', __( 'Generated theme baseline files must be a list.', 'superdav-ai-agent' ) );
		}

		$normalized = [];
		$seen       = [];
		foreach ( $files as $file ) {
			$path         = is_array( $file ) ? ( $file['path'] ?? null ) : null;
			$content      = is_array( $file ) ? ( $file['content'] ?? null ) : null;
			$content_hash = is_array( $file ) ? ( $file['content_hash'] ?? null ) : null;
			if ( 'theme.json' !== $path || ! is_string( $content ) || ! is_string( $content_hash ) || ! self::is_sha256( $content_hash ) || ! hash_equals( hash( 'sha256', $content ), strtolower( $content_hash ) ) ) {
				return self::error( 'invalid_baseline_file', __( 'Generated theme baseline files must be verified theme.json content.', 'superdav-ai-agent' ) );
			}
			if ( isset( $seen[ $path ] ) ) {
				return self::error( 'duplicate_baseline_file', __( 'Generated theme baseline files must not repeat a path.', 'superdav-ai-agent' ) );
			}
			$seen[ $path ] = true;
			$normalized[]  = [
				'path'         => $path,
				'content'      => $content,
				'content_hash' => strtolower( $content_hash ),
			];
		}

		return $normalized;
	}

	/**
	 * Normalize one versioned artifact record.
	 *
	 * @param array<string,mixed> $artifact Raw artifact record.
	 * @return array<string,mixed>|WP_Error Normalized artifact or a validation error.
	 */
	public static function normalize_artifact( array $artifact ): array|WP_Error {
		$id      = $artifact['id'] ?? null;
		$kind    = $artifact['kind'] ?? null;
		$version = $artifact['version'] ?? null;

		if ( ! is_string( $id ) || ! self::is_valid_id( $id ) ) {
			return self::error( 'invalid_id', __( 'Artifact IDs must be stable sd-ai-agent/{kind}/{name} identifiers.', 'superdav-ai-agent' ) );
		}

		if ( ! is_string( $kind ) || ! in_array( $kind, self::KINDS, true ) ) {
			return self::error( 'invalid_kind', __( 'Artifact kind must be token_set, pattern, or style_variation.', 'superdav-ai-agent' ) );
		}

		if ( ! str_starts_with( $id, 'sd-ai-agent/' . $kind . '/' ) ) {
			return self::error( 'id_kind_mismatch', __( 'Artifact ID namespace must match its declared kind.', 'superdav-ai-agent' ) );
		}

		if ( ! is_string( $version ) || ! self::is_valid_semver( $version ) ) {
			return self::error( 'invalid_version', __( 'Artifact version must use numeric Semantic Versioning.', 'superdav-ai-agent' ) );
		}

		$maturity = $artifact['maturity'] ?? null;
		if ( ! is_string( $maturity ) || ! in_array( $maturity, self::MATURITIES, true ) ) {
			return self::error( 'invalid_maturity', __( 'Artifact maturity must be stable, candidate, experimental, or deprecated.', 'superdav-ai-agent' ) );
		}

		$provenance = self::normalize_provenance( $artifact['provenance'] ?? null );
		if ( is_wp_error( $provenance ) ) {
			return $provenance;
		}

		$compatibility = self::normalize_compatibility( $artifact['compatibility'] ?? null );
		if ( is_wp_error( $compatibility ) ) {
			return $compatibility;
		}

		$deprecation = self::normalize_deprecation( $artifact['deprecation'] ?? null, $maturity );
		if ( is_wp_error( $deprecation ) ) {
			return $deprecation;
		}

		if ( ! array_key_exists( 'payload', $artifact ) || ! is_array( $artifact['payload'] ) ) {
			return self::error( 'invalid_payload', __( 'Artifact payload must be an object.', 'superdav-ai-agent' ) );
		}

		$payload = self::normalize_payload( $artifact['payload'] );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$integrity = $artifact['integrity'] ?? null;
		if ( ! is_array( $integrity ) || ! isset( $integrity['content_hash'] ) || ! is_string( $integrity['content_hash'] ) ) {
			return self::error( 'missing_integrity', __( 'Artifact integrity must include a SHA-256 content hash.', 'superdav-ai-agent' ) );
		}

		$hash = self::hash_payload( $payload );
		if ( is_wp_error( $hash ) ) {
			return $hash;
		}

		if ( ! hash_equals( $hash, strtolower( $integrity['content_hash'] ) ) ) {
			return self::error( 'integrity_mismatch', __( 'Artifact content does not match its declared integrity hash.', 'superdav-ai-agent' ) );
		}

		$normalized                  = $artifact;
		$normalized['id']            = $id;
		$normalized['kind']          = $kind;
		$normalized['version']       = $version;
		$normalized['maturity']      = $maturity;
		$normalized['provenance']    = $provenance;
		$normalized['compatibility'] = $compatibility;
		$normalized['deprecation']   = $deprecation;
		$normalized['payload']       = $payload;
		$normalized['integrity']     = [ 'content_hash' => $hash ];

		return $normalized;
	}

	/**
	 * Return a deterministic logical key for an artifact version.
	 *
	 * @param array<string,mixed> $artifact Normalized artifact.
	 */
	public static function artifact_key( array $artifact ): string {
		return (string) $artifact['id'] . '@' . (string) $artifact['version'];
	}

	/**
	 * Return a SHA-256 hash of canonical producer payload content.
	 *
	 * @param mixed $payload Artifact payload.
	 * @return string|WP_Error Hash or a canonicalization error.
	 */
	public static function hash_payload( mixed $payload ): string|WP_Error {
		$json = self::canonical_json( $payload );
		if ( is_wp_error( $json ) ) {
			return $json;
		}

		return hash( 'sha256', $json );
	}

	/**
	 * Encode any JSON-safe value with recursive key ordering.
	 *
	 * @param mixed $value Value to encode.
	 * @return string|WP_Error Canonical JSON or an error.
	 */
	public static function canonical_json( mixed $value ): string|WP_Error {
		$canonical = self::canonicalize( $value );
		if ( is_wp_error( $canonical ) ) {
			return $canonical;
		}

		$json = wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return self::error( 'canonical_json_failed', __( 'Artifact content cannot be encoded as JSON.', 'superdav-ai-agent' ) );
		}

		return $json;
	}

	/**
	 * Validate numeric Semantic Versioning, including valid prerelease precedence parts.
	 */
	public static function is_valid_semver( string $version ): bool {
		return 1 === preg_match(
			'/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/',
			$version
		);
	}

	/**
	 * Check the namespace and path-safe identifier shape.
	 */
	private static function is_valid_id( string $id ): bool {
		return 1 === preg_match( '/^sd-ai-agent\/(?:token_set|pattern|style_variation)\/[a-z0-9][a-z0-9._-]*$/', $id );
	}

	/**
	 * Normalize the optional theme ownership descriptor.
	 *
	 * @param mixed $theme Raw theme descriptor.
	 * @return array<string,string>|WP_Error Normalized theme data or an error.
	 */
	private static function normalize_theme( mixed $theme ): array|WP_Error {
		if ( [] === $theme ) {
			return [];
		}

		if ( ! is_array( $theme ) ) {
			return self::error( 'invalid_theme', __( 'Manifest theme must be an object.', 'superdav-ai-agent' ) );
		}

		$stylesheet = $theme['stylesheet'] ?? null;
		if ( ! is_string( $stylesheet ) || '' === $stylesheet || 1 !== preg_match( '/^[a-z0-9][a-z0-9-]*$/', $stylesheet ) ) {
			return self::error( 'invalid_stylesheet', __( 'Manifest stylesheet must be a safe theme slug.', 'superdav-ai-agent' ) );
		}

		return [ 'stylesheet' => $stylesheet ];
	}

	/**
	 * Normalize provenance values which explain reproducibility and origin.
	 *
	 * @param mixed $provenance Raw provenance block.
	 * @return array<string,string>|WP_Error Normalized provenance or an error.
	 */
	private static function normalize_provenance( mixed $provenance ): array|WP_Error {
		if ( ! is_array( $provenance ) ) {
			return self::error( 'invalid_provenance', __( 'Artifact provenance must be an object.', 'superdav-ai-agent' ) );
		}

		$generator_version = $provenance['generator_version'] ?? null;
		$source_type       = $provenance['source_type'] ?? null;
		$source_reference  = $provenance['source_reference'] ?? null;
		$generated_at      = $provenance['generated_at'] ?? null;
		$input_hash        = $provenance['input_hash'] ?? null;

		if ( ! is_string( $generator_version ) || ! self::is_valid_semver( $generator_version ) ) {
			return self::error( 'invalid_generator_version', __( 'Artifact provenance must include a Semantic Versioning generator version.', 'superdav-ai-agent' ) );
		}

		if ( ! is_string( $source_type ) || '' === trim( $source_type ) || ! is_string( $source_reference ) || '' === trim( $source_reference ) ) {
			return self::error( 'invalid_source', __( 'Artifact provenance must include source type and source reference.', 'superdav-ai-agent' ) );
		}

		if ( ! is_string( $generated_at ) || ! self::is_rfc3339_timestamp( $generated_at ) ) {
			return self::error( 'invalid_generated_at', __( 'Artifact provenance timestamp must be RFC 3339.', 'superdav-ai-agent' ) );
		}

		if ( ! is_string( $input_hash ) || ! self::is_sha256( $input_hash ) ) {
			return self::error( 'invalid_input_hash', __( 'Artifact provenance input hash must be SHA-256.', 'superdav-ai-agent' ) );
		}

		return [
			'generator_version' => $generator_version,
			'source_type'       => trim( $source_type ),
			'source_reference'  => trim( $source_reference ),
			'generated_at'      => $generated_at,
			'input_hash'        => strtolower( $input_hash ),
		];
	}

	/**
	 * Normalize compatibility constraints using inclusive ranges.
	 *
	 * @param mixed $compatibility Raw compatibility block.
	 * @return array<string,mixed>|WP_Error Normalized constraints or an error.
	 */
	private static function normalize_compatibility( mixed $compatibility ): array|WP_Error {
		if ( ! is_array( $compatibility ) ) {
			return self::error( 'invalid_compatibility', __( 'Artifact compatibility must be an object.', 'superdav-ai-agent' ) );
		}

		$wordpress  = $compatibility['wordpress'] ?? null;
		$theme_json = $compatibility['theme_json'] ?? null;
		if ( ! is_array( $wordpress ) || ! is_array( $theme_json ) ) {
			return self::error( 'missing_compatibility_range', __( 'Artifact compatibility requires WordPress and theme.json ranges.', 'superdav-ai-agent' ) );
		}

		$wordpress_min = $wordpress['min'] ?? null;
		$wordpress_max = $wordpress['max'] ?? null;
		$theme_min     = $theme_json['min'] ?? null;
		$theme_max     = $theme_json['max'] ?? null;

		if ( ! is_string( $wordpress_min ) || ! self::is_wp_version( $wordpress_min ) || ( null !== $wordpress_max && ( ! is_string( $wordpress_max ) || ! self::is_wp_version( $wordpress_max ) ) ) ) {
			return self::error( 'invalid_wordpress_range', __( 'Artifact compatibility must include a valid WordPress version range.', 'superdav-ai-agent' ) );
		}

		if ( null !== $wordpress_max && version_compare( $wordpress_min, $wordpress_max, '>' ) ) {
			return self::error( 'reversed_wordpress_range', __( 'Artifact WordPress minimum cannot exceed its maximum.', 'superdav-ai-agent' ) );
		}

		if ( ! is_int( $theme_min ) || $theme_min < 1 || ( null !== $theme_max && ( ! is_int( $theme_max ) || $theme_max < $theme_min ) ) ) {
			return self::error( 'invalid_theme_json_range', __( 'Artifact compatibility must include a valid theme.json version range.', 'superdav-ai-agent' ) );
		}

		$required_blocks   = self::string_list( $compatibility['required_blocks'] ?? [] );
		$required_features = self::string_list( $compatibility['required_features'] ?? [] );
		$theme_constraints = self::string_list( $compatibility['theme_constraints'] ?? [] );
		if ( is_wp_error( $required_blocks ) || is_wp_error( $required_features ) || is_wp_error( $theme_constraints ) ) {
			return self::error( 'invalid_compatibility_lists', __( 'Compatibility block, feature, and theme constraints must be string lists.', 'superdav-ai-agent' ) );
		}

		return [
			'wordpress'         => [
				'min' => $wordpress_min,
				'max' => $wordpress_max,
			],
			'theme_json'        => [
				'min' => $theme_min,
				'max' => $theme_max,
			],
			'required_blocks'   => $required_blocks,
			'required_features' => $required_features,
			'theme_constraints' => $theme_constraints,
		];
	}

	/**
	 * Validate lifecycle retirement metadata only when a record is deprecated.
	 *
	 * @param mixed  $deprecation Raw deprecation block.
	 * @param string $maturity    Artifact maturity.
	 * @return array<string,string>|null|WP_Error Normalized metadata or an error.
	 */
	private static function normalize_deprecation( mixed $deprecation, string $maturity ): array|null|WP_Error {
		if ( 'deprecated' !== $maturity && null === $deprecation ) {
			return null;
		}

		if ( ! is_array( $deprecation ) ) {
			return self::error( 'missing_deprecation', __( 'Deprecated artifacts must include replacement and removal metadata.', 'superdav-ai-agent' ) );
		}

		$reason         = $deprecation['reason'] ?? null;
		$replacement    = $deprecation['replacement'] ?? null;
		$removal_target = $deprecation['removal_target'] ?? null;
		if ( ! is_string( $reason ) || '' === trim( $reason ) || ! is_string( $replacement ) || '' === trim( $replacement ) || ! is_string( $removal_target ) || '' === trim( $removal_target ) ) {
			return self::error( 'invalid_deprecation', __( 'Deprecation metadata requires reason, replacement, and removal target.', 'superdav-ai-agent' ) );
		}

		return [
			'reason'         => trim( $reason ),
			'replacement'    => trim( $replacement ),
			'removal_target' => trim( $removal_target ),
		];
	}

	/**
	 * Normalize payload lists while rejecting unrecognized executable targets later.
	 *
	 * @param array<string,mixed> $payload Raw payload.
	 * @return array<string,mixed>|WP_Error Normalized payload or an error.
	 */
	private static function normalize_payload( array $payload ): array|WP_Error {
		$files   = $payload['files'] ?? [];
		$records = $payload['records'] ?? [];
		if ( ! is_array( $files ) || ! array_is_list( $files ) || ! is_array( $records ) || ! array_is_list( $records ) ) {
			return self::error( 'invalid_payload_targets', __( 'Artifact payload files and records must be lists.', 'superdav-ai-agent' ) );
		}

		$normalized_files = [];
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) || ! isset( $file['path'], $file['content'] ) || ! is_string( $file['path'] ) || ! is_string( $file['content'] ) ) {
				return self::error( 'invalid_payload_file', __( 'Artifact payload file entries require path and content strings.', 'superdav-ai-agent' ) );
			}
			$normalized_files[] = [
				'path'    => ltrim( $file['path'], '/' ),
				'content' => $file['content'],
			];
		}

		$normalized_records = [];
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || ! isset( $record['id'], $record['post_type'], $record['post_content'] ) || ! is_string( $record['id'] ) || ! is_string( $record['post_type'] ) || ! is_string( $record['post_content'] ) ) {
				return self::error( 'invalid_payload_record', __( 'Artifact payload records require id, post type, and post content.', 'superdav-ai-agent' ) );
			}
			$normalized_records[] = [
				'id'           => $record['id'],
				'post_type'    => $record['post_type'],
				'post_title'   => isset( $record['post_title'] ) ? (string) $record['post_title'] : '',
				'post_excerpt' => isset( $record['post_excerpt'] ) ? (string) $record['post_excerpt'] : '',
				'post_name'    => isset( $record['post_name'] ) ? (string) $record['post_name'] : '',
				'post_status'  => isset( $record['post_status'] ) ? (string) $record['post_status'] : 'publish',
				'post_content' => $record['post_content'],
			];
		}

		$normalized            = $payload;
		$normalized['files']   = $normalized_files;
		$normalized['records'] = $normalized_records;

		return $normalized;
	}

	/**
	 * Convert a decoded list into a deterministic, duplicate-free string list.
	 *
	 * @param mixed $value Raw list.
	 * @return list<string>|WP_Error Normalized list or an error.
	 */
	private static function string_list( mixed $value ): array|WP_Error {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return self::error( 'invalid_string_list', __( 'Expected a list of strings.', 'superdav-ai-agent' ) );
		}

		$result = [];
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || '' === trim( $item ) ) {
				return self::error( 'invalid_string_list_item', __( 'Expected a non-empty string list item.', 'superdav-ai-agent' ) );
			}
			$result[] = trim( $item );
		}

		$result = array_values( array_unique( $result ) );
		sort( $result, SORT_STRING );

		return $result;
	}

	/**
	 * Recursively normalize associative key ordering without altering list order.
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed|WP_Error Canonical value or an error.
	 */
	private static function canonicalize( mixed $value ): mixed {
		if ( is_object( $value ) || is_resource( $value ) ) {
			return self::error( 'non_json_value', __( 'Artifact payload contains a non-JSON value.', 'superdav-ai-agent' ) );
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		$result = [];
		foreach ( $value as $key => $item ) {
			$normalized = self::canonicalize( $item );
			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}
			$result[ $key ] = $normalized;
		}

		if ( ! array_is_list( $result ) ) {
			ksort( $result, SORT_STRING );
		}

		return $result;
	}

	/**
	 * Check RFC 3339 timestamps without silently accepting free-form dates.
	 */
	private static function is_rfc3339_timestamp( string $timestamp ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $timestamp ) ) {
			return false;
		}

		return false !== strtotime( $timestamp );
	}

	/**
	 * Check a SHA-256 digest shape.
	 */
	private static function is_sha256( string $hash ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/i', $hash );
	}

	/**
	 * Check WordPress's numeric version format used in inclusive compatibility ranges.
	 */
	private static function is_wp_version( string $version ): bool {
		return 1 === preg_match( '/^\d+(?:\.\d+){1,2}(?:-[0-9A-Za-z.-]+)?$/', $version );
	}

	/**
	 * Build a consistently namespaced manifest validation error.
	 */
	private static function error( string $reason, string $message ): WP_Error {
		return new WP_Error( 'sd_ai_agent_design_artifact_' . $reason, $message );
	}
}
