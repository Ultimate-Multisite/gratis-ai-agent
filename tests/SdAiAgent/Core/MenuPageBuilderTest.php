<?php

declare(strict_types=1);
/**
 * Tests for MenuPageBuilder — structured Gutenberg block generation
 * for hospitality menu pages.
 *
 * Covers:
 *   - build_menu_page_content() with a complete dataset
 *   - build_menu_page_content() with a PDF URL
 *   - build_category_block() heading and group structure
 *   - build_item_block() column layout, price alignment, description
 *   - build_item_block() with allergens
 *   - build_dietary_badges_block() recognised and unrecognised abbreviations
 *   - build_pdf_block() output
 *   - build_separator_block() output
 *   - validate() accepts valid data
 *   - validate() rejects missing categories
 *   - validate() rejects category without name
 *   - validate() rejects item without name
 *   - validate() rejects item without price
 *   - build_menu_page_content() with no categories returns empty string
 *   - Price strings appear right-aligned in item blocks
 *   - Dietary tags render as badges with correct class and title attributes
 *   - Each category block contains an h2 heading
 *   - Separator blocks appear between categories (not after last)
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\MenuPageBuilder;
use WP_UnitTestCase;

/**
 * Tests for MenuPageBuilder.
 *
 * @since 1.7.0
 * @coversDefaultClass \SdAiAgent\Core\MenuPageBuilder
 */
class MenuPageBuilderTest extends WP_UnitTestCase {

	// ── Fixtures ──────────────────────────────────────────────────────────

	/**
	 * Minimal valid single-category menu dataset.
	 *
	 * @return array<string,mixed>
	 */
	private function minimal_menu(): array {
		return [
			'categories' => [
				[
					'name'  => 'Espresso',
					'items' => [
						[
							'name'  => 'Flat White',
							'price' => '£3.50',
						],
					],
				],
			],
		];
	}

	/**
	 * Full café menu dataset with two categories, multiple items, descriptions,
	 * allergens, dietary tags, and a PDF URL.
	 *
	 * @return array<string,mixed>
	 */
	private function full_menu(): array {
		return [
			'pdf_url'    => 'https://example.com/menu.pdf',
			'categories' => [
				[
					'name'  => 'Espresso Drinks',
					'items' => [
						[
							'name'         => 'Flat White',
							'price'        => '£3.50',
							'description'  => 'Rich espresso with steamed milk.',
							'allergens'    => 'Contains milk.',
							'dietary_tags' => [ 'V' ],
						],
						[
							'name'         => 'Oat Latte',
							'price'        => '£4.00',
							'description'  => 'Espresso with creamy oat milk.',
							'dietary_tags' => [ 'VG', 'GF' ],
						],
					],
				],
				[
					'name'  => 'Pastries',
					'items' => [
						[
							'name'  => 'Almond Croissant',
							'price' => '£3.20',
						],
						[
							'name'         => 'Vegan Brownie',
							'price'        => '£2.80',
							'dietary_tags' => [ 'VG', 'DF', 'GF' ],
						],
					],
				],
			],
		];
	}

	// ── validate() ───────────────────────────────────────────────────────

	/**
	 * @covers ::validate
	 */
	public function test_validate_accepts_valid_data(): void {
		$result = MenuPageBuilder::validate( $this->minimal_menu() );
		$this->assertTrue( $result, 'validate() must return true for valid menu data' );
	}

	/**
	 * @covers ::validate
	 */
	public function test_validate_rejects_missing_categories(): void {
		$result = MenuPageBuilder::validate( [] );
		$this->assertIsString( $result, 'validate() must return an error string for missing categories' );
		$this->assertStringContainsString( 'category', strtolower( $result ) );
	}

	/**
	 * @covers ::validate
	 */
	public function test_validate_rejects_empty_categories_array(): void {
		$result = MenuPageBuilder::validate( [ 'categories' => [] ] );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'category', strtolower( $result ) );
	}

	/**
	 * @covers ::validate
	 */
	public function test_validate_rejects_category_without_name(): void {
		$data   = [
			'categories' => [
				[ 'items' => [] ],
			],
		];
		$result = MenuPageBuilder::validate( $data );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'name', strtolower( $result ) );
	}

	/**
	 * @covers ::validate
	 */
	public function test_validate_rejects_item_without_name(): void {
		$data   = [
			'categories' => [
				[
					'name'  => 'Espresso',
					'items' => [
						[ 'price' => '£3.50' ],
					],
				],
			],
		];
		$result = MenuPageBuilder::validate( $data );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'name', strtolower( $result ) );
	}

	/**
	 * @covers ::validate
	 */
	public function test_validate_rejects_item_without_price(): void {
		$data   = [
			'categories' => [
				[
					'name'  => 'Espresso',
					'items' => [
						[ 'name' => 'Flat White' ],
					],
				],
			],
		];
		$result = MenuPageBuilder::validate( $data );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'price', strtolower( $result ) );
	}

	// ── build_menu_page_content() ────────────────────────────────────────

	/**
	 * @covers ::build_menu_page_content
	 */
	public function test_build_menu_page_content_returns_non_empty_string(): void {
		$content = MenuPageBuilder::build_menu_page_content( $this->minimal_menu() );
		$this->assertNotEmpty( $content, 'build_menu_page_content() must return non-empty block markup' );
	}

	/**
	 * @covers ::build_menu_page_content
	 */
	public function test_build_menu_page_content_contains_category_heading(): void {
		$content = MenuPageBuilder::build_menu_page_content( $this->minimal_menu() );
		$this->assertStringContainsString( 'Espresso', $content );
		$this->assertStringContainsString( 'wp:heading', $content );
	}

	/**
	 * @covers ::build_menu_page_content
	 */
	public function test_build_menu_page_content_contains_item_name_and_price(): void {
		$content = MenuPageBuilder::build_menu_page_content( $this->minimal_menu() );
		$this->assertStringContainsString( 'Flat White', $content );
		$this->assertStringContainsString( '£3.50', $content );
	}

	/**
	 * @covers ::build_menu_page_content
	 */
	public function test_build_menu_page_content_price_is_right_aligned(): void {
		$content = MenuPageBuilder::build_menu_page_content( $this->minimal_menu() );
		$this->assertStringContainsString( 'has-text-align-right', $content );
		$this->assertStringContainsString( 'sd-ai-agent-menu-item-price', $content );
	}

	/**
	 * @covers ::build_menu_page_content
	 */
	public function test_build_menu_page_content_with_multiple_categories_has_separator(): void {
		$content = MenuPageBuilder::build_menu_page_content( $this->full_menu() );
		$this->assertStringContainsString( 'wp:separator', $content );
		$this->assertStringContainsString( 'sd-ai-agent-menu-separator', $content );
	}

	/**
	 * @covers ::build_menu_page_content
	 */
	public function test_build_menu_page_content_single_category_has_no_trailing_separator(): void {
		$content = MenuPageBuilder::build_menu_page_content( $this->minimal_menu() );
		$this->assertStringNotContainsString( 'wp:separator', $content );
	}

	/**
	 * @covers ::build_menu_page_content
	 */
	public function test_build_menu_page_content_with_pdf_url_includes_pdf_block(): void {
		$content = MenuPageBuilder::build_menu_page_content( $this->full_menu() );
		$this->assertStringContainsString( 'wp:file', $content );
		$this->assertStringContainsString( 'menu.pdf', $content );
	}

	/**
	 * @covers ::build_menu_page_content
	 */
	public function test_build_menu_page_content_without_pdf_url_excludes_pdf_block(): void {
		$content = MenuPageBuilder::build_menu_page_content( $this->minimal_menu() );
		$this->assertStringNotContainsString( 'wp:file', $content );
	}

	/**
	 * @covers ::build_menu_page_content
	 */
	public function test_build_menu_page_content_with_empty_categories_returns_empty(): void {
		$content = MenuPageBuilder::build_menu_page_content( [ 'categories' => [] ] );
		$this->assertSame( '', $content );
	}

	/**
	 * @covers ::build_menu_page_content
	 */
	public function test_build_menu_page_content_contains_all_categories(): void {
		$content = MenuPageBuilder::build_menu_page_content( $this->full_menu() );
		$this->assertStringContainsString( 'Espresso Drinks', $content );
		$this->assertStringContainsString( 'Pastries', $content );
	}

	/**
	 * @covers ::build_menu_page_content
	 */
	public function test_build_menu_page_content_contains_all_items(): void {
		$content = MenuPageBuilder::build_menu_page_content( $this->full_menu() );
		$this->assertStringContainsString( 'Flat White', $content );
		$this->assertStringContainsString( 'Oat Latte', $content );
		$this->assertStringContainsString( 'Almond Croissant', $content );
		$this->assertStringContainsString( 'Vegan Brownie', $content );
	}

	// ── build_category_block() ───────────────────────────────────────────

	/**
	 * @covers ::build_category_block
	 */
	public function test_build_category_block_returns_h2_heading(): void {
		$block = MenuPageBuilder::build_category_block(
			[
				'name'  => 'Cold Brew',
				'items' => [
					[ 'name' => 'Cold Brew Classic', 'price' => '£3.80' ],
				],
			]
		);
		$this->assertStringContainsString( 'level":2', $block );
		$this->assertStringContainsString( '<h2', $block );
		$this->assertStringContainsString( 'Cold Brew', $block );
	}

	/**
	 * @covers ::build_category_block
	 */
	public function test_build_category_block_has_category_class(): void {
		$block = MenuPageBuilder::build_category_block(
			[
				'name'  => 'Tea',
				'items' => [
					[ 'name' => 'English Breakfast', 'price' => '£2.50' ],
				],
			]
		);
		$this->assertStringContainsString( 'sd-ai-agent-menu-category', $block );
	}

	/**
	 * @covers ::build_category_block
	 */
	public function test_build_category_block_empty_items_returns_heading_only(): void {
		$block = MenuPageBuilder::build_category_block(
			[
				'name'  => 'Coming Soon',
				'items' => [],
			]
		);
		$this->assertStringContainsString( 'wp:heading', $block );
		$this->assertStringNotContainsString( 'wp:group', $block );
	}

	// ── build_item_block() ───────────────────────────────────────────────

	/**
	 * @covers ::build_item_block
	 */
	public function test_build_item_block_contains_name_and_price(): void {
		$block = MenuPageBuilder::build_item_block(
			[ 'name' => 'Cappuccino', 'price' => '£3.20' ]
		);
		$this->assertStringContainsString( 'Cappuccino', $block );
		$this->assertStringContainsString( '£3.20', $block );
	}

	/**
	 * @covers ::build_item_block
	 */
	public function test_build_item_block_uses_two_columns(): void {
		$block = MenuPageBuilder::build_item_block(
			[ 'name' => 'Latte', 'price' => '£3.50' ]
		);
		$this->assertStringContainsString( 'wp:columns', $block );
		$this->assertStringContainsString( 'wp:column', $block );
	}

	/**
	 * @covers ::build_item_block
	 */
	public function test_build_item_block_price_has_right_align(): void {
		$block = MenuPageBuilder::build_item_block(
			[ 'name' => 'Latte', 'price' => '£3.50' ]
		);
		$this->assertStringContainsString( 'textAlign":"right"', $block );
		$this->assertStringContainsString( 'has-text-align-right', $block );
	}

	/**
	 * @covers ::build_item_block
	 */
	public function test_build_item_block_with_description_includes_description(): void {
		$block = MenuPageBuilder::build_item_block(
			[
				'name'        => 'Espresso',
				'price'       => '£2.80',
				'description' => 'A concentrated shot of pure coffee.',
			]
		);
		$this->assertStringContainsString( 'A concentrated shot of pure coffee.', $block );
		$this->assertStringContainsString( 'sd-ai-agent-menu-item-description', $block );
	}

	/**
	 * @covers ::build_item_block
	 */
	public function test_build_item_block_without_description_has_no_description_block(): void {
		$block = MenuPageBuilder::build_item_block(
			[ 'name' => 'Espresso', 'price' => '£2.80' ]
		);
		$this->assertStringNotContainsString( 'sd-ai-agent-menu-item-description', $block );
	}

	/**
	 * @covers ::build_item_block
	 */
	public function test_build_item_block_with_allergens_includes_allergen_note(): void {
		$block = MenuPageBuilder::build_item_block(
			[
				'name'      => 'Almond Latte',
				'price'     => '£4.00',
				'allergens' => 'Contains nuts, milk.',
			]
		);
		$this->assertStringContainsString( 'Contains nuts, milk.', $block );
		$this->assertStringContainsString( 'sd-ai-agent-menu-item-allergens', $block );
	}

	/**
	 * @covers ::build_item_block
	 */
	public function test_build_item_block_with_dietary_tags_includes_badges(): void {
		$block = MenuPageBuilder::build_item_block(
			[
				'name'         => 'Oat Latte',
				'price'        => '£4.00',
				'dietary_tags' => [ 'VG', 'GF' ],
			]
		);
		$this->assertStringContainsString( 'sd-ai-agent-badge', $block );
		$this->assertStringContainsString( 'VG', $block );
		$this->assertStringContainsString( 'GF', $block );
	}

	/**
	 * @covers ::build_item_block
	 */
	public function test_build_item_block_has_item_group_class(): void {
		$block = MenuPageBuilder::build_item_block(
			[ 'name' => 'Filter', 'price' => '£2.50' ]
		);
		$this->assertStringContainsString( 'sd-ai-agent-menu-item', $block );
	}

	// ── build_dietary_badges_block() ─────────────────────────────────────

	/**
	 * @covers ::build_dietary_badges_block
	 */
	public function test_build_dietary_badges_block_known_tag_has_title_attribute(): void {
		$block = MenuPageBuilder::build_dietary_badges_block( [ 'V' ] );
		$this->assertStringContainsString( 'title="Vegetarian"', $block );
	}

	/**
	 * @covers ::build_dietary_badges_block
	 */
	public function test_build_dietary_badges_block_vg_expands_to_vegan(): void {
		$block = MenuPageBuilder::build_dietary_badges_block( [ 'VG' ] );
		$this->assertStringContainsString( 'title="Vegan"', $block );
	}

	/**
	 * @covers ::build_dietary_badges_block
	 */
	public function test_build_dietary_badges_block_gf_expands_to_gluten_free(): void {
		$block = MenuPageBuilder::build_dietary_badges_block( [ 'GF' ] );
		$this->assertStringContainsString( 'title="Gluten-Free"', $block );
	}

	/**
	 * @covers ::build_dietary_badges_block
	 */
	public function test_build_dietary_badges_block_unknown_tag_renders_as_is(): void {
		$block = MenuPageBuilder::build_dietary_badges_block( [ 'ORGANIC' ] );
		$this->assertStringContainsString( 'ORGANIC', $block );
	}

	/**
	 * @covers ::build_dietary_badges_block
	 */
	public function test_build_dietary_badges_block_multiple_tags_rendered(): void {
		$block = MenuPageBuilder::build_dietary_badges_block( [ 'V', 'GF', 'DF' ] );
		$this->assertStringContainsString( 'V', $block );
		$this->assertStringContainsString( 'GF', $block );
		$this->assertStringContainsString( 'DF', $block );
	}

	/**
	 * @covers ::build_dietary_badges_block
	 */
	public function test_build_dietary_badges_block_empty_tags_returns_empty_string(): void {
		$block = MenuPageBuilder::build_dietary_badges_block( [] );
		$this->assertSame( '', $block );
	}

	/**
	 * @covers ::build_dietary_badges_block
	 */
	public function test_build_dietary_badges_block_has_badge_class(): void {
		$block = MenuPageBuilder::build_dietary_badges_block( [ 'V' ] );
		$this->assertStringContainsString( 'sd-ai-agent-badge', $block );
		$this->assertStringContainsString( 'sd-ai-agent-badge--V', $block );
	}

	// ── build_pdf_block() ────────────────────────────────────────────────

	/**
	 * @covers ::build_pdf_block
	 */
	public function test_build_pdf_block_contains_file_block(): void {
		$block = MenuPageBuilder::build_pdf_block( 'https://example.com/menu.pdf' );
		$this->assertStringContainsString( 'wp:file', $block );
	}

	/**
	 * @covers ::build_pdf_block
	 */
	public function test_build_pdf_block_includes_url(): void {
		$block = MenuPageBuilder::build_pdf_block( 'https://example.com/menu.pdf' );
		$this->assertStringContainsString( 'https://example.com/menu.pdf', $block );
	}

	// ── build_separator_block() ──────────────────────────────────────────

	/**
	 * @covers ::build_separator_block
	 */
	public function test_build_separator_block_returns_separator(): void {
		$block = MenuPageBuilder::build_separator_block();
		$this->assertStringContainsString( 'wp:separator', $block );
		$this->assertStringContainsString( '<hr', $block );
	}

	/**
	 * @covers ::build_separator_block
	 */
	public function test_build_separator_block_has_menu_separator_class(): void {
		$block = MenuPageBuilder::build_separator_block();
		$this->assertStringContainsString( 'sd-ai-agent-menu-separator', $block );
	}

	// ── Rendering invariants ──────────────────────────────────────────────

	/**
	 * Each category in the full menu must have a valid wp:heading block.
	 *
	 * @covers ::build_menu_page_content
	 */
	public function test_each_category_has_h2_heading_block(): void {
		$content    = MenuPageBuilder::build_menu_page_content( $this->full_menu() );
		$h2_count   = substr_count( $content, '<h2' );
		$categories = count( $this->full_menu()['categories'] );
		$this->assertSame( $categories, $h2_count, 'Each category must have exactly one h2 heading' );
	}

	/**
	 * The separator count must be one less than the number of categories.
	 *
	 * @covers ::build_menu_page_content
	 */
	public function test_separator_count_is_categories_minus_one(): void {
		$content    = MenuPageBuilder::build_menu_page_content( $this->full_menu() );
		$sep_count  = substr_count( $content, 'wp:separator' );
		// Each separator opens and closes: <!-- wp:separator --> + <!-- /wp:separator -->
		// so count of 'wp:separator' is (n-1) * 2.
		$categories = count( $this->full_menu()['categories'] );
		$this->assertSame( ( $categories - 1 ) * 2, $sep_count );
	}

	/**
	 * XSS: HTML-special characters in item name and price must be escaped.
	 *
	 * @covers ::build_item_block
	 */
	public function test_item_name_is_html_escaped(): void {
		$block = MenuPageBuilder::build_item_block(
			[ 'name' => '<script>alert(1)</script>', 'price' => '£3.00' ]
		);
		$this->assertStringNotContainsString( '<script>', $block );
		$this->assertStringContainsString( '&lt;script&gt;', $block );
	}

	/**
	 * XSS: HTML-special characters in category name must be escaped.
	 *
	 * @covers ::build_category_block
	 */
	public function test_category_name_is_html_escaped(): void {
		$block = MenuPageBuilder::build_category_block(
			[
				'name'  => '<b>Bold Category</b>',
				'items' => [ [ 'name' => 'Item', 'price' => '£1.00' ] ],
			]
		);
		$this->assertStringNotContainsString( '<b>', $block );
		$this->assertStringContainsString( '&lt;b&gt;', $block );
	}
}
