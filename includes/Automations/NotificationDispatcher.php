<?php

declare(strict_types=1);
/**
 * Notification Dispatcher — sends automation results to Slack and Discord webhooks.
 *
 * Each automation can have zero or more notification channels configured as a JSON
 * array of objects:
 *
 *   [
 *     { "type": "slack",   "webhook_url": "https://hooks.slack.com/…",   "enabled": true },
 *     { "type": "discord", "webhook_url": "https://discord.com/api/webhooks/…", "enabled": true }
 *   ]
 *
 * The dispatcher is called by AutomationRunner after every run (success or error).
 * Channels with `enabled: false` are silently skipped.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Automations;

use SdAiAgent\Abilities\MessagingAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NotificationDispatcher {

	/**
	 * Dispatch notifications for a completed automation run.
	 *
	 * @param array<string, mixed> $automation The automation definition (from Automations::get()).
	 * @param array<string, mixed> $log_data   The log data produced by AutomationRunner::run().
	 */
	public static function dispatch( array $automation, array $log_data ): void {
		$channels = $automation['notification_channels'] ?? [];

		if ( empty( $channels ) || ! is_array( $channels ) ) {
			return;
		}

		foreach ( $channels as $channel ) {
			// @phpstan-ignore-next-line
			if ( empty( $channel['enabled'] ) ) {
				continue;
			}

			// @phpstan-ignore-next-line
			$type = sanitize_text_field( $channel['type'] ?? '' );
			// @phpstan-ignore-next-line
			switch ( $type ) {
				case 'slack':
					$webhook_url = esc_url_raw( (string) ( $channel['webhook_url'] ?? '' ) );
					if ( '' === $webhook_url ) {
						break;
					}
					self::post_webhook( $webhook_url, self::build_slack_payload( $automation, $log_data ) );
					break;

				case 'discord':
					$webhook_url = esc_url_raw( (string) ( $channel['webhook_url'] ?? '' ) );
					if ( '' === $webhook_url ) {
						break;
					}
					self::post_webhook( $webhook_url, self::build_discord_payload( $automation, $log_data ) );
					break;

				case 'whatsapp':
					$recipient = sanitize_text_field( (string) ( $channel['recipient'] ?? '' ) );
					if ( '' !== $recipient ) {
						MessagingAbilities::handle_whatsapp_send(
							[
								'recipients' => [ $recipient ],
								'message'    => self::build_text_payload( $automation, $log_data ),
							]
							);
					}
					break;

				case 'telegram':
					$recipient = sanitize_text_field( (string) ( $channel['recipient'] ?? '' ) );
					if ( '' !== $recipient ) {
						MessagingAbilities::handle_telegram_send(
							[
								'chat_ids' => [ $recipient ],
								'message'  => self::build_text_payload( $automation, $log_data ),
							]
							);
					}
					break;

				default:
					// Unknown channel type — skip silently.
					break;
			}
		}
	}

	/**
	 * Send a synchronous test notification to a single channel.
	 *
	 * Used by the REST test endpoint so the admin can verify their webhook URL
	 * before saving. Unlike dispatch(), this uses blocking=true so the response
	 * code can be checked and returned.
	 *
	 * @param string $type        Notification channel type.
	 * @param string $destination Webhook URL, phone number, or Telegram chat ID.
	 * @return array{success: bool, http_code: int, message: string}
	 */
	public static function test( string $type, string $destination ): array {
		$automation = [
			'name'     => __( 'Test Notification', 'superdav-ai-agent' ),
			'schedule' => 'manual',
		];

		$log_data = [
			'status'            => 'success',
			'reply'             => __( 'This is a test notification from Superdav AI Agent. Your webhook is configured correctly.', 'superdav-ai-agent' ),
			'duration_ms'       => 0,
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'error_message'     => '',
		];

		if ( 'whatsapp' === $type ) {
			return self::ability_test_result(
				MessagingAbilities::handle_whatsapp_send(
				[
					'recipients' => [ $destination ],
					'message'    => (string) $log_data['reply'],
				]
				)
				);
		}

		if ( 'telegram' === $type ) {
			return self::ability_test_result(
				MessagingAbilities::handle_telegram_send(
				[
					'chat_ids' => [ $destination ],
					'message'  => (string) $log_data['reply'],
				]
				)
				);
		}

		if ( 'slack' === $type ) {
			$payload = self::build_slack_payload( $automation, $log_data );
		} elseif ( 'discord' === $type ) {
			$payload = self::build_discord_payload( $automation, $log_data );
		} else {
			return [
				'success'   => false,
				'http_code' => 0,
				'message'   => __( 'Unknown notification channel type.', 'superdav-ai-agent' ),
			];
		}

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			return [
				'success'   => false,
				'http_code' => 0,
				'message'   => __( 'Failed to encode payload.', 'superdav-ai-agent' ),
			];
		}

		$response = wp_remote_post(
			$destination,
			[
				'timeout'     => 15,
				'blocking'    => true,
				'headers'     => [
					'Content-Type' => 'application/json',
				],
				'body'        => $body,
				'data_format' => 'body',
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'success'   => false,
				'http_code' => 0,
				'message'   => $response->get_error_message(),
			];
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$success   = $http_code >= 200 && $http_code < 300;

		return [
			'success'   => $success,
			'http_code' => (int) $http_code,
			'message'   => $success
				? __( 'Test notification sent successfully.', 'superdav-ai-agent' )
				: sprintf(
					/* translators: HTTP status code */
					__( 'Webhook returned HTTP %d.', 'superdav-ai-agent' ),
					$http_code
				),
		];
	}

	/**
	 * Normalize a messaging ability result for the notification test endpoint.
	 *
	 * @param array<string, mixed>|\WP_Error $result Ability result.
	 * @return array{success: bool, http_code: int, message: string}
	 */
	private static function ability_test_result( $result ): array {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			return [
				'success'   => false,
				'http_code' => is_array( $data ) ? absint( $data['http_code'] ?? 0 ) : 0,
				'message'   => $result->get_error_message(),
			];
		}

		return [
			'success'   => true,
			'http_code' => absint( $result['sent'][0]['http_code'] ?? 200 ),
			'message'   => __( 'Test notification sent successfully.', 'superdav-ai-agent' ),
		];
	}

	/**
	 * Build a compact plain-text automation result for messaging channels.
	 *
	 * @param array<string, mixed> $automation Automation definition.
	 * @param array<string, mixed> $log_data   Automation run log.
	 */
	private static function build_text_payload( array $automation, array $log_data ): string {
		$text = sprintf(
			/* translators: 1: automation name, 2: completion status, 3: response text. */
			__( 'Automation: %1$s\nStatus: %2$s\n\n%3$s', 'superdav-ai-agent' ),
			(string) ( $automation['name'] ?? '' ),
			(string) ( $log_data['status'] ?? 'unknown' ),
			(string) ( $log_data['reply'] ?? $log_data['error_message'] ?? '' )
		);

		return strlen( $text ) > MessagingAbilities::MAX_MESSAGE_LENGTH
			? substr( $text, 0, MessagingAbilities::MAX_MESSAGE_LENGTH - 1 ) . '…'
			: $text;
	}

	/**
	 * Build a Slack Incoming Webhook payload.
	 *
	 * @param array<string, mixed> $automation Automation definition.
	 * @param array<string, mixed> $log_data   Run log data.
	 * @return array<string, mixed>
	 */
	private static function build_slack_payload( array $automation, array $log_data ): array {
		$status     = $log_data['status'] ?? 'unknown';
		$is_success = 'success' === $status;
		$color      = $is_success ? '#36a64f' : '#e01e5a';
		$icon       = $is_success ? ':white_check_mark:' : ':x:';

		$reply = $log_data['reply'] ?? '';
		// @phpstan-ignore-next-line
		if ( strlen( $reply ) > 2000 ) {
			// @phpstan-ignore-next-line
			$reply = substr( $reply, 0, 1997 ) . '…';
		}

		$fields = [
			[
				'title' => __( 'Status', 'superdav-ai-agent' ),
				// @phpstan-ignore-next-line
				'value' => ucfirst( $status ),
				'short' => true,
			],
			[
				'title' => __( 'Schedule', 'superdav-ai-agent' ),
				'value' => $automation['schedule'] ?? '',
				'short' => true,
			],
			[
				'title' => __( 'Duration', 'superdav-ai-agent' ),
				// @phpstan-ignore-next-line
				'value' => ( $log_data['duration_ms'] ?? 0 ) . 'ms',
				'short' => true,
			],
			[
				'title' => __( 'Tokens', 'superdav-ai-agent' ),
				// @phpstan-ignore-next-line
				'value' => (string) ( ( $log_data['prompt_tokens'] ?? 0 ) + ( $log_data['completion_tokens'] ?? 0 ) ),
				'short' => true,
			],
		];

		if ( ! $is_success && ! empty( $log_data['error_message'] ) ) {
			$fields[] = [
				'title' => __( 'Error', 'superdav-ai-agent' ),
				'value' => $log_data['error_message'],
				'short' => false,
			];
		}

		return [
			'attachments' => [
				[
					'fallback'  => sprintf(
						/* translators: 1: automation name, 2: status */
						__( 'Automation "%1$s" completed with status: %2$s', 'superdav-ai-agent' ),
						// @phpstan-ignore-next-line
						$automation['name'] ?? '',
						// @phpstan-ignore-next-line
						$status
					),
					'color'     => $color,
					'pretext'   => sprintf(
						/* translators: 1: icon emoji, 2: automation name */
						__( '%1$s Automation: *%2$s*', 'superdav-ai-agent' ),
						$icon,
						// @phpstan-ignore-next-line
						$automation['name'] ?? ''
					),
					'text'      => $reply,
					'fields'    => $fields,
					'footer'    => __( 'Superdav AI Agent', 'superdav-ai-agent' ),
					'ts'        => time(),
					'mrkdwn_in' => [ 'pretext', 'text' ],
				],
			],
		];
	}

	/**
	 * Build a Discord Webhook payload.
	 *
	 * @param array<string, mixed> $automation Automation definition.
	 * @param array<string, mixed> $log_data   Run log data.
	 * @return array<string, mixed>
	 */
	private static function build_discord_payload( array $automation, array $log_data ): array {
		$status     = $log_data['status'] ?? 'unknown';
		$is_success = 'success' === $status;
		$color      = $is_success ? 0x36a64f : 0xe01e5a;

		$reply = $log_data['reply'] ?? '';
		// @phpstan-ignore-next-line
		if ( strlen( $reply ) > 1024 ) {
			// @phpstan-ignore-next-line
			$reply = substr( $reply, 0, 1021 ) . '…';
		}

		$fields = [
			[
				'name'   => __( 'Status', 'superdav-ai-agent' ),
				// @phpstan-ignore-next-line
				'value'  => ucfirst( $status ),
				'inline' => true,
			],
			[
				'name'   => __( 'Schedule', 'superdav-ai-agent' ),
				'value'  => $automation['schedule'] ?? 'N/A',
				'inline' => true,
			],
			[
				'name'   => __( 'Duration', 'superdav-ai-agent' ),
				// @phpstan-ignore-next-line
				'value'  => ( $log_data['duration_ms'] ?? 0 ) . 'ms',
				'inline' => true,
			],
			[
				'name'   => __( 'Tokens', 'superdav-ai-agent' ),
				// @phpstan-ignore-next-line
				'value'  => (string) ( ( $log_data['prompt_tokens'] ?? 0 ) + ( $log_data['completion_tokens'] ?? 0 ) ),
				'inline' => true,
			],
		];

		if ( ! $is_success && ! empty( $log_data['error_message'] ) ) {
			$fields[] = [
				'name'   => __( 'Error', 'superdav-ai-agent' ),
				// @phpstan-ignore-next-line
				'value'  => substr( $log_data['error_message'], 0, 1024 ),
				'inline' => false,
			];
		}

		if ( ! empty( $reply ) ) {
			$fields[] = [
				'name'   => __( 'Response', 'superdav-ai-agent' ),
				'value'  => $reply,
				'inline' => false,
			];
		}

		return [
			'embeds' => [
				[
					'title'     => sprintf(
						/* translators: automation name */
						__( 'Automation: %s', 'superdav-ai-agent' ),
						// @phpstan-ignore-next-line
						$automation['name'] ?? ''
					),
					'color'     => $color,
					'fields'    => $fields,
					'footer'    => [
						'text' => __( 'Superdav AI Agent', 'superdav-ai-agent' ),
					],
					'timestamp' => gmdate( 'c' ),
				],
			],
		];
	}

	/**
	 * POST a JSON payload to a webhook URL using wp_remote_post (non-blocking).
	 *
	 * Failures are logged via error_log but do not throw — notification
	 * failures must never interrupt the automation run itself.
	 *
	 * @param string               $url     Webhook URL.
	 * @param array<string, mixed> $payload JSON-serialisable payload.
	 */
	private static function post_webhook( string $url, array $payload ): void {
		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Notification failure logging.
				error_log( 'SdAiAgent NotificationDispatcher: failed to JSON-encode payload for ' . $url );
			}
			return;
		}

		$response = wp_remote_post(
			$url,
			[
				'timeout'     => 10,
				'blocking'    => false,
				'headers'     => [
					'Content-Type' => 'application/json',
				],
				'body'        => $body,
				'data_format' => 'body',
			]
		);

		if ( is_wp_error( $response ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Notification failure logging.
				error_log( 'SdAiAgent NotificationDispatcher: webhook error for ' . $url . ': ' . $response->get_error_message() );
			}
		}
	}
}
