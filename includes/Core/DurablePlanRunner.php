<?php

declare(strict_types=1);
/**
 * Bounded execution coordinator for durable multi-phase site-operation plans.
 *
 * The runner supplies one compact phase context at a time. It never treats a
 * plan as a blanket tool permission and never replays a consequential phase
 * after an interruption without a new explicit approval.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use SdAiAgent\Automations\HumanApprovalGate;
use SdAiAgent\Models\DurablePlanRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DurablePlanRunner {

	private const CONTEXT_MAX_CHARS      = 6000;
	private const CONTEXT_EVIDENCE_LIMIT = 3;

	/**
	 * Create a compact durable plan for a session.
	 *
	 * @param int                  $session_id Session ID.
	 * @param int                  $user_id    Current authenticated user ID.
	 * @param array<string, mixed> $definition Reviewed compact definition.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create( int $session_id, int $user_id, array $definition ) {
		return DurablePlanRepository::create( $session_id, $user_id, $definition );
	}

	/**
	 * Create a durable plan from browser-controlled compact metadata.
	 *
	 * Browser-supplied classifications are preserved for display, but the
	 * repository persists a fail-closed approval and resume policy.
	 *
	 * @param int                  $session_id Session ID.
	 * @param int                  $user_id    Current authenticated user ID.
	 * @param array<string, mixed> $definition Compact plan definition.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_from_client( int $session_id, int $user_id, array $definition ) {
		return DurablePlanRepository::create_from_client( $session_id, $user_id, $definition );
	}

	/**
	 * Resolve the next phase into an approval pause or bounded provider context.
	 *
	 * @param string $plan_id Plan UUID.
	 * @param int    $user_id Current authenticated user ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function prepare_next( string $plan_id, int $user_id ) {
		$plan = self::get_owned_plan( $plan_id, $user_id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		if ( in_array( $plan['status'], [ 'completed', 'cancelled', 'failed', 'blocked' ], true ) ) {
			return new WP_Error(
				'sd_ai_agent_plan_not_runnable',
				__( 'This durable plan has no runnable phase.', 'superdav-ai-agent' ),
				[
					'status' => 409,
					'plan'   => DurablePlanRepository::to_public( $plan ),
				]
			);
		}

		// A requested scope change must be decided before any phase can start.
		// Without this guard, a browser could call /continue while the scope
		// approval is pending and execute the next phase against unapproved scope.
		if ( '' !== (string) $plan['pending_scope'] ) {
			return [
				'status' => 'awaiting_approval',
				'plan'   => DurablePlanRepository::to_public( $plan ),
			];
		}

		$step = DurablePlanRepository::get_current_step( (int) $plan['id'] );
		if ( null === $step ) {
			DurablePlanRepository::update_plan_status( (int) $plan['id'], 'completed', [ 'completed_at' => current_time( 'mysql', true ) ] );
			return [
				'status' => 'completed',
				'plan'   => self::public_plan( $plan_id ),
			];
		}

		if ( 'running' === $step['status'] ) {
			return [
				'status' => 'running',
				'plan'   => DurablePlanRepository::to_public( $plan ),
			];
		}

		if ( 'interrupted' === $step['status'] && ! self::is_safe_to_resume( $step ) ) {
			DurablePlanRepository::update_plan_status( (int) $plan['id'], 'blocked', [ 'current_step' => (int) $step['position'] ] );
			return new WP_Error(
				'sd_ai_agent_plan_manual_retry_required',
				__( 'This phase may have changed the site and needs a new explicit confirmation before retrying.', 'superdav-ai-agent' ),
				[
					'status' => 409,
					'plan'   => self::public_plan( $plan_id ),
				]
			);
		}

		if ( self::requires_approval( $step ) ) {
			$approval = self::ensure_phase_approval( $plan, $step, $user_id );
			if ( is_wp_error( $approval ) ) {
				return $approval;
			}

			if ( HumanApprovalGate::STATUS_APPROVED !== $approval['status'] && HumanApprovalGate::STATUS_EXECUTED !== $approval['status'] ) {
				return [
					'status' => 'awaiting_approval',
					'plan'   => self::public_plan( $plan_id ),
				];
			}
		}

		if ( ! DurablePlanRepository::claim_step_and_start_plan( (int) $plan['id'], (int) $step['id'], (int) $step['position'] ) ) {
			$current_plan = DurablePlanRepository::get_by_plan_id( $plan_id );
			if ( null !== $current_plan && '' !== (string) $current_plan['pending_scope'] ) {
				return [
					'status' => 'awaiting_approval',
					'plan'   => DurablePlanRepository::to_public( $current_plan ),
				];
			}
			if ( null !== $current_plan && 'running' === $current_plan['status'] ) {
				return [
					'status' => 'running',
					'plan'   => DurablePlanRepository::to_public( $current_plan ),
				];
			}

			return new WP_Error( 'sd_ai_agent_plan_claim_failed', __( 'This plan phase was changed by another request. Refresh the plan status and continue.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		$plan = DurablePlanRepository::get_by_plan_id( $plan_id );
		$step = DurablePlanRepository::get_step( (int) $step['id'] );
		if ( null === $plan || null === $step ) {
			return new WP_Error( 'sd_ai_agent_plan_missing_after_claim', __( 'The durable plan could not be loaded after starting its phase.', 'superdav-ai-agent' ), [ 'status' => 500 ] );
		}

		return [
			'status'           => 'ready',
			'plan'             => DurablePlanRepository::to_public( $plan ),
			'step'             => $step,
			'provider_context' => self::build_provider_context( $plan, $step ),
		];
	}

	/**
	 * Approve the current phase using the durable, server-side approval record.
	 *
	 * @param string $plan_id             Plan UUID.
	 * @param int    $approval_request_id Durable approval record ID.
	 * @param int    $user_id             Current authenticated user ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function approve( string $plan_id, int $approval_request_id, int $user_id ) {
		$plan = self::get_owned_plan( $plan_id, $user_id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$approval = HumanApprovalGate::get( $approval_request_id );
		if ( null === $approval || (int) $approval['requested_by'] !== $user_id ) {
			return new WP_Error( 'sd_ai_agent_plan_approval_not_found', __( 'The requested plan approval was not found.', 'superdav-ai-agent' ), [ 'status' => 404 ] );
		}

		if ( 'durable-plan-scope' === $approval['action_type'] ) {
			return self::approve_scope_change( $plan, $approval, $user_id );
		}

		$step = DurablePlanRepository::get_current_step( (int) $plan['id'] );
		if ( null === $step || (int) $step['approval_request_id'] !== $approval_request_id || ! self::approval_matches_phase( $approval, $plan, $step ) ) {
			return new WP_Error( 'sd_ai_agent_plan_approval_mismatch', __( 'This approval does not match the current durable plan phase.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		$approved = HumanApprovalGate::approve( $approval_request_id, $user_id, false );
		if ( is_wp_error( $approved ) ) {
			return $approved;
		}

		return self::prepare_next( $plan_id, $user_id );
	}

	/**
	 * Reject a pending phase and cancel the remaining plan safely.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function reject( string $plan_id, int $approval_request_id, int $user_id ) {
		$plan = self::get_owned_plan( $plan_id, $user_id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$approval = HumanApprovalGate::get( $approval_request_id );
		if ( null === $approval || (int) $approval['requested_by'] !== $user_id ) {
			return new WP_Error( 'sd_ai_agent_plan_approval_not_found', __( 'The requested plan approval was not found.', 'superdav-ai-agent' ), [ 'status' => 404 ] );
		}
		if ( 'durable-plan-scope' === $approval['action_type'] ) {
			return self::reject_scope_change( $plan, $approval, $user_id );
		}

		$step = DurablePlanRepository::get_current_step( (int) $plan['id'] );
		if ( null === $step || (int) $step['approval_request_id'] !== $approval_request_id ) {
			return new WP_Error( 'sd_ai_agent_plan_approval_mismatch', __( 'This approval does not match the current durable plan phase.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		$rejected = HumanApprovalGate::reject( $approval_request_id, $user_id );
		if ( is_wp_error( $rejected ) ) {
			return $rejected;
		}

		DurablePlanRepository::update_step_status( (int) $step['id'], 'cancelled', [ 'failure_message' => __( 'The phase was declined by the user.', 'superdav-ai-agent' ) ] );
		DurablePlanRepository::update_plan_status( (int) $plan['id'], 'cancelled', [ 'cancelled_at' => current_time( 'mysql', true ) ] );

		return [
			'status' => 'cancelled',
			'plan'   => self::public_plan( $plan_id ),
		];
	}

	/**
	 * Cancel a plan without executing any unstarted phase.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function cancel( string $plan_id, int $user_id ) {
		$plan = self::get_owned_plan( $plan_id, $user_id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		if ( 'running' === $plan['status'] ) {
			return new WP_Error( 'sd_ai_agent_plan_cancel_running', __( 'A running phase must finish or fail before this plan can be cancelled.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		$step = DurablePlanRepository::get_current_step( (int) $plan['id'] );
		if ( null !== $step && in_array( $step['status'], [ 'pending', 'awaiting_approval', 'interrupted' ], true ) ) {
			DurablePlanRepository::update_step_status( (int) $step['id'], 'cancelled' );
		}
		DurablePlanRepository::update_plan_status( (int) $plan['id'], 'cancelled', [ 'cancelled_at' => current_time( 'mysql', true ) ] );

		return [
			'status' => 'cancelled',
			'plan'   => self::public_plan( $plan_id ),
		];
	}

	/**
	 * Retry a failed or interrupted phase only after a new explicit user action.
	 *
	 * Write and destructive phases are reset to pending and then pass through
	 * prepare_next(), which creates a fresh HumanApprovalGate record. Read-only
	 * idempotent phases may continue directly, but are still never auto-retried.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function retry( string $plan_id, int $user_id ) {
		$plan = self::get_owned_plan( $plan_id, $user_id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( ! in_array( $plan['status'], [ 'failed', 'blocked' ], true ) ) {
			return new WP_Error( 'sd_ai_agent_plan_retry_unavailable', __( 'This durable plan phase is not available for retry.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		$step = DurablePlanRepository::get_step_at_position( (int) $plan['id'], (int) $plan['current_step'] );
		if ( null === $step || ! in_array( $step['status'], [ 'failed', 'interrupted' ], true ) ) {
			return new WP_Error( 'sd_ai_agent_plan_retry_unavailable', __( 'The failed durable plan phase is no longer available for retry.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		if ( ! DurablePlanRepository::update_step_status(
			(int) $step['id'],
			'pending',
			[
				'approval_request_id' => 0,
				'job_id'              => '',
				'failure_message'     => '',
			]
		) ) {
			return new WP_Error( 'sd_ai_agent_plan_retry_failed', __( 'The durable plan phase could not be prepared for retry.', 'superdav-ai-agent' ), [ 'status' => 500 ] );
		}

		DurablePlanRepository::update_plan_status(
			(int) $plan['id'],
			'pending',
			[ 'approval_request_id' => 0 ]
		);

		return self::prepare_next( $plan_id, $user_id );
	}

	/**
	 * Require a fresh approval before changing a stored plan's compact scope.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function request_scope_change( string $plan_id, int $user_id, string $scope ) {
		$plan = self::get_owned_plan( $plan_id, $user_id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( 'pending' !== $plan['status'] ) {
			return new WP_Error( 'sd_ai_agent_plan_scope_unavailable', __( 'Change the plan scope only between phases before starting the next phase.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		$scope = self::safe_text( $scope, 1200 );
		if ( '' === $scope ) {
			return new WP_Error( 'sd_ai_agent_plan_scope_invalid', __( 'A changed plan scope is required.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
		}

		$scope_hash = hash( 'sha256', $scope );
		if ( hash_equals( (string) $plan['scope_hash'], $scope_hash ) ) {
			return [
				'status' => $plan['status'],
				'plan'   => DurablePlanRepository::to_public( $plan ),
			];
		}

		$approval = HumanApprovalGate::create_pending(
			[
				'source_type'  => 'durable-plan',
				'source_id'    => (int) $plan['id'],
				'action_type'  => 'durable-plan-scope',
				'requested_by' => $user_id,
				'payload'      => [
					'plan_id'    => $plan['plan_id'],
					'scope'      => $scope,
					'scope_hash' => $scope_hash,
				],
			]
		);
		if ( is_wp_error( $approval ) ) {
			return $approval;
		}

		if ( ! DurablePlanRepository::claim_scope_change( (int) $plan['id'], $scope, $scope_hash, (int) $approval['id'] ) ) {
			return new WP_Error( 'sd_ai_agent_plan_scope_changed', __( 'This durable plan changed before the scope request could be saved. Refresh the plan status and try again.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		return [
			'status' => 'awaiting_approval',
			'plan'   => self::public_plan( $plan_id ),
		];
	}

	/**
	 * Attach a queued background job to the currently running phase.
	 */
	public static function assign_job( string $plan_id, int $step_id, string $job_id ): bool {
		$plan = DurablePlanRepository::get_by_plan_id( $plan_id );
		$step = DurablePlanRepository::get_step( $step_id );
		if ( null === $plan || null === $step || (int) $step['plan_db_id'] !== (int) $plan['id'] ) {
			return false;
		}

		return DurablePlanRepository::assign_job( $step_id, $job_id );
	}

	/**
	 * Mark a completed bounded phase and persist only compact safe evidence.
	 *
	 * @param string               $plan_id Plan UUID.
	 * @param int                  $step_id Phase ID.
	 * @param array<string, mixed> $result  Agent-loop result.
	 * @return array<string, mixed>|null
	 */
	public static function complete_phase( string $plan_id, int $step_id, array $result ): ?array {
		$plan = DurablePlanRepository::get_by_plan_id( $plan_id );
		$step = DurablePlanRepository::get_step( $step_id );
		if ( null === $plan || null === $step || (int) $step['plan_db_id'] !== (int) $plan['id'] || 'running' !== $step['status'] ) {
			return null;
		}

		$evidence = wp_json_encode( self::safe_evidence( $result ) );
		if ( false === $evidence ) {
			$evidence = '{}';
		}

		DurablePlanRepository::update_step_status(
			$step_id,
			'completed',
			[
				'evidence'     => $evidence,
				'completed_at' => current_time( 'mysql', true ),
			]
		);

		$next = DurablePlanRepository::get_current_step( (int) $plan['id'] );
		DurablePlanRepository::update_plan_status(
			(int) $plan['id'],
			null === $next ? 'completed' : 'pending',
			null === $next ? [ 'completed_at' => current_time( 'mysql', true ) ] : [ 'current_step' => (int) $next['position'] ]
		);

		return self::public_plan( $plan_id );
	}

	/**
	 * Mark a normal phase failure and expose an actionable, redacted state.
	 */
	public static function fail_phase( string $plan_id, int $step_id, string $message ): ?array {
		$plan = DurablePlanRepository::get_by_plan_id( $plan_id );
		$step = DurablePlanRepository::get_step( $step_id );
		if ( null === $plan || null === $step || (int) $step['plan_db_id'] !== (int) $plan['id'] || 'running' !== $step['status'] ) {
			return null;
		}

		$message = self::safe_text( $message, 500 );
		DurablePlanRepository::update_step_status( $step_id, 'failed', [ 'failure_message' => $message ] );
		DurablePlanRepository::update_plan_status( (int) $plan['id'], 'failed', [ 'current_step' => (int) $step['position'] ] );
		return self::public_plan( $plan_id );
	}

	/**
	 * Convert an interrupted phase into a safe retry or manual-retry block.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function mark_phase_interrupted_by_job( string $job_id ): ?array {
		$step = DurablePlanRepository::get_step_by_job_id( $job_id );
		if ( null === $step ) {
			return null;
		}

		$plan = self::get_plan_by_internal_id( (int) $step['plan_db_id'] );
		if ( null === $plan || ! in_array( $step['status'], [ 'running', 'interrupted' ], true ) ) {
			return null;
		}

		if ( self::is_safe_to_resume( $step ) ) {
			DurablePlanRepository::update_step_status( (int) $step['id'], 'interrupted', [ 'failure_message' => __( 'The safe read-only phase was interrupted and can be continued.', 'superdav-ai-agent' ) ] );
			DurablePlanRepository::update_plan_status( (int) $plan['id'], 'pending', [ 'current_step' => (int) $step['position'] ] );
		} else {
			DurablePlanRepository::update_step_status( (int) $step['id'], 'failed', [ 'failure_message' => __( 'This consequential phase was interrupted. Review the site state and request a new confirmation before retrying.', 'superdav-ai-agent' ) ] );
			DurablePlanRepository::update_plan_status( (int) $plan['id'], 'blocked', [ 'current_step' => (int) $step['position'] ] );
		}

		return self::public_plan( (string) $plan['plan_id'] );
	}

	/**
	 * Build the compact provider message for one active phase.
	 *
	 * @param array<string, mixed> $plan Plan record.
	 * @param array<string, mixed> $step Phase record.
	 */
	public static function build_provider_context( array $plan, array $step ): string {
		$evidence_lines = [];
		$plan_steps     = $plan['steps'] ?? [];
		if ( ! is_array( $plan_steps ) ) {
			$plan_steps = [];
		}
		$completed = array_filter(
			$plan_steps,
			static fn( mixed $candidate ): bool => is_array( $candidate ) && 'completed' === ( $candidate['status'] ?? '' )
		);
		$completed = array_slice( $completed, -self::CONTEXT_EVIDENCE_LIMIT );
		foreach ( $completed as $completed_step ) {
			$summary = is_array( $completed_step['evidence'] ?? null ) ? (string) ( $completed_step['evidence']['summary'] ?? '' ) : '';
			if ( '' !== $summary ) {
				$evidence_lines[] = '- ' . self::safe_text( (string) $completed_step['title'], 120 ) . ': ' . self::safe_text( $summary, 360 );
			}
		}

		$sections = [
			'Execute only the active phase of this durable site operation. Do not infer blanket permission for tools or later phases.',
			'Scope: ' . self::safe_text( (string) $plan['scope'], 1200 ),
			'Active phase ' . (int) $step['position'] . ': ' . self::safe_text( (string) $step['title'], 255 ),
			'Instruction: ' . self::safe_text( (string) $step['instruction'], 1600 ),
			'Classification: ' . self::safe_text( (string) $step['classification'], 32 ),
			'Preconditions: ' . self::safe_text( (string) $step['preconditions'], 600 ),
			'Expected evidence: ' . self::safe_text( (string) $step['expected_evidence'], 600 ),
			'Rollback guidance: ' . self::safe_text( (string) $step['rollback_guidance'], 600 ),
		];
		if ( ! empty( $evidence_lines ) ) {
			$sections[] = "Completed phase evidence:\n" . implode( "\n", $evidence_lines );
		}
		$sections[] = 'Return a concise completion summary and evidence. Do not include credentials, raw tool payloads, or the full prior conversation.';

		$context = implode( "\n\n", $sections );
		return function_exists( 'mb_substr' ) ? mb_substr( $context, 0, self::CONTEXT_MAX_CHARS ) : substr( $context, 0, self::CONTEXT_MAX_CHARS );
	}

	/**
	 * Get a browser-safe plan record or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function public_plan( string $plan_id ): ?array {
		$plan = DurablePlanRepository::get_by_plan_id( $plan_id );
		return null === $plan ? null : DurablePlanRepository::to_public( $plan );
	}

	/**
	 * Get a browser-safe plan by its active or completed phase job ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function public_plan_for_job( string $job_id ): ?array {
		$step = DurablePlanRepository::get_step_by_job_id( $job_id );
		if ( null === $step ) {
			return null;
		}

		$plan = DurablePlanRepository::get_by_id( (int) $step['plan_db_id'] );
		return null === $plan ? null : DurablePlanRepository::to_public( $plan );
	}

	/**
	 * Check ownership against a server-side persisted plan row.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private static function get_owned_plan( string $plan_id, int $user_id ) {
		$plan = DurablePlanRepository::get_by_plan_id( $plan_id );
		if ( null === $plan ) {
			return new WP_Error( 'sd_ai_agent_plan_not_found', __( 'Durable plan not found.', 'superdav-ai-agent' ), [ 'status' => 404 ] );
		}
		if ( $user_id <= 0 || (int) $plan['user_id'] !== $user_id ) {
			return new WP_Error( 'sd_ai_agent_plan_forbidden', __( 'You are not authorized to change this durable plan.', 'superdav-ai-agent' ), [ 'status' => 403 ] );
		}

		return $plan;
	}

	/**
	 * Load a plan by internal ID for a job-to-phase state transition.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function get_plan_by_internal_id( int $plan_db_id ): ?array {
		return DurablePlanRepository::get_by_id( $plan_db_id );
	}

	/**
	 * A server-owned policy determines whether a phase needs approval.
	 *
	 * @param array<string, mixed> $step Phase record.
	 */
	private static function requires_approval( array $step ): bool {
		return 1 === (int) ( $step['requires_approval'] ?? 1 );
	}

	/**
	 * Only server-reviewed read-only phases with a stable key may resume.
	 *
	 * @param array<string, mixed> $step Phase record.
	 */
	private static function is_safe_to_resume( array $step ): bool {
		return ! self::requires_approval( $step )
			&& 'read' === (string) ( $step['classification'] ?? '' )
			&& 1 === (int) ( $step['safe_to_resume'] ?? 0 )
			&& '' !== (string) ( $step['idempotency_key'] ?? '' );
	}

	/**
	 * Find or create the approval request protecting a consequential phase.
	 *
	 * @param array<string, mixed> $plan Plan record.
	 * @param array<string, mixed> $step Phase record.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function ensure_phase_approval( array $plan, array $step, int $user_id ) {
		if ( (int) $step['approval_request_id'] > 0 ) {
			$existing = HumanApprovalGate::get( (int) $step['approval_request_id'] );
			if ( null !== $existing && self::approval_matches_phase( $existing, $plan, $step ) ) {
				if ( in_array( $existing['status'], [ HumanApprovalGate::STATUS_PENDING, HumanApprovalGate::STATUS_APPROVED, HumanApprovalGate::STATUS_EXECUTED ], true ) ) {
					return $existing;
				}

				return new WP_Error( 'sd_ai_agent_plan_approval_unavailable', __( 'The required plan approval is no longer available. Review the phase and request a new plan.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
			}
		}

		$approval = HumanApprovalGate::create_pending(
			[
				'source_type'  => 'durable-plan',
				'source_id'    => (int) $plan['id'],
				'action_type'  => 'durable-plan-phase',
				'requested_by' => $user_id,
				'payload'      => [
					'plan_id'        => $plan['plan_id'],
					'step_id'        => (int) $step['id'],
					'step_key'       => $step['step_key'],
					'step_title'     => $step['title'],
					'classification' => $step['classification'],
					'scope_hash'     => $plan['scope_hash'],
				],
			]
		);
		if ( is_wp_error( $approval ) ) {
			return $approval;
		}

		DurablePlanRepository::update_step_status(
			(int) $step['id'],
			'awaiting_approval',
			[ 'approval_request_id' => (int) $approval['id'] ]
		);
		DurablePlanRepository::update_plan_status(
			(int) $plan['id'],
			'awaiting_approval',
			[
				'current_step'        => (int) $step['position'],
				'approval_request_id' => (int) $approval['id'],
			]
		);
		return $approval;
	}

	/**
	 * Verify the server-side approval payload still describes this exact phase.
	 *
	 * @param array<string, mixed> $approval Approval record.
	 * @param array<string, mixed> $plan     Plan record.
	 * @param array<string, mixed> $step     Phase record.
	 */
	private static function approval_matches_phase( array $approval, array $plan, array $step ): bool {
		$payload = is_array( $approval['payload'] ?? null ) ? $approval['payload'] : [];
		return 'durable-plan-phase' === $approval['action_type']
			&& (string) ( $payload['plan_id'] ?? '' ) === (string) $plan['plan_id']
			&& (int) ( $payload['step_id'] ?? 0 ) === (int) $step['id']
			&& hash_equals( (string) $plan['scope_hash'], (string) ( $payload['scope_hash'] ?? '' ) );
	}

	/**
	 * Apply an approved scope change only after matching the persisted approval.
	 *
	 * @param array<string, mixed> $plan     Plan record.
	 * @param array<string, mixed> $approval Approval record.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function approve_scope_change( array $plan, array $approval, int $user_id ) {
		$payload = is_array( $approval['payload'] ?? null ) ? $approval['payload'] : [];
		if (
			(string) ( $payload['plan_id'] ?? '' ) !== (string) $plan['plan_id']
			|| (string) ( $payload['scope_hash'] ?? '' ) !== (string) $plan['pending_scope_hash']
			|| '' === (string) $plan['pending_scope']
		) {
			return new WP_Error( 'sd_ai_agent_plan_scope_mismatch', __( 'This approval no longer matches the pending plan scope.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		$approved = HumanApprovalGate::approve( (int) $approval['id'], $user_id, false );
		if ( is_wp_error( $approved ) ) {
			return $approved;
		}

		DurablePlanRepository::update_plan_status(
			(int) $plan['id'],
			'pending',
			[
				'scope'               => $plan['pending_scope'],
				'scope_hash'          => $plan['pending_scope_hash'],
				'pending_scope'       => '',
				'pending_scope_hash'  => '',
				'approval_request_id' => 0,
			]
		);

		return [
			'status' => 'pending',
			'plan'   => self::public_plan( (string) $plan['plan_id'] ),
		];
	}

	/**
	 * Reject a pending scope change and retain the previously approved scope.
	 *
	 * @param array<string, mixed> $plan     Plan record.
	 * @param array<string, mixed> $approval Approval record.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function reject_scope_change( array $plan, array $approval, int $user_id ) {
		$payload = is_array( $approval['payload'] ?? null ) ? $approval['payload'] : [];
		if (
			(string) ( $payload['plan_id'] ?? '' ) !== (string) $plan['plan_id']
			|| (string) ( $payload['scope_hash'] ?? '' ) !== (string) $plan['pending_scope_hash']
			|| '' === (string) $plan['pending_scope']
		) {
			return new WP_Error( 'sd_ai_agent_plan_scope_mismatch', __( 'This approval no longer matches the pending plan scope.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		$rejected = HumanApprovalGate::reject( (int) $approval['id'], $user_id );
		if ( is_wp_error( $rejected ) ) {
			return $rejected;
		}

		DurablePlanRepository::update_plan_status(
			(int) $plan['id'],
			'pending',
			[
				'pending_scope'       => '',
				'pending_scope_hash'  => '',
				'approval_request_id' => 0,
			]
		);

		return [
			'status' => 'pending',
			'plan'   => self::public_plan( (string) $plan['plan_id'] ),
		];
	}

	/**
	 * Persist a compact, redacted completion record rather than raw provider data.
	 *
	 * @param array<string, mixed> $result Agent-loop result.
	 * @return array<string, mixed>
	 */
	private static function safe_evidence( array $result ): array {
		$reply       = self::safe_text( (string) ( $result['reply'] ?? '' ), 900 );
		$token_usage = is_array( $result['token_usage'] ?? null ) ? $result['token_usage'] : [];
		return [
			'summary'           => '' !== $reply ? $reply : __( 'Phase completed without a provider summary.', 'superdav-ai-agent' ),
			'exit_reason'       => self::safe_text( (string) ( $result['exit_reason'] ?? 'complete' ), 80 ),
			'completed_at'      => current_time( 'mysql', true ),
			'prompt_tokens'     => max( 0, (int) ( $token_usage['prompt'] ?? 0 ) ),
			'completion_tokens' => max( 0, (int) ( $token_usage['completion'] ?? 0 ) ),
		];
	}

	/**
	 * Redact common secret values and cap any operator-facing text.
	 */
	private static function safe_text( string $value, int $max_length ): string {
		return DurablePlanTextSanitizer::sanitize( $value, $max_length );
	}
}
