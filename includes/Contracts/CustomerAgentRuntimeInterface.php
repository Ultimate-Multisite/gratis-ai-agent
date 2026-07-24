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
	 * Create or recover the constrained runtime conversation for an external session.
	 *
	 * @return array{conversation_id:string,status:string,recovered:bool,expires_at:string}|WP_Error
	 */
	public function create_or_recover_conversation( string $integration_key, string $external_session_id ): array|WP_Error;

	/**
	 * Queue one idempotent customer turn.
	 *
	 * @return array{conversation_id:string,job_id:string,status:string,created:bool,expires_at:string}|WP_Error
	 */
	public function enqueue_turn( string $integration_key, string $external_session_id, string $external_message_id, string $message ): array|WP_Error;

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
