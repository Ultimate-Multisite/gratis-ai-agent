<?php

declare(strict_types=1);
/**
 * Durable storage for bounded multi-phase site-operation plans.
 *
 * Plan rows deliberately contain compact operational summaries rather than
 * conversation transcripts, provider prompts, or raw tool payloads.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Models;

use SdAiAgent\Core\Database;
use SdAiAgent\Core\DurablePlanTextSanitizer;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DurablePlanRepository {

	/** @var list<string> Valid durable-plan lifecycle states. */
	public const PLAN_STATUSES = [ 'pending', 'running', 'awaiting_approval', 'completed', 'failed', 'blocked', 'cancelled' ];

	/** @var list<string> Valid durable-plan phase lifecycle states. */
	public const STEP_STATUSES = [ 'pending', 'running', 'awaiting_approval', 'completed', 'failed', 'interrupted', 'cancelled' ];

	/** @var list<string> Supported phase risk classifications. */
	public const CLASSIFICATIONS = [ 'read', 'write', 'destructive' ];

	/** Maximum database-backed phase key length. */
	private const MAX_STEP_KEY_LENGTH = 100;

	/**
	 * Get the durable plans table name.
	 */
	public static function table_name(): string {
		return Database::durable_plans_table_name();
	}

	/**
	 * Get the durable plan steps table name.
	 */
	public static function steps_table_name(): string {
		return Database::durable_plan_steps_table_name();
	}

	/**
	 * Create a plan and its compact phase records from server-reviewed input.
	 *
	 * Only server-side planning code may use this method. Browser requests must use
	 * create_from_client() so they cannot label a consequential phase as safe.
	 *
	 * @param int                  $session_id Session that owns the plan.
	 * @param int                  $user_id    User that created the plan.
	 * @param array<string, mixed> $definition Compact plan definition.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create( int $session_id, int $user_id, array $definition ): array|WP_Error {
		return self::create_with_policy( $session_id, $user_id, $definition, true );
	}

	/**
	 * Create a plan from browser-controlled compact metadata.
	 *
	 * Client classifications remain descriptive only. Every client-created phase
	 * requires an approval and cannot automatically resume after interruption.
	 *
	 * @param int                  $session_id Session that owns the plan.
	 * @param int                  $user_id    User that created the plan.
	 * @param array<string, mixed> $definition Compact plan definition.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_from_client( int $session_id, int $user_id, array $definition ): array|WP_Error {
		return self::create_with_policy( $session_id, $user_id, $definition, false );
	}

	/**
	 * Persist a reviewed compact definition and its execution policy.
	 *
	 * The accepted definition intentionally has no raw_prompt, history, tool_result,
	 * or credential field. Callers must pass a reviewed scope and compact phase
	 * instructions instead of copying chat state into this storage.
	 *
	 * @param int                  $session_id       Session that owns the plan.
	 * @param int                  $user_id          User that created the plan.
	 * @param array<string, mixed> $definition       Compact plan definition.
	 * @param bool                 $server_reviewed Whether server-side planning reviewed phase safety.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function create_with_policy( int $session_id, int $user_id, array $definition, bool $server_reviewed ): array|WP_Error {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$scope = self::sanitize_text( (string) ( $definition['scope'] ?? '' ), 1200 );
		$steps = $definition['steps'] ?? [];
		if ( $session_id <= 0 || $user_id <= 0 || '' === $scope || ! is_array( $steps ) || empty( $steps ) ) {
			return new WP_Error( 'sd_ai_agent_plan_invalid', __( 'A session, scope, and at least one phase are required.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
		}

		if ( count( $steps ) > 20 ) {
			return new WP_Error( 'sd_ai_agent_plan_too_many_steps', __( 'A durable plan can contain at most 20 phases.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
		}

		$plan_id = wp_generate_uuid4();
		$now     = current_time( 'mysql', true );
		$summary = self::sanitize_text( (string) ( $definition['summary'] ?? '' ), 600 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom durable-plan table.
		$inserted = $wpdb->insert(
			self::table_name(),
			[
				'plan_id'             => $plan_id,
				'session_id'          => $session_id,
				'user_id'             => $user_id,
				'scope'               => $scope,
				'scope_hash'          => hash( 'sha256', $scope ),
				'pending_scope'       => '',
				'pending_scope_hash'  => '',
				'summary'             => $summary,
				'status'              => 'pending',
				'current_step'        => 0,
				'approval_request_id' => 0,
				'created_at'          => $now,
				'updated_at'          => $now,
			],
			[ '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			return new WP_Error( 'sd_ai_agent_plan_create_failed', __( 'The durable plan could not be saved.', 'superdav-ai-agent' ), [ 'status' => 500 ] );
		}

		$plan_db_id     = (int) $wpdb->insert_id;
		$seen_step_keys = [];
		foreach ( array_values( $steps ) as $position => $raw_step ) {
			if ( ! is_array( $raw_step ) ) {
				self::delete_by_id( $plan_db_id );
				return new WP_Error( 'sd_ai_agent_plan_invalid_step', __( 'Each durable plan phase must be an object.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
			}

			$step = self::normalize_step( $raw_step, $position + 1, $plan_id, $server_reviewed );
			if ( is_wp_error( $step ) ) {
				self::delete_by_id( $plan_db_id );
				return $step;
			}
			$step_key = (string) $step['step_key'];
			if ( isset( $seen_step_keys[ $step_key ] ) ) {
				self::delete_by_id( $plan_db_id );
				return new WP_Error( 'sd_ai_agent_plan_duplicate_step_key', __( 'Each durable plan phase needs a unique key.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
			}
			$seen_step_keys[ $step_key ] = true;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom durable-plan table.
			$step_inserted = $wpdb->insert(
				self::steps_table_name(),
				[
					'plan_db_id'          => $plan_db_id,
					'step_key'            => $step['step_key'],
					'position'            => $step['position'],
					'title'               => $step['title'],
					'instruction'         => $step['instruction'],
					'classification'      => $step['classification'],
					'requires_approval'   => $step['requires_approval'],
					'safe_to_resume'      => $step['safe_to_resume'],
					'idempotency_key'     => $step['idempotency_key'],
					'preconditions'       => $step['preconditions'],
					'expected_evidence'   => $step['expected_evidence'],
					'rollback_guidance'   => $step['rollback_guidance'],
					'status'              => 'pending',
					'approval_request_id' => 0,
					'job_id'              => '',
					'evidence'            => '',
					'failure_message'     => '',
					'attempts'            => 0,
					'created_at'          => $now,
					'updated_at'          => $now,
				],
				[ '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
			);

			if ( false === $step_inserted ) {
				self::delete_by_id( $plan_db_id );
				return new WP_Error( 'sd_ai_agent_plan_step_create_failed', __( 'A durable plan phase could not be saved.', 'superdav-ai-agent' ), [ 'status' => 500 ] );
			}
		}

		$plan = self::get_by_plan_id( $plan_id );
		return $plan ?? new WP_Error( 'sd_ai_agent_plan_create_failed', __( 'The durable plan could not be loaded after saving.', 'superdav-ai-agent' ), [ 'status' => 500 ] );
	}

	/**
	 * Get a plan with all phase records by public UUID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_by_plan_id( string $plan_id ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom durable-plan table.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE plan_id = %s LIMIT 1', self::table_name(), $plan_id )
		);

		return $row ? self::decode_plan_row( $row ) : null;
	}

	/**
	 * Get a plan with all phase records by its internal database ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_by_id( int $plan_db_id ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom durable-plan table.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', self::table_name(), $plan_db_id )
		);

		return $row ? self::decode_plan_row( $row ) : null;
	}

	/**
	 * Get the latest plan visible to a session owner.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_latest_for_session( int $session_id, int $user_id ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom durable-plan table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE session_id = %d AND user_id = %d ORDER BY updated_at DESC, id DESC LIMIT 1',
				self::table_name(),
				$session_id,
				$user_id
			)
		);

		return $row ? self::decode_plan_row( $row ) : null;
	}

	/**
	 * Get the next phase that has not reached a terminal state.
	 *
	 * @param int $plan_db_id Internal plan ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_current_step( int $plan_db_id ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom durable-plan table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE plan_db_id = %d AND status IN ('pending', 'running', 'awaiting_approval', 'interrupted') ORDER BY position ASC LIMIT 1",
				self::steps_table_name(),
				$plan_db_id
			)
		);

		return $row ? self::decode_step_row( $row ) : null;
	}

	/**
	 * Get a phase by its internal ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_step( int $step_id ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom durable-plan table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', self::steps_table_name(), $step_id ) );
		return $row ? self::decode_step_row( $row ) : null;
	}

	/**
	 * Get a phase at its immutable one-based plan position.
	 *
	 * This is used for an explicit retry after a phase has reached a terminal
	 * failure state and is therefore intentionally absent from get_current_step().
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_step_at_position( int $plan_db_id, int $position ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom durable-plan table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE plan_db_id = %d AND position = %d LIMIT 1',
				self::steps_table_name(),
				$plan_db_id,
				$position
			)
		);

		return $row ? self::decode_step_row( $row ) : null;
	}

	/**
	 * Get a phase by the background job that is executing it.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_step_by_job_id( string $job_id ): ?array {
		if ( '' === $job_id ) {
			return null;
		}

		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom durable-plan table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE job_id = %s LIMIT 1', self::steps_table_name(), $job_id ) );
		return $row ? self::decode_step_row( $row ) : null;
	}

	/**
	 * Set durable plan lifecycle data using a fixed allowlist.
	 *
	 * @param int                  $plan_db_id Plan ID.
	 * @param string               $status     New status.
	 * @param array<string, mixed> $extra      Additional safe columns.
	 */
	public static function update_plan_status( int $plan_db_id, string $status, array $extra = [] ): bool {
		if ( ! in_array( $status, self::PLAN_STATUSES, true ) ) {
			return false;
		}

		$allowed            = [ 'scope', 'scope_hash', 'pending_scope', 'pending_scope_hash', 'summary', 'current_step', 'approval_request_id', 'completed_at', 'cancelled_at' ];
		$data               = array_intersect_key( $extra, array_flip( $allowed ) );
		$data['status']     = $status;
		$data['updated_at'] = current_time( 'mysql', true );

		return self::update_row( self::table_name(), $data, [ 'id' => $plan_db_id ], [ 'current_step', 'approval_request_id' ] );
	}

	/**
	 * Set durable phase lifecycle data using a fixed allowlist.
	 *
	 * @param int                  $step_id Phase ID.
	 * @param string               $status  New status.
	 * @param array<string, mixed> $extra   Additional safe columns.
	 */
	public static function update_step_status( int $step_id, string $status, array $extra = [] ): bool {
		if ( ! in_array( $status, self::STEP_STATUSES, true ) ) {
			return false;
		}

		$allowed            = [ 'approval_request_id', 'job_id', 'evidence', 'failure_message', 'completed_at' ];
		$data               = array_intersect_key( $extra, array_flip( $allowed ) );
		$data['status']     = $status;
		$data['updated_at'] = current_time( 'mysql', true );

		return self::update_row( self::steps_table_name(), $data, [ 'id' => $step_id ], [ 'approval_request_id' ] );
	}

	/**
	 * Atomically claim a phase and transition its plan to running.
	 *
	 * Claiming both records in one conditional update prevents a scope-change
	 * request from winning between a phase claim and its plan-state update.
	 */
	public static function claim_step_and_start_plan( int $plan_db_id, int $step_id, int $position ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Joined conditional update makes the plan/phase transition atomic.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i AS plan INNER JOIN %i AS step ON step.plan_db_id = plan.id SET plan.status = 'running', plan.current_step = %d, plan.approval_request_id = 0, plan.updated_at = %s, step.status = 'running', step.attempts = step.attempts + 1, step.updated_at = %s WHERE plan.id = %d AND plan.status IN ('pending', 'awaiting_approval') AND plan.pending_scope = '' AND step.id = %d AND step.status IN ('pending', 'interrupted', 'awaiting_approval')",
				self::table_name(),
				self::steps_table_name(),
				$position,
				$now,
				$now,
				$plan_db_id,
				$step_id
			)
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Atomically reserve a pending plan for a scope-change approval.
	 *
	 * A phase can claim the same plan only while it has no pending scope, so
	 * this conditional transition prevents either operation from overriding the
	 * other after it has won the race.
	 */
	public static function claim_scope_change( int $plan_db_id, string $scope, string $scope_hash, int $approval_request_id ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional scope reservation prevents concurrent plan execution.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'awaiting_approval', pending_scope = %s, pending_scope_hash = %s, approval_request_id = %d, updated_at = %s WHERE id = %d AND status = 'pending' AND pending_scope = ''",
				self::table_name(),
				$scope,
				$scope_hash,
				$approval_request_id,
				current_time( 'mysql', true ),
				$plan_db_id
			)
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Associate a background job with an already claimed phase.
	 */
	public static function assign_job( int $step_id, string $job_id ): bool {
		return self::update_step_status( $step_id, 'running', [ 'job_id' => sanitize_text_field( $job_id ) ] );
	}

	/**
	 * Remove plan records after a failed create operation or test teardown.
	 */
	public static function delete_by_id( int $plan_db_id ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom durable-plan table cleanup.
		$wpdb->delete( self::steps_table_name(), [ 'plan_db_id' => $plan_db_id ], [ '%d' ] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom durable-plan table cleanup.
		$result = $wpdb->delete( self::table_name(), [ 'id' => $plan_db_id ], [ '%d' ] );
		return false !== $result;
	}

	/**
	 * Convert an internal plan row into a browser-safe response payload.
	 *
	 * @param array<string, mixed> $plan Plan record.
	 * @return array<string, mixed>
	 */
	public static function to_public( array $plan ): array {
		$steps = [];
		foreach ( $plan['steps'] as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$steps[] = [
				'key'                 => $step['step_key'],
				'position'            => $step['position'],
				'title'               => $step['title'],
				'instruction'         => $step['instruction'],
				'classification'      => $step['classification'],
				'preconditions'       => $step['preconditions'],
				'expected_evidence'   => $step['expected_evidence'],
				'rollback_guidance'   => $step['rollback_guidance'],
				'status'              => $step['status'],
				'approval_request_id' => $step['approval_request_id'],
				'evidence'            => $step['evidence'],
				'failure_message'     => $step['failure_message'],
			];
		}

		return [
			'plan_id'             => $plan['plan_id'],
			'session_id'          => $plan['session_id'],
			'scope'               => $plan['scope'],
			'pending_scope'       => $plan['pending_scope'],
			'summary'             => $plan['summary'],
			'status'              => $plan['status'],
			'current_step'        => $plan['current_step'],
			'approval_request_id' => $plan['approval_request_id'],
			'created_at'          => $plan['created_at'],
			'updated_at'          => $plan['updated_at'],
			'completed_at'        => $plan['completed_at'],
			'cancelled_at'        => $plan['cancelled_at'],
			'steps'               => $steps,
		];
	}

	/**
	 * Normalize a compact phase definition and derive server-owned execution policy.
	 *
	 * @param array<string, mixed> $raw             Phase input.
	 * @param int                  $position        One-based phase position.
	 * @param string               $plan_id         Plan UUID.
	 * @param bool                 $server_reviewed Whether server-side planning reviewed phase safety.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function normalize_step( array $raw, int $position, string $plan_id, bool $server_reviewed ): array|WP_Error {
		$title       = self::sanitize_text( (string) ( $raw['title'] ?? '' ), 255 );
		$instruction = self::sanitize_text( (string) ( $raw['instruction'] ?? '' ), 1600 );
		if ( '' === $title || '' === $instruction ) {
			return new WP_Error( 'sd_ai_agent_plan_invalid_step', __( 'Every durable plan phase needs a title and instruction.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
		}

		$step_key = sanitize_key( (string) ( $raw['key'] ?? '' ) );
		if ( '' === $step_key ) {
			$step_key = 'phase-' . $position;
		}
		if ( strlen( $step_key ) > self::MAX_STEP_KEY_LENGTH ) {
			return new WP_Error( 'sd_ai_agent_plan_invalid_step_key', __( 'Each durable plan phase key must be 100 characters or fewer.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
		}

		$classification = sanitize_key( (string) ( $raw['classification'] ?? 'read' ) );
		if ( ! in_array( $classification, self::CLASSIFICATIONS, true ) ) {
			return new WP_Error( 'sd_ai_agent_plan_invalid_classification', __( 'Each durable plan phase must be read, write, or destructive.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
		}

		// Keep this opaque and server-generated so plan records never retain a
		// browser or model-provided token under the guise of an idempotency key.
		$idempotency_key = hash( 'sha256', $plan_id . ':' . $position . ':' . $instruction );

		$requires_approval = ! $server_reviewed || 'read' !== $classification;
		$safe_to_resume    = $server_reviewed && ! $requires_approval;

		return [
			'step_key'          => $step_key,
			'position'          => $position,
			'title'             => $title,
			'instruction'       => $instruction,
			'classification'    => $classification,
			'requires_approval' => $requires_approval ? 1 : 0,
			'safe_to_resume'    => $safe_to_resume ? 1 : 0,
			'idempotency_key'   => $idempotency_key,
			'preconditions'     => self::sanitize_text( (string) ( $raw['preconditions'] ?? '' ), 600 ),
			'expected_evidence' => self::sanitize_text( (string) ( $raw['expected_evidence'] ?? '' ), 600 ),
			'rollback_guidance' => self::sanitize_text( (string) ( $raw['rollback_guidance'] ?? '' ), 600 ),
		];
	}

	/**
	 * Load and decode a plan's phase records.
	 *
	 * @return array<string, mixed>
	 */
	private static function decode_plan_row( object $row ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom durable-plan table.
		$step_rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE plan_db_id = %d ORDER BY position ASC', self::steps_table_name(), (int) $row->id )
		);

		return [
			'id'                  => (int) $row->id,
			'plan_id'             => (string) $row->plan_id,
			'session_id'          => (int) $row->session_id,
			'user_id'             => (int) $row->user_id,
			'scope'               => (string) $row->scope,
			'scope_hash'          => (string) $row->scope_hash,
			'pending_scope'       => (string) $row->pending_scope,
			'pending_scope_hash'  => (string) $row->pending_scope_hash,
			'summary'             => (string) $row->summary,
			'status'              => (string) $row->status,
			'current_step'        => (int) $row->current_step,
			'approval_request_id' => (int) $row->approval_request_id,
			'created_at'          => (string) $row->created_at,
			'updated_at'          => (string) $row->updated_at,
			'completed_at'        => $row->completed_at ? (string) $row->completed_at : '',
			'cancelled_at'        => $row->cancelled_at ? (string) $row->cancelled_at : '',
			'steps'               => array_map( [ self::class, 'decode_step_row' ], $step_rows ?: [] ),
		];
	}

	/**
	 * Decode a plan phase row.
	 *
	 * @return array<string, mixed>
	 */
	private static function decode_step_row( object $row ): array {
		$evidence = json_decode( (string) $row->evidence, true );

		return [
			'id'                  => (int) $row->id,
			'plan_db_id'          => (int) $row->plan_db_id,
			'step_key'            => (string) $row->step_key,
			'position'            => (int) $row->position,
			'title'               => (string) $row->title,
			'instruction'         => (string) $row->instruction,
			'classification'      => (string) $row->classification,
			'requires_approval'   => isset( $row->requires_approval ) ? (int) $row->requires_approval : 1,
			'safe_to_resume'      => isset( $row->safe_to_resume ) ? (int) $row->safe_to_resume : 0,
			'idempotency_key'     => (string) $row->idempotency_key,
			'preconditions'       => (string) $row->preconditions,
			'expected_evidence'   => (string) $row->expected_evidence,
			'rollback_guidance'   => (string) $row->rollback_guidance,
			'status'              => (string) $row->status,
			'approval_request_id' => (int) $row->approval_request_id,
			'job_id'              => (string) $row->job_id,
			'evidence'            => is_array( $evidence ) ? $evidence : [],
			'failure_message'     => (string) $row->failure_message,
			'attempts'            => (int) $row->attempts,
			'created_at'          => (string) $row->created_at,
			'updated_at'          => (string) $row->updated_at,
			'completed_at'        => $row->completed_at ? (string) $row->completed_at : '',
		];
	}

	/**
	 * Update a custom-table row with explicit integer format columns.
	 *
	 * @param string               $table        Table name.
	 * @param array<string, mixed> $data         Data to store.
	 * @param array<string, mixed> $where        Row selector.
	 * @param array<string>        $integer_keys Data keys that are integers.
	 */
	private static function update_row( string $table, array $data, array $where, array $integer_keys ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$formats = [];
		foreach ( array_keys( $data ) as $key ) {
			$formats[] = in_array( $key, $integer_keys, true ) ? '%d' : '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom durable-plan table.
		$result = $wpdb->update( $table, $data, $where, $formats, [ '%d' ] );
		return false !== $result;
	}

	/**
	 * Strip markup, cap stored plan fields, and redact common credential forms.
	 */
	private static function sanitize_text( string $value, int $max_length ): string {
		return DurablePlanTextSanitizer::sanitize( $value, $max_length );
	}
}
