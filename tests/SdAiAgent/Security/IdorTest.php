<?php

declare(strict_types=1);
/**
 * IDOR (Insecure Direct Object Reference) security tests.
 *
 * Verifies that abilities enforce per-resource capability checks so that a
 * lower-privileged user cannot read or write posts owned by another user.
 *
 * The WordPress capability system distinguishes:
 *   - `edit_posts`         (global) — contributor and above
 *   - `edit_post($id)`     (per-resource) — own posts only for contributor
 *   - `edit_others_posts`  — required to touch another user's post
 *
 * Most handlers rely only on the global `permission_callback` and do NOT yet
 * enforce the stricter per-resource check inside the handler itself.  Those
 * gaps are tracked in GH#1802 and marked incomplete below; they become
 * follow-up bug PRs once the tests are merged and CI exposes the holes.
 *
 * `BlockMutator::revert_to_revision()` DOES enforce the per-resource check and
 * therefore produces a concrete passing test.
 *
 * @package SdAiAgent
 * @subpackage Tests\Security
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1789
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1802
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Security;

use SdAiAgent\Abilities\BlockAbilities;
use SdAiAgent\Abilities\MediaAbilities;
use SdAiAgent\Abilities\PostAbilities;
use SdAiAgent\Core\RevisionGuard;
use WP_UnitTestCase;

/**
 * IDOR security tests for read and write abilities.
 *
 * @group security
 * @group idor
 *
 * @since 1.11.0
 */
class IdorTest extends WP_UnitTestCase {

	/**
	 * Administrator user ID who owns the test post.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Contributor user ID used for IDOR probing.
	 *
	 * Contributors have `edit_posts` globally but NOT `edit_others_posts`,
	 * so they should be blocked from accessing admin-owned resources.
	 *
	 * @var int
	 */
	private int $contributor_id;

	/**
	 * Admin-owned draft post used as the IDOR target across all tests.
	 *
	 * @var int
	 */
	private int $admin_draft_id;

	/**
	 * Attachment ID owned by admin — used for media IDOR tests.
	 *
	 * @var int
	 */
	private int $admin_attachment_id;

	/**
	 * Set up two users and an admin-owned draft post before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		// Create admin and contributor.
		$this->admin_id       = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->contributor_id = self::factory()->user->create( [ 'role' => 'contributor' ] );

		// Create the IDOR target as admin.
		wp_set_current_user( $this->admin_id );

		$this->admin_draft_id = self::factory()->post->create( [
			'post_status'  => 'draft',
			'post_author'  => $this->admin_id,
			'post_content' => "<!-- wp:paragraph -->\n<p>Admin secret draft content.</p>\n<!-- /wp:paragraph -->",
			'post_title'   => 'Admin Private Draft',
		] );

		// Create an attachment owned by admin.
		$this->admin_attachment_id = self::factory()->attachment->create( [
			'post_author'    => $this->admin_id,
			'post_mime_type' => 'image/jpeg',
			'post_status'    => 'inherit',
			'post_title'     => 'Admin Private Attachment',
		] );
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
	 * Make two edits to a post so at least one revision is available.
	 *
	 * @param int $post_id Target post ID.
	 * @return int Latest revision ID after the updates, or 0.
	 */
	private function create_two_revisions( int $post_id ): int {
		$make_content = static function ( string $label ): string {
			return serialize_blocks( [
				[
					'blockName'    => 'core/paragraph',
					'attrs'        => [],
					'innerBlocks'  => [],
					'innerHTML'    => "<p>{$label}</p>",
					'innerContent' => [ "<p>{$label}</p>" ],
				],
			] );
		};

		wp_update_post( [ 'ID' => $post_id, 'post_content' => $make_content( 'revision-A' ) ] );
		wp_update_post( [ 'ID' => $post_id, 'post_content' => $make_content( 'revision-B' ) ] );

		return RevisionGuard::current_revision_id( $post_id );
	}

	// ── revert-to-revision: enforces per-resource check ──────────────────

	/**
	 * AC: Contributor cannot revert an admin-owned post to a revision.
	 *
	 * BlockMutator::revert_to_revision() calls
	 * `current_user_can('edit_post', $post_id)` explicitly. A contributor
	 * (who has `edit_posts` globally but NOT `edit_others_posts`) is therefore
	 * blocked with `insufficient_capability`.
	 *
	 * This test confirms per-resource enforcement IS in place for revert-to-revision.
	 */
	public function test_revert_to_revision_contributor_blocked_on_admin_post(): void {
		wp_set_current_user( $this->admin_id );
		$this->create_two_revisions( $this->admin_draft_id );

		$revisions = wp_get_post_revisions( $this->admin_draft_id, [ 'orderby' => 'ID', 'order' => 'ASC' ] );
		$this->assertNotEmpty( $revisions, 'Need at least one revision for this IDOR test.' );
		$oldest = reset( $revisions );

		// Switch to contributor and try to revert admin's post.
		wp_set_current_user( $this->contributor_id );
		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'     => $this->admin_draft_id,
			'revision_id' => $oldest->ID,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'insufficient_capability', $result->get_error_code() );
	}

	/**
	 * AC: Contributor CAN revert their OWN post to a revision (positive control).
	 *
	 * Confirms that the per-resource check does not over-block — the contributor
	 * is allowed to revert a post they own.
	 */
	public function test_revert_to_revision_contributor_allowed_on_own_post(): void {
		wp_set_current_user( $this->contributor_id );
		$own_draft_id = self::factory()->post->create( [
			'post_status'  => 'draft',
			'post_author'  => $this->contributor_id,
			'post_content' => "<!-- wp:paragraph -->\n<p>Contributor own draft.</p>\n<!-- /wp:paragraph -->",
		] );

		$make_content = static function ( string $label ): string {
			return serialize_blocks( [
				[
					'blockName'    => 'core/paragraph',
					'attrs'        => [],
					'innerBlocks'  => [],
					'innerHTML'    => "<p>{$label}</p>",
					'innerContent' => [ "<p>{$label}</p>" ],
				],
			] );
		};

		// Need admin context to reliably create revisions (WP test harness quirk).
		wp_set_current_user( $this->admin_id );
		wp_update_post( [ 'ID' => $own_draft_id, 'post_content' => $make_content( 'rev-a' ) ] );
		wp_update_post( [ 'ID' => $own_draft_id, 'post_content' => $make_content( 'rev-b' ) ] );

		$revisions = wp_get_post_revisions( $own_draft_id, [ 'orderby' => 'ID', 'order' => 'ASC' ] );
		$this->assertNotEmpty( $revisions, 'Need at least one revision for positive control.' );
		$oldest = reset( $revisions );

		// Now switch to contributor and revert their own post.
		wp_set_current_user( $this->contributor_id );
		$result = BlockAbilities::handle_revert_to_revision( [
			'post_id'     => $own_draft_id,
			'revision_id' => $oldest->ID,
		] );

		// Contributor owns the post → should succeed.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'post_id', $result );
	}

	// ── Abilities missing per-resource check (GH#1802) ───────────────────

	/**
	 * IDOR gap: handle_get_post does not call current_user_can('edit_post', $id).
	 *
	 * A contributor with `edit_posts` can currently read any post, including
	 * admin-owned drafts. Tracked in GH#1802.
	 *
	 * @see https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1802
	 */
	public function test_get_post_contributor_blocked_on_admin_draft(): void {
		$this->markTestIncomplete(
			'GH#1802: handle_get_post does not enforce current_user_can("edit_post", $post_id). ' .
			'Contributor can currently read admin-owned drafts. Fix in follow-up security PR.'
		);

		wp_set_current_user( $this->contributor_id );
		$result = PostAbilities::handle_get_post( [ 'post_id' => $this->admin_draft_id ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'insufficient_capability', $result->get_error_code() );
	}

	/**
	 * IDOR gap: handle_get_page_blocks does not call current_user_can('edit_post', $id).
	 *
	 * @see https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1802
	 */
	public function test_get_page_blocks_contributor_blocked_on_admin_draft(): void {
		$this->markTestIncomplete(
			'GH#1802: handle_get_page_blocks does not enforce current_user_can("edit_post", $post_id). ' .
			'Contributor can currently read block tree of admin-owned drafts.'
		);

		wp_set_current_user( $this->contributor_id );
		$result = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $this->admin_draft_id,
			'persist_refs' => false,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'insufficient_capability', $result->get_error_code() );
	}

	/**
	 * IDOR gap: handle_update_blocks does not call current_user_can('edit_post', $id).
	 *
	 * @see https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1802
	 */
	public function test_update_blocks_contributor_blocked_on_admin_post(): void {
		$this->markTestIncomplete(
			'GH#1802: handle_update_blocks does not enforce current_user_can("edit_post", $post_id). ' .
			'Contributor can currently write blocks on admin-owned posts.'
		);

		wp_set_current_user( $this->contributor_id );
		$result = BlockAbilities::handle_update_blocks( [
			'post_id' => $this->admin_draft_id,
			'updates' => [
				[
					'op'        => 'update-html',
					'flat_index' => 0,
					'innerHTML' => '<p>Injected by contributor</p>',
				],
			],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'insufficient_capability', $result->get_error_code() );
	}

	/**
	 * IDOR gap: handle_update_post does not call current_user_can('edit_post', $id).
	 *
	 * @see https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1802
	 */
	public function test_update_post_contributor_blocked_on_admin_post(): void {
		$this->markTestIncomplete(
			'GH#1802: handle_update_post does not enforce current_user_can("edit_post", $post_id). ' .
			'Contributor can currently update admin-owned posts.'
		);

		wp_set_current_user( $this->contributor_id );
		$result = PostAbilities::handle_update_post( [
			'post_id' => $this->admin_draft_id,
			'title'   => 'Hijacked title',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'insufficient_capability', $result->get_error_code() );
	}

	/**
	 * IDOR gap: handle_delete_post does not call current_user_can('delete_post', $id).
	 *
	 * @see https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1802
	 */
	public function test_delete_post_contributor_blocked_on_admin_post(): void {
		$this->markTestIncomplete(
			'GH#1802: handle_delete_post does not enforce current_user_can("delete_post", $post_id). ' .
			'Contributor can currently attempt to delete admin-owned posts.'
		);

		wp_set_current_user( $this->contributor_id );
		$result = PostAbilities::handle_delete_post( [ 'post_id' => $this->admin_draft_id ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'insufficient_capability', $result->get_error_code() );
	}

	/**
	 * IDOR gap: handle_delete_media does not call current_user_can('delete_post', $id).
	 *
	 * @see https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1802
	 */
	public function test_delete_media_contributor_blocked_on_admin_attachment(): void {
		$this->markTestIncomplete(
			'GH#1802: handle_delete_media does not enforce current_user_can("delete_post", $attachment_id). ' .
			'Contributor can currently attempt to delete admin-owned attachments.'
		);

		wp_set_current_user( $this->contributor_id );
		$result = MediaAbilities::handle_delete_media( [
			'attachment_id' => $this->admin_attachment_id,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'insufficient_capability', $result->get_error_code() );
	}

	/**
	 * IDOR gap: handle_rewrite_post_blocks does not call current_user_can('edit_post', $id).
	 *
	 * @see https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1802
	 */
	public function test_rewrite_post_blocks_contributor_blocked_on_admin_post(): void {
		$this->markTestIncomplete(
			'GH#1802: handle_rewrite_post_blocks does not enforce current_user_can("edit_post", $post_id). ' .
			'Contributor can currently rewrite the entire block tree of admin-owned posts.'
		);

		wp_set_current_user( $this->contributor_id );
		$result = BlockAbilities::handle_rewrite_post_blocks( [
			'post_id' => $this->admin_draft_id,
			'blocks'  => [
				[
					'blockName'    => 'core/paragraph',
					'attrs'        => [],
					'innerHTML'    => '<p>Hijacked content</p>',
					'innerBlocks'  => [],
					'innerContent' => [ '<p>Hijacked content</p>' ],
				],
			],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'insufficient_capability', $result->get_error_code() );
	}

	// ── Positive controls: contributor can act on own resources ──────────

	/**
	 * Positive control: contributor CAN read their own draft via get-post
	 * once per-resource checks are added.
	 *
	 * Since handle_get_post currently lacks the per-resource check, this test
	 * simply verifies that the handler succeeds when the contributor accesses
	 * a post they own — establishing the pass-through baseline.
	 */
	public function test_get_post_contributor_can_read_own_draft(): void {
		wp_set_current_user( $this->contributor_id );
		$own_draft = self::factory()->post->create( [
			'post_status' => 'draft',
			'post_author' => $this->contributor_id,
			'post_title'  => 'Contributor Own Draft',
		] );

		$result = PostAbilities::handle_get_post( [ 'post_id' => $own_draft ] );

		$this->assertIsArray( $result );
		$this->assertSame( $own_draft, $result['id'] );
		$this->assertSame( $this->contributor_id, $result['author_id'] );
	}

	/**
	 * Positive control: admin can always access any post (global + per-resource caps).
	 */
	public function test_get_post_admin_can_read_any_draft(): void {
		wp_set_current_user( $this->admin_id );

		$result = PostAbilities::handle_get_post( [ 'post_id' => $this->admin_draft_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( $this->admin_draft_id, $result['id'] );
	}
}
