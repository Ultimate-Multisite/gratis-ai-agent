<?php

declare(strict_types=1);
/**
 * Tests for attendee contact mappings.
 *
 * @package SdAiAgent\Tests\Models
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Models;

use SdAiAgent\Core\Database;
use SdAiAgent\Models\ContactMapping;
use WP_UnitTestCase;

/**
 * Covers contact mapping validation and lookup behavior.
 */
final class ContactMappingTest extends WP_UnitTestCase {

	/**
	 * Set up the custom table.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Database::DB_VERSION_OPTION );
		Database::install();
	}

	/**
	 * Tear down custom rows.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test cleanup for plugin-owned table.
		$wpdb->query( 'TRUNCATE TABLE ' . Database::contact_mappings_table_name() );
		parent::tear_down();
	}

	/**
	 * Create normalizes email addresses and preserves E.164 phone numbers.
	 */
	public function test_create_normalizes_email_and_phone(): void {
		$contact = ContactMapping::create(
			array(
				'attendee_email' => ' Person@Example.COM ',
				'phone_e164'     => '+15551234567',
				'sms_consent'    => true,
				'display_name'   => 'Person',
			)
		);

		$this->assertIsArray( $contact );
		$this->assertSame( 'person@example.com', $contact['attendee_email'] );
		$this->assertSame( '+15551234567', $contact['phone_e164'] );
		$this->assertTrue( $contact['sms_consent'] );
	}

	/**
	 * Invalid email addresses are rejected.
	 */
	public function test_invalid_email_is_rejected(): void {
		$result = ContactMapping::create(
			array(
				'attendee_email' => 'not an email',
				'phone_e164'     => '+15551234567',
				'sms_consent'    => true,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_contact_invalid_email', $result->get_error_code() );
	}

	/**
	 * Invalid phone numbers are rejected.
	 */
	public function test_invalid_phone_is_rejected(): void {
		$result = ContactMapping::create(
			array(
				'attendee_email' => 'person@example.com',
				'phone_e164'     => '555-1234',
				'sms_consent'    => true,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_contact_invalid_phone', $result->get_error_code() );
	}

	/**
	 * Duplicate attendee emails are rejected by the unique key.
	 */
	public function test_duplicate_email_is_rejected(): void {
		ContactMapping::create(
			array(
				'attendee_email' => 'person@example.com',
				'phone_e164'     => '+15551234567',
				'sms_consent'    => true,
			)
		);

		$duplicate = ContactMapping::create(
			array(
				'attendee_email' => 'PERSON@example.com',
				'phone_e164'     => '+15557654321',
				'sms_consent'    => true,
			)
		);

		$this->assertWPError( $duplicate );
		$this->assertSame( 'sd_ai_agent_contact_duplicate', $duplicate->get_error_code() );
	}

	/**
	 * Lookup returns consented contacts and deterministic skipped reasons.
	 */
	public function test_lookup_requires_sms_consent(): void {
		ContactMapping::create(
			array(
				'attendee_email' => 'yes@example.com',
				'phone_e164'     => '+15551234567',
				'sms_consent'    => true,
			)
		);
		ContactMapping::create(
			array(
				'attendee_email' => 'no@example.com',
				'phone_e164'     => '+15557654321',
				'sms_consent'    => false,
			)
		);

		$result = ContactMapping::lookup_for_reminders( [ 'YES@example.com', 'no@example.com', 'missing@example.com', 'bad email' ] );

		$this->assertSame( [ 'yes@example.com' ], wp_list_pluck( $result['contacts'], 'attendee_email' ) );
		$this->assertSame( '+15551234567', $result['contacts'][0]['phone_e164'] );
		$this->assertSame( [ 'sms_consent_missing', 'not_mapped', 'invalid_email' ], wp_list_pluck( $result['skipped'], 'reason' ) );
	}
}
