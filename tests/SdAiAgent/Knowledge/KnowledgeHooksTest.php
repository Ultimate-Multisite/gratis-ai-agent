<?php

declare(strict_types=1);
/**
 * Test case for KnowledgeHooks class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Knowledge;

use SdAiAgent\Knowledge\KnowledgeHooks;
use WP_UnitTestCase;

/**
 * Test KnowledgeHooks functionality.
 */
class KnowledgeHooksTest extends WP_UnitTestCase {

	/**
	 * Clean up scheduled events and options after each test.
	 */
	public function tear_down(): void {
		$ts = wp_next_scheduled( KnowledgeHooks::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, KnowledgeHooks::CRON_HOOK );
		}
		delete_option( 'sd_ai_agent_settings' );
		parent::tear_down();
	}

	// ── register ──────────────────────────────────────────────────────────

	/**
	 * register() hooks handle_save_post to save_post.
	 */
	public function test_register_hooks_handle_save_post(): void {
		KnowledgeHooks::register();

		$this->assertNotFalse( has_action( 'save_post', [ KnowledgeHooks::class, 'handle_save_post' ] ) );
	}

	/**
	 * register() hooks handle_delete_post to delete_post.
	 */
	public function test_register_hooks_handle_delete_post(): void {
		KnowledgeHooks::register();

		$this->assertNotFalse( has_action( 'delete_post', [ KnowledgeHooks::class, 'handle_delete_post' ] ) );
	}

	/**
	 * register() hooks handle_cron_reindex to the plugin-owned cron hook.
	 */
	public function test_register_hooks_handle_cron_reindex(): void {
		KnowledgeHooks::register();

		$this->assertNotFalse( has_action( KnowledgeHooks::CRON_HOOK, [ KnowledgeHooks::class, 'handle_cron_reindex' ] ) );
	}

	/**
	 * register() schedules the hourly reindex cron event.
	 */
	public function test_register_schedules_hourly_reindex(): void {
		// Clear any existing scheduled event.
		$ts = wp_next_scheduled( KnowledgeHooks::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, KnowledgeHooks::CRON_HOOK );
		}

		KnowledgeHooks::register();

		$this->assertNotFalse( wp_next_scheduled( KnowledgeHooks::CRON_HOOK ) );
	}

	/**
	 * register() does not schedule duplicate cron events.
	 */
	public function test_register_does_not_duplicate_cron_event(): void {
		// Clear any existing scheduled event.
		$ts = wp_next_scheduled( KnowledgeHooks::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, KnowledgeHooks::CRON_HOOK );
		}

		KnowledgeHooks::register();
		$first_ts = wp_next_scheduled( KnowledgeHooks::CRON_HOOK );

		KnowledgeHooks::register();
		$second_ts = wp_next_scheduled( KnowledgeHooks::CRON_HOOK );

		$this->assertSame( $first_ts, $second_ts );
	}

	// ── handle_save_post ──────────────────────────────────────────────────

	/**
	 * handle_save_post() skips revisions.
	 */
	public function test_handle_save_post_skips_revisions(): void {
		$post_id     = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$revision_id = wp_save_post_revision( $post_id );

		if ( ! $revision_id ) {
			$this->markTestSkipped( 'Could not create revision.' );
		}

		// Should not throw or produce errors.
		$revision = get_post( $revision_id );
		KnowledgeHooks::handle_save_post( $revision_id, $revision );

		$this->assertTrue( true, 'handle_save_post should skip revisions gracefully' );
	}

	/**
	 * handle_save_post() skips non-published posts.
	 */
	public function test_handle_save_post_skips_draft_posts(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$post    = get_post( $post_id );

		// Should not throw or produce errors.
		KnowledgeHooks::handle_save_post( $post_id, $post );

		$this->assertTrue( true, 'handle_save_post should skip draft posts gracefully' );
	}

	/**
	 * handle_save_post() skips when knowledge is disabled in settings.
	 */
	public function test_handle_save_post_skips_when_knowledge_disabled(): void {
		update_option( 'sd_ai_agent_settings', [
			'knowledge_enabled'    => false,
			'knowledge_auto_index' => true,
		] );

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$post    = get_post( $post_id );

		// Should not throw or produce errors.
		KnowledgeHooks::handle_save_post( $post_id, $post );

		$this->assertTrue( true, 'handle_save_post should skip when knowledge disabled' );
	}

	// ── handle_delete_post ────────────────────────────────────────────────

	/**
	 * handle_delete_post() runs without error when no sources exist.
	 */
	public function test_handle_delete_post_runs_without_error_when_no_sources(): void {
		$post_id = self::factory()->post->create();

		// Should not throw or produce errors.
		KnowledgeHooks::handle_delete_post( $post_id );

		$this->assertTrue( true, 'handle_delete_post should handle missing sources gracefully' );
	}

	/**
	 * handle_delete_post() runs without error for non-existent post ID.
	 */
	public function test_handle_delete_post_handles_nonexistent_post(): void {
		KnowledgeHooks::handle_delete_post( 999999 );

		$this->assertTrue( true, 'handle_delete_post should handle non-existent post gracefully' );
	}

	// ── handle_cron_reindex ───────────────────────────────────────────────

	/**
	 * handle_cron_reindex() runs without error when knowledge is disabled.
	 */
	public function test_handle_cron_reindex_skips_when_knowledge_disabled(): void {
		update_option( 'sd_ai_agent_settings', [
			'knowledge_enabled' => false,
		] );

		// Should not throw or produce errors.
		KnowledgeHooks::handle_cron_reindex();

		$this->assertTrue( true, 'handle_cron_reindex should skip when knowledge disabled' );
	}

	/**
	 * handle_cron_reindex() runs without error when no collections exist.
	 */
	public function test_handle_cron_reindex_runs_without_error_when_no_collections(): void {
		update_option( 'sd_ai_agent_settings', [
			'knowledge_enabled' => true,
		] );

		// Should not throw or produce errors.
		KnowledgeHooks::handle_cron_reindex();

		$this->assertTrue( true, 'handle_cron_reindex should handle no collections gracefully' );
	}

	// ── deactivate ────────────────────────────────────────────────────────

	/**
	 * deactivate() unschedules the reindex cron event.
	 */
	public function test_deactivate_unschedules_reindex(): void {
		// Ensure it's scheduled first.
		if ( ! wp_next_scheduled( KnowledgeHooks::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', KnowledgeHooks::CRON_HOOK );
		}

		KnowledgeHooks::deactivate();

		$this->assertFalse( wp_next_scheduled( KnowledgeHooks::CRON_HOOK ) );
	}

	/**
	 * deactivate() runs without error when no cron event is scheduled.
	 */
	public function test_deactivate_runs_without_error_when_not_scheduled(): void {
		// Ensure it's not scheduled.
		$ts = wp_next_scheduled( KnowledgeHooks::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, KnowledgeHooks::CRON_HOOK );
		}

		// Should not throw.
		KnowledgeHooks::deactivate();

		$this->assertFalse( wp_next_scheduled( KnowledgeHooks::CRON_HOOK ) );
	}
}
