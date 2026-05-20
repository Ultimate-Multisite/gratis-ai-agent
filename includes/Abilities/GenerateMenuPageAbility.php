<?php

declare(strict_types=1);
/**
 * Generate Menu Page ability — creates a structured hospitality menu page
 * from categorised item/price data and publishes it at /menu/.
 *
 * The ability accepts structured menu data (categories → items → prices with
 * optional descriptions, allergens, and dietary tags), generates a Gutenberg
 * block layout via {@see \SdAiAgent\Core\MenuPageBuilder}, and publishes the
 * result as a WordPress page with the slug "menu" so it is reachable at
 * /menu/.
 *
 * If a page with post_name "menu" already exists it is updated in place.
 * The ability is idempotent: re-running the theme-builder interview will
 * regenerate the page content rather than creating a duplicate.
 *
 * @package SdAiAgent\Abilities
 * @since   1.7.0
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Core\MenuPageBuilder;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate Menu Page ability.
 *
 * @since 1.7.0
 */
class GenerateMenuPageAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Generate Menu Page', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __(
			'Create a structured hospitality menu page at /menu/ from categorised items and prices. Accepts menu categories with items (name, price, optional description, allergens, dietary tags). Publishes as a WordPress page with slug "menu". Idempotent: re-running updates the existing page.',
			'superdav-ai-agent'
		);
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'title'      => [
					'type'        => 'string',
					'description' => 'Page title displayed to visitors (default: "Menu").',
				],
				'pdf_url'    => [
					'type'        => 'string',
					'description' => 'Optional absolute URL of a PDF menu file already in the media library. When supplied a PDF download block is prepended above the categorised list.',
				],
				'categories' => [
					'type'        => 'array',
					'description' => 'Ordered list of menu categories.',
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'name'  => [
								'type'        => 'string',
								'description' => 'Category heading (e.g. "Espresso Drinks").',
							],
							'items' => [
								'type'        => 'array',
								'description' => 'Items in this category.',
								'items'       => [
									'type'       => 'object',
									'properties' => [
										'name'         => [
											'type'        => 'string',
											'description' => 'Item name (e.g. "Flat White").',
										],
										'price'        => [
											'type'        => 'string',
											'description' => 'Formatted price string including currency symbol (e.g. "£3.50", "$4.00", "€3.00"). Use the locale of the WordPress site (get_locale()).',
										],
										'description'  => [
											'type'        => 'string',
											'description' => 'Optional 1–2 line item description.',
										],
										'allergens'    => [
											'type'        => 'string',
											'description' => 'Optional free-text allergen note (e.g. "Contains milk, soya.").',
										],
										'dietary_tags' => [
											'type'        => 'array',
											'description' => 'Optional list of dietary abbreviation tags. Recognised values: V (Vegetarian), VG or VE (Vegan), GF (Gluten-Free), DF (Dairy-Free), H (Halal), K (Kosher), SP (Spicy).',
											'items'       => [ 'type' => 'string' ],
										],
									],
									'required'   => [ 'name', 'price' ],
								],
							],
						],
						'required'   => [ 'name', 'items' ],
					],
				],
			],
			'required'   => [ 'categories' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'          => [ 'type' => 'integer' ],
				'permalink'        => [ 'type' => 'string' ],
				'action'           => [ 'type' => 'string' ],
				'categories_count' => [ 'type' => 'integer' ],
				'items_count'      => [ 'type' => 'integer' ],
				'block_content'    => [ 'type' => 'string' ],
				'error'            => [ 'type' => 'string' ],
			],
		];
	}

	/**
	 * Execute the generate-menu-page ability.
	 *
	 * @param mixed $input The validated input data containing categories, optional title, and optional pdf_url.
	 * @return array<string,mixed>|WP_Error Result with post_id, permalink, and summary counts, or WP_Error on failure.
	 */
	protected function execute_callback( $input ) {
		/** @var array<string,mixed> $input */
		$input = is_array( $input ) ? $input : [];

		$categories = isset( $input['categories'] ) && is_array( $input['categories'] )
			? $input['categories']
			: [];

		$title = isset( $input['title'] ) && '' !== trim( (string) $input['title'] )
			? trim( (string) $input['title'] )
			: __( 'Menu', 'superdav-ai-agent' );

		$pdf_url = isset( $input['pdf_url'] ) ? (string) $input['pdf_url'] : '';

		$menu_data = [
			'categories' => $categories,
		];
		if ( '' !== $pdf_url ) {
			$menu_data['pdf_url'] = $pdf_url;
		}

		// Validate before generating markup.
		$validation = MenuPageBuilder::validate( $menu_data );
		if ( true !== $validation ) {
			return new WP_Error(
				'sd_ai_agent_invalid_menu_data',
				(string) $validation
			);
		}

		// Generate the block markup.
		$block_content = MenuPageBuilder::build_menu_page_content( $menu_data );

		// Upsert the page: update the existing /menu/ page or create a new one.
		$existing_page = get_page_by_path( 'menu', OBJECT, 'page' );
		$action        = 'created';

		if ( $existing_page instanceof \WP_Post ) {
			$post_id = wp_update_post(
				[
					'ID'           => $existing_page->ID,
					'post_title'   => $title,
					'post_content' => $block_content,
					'post_status'  => 'publish',
					'post_name'    => 'menu',
				],
				true
			);
			$action  = 'updated';
		} else {
			$post_id = wp_insert_post(
				[
					'post_title'   => $title,
					'post_content' => $block_content,
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_name'    => 'menu',
				],
				true
			);
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Count categories and items for the summary.
		$categories_count = count( $categories );
		$items_count      = 0;
		foreach ( $categories as $cat ) {
			if ( is_array( $cat ) && isset( $cat['items'] ) && is_array( $cat['items'] ) ) {
				$items_count += count( $cat['items'] );
			}
		}

		$permalink = get_permalink( $post_id );

		return [
			'post_id'          => $post_id,
			'permalink'        => $permalink ?: '',
			'action'           => $action,
			'categories_count' => $categories_count,
			'items_count'      => $items_count,
			'block_content'    => $block_content,
		];
	}

	/**
	 * Check whether the current user has permission to publish pages.
	 *
	 * @param mixed $input The input data (unused for permission check).
	 * @return bool True if the current user can publish_pages.
	 */
	protected function permission_callback( $input ): bool {
		return current_user_can( 'publish_pages' );
	}

	protected function meta(): array {
		return [
			'mcp'         => [ 'public' => true ],
			'annotations' => [
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			],
		];
	}
}
