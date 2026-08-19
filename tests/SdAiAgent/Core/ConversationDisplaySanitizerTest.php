<?php

declare(strict_types=1);
/**
 * Test case for ConversationDisplaySanitizer.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\ConversationDisplaySanitizer;
use WP_UnitTestCase;

/**
 * Tests for hiding provider thought-channel content from display surfaces.
 */
class ConversationDisplaySanitizerTest extends WP_UnitTestCase {

	/**
	 * sanitize_messages() removes thought parts while preserving visible content.
	 */
	public function test_sanitize_messages_removes_thought_parts(): void {
		$messages = [
			[
				'role'  => 'assistant',
				'parts' => [
					[
						'channel' => 'thought',
						'type'    => 'text',
						'text'    => 'The user wants hidden reasoning.',
					],
					[
						'channel' => 'content',
						'type'    => 'text',
						'text'    => 'Visible answer.',
					],
				],
			],
		];

		$sanitized = ConversationDisplaySanitizer::sanitize_messages( $messages );

		$this->assertCount( 1, $sanitized );
		$this->assertCount( 1, $sanitized[0]['parts'] );
		$this->assertSame( 'Visible answer.', $sanitized[0]['parts'][0]['text'] );
		$this->assertStringNotContainsString( 'hidden reasoning', (string) wp_json_encode( $sanitized ) );
	}

	/**
	 * sanitize_messages() keeps empty messages so persisted indices remain stable.
	 */
	public function test_sanitize_messages_keeps_thought_only_messages_empty(): void {
		$messages = [
			[
				'role'  => 'assistant',
				'parts' => [
					[
						'channel' => 'thought',
						'text'    => 'Private chain of thought.',
					],
				],
			],
			[
				'role'  => 'assistant',
				'parts' => [ [ 'text' => 'Legacy visible answer.' ] ],
			],
		];

		$sanitized = ConversationDisplaySanitizer::sanitize_messages( $messages );

		$this->assertCount( 2, $sanitized );
		$this->assertSame( array(), $sanitized[0]['parts'] );
		$this->assertSame( 'Legacy visible answer.', $sanitized[1]['parts'][0]['text'] );
	}

	/** Per-turn model metadata remains available to hydrated transcript UIs. */
	public function test_sanitize_messages_preserves_turn_model_metadata(): void {
		$messages = [
			[
				'role'        => 'user',
				'provider_id' => 'superdav',
				'model_id'    => 'superdav-chat-fast',
				'parts'       => [ [ 'text' => 'First turn.' ] ],
			],
		];

		$sanitized = ConversationDisplaySanitizer::sanitize_messages( $messages );

		$this->assertSame( 'superdav', $sanitized[0]['provider_id'] );
		$this->assertSame( 'superdav-chat-fast', $sanitized[0]['model_id'] );
	}

	/**
	 * extract_text() concatenates only content-channel or legacy unchannelled text.
	 */
	public function test_extract_text_uses_only_visible_content(): void {
		$message = [
			'role'  => 'assistant',
			'parts' => [
				[
					'channel' => 'thought',
					'text'    => 'Do not show this.',
				],
				[
					'channel' => 'content',
					'text'    => 'Visible ',
				],
				[ 'text' => 'legacy.' ],
			],
		];

		$this->assertSame( 'Visible legacy.', ConversationDisplaySanitizer::extract_text( $message ) );
	}

	/** Textual thinking blocks are removed from content-channel display text. */
	public function test_sanitize_messages_removes_complete_multiline_thinking_blocks(): void {
		$messages = [
			[
				'role'  => 'assistant',
				'parts' => [
					[
						'channel' => 'content',
						'text'    => "Visible before.<THINKING provider=\"example\">\nPrivate plan.\n</ThInKiNg>Visible after.",
					],
				],
			],
		];

		$sanitized = ConversationDisplaySanitizer::sanitize_messages( $messages );

		$this->assertSame( 'Visible before.Visible after.', $sanitized[0]['parts'][0]['text'] );
		$this->assertStringNotContainsString( 'Private plan', (string) wp_json_encode( $sanitized ) );
	}

	/** An unfinished textual thinking block fails closed for display projections. */
	public function test_sanitize_display_text_removes_unterminated_thinking_block(): void {
		$text = ConversationDisplaySanitizer::sanitize_display_text(
			'Visible answer.<thinking>Private reasoning that must not be shown.'
		);

		$this->assertSame( 'Visible answer.', $text );
	}
}
