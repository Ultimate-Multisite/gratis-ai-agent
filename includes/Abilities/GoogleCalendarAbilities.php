<?php

declare(strict_types=1);
/**
 * Google Calendar read-only abilities for the AI agent.
 *
 * Provides normalized, structured calendar data via OAuth2 refresh-token
 * credentials stored separately from general settings.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Core\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GoogleCalendarAbilities {

	private const TOKEN_URL             = 'https://oauth2.googleapis.com/token';
	private const API_BASE              = 'https://www.googleapis.com/calendar/v3';
	private const SETUP_REQUIRED_STATUS = 412;

	/**
	 * Register Google Calendar abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::register_list_events();
		self::register_get_event();
		self::register_list_calendars();
	}

	/**
	 * Register the list events ability.
	 */
	private static function register_list_events(): void {
		wp_register_ability(
			'sd-ai-agent/google-calendar-list-events',
			[
				'label'               => __( 'Google Calendar List Events', 'superdav-ai-agent' ),
				'description'         => __( 'List upcoming Google Calendar events in a normalized, read-only shape.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'calendar_id' => [ 'type' => 'string' ],
						'time_min'    => [ 'type' => 'string' ],
						'time_max'    => [ 'type' => 'string' ],
						'limit'       => [ 'type' => 'integer' ],
					],
					'required'   => [],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_list_events' ],
				'permission_callback' => static fn(): bool => ToolCapabilities::current_user_can( 'sd-ai-agent/google-calendar-list-events' ),
			]
		);
	}

	/** Register the get event ability. */
	private static function register_get_event(): void {
		wp_register_ability(
			'sd-ai-agent/google-calendar-get-event',
			[
				'label'               => __( 'Google Calendar Get Event', 'superdav-ai-agent' ),
				'description'         => __( 'Fetch one Google Calendar event by ID in a normalized, read-only shape.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'calendar_id' => [ 'type' => 'string' ],
						'event_id'    => [ 'type' => 'string' ],
					],
					'required'   => [ 'event_id' ],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_get_event' ],
				'permission_callback' => static fn(): bool => ToolCapabilities::current_user_can( 'sd-ai-agent/google-calendar-get-event' ),
			]
		);
	}

	/** Register the list calendars ability. */
	private static function register_list_calendars(): void {
		wp_register_ability(
			'sd-ai-agent/google-calendar-list-calendars',
			[
				'label'               => __( 'Google Calendar List Calendars', 'superdav-ai-agent' ),
				'description'         => __( 'List Google Calendar calendars available to the configured OAuth account.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [],
					'required'   => [],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_list_calendars' ],
				'permission_callback' => static fn(): bool => ToolCapabilities::current_user_can( 'sd-ai-agent/google-calendar-list-calendars' ),
			]
		);
	}

	/**
	 * List events.
	 *
	 * @param array<string, mixed> $input Input arguments.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_list_events( array $input = [] ): array|WP_Error {
		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$calendar_id = self::resolve_calendar_id( $input );
		$limit       = max( 1, min( 50, (int) ( $input['limit'] ?? 10 ) ) );
		$time_min    = sanitize_text_field( (string) ( $input['time_min'] ?? gmdate( 'c' ) ) );
		$time_max    = sanitize_text_field( (string) ( $input['time_max'] ?? gmdate( 'c', strtotime( '+1 day' ) ) ) );

		$response = self::api_get(
			$token,
			'/calendars/' . rawurlencode( $calendar_id ) . '/events',
			[
				'singleEvents' => 'true',
				'orderBy'      => 'startTime',
				'maxResults'   => (string) $limit,
				'timeMin'      => $time_min,
				'timeMax'      => $time_max,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$items = is_array( $response['items'] ?? null ) ? $response['items'] : [];

		return [
			'calendar_id' => $calendar_id,
			'time_min'    => $time_min,
			'time_max'    => $time_max,
			'events'      => array_map( static fn( mixed $event ): array => self::normalize_event( is_array( $event ) ? $event : [], $calendar_id ), $items ),
		];
	}

	/**
	 * Get one event.
	 *
	 * @param array<string, mixed> $input Input arguments.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_get_event( array $input = [] ): array|WP_Error {
		$event_id = sanitize_text_field( (string) ( $input['event_id'] ?? '' ) );
		if ( '' === $event_id ) {
			return new WP_Error( 'google_calendar_missing_event_id', __( 'event_id is required.', 'superdav-ai-agent' ) );
		}

		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$calendar_id = self::resolve_calendar_id( $input );
		$response    = self::api_get( $token, '/calendars/' . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $event_id ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return self::normalize_event( $response, $calendar_id );
	}

	/**
	 * List calendars.
	 *
	 * @param array<string, mixed> $input Input arguments.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_list_calendars( array $input = [] ): array|WP_Error {
		unset( $input );
		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = self::api_get( $token, '/users/me/calendarList' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$items = is_array( $response['items'] ?? null ) ? $response['items'] : [];

		return [
			'calendars' => array_map(
				static function ( mixed $calendar ): array {
					$calendar = is_array( $calendar ) ? $calendar : [];
					return [
						'id'          => (string) ( $calendar['id'] ?? '' ),
						'summary'     => (string) ( $calendar['summary'] ?? '' ),
						'timezone'    => (string) ( $calendar['timeZone'] ?? '' ),
						'primary'     => (bool) ( $calendar['primary'] ?? false ),
						'access_role' => (string) ( $calendar['accessRole'] ?? '' ),
					];
				},
				$items
			),
		];
	}

	/**
	 * Resolve an access token from OAuth2 refresh-token credentials.
	 *
	 * @return string|WP_Error
	 */
	private static function get_access_token(): string|WP_Error {
		$creds = Settings::instance()->get_google_calendar_credentials();
		if ( empty( $creds ) || empty( $creds['type'] ) ) {
			return new WP_Error( 'google_calendar_not_configured', __( 'Google Calendar credentials are not configured.', 'superdav-ai-agent' ), [ 'status' => self::SETUP_REQUIRED_STATUS ] );
		}

		if ( 'oauth2_refresh_token' !== $creds['type'] ) {
			return new WP_Error( 'google_calendar_unknown_type', __( 'Unknown Google Calendar credential type.', 'superdav-ai-agent' ) );
		}

		$client_id     = (string) ( $creds['client_id'] ?? '' );
		$client_secret = (string) ( $creds['client_secret'] ?? '' );
		$refresh_token = (string) ( $creds['refresh_token'] ?? '' );
		if ( '' === $client_id || '' === $client_secret || '' === $refresh_token ) {
			return new WP_Error( 'google_calendar_invalid_credentials', __( 'Google Calendar OAuth2 credentials are incomplete.', 'superdav-ai-agent' ) );
		}

		$cache_key = 'sd_google_calendar_token_' . substr(
			hash_hmac( 'sha256', $client_id . ':' . $refresh_token, wp_salt( 'auth' ) ),
			0,
			24
		);
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			[
				'timeout' => 15,
				'body'    => [
					'grant_type'    => 'refresh_token',
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'refresh_token' => $refresh_token,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'google_calendar_token_request_failed', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			$error = is_array( $body ) ? (string) ( $body['error_description'] ?? $body['error'] ?? 'Unknown error' ) : 'Unknown error';
			/* translators: %s: error returned by Google OAuth token endpoint. */
			return new WP_Error( 'google_calendar_token_error', sprintf( __( 'Google Calendar token refresh failed: %s', 'superdav-ai-agent' ), $error ) );
		}

		$access_token = (string) $body['access_token'];
		$expires_in   = (int) ( $body['expires_in'] ?? 3600 );
		set_transient( $cache_key, $access_token, max( 60, $expires_in - 300 ) );

		return $access_token;
	}

	/**
	 * Execute a Google Calendar GET request.
	 *
	 * @param string               $access_token Access token.
	 * @param string               $path         API path.
	 * @param array<string,string> $query        Query arguments.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function api_get( string $access_token, string $path, array $query = [] ): array|WP_Error {
		$url = self::API_BASE . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout' => 20,
				'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'google_calendar_api_request_failed', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			$error = is_array( $body ) ? (string) ( $body['error']['message'] ?? 'Unknown Google Calendar API error' ) : 'Unknown Google Calendar API error';
			/* translators: 1: HTTP response code, 2: error message returned by Google Calendar API. */
			return new WP_Error( 'google_calendar_api_error', sprintf( __( 'Google Calendar API error (%1$d): %2$s', 'superdav-ai-agent' ), $code, $error ) );
		}

		return self::normalize_assoc_array( $body );
	}

	/**
	 * Normalize decoded JSON into a string-keyed array for PHPStan and callers.
	 *
	 * @param array<mixed, mixed> $body Decoded JSON body.
	 * @return array<string, mixed>
	 */
	private static function normalize_assoc_array( array $body ): array {
		$normalized = [];
		foreach ( $body as $key => $value ) {
			$normalized[ (string) $key ] = $value;
		}

		return $normalized;
	}

	/**
	 * Resolve requested or stored calendar ID.
	 *
	 * @param array<string, mixed> $input Input arguments.
	 * @return string
	 */
	private static function resolve_calendar_id( array $input ): string {
		$calendar_id = sanitize_text_field( (string) ( $input['calendar_id'] ?? '' ) );
		if ( '' !== $calendar_id ) {
			return $calendar_id;
		}

		$creds  = Settings::instance()->get_google_calendar_credentials();
		$stored = sanitize_text_field( (string) ( $creds['default_calendar_id'] ?? '' ) );
		return '' !== $stored ? $stored : 'primary';
	}

	/**
	 * Normalize one Google Calendar event.
	 *
	 * Event text is returned only as structured fields so downstream prompts can
	 * treat it as untrusted external content instead of instructions.
	 *
	 * @param array<string, mixed> $event       Raw event.
	 * @param string               $calendar_id Calendar ID.
	 * @return array<string, mixed>
	 */
	private static function normalize_event( array $event, string $calendar_id ): array {
		$start = is_array( $event['start'] ?? null ) ? $event['start'] : [];
		$end   = is_array( $event['end'] ?? null ) ? $event['end'] : [];

		return [
			'calendar_id'    => $calendar_id,
			'event_id'       => (string) ( $event['id'] ?? '' ),
			'status'         => (string) ( $event['status'] ?? '' ),
			'summary'        => (string) ( $event['summary'] ?? '' ),
			'description'    => (string) ( $event['description'] ?? '' ),
			'start'          => self::normalize_event_time( $start ),
			'end'            => self::normalize_event_time( $end ),
			'timezone'       => (string) ( $start['timeZone'] ?? $end['timeZone'] ?? '' ),
			'location'       => (string) ( $event['location'] ?? '' ),
			'meeting_link'   => self::extract_meeting_link( $event ),
			'attendees'      => self::normalize_attendees( self::normalize_list_array( is_array( $event['attendees'] ?? null ) ? $event['attendees'] : [] ) ),
			'untrusted_text' => true,
		];
	}

	/**
	 * Normalize a decoded JSON list to integer keys.
	 *
	 * @param array<mixed> $items Raw list.
	 * @return array<int, mixed>
	 */
	private static function normalize_list_array( array $items ): array {
		return array_values( $items );
	}

	/**
	 * Normalize event date/time payload.
	 *
	 * @param array<string, mixed> $time Raw time data.
	 * @return array<string, string>
	 */
	private static function normalize_event_time( array $time ): array {
		return [
			'datetime' => (string) ( $time['dateTime'] ?? $time['date'] ?? '' ),
			'timezone' => (string) ( $time['timeZone'] ?? '' ),
		];
	}

	/**
	 * Extract a meeting link.
	 *
	 * @param array<string, mixed> $event Raw event.
	 * @return string
	 */
	private static function extract_meeting_link( array $event ): string {
		if ( ! empty( $event['hangoutLink'] ) ) {
			return (string) $event['hangoutLink'];
		}

		$conference = is_array( $event['conferenceData'] ?? null ) ? $event['conferenceData'] : [];
		$points     = is_array( $conference['entryPoints'] ?? null ) ? $conference['entryPoints'] : [];
		foreach ( $points as $point ) {
			if ( is_array( $point ) && 'video' === ( $point['entryPointType'] ?? '' ) && ! empty( $point['uri'] ) ) {
				return (string) $point['uri'];
			}
		}

		return '';
	}

	/**
	 * Normalize attendees.
	 *
	 * @param array<int, mixed> $attendees Raw attendees.
	 * @return array<int, array<string, string>>
	 */
	private static function normalize_attendees( array $attendees ): array {
		$normalized = [];
		foreach ( $attendees as $attendee ) {
			if ( ! is_array( $attendee ) ) {
				continue;
			}

			$normalized[] = [
				'email'           => (string) ( $attendee['email'] ?? '' ),
				'display_name'    => (string) ( $attendee['displayName'] ?? '' ),
				'response_status' => (string) ( $attendee['responseStatus'] ?? '' ),
			];
		}

		return $normalized;
	}
}
