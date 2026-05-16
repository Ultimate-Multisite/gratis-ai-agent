<?php

declare(strict_types=1);
/**
 * Test case for ProviderTraceLogger::classify_truncation().
 *
 * Covers the three classification outcomes:
 *   - 'truncated_tool_call'        : finish=length AND a partial tool call.
 *   - 'truncated_before_tool_call' : finish=length AND non-empty text AND no tool call.
 *   - ''                           : everything else.
 *
 * Provider response shapes covered: OpenAI-compatible (`choices[].message`),
 * Anthropic (`content[]` with type-tagged blocks), and Gemini (`candidates[]`
 * with `content.parts[]`).
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\ProviderTraceLogger;
use WP_UnitTestCase;

/**
 * @covers \SdAiAgent\Core\ProviderTraceLogger::classify_truncation
 */
class ProviderTraceLoggerTruncationTest extends WP_UnitTestCase {

	// --- OpenAI-compatible shape (choices[].message) ---------------------

	public function test_openai_length_with_partial_tool_call_returns_truncated_tool_call(): void {
		$decoded = [
			'choices' => [
				[
					'finish_reason' => 'length',
					'message'       => [
						'role'       => 'assistant',
						'content'    => null,
						'tool_calls' => [
							[
								'id'       => 'call_1',
								'type'     => 'function',
								'function' => [
									'name'      => 'sd-ai-agent/create-post',
									// Truncated mid-JSON.
									'arguments' => '{"title":"Landing Page","content":"<!-- wp:heading -->',
								],
							],
						],
					],
				],
			],
		];

		$this->assertSame(
			'truncated_tool_call',
			ProviderTraceLogger::classify_truncation( $decoded )
		);
	}

	public function test_openai_length_with_preamble_only_returns_before_tool_call(): void {
		// The actual Kimi K2.6 stall: model emitted a one-line lead-in, hit
		// finish=length, never produced a tool call.
		$decoded = [
			'choices' => [
				[
					'finish_reason' => 'length',
					'message'       => [
						'role'    => 'assistant',
						'content' => "Now I'll create the full landing page with professional Gutenberg block markup:",
					],
				],
			],
		];

		$this->assertSame(
			'truncated_before_tool_call',
			ProviderTraceLogger::classify_truncation( $decoded )
		);
	}

	public function test_openai_length_with_empty_content_returns_empty(): void {
		// Empty response with length finish is almost always a provider bug,
		// not a recoverable preamble truncation — must not trigger the
		// retry guidance path.
		$decoded = [
			'choices' => [
				[
					'finish_reason' => 'length',
					'message'       => [
						'role'    => 'assistant',
						'content' => '',
					],
				],
			],
		];

		$this->assertSame( '', ProviderTraceLogger::classify_truncation( $decoded ) );
	}

	public function test_openai_length_with_whitespace_only_content_returns_empty(): void {
		$decoded = [
			'choices' => [
				[
					'finish_reason' => 'length',
					'message'       => [
						'role'    => 'assistant',
						'content' => "   \n\t  ",
					],
				],
			],
		];

		$this->assertSame( '', ProviderTraceLogger::classify_truncation( $decoded ) );
	}

	public function test_openai_stop_finish_with_text_returns_empty(): void {
		// Normal completion — model wanted to stop here.
		$decoded = [
			'choices' => [
				[
					'finish_reason' => 'stop',
					'message'       => [
						'role'    => 'assistant',
						'content' => 'Here is your answer.',
					],
				],
			],
		];

		$this->assertSame( '', ProviderTraceLogger::classify_truncation( $decoded ) );
	}

	public function test_openai_max_tokens_alias_treated_as_length(): void {
		$decoded = [
			'choices' => [
				[
					'finish_reason' => 'max_tokens',
					'message'       => [
						'role'    => 'assistant',
						'content' => 'Working on it now.',
					],
				],
			],
		];

		$this->assertSame(
			'truncated_before_tool_call',
			ProviderTraceLogger::classify_truncation( $decoded )
		);
	}

	// --- Anthropic shape (content[] with type-tagged blocks) -------------

	public function test_anthropic_max_tokens_with_partial_tool_use_returns_truncated_tool_call(): void {
		$decoded = [
			'stop_reason' => 'max_tokens',
			'content'     => [
				[
					'type' => 'text',
					'text' => 'Calling the create-post tool now.',
				],
				[
					'type'  => 'tool_use',
					'id'    => 'toolu_1',
					'name'  => 'sd-ai-agent/create-post',
					'input' => [ 'title' => 'Page' ],
				],
			],
		];

		$this->assertSame(
			'truncated_tool_call',
			ProviderTraceLogger::classify_truncation( $decoded )
		);
	}

	public function test_anthropic_max_tokens_text_only_returns_before_tool_call(): void {
		$decoded = [
			'stop_reason' => 'max_tokens',
			'content'     => [
				[
					'type' => 'text',
					'text' => "Let me create that landing page for you.",
				],
			],
		];

		$this->assertSame(
			'truncated_before_tool_call',
			ProviderTraceLogger::classify_truncation( $decoded )
		);
	}

	public function test_anthropic_end_turn_with_text_returns_empty(): void {
		$decoded = [
			'stop_reason' => 'end_turn',
			'content'     => [
				[
					'type' => 'text',
					'text' => 'Done.',
				],
			],
		];

		$this->assertSame( '', ProviderTraceLogger::classify_truncation( $decoded ) );
	}

	// --- Gemini shape (candidates[] with content.parts[]) ----------------

	public function test_gemini_max_tokens_with_partial_function_call_returns_truncated_tool_call(): void {
		$decoded = [
			'candidates' => [
				[
					'finishReason' => 'MAX_TOKENS',
					'content'      => [
						'parts' => [
							[ 'text' => 'Calling the tool.' ],
							[
								'functionCall' => [
									'name' => 'sd-ai-agent/create-post',
									'args' => [ 'title' => 'Hi' ],
								],
							],
						],
					],
				],
			],
		];

		$this->assertSame(
			'truncated_tool_call',
			ProviderTraceLogger::classify_truncation( $decoded )
		);
	}

	public function test_gemini_max_tokens_text_only_returns_before_tool_call(): void {
		$decoded = [
			'candidates' => [
				[
					'finishReason' => 'MAX_TOKENS',
					'content'      => [
						'parts' => [
							[ 'text' => 'I will now build the page section by section.' ],
						],
					],
				],
			],
		];

		$this->assertSame(
			'truncated_before_tool_call',
			ProviderTraceLogger::classify_truncation( $decoded )
		);
	}

	// --- Edge cases ------------------------------------------------------

	public function test_non_array_input_returns_empty(): void {
		$this->assertSame( '', ProviderTraceLogger::classify_truncation( null ) );
		$this->assertSame( '', ProviderTraceLogger::classify_truncation( 'string' ) );
		$this->assertSame( '', ProviderTraceLogger::classify_truncation( 42 ) );
	}

	public function test_empty_array_returns_empty(): void {
		$this->assertSame( '', ProviderTraceLogger::classify_truncation( [] ) );
	}

	public function test_missing_finish_reason_returns_empty(): void {
		$decoded = [
			'choices' => [
				[
					'message' => [
						'role'    => 'assistant',
						'content' => "Now I'll write the page:",
					],
				],
			],
		];

		$this->assertSame( '', ProviderTraceLogger::classify_truncation( $decoded ) );
	}

	public function test_finish_reason_case_and_hyphens_normalized(): void {
		// "MAX-TOKENS" → "max_tokens" via the strtolower + replace pipeline.
		$decoded = [
			'choices' => [
				[
					'finish_reason' => 'MAX-TOKENS',
					'message'       => [
						'role'    => 'assistant',
						'content' => 'Some preamble text.',
					],
				],
			],
		];

		$this->assertSame(
			'truncated_before_tool_call',
			ProviderTraceLogger::classify_truncation( $decoded )
		);
	}

	public function test_multiple_candidates_partial_tool_call_takes_precedence(): void {
		// If any candidate has a partial tool call, that's the higher-risk
		// classification and should win over a sibling preamble-only one.
		$decoded = [
			'choices' => [
				[
					'finish_reason' => 'length',
					'message'       => [
						'role'    => 'assistant',
						'content' => 'Preamble only.',
					],
				],
				[
					'finish_reason' => 'length',
					'message'       => [
						'role'       => 'assistant',
						'content'    => null,
						'tool_calls' => [
							[
								'id'       => 'call_1',
								'type'     => 'function',
								'function' => [ 'name' => 'foo', 'arguments' => '{"x":' ],
							],
						],
					],
				],
			],
		];

		$this->assertSame(
			'truncated_tool_call',
			ProviderTraceLogger::classify_truncation( $decoded )
		);
	}
}
