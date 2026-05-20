<?php

declare(strict_types=1);
/**
 * Navigation menu management abilities for the AI agent.
 *
 * Provides WordPress nav menu listing, creation, item management, and deletion.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MenuAbilities {

	/**
	 * Register all navigation menu management abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/list-menus',
			[
				'label'               => __( 'List Menus', 'superdav-ai-agent' ),
				'description'         => __( 'List all registered WordPress navigation menus. Returns menu ID, name, slug, and the theme locations it is assigned to.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [],
					'required'   => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'menus' => [ 'type' => 'array' ],
						'total' => [ 'type' => 'integer' ],
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
				'execute_callback'    => [ __CLASS__, 'handle_list_menus' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_theme_options' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/get-menu',
			[
				'label'               => __( 'Get Menu', 'superdav-ai-agent' ),
				'description'         => __( 'Retrieve a WordPress navigation menu by ID or slug, including all its items with labels, URLs, and hierarchy.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'menu_id'   => [
							'type'        => 'integer',
							'description' => 'The term ID of the menu to retrieve.',
						],
						'menu_slug' => [
							'type'        => 'string',
							'description' => 'The slug of the menu to retrieve (alternative to menu_id).',
						],
					],
					'required'   => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'    => [ 'type' => 'integer' ],
						'name'  => [ 'type' => 'string' ],
						'slug'  => [ 'type' => 'string' ],
						'items' => [ 'type' => 'array' ],
						'count' => [ 'type' => 'integer' ],
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
				'execute_callback'    => [ __CLASS__, 'handle_get_menu' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_theme_options' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/create-menu',
			[
				'label'               => __( 'Create Menu', 'superdav-ai-agent' ),
				'description'         => __( 'Create a new WordPress navigation menu with the given name. Optionally assign it to a theme location and populate it with items in one call. Each item may supply a navigation_label to override the linked page\'s title in the rendered nav.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'name'     => [
							'type'        => 'string',
							'description' => 'The display name for the new menu.',
						],
						'location' => [
							'type'        => 'string',
							'description' => 'Optional theme location slug to assign this menu to (e.g. "primary", "footer").',
						],
						'items'    => [
							'type'        => 'array',
							'description' => 'Optional list of items to add to the menu immediately after creation. Items are added in the order provided.',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'page_id'          => [
										'type'        => 'integer',
										'description' => 'ID of a page/post to link. When supplied the item type is set to post_type/page.',
									],
									'url'              => [
										'type'        => 'string',
										'description' => 'URL for a custom-link item (used when page_id is not provided).',
									],
									'navigation_label' => [
										'type'        => 'string',
										'description' => 'The label rendered in the navigation. When omitted the linked page title (or url) is used as the label.',
									],
									'title'            => [
										'type'        => 'string',
										'description' => 'Alias for navigation_label (navigation_label takes precedence when both are supplied).',
									],
								],
							],
						],
					],
					'required'   => [ 'name' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'menu_id'     => [ 'type' => 'integer' ],
						'name'        => [ 'type' => 'string' ],
						'slug'        => [ 'type' => 'string' ],
						'items_added' => [ 'type' => 'integer' ],
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
				'execute_callback'    => [ __CLASS__, 'handle_create_menu' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_theme_options' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/delete-menu',
			[
				'label'               => __( 'Delete Menu', 'superdav-ai-agent' ),
				'description'         => __( 'Delete a WordPress navigation menu and all its items by menu ID or slug.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'menu_id'   => [
							'type'        => 'integer',
							'description' => 'The term ID of the menu to delete.',
						],
						'menu_slug' => [
							'type'        => 'string',
							'description' => 'The slug of the menu to delete (alternative to menu_id).',
						],
					],
					'required'   => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'menu_id' => [ 'type' => 'integer' ],
						'name'    => [ 'type' => 'string' ],
						'deleted' => [ 'type' => 'boolean' ],
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
				'execute_callback'    => [ __CLASS__, 'handle_delete_menu' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_theme_options' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/add-menu-item',
			[
				'label'               => __( 'Add Menu Item', 'superdav-ai-agent' ),
				'description'         => __( 'Add an item to a WordPress navigation menu. Supports custom URLs, pages, posts, categories, and tags.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'menu_id'     => [
							'type'        => 'integer',
							'description' => 'The term ID of the menu to add the item to.',
						],
						'menu_slug'   => [
							'type'        => 'string',
							'description' => 'The slug of the menu (alternative to menu_id).',
						],
						'title'       => [
							'type'        => 'string',
							'description' => 'The display label for the menu item.',
						],
						'url'         => [
							'type'        => 'string',
							'description' => 'The URL for a custom link menu item.',
						],
						'object_type' => [
							'type'        => 'string',
							'description' => 'Type of object: "custom", "post_type", or "taxonomy". Defaults to "custom".',
							'enum'        => [ 'custom', 'post_type', 'taxonomy' ],
						],
						'object'      => [
							'type'        => 'string',
							'description' => 'For post_type: the post type slug (e.g. "page", "post"). For taxonomy: the taxonomy slug (e.g. "category", "post_tag").',
						],
						'object_id'   => [
							'type'        => 'integer',
							'description' => 'For post_type or taxonomy items: the ID of the post, page, or term.',
						],
						'parent_id'   => [
							'type'        => 'integer',
							'description' => 'Menu item ID of the parent item (for nested/dropdown menus).',
						],
						'position'    => [
							'type'        => 'integer',
							'description' => 'Menu order position (1-based). Defaults to last.',
						],
						'attr_title'  => [
							'type'        => 'string',
							'description' => 'Title attribute (tooltip) for the menu item.',
						],
						'css_classes' => [
							'type'        => 'string',
							'description' => 'Space-separated CSS classes to add to the menu item.',
						],
						'target'      => [
							'type'        => 'string',
							'description' => 'Link target attribute (e.g. "_blank" to open in new tab).',
						],
					],
					'required'   => [ 'title' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'item_id' => [ 'type' => 'integer' ],
						'title'   => [ 'type' => 'string' ],
						'url'     => [ 'type' => 'string' ],
						'menu_id' => [ 'type' => 'integer' ],
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
				'execute_callback'    => [ __CLASS__, 'handle_add_menu_item' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_theme_options' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/remove-menu-item',
			[
				'label'               => __( 'Remove Menu Item', 'superdav-ai-agent' ),
				'description'         => __( 'Remove an item from a WordPress navigation menu by its menu item ID.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'item_id' => [
							'type'        => 'integer',
							'description' => 'The post ID of the menu item to remove.',
						],
					],
					'required'   => [ 'item_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'item_id' => [ 'type' => 'integer' ],
						'title'   => [ 'type' => 'string' ],
						'deleted' => [ 'type' => 'boolean' ],
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
				'execute_callback'    => [ __CLASS__, 'handle_remove_menu_item' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_theme_options' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/assign-menu-location',
			[
				'label'               => __( 'Assign Menu to Location', 'superdav-ai-agent' ),
				'description'         => __( 'Assign a WordPress navigation menu to a registered theme location (e.g. "primary", "footer"). Use list-menus to see available menus and their current locations.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'menu_id'   => [
							'type'        => 'integer',
							'description' => 'The term ID of the menu to assign.',
						],
						'menu_slug' => [
							'type'        => 'string',
							'description' => 'The slug of the menu (alternative to menu_id).',
						],
						'location'  => [
							'type'        => 'string',
							'description' => 'The theme location slug to assign the menu to.',
						],
					],
					'required'   => [ 'location' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'menu_id'  => [ 'type' => 'integer' ],
						'name'     => [ 'type' => 'string' ],
						'location' => [ 'type' => 'string' ],
						'assigned' => [ 'type' => 'boolean' ],
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
				'execute_callback'    => [ __CLASS__, 'handle_assign_menu_location' ],
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_theme_options' );
				},
			]
		);
	}

	/**
	 * Handle the list-menus ability.
	 *
	 * @param array<string, mixed> $input Input (unused).
	 * @return array<string, mixed>
	 */
	public static function handle_list_menus( array $input ): array {
		$nav_menus = wp_get_nav_menus();
		$locations = get_nav_menu_locations();

		// Build a reverse map: menu_id => [ location_slugs ].
		$menu_locations = [];
		foreach ( $locations as $location => $menu_id ) {
			if ( ! isset( $menu_locations[ $menu_id ] ) ) {
				$menu_locations[ $menu_id ] = [];
			}
			$menu_locations[ $menu_id ][] = $location;
		}

		$menus = [];
		foreach ( $nav_menus as $menu ) {
			$menus[] = [
				'id'        => $menu->term_id,
				'name'      => $menu->name,
				'slug'      => $menu->slug,
				'count'     => $menu->count,
				'locations' => $menu_locations[ $menu->term_id ] ?? [],
			];
		}

		return [
			'menus' => $menus,
			'total' => count( $menus ),
		];
	}

	/**
	 * Handle the get-menu ability.
	 *
	 * @param array<string, mixed> $input Input with menu_id or menu_slug.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_get_menu( array $input ) {
		$menu = self::resolve_menu( $input );

		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		$menu_items = wp_get_nav_menu_items( $menu->term_id );

		$items = [];
		if ( is_array( $menu_items ) ) {
			foreach ( $menu_items as $item ) {
				if ( ! ( $item instanceof \WP_Post ) ) {
					continue;
				}
				$raw_classes = get_post_meta( $item->ID, '_menu_item_classes', true );
				$classes     = is_array( $raw_classes ) ? implode( ' ', array_filter( $raw_classes, 'is_string' ) ) : '';
				$items[]     = [
					'id'         => $item->ID,
					'title'      => $item->post_title,
					'url'        => (string) get_post_meta( $item->ID, '_menu_item_url', true ),
					'type'       => (string) get_post_meta( $item->ID, '_menu_item_type', true ),
					'object'     => (string) get_post_meta( $item->ID, '_menu_item_object', true ),
					'object_id'  => (int) get_post_meta( $item->ID, '_menu_item_object_id', true ),
					'parent_id'  => (int) get_post_meta( $item->ID, '_menu_item_menu_item_parent', true ),
					'position'   => (int) $item->menu_order,
					'target'     => (string) get_post_meta( $item->ID, '_menu_item_target', true ),
					'classes'    => $classes,
					'attr_title' => (string) get_post_meta( $item->ID, '_menu_item_attr_title', true ),
				];
			}
		}

		return [
			'id'    => $menu->term_id,
			'name'  => $menu->name,
			'slug'  => $menu->slug,
			'items' => $items,
			'count' => count( $items ),
		];
	}

	/**
	 * Handle the create-menu ability.
	 *
	 * @param array<string, mixed> $input Input with name, optional location, and optional items.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_create_menu( array $input ) {
		// @phpstan-ignore-next-line
		$name = sanitize_text_field( $input['name'] ?? '' );
		// @phpstan-ignore-next-line
		$location = sanitize_text_field( $input['location'] ?? '' );
		// @phpstan-ignore-next-line
		$items = isset( $input['items'] ) && is_array( $input['items'] ) ? $input['items'] : [];

		if ( empty( $name ) ) {
			return new WP_Error( 'ai_agent_empty_menu_name', __( 'Menu name is required.', 'superdav-ai-agent' ) );
		}

		$menu_id = wp_create_nav_menu( $name );

		if ( is_wp_error( $menu_id ) ) {
			return $menu_id;
		}

		// Assign to theme location if provided.
		if ( ! empty( $location ) ) {
			$locations              = get_nav_menu_locations();
			$locations[ $location ] = $menu_id;
			set_theme_mod( 'nav_menu_locations', $locations );
		}

		// Add items if provided.
		$items_added = 0;
		foreach ( $items as $position => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			// @phpstan-ignore-next-line
			$page_id = (int) ( $item['page_id'] ?? 0 );
			// @phpstan-ignore-next-line
			$url = esc_url_raw( $item['url'] ?? '' );

			// Resolve label: navigation_label > title > page post_title > url.
			// @phpstan-ignore-next-line
			$navigation_label = sanitize_text_field( $item['navigation_label'] ?? '' );
			if ( '' === $navigation_label ) {
				// @phpstan-ignore-next-line
				$navigation_label = sanitize_text_field( $item['title'] ?? '' );
			}
			if ( '' === $navigation_label && $page_id > 0 ) {
				$page = get_post( $page_id );
				if ( $page instanceof \WP_Post ) {
					$navigation_label = $page->post_title;
				}
			}
			if ( '' === $navigation_label ) {
				$navigation_label = $url;
			}

			if ( '' === $navigation_label ) {
				// Cannot determine a label; skip this item.
				continue;
			}

			$item_data = [
				'menu-item-title'    => $navigation_label,
				'menu-item-position' => $position + 1,
				'menu-item-status'   => 'publish',
			];

			if ( $page_id > 0 ) {
				$item_data['menu-item-object-id'] = $page_id;
				$item_data['menu-item-object']    = 'page';
				$item_data['menu-item-type']      = 'post_type';
			} else {
				$item_data['menu-item-url']  = $url;
				$item_data['menu-item-type'] = 'custom';
			}

			$result = wp_update_nav_menu_item( $menu_id, 0, $item_data );
			if ( ! is_wp_error( $result ) ) {
				++$items_added;
			}
		}

		$menu = wp_get_nav_menu_object( $menu_id );

		return [
			'menu_id'     => $menu_id,
			'name'        => $menu ? $menu->name : $name,
			'slug'        => $menu ? $menu->slug : sanitize_title( $name ),
			'items_added' => $items_added,
		];
	}

	/**
	 * Handle the delete-menu ability.
	 *
	 * @param array<string, mixed> $input Input with menu_id or menu_slug.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_delete_menu( array $input ) {
		$menu = self::resolve_menu( $input );

		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		$menu_id   = $menu->term_id;
		$menu_name = $menu->name;

		$result = wp_delete_nav_menu( $menu_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'menu_id' => $menu_id,
			'name'    => $menu_name,
			'deleted' => (bool) $result,
		];
	}

	/**
	 * Handle the add-menu-item ability.
	 *
	 * @param array<string, mixed> $input Input with menu_id/slug, title, url, etc.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_add_menu_item( array $input ) {
		$menu = self::resolve_menu( $input );

		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		// @phpstan-ignore-next-line
		$title = sanitize_text_field( $input['title'] ?? '' );
		// @phpstan-ignore-next-line
		$url = esc_url_raw( $input['url'] ?? '' );
		// @phpstan-ignore-next-line
		$object_type = sanitize_text_field( $input['object_type'] ?? 'custom' );
		// @phpstan-ignore-next-line
		$object = sanitize_text_field( $input['object'] ?? '' );
		// @phpstan-ignore-next-line
		$object_id = (int) ( $input['object_id'] ?? 0 );
		// @phpstan-ignore-next-line
		$parent_id = (int) ( $input['parent_id'] ?? 0 );
		// @phpstan-ignore-next-line
		$position = (int) ( $input['position'] ?? 0 );
		// @phpstan-ignore-next-line
		$attr_title = sanitize_text_field( $input['attr_title'] ?? '' );
		// @phpstan-ignore-next-line
		$css_classes = sanitize_text_field( $input['css_classes'] ?? '' );
		// @phpstan-ignore-next-line
		$target = sanitize_text_field( $input['target'] ?? '' );

		if ( empty( $title ) ) {
			return new WP_Error( 'ai_agent_empty_item_title', __( 'Menu item title is required.', 'superdav-ai-agent' ) );
		}

		$allowed_types = [ 'custom', 'post_type', 'taxonomy' ];
		if ( ! in_array( $object_type, $allowed_types, true ) ) {
			$object_type = 'custom';
		}

		// Auto-assign the next sequential position when the caller does not specify one.
		// Without this, wp_update_nav_menu_item sets menu_order = 0 for every item,
		// causing WordPress to fall back to alphabetical sorting by post title (GH#1524).
		if ( $position <= 0 ) {
			$existing_items = wp_get_nav_menu_items( $menu->term_id );
			$position       = is_array( $existing_items ) ? count( $existing_items ) + 1 : 1;
		}

		$item_data = [
			'menu-item-title'      => $title,
			'menu-item-url'        => $url,
			'menu-item-type'       => $object_type,
			'menu-item-object'     => $object,
			'menu-item-object-id'  => $object_id,
			'menu-item-parent-id'  => $parent_id,
			'menu-item-position'   => $position,
			'menu-item-attr-title' => $attr_title,
			'menu-item-classes'    => $css_classes,
			'menu-item-target'     => $target,
			'menu-item-status'     => 'publish',
		];

		$item_id = wp_update_nav_menu_item( $menu->term_id, 0, $item_data );

		if ( is_wp_error( $item_id ) ) {
			return $item_id;
		}

		return [
			'item_id' => $item_id,
			'title'   => $title,
			'url'     => $url,
			'menu_id' => $menu->term_id,
		];
	}

	/**
	 * Handle the remove-menu-item ability.
	 *
	 * @param array<string, mixed> $input Input with item_id.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_remove_menu_item( array $input ) {
		// @phpstan-ignore-next-line
		$item_id = (int) ( $input['item_id'] ?? 0 );

		if ( ! $item_id ) {
			return new WP_Error( 'ai_agent_empty_item_id', __( 'item_id is required.', 'superdav-ai-agent' ) );
		}

		$item = get_post( $item_id );

		if ( ! $item || $item->post_type !== 'nav_menu_item' ) {
			return new WP_Error(
				'ai_agent_menu_item_not_found',
				/* translators: %d: menu item ID */
				sprintf( __( 'Menu item %d not found.', 'superdav-ai-agent' ), $item_id )
			);
		}

		$title  = $item->post_title;
		$result = wp_delete_post( $item_id, true );

		return [
			'item_id' => $item_id,
			'title'   => $title,
			'deleted' => (bool) $result,
		];
	}

	/**
	 * Handle the assign-menu-location ability.
	 *
	 * @param array<string, mixed> $input Input with menu_id/slug and location.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_assign_menu_location( array $input ) {
		$menu = self::resolve_menu( $input );

		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		// @phpstan-ignore-next-line
		$location = sanitize_text_field( $input['location'] ?? '' );

		if ( empty( $location ) ) {
			return new WP_Error( 'ai_agent_empty_location', __( 'location is required.', 'superdav-ai-agent' ) );
		}

		$locations              = get_nav_menu_locations();
		$locations[ $location ] = $menu->term_id;
		set_theme_mod( 'nav_menu_locations', $locations );

		return [
			'menu_id'  => $menu->term_id,
			'name'     => $menu->name,
			'location' => $location,
			'assigned' => true,
		];
	}

	/**
	 * Resolve a menu object from input containing menu_id or menu_slug.
	 *
	 * @param array<string, mixed> $input Input array.
	 * @return \WP_Term|WP_Error
	 */
	private static function resolve_menu( array $input ) {
		// @phpstan-ignore-next-line
		$menu_id = (int) ( $input['menu_id'] ?? 0 );
		// @phpstan-ignore-next-line
		$menu_slug = sanitize_text_field( $input['menu_slug'] ?? '' );

		if ( $menu_id > 0 ) {
			$menu = wp_get_nav_menu_object( $menu_id );
		} elseif ( ! empty( $menu_slug ) ) {
			$menu = wp_get_nav_menu_object( $menu_slug );
		} else {
			return new WP_Error( 'ai_agent_missing_menu_identifier', __( 'Provide menu_id or menu_slug.', 'superdav-ai-agent' ) );
		}

		if ( ! $menu || is_wp_error( $menu ) ) {
			return new WP_Error(
				'ai_agent_menu_not_found',
				__( 'Menu not found.', 'superdav-ai-agent' )
			);
		}

		return $menu;
	}
}
