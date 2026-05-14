<?php

declare(strict_types=1);
/**
 * Custom post type abilities for the AI agent.
 *
 * Provides abilities to register, list, and delete custom post types
 * with persistence via WordPress options (stored in the database so
 * CPTs survive page reloads and are re-registered on every init).
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
 * Custom post type abilities.
 *
 * Persists CPT definitions in the `sd_ai_agent_custom_post_types` option
 * (an array keyed by post-type slug) and re-registers them on every `init`
 * hook so they survive page reloads.
 *
 * @since 1.3.3
 */
class CustomPostTypeAbilities {

	/**
	 * WordPress option key used to persist CPT definitions.
	 */
	const OPTION_KEY = 'sd_ai_agent_custom_post_types';

	/**
	 * Re-register all CPTs that were previously persisted via the register ability.
	 *
	 * Runs on `init` with priority 5 (before most plugins) so the CPTs are
	 * available to the rest of WordPress on every request.
	 */
	public static function restore_persisted_post_types(): void {
		$stored = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return;
		}

		foreach ( $stored as $post_type => $args ) {
			if ( ! is_string( $post_type ) || '' === $post_type || post_type_exists( $post_type ) ) {
				continue;
			}
			if ( ! is_array( $args ) ) {
				continue;
			}
			// @phpstan-ignore-next-line
			register_post_type( $post_type, $args );
		}
	}

	/**
	 * Register all custom post type abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/register-post-type',
			[
				'label'               => __( 'Register Custom Post Type', 'superdav-ai-agent' ),
				'description'         => __( 'Register a new custom post type and persist it in the database so it survives page reloads. Supports labels, public visibility, REST API support, menu icon, and hierarchical settings.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_type'    => [
							'type'        => 'string',
							'description' => 'The post type slug (max 20 characters, lowercase letters, numbers, and hyphens only). Example: "portfolio", "event", "product-review".',
						],
						'singular'     => [
							'type'        => 'string',
							'description' => 'Singular label for the post type (e.g. "Portfolio Item").',
						],
						'plural'       => [
							'type'        => 'string',
							'description' => 'Plural label for the post type (e.g. "Portfolio Items").',
						],
						'public'       => [
							'type'        => 'boolean',
							'description' => 'Whether the post type is publicly accessible (default: true).',
						],
						'show_in_rest' => [
							'type'        => 'boolean',
							'description' => 'Whether to expose the post type in the REST API / block editor (default: true).',
						],
						'hierarchical' => [
							'type'        => 'boolean',
							'description' => 'Whether the post type is hierarchical like pages (default: false).',
						],
						'menu_icon'    => [
							'type'        => 'string',
							'description' => 'Dashicons class for the admin menu icon (e.g. "dashicons-portfolio"). Defaults to "dashicons-admin-post".',
						],
						'supports'     => [
							'type'        => 'array',
							'description' => 'Features the post type supports. Defaults to ["title","editor","thumbnail","excerpt","custom-fields"]. Options: "title","editor","author","thumbnail","excerpt","trackbacks","custom-fields","comments","revisions","page-attributes","post-formats".',
							'items'       => [ 'type' => 'string' ],
						],
						'description'  => [
							'type'        => 'string',
							'description' => 'A short descriptive summary of what the post type is.',
						],
					],
					'required'   => [ 'post_type', 'singular', 'plural' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'post_type'    => [ 'type' => 'string' ],
						'singular'     => [ 'type' => 'string' ],
						'plural'       => [ 'type' => 'string' ],
						'public'       => [ 'type' => 'boolean' ],
						'show_in_rest' => [ 'type' => 'boolean' ],
						'hierarchical' => [ 'type' => 'boolean' ],
						'menu_icon'    => [ 'type' => 'string' ],
						'supports'     => [ 'type' => 'array' ],
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
				'execute_callback'    => [ __CLASS__, 'handle_register_post_type' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'manage_options' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/list-post-types',
			[
				'label'               => __( 'List Custom Post Types', 'superdav-ai-agent' ),
				'description'         => __( 'List all registered custom post types, including those persisted by the AI agent. Returns slug, labels, public status, and whether the type was registered by the AI agent.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'include_builtin' => [
							'type'        => 'boolean',
							'description' => 'Whether to include built-in WordPress post types (post, page, attachment, etc.). Default: false.',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'post_types' => [ 'type' => 'array' ],
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
				'execute_callback'    => [ __CLASS__, 'handle_list_post_types' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/delete-post-type',
			[
				'label'               => __( 'Delete Custom Post Type', 'superdav-ai-agent' ),
				'description'         => __( 'Remove a custom post type that was registered by the AI agent. This unregisters the post type and removes it from the database so it will not be re-registered on future page loads. Only AI-registered post types can be deleted via this ability.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_type' => [
							'type'        => 'string',
							'description' => 'The post type slug to delete.',
						],
					],
					'required'   => [ 'post_type' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'post_type' => [ 'type' => 'string' ],
						'deleted'   => [ 'type' => 'boolean' ],
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
				'execute_callback'    => [ __CLASS__, 'handle_delete_post_type' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'manage_options' );
				},
			]
		);
	}

	/**
	 * Handle the register-post-type ability.
	 *
	 * @param array<string, mixed> $input Input with post_type, singular, plural, and optional settings.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_register_post_type( array $input ) {
		$post_type    = sanitize_key( $input['post_type'] ?? '' );
		$singular     = sanitize_text_field( $input['singular'] ?? '' );
		$plural       = sanitize_text_field( $input['plural'] ?? '' );
		$public       = isset( $input['public'] ) ? (bool) $input['public'] : true;
		$show_in_rest = isset( $input['show_in_rest'] ) ? (bool) $input['show_in_rest'] : true;
		$hierarchical = isset( $input['hierarchical'] ) ? (bool) $input['hierarchical'] : false;
		$menu_icon    = sanitize_text_field( $input['menu_icon'] ?? 'dashicons-admin-post' );
		$description  = sanitize_text_field( $input['description'] ?? '' );

		$default_supports = [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ];
		$supports         = isset( $input['supports'] ) && is_array( $input['supports'] )
			? array_map(
				static function ( $item ): string {
					return sanitize_text_field( (string) $item ); },
				$input['supports']
			)
			: $default_supports;

		if ( empty( $post_type ) ) {
			return new WP_Error( 'sd_ai_agent_empty_post_type', __( 'post_type is required.', 'superdav-ai-agent' ) );
		}

		if ( strlen( $post_type ) > 20 ) {
			return new WP_Error(
				'sd_ai_agent_post_type_too_long',
				__( 'post_type slug must be 20 characters or fewer.', 'superdav-ai-agent' )
			);
		}

		if ( empty( $singular ) || empty( $plural ) ) {
			return new WP_Error( 'sd_ai_agent_empty_labels', __( 'singular and plural labels are required.', 'superdav-ai-agent' ) );
		}

		// Prevent overwriting built-in post types.
		$builtin_types = [ 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' ];
		if ( in_array( $post_type, $builtin_types, true ) ) {
			return new WP_Error(
				'sd_ai_agent_builtin_post_type',
				/* translators: %s: post type slug */
				sprintf( __( '"%s" is a built-in WordPress post type and cannot be overwritten.', 'superdav-ai-agent' ), $post_type )
			);
		}

		$labels = [
			'name'               => $plural,
			'singular_name'      => $singular,
			'add_new'            => __( 'Add New', 'superdav-ai-agent' ),
			/* translators: %s: singular label */
			'add_new_item'       => sprintf( __( 'Add New %s', 'superdav-ai-agent' ), $singular ),
			/* translators: %s: singular label */
			'edit_item'          => sprintf( __( 'Edit %s', 'superdav-ai-agent' ), $singular ),
			/* translators: %s: singular label */
			'new_item'           => sprintf( __( 'New %s', 'superdav-ai-agent' ), $singular ),
			/* translators: %s: singular label */
			'view_item'          => sprintf( __( 'View %s', 'superdav-ai-agent' ), $singular ),
			/* translators: %s: plural label */
			'search_items'       => sprintf( __( 'Search %s', 'superdav-ai-agent' ), $plural ),
			/* translators: %s: plural label */
			'not_found'          => sprintf( __( 'No %s found.', 'superdav-ai-agent' ), strtolower( $plural ) ),
			/* translators: %s: plural label */
			'not_found_in_trash' => sprintf( __( 'No %s found in Trash.', 'superdav-ai-agent' ), strtolower( $plural ) ),
			/* translators: %s: plural label */
			'all_items'          => sprintf( __( 'All %s', 'superdav-ai-agent' ), $plural ),
			/* translators: %s: singular label */
			'menu_name'          => $plural,
		];

		$args = [
			'labels'       => $labels,
			'description'  => $description,
			'public'       => $public,
			'show_in_rest' => $show_in_rest,
			'hierarchical' => $hierarchical,
			'menu_icon'    => $menu_icon,
			'supports'     => $supports,
			'rewrite'      => [ 'slug' => $post_type ],
		];

		// @phpstan-ignore-next-line
		$result = register_post_type( $post_type, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Persist the CPT definition so it survives page reloads.
		$stored               = get_option( self::OPTION_KEY, [] );
		$stored[ $post_type ] = $args;
		update_option( self::OPTION_KEY, $stored, false );

		return [
			'post_type'    => $post_type,
			'singular'     => $singular,
			'plural'       => $plural,
			'public'       => $public,
			'show_in_rest' => $show_in_rest,
			'hierarchical' => $hierarchical,
			'menu_icon'    => $menu_icon,
			'supports'     => $supports,
			'persisted'    => true,
		];
	}

	/**
	 * Handle the list-post-types ability.
	 *
	 * @param array<string, mixed> $input Input with optional include_builtin flag.
	 * @return array<string, mixed>
	 */
	public static function handle_list_post_types( array $input ) {
		$include_builtin = (bool) ( $input['include_builtin'] ?? false );

		$builtin_types = [ 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' ];

		$stored_types = array_keys( (array) get_option( self::OPTION_KEY, [] ) );

		$all_post_types = get_post_types( [], 'objects' );
		$result         = [];

		foreach ( $all_post_types as $post_type_obj ) {
			$slug = $post_type_obj->name;

			if ( ! $include_builtin && in_array( $slug, $builtin_types, true ) ) {
				continue;
			}

			$result[] = [
				'slug'           => $slug,
				'label'          => $post_type_obj->label,
				'singular_label' => $post_type_obj->labels->singular_name ?? $slug,
				'public'         => (bool) $post_type_obj->public,
				'show_in_rest'   => (bool) $post_type_obj->show_in_rest,
				'hierarchical'   => (bool) $post_type_obj->hierarchical,
				'menu_icon'      => $post_type_obj->menu_icon,
				'supports'       => array_keys( (array) get_all_post_type_supports( $slug ) ),
				'ai_registered'  => in_array( $slug, $stored_types, true ),
			];
		}

		return [
			'post_types' => $result,
			'total'      => count( $result ),
		];
	}

	/**
	 * Handle the delete-post-type ability.
	 *
	 * @param array<string, mixed> $input Input with post_type slug.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_delete_post_type( array $input ) {
		$post_type = sanitize_key( $input['post_type'] ?? '' );

		if ( empty( $post_type ) ) {
			return new WP_Error( 'sd_ai_agent_empty_post_type', __( 'post_type is required.', 'superdav-ai-agent' ) );
		}

		$stored = get_option( self::OPTION_KEY, [] );

		if ( ! is_array( $stored ) || ! array_key_exists( $post_type, $stored ) ) {
			return new WP_Error(
				'sd_ai_agent_post_type_not_found',
				/* translators: %s: post type slug */
				sprintf( __( 'Post type "%s" was not registered by the AI agent and cannot be deleted via this ability.', 'superdav-ai-agent' ), $post_type )
			);
		}

		// Unregister from the current request.
		unregister_post_type( $post_type );

		// Remove from persistent storage.
		unset( $stored[ $post_type ] );
		update_option( self::OPTION_KEY, $stored, false );

		return [
			'post_type' => $post_type,
			'deleted'   => true,
		];
	}
}
