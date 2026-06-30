<?php

declare(strict_types=1);
/**
 * Calendar reminder state records for idempotent reminder delivery.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Automations;

use SdAiAgent\Core\Database;

class CalendarReminderRecords {

	public const STATUS_SENT             = 'sent';
	public const STATUS_SKIPPED          = 'skipped';
	public const STATUS_PENDING_APPROVAL = 'pending_approval';
	public const STATUS_FAILED           = 'failed';

	/**
	 * Get the reminder records table name.
	 */
	public static function table_name(): string {
		return Database::calendar_reminders_table_name();
	}

	/**
	 * Check whether a reminder window has already reached any terminal or queued state.
	 */
	public static function already_processed( string $calendar_id, string $event_id, string $attendee_email, string $reminder_date ): bool {
		return null !== self::find_by_identity( $calendar_id, $event_id, $attendee_email, $reminder_date );
	}

	/**
	 * Record a sent reminder.
	 *
	 * @param array<string, mixed> $data Reminder data.
	 */
	public static function record_sent( array $data ): int|false {
		return self::record_status(
			$data,
			self::STATUS_SENT,
			[
				'provider'            => sanitize_text_field( (string) ( $data['provider'] ?? '' ) ),
				'provider_message_id' => sanitize_text_field( (string) ( $data['provider_message_id'] ?? '' ) ),
				'sent_at'             => self::sanitize_datetime( (string) ( $data['sent_at'] ?? current_time( 'mysql', true ) ) ),
			]
		);
	}

	/**
	 * Record a skipped reminder.
	 *
	 * @param array<string, mixed> $data Reminder data.
	 */
	public static function record_skipped( array $data ): int|false {
		return self::record_status(
			$data,
			self::STATUS_SKIPPED,
			[
				'skip_reason' => sanitize_textarea_field( (string) ( $data['skip_reason'] ?? '' ) ),
			]
		);
	}

	/**
	 * Record a reminder queued for human approval.
	 *
	 * @param array<string, mixed> $data Reminder data.
	 */
	public static function record_pending_approval( array $data ): int|false {
		return self::record_status(
			$data,
			self::STATUS_PENDING_APPROVAL,
			[
				'approval_request_id' => sanitize_text_field( (string) ( $data['approval_request_id'] ?? '' ) ),
			]
		);
	}

	/**
	 * Record a failed reminder attempt.
	 *
	 * @param array<string, mixed> $data Reminder data.
	 */
	public static function record_failed( array $data ): int|false {
		return self::record_status(
			$data,
			self::STATUS_FAILED,
			[
				'provider'            => sanitize_text_field( (string) ( $data['provider'] ?? '' ) ),
				'provider_message_id' => sanitize_text_field( (string) ( $data['provider_message_id'] ?? '' ) ),
				'skip_reason'         => sanitize_textarea_field( (string) ( $data['error_message'] ?? $data['skip_reason'] ?? '' ) ),
			]
		);
	}

	/**
	 * Get a reminder record by ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table_name(), $id )
		);

		return $row ? self::decode_row( $row ) : null;
	}

	/**
	 * Find a reminder record by duplicate-prevention identity.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function find_by_identity( string $calendar_id, string $event_id, string $attendee_email, string $reminder_date ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE calendar_id = %s AND event_id = %s AND attendee_email = %s AND reminder_date = %s',
				self::table_name(),
				self::sanitize_identifier( $calendar_id ),
				self::sanitize_identifier( $event_id ),
				self::sanitize_email( $attendee_email ),
				self::sanitize_date( $reminder_date )
			)
		);

		return $row ? self::decode_row( $row ) : null;
	}

	/**
	 * List recent reminder records for the admin setup UI.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function list_recent( int $limit = 25 ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$limit = max( 1, min( 100, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY updated_at DESC, id DESC LIMIT %d', self::table_name(), $limit )
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$records = array();
		foreach ( $rows as $row ) {
			if ( is_object( $row ) ) {
				$records[] = self::decode_row( $row );
			}
		}

		return $records;
	}

	/**
	 * Hash a phone number for storage without retaining the raw value.
	 */
	public static function hash_phone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone ) ?: '';

		return '' === $digits ? '' : hash_hmac( 'sha256', $digits, wp_salt( 'auth' ) );
	}

	/**
	 * Insert or update a reminder state record.
	 *
	 * @param array<string, mixed> $data   Reminder data.
	 * @param string               $status Reminder status.
	 * @param array<string, mixed> $fields Additional fields.
	 * @return int|false Record ID or false.
	 */
	private static function record_status( array $data, string $status, array $fields = [] ): int|false {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$now           = current_time( 'mysql', true );
		$reminder_date = self::sanitize_date( (string) ( $data['reminder_date'] ?? $data['event_start_at'] ?? $now ) );
		$base          = [
			'calendar_id'         => self::sanitize_identifier( (string) ( $data['calendar_id'] ?? '' ) ),
			'event_id'            => self::sanitize_identifier( (string) ( $data['event_id'] ?? '' ) ),
			'event_start_at'      => self::sanitize_datetime( (string) ( $data['event_start_at'] ?? $now ) ),
			'reminder_date'       => $reminder_date,
			'attendee_email'      => self::sanitize_email( (string) ( $data['attendee_email'] ?? '' ) ),
			'phone_hash'          => self::phone_hash_from_data( $data ),
			'status'              => self::sanitize_status( $status ),
			'skip_reason'         => '',
			'provider'            => '',
			'provider_message_id' => '',
			'approval_request_id' => '',
			'sent_at'             => null,
			'created_at'          => $now,
			'updated_at'          => $now,
		];

		$record = array_merge( $base, $fields );

		$existing = self::find_by_identity(
			$record['calendar_id'],
			$record['event_id'],
			$record['attendee_email'],
			$record['reminder_date']
		);

		if ( null !== $existing ) {
			return self::update_existing_record( (int) $existing['id'], $record, $now );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query; caching not applicable.
		$result = $wpdb->insert( self::table_name(), $record );

		if ( $result ) {
			return (int) $wpdb->insert_id;
		}

		$existing = self::find_by_identity(
			$record['calendar_id'],
			$record['event_id'],
			$record['attendee_email'],
			$record['reminder_date']
		);

		return null === $existing ? false : self::update_existing_record( (int) $existing['id'], $record, $now );
	}

	/**
	 * Update an existing reminder record while preserving optional metadata unless new values are supplied.
	 *
	 * @param int                  $id     Reminder record ID.
	 * @param array<string, mixed> $record Sanitized reminder record.
	 * @param string               $now    Current MySQL datetime.
	 */
	private static function update_existing_record( int $id, array $record, string $now ): int|false {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$update = $record;
		unset( $update['calendar_id'], $update['event_id'], $update['event_start_at'], $update['reminder_date'], $update['attendee_email'], $update['created_at'] );

		foreach ( [ 'phone_hash', 'skip_reason', 'provider', 'provider_message_id', 'approval_request_id', 'sent_at' ] as $optional_field ) {
			if ( empty( $update[ $optional_field ] ) ) {
				unset( $update[ $optional_field ] );
			}
		}

		$update['updated_at'] = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$updated = $wpdb->update(
			self::table_name(),
			$update,
			[ 'id' => $id ]
		);

		return false === $updated ? false : $id;
	}

	/**
	 * Decode a database row.
	 *
	 * @return array<string, mixed>
	 */
	private static function decode_row( object $row ): array {
		return [
			'id'                  => (int) $row->id,
			'calendar_id'         => $row->calendar_id,
			'event_id'            => $row->event_id,
			'event_start_at'      => $row->event_start_at,
			'reminder_date'       => $row->reminder_date,
			'attendee_email'      => $row->attendee_email,
			'phone_hash'          => $row->phone_hash,
			'status'              => $row->status,
			'skip_reason'         => $row->skip_reason,
			'provider'            => $row->provider,
			'provider_message_id' => $row->provider_message_id,
			'approval_request_id' => $row->approval_request_id,
			'sent_at'             => $row->sent_at,
			'created_at'          => $row->created_at,
			'updated_at'          => $row->updated_at,
		];
	}

	/**
	 * Get a phone hash from explicit hash or raw phone input.
	 *
	 * @param array<string, mixed> $data Reminder data.
	 */
	private static function phone_hash_from_data( array $data ): string {
		$phone_hash = sanitize_text_field( (string) ( $data['phone_hash'] ?? '' ) );

		if ( '' !== $phone_hash ) {
			return substr( $phone_hash, 0, 64 );
		}

		return self::hash_phone( (string) ( $data['phone'] ?? '' ) );
	}

	/**
	 * Sanitize a short identifier.
	 */
	private static function sanitize_identifier( string $value ): string {
		return substr( sanitize_text_field( $value ), 0, 191 );
	}

	/**
	 * Sanitize and normalize an email address.
	 */
	private static function sanitize_email( string $email ): string {
		return substr( sanitize_email( strtolower( $email ) ), 0, 191 );
	}

	/**
	 * Sanitize a supported status.
	 */
	private static function sanitize_status( string $status ): string {
		$allowed = [ self::STATUS_SENT, self::STATUS_SKIPPED, self::STATUS_PENDING_APPROVAL, self::STATUS_FAILED ];

		return in_array( $status, $allowed, true ) ? $status : self::STATUS_FAILED;
	}

	/**
	 * Sanitize a MySQL datetime string.
	 */
	private static function sanitize_datetime( string $datetime ): string {
		$timestamp = strtotime( $datetime );

		return false === $timestamp ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Sanitize a MySQL date string.
	 */
	private static function sanitize_date( string $date ): string {
		$timestamp = strtotime( $date );

		return false === $timestamp ? gmdate( 'Y-m-d' ) : gmdate( 'Y-m-d', $timestamp );
	}
}
