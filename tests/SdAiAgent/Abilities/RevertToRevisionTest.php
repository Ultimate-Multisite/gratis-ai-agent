<?php
/**
 * Test case for the sd-ai-agent/revert-to-revision ability handler (GH#1749).
 *
 * Covers:
 *   AC1: Happy path — revert succeeds, new revision created, content matches old revision.
 *   AC2: Revision belonging to a different post → revision_post_mismatch.
 *   AC3: expected_current_revision_id mismatch → revision_stale.
 *   AC4: User without edit_post capability → insufficient_capability.
 *   AC5: All blocks receive fresh sd_ref values after revert (refs_reseeded > 0).
 *   Validation: missing/invalid post_id, revision_id, nonexistent post/revision.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1749
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\BlockAbilities;
use SdAiAgent\Core\BlockMutator;
use SdAiAgent\Core\RevisionGuard;
use WP_UnitTestCase;

/**
 * Integration tests for BlockAbilities::handle_revert_to_revision.
 *
 * Uses WP_UnitTestCase so real posts, revisions, and WordPress functions
 * are available.
 */
class RevertToRevisionTest extends WP_UnitTestCase {

	/**
	 * Administrator user ID used for all tests.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Set up an administrator user context before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Restore user context after each test.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Create a published post with a single paragraph block.
	 *
	 * @param string $text Paragraph text.
	 * @return int Post ID.
	 */
	private function create_post_with_content( string $text = 'Original content' ): int {
		$content = serialize_blocks( [
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '<p>' . esc_html( $text ) . '</p>',
				'innerContent' => [ '<p>' . esc_html( $text ) . '</p>' ],
			],
		] );

		$post_id = self::factory()->post->create( [
			'post_content' => $content,
			'post_status'  => 'publish',
		] );

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		return $post_id;
	}

	/**
	 * Update a post with new paragraph content.
	 *
	 * Returns the post's latest revision ID after the update (or 0 if no
	 * revisions exist yet). Does NOT assert that a new revision was created —
	 * the test environment may merge rapid revisions within the same second.
	 *
	 * @param int    $post_id Post ID to update.
	 * @param string $text    New paragraph text.
	 * @return int Post-update current revision ID.
	 */
	private function update_post_content( int $post_id, string $text ): int {
		$content = serialize_blocks( [
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '<p>' . esc_html( $text ) . '</p>',
				'innerContent' => [ '<p>' . esc_html( $text ) . '</p>' ],
			],
		] );

		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => $content,
		] );

		return RevisionGuard::current_revision_id( $post_id );
	}

	// ── Input validation ──────────────────────────────────────────────────

	/**
	 * Missing post_id returns WP_Error with code missing_post_id.
	 */
	public function test_missing_post_id_returns_error(): void {
		$result = BlockAbilities::handle_revert_to_revision( [
			'revision_id' => 999,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_post_id', $result->get_error_code() );
	}

	/**
	 * Missing revision_id returns WP_Error with code missing_revision_id.
	 */
	public function test_missing_revision_id_returns_error(): void {
		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id' => 1,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_revision_id', $result->get_error_code() );
	}

	/**
	 * Nonexistent post returns WP_Error with code post_not_found.
	 */
	public function test_nonexistent_post_returns_error(): void {
		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'     => 999999,
			'revision_id' => 1,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	/**
	 * Nonexistent revision returns WP_Error with code revision_not_found.
	 */
	public function test_nonexistent_revision_returns_error(): void {
		$post_id = $this->create_post_with_content();

		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'     => $post_id,
			'revision_id' => 999999,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'revision_not_found', $result->get_error_code() );
	}

	// ── AC1: Happy path ───────────────────────────────────────────────────

	/**
	 * AC1: Revert to a known revision succeeds; content matches the old revision.
	 *
	 * Two updates are needed: the first creates a revision with C1 ("Version one");
	 * the second creates a revision with C2 ("Version two") as the current state.
	 * Reverting to the first revision restores C1.
	 */
	public function test_happy_path_revert_restores_content(): void {
		$post_id = $this->create_post_with_content( 'Version zero' );

		// First update → revision R1 captures "Version one".
		$this->update_post_content( $post_id, 'Version one' );

		// Second update → revision R2 captures "Version two"; current = R2.
		$this->update_post_content( $post_id, 'Version two' );

		// Get the oldest revision (R1 — content: "Version one").
		$revisions = wp_get_post_revisions( $post_id, [ 'orderby' => 'ID', 'order' => 'ASC' ] );
		$this->assertNotEmpty( $revisions, 'Post should have at least one revision' );
		$first_revision = reset( $revisions );

		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'     => $post_id,
			'revision_id' => $first_revision->ID,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'post_id', $result );
		$this->assertArrayHasKey( 'reverted_to_revision_id', $result );
		$this->assertArrayHasKey( 'new_revision_id', $result );
		$this->assertArrayHasKey( 'refs_reseeded', $result );
		$this->assertArrayHasKey( 'block_count', $result );

		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( $first_revision->ID, $result['reverted_to_revision_id'] );
		$this->assertGreaterThan( 0, $result['new_revision_id'] );

		// Post content should now contain "Version one" (the content of R1).
		$post = get_post( $post_id );
		$this->assertStringContainsString( 'Version one', $post->post_content );
	}

	/**
	 * AC1: block_count reflects the number of named blocks in the restored post.
	 */
	public function test_block_count_matches_restored_blocks(): void {
		$post_id = $this->create_post_with_content( 'Original' );
		$this->update_post_content( $post_id, 'Single block post' );
		$this->update_post_content( $post_id, 'Updated content' );

		$revisions      = wp_get_post_revisions( $post_id, [ 'orderby' => 'ID', 'order' => 'ASC' ] );
		$first_revision = reset( $revisions );

		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'     => $post_id,
			'revision_id' => $first_revision->ID,
		] );

		$this->assertIsArray( $result );
		// Single-paragraph post → 1 block.
		$this->assertSame( 1, $result['block_count'] );
	}

	// ── AC2: Revision belongs to wrong post ───────────────────────────────

	/**
	 * AC2: Revision belonging to a different post returns revision_post_mismatch.
	 */
	public function test_wrong_post_revision_returns_mismatch_error(): void {
		$post_a = $this->create_post_with_content( 'Post A original' );
		$post_b = $this->create_post_with_content( 'Post B original' );

		// Create a revision on post B.
		$this->update_post_content( $post_b, 'Post B updated' );
		$revisions_b      = wp_get_post_revisions( $post_b, [ 'orderby' => 'ID', 'order' => 'ASC' ] );
		$first_revision_b = reset( $revisions_b );

		// Attempt to revert post A using a revision from post B.
		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'     => $post_a,
			'revision_id' => $first_revision_b->ID,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'revision_post_mismatch', $result->get_error_code() );
	}

	// ── AC3: Optimistic concurrency ───────────────────────────────────────

	/**
	 * AC3: Stale expected_current_revision_id returns revision_stale with
	 * current_revision_id in the error data so callers can re-fetch and retry.
	 *
	 * Passes a clearly-invalid expected_current_revision_id (PHP_INT_MAX) which
	 * will never match any real revision, making the test immune to timing
	 * issues in the test environment's revision merging behaviour.
	 */
	public function test_stale_concurrency_returns_error(): void {
		$post_id = $this->create_post_with_content( 'Initial' );

		// Create at least one revision to have a valid target.
		$this->update_post_content( $post_id, 'First update' );
		$revisions  = wp_get_post_revisions( $post_id, [ 'orderby' => 'ID', 'order' => 'ASC' ] );
		$target_rev = reset( $revisions );
		$this->assertNotEmpty( $revisions, 'Post should have at least one revision after update' );

		$current_rev = RevisionGuard::current_revision_id( $post_id );

		// Pass a clearly wrong expected_current_revision_id (PHP_INT_MAX never
		// matches a real WordPress post ID), so we reliably trigger stale.
		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'                      => $post_id,
			'revision_id'                  => $target_rev->ID,
			'expected_current_revision_id' => PHP_INT_MAX,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'revision_stale', $result->get_error_code() );

		// Error data must include current_revision_id so the caller can retry.
		$data = $result->get_error_data( 'revision_stale' );
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'current_revision_id', $data );
		// current_revision_id in error data should match the actual current revision.
		$this->assertSame( $current_rev, $data['current_revision_id'] );
	}

	/**
	 * Matching expected_current_revision_id allows the revert to proceed.
	 */
	public function test_correct_concurrency_allows_revert(): void {
		$post_id = $this->create_post_with_content( 'Concurrency test original' );
		$this->update_post_content( $post_id, 'Concurrency state A' );
		$this->update_post_content( $post_id, 'Concurrency state B' );

		$current_rev = RevisionGuard::current_revision_id( $post_id );

		$revisions  = wp_get_post_revisions( $post_id, [ 'orderby' => 'ID', 'order' => 'ASC' ] );
		$target_rev = reset( $revisions );

		// Pass the correct current revision → should succeed.
		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'                      => $post_id,
			'revision_id'                  => $target_rev->ID,
			'expected_current_revision_id' => $current_rev,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'new_revision_id', $result );
	}

	// ── AC4: Capability check ────────────────────────────────────────────

	/**
	 * AC4: User without edit_post capability returns insufficient_capability.
	 */
	public function test_insufficient_capability_returns_error(): void {
		$post_id = $this->create_post_with_content( 'Privileged post' );
		$this->update_post_content( $post_id, 'Admin edit A' );
		$this->update_post_content( $post_id, 'Admin edit B' );

		$revisions = wp_get_post_revisions( $post_id, [ 'orderby' => 'ID', 'order' => 'ASC' ] );
		$this->assertNotEmpty( $revisions, 'Post should have at least one revision' );
		$first_revision = reset( $revisions );

		// Switch to a subscriber who cannot edit posts.
		$subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'     => $post_id,
			'revision_id' => $first_revision->ID,
		] );

		// Restore admin before assertions (so failure doesn't bleed into other tests).
		wp_set_current_user( $this->admin_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'insufficient_capability', $result->get_error_code() );
	}

	// ── AC5: Ref reseeding ────────────────────────────────────────────────

	/**
	 * AC5: All blocks receive fresh sd_ref values after the revert.
	 *
	 * refs_reseeded > 0 and every block in the post has a valid blk_* ref.
	 */
	public function test_refs_reseeded_after_revert(): void {
		$post_id = $this->create_post_with_content( 'Ref test original' );

		// Assign refs to the initial content.
		BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => true,
		] );

		// First update → R1 with "Ref test state A".
		$this->update_post_content( $post_id, 'Ref test state A' );

		// Second update → R2 with "Ref test state B" (current).
		$this->update_post_content( $post_id, 'Ref test state B' );

		// R1 has "Ref test state A" (different from current "Ref test state B").
		$revisions      = wp_get_post_revisions( $post_id, [ 'orderby' => 'ID', 'order' => 'ASC' ] );
		$first_revision = reset( $revisions );

		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'     => $post_id,
			'revision_id' => $first_revision->ID,
		] );

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['refs_reseeded'], 'refs_reseeded should be > 0 after revert' );

		// For a flat single-block post, refs_reseeded and block_count should agree.
		$this->assertSame( $result['block_count'], $result['refs_reseeded'] );

		// Verify every block in the post now carries a valid sd_ref.
		$page_result = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => false,
		] );

		$this->assertIsArray( $page_result );
		$this->assertNotEmpty( $page_result['blocks'] );

		foreach ( $page_result['blocks'] as $block ) {
			$this->assertArrayHasKey( 'ref', $block, "Block '{$block['name']}' should have a ref after revert." );
			$this->assertNotEmpty( $block['ref'] );
			$this->assertStringStartsWith( 'blk_', $block['ref'] );
		}
	}

	// ── Return value structure ────────────────────────────────────────────

	/**
	 * new_revision_id differs from reverted_to_revision_id (restore creates a new rev).
	 *
	 * Two updates are needed so R1's content differs from the current post content.
	 * When the reverted content is different from the current, wp_restore_post_revision
	 * calls wp_update_post which creates a new revision with a larger ID.
	 */
	public function test_new_revision_differs_from_source_revision(): void {
		$post_id = $this->create_post_with_content( 'Original state' );
		// R1: "First edit"
		$this->update_post_content( $post_id, 'First edit' );
		// R2: "Second edit" (current)
		$this->update_post_content( $post_id, 'Second edit' );

		// R1 is the oldest revision (content = "First edit"), which differs from current ("Second edit").
		$revisions      = wp_get_post_revisions( $post_id, [ 'orderby' => 'ID', 'order' => 'ASC' ] );
		$first_revision = reset( $revisions );

		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'     => $post_id,
			'revision_id' => $first_revision->ID,
		] );

		$this->assertIsArray( $result );
		// wp_restore_post_revision calls wp_update_post which creates a new revision.
		// The new revision ID is always larger than the source revision ID.
		$this->assertGreaterThan(
			$result['reverted_to_revision_id'],
			$result['new_revision_id'],
			'wp_restore_post_revision creates a new revision; new_revision_id must be greater than the source.'
		);
	}

	// ── GH#1786: null expected_current_revision_id ────────────────────────

	/**
	 * AC4 (GH#1786): expected_current_revision_id: null skips the precondition
	 * and allows the revert to proceed.
	 *
	 * @see https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1786
	 */
	public function test_null_expected_current_revision_id_skips_precondition(): void {
		$post_id = $this->create_post_with_content( 'Null precondition original' );
		$this->update_post_content( $post_id, 'Null precondition state A' );
		$this->update_post_content( $post_id, 'Null precondition state B' );

		$revisions  = wp_get_post_revisions( $post_id, [ 'orderby' => 'ID', 'order' => 'ASC' ] );
		$target_rev = reset( $revisions );

		// Explicitly passing null must not trigger stale_revision.
		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'                      => $post_id,
			'revision_id'                  => $target_rev->ID,
			'expected_current_revision_id' => null,
		] );

		$this->assertIsArray( $result, 'Null expected_current_revision_id must allow the revert (no WP_Error)' );
		$this->assertArrayHasKey( 'new_revision_id', $result );
	}

	/**
	 * AC5 (GH#1786): expected_current_revision_id: 0 must produce revision_stale
	 * because 0 is a concrete value that should not match any real revision ID.
	 *
	 * @see https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1786
	 */
	public function test_zero_expected_current_revision_id_produces_stale_revision(): void {
		$post_id = $this->create_post_with_content( 'Zero precondition original' );
		$this->update_post_content( $post_id, 'Zero precondition state A' );

		$revisions  = wp_get_post_revisions( $post_id, [ 'orderby' => 'ID', 'order' => 'ASC' ] );
		$target_rev = reset( $revisions );

		// 0 is never a real revision ID; current revision is a positive integer → mismatch.
		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'                      => $post_id,
			'revision_id'                  => $target_rev->ID,
			'expected_current_revision_id' => 0,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result, 'expected_current_revision_id: 0 must return revision_stale' );
		$this->assertSame( 'revision_stale', $result->get_error_code() );
	}

	// ── BlockMutator::revert_to_revision direct test ──────────────────────

	/**
	 * BlockMutator::revert_to_revision returns all required keys on success.
	 */
	public function test_block_mutator_revert_returns_expected_keys(): void {
		$post_id = $this->create_post_with_content( 'Direct mutator test' );
		$this->update_post_content( $post_id, 'Mutator state A' );
		$this->update_post_content( $post_id, 'Mutator state B' );

		$revisions      = wp_get_post_revisions( $post_id, [ 'orderby' => 'ID', 'order' => 'ASC' ] );
		$first_revision = reset( $revisions );

		$result = BlockMutator::revert_to_revision( $post_id, $first_revision->ID );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'post_id', $result );
		$this->assertArrayHasKey( 'reverted_to_revision_id', $result );
		$this->assertArrayHasKey( 'new_revision_id', $result );
		$this->assertArrayHasKey( 'refs_reseeded', $result );
		$this->assertArrayHasKey( 'block_count', $result );
		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( $first_revision->ID, $result['reverted_to_revision_id'] );
	}
}
