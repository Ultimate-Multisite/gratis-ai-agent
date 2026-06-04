<?php

declare(strict_types=1);
/**
 * Repository for skill usage telemetry.
 *
 * Tracks which skills are loaded, how they were triggered, and which model
 * received them. Used to surface quality signals (helpful/neutral/negative)
 * and to tune the auto-injection trigger patterns over time.
 *
 * @package SdAiAgent\Repositories
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Repositories;

use SdAiAgent\Core\Database;
use SdAiAgent\Models\DTO\SkillUsageRow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles persistence for skill usage records.
 */
class SkillUsageRepository {

	/**
	 * Record a skill usage event.
	 *
	 * Keys: skill_id (int FK), session_id (int, 0 = no session), trigger_type
	 * ('auto'|'manual'|'tool_call'), injected_tokens (int), outcome
	 * ('helpful'|'neutral'|'negative'|'unknown'), model_id (string).
	 *
	 * @param array<string, mixed> $data Skill usage event data.
	 * @return int|false Inserted row ID or false on failure.
	 */
	public static function create( array $data ): int|false {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$skill_id = (int) ( $data['skill_id'] ?? 0 );
		if ( $skill_id <= 0 ) {
			return false;
		}

		$trigger_type = $data['trigger_type'] ?? 'auto';
		if ( ! in_array( $trigger_type, [ 'auto', 'manual', 'tool_call' ], true ) ) {
			$trigger_type = 'auto';
		}

		$outcome = $data['outcome'] ?? 'unknown';
		if ( ! in_array( $outcome, [ 'helpful', 'neutral', 'negative', 'unknown' ], true ) ) {
			$outcome = 'unknown';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query; caching not applicable.
		$result = $wpdb->insert(
			Database::skill_usage_table_name(),
			[
				'skill_id'        => $skill_id,
				'session_id'      => (int) ( $data['session_id'] ?? 0 ),
				'trigger_type'    => $trigger_type,
				'injected_tokens' => (int) ( $data['injected_tokens'] ?? 0 ),
				'outcome'         => $outcome,
				'model_id'        => (string) ( $data['model_id'] ?? '' ),
				'created_at'      => current_time( 'mysql', true ),
			],
			[ '%d', '%d', '%s', '%d', '%s', '%s', '%s' ]
		);

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Get usage records for a specific skill.
	 *
	 * @param int $skill_id Skill ID.
	 * @param int $limit    Maximum number of records to return (default 100).
	 * @return list<SkillUsageRow>
	 */
	public static function get_by_skill( int $skill_id, int $limit = 100 ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = Database::skill_usage_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE skill_id = %d ORDER BY created_at DESC LIMIT %d',
				$table,
				$skill_id,
				$limit
			)
		);

		if ( empty( $rows ) ) {
			return [];
		}

		return array_map( [ SkillUsageRow::class, 'from_row' ], $rows );
	}

	/**
	 * Get aggregated usage statistics per skill.
	 *
	 * Returns load count, helpful count, and last used timestamp for each skill
	 * that has at least one usage record.
	 *
	 * @return list<object> Each object has: skill_id, total_loads, helpful_count, neutral_count,
	 *                      negative_count, last_used_at.
	 */
	public static function get_stats(): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = Database::skill_usage_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query; table name from internal method.
		$rows = $wpdb->get_results(
			"SELECT
				skill_id,
				COUNT(*) AS total_loads,
				SUM(CASE WHEN outcome = 'helpful'  THEN 1 ELSE 0 END) AS helpful_count,
				SUM(CASE WHEN outcome = 'neutral'  THEN 1 ELSE 0 END) AS neutral_count,
				SUM(CASE WHEN outcome = 'negative' THEN 1 ELSE 0 END) AS negative_count,
				MAX(created_at) AS last_used_at
			FROM {$table}
			GROUP BY skill_id
			ORDER BY total_loads DESC"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $rows ?? [];
	}

	/**
	 * Update the outcome for a single skill usage row.
	 *
	 * Allows explicit post-hoc correction (e.g. thumbs-down feedback → negative).
	 *
	 * @param int    $id      Row ID to update.
	 * @param string $outcome New outcome: 'helpful', 'neutral', 'negative', or 'unknown'.
	 * @return bool Whether the update succeeded.
	 */
	public static function update_outcome( int $id, string $outcome ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( ! in_array( $outcome, [ 'helpful', 'neutral', 'negative', 'unknown' ], true ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->update(
			Database::skill_usage_table_name(),
			[ 'outcome' => $outcome ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Batch-update the outcome for all 'unknown' rows in a session.
	 *
	 * Called by AgentLoop after the loop completes to apply the outcome
	 * heuristic across all skills injected during that session.
	 * Only rows that are still 'unknown' are updated — explicitly-set
	 * outcomes (e.g. thumbs-down → negative) are preserved.
	 *
	 * @param int    $session_id Session ID (0 = no-op).
	 * @param string $outcome    Outcome to apply: 'helpful', 'neutral', or 'negative'.
	 * @return int Number of rows updated.
	 */
	public static function update_session_outcomes( int $session_id, string $outcome ): int {
		if ( $session_id <= 0 ) {
			return 0;
		}

		if ( ! in_array( $outcome, [ 'helpful', 'neutral', 'negative' ], true ) ) {
			return 0;
		}

		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$rows_affected = $wpdb->update(
			Database::skill_usage_table_name(),
			[ 'outcome' => $outcome ],
			[
				'session_id' => $session_id,
				'outcome'    => 'unknown',
			],
			[ '%s' ],
			[ '%d', '%s' ]
		);

		return is_int( $rows_affected ) ? $rows_affected : 0;
	}

	/**
	 * Estimate the token count for a string of text.
	 *
	 * Uses chars/4 as a rough approximation for English text.
	 * Sufficient for order-of-magnitude token budgeting; not for billing.
	 *
	 * @param string $text The text to estimate tokens for.
	 * @return int Estimated token count.
	 */
	public static function estimate_tokens( string $text ): int {
		return (int) ceil( mb_strlen( $text ) / 4 );
	}
}
