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

		$is_owner = (int) $session->user_id === get_current_user_id();
		if ( $is_owner ) {
			return true;
		}

		if ( Database::get_shared_session( $session_id ) ) {
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

		// Non-owners may access shared sessions (read + continue), but not delete/trash/archive.
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
