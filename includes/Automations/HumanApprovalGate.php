<?php

declare(strict_types=1);
/**
 * Durable human approval requests for sensitive automation actions.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Automations;

use SdAiAgent\Core\Database;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HumanApprovalGate {

	public const STATUS_PENDING  = 'pending';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_REJECTED = 'rejected';
	public const STATUS_EXPIRED  = 'expired';
	public const STATUS_EXECUTED = 'executed';
	public const STATUS_FAILED   = 'failed';

	private const SECRET_REDACTED_PLACEHOLDER = '[redacted]';

	/**
	 * Registered action handlers.
	 *
	 * @var array<string, callable(array<string, mixed>, array<string, mixed>): mixed>
	 */
	private static array $handlers = [];

	/**
	 * Get the approval request table name.
	 */
	public static function table_name(): string {
		return Database::approval_requests_table_name();
	}

	/**
	 * Register an executable action handler.
	 *
	 * @param string   $action_type Action type such as sms-send.
	 * @param callable $handler     Handler callback.
	 */
	public static function register_handler( string $action_type, callable $handler ): void {
		$action_type = sanitize_key( $action_type );
		if ( '' === $action_type ) {
			return;
		}

		self::$handlers[ $action_type ] = $handler;
	}

	/**
	 * Clear registered handlers. Intended for tests.
	 */
	public static function clear_handlers(): void {
		self::$handlers = [];
	}

	/**
	 * Create or reuse a pending approval request for a sensitive action.
	 *
	 * @param array<string, mixed> $args Request fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_pending( array $args ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$source_type = sanitize_key( (string) ( $args['source_type'] ?? 'automation' ) );
		$source_id   = absint( $args['source_id'] ?? 0 );
		$action_type = sanitize_key( (string) ( $args['action_type'] ?? '' ) );
		$payload     = self::sanitize_payload( is_array( $args['payload'] ?? null ) ? $args['payload'] : [] );

		if ( '' === $source_type || '' === $action_type ) {
			return new WP_Error( 'sd_ai_agent_approval_invalid_request', __( 'Approval request source and action type are required.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
		}

		$payload_json = wp_json_encode( $payload );
		if ( false === $payload_json ) {
			return new WP_Error( 'sd_ai_agent_approval_invalid_payload', __( 'Approval request payload could not be encoded.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
		}

		$payload_hash = hash( 'sha256', $payload_json );
		$existing     = self::find_equivalent_pending( $source_type, $source_id, $action_type, $payload_hash );
		if ( null !== $existing ) {
			return $existing;
		}

		$now        = current_time( 'mysql', true );
		$expires_at = self::normalize_datetime( $args['expires_at'] ?? null );
		$result     = $wpdb->insert(
			self::table_name(),
			[
				'source_type'  => $source_type,
				'source_id'    => $source_id,
				'action_type'  => $action_type,
				'status'       => self::STATUS_PENDING,
				'payload'      => $payload_json,
				'payload_hash' => $payload_hash,
				'result'       => '',
				'requested_by' => absint( $args['requested_by'] ?? get_current_user_id() ),
				'approved_by'  => 0,
				'expires_at'   => $expires_at,
				'created_at'   => $now,
				'updated_at'   => $now,
			],
			[ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ]
		);

		if ( false === $result ) {
			return new WP_Error( 'sd_ai_agent_approval_create_failed', __( 'Failed to create approval request.', 'superdav-ai-agent' ), [ 'status' => 500 ] );
		}

		$created = self::get( (int) $wpdb->insert_id );
		if ( null === $created ) {
			return new WP_Error( 'sd_ai_agent_approval_create_failed', __( 'Failed to load approval request after creation.', 'superdav-ai-agent' ), [ 'status' => 500 ] );
		}

		return $created;
	}

	/**
	 * List approval requests.
	 *
	 * @param string $status Optional status filter.
	 * @param int    $limit  Maximum rows.
	 * @return list<array<string, mixed>>
	 */
	public static function list( string $status = '', int $limit = 50 ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$status = sanitize_key( $status );
		$limit  = max( 1, min( 100, $limit ) );

		if ( '' !== $status ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY created_at DESC LIMIT %d', self::table_name(), $status, $limit )
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i ORDER BY created_at DESC LIMIT %d', self::table_name(), $limit )
			);
		}

		return array_map( [ __CLASS__, 'decode_row' ], $rows ?: [] );
	}

	/**
	 * Get one approval request.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table_name(), $id ) );

		return $row ? self::decode_row( $row ) : null;
	}

	/**
	 * Approve a pending request and optionally execute it.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function approve( int $id, int $approved_by = 0, bool $execute = true ) {
		$request = self::transition( $id, self::STATUS_PENDING, self::STATUS_APPROVED, [ 'approved_by' => absint( $approved_by ?: get_current_user_id() ) ] );
		if ( is_wp_error( $request ) || ! $execute || self::STATUS_APPROVED !== $request['status'] ) {
			return $request;
		}

		return self::execute( $id );
	}

	/**
	 * Reject a pending request.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function reject( int $id, int $approved_by = 0 ) {
		return self::transition( $id, self::STATUS_PENDING, self::STATUS_REJECTED, [ 'approved_by' => absint( $approved_by ?: get_current_user_id() ) ] );
	}

	/**
	 * Execute an approved request exactly once through its registered handler.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute( int $id ) {
		$request = self::get( $id );
		if ( null === $request ) {
			return new WP_Error( 'sd_ai_agent_approval_not_found', __( 'Approval request not found.', 'superdav-ai-agent' ), [ 'status' => 404 ] );
		}

		if ( self::STATUS_EXECUTED === $request['status'] ) {
			return $request;
		}

		if ( self::STATUS_APPROVED !== $request['status'] ) {
			return new WP_Error( 'sd_ai_agent_approval_not_executable', __( 'Approval request is not approved for execution.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		if ( self::is_expired( $request ) ) {
			return self::transition( $id, self::STATUS_APPROVED, self::STATUS_EXPIRED );
		}

		$action_type = (string) $request['action_type'];
		if ( ! isset( self::$handlers[ $action_type ] ) ) {
			$error = new WP_Error( 'sd_ai_agent_approval_handler_missing', __( 'No approval action handler is registered for this action type.', 'superdav-ai-agent' ), [ 'status' => 501 ] );
			self::store_result( $id, self::STATUS_FAILED, self::error_to_array( $error ) );
			return $error;
		}

		$payload = is_array( $request['payload'] ) ? $request['payload'] : [];
		$result  = call_user_func( self::$handlers[ $action_type ], $payload, $request );
		if ( is_wp_error( $result ) ) {
			self::store_result( $id, self::STATUS_FAILED, self::error_to_array( $result ) );
			return $result;
		}

		return self::store_result(
			$id,
			self::STATUS_EXECUTED,
			[
				'success' => true,
				'data'    => self::sanitize_payload( is_array( $result ) ? $result : [ 'result' => $result ] ),
			]
		);
	}

	/**
	 * Sanitize and redact payload data before storage or output.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @return array<string, mixed>
	 */
	public static function sanitize_payload( array $payload ): array {
		$clean = [];
		foreach ( $payload as $key => $value ) {
			$clean_key = is_string( $key ) ? sanitize_key( $key ) : (string) $key;
			if ( '' === $clean_key ) {
				continue;
			}

			if ( self::is_sensitive_key( $clean_key ) ) {
				$clean[ $clean_key ] = self::SECRET_REDACTED_PLACEHOLDER;
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $clean_key ] = self::sanitize_payload( $value );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$clean[ $clean_key ] = self::sanitize_scalar( $clean_key, $value );
			}
		}

		return $clean;
	}

	/**
	 * Decode a request row.
	 *
	 * @param object $row Database row.
	 * @return array<string, mixed>
	 */
	private static function decode_row( object $row ): array {
		return [
			'id'           => (int) $row->id,
			'source_type'  => (string) $row->source_type,
			'source_id'    => (int) $row->source_id,
			'action_type'  => (string) $row->action_type,
			'status'       => (string) $row->status,
			'payload'      => json_decode( (string) $row->payload, true ) ?: [],
			'payload_hash' => (string) $row->payload_hash,
			'result'       => json_decode( (string) $row->result, true ) ?: [],
			'requested_by' => (int) $row->requested_by,
			'approved_by'  => (int) $row->approved_by,
			'expires_at'   => $row->expires_at,
			'created_at'   => (string) $row->created_at,
			'updated_at'   => (string) $row->updated_at,
		];
	}

	/**
	 * Find an equivalent pending request.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function find_equivalent_pending( string $source_type, int $source_id, string $action_type, string $payload_hash ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE source_type = %s AND source_id = %d AND action_type = %s AND payload_hash = %s AND status = %s ORDER BY created_at DESC LIMIT 1',
				self::table_name(),
				$source_type,
				$source_id,
				$action_type,
				$payload_hash,
				self::STATUS_PENDING
			)
		);

		return $row ? self::decode_row( $row ) : null;
	}

	/**
	 * Transition status with optimistic current-status check.
	 *
	 * @param int                  $id    Request ID.
	 * @param string               $from  Required current status.
	 * @param string               $to    New status.
	 * @param array<string, mixed> $extra Extra fields.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function transition( int $id, string $from, string $to, array $extra = [] ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$request = self::get( $id );
		if ( null === $request ) {
			return new WP_Error( 'sd_ai_agent_approval_not_found', __( 'Approval request not found.', 'superdav-ai-agent' ), [ 'status' => 404 ] );
		}

		if ( self::is_expired( $request ) && self::STATUS_PENDING === $request['status'] ) {
			$from = self::STATUS_PENDING;
			$to   = self::STATUS_EXPIRED;
		}

		if ( $from !== $request['status'] ) {
			return new WP_Error( 'sd_ai_agent_approval_invalid_transition', __( 'Approval request cannot be changed from its current status.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		$data    = array_merge(
			$extra,
			[
				'status'     => $to,
				'updated_at' => current_time( 'mysql', true ),
			]
		);
		$formats = [];
		foreach ( $data as $key => $value ) {
			$formats[] = in_array( $key, [ 'approved_by' ], true ) ? '%d' : '%s';
		}

		$updated = $wpdb->update(
			self::table_name(),
			$data,
			[
				'id'     => $id,
				'status' => $from,
			],
			$formats,
			[ '%d', '%s' ]
		);
		if ( false === $updated || 0 === $updated ) {
			return new WP_Error( 'sd_ai_agent_approval_update_failed', __( 'Failed to update approval request.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		$updated_request = self::get( $id );
		if ( null === $updated_request ) {
			return new WP_Error( 'sd_ai_agent_approval_update_failed', __( 'Failed to load approval request after update.', 'superdav-ai-agent' ), [ 'status' => 500 ] );
		}

		return $updated_request;
	}

	/**
	 * Persist an execution result.
	 *
	 * @param int                  $id     Request ID.
	 * @param string               $status Result status.
	 * @param array<string, mixed> $result Result payload.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function store_result( int $id, string $status, array $result ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$result_json = wp_json_encode( self::sanitize_payload( $result ) );
		if ( false === $result_json ) {
			$result_json = '{}';
		}

		$updated = $wpdb->update(
			self::table_name(),
			[
				'status'     => $status,
				'result'     => $result_json,
				'updated_at' => current_time( 'mysql', true ),
			],
			[
				'id'     => $id,
				'status' => self::STATUS_APPROVED,
			],
			[ '%s', '%s', '%s' ],
			[ '%d', '%s' ]
		);

		if ( false === $updated || 0 === $updated ) {
			return new WP_Error( 'sd_ai_agent_approval_execute_race', __( 'Approval request was already executed or changed.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		$updated_request = self::get( $id );
		if ( null === $updated_request ) {
			return new WP_Error( 'sd_ai_agent_approval_execute_race', __( 'Failed to load approval request after execution.', 'superdav-ai-agent' ), [ 'status' => 500 ] );
		}

		return $updated_request;
	}

	/**
	 * Check whether a request is expired.
	 *
	 * @param array<string, mixed> $request Request.
	 */
	private static function is_expired( array $request ): bool {
		$expires_at = (string) ( $request['expires_at'] ?? '' );
		return '' !== $expires_at && strtotime( $expires_at ) <= time();
	}

	/**
	 * Normalize a date/time string for storage.
	 */
	private static function normalize_datetime( mixed $value ): ?string {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		$timestamp = strtotime( $value );
		return false === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Redact known secret-bearing keys.
	 */
	private static function is_sensitive_key( string $key ): bool {
		return (bool) preg_match( '/(api[_-]?key|token|secret|password|authorization|credential|webhook|private[_-]?key)/i', $key );
	}

	/**
	 * Sanitize scalar values and mask phone-like fields.
	 */
	private static function sanitize_scalar( string $key, mixed $value ): mixed {
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		$value = sanitize_text_field( (string) $value );
		if ( str_contains( $key, 'phone' ) || str_contains( $key, 'recipient' ) || str_contains( $key, 'to' ) ) {
			return self::mask_phone_like_value( $value );
		}

		return $value;
	}

	/**
	 * Mask phone-like values while preserving enough context for review.
	 */
	private static function mask_phone_like_value( string $value ): string {
		$digits = preg_replace( '/\D+/', '', $value ) ?? '';
		if ( strlen( $digits ) < 7 ) {
			return $value;
		}

		return '***' . substr( $digits, -4 );
	}

	/**
	 * Convert a WP_Error to a storable array.
	 *
	 * @return array<string, mixed>
	 */
	private static function error_to_array( WP_Error $error ): array {
		return [
			'success' => false,
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
		];
	}
}
