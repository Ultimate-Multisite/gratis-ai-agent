<?php

declare(strict_types=1);
/**
 * Custom taxonomy abilities for the AI agent.
 *
 * Provides abilities to register, list, and delete custom taxonomies
 * with persistence via WordPress options (stored in the database so
 * taxonomies survive page reloads and are re-registered on every init).
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom taxonomy abilities.
 *
 * Persists taxonomy definitions in the `sd_ai_agent_custom_taxonomies` option
 * (an array keyed by taxonomy slug) and re-registers them on every `init`
 * hook so they survive page reloads.
 *
 * @since 1.3.4
 */
class CustomTaxonomyAbilities {

	/**
	 * WordPress option key used to persist taxonomy definitions.
	 */
	const OPTION_KEY = 'sd_ai_agent_custom_taxonomies';

	/**
	 * Re-register all taxonomies that were previously persisted via the register ability.
	 *
	 * Runs on `init` with priority 5 (before most plugins) so the taxonomies are
	 * available to the rest of WordPress on every request.
	 */
	public static function restore_persisted_taxonomies(): void {
		$stored = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return;
		}

		foreach ( $stored as $taxonomy => $entry ) {
			if ( ! is_string( $taxonomy ) || '' === $taxonomy || taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			if ( ! is_array( $entry ) || empty( $entry['object_type'] ) || ! is_array( $entry['object_type'] ) ) {
				continue;
			}
			$object_type = $entry['object_type'];
			$args        = $entry['args'] ?? [];
			if ( ! is_array( $args ) ) {
				continue;
			}
			// @phpstan-ignore-next-line
			register_taxonomy( $taxonomy, $object_type, $args );
		}
	}

	/**
	 * Register all custom taxonomy abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/register-taxonomy',
			[
				'label'               => __( 'Register Custom Taxonomy', 'superdav-ai-agent' ),
				'description'         => __( 'Register a new custom taxonomy and persist it in the database so it survives page reloads. Supports labels, public visibility, REST API support, hierarchical settings, and association with one or more post types.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'taxonomy'     => [
							'type'        => 'string',
							'description' => 'The taxonomy slug (max 32 characters, lowercase letters, numbers, and hyphens only). Example: "genre", "location", "product-tag".',
						],
						'object_type'  => [
							'type'        => 'array',
							'description' => 'One or more post type slugs to associate this taxonomy with (e.g. ["post", "page", "portfolio"]). Use an empty array to register without association.',
							'items'       => [ 'type' => 'string' ],
						],
						'singular'     => [
							'type'        => 'string',
							'description' => 'Singular label for the taxonomy (e.g. "Genre").',
						],
						'plural'       => [
							'type'        => 'string',
							'description' => 'Plural label for the taxonomy (e.g. "Genres").',
						],
						'public'       => [
							'type'        => 'boolean',
							'description' => 'Whether the taxonomy is publicly accessible (default: true).',
						],
						'show_in_rest' => [
							'type'        => 'boolean',
							'description' => 'Whether to expose the taxonomy in the REST API / block editor (default: true).',
						],
						'hierarchical' => [
							'type'        => 'boolean',
							'description' => 'Whether the taxonomy is hierarchical like categories (default: false — tag-like).',
						],
						'description'  => [
							'type'        => 'string',
							'description' => 'A short descriptive summary of what the taxonomy is.',
						],
					],
					'required'   => [ 'taxonomy', 'object_type', 'singular', 'plural' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'taxonomy'     => [ 'type' => 'string' ],
						'object_type'  => [ 'type' => 'array' ],
						'singular'     => [ 'type' => 'string' ],
						'plural'       => [ 'type' => 'string' ],
						'public'       => [ 'type' => 'boolean' ],
						'show_in_rest' => [ 'type' => 'boolean' ],
						'hierarchical' => [ 'type' => 'boolean' ],
						'persisted'    => [ 'type' => 'boolean' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_register_taxonomy' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'manage_options' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/list-taxonomies',
			[
				'label'               => __( 'List Custom Taxonomies', 'superdav-ai-agent' ),
				'description'         => __( 'List all registered taxonomies, including those persisted by the AI agent. Returns slug, labels, public status, associated post types, and whether the taxonomy was registered by the AI agent.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'include_builtin' => [
							'type'        => 'boolean',
							'description' => 'Whether to include built-in WordPress taxonomies (category, post_tag, etc.). Default: false.',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'taxonomies' => [ 'type' => 'array' ],
						'total'      => [ 'type' => 'integer' ],
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
				'execute_callback'    => [ __CLASS__, 'handle_list_taxonomies' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/delete-taxonomy',
			[
				'label'               => __( 'Delete Custom Taxonomy', 'superdav-ai-agent' ),
				'description'         => __( 'Remove a custom taxonomy that was registered by the AI agent. This unregisters the taxonomy and removes it from the database so it will not be re-registered on future page loads. Only AI-registered taxonomies can be deleted via this ability.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'taxonomy' => [
							'type'        => 'string',
							'description' => 'The taxonomy slug to delete.',
						],
					],
					'required'   => [ 'taxonomy' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'taxonomy' => [ 'type' => 'string' ],
						'deleted'  => [ 'type' => 'boolean' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_delete_taxonomy' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'manage_options' );
				},
			]
		);
	}

	/**
	 * Handle the register-taxonomy ability.
	 *
	 * @param array<string, mixed> $input Input with taxonomy, object_type, singular, plural, and optional settings.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_register_taxonomy( array $input ) {
		$taxonomy     = sanitize_key( $input['taxonomy'] ?? '' );
		$singular     = sanitize_text_field( $input['singular'] ?? '' );
		$plural       = sanitize_text_field( $input['plural'] ?? '' );
		$public       = isset( $input['public'] ) ? (bool) $input['public'] : true;
		$show_in_rest = isset( $input['show_in_rest'] ) ? (bool) $input['show_in_rest'] : true;
		$hierarchical = isset( $input['hierarchical'] ) ? (bool) $input['hierarchical'] : false;
		$description  = sanitize_text_field( $input['description'] ?? '' );

		$object_type = isset( $input['object_type'] ) && is_array( $input['object_type'] )
			? array_map(
				static function ( $item ): string {
					return sanitize_key( (string) $item );
				},
				$input['object_type']
			)
			: [];

		if ( empty( $taxonomy ) ) {
			return new WP_Error( 'sd_ai_agent_empty_taxonomy', __( 'taxonomy is required.', 'superdav-ai-agent' ) );
		}

		if ( strlen( $taxonomy ) > 32 ) {
			return new WP_Error(
				'sd_ai_agent_taxonomy_too_long',
				__( 'taxonomy slug must be 32 characters or fewer.', 'superdav-ai-agent' )
			);
		}

		if ( empty( $singular ) || empty( $plural ) ) {
			return new WP_Error( 'sd_ai_agent_empty_labels', __( 'singular and plural labels are required.', 'superdav-ai-agent' ) );
		}

		// Prevent overwriting built-in taxonomies.
		$builtin_taxonomies = [ 'category', 'post_tag', 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' ];
		if ( in_array( $taxonomy, $builtin_taxonomies, true ) ) {
			return new WP_Error(
				'sd_ai_agent_builtin_taxonomy',
				/* translators: %s: taxonomy slug */
				sprintf( __( '"%s" is a built-in WordPress taxonomy and cannot be overwritten.', 'superdav-ai-agent' ), $taxonomy )
			);
		}

		$labels = [
			'name'                  => $plural,
			'singular_name'         => $singular,
			/* translators: %s: singular label */
			'search_items'          => sprintf( __( 'Search %s', 'superdav-ai-agent' ), $plural ),
			/* translators: %s: plural label */
			'all_items'             => sprintf( __( 'All %s', 'superdav-ai-agent' ), $plural ),
			/* translators: %s: singular label */
			'edit_item'             => sprintf( __( 'Edit %s', 'superdav-ai-agent' ), $singular ),
			/* translators: %s: singular label */
			'view_item'             => sprintf( __( 'View %s', 'superdav-ai-agent' ), $singular ),
			/* translators: %s: singular label */
			'update_item'           => sprintf( __( 'Update %s', 'superdav-ai-agent' ), $singular ),
			/* translators: %s: singular label */
			'add_new_item'          => sprintf( __( 'Add New %s', 'superdav-ai-agent' ), $singular ),
			/* translators: %s: singular label */
			'new_item_name'         => sprintf( __( 'New %s Name', 'superdav-ai-agent' ), $singular ),
			'menu_name'             => $plural,
			/* translators: %s: plural label */
			'not_found'             => sprintf( __( 'No %s found.', 'superdav-ai-agent' ), strtolower( $plural ) ),
			/* translators: %s: plural label */
			'no_terms'              => sprintf( __( 'No %s', 'superdav-ai-agent' ), strtolower( $plural ) ),
			/* translators: %s: plural label */
			'items_list'            => sprintf( __( '%s list', 'superdav-ai-agent' ), $plural ),
			/* translators: %s: plural label */
			'items_list_navigation' => sprintf( __( '%s list navigation', 'superdav-ai-agent' ), $plural ),
		];

		$args = [
			'labels'       => $labels,
			'description'  => $description,
			'public'       => $public,
			'show_in_rest' => $show_in_rest,
			'hierarchical' => $hierarchical,
			'rewrite'      => [ 'slug' => $taxonomy ],
		];

		// @phpstan-ignore-next-line
		$result = register_taxonomy( $taxonomy, $object_type, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Persist the taxonomy definition so it survives page reloads.
		$stored              = get_option( self::OPTION_KEY, [] );
		$stored[ $taxonomy ] = [
			'object_type' => $object_type,
			'args'        => $args,
		];
		update_option( self::OPTION_KEY, $stored, false );

		return [
			'taxonomy'     => $taxonomy,
			'object_type'  => $object_type,
			'singular'     => $singular,
			'plural'       => $plural,
			'public'       => $public,
			'show_in_rest' => $show_in_rest,
			'hierarchical' => $hierarchical,
			'persisted'    => true,
		];
	}

	/**
	 * Handle the list-taxonomies ability.
	 *
	 * @param array<string, mixed> $input Input with optional include_builtin flag.
	 * @return array<string, mixed>
	 */
	public static function handle_list_taxonomies( array $input ) {
		$include_builtin = (bool) ( $input['include_builtin'] ?? false );

		$builtin_taxonomies = [ 'category', 'post_tag', 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' ];

		$stored_taxonomies = array_keys( (array) get_option( self::OPTION_KEY, [] ) );

		$all_taxonomies = get_taxonomies( [], 'objects' );
		$result         = [];

		foreach ( $all_taxonomies as $taxonomy_obj ) {
			$slug = $taxonomy_obj->name;

			if ( ! $include_builtin && in_array( $slug, $builtin_taxonomies, true ) ) {
				continue;
			}

			$result[] = [
				'slug'           => $slug,
				'label'          => $taxonomy_obj->label,
				'singular_label' => $taxonomy_obj->labels->singular_name ?? $slug,
				'public'         => (bool) $taxonomy_obj->public,
				'show_in_rest'   => (bool) $taxonomy_obj->show_in_rest,
				'hierarchical'   => (bool) $taxonomy_obj->hierarchical,
				'object_type'    => $taxonomy_obj->object_type,
				'ai_registered'  => in_array( $slug, $stored_taxonomies, true ),
			];
		}

		return [
			'taxonomies' => $result,
			'total'      => count( $result ),
		];
	}

	/**
	 * Handle the delete-taxonomy ability.
	 *
	 * @param array<string, mixed> $input Input with taxonomy slug.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_delete_taxonomy( array $input ) {
		$taxonomy = sanitize_key( $input['taxonomy'] ?? '' );

		if ( empty( $taxonomy ) ) {
			return new WP_Error( 'sd_ai_agent_empty_taxonomy', __( 'taxonomy is required.', 'superdav-ai-agent' ) );
		}

		$stored = get_option( self::OPTION_KEY, [] );

		if ( ! is_array( $stored ) || ! array_key_exists( $taxonomy, $stored ) ) {
			return new WP_Error(
				'sd_ai_agent_taxonomy_not_found',
				/* translators: %s: taxonomy slug */
				sprintf( __( 'Taxonomy "%s" was not registered by the AI agent and cannot be deleted via this ability.', 'superdav-ai-agent' ), $taxonomy )
			);
		}

		// Unregister from the current request.
		unregister_taxonomy( $taxonomy );

		// Remove from persistent storage.
		unset( $stored[ $taxonomy ] );
		update_option( self::OPTION_KEY, $stored, false );

		return [
			'taxonomy' => $taxonomy,
			'deleted'  => true,
		];
	}
}
