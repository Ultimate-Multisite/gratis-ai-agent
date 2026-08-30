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
		$sessionId = $this->create_session( 'Disabled cleanup', 'trash', 40 );

		SessionTrashCleanupService::run();

		$this->assertNotNull( Database::get_session( $sessionId ) );
	}

	/** Cleanup removes only trashed sessions older than the configured period. */
	public function test_run_deletes_only_expired_trashed_sessions(): void {
		$expiredId = $this->create_session( 'Expired trash', 'trash', 40 );
		$freshId   = $this->create_session( 'Fresh trash', 'trash', 5 );
		$activeId  = $this->create_session( 'Old active', 'active', 40 );
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
		$this->assertNull( Database::get_session( $expiredId ) );
		$this->assertNotNull( Database::get_session( $freshId ) );
		$this->assertNotNull( Database::get_session( $activeId ) );
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
		$sessionId = $this->create_session( 'Old trash', 'trash', 40 );
		$before    = Database::get_session( $sessionId );
		$this->assertNotNull( $before );

		Database::update_session( $sessionId, [ 'status' => 'trash' ] );
		$afterSingleTrash = Database::get_session( $sessionId );
		Database::bulk_update_sessions( [ $sessionId ], (int) $before->user_id, [ 'status' => 'trash' ] );
		$afterBulkTrash = Database::get_session( $sessionId );
		Database::update_session( $sessionId, [ 'title' => 'Renamed trash' ] );
		$after = Database::get_session( $sessionId );

		$this->assertNotNull( $afterSingleTrash );
		$this->assertNotNull( $afterBulkTrash );
		$this->assertNotNull( $after );
		$this->assertSame( $before->trashed_at, $afterSingleTrash->trashed_at );
		$this->assertSame( $before->trashed_at, $afterBulkTrash->trashed_at );
		$this->assertSame( $before->trashed_at, $after->trashed_at );

		Settings::instance()->update( [ 'chat_trash_retention_days' => 30 ] );
		SessionTrashCleanupService::run();

		$this->assertNull( Database::get_session( $sessionId ) );
	}

	/**
	 * Create a session with a controlled age and status.
	 *
	 * @param string $title    Session title.
	 * @param string $status   Session status.
	 * @param int    $ageDays Age of trashed_at in days.
	 * @return int Session ID.
	 */
	private function create_session( string $title, string $status, int $ageDays ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$userId    = self::factory()->user->create();
		$sessionId = Database::create_session( [ 'user_id' => $userId, 'title' => $title ] );
		Database::update_session( $sessionId, [ 'status' => $status ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture controls the timestamp used by the cleanup query.
		$wpdb->update(
			Database::table_name(),
			[ 'trashed_at' => gmdate( 'Y-m-d H:i:s', time() - ( $ageDays * DAY_IN_SECONDS ) ) ],
			[ 'id' => $sessionId ],
			[ '%s' ],
			[ '%d' ]
		);

		return (int) $sessionId;
	}
}
