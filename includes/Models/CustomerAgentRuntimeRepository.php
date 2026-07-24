<?php

declare(strict_types=1);
/**
 * Durable storage for the customer-agent runtime contract.
 *
 * @package SdAiAgent\Models
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Models;

use SdAiAgent\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores opaque external conversations and idempotent runtime jobs.
 */
class CustomerAgentRuntimeRepository {

	/** Get the customer-agent conversations table name. */
	public static function conversations_table_name(): string {
		return Database::customer_agent_conversations_table_name();
	}

	/** Get the customer-agent jobs table name. */
	public static function jobs_table_name(): string {
		return Database::customer_agent_jobs_table_name();
	}

	/**
	 * Locate a conversation by its opaque internal identifier.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_conversation( string $conversation_id ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE conversation_id = %s LIMIT 1',
				self::conversations_table_name(),
				$conversation_id
			),
			ARRAY_A
		);

		return self::normalise_row( $row );
	}

	/**
	 * Locate a conversation through its hashed integration and external session.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function find_conversation( string $integration_hash, string $external_session_hash ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE integration_hash = %s AND external_session_hash = %s LIMIT 1',
				self::conversations_table_name(),
				$integration_hash,
				$external_session_hash
			),
			ARRAY_A
		);

		return self::normalise_row( $row );
	}

	/**
	 * Insert an external conversation.
	 *
	 * @param array<string,mixed> $data Conversation storage fields.
	 */
	public static function create_conversation( array $data ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$result = $wpdb->insert(
			self::conversations_table_name(),
			$data,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Refresh a conversation's profile and retention window.
	 */
	public static function touch_conversation( string $conversation_id, string $profile_id, string $expires_at ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$result = $wpdb->update(
			self::conversations_table_name(),
			array(
				'profile_id' => $profile_id,
				'expires_at' => $expires_at,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'conversation_id' => $conversation_id ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);

		return false !== $result;
	}

	/**
	 * Persist only the bounded runtime history needed for the next turn.
	 */
	public static function update_runtime_history( string $conversation_id, string $history, string $expires_at ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$result = $wpdb->update(
			self::conversations_table_name(),
			array(
				'runtime_history' => $history,
				'expires_at'      => $expires_at,
				'updated_at'      => current_time( 'mysql', true ),
			),
			array( 'conversation_id' => $conversation_id ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);

		return false !== $result;
	}

	/**
	 * Find one job by its opaque identifier.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_job( string $job_id ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE job_id = %s LIMIT 1',
				self::jobs_table_name(),
				$job_id
			),
			ARRAY_A
		);

		return self::normalise_row( $row );
	}

	/**
	 * Find a job through the idempotency tuple.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function find_job_by_idempotency( string $integration_hash, string $external_session_hash, string $external_message_hash ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE integration_hash = %s AND external_session_hash = %s AND external_message_hash = %s LIMIT 1',
				self::jobs_table_name(),
				$integration_hash,
				$external_session_hash,
				$external_message_hash
			),
			ARRAY_A
		);

		return self::normalise_row( $row );
	}

	/**
	 * Insert a queued customer-agent job.
	 *
	 * @param array<string,mixed> $data Job storage fields.
	 */
	public static function create_job( array $data ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$result = $wpdb->insert(
			self::jobs_table_name(),
			$data,
			array(
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		return false !== $result;
	}

	/**
	 * Atomically claim a queued job so concurrent cron events cannot bill twice.
	 */
	public static function claim_queued_job( string $job_id, string $now ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'processing', started_at = %s, updated_at = %s WHERE job_id = %s AND status = 'queued' AND deadline_at >= %s",
				self::jobs_table_name(),
				$now,
				$now,
				$job_id,
				$now
			)
		);

		return 1 === (int) $result;
	}

	/**
	 * Mark a queued or processing job failed once its delivery deadline passes.
	 */
	public static function mark_timed_out( string $job_id, string $now, string $error_code, string $error_message ): bool {
		return self::mark_terminal_job( $job_id, array( 'queued', 'processing' ), 'failed', $now, $error_code, $error_message );
	}

	/**
	 * Mark a queued or processing job as cancelled.
	 */
	public static function mark_cancelled( string $job_id, string $now ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'cancelled', cancelled_at = %s, completed_at = %s, updated_at = %s WHERE job_id = %s AND status IN ('queued', 'processing')",
				self::jobs_table_name(),
				$now,
				$now,
				$now,
				$job_id
			)
		);

		return 1 === (int) $result;
	}

	/**
	 * Persist a terminal failed state unless cancellation already won the race.
	 */
	public static function mark_failed( string $job_id, string $now, string $error_code, string $error_message ): bool {
		return self::mark_terminal_job( $job_id, array( 'queued', 'processing' ), 'failed', $now, $error_code, $error_message );
	}

	/**
	 * Persist a complete result only while the job is still processing.
	 *
	 * This conditional update prevents a late provider result from becoming
	 * deliverable after cancellation or timeout.
	 */
	public static function mark_complete( string $job_id, string $now, string $result_payload, string $provider_id, string $model_id, int $iterations_used, int $prompt_tokens, int $completion_tokens ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'complete', result_payload = %s, provider_id = %s, model_id = %s, iterations_used = %d, prompt_tokens = %d, completion_tokens = %d, error_code = '', error_message = '', completed_at = %s, updated_at = %s WHERE job_id = %s AND status = 'processing'",
				self::jobs_table_name(),
				$result_payload,
				$provider_id,
				$model_id,
				$iterations_used,
				$prompt_tokens,
				$completion_tokens,
				$now,
				$now,
				$job_id
			)
		);

		return 1 === (int) $result;
	}

	/**
	 * Return every job identifier that belongs to a conversation, then purge it.
	 *
	 * @return array{deleted:bool,job_ids:list<string>}
	 */
	public static function purge_conversation( string $conversation_id ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$raw_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT job_id FROM %i WHERE conversation_id = %s',
				self::jobs_table_name(),
				$conversation_id
			)
		);
		$job_ids = array();
		foreach ( $raw_ids as $raw_id ) {
			if ( is_string( $raw_id ) && '' !== $raw_id ) {
				$job_ids[] = $raw_id;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Purging a private runtime record requires an atomic direct delete.
		$wpdb->delete( self::jobs_table_name(), array( 'conversation_id' => $conversation_id ), array( '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Purging a private runtime record requires an atomic direct delete.
		$deleted = $wpdb->delete( self::conversations_table_name(), array( 'conversation_id' => $conversation_id ), array( '%s' ) );

		return array(
			'deleted' => false !== $deleted,
			'job_ids' => $job_ids,
		);
	}

	/**
	 * Purge all runtime records owned by one hashed integration key.
	 *
	 * This deliberately selects and deletes only rows with the exact opaque
	 * integration hash, so managed-profile removal cannot affect another
	 * integration's customer conversations or jobs.
	 *
	 * @return array{purged:bool,conversations:int,job_ids:list<string>}
	 */
	public static function purge_integration( string $integration_hash ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A managed-profile removal must atomically purge its private runtime rows.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return array(
				'purged'        => false,
				'conversations' => 0,
				'job_ids'       => array(),
			);
		}

		$raw_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT job_id FROM %i WHERE integration_hash = %s',
				self::jobs_table_name(),
				$integration_hash
			)
		);
		$job_ids = array();
		foreach ( $raw_ids as $raw_id ) {
			if ( is_string( $raw_id ) && '' !== $raw_id ) {
				$job_ids[] = $raw_id;
			}
		}

		$conversation_count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE integration_hash = %s',
				self::conversations_table_name(),
				$integration_hash
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Purges only an explicitly managed integration's private runtime jobs.
		$jobs_deleted = $wpdb->delete( self::jobs_table_name(), array( 'integration_hash' => $integration_hash ), array( '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Purges only an explicitly managed integration's private runtime conversations.
		$conversations_deleted = $wpdb->delete( self::conversations_table_name(), array( 'integration_hash' => $integration_hash ), array( '%s' ) );
		if ( false === $jobs_deleted || false === $conversations_deleted || false === $wpdb->query( 'COMMIT' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reverts a failed managed-profile runtime purge.
			$wpdb->query( 'ROLLBACK' );
			return array(
				'purged'        => false,
				'conversations' => 0,
				'job_ids'       => array(),
			);
		}

		return array(
			'purged'        => true,
			'conversations' => (int) $conversation_count,
			'job_ids'       => $job_ids,
		);
	}

	/**
	 * Convert a WordPress database result into an associative storage map.
	 *
	 * @param mixed $row Raw database row.
	 * @return array<string,mixed>|null
	 */
	private static function normalise_row( mixed $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$normalised = array();
		foreach ( $row as $key => $value ) {
			if ( ! is_string( $key ) ) {
				return null;
			}
			$normalised[ $key ] = $value;
		}

		return $normalised;
	}

	/**
	 * Update a terminal active-job row without letting it override cancellation.
	 *
	 * @param string $job_id        Runtime job identifier.
	 * @param array  $from_statuses Expected current runtime statuses.
	 * @phpstan-param list<string> $from_statuses
	 * @param string $new_status    Terminal runtime status.
	 * @param string $now           Current UTC MySQL timestamp.
	 * @param string $error_code    Stable public error code.
	 * @param string $error_message Customer-safe error message.
	 */
	private static function mark_terminal_job( string $job_id, array $from_statuses, string $new_status, string $now, string $error_code, string $error_message ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( empty( $from_statuses ) ) {
			return false;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $from_statuses ), '%s' ) );
		$sql          = "UPDATE %i SET status = %s, error_code = %s, error_message = %s, completed_at = %s, updated_at = %s WHERE job_id = %s AND status IN ({$placeholders})";
		$args         = array_merge(
			array(
				self::jobs_table_name(),
				$new_status,
				$error_code,
				$error_message,
				$now,
				$now,
				$job_id,
			),
			$from_statuses
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Status placeholders are generated internally and every value is passed to wpdb::prepare().
		$result = $wpdb->query( $wpdb->prepare( $sql, ...$args ) );

		return 1 === (int) $result;
	}
}
