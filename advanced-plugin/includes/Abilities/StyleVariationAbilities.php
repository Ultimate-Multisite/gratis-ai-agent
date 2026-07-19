<?php

declare(strict_types=1);
/**
 * Explicit WordPress style-variation lifecycle abilities.
 *
 * @package SdAiAgent\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Services\StyleVariationManager;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers separate contracts for every style-variation lifecycle operation.
 */
final class StyleVariationAbilities {

	/**
	 * Register all explicit lifecycle abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::register_ability(
			'sd-ai-agent/list-style-variations',
			__( 'List Style Variations', 'superdav-ai-agent' ),
			__( 'List active stylesheet and inherited parent style variations with origin, hash, read-only, and managed selection state.', 'superdav-ai-agent' ),
			self::empty_input_schema(),
			'handle_list',
			true,
			false,
			true
		);
		self::register_ability(
			'sd-ai-agent/create-style-variation',
			__( 'Create Style Variation', 'superdav-ai-agent' ),
			__( 'Validate and atomically create one complete theme.json v3 style variation in the active stylesheet styles directory. Existing files are never overwritten.', 'superdav-ai-agent' ),
			[
				'type'       => 'object',
				'properties' => [
					'document' => self::document_schema(),
				],
				'required'   => [ 'document' ],
			],
			'handle_create',
			false,
			true,
			false
		);
		self::register_ability(
			'sd-ai-agent/update-style-variation',
			__( 'Update Style Variation', 'superdav-ai-agent' ),
			__( 'Validate and atomically replace one complete active stylesheet style variation. The supplied expected hash is required to prevent stale writes; parent files are read-only.', 'superdav-ai-agent' ),
			[
				'type'       => 'object',
				'properties' => [
					'slug'          => self::slug_schema(),
					'expected_hash' => self::hash_schema(),
					'document'      => self::document_schema(),
				],
				'required'   => [ 'slug', 'expected_hash', 'document' ],
			],
			'handle_update',
			false,
			true,
			false
		);
		self::register_ability(
			'sd-ai-agent/validate-style-variation',
			__( 'Validate Style Variation', 'superdav-ai-agent' ),
			__( 'Validate a supplied complete theme.json v3 style variation or an existing active/parent variation without writing files, options, posts, or selection state.', 'superdav-ai-agent' ),
			self::document_or_slug_schema(),
			'handle_validate',
			true,
			false,
			true
		);
		self::register_ability(
			'sd-ai-agent/preview-style-variation',
			__( 'Preview Style Variation', 'superdav-ai-agent' ),
			__( 'Validate and merge a supplied or existing style variation with the active theme entirely in memory, returning changed theme.json paths and generated CSS without persisting changes.', 'superdav-ai-agent' ),
			self::document_or_slug_schema(),
			'handle_preview',
			true,
			false,
			true
		);
		self::register_ability(
			'sd-ai-agent/select-style-variation',
			__( 'Select Style Variation', 'superdav-ai-agent' ),
			__( 'Apply a validated style variation to active-theme user Global Styles while preserving an exact plugin-owned baseline. Refuses stale source hashes and intervening Site Editor changes.', 'superdav-ai-agent' ),
			[
				'type'       => 'object',
				'properties' => [
					'slug'          => self::slug_schema(),
					'expected_hash' => self::hash_schema(),
				],
				'required'   => [ 'slug', 'expected_hash' ],
			],
			'handle_select',
			false,
			true,
			true
		);
		self::register_ability(
			'sd-ai-agent/reset-style-variation',
			__( 'Reset Style Variation', 'superdav-ai-agent' ),
			__( 'Restore the exact Global Styles baseline saved by select-style-variation only while the selected-state content hash still matches. Never wholesale-resets unrelated customizations.', 'superdav-ai-agent' ),
			self::empty_input_schema(),
			'handle_reset',
			false,
			true,
			false
		);
	}

	/**
	 * List installed active/parent variation metadata.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error Result or an error.
	 */
	public static function handle_list( array $input ): array|WP_Error {
		return ( new StyleVariationManager() )->list();
	}

	/**
	 * Create one variation document.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error Result or an error.
	 */
	public static function handle_create( array $input ): array|WP_Error {
		$document = $input['document'] ?? null;
		if ( ! is_array( $document ) ) {
			return self::document_required_error();
		}

		return ( new StyleVariationManager() )->create( $document );
	}

	/**
	 * Replace one variation document after a hash check.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error Result or an error.
	 */
	public static function handle_update( array $input ): array|WP_Error {
		$document = $input['document'] ?? null;
		if ( ! is_array( $document ) ) {
			return self::document_required_error();
		}

		return ( new StyleVariationManager() )->update(
			isset( $input['slug'] ) ? (string) $input['slug'] : '',
			$document,
			isset( $input['expected_hash'] ) ? (string) $input['expected_hash'] : ''
		);
	}

	/**
	 * Validate a supplied document or existing variation by slug.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error Result or an error.
	 */
	public static function handle_validate( array $input ): array|WP_Error {
		$variation = self::resolve_document( $input );
		if ( is_wp_error( $variation ) ) {
			return $variation;
		}

		return self::validation_output( $variation );
	}

	/**
	 * Preview a supplied document or existing variation by slug.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error Result or an error.
	 */
	public static function handle_preview( array $input ): array|WP_Error {
		$variation = self::resolve_document( $input );
		if ( is_wp_error( $variation ) ) {
			return $variation;
		}

		return ( new StyleVariationManager() )->preview( $variation['document'] );
	}

	/**
	 * Select one variation with source optimistic concurrency.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error Result or an error.
	 */
	public static function handle_select( array $input ): array|WP_Error {
		return ( new StyleVariationManager() )->select(
			isset( $input['slug'] ) ? (string) $input['slug'] : '',
			isset( $input['expected_hash'] ) ? (string) $input['expected_hash'] : ''
		);
	}

	/**
	 * Reset only a verified managed selection state.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error Result or an error.
	 */
	public static function handle_reset( array $input ): array|WP_Error {
		return ( new StyleVariationManager() )->reset();
	}

	/**
	 * Register one explicitly named lifecycle contract.
	 *
	 * @param string              $id             Ability ID.
	 * @param string              $label          Ability label.
	 * @param string              $description    Ability description.
	 * @param array<string,mixed> $input_schema   Input schema.
	 * @param string              $callback       Static callback.
	 * @param bool                $readonly       Whether no state changes occur.
	 * @param bool                $destructive    Whether state/files are changed.
	 * @param bool                $idempotent     Whether identical safe input is a no-op.
	 */
	private static function register_ability( string $id, string $label, string $description, array $input_schema, string $callback, bool $readonly, bool $destructive, bool $idempotent ): void {
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
				'permission_callback' => static function () use ( $id ): bool {
					return ToolCapabilities::current_user_can( $id ) && current_user_can( 'edit_theme_options' );
				},
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => $readonly,
						'destructive' => $destructive,
						'idempotent'  => $idempotent,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Resolve a supplied document first, otherwise one existing slug.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error Validated document or an error.
	 */
	private static function resolve_document( array $input ): array|WP_Error {
		$manager = new StyleVariationManager();
		if ( isset( $input['document'] ) ) {
			if ( ! is_array( $input['document'] ) ) {
				return self::document_required_error();
			}

			return $manager->validate_document( $input['document'] );
		}
		if ( isset( $input['slug'] ) ) {
			return $manager->validate_existing( (string) $input['slug'] );
		}

		return new WP_Error(
			'sd_ai_agent_style_variation_document_or_slug_required',
			__( 'Provide either a complete style variation document or an existing variation slug.', 'superdav-ai-agent' )
		);
	}

	/**
	 * Return the public validation response without echoing an entire document.
	 *
	 * @param array<string,mixed> $variation Validated document details.
	 * @return array<string,mixed> Safe validation response.
	 */
	private static function validation_output( array $variation ): array {
		$output = [
			'valid' => true,
			'slug'  => $variation['slug'],
			'title' => $variation['title'],
			'hash'  => $variation['hash'],
		];
		foreach ( [ 'origin', 'relative_path', 'read_only' ] as $field ) {
			if ( array_key_exists( $field, $variation ) ) {
				$output[ $field ] = $variation[ $field ];
			}
		}

		return $output;
	}

	/**
	 * Return a standard missing-document error.
	 */
	private static function document_required_error(): WP_Error {
		return new WP_Error(
			'sd_ai_agent_style_variation_document_required',
			__( 'A complete style variation document is required.', 'superdav-ai-agent' )
		);
	}

	/**
	 * Return a schema for an empty object input.
	 *
	 * @return array<string,mixed> Input schema.
	 */
	private static function empty_input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [],
			'additionalProperties' => false,
		];
	}

	/**
	 * Return a schema accepting one document or one existing slug.
	 *
	 * Runtime validation enforces that at least one is supplied because the
	 * WordPress ability schema normalizer does not consistently preserve oneOf.
	 *
	 * @return array<string,mixed> Input schema.
	 */
	private static function document_or_slug_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'document' => self::document_schema(),
				'slug'     => self::slug_schema(),
			],
			'required'   => [],
		];
	}

	/**
	 * Return a schema for a complete theme.json document.
	 *
	 * @return array<string,mixed> Document schema.
	 */
	private static function document_schema(): array {
		return [
			'type'        => 'object',
			'description' => 'Complete WordPress theme.json v3 style variation document with $schema, version, slug, title, settings, and styles.',
		];
	}

	/**
	 * Return a schema for conservative variation slugs.
	 *
	 * @return array<string,mixed> Slug schema.
	 */
	private static function slug_schema(): array {
		return [
			'type'        => 'string',
			'pattern'     => '^[a-z0-9][a-z0-9-]*$',
			'description' => 'Style variation slug from the active stylesheet or inherited parent.',
		];
	}

	/**
	 * Return a schema for canonical SHA-256 optimistic concurrency hashes.
	 *
	 * @return array<string,mixed> Hash schema.
	 */
	private static function hash_schema(): array {
		return [
			'type'        => 'string',
			'pattern'     => '^[a-f0-9]{64}$',
			'description' => 'Canonical SHA-256 hash returned by list-style-variations or validate-style-variation.',
		];
	}
}
