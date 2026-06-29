<?php

declare(strict_types=1);
/**
 * Contact mapping abilities for attendee SMS reminder resolution.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Models\ContactMapping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers attendee contact lookup abilities.
 */
class ContactMappingAbilities {

	/**
	 * Register contact mapping abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/contact-phone-lookup',
			[
				'label'               => __( 'Lookup Attendee Phone Contacts', 'superdav-ai-agent' ),
				'description'         => __( 'Resolve Google Calendar attendee email addresses to consented E.164 phone numbers for SMS reminders. Non-consenting, invalid, or unmapped attendees are returned as skipped reasons.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'attendee_emails' => [
							'type'        => 'array',
							'description' => 'Google Calendar attendee email addresses to resolve.',
							'items'       => [ 'type' => 'string' ],
						],
					],
					'required'   => [ 'attendee_emails' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'contacts' => [ 'type' => 'array' ],
						'skipped'  => [ 'type' => 'array' ],
					],
				],
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_contact_phone_lookup' ],
				'permission_callback' => static function (): bool {
					return ToolCapabilities::current_user_can( 'sd-ai-agent/contact-phone-lookup' );
				},
			]
		);
	}

	/**
	 * Resolve attendee emails to consented phone mappings.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array{contacts:list<array<string,mixed>>,skipped:list<array<string,string>>}
	 */
	public static function handle_contact_phone_lookup( array $input ): array {
		$emails = $input['attendee_emails'] ?? array();
		if ( ! is_array( $emails ) ) {
			$emails = array();
		}

		$attendee_emails = array();
		foreach ( $emails as $email ) {
			$attendee_emails[] = (string) $email;
		}

		return ContactMapping::lookup_for_reminders( $attendee_emails );
	}
}
