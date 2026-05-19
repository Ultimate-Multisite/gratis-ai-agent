<?php
/**
 * Test case for MenuAbilities class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\MenuAbilities;
use WP_UnitTestCase;

/**
 * Test MenuAbilities handler methods.
 */
class MenuAbilitiesTest extends WP_UnitTestCase {

	// ─── handle_list_menus ────────────────────────────────────────

	/**
	 * Test handle_list_menus returns expected structure when no menus exist.
	 */
	public function test_handle_list_menus_returns_structure() {
		$result = MenuAbilities::handle_list_menus( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'menus', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertIsArray( $result['menus'] );
		$this->assertIsInt( $result['total'] );
	}

	/**
	 * Test handle_list_menus total matches menus array count.
	 */
	public function test_handle_list_menus_total_matches_count() {
		wp_create_nav_menu( 'Test Menu A' );
		wp_create_nav_menu( 'Test Menu B' );

		$result = MenuAbilities::handle_list_menus( [] );

		$this->assertSame( count( $result['menus'] ), $result['total'] );
	}

	/**
	 * Test handle_list_menus each menu has required fields.
	 */
	public function test_handle_list_menus_menu_structure() {
		wp_create_nav_menu( 'Structure Test Menu' );

		$result = MenuAbilities::handle_list_menus( [] );

		$this->assertNotEmpty( $result['menus'] );
		$menu = $result['menus'][0];
		$this->assertArrayHasKey( 'id', $menu );
		$this->assertArrayHasKey( 'name', $menu );
		$this->assertArrayHasKey( 'slug', $menu );
		$this->assertArrayHasKey( 'count', $menu );
		$this->assertArrayHasKey( 'locations', $menu );
		$this->assertIsArray( $menu['locations'] );
	}

	// ─── handle_get_menu ─────────────────────────────────────────

	/**
	 * Test handle_get_menu returns error when no identifier provided.
	 */
	public function test_handle_get_menu_missing_identifier() {
		$result = MenuAbilities::handle_get_menu( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_missing_menu_identifier', $result->get_error_code() );
	}

	/**
	 * Test handle_get_menu returns error for non-existent menu.
	 */
	public function test_handle_get_menu_not_found() {
		$result = MenuAbilities::handle_get_menu( [ 'menu_id' => 999999 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_menu_not_found', $result->get_error_code() );
	}

	/**
	 * Test handle_get_menu returns menu by ID.
	 */
	public function test_handle_get_menu_by_id() {
		$menu_id = wp_create_nav_menu( 'Get By ID Menu' );
		$this->assertIsInt( $menu_id );

		$result = MenuAbilities::handle_get_menu( [ 'menu_id' => $menu_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( $menu_id, $result['id'] );
		$this->assertSame( 'Get By ID Menu', $result['name'] );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'count', $result );
	}

	/**
	 * Test handle_get_menu returns menu by slug.
	 */
	public function test_handle_get_menu_by_slug() {
		$menu_id = wp_create_nav_menu( 'Get By Slug Menu' );
		$this->assertIsInt( $menu_id );

		$menu = wp_get_nav_menu_object( $menu_id );
		$this->assertNotFalse( $menu );

		$result = MenuAbilities::handle_get_menu( [ 'menu_slug' => $menu->slug ] );

		$this->assertIsArray( $result );
		$this->assertSame( $menu_id, $result['id'] );
	}

	// ─── handle_create_menu ───────────────────────────────────────

	/**
	 * Test handle_create_menu returns error when name is empty.
	 */
	public function test_handle_create_menu_empty_name() {
		$result = MenuAbilities::handle_create_menu( [ 'name' => '' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_empty_menu_name', $result->get_error_code() );
	}

	/**
	 * Test handle_create_menu creates a menu and returns expected structure.
	 */
	public function test_handle_create_menu_success() {
		$result = MenuAbilities::handle_create_menu( [ 'name' => 'New Test Menu' ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'menu_id', $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'slug', $result );
		$this->assertIsInt( $result['menu_id'] );
		$this->assertSame( 'New Test Menu', $result['name'] );
	}

	/**
	 * Test handle_create_menu creates a menu that can be retrieved.
	 */
	public function test_handle_create_menu_persisted() {
		$result = MenuAbilities::handle_create_menu( [ 'name' => 'Persisted Menu' ] );

		$this->assertIsArray( $result );
		$menu = wp_get_nav_menu_object( $result['menu_id'] );
		$this->assertNotFalse( $menu );
		$this->assertSame( 'Persisted Menu', $menu->name );
	}

	// ─── handle_delete_menu ───────────────────────────────────────

	/**
	 * Test handle_delete_menu returns error when no identifier provided.
	 */
	public function test_handle_delete_menu_missing_identifier() {
		$result = MenuAbilities::handle_delete_menu( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_missing_menu_identifier', $result->get_error_code() );
	}

	/**
	 * Test handle_delete_menu deletes a menu by ID.
	 */
	public function test_handle_delete_menu_success() {
		$menu_id = wp_create_nav_menu( 'Menu To Delete' );
		$this->assertIsInt( $menu_id );

		$result = MenuAbilities::handle_delete_menu( [ 'menu_id' => $menu_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( $menu_id, $result['menu_id'] );
		$this->assertTrue( $result['deleted'] );

		// Verify it's gone.
		$menu = wp_get_nav_menu_object( $menu_id );
		$this->assertFalse( $menu );
	}

	// ─── handle_add_menu_item ─────────────────────────────────────

	/**
	 * Test handle_add_menu_item returns error when title is empty.
	 */
	public function test_handle_add_menu_item_empty_title() {
		$menu_id = wp_create_nav_menu( 'Add Item Menu' );
		$this->assertIsInt( $menu_id );

		$result = MenuAbilities::handle_add_menu_item(
			[
				'menu_id' => $menu_id,
				'title'   => '',
				'url'     => 'https://example.com',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_empty_item_title', $result->get_error_code() );
	}

	/**
	 * Test handle_add_menu_item adds a custom link item.
	 */
	public function test_handle_add_menu_item_custom_link() {
		$menu_id = wp_create_nav_menu( 'Custom Link Menu' );
		$this->assertIsInt( $menu_id );

		$result = MenuAbilities::handle_add_menu_item(
			[
				'menu_id' => $menu_id,
				'title'   => 'Home',
				'url'     => 'https://example.com',
			]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'item_id', $result );
		$this->assertArrayHasKey( 'title', $result );
		$this->assertArrayHasKey( 'url', $result );
		$this->assertArrayHasKey( 'menu_id', $result );
		$this->assertIsInt( $result['item_id'] );
		$this->assertSame( 'Home', $result['title'] );
		$this->assertSame( $menu_id, $result['menu_id'] );
	}

	/**
	 * Test handle_add_menu_item item appears in menu after adding.
	 */
	public function test_handle_add_menu_item_persisted() {
		$menu_id = wp_create_nav_menu( 'Persist Item Menu' );
		$this->assertIsInt( $menu_id );

		MenuAbilities::handle_add_menu_item(
			[
				'menu_id' => $menu_id,
				'title'   => 'About',
				'url'     => 'https://example.com/about',
			]
		);

		$items = wp_get_nav_menu_items( $menu_id );
		$this->assertIsArray( $items );
		$this->assertCount( 1, $items );
		$this->assertSame( 'About', $items[0]->title );
	}

	/**
	 * Test handle_add_menu_item preserves insertion order when no position is specified.
	 *
	 * Regression test for GH#1524: items were sorted alphabetically because
	 * wp_update_nav_menu_item received menu-item-position = 0 for every item.
	 */
	public function test_handle_add_menu_item_preserves_insertion_order() {
		$menu_id = wp_create_nav_menu( 'Order Test Menu' );
		$this->assertIsInt( $menu_id );

		$titles = [ 'Home', 'Menu', 'Our Story', 'Events', 'Find Us', 'Shop' ];

		foreach ( $titles as $title ) {
			$result = MenuAbilities::handle_add_menu_item(
				[
					'menu_id' => $menu_id,
					'title'   => $title,
					'url'     => 'https://example.com/' . sanitize_title( $title ),
				]
			);
			$this->assertIsArray( $result, "Expected array result for item '$title'" );
		}

		// wp_get_nav_menu_items returns items sorted by menu_order.
		$items = wp_get_nav_menu_items( $menu_id );
		$this->assertIsArray( $items );
		$this->assertCount( count( $titles ), $items );

		foreach ( $titles as $index => $expected_title ) {
			$this->assertSame(
				$expected_title,
				$items[ $index ]->title,
				"Item at position " . ( $index + 1 ) . " should be '$expected_title'"
			);
			$this->assertSame(
				$index + 1,
				(int) $items[ $index ]->menu_order,
				"menu_order for '$expected_title' should be " . ( $index + 1 )
			);
		}
	}

	/**
	 * Test handle_add_menu_item respects explicitly provided positions for ordering.
	 *
	 * Adds two items in reverse position order (Second first, First second) and
	 * verifies they are returned in position order (First, Second).
	 */
	public function test_handle_add_menu_item_respects_explicit_position() {
		$menu_id = wp_create_nav_menu( 'Explicit Position Menu' );
		$this->assertIsInt( $menu_id );

		// Add "Second" at position 2 first.
		$result_second = MenuAbilities::handle_add_menu_item(
			[
				'menu_id'  => $menu_id,
				'title'    => 'Second',
				'url'      => 'https://example.com/second',
				'position' => 2,
			]
		);
		$this->assertIsArray( $result_second );

		// Add "First" at position 1 second (inserted after "Second").
		$result_first = MenuAbilities::handle_add_menu_item(
			[
				'menu_id'  => $menu_id,
				'title'    => 'First',
				'url'      => 'https://example.com/first',
				'position' => 1,
			]
		);
		$this->assertIsArray( $result_first );

		// wp_get_nav_menu_items sorts by menu_order; "First" (pos 1) should come before "Second" (pos 2).
		$items = wp_get_nav_menu_items( $menu_id );
		$this->assertIsArray( $items );
		$this->assertCount( 2, $items );
		$this->assertSame( 'First', $items[0]->title, 'Item with position 1 should come first' );
		$this->assertSame( 'Second', $items[1]->title, 'Item with position 2 should come second' );
	}

	// ─── handle_remove_menu_item ──────────────────────────────────

	/**
	 * Test handle_remove_menu_item returns error when item_id is missing.
	 */
	public function test_handle_remove_menu_item_missing_id() {
		$result = MenuAbilities::handle_remove_menu_item( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_empty_item_id', $result->get_error_code() );
	}

	/**
	 * Test handle_remove_menu_item returns error for non-existent item.
	 */
	public function test_handle_remove_menu_item_not_found() {
		$result = MenuAbilities::handle_remove_menu_item( [ 'item_id' => 999999 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_menu_item_not_found', $result->get_error_code() );
	}

	/**
	 * Test handle_remove_menu_item removes an item successfully.
	 */
	public function test_handle_remove_menu_item_success() {
		$menu_id = wp_create_nav_menu( 'Remove Item Menu' );
		$this->assertIsInt( $menu_id );

		$item_id = wp_update_nav_menu_item(
			$menu_id,
			0,
			[
				'menu-item-title'  => 'Contact',
				'menu-item-url'    => 'https://example.com/contact',
				'menu-item-type'   => 'custom',
				'menu-item-status' => 'publish',
			]
		);
		$this->assertIsInt( $item_id );

		$result = MenuAbilities::handle_remove_menu_item( [ 'item_id' => $item_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( $item_id, $result['item_id'] );
		$this->assertTrue( $result['deleted'] );
	}

	// ─── handle_assign_menu_location ─────────────────────────────

	/**
	 * Test handle_assign_menu_location returns error when location is empty.
	 */
	public function test_handle_assign_menu_location_empty_location() {
		$menu_id = wp_create_nav_menu( 'Location Menu' );
		$this->assertIsInt( $menu_id );

		$result = MenuAbilities::handle_assign_menu_location(
			[
				'menu_id'  => $menu_id,
				'location' => '',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_agent_empty_location', $result->get_error_code() );
	}

	/**
	 * Test handle_assign_menu_location assigns menu to location.
	 */
	public function test_handle_assign_menu_location_success() {
		$menu_id = wp_create_nav_menu( 'Assign Location Menu' );
		$this->assertIsInt( $menu_id );

		$result = MenuAbilities::handle_assign_menu_location(
			[
				'menu_id'  => $menu_id,
				'location' => 'primary',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( $menu_id, $result['menu_id'] );
		$this->assertSame( 'primary', $result['location'] );
		$this->assertTrue( $result['assigned'] );

		// Verify the location was set.
		$locations = get_nav_menu_locations();
		$this->assertArrayHasKey( 'primary', $locations );
		$this->assertSame( $menu_id, $locations['primary'] );
	}
}
