<?php

declare(strict_types=1);
/**
 * Tests for configurable chat Trash cleanup.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Bootstrap\OnboardingHandler;
use SdAiAgent\Core\Database;
use SdAiAgent\Core\SessionTrashCleanupService;
use SdAiAgent\Core\Settings;
use WP_UnitTestCase;

/** Integration tests for chat Trash retention and scheduling. */
class SessionTrashCleanupTest extends WP_UnitTestCase {

	/** Reset settings, hooks, and scheduled events after each test. */
	public function tear_down(): void {
		SessionTrashCleanupService::unschedule();
		delete_option( Settings::OPTION_NAME );
		remove_all_actions( 'sd_ai_agent_session_trash_cleaned' );
		parent::tear_down();
	}

	/** Automatic cleanup remains disabled by default. */
	public function test_run_keeps_expired_trash_when_retention_is_disabled(): void {
		$session_id = $this->create_session( 'Disabled cleanup', 'trash', 40 );

		SessionTrashCleanupService::run();

		$this->assertNotNull( Database::get_session( $session_id ) );
	}

	/** Cleanup removes only trashed sessions older than the configured period. */
	public function test_run_deletes_only_expired_trashed_sessions(): void {
		$expired_id = $this->create_session( 'Expired trash', 'trash', 40 );
		$fresh_id   = $this->create_session( 'Fresh trash', 'trash', 5 );
		$active_id  = $this->create_session( 'Old active', 'active', 40 );
		Settings::instance()->update( [ 'chat_trash_retention_days' => 30 ] );

		$cleaned = 0;
		add_action(
			'sd_ai_agent_session_trash_cleaned',
			static function ( int $count ) use ( &$cleaned ): void {
				$cleaned = $count;
			}
		);

		SessionTrashCleanupService::run();

		$this->assertSame( 1, $cleaned );
		$this->assertNull( Database::get_session( $expired_id ) );
		$this->assertNotNull( Database::get_session( $fresh_id ) );
		$this->assertNotNull( Database::get_session( $active_id ) );
	}

	/** Schedule and unschedule are idempotent. */
	public function test_schedule_and_unschedule_daily_cleanup(): void {
		( new OnboardingHandler() )->schedule_session_trash_cleanup();
		$first = wp_next_scheduled( SessionTrashCleanupService::CRON_HOOK );
		( new OnboardingHandler() )->schedule_session_trash_cleanup();

		$this->assertNotFalse( $first );
		$this->assertSame( $first, wp_next_scheduled( SessionTrashCleanupService::CRON_HOOK ) );

		SessionTrashCleanupService::unschedule();
		$this->assertFalse( wp_next_scheduled( SessionTrashCleanupService::CRON_HOOK ) );
	}

	/** Updating a trashed session does not postpone its permanent deletion. */
	public function test_unrelated_update_does_not_change_trash_entry_time(): void {
		$session_id = $this->create_session( 'Old trash', 'trash', 40 );
		$before     = Database::get_session( $session_id );
		$this->assertNotNull( $before );

		Database::update_session( $session_id, [ 'status' => 'trash' ] );
		$after_single_trash = Database::get_session( $session_id );
		Database::bulk_update_sessions( [ $session_id ], (int) $before->user_id, [ 'status' => 'trash' ] );
		$after_bulk_trash = Database::get_session( $session_id );
		Database::update_session( $session_id, [ 'title' => 'Renamed trash' ] );
		$after = Database::get_session( $session_id );

		$this->assertNotNull( $after_single_trash );
		$this->assertNotNull( $after_bulk_trash );
		$this->assertNotNull( $after );
		$this->assertSame( $before->trashed_at, $after_single_trash->trashed_at );
		$this->assertSame( $before->trashed_at, $after_bulk_trash->trashed_at );
		$this->assertSame( $before->trashed_at, $after->trashed_at );

		Settings::instance()->update( [ 'chat_trash_retention_days' => 30 ] );
		SessionTrashCleanupService::run();

		$this->assertNull( Database::get_session( $session_id ) );
	}

	/**
	 * Create a session with a controlled age and status.
	 *
	 * @param string $title    Session title.
	 * @param string $status   Session status.
	 * @param int    $age_days Age of trashed_at in days.
	 * @return int Session ID.
	 */
	private function create_session( string $title, string $status, int $age_days ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$user_id    = self::factory()->user->create();
		$session_id = Database::create_session( [ 'user_id' => $user_id, 'title' => $title ] );
		Database::update_session( $session_id, [ 'status' => $status ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture controls the timestamp used by the cleanup query.
		$wpdb->update(
			Database::table_name(),
			[ 'trashed_at' => gmdate( 'Y-m-d H:i:s', time() - ( $age_days * DAY_IN_SECONDS ) ) ],
			[ 'id' => $session_id ],
			[ '%s' ],
			[ '%d' ]
		);

		return (int) $session_id;
	}
}
