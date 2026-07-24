<?php

declare(strict_types=1);
/**
 * Public server-side contract for trusted customer-agent integrations.
 *
 * @package SdAiAgent\Contracts
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Contracts;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines the versioned PHP API used by trusted same-install integrations.
 *
 * Implementations own only a bounded runtime history. The consuming plugin
 * remains the source of truth for its customer transcript and routing state.
 */
interface CustomerAgentRuntimeInterface {

	/**
	 * Discover the semantic contract version, supported operations, and limits.
	 *
	 * @return array{contract_version:string,features:array<string,bool>,limits:array<string,int>}
	 */
	public function discover_capabilities(): array;

	/**
	 * Create or safely reconcile an explicitly owned customer-support profile.
	 *
	 * The profile is keyed by a stable integration key and specification version.
	 * Repeated calls preserve operator-owned model, provider, presentation, and
	 * approved-collection settings unless an explicit reset is requested.
	 *
	 * @param string $integration_key Stable integration-owned profile key.
	 * @param array  $spec            Integration-managed support profile specification.
	 * @phpstan-param array<string,mixed> $spec
	 * @return array<string,mixed>|WP_Error
	 */
	public function ensure_profile( string $integration_key, array $spec ): array|WP_Error;

	/**
	 * Return a customer-safe readiness summary without prompts, credentials, or content.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function profile_status( string $integration_key ): array|WP_Error;

	/** Disable one explicitly managed profile while preserving its owned record. */
	public function disable_profile( string $integration_key ): array|WP_Error;

	/** Remove one explicitly managed profile and only its runtime conversations/jobs. */
	public function remove_profile( string $integration_key ): array|WP_Error;

	/**
	 * Create or recover the constrained runtime conversation for an external session.
	 *
	 * @return array{conversation_id:string,status:string,recovered:bool,expires_at:string}|WP_Error
	 */
	public function create_or_recover_conversation( string $integration_key, string $external_session_id ): array|WP_Error;

	/**
	 * Queue one idempotent customer turn.
	 *
	 * @param string              $integration_key      Stable integration-owned profile key.
	 * @param string              $external_session_id  Consumer-owned opaque session identifier.
	 * @param string              $external_message_id  Consumer-owned idempotency identifier.
	 * @param string              $message              Customer message content.
	 * @param array<string,mixed> $request_context      Trusted integration context used only to narrow profile limits.
	 * @return array{conversation_id:string,job_id:string,status:string,created:bool,expires_at:string}|WP_Error
	 */
	public function enqueue_turn( string $integration_key, string $external_session_id, string $external_message_id, string $message, array $request_context = array() ): array|WP_Error;

	/**
	 * Inspect an external job without consuming its terminal result.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function inspect_job( string $integration_key, string $external_session_id, string $job_id ): array|WP_Error;

	/**
	 * Cancel a queued or processing external job.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function cancel_job( string $integration_key, string $external_session_id, string $job_id ): array|WP_Error;

	/**
	 * Cancel and purge the runtime conversation owned by an external session.
	 *
	 * @return array{conversation_id:string,status:string}|WP_Error
	 */
	public function close_conversation( string $integration_key, string $external_session_id ): array|WP_Error;
}
