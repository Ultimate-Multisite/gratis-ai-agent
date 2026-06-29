<?php

declare(strict_types=1);
/**
 * SMS abilities for the AI agent.
 *
 * Provides a generic SMS send ability backed by the configured TextBee gateway.
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

class SmsAbilities {

	/** Default TextBee API base URL. */
	public const DEFAULT_API_BASE_URL = 'https://api.textbee.dev';

	/** Maximum SMS message length accepted by the agent. */
	public const MAX_MESSAGE_LENGTH = 1600;

	/** Redacted placeholder for sensitive values. */
	private const REDACTED = '[redacted]';

	/**
	 * Register SMS abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/sms-send',
			[
				'label'               => __( 'Send SMS', 'superdav-ai-agent' ),
				'description'         => __( 'Send an SMS message to one or more E.164 phone numbers through the configured TextBee SMS gateway.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'recipients' => [
							'type'        => 'array',
							'description' => 'Recipient phone numbers in E.164 format, for example +15551234567.',
							'items'       => [ 'type' => 'string' ],
						],
						'message'    => [
							'type'        => 'string',
							'description' => 'Message body to send. Maximum 1600 characters.',
						],
					],
					'required'   => [ 'recipients', 'message' ],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_sms_send' ],
				'permission_callback' => static function (): bool {
					return ToolCapabilities::current_user_can( 'sd-ai-agent/sms-send' );
				},
			]
		);
	}

	/**
	 * Send an SMS via the configured provider.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_sms_send( array $input ) {
		$validation = self::validate_send_input( $input );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$config = Settings::instance()->get_sms_provider();
		if ( 'textbee' !== ( $config['provider'] ?? '' ) || empty( $config['api_key'] ) || empty( $config['device_id'] ) ) {
			return new WP_Error(
				'sms_not_configured',
				__( 'TextBee SMS provider is not configured.', 'superdav-ai-agent' ),
				[ 'status' => 400 ]
			);
		}

		$api_base_url = self::normalise_api_base_url( (string) ( $config['api_base_url'] ?? self::DEFAULT_API_BASE_URL ) );
		if ( is_wp_error( $api_base_url ) ) {
			return $api_base_url;
		}

		/** @var string $api_base_url */
		/** @var array{recipients: string[], message: string} $validation */
		return self::send_textbee_sms( $config, $api_base_url, $validation['recipients'], $validation['message'] );
	}

	/**
	 * Validate ability input.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array{recipients: string[], message: string}|WP_Error
	 */
	private static function validate_send_input( array $input ) {
		$recipients = $input['recipients'] ?? array();
		if ( ! is_array( $recipients ) || array() === $recipients ) {
			return new WP_Error( 'sms_invalid_recipients', __( 'recipients must be a non-empty array.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
		}

		$valid_recipients = array();
		foreach ( $recipients as $recipient ) {
			$phone = is_scalar( $recipient ) ? trim( (string) $recipient ) : '';
			if ( ! self::is_e164_phone_number( $phone ) ) {
				return new WP_Error( 'sms_invalid_recipient', __( 'All recipients must be valid E.164 phone numbers.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
			}

			$valid_recipients[] = $phone;
		}

		$message = is_scalar( $input['message'] ?? null ) ? trim( (string) $input['message'] ) : '';
		if ( '' === $message ) {
			return new WP_Error( 'sms_empty_message', __( 'message is required.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
		}

		if ( strlen( $message ) > self::MAX_MESSAGE_LENGTH ) {
			return new WP_Error(
				'sms_message_too_long',
				sprintf(
					/* translators: %d: maximum SMS message length. */
					__( 'message must be %d characters or fewer.', 'superdav-ai-agent' ),
					self::MAX_MESSAGE_LENGTH
				),
				[ 'status' => 400 ]
			);
		}

		return [
			'recipients' => $valid_recipients,
			'message'    => $message,
		];
	}

	/**
	 * Validate E.164-ish phone number format.
	 *
	 * @param string $phone Phone number.
	 * @return bool
	 */
	private static function is_e164_phone_number( string $phone ): bool {
		return 1 === preg_match( '/^\+[1-9][0-9]{7,14}$/', $phone );
	}

	/**
	 * Normalise and validate the configured API base URL.
	 *
	 * @param string $api_base_url API base URL.
	 * @return string|WP_Error
	 */
	public static function normalise_api_base_url( string $api_base_url ) {
		$api_base_url = untrailingslashit( trim( $api_base_url ) );
		$scheme       = wp_parse_url( $api_base_url, PHP_URL_SCHEME );
		$host         = wp_parse_url( $api_base_url, PHP_URL_HOST );

		if ( ! is_string( $scheme ) || ! is_string( $host ) || ! in_array( strtolower( $scheme ), [ 'http', 'https' ], true ) ) {
			return new WP_Error( 'sms_invalid_api_base_url', __( 'api_base_url must be a valid HTTP or HTTPS URL.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
		}

		return esc_url_raw( $api_base_url );
	}

	/**
	 * Send the TextBee HTTP request.
	 *
	 * @param array<string, mixed> $config       SMS provider configuration.
	 * @param string               $api_base_url Normalised API base URL.
	 * @param string[]             $recipients   Recipient phone numbers.
	 * @param string               $message      Message body.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function send_textbee_sms( array $config, string $api_base_url, array $recipients, string $message ) {
		$device_id = sanitize_text_field( (string) ( $config['device_id'] ?? '' ) );
		$api_key   = (string) ( $config['api_key'] ?? '' );
		$url       = $api_base_url . '/api/v1/gateway/devices/' . rawurlencode( $device_id ) . '/send-sms';
		$body      = wp_json_encode(
			[
				'recipients' => $recipients,
				'message'    => $message,
			]
		);

		if ( false === $body ) {
			return new WP_Error( 'sms_payload_encode_failed', __( 'Failed to encode SMS payload.', 'superdav-ai-agent' ), [ 'status' => 500 ] );
		}

		$response = wp_remote_post(
			$url,
			[
				'timeout'     => 15,
				'blocking'    => true,
				'headers'     => [
					'Content-Type' => 'application/json',
					'x-api-key'    => $api_key,
				],
				'body'        => $body,
				'data_format' => 'body',
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'sms_provider_error', $response->get_error_message(), [ 'status' => 502 ] );
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $http_code < 200 || $http_code >= 300 ) {
			return new WP_Error(
				'sms_provider_http_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'TextBee returned HTTP %d.', 'superdav-ai-agent' ),
					$http_code
				),
				[
					'status'    => 502,
					'http_code' => $http_code,
				]
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return [
			'success'           => true,
			'provider'          => 'textbee',
			'http_code'         => $http_code,
			'recipients'        => array_map( [ __CLASS__, 'redact_phone_number' ], $recipients ),
			'provider_response' => is_array( $decoded ) ? self::redact_provider_response( $decoded ) : null,
		];
	}

	/**
	 * Redact a phone number for responses and logs.
	 *
	 * @param string $phone Phone number.
	 * @return string
	 */
	public static function redact_phone_number( string $phone ): string {
		$last_four = substr( $phone, -4 );
		return '+*******' . $last_four;
	}

	/**
	 * Recursively redact sensitive provider response values.
	 *
	 * @param array<string|int, mixed> $response Provider response.
	 * @return array<string|int, mixed>
	 */
	private static function redact_provider_response( array $response ): array {
		foreach ( $response as $key => $value ) {
			$key_string = is_string( $key ) ? strtolower( $key ) : '';
			if ( in_array( $key_string, [ 'api_key', 'api-key', 'x-api-key', 'authorization', 'token', 'secret' ], true ) ) {
				$response[ $key ] = self::REDACTED;
				continue;
			}

			if ( is_array( $value ) ) {
				$response[ $key ] = self::redact_provider_response( $value );
			}
		}

		return $response;
	}
}
