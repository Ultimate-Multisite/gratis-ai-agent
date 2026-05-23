<?php
/**
 * Test case for TaxonomyAbilities class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\TaxonomyAbilities;
use WP_UnitTestCase;

/**
 * Test TaxonomyAbilities handler methods.
 */
class TaxonomyAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Slug for the private taxonomy registered in tests that require it.
	 *
	 * @var string
	 */
	private string $private_tax = 'sd_ai_agent_test_private';

	/**
	 * Administrator user ID for tests that require elevated capabilities.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Set up shared test state.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Tear down test state: restore unauthenticated user and unregister test taxonomy.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		if ( taxonomy_exists( $this->private_tax ) ) {
			unregister_taxonomy( $this->private_tax );
		}
		parent::tearDown();
	}

	// ─── handle_list_terms ─────────────────────────────────────────────────

	/**
	 * Test default call (no input) returns the category taxonomy with Uncategorized.
	 */
	public function test_default_category_returns_uncategorized(): void {
		$result = TaxonomyAbilities::handle_list_terms( [] );

		$this->assertIsArray( $result );
		$this->assertSame( 'category', $result['taxonomy'] );

		$names = array_column( $result['items'], 'name' );
		$this->assertContains( 'Uncategorized', $names );
	}

	/**
	 * Test response contains all expected top-level keys.
	 */
	public function test_returns_expected_structure(): void {
		$result = TaxonomyAbilities::handle_list_terms( [ 'taxonomy' => 'category' ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'taxonomy', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'page', $result );
		$this->assertArrayHasKey( 'per_page', $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertIsInt( $result['page'] );
		$this->assertIsInt( $result['per_page'] );
	}

	/**
	 * Test each item contains the required fields with correct types.
	 */
	public function test_item_structure(): void {
		$result = TaxonomyAbilities::handle_list_terms( [ 'taxonomy' => 'category' ] );

		$this->assertNotEmpty( $result['items'], 'Expected at least the Uncategorized term.' );

		$item = $result['items'][0];
		$this->assertArrayHasKey( 'term_id', $item );
		$this->assertArrayHasKey( 'name', $item );
		$this->assertArrayHasKey( 'slug', $item );
		$this->assertArrayHasKey( 'count', $item );
		$this->assertArrayHasKey( 'parent', $item );
		$this->assertArrayHasKey( 'description', $item );
		$this->assertIsInt( $item['term_id'] );
		$this->assertIsString( $item['name'] );
		$this->assertIsString( $item['slug'] );
		$this->assertIsInt( $item['count'] );
		$this->assertIsInt( $item['parent'] );
		$this->assertIsString( $item['description'] );
	}

	/**
	 * Test search parameter narrows results to matching terms.
	 */
	public function test_search_narrows_results(): void {
		$unique = 'zzsrch' . uniqid();
		$this->factory->term->create( [
			'taxonomy' => 'category',
			'name'     => $unique . '-alpha',
		] );
		$this->factory->term->create( [
			'taxonomy' => 'category',
			'name'     => 'totally-different-' . uniqid(),
		] );

		$result = TaxonomyAbilities::handle_list_terms( [
			'taxonomy' => 'category',
			'search'   => $unique,
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $unique . '-alpha', $result['items'][0]['name'] );
	}

	/**
	 * Test per_page and page inputs are reflected in the response and items count.
	 */
	public function test_pagination_metadata_correct(): void {
		$prefix = 'zzpag' . uniqid();
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->factory->term->create( [
				'taxonomy' => 'category',
				'name'     => $prefix . '-term-' . $i,
			] );
		}

		$result = TaxonomyAbilities::handle_list_terms( [
			'taxonomy' => 'category',
			'search'   => $prefix,
			'per_page' => 2,
			'page'     => 1,
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 5, $result['total'], 'Total must reflect unpaginated count.' );
		$this->assertCount( 2, $result['items'] );
		$this->assertSame( 1, $result['page'] );
		$this->assertSame( 2, $result['per_page'] );
	}

	/**
	 * Test total is consistent across pages (reflects unpaginated count).
	 */
	public function test_total_is_unpaginated(): void {
		$prefix = 'zztot' . uniqid();
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->factory->term->create( [
				'taxonomy' => 'category',
				'name'     => $prefix . '-term-' . $i,
			] );
		}

		$page1 = TaxonomyAbilities::handle_list_terms( [
			'taxonomy' => 'category',
			'search'   => $prefix,
			'per_page' => 2,
			'page'     => 1,
		] );

		$page2 = TaxonomyAbilities::handle_list_terms( [
			'taxonomy' => 'category',
			'search'   => $prefix,
			'per_page' => 2,
			'page'     => 2,
		] );

		$this->assertSame( 3, $page1['total'] );
		$this->assertSame( 3, $page2['total'] );
		$this->assertCount( 2, $page1['items'] );
		$this->assertCount( 1, $page2['items'] );
	}

	/**
	 * Test parent parameter returns only direct children of the given term.
	 */
	public function test_parent_filter_returns_direct_children(): void {
		$parent_id = $this->factory->term->create( [
			'taxonomy' => 'category',
			'name'     => 'zzpar-' . uniqid(),
		] );
		$child_id  = $this->factory->term->create( [
			'taxonomy' => 'category',
			'name'     => 'zzchild-' . uniqid(),
			'parent'   => $parent_id,
		] );

		$result = TaxonomyAbilities::handle_list_terms( [
			'taxonomy' => 'category',
			'parent'   => $parent_id,
		] );

		$this->assertIsArray( $result );
		$term_ids = array_column( $result['items'], 'term_id' );
		$this->assertContains( $child_id, $term_ids );
		$this->assertNotContains( $parent_id, $term_ids );
	}

	/**
	 * Test unknown taxonomy slug returns WP_Error with taxonomy_not_found code.
	 */
	public function test_unknown_taxonomy_returns_error(): void {
		$result = TaxonomyAbilities::handle_list_terms( [
			'taxonomy' => 'totally_nonexistent_taxonomy_xyz_9999',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'taxonomy_not_found', $result->get_error_code() );
	}

	/**
	 * Test private taxonomy without manage_terms cap returns insufficient_capability.
	 */
	public function test_private_taxonomy_without_cap_returns_error(): void {
		register_taxonomy(
			$this->private_tax,
			[ 'post' ],
			[
				'public'       => false,
				'capabilities' => [
					'manage_terms' => 'manage_options',
					'edit_terms'   => 'manage_options',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'manage_options',
				],
			]
		);

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = TaxonomyAbilities::handle_list_terms( [ 'taxonomy' => $this->private_tax ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'insufficient_capability', $result->get_error_code() );

		// Restore admin for subsequent assertions or tearDown.
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Test private taxonomy with manage_terms capability returns valid results.
	 */
	public function test_private_taxonomy_with_cap_returns_results(): void {
		register_taxonomy(
			$this->private_tax,
			[ 'post' ],
			[
				'public'       => false,
				'capabilities' => [
					'manage_terms' => 'manage_options',
					'edit_terms'   => 'manage_options',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'manage_options',
				],
			]
		);

		// Admin has manage_options.
		$result = TaxonomyAbilities::handle_list_terms( [ 'taxonomy' => $this->private_tax ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertSame( $this->private_tax, $result['taxonomy'] );
	}

	/**
	 * Test hide_empty excludes terms with zero assigned posts.
	 *
	 * Freshly created terms have count = 0. With hide_empty false both terms
	 * appear; with hide_empty true both are excluded.
	 */
	public function test_hide_empty_excludes_zero_count_terms(): void {
		$prefix = 'zzhide' . uniqid();
		$this->factory->term->create( [
			'taxonomy' => 'category',
			'name'     => $prefix . '-a',
		] );
		$this->factory->term->create( [
			'taxonomy' => 'category',
			'name'     => $prefix . '-b',
		] );

		$result_all = TaxonomyAbilities::handle_list_terms( [
			'taxonomy'   => 'category',
			'search'     => $prefix,
			'hide_empty' => false,
		] );
		$this->assertSame( 2, $result_all['total'] );

		$result_empty = TaxonomyAbilities::handle_list_terms( [
			'taxonomy'   => 'category',
			'search'     => $prefix,
			'hide_empty' => true,
		] );
		$this->assertSame( 0, $result_empty['total'] );
	}

	/**
	 * Test per_page greater than 200 returns WP_Error with per_page_too_large code.
	 */
	public function test_per_page_too_large_returns_error(): void {
		$result = TaxonomyAbilities::handle_list_terms( [
			'taxonomy' => 'category',
			'per_page' => 500,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'per_page_too_large', $result->get_error_code() );
	}

	/**
	 * Test per_page of exactly 200 is accepted and reflected in the response.
	 */
	public function test_per_page_200_is_accepted(): void {
		$result = TaxonomyAbilities::handle_list_terms( [
			'taxonomy' => 'category',
			'per_page' => 200,
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 200, $result['per_page'] );
	}

	/**
	 * Test orderby term_id with order ASC returns terms sorted ascending by ID.
	 */
	public function test_orderby_term_id_asc(): void {
		$prefix = 'zzord' . uniqid();
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->factory->term->create( [
				'taxonomy' => 'category',
				'name'     => $prefix . '-' . $i,
			] );
		}

		$result = TaxonomyAbilities::handle_list_terms( [
			'taxonomy' => 'category',
			'search'   => $prefix,
			'orderby'  => 'term_id',
			'order'    => 'ASC',
		] );

		$this->assertIsArray( $result );
		$result_ids = array_column( $result['items'], 'term_id' );
		$sorted     = $result_ids;
		sort( $sorted );
		$this->assertSame( $sorted, $result_ids, 'Items should be sorted by term_id ASC.' );
	}
}
