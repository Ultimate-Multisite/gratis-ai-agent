<?php

declare(strict_types=1);
/**
 * Attendee email to SMS contact mapping model.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Models;

use SdAiAgent\Core\Database;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD and validation helpers for attendee phone mappings.
 */
class ContactMapping {

	/** E.164-ish phone pattern: + followed by 8-15 digits, first digit non-zero. */
	private const PHONE_PATTERN = '/^\+[1-9][0-9]{7,14}$/';

	/**
	 * Normalize and validate an attendee email address.
	 */
	public static function normalize_email( string $email ): string|WP_Error {
		$normalized = strtolower( sanitize_email( $email ) );
		if ( '' === $normalized || ! is_email( $normalized ) ) {
			return new WP_Error( 'sd_ai_agent_contact_invalid_email', __( 'A valid attendee email is required.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
		}

		return $normalized;
	}

	/**
	 * Normalize and validate an E.164-ish phone number.
	 */
	public static function normalize_phone( string $phone ): string|WP_Error {
		$normalized = preg_replace( '/[^0-9+]/', '', trim( $phone ) );
		$normalized = is_string( $normalized ) ? $normalized : '';

		if ( ! preg_match( self::PHONE_PATTERN, $normalized ) ) {
			return new WP_Error( 'sd_ai_agent_contact_invalid_phone', __( 'A valid E.164 phone number is required.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
		}

		return $normalized;
	}

	/**
	 * Create a mapping.
	 *
	 * @param array<string, mixed> $data Raw mapping data.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;

		$prepared = self::prepare_data( $data, true );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		if ( is_array( self::get_by_email( (string) $prepared['attendee_email'] ) ) ) {
			return new WP_Error( 'sd_ai_agent_contact_duplicate', __( 'A contact mapping already exists for this attendee email.', 'superdav-ai-agent' ), array( 'status' => 409 ) );
		}

		$now                    = current_time( 'mysql' );
		$prepared['created_at'] = $now;
		$prepared['updated_at'] = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table CRUD.
		$inserted = $wpdb->insert( Database::contact_mappings_table_name(), $prepared, self::formats_for( $prepared ) );
		if ( false === $inserted ) {
			return new WP_Error( 'sd_ai_agent_contact_duplicate', __( 'A contact mapping already exists for this attendee email.', 'superdav-ai-agent' ), array( 'status' => 409 ) );
		}

		$contact = self::get( (int) $wpdb->insert_id );
		if ( null === $contact ) {
			return new WP_Error( 'sd_ai_agent_contact_create_failed', __( 'Failed to create contact mapping.', 'superdav-ai-agent' ), array( 'status' => 500 ) );
		}

		return $contact;
	}

	/**
	 * Update a mapping.
	 *
	 * @param int                  $id   Mapping ID.
	 * @param array<string, mixed> $data Raw mapping data.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		if ( $id <= 0 || null === self::get( $id ) ) {
			return new WP_Error( 'sd_ai_agent_contact_not_found', __( 'Contact mapping not found.', 'superdav-ai-agent' ), array( 'status' => 404 ) );
		}

		$prepared = self::prepare_data( $data, false );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		if ( [] === $prepared ) {
			return self::get( $id );
		}

		$prepared['updated_at'] = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table CRUD.
		$updated = $wpdb->update( Database::contact_mappings_table_name(), $prepared, array( 'id' => $id ), self::formats_for( $prepared ), array( '%d' ) );
		if ( false === $updated ) {
			return new WP_Error( 'sd_ai_agent_contact_duplicate', __( 'A contact mapping already exists for this attendee email.', 'superdav-ai-agent' ), array( 'status' => 409 ) );
		}

		return self::get( $id );
	}

	/**
	 * Get a mapping by ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table CRUD.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Database::contact_mappings_table_name(), $id ), ARRAY_A );

		return is_array( $row ) ? self::format_row( $row ) : null;
	}

	/**
	 * Get a mapping by normalized email.
	 *
	 * @return array<string, mixed>|null|WP_Error
	 */
	public static function get_by_email( string $email ) {
		global $wpdb;

		$normalized = self::normalize_email( $email );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table CRUD.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE attendee_email = %s', Database::contact_mappings_table_name(), $normalized ), ARRAY_A );

		return is_array( $row ) ? self::format_row( $row ) : null;
	}

	/**
	 * List mappings.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function list( int $limit = 100, int $offset = 0 ): array {
		global $wpdb;

		$limit  = max( 1, min( 500, $limit ) );
		$offset = max( 0, $offset );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table CRUD.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY attendee_email ASC LIMIT %d OFFSET %d', Database::contact_mappings_table_name(), $limit, $offset ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$contacts = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$contacts[] = self::format_row( $row );
			}
		}

		return $contacts;
	}

	/**
	 * Delete a mapping.
	 */
	public static function delete( int $id ): bool|WP_Error {
		global $wpdb;

		if ( $id <= 0 || null === self::get( $id ) ) {
			return new WP_Error( 'sd_ai_agent_contact_not_found', __( 'Contact mapping not found.', 'superdav-ai-agent' ), array( 'status' => 404 ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table CRUD.
		return false !== $wpdb->delete( Database::contact_mappings_table_name(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Prepare a deterministic lookup response for attendee emails.
	 *
	 * @param string[] $emails Attendee emails.
	 * @return array{contacts:list<array<string,mixed>>,skipped:list<array<string,string>>}
	 * @phpstan-param list<string> $emails
	 */
	public static function lookup_for_reminders( array $emails ): array {
		$contacts = array();
		$skipped  = array();
		$seen     = array();

		foreach ( $emails as $email ) {
			$normalized = self::normalize_email( (string) $email );
			if ( is_wp_error( $normalized ) ) {
				$skipped[] = array(
					'attendee_email' => (string) $email,
					'reason'         => 'invalid_email',
				);
				continue;
			}

			if ( isset( $seen[ $normalized ] ) ) {
				continue;
			}
			$seen[ $normalized ] = true;

			$mapping = self::get_by_email( $normalized );
			if ( ! is_array( $mapping ) ) {
				$skipped[] = array(
					'attendee_email' => $normalized,
					'reason'         => 'not_mapped',
				);
				continue;
			}

			if ( true !== $mapping['sms_consent'] ) {
				$skipped[] = array(
					'attendee_email' => $normalized,
					'reason'         => 'sms_consent_missing',
				);
				continue;
			}

			$contacts[] = array(
				'attendee_email' => $normalized,
				'phone_e164'     => (string) $mapping['phone_e164'],
				'sms_consent'    => true,
				'display_name'   => (string) $mapping['display_name'],
			);
		}

		return array(
			'contacts' => $contacts,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Prepare write data.
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function prepare_data( array $data, bool $require_all ) {
		$prepared = array();

		if ( $require_all || array_key_exists( 'attendee_email', $data ) ) {
			$email = self::normalize_email( (string) ( $data['attendee_email'] ?? '' ) );
			if ( is_wp_error( $email ) ) {
				return $email;
			}
			$prepared['attendee_email'] = $email;
		}

		if ( $require_all || array_key_exists( 'phone_e164', $data ) ) {
			$phone = self::normalize_phone( (string) ( $data['phone_e164'] ?? '' ) );
			if ( is_wp_error( $phone ) ) {
				return $phone;
			}
			$prepared['phone_e164'] = $phone;
		}

		if ( $require_all || array_key_exists( 'sms_consent', $data ) ) {
			$prepared['sms_consent'] = wp_validate_boolean( $data['sms_consent'] ?? false ) ? 1 : 0;
		}

		foreach ( [ 'display_name', 'source' ] as $field ) {
			if ( $require_all || array_key_exists( $field, $data ) ) {
				$prepared[ $field ] = sanitize_text_field( (string) ( $data[ $field ] ?? '' ) );
			}
		}

		if ( $require_all || array_key_exists( 'notes', $data ) ) {
			$prepared['notes'] = sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) );
		}

		return $prepared;
	}

	/**
	 * Format a database row for REST/ability output.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function format_row( array $row ): array {
		return array(
			'id'             => (int) $row['id'],
			'attendee_email' => (string) $row['attendee_email'],
			'phone_e164'     => (string) $row['phone_e164'],
			'sms_consent'    => (bool) $row['sms_consent'],
			'display_name'   => (string) $row['display_name'],
			'source'         => (string) $row['source'],
			'notes'          => (string) $row['notes'],
			'created_at'     => (string) $row['created_at'],
			'updated_at'     => (string) $row['updated_at'],
		);
	}

	/**
	 * Build wpdb format list for a data array.
	 *
	 * @param array<string, mixed> $data Prepared data.
	 * @return list<string>
	 */
	private static function formats_for( array $data ): array {
		$formats = array();
		foreach ( array_keys( $data ) as $key ) {
			$formats[] = 'sms_consent' === $key ? '%d' : '%s';
		}

		return $formats;
	}
}
