<?php

declare(strict_types=1);
/**
 * Menu Page Builder — generates structured Gutenberg block markup for
 * hospitality menu pages (café, restaurant, bar, food truck, etc.).
 *
 * Converts structured menu data (categories → items → prices) into a
 * serialised block HTML string suitable for `post_content`. The output
 * renders as a categorised price list: each category opens with an h2
 * heading; each item uses a two-column row (name left, price right) with
 * an optional description and optional dietary / allergen badges.
 *
 * Block structure per category:
 *
 *   <!-- wp:heading {"level":2,"className":"sd-ai-agent-menu-category-heading"} -->
 *   <h2 class="wp-block-heading sd-ai-agent-menu-category-heading">Category</h2>
 *   <!-- /wp:heading -->
 *
 *   <!-- wp:group {"className":"sd-ai-agent-menu-category",...} -->
 *     (one sd-ai-agent-menu-item group per item)
 *   <!-- /wp:group -->
 *
 *   <!-- wp:separator -->
 *   <hr .../>
 *   <!-- /wp:separator -->
 *
 * Item row (inside sd-ai-agent-menu-category group):
 *
 *   <!-- wp:group {"className":"sd-ai-agent-menu-item",...} -->
 *     <!-- wp:columns -->
 *       <!-- wp:column -->  name + optional description  <!-- /wp:column -->
 *       <!-- wp:column {"width":"140px"} --> price  <!-- /wp:column -->
 *     <!-- /wp:columns -->
 *     (optional dietary badge paragraph)
 *   <!-- /wp:group -->
 *
 * PDF menu preference: when `pdf_url` is set at the top level the builder
 * prepends a PDF embed block above the categorised list.
 *
 * @package SdAiAgent\Core
 * @since   1.7.0
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds Gutenberg block markup for a structured hospitality menu page.
 *
 * All public methods are static and have no WordPress dependencies so
 * they can be called from tests, abilities, and REST handlers without a
 * full WordPress bootstrap.
 *
 * Expected input shape for build_menu_page_content():
 *
 * ```php
 * [
 *     'pdf_url'    => 'https://example.com/menu.pdf', // optional
 *     'categories' => [
 *         [
 *             'name'  => 'Espresso',
 *             'items' => [
 *                 [
 *                     'name'          => 'Flat White',
 *                     'price'         => '£3.50',
 *                     'description'   => 'Rich espresso with steamed milk.', // optional
 *                     'allergens'     => 'Contains milk.',                   // optional
 *                     'dietary_tags'  => ['V'],                              // optional
 *                 ],
 *             ],
 *         ],
 *     ],
 * ]
 * ```
 *
 * @since 1.7.0
 */
class MenuPageBuilder {

	// ── Dietary tag abbreviation map ──────────────────────────────────────

	/**
	 * Recognised dietary tag abbreviations and their full labels.
	 *
	 * Keys are the abbreviations the interview captures; values are the
	 * human-readable labels shown in the rendered badge.
	 */
	private const DIETARY_TAG_LABELS = [
		'V'   => 'Vegetarian',
		'VG'  => 'Vegan',
		'VE'  => 'Vegan',
		'GF'  => 'Gluten-Free',
		'DF'  => 'Dairy-Free',
		'N'   => 'Contains Nuts',
		'NF'  => 'Nut-Free',
		'H'   => 'Halal',
		'K'   => 'Kosher',
		'SP'  => 'Spicy',
		'RAW' => 'Raw',
	];

	// ── Public API ────────────────────────────────────────────────────────

	/**
	 * Build full Gutenberg block markup for a structured menu page.
	 *
	 * Returns a string of serialised block HTML ready for `post_content`.
	 * Each category becomes an h2 heading followed by a group of item rows.
	 * An optional PDF embed block is prepended when `pdf_url` is supplied.
	 *
	 * @since 1.7.0
	 *
	 * @param array<string,mixed> $menu_data Structured menu data. See class docblock.
	 * @return string Serialised Gutenberg block markup.
	 */
	public static function build_menu_page_content( array $menu_data ): string {
		$parts = [];

		// Optional PDF embed / download link above the categorised list.
		if ( ! empty( $menu_data['pdf_url'] ) ) {
			$parts[] = self::build_pdf_block( (string) $menu_data['pdf_url'] );
		}

		$categories = $menu_data['categories'] ?? [];
		foreach ( $categories as $index => $category ) {
			if ( ! is_array( $category ) || empty( $category['name'] ) ) {
				continue;
			}

			$parts[] = self::build_category_block( $category );

			// Add a separator between categories (not after the last one).
			if ( $index < count( $categories ) - 1 ) {
				$parts[] = self::build_separator_block();
			}
		}

		return implode( "\n\n", array_filter( $parts ) );
	}

	/**
	 * Build the Gutenberg block for one menu category.
	 *
	 * Returns an h2 heading followed by a group containing all item rows.
	 *
	 * @since 1.7.0
	 *
	 * @param array<string,mixed> $category Category data with 'name' and 'items'.
	 * @return string Block markup for the category.
	 */
	public static function build_category_block( array $category ): string {
		$name  = esc_html( (string) ( $category['name'] ?? '' ) );
		$items = $category['items'] ?? [];

		$heading = "<!-- wp:heading {\"level\":2,\"className\":\"sd-ai-agent-menu-category-heading\"} -->\n"
			. "<h2 class=\"wp-block-heading sd-ai-agent-menu-category-heading\">{$name}</h2>\n"
			. '<!-- /wp:heading -->';

		$item_blocks = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['name'] ) ) {
				continue;
			}
			$item_blocks[] = self::build_item_block( $item );
		}

		if ( empty( $item_blocks ) ) {
			return $heading;
		}

		$items_markup = implode( "\n\n", $item_blocks );

		$group = "<!-- wp:group {\"className\":\"sd-ai-agent-menu-category\",\"layout\":{\"type\":\"constrained\"}} -->\n"
			. "<div class=\"wp-block-group sd-ai-agent-menu-category\">\n"
			. $items_markup . "\n"
			. "</div>\n"
			. '<!-- /wp:group -->';

		return $heading . "\n\n" . $group;
	}

	/**
	 * Build the Gutenberg block for one menu item.
	 *
	 * Uses a two-column layout: item name (and optional description) on the
	 * left; price right-aligned on the right. Optional dietary tag badges
	 * are appended below the columns.
	 *
	 * @since 1.7.0
	 *
	 * @param array<string,mixed> $item Item data: name, price, description, allergens, dietary_tags.
	 * @return string Block markup for the item.
	 */
	public static function build_item_block( array $item ): string {
		$name        = esc_html( (string) ( $item['name'] ?? '' ) );
		$price       = esc_html( (string) ( $item['price'] ?? '' ) );
		$description = isset( $item['description'] ) ? esc_html( (string) $item['description'] ) : '';
		$allergens   = isset( $item['allergens'] ) ? esc_html( (string) $item['allergens'] ) : '';
		/** @var array<string> $dietary_tags */
		$dietary_tags = isset( $item['dietary_tags'] ) && is_array( $item['dietary_tags'] )
			? $item['dietary_tags']
			: [];

		// Left column: name + optional description.
		$name_block = "<!-- wp:paragraph {\"className\":\"sd-ai-agent-menu-item-name\"} -->\n"
			. "<p class=\"sd-ai-agent-menu-item-name\"><strong>{$name}</strong></p>\n"
			. '<!-- /wp:paragraph -->';

		$left_content = $name_block;

		if ( '' !== $description ) {
			$desc_block    = "<!-- wp:paragraph {\"className\":\"sd-ai-agent-menu-item-description\"} -->\n"
				. "<p class=\"sd-ai-agent-menu-item-description\">{$description}</p>\n"
				. '<!-- /wp:paragraph -->';
			$left_content .= "\n\n" . $desc_block;
		}

		$left_col = "<!-- wp:column -->\n"
			. "<div class=\"wp-block-column\">\n"
			. $left_content . "\n"
			. "</div>\n"
			. '<!-- /wp:column -->';

		// Right column: price right-aligned.
		$price_block = "<!-- wp:paragraph {\"textAlign\":\"right\",\"className\":\"sd-ai-agent-menu-item-price\"} -->\n"
			. "<p class=\"has-text-align-right sd-ai-agent-menu-item-price\">{$price}</p>\n"
			. '<!-- /wp:paragraph -->';

		$right_col = "<!-- wp:column {\"width\":\"140px\"} -->\n"
			. "<div class=\"wp-block-column\" style=\"flex-basis:140px\">\n"
			. $price_block . "\n"
			. "</div>\n"
			. '<!-- /wp:column -->';

		$columns = "<!-- wp:columns {\"isStackedOnMobile\":false} -->\n"
			. "<div class=\"wp-block-columns is-not-stacked-on-mobile\">\n"
			. $left_col . "\n"
			. $right_col . "\n"
			. "</div>\n"
			. '<!-- /wp:columns -->';

		$inner = $columns;

		// Optional dietary badge row.
		if ( ! empty( $dietary_tags ) ) {
			$inner .= "\n\n" . self::build_dietary_badges_block( $dietary_tags );
		}

		// Optional allergen note.
		if ( '' !== $allergens ) {
			$allergen_block = "<!-- wp:paragraph {\"className\":\"sd-ai-agent-menu-item-allergens\"} -->\n"
				. "<p class=\"sd-ai-agent-menu-item-allergens\"><em>{$allergens}</em></p>\n"
				. '<!-- /wp:paragraph -->';
			$inner         .= "\n\n" . $allergen_block;
		}

		return "<!-- wp:group {\"className\":\"sd-ai-agent-menu-item\",\"layout\":{\"type\":\"constrained\"}} -->\n"
			. "<div class=\"wp-block-group sd-ai-agent-menu-item\">\n"
			. $inner . "\n"
			. "</div>\n"
			. '<!-- /wp:group -->';
	}

	/**
	 * Build the dietary badge paragraph block for one item.
	 *
	 * Recognised abbreviations (V, VG, GF, DF, …) are expanded to their full
	 * labels in title-attribute tooltips for accessibility. Unknown tags are
	 * rendered as-is.
	 *
	 * @since 1.7.0
	 *
	 * @param array<string> $tags Array of dietary tag abbreviations.
	 * @return string Block markup containing the badge spans.
	 */
	public static function build_dietary_badges_block( array $tags ): string {
		if ( empty( $tags ) ) {
			return '';
		}

		$spans = [];
		foreach ( $tags as $tag ) {
			$abbr       = strtoupper( trim( (string) $tag ) );
			$full_label = self::DIETARY_TAG_LABELS[ $abbr ] ?? $abbr;
			$safe_abbr  = esc_html( $abbr );
			$safe_label = esc_attr( $full_label );
			$spans[]    = "<span class=\"sd-ai-agent-badge sd-ai-agent-badge--{$safe_abbr}\" title=\"{$safe_label}\">{$safe_abbr}</span>";
		}

		$badges_html = implode( ' ', $spans );

		return "<!-- wp:paragraph {\"className\":\"sd-ai-agent-menu-item-badges\"} -->\n"
			. "<p class=\"sd-ai-agent-menu-item-badges\">{$badges_html}</p>\n"
			. '<!-- /wp:paragraph -->';
	}

	/**
	 * Build a PDF embed / download block when the business provides a PDF menu.
	 *
	 * Uses a Gutenberg File block so visitors can view or download the PDF.
	 * A descriptive paragraph precedes the embed to provide context.
	 *
	 * @since 1.7.0
	 *
	 * @param string $pdf_url Absolute URL of the PDF file (must already be in the media library).
	 * @return string Block markup for the PDF section.
	 */
	public static function build_pdf_block( string $pdf_url ): string {
		$safe_url = esc_url( $pdf_url );

		$intro = "<!-- wp:paragraph -->\n"
			. '<p>' . esc_html__( 'Download our full menu as a PDF:', 'superdav-ai-agent' ) . "</p>\n"
			. '<!-- /wp:paragraph -->';

		$file_block = "<!-- wp:file {\"href\":\"{$safe_url}\"} -->\n"
			. '<div class="wp-block-file">'
			. "<a href=\"{$safe_url}\" class=\"wp-block-file__button\">"
			. esc_html__( 'Download Menu (PDF)', 'superdav-ai-agent' )
			. '</a>'
			. "</div>\n"
			. '<!-- /wp:file -->';

		return $intro . "\n\n" . $file_block;
	}

	/**
	 * Build a horizontal separator block between categories.
	 *
	 * @since 1.7.0
	 *
	 * @return string Block markup for the separator.
	 */
	public static function build_separator_block(): string {
		return "<!-- wp:separator {\"className\":\"sd-ai-agent-menu-separator\"} -->\n"
			. "<hr class=\"wp-block-separator has-alpha-channel-opacity sd-ai-agent-menu-separator\"/>\n"
			. '<!-- /wp:separator -->';
	}

	/**
	 * Validate the structured menu data array.
	 *
	 * Returns true when the data is usable. Returns a non-empty string error
	 * message when a required field is missing or malformed.
	 *
	 * @since 1.7.0
	 *
	 * @param array<string,mixed> $menu_data The menu data to validate.
	 * @return true|string True on success; error message string on failure.
	 */
	public static function validate( array $menu_data ): bool|string {
		if ( empty( $menu_data['categories'] ) || ! is_array( $menu_data['categories'] ) ) {
			return 'Menu data must include at least one category.';
		}

		foreach ( $menu_data['categories'] as $cat_index => $category ) {
			if ( ! is_array( $category ) ) {
				return sprintf( 'Category at index %d must be an array.', $cat_index );
			}
			if ( empty( $category['name'] ) ) {
				return sprintf( 'Category at index %d must have a non-empty name.', $cat_index );
			}
			if ( ! isset( $category['items'] ) || ! is_array( $category['items'] ) ) {
				return sprintf( 'Category "%s" must have an items array.', $category['name'] );
			}
			foreach ( $category['items'] as $item_index => $item ) {
				if ( ! is_array( $item ) ) {
					return sprintf(
						'Item at index %d in category "%s" must be an array.',
						$item_index,
						$category['name']
					);
				}
				if ( empty( $item['name'] ) ) {
					return sprintf(
						'Item at index %d in category "%s" must have a non-empty name.',
						$item_index,
						$category['name']
					);
				}
				if ( empty( $item['price'] ) ) {
					return sprintf(
						'Item "%s" in category "%s" must have a non-empty price.',
						$item['name'],
						$category['name']
					);
				}
			}
		}

		return true;
	}
}
