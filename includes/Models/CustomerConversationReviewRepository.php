<?php

declare(strict_types=1);
/**
 * Privacy-safe read model for customer-facing conversation review.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Models;

use SdAiAgent\Core\ConversationDisplaySanitizer;
use SdAiAgent\Core\Database;
use SdAiAgent\Core\DurablePlanTextSanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores a minimized, administrator-only projection of customer conversations.
 *
 * Runtime request payloads, profile snapshots, external identifiers, job tokens,
 * and public session tokens never enter this read model. Each safe turn is stored
 * independently so retries can be idempotent and deletion can win concurrent
 * completion writes without recreating retained content.
 */
final class CustomerConversationReviewRepository {

	public const SOURCE_PUBLIC_EMBED     = 'public_embed';
	public const SOURCE_CUSTOMER_RUNTIME = 'customer_runtime';
	public const REDACTED_PLACEHOLDER    = DurablePlanTextSanitizer::REDACTED_PLACEHOLDER;

	private const MAX_DETAIL_TURNS     = 100;
	private const MAX_RECONCILED_TURNS = 100;
	private const MAX_TURN_LENGTH      = 12000;
	private const MAX_SUMMARY          = 500;
	private const MAX_PAGE_SIZE        = 50;

	/** Return the customer conversation review projection table name. */
	public static function table_name(): string {
		return Database::customer_conversation_reviews_table_name();
	}

	/** Return the normalized customer conversation review turns table name. */
	public static function turns_table_name(): string {
		return Database::customer_conversation_review_turns_table_name();
	}

	/**
	 * Return whether both optional review-projection tables are available.
	 *
	 * Customer delivery must continue while a rolling deployment has installed the
	 * runtime tables but has not yet installed this display-only projection.
	 */
	public static function is_storage_available(): bool {
		global $wpdb;

		foreach ( array( self::table_name(), self::turns_table_name() ) as $table_name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Detects whether an optional display-only projection is available during a rolling schema upgrade.
			$table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
			if ( $table_name !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Create an anonymous public-embed review shell after visitor consent.
	 *
	 * The review identifier is stored only in the signed-session transient. It is
	 * never added to the public token or REST response.
	 */
	public static function create_public_review( string $review_id, int $agent_id, string $provider_id, string $model_id, string $expires_at ): bool {
		return self::create_review(
			$review_id,
			null,
			self::SOURCE_PUBLIC_EMBED,
			$agent_id,
			'queued',
			$provider_id,
			$model_id,
			$expires_at
		);
	}

	/**
	 * Create an isolated runtime review shell without copying a private runtime row.
	 */
	public static function create_runtime_review( string $review_id, string $runtime_conversation_id, string $provider_id, string $model_id, string $expires_at, int $agent_id = 0 ): bool {
		return self::create_review(
			$review_id,
			$runtime_conversation_id,
			self::SOURCE_CUSTOMER_RUNTIME,
			$agent_id,
			'queued',
			$provider_id,
			$model_id,
			$expires_at
		);
	}

	/**
	 * Append one public-embed turn using a unique job/event identifier.
	 *
	 * Metadata is restricted to safe provider, model, and usage fields.
	 *
	 * @phpstan-param array<string,mixed> $metadata
	 */
	public static function append_public_turn( string $review_id, string $source_event_id, string $role, string $content, string $status, array $metadata = array() ): bool {
		return self::append_turn( $review_id, $source_event_id, $role, $content, $status, $metadata );
	}

	/**
	 * Append one runtime turn by its server-only conversation identifier.
	 *
	 * Metadata is restricted to safe provider, model, and usage fields.
	 *
	 * @phpstan-param array<string,mixed> $metadata
	 */
	public static function append_runtime_turn( string $runtime_conversation_id, string $source_event_id, string $role, string $content, string $status, array $metadata = array() ): bool {
		$review_id = self::get_runtime_review_id( $runtime_conversation_id );
		if ( null === $review_id ) {
			return false;
		}

		return self::append_turn( $review_id, $source_event_id, $role, $content, $status, $metadata );
	}

	/**
	 * Update a public review event's terminal state without writing response text.
	 *
	 * Metadata is restricted to safe provider, model, and usage fields.
	 *
	 * @phpstan-param array<string,mixed> $metadata
	 */
	public static function update_public_review_status( string $review_id, string $source_event_id, string $status, array $metadata = array() ): bool {
		return self::update_event_status( $review_id, $source_event_id, $status, $metadata );
	}

	/**
	 * Update a runtime review event's terminal state without writing raw errors.
	 *
	 * Metadata is restricted to safe provider, model, and usage fields.
	 *
	 * @phpstan-param array<string,mixed> $metadata
	 */
	public static function update_runtime_review_status( string $runtime_conversation_id, string $source_event_id, string $status, array $metadata = array() ): bool {
		$review_id = self::get_runtime_review_id( $runtime_conversation_id );
		if ( null === $review_id ) {
			return false;
		}

		return self::update_event_status( $review_id, $source_event_id, $status, $metadata );
	}

	/**
	 * Return one safe review detail DTO or null when it is missing, expired, or deleted.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_review( string $review_id, int $turn_limit = self::MAX_DETAIL_TURNS, int $turn_offset = 0 ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$turn_limit  = min( self::MAX_DETAIL_TURNS, max( 1, $turn_limit ) );
		$turn_offset = min( 10000, max( 0, $turn_offset ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads one minimized internal review projection by opaque public identifier.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT review_id, source, agent_id, status, summary, turn_count, provider_id, model_id, iterations_used, prompt_tokens, completion_tokens, handoff_intent, error_code, expires_at, created_at, updated_at FROM %i WHERE review_id = %s AND deleted_at IS NULL AND expires_at >= %s LIMIT 1',
				self::table_name(),
				$review_id,
				current_time( 'mysql', true )
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Detail loads one bounded page of already-sanitized turns, newest first before restoring transcript order.
		$turns = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT role, content FROM ( SELECT role, content, created_at, id FROM %i WHERE review_id = %s ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d ) AS recent_turns ORDER BY created_at ASC, id ASC',
				self::turns_table_name(),
				$review_id,
				$turn_limit,
				$turn_offset
			),
			ARRAY_A
		);

		$row['transcript']             = self::normalise_turn_rows( is_array( $turns ) ? $turns : array() );
		$detail                        = self::normalise_detail( $row );
		$detail['transcript_offset']   = $turn_offset;
		$detail['transcript_limit']    = $turn_limit;
		$detail['transcript_has_more'] = (int) $detail['turn_count'] > $turn_offset + count( $detail['transcript'] );

		return $detail;
	}

	/**
	 * List safe summaries without loading transcript bodies.
	 *
	 * @param array<string,mixed> $filters List filters supplied by an admin route.
	 * @return list<array<string,mixed>>
	 */
	public static function list_reviews( array $filters ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		list( $where, $args ) = self::build_filter_query( $filters );
		$limit                = min( self::MAX_PAGE_SIZE, max( 1, (int) ( $filters['limit'] ?? 20 ) ) );
		$offset               = max( 0, min( 10000, (int) ( $filters['offset'] ?? 0 ) ) );
		$sql                  = 'SELECT review_id, source, agent_id, status, summary, turn_count, provider_id, model_id, iterations_used, prompt_tokens, completion_tokens, handoff_intent, error_code, expires_at, created_at, updated_at FROM %i WHERE ' . $where . ' ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d';
		$query_args           = array_merge( array( self::table_name() ), $args, array( $limit, $offset ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Paged read intentionally excludes transcripts and private source identifiers.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$query_args ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_values(
			array_map(
				static fn( array $row ): array => self::normalise_summary( $row ),
				$rows
			)
		);
	}

	/**
	 * Count review summaries for the same bounded filter set used by list_reviews().
	 *
	 * @param array<string,mixed> $filters List filters supplied by an admin route.
	 */
	public static function count_reviews( array $filters ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		list( $where, $args ) = self::build_filter_query( $filters );
		$sql                  = 'SELECT COUNT(*) FROM %i WHERE ' . $where;
		$query_args           = array_merge( array( self::table_name() ), $args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded admin pagination count over the minimized review table.
		return max( 0, (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$query_args ) ) );
	}

	/**
	 * Delete visible customer content while preserving the review source tombstone.
	 *
	 * Repeating deletion for an existing tombstone is intentionally idempotent.
	 */
	public static function delete_review( string $review_id ): bool {
		return self::tombstone_review( $review_id, 'deleted' );
	}

	/** Remove up to a bounded number of retained reviews after explicit admin confirmation. */
	public static function purge_reviews( int $limit = 500 ): int {
		return self::purge_matching_reviews( 'deleted_at IS NULL', array(), $limit, 'deleted' );
	}

	/** Purge expired reviews through a bounded, retry-safe tombstone update. */
	public static function purge_expired_reviews( int $limit = 100 ): int {
		return self::purge_matching_reviews( 'deleted_at IS NULL AND expires_at < %s', array( current_time( 'mysql', true ) ), $limit, 'expired' );
	}

	/**
	 * Backfill a bounded set of existing runtime conversations into the safe projection.
	 *
	 * This reads runtime history only long enough to create the sanitized projection;
	 * it neither exposes nor rewrites the private runtime payloads.
	 */
	public static function reconcile_runtime_reviews( int $limit = 50 ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$limit = max( 1, min( self::MAX_PAGE_SIZE, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded migration read from private runtime data into a separate sanitized projection.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT runtime.conversation_id, runtime.runtime_history, runtime.expires_at FROM %i AS runtime LEFT JOIN %i AS review ON review.runtime_conversation_id = runtime.conversation_id WHERE review.runtime_conversation_id IS NULL AND runtime.expires_at >= %s ORDER BY runtime.updated_at DESC LIMIT %d',
				CustomerAgentRuntimeRepository::conversations_table_name(),
				self::table_name(),
				current_time( 'mysql', true ),
				$limit
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return 0;
		}

		$created = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$conversation_id = (string) ( $row['conversation_id'] ?? '' );
			if ( '' === $conversation_id ) {
				continue;
			}

			$latest_job = CustomerAgentRuntimeRepository::get_latest_job_for_conversation( $conversation_id );
			$metadata   = is_array( $latest_job )
				? array(
					'provider_id'             => (string) ( $latest_job['provider_id'] ?? '' ),
					'model_id'                => (string) ( $latest_job['model_id'] ?? '' ),
					'iterations_used'         => (int) ( $latest_job['iterations_used'] ?? 0 ),
					'prompt_tokens_total'     => (int) ( $latest_job['prompt_tokens'] ?? 0 ),
					'completion_tokens_total' => (int) ( $latest_job['completion_tokens'] ?? 0 ),
					'error_code'              => (string) ( $latest_job['error_code'] ?? '' ),
				)
				: array();
			$status     = self::sanitize_status( $latest_job['status'] ?? '' );
			$status     = '' !== $status ? $status : 'complete';

			if ( ! self::create_runtime_review(
				wp_generate_uuid4(),
				$conversation_id,
				(string) ( $metadata['provider_id'] ?? '' ),
				(string) ( $metadata['model_id'] ?? '' ),
				(string) ( $row['expires_at'] ?? current_time( 'mysql', true ) )
			) ) {
				continue;
			}

			$history = json_decode( (string) ( $row['runtime_history'] ?? '[]' ), true );
			$turns   = self::sanitize_transcript( is_array( $history ) ? $history : array() );
			foreach ( $turns as $index => $turn ) {
				self::append_runtime_turn(
					$conversation_id,
					hash( 'sha256', $conversation_id . '|' . $index ),
					$turn['role'],
					$turn['content'],
					$status
				);
			}
			$last_event_id = empty( $turns )
				? hash( 'sha256', $conversation_id . '|status' )
				: hash( 'sha256', $conversation_id . '|' . ( count( $turns ) - 1 ) );
			self::update_runtime_review_status( $conversation_id, $last_event_id, $status, $metadata );
			++$created;
		}

		return $created;
	}

	/** Remove review projections when a runtime conversation is destroyed. */
	public static function delete_by_runtime_conversation( string $runtime_conversation_id ): bool {
		return self::delete_by_runtime_conversations( array( $runtime_conversation_id ) );
	}

	/**
	 * Remove projections for a known set of runtime conversations.
	 *
	 * Runtime conversation IDs remain server-only and never enter a review DTO.

	 * @phpstan-param list<string> $runtime_conversation_ids
	 */
	public static function delete_by_runtime_conversations( array $runtime_conversation_ids ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$ids = array_values( array_filter( $runtime_conversation_ids, static fn( mixed $id ): bool => is_string( $id ) && '' !== $id ) );
		if ( empty( $ids ) ) {
			return true;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The fixed number of placeholders is generated only from a filtered list of opaque IDs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes minimized turns only as part of the underlying runtime integration purge.
		$review_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT review_id FROM %i WHERE runtime_conversation_id IN ({$placeholders})",
				self::table_name(),
				...$ids
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		if ( ! is_array( $review_ids ) ) {
			return false;
		}
		foreach ( $review_ids as $review_id ) {
			if ( ! is_string( $review_id ) || '' === $review_id || ! self::delete_review_shell( $review_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Reduce arbitrary serialized messages to a compact, text-only review transcript.
	 *
	 * @param array<mixed> $messages Potentially provider-shaped messages.
	 * @return list<array{role:string,content:string}>
	 */
	public static function sanitize_transcript( array $messages ): array {
		$transcript = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}
			$role = self::normalise_role( $message['role'] ?? '' );
			if ( null === $role ) {
				continue;
			}
			$text = self::extract_display_text( $message );
			if ( '' === $text ) {
				continue;
			}

			$transcript[] = array(
				'role'    => $role,
				'content' => $text,
			);
		}

		return array_slice( $transcript, -self::MAX_RECONCILED_TURNS );
	}

	/**
	 * Insert a source-bound review shell without clearing an existing tombstone.
	 */
	private static function create_review( string $review_id, ?string $runtime_conversation_id, string $source, int $agent_id, string $status, string $provider_id, string $model_id, string $expires_at ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$now    = current_time( 'mysql', true );
		$data   = array(
			'review_id'               => $review_id,
			'runtime_conversation_id' => $runtime_conversation_id,
			'source'                  => self::sanitize_source( $source ),
			'agent_id'                => max( 0, $agent_id ),
			'status'                  => self::sanitize_status( $status ),
			'summary'                 => '',
			'turn_count'              => 0,
			'provider_id'             => self::sanitize_metadata_string( $provider_id, 100 ),
			'model_id'                => self::sanitize_metadata_string( $model_id, 100 ),
			'iterations_used'         => 0,
			'prompt_tokens'           => 0,
			'completion_tokens'       => 0,
			'handoff_intent'          => '',
			'error_code'              => '',
			'expires_at'              => $expires_at,
			'created_at'              => $now,
			'updated_at'              => $now,
		);
		$result = $wpdb->insert(
			self::table_name(),
			$data,
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false !== $result ) {
			return true;
		}

		return self::review_exists( $review_id ) || ( null !== $runtime_conversation_id && self::runtime_review_exists( $runtime_conversation_id ) );
	}

	/**
	 * Append an idempotent safe-display source event under a review-row lock.
	 *
	 * Metadata is restricted to safe provider, model, and usage fields.
	 *
	 * @phpstan-param array<string,mixed> $metadata
	 */
	private static function append_turn( string $review_id, string $source_event_id, string $role, string $content, string $status, array $metadata ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$role            = self::normalise_role( $role );
		$status          = self::sanitize_status( $status );
		$source_event_id = self::sanitize_event_id( $source_event_id );
		if ( null === $role || '' === $status || '' === $source_event_id ) {
			return false;
		}

		$content     = self::sanitize_turn_text( $content );
		$transaction = self::begin_transaction();
		if ( false === $transaction ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Locks one opaque review shell before appending a safe turn.
		$review = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT review_id, deleted_at, expires_at FROM %i WHERE review_id = %s LIMIT 1 FOR UPDATE',
				self::table_name(),
				$review_id
			),
			ARRAY_A
		);
		if ( ! is_array( $review ) ) {
			self::finish_transaction( $transaction, false );
			return false;
		}
		if ( ! empty( $review['deleted_at'] ) ) {
			self::finish_transaction( $transaction, true );
			return true;
		}
		if ( (string) ( $review['expires_at'] ?? '' ) < current_time( 'mysql', true ) ) {
			self::finish_transaction( $transaction, true );
			return false;
		}

		$now              = current_time( 'mysql', true );
		$event_created_at = self::event_created_at( $review_id, $source_event_id, $now );
		$inserted         = 0;
		if ( '' !== $content ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A normal insert distinguishes an existing source event from malformed or truncated writes.
			$inserted = $wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (review_id, source_event_id, role, event_status, content, created_at, updated_at) VALUES (%s, %s, %s, %s, %s, %s, %s)',
					self::turns_table_name(),
					$review_id,
					$source_event_id,
					$role,
					$status,
					$content,
					$event_created_at,
					$now
				)
			);
			if ( false === $inserted ) {
				if ( ! self::turn_exists( $review_id, $source_event_id, $role ) ) {
					self::finish_transaction( $transaction, false );
					return false;
				}
				$inserted = 0;
			}
		}

		if ( ! self::update_locked_event_status( $review_id, $source_event_id, $status, $now ) ) {
			self::finish_transaction( $transaction, false );
			return false;
		}
		if ( ! self::update_locked_review( $review_id, $source_event_id, $status, $metadata, $now, (int) $inserted > 0 && 'assistant' === $role ) ) {
			self::finish_transaction( $transaction, false );
			return false;
		}

		return self::finish_transaction( $transaction, true );
	}

	/**
	 * Update an existing source event's state under the same deletion lock.
	 *
	 * Metadata is restricted to safe provider, model, and usage fields.
	 *
	 * @phpstan-param array<string,mixed> $metadata
	 */
	private static function update_event_status( string $review_id, string $source_event_id, string $status, array $metadata ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$status          = self::sanitize_status( $status );
		$source_event_id = self::sanitize_event_id( $source_event_id );
		if ( '' === $status || '' === $source_event_id ) {
			return false;
		}

		$transaction = self::begin_transaction();
		if ( false === $transaction ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Locks one minimized review shell before status-only update.
		$review = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT review_id, deleted_at, expires_at FROM %i WHERE review_id = %s LIMIT 1 FOR UPDATE',
				self::table_name(),
				$review_id
			),
			ARRAY_A
		);
		if ( ! is_array( $review ) ) {
			self::finish_transaction( $transaction, false );
			return false;
		}
		if ( ! empty( $review['deleted_at'] ) ) {
			self::finish_transaction( $transaction, true );
			return true;
		}
		if ( (string) ( $review['expires_at'] ?? '' ) < current_time( 'mysql', true ) ) {
			self::finish_transaction( $transaction, true );
			return false;
		}

		$now = current_time( 'mysql', true );
		if ( ! self::update_locked_event_status( $review_id, $source_event_id, $status, $now ) ) {
			self::finish_transaction( $transaction, false );
			return false;
		}
		if ( ! self::update_locked_review( $review_id, $source_event_id, $status, $metadata, $now, false ) ) {
			self::finish_transaction( $transaction, false );
			return false;
		}

		return self::finish_transaction( $transaction, true );
	}

	/** Update every safe turn for a source event to its latest known safe state. */
	private static function update_locked_event_status( string $review_id, string $source_event_id, string $status, string $now ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Updates only safe event-state metadata under the review-row transaction lock.
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET event_status = %s, updated_at = %s WHERE review_id = %s AND source_event_id = %s',
				self::turns_table_name(),
				$status,
				$now,
				$review_id,
				$source_event_id
			)
		);

		return false !== $result;
	}

	/**
	 * Refresh a locked review summary, counts, current event status, and metadata.
	 *
	 * Late terminal writes may add token totals, but only the latest customer event
	 * may replace conversation-level provider/model/error metadata.
	 *
	 * Metadata is restricted to safe provider, model, and usage fields.
	 *
	 * @phpstan-param array<string,mixed> $metadata
	 */
	private static function update_locked_review( string $review_id, string $source_event_id, string $fallback_status, array $metadata, string $now, bool $increment_tokens ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$aggregate        = self::locked_review_aggregate( $review_id, $fallback_status );
		$is_current_event = '' === $aggregate['source_event_id'] || $source_event_id === $aggregate['source_event_id'];
		$data             = $is_current_event ? self::review_metadata_data( $metadata, $now ) : array();
		$formats          = $is_current_event ? self::review_metadata_formats( $metadata ) : array();

		if ( $increment_tokens ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads existing safe aggregate counters while the review row is locked.
			$totals                    = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT prompt_tokens, completion_tokens FROM %i WHERE review_id = %s LIMIT 1',
					self::table_name(),
					$review_id
				),
				ARRAY_A
			);
			$totals                    = is_array( $totals ) ? $totals : array();
			$has_prompt_tokens         = array_key_exists( 'prompt_tokens', $data );
			$has_completion_tokens     = array_key_exists( 'completion_tokens', $data );
			$data['prompt_tokens']     = max( 0, (int) ( $totals['prompt_tokens'] ?? 0 ) ) + max( 0, (int) ( $metadata['prompt_tokens'] ?? 0 ) );
			$data['completion_tokens'] = max( 0, (int) ( $totals['completion_tokens'] ?? 0 ) ) + max( 0, (int) ( $metadata['completion_tokens'] ?? 0 ) );
			if ( ! $has_prompt_tokens ) {
				$formats[] = '%d';
			}
			if ( ! $has_completion_tokens ) {
				$formats[] = '%d';
			}
		}

		$data['status']     = $aggregate['status'];
		$data['summary']    = $aggregate['summary'];
		$data['turn_count'] = $aggregate['turn_count'];
		$formats[]          = '%s';
		$formats[]          = '%s';
		$formats[]          = '%d';

		$result = $wpdb->update(
			self::table_name(),
			$data,
			array( 'review_id' => $review_id ),
			$formats,
			array( '%s' )
		);

		return false !== $result;
	}

	/**
	 * @phpstan-param array<string,mixed> $metadata
	 * @return array<string,mixed>
	 */
	private static function review_metadata_data( array $metadata, string $now ): array {
		$data = array( 'updated_at' => $now );
		if ( array_key_exists( 'provider_id', $metadata ) ) {
			$data['provider_id'] = self::sanitize_metadata_string( $metadata['provider_id'], 100 );
		}
		if ( array_key_exists( 'model_id', $metadata ) ) {
			$data['model_id'] = self::sanitize_metadata_string( $metadata['model_id'], 100 );
		}
		if ( array_key_exists( 'iterations_used', $metadata ) ) {
			$data['iterations_used'] = max( 0, (int) $metadata['iterations_used'] );
		}
		if ( array_key_exists( 'handoff_intent', $metadata ) ) {
			$data['handoff_intent'] = self::sanitize_handoff_intent( $metadata['handoff_intent'] );
		}
		if ( array_key_exists( 'error_code', $metadata ) ) {
			$data['error_code'] = self::sanitize_metadata_string( $metadata['error_code'], 100 );
		}
		if ( array_key_exists( 'prompt_tokens_total', $metadata ) ) {
			$data['prompt_tokens'] = max( 0, (int) $metadata['prompt_tokens_total'] );
		}
		if ( array_key_exists( 'completion_tokens_total', $metadata ) ) {
			$data['completion_tokens'] = max( 0, (int) $metadata['completion_tokens_total'] );
		}
		if ( array_key_exists( 'expires_at', $metadata ) && is_string( $metadata['expires_at'] ) && '' !== $metadata['expires_at'] ) {
			$data['expires_at'] = $metadata['expires_at'];
		}
		return $data;
	}

	/**
	 * @phpstan-param array<string,mixed> $metadata
	 * @return list<string>
	 */
	private static function review_metadata_formats( array $metadata ): array {
		$formats = array( '%s' );
		if ( array_key_exists( 'provider_id', $metadata ) ) {
			$formats[] = '%s';
		}
		if ( array_key_exists( 'model_id', $metadata ) ) {
			$formats[] = '%s';
		}
		if ( array_key_exists( 'iterations_used', $metadata ) ) {
			$formats[] = '%d';
		}
		if ( array_key_exists( 'handoff_intent', $metadata ) ) {
			$formats[] = '%s';
		}
		if ( array_key_exists( 'error_code', $metadata ) ) {
			$formats[] = '%s';
		}
		if ( array_key_exists( 'prompt_tokens_total', $metadata ) ) {
			$formats[] = '%d';
		}
		if ( array_key_exists( 'completion_tokens_total', $metadata ) ) {
			$formats[] = '%d';
		}
		if ( array_key_exists( 'expires_at', $metadata ) && is_string( $metadata['expires_at'] ) && '' !== $metadata['expires_at'] ) {
			$formats[] = '%s';
		}
		return $formats;
	}

	/**
	 * Return summary/count/status while the parent review row remains locked.
	 *
	 * @return array{summary:string,turn_count:int,status:string,source_event_id:string}
	 */
	private static function locked_review_aggregate( string $review_id, string $fallback_status ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregates safe turn rows under the existing review-row transaction lock.
		$turn_count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE review_id = %s', self::turns_table_name(), $review_id )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Summary is sourced only from the first sanitized customer turn.
		$summary = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT content FROM %i WHERE review_id = %s AND role = %s ORDER BY created_at ASC, id ASC LIMIT 1',
				self::turns_table_name(),
				$review_id,
				'user'
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The latest customer event determines visible aggregate status and metadata ownership, not late completion order.
		$latest_event = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT source_event_id, event_status FROM %i WHERE review_id = %s AND role = %s ORDER BY created_at DESC, id DESC LIMIT 1',
				self::turns_table_name(),
				$review_id,
				'user'
			),
			ARRAY_A
		);

		$status = self::sanitize_status( is_array( $latest_event ) ? ( $latest_event['event_status'] ?? '' ) : '' );
		if ( '' === $status ) {
			$status = $fallback_status;
		}

		return array(
			'summary'         => self::sanitize_text( is_string( $summary ) ? $summary : '', self::MAX_SUMMARY ),
			'turn_count'      => max( 0, $turn_count ),
			'status'          => $status,
			'source_event_id' => is_array( $latest_event ) && is_string( $latest_event['source_event_id'] ?? null ) ? $latest_event['source_event_id'] : '',
		);
	}

	/**
	 * Tombstone one review while removing all retained content under a row lock.
	 */
	private static function tombstone_review( string $review_id, string $status ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$status = self::sanitize_status( $status );
		if ( '' === $status ) {
			return false;
		}
		$transaction = self::begin_transaction();
		if ( false === $transaction ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Locks one opaque review before clearing safe retained content.
		$review = $wpdb->get_row(
			$wpdb->prepare( 'SELECT review_id, deleted_at FROM %i WHERE review_id = %s LIMIT 1 FOR UPDATE', self::table_name(), $review_id ),
			ARRAY_A
		);
		if ( ! is_array( $review ) ) {
			self::finish_transaction( $transaction, false );
			return false;
		}
		if ( ! empty( $review['deleted_at'] ) ) {
			return self::finish_transaction( $transaction, true );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Physically removes sanitized turn content while preserving the review-shell tombstone.
		$turns_deleted = $wpdb->delete( self::turns_table_name(), array( 'review_id' => $review_id ), array( '%s' ) );
		if ( false === $turns_deleted ) {
			self::finish_transaction( $transaction, false );
			return false;
		}

		$now    = current_time( 'mysql', true );
		$update = $wpdb->update(
			self::table_name(),
			array(
				'status'            => $status,
				'summary'           => '',
				'turn_count'        => 0,
				'provider_id'       => '',
				'model_id'          => '',
				'iterations_used'   => 0,
				'prompt_tokens'     => 0,
				'completion_tokens' => 0,
				'handoff_intent'    => '',
				'error_code'        => '',
				'deleted_at'        => $now,
				'updated_at'        => $now,
			),
			array( 'review_id' => $review_id ),
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ),
			array( '%s' )
		);
		if ( false === $update ) {
			self::finish_transaction( $transaction, false );
			return false;
		}

		return self::finish_transaction( $transaction, true );
	}

	/**
	 * Selects a bounded set before each tombstone receives its own deletion lock.
	 *
	 * @phpstan-param list<mixed> $args
	 */
	private static function purge_matching_reviews( string $where, array $args, int $limit, string $status ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$limit      = max( 1, min( 1000, $limit ) );
		$sql        = 'SELECT review_id FROM %i WHERE ' . $where . ' ORDER BY updated_at ASC, id ASC LIMIT %d';
		$query_args = array_merge( array( self::table_name() ), $args, array( $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded selection lets each tombstone take the same race-safe deletion path.
		$review_ids = $wpdb->get_col( $wpdb->prepare( $sql, ...$query_args ) );
		if ( ! is_array( $review_ids ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $review_ids as $review_id ) {
			if ( is_string( $review_id ) && self::tombstone_review( $review_id, $status ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/** Return the event creation time from the customer turn when it already exists. */
	private static function event_created_at( string $review_id, string $source_event_id, string $fallback ): string {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Keeps assistant/event ordering anchored to its original customer message.
		$created_at = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT created_at FROM %i WHERE review_id = %s AND source_event_id = %s AND role = %s LIMIT 1',
				self::turns_table_name(),
				$review_id,
				$source_event_id,
				'user'
			)
		);

		return is_string( $created_at ) && '' !== $created_at ? $created_at : $fallback;
	}

	/** Determine whether a duplicate-key retry already persisted this exact turn. */
	private static function turn_exists( string $review_id, string $source_event_id, string $role ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verifies only the unique idempotency tuple while the parent review row remains locked.
		return null !== $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE review_id = %s AND source_event_id = %s AND role = %s LIMIT 1',
				self::turns_table_name(),
				$review_id,
				$source_event_id,
				$role
			)
		);
	}

	/** Permanently remove one review shell and its turns under the append/delete lock. */
	private static function delete_review_shell( string $review_id ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$transaction = self::begin_transaction();
		if ( false === $transaction ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uses the same parent-row lock as append and tombstone operations.
		$locked = $wpdb->get_var(
			$wpdb->prepare( 'SELECT review_id FROM %i WHERE review_id = %s LIMIT 1 FOR UPDATE', self::table_name(), $review_id )
		);
		if ( ! is_string( $locked ) ) {
			return self::finish_transaction( $transaction, true );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes minimized turns while their parent shell is locked.
		$turns_deleted = $wpdb->delete( self::turns_table_name(), array( 'review_id' => $review_id ), array( '%s' ) );
		if ( false === $turns_deleted ) {
			self::finish_transaction( $transaction, false );
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes the locked review shell after all retained content is gone.
		$review_deleted = $wpdb->delete( self::table_name(), array( 'review_id' => $review_id ), array( '%s' ) );
		if ( false === $review_deleted ) {
			self::finish_transaction( $transaction, false );
			return false;
		}

		return self::finish_transaction( $transaction, true );
	}

	/** Begin a local transaction without committing a caller-owned transaction. */
	private static function begin_transaction(): string|false {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Detects a caller-owned transaction, including the WordPress PHPUnit isolation transaction.
		$in_transaction = (int) $wpdb->get_var( 'SELECT @@in_transaction' );
		if ( 1 === $in_transaction ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A savepoint preserves the caller's transaction boundary.
			return false === $wpdb->query( 'SAVEPOINT sd_ai_agent_review_projection' ) ? false : 'savepoint';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Starts a narrowly scoped projection write transaction.
		return false === $wpdb->query( 'START TRANSACTION' ) ? false : 'transaction';
	}

	/** Commit or roll back a local transaction or savepoint. */
	private static function finish_transaction( string $transaction, bool $commit ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( 'savepoint' === $transaction ) {
			$sql = $commit
				? 'RELEASE SAVEPOINT sd_ai_agent_review_projection'
				: 'ROLLBACK TO SAVEPOINT sd_ai_agent_review_projection';
		} else {
			$sql = $commit ? 'COMMIT' : 'ROLLBACK';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Ends only the transaction boundary opened by begin_transaction().
		$result = $wpdb->query( $sql );

		return false !== $result;
	}

	/**
	 * Build a prepared SQL fragment for bounded review filtering.
	 *
	 * @phpstan-param array<string,mixed> $filters
	 * @phpstan-return array{0:string,1:list<mixed>}
	 */
	private static function build_filter_query( array $filters ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$clauses = array( 'deleted_at IS NULL', 'expires_at >= %s' );
		$args    = array( current_time( 'mysql', true ) );
		$source  = self::sanitize_source( $filters['source'] ?? '' );
		if ( '' !== $source ) {
			$clauses[] = 'source = %s';
			$args[]    = $source;
		}

		$status = self::sanitize_status( $filters['status'] ?? '' );
		if ( '' !== $status ) {
			$clauses[] = 'status = %s';
			$args[]    = $status;
		}

		$agent = self::sanitize_metadata_string( $filters['agent'] ?? '', 100 );
		if ( '' !== $agent ) {
			if ( ctype_digit( $agent ) ) {
				$clauses[] = 'agent_id = %d';
				$args[]    = (int) $agent;
			}
		}

		$date_from = self::normalise_date( $filters['date_from'] ?? '', false );
		if ( '' !== $date_from ) {
			$clauses[] = 'created_at >= %s';
			$args[]    = $date_from;
		}
		$date_to = self::normalise_date( $filters['date_to'] ?? '', true );
		if ( '' !== $date_to ) {
			$clauses[] = 'created_at <= %s';
			$args[]    = $date_to;
		}

		$search = self::sanitize_metadata_string( $filters['search'] ?? '', 100 );
		if ( '' !== $search ) {
			$clauses[] = 'MATCH (summary) AGAINST (%s IN NATURAL LANGUAGE MODE)';
			$args[]    = $search;
		}

		return array( implode( ' AND ', $clauses ), $args );
	}

	/**
	 * Normalize one database row into a transcript-free review summary DTO.
	 *
	 * @phpstan-param array<string,mixed> $row
	 * @phpstan-return array<string,mixed>
	 */
	private static function normalise_summary( array $row ): array {
		return array(
			'id'                => (string) ( $row['review_id'] ?? '' ),
			'source'            => self::sanitize_source( $row['source'] ?? '' ),
			'agent_id'          => max( 0, (int) ( $row['agent_id'] ?? 0 ) ),
			'status'            => self::sanitize_status( $row['status'] ?? '' ),
			'preview'           => self::sanitize_text( (string) ( $row['summary'] ?? '' ), self::MAX_SUMMARY ),
			'turn_count'        => max( 0, (int) ( $row['turn_count'] ?? 0 ) ),
			'provider_id'       => self::sanitize_metadata_string( $row['provider_id'] ?? '', 100 ),
			'model_id'          => self::sanitize_metadata_string( $row['model_id'] ?? '', 100 ),
			'iterations_used'   => max( 0, (int) ( $row['iterations_used'] ?? 0 ) ),
			'prompt_tokens'     => max( 0, (int) ( $row['prompt_tokens'] ?? 0 ) ),
			'completion_tokens' => max( 0, (int) ( $row['completion_tokens'] ?? 0 ) ),
			'handoff_intent'    => self::sanitize_handoff_intent( $row['handoff_intent'] ?? '' ),
			'error_code'        => self::sanitize_metadata_string( $row['error_code'] ?? '', 100 ),
			'expires_at'        => (string) ( $row['expires_at'] ?? '' ),
			'created_at'        => (string) ( $row['created_at'] ?? '' ),
			'updated_at'        => (string) ( $row['updated_at'] ?? '' ),
		);
	}

	/**
	 * Normalize one database row into a review detail DTO.
	 *
	 * @phpstan-param array<string,mixed> $row
	 * @phpstan-return array<string,mixed>
	 */
	private static function normalise_detail( array $row ): array {
		$detail               = self::normalise_summary( $row );
		$detail['transcript'] = isset( $row['transcript'] ) && is_array( $row['transcript'] )
			? self::normalise_turn_rows( $row['transcript'] )
			: array();

		return $detail;
	}

	/**
	 * Normalize one bounded page of sanitized turn rows.
	 *
	 * @phpstan-param array<int|string,mixed> $turns
	 * @return list<array{role:string,content:string}>
	 */
	private static function normalise_turn_rows( array $turns ): array {
		$normalised = array();
		foreach ( $turns as $turn ) {
			if ( ! is_array( $turn ) ) {
				continue;
			}
			$role = self::normalise_role( $turn['role'] ?? '' );
			if ( null === $role ) {
				continue;
			}
			$content = self::sanitize_turn_text( (string) ( $turn['content'] ?? '' ) );
			if ( '' === $content ) {
				continue;
			}
			$normalised[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		return $normalised;
	}

	/**
	 * Extract content-channel display text from one provider-shaped message.
	 *
	 * @phpstan-param array<string,mixed> $message
	 */
	private static function extract_display_text( array $message ): string {
		$text = '';
		if ( isset( $message['content'] ) && is_string( $message['content'] ) ) {
			$text .= $message['content'];
		}

		$parts = $message['parts'] ?? null;
		if ( is_array( $parts ) ) {
			foreach ( $parts as $part ) {
				if ( ! is_array( $part ) || ! self::is_content_part( $part ) ) {
					continue;
				}
				if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
					$text .= $part['text'];
				} elseif ( isset( $part['content'] ) && is_string( $part['content'] ) ) {
					$text .= $part['content'];
				}
			}
		}

		return self::sanitize_turn_text( $text );
	}

	/**
	 * Determine whether one provider-shaped part is safe display content.
	 *
	 * @phpstan-param array<string,mixed> $part
	 */
	private static function is_content_part( array $part ): bool {
		$channel = $part['channel'] ?? null;

		return null === $channel || '' === $channel || ( is_string( $channel ) && 'content' === strtolower( $channel ) );
	}

	private static function normalise_role( mixed $role ): ?string {
		$role = is_string( $role ) ? strtolower( $role ) : '';
		if ( 'user' === $role ) {
			return 'user';
		}
		if ( in_array( $role, array( 'assistant', 'model' ), true ) ) {
			return 'assistant';
		}

		return null;
	}

	private static function sanitize_text( string $value, int $max_length ): string {
		return DurablePlanTextSanitizer::sanitize( $value, $max_length );
	}

	/** Remove hidden reasoning and redact durable secrets before persisting a review turn. */
	private static function sanitize_turn_text( string $value ): string {
		return self::sanitize_text(
			ConversationDisplaySanitizer::sanitize_display_text( $value ),
			self::MAX_TURN_LENGTH
		);
	}

	private static function sanitize_source( mixed $source ): string {
		$source = is_string( $source ) ? sanitize_key( $source ) : '';

		return in_array( $source, array( self::SOURCE_PUBLIC_EMBED, self::SOURCE_CUSTOMER_RUNTIME ), true ) ? $source : '';
	}

	private static function sanitize_status( mixed $status ): string {
		$status = is_string( $status ) ? sanitize_key( $status ) : '';

		return in_array( $status, array( 'queued', 'processing', 'complete', 'failed', 'cancelled', 'deleted', 'expired' ), true ) ? $status : '';
	}

	private static function sanitize_handoff_intent( mixed $intent ): string {
		$intent = is_string( $intent ) ? sanitize_key( $intent ) : '';

		return in_array( $intent, array( 'human_support', 'private_data_required', 'insufficient_evidence', 'unsafe_request' ), true ) ? $intent : '';
	}

	private static function sanitize_event_id( string $source_event_id ): string {
		$source_event_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', $source_event_id );
		$source_event_id = is_string( $source_event_id ) ? $source_event_id : '';

		return function_exists( 'mb_substr' ) ? mb_substr( $source_event_id, 0, 64 ) : substr( $source_event_id, 0, 64 );
	}

	private static function sanitize_metadata_string( mixed $value, int $max_length ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return self::sanitize_text( (string) $value, $max_length );
	}

	private static function normalise_date( mixed $date, bool $end_of_day ): string {
		$date = is_string( $date ) ? $date : '';
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}
		$time = $end_of_day ? ' 23:59:59' : ' 00:00:00';
		$unix = strtotime( $date . $time . ' UTC' );

		return false === $unix ? '' : gmdate( 'Y-m-d H:i:s', $unix );
	}

	private static function review_exists( string $review_id ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verifies idempotent shell creation by opaque review ID.
		return null !== $wpdb->get_var(
			$wpdb->prepare( 'SELECT review_id FROM %i WHERE review_id = %s LIMIT 1', self::table_name(), $review_id )
		);
	}

	private static function runtime_review_exists( string $runtime_conversation_id ): bool {
		return null !== self::get_runtime_review_id( $runtime_conversation_id );
	}

	private static function get_runtime_review_id( string $runtime_conversation_id ): ?string {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Server-only lookup never enters a review DTO.
		$review_id = $wpdb->get_var(
			$wpdb->prepare( 'SELECT review_id FROM %i WHERE runtime_conversation_id = %s LIMIT 1', self::table_name(), $runtime_conversation_id )
		);

		return is_string( $review_id ) && '' !== $review_id ? $review_id : null;
	}
}
