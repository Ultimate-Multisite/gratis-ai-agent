<?php

declare(strict_types=1);
/**
 * Public abilities for governed generated design artifacts.
 *
 * @package SdAiAgent\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\DesignSystem\ArtifactReleaseManager;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes separate read, resolve, apply, and exact rollback operations.
 */
final class DesignSystemArtifactAbilities {

	/**
	 * Register every generated-design-artifact ability.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::register_ability(
			'sd-ai-agent/list-design-artifacts',
			__( 'List Generated Design Artifacts', 'superdav-ai-agent' ),
			__( 'List the schema-v1 generated design-artifact registry, retained releases, and current active release. This reads only manager-owned hidden release metadata.', 'superdav-ai-agent' ),
			self::theme_input_schema(),
			'handle_list',
			true,
			false
		);
		self::register_ability(
			'sd-ai-agent/inspect-design-artifact',
			__( 'Inspect Generated Design Artifact', 'superdav-ai-agent' ),
			__( 'Inspect one immutable token set, block pattern, or style variation version with provenance, compatibility, maturity, deprecation, and integrity metadata.', 'superdav-ai-agent' ),
			[
				'type'       => 'object',
				'properties' => array_merge(
					self::theme_properties(),
					[
						'id'      => [
							'type'        => 'string',
							'description' => 'Stable artifact ID, for example sd-ai-agent/pattern/hero.',
						],
						'version' => [
							'type'        => 'string',
							'description' => 'Optional exact Semantic Versioning value.',
						],
					]
				),
				'required'   => [ 'id' ],
			],
			'handle_inspect',
			true,
			false
		);
		self::register_ability(
			'sd-ai-agent/resolve-design-artifacts',
			__( 'Resolve Generated Design Artifacts', 'superdav-ai-agent' ),
			__( 'Resolve the saved generated design-artifact registry for the current site without writing. The decision trace records incompatibility, pins, maturity opt-ins, major-version protection, and deterministic tie breaks.', 'superdav-ai-agent' ),
			[
				'type'       => 'object',
				'properties' => array_merge(
					self::theme_properties(),
					[
						'context' => [
							'type'        => 'object',
							'description' => 'Optional compatibility and explicit-selection context, including pins, candidate opt-in, per-artifact experimental opt-ins, and major-upgrade authorization.',
						],
					]
				),
				'required'   => [],
			],
			'handle_resolve',
			true,
			false
		);
		self::register_ability(
			'sd-ai-agent/apply-design-artifact-release',
			__( 'Apply Generated Design Artifact Release', 'superdav-ai-agent' ),
			__( 'Stage, validate, and atomically apply a schema-v1 generated design-artifact manifest. Only declared token, pattern, and style-variation targets are materialized; ordinary user patterns and customizations are not imported or rewritten.', 'superdav-ai-agent' ),
			[
				'type'       => 'object',
				'properties' => array_merge(
					self::theme_properties(),
					[
						'manifest' => [
							'type'        => 'object',
							'description' => 'Complete schema-v1 manifest. Every artifact must include provenance, compatibility, maturity, deprecation metadata when applicable, and a canonical payload integrity hash.',
						],
						'context'  => [
							'type'        => 'object',
							'description' => 'Optional explicit pins and maturity/major-upgrade opt-ins used by the deterministic resolver.',
						],
					]
				),
				'required'   => [ 'manifest' ],
			],
			'handle_apply',
			false,
			true
		);
		self::register_ability(
			'sd-ai-agent/rollback-design-artifact-release',
			__( 'Rollback Generated Design Artifact Release', 'superdav-ai-agent' ),
			__( 'Restore one exact retained generated design-artifact release after verifying its immutable content hashes. This is destructive because it replaces the active manager-owned generated release.', 'superdav-ai-agent' ),
			[
				'type'       => 'object',
				'properties' => array_merge(
					self::theme_properties(),
					[
						'release_id' => [
							'type'        => 'string',
							'description' => 'Exact retained release ID returned by list-design-artifacts or apply-design-artifact-release.',
						],
					]
				),
				'required'   => [ 'release_id' ],
			],
			'handle_rollback',
			false,
			true
		);
	}

	/**
	 * List manifest and release metadata.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error Result or an error.
	 */
	public static function handle_list( array $input ): array|WP_Error {
		$theme = self::resolve_theme( $input );
		if ( is_wp_error( $theme ) ) {
			return $theme;
		}

		return ( new ArtifactReleaseManager() )->list( $theme['directory'], $theme['stylesheet'] );
	}

	/**
	 * Inspect a logical artifact version.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error Result or an error.
	 */
	public static function handle_inspect( array $input ): array|WP_Error {
		$theme = self::resolve_theme( $input );
		if ( is_wp_error( $theme ) ) {
			return $theme;
		}
		$id      = isset( $input['id'] ) ? (string) $input['id'] : '';
		$version = isset( $input['version'] ) && '' !== (string) $input['version'] ? (string) $input['version'] : null;
		if ( '' === $id ) {
			return new WP_Error( 'sd_ai_agent_design_artifact_missing_id', __( 'Artifact ID is required.', 'superdav-ai-agent' ) );
		}

		return ( new ArtifactReleaseManager() )->inspect( $theme['directory'], $id, $version, $theme['stylesheet'] );
	}

	/**
	 * Resolve the persisted registry without changing it.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error Result or an error.
	 */
	public static function handle_resolve( array $input ): array|WP_Error {
		$theme = self::resolve_theme( $input );
		if ( is_wp_error( $theme ) ) {
			return $theme;
		}
		$context = isset( $input['context'] ) && is_array( $input['context'] ) ? $input['context'] : [];

		return ( new ArtifactReleaseManager() )->resolve( $theme['directory'], $context, $theme['stylesheet'] );
	}

	/**
	 * Apply a governed manifest transaction.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error Result or an error.
	 */
	public static function handle_apply( array $input ): array|WP_Error {
		$theme = self::resolve_theme( $input );
		if ( is_wp_error( $theme ) ) {
			return $theme;
		}
		$manifest = $input['manifest'] ?? null;
		if ( ! is_array( $manifest ) ) {
			return new WP_Error( 'sd_ai_agent_design_artifact_missing_manifest', __( 'A schema-v1 artifact manifest is required.', 'superdav-ai-agent' ) );
		}
		$context          = isset( $input['context'] ) && is_array( $input['context'] ) ? $input['context'] : [];
		$context['theme'] = $theme['stylesheet'];

		return ( new ArtifactReleaseManager() )->apply( $theme['directory'], $manifest, $context );
	}

	/**
	 * Roll back by retained immutable release ID.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error Result or an error.
	 */
	public static function handle_rollback( array $input ): array|WP_Error {
		$theme = self::resolve_theme( $input );
		if ( is_wp_error( $theme ) ) {
			return $theme;
		}
		$release_id = isset( $input['release_id'] ) ? (string) $input['release_id'] : '';
		if ( '' === $release_id ) {
			return new WP_Error( 'sd_ai_agent_design_artifact_missing_release', __( 'Release ID is required.', 'superdav-ai-agent' ) );
		}

		return ( new ArtifactReleaseManager() )->rollback( $theme['directory'], $release_id );
	}

	/**
	 * Register one ability with an explicit capability and mutation annotation.
	 *
	 * @param string              $id          Ability ID.
	 * @param string              $label       Ability label.
	 * @param string              $description Ability description.
	 * @param array<string,mixed> $input_schema Input schema.
	 * @param string              $callback    Execute callback method.
	 * @param bool                $is_readonly Whether the ability is read-only.
	 * @param bool                $destructive Whether the ability changes state.
	 */
	private static function register_ability( string $id, string $label, string $description, array $input_schema, string $callback, bool $is_readonly, bool $destructive ): void {
		wp_register_ability(
			$id,
			[
				'label'               => $label,
				'description'         => $description,
				'category'            => 'sd-ai-agent',
				'input_schema'        => $input_schema,
				'output_schema'       => [
					'type'                 => 'object',
					'additionalProperties' => true,
				],
				'execute_callback'    => [ __CLASS__, $callback ],
				'permission_callback' => static function () use ( $id ) {
					return ToolCapabilities::current_user_can( $id );
				},
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => $is_readonly,
						'destructive' => $destructive,
						'idempotent'  => $is_readonly,
					],
				],
			]
		);
	}

	/**
	 * Return shared safe-theme input schema.
	 *
	 * @return array<string,mixed> Input schema.
	 */
	private static function theme_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => self::theme_properties(),
			'required'   => [],
		];
	}

	/**
	 * Return a shared theme selector property, never a caller-supplied filesystem path.
	 *
	 * @return array<string,mixed> Property schema.
	 */
	private static function theme_properties(): array {
		return [
			'theme' => [
				'type'        => 'string',
				'description' => 'Optional installed theme stylesheet slug. Defaults to the active theme. Filesystem paths are not accepted.',
			],
		];
	}

	/**
	 * Resolve only a verified installed theme root and safe stylesheet slug.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array{directory:string,stylesheet:string}|WP_Error Theme location or error.
	 */
	private static function resolve_theme( array $input ): array|WP_Error {
		$stylesheet = isset( $input['theme'] ) ? (string) $input['theme'] : ( function_exists( 'get_stylesheet' ) ? (string) get_stylesheet() : '' );
		if ( '' === $stylesheet || 1 !== preg_match( '/^[a-z0-9][a-z0-9-]*$/', $stylesheet ) ) {
			return new WP_Error( 'sd_ai_agent_design_artifact_invalid_theme', __( 'Theme must be an installed stylesheet slug.', 'superdav-ai-agent' ) );
		}

		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'sd_ai_agent_design_artifact_theme_not_found', __( 'The requested theme is not installed.', 'superdav-ai-agent' ) );
		}
		$directory = $theme->get_stylesheet_directory();
		if ( '' === $directory || ! is_dir( $directory ) ) {
			return new WP_Error( 'sd_ai_agent_design_artifact_theme_directory_missing', __( 'The requested theme directory is unavailable.', 'superdav-ai-agent' ) );
		}

		return [
			'directory'  => $directory,
			'stylesheet' => $stylesheet,
		];
	}
}
