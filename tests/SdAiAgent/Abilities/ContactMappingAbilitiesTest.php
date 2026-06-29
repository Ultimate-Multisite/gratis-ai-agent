<?php

declare(strict_types=1);
/**
 * Tests for contact mapping abilities.
 *
 * @package SdAiAgent\Tests\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\ContactMappingAbilities;
use SdAiAgent\Core\Database;
use SdAiAgent\Models\ContactMapping;
use WP_UnitTestCase;

/**
 * Covers attendee phone lookup ability behavior.
 */
final class ContactMappingAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Set up the custom table.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Database::DB_VERSION_OPTION );
		Database::install();
	}

	/**
	 * Contact lookup omits non-consenting mappings from sendable contacts.
	 */
	public function test_contact_phone_lookup_returns_skipped_reason_for_non_consent(): void {
		ContactMapping::create(
			array(
				'attendee_email' => 'person@example.com',
				'phone_e164'     => '+15551234567',
				'sms_consent'    => false,
			)
		);

		$result = ContactMappingAbilities::handle_contact_phone_lookup(
			array(
				'attendee_emails' => [ 'person@example.com' ],
			)
		);

		$this->assertSame( array(), $result['contacts'] );
		$this->assertSame( 'sms_consent_missing', $result['skipped'][0]['reason'] );
	}
}
