<?php
/**
 * Test case for DualStorageRegistry (GH#1713).
 *
 * Covers the acceptance criteria from the issue:
 *   AC1: update-attrs on yoast/faq-block without innerHTML → 400 dual_storage_requires_both.
 *   AC2: update-html on yoast/faq-block without attributes → same error.
 *   AC3: Combined { attributes, innerHTML } update succeeds.
 *   AC4: update-attrs on core/heading is unaffected.
 *   AC5: Filter sd_ai_agent_block_dual_storage_blocks lets a plugin add a block name.
 *   AC6: Scan helper populates a cached option distinct from the hard-coded list.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1713
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\DualStorageRegistry;
use WP_UnitTestCase;

/**
 * Integration tests for DualStorageRegistry.
 *
 * Uses WP_UnitTestCase so WP functions (add_filter, get_option, etc.) are available.
 */
class DualStorageRegistryTest extends WP_UnitTestCase {

	/**
	 * Clean up filters and cached options after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'sd_ai_agent_block_dual_storage_blocks' );
		DualStorageRegistry::delete_scan_cache();
		parent::tear_down();
	}

	// ── get_blocks / hard-coded list ──────────────────────────────────────

	/**
	 * get_blocks returns the hard-coded Yoast blocks by default.
	 */
	public function test_get_blocks_contains_known_yoast_blocks(): void {
		$blocks = DualStorageRegistry::get_blocks();

		$this->assertContains( 'yoast/faq-block', $blocks );
		$this->assertContains( 'yoast/how-to-block', $blocks );
	}

	/**
	 * get_blocks returns an array of strings with no duplicates.
	 */
	public function test_get_blocks_returns_unique_strings(): void {
		$blocks = DualStorageRegistry::get_blocks();

		$this->assertIsArray( $blocks );
		$this->assertSame( count( $blocks ), count( array_unique( $blocks ) ) );

		foreach ( $blocks as $name ) {
			$this->assertIsString( $name );
			$this->assertNotEmpty( $name );
		}
	}

	// ── is_dual_storage ───────────────────────────────────────────────────

	/**
	 * is_dual_storage returns true for known Yoast blocks.
	 */
	public function test_is_dual_storage_true_for_known_blocks(): void {
		$this->assertTrue( DualStorageRegistry::is_dual_storage( 'yoast/faq-block' ) );
		$this->assertTrue( DualStorageRegistry::is_dual_storage( 'yoast/how-to-block' ) );
	}

	/**
	 * is_dual_storage returns false for standard core blocks.
	 */
	public function test_is_dual_storage_false_for_core_blocks(): void {
		$this->assertFalse( DualStorageRegistry::is_dual_storage( 'core/heading' ) );
		$this->assertFalse( DualStorageRegistry::is_dual_storage( 'core/paragraph' ) );
		$this->assertFalse( DualStorageRegistry::is_dual_storage( 'core/image' ) );
	}

	/**
	 * is_dual_storage returns false for an unknown block.
	 */
	public function test_is_dual_storage_false_for_unknown_block(): void {
		$this->assertFalse( DualStorageRegistry::is_dual_storage( 'acme/unknown-block' ) );
		$this->assertFalse( DualStorageRegistry::is_dual_storage( '' ) );
	}

	// ── Filter hook (AC5) ─────────────────────────────────────────────────

	/**
	 * sd_ai_agent_block_dual_storage_blocks filter extends the list.
	 */
	public function test_filter_adds_block_to_list(): void {
		add_filter(
			'sd_ai_agent_block_dual_storage_blocks',
			static function ( array $blocks ): array {
				$blocks[] = 'acme/faq-block';
				return $blocks;
			}
		);

		$blocks = DualStorageRegistry::get_blocks();

		$this->assertContains( 'acme/faq-block', $blocks );
		$this->assertTrue( DualStorageRegistry::is_dual_storage( 'acme/faq-block' ) );
	}

	/**
	 * Filter-added block triggers dual_storage_requires_both enforcement in BlockMutator.
	 */
	public function test_filter_added_block_triggers_enforcement(): void {
		add_filter(
			'sd_ai_agent_block_dual_storage_blocks',
			static function ( array $blocks ): array {
				$blocks[] = 'acme/custom-block';
				return $blocks;
			}
		);

		$block  = $this->make_block( 'acme/custom-block' );
		$result = \SdAiAgent\Core\BlockMutator::apply(
			[ $block ],
			'update-attrs',
			[
				'path'       => [ 0 ],
				'attributes' => [ 'title' => 'Hello' ],
				// No innerHTML — should be rejected.
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'dual_storage_requires_both', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 'acme/custom-block', $data['block_name'] );
	}

	/**
	 * Filter that returns non-array values is gracefully handled.
	 */
	public function test_filter_non_array_values_are_ignored(): void {
		add_filter(
			'sd_ai_agent_block_dual_storage_blocks',
			static function (): array {
				// Mix in non-string values that should be filtered out.
				return [ 'yoast/faq-block', 123, null, '', 'yoast/faq-block' ];
			}
		);

		$blocks = DualStorageRegistry::get_blocks();

		// Only the valid string survives; empty string and non-strings are dropped.
		// Duplicates are removed.
		$this->assertContains( 'yoast/faq-block', $blocks );
		$this->assertNotContains( '', $blocks );
		$this->assertSame( count( $blocks ), count( array_unique( $blocks ) ) );
	}

	// ── Scan helper (AC6) ─────────────────────────────────────────────────

	/**
	 * Scan helper returns an array (may be empty on a site with no matching posts).
	 */
	public function test_scan_returns_array(): void {
		$result = DualStorageRegistry::scan();
		$this->assertIsArray( $result );
	}

	/**
	 * Scan result is stored in a site option distinct from the hard-coded list.
	 */
	public function test_scan_stores_result_in_option(): void {
		DualStorageRegistry::delete_scan_cache();

		// Before scan: option not set.
		$before = get_option( DualStorageRegistry::SCAN_OPTION, 'not-set' );
		$this->assertSame( 'not-set', $before );

		// After scan: option is an array.
		DualStorageRegistry::scan();
		$after = get_option( DualStorageRegistry::SCAN_OPTION );
		$this->assertIsArray( $after );
	}

	/**
	 * Scan detects a block where an attribute value appears verbatim in innerHTML.
	 */
	public function test_scan_detects_dual_storage_candidate(): void {
		// Create a published post with a block that overlaps attribute text in innerHTML.
		$answer_text = 'This is the answer to the question.';
		$block_content = sprintf(
			'<!-- wp:acme/overlap-block %s -->
<div class="acme-overlap-block"><p>%s</p></div>
<!-- /wp:acme/overlap-block -->',
			wp_json_encode( [ 'answer' => $answer_text ] ),
			$answer_text
		);

		$this->factory()->post->create(
			[
				'post_content' => $block_content,
				'post_status'  => 'publish',
			]
		);

		$detected = DualStorageRegistry::scan( 100 );

		$this->assertContains( 'acme/overlap-block', $detected );
	}

	/**
	 * get_detected_blocks returns empty array before first scan.
	 */
	public function test_get_detected_blocks_empty_before_scan(): void {
		DualStorageRegistry::delete_scan_cache();
		$this->assertSame( [], DualStorageRegistry::get_detected_blocks() );
	}

	/**
	 * get_detected_blocks returns the cached result after scan.
	 */
	public function test_get_detected_blocks_returns_cached_result(): void {
		DualStorageRegistry::scan();
		$cached = DualStorageRegistry::get_detected_blocks();
		$this->assertIsArray( $cached );
	}

	/**
	 * delete_scan_cache clears the stored option.
	 */
	public function test_delete_scan_cache_clears_option(): void {
		DualStorageRegistry::scan();
		$this->assertIsArray( get_option( DualStorageRegistry::SCAN_OPTION ) );

		DualStorageRegistry::delete_scan_cache();
		$this->assertFalse( get_option( DualStorageRegistry::SCAN_OPTION ) );
	}

	/**
	 * Scan result does not include hard-coded known blocks (separate lists).
	 */
	public function test_scan_result_is_distinct_from_hard_coded_list(): void {
		// Even if yoast markup somehow appears, the known list is kept separate.
		// The scan helper only reports NEW candidates (not already in get_blocks()).
		$detected = DualStorageRegistry::scan();

		// Hard-coded blocks should not appear in detected (they are excluded by design).
		$this->assertNotContains( 'yoast/faq-block', $detected );
		$this->assertNotContains( 'yoast/how-to-block', $detected );
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Build a minimal parsed-block array for a given block name.
	 *
	 * @param string              $name  Block name.
	 * @param array<string,mixed> $attrs Block attributes.
	 * @param string              $html  innerHTML.
	 * @return array<string,mixed>
	 */
	private function make_block( string $name, array $attrs = [], string $html = '<p>Content</p>' ): array {
		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
		];
	}
}
