<?php

declare(strict_types=1);
/**
 * Integration tests for calendar reminder idempotency state records.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Automations;

use SdAiAgent\Automations\CalendarReminderRecords;
use SdAiAgent\Core\Database;
use WP_UnitTestCase;

class CalendarReminderRecordsTest extends WP_UnitTestCase {

	/**
	 * Ensure the reminder state table exists and is empty before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		/** @var \wpdb $wpdb */
		Database::install();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only table reset.
		$wpdb->query( 'TRUNCATE TABLE ' . CalendarReminderRecords::table_name() );
	}

	/**
	 * Build a valid reminder payload.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function make_reminder( array $overrides = [] ): array {
		return array_merge(
			[
				'calendar_id'    => 'primary',
				'event_id'       => 'event-123',
				'event_start_at' => '2026-07-01 09:00:00',
				'reminder_date'  => '2026-07-01',
				'attendee_email' => 'Person@Example.com',
				'phone'          => '+1 (555) 123-4567',
			],
			$overrides
		);
	}

	/**
	 * Recording a sent reminder stores state without retaining a raw phone number.
	 */
	public function test_record_sent_stores_hashed_phone_only(): void {
		$id = CalendarReminderRecords::record_sent(
			$this->make_reminder(
				[
					'provider'            => 'textbee',
					'provider_message_id' => 'msg-123',
				]
			)
		);

		$this->assertIsInt( $id );
		$record = CalendarReminderRecords::get( $id );

		$this->assertNotNull( $record );
		$this->assertSame( CalendarReminderRecords::STATUS_SENT, $record['status'] );
		$this->assertSame( 'person@example.com', $record['attendee_email'] );
		$this->assertSame( hash( 'sha256', '15551234567' ), $record['phone_hash'] );
		$this->assertStringNotContainsString( '555', implode( ' ', array_map( 'strval', $record ) ) );
		$this->assertNotEmpty( $record['sent_at'] );
	}

	/**
	 * Duplicate records for the same reminder window update the existing row.
	 */
	public function test_duplicate_identity_reuses_existing_record(): void {
		$first_id  = CalendarReminderRecords::record_pending_approval( $this->make_reminder( [ 'approval_request_id' => 'approval-1' ] ) );
		$second_id = CalendarReminderRecords::record_sent( $this->make_reminder( [ 'provider_message_id' => 'msg-456' ] ) );

		$this->assertSame( $first_id, $second_id );
		$record = CalendarReminderRecords::get( (int) $first_id );

		$this->assertSame( CalendarReminderRecords::STATUS_SENT, $record['status'] );
		$this->assertSame( 'msg-456', $record['provider_message_id'] );

		global $wpdb;
		/** @var \wpdb $wpdb */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-only assertion.
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CalendarReminderRecords::table_name() );
		$this->assertSame( 1, $count );
	}

	/**
	 * Processed reminders can be detected before another SMS send is attempted.
	 */
	public function test_already_processed_checks_duplicate_identity(): void {
		$this->assertFalse( CalendarReminderRecords::already_processed( 'primary', 'event-123', 'person@example.com', '2026-07-01' ) );

		CalendarReminderRecords::record_skipped( $this->make_reminder( [ 'skip_reason' => 'Missing consent' ] ) );

		$this->assertTrue( CalendarReminderRecords::already_processed( 'primary', 'event-123', 'PERSON@example.com', '2026-07-01' ) );
		$this->assertFalse( CalendarReminderRecords::already_processed( 'primary', 'event-123', 'person@example.com', '2026-07-02' ) );
	}

	/**
	 * Reminder state can represent skipped, pending approval, failed, and sent outcomes.
	 */
	public function test_status_helpers_record_supported_states(): void {
		$pending = CalendarReminderRecords::record_pending_approval( $this->make_reminder( [ 'event_id' => 'pending', 'approval_request_id' => 'approval-2' ] ) );
		$skipped = CalendarReminderRecords::record_skipped( $this->make_reminder( [ 'event_id' => 'skipped', 'skip_reason' => 'No phone' ] ) );
		$failed  = CalendarReminderRecords::record_failed( $this->make_reminder( [ 'event_id' => 'failed', 'provider' => 'textbee', 'error_message' => 'Provider timeout' ] ) );
		$sent    = CalendarReminderRecords::record_sent( $this->make_reminder( [ 'event_id' => 'sent', 'provider_message_id' => 'msg-789' ] ) );

		$this->assertSame( CalendarReminderRecords::STATUS_PENDING_APPROVAL, CalendarReminderRecords::get( (int) $pending )['status'] );
		$this->assertSame( CalendarReminderRecords::STATUS_SKIPPED, CalendarReminderRecords::get( (int) $skipped )['status'] );
		$this->assertSame( CalendarReminderRecords::STATUS_FAILED, CalendarReminderRecords::get( (int) $failed )['status'] );
		$this->assertSame( CalendarReminderRecords::STATUS_SENT, CalendarReminderRecords::get( (int) $sent )['status'] );
	}
}
