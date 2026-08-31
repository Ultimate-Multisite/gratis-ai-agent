<?php
/**
 * Tests for NotificationDispatcher.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Automations;

use SdAiAgent\Automations\NotificationDispatcher;
use SdAiAgent\Core\Settings;
use WP_UnitTestCase;

/**
 * Test NotificationDispatcher payload building and dispatch logic.
 *
 * Note: Tests that require a live Slack/Discord webhook are not included here.
 * This suite focuses on payload construction, channel filtering, and the
 * test() method's error-path handling using WP HTTP API mocking.
 */
class NotificationDispatcherTest extends WP_UnitTestCase {

	/**
	 * Minimal automation definition used across tests.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function make_automation( array $overrides = [] ): array {
		return array_merge(
			[
				'name'                  => 'Test Automation',
				'schedule'              => 'daily',
				'notification_channels' => [],
			],
			$overrides
		);
	}

	/**
	 * Minimal log data used across tests.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function make_log_data( array $overrides = [] ): array {
		return array_merge(
			[
				'status'            => 'success',
				'reply'             => 'The task completed successfully.',
				'duration_ms'       => 1234,
				'prompt_tokens'     => 100,
				'completion_tokens' => 50,
				'error_message'     => '',
			],
			$overrides
		);
	}

	// -------------------------------------------------------------------------
	// dispatch — channel filtering
	// -------------------------------------------------------------------------

	/**
	 * Test dispatch returns early when notification_channels is empty.
	 */
	public function test_dispatch_empty_channels_returns_early(): void {
		$automation = $this->make_automation( [ 'notification_channels' => [] ] );

		// No HTTP request should be made — if it were, WP test suite would error.
		NotificationDispatcher::dispatch( $automation, $this->make_log_data() );

		$this->assertTrue( true, 'dispatch should handle empty channels without error' );
	}

	/**
	 * Test dispatch skips channels with enabled=false.
	 */
	public function test_dispatch_skips_disabled_channels(): void {
		$automation = $this->make_automation( [
			'notification_channels' => [
				[
					'type'        => 'slack',
					'webhook_url' => 'https://hooks.slack.com/test',
					'enabled'     => false,
				],
			],
		] );

		// No HTTP request should be made for disabled channels.
		NotificationDispatcher::dispatch( $automation, $this->make_log_data() );

		$this->assertTrue( true, 'dispatch should skip disabled channels without error' );
	}

	/**
	 * Test dispatch skips channels with missing webhook_url.
	 */
	public function test_dispatch_skips_missing_webhook_url(): void {
		$automation = $this->make_automation( [
			'notification_channels' => [
				[
					'type'    => 'slack',
					'enabled' => true,
					// webhook_url intentionally omitted.
				],
			],
		] );

		NotificationDispatcher::dispatch( $automation, $this->make_log_data() );

		$this->assertTrue( true, 'dispatch should skip channels with missing webhook_url' );
	}

	/**
	 * Test dispatch skips channels with unknown type.
	 */
	public function test_dispatch_skips_unknown_channel_type(): void {
		$automation = $this->make_automation( [
			'notification_channels' => [
				[
					'type'        => 'telegram',
					'webhook_url' => 'https://api.telegram.org/test',
					'enabled'     => true,
				],
			],
		] );

		NotificationDispatcher::dispatch( $automation, $this->make_log_data() );

		$this->assertTrue( true, 'dispatch should skip unknown channel types without error' );
	}

	/**
	 * Test dispatch handles non-array notification_channels gracefully.
	 */
	public function test_dispatch_non_array_channels_returns_early(): void {
		$automation = $this->make_automation( [ 'notification_channels' => 'invalid' ] );

		NotificationDispatcher::dispatch( $automation, $this->make_log_data() );

		$this->assertTrue( true, 'dispatch should handle non-array channels without error' );
	}

	// -------------------------------------------------------------------------
	// test() — unknown type
	// -------------------------------------------------------------------------

	/**
	 * Test test() returns failure for unknown channel type.
	 */
	public function test_test_unknown_type_returns_failure(): void {
		$result = NotificationDispatcher::test( 'signal', 'https://example.com/webhook' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'http_code', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['http_code'] );
	}

	/** Telegram automation notifications use the globally configured bot. */
	public function test_dispatch_sends_telegram_notification(): void {
		Settings::instance()->set_telegram_provider( [ 'provider' => 'bot_api', 'bot_token' => '123:test-token' ] );
		$captured_body = [];
		add_filter(
			'pre_http_request',
			static function ( mixed $preempt, array $args ) use ( &$captured_body ): array {
				$captured_body = json_decode( (string) $args['body'], true );
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => '{"ok":true,"result":{"message_id":7}}',
				];
			},
			10,
			2
		);

		NotificationDispatcher::dispatch(
			$this->make_automation(
				[
					'notification_channels' => [
						[ 'type' => 'telegram', 'recipient' => '@validchannel', 'enabled' => true ],
					],
				]
			),
			$this->make_log_data()
		);

		$this->assertSame( '@validchannel', $captured_body['chat_id'] ?? '' );
		$this->assertStringContainsString( 'The task completed successfully.', $captured_body['text'] ?? '' );
		Settings::instance()->set_telegram_provider( [] );
	}

	/**
	 * Test test() returns failure when wp_remote_post returns WP_Error.
	 */
	public function test_test_wp_error_returns_failure(): void {
		// Mock wp_remote_post to return a WP_Error.
		add_filter(
			'pre_http_request',
			function () {
				return new \WP_Error( 'http_request_failed', 'Connection refused' );
			}
		);

		$result = NotificationDispatcher::test( 'slack', 'https://hooks.slack.com/test' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['http_code'] );
		$this->assertStringContainsString( 'Connection refused', $result['message'] );
	}

	/**
	 * Test test() returns success for a 200 response.
	 */
	public function test_test_200_response_returns_success(): void {
		add_filter(
			'pre_http_request',
			function () {
				return [
					'headers'  => [],
					'body'     => 'ok',
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'cookies'  => [],
					'filename' => '',
				];
			}
		);

		$result = NotificationDispatcher::test( 'slack', 'https://hooks.slack.com/test' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 200, $result['http_code'] );
	}

	/**
	 * Test test() returns failure for a 4xx response.
	 */
	public function test_test_4xx_response_returns_failure(): void {
		add_filter(
			'pre_http_request',
			function () {
				return [
					'headers'  => [],
					'body'     => 'Unauthorized',
					'response' => [ 'code' => 401, 'message' => 'Unauthorized' ],
					'cookies'  => [],
					'filename' => '',
				];
			}
		);

		$result = NotificationDispatcher::test( 'discord', 'https://discord.com/api/webhooks/test' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 401, $result['http_code'] );
	}

	/**
	 * Test test() returns success for a 204 response (Discord returns 204 on success).
	 */
	public function test_test_204_response_returns_success(): void {
		add_filter(
			'pre_http_request',
			function () {
				return [
					'headers'  => [],
					'body'     => '',
					'response' => [ 'code' => 204, 'message' => 'No Content' ],
					'cookies'  => [],
					'filename' => '',
				];
			}
		);

		$result = NotificationDispatcher::test( 'discord', 'https://discord.com/api/webhooks/test' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 204, $result['http_code'] );
	}

	// -------------------------------------------------------------------------
	// Slack payload structure (via test())
	// -------------------------------------------------------------------------

	/**
	 * Test test() for slack sends a payload with 'attachments' key.
	 */
	public function test_test_slack_sends_attachments_payload(): void {
		$captured_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_body ) {
				$captured_body = json_decode( $args['body'], true );
				return [
					'headers'  => [],
					'body'     => 'ok',
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'cookies'  => [],
					'filename' => '',
				];
			},
			10,
			2
		);

		NotificationDispatcher::test( 'slack', 'https://hooks.slack.com/test' );

		$this->assertNotNull( $captured_body, 'HTTP request body should be captured' );
		$this->assertArrayHasKey( 'attachments', $captured_body, 'Slack payload should have attachments key' );
		$this->assertIsArray( $captured_body['attachments'] );
		$this->assertNotEmpty( $captured_body['attachments'] );
	}

	/**
	 * Test test() for slack attachment has required fields.
	 */
	public function test_test_slack_attachment_has_required_fields(): void {
		$captured_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_body ) {
				$captured_body = json_decode( $args['body'], true );
				return [
					'headers'  => [],
					'body'     => 'ok',
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'cookies'  => [],
					'filename' => '',
				];
			},
			10,
			2
		);

		NotificationDispatcher::test( 'slack', 'https://hooks.slack.com/test' );

		$attachment = $captured_body['attachments'][0];
		$this->assertArrayHasKey( 'color', $attachment );
		$this->assertArrayHasKey( 'fields', $attachment );
		$this->assertArrayHasKey( 'footer', $attachment );
		$this->assertIsArray( $attachment['fields'] );
	}

	// -------------------------------------------------------------------------
	// Discord payload structure (via test())
	// -------------------------------------------------------------------------

	/**
	 * Test test() for discord sends a payload with 'embeds' key.
	 */
	public function test_test_discord_sends_embeds_payload(): void {
		$captured_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_body ) {
				$captured_body = json_decode( $args['body'], true );
				return [
					'headers'  => [],
					'body'     => '',
					'response' => [ 'code' => 204, 'message' => 'No Content' ],
					'cookies'  => [],
					'filename' => '',
				];
			},
			10,
			2
		);

		NotificationDispatcher::test( 'discord', 'https://discord.com/api/webhooks/test' );

		$this->assertNotNull( $captured_body, 'HTTP request body should be captured' );
		$this->assertArrayHasKey( 'embeds', $captured_body, 'Discord payload should have embeds key' );
		$this->assertIsArray( $captured_body['embeds'] );
		$this->assertNotEmpty( $captured_body['embeds'] );
	}

	/**
	 * Test test() for discord embed has required fields.
	 */
	public function test_test_discord_embed_has_required_fields(): void {
		$captured_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_body ) {
				$captured_body = json_decode( $args['body'], true );
				return [
					'headers'  => [],
					'body'     => '',
					'response' => [ 'code' => 204, 'message' => 'No Content' ],
					'cookies'  => [],
					'filename' => '',
				];
			},
			10,
			2
		);

		NotificationDispatcher::test( 'discord', 'https://discord.com/api/webhooks/test' );

		$embed = $captured_body['embeds'][0];
		$this->assertArrayHasKey( 'title', $embed );
		$this->assertArrayHasKey( 'color', $embed );
		$this->assertArrayHasKey( 'fields', $embed );
		$this->assertArrayHasKey( 'footer', $embed );
		$this->assertArrayHasKey( 'timestamp', $embed );
		$this->assertIsArray( $embed['fields'] );
	}

	// -------------------------------------------------------------------------
	// Payload content — success vs error status
	// -------------------------------------------------------------------------

	/**
	 * Test Slack payload uses green color for success status.
	 */
	public function test_slack_payload_success_color(): void {
		$captured_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_body ) {
				$captured_body = json_decode( $args['body'], true );
				return [
					'headers'  => [],
					'body'     => 'ok',
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'cookies'  => [],
					'filename' => '',
				];
			},
			10,
			2
		);

		// test() always uses status=success in its log_data.
		NotificationDispatcher::test( 'slack', 'https://hooks.slack.com/test' );

		$this->assertSame( '#36a64f', $captured_body['attachments'][0]['color'] );
	}

	/**
	 * Test Discord payload uses green color for success status.
	 */
	public function test_discord_payload_success_color(): void {
		$captured_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_body ) {
				$captured_body = json_decode( $args['body'], true );
				return [
					'headers'  => [],
					'body'     => '',
					'response' => [ 'code' => 204, 'message' => 'No Content' ],
					'cookies'  => [],
					'filename' => '',
				];
			},
			10,
			2
		);

		NotificationDispatcher::test( 'discord', 'https://discord.com/api/webhooks/test' );

		// 0x36a64f = 3581007 in decimal.
		$this->assertSame( 0x36a64f, $captured_body['embeds'][0]['color'] );
	}

	/**
	 * Test Slack payload includes Status, Schedule, Duration, Tokens fields.
	 */
	public function test_slack_payload_includes_standard_fields(): void {
		$captured_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_body ) {
				$captured_body = json_decode( $args['body'], true );
				return [
					'headers'  => [],
					'body'     => 'ok',
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'cookies'  => [],
					'filename' => '',
				];
			},
			10,
			2
		);

		NotificationDispatcher::test( 'slack', 'https://hooks.slack.com/test' );

		$field_titles = array_column( $captured_body['attachments'][0]['fields'], 'title' );

		$this->assertContains( 'Status', $field_titles );
		$this->assertContains( 'Schedule', $field_titles );
		$this->assertContains( 'Duration', $field_titles );
		$this->assertContains( 'Tokens', $field_titles );
	}

	/**
	 * Test Discord payload includes Status, Schedule, Duration, Tokens fields.
	 */
	public function test_discord_payload_includes_standard_fields(): void {
		$captured_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_body ) {
				$captured_body = json_decode( $args['body'], true );
				return [
					'headers'  => [],
					'body'     => '',
					'response' => [ 'code' => 204, 'message' => 'No Content' ],
					'cookies'  => [],
					'filename' => '',
				];
			},
			10,
			2
		);

		NotificationDispatcher::test( 'discord', 'https://discord.com/api/webhooks/test' );

		$field_names = array_column( $captured_body['embeds'][0]['fields'], 'name' );

		$this->assertContains( 'Status', $field_names );
		$this->assertContains( 'Schedule', $field_names );
		$this->assertContains( 'Duration', $field_names );
		$this->assertContains( 'Tokens', $field_names );
	}

	// -------------------------------------------------------------------------
	// Reply truncation
	// -------------------------------------------------------------------------

	/**
	 * Test Slack payload truncates reply longer than 2000 characters.
	 */
	public function test_slack_payload_truncates_long_reply(): void {
		$captured_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_body ) {
				$captured_body = json_decode( $args['body'], true );
				return [
					'headers'  => [],
					'body'     => 'ok',
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'cookies'  => [],
					'filename' => '',
				];
			},
			10,
			2
		);

		// Build an automation with a channel and a long reply via dispatch().
		$long_reply  = str_repeat( 'a', 2500 );
		$automation  = $this->make_automation( [
			'notification_channels' => [
				[
					'type'        => 'slack',
					'webhook_url' => 'https://hooks.slack.com/test',
					'enabled'     => true,
				],
			],
		] );
		$log_data    = $this->make_log_data( [ 'reply' => $long_reply ] );

		NotificationDispatcher::dispatch( $automation, $log_data );

		$text = $captured_body['attachments'][0]['text'];
		$this->assertLessThanOrEqual( 2000, strlen( $text ), 'Slack reply should be truncated to 2000 chars' );
		$this->assertStringEndsWith( '…', $text, 'Truncated Slack reply should end with ellipsis' );
	}

	/**
	 * Test Discord payload truncates reply longer than 1024 characters.
	 */
	public function test_discord_payload_truncates_long_reply(): void {
		$captured_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_body ) {
				$captured_body = json_decode( $args['body'], true );
				return [
					'headers'  => [],
					'body'     => '',
					'response' => [ 'code' => 204, 'message' => 'No Content' ],
					'cookies'  => [],
					'filename' => '',
				];
			},
			10,
			2
		);

		$long_reply = str_repeat( 'b', 1500 );
		$automation = $this->make_automation( [
			'notification_channels' => [
				[
					'type'        => 'discord',
					'webhook_url' => 'https://discord.com/api/webhooks/test',
					'enabled'     => true,
				],
			],
		] );
		$log_data   = $this->make_log_data( [ 'reply' => $long_reply ] );

		NotificationDispatcher::dispatch( $automation, $log_data );

		// Find the Response field in the embed.
		$fields      = $captured_body['embeds'][0]['fields'];
		$reply_field = null;
		foreach ( $fields as $field ) {
			if ( 'Response' === $field['name'] ) {
				$reply_field = $field;
				break;
			}
		}

		$this->assertNotNull( $reply_field, 'Discord embed should have a Response field for non-empty reply' );
		$this->assertLessThanOrEqual( 1024, strlen( $reply_field['value'] ), 'Discord reply should be truncated to 1024 chars' );
		$this->assertStringEndsWith( '…', $reply_field['value'], 'Truncated Discord reply should end with ellipsis' );
	}

	// -------------------------------------------------------------------------
	// Error field in payload
	// -------------------------------------------------------------------------

	/**
	 * Test Slack payload includes Error field when status is error.
	 */
	public function test_slack_payload_includes_error_field_on_failure(): void {
		$captured_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_body ) {
				$captured_body = json_decode( $args['body'], true );
				return [
					'headers'  => [],
					'body'     => 'ok',
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'cookies'  => [],
					'filename' => '',
				];
			},
			10,
			2
		);

		$automation = $this->make_automation( [
			'notification_channels' => [
				[
					'type'        => 'slack',
					'webhook_url' => 'https://hooks.slack.com/test',
					'enabled'     => true,
				],
			],
		] );
		$log_data   = $this->make_log_data( [
			'status'        => 'error',
			'error_message' => 'Provider API returned 500',
		] );

		NotificationDispatcher::dispatch( $automation, $log_data );

		$field_titles = array_column( $captured_body['attachments'][0]['fields'], 'title' );
		$this->assertContains( 'Error', $field_titles, 'Slack payload should include Error field on failure' );
	}

	/**
	 * Test Discord payload includes Error field when status is error.
	 */
	public function test_discord_payload_includes_error_field_on_failure(): void {
		$captured_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_body ) {
				$captured_body = json_decode( $args['body'], true );
				return [
					'headers'  => [],
					'body'     => '',
					'response' => [ 'code' => 204, 'message' => 'No Content' ],
					'cookies'  => [],
					'filename' => '',
				];
			},
			10,
			2
		);

		$automation = $this->make_automation( [
			'notification_channels' => [
				[
					'type'        => 'discord',
					'webhook_url' => 'https://discord.com/api/webhooks/test',
					'enabled'     => true,
				],
			],
		] );
		$log_data   = $this->make_log_data( [
			'status'        => 'error',
			'error_message' => 'Provider API returned 500',
		] );

		NotificationDispatcher::dispatch( $automation, $log_data );

		$field_names = array_column( $captured_body['embeds'][0]['fields'], 'name' );
		$this->assertContains( 'Error', $field_names, 'Discord payload should include Error field on failure' );
	}

	/**
	 * Test Slack payload uses red color for error status.
	 */
	public function test_slack_payload_error_color(): void {
		$captured_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_body ) {
				$captured_body = json_decode( $args['body'], true );
				return [
					'headers'  => [],
					'body'     => 'ok',
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'cookies'  => [],
					'filename' => '',
				];
			},
			10,
			2
		);

		$automation = $this->make_automation( [
			'notification_channels' => [
				[
					'type'        => 'slack',
					'webhook_url' => 'https://hooks.slack.com/test',
					'enabled'     => true,
				],
			],
		] );
		$log_data   = $this->make_log_data( [ 'status' => 'error', 'error_message' => 'Something went wrong' ] );

		NotificationDispatcher::dispatch( $automation, $log_data );

		$this->assertSame( '#e01e5a', $captured_body['attachments'][0]['color'] );
	}

	/**
	 * Test Discord payload uses red color for error status.
	 */
	public function test_discord_payload_error_color(): void {
		$captured_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_body ) {
				$captured_body = json_decode( $args['body'], true );
				return [
					'headers'  => [],
					'body'     => '',
					'response' => [ 'code' => 204, 'message' => 'No Content' ],
					'cookies'  => [],
					'filename' => '',
				];
			},
			10,
			2
		);

		$automation = $this->make_automation( [
			'notification_channels' => [
				[
					'type'        => 'discord',
					'webhook_url' => 'https://discord.com/api/webhooks/test',
					'enabled'     => true,
				],
			],
		] );
		$log_data   = $this->make_log_data( [ 'status' => 'error', 'error_message' => 'Something went wrong' ] );

		NotificationDispatcher::dispatch( $automation, $log_data );

		// 0xe01e5a = 14688858 in decimal.
		$this->assertSame( 0xe01e5a, $captured_body['embeds'][0]['color'] );
	}
}
