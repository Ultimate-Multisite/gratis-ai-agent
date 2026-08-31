<?php
/**
 * Tests for WhatsApp and Telegram messaging abilities.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\MessagingAbilities;
use SdAiAgent\Core\Settings;
use WP_Error;
use WP_UnitTestCase;

class MessagingAbilitiesTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Settings::instance()->set_whatsapp_provider( [] );
		Settings::instance()->set_telegram_provider( [] );
		remove_all_filters( 'pre_http_request' );
	}

	public function tearDown(): void {
		Settings::instance()->set_whatsapp_provider( [] );
		Settings::instance()->set_telegram_provider( [] );
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	public function test_whatsapp_requires_configuration(): void {
		$result = MessagingAbilities::handle_whatsapp_send( [ 'recipients' => [ '+15551234567' ], 'message' => 'Hello' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'whatsapp_not_configured', $result->get_error_code() );
	}

	public function test_whatsapp_rejects_invalid_recipient(): void {
		$result = MessagingAbilities::handle_whatsapp_send( [ 'recipients' => [ '555-1234' ], 'message' => 'Hello' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'whatsapp_invalid_recipient', $result->get_error_code() );
	}

	public function test_whatsapp_sends_scrubbed_message(): void {
		Settings::instance()->set_whatsapp_provider(
			[
				'provider'        => 'meta_cloud',
				'access_token'    => 'meta-secret-token',
				'phone_number_id' => '1234567890',
				'api_version'     => MessagingAbilities::WHATSAPP_API_VERSION,
			]
		);
		$captured_url     = '';
		$captured_args    = [];
		$captured_payload = [];
		add_filter(
			'pre_http_request',
			static function ( mixed $preempt, array $args, string $url ) use ( &$captured_url, &$captured_args, &$captured_payload ): array {
				$captured_url     = $url;
				$captured_args    = $args;
				$captured_payload = json_decode( (string) $args['body'], true );
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => '{"messages":[{"id":"wamid.123"}]}',
				];
			},
			10,
			3
		);

		$result = MessagingAbilities::handle_whatsapp_send( [ 'recipients' => [ '+15551234567' ], 'message' => 'Hello' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://graph.facebook.com/' . MessagingAbilities::WHATSAPP_API_VERSION . '/1234567890/messages', $captured_url );
		$this->assertSame( 'Bearer meta-secret-token', $captured_args['headers']['Authorization'] ?? '' );
		$this->assertSame( 'individual', $captured_payload['recipient_type'] ?? '' );
		$this->assertSame( '+15551234567', $captured_payload['to'] ?? '' );
		$this->assertSame( '+*******4567', $result['sent'][0]['recipient'] ?? '' );
		$this->assertSame( 'wamid.123', $result['sent'][0]['message_id'] ?? '' );
		$this->assertSame( 'accepted', $result['sent'][0]['status'] ?? '' );
		$this->assertFalse( $result['delivery_confirmed'] ?? true );
		$this->assertStringNotContainsString( 'meta-secret-token', wp_json_encode( $result ) ?: '' );
	}

	/** Uncertain WhatsApp requests retain all unconfirmed recipients without enabling retries. */
	public function test_whatsapp_transport_error_marks_remaining_recipients_unknown(): void {
		Settings::instance()->set_whatsapp_provider(
			[
				'provider'        => 'meta_cloud',
				'access_token'    => 'meta-secret-token',
				'phone_number_id' => '1234567890',
				'api_version'     => MessagingAbilities::WHATSAPP_API_VERSION,
			]
		);
		$requestCount = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$requestCount ): array|WP_Error {
				++$requestCount;
				if ( 1 === $requestCount ) {
					return [
						'response' => [ 'code' => 200, 'message' => 'OK' ],
						'body'     => '{"messages":[{"id":"wamid.123"}]}',
					];
				}

				return new WP_Error( 'http_request_failed', 'Connection timed out' );
			}
		);

		$result = MessagingAbilities::handle_whatsapp_send( [ 'recipients' => [ '+15551234567', '+15557654321', '+15559876543' ], 'message' => 'Hello' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 2, $requestCount );
		$errorData = $result->get_error_data();
		$this->assertSame( '+*******4567', $errorData['sent'][0]['recipient'] ?? '' );
		$this->assertSame( '+*******4321', $errorData['unconfirmed'][0]['recipient'] ?? '' );
		$this->assertSame( '+*******6543', $errorData['unconfirmed'][1]['recipient'] ?? '' );
		$this->assertSame( 'unknown', $errorData['unconfirmed'][0]['status'] ?? '' );
		$this->assertStringNotContainsString( '+15557654321', wp_json_encode( $errorData ) ?: '' );
		$this->assertStringNotContainsString( '+15559876543', wp_json_encode( $errorData ) ?: '' );
	}

	/** A successful response without a provider message ID is an unknown delivery state. */
	public function test_whatsapp_missing_message_id_marks_recipient_unknown(): void {
		Settings::instance()->set_whatsapp_provider(
			[
				'provider'        => 'meta_cloud',
				'access_token'    => 'meta-secret-token',
				'phone_number_id' => '1234567890',
				'api_version'     => MessagingAbilities::WHATSAPP_API_VERSION,
			]
		);
		add_filter(
			'pre_http_request',
			static function (): array {
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => '{"messages":[]}',
				];
			}
		);

		$result = MessagingAbilities::handle_whatsapp_send( [ 'recipients' => [ '+15551234567' ], 'message' => 'Hello' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'whatsapp_invalid_provider_response', $result->get_error_code() );
		$this->assertSame( 'unknown', $result->get_error_data()['unconfirmed'][0]['status'] ?? '' );
	}

	public function test_telegram_rejects_invalid_chat_id(): void {
		$result = MessagingAbilities::handle_telegram_send( [ 'chat_ids' => [ 'not a chat' ], 'message' => 'Hello' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telegram_invalid_recipient', $result->get_error_code() );
	}

	public function test_telegram_sends_scrubbed_message(): void {
		Settings::instance()->set_telegram_provider( [ 'provider' => 'bot_api', 'bot_token' => '123:telegram-secret' ] );
		$captured_url  = '';
		$captured_body = [];
		add_filter(
			'pre_http_request',
			static function ( mixed $preempt, array $args, string $url ) use ( &$captured_url, &$captured_body ): array {
				$captured_url  = $url;
				$captured_body = json_decode( (string) $args['body'], true );
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => '{"ok":true,"result":{"message_id":42}}',
				];
			},
			10,
			3
		);

		$result = MessagingAbilities::handle_telegram_send( [ 'chat_ids' => [ '-1001234567890' ], 'message' => 'Hello' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://api.telegram.org/bot123:telegram-secret/sendMessage', $captured_url );
		$this->assertSame( '-1001234567890', $captured_body['chat_id'] ?? '' );
		$this->assertSame( '***7890', $result['sent'][0]['chat_id'] ?? '' );
		$this->assertSame( 42, $result['sent'][0]['message_id'] ?? 0 );
		$this->assertStringNotContainsString( 'telegram-secret', wp_json_encode( $result ) ?: '' );
	}

	public function test_provider_error_does_not_leak_telegram_token(): void {
		Settings::instance()->set_telegram_provider( [ 'provider' => 'bot_api', 'bot_token' => '123:telegram-secret' ] );
		add_filter(
			'pre_http_request',
			static function (): WP_Error {
				return new WP_Error( 'http_request_failed', 'Request failed for secret URL.' );
			}
		);

		$result = MessagingAbilities::handle_telegram_send( [ 'chat_ids' => [ '@validchannel' ], 'message' => 'Hello' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telegram_provider_error', $result->get_error_code() );
		$this->assertStringNotContainsString( 'telegram-secret', $result->get_error_message() );
	}

	/** Provider limits count Unicode characters rather than UTF-8 bytes. */
	public function test_message_length_validation_counts_unicode_characters(): void {
		$within_limit = MessagingAbilities::handle_telegram_send(
			[ 'chat_ids' => [ '@validchannel' ], 'message' => str_repeat( '🙂', MessagingAbilities::MAX_MESSAGE_LENGTH ) ]
		);
		$too_long     = MessagingAbilities::handle_telegram_send(
			[ 'chat_ids' => [ '@validchannel' ], 'message' => str_repeat( '🙂', MessagingAbilities::MAX_MESSAGE_LENGTH + 1 ) ]
		);

		$this->assertInstanceOf( WP_Error::class, $within_limit );
		$this->assertSame( 'telegram_not_configured', $within_limit->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, $too_long );
		$this->assertSame( 'telegram_message_too_long', $too_long->get_error_code() );
	}

	/** Telegram success requires the documented ok/result response shape. */
	public function test_telegram_rejects_invalid_success_response(): void {
		Settings::instance()->set_telegram_provider( [ 'provider' => 'bot_api', 'bot_token' => '123:telegram-secret' ] );
		add_filter(
			'pre_http_request',
			static function (): array {
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => '{"ok":false}',
				];
			}
		);

		$result = MessagingAbilities::handle_telegram_send( [ 'chat_ids' => [ '@validchannel' ], 'message' => 'Hello' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telegram_invalid_provider_response', $result->get_error_code() );
	}

	/**
	 * Telegram message identifiers must retain the documented integer type.
	 *
	 * @dataProvider provide_invalid_telegram_message_ids
	 *
	 * @param mixed $message_id Invalid Telegram message identifier.
	 */
	public function test_telegram_rejects_invalid_message_id_type( mixed $message_id ): void {
		Settings::instance()->set_telegram_provider( [ 'provider' => 'bot_api', 'bot_token' => '123:telegram-secret' ] );
		add_filter(
			'pre_http_request',
			static function () use ( $message_id ): array {
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => (string) wp_json_encode(
						[
							'ok'     => true,
							'result' => [ 'message_id' => $message_id ],
						]
					),
				];
			}
		);

		$result = MessagingAbilities::handle_telegram_send( [ 'chat_ids' => [ '@validchannel' ], 'message' => 'Hello' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telegram_invalid_provider_response', $result->get_error_code() );
	}

	/**
	 * Invalid Telegram message identifier fixtures.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function provide_invalid_telegram_message_ids(): array {
		return [
			'numeric string' => [ '42' ],
			'array'          => [ [ 42 ] ],
		];
	}
}
