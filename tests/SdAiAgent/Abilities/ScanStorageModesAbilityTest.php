<?php
/**
 * Test case for sd-ai-agent/scan-storage-modes ability (GH#1781).
 *
 * Covers the ability-level acceptance criteria from the brief:
 *
 * 1. scan-storage-modes {} on a fresh install returns items (possibly empty),
 *    posts_scanned >= 0, and truncated: false.
 * 2. After seeding a post with a dual-storage block, the item reports
 *    storage_mode: "dual" and evidence.attr_keys includes the attribute key.
 * 3. include_registry_known: false (default) excludes registry-known blocks.
 * 4. limit: 1000 accepted; limit: 5000 → WP_Error('limit_too_large').
 * 5. limit reached → truncated: true, posts_scanned equals the limit.
 * 6. Non-edit_posts user → WP_Error('insufficient_capability').
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1781
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\BlockAbilities;
use WP_UnitTestCase;

/**
 * Ability-level integration tests for scan-storage-modes.
 */
class ScanStorageModesAbilityTest extends WP_UnitTestCase {

	/**
	 * Admin user ID set up once per test.
	 *
	 * @var int
	 */
	private int $admin_id = 0;

	/**
	 * Subscriber user ID (no edit_posts capability).
	 *
	 * @var int
	 */
	private int $subscriber_id = 0;

	/**
	 * Set up admin and subscriber users; set current user to admin.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id      = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Restore original user after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		remove_all_filters( 'sd_ai_agent_block_dual_storage_blocks' );
		parent::tear_down();
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Create a published post with the given block content.
	 *
	 * @param string $content Block content.
	 * @return int Post ID.
	 */
	private function make_post( string $content ): int {
		return (int) $this->factory()->post->create(
			[
				'post_content' => $content,
				'post_status'  => 'publish',
			]
		);
	}

	/**
	 * Return an item from a result by block_name, or null if not found.
	 *
	 * @param array<int,array<string,mixed>> $items      Items array.
	 * @param string                          $block_name Block name to find.
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

	// ── AC 6: capability check ─────────────────────────────────────────────

	/**
	 * A subscriber (no edit_posts) gets WP_Error insufficient_capability.
	 *
	 * @covers \SdAiAgent\Abilities\BlockAbilities::handle_scan_storage_modes
	 */
	public function test_capability_rejection_for_subscriber(): void {
		wp_set_current_user( $this->subscriber_id );

		$result = BlockAbilities::handle_scan_storage_modes( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'insufficient_capability', $result->get_error_code() );
	}

	/**
	 * An administrator can call the ability without a capability error.
	 *
	 * @covers \SdAiAgent\Abilities\BlockAbilities::handle_scan_storage_modes
	 */
	public function test_admin_can_call_ability(): void {
		$result = BlockAbilities::handle_scan_storage_modes( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'posts_scanned', $result );
	}

	// ── AC 1: fresh install returns valid structure ────────────────────────

	/**
	 * On a fresh install, the ability returns expected top-level keys with
	 * correct types, posts_scanned >= 0, and truncated: false.
	 *
	 * @covers \SdAiAgent\Abilities\BlockAbilities::handle_scan_storage_modes
	 */
	public function test_fresh_install_returns_valid_structure(): void {
		$result = BlockAbilities::handle_scan_storage_modes( [ 'limit' => 50, 'post_types' => [ 'post' ] ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'posts_scanned', $result );
		$this->assertArrayHasKey( 'unique_blocks', $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'truncated', $result );
		$this->assertIsInt( $result['posts_scanned'] );
		$this->assertGreaterThanOrEqual( 0, $result['posts_scanned'] );
		$this->assertFalse( $result['truncated'] );
	}

	// ── AC 2: dual-storage detection ─────────────────────────────────────

	/**
	 * After seeding a dual-storage block, the item reports storage_mode: dual
	 * and evidence.attr_keys includes the seeded attribute key.
	 *
	 * @covers \SdAiAgent\Abilities\BlockAbilities::handle_scan_storage_modes
	 */
	public function test_dual_storage_block_detected(): void {
		// Seed a post with an acme/dual block carrying both attrs and innerHTML.
		$this->make_post(
			'<!-- wp:acme/dual {"title":"y"} --><div>x</div><!-- /wp:acme/dual -->'
		);

		$result = BlockAbilities::handle_scan_storage_modes(
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

	// ── AC 3: include_registry_known default excludes known blocks ────────

	/**
	 * By default, blocks in DualStorageRegistry are excluded from results.
	 *
	 * @covers \SdAiAgent\Abilities\BlockAbilities::handle_scan_storage_modes
	 */
	public function test_registry_blocks_excluded_by_default(): void {
		add_filter(
			'sd_ai_agent_block_dual_storage_blocks',
			static function ( array $blocks ): array {
				$blocks[] = 'acme/known-registry';
				return $blocks;
			}
		);

		$this->make_post(
			'<!-- wp:acme/known-registry {"k":"v"} --><p>x</p><!-- /wp:acme/known-registry -->'
		);

		$result = BlockAbilities::handle_scan_storage_modes(
			[
				'limit'      => 50,
				'post_types' => [ 'post' ],
			]
		);

		remove_all_filters( 'sd_ai_agent_block_dual_storage_blocks' );

		$this->assertIsArray( $result );
		$item = $this->find_item( $result['items'], 'acme/known-registry' );
		$this->assertNull( $item, 'Registry-known block should be excluded from default scan.' );
	}

	// ── AC 4: limit validation ─────────────────────────────────────────────

	/**
	 * limit: 5000 returns a WP_Error with code limit_too_large.
	 *
	 * @covers \SdAiAgent\Abilities\BlockAbilities::handle_scan_storage_modes
	 */
	public function test_limit_5000_returns_wp_error(): void {
		$result = BlockAbilities::handle_scan_storage_modes( [ 'limit' => 5000 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'limit_too_large', $result->get_error_code() );
	}

	/**
	 * limit: 1000 is accepted and returns an array (not a WP_Error).
	 *
	 * @covers \SdAiAgent\Abilities\BlockAbilities::handle_scan_storage_modes
	 */
	public function test_limit_1000_is_accepted(): void {
		$result = BlockAbilities::handle_scan_storage_modes( [ 'limit' => 1000 ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'posts_scanned', $result );
	}

	// ── AC 5: truncation ──────────────────────────────────────────────────

	/**
	 * When limit is reached and more posts exist, truncated is true and
	 * posts_scanned equals the limit.
	 *
	 * @covers \SdAiAgent\Abilities\BlockAbilities::handle_scan_storage_modes
	 */
	public function test_truncation_flag_set_when_limit_reached(): void {
		$block = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->';
		for ( $i = 0; $i < 5; $i++ ) {
			$this->make_post( $block );
		}

		$result = BlockAbilities::handle_scan_storage_modes(
			[
				'limit'      => 2,
				'post_types' => [ 'post' ],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['posts_scanned'] );
		$this->assertTrue( $result['truncated'] );
	}

	// ── Schema validation ─────────────────────────────────────────────────

	/**
	 * Each item in the result has all expected keys with correct types.
	 *
	 * @covers \SdAiAgent\Abilities\BlockAbilities::handle_scan_storage_modes
	 */
	public function test_item_structure_is_complete(): void {
		$this->make_post(
			'<!-- wp:acme/schema-test {"a":"1"} --><p>y</p><!-- /wp:acme/schema-test -->'
		);

		$result = BlockAbilities::handle_scan_storage_modes(
			[
				'limit'      => 50,
				'post_types' => [ 'post' ],
			]
		);

		$this->assertIsArray( $result );
		$item = $this->find_item( $result['items'], 'acme/schema-test' );
		$this->assertNotNull( $item );

		$this->assertArrayHasKey( 'block_name', $item );
		$this->assertArrayHasKey( 'storage_mode', $item );
		$this->assertArrayHasKey( 'in_registry', $item );
		$this->assertArrayHasKey( 'occurrences', $item );
		$this->assertArrayHasKey( 'first_post_id', $item );
		$this->assertArrayHasKey( 'evidence', $item );
		$this->assertArrayHasKey( 'attr_keys', $item['evidence'] );
		$this->assertArrayHasKey( 'inner_html_chars', $item['evidence'] );

		$this->assertIsString( $item['block_name'] );
		$this->assertIsString( $item['storage_mode'] );
		$this->assertIsBool( $item['in_registry'] );
		$this->assertIsInt( $item['occurrences'] );
		$this->assertIsInt( $item['first_post_id'] );
		$this->assertIsArray( $item['evidence']['attr_keys'] );
		$this->assertIsInt( $item['evidence']['inner_html_chars'] );

		$this->assertContains(
			$item['storage_mode'],
			[ 'attrs_only', 'inner_html_only', 'dual', 'unknown' ]
		);
	}
}
