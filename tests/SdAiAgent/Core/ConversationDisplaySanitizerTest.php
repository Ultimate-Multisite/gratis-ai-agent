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
}
