<?php

declare(strict_types=1);
/**
 * Daily cleanup for expired chat sessions in Trash.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules and runs configurable chat Trash retention cleanup.
 */
final class SessionTrashCleanupService {

	/** WP-Cron hook for automatic Trash cleanup. */
	const CRON_HOOK = 'sd_ai_agent_cleanup_session_trash';

	/** Schedule the daily cleanup event idempotently. */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/** Remove the scheduled cleanup event. */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/** Permanently delete expired trashed sessions when retention is enabled. */
	public static function run(): void {
		$retention_days = (int) Settings::instance()->get( 'chat_trash_retention_days' );
		if ( $retention_days <= 0 ) {
			return;
		}

		$deleted = Database::delete_expired_trash( $retention_days );
		if ( $deleted > 0 ) {
			/**
			 * Fires after expired trashed chat sessions are permanently deleted.
			 *
			 * @param int $deleted Number of sessions deleted.
			 */
			do_action( 'sd_ai_agent_session_trash_cleaned', $deleted );
		}
	}
}
