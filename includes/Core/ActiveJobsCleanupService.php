<?php

declare(strict_types=1);
/**
 * Active Jobs Cleanup Service — hourly reaper for zombie processing rows.
 *
 * Rows in wp_sd_ai_agent_active_jobs become permanently stuck in
 * status='processing' when the PHP request running an AgentLoop terminates
 * abnormally (PHP fatal, FastCGI/nginx timeout, SIGKILL, OOM, FPM child exit).
 *
 * Two-tier cleanup strategy:
 *
 * 1. Shutdown handler (best-effort, fires on normal-or-abnormal request exit):
 *    Registered in SessionController::handle_process(). If the row is still
 *    'processing' at shutdown, it is marked 'interrupted' with a reason note.
 *
 * 2. Periodic reaper (this class, hourly cron):
 *    For cases where the shutdown handler itself could not run. Marks rows as
 *    'abandoned' when status='processing' and updated_at has not advanced
 *    within the configured threshold (default 15 minutes, filterable via
 *    `sd_ai_agent_stale_job_threshold_minutes`).
 *
 * The AgentLoop heartbeat (updated_at advance on each loop iteration) ensures
 * the 15-minute threshold is honest: a genuinely-running loop keeps its row
 * fresh and the reaper leaves it alone.
 *
 * Reference: issue #1510.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use SdAiAgent\Models\ActiveJobRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ActiveJobsCleanupService {

	/**
	 * WP-Cron hook name for the hourly stale-job cleanup.
	 */
	const CRON_HOOK = 'sd_ai_agent_cleanup_stale_active_jobs';

	/**
	 * Default stale-job threshold in minutes.
	 *
	 * A row is considered stale when status='processing' and updated_at has
	 * not advanced within this window. Must be longer than the longest
	 * expected legitimate single loop iteration (image generation, large RAG
	 * queries, etc. can legitimately take several minutes).
	 */
	const DEFAULT_THRESHOLD_MINUTES = 15;

	// ── Registration ─────────────────────────────────────────────────────

	/**
	 * Register the cron hook handler (add_action for the cron callback).
	 */
	public static function register(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'run' ] );
	}

	/**
	 * Schedule an hourly cleanup check (idempotent — safe to call multiple times).
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Cancel the scheduled cleanup (e.g. on plugin deactivation).
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	// ── Cron callback ────────────────────────────────────────────────────

	/**
	 * Execute the stale-job reaper (called by WP-Cron).
	 *
	 * Marks rows as 'abandoned' when:
	 *   - status = 'processing', AND
	 *   - updated_at < NOW() - INTERVAL {threshold} MINUTE
	 *
	 * The threshold is filterable via `sd_ai_agent_stale_job_threshold_minutes`.
	 * Clamped to a minimum of 1 minute.
	 *
	 * Fires the `sd_ai_agent_stale_jobs_reaped` action with the count of
	 * reaped rows so logging/monitoring integrations can observe cleanup.
	 */
	public static function run(): void {
		/**
		 * Filter the number of minutes of inactivity before a processing row
		 * is considered stale and reaped by the hourly cron job.
		 *
		 * Must be longer than the longest expected legitimate single loop
		 * iteration. Default is 15 minutes.
		 *
		 * @param int $minutes Threshold in minutes.
		 */
		$threshold = (int) apply_filters( 'sd_ai_agent_stale_job_threshold_minutes', self::DEFAULT_THRESHOLD_MINUTES );
		$threshold = max( 1, $threshold );

		$count = ActiveJobRepository::cleanup_stale( $threshold );

		if ( $count > 0 ) {
			/**
			 * Fired after the hourly reaper marks stale processing rows as abandoned.
			 *
			 * @param int $count Number of rows reaped.
			 */
			do_action( 'sd_ai_agent_stale_jobs_reaped', $count );
		}
	}
}
