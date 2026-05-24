<?php

declare(strict_types=1);
/**
 * Tests for DeepSeek reasoning_content round-trip in conversation history.
 *
 * DeepSeek thinking-mode models (V3.1+ reasoning, V4 Flash/Pro) emit
 * reasoning_content in assistant messages. This content must be passed
 * back to the API in subsequent requests, or the API returns a 400 error:
 * "The `reasoning_content` in the thinking mode must be passed back to the API."
 *
 * This test verifies that:
 * 1. reasoning_content from a DeepSeek response is preserved in the Message
 * 2. When the message is serialized for the next API request, reasoning_content
 *    is included in the request body
 * 3. Non-DeepSeek models do not include reasoning_content in requests
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\AgentLoop;
use SdAiAgent\Core\ConversationSerializer;
use SdAiAgent\Core\ProviderCredentialLoader;
use SdAiAgent\Core\Settings;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use WP_UnitTestCase;

/**
 * Tests for DeepSeek reasoning_content round-trip.
 *
 * @group agent-loop
 * @group deepseek
 * @group reasoning-content
 */
class DeepSeekReasoningContentTest extends WP_UnitTestCase {

	/** @var string Fake endpoint URL used in tests. */
	private const FAKE_ENDPOINT = 'http://fake-ai-proxy.test';

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		// Point AgentLoop at the fake endpoint.
		update_option( 'openai_compat_endpoint_url', self::FAKE_ENDPOINT );
		update_option( 'openai_compat_api_key', 'test-key' );

		// Reset settings to defaults.
		delete_option( Settings::OPTION_NAME );
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down(): void {
		parent::tear_down();

		delete_option( 'openai_compat_endpoint_url' );
		delete_option( 'openai_compat_api_key' );
		delete_option( Settings::OPTION_NAME );

		// Remove any lingering pre_http_request filters added by tests.
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that reasoning_content from a DeepSeek response is preserved.
	 *
	 * When DeepSeek returns a response with reasoning_content, it should be
	 * stored as a MessagePart with the thought channel.
	 */
	public function test_deepseek_reasoning_content_preserved_in_message(): void {
		$this->skip_if_sdk_unavailable();
		$this->skip_if_provider_unavailable();

		// Simulate a DeepSeek response with reasoning_content.
		$reasoning_text = 'Let me think about this step by step...';
		$response_text  = 'I will help you with this task.';

		$http_response = [
			'choices' => [
				[
					'message' => [
						'role'               => 'assistant',
						'reasoning_content'  => $reasoning_text,
						'content'            => $response_text,
						'tool_calls'         => [],
					],
					'finish_reason' => 'stop',
				],
			],
			'usage'   => [
				'prompt_tokens'     => 10,
				'completion_tokens' => 20,
			],
		];

		// Mock the HTTP response.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $http_response ) {
				if ( strpos( $url, self::FAKE_ENDPOINT ) === 0 ) {
					return [
						'headers'       => [ 'content-type' => 'application/json' ],
						'body'          => wp_json_encode( $http_response ),
						'response'      => [ 'code' => 200 ],
						'cookies'       => [],
						'http_response' => null,
					];
				}
				return $preempt;
			},
			10,
			3
		);

		// Create an AgentLoop and run it.
		$loop = new AgentLoop(
			'Test prompt',
			[],
			[],
			[
				'provider_id' => 'openai_compat',
				'model_id'    => 'deepseek-reasoner',
			]
		);

		$result = $loop->run();

		// Verify the result is not an error.
		$this->assertNotWPError( $result );

		// Deserialize the history to check the message structure.
		$history = ConversationSerializer::deserialize( $result['history'] );

		// Find the assistant message (should be the last one before the user message).
		$assistant_message = null;
		foreach ( array_reverse( $history ) as $msg ) {
			if ( $msg->getRole()->isModel() ) {
				$assistant_message = $msg;
				break;
			}
		}

		$this->assertNotNull( $assistant_message, 'Assistant message not found in history' );

		// Check that the message has parts with reasoning_content.
		$parts = $assistant_message->getParts();
		$this->assertNotEmpty( $parts, 'Assistant message has no parts' );

		// Find the thought-channel part.
		$thought_part = null;
		foreach ( $parts as $part ) {
			if ( $part->getChannel()->isThought() && $part->getType()->isText() ) {
				$thought_part = $part;
				break;
			}
		}

		$this->assertNotNull( $thought_part, 'Thought-channel part not found in assistant message' );
		$this->assertSame( $reasoning_text, $thought_part->getText() );
	}

	/**
	 * Test that reasoning_content is included in subsequent API requests for DeepSeek models.
	 *
	 * When a DeepSeek thinking model is used and the conversation history includes
	 * reasoning_content, the next API request should include reasoning_content in
	 * the message body.
	 */
	public function test_deepseek_reasoning_content_included_in_next_request(): void {
		$this->skip_if_sdk_unavailable();
		$this->skip_if_provider_unavailable();

		$reasoning_text = 'Analyzing the request...';
		$response_text  = 'Here is my response.';

		// Track the second request to verify reasoning_content is included.
		$second_request_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$second_request_body, $reasoning_text, $response_text ) {
				if ( strpos( $url, self::FAKE_ENDPOINT ) === 0 ) {
					// Capture the request body on the second call.
					if ( $second_request_body === null && isset( $args['body'] ) ) {
						$second_request_body = $args['body'];
					}

					// Return a simple response.
					$http_response = [
						'choices' => [
							[
								'message' => [
									'role'               => 'assistant',
									'reasoning_content'  => $reasoning_text,
									'content'            => $response_text,
									'tool_calls'         => [],
								],
								'finish_reason' => 'stop',
							],
						],
						'usage'   => [
							'prompt_tokens'     => 10,
							'completion_tokens' => 20,
						],
					];

					return [
						'headers'       => [ 'content-type' => 'application/json' ],
						'body'          => wp_json_encode( $http_response ),
						'response'      => [ 'code' => 200 ],
						'cookies'       => [],
						'http_response' => null,
					];
				}
				return $preempt;
			},
			10,
			3
		);

		// Create a history with a message that has reasoning_content.
		$history = [
			new UserMessage( [ new MessagePart( 'First prompt' ) ] ),
			new ModelMessage(
				[
					new MessagePart( $reasoning_text, MessagePartChannelEnum::thought() ),
					new MessagePart( 'First response' ),
				]
			),
			new UserMessage( [ new MessagePart( 'Follow-up prompt' ) ] ),
		];

		// Create an AgentLoop with the history.
		$loop = new AgentLoop(
			'Follow-up prompt',
			[],
			$history,
			[
				'provider_id' => 'openai_compat',
				'model_id'    => 'deepseek-reasoner',
			]
		);

		$result = $loop->run();

		// Verify the result is not an error.
		$this->assertNotWPError( $result );

		// Verify that the second request included reasoning_content.
		$this->assertNotNull( $second_request_body, 'Second request body was not captured' );

		$request_data = json_decode( $second_request_body, true );
		$this->assertIsArray( $request_data, 'Request body is not valid JSON' );
		$this->assertArrayHasKey( 'messages', $request_data );

		// Find the message with reasoning_content.
		$found_reasoning_content = false;
		foreach ( $request_data['messages'] as $msg ) {
			if ( isset( $msg['reasoning_content'] ) && $msg['reasoning_content'] === $reasoning_text ) {
				$found_reasoning_content = true;
				break;
			}
		}

		$this->assertTrue(
			$found_reasoning_content,
			'reasoning_content not found in second request for DeepSeek model'
		);
	}

	/**
	 * Test that reasoning_content appears on the tool_call assistant message when
	 * the history was produced by ConversationSerializer splitting a thinking+tool_call
	 * DeepSeek response into two separate ModelMessages (GH#1814).
	 *
	 * DeepSeek V4 Flash thinking-mode responses include both reasoning_content and
	 * tool_calls. ConversationSerializer::append_assistant_message() splits them into:
	 *   [ModelMessage(thought_part), ModelMessage(function_call_part)]
	 * to satisfy the OpenAI Responses API constraint. On the next request DeepSeek
	 * requires reasoning_content and tool_calls in the SAME assistant message, or it
	 * returns a 400 error. The base class mergeDeepSeekSplitReasoningMessages() must
	 * re-merge the pair at the wire level before the HTTP request is sent.
	 */
	public function test_deepseek_reasoning_content_on_tool_call_message_after_split(): void {
		$this->skip_if_sdk_unavailable();
		$this->skip_if_provider_unavailable();

		$reasoning_text = 'Let me fetch that post for you.';

		// Track the request body sent to the fake endpoint.
		$request_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$request_body ) {
				if ( strpos( $url, self::FAKE_ENDPOINT ) === 0 ) {
					if ( isset( $args['body'] ) ) {
						$request_body = $args['body'];
					}

					return [
						'headers'       => [ 'content-type' => 'application/json' ],
						'body'          => wp_json_encode( [
							'choices' => [
								[
									'message'       => [
										'role'    => 'assistant',
										'content' => 'Here is the post content.',
									],
									'finish_reason' => 'stop',
								],
							],
							'usage'   => [
								'prompt_tokens'     => 10,
								'completion_tokens' => 10,
							],
						] ),
						'response'      => [ 'code' => 200 ],
						'cookies'       => [],
						'http_response' => null,
					];
				}
				return $preempt;
			},
			10,
			3
		);

		// Build a history that mirrors what ConversationSerializer produces after splitting
		// a DeepSeek thinking-mode response that contained both reasoning_content and tool_calls:
		//   - ModelMessage([thought_part])     — reasoning-only message (first half of split)
		//   - ModelMessage([function_call])    — tool-call-only message (second half of split)
		//   - UserMessage([function_response]) — tool result
		$history = [
			new UserMessage( [ new MessagePart( 'What blocks are on wave2-bindings-test?' ) ] ),
			// Split half 1: reasoning-only.
			new ModelMessage( [ new MessagePart( $reasoning_text, MessagePartChannelEnum::thought() ) ] ),
			// Split half 2: function_call-only (no thought part).
			new ModelMessage( [ new MessagePart( new FunctionCall( 'call_1', 'wpab__sd-ai-agent__get-post', [ 'id' => 1 ] ) ) ] ),
			// Tool result.
			new UserMessage( [ new MessagePart( new FunctionResponse( 'call_1', 'wpab__sd-ai-agent__get-post', '{"title":"Test Post"}' ) ) ] ),
		];

		$loop = new AgentLoop(
			'Now summarize the post.',
			[],
			$history,
			[
				'provider_id' => 'openai_compat',
				'model_id'    => 'deepseek-reasoner',
			]
		);

		$result = $loop->run();
		$this->assertNotWPError( $result );
		$this->assertNotNull( $request_body, 'Request body was not captured' );

		$request_data = json_decode( $request_body, true );
		$this->assertIsArray( $request_data, 'Request body is not valid JSON' );
		$this->assertArrayHasKey( 'messages', $request_data );

		// After the split+merge fix, the wire messages should contain ONE assistant
		// entry with BOTH tool_calls AND reasoning_content (the merged form).
		// Verify that reasoning_content appears on the message that has tool_calls.
		$found_reasoning_on_tool_call_message = false;
		foreach ( $request_data['messages'] as $msg ) {
			if ( ! empty( $msg['tool_calls'] ) && isset( $msg['reasoning_content'] ) && '' !== $msg['reasoning_content'] ) {
				$this->assertSame(
					$reasoning_text,
					$msg['reasoning_content'],
					'reasoning_content on tool_call message must match the original thought text'
				);
				$found_reasoning_on_tool_call_message = true;
				break;
			}
		}

		$this->assertTrue(
			$found_reasoning_on_tool_call_message,
			'Expected reasoning_content to appear on the assistant message with tool_calls (GH#1814 regression: split messages were not merged)'
		);
	}

	/**
	 * Test that non-DeepSeek models do not include reasoning_content in requests.
	 *
	 * Models like Claude or GPT-4 should not include reasoning_content even if
	 * a thought-channel part is present.
	 */
	public function test_non_deepseek_models_exclude_reasoning_content(): void {
		$this->skip_if_sdk_unavailable();
		$this->skip_if_provider_unavailable();

		// Track the request to verify reasoning_content is NOT included.
		$request_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$request_body ) {
				if ( strpos( $url, self::FAKE_ENDPOINT ) === 0 ) {
					if ( isset( $args['body'] ) ) {
						$request_body = $args['body'];
					}

					$http_response = [
						'choices' => [
							[
								'message' => [
									'role'       => 'assistant',
									'content'    => 'Response from non-DeepSeek model',
									'tool_calls' => [],
								],
								'finish_reason' => 'stop',
							],
						],
						'usage'   => [
							'prompt_tokens'     => 10,
							'completion_tokens' => 20,
						],
					];

					return [
						'headers'       => [ 'content-type' => 'application/json' ],
						'body'          => wp_json_encode( $http_response ),
						'response'      => [ 'code' => 200 ],
						'cookies'       => [],
						'http_response' => null,
					];
				}
				return $preempt;
			},
			10,
			3
		);

		// Create a history with a message that has a thought-channel part.
		$history = [
			new UserMessage( [ new MessagePart( 'First prompt' ) ] ),
			new ModelMessage(
				[
					new MessagePart( 'Some thinking', MessagePartChannelEnum::thought() ),
					new MessagePart( 'First response' ),
				]
			),
			new UserMessage( [ new MessagePart( 'Follow-up prompt' ) ] ),
		];

		// Create an AgentLoop with a non-DeepSeek model.
		$loop = new AgentLoop(
			'Follow-up prompt',
			[],
			$history,
			[
				'provider_id' => 'openai_compat',
				'model_id'    => 'gpt-4o',
			]
		);

		$result = $loop->run();

		// Verify the result is not an error.
		$this->assertNotWPError( $result );

		// Verify that reasoning_content is NOT in the request.
		$this->assertNotNull( $request_body, 'Request body was not captured' );

		$request_data = json_decode( $request_body, true );
		$this->assertIsArray( $request_data, 'Request body is not valid JSON' );
		$this->assertArrayHasKey( 'messages', $request_data );

		// Verify no message has reasoning_content.
		foreach ( $request_data['messages'] as $msg ) {
			$this->assertArrayNotHasKey(
				'reasoning_content',
				$msg,
				'reasoning_content should not be in request for non-DeepSeek model'
			);
		}
	}

	/**
	 * Skip the test if wp_ai_client_prompt() is unavailable.
	 */
	private function skip_if_sdk_unavailable(): void {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'wp_ai_client_prompt() is not available — requires WordPress 7.0+.' );
		}

		if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			$this->markTestSkipped( 'WordPress\AiClient\AiClient class not available.' );
		}
	}

	/**
	 * Skip the test if the openai_compat provider is not available.
	 */
	private function skip_if_provider_unavailable(): void {
		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			if ( ! $registry->hasProvider( 'openai_compat' ) ) {
				$this->markTestSkipped( 'openai_compat provider not available in registry.' );
			}
		} catch ( \Throwable $e ) {
			$this->markTestSkipped( 'Could not check provider availability: ' . $e->getMessage() );
		}
	}
}
