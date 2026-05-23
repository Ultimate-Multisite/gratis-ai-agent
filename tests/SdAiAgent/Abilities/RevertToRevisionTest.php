<?php
/**
 * Test case for the sd-ai-agent/revert-to-revision ability (GH#1749).
 *
 * Covers the ability handler surface:
 *   - handle_revert_to_revision: input validation, delegation to BlockMutator.
 *   - BlockMutator::revert_to_revision: happy path, cap check, wrong-post revision,
 *     stale concurrency, ref reseeding count.
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
 * Integration tests for BlockAbilities::handle_revert_to_revision
 * and BlockMutator::revert_to_revision.
 *
 * Uses WP_UnitTestCase so real posts, revisions, parse_blocks(), and
 * serialize_blocks() are available.
 *
 * setUp()/tearDown() ensure every test runs as an administrator so that
 * capability checks do not interfere with non-capability assertions.
 */
class RevertToRevisionTest extends WP_UnitTestCase {

	/**
	 * Administrator user ID created fresh for each test.
	 *
	 * Using a factory-created admin rather than user ID 1 avoids role-corruption
	 * side effects from other tests in the full suite that may demote user 1.
	 *
	 * @var int
	 */
	private int $admin_id = 0;

	/**
	 * Create a fresh administrator user and set it as current user.
	 *
	 * WP_UnitTestCase defaults to user 0 (not logged in), which would fail
	 * capability checks and mask non-capability assertion failures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Restore anonymous user context after each test.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Create a post with two named blocks (paragraph + heading).
	 *
	 * Uses serialize_blocks() to avoid freeform whitespace nodes between
	 * named blocks, keeping path indices predictable.
	 *
	 * @param string $paragraph_text Text for the paragraph block.
	 * @return int Post ID.
	 */
	private function create_post_with_blocks( string $paragraph_text = 'Hello world' ): int {
		$content = serialize_blocks( [
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '<p>' . esc_html( $paragraph_text ) . '</p>',
				'innerContent' => [ '<p>' . esc_html( $paragraph_text ) . '</p>' ],
			],
			[
				'blockName'    => 'core/heading',
				'attrs'        => [ 'level' => 2 ],
				'innerBlocks'  => [],
				'innerHTML'    => '<h2>Section</h2>',
				'innerContent' => [ '<h2>Section</h2>' ],
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
	 * Save a revision of the post's current content and return its ID.
	 *
	 * Uses wp_save_post_revision(). Skips the test if the environment does
	 * not support revisions (WP_POST_REVISIONS === 0).
	 *
	 * @param int $post_id Post to snapshot.
	 * @return int Revision post ID.
	 */
	private function save_revision( int $post_id ): int {
		$rev_id = wp_save_post_revision( $post_id );

		if ( is_wp_error( $rev_id ) || ! $rev_id ) {
			$this->markTestSkipped( 'wp_save_post_revision() returned false — revisions may be disabled in this test environment.' );
		}

		return (int) $rev_id;
	}

	/**
	 * Serialize a one-paragraph block with the given text.
	 *
	 * @param string $text Paragraph text.
	 * @return string Serialized block content.
	 */
	private function paragraph_content( string $text ): string {
		return serialize_blocks( [
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '<p>' . esc_html( $text ) . '</p>',
				'innerContent' => [ '<p>' . esc_html( $text ) . '</p>' ],
			],
		] );
	}

	/**
	 * Update a post's content and return the new current revision ID.
	 *
	 * wp_update_post() creates a revision automatically when the post type
	 * supports revisions. Returns the latest revision ID via RevisionGuard
	 * so callers do not need to guess whether a manual save is needed.
	 *
	 * @param int    $post_id     Post to update.
	 * @param string $new_content New post_content.
	 * @return int Latest revision ID after the update (may be 0 if revisions disabled).
	 */
	private function update_post_content( int $post_id, string $new_content ): int {
		$result = wp_update_post( [
			'ID'           => $post_id,
			'post_content' => $new_content,
		] );

		$this->assertGreaterThan( 0, $result );

		return RevisionGuard::current_revision_id( $post_id );
	}

	// ── Input validation errors ───────────────────────────────────────────

	/**
	 * Missing post_id returns WP_Error missing_post_id.
	 */
	public function test_missing_post_id_returns_error(): void {
		$result = BlockAbilities::handle_revert_to_revision( [
			'revision_id' => 999,
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_post_id', $result->get_error_code() );
	}

	/**
	 * Zero post_id returns WP_Error missing_post_id.
	 */
	public function test_zero_post_id_returns_error(): void {
		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'     => 0,
			'revision_id' => 999,
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_post_id', $result->get_error_code() );
	}

	/**
	 * Missing revision_id returns WP_Error missing_revision_id.
	 */
	public function test_missing_revision_id_returns_error(): void {
		$post_id = $this->create_post_with_blocks();
		$result  = BlockAbilities::handle_revert_to_revision( [
			'post_id' => $post_id,
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_revision_id', $result->get_error_code() );
	}

	/**
	 * Zero revision_id returns WP_Error missing_revision_id.
	 */
	public function test_zero_revision_id_returns_error(): void {
		$post_id = $this->create_post_with_blocks();
		$result  = BlockAbilities::handle_revert_to_revision( [
			'post_id'     => $post_id,
			'revision_id' => 0,
		] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_revision_id', $result->get_error_code() );
	}

	// ── Cap check ────────────────────────────────────────────────────────

	/**
	 * A subscriber user (no edit_post cap) gets insufficient_capability (AC5).
	 */
	public function test_insufficient_capability_returns_error(): void {
		$post_id    = $this->create_post_with_blocks();
		$rev_id     = $this->save_revision( $post_id );
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		wp_set_current_user( $subscriber );
		$result = BlockMutator::revert_to_revision( $post_id, $rev_id );
		wp_set_current_user( $this->admin_id ); // restore admin

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'insufficient_capability', $result->get_error_code() );
	}

	// ── revision_post_mismatch ───────────────────────────────────────────

	/**
	 * Providing a revision that belongs to a different post returns
	 * WP_Error revision_post_mismatch (AC3).
	 */
	public function test_revision_from_different_post_returns_mismatch(): void {
		$post1   = $this->create_post_with_blocks();
		$post2   = $this->create_post_with_blocks( 'Different post' );
		$rev_of2 = $this->save_revision( $post2 );

		// Try to revert post1 to a revision that belongs to post2.
		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'     => $post1,
			'revision_id' => $rev_of2,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'revision_post_mismatch', $result->get_error_code() );
	}

	/**
	 * Providing a non-revision post ID as revision_id returns
	 * WP_Error revision_post_mismatch (a plain post is not a revision).
	 */
	public function test_non_revision_id_returns_mismatch(): void {
		$post1 = $this->create_post_with_blocks();
		$post2 = $this->create_post_with_blocks( 'Not a revision' );

		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'     => $post1,
			'revision_id' => $post2, // post2 is a plain post, not a revision
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'revision_post_mismatch', $result->get_error_code() );
	}

	// ── revision_stale (optimistic concurrency) ──────────────────────────

	/**
	 * expected_current_revision_id mismatch returns WP_Error revision_stale (AC4).
	 *
	 * Uses a guaranteed-wrong expected ID ($rev1 + 99999) so the test is not
	 * sensitive to whether wp_update_post auto-creates a second revision in the
	 * test environment. The actual current revision will always be rev1 or a
	 * later auto-revision, never rev1 + 99999.
	 */
	public function test_stale_concurrency_returns_error(): void {
		$post_id = $this->create_post_with_blocks();

		// Save a snapshot revision.
		$rev1 = $this->save_revision( $post_id );

		// Update the post content so there is something to revert later.
		$this->update_post_content( $post_id, $this->paragraph_content( 'Updated content' ) );

		// Pass a deliberately wrong expected_current_revision_id — this cannot
		// match the actual current revision regardless of auto-revision behaviour.
		$wrong_expected = $rev1 + 99999;

		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'                      => $post_id,
			'revision_id'                  => $rev1,
			'expected_current_revision_id' => $wrong_expected,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'revision_stale', $result->get_error_code() );
		$this->assertSame( 412, $result->get_error_data()['status'] ?? 0 );
	}

	// ── Happy path ───────────────────────────────────────────────────────

	/**
	 * Reverting to a known revision returns the expected response shape (AC1).
	 */
	public function test_happy_path_reverts_content(): void {
		$post_id = $this->create_post_with_blocks( 'Original text' );

		// Snapshot the original content as a revision.
		$rev_id = $this->save_revision( $post_id );
		$this->assertGreaterThan( 0, $rev_id );

		// Verify the revision belongs to our post.
		$this->assertSame( $post_id, (int) wp_is_post_revision( $rev_id ) );

		// Update the post so content differs from the revision.
		$this->update_post_content( $post_id, $this->paragraph_content( 'Changed text' ) );

		// Revert to the saved revision.
		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'     => $post_id,
			'revision_id' => $rev_id,
		] );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( $rev_id, $result['reverted_to_revision_id'] );
		$this->assertGreaterThan( 0, $result['new_revision_id'] );

		// new_revision_id is a fresh revision created by wp_restore_post_revision;
		// it will differ from the target revision we restored to.
		$this->assertNotSame( $rev_id, $result['new_revision_id'] );
	}

	/**
	 * After a successful revert, the post block content matches the revision (AC1).
	 */
	public function test_happy_path_content_matches_revision(): void {
		$post_id = $this->create_post_with_blocks( 'Stable text' );

		// Snapshot: save the current content as a revision.
		$rev_id           = $this->save_revision( $post_id );
		$revision_post    = get_post( $rev_id );
		$revision_content = $revision_post->post_content;

		// Modify the post to something different.
		$this->update_post_content( $post_id, $this->paragraph_content( 'Overwritten' ) );

		// Revert.
		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'     => $post_id,
			'revision_id' => $rev_id,
		] );

		$this->assertIsArray( $result );

		// After revert, compare named-block innerHTML (not the raw serialised
		// string, which differs due to fresh sd_ref annotations from reseed).
		$reverted_blocks  = parse_blocks( get_post( $post_id )->post_content );
		$revision_blocks  = parse_blocks( $revision_content );

		$named_reverted = array_values( array_filter( $reverted_blocks, static fn( $b ) => ! empty( $b['blockName'] ) ) );
		$named_revision = array_values( array_filter( $revision_blocks, static fn( $b ) => ! empty( $b['blockName'] ) ) );

		$this->assertCount( count( $named_revision ), $named_reverted );

		if ( ! empty( $named_revision ) ) {
			$this->assertSame( $named_revision[0]['innerHTML'], $named_reverted[0]['innerHTML'] );
		}
	}

	// ── Ref reseeding ────────────────────────────────────────────────────

	/**
	 * refs_reseeded equals block_count and is > 0 for a non-empty post (AC2).
	 */
	public function test_refs_reseeded_count_matches_block_count(): void {
		$post_id = $this->create_post_with_blocks();
		$rev_id  = $this->save_revision( $post_id );

		// Modify the post so the revert has something to restore.
		$this->update_post_content( $post_id, $this->paragraph_content( 'Replacement' ) );

		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'     => $post_id,
			'revision_id' => $rev_id,
		] );

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['refs_reseeded'] );
		$this->assertSame( $result['refs_reseeded'], $result['block_count'] );
	}

	/**
	 * After revert, every named block in the restored post has a valid sd_ref (AC2).
	 */
	public function test_all_named_blocks_have_refs_after_revert(): void {
		$post_id = $this->create_post_with_blocks();
		$rev_id  = $this->save_revision( $post_id );

		$this->update_post_content( $post_id, $this->paragraph_content( 'Different' ) );

		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'     => $post_id,
			'revision_id' => $rev_id,
		] );

		$this->assertIsArray( $result );

		// Reload and verify that every named block carries a fresh sd_ref.
		$blocks = parse_blocks( get_post( $post_id )->post_content );

		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue; // skip freeform / null nodes
			}

			$ref = $block['attrs']['metadata']['sd_ref'] ?? null;
			$this->assertIsString( $ref, 'Block missing sd_ref after reseed: ' . $block['blockName'] );
			$this->assertStringStartsWith( 'blk_', $ref );
		}
	}

	// ── Response structure ───────────────────────────────────────────────

	/**
	 * Successful response contains all expected keys.
	 */
	public function test_response_structure(): void {
		$post_id = $this->create_post_with_blocks();
		$rev_id  = $this->save_revision( $post_id );

		$this->update_post_content( $post_id, $this->paragraph_content( 'Changed' ) );

		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'     => $post_id,
			'revision_id' => $rev_id,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'post_id', $result );
		$this->assertArrayHasKey( 'reverted_to_revision_id', $result );
		$this->assertArrayHasKey( 'new_revision_id', $result );
		$this->assertArrayHasKey( 'refs_reseeded', $result );
		$this->assertArrayHasKey( 'block_count', $result );
	}

	/**
	 * Optimistic concurrency passes when expected_current_revision_id is correct (AC4).
	 */
	public function test_correct_expected_revision_passes(): void {
		$post_id = $this->create_post_with_blocks();

		// Snapshot v1.
		$rev1 = $this->save_revision( $post_id );

		// Update the post content — the revision landscape may or may not change
		// depending on the test environment's auto-revision setting.
		$this->update_post_content( $post_id, $this->paragraph_content( 'Updated' ) );

		// Use the actual current revision ID from RevisionGuard so the expected
		// value is always correct regardless of whether auto-revisions were created.
		$actual_current = RevisionGuard::current_revision_id( $post_id );

		// Pass the correct expected — should succeed.
		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'                      => $post_id,
			'revision_id'                  => $rev1,
			'expected_current_revision_id' => $actual_current,
		] );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( $rev1, $result['reverted_to_revision_id'] );
	}
}
