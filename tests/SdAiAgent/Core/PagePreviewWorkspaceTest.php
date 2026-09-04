<?php

declare(strict_types=1);
/**
 * Tests for autosave-backed page preview workspaces.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Abilities\BlockAbilities;
use SdAiAgent\Abilities\PostAbilities;
use SdAiAgent\Core\PageCompletionGate;
use SdAiAgent\Core\PagePreviewWorkspace;
use WP_UnitTestCase;

/** Verifies staging isolation, optimistic guards, and guarded publication. */
class PagePreviewWorkspaceTest extends WP_UnitTestCase {

	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );
		PagePreviewWorkspace::activate( PageCompletionGate::PROFILE_INCREMENTAL, wp_rand( 10000, 999999 ), 'job-preview-test', true );
	}

	public function tear_down(): void {
		PagePreviewWorkspace::deactivate();
		parent::tear_down();
	}

	/** An untouched provisioned starter can be replaced directly exactly once. */
	public function test_unchanged_cloned_starter_bypasses_preview_until_first_edit(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Starter Home',
				'post_content' => 'Starter content',
			)
		);
		$post    = get_post( $post_id );
		$payload = wp_json_encode(
			array(
				'title'   => $post->post_title,
				'content' => $post->post_content,
				'excerpt' => $post->post_excerpt,
			)
		);
		update_post_meta( $post_id, '_sd_ai_agent_cloned_starter_fingerprint', hash( 'sha256', (string) $payload ) );

		$this->assertFalse( PagePreviewWorkspace::governs( $post ) );

		wp_update_post( array( 'ID' => $post_id, 'post_content' => 'Customer content' ) );
		$this->assertTrue( PagePreviewWorkspace::governs( get_post( $post_id ) ) );
	}

	/** A staged update renders from an autosave while the public parent is unchanged. */
	public function test_stage_and_commit_existing_published_page(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Live title',
				'post_content' => '<!-- wp:paragraph --><p>Live copy</p><!-- /wp:paragraph -->',
			)
		);

		$preview = PagePreviewWorkspace::stage_fields(
			$post_id,
			array( 'post_content' => '<!-- wp:paragraph --><p>Preview copy</p><!-- /wp:paragraph -->' ),
			null,
			array( 'post_content' )
		);

		$this->assertIsArray( $preview );
		$this->assertSame( 'preview', $preview['render_mode'] );
		$this->assertStringContainsString( "/wp/v2/pages/{$post_id}/autosaves/", $preview['preview_rest_path'] );
		$this->assertStringNotContainsString( 'preview_nonce', $preview['preview_rest_path'] );
		$this->assertStringContainsString( 'Live copy', (string) get_post( $post_id )->post_content );
		$this->assertStringContainsString( 'Preview copy', (string) PagePreviewWorkspace::get_working_post( $post_id )->post_content );

		$result = PagePreviewWorkspace::commit(
			array(
				'post_id'     => $post_id,
				'revision_id' => $preview['autosave_id'],
				'workspace_id' => $preview['workspace_id'],
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'publish', $result['status'] );
		$this->assertStringContainsString( 'Preview copy', (string) get_post( $post_id )->post_content );
		$this->assertFalse( wp_get_post_autosave( $post_id, $this->user_id ) );
	}

	/** A live edit after preview creation blocks publication and remains authoritative. */
	public function test_commit_rejects_stale_published_parent(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => 'Live copy',
			)
		);
		$preview = PagePreviewWorkspace::stage_fields( $post_id, array( 'post_content' => 'Preview copy' ), null, array( 'post_content' ) );
		$this->assertIsArray( $preview );

		wp_update_post( array( 'ID' => $post_id, 'post_content' => 'Concurrent live copy' ) );
		$result = PagePreviewWorkspace::commit(
			array(
				'post_id'      => $post_id,
				'revision_id'  => $preview['autosave_id'],
				'workspace_id' => $preview['workspace_id'],
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'stale_preview_parent', $result->get_error_code() );
		$this->assertSame( 'Concurrent live copy', get_post( $post_id )->post_content );
		$this->assertInstanceOf( \WP_Post::class, wp_get_post_autosave( $post_id, $this->user_id ) );
	}

	/** Publication rechecks capabilities after preview approval. */
	public function test_commit_rejects_capability_revoked_after_staging(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => 'Live copy',
			)
		);
		$preview = PagePreviewWorkspace::stage_fields( $post_id, array( 'post_content' => 'Preview copy' ) );
		$this->assertIsArray( $preview );

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );
		$result = PagePreviewWorkspace::commit(
			array(
				'post_id'      => $post_id,
				'revision_id'  => $preview['autosave_id'],
				'workspace_id' => $preview['workspace_id'],
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'insufficient_capability', $result->get_error_code() );
		$this->assertSame( 'Live copy', get_post( $post_id )->post_content );
	}

	/** Existing editor autosaves are never overwritten by an AI workspace. */
	public function test_unrelated_autosave_causes_conflict(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => 'Live copy',
			)
		);
		$controller = new \WP_REST_Autosaves_Controller( 'page' );
		$controller->create_post_autosave(
			array(
				'ID'           => $post_id,
				'post_title'   => get_post( $post_id )->post_title,
				'post_content' => 'Human editor autosave',
				'post_excerpt' => '',
			)
		);

		$result = PagePreviewWorkspace::stage_fields( $post_id, array( 'post_content' => 'AI preview' ), null, array( 'post_content' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_preview_autosave_conflict', $result->get_error_code() );
		$this->assertSame( 'Human editor autosave', wp_get_post_autosave( $post_id, $this->user_id )->post_content );
		$this->assertSame( 'Live copy', get_post( $post_id )->post_content );
	}

	/** One run cannot accumulate a non-atomic set of unrelated page previews. */
	public function test_second_published_page_is_rejected_until_first_finishes(): void {
		$first = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => 'First live' ) );
		$second = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => 'Second live' ) );
		$this->assertIsArray( PagePreviewWorkspace::stage_fields( $first, array( 'post_content' => 'First preview' ) ) );

		$result = PagePreviewWorkspace::stage_fields( $second, array( 'post_content' => 'Second preview' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_preview_scope_conflict', $result->get_error_code() );
		$this->assertSame( 'Second live', get_post( $second )->post_content );
	}

	/** Read-oriented ref persistence degrades safely outside the claimed page scope. */
	public function test_get_page_blocks_scope_conflict_returns_non_persisting_read(): void {
		$first = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => 'First live' ) );
		$second_content = '<!-- wp:paragraph --><p>Second live</p><!-- /wp:paragraph -->';
		$second         = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $second_content ) );
		$this->assertIsArray( PagePreviewWorkspace::stage_fields( $first, array( 'post_content' => 'First preview' ) ) );

		$result = BlockAbilities::handle_get_page_blocks(
			array(
				'post_id'      => $second,
				'persist_refs' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['refs_stored'] );
		$this->assertNotEmpty( $result['blocks'][0]['ref'] );
		$this->assertSame( $second_content, get_post( $second )->post_content );
	}

	/** Missing browser validation blocks the write instead of falling back live. */
	public function test_missing_preview_validator_never_mutates_published_page(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => 'Live copy' ) );
		PagePreviewWorkspace::activate( PageCompletionGate::PROFILE_INCREMENTAL, wp_rand( 10000, 999999 ), 'no-validator', false );

		$result = PostAbilities::handle_update_post( array( 'post_id' => $post_id, 'content' => 'Unsafe direct copy' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_preview_validator_unavailable', $result->get_error_code() );
		$this->assertSame( 'Live copy', get_post( $post_id )->post_content );
	}

	/** Existing public-page update-post calls are automatically staged. */
	public function test_update_post_routes_supported_fields_to_preview(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Live title',
				'post_content' => 'Live copy',
			)
		);

		$result = PostAbilities::handle_update_post(
			array(
				'post_id' => $post_id,
				'title'   => 'Preview title',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'preview', $result['render_mode'] );
		$this->assertSame( 'preview', $result['affected']['render_mode'] );
		$this->assertSame( 'Live title', get_post( $post_id )->post_title );
		$this->assertSame( 'Preview title', PagePreviewWorkspace::get_working_post( $post_id )->post_title );
	}

	/** Stable block edits read and update the current autosave generation. */
	public function test_block_edit_routes_to_preview_and_preserves_live_content(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph {"metadata":{"sd_ref":"copy-ref"}} --><p>Live copy</p><!-- /wp:paragraph -->',
			)
		);

		$result = BlockAbilities::handle_edit_block_tree(
			array(
				'post_id'  => $post_id,
				'op'        => 'update-html',
				'ref'       => 'copy-ref',
				'innerHTML' => '<p>Preview copy</p>',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'preview', $result['render_mode'] );
		$this->assertStringContainsString( 'Live copy', get_post( $post_id )->post_content );
		$this->assertStringContainsString( 'Preview copy', PagePreviewWorkspace::get_working_post( $post_id )->post_content );
	}
}
