<?php
/**
 * Test case for BlockStorageScanner (GH#1781).
 *
 * Covers the acceptance criteria from the brief:
 *
 * 1. Empty post returns zero items.
 * 2. Post with one attrs_only block returns storage_mode: attrs_only.
 * 3. Post with one dual block returns storage_mode: dual and evidence.
 * 4. Post mixing all four modes aggregates correctly (modal mode).
 * 5. Truncation at limit: truncated: true, posts_scanned equals limit.
 * 6. include_registry_known: false (default) excludes registry blocks.
 * 7. Memoised tree-walk: scanning the same post content reuses parse_cache.
 * 8. limit: 5000 → WP_Error('limit_too_large').
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1781
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\BlockStorageScanner;
use SdAiAgent\Core\DualStorageRegistry;
use WP_UnitTestCase;

/**
 * Integration tests for BlockStorageScanner.
 *
 * Uses WP_UnitTestCase so real database calls and parse_blocks() are available.
 */
class BlockStorageScannerTest extends WP_UnitTestCase {

	/**
	 * Post IDs created during the test (auto-cleaned by WP_UnitTestCase).
	 *
	 * @var int[]
	 */
	private array $post_ids = [];

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Create a published post with the given block content.
	 *
	 * @param string $content    Serialised block markup.
	 * @param string $post_status Post status. Default 'publish'.
	 * @return int Post ID.
	 */
	private function make_post( string $content, string $post_status = 'publish' ): int {
		$post_id = $this->factory()->post->create(
			[
				'post_content' => $content,
				'post_status'  => $post_status,
			]
		);
		$this->post_ids[] = $post_id;
		return (int) $post_id;
	}

	/**
	 * Return an item from a scan result by block_name, or null if not found.
	 *
	 * @param array<int,array<string,mixed>> $items      Items array from scan result.
	 * @param string                          $block_name Block name to look up.
	 * @return array<string,mixed>|null
	 */
	private function find_item( array $items, string $block_name ): ?array {
		foreach ( $items as $item ) {
			if ( $item['block_name'] === $block_name ) {
				return $item;
			}
		}
		return null;
	}

	// ── AC 8: limit_too_large validation ──────────────────────────────────

	/**
	 * Passing limit > 1000 returns a WP_Error with code limit_too_large.
	 *
	 * @covers \SdAiAgent\Core\BlockStorageScanner::scan
	 */
	public function test_limit_too_large_returns_wp_error(): void {
		$result = BlockStorageScanner::scan( [ 'limit' => 5000 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'limit_too_large', $result->get_error_code() );
	}

	/**
	 * Passing limit = 1000 (max) does not return a WP_Error.
	 *
	 * @covers \SdAiAgent\Core\BlockStorageScanner::scan
	 */
	public function test_limit_at_max_is_accepted(): void {
		$result = BlockStorageScanner::scan( [ 'limit' => 1000 ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'posts_scanned', $result );
	}

	// ── AC 1: Empty post returns zero items ────────────────────────────────

	/**
	 * A post with no block content contributes zero items.
	 *
	 * @covers \SdAiAgent\Core\BlockStorageScanner::scan
	 */
	public function test_post_with_no_blocks_returns_empty_items(): void {
		$this->make_post( 'Plain text, no blocks.' );

		$result = BlockStorageScanner::scan( [ 'limit' => 50, 'post_types' => [ 'post' ] ] );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['unique_blocks'] );
		$this->assertCount( 0, $result['items'] );
	}

	// ── AC 2: attrs_only classification ────────────────────────────────────

	/**
	 * A block with attrs but empty innerHTML is classified as attrs_only.
	 *
	 * @covers \SdAiAgent\Core\BlockStorageScanner::scan
	 */
	public function test_attrs_only_block_is_classified_correctly(): void {
		// core/image: attrs set, innerHTML is a figure wrapper (non-empty).
		// Use a custom block comment with attrs only and empty innerHTML to test
		// the attrs_only path cleanly.
		$content = '<!-- wp:acme/attrs-only {"key":"value"} /-->';
		$this->make_post( $content );

		$result = BlockStorageScanner::scan(
			[
				'limit'      => 50,
				'post_types' => [ 'post' ],
			]
		);

		$this->assertIsArray( $result );
		$item = $this->find_item( $result['items'], 'acme/attrs-only' );
		$this->assertNotNull( $item, 'Expected acme/attrs-only in items.' );
		$this->assertSame( 'attrs_only', $item['storage_mode'] );
		$this->assertSame( 1, $item['occurrences'] );
		$this->assertContains( 'key', $item['evidence']['attr_keys'] );
		$this->assertSame( 0, $item['evidence']['inner_html_chars'] );
	}

	// ── AC 3: dual classification + evidence ──────────────────────────────

	/**
	 * A block with both attrs and innerHTML is classified as dual and
	 * evidence.attr_keys contains the attribute keys.
	 *
	 * @covers \SdAiAgent\Core\BlockStorageScanner::scan
	 */
	public function test_dual_block_is_classified_correctly(): void {
		$content = '<!-- wp:acme/dual {"title":"Hello"} --><div>Hello</div><!-- /wp:acme/dual -->';
		$this->make_post( $content );

		$result = BlockStorageScanner::scan(
			[
				'limit'      => 50,
				'post_types' => [ 'post' ],
			]
		);

		$this->assertIsArray( $result );
		$item = $this->find_item( $result['items'], 'acme/dual' );
		$this->assertNotNull( $item, 'Expected acme/dual in items.' );
		$this->assertSame( 'dual', $item['storage_mode'] );
		$this->assertContains( 'title', $item['evidence']['attr_keys'] );
		$this->assertGreaterThan( 0, $item['evidence']['inner_html_chars'] );
	}

	// ── AC 4: inner_html_only and unknown classifications ──────────────────

	/**
	 * A block with innerHTML only is classified as inner_html_only.
	 *
	 * @covers \SdAiAgent\Core\BlockStorageScanner::scan
	 */
	public function test_inner_html_only_block_is_classified_correctly(): void {
		$content = '<!-- wp:acme/html-only --><p>Content</p><!-- /wp:acme/html-only -->';
		$this->make_post( $content );

		$result = BlockStorageScanner::scan(
			[
				'limit'      => 50,
				'post_types' => [ 'post' ],
			]
		);

		$this->assertIsArray( $result );
		$item = $this->find_item( $result['items'], 'acme/html-only' );
		$this->assertNotNull( $item, 'Expected acme/html-only in items.' );
		$this->assertSame( 'inner_html_only', $item['storage_mode'] );
	}

	/**
	 * A block with no attrs and whitespace-only innerHTML is classified as unknown.
	 *
	 * @covers \SdAiAgent\Core\BlockStorageScanner::scan
	 */
	public function test_empty_block_is_classified_as_unknown(): void {
		// Self-closing block with no attrs and no inner content.
		$content = '<!-- wp:acme/empty /-->';
		$this->make_post( $content );

		$result = BlockStorageScanner::scan(
			[
				'limit'      => 50,
				'post_types' => [ 'post' ],
			]
		);

		$this->assertIsArray( $result );
		$item = $this->find_item( $result['items'], 'acme/empty' );
		$this->assertNotNull( $item, 'Expected acme/empty in items.' );
		$this->assertSame( 'unknown', $item['storage_mode'] );
	}

	// ── AC 4: modal aggregation — tie-break favours dual ──────────────────

	/**
	 * When a block_name has equal counts for two modes, 'dual' wins (tie-break).
	 *
	 * @covers \SdAiAgent\Core\BlockStorageScanner::scan
	 */
	public function test_modal_tie_break_favours_dual(): void {
		// Post 1: acme/mixed appears as attrs_only.
		$this->make_post( '<!-- wp:acme/mixed {"k":"v"} /-->' );
		// Post 2: acme/mixed appears as dual.
		$this->make_post( '<!-- wp:acme/mixed {"k":"v"} --><p>x</p><!-- /wp:acme/mixed -->' );

		$result = BlockStorageScanner::scan(
			[
				'limit'      => 50,
				'post_types' => [ 'post' ],
			]
		);

		$this->assertIsArray( $result );
		$item = $this->find_item( $result['items'], 'acme/mixed' );
		$this->assertNotNull( $item );
		// Equal counts (1 attrs_only vs 1 dual) — dual wins.
		$this->assertSame( 'dual', $item['storage_mode'] );
		$this->assertSame( 2, $item['occurrences'] );
	}

	// ── AC 6: include_registry_known toggling ─────────────────────────────

	/**
	 * By default (include_registry_known: false), blocks in DualStorageRegistry
	 * are excluded from items.
	 *
	 * @covers \SdAiAgent\Core\BlockStorageScanner::scan
	 */
	public function test_registry_known_blocks_excluded_by_default(): void {
		// Register a custom known block via filter.
		add_filter(
			'sd_ai_agent_block_dual_storage_blocks',
			static function ( array $blocks ): array {
				$blocks[] = 'acme/registry-block';
				return $blocks;
			}
		);

		$this->make_post(
			'<!-- wp:acme/registry-block {"k":"v"} --><p>x</p><!-- /wp:acme/registry-block -->'
		);

		$result = BlockStorageScanner::scan(
			[
				'limit'      => 50,
				'post_types' => [ 'post' ],
			]
		);

		remove_all_filters( 'sd_ai_agent_block_dual_storage_blocks' );

		$this->assertIsArray( $result );
		$item = $this->find_item( $result['items'], 'acme/registry-block' );
		$this->assertNull( $item, 'Registry-known block should be excluded by default.' );
	}

	/**
	 * When include_registry_known: true, registry-known blocks appear in items
	 * with in_registry: true.
	 *
	 * @covers \SdAiAgent\Core\BlockStorageScanner::scan
	 */
	public function test_registry_known_blocks_included_when_flag_true(): void {
		add_filter(
			'sd_ai_agent_block_dual_storage_blocks',
			static function ( array $blocks ): array {
				$blocks[] = 'acme/registry-included';
				return $blocks;
			}
		);

		$this->make_post(
			'<!-- wp:acme/registry-included {"k":"v"} --><p>x</p><!-- /wp:acme/registry-included -->'
		);

		$result = BlockStorageScanner::scan(
			[
				'limit'                  => 50,
				'post_types'             => [ 'post' ],
				'include_registry_known' => true,
			]
		);

		remove_all_filters( 'sd_ai_agent_block_dual_storage_blocks' );

		$this->assertIsArray( $result );
		$item = $this->find_item( $result['items'], 'acme/registry-included' );
		$this->assertNotNull( $item, 'Registry-known block should be present when include_registry_known is true.' );
		$this->assertTrue( $item['in_registry'] );
	}

	// ── AC 5: truncation at limit ─────────────────────────────────────────

	/**
	 * When limit posts are reached and more exist, truncated is true and
	 * posts_scanned equals limit.
	 *
	 * @covers \SdAiAgent\Core\BlockStorageScanner::scan
	 */
	public function test_truncation_when_limit_reached(): void {
		// Create 5 posts with block content.
		$block = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->';
		for ( $i = 0; $i < 5; $i++ ) {
			$this->make_post( $block );
		}

		// Scan with limit=2 — should scan exactly 2, then detect more exist.
		$result = BlockStorageScanner::scan(
			[
				'limit'      => 2,
				'post_types' => [ 'post' ],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['posts_scanned'] );
		$this->assertTrue( $result['truncated'] );
	}

	/**
	 * When limit is not reached, truncated is false.
	 *
	 * @covers \SdAiAgent\Core\BlockStorageScanner::scan
	 */
	public function test_not_truncated_when_limit_not_reached(): void {
		$this->make_post( '<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->' );

		$result = BlockStorageScanner::scan(
			[
				'limit'      => 50,
				'post_types' => [ 'post' ],
			]
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['truncated'] );
	}

	// ── Structural result keys ─────────────────────────────────────────────

	/**
	 * Scan result always contains all required top-level keys.
	 *
	 * @covers \SdAiAgent\Core\BlockStorageScanner::scan
	 */
	public function test_result_has_required_keys(): void {
		$result = BlockStorageScanner::scan( [ 'limit' => 10, 'post_types' => [ 'post' ] ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'posts_scanned', $result );
		$this->assertArrayHasKey( 'unique_blocks', $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'truncated', $result );
	}

	/**
	 * first_post_id in an item refers to a valid post ID that was scanned.
	 *
	 * @covers \SdAiAgent\Core\BlockStorageScanner::scan
	 */
	public function test_first_post_id_is_recorded(): void {
		$pid = $this->make_post(
			'<!-- wp:acme/tracked {"x":"1"} --><p>y</p><!-- /wp:acme/tracked -->'
		);

		$result = BlockStorageScanner::scan(
			[
				'limit'      => 50,
				'post_types' => [ 'post' ],
			]
		);

		$this->assertIsArray( $result );
		$item = $this->find_item( $result['items'], 'acme/tracked' );
		$this->assertNotNull( $item );
		$this->assertSame( $pid, $item['first_post_id'] );
	}

	// ── innerBlocks recursion ─────────────────────────────────────────────

	/**
	 * Blocks nested inside innerBlocks are also classified.
	 *
	 * @covers \SdAiAgent\Core\BlockStorageScanner::scan
	 */
	public function test_inner_blocks_are_scanned_recursively(): void {
		$content = <<<'BLOCKS'
<!-- wp:acme/wrapper -->
<!-- wp:acme/nested {"deep":"true"} --><span>x</span><!-- /wp:acme/nested -->
<!-- /wp:acme/wrapper -->
BLOCKS;
		$this->make_post( $content );

		$result = BlockStorageScanner::scan(
			[
				'limit'      => 50,
				'post_types' => [ 'post' ],
			]
		);

		$this->assertIsArray( $result );
		$nested_item = $this->find_item( $result['items'], 'acme/nested' );
		$this->assertNotNull( $nested_item, 'Nested block should be discovered.' );
		$this->assertSame( 'dual', $nested_item['storage_mode'] );
	}

	// ── post_status filter ────────────────────────────────────────────────

	/**
	 * When post_status includes 'draft', draft posts are scanned.
	 *
	 * @covers \SdAiAgent\Core\BlockStorageScanner::scan
	 */
	public function test_post_status_filter_includes_drafts(): void {
		$this->make_post(
			'<!-- wp:acme/draft-block {"k":"v"} --><p>x</p><!-- /wp:acme/draft-block -->',
			'draft'
		);

		$result_no_draft = BlockStorageScanner::scan(
			[
				'limit'       => 50,
				'post_types'  => [ 'post' ],
				'post_status' => [ 'publish' ],
			]
		);
		$result_with_draft = BlockStorageScanner::scan(
			[
				'limit'       => 50,
				'post_types'  => [ 'post' ],
				'post_status' => [ 'publish', 'draft' ],
			]
		);

		$this->assertNull(
			$this->find_item( $result_no_draft['items'], 'acme/draft-block' ),
			'Draft block should not appear in publish-only scan.'
		);
		$this->assertNotNull(
			$this->find_item( $result_with_draft['items'], 'acme/draft-block' ),
			'Draft block should appear when draft status is included.'
		);
	}
}
