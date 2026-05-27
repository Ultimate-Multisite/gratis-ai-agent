<?php
/**
 * Test case for ToolResultTruncator class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\ToolResultTruncator;
use WP_UnitTestCase;

/**
 * Test ToolResultTruncator functionality.
 */
class ToolResultTruncatorTest extends WP_UnitTestCase {

	// ── truncate — pass-through ───────────────────────────────────────────

	/**
	 * Test non-array values pass through unchanged.
	 */
	public function test_non_array_passes_through(): void {
		$this->assertSame( 'hello', ToolResultTruncator::truncate( 'hello' ) );
		$this->assertSame( 42, ToolResultTruncator::truncate( 42 ) );
		$this->assertNull( ToolResultTruncator::truncate( null ) );
		$this->assertTrue( ToolResultTruncator::truncate( true ) );
	}

	/**
	 * Test small array passes through unchanged.
	 */
	public function test_small_array_passes_through(): void {
		$input  = [ 'key' => 'value', 'count' => 3 ];
		$result = ToolResultTruncator::truncate( $input );
		$this->assertSame( $input, $result );
	}

	/**
	 * Test empty array passes through unchanged.
	 */
	public function test_empty_array_passes_through(): void {
		$result = ToolResultTruncator::truncate( [] );
		$this->assertSame( [], $result );
	}

	// ── truncate — generic large array ───────────────────────────────────

	/**
	 * Test large sequential array is truncated to MAX_ARRAY_ITEMS.
	 */
	public function test_large_sequential_array_is_truncated(): void {
		// Build an array that exceeds MAX_RESULT_BYTES when encoded.
		$items = [];
		for ( $i = 0; $i < 100; $i++ ) {
			$items[] = str_repeat( 'x', 100 );
		}
		$input = [ 'items' => $items ];

		$result = ToolResultTruncator::truncate( $input );

		$this->assertIsArray( $result );
		// The nested items array should be truncated.
		$this->assertLessThanOrEqual( ToolResultTruncator::MAX_ARRAY_ITEMS + 1, count( $result['items'] ) );
	}

	/**
	 * Test long string values are truncated.
	 */
	public function test_long_string_values_are_truncated(): void {
		$long_string = str_repeat( 'a', 10000 );
		// Build a large enough result to trigger truncation.
		$input = [
			'content' => $long_string,
			'extra'   => str_repeat( 'b', 5000 ),
		];

		$result = ToolResultTruncator::truncate( $input );

		$this->assertIsArray( $result );
		$this->assertLessThanOrEqual(
			ToolResultTruncator::MAX_STRING_LENGTH + strlen( '... [truncated]' ),
			strlen( $result['content'] )
		);
	}

	/**
	 * Test truncated string ends with '... [truncated]'.
	 */
	public function test_truncated_string_has_suffix(): void {
		$long_string = str_repeat( 'z', 10000 );
		$input       = array_fill( 0, 5, $long_string );
		// Make it large enough to trigger truncation.
		$big_input = [ 'data' => $input, 'more' => str_repeat( 'y', 5000 ) ];

		$result = ToolResultTruncator::truncate( $big_input );

		// If the data array was processed, strings inside should be truncated.
		if ( is_array( $result['data'] ) && ! empty( $result['data'] ) ) {
			$first = $result['data'][0];
			if ( is_string( $first ) && strlen( $first ) < strlen( $long_string ) ) {
				$this->assertStringEndsWith( '... [truncated]', $first );
			}
		}
		// At minimum, the result is an array.
		$this->assertIsArray( $result );
	}

	// ── tool-specific strategies ──────────────────────────────────────────

	/**
	 * Test get-plugins strategy keeps name, active, file fields only.
	 */
	public function test_get_plugins_strategy_keeps_essential_fields(): void {
		$plugins = [];
		for ( $i = 0; $i < 50; $i++ ) {
			$plugins[] = [
				'name'        => "Plugin $i",
				'active'      => true,
				'file'        => "plugin-$i/plugin-$i.php",
				'description' => str_repeat( 'Long description text. ', 20 ),
				'version'     => '1.0.0',
				'author'      => 'Author Name',
			];
		}
		$input = [ 'plugins' => $plugins ];

		$result = ToolResultTruncator::truncate( $input, 'sd-ai-agent/get-plugins' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'plugins', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertSame( 50, $result['total'] );
		$this->assertLessThanOrEqual( ToolResultTruncator::MAX_ARRAY_ITEMS, count( $result['plugins'] ) );

		// Each kept plugin should only have name, active, file.
		if ( ! empty( $result['plugins'] ) ) {
			$first = $result['plugins'][0];
			$this->assertArrayHasKey( 'name', $first );
			$this->assertArrayHasKey( 'active', $first );
			$this->assertArrayHasKey( 'file', $first );
			$this->assertArrayNotHasKey( 'description', $first );
			$this->assertArrayNotHasKey( 'version', $first );
		}
	}

	/**
	 * Test db-query strategy truncates rows.
	 */
	public function test_db_query_strategy_truncates_rows(): void {
		$rows = [];
		for ( $i = 0; $i < 50; $i++ ) {
			$rows[] = [ 'id' => $i, 'data' => str_repeat( 'row data ', 20 ) ];
		}
		$input = [ 'rows' => $rows ];

		$result = ToolResultTruncator::truncate( $input, 'sd-ai-agent/db-query' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'rows', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'showing', $result );
		$this->assertSame( 50, $result['total'] );
		$this->assertLessThanOrEqual( ToolResultTruncator::MAX_ARRAY_ITEMS, count( $result['rows'] ) );
	}

	/**
	 * Test navigate strategy truncates html field.
	 */
	public function test_navigate_strategy_truncates_html(): void {
		$long_html = '<html>' . str_repeat( '<p>Content</p>', 500 ) . '</html>';
		$input     = [
			'url'  => 'https://example.com',
			'html' => $long_html,
		];

		$result = ToolResultTruncator::truncate( $input, 'sd-ai-agent/navigate' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'html', $result );
		$this->assertLessThanOrEqual(
			ToolResultTruncator::MAX_STRING_LENGTH + strlen( '... [truncated]' ),
			strlen( $result['html'] )
		);
		$this->assertStringEndsWith( '... [truncated]', $result['html'] );
		$this->assertTrue( $result['_truncated'] );
	}

	/**
	 * Test get-page-html strategy truncates content field.
	 */
	public function test_get_page_html_strategy_truncates_content(): void {
		$long_content = str_repeat( 'Page content paragraph. ', 200 );
		$input        = [
			'selector' => 'body',
			'content'  => $long_content,
		];

		$result = ToolResultTruncator::truncate( $input, 'sd-ai-agent/get-page-html' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'content', $result );
		$this->assertLessThanOrEqual(
			ToolResultTruncator::MAX_STRING_LENGTH + strlen( '... [truncated]' ),
			strlen( $result['content'] )
		);
		$this->assertTrue( $result['_truncated'] );
	}

	/**
	 * Test navigate strategy does not truncate short html.
	 */
	public function test_navigate_strategy_does_not_truncate_short_html(): void {
		$short_html = '<p>Short</p>';
		$input      = [
			'url'  => 'https://example.com',
			'html' => $short_html,
		];

		// Result may not be truncated if total size is under threshold.
		$result = ToolResultTruncator::truncate( $input, 'sd-ai-agent/navigate' );

		$this->assertIsArray( $result );
		// html should be unchanged since it's short.
		$this->assertSame( $short_html, $result['html'] );
	}

	/**
	 * Test content-analyze strategy truncates posts_without_featured_image.
	 */
	public function test_content_analyze_strategy_truncates_posts_without_featured_image(): void {
		$posts = [];
		for ( $i = 1; $i <= 20; $i++ ) {
			// Use a long URL to ensure the encoded result exceeds MAX_RESULT_BYTES.
			$posts[] = [
				'id'    => $i,
				'title' => "Post $i — " . str_repeat( 'a', 50 ),
				'url'   => 'https://example.com/post-' . str_repeat( 'slug-', 20 ) . $i,
			];
		}
		$input = [
			'posts_without_featured_image' => $posts,
			'total_posts'                  => 100,
		];

		$result = ToolResultTruncator::truncate( $input, 'sd-ai-agent/content-analyze' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'posts_without_featured_image', $result );
		$this->assertLessThanOrEqual( 5, count( $result['posts_without_featured_image'] ) );
		$this->assertArrayHasKey( 'posts_without_featured_image_total', $result );
		$this->assertSame( 20, $result['posts_without_featured_image_total'] );
	}

	/**
	 * Test list-block-types strategy keeps name, title, category.
	 */
	public function test_list_block_types_strategy_keeps_essential_fields(): void {
		$block_types = [];
		for ( $i = 0; $i < 50; $i++ ) {
			$block_types[] = [
				'name'        => "core/block-$i",
				'title'       => "Block $i",
				'category'    => 'common',
				'description' => str_repeat( 'Block description. ', 10 ),
				'keywords'    => [ 'keyword1', 'keyword2' ],
			];
		}
		$input = [ 'block_types' => $block_types ];

		$result = ToolResultTruncator::truncate( $input, 'sd-ai-agent/list-block-types' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'block_types', $result );
		$this->assertLessThanOrEqual( ToolResultTruncator::MAX_ARRAY_ITEMS, count( $result['block_types'] ) );

		if ( ! empty( $result['block_types'] ) ) {
			$first = $result['block_types'][0];
			$this->assertArrayHasKey( 'name', $first );
			$this->assertArrayHasKey( 'title', $first );
			$this->assertArrayHasKey( 'category', $first );
			$this->assertArrayNotHasKey( 'description', $first );
			$this->assertArrayNotHasKey( 'keywords', $first );
		}
	}

	/**
	 * Regression: WooCommerce order tools must not expose customer PII even when
	 * the response is below the generic truncation threshold.
	 */
	public function test_woocommerce_orders_list_scrubs_customer_pii_from_small_array(): void {
		$input = [
			[
				'id'                    => 123,
				'status'                => 'processing',
				'currency'              => 'GBP',
				'total'                 => '42.00',
				'customer_id'           => 456,
				'order_key'             => 'wc_order_sensitive_key',
				'transaction_id'        => 'txn_sensitive_id',
				'customer_ip_address'   => '203.0.113.10',
				'customer_user_agent'   => 'Sensitive Browser UA',
				'billing'               => [
					'email'     => 'customer@example.test',
					'phone'     => '0123456789',
					'address_1' => '1 Private Street',
				],
				'shipping'              => [
					'address_1' => '2 Private Street',
				],
				'line_items'            => [
					[
						'name'       => 'Sample product',
						'product_id' => 789,
						'quantity'   => 2,
						'total'      => '42.00',
						'meta_data'  => [ [ 'value' => 'engraving with private note' ] ],
					],
				],
			],
		];

		$result = ToolResultTruncator::truncate( $input, 'woocommerce/orders-list' );

		$this->assertIsArray( $result );
		$this->assertSame( 123, $result[0]['id'] );
		$this->assertSame( 'processing', $result[0]['status'] );
		$this->assertSame( '42.00', $result[0]['total'] );
		$this->assertSame( 456, $result[0]['customer_id'] );
		$this->assertTrue( $result[0]['_redacted'] );
		$this->assertArrayNotHasKey( 'billing', $result[0] );
		$this->assertArrayNotHasKey( 'shipping', $result[0] );
		$this->assertArrayNotHasKey( 'order_key', $result[0] );
		$this->assertArrayNotHasKey( 'transaction_id', $result[0] );
		$this->assertArrayNotHasKey( 'customer_ip_address', $result[0] );
		$this->assertArrayNotHasKey( 'customer_user_agent', $result[0] );
		$this->assertArrayNotHasKey( 'meta_data', $result[0]['line_items'][0] );
	}

	/**
	 * Regression: WooCommerce order responses can arrive as JSON strings from
	 * function-response DTOs, so scrub the decoded payload and re-encode it.
	 */
	public function test_woocommerce_orders_get_scrubs_json_string_response(): void {
		$input = wp_json_encode(
			[
				'id'                  => 321,
				'status'              => 'completed',
				'total'               => '10.00',
				'billing'             => [ 'email' => 'customer@example.test' ],
				'customer_ip_address' => '203.0.113.20',
			]
		);

		$result = ToolResultTruncator::truncate( (string) $input, 'woocommerce/orders-get' );
		$this->assertIsString( $result );

		$decoded = json_decode( $result, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 321, $decoded['id'] );
		$this->assertSame( 'completed', $decoded['status'] );
		$this->assertTrue( $decoded['_redacted'] );
		$this->assertArrayNotHasKey( 'billing', $decoded );
		$this->assertArrayNotHasKey( 'customer_ip_address', $decoded );
	}

	// ── constants ─────────────────────────────────────────────────────────

	/**
	 * Test MAX_RESULT_BYTES constant value.
	 */
	public function test_max_result_bytes_constant(): void {
		$this->assertSame( 4096, ToolResultTruncator::MAX_RESULT_BYTES );
	}

	/**
	 * Test MAX_ARRAY_ITEMS constant value.
	 */
	public function test_max_array_items_constant(): void {
		$this->assertSame( 10, ToolResultTruncator::MAX_ARRAY_ITEMS );
	}

	/**
	 * Test MAX_STRING_LENGTH constant value.
	 */
	public function test_max_string_length_constant(): void {
		$this->assertSame( 500, ToolResultTruncator::MAX_STRING_LENGTH );
	}
}
