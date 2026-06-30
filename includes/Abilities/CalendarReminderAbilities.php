<?php

declare(strict_types=1);
/**
 * Deterministic Google Calendar SMS reminder orchestration abilities.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Automations\CalendarReminderRecords;
use SdAiAgent\Automations\HumanApprovalGate;
use SdAiAgent\Models\ContactMapping;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates calendar event lookup, contact consent, approval, SMS delivery,
 * and idempotent reminder state without relying on LLM control flow.
 */
class CalendarReminderAbilities {

	private const ABILITY_ID       = 'sd-ai-agent/calendar-send-sms-reminders';
	private const APPROVAL_ACTION  = 'calendar-sms-reminder';
	private const DEFAULT_TEMPLATE = 'Reminder: {title} starts at {time}. {summary}';

	/** Register calendar reminder abilities and approval handlers. */
	public static function register_abilities(): void {
		HumanApprovalGate::register_handler( self::APPROVAL_ACTION, [ __CLASS__, 'handle_approved_reminder' ] );

		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			self::ABILITY_ID,
			[
				'label'               => __( 'Send Calendar SMS Reminders', 'superdav-ai-agent' ),
				'description'         => __( 'Deterministically send or queue SMS reminders for upcoming Google Calendar attendees with phone mappings, consent, approval, and duplicate prevention.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'calendar_id'      => [ 'type' => 'string' ],
						'lookahead_hours'  => [
							'type'    => 'integer',
							'default' => 24,
						],
						'approval_mode'    => [
							'type'    => 'string',
							'enum'    => [ 'auto', 'require_approval', 'dry_run' ],
							'default' => 'dry_run',
						],
						'message_template' => [ 'type' => 'string' ],
						'max_events'       => [
							'type'    => 'integer',
							'default' => 10,
						],
						'max_recipients'   => [
							'type'    => 'integer',
							'default' => 50,
						],
					],
					'required'   => [],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_send_sms_reminders' ],
				'permission_callback' => static fn(): bool => ToolCapabilities::current_user_can( self::ABILITY_ID ),
			]
		);
	}

	/**
	 * Run the deterministic reminder workflow.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_send_sms_reminders( array $input = [] ): array|WP_Error {
		$args   = self::normalize_input( $input );
		$events = GoogleCalendarAbilities::handle_list_events(
			[
				'calendar_id' => $args['calendar_id'],
				'time_min'    => gmdate( 'c' ),
				'time_max'    => gmdate( 'c', time() + ( (int) $args['lookahead_hours'] * HOUR_IN_SECONDS ) ),
				'limit'       => $args['max_events'],
			]
		);

		if ( is_wp_error( $events ) ) {
			return $events;
		}

		$calendar_id = (string) ( $events['calendar_id'] ?? $args['calendar_id'] );
		$summary     = self::empty_summary( $calendar_id, (string) $args['approval_mode'] );
		$processed   = 0;

		foreach ( self::list_from( $events['events'] ?? [] ) as $event ) {
			if ( $processed >= (int) $args['max_recipients'] ) {
				break;
			}

			if ( ! is_array( $event ) ) {
				continue;
			}

			$event_summary = self::process_event( $event, $calendar_id, $args, $processed );
			foreach ( [ 'sent', 'skipped', 'pending', 'failed' ] as $bucket ) {
				foreach ( $event_summary[ $bucket ] as $item ) {
					$summary[ $bucket ][] = $item;
				}
			}
		}

		$summary['counts'] = [
			'sent'    => count( $summary['sent'] ),
			'skipped' => count( $summary['skipped'] ),
			'pending' => count( $summary['pending'] ),
			'failed'  => count( $summary['failed'] ),
		];

		return $summary;
	}

	/**
	 * Execute an approved reminder request.
	 *
	 * @param array<string, mixed> $payload Approval payload.
	 * @param array<string, mixed> $request Approval request row.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_approved_reminder( array $payload, array $request = [] ): array|WP_Error {
		$contact = ContactMapping::get_by_email( (string) ( $payload['attendee_email'] ?? '' ) );
		if ( ! is_array( $contact ) || true !== $contact['sms_consent'] ) {
			$error = new WP_Error( 'calendar_reminder_contact_unavailable', __( 'Consented attendee phone mapping is no longer available.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
			self::record_failed_from_payload( $payload, $error );
			return $error;
		}

		$record_data = self::record_data_from_payload(
			$payload,
			[
				'approval_request_id' => (string) ( $request['id'] ?? $payload['approval_request_id'] ?? '' ),
				'phone'               => (string) $contact['phone_e164'],
			]
		);
		if ( false === CalendarReminderRecords::claim_for_delivery( $record_data ) ) {
			return new WP_Error( 'calendar_reminder_already_processed', __( 'Reminder was already processed or claimed.', 'superdav-ai-agent' ), [ 'status' => 409 ] );
		}

		$result = self::send_sms( (string) $contact['phone_e164'], (string) ( $payload['message'] ?? '' ) );
		if ( is_wp_error( $result ) ) {
			self::record_failed_from_payload( $payload, $result );
			return $result;
		}

		CalendarReminderRecords::record_sent(
			array_merge(
				$record_data,
				[
					'provider' => 'textbee',
				]
			)
		);

		return [
			'success'        => true,
			'attendee_email' => (string) ( $payload['attendee_email'] ?? '' ),
			'event_id'       => (string) ( $payload['event_id'] ?? '' ),
			'recipient'      => SmsAbilities::redact_phone_number( (string) $contact['phone_e164'] ),
		];
	}

	/**
	 * Normalize reminder workflow input.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array{calendar_id:string,lookahead_hours:int,approval_mode:string,message_template:string,max_events:int,max_recipients:int}
	 */
	private static function normalize_input( array $input ): array {
		$approval_mode = sanitize_key( (string) ( $input['approval_mode'] ?? 'dry_run' ) );
		if ( ! in_array( $approval_mode, [ 'auto', 'require_approval', 'dry_run' ], true ) ) {
			$approval_mode = 'dry_run';
		}

		return [
			'calendar_id'      => sanitize_text_field( (string) ( $input['calendar_id'] ?? 'primary' ) ),
			'lookahead_hours'  => max( 1, min( 168, absint( $input['lookahead_hours'] ?? 24 ) ) ),
			'approval_mode'    => $approval_mode,
			'message_template' => self::sanitize_message_template( (string) ( $input['message_template'] ?? self::DEFAULT_TEMPLATE ) ),
			'max_events'       => max( 1, min( 50, absint( $input['max_events'] ?? 10 ) ) ),
			'max_recipients'   => max( 1, min( 200, absint( $input['max_recipients'] ?? 50 ) ) ),
		];
	}

	/**
	 * Build an empty reminder summary.
	 *
	 * @return array{calendar_id:string,approval_mode:string,sent:list<array<string,mixed>>,skipped:list<array<string,mixed>>,pending:list<array<string,mixed>>,failed:list<array<string,mixed>>,counts:array{sent:int,skipped:int,pending:int,failed:int}}
	 */
	private static function empty_summary( string $calendar_id, string $approval_mode ): array {
		return [
			'calendar_id'   => $calendar_id,
			'approval_mode' => $approval_mode,
			'sent'          => [],
			'skipped'       => [],
			'pending'       => [],
			'failed'        => [],
			'counts'        => [
				'sent'    => 0,
				'skipped' => 0,
				'pending' => 0,
				'failed'  => 0,
			],
		];
	}

	/**
	 * Process one normalized calendar event.
	 *
	 * @param array<string, mixed> $event       Event.
	 * @param string               $calendar_id Calendar ID.
	 * @param array<string, mixed> $args        Normalized args.
	 * @param int                  $processed   Processed recipient counter.
	 * @return array{sent:list<array<string,mixed>>,skipped:list<array<string,mixed>>,pending:list<array<string,mixed>>,failed:list<array<string,mixed>>}
	 */
	private static function process_event( array $event, string $calendar_id, array $args, int &$processed ): array {
		$result = [
			'sent'    => [],
			'skipped' => [],
			'pending' => [],
			'failed'  => [],
		];
		if ( 'cancelled' === sanitize_key( (string) ( $event['status'] ?? '' ) ) ) {
			$result['skipped'][] = self::skip_item( $event, '', 'event_cancelled' );
			return $result;
		}

		$skipped_attendees = [];
		$attendees         = self::eligible_attendees( $event, $skipped_attendees );
		foreach ( $skipped_attendees as $skipped_attendee ) {
			$result['skipped'][] = $skipped_attendee;
		}
		if ( [] === $attendees ) {
			return $result;
		}

		$lookup      = ContactMapping::lookup_for_reminders( array_keys( $attendees ) );
		$event_start = self::event_start( $event );
		$date        = gmdate( 'Y-m-d', strtotime( $event_start ) ?: time() );
		$message     = self::build_message( $event, (string) $args['message_template'] );

		foreach ( $lookup['skipped'] as $skip ) {
			$email = (string) ( $skip['attendee_email'] ?? '' );
			self::record_skipped( $calendar_id, $event, $email, $event_start, $date, (string) ( $skip['reason'] ?? 'contact_unavailable' ) );
			$result['skipped'][] = self::skip_item( $event, $email, (string) ( $skip['reason'] ?? 'contact_unavailable' ) );
		}

		foreach ( $lookup['contacts'] as $contact ) {
			if ( $processed >= (int) $args['max_recipients'] ) {
				break;
			}

			$email = (string) ( $contact['attendee_email'] ?? '' );
			if ( CalendarReminderRecords::already_processed( $calendar_id, (string) ( $event['event_id'] ?? '' ), $email, $date ) ) {
				$result['skipped'][] = self::skip_item( $event, $email, 'already_processed' );
				continue;
			}

			++$processed;
			$record_data = self::record_data( $calendar_id, $event, $email, $event_start, $date, (string) ( $contact['phone_e164'] ?? '' ) );
			if ( 'dry_run' === $args['approval_mode'] ) {
				$result['skipped'][] = self::skip_item( $event, $email, 'dry_run' );
				continue;
			}

			if ( 'require_approval' === $args['approval_mode'] ) {
				$pending = self::queue_approval( $record_data, $message );
				if ( is_wp_error( $pending ) ) {
					CalendarReminderRecords::record_failed( array_merge( $record_data, [ 'error_message' => $pending->get_error_message() ] ) );
					$result['failed'][] = self::failure_item( $event, $email, $pending );
					continue;
				}
				$result['pending'][] = self::pending_item( $event, $email, (int) $pending['id'] );
				continue;
			}

			if ( false === CalendarReminderRecords::claim_for_delivery( $record_data ) ) {
				$result['skipped'][] = self::skip_item( $event, $email, 'already_processed' );
				continue;
			}

			$sent = self::send_sms( (string) ( $contact['phone_e164'] ?? '' ), $message );
			if ( is_wp_error( $sent ) ) {
				CalendarReminderRecords::record_failed(
					array_merge(
						$record_data,
						[
							'provider'      => 'textbee',
							'error_message' => $sent->get_error_message(),
						]
					)
				);
				$result['failed'][] = self::failure_item( $event, $email, $sent );
				continue;
			}

			CalendarReminderRecords::record_sent( array_merge( $record_data, [ 'provider' => 'textbee' ] ) );
			$result['sent'][] = self::sent_item( $event, $email, (string) ( $contact['phone_e164'] ?? '' ) );
		}

		return $result;
	}

	/**
	 * Select attendees that can still receive reminders.
	 *
	 * @param array<string, mixed>       $event   Event.
	 * @param list<array<string, mixed>> $skipped Skipped output accumulator.
	 * @return array<string, array<string, string>>
	 */
	private static function eligible_attendees( array $event, array &$skipped ): array {
		$eligible = [];
		foreach ( self::list_from( $event['attendees'] ?? [] ) as $attendee ) {
			if ( ! is_array( $attendee ) ) {
				continue;
			}
			$email = strtolower( sanitize_email( (string) ( $attendee['email'] ?? '' ) ) );
			if ( '' === $email ) {
				continue;
			}
			if ( 'declined' === sanitize_key( (string) ( $attendee['response_status'] ?? '' ) ) ) {
				$skipped[] = self::skip_item( $event, $email, 'attendee_declined' );
				continue;
			}
			$eligible[ $email ] = [
				'email'           => $email,
				'response_status' => (string) ( $attendee['response_status'] ?? '' ),
			];
		}

		return $eligible;
	}

	/**
	 * Queue a reminder for approval.
	 *
	 * @param array<string, mixed> $record_data Reminder record data.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function queue_approval( array $record_data, string $message ): array|WP_Error {
		$approval = HumanApprovalGate::create_pending(
			[
				'source_type' => 'automation',
				'action_type' => self::APPROVAL_ACTION,
				'payload'     => array_merge( $record_data, [ 'message' => $message ] ),
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			]
		);

		if ( is_wp_error( $approval ) ) {
			return $approval;
		}

		CalendarReminderRecords::record_pending_approval( array_merge( $record_data, [ 'approval_request_id' => (string) $approval['id'] ] ) );

		return $approval;
	}

	private static function send_sms( string $phone, string $message ): array|WP_Error {
		return SmsAbilities::handle_sms_send(
			[
				'recipients' => [ $phone ],
				'message'    => $message,
			]
		);
	}

	/**
	 * Build a concise SMS body from untrusted event fields.
	 *
	 * @param array<string, mixed> $event Event.
	 */
	private static function build_message( array $event, string $template ): string {
		$start = self::event_start( $event );
		$text  = strtr(
			$template,
			[
				'{title}'   => self::safe_text( (string) ( $event['summary'] ?? __( 'Calendar event', 'superdav-ai-agent' ) ), 80 ),
				'{time}'    => self::format_event_time( $start ),
				'{summary}' => self::safe_text( (string) ( $event['description'] ?? '' ), 180 ),
			]
		);

		return self::safe_text( $text, SmsAbilities::MAX_MESSAGE_LENGTH );
	}

	private static function sanitize_message_template( string $template ): string {
		$template = self::safe_text( $template, 500 );
		return '' === $template ? self::DEFAULT_TEMPLATE : $template;
	}

	private static function safe_text( string $text, int $max_length ): string {
		$text = wp_strip_all_tags( preg_replace( '/\s+/', ' ', $text ) ?: '' );
		$text = trim( str_replace( [ "\r", "\n" ], ' ', $text ) );
		if ( strlen( $text ) <= $max_length ) {
			return $text;
		}

		$ellipsis = '…';
		if ( $max_length <= strlen( $ellipsis ) ) {
			return substr( $text, 0, max( 0, $max_length ) );
		}

		return substr( $text, 0, max( 0, $max_length - strlen( $ellipsis ) ) ) . $ellipsis;
	}

	/**
	 * Read an event start datetime.
	 *
	 * @param array<string, mixed> $event Event.
	 */
	private static function event_start( array $event ): string {
		$start = is_array( $event['start'] ?? null ) ? $event['start'] : [];
		return (string) ( $start['datetime'] ?? current_time( 'mysql', true ) );
	}

	private static function format_event_time( string $datetime ): string {
		$timestamp = strtotime( $datetime );
		$formatted = false === $timestamp ? false : wp_date( 'M j, Y g:i A', $timestamp );

		return false === $formatted ? $datetime : $formatted;
	}

	/** @return list<mixed> */
	private static function list_from( mixed $value ): array {
		return is_array( $value ) ? array_values( $value ) : [];
	}

	/**
	 * Build reminder record data.
	 *
	 * @param string               $calendar_id Calendar ID.
	 * @param array<string, mixed> $event       Event.
	 * @param string               $email       Attendee email.
	 * @param string               $event_start Event start datetime.
	 * @param string               $date        Reminder date.
	 * @param string               $phone       Raw recipient phone number.
	 * @return array<string, mixed>
	 */
	private static function record_data( string $calendar_id, array $event, string $email, string $event_start, string $date, string $phone = '' ): array {
		return [
			'calendar_id'    => $calendar_id,
			'event_id'       => (string) ( $event['event_id'] ?? '' ),
			'event_start_at' => $event_start,
			'reminder_date'  => $date,
			'attendee_email' => $email,
			'phone'          => $phone,
		];
	}

	/**
	 * Build reminder record data from an approval payload.
	 *
	 * @param array<string, mixed> $payload   Payload.
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private static function record_data_from_payload( array $payload, array $overrides = [] ): array {
		return array_merge(
			[
				'calendar_id'    => (string) ( $payload['calendar_id'] ?? '' ),
				'event_id'       => (string) ( $payload['event_id'] ?? '' ),
				'event_start_at' => (string) ( $payload['event_start_at'] ?? '' ),
				'reminder_date'  => (string) ( $payload['reminder_date'] ?? '' ),
				'attendee_email' => (string) ( $payload['attendee_email'] ?? '' ),
			],
			$overrides
		);
	}

	/**
	 * Record an approval execution failure.
	 *
	 * @param array<string, mixed> $payload Payload.
	 */
	private static function record_failed_from_payload( array $payload, WP_Error $error ): void {
		CalendarReminderRecords::record_failed(
			self::record_data_from_payload(
				$payload,
				[
					'provider'      => 'textbee',
					'error_message' => $error->get_error_message(),
				]
			)
		);
	}

	/**
	 * Record a skipped reminder.
	 *
	 * @param string               $calendar_id Calendar ID.
	 * @param array<string, mixed> $event       Event.
	 * @param string               $email       Attendee email.
	 * @param string               $event_start Event start datetime.
	 * @param string               $date        Reminder date.
	 * @param string               $reason      Skip reason.
	 */
	private static function record_skipped( string $calendar_id, array $event, string $email, string $event_start, string $date, string $reason ): void {
		CalendarReminderRecords::record_skipped( array_merge( self::record_data( $calendar_id, $event, $email, $event_start, $date ), [ 'skip_reason' => $reason ] ) );
	}

	/**
	 * Build a skipped output item.
	 *
	 * @param array<string, mixed> $event Event.
	 * @param string               $email Attendee email.
	 * @param string               $reason Skip reason.
	 * @param array<string, mixed> $extra Extra output fields.
	 * @return array<string, mixed>
	 */
	private static function skip_item( array $event, string $email, string $reason, array $extra = [] ): array {
		return array_merge(
			[
				'event_id'       => (string) ( $event['event_id'] ?? '' ),
				'attendee_email' => $email,
				'reason'         => $reason,
			],
			$extra
		);
	}

	/**
	 * Build a pending approval output item.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, mixed>
	 */
	private static function pending_item( array $event, string $email, int $approval_id ): array {
		return [
			'event_id'            => (string) ( $event['event_id'] ?? '' ),
			'attendee_email'      => $email,
			'approval_request_id' => $approval_id,
		];
	}

	/**
	 * Build a sent output item.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, mixed>
	 */
	private static function sent_item( array $event, string $email, string $phone ): array {
		return [
			'event_id'       => (string) ( $event['event_id'] ?? '' ),
			'attendee_email' => $email,
			'recipient'      => SmsAbilities::redact_phone_number( $phone ),
		];
	}

	/**
	 * Build a failed output item.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, mixed>
	 */
	private static function failure_item( array $event, string $email, WP_Error $error ): array {
		return [
			'event_id'       => (string) ( $event['event_id'] ?? '' ),
			'attendee_email' => $email,
			'error_code'     => $error->get_error_code(),
			'message'        => $error->get_error_message(),
		];
	}
}
