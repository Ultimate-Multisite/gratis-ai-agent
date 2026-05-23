<?php

declare(strict_types=1);
/**
 * Taxonomy term abilities for the AI agent.
 *
 * Provides abilities to list taxonomy terms for AI-assisted
 * content management workflows.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use WP_Error;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Taxonomy term abilities.
 *
 * Houses the sd-ai-agent/list-terms ability and future term-management
 * operations. Keeps CustomTaxonomyAbilities focused on taxonomy-schema
 * registration; this class handles term-level read operations.
 *
 * @since 1.3.6
 */
class TaxonomyAbilities {

	/**
	 * Maximum allowed per_page value.
	 */
	const MAX_PER_PAGE = 200;

	/**
	 * Allowed orderby values.
	 *
	 * @var string[]
	 */
	const ALLOWED_ORDERBY = [ 'name', 'slug', 'count', 'term_id' ];

	/**
	 * Register all taxonomy term abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/list-terms',
			[
				'label'               => __( 'List Taxonomy Terms', 'superdav-ai-agent' ),
				'description'         => __( 'List terms for a given taxonomy with optional search, pagination, hierarchy, and ordering filters. Use this to discover term IDs before assigning them to posts. Returns term_id, name, slug, count, parent, and description for each term.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'taxonomy'   => [
							'type'        => 'string',
							'description' => 'The taxonomy slug to list terms for (e.g. "category", "post_tag"). Default: "category".',
						],
						'search'     => [
							'type'        => 'string',
							'description' => 'Optional fuzzy-match filter applied to term name.',
						],
						'hide_empty' => [
							'type'        => 'boolean',
							'description' => 'Exclude terms with zero assigned posts. Default: false.',
						],
						'per_page'   => [
							'type'        => 'integer',
							'description' => 'Number of terms per page (1–200). Default: 50.',
						],
						'page'       => [
							'type'        => 'integer',
							'description' => '1-based page number. Default: 1.',
						],
						'parent'     => [
							'type'        => 'integer',
							'description' => 'Restrict to direct children of this term ID. 0 = top-level terms only.',
						],
						'orderby'    => [
							'type'        => 'string',
							'description' => 'Sort field: name, slug, count, term_id. Default: name.',
						],
						'order'      => [
							'type'        => 'string',
							'description' => 'Sort direction: ASC or DESC. Default: ASC.',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'taxonomy' => [ 'type' => 'string' ],
						'total'    => [ 'type' => 'integer' ],
						'page'     => [ 'type' => 'integer' ],
						'per_page' => [ 'type' => 'integer' ],
						'items'    => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'term_id'     => [ 'type' => 'integer' ],
									'name'        => [ 'type' => 'string' ],
									'slug'        => [ 'type' => 'string' ],
									'count'       => [ 'type' => 'integer' ],
									'parent'      => [ 'type' => 'integer' ],
									'description' => [ 'type' => 'string' ],
								],
							],
						],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'   => true,
						'idempotent' => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_list_terms' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_posts' );
				},
			]
		);
	}

	/**
	 * Handle the list-terms ability.
	 *
	 * Returns a paginated list of terms for the requested taxonomy.
	 * Public taxonomies are accessible to any user with edit_posts;
	 * private taxonomies additionally require the taxonomy's manage_terms cap.
	 *
	 * @param array<string, mixed> $input Input with taxonomy, search, hide_empty, per_page, page, parent, orderby, and order.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_list_terms( array $input ) {
		$taxonomy = ( isset( $input['taxonomy'] ) && '' !== (string) $input['taxonomy'] )
			? sanitize_key( (string) $input['taxonomy'] )
			: 'category';

		// Validate taxonomy exists.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error(
				'taxonomy_not_found',
				/* translators: %s: taxonomy slug */
				sprintf( __( 'Taxonomy "%s" does not exist.', 'superdav-ai-agent' ), $taxonomy )
			);
		}

		// Guard private taxonomies: require the taxonomy's manage_terms cap.
		$taxonomy_obj = get_taxonomy( $taxonomy );
		if ( $taxonomy_obj && ! (bool) $taxonomy_obj->public && ! current_user_can( $taxonomy_obj->cap->manage_terms ) ) {
			return new WP_Error(
				'insufficient_capability',
				/* translators: %s: taxonomy slug */
				sprintf( __( 'You do not have permission to list terms for taxonomy "%s".', 'superdav-ai-agent' ), $taxonomy )
			);
		}

		// Validate per_page before querying.
		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 50;
		if ( $per_page > self::MAX_PER_PAGE ) {
			return new WP_Error(
				'per_page_too_large',
				/* translators: %d: maximum per_page value */
				sprintf( __( 'per_page must not exceed %d.', 'superdav-ai-agent' ), self::MAX_PER_PAGE )
			);
		}
		$per_page = max( 1, $per_page );
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		// Validate orderby.
		$orderby = 'name';
		if ( isset( $input['orderby'] ) && in_array( $input['orderby'], self::ALLOWED_ORDERBY, true ) ) {
			$orderby = $input['orderby'];
		}

		// Validate order.
		$order = 'ASC';
		if ( isset( $input['order'] ) && 'DESC' === strtoupper( (string) $input['order'] ) ) {
			$order = 'DESC';
		}

		$hide_empty = isset( $input['hide_empty'] ) ? (bool) $input['hide_empty'] : false;

		// Build base query args shared by both the count and paginated queries.
		$base_args = [
			'taxonomy'   => $taxonomy,
			'hide_empty' => $hide_empty,
			'orderby'    => $orderby,
			'order'      => $order,
		];

		if ( isset( $input['search'] ) && '' !== (string) $input['search'] ) {
			$base_args['search'] = sanitize_text_field( (string) $input['search'] );
		}

		if ( isset( $input['parent'] ) ) {
			$base_args['parent'] = (int) $input['parent'];
		}

		// Count query — omit number/offset so total reflects all matching terms.
		$count_args           = $base_args;
		$count_args['fields'] = 'count';
		$count_result         = get_terms( $count_args );
		$total                = is_wp_error( $count_result ) ? 0 : (int) $count_result;

		// Paginated query.
		$query_args           = $base_args;
		$query_args['number'] = $per_page;
		$query_args['offset'] = $offset;
		$terms                = get_terms( $query_args );

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$items = [];
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			if ( ! ( $term instanceof WP_Term ) ) {
				continue;
			}
			$items[] = [
				'term_id'     => (int) $term->term_id,
				'name'        => (string) $term->name,
				'slug'        => (string) $term->slug,
				'count'       => (int) $term->count,
				'parent'      => (int) $term->parent,
				'description' => (string) $term->description,
			];
		}

		return [
			'taxonomy' => $taxonomy,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'items'    => $items,
		];
	}
}
