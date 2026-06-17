<?php

declare(strict_types=1);
/**
 * Export/Import functionality for AI Agent sessions.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use WP_Error;

class Export {

	/**
	 * Export a session in the specified format.
	 *
	 * @param object $session Database session row.
	 * @param string $format  'json' or 'markdown'.
	 * @return array<string, mixed> Export data with 'content' and 'filename' keys.
	 */
	public static function export( object $session, string $format = 'json' ): array {
		if ( 'markdown' === $format ) {
			return self::export_markdown( $session );
		}

		return self::export_json( $session );
	}

	/**
	 * Export a session as JSON.
	 *
	 * @param object $session Database session row.
	 * @return array<string, mixed>
	 */
	public static function export_json( object $session ): array {
		$messages   = json_decode( $session->messages, true ) ?: [];
		$tool_calls = json_decode( $session->tool_calls, true ) ?: [];
		if ( ! is_array( $messages ) ) {
			$messages = [];
		}

		$data = [
			'format'      => 'sd-ai-agent-v1',
			'title'       => $session->title,
			'provider_id' => $session->provider_id,
			'model_id'    => $session->model_id,
			'messages'    => ConversationDisplaySanitizer::sanitize_messages( $messages ),
			'tool_calls'  => $tool_calls,
			'token_usage' => [
				'prompt'     => (int) $session->prompt_tokens,
				'completion' => (int) $session->completion_tokens,
			],
			'created_at'  => $session->created_at,
			'exported_at' => current_time( 'mysql', true ),
		];

		$slug = sanitize_title( $session->title ?: 'conversation' );

		return [
			'content'  => $data,
			'filename' => $slug . '.json',
		];
	}

	/**
	 * Export a session as Markdown.
	 *
	 * @param object $session Database session row.
	 * @return array<string, mixed>
	 */
	public static function export_markdown( object $session ): array {
		$messages = json_decode( $session->messages, true ) ?: [];
		if ( ! is_array( $messages ) ) {
			$messages = [];
		}
		$lines = [];

		$lines[] = '# ' . ( $session->title ?: 'Conversation' );
		$lines[] = '';
		$lines[] = sprintf(
			'*Exported on %s | Model: %s*',
			current_time( 'Y-m-d H:i' ),
			$session->model_id ?: 'unknown'
		);
		$lines[] = '';
		$lines[] = '---';
		$lines[] = '';

		// @phpstan-ignore-next-line
		foreach ( $messages as $msg ) {
			// @phpstan-ignore-next-line
			$role = $msg['role'] ?? 'unknown';

			// Skip function responses in markdown.
			if ( 'function' === $role ) {
				continue;
			}

			$text = is_array( $msg ) ? ConversationDisplaySanitizer::extract_text( $msg ) : '';

			if ( empty( $text ) ) {
				continue;
			}

			$label = 'user' === $role ? '**User**' : '**Assistant**';
			if ( 'system' === $role ) {
				$label = '**System**';
			}

			$lines[] = $label;
			$lines[] = '';
			$lines[] = $text;
			$lines[] = '';
			$lines[] = '---';
			$lines[] = '';
		}

		$slug = sanitize_title( $session->title ?: 'conversation' );

		return [
			'content'  => implode( "\n", $lines ),
			'filename' => $slug . '.md',
		];
	}

	/**
	 * Import a session from JSON data.
	 *
	 * @param array<string, mixed> $data    Import data (sd-ai-agent-v1 format).
	 * @param int                  $user_id WordPress user ID.
	 * @return int|WP_Error Session ID on success, WP_Error on failure.
	 */
	public static function import_json( array $data, int $user_id ) {
		$valid_formats = [ 'sd-ai-agent-v1', 'ai-agent-v1' ];
		if ( empty( $data['format'] ) || ! in_array( $data['format'], $valid_formats, true ) ) {
			return new WP_Error(
				'sd_ai_agent_import_invalid',
				__( 'Invalid import format. Expected sd-ai-agent-v1.', 'superdav-ai-agent' ),
				[ 'status' => 400 ]
			);
		}

		if ( empty( $data['messages'] ) || ! is_array( $data['messages'] ) ) {
			return new WP_Error(
				'sd_ai_agent_import_no_messages',
				__( 'Import data contains no messages.', 'superdav-ai-agent' ),
				[ 'status' => 400 ]
			);
		}

		// @phpstan-ignore-next-line
		$title = ! empty( $data['title'] ) ? sanitize_text_field( $data['title'] ) . ' (imported)' : 'Imported conversation';

		$session_id = Database::create_session(
			[
				'user_id'     => $user_id,
				'title'       => $title,
				// @phpstan-ignore-next-line
				'provider_id' => sanitize_text_field( $data['provider_id'] ?? '' ),
				// @phpstan-ignore-next-line
				'model_id'    => sanitize_text_field( $data['model_id'] ?? '' ),
			]
		);

		if ( ! $session_id ) {
			return new WP_Error(
				'sd_ai_agent_import_failed',
				__( 'Failed to create session for import.', 'superdav-ai-agent' ),
				[ 'status' => 500 ]
			);
		}

		Database::update_session(
			$session_id,
			[
				'messages'   => wp_json_encode( $data['messages'] ),
				'tool_calls' => wp_json_encode( $data['tool_calls'] ?? [] ),
			]
		);

		return $session_id;
	}
}
