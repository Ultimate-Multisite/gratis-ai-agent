<?php

declare(strict_types=1);
/**
 * WhatsApp and Telegram messaging abilities for the AI agent.
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

class MessagingAbilities {

	/** Maximum text length, in Unicode characters, supported by both APIs. */
	public const MAX_MESSAGE_LENGTH = 4096;

	/** Meta Graph API origin used by WhatsApp Cloud API. */
	public const WHATSAPP_API_BASE_URL = 'https://graph.facebook.com';

	/** Graph API version used for WhatsApp Cloud API requests. */
	public const WHATSAPP_API_VERSION = 'v26.0';

	/** Telegram Bot API origin. */
	public const TELEGRAM_API_BASE_URL = 'https://api.telegram.org';

	/**
	 * Register messaging abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::register_send_ability(
			'sd-ai-agent/whatsapp-send',
			__( 'Send WhatsApp Message', 'superdav-ai-agent' ),
			__( 'Send a WhatsApp service text message to one or more E.164 phone numbers through the configured Meta Cloud API account. Free-form text requires an open 24-hour customer service window.', 'superdav-ai-agent' ),
			'recipients',
			'Recipient phone numbers in E.164 format, for example +15551234567.',
			[ __CLASS__, 'handle_whatsapp_send' ]
		);

		self::register_send_ability(
			'sd-ai-agent/telegram-send',
			__( 'Send Telegram Message', 'superdav-ai-agent' ),
			__( 'Send a Telegram text message to one or more chat IDs or channel usernames through the configured bot.', 'superdav-ai-agent' ),
			'chat_ids',
			'Recipient numeric chat IDs or public channel usernames beginning with @.',
			[ __CLASS__, 'handle_telegram_send' ]
		);
	}

	/**
	 * Register one provider-specific send ability.
	 *
	 * @param string   $ability_id          Ability ID.
	 * @param string   $label               Human-readable label.
	 * @param string   $description         Human-readable description.
	 * @param string   $recipient_field     Input recipient field name.
	 * @param string   $recipient_help      Input recipient field description.
	 * @param callable $execute_callback    Ability callback.
	 */
	private static function register_send_ability( string $ability_id, string $label, string $description, string $recipient_field, string $recipient_help, callable $execute_callback ): void {
		wp_register_ability(
			$ability_id,
			[
				'label'               => $label,
				'description'         => $description,
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						$recipient_field => [
							'type'        => 'array',
							'description' => $recipient_help,
							'items'       => [ 'type' => 'string' ],
						],
						'message'        => [
							'type'        => 'string',
							'description' => 'Message body to send. Maximum 4096 characters.',
						],
					],
					'required'   => [ $recipient_field, 'message' ],
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
				'execute_callback'    => $execute_callback,
				'permission_callback' => static function () use ( $ability_id ): bool {
					return ToolCapabilities::current_user_can( $ability_id );
				},
			]
		);
	}

	/**
	 * Send WhatsApp messages through Meta Cloud API.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_whatsapp_send( array $input ) {
		$validation = self::validate_input( $input, 'recipients', [ __CLASS__, 'is_e164_phone_number' ], 'whatsapp' );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$config = Settings::instance()->get_whatsapp_provider();
		if ( ! Settings::instance()->has_whatsapp_provider() ) {
			return new WP_Error( 'whatsapp_not_configured', __( 'WhatsApp Cloud API is not configured.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
		}

		$version = self::normalise_graph_api_version( (string) ( $config['api_version'] ?? self::WHATSAPP_API_VERSION ) );
		if ( is_wp_error( $version ) ) {
			return $version;
		}

		$phone_number_id = sanitize_text_field( (string) $config['phone_number_id'] );
		$url             = self::WHATSAPP_API_BASE_URL . '/' . rawurlencode( $version ) . '/' . rawurlencode( $phone_number_id ) . '/messages';
		$results         = [];

		foreach ( $validation['recipients'] as $recipient ) {
			$payload = [
				'messaging_product' => 'whatsapp',
				'recipient_type'    => 'individual',
				'to'                => $recipient,
				'type'              => 'text',
				'text'              => [ 'body' => $validation['message'] ],
			];
			$result  = self::post_json( $url, $payload, [ 'Authorization' => 'Bearer ' . (string) $config['access_token'] ], 'whatsapp' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$message_id = sanitize_text_field( (string) ( $result['body']['messages'][0]['id'] ?? '' ) );
			if ( '' === $message_id ) {
				return new WP_Error(
					'whatsapp_invalid_provider_response',
					__( 'WhatsApp returned an invalid response.', 'superdav-ai-agent' ),
					[
						'status'    => 502,
						'http_code' => $result['http_code'],
					]
				);
			}

			$results[] = [
				'recipient'  => SmsAbilities::redact_phone_number( $recipient ),
				'message_id' => $message_id,
				'http_code'  => $result['http_code'],
				'status'     => 'accepted',
			];
		}

		return [
			'success'            => true,
			'provider'           => 'meta_cloud',
			'delivery_confirmed' => false,
			'sent'               => $results,
		];
	}

	/**
	 * Send Telegram messages through the Bot API.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_telegram_send( array $input ) {
		$validation = self::validate_input( $input, 'chat_ids', [ __CLASS__, 'is_telegram_chat_id' ], 'telegram' );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$config = Settings::instance()->get_telegram_provider();
		if ( ! Settings::instance()->has_telegram_provider() ) {
			return new WP_Error( 'telegram_not_configured', __( 'Telegram Bot API is not configured.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
		}

		$encoded_token = str_replace( '%3A', ':', rawurlencode( (string) $config['bot_token'] ) );
		$url           = self::TELEGRAM_API_BASE_URL . '/bot' . $encoded_token . '/sendMessage';
		$results       = [];

		foreach ( $validation['recipients'] as $chat_id ) {
			$result = self::post_json(
				$url,
				[
					'chat_id' => $chat_id,
					'text'    => $validation['message'],
				],
				[],
				'telegram'
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( true !== ( $result['body']['ok'] ?? null ) || ! isset( $result['body']['result']['message_id'] ) ) {
				return new WP_Error(
					'telegram_invalid_provider_response',
					__( 'Telegram returned an invalid response.', 'superdav-ai-agent' ),
					[
						'status'    => 502,
						'http_code' => $result['http_code'],
					]
				);
			}

			$results[] = [
				'chat_id'    => self::redact_chat_id( $chat_id ),
				'message_id' => absint( $result['body']['result']['message_id'] ?? 0 ),
				'http_code'  => $result['http_code'],
			];
		}

		return [
			'success'  => true,
			'provider' => 'telegram_bot',
			'sent'     => $results,
		];
	}

	/**
	 * Validate common messaging input.
	 *
	 * @param array<string, mixed> $input             Ability input.
	 * @param string               $recipient_field   Recipient field name.
	 * @param callable             $recipient_checker Recipient validation callback.
	 * @param string               $error_prefix      Error-code prefix.
	 * @return array{recipients: string[], message: string}|WP_Error
	 */
	private static function validate_input( array $input, string $recipient_field, callable $recipient_checker, string $error_prefix ) {
		$recipients = $input[ $recipient_field ] ?? [];
		if ( ! is_array( $recipients ) || [] === $recipients ) {
			return new WP_Error( $error_prefix . '_invalid_recipients', sprintf( '%s must be a non-empty array.', $recipient_field ), [ 'status' => 400 ] );
		}

		$valid_recipients = [];
		foreach ( $recipients as $recipient ) {
			$value = is_scalar( $recipient ) ? trim( (string) $recipient ) : '';
			if ( ! call_user_func( $recipient_checker, $value ) ) {
				return new WP_Error( $error_prefix . '_invalid_recipient', __( 'One or more message recipients are invalid.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
			}
			$valid_recipients[] = $value;
		}

		$message = is_scalar( $input['message'] ?? null ) ? trim( (string) $input['message'] ) : '';
		if ( '' === $message ) {
			return new WP_Error( $error_prefix . '_empty_message', __( 'message is required.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
		}

		if ( mb_strlen( $message, 'UTF-8' ) > self::MAX_MESSAGE_LENGTH ) {
			return new WP_Error( $error_prefix . '_message_too_long', sprintf( 'message must be %d characters or fewer.', self::MAX_MESSAGE_LENGTH ), [ 'status' => 400 ] );
		}

		return [
			'recipients' => array_values( array_unique( $valid_recipients ) ),
			'message'    => $message,
		];
	}

	/**
	 * Validate an E.164 phone number.
	 */
	public static function is_e164_phone_number( string $phone ): bool {
		return 1 === preg_match( '/^\+[1-9][0-9]{7,14}$/', $phone );
	}

	/**
	 * Validate a Telegram numeric chat ID or public channel username.
	 */
	public static function is_telegram_chat_id( string $chat_id ): bool {
		return 1 === preg_match( '/^(?:-?[1-9][0-9]*|@[A-Za-z][A-Za-z0-9_]{4,31})$/', $chat_id );
	}

	/**
	 * Validate and normalize a Meta Graph API version.
	 *
	 * @return string|WP_Error
	 */
	public static function normalise_graph_api_version( string $version ) {
		$version = strtolower( trim( $version ) );
		if ( 1 !== preg_match( '/^v[0-9]{1,2}\.[0-9]$/', $version ) ) {
			return new WP_Error( 'whatsapp_invalid_api_version', __( 'api_version must use the Meta Graph API format, for example v26.0.', 'superdav-ai-agent' ), [ 'status' => 400 ] );
		}
		return $version;
	}

	/**
	 * Redact a Telegram destination for responses and logs.
	 */
	public static function redact_chat_id( string $chat_id ): string {
		if ( str_starts_with( $chat_id, '@' ) ) {
			return '@***' . substr( $chat_id, -3 );
		}

		return '***' . substr( $chat_id, -4 );
	}

	/**
	 * POST JSON without exposing provider response bodies or credentials in errors.
	 *
	 * @param string                $url      Provider endpoint.
	 * @param array<string, mixed>  $payload  Request body.
	 * @param array<string, string> $headers Additional headers.
	 * @param string                $provider Error-code prefix.
	 * @return array{http_code: int, body: array<int|string, mixed>}|WP_Error
	 */
	private static function post_json( string $url, array $payload, array $headers, string $provider ) {
		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			return new WP_Error( $provider . '_payload_encode_failed', __( 'Failed to encode the messaging payload.', 'superdav-ai-agent' ), [ 'status' => 500 ] );
		}

		$response = wp_remote_post(
			$url,
			[
				'timeout'     => 15,
				'blocking'    => true,
				'headers'     => array_merge( [ 'Content-Type' => 'application/json' ], $headers ),
				'body'        => $body,
				'data_format' => 'body',
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( $provider . '_provider_error', __( 'The messaging provider request failed.', 'superdav-ai-agent' ), [ 'status' => 502 ] );
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $http_code < 200 || $http_code >= 300 ) {
			return new WP_Error(
				$provider . '_provider_http_error',
				sprintf(
					/* translators: 1: provider name, 2: HTTP status code. */
					__( '%1$s returned HTTP %2$d.', 'superdav-ai-agent' ),
					ucfirst( $provider ),
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
			'http_code' => $http_code,
			'body'      => is_array( $decoded ) ? $decoded : [],
		];
	}
}
