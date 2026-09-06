<?php

declare(strict_types=1);
/**
 * Shared permission-check methods for REST controllers.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\REST;

use SdAiAgent\Core\Database;
use SdAiAgent\Core\RolePermissions;
use SdAiAgent\Models\ActiveJobRepository;
use WP_Error;
use WP_REST_Request;

trait PermissionTrait {

	/**
	 * Permission check — admin only.
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission check — admin only (alias for check_permission).
	 */
	public static function check_admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission check for chat endpoints (stream, run, process).
	 *
	 * Allows access based on role-based permissions configuration.
	 * Administrators always have access.
	 *
	 * @return bool|WP_Error
	 */
	public function check_chat_permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to use the AI chat.', 'superdav-ai-agent' ),
				array( 'status' => 401 )
			);
		}

		if ( ! RolePermissions::current_user_has_chat_access() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Your user role does not have permission to access the AI chat.', 'superdav-ai-agent' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission check for starting an authenticated chat run.
	 *
	 * A chat-enabled user may start a new session. Continuing an existing
	 * session additionally requires ownership; administrators may continue a
	 * session that was explicitly shared with administrators. Non-admin users
	 * cannot start durable plans through the general chat route.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool|WP_Error
	 */
	public function check_chat_run_permission( WP_REST_Request $request ) {
		$chat_permission = $this->check_chat_permission();
		if ( true !== $chat_permission ) {
			return $chat_permission;
		}

		if ( ! current_user_can( 'manage_options' ) && true === $request->get_param( 'durable_plan' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Your user role does not have permission to create durable plans.', 'superdav-ai-agent' ),
				array( 'status' => 403 )
			);
		}

		$session_id = self::get_int_param( $request, 'session_id' );
		if ( 0 === $session_id ) {
			return true;
		}

		return $this->current_user_can_access_session( $session_id );
	}

	/**
	 * Permission check for authenticated job status and control routes.
	 *
	 * Job UUIDs are bearer-like identifiers and are not authorization. Resolve
	 * the persisted owner from the transient first and the active-job table as a
	 * durable fallback, then require the real current user to match it.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool|WP_Error
	 */
	public function check_chat_job_permission( WP_REST_Request $request ) {
		$chat_permission = $this->check_chat_permission();
		if ( true !== $chat_permission ) {
			return $chat_permission;
		}

		// Preserve the existing administrator support/debugging contract while
		// requiring every non-admin chat user to own the requested job.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$job_id = self::get_string_param( $request, 'id' );
		if ( '' === $job_id ) {
			return false;
		}

		$job      = get_transient( RestController::JOB_PREFIX . $job_id );
		$owner_id = is_array( $job ) ? absint( $job['user_id'] ?? 0 ) : 0;

		if ( 0 === $owner_id ) {
			$row      = ActiveJobRepository::get_by_job_id( $job_id );
			$owner_id = $row->user_id ?? 0;
		}

		return $owner_id > 0 && $owner_id === get_current_user_id();
	}

	/**
	 * Permission check for the browser tool-result callback endpoint.
	 *
	 * The endpoint mutates session state by consuming paused_state and appending
	 * resumed loop output, so the current user must both have chat access and be
	 * allowed to continue the supplied session before paused_state is loaded.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool|WP_Error
	 */
	public function check_tool_result_permission( WP_REST_Request $request ) {
		$chat_permission = $this->check_chat_permission();
		if ( true !== $chat_permission ) {
			return $chat_permission;
		}

		$session_id = self::get_int_param( $request, 'session_id' );
		if ( ! $session_id ) {
			return new WP_Error(
				'sd_ai_agent_missing_session',
				__( 'session_id is required.', 'superdav-ai-agent' ),
				array( 'status' => 400 )
			);
		}

		$session = Database::get_session( $session_id );
		if ( ! $session ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this chat session.', 'superdav-ai-agent' ),
				array( 'status' => 403 )
			);
		}

		if ( $this->current_user_can_access_session( $session_id ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to access this chat session.', 'superdav-ai-agent' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Permission check for session-specific endpoints.
	 *
	 * Verifies chat access + (session ownership OR session is shared with all admins).
	 * Destructive operations (delete, trash, archive) additionally require ownership.
	 */
	public function check_session_permission( WP_REST_Request $request ): bool {
		if ( ! RolePermissions::current_user_has_chat_access() ) {
			return false;
		}

		$session_id = self::get_int_param( $request, 'id' );
		$session    = Database::get_session( $session_id );

		if ( ! $session ) {
			return false;
		}

		$is_owner = (int) $session->user_id === get_current_user_id();

		if ( $is_owner ) {
			return true;
		}

		// Shared sessions are an administrator collaboration feature. A globally
		// shared row must never expose one vendor's conversation to another vendor.
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// Non-owner administrators may access shared sessions (read + continue),
		// but not delete/trash/archive.
		$method = $request->get_method();
		if ( 'DELETE' === $method ) {
			return false;
		}

		// For PATCH (update), only allow title/pinned/folder changes by non-owners on shared sessions.
		// Status changes (archive/trash) are owner-only.
		if ( 'PATCH' === $method ) {
			$status = $request->get_param( 'status' );
			if ( ! empty( $status ) ) {
				return false;
			}
		}

		// Allow if the session is shared.
		$shared = Database::get_shared_session( $session_id );
		return $shared !== null;
	}

	/**
	 * Permission check for share/unshare endpoints — owner only.
	 */
	public function check_session_owner_permission( WP_REST_Request $request ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$session_id = self::get_int_param( $request, 'id' );
		$session    = Database::get_session( $session_id );

		if ( ! $session ) {
			return false;
		}

		return (int) $session->user_id === get_current_user_id();
	}

	/**
	 * Check whether the current user owns a session or is an administrator with
	 * access to a session explicitly shared for administrator collaboration.
	 *
	 * @param int $session_id Session ID.
	 */
	private function current_user_can_access_session( int $session_id ): bool {
		$session = Database::get_session( $session_id );
		if ( ! $session ) {
			return false;
		}

		if ( (int) $session->user_id === get_current_user_id() ) {
			return true;
		}

		return current_user_can( 'manage_options' ) && null !== Database::get_shared_session( $session_id );
	}

	/**
	 * Permission check for the internal /process endpoint.
	 *
	 * Validates a one-time token stored in the job transient instead of
	 * requiring cookie-based auth (the loopback request has no session).
	 */
	public function check_process_permission( WP_REST_Request $request ): bool {
		$job_id = self::get_string_param( $request, 'job_id' );
		$token  = self::get_string_param( $request, 'token' );

		if ( empty( $job_id ) || empty( $token ) ) {
			return false;
		}

		$job = get_transient( RestController::JOB_PREFIX . $job_id );

		if ( ! is_array( $job ) || empty( $job['token'] ) ) {
			return false;
		}

		/** @var array<string, mixed> $job */
		// @phpstan-ignore-next-line
		return hash_equals( (string) $job['token'], $token );
	}

	/**
	 * Permission check for the plugin download endpoint.
	 *
	 * Requires manage_options capability and a valid nonce for the specific
	 * plugin slug. The nonce is generated by the ability and passed as
	 * `_wpnonce` in the query string.
	 */
	public function check_download_permission( WP_REST_Request $request ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// @phpstan-ignore-next-line
		$slug  = sanitize_key( $request->get_param( 'slug' ) );
		$nonce = $request->get_param( '_wpnonce' );

		// @phpstan-ignore-next-line
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'sd_ai_agent_download_plugin_' . $slug ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get an integer parameter from a REST request.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @param string          $key     Parameter name.
	 * @return int
	 */
	private static function get_int_param( WP_REST_Request $request, string $key ): int {
		$value = $request->get_param( $key );
		/** @var int|string|null $value */
		return absint( $value );
	}

	/**
	 * Get a string parameter from a REST request.
	 *
	 * @param WP_REST_Request $request       The request object.
	 * @param string          $key           Parameter name.
	 * @param string          $default_value Default value if param is not set.
	 * @return string
	 */
	private static function get_string_param( WP_REST_Request $request, string $key, string $default_value = '' ): string {
		$value = $request->get_param( $key );
		if ( ! is_string( $value ) ) {
			return $default_value;
		}
		return $value;
	}
}
