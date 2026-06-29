<?php
/**
 * Test case for SmsAbilities class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\SmsAbilities;
use SdAiAgent\Core\Settings;
use WP_Error;
use WP_UnitTestCase;

/**
 * Test TextBee-backed SMS ability behavior.
 */
class SmsAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Clear SMS provider state before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		Settings::instance()->set_sms_provider( [] );
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Clear SMS provider state after each test.
	 */
	public function tearDown(): void {
		Settings::instance()->set_sms_provider( [] );
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	/**
	 * Missing credentials return a clear WP_Error.
	 */
	public function test_handle_sms_send_missing_credentials_returns_wp_error(): void {
		$result = SmsAbilities::handle_sms_send(
			[
				'recipients' => [ '+15551234567' ],
				'message'    => 'Hello.',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sms_not_configured', $result->get_error_code() );
	}

	/**
	 * Invalid recipients are rejected before any provider call.
	 */
	public function test_handle_sms_send_invalid_recipients_returns_wp_error(): void {
		$result = SmsAbilities::handle_sms_send(
			[
				'recipients' => [ '555-1234' ],
				'message'    => 'Hello.',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sms_invalid_recipient', $result->get_error_code() );
	}

	/**
	 * Invalid self-hosted base URLs are rejected.
	 */
	public function test_handle_sms_send_invalid_base_url_returns_wp_error(): void {
		Settings::instance()->set_sms_provider(
			[
				'provider'     => 'textbee',
				'api_key'      => 'tb_secret_key',
				'device_id'    => 'device-123',
				'api_base_url' => 'not-a-url',
			]
		);

		$result = SmsAbilities::handle_sms_send(
			[
				'recipients' => [ '+15551234567' ],
				'message'    => 'Hello.',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sms_invalid_api_base_url', $result->get_error_code() );
	}

	/**
	 * Provider transport errors are converted to a scrubbed ability error.
	 */
	public function test_handle_sms_send_provider_wp_error_returns_wp_error(): void {
		$this->configure_sms_provider();

		add_filter(
			'pre_http_request',
			static function (): WP_Error {
				return new WP_Error( 'http_request_failed', 'Connection failed.' );
			}
		);

		$result = SmsAbilities::handle_sms_send(
			[
				'recipients' => [ '+15551234567' ],
				'message'    => 'Hello.',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sms_provider_error', $result->get_error_code() );
		$this->assertStringNotContainsString( 'tb_secret_key', $result->get_error_message() );
	}

	/**
	 * Non-2xx TextBee responses are reported without leaking response bodies.
	 */
	public function test_handle_sms_send_non_2xx_response_returns_wp_error(): void {
		$this->configure_sms_provider();

		add_filter(
			'pre_http_request',
			static function (): array {
				return [
					'response' => [
						'code'    => 401,
						'message' => 'Unauthorized',
					],
					'body'     => '{"api_key":"tb_secret_key"}',
				];
			}
		);

		$result = SmsAbilities::handle_sms_send(
			[
				'recipients' => [ '+15551234567' ],
				'message'    => 'Hello.',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sms_provider_http_error', $result->get_error_code() );
		$this->assertStringNotContainsString( 'tb_secret_key', $result->get_error_message() );
	}

	/**
	 * Successful sends call the documented TextBee endpoint and redact output.
	 */
	public function test_handle_sms_send_success_returns_scrubbed_result(): void {
		$this->configure_sms_provider();
		$captured_url  = '';
		$captured_args = [];

		add_filter(
			'pre_http_request',
			static function ( mixed $preempt, array $parsed_args, string $url ) use ( &$captured_url, &$captured_args ): array {
				$captured_url  = $url;
				$captured_args = $parsed_args;

				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'body'     => wp_json_encode(
						[
							'id'      => 'sms-123',
							'api_key' => 'tb_secret_key',
						]
					),
				];
			},
			10,
			3
		);

		$result = SmsAbilities::handle_sms_send(
			[
				'recipients' => [ '+15551234567', '+447700900123' ],
				'message'    => 'Hello.',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'https://textbee.example/api/v1/gateway/devices/device-123/send-sms', $captured_url );
		$this->assertSame( 'tb_secret_key', $captured_args['headers']['x-api-key'] ?? '' );
		$this->assertSame( [ '+*******4567', '+*******0123' ], $result['recipients'] );
		$this->assertSame( '[redacted]', $result['provider_response']['api_key'] ?? '' );
		$this->assertStringNotContainsString( 'tb_secret_key', wp_json_encode( $result ) ?: '' );
	}

	/**
	 * Message length is capped before sending.
	 */
	public function test_handle_sms_send_message_too_long_returns_wp_error(): void {
		$result = SmsAbilities::handle_sms_send(
			[
				'recipients' => [ '+15551234567' ],
				'message'    => str_repeat( 'a', SmsAbilities::MAX_MESSAGE_LENGTH + 1 ),
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sms_message_too_long', $result->get_error_code() );
	}

	/**
	 * Configure a valid TextBee provider for tests.
	 */
	private function configure_sms_provider(): void {
		Settings::instance()->set_sms_provider(
			[
				'provider'     => 'textbee',
				'api_key'      => 'tb_secret_key',
				'device_id'    => 'device-123',
				'api_base_url' => 'https://textbee.example',
			]
		);
	}
}
