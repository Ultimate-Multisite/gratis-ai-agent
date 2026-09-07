<?php

declare(strict_types=1);
/**
 * Repository for AI Agent session persistence.
 *
 * Extracted from SdAiAgent\Core\Database to keep domain logic focused.
 * Database::* methods delegate here for backward compatibility.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Repositories;

use SdAiAgent\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles persistence for chat sessions and shared-session metadata.
 */
class SessionRepository {

	/** Soft maintenance threshold for the total persisted session payload (8 MiB). */
	public const STORAGE_MAINTENANCE_BYTES = 8388608;

	/** Soft maintenance threshold for persisted conversation messages. */
	public const STORAGE_MAINTENANCE_MESSAGES = 10000;

	/** Maximum database slice read while compacting a historical session. */
	public const STREAM_CHUNK_BYTES = 65536;

	/**
	 * Create a new session.
	 *
	 * @param array<string, mixed> $data Session data: user_id, title, provider_id, model_id.
	 * @return int|false Inserted row ID or false on failure.
	 */
	public static function create( array $data ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query; caching not applicable.
		$result = $wpdb->insert(
			Database::table_name(),
			[
				'user_id'     => $data['user_id'],
				'title'       => $data['title'] ?? '',
				'provider_id' => $data['provider_id'] ?? '',
				'model_id'    => $data['model_id'] ?? '',
				'messages'    => '[]',
				'tool_calls'  => '[]',
				'created_at'  => $now,
				'updated_at'  => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Get a single session by ID.
	 *
	 * @param int $session_id Session ID.
	 * @return object|null Session row or null.
	 */
	public static function get( int $session_id ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				Database::table_name(),
				$session_id
			)
		);
	}

	/**
	 * Return the configurable thresholds that trigger a storage-maintenance notice.
	 *
	 * These are deliberately soft limits: normal persistence remains non-destructive
	 * while an administrator chooses whether to export, compact, archive, or remove
	 * a session that crosses the threshold.
	 *
	 * @return array{bytes:int,messages:int}
	 */
	public static function get_storage_maintenance_limits(): array {
		$bytes = (int) apply_filters( 'sd_ai_agent_session_storage_maintenance_bytes', self::STORAGE_MAINTENANCE_BYTES );
		$messages = (int) apply_filters( 'sd_ai_agent_session_storage_maintenance_messages', self::STORAGE_MAINTENANCE_MESSAGES );

		return array(
			'bytes'    => max( 1024, $bytes ),
			'messages' => max( 1, $messages ),
		);
	}

	/**
	 * List session-storage candidates without reading their conversation payloads.
	 *
	 * @param int $min_bytes    Minimum combined messages, tool calls, and pause-state bytes. 0 uses the maintenance threshold.
	 * @param int $min_messages Minimum message count. 0 uses the maintenance threshold.
	 * @param int $limit        Maximum candidates to return.
	 * @return list<object>
	 */
	public static function list_oversized( int $min_bytes = 0, int $min_messages = 0, int $limit = 100 ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$limits       = self::get_storage_maintenance_limits();
		$min_bytes    = max( 1024, $min_bytes > 0 ? $min_bytes : $limits['bytes'] );
		$min_messages = max( 1, $min_messages > 0 ? $min_messages : $limits['messages'] );
		$limit        = min( 500, max( 1, $limit ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table inspection returns metadata only; caching cannot safely represent live maintenance candidates.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, status, message_count, messages_bytes, tool_calls_bytes, paused_state_bytes, total_bytes
				FROM (
					SELECT id, status,
						COALESCE(JSON_LENGTH(messages), 0) AS message_count,
						COALESCE(OCTET_LENGTH(messages), 0) AS messages_bytes,
						COALESCE(OCTET_LENGTH(tool_calls), 0) AS tool_calls_bytes,
						COALESCE(OCTET_LENGTH(paused_state), 0) AS paused_state_bytes,
						COALESCE(OCTET_LENGTH(messages), 0) + COALESCE(OCTET_LENGTH(tool_calls), 0) + COALESCE(OCTET_LENGTH(paused_state), 0) AS total_bytes
					FROM %i
				) AS sd_ai_agent_session_sizes
				WHERE total_bytes >= %d OR message_count >= %d
				ORDER BY total_bytes DESC, id ASC
				LIMIT %d",
				Database::table_name(),
				$min_bytes,
				$min_messages,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Return only fields needed to safely maintain a historical session.
	 *
	 * Deliberately omits messages, tool_calls, and paused_state so a maintenance
	 * request does not create a full in-memory copy before chunked compaction.
	 *
	 * @param int $session_id Session ID.
	 * @return object|null Metadata row, or null when not found.
	 */
	public static function get_maintenance_metadata( int $session_id ): ?object {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table metadata query; caching not applicable.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, user_id, title, provider_id, model_id, status,
					COALESCE(JSON_LENGTH(messages), 0) AS message_count,
					COALESCE(OCTET_LENGTH(messages), 0) AS messages_bytes,
					COALESCE(OCTET_LENGTH(tool_calls), 0) AS tool_calls_bytes,
					COALESCE(OCTET_LENGTH(paused_state), 0) AS paused_state_bytes,
					CASE WHEN paused_state IS NULL OR paused_state = '' THEN 0 ELSE 1 END AS has_paused_state
				FROM %i WHERE id = %d",
				Database::table_name(),
				$session_id
			)
		);

		return is_object( $row ) ? $row : null;
	}

	/**
	 * Yield a session's serialized message JSON in bounded database slices.
	 *
	 * @param int $session_id Session ID.
	 * @param int $chunk_bytes Maximum bytes per yielded slice.
	 * @return \Generator<int, string>
	 */
	public static function stream_messages( int $session_id, int $chunk_bytes = self::STREAM_CHUNK_BYTES ): \Generator {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$session_id = absint( $session_id );
		if ( $session_id <= 0 ) {
			return;
		}

		$chunk_bytes = min( self::STREAM_CHUNK_BYTES, max( 1024, $chunk_bytes ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads only a scalar length before bounded custom-table slices.
		$total_bytes = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT OCTET_LENGTH(messages) FROM %i WHERE id = %d',
				Database::table_name(),
				$session_id
			)
		);

		if ( ! is_numeric( $total_bytes ) || (int) $total_bytes <= 0 ) {
			return;
		}

		for ( $offset = 1; $offset <= (int) $total_bytes; $offset += $chunk_bytes ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded custom-table slice for maintenance compaction.
			$chunk = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT SUBSTRING(messages, %d, %d) FROM %i WHERE id = %d',
					$offset,
					$chunk_bytes,
					Database::table_name(),
					$session_id
				)
			);

			if ( ! is_string( $chunk ) || '' === $chunk ) {
				return;
			}

			yield $chunk;
		}
	}

	/**
	 * List sessions for a user (lightweight — no messages/tool_calls).
	 *
	 * @param int                  $user_id WordPress user ID.
	 * @param array<string, mixed> $filters Optional filters: status, folder, search, pinned.
	 * @return list<object>|null Array of session summary objects.
	 */
	public static function list( int $user_id, array $filters = [] ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = Database::table_name();

		$where = [ $wpdb->prepare( 'user_id = %d', $user_id ) ];

		$status  = $filters['status'] ?? 'active';
		$where[] = $wpdb->prepare( 'status = %s', $status );

		if ( ! empty( $filters['folder'] ) ) {
			$where[] = $wpdb->prepare( 'folder = %s', $filters['folder'] );
		}

		if ( isset( $filters['pinned'] ) ) {
			$where[] = $wpdb->prepare( 'pinned = %d', $filters['pinned'] ? 1 : 0 );
		}

		if ( ! empty( $filters['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = $wpdb->prepare( '(title LIKE %s OR messages LIKE %s)', $like, $like );
		}

		$where_sql    = implode( ' AND ', $where );
		$shared_table = Database::shared_sessions_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query; built from prepared fragments; table names from internal methods.
		return $wpdb->get_results(
			"SELECT s.id, s.user_id, s.title, s.provider_id, s.model_id, s.status,
				s.pinned, s.folder, s.created_at, s.updated_at,
				JSON_LENGTH(s.messages) AS message_count,
				CASE WHEN ss.session_id IS NOT NULL THEN 1 ELSE 0 END AS is_shared,
				ss.shared_by
			FROM {$table} s
			LEFT JOIN {$shared_table} ss ON ss.session_id = s.id
			WHERE {$where_sql}
			ORDER BY s.pinned DESC, s.updated_at DESC"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * List distinct folders for a user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array Array of folder name strings.
	 */
	public static function list_folders( int $user_id ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = Database::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT folder FROM %i WHERE user_id = %d AND folder != '' ORDER BY folder ASC",
				$table,
				$user_id
			)
		);

		return $results ?: [];
	}

	/**
	 * Bulk update sessions.
	 *
	 * @param array<int|string, mixed> $session_ids Array of session IDs.
	 * @param int                      $user_id     User ID for ownership check.
	 * @param array<string, mixed>     $data        Fields to update (status, pinned, folder).
	 * @return int Number of rows affected.
	 */
	public static function bulk_update( array $session_ids, int $user_id, array $data ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$session_ids = array_values(
			array_filter(
				array_map(
					static function ( $session_id ): int {
						return absint( $session_id );
					},
					$session_ids
				)
			)
		);
		if ( empty( $session_ids ) || empty( $data ) ) {
			return 0;
		}

		$table              = Database::table_name();
		$data['updated_at'] = current_time( 'mysql', true );
		unset( $data['trashed_at'] );
		$sets_trash = array_key_exists( 'status', $data ) && 'trash' === $data['status'];
		if ( array_key_exists( 'status', $data ) && ! $sets_trash ) {
			$data['trashed_at'] = null;
		}
		$allowed_fields = [ 'status', 'pinned', 'folder', 'trashed_at', 'updated_at' ];

		$set_parts = [];
		if ( $sets_trash ) {
			$set_parts[] = $wpdb->prepare( 'trashed_at = COALESCE(trashed_at, %s)', $data['updated_at'] );
		}

		foreach ( $data as $key => $value ) {
			if ( ! in_array( $key, $allowed_fields, true ) ) {
				continue;
			}

			if ( 'pinned' === $key ) {
				$set_parts[] = $wpdb->prepare( '%i = %d', $key, $value );
				continue;
			}
			if ( null === $value ) {
				$set_parts[] = $wpdb->prepare( '%i = NULL', $key );
				continue;
			}

			$set_parts[] = $wpdb->prepare( '%i = %s', $key, $value );
		}

		if ( empty( $set_parts ) ) {
			return 0;
		}

		$set_sql      = implode( ', ', $set_parts );
		$placeholders = implode( ',', array_fill( 0, count( $session_ids ), '%d' ) );
		$values       = array_merge( $session_ids, [ $user_id ] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table query; SET fragments are prepared from allowlisted column names and values.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET {$set_sql} WHERE id IN ({$placeholders}) AND user_id = %d",
				$table,
				...$values
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $result !== false ? (int) $result : 0;
	}

	/**
	 * Permanently delete sessions in trash for a user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int Number of rows deleted.
	 */
	public static function empty_trash( int $user_id ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = Database::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE user_id = %d AND status = 'trash'",
				$table,
				$user_id
			)
		);

		return $result !== false ? (int) $result : 0;
	}

	/**
	 * Permanently delete selected trashed sessions owned by a user.
	 *
	 * @param array<int|string, mixed> $session_ids Session IDs to delete.
	 * @param int                      $user_id     User ID for ownership check.
	 * @return int Number of rows deleted.
	 */
	public static function bulk_delete_trashed( array $session_ids, int $user_id ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$session_ids = array_values(
			array_filter(
				array_map(
					static function ( $session_id ): int {
						return absint( $session_id );
					},
					$session_ids
				)
			)
		);
		if ( empty( $session_ids ) ) {
			return 0;
		}

		$table        = Database::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $session_ids ), '%d' ) );
		$values       = array_merge( array( $table ), $session_ids, array( $user_id ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom table query; IDs are normalized integers and the placeholder count is built from the same ID list.
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE id IN ({$placeholders}) AND user_id = %d AND status = 'trash'",
				...$values
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return $result !== false ? (int) $result : 0;
	}

	/**
	 * Permanently delete trashed sessions older than the retention period.
	 *
	 * The dedicated trashed_at timestamp is stable even if other session fields
	 * change after the session enters Trash.
	 *
	 * @param int $retention_days Number of days to retain trashed sessions.
	 * @return int Number of rows deleted.
	 */
	public static function delete_expired_trash( int $retention_days ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$retention_days = max( 1, min( 365, $retention_days ) );
		$cutoff         = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table maintenance query; caching is not applicable.
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE status = 'trash' AND trashed_at < %s",
				Database::table_name(),
				$cutoff
			)
		);

		return $result !== false ? (int) $result : 0;
	}

	/**
	 * Update session fields.
	 *
	 * @param int                  $session_id Session ID.
	 * @param array<string, mixed> $data       Fields to update.
	 * @return bool Whether the update succeeded.
	 */
	public static function update( int $session_id, array $data ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$data['updated_at'] = current_time( 'mysql', true );
		unset( $data['trashed_at'] );
		if ( array_key_exists( 'status', $data ) ) {
			$status = (string) $data['status'];
			unset( $data['status'] );

			if ( 'trash' === $status ) {
				// Keep the first Trash-entry timestamp while the row remains trashed.
				// trashed_at is assigned before status so the CASE sees the prior state.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic custom-table status transition avoids a read/write race.
				$status_result = $wpdb->query(
					$wpdb->prepare(
						"UPDATE %i SET trashed_at = CASE WHEN status = 'trash' THEN COALESCE(trashed_at, %s) ELSE %s END, status = %s, updated_at = %s WHERE id = %d",
						Database::table_name(),
						$data['updated_at'],
						$data['updated_at'],
						$status,
						$data['updated_at'],
						$session_id
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic custom-table status transition clears the Trash timestamp on restore/archive.
				$status_result = $wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET trashed_at = NULL, status = %s, updated_at = %s WHERE id = %d',
						Database::table_name(),
						$status,
						$data['updated_at'],
						$session_id
					)
				);
			}

			if ( false === $status_result ) {
				return false;
			}
			if ( 1 === count( $data ) ) {
				return true;
			}
		}

		$formats = [];
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, [ 'user_id', 'id' ], true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->update(
			Database::table_name(),
			$data,
			[ 'id' => $session_id ],
			$formats,
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Delete a session.
	 *
	 * @param int $session_id Session ID.
	 * @return bool Whether the delete succeeded.
	 */
	public static function delete( int $session_id ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->delete(
			Database::table_name(),
			[ 'id' => $session_id ],
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Update token usage for a session (accumulates).
	 *
	 * @param int $session_id       Session ID.
	 * @param int $prompt_tokens    Prompt tokens to add.
	 * @param int $completion_tokens Completion tokens to add.
	 * @return bool
	 */
	public static function update_tokens( int $session_id, int $prompt_tokens, int $completion_tokens ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = Database::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET prompt_tokens = prompt_tokens + %d, completion_tokens = completion_tokens + %d, updated_at = %s WHERE id = %d',
				$table,
				$prompt_tokens,
				$completion_tokens,
				current_time( 'mysql', true ),
				$session_id
			)
		);

		return $result !== false;
	}

	/**
	 * Persist the paused agent-loop state for a session.
	 *
	 * @param int                  $session_id Session ID.
	 * @param array<string, mixed> $state      Serializable loop state.
	 * @return bool Whether the update succeeded.
	 */
	public static function save_paused_state( int $session_id, array $state ): bool {
		return self::update(
			$session_id,
			array( 'paused_state' => wp_json_encode( $state ) )
		);
	}

	/**
	 * Load and clear the paused agent-loop state for a session.
	 *
	 * @param int $session_id Session ID.
	 * @return array<string, mixed>|null Paused state, or null if none.
	 */
	public static function load_and_clear_paused_state( int $session_id ): ?array {
		$session = self::get( $session_id );

		if ( ! $session ) {
			return null;
		}

		// @phpstan-ignore-next-line
		$raw = $session->paused_state ?? null;

		if ( empty( $raw ) ) {
			return null;
		}

		$state = json_decode( (string) $raw, true );

		if ( ! is_array( $state ) ) {
			return null;
		}

		// Clear the paused state so it cannot be replayed.
		self::update( $session_id, array( 'paused_state' => null ) );

		/** @var array<string, mixed> $state */
		return $state;
	}

	/**
	 * Append messages and tool calls to a session.
	 *
	 * @param int   $session_id Session ID.
	 * @param array $messages   New message arrays to append.
	 * @param array $tool_calls New tool call log entries to append.
	 * @return bool Whether the update succeeded.
	 *
	 * @phpstan-param list<mixed>                $messages
	 * @phpstan-param list<array<string, mixed>> $tool_calls
	 */
	public static function append( int $session_id, array $messages, array $tool_calls = [] ): bool {
		$session = self::get( $session_id );

		if ( ! $session ) {
			return false;
		}

		$existing_messages   = json_decode( $session->messages, true ) ?: [];
		$existing_tool_calls = json_decode( $session->tool_calls, true ) ?: [];

		// @phpstan-ignore-next-line
		$merged_messages = array_merge( $existing_messages, $messages );
		// @phpstan-ignore-next-line
		$merged_tool_calls = array_merge( $existing_tool_calls, $tool_calls );

		$encoded_messages   = wp_json_encode( $merged_messages );
		$encoded_tool_calls = wp_json_encode( $merged_tool_calls );
		if ( ! is_string( $encoded_messages ) || ! is_string( $encoded_tool_calls ) ) {
			return false;
		}

		$updated = self::update(
			$session_id,
			[
				'messages'   => $encoded_messages,
				'tool_calls' => $encoded_tool_calls,
			]
		);

		if ( ! $updated ) {
			return false;
		}

		$limits             = self::get_storage_maintenance_limits();
		$paused_state_bytes = isset( $session->paused_state ) && is_string( $session->paused_state ) ? strlen( $session->paused_state ) : 0;
		$total_bytes        = strlen( $encoded_messages ) + strlen( $encoded_tool_calls ) + $paused_state_bytes;
		$message_count      = count( $merged_messages );
		if ( $total_bytes >= $limits['bytes'] || $message_count >= $limits['messages'] ) {
			/**
			 * Fires after a session crosses the non-destructive storage-maintenance threshold.
			 *
			 * @param int                                    $session_id Session ID.
			 * @param array{message_count:int,total_bytes:int} $metrics    Safe size metadata only.
			 */
			do_action(
				'sd_ai_agent_session_storage_maintenance_required',
				$session_id,
				array(
					'message_count' => $message_count,
					'total_bytes'   => $total_bytes,
				)
			);
		}

		return true;
	}

	// ─── Shared Sessions ─────────────────────────────────────────────────────

	/**
	 * Share a session (make it visible to all admins).
	 *
	 * @param int $session_id Session ID to share.
	 * @param int $shared_by  User ID of the admin sharing the session.
	 * @return bool Whether the insert succeeded.
	 */
	public static function share( int $session_id, int $shared_by ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write (REPLACE); caching not applicable to mutations.
		$result = $wpdb->replace(
			Database::shared_sessions_table_name(),
			[
				'session_id' => $session_id,
				'shared_by'  => $shared_by,
				'shared_at'  => current_time( 'mysql', true ),
			],
			[ '%d', '%d', '%s' ]
		);

		return $result !== false;
	}

	/**
	 * Unshare a session (remove from shared sessions).
	 *
	 * @param int $session_id Session ID to unshare.
	 * @return bool Whether the delete succeeded.
	 */
	public static function unshare( int $session_id ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->delete(
			Database::shared_sessions_table_name(),
			[ 'session_id' => $session_id ],
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Check whether a session is shared.
	 *
	 * @param int $session_id Session ID.
	 * @return object|null Shared session row (with shared_by, shared_at) or null.
	 */
	public static function get_shared( int $session_id ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE session_id = %d',
				Database::shared_sessions_table_name(),
				$session_id
			)
		);
	}

	/**
	 * List all shared sessions (full session rows + sharing metadata).
	 *
	 * @return list<object>|null Array of session rows with is_shared=1 and shared_by fields.
	 */
	public static function list_shared(): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$sessions_table = Database::table_name();
		$shared_table   = Database::shared_sessions_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query; table names from internal methods.
		return $wpdb->get_results(
			"SELECT s.id, s.user_id, s.title, s.provider_id, s.model_id, s.status,
				s.pinned, s.folder, s.created_at, s.updated_at,
				JSON_LENGTH(s.messages) AS message_count,
				1 AS is_shared,
				ss.shared_by, ss.shared_at
			FROM {$sessions_table} s
			INNER JOIN {$shared_table} ss ON ss.session_id = s.id
			WHERE s.status = 'active'
			ORDER BY s.updated_at DESC"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}
}
