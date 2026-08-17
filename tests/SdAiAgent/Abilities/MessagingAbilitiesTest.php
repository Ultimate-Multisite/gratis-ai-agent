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
				'api_version'     => 'v25.0',
			]
		);
		$captured_url  = '';
		$captured_args = [];
		add_filter(
			'pre_http_request',
			static function ( mixed $preempt, array $args, string $url ) use ( &$captured_url, &$captured_args ): array {
				$captured_url  = $url;
				$captured_args = $args;
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
		$this->assertSame( 'https://graph.facebook.com/v25.0/1234567890/messages', $captured_url );
		$this->assertSame( 'Bearer meta-secret-token', $captured_args['headers']['Authorization'] ?? '' );
		$this->assertSame( '+*******4567', $result['sent'][0]['recipient'] ?? '' );
		$this->assertSame( 'wamid.123', $result['sent'][0]['message_id'] ?? '' );
		$this->assertStringNotContainsString( 'meta-secret-token', wp_json_encode( $result ) ?: '' );
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
		$this->assertSame( 'https://api.telegram.org/bot123%3Atelegram-secret/sendMessage', $captured_url );
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
}
