<?php

declare(strict_types=1);
/**
 * Test case for Export class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\Export;
use WP_UnitTestCase;

/**
 * Test Export functionality.
 */
class ExportTest extends WP_UnitTestCase {

	/**
	 * Build a minimal session object for testing.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return object
	 */
	private function make_session( array $overrides = [] ): object {
		$defaults = [
			'title'             => 'Test Conversation',
			'provider_id'       => 'openai',
			'model_id'          => 'gpt-4o',
			'messages'          => wp_json_encode( [
				[
					'role'  => 'user',
					'parts' => [ [ 'text' => 'Hello' ] ],
				],
				[
					'role'  => 'assistant',
					'parts' => [ [ 'text' => 'Hi there!' ] ],
				],
			] ),
			'tool_calls'        => wp_json_encode( [] ),
			'prompt_tokens'     => 100,
			'completion_tokens' => 50,
			'created_at'        => '2025-01-01 00:00:00',
		];

		return (object) array_merge( $defaults, $overrides );
	}

	// ── export() dispatcher ───────────────────────────────────────────────────

	/**
	 * export() defaults to JSON format.
	 */
	public function test_export_defaults_to_json(): void {
		$session = $this->make_session();
		$result  = Export::export( $session );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'content', $result );
		$this->assertArrayHasKey( 'filename', $result );
		$this->assertStringEndsWith( '.json', $result['filename'] );
		$this->assertIsArray( $result['content'] );
	}

	/**
	 * export() returns markdown when format is 'markdown'.
	 */
	public function test_export_returns_markdown_when_format_is_markdown(): void {
		$session = $this->make_session();
		$result  = Export::export( $session, 'markdown' );

		$this->assertStringEndsWith( '.md', $result['filename'] );
		$this->assertIsString( $result['content'] );
	}

	/**
	 * export() falls back to JSON for unknown format.
	 */
	public function test_export_falls_back_to_json_for_unknown_format(): void {
		$session = $this->make_session();
		$result  = Export::export( $session, 'csv' );

		$this->assertIsArray( $result['content'] );
		$this->assertStringEndsWith( '.json', $result['filename'] );
	}

	// ── export_json ───────────────────────────────────────────────────────────

	/**
	 * export_json() returns the expected data shape.
	 */
	public function test_export_json_returns_expected_shape(): void {
		$session = $this->make_session();
		$result  = Export::export_json( $session );

		$this->assertArrayHasKey( 'content', $result );
		$this->assertArrayHasKey( 'filename', $result );

		$content = $result['content'];
		$this->assertSame( 'sd-ai-agent-v1', $content['format'] );
		$this->assertSame( 'Test Conversation', $content['title'] );
		$this->assertSame( 'openai', $content['provider_id'] );
		$this->assertSame( 'gpt-4o', $content['model_id'] );
		$this->assertIsArray( $content['messages'] );
		$this->assertIsArray( $content['tool_calls'] );
		$this->assertArrayHasKey( 'token_usage', $content );
		$this->assertSame( 100, $content['token_usage']['prompt'] );
		$this->assertSame( 50, $content['token_usage']['completion'] );
		$this->assertArrayHasKey( 'exported_at', $content );
	}

	/**
	 * export_json() generates a slug-based filename from the session title.
	 */
	public function test_export_json_generates_slug_filename(): void {
		$session = $this->make_session( [ 'title' => 'My Test Conversation' ] );
		$result  = Export::export_json( $session );

		$this->assertSame( 'my-test-conversation.json', $result['filename'] );
	}

	/**
	 * export_json() uses 'conversation' as filename when title is empty.
	 */
	public function test_export_json_uses_fallback_filename_when_title_empty(): void {
		$session = $this->make_session( [ 'title' => '' ] );
		$result  = Export::export_json( $session );

		$this->assertSame( 'conversation.json', $result['filename'] );
	}

	/**
	 * export_json() handles invalid JSON in messages gracefully.
	 */
	public function test_export_json_handles_invalid_messages_json(): void {
		$session = $this->make_session( [ 'messages' => 'not-json' ] );
		$result  = Export::export_json( $session );

		$this->assertIsArray( $result['content']['messages'] );
		$this->assertEmpty( $result['content']['messages'] );
	}

	/**
	 * export_json() strips hidden thought-channel parts from transcript data.
	 */
	public function test_export_json_strips_thought_channel_parts(): void {
		$session = $this->make_session( [
			'messages' => wp_json_encode( [
				[
					'role'  => 'assistant',
					'parts' => [
						[
							'channel' => 'thought',
							'text'    => 'The user wants hidden reasoning.',
						],
						[
							'channel' => 'content',
							'text'    => 'Visible answer.',
						],
					],
				],
			] ),
		] );

		$result  = Export::export_json( $session );
		$content = wp_json_encode( $result['content'] );

		$this->assertIsString( $content );
		$this->assertStringNotContainsString( 'hidden reasoning', $content );
		$this->assertStringContainsString( 'Visible answer.', $content );
	}

	// ── export_markdown ───────────────────────────────────────────────────────

	/**
	 * export_markdown() returns a string with the session title as H1.
	 */
	public function test_export_markdown_includes_title_as_h1(): void {
		$session = $this->make_session();
		$result  = Export::export_markdown( $session );

		$this->assertStringContainsString( '# Test Conversation', $result['content'] );
	}

	/**
	 * export_markdown() includes user and assistant messages.
	 */
	public function test_export_markdown_includes_messages(): void {
		$session = $this->make_session();
		$result  = Export::export_markdown( $session );

		$this->assertStringContainsString( '**User**', $result['content'] );
		$this->assertStringContainsString( 'Hello', $result['content'] );
		$this->assertStringContainsString( '**Assistant**', $result['content'] );
		$this->assertStringContainsString( 'Hi there!', $result['content'] );
	}

	/**
	 * export_markdown() skips function role messages.
	 */
	public function test_export_markdown_skips_function_messages(): void {
		$session = $this->make_session( [
			'messages' => wp_json_encode( [
				[
					'role'  => 'function',
					'parts' => [ [ 'text' => 'Tool result data' ] ],
				],
				[
					'role'  => 'user',
					'parts' => [ [ 'text' => 'User message' ] ],
				],
			] ),
		] );

		$result = Export::export_markdown( $session );

		$this->assertStringNotContainsString( 'Tool result data', $result['content'] );
		$this->assertStringContainsString( 'User message', $result['content'] );
	}

	/**
	 * export_markdown() skips messages with no text parts.
	 */
	public function test_export_markdown_skips_messages_without_text(): void {
		$session = $this->make_session( [
			'messages' => wp_json_encode( [
				[
					'role'  => 'user',
					'parts' => [],
				],
				[
					'role'  => 'assistant',
					'parts' => [ [ 'text' => 'Response' ] ],
				],
			] ),
		] );

		$result = Export::export_markdown( $session );

		// Only the assistant message should appear.
		$this->assertStringContainsString( 'Response', $result['content'] );
	}

	/**
	 * export_markdown() excludes hidden thought-channel reasoning.
	 */
	public function test_export_markdown_strips_thought_channel_text(): void {
		$session = $this->make_session( [
			'messages' => wp_json_encode( [
				[
					'role'  => 'assistant',
					'parts' => [
						[
							'channel' => 'thought',
							'text'    => 'The user wants hidden reasoning.',
						],
						[
							'channel' => 'content',
							'text'    => 'Visible answer.',
						],
					],
				],
			] ),
		] );

		$result = Export::export_markdown( $session );

		$this->assertStringNotContainsString( 'hidden reasoning', $result['content'] );
		$this->assertStringContainsString( 'Visible answer.', $result['content'] );
	}

	/**
	 * export_markdown() uses 'Conversation' as title when title is empty.
	 */
	public function test_export_markdown_uses_fallback_title(): void {
		$session = $this->make_session( [ 'title' => '' ] );
		$result  = Export::export_markdown( $session );

		$this->assertStringContainsString( '# Conversation', $result['content'] );
		$this->assertSame( 'conversation.md', $result['filename'] );
	}

	/**
	 * export_markdown() includes model info in the header.
	 */
	public function test_export_markdown_includes_model_info(): void {
		$session = $this->make_session();
		$result  = Export::export_markdown( $session );

		$this->assertStringContainsString( 'gpt-4o', $result['content'] );
	}

	// ── import_json ───────────────────────────────────────────────────────────

	/**
	 * import_json() returns WP_Error for missing format field.
	 */
	public function test_import_json_returns_wp_error_for_missing_format(): void {
		$result = Export::import_json( [ 'messages' => [ [] ] ], 1 );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_import_invalid', $result->get_error_code() );
	}

	/**
	 * import_json() returns WP_Error for invalid format value.
	 */
	public function test_import_json_returns_wp_error_for_invalid_format(): void {
		$result = Export::import_json(
			[
				'format'   => 'unknown-format',
				'messages' => [ [] ],
			],
			1
		);

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_import_invalid', $result->get_error_code() );
	}

	/**
	 * import_json() returns WP_Error when messages array is empty.
	 */
	public function test_import_json_returns_wp_error_for_empty_messages(): void {
		$result = Export::import_json(
			[
				'format'   => 'sd-ai-agent-v1',
				'messages' => [],
			],
			1
		);

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_import_no_messages', $result->get_error_code() );
	}

	/**
	 * import_json() returns WP_Error when messages is not an array.
	 */
	public function test_import_json_returns_wp_error_when_messages_not_array(): void {
		$result = Export::import_json(
			[
				'format'   => 'sd-ai-agent-v1',
				'messages' => 'not an array',
			],
			1
		);

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_import_no_messages', $result->get_error_code() );
	}

	/**
	 * import_json() accepts the legacy 'ai-agent-v1' format.
	 */
	public function test_import_json_accepts_legacy_format(): void {
		$user_id = self::factory()->user->create();

		$result = Export::import_json(
			[
				'format'   => 'ai-agent-v1',
				'title'    => 'Legacy Import',
				'messages' => [ [ 'role' => 'user', 'parts' => [ [ 'text' => 'Hi' ] ] ] ],
			],
			$user_id
		);

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
	}

	/**
	 * import_json() creates a session and returns its ID on success.
	 */
	public function test_import_json_creates_session_on_success(): void {
		$user_id = self::factory()->user->create();

		$result = Export::import_json(
			[
				'format'      => 'sd-ai-agent-v1',
				'title'       => 'Imported Chat',
				'provider_id' => 'openai',
				'model_id'    => 'gpt-4o',
				'messages'    => [
					[ 'role' => 'user', 'parts' => [ [ 'text' => 'Hello' ] ] ],
				],
				'tool_calls'  => [],
			],
			$user_id
		);

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
	}
}
