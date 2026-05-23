<?php
/**
 * Test case for BlockMutator::apply_batch() (GH#1709).
 *
 * Covers the acceptance criteria from the issue:
 *   AC1: 5-update batch produces exactly 1 WordPress revision.
 *   AC2: Batch where item 3 of 5 has a stale ref → HTTP 400, all errors
 *         itemised, zero revisions, zero changes.
 *   AC3: Empty batch → HTTP 400 empty_batch.
 *   AC4: Batch > MAX_BATCH_SIZE → HTTP 400 batch_too_large with max_batch_size.
 *   AC5: Duplicate targets → batch_validation_failed with duplicate_target per item.
 *   AC6: Full PHPUnit suite passes.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1709
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Abilities\BlockAbilities;
use SdAiAgent\Core\BlockMutator;
use SdAiAgent\Core\BlockReferences;
use SdAiAgent\Core\RevisionGuard;
use WP_UnitTestCase;

/**
 * Integration tests for BlockMutator::apply_batch() and
 * BlockAbilities::handle_update_blocks().
 *
 * Extends WP_UnitTestCase so wp_update_post(), wp_get_post_revisions(),
 * and the full WordPress test infrastructure are available.
 */
class BlockMutatorBatchTest extends WP_UnitTestCase {

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Build a minimal parsed-block array.
	 *
	 * @param string              $name        Block name.
	 * @param array<string,mixed> $attrs       Block attributes.
	 * @param array<int,mixed>    $inner_blocks Inner blocks.
	 * @param string              $inner_html  innerHTML string.
	 * @return array<string,mixed>
	 */
	private function make_block(
		string $name,
		array $attrs = [],
		array $inner_blocks = [],
		string $inner_html = '<p>Content</p>'
	): array {
		$inner_content = [];

		foreach ( $inner_blocks as $ignored ) {
			$inner_content[] = null;
		}

		if ( empty( $inner_blocks ) ) {
			$inner_content = [ $inner_html ];
		}

		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
		];
	}

	/**
	 * Build a block with a stable sd_ref.
	 *
	 * @param string              $name  Block name.
	 * @param string              $ref   sd_ref value.
	 * @param array<string,mixed> $attrs Additional attributes.
	 * @param string              $inner_html innerHTML string.
	 * @return array<string,mixed>
	 */
	private function make_ref_block( string $name, string $ref, array $attrs = [], string $inner_html = '<p>Content</p>' ): array {
		$attrs['metadata'][ BlockReferences::REF_KEY ] = $ref;
		return $this->make_block( $name, $attrs, [], $inner_html );
	}

	/**
	 * Create a WP post with serialized block content.
	 *
	 * @param array<int,mixed> $blocks Parsed block tree.
	 * @return int Post ID.
	 */
	private function create_post_with_blocks( array $blocks ): int {
		$content = serialize_blocks( $blocks );
		$post_id = self::factory()->post->create(
			[
				'post_content' => $content,
				'post_status'  => 'publish',
			]
		);
		$this->assertIsInt( $post_id );
		return $post_id;
	}

	// ── apply_batch() unit tests ──────────────────────────────────────────

	/**
	 * AC3: Empty updates array returns empty_batch error.
	 */
	public function test_empty_batch_returns_error(): void {
		$blocks = [ $this->make_block( 'core/paragraph' ) ];
		$result = BlockMutator::apply_batch( $blocks, [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'empty_batch', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * AC4: Updates exceeding MAX_BATCH_SIZE returns batch_too_large
	 * with data.max_batch_size.
	 */
	public function test_batch_too_large_returns_error(): void {
		$blocks  = [ $this->make_block( 'core/paragraph' ) ];
		$updates = [];

		// 51 updates: one more than the cap.
		for ( $i = 0; $i < BlockMutator::MAX_BATCH_SIZE + 1; $i++ ) {
			$updates[] = [
				'op'         => 'update-attrs',
				'path'       => [ 0 ],
				'attributes' => [ 'level' => 2 ],
			];
		}

		$result = BlockMutator::apply_batch( $blocks, $updates );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'batch_too_large', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( BlockMutator::MAX_BATCH_SIZE, $result->get_error_data()['max_batch_size'] );
	}

	/**
	 * AC5: Two updates targeting the same block (by ref) return
	 * batch_validation_failed with duplicate_target.
	 */
	public function test_duplicate_target_by_ref_returns_error(): void {
		$ref    = 'blk_dup_test01';
		$blocks = [ $this->make_ref_block( 'core/paragraph', $ref ) ];

		$updates = [
			[
				'op'         => 'update-attrs',
				'ref'        => $ref,
				'attributes' => [ 'dropCap' => true ],
			],
			[
				'op'        => 'update-html',
				'ref'       => $ref,
				'innerHTML' => '<p>New text</p>',
			],
		];

		$result = BlockMutator::apply_batch( $blocks, $updates );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'batch_validation_failed', $result->get_error_code() );

		$errors = $result->get_error_data()['errors'] ?? [];
		$this->assertNotEmpty( $errors );

		// The second update should be flagged as duplicate_target.
		$dup_errors = array_filter(
			$errors,
			fn( $e ) => $e['code'] === 'duplicate_target'
		);
		$this->assertNotEmpty( $dup_errors );
	}

	/**
	 * AC5: Two updates targeting the same block by path also detected.
	 */
	public function test_duplicate_target_by_path_returns_error(): void {
		$blocks = [
			$this->make_block( 'core/paragraph', [], [], '<p>A</p>' ),
			$this->make_block( 'core/paragraph', [], [], '<p>B</p>' ),
		];

		$updates = [
			[
				'op'         => 'update-attrs',
				'path'       => [ 0 ],
				'attributes' => [ 'dropCap' => true ],
			],
			[
				'op'        => 'update-html',
				'path'      => [ 0 ],
				'innerHTML' => '<p>Overwrite</p>',
			],
		];

		$result = BlockMutator::apply_batch( $blocks, $updates );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'batch_validation_failed', $result->get_error_code() );

		$errors     = $result->get_error_data()['errors'] ?? [];
		$dup_errors = array_filter(
			$errors,
			fn( $e ) => $e['code'] === 'duplicate_target'
		);
		$this->assertNotEmpty( $dup_errors );
	}

	/**
	 * AC2: A batch where item 3 has an invalid ref rejects the entire
	 * batch with per-item errors, and no modifications to the tree.
	 */
	public function test_partial_failure_rejects_entire_batch(): void {
		$blocks = [
			$this->make_ref_block( 'core/paragraph', 'blk_ok1', [], '<p>P1</p>' ),
			$this->make_ref_block( 'core/heading', 'blk_ok2', [ 'level' => 2 ], '<h2>H</h2>' ),
			$this->make_ref_block( 'core/paragraph', 'blk_ok3', [], '<p>P2</p>' ),
			$this->make_ref_block( 'core/paragraph', 'blk_ok4', [], '<p>P3</p>' ),
			$this->make_ref_block( 'core/paragraph', 'blk_ok5', [], '<p>P4</p>' ),
		];

		$updates = [
			[
				'op'         => 'update-attrs',
				'ref'        => 'blk_ok1',
				'attributes' => [ 'dropCap' => true ],
			],
			[
				'op'         => 'update-attrs',
				'ref'        => 'blk_ok2',
				'attributes' => [ 'level' => 3 ],
			],
			[
				// Item 3 (index 2): stale ref that does not exist.
				'op'         => 'update-attrs',
				'ref'        => 'blk_DOES_NOT_EXIST',
				'attributes' => [ 'dropCap' => true ],
			],
			[
				'op'        => 'update-html',
				'ref'       => 'blk_ok4',
				'innerHTML' => '<p>Updated P3</p>',
			],
			[
				'op'        => 'update-html',
				'ref'       => 'blk_ok5',
				'innerHTML' => '<p>Updated P4</p>',
			],
		];

		$result = BlockMutator::apply_batch( $blocks, $updates );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'batch_validation_failed', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );

		$errors = $result->get_error_data()['errors'] ?? [];
		$this->assertNotEmpty( $errors );

		// The error should reference index 2 (the failing item).
		$failing_indices = array_column( $errors, 'index' );
		$this->assertContains( 2, $failing_indices );
	}

	/**
	 * Successful 5-update batch returns a valid mutated tree.
	 */
	public function test_successful_batch_returns_mutated_tree(): void {
		$blocks = [
			$this->make_ref_block( 'core/paragraph', 'blk_b1', [], '<p>P1</p>' ),
			$this->make_ref_block( 'core/heading', 'blk_b2', [ 'level' => 2 ], '<h2>H</h2>' ),
			$this->make_ref_block( 'core/paragraph', 'blk_b3', [], '<p>P2</p>' ),
			$this->make_ref_block( 'core/paragraph', 'blk_b4', [], '<p>P3</p>' ),
			$this->make_ref_block( 'core/paragraph', 'blk_b5', [], '<p>P4</p>' ),
		];

		$updates = [
			[
				'op'         => 'update-attrs',
				'ref'        => 'blk_b1',
				'attributes' => [ 'dropCap' => true ],
			],
			[
				'op'         => 'update-attrs',
				'ref'        => 'blk_b2',
				'attributes' => [ 'level' => 3 ],
			],
			[
				'op'         => 'update-attrs',
				'ref'        => 'blk_b3',
				'attributes' => [ 'className' => 'highlight' ],
			],
			[
				'op'        => 'update-html',
				'ref'       => 'blk_b4',
				'innerHTML' => '<p>Updated P3</p>',
			],
			[
				'op'        => 'update-html',
				'ref'       => 'blk_b5',
				'innerHTML' => '<p>Updated P4</p>',
			],
		];

		$result = BlockMutator::apply_batch( $blocks, $updates );

		$this->assertIsArray( $result );
		$this->assertCount( 5, $result );
		$this->assertTrue( $result[0]['attrs']['dropCap'] );
		$this->assertSame( 3, $result[1]['attrs']['level'] );
		$this->assertSame( 'highlight', $result[2]['attrs']['className'] );
		$this->assertSame( '<p>Updated P3</p>', $result[3]['innerHTML'] );
		$this->assertSame( '<p>Updated P4</p>', $result[4]['innerHTML'] );
	}

	/**
	 * An invalid op name in the batch is caught in pre-flight.
	 */
	public function test_invalid_op_in_batch(): void {
		$blocks = [ $this->make_block( 'core/paragraph' ) ];

		$result = BlockMutator::apply_batch( $blocks, [
			[
				'op'   => 'nuke-everything',
				'path' => [ 0 ],
			],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'batch_validation_failed', $result->get_error_code() );

		$errors = $result->get_error_data()['errors'] ?? [];
		$this->assertNotEmpty( $errors );
		$this->assertSame( 'invalid_op', $errors[0]['code'] );
	}

	/**
	 * A non-array entry in updates is rejected.
	 */
	public function test_non_array_update_entry(): void {
		$blocks = [ $this->make_block( 'core/paragraph' ) ];

		$result = BlockMutator::apply_batch( $blocks, [ 'not-an-array' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'batch_validation_failed', $result->get_error_code() );
	}

	/**
	 * Errors during application phase (e.g. missing required arg for op)
	 * also result in batch_validation_failed.
	 */
	public function test_application_phase_error_rejects_batch(): void {
		$blocks = [
			$this->make_ref_block( 'core/paragraph', 'blk_app1', [], '<p>P1</p>' ),
			$this->make_ref_block( 'core/paragraph', 'blk_app2', [], '<p>P2</p>' ),
		];

		$updates = [
			[
				'op'         => 'update-attrs',
				'ref'        => 'blk_app1',
				'attributes' => [ 'dropCap' => true ],
			],
			[
				// update-html without innerHTML → missing_inner_html error
				// during application phase.
				'op'  => 'update-html',
				'ref' => 'blk_app2',
				// no 'innerHTML' key
			],
		];

		$result = BlockMutator::apply_batch( $blocks, $updates );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'batch_validation_failed', $result->get_error_code() );

		$errors = $result->get_error_data()['errors'] ?? [];
		$this->assertNotEmpty( $errors );
		$this->assertSame( 1, $errors[0]['index'] );
	}

	// ── handle_update_blocks() integration tests ──────────────────────────

	/**
	 * AC1: A successful 5-update batch produces exactly 1 WordPress revision.
	 */
	public function test_handler_creates_exactly_one_revision(): void {
		$blocks = [
			$this->make_ref_block( 'core/paragraph', 'blk_rev1', [], '<p>P1</p>' ),
			$this->make_ref_block( 'core/heading', 'blk_rev2', [ 'level' => 2 ], '<h2>H</h2>' ),
			$this->make_ref_block( 'core/paragraph', 'blk_rev3', [], '<p>P2</p>' ),
			$this->make_ref_block( 'core/paragraph', 'blk_rev4', [], '<p>P3</p>' ),
			$this->make_ref_block( 'core/paragraph', 'blk_rev5', [], '<p>P4</p>' ),
		];

		$post_id = $this->create_post_with_blocks( $blocks );

		// Count revisions before.
		$revisions_before = count( wp_get_post_revisions( $post_id ) );

		// Run the handler.
		$result = BlockAbilities::handle_update_blocks( [
			'post_id' => $post_id,
			'updates' => [
				[
					'op'         => 'update-attrs',
					'ref'        => 'blk_rev1',
					'attributes' => [ 'dropCap' => true ],
				],
				[
					'op'         => 'update-attrs',
					'ref'        => 'blk_rev2',
					'attributes' => [ 'level' => 3 ],
				],
				[
					'op'         => 'update-attrs',
					'ref'        => 'blk_rev3',
					'attributes' => [ 'className' => 'highlight' ],
				],
				[
					'op'        => 'update-html',
					'ref'       => 'blk_rev4',
					'innerHTML' => '<p>Updated P3</p>',
				],
				[
					'op'        => 'update-html',
					'ref'       => 'blk_rev5',
					'innerHTML' => '<p>Updated P4</p>',
				],
			],
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 5, $result['updates'] );

		// Count revisions after.
		$revisions_after = count( wp_get_post_revisions( $post_id ) );

		// Exactly 1 new revision.
		$this->assertSame( 1, $revisions_after - $revisions_before );
	}

	/**
	 * AC2: A batch with a failing item (stale ref) creates zero revisions
	 * and zero changes to post_content.
	 */
	public function test_handler_failed_batch_creates_zero_revisions(): void {
		$blocks = [
			$this->make_ref_block( 'core/paragraph', 'blk_z1', [], '<p>P1</p>' ),
			$this->make_ref_block( 'core/heading', 'blk_z2', [ 'level' => 2 ], '<h2>H</h2>' ),
			$this->make_ref_block( 'core/paragraph', 'blk_z3', [], '<p>P2</p>' ),
			$this->make_ref_block( 'core/paragraph', 'blk_z4', [], '<p>P3</p>' ),
			$this->make_ref_block( 'core/paragraph', 'blk_z5', [], '<p>P4</p>' ),
		];

		$post_id = $this->create_post_with_blocks( $blocks );

		$content_before   = get_post( $post_id )->post_content;
		$revisions_before = count( wp_get_post_revisions( $post_id ) );

		// Item index 2 has a non-existent ref.
		$result = BlockAbilities::handle_update_blocks( [
			'post_id' => $post_id,
			'updates' => [
				[
					'op'         => 'update-attrs',
					'ref'        => 'blk_z1',
					'attributes' => [ 'dropCap' => true ],
				],
				[
					'op'         => 'update-attrs',
					'ref'        => 'blk_z2',
					'attributes' => [ 'level' => 3 ],
				],
				[
					'op'         => 'update-attrs',
					'ref'        => 'blk_STALE_REF',
					'attributes' => [ 'dropCap' => true ],
				],
				[
					'op'        => 'update-html',
					'ref'       => 'blk_z4',
					'innerHTML' => '<p>Updated P3</p>',
				],
				[
					'op'        => 'update-html',
					'ref'       => 'blk_z5',
					'innerHTML' => '<p>Updated P4</p>',
				],
			],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'batch_validation_failed', $result->get_error_code() );

		// Zero revisions.
		$revisions_after = count( wp_get_post_revisions( $post_id ) );
		$this->assertSame( 0, $revisions_after - $revisions_before );

		// Zero changes to post_content.
		$content_after = get_post( $post_id )->post_content;
		$this->assertSame( $content_before, $content_after );
	}

	/**
	 * AC3: Handler returns empty_batch for empty updates array.
	 */
	public function test_handler_empty_batch(): void {
		$blocks  = [ $this->make_block( 'core/paragraph' ) ];
		$post_id = $this->create_post_with_blocks( $blocks );

		$result = BlockAbilities::handle_update_blocks( [
			'post_id' => $post_id,
			'updates' => [],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'empty_batch', $result->get_error_code() );
	}

	/**
	 * AC4: Handler returns batch_too_large with max_batch_size.
	 */
	public function test_handler_batch_too_large(): void {
		$blocks  = [ $this->make_block( 'core/paragraph' ) ];
		$post_id = $this->create_post_with_blocks( $blocks );

		$updates = [];
		for ( $i = 0; $i < BlockMutator::MAX_BATCH_SIZE + 1; $i++ ) {
			$updates[] = [
				'op'         => 'update-attrs',
				'path'       => [ 0 ],
				'attributes' => [ 'level' => 2 ],
			];
		}

		$result = BlockAbilities::handle_update_blocks( [
			'post_id' => $post_id,
			'updates' => $updates,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'batch_too_large', $result->get_error_code() );
		$this->assertSame( BlockMutator::MAX_BATCH_SIZE, $result->get_error_data()['max_batch_size'] );
	}

	/**
	 * AC5: Handler detects duplicate targets and rejects.
	 */
	public function test_handler_duplicate_targets(): void {
		$blocks = [
			$this->make_ref_block( 'core/paragraph', 'blk_hdup1', [], '<p>P1</p>' ),
		];
		$post_id = $this->create_post_with_blocks( $blocks );

		$result = BlockAbilities::handle_update_blocks( [
			'post_id' => $post_id,
			'updates' => [
				[
					'op'         => 'update-attrs',
					'ref'        => 'blk_hdup1',
					'attributes' => [ 'dropCap' => true ],
				],
				[
					'op'        => 'update-html',
					'ref'       => 'blk_hdup1',
					'innerHTML' => '<p>Overwrite</p>',
				],
			],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'batch_validation_failed', $result->get_error_code() );

		$errors     = $result->get_error_data()['errors'] ?? [];
		$dup_errors = array_filter(
			$errors,
			fn( $e ) => $e['code'] === 'duplicate_target'
		);
		$this->assertNotEmpty( $dup_errors );
	}

	/**
	 * Handler rejects missing post_id.
	 */
	public function test_handler_missing_post_id(): void {
		$result = BlockAbilities::handle_update_blocks( [
			'updates' => [
				[
					'op'         => 'update-attrs',
					'path'       => [ 0 ],
					'attributes' => [ 'level' => 2 ],
				],
			],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_post_id', $result->get_error_code() );
	}

	/**
	 * Handler rejects non-existent post.
	 */
	public function test_handler_post_not_found(): void {
		$result = BlockAbilities::handle_update_blocks( [
			'post_id' => 999999,
			'updates' => [
				[
					'op'         => 'update-attrs',
					'path'       => [ 0 ],
					'attributes' => [ 'level' => 2 ],
				],
			],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	/**
	 * dry_run mode validates and returns the tree without persisting.
	 */
	public function test_handler_dry_run_does_not_persist(): void {
		$blocks = [
			$this->make_ref_block( 'core/paragraph', 'blk_dry1', [], '<p>Original</p>' ),
		];
		$post_id = $this->create_post_with_blocks( $blocks );

		$content_before   = get_post( $post_id )->post_content;
		$revisions_before = count( wp_get_post_revisions( $post_id ) );

		$result = BlockAbilities::handle_update_blocks( [
			'post_id' => $post_id,
			'dry_run' => true,
			'updates' => [
				[
					'op'        => 'update-html',
					'ref'       => 'blk_dry1',
					'innerHTML' => '<p>Changed</p>',
				],
			],
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['dry_run'] );

		// Content should NOT have changed.
		$content_after = get_post( $post_id )->post_content;
		$this->assertSame( $content_before, $content_after );

		// No new revisions.
		$revisions_after = count( wp_get_post_revisions( $post_id ) );
		$this->assertSame( 0, $revisions_after - $revisions_before );
	}

	/**
	 * Batch at exactly MAX_BATCH_SIZE (50) succeeds.
	 */
	public function test_batch_at_max_size_succeeds(): void {
		$blocks = [];
		for ( $i = 0; $i < BlockMutator::MAX_BATCH_SIZE; $i++ ) {
			$blocks[] = $this->make_ref_block(
				'core/paragraph',
				'blk_max' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT ),
				[],
				'<p>Block ' . $i . '</p>'
			);
		}

		$updates = [];
		for ( $i = 0; $i < BlockMutator::MAX_BATCH_SIZE; $i++ ) {
			$updates[] = [
				'op'         => 'update-attrs',
				'ref'        => 'blk_max' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT ),
				'attributes' => [ 'className' => 'batch-' . $i ],
			];
		}

		$result = BlockMutator::apply_batch( $blocks, $updates );

		$this->assertIsArray( $result );
		$this->assertCount( BlockMutator::MAX_BATCH_SIZE, $result );
	}

	// ── Tier policy enforcement tests (GH#1735) ────────────────────────────

	/**
	 * Batch with one legacy insert-child item is entirely rejected.
	 *
	 * AC1: Batch with one legacy item → entire batch rejected, no disk write.
	 */
	public function test_batch_with_legacy_insert_child_rejected(): void {
		$blocks = [
			$this->make_ref_block( 'core/paragraph', 'blk_p1', [], '<p>Para 1</p>' ),
			$this->make_ref_block( 'core/paragraph', 'blk_p2', [], '<p>Para 2</p>' ),
		];

		$updates = [
			[
				'op'  => 'update-attrs',
				'ref' => 'blk_p1',
				'attributes' => [ 'className' => 'updated' ],
			],
			[
				'op'        => 'insert-child',
				'ref'       => 'blk_p2',
				'block_def' => [
					'blockName' => 'core/freeform',
					'attrs'     => [],
					'innerHTML' => 'legacy block',
				],
			],
		];

		$result = BlockMutator::apply_batch( $blocks, $updates );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'batch_validation_failed', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertArrayHasKey( 'errors', $data );
		$this->assertNotEmpty( $data['errors'] );

		// The error should be for the second update (index 1).
		$error = $data['errors'][0];
		$this->assertSame( 1, $error['index'] );
		$this->assertSame( 'legacy_block', $error['code'] );
	}

	/**
	 * Batch with one legacy replace-block item is entirely rejected.
	 *
	 * AC2: Batch with legacy replace-block → entire batch rejected.
	 */
	public function test_batch_with_legacy_replace_block_rejected(): void {
		$blocks = [
			$this->make_ref_block( 'core/paragraph', 'blk_p1', [], '<p>Para 1</p>' ),
		];

		$updates = [
			[
				'op'        => 'replace-block',
				'ref'       => 'blk_p1',
				'block_def' => [
					'blockName' => 'core/legacy-widget',
					'attrs'     => [],
					'innerHTML' => '',
				],
			],
		];

		$result = BlockMutator::apply_batch( $blocks, $updates );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'batch_validation_failed', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertArrayHasKey( 'errors', $data );
		$error = $data['errors'][0];
		$this->assertSame( 'legacy_block', $error['code'] );
	}

	/**
	 * Batch with all preferred blocks succeeds.
	 *
	 * AC3: Batch with all preferred blocks → success.
	 */
	public function test_batch_with_all_preferred_blocks_succeeds(): void {
		$blocks = [
			$this->make_ref_block( 'core/paragraph', 'blk_p1', [], '<p>Para 1</p>' ),
			$this->make_ref_block( 'core/paragraph', 'blk_p2', [], '<p>Para 2</p>' ),
		];

		$updates = [
			[
				'op'        => 'insert-child',
				'ref'       => 'blk_p1',
				'block_def' => [
					'blockName' => 'core/paragraph',
					'attrs'     => [],
					'innerHTML' => '<p>New child</p>',
				],
			],
			[
				'op'        => 'replace-block',
				'ref'       => 'blk_p2',
				'block_def' => [
					'blockName' => 'core/heading',
					'attrs'     => [ 'level' => 2 ],
					'innerHTML' => '<h2>Heading</h2>',
				],
			],
		];

		$result = BlockMutator::apply_batch( $blocks, $updates );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
	}
}
