<?php

declare(strict_types=1);
/**
 * Read-only abilities for code-owned landing-page pattern selection.
 *
 * @package SdAiAgent\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Services\LandingPagePatternCatalog;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes bounded, deterministic landing-page pattern catalog reads.
 */
final class LandingPagePatternAbilities {

	/**
	 * Register the catalog list and selector abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::register_ability(
			'sd-ai-agent/list-landing-page-pattern-families',
			__( 'List Landing-page Pattern Families', 'superdav-ai-agent' ),
			__( 'List the bounded code-owned landing-page pattern families and structural variants. Each definition contains core-block-only, responsive, accessibility, contraindication, and governed metadata. This ability never creates pages, blocks, copy, media, files, posts, options, or memory.', 'superdav-ai-agent' ),
			[
				'type'                 => 'object',
				'properties'           => [],
				'additionalProperties' => false,
			],
			'handle_list'
		);
		self::register_ability(
			'sd-ai-agent/select-landing-page-pattern-family',
			__( 'Select Landing-page Pattern Family', 'superdav-ai-agent' ),
			__( 'Select one landing-page pattern family and structural variant from a site brief. Explicit primary goal outranks site type, required content, layout notes, and section requests. Missing required business content rejects the candidate and requests clarification instead of fabricating copy, media, statistics, testimonials, blocks, or persisted state.', 'superdav-ai-agent' ),
			self::selection_input_schema(),
			'handle_select'
		);
		self::register_ability(
			'sd-ai-agent/submit-page-visual-review',
			__( 'Submit Page Visual Review', 'superdav-ai-agent' ),
			__( 'Submit the Setup Assistant\'s screenshot-based visual critique after deterministic page validation. Scores hierarchy, composition, spacing, typography, imagery, coherence, and content credibility against the current mutation token. This ability records no WordPress state; the agent loop independently verifies the token, score floor, and absence of blocking findings.', 'superdav-ai-agent' ),
			self::visual_review_input_schema(),
			'handle_visual_review'
		);
	}

	/**
	 * List the complete static catalog without WordPress mutation.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function handle_list( array $input ): array|WP_Error {
		$families = LandingPagePatternCatalog::get_families();
		if ( is_wp_error( $families ) ) {
			return $families;
		}

		return [
			'catalog_version' => LandingPagePatternCatalog::get_catalog_version(),
			'families'        => $families,
			'total'           => count( $families ),
		];
	}

	/**
	 * Select one safe family or return an evidence-backed clarification request.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function handle_select( array $input ): array|WP_Error {
		return LandingPagePatternCatalog::select_family( $input );
	}

	/**
	 * Normalize one screenshot-based visual critique for completion-gate review.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	public static function handle_visual_review( array $input ): array {
		$score_keys = [ 'hierarchy', 'composition', 'spacing', 'typography', 'imagery', 'coherence', 'content_credibility' ];
		$scores     = [];
		$raw_scores = is_array( $input['scores'] ?? null ) ? $input['scores'] : [];
		foreach ( $score_keys as $key ) {
			$scores[ $key ] = max( 0, min( 100, (int) ( $raw_scores[ $key ] ?? 0 ) ) );
		}

		$findings = [];
		if ( is_array( $input['blocking_findings'] ?? null ) ) {
			foreach ( $input['blocking_findings'] as $finding ) {
				if ( is_string( $finding ) && '' !== trim( $finding ) ) {
					$findings[] = sanitize_text_field( $finding );
				}
			}
		}

		return [
			'quality_token'     => sanitize_text_field( (string) ( $input['quality_token'] ?? '' ) ),
			'passed'            => true === ( $input['passed'] ?? false ),
			'overall_score'     => max( 0, min( 100, (int) ( $input['overall_score'] ?? 0 ) ) ),
			'scores'            => $scores,
			'blocking_findings' => $findings,
			'summary'           => sanitize_textarea_field( (string) ( $input['summary'] ?? '' ) ),
		];
	}

	/**
	 * Register one public, idempotent, read-only ability.
	 *
	 * @param string              $id Ability ID.
	 * @param string              $label Human-readable label.
	 * @param string              $description Ability description.
	 * @param array<string,mixed> $input_schema Input schema.
	 * @param string              $callback Execute callback method.
	 */
	private static function register_ability( string $id, string $label, string $description, array $input_schema, string $callback ): void {
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
					return ToolCapabilities::current_user_can( $id )
						&& current_user_can( 'edit_theme_options' );
				},
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Return the bounded site-brief selector input schema.
	 *
	 * @return array<string,mixed>
	 */
	private static function selection_input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'site_brief'        => [
					'type'                 => 'object',
					'description'          => 'Confirmed site brief. Use the site-specification fields siteName, siteType, primaryGoal, audience, tone, brandKeywords, and any only-known business facts.',
					'additionalProperties' => true,
				],
				'available_content' => [
					'type'                 => [ 'object', 'array' ],
					'description'          => 'Known business content only, as an object whose non-empty values or a list whose values use catalog keys such as site_name, offer, cta_destination, product, booking_method, location_or_contact, portfolio_items, inquiry_method, mission, donation_or_volunteer_path, publication_or_topic, and subscription_method. Do not mark inferred or fabricated content as available.',
					'additionalProperties' => true,
					'items'                => [ 'type' => 'string' ],
					'maxItems'             => 24,
				],
				'layout_notes'      => [
					'type'        => 'array',
					'items'       => [
						'type'      => 'string',
						'maxLength' => 500,
					],
					'maxItems'    => 24,
					'description' => 'Known layout notes from the site brief. These break ties after goal, site type, and required content.',
				],
				'section_requests'  => [
					'type'        => 'array',
					'items'       => [
						'type'      => 'string',
						'maxLength' => 500,
					],
					'maxItems'    => 24,
					'description' => 'Explicit user section requests. These only choose between compatible structural variants and never create content.',
				],
			],
			'required'             => [],
			'additionalProperties' => false,
		];
	}

	/** Return the strict screenshot-based visual-review schema. */
	private static function visual_review_input_schema(): array {
		$score_properties = [];
		foreach ( [ 'hierarchy', 'composition', 'spacing', 'typography', 'imagery', 'coherence', 'content_credibility' ] as $key ) {
			$score_properties[ $key ] = [
				'type'        => 'integer',
				'minimum'     => 0,
				'maximum'     => 100,
				'description' => 'Blind screenshot-review score from 0 to 100.',
			];
		}

		return [
			'type'                 => 'object',
			'properties'           => [
				'quality_token'     => [ 'type' => 'string' ],
				'passed'            => [ 'type' => 'boolean' ],
				'overall_score'     => [
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 100,
				],
				'scores'            => [
					'type'                 => 'object',
					'properties'           => $score_properties,
					'required'             => array_keys( $score_properties ),
					'additionalProperties' => false,
				],
				'blocking_findings' => [
					'type'     => 'array',
					'items'    => [
						'type'      => 'string',
						'maxLength' => 500,
					],
					'maxItems' => 20,
				],
				'summary'           => [
					'type'      => 'string',
					'maxLength' => 2000,
				],
			],
			'required'             => [ 'quality_token', 'passed', 'overall_score', 'scores', 'blocking_findings', 'summary' ],
			'additionalProperties' => false,
		];
	}
}
