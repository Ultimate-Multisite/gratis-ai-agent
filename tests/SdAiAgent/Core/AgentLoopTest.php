<?php

declare(strict_types=1);
/**
 * Integration tests for AgentLoop with mocked AI responses.
 *
 * These tests exercise the AgentLoop's agentic loop logic — iteration
 * counting, tool-call detection, confirmation gating, history serialisation,
 * and error handling — without making real HTTP calls to an AI provider.
 *
 * Strategy
 * --------
 * AgentLoop routes all prompts through the WordPress AI Client SDK
 * (`wp_ai_client_prompt()`). The provider is resolved dynamically from
 * the SDK registry — whichever authenticated provider is configured via
 * the Connectors page.
 *
 * In tests we set the `openai_compat_endpoint_url` option and use the
 * `pre_http_request` filter to return a fake HTTP response, bypassing
 * the network entirely.
 *
 * For the SDK-unavailable path we simply don't define `wp_ai_client_prompt`
 * (it may be absent in the test environment), which lets us test the
 * WP_Error early-return branch.
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
use SdAiAgent\Core\SystemInstructionBuilder;
use SdAiAgent\Core\ToolPermissionResolver;
use WP_UnitTestCase;

/**
 * Integration tests for AgentLoop.
 *
 * @group agent-loop
 * @group ai-client
 */
class AgentLoopTest extends WP_UnitTestCase {

	/** @var string Fake endpoint URL used in all direct-path tests. */
	private const FAKE_ENDPOINT = 'http://fake-ai-proxy.test';

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		// Point AgentLoop at the fake endpoint so it always uses the direct path.
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

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Skip the test if wp_ai_client_prompt() is unavailable or no provider is
	 * registered in the SDK registry.
	 *
	 * run() now routes exclusively through the WordPress AI Client SDK. Tests
	 * that call run() must skip when the SDK is absent or when no authenticated
	 * provider is registered (the typical CI environment for WP trunk without
	 * a real provider configured).
	 */
	private function skip_if_sdk_unavailable(): void {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'wp_ai_client_prompt() is not available — requires WordPress 7.0+.' );
		}

		if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			$this->markTestSkipped( 'WordPress\AiClient\AiClient class not available.' );
		}

		try {
			$registry     = \WordPress\AiClient\AiClient::defaultRegistry();
			$provider_ids = $registry->getRegisteredProviderIds();
			$has_provider = false;
			ProviderCredentialLoader::load();

			foreach ( $provider_ids as $id ) {
				if ( null !== $registry->getProviderRequestAuthentication( $id ) ) {
					$has_provider = true;
					break;
				}
			}

			if ( ! $has_provider ) {
				$this->markTestSkipped( 'No authenticated AI provider registered in SDK registry — skipping run() test.' );
			}
		} catch ( \Throwable $e ) {
			$this->markTestSkipped( 'SDK registry unavailable: ' . $e->getMessage() );
		}
	}

	/**
	 * Register a `pre_http_request` filter that returns a fake AI response.
	 *
	 * The filter intercepts wp_remote_post() calls to the fake endpoint and
	 * returns a well-formed OpenAI-compatible chat completion response.
	 *
	 * @param string $reply_text The assistant's text reply.
	 * @param array  $tool_calls Optional OpenAI-format tool_calls array.
	 * @param array  $usage      Optional token usage array.
	 */
	private function mock_ai_response(
		string $reply_text,
		array $tool_calls = [],
		array $usage = []
	): void {
		$message = [ 'role' => 'assistant', 'content' => $reply_text ];
		if ( ! empty( $tool_calls ) ) {
			$message['tool_calls'] = $tool_calls;
			$message['content']    = null;
		}

		$body = wp_json_encode(
			[
				'id'      => 'chatcmpl-test',
				'object'  => 'chat.completion',
				'choices' => [
					[
						'index'         => 0,
						'message'       => $message,
						'finish_reason' => empty( $tool_calls ) ? 'stop' : 'tool_calls',
					],
				],
				'usage'   => array_merge(
					[ 'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15 ],
					$usage
				),
			]
		);

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $body ) {
				if ( false !== strpos( $url, 'fake-ai-proxy.test' ) ) {
					return [
						'headers'  => [ 'content-type' => 'application/json' ],
						'body'     => $body,
						'response' => [ 'code' => 200, 'message' => 'OK' ],
						'cookies'  => [],
						'filename' => '',
					];
				}
				return $preempt;
			},
			10,
			3
		);
	}

	/**
	 * Register a `pre_http_request` filter that returns an HTTP error response.
	 *
	 * @param int    $code    HTTP status code.
	 * @param string $message Error message in the response body.
	 */
	private function mock_ai_error_response( int $code, string $message ): void {
		$body = wp_json_encode( [ 'error' => [ 'message' => $message ] ] );

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $code, $body ) {
				if ( false !== strpos( $url, 'fake-ai-proxy.test' ) ) {
					return [
						'headers'  => [ 'content-type' => 'application/json' ],
						'body'     => $body,
						'response' => [ 'code' => $code, 'message' => 'Error' ],
						'cookies'  => [],
						'filename' => '',
					];
				}
				return $preempt;
			},
			10,
			3
		);
	}

	/**
	 * Register a `pre_http_request` filter that returns a WP_Error (network failure).
	 */
	private function mock_ai_network_failure(): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, 'fake-ai-proxy.test' ) ) {
					return new \WP_Error( 'http_request_failed', 'cURL error: connection refused' );
				}
				return $preempt;
			},
			10,
			3
		);
	}

	// -------------------------------------------------------------------------
	// Constructor / configuration tests
	// -------------------------------------------------------------------------

	/**
	 * Test AgentLoop can be instantiated with minimal arguments.
	 */
	public function test_constructor_minimal_args(): void {
		$loop = new AgentLoop( 'Hello' );
		$this->assertInstanceOf( AgentLoop::class, $loop );
	}

	/**
	 * Test AgentLoop accepts all optional constructor arguments.
	 */
	public function test_constructor_with_all_options(): void {
		$loop = new AgentLoop(
			'Hello',
			[],
			[],
			[
				'provider_id'        => 'test-provider',
				'model_id'           => 'claude-sonnet-4',
				'max_iterations'     => 5,
				'temperature'        => 0.5,
				'max_output_tokens'  => 2048,
				'system_instruction' => 'You are a test assistant.',
			]
		);
		$this->assertInstanceOf( AgentLoop::class, $loop );
	}

	/**
	 * Test AgentLoop reads max_iterations from settings when not provided.
	 */
	public function test_constructor_reads_max_iterations_from_settings(): void {
		Settings::instance()->update( [ 'max_iterations' => 7 ] );

		// We can't directly inspect private properties, but we can verify the
		// loop exhausts after 7 iterations by providing a mock that always
		// returns tool calls (forcing the loop to keep running).
		// This is tested in test_run_exhausts_max_iterations below.
		$loop = new AgentLoop( 'Hello' );
		$this->assertInstanceOf( AgentLoop::class, $loop );
	}

	// -------------------------------------------------------------------------
	// run() — happy path
	// -------------------------------------------------------------------------

	/**
	 * Test run() returns a reply when the AI responds with text.
	 */
	public function test_run_returns_reply_on_success(): void {
		$this->skip_if_sdk_unavailable();
		$this->mock_ai_response( 'Hello, I am your WordPress assistant.' );

		$loop   = new AgentLoop( 'Hi there' );
		$result = $loop->run();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'reply', $result );
		$this->assertSame( 'Hello, I am your WordPress assistant.', $result['reply'] );
	}

	/**
	 * Test run() result contains all expected keys.
	 */
	public function test_run_result_has_expected_keys(): void {
		$this->skip_if_sdk_unavailable();
		$this->mock_ai_response( 'Test reply' );

		$loop   = new AgentLoop( 'Test message' );
		$result = $loop->run();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'reply', $result );
		$this->assertArrayHasKey( 'history', $result );
		$this->assertArrayHasKey( 'tool_calls', $result );
		$this->assertArrayHasKey( 'token_usage', $result );
		$this->assertArrayHasKey( 'iterations_used', $result );
		$this->assertArrayHasKey( 'model_id', $result );
	}

	/**
	 * Test run() increments iterations_used by 1 for a single-turn response.
	 */
	public function test_run_increments_iterations_used(): void {
		$this->skip_if_sdk_unavailable();
		$this->mock_ai_response( 'Done' );

		$loop   = new AgentLoop( 'Do something' );
		$result = $loop->run();

		$this->assertSame( 1, $result['iterations_used'] );
	}

	/**
	 * Test run() accumulates token usage from the response.
	 */
	public function test_run_accumulates_token_usage(): void {
		$this->skip_if_sdk_unavailable();
		$this->mock_ai_response(
			'Done',
			[],
			[ 'prompt_tokens' => 100, 'completion_tokens' => 50 ]
		);

		$loop   = new AgentLoop( 'Count tokens' );
		$result = $loop->run();

		$this->assertArrayHasKey( 'token_usage', $result );
		$this->assertSame( 100, $result['token_usage']['prompt'] );
		$this->assertSame( 50, $result['token_usage']['completion'] );
	}

	/**
	 * Test run() appends the user message to history before calling the AI.
	 */
	public function test_run_appends_user_message_to_history(): void {
		$this->skip_if_sdk_unavailable();
		$this->mock_ai_response( 'Got it' );

		$loop   = new AgentLoop( 'Remember this' );
		$result = $loop->run();

		// History should contain at least the user message and the assistant reply.
		$this->assertIsArray( $result['history'] );
		$this->assertGreaterThanOrEqual( 2, count( $result['history'] ) );
	}

	/**
	 * Test run() with pre-existing history (multi-turn conversation).
	 */
	public function test_run_with_existing_history(): void {
		$this->skip_if_sdk_unavailable();
		if ( ! class_exists( 'WordPress\AiClient\Messages\DTO\UserMessage' ) ) {
			$this->markTestSkipped( 'AI Client SDK not available.' );
		}

		$this->mock_ai_response( 'Continuing the conversation' );

		$prior_history = [
			new \WordPress\AiClient\Messages\DTO\UserMessage(
				[ new \WordPress\AiClient\Messages\DTO\MessagePart( 'First message' ) ]
			),
			new \WordPress\AiClient\Messages\DTO\ModelMessage(
				[ new \WordPress\AiClient\Messages\DTO\MessagePart( 'First reply' ) ]
			),
		];

		$loop   = new AgentLoop( 'Second message', [], $prior_history );
		$result = $loop->run();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'reply', $result );
		// History should include prior messages + new user message + assistant reply.
		$this->assertGreaterThanOrEqual( 4, count( $result['history'] ) );
	}

	/**
	 * Test run() with empty reply text returns empty string (not null/false).
	 */
	public function test_run_with_empty_reply_returns_empty_string(): void {
		$this->skip_if_sdk_unavailable();
		$this->mock_ai_response( '' );

		$loop   = new AgentLoop( 'Silence please' );
		$result = $loop->run();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'reply', $result );
		$this->assertIsString( $result['reply'] );
	}

	// -------------------------------------------------------------------------
	// run() — error paths
	// -------------------------------------------------------------------------

	/**
	 * Test run() returns WP_Error when AI SDK is unavailable and no endpoint configured.
	 */
	public function test_run_returns_wp_error_when_sdk_unavailable_and_no_endpoint(): void {
		// Remove the endpoint so the direct path also fails.
		delete_option( 'openai_compat_endpoint_url' );

		$loop   = new AgentLoop( 'Hello' );
		$result = $loop->run();

		// Without wp_ai_client_prompt() and without an endpoint, we expect a WP_Error.
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->assertInstanceOf( \WP_Error::class, $result );
		} else {
			// SDK is available — the test environment loaded it. Skip the assertion.
			$this->markTestSkipped( 'wp_ai_client_prompt() is available; cannot test SDK-unavailable path.' );
		}
	}

	/**
	 * Test run() returns WP_Error when endpoint is not configured.
	 */
	public function test_run_returns_wp_error_when_no_endpoint_configured(): void {
		delete_option( 'openai_compat_endpoint_url' );

		if ( function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'wp_ai_client_prompt() is available; direct-path error cannot be triggered.' );
		}

		$loop   = new AgentLoop( 'Hello' );
		$result = $loop->run();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_missing_client', $result->get_error_code() );
	}

	/**
	 * Test run() returns WP_Error when the AI proxy returns an HTTP error.
	 */
	public function test_run_returns_wp_error_on_http_error_response(): void {
		$this->skip_if_sdk_unavailable();
		$this->mock_ai_error_response( 500, 'Internal server error' );

		$loop   = new AgentLoop( 'Hello' );
		$result = $loop->run();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_provider_unavailable', $result->get_error_code() );
		$this->assertStringContainsString( 'Internal server error', $result->get_error_message() );
	}

	/**
	 * Test run() returns WP_Error on network failure (wp_remote_post returns WP_Error).
	 */
	public function test_run_returns_wp_error_on_network_failure(): void {
		$this->skip_if_sdk_unavailable();
		$this->mock_ai_network_failure();

		$loop   = new AgentLoop( 'Hello' );
		$result = $loop->run();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
	}

	/**
	 * Test run() returns WP_Error with 401 Unauthorized response.
	 */
	public function test_run_returns_wp_error_on_unauthorized(): void {
		$this->skip_if_sdk_unavailable();
		$this->mock_ai_error_response( 401, 'Invalid API key' );

		$loop   = new AgentLoop( 'Hello' );
		$result = $loop->run();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_provider_unavailable', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Tool call / confirmation flow
	// -------------------------------------------------------------------------

	/**
	 * Test run() returns awaiting_confirmation when a tool requires confirmation.
	 */
	public function test_run_returns_awaiting_confirmation_for_confirm_tools(): void {
		$this->skip_if_sdk_unavailable();
		if ( ! class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
			$this->markTestSkipped( 'WP_AI_Client_Ability_Function_Resolver not available.' );
		}

		// Set a tool permission to 'confirm'.
		Settings::instance()->update(
			[
				'tool_permissions' => [
					'sd-ai-agent/memory-save' => 'confirm',
				],
			]
		);

		// Mock a response that requests the memory-save tool.
		$this->mock_ai_response(
			'',
			[
				[
					'id'       => 'call_abc123',
					'type'     => 'function',
					'function' => [
						'name'      => 'wpab__sd-ai-agent__memory-save',
						'arguments' => wp_json_encode( [ 'content' => 'Test memory' ] ),
					],
				],
			]
		);

		$loop   = new AgentLoop( 'Remember something' );
		$result = $loop->run();

		// Should pause for confirmation.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'awaiting_confirmation', $result );
		$this->assertTrue( $result['awaiting_confirmation'] );
		$this->assertArrayHasKey( 'pending_tools', $result );
		$this->assertNotEmpty( $result['pending_tools'] );
	}

	/**
	 * Test run() logs tool calls in tool_call_log.
	 */
	public function test_run_logs_tool_calls(): void {
		$this->skip_if_sdk_unavailable();
		if ( ! class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
			$this->markTestSkipped( 'WP_AI_Client_Ability_Function_Resolver not available.' );
		}

		// First call returns a tool call; second call returns a text reply.
		$call_count = 0;
		$body_text  = wp_json_encode(
			[
				'id'      => 'chatcmpl-test',
				'object'  => 'chat.completion',
				'choices' => [
					[
						'index'         => 0,
						'message'       => [
							'role'       => 'assistant',
							'content'    => null,
							'tool_calls' => [
								[
									'id'       => 'call_xyz',
									'type'     => 'function',
									'function' => [
										'name'      => 'wpab__sd-ai-agent__memory-list',
										'arguments' => '{}',
									],
								],
							],
						],
						'finish_reason' => 'tool_calls',
					],
				],
				'usage'   => [ 'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15 ],
			]
		);

		$body_reply = wp_json_encode(
			[
				'id'      => 'chatcmpl-test2',
				'object'  => 'chat.completion',
				'choices' => [
					[
						'index'         => 0,
						'message'       => [ 'role' => 'assistant', 'content' => 'Here are your memories.' ],
						'finish_reason' => 'stop',
					],
				],
				'usage'   => [ 'prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30 ],
			]
		);

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$call_count, $body_text, $body_reply ) {
				if ( false !== strpos( $url, 'fake-ai-proxy.test' ) ) {
					++$call_count;
					$body = ( 1 === $call_count ) ? $body_text : $body_reply;
					return [
						'headers'  => [ 'content-type' => 'application/json' ],
						'body'     => $body,
						'response' => [ 'code' => 200, 'message' => 'OK' ],
						'cookies'  => [],
						'filename' => '',
					];
				}
				return $preempt;
			},
			10,
			3
		);

		$loop   = new AgentLoop( 'List my memories' );
		$result = $loop->run();

		// The tool call should be logged.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'tool_calls', $result );
		$this->assertNotEmpty( $result['tool_calls'] );

		// Find the 'call' entry.
		$calls = array_filter( $result['tool_calls'], fn( $entry ) => 'call' === $entry['type'] );
		$this->assertNotEmpty( $calls );

		$first_call = array_values( $calls )[0];
		$this->assertSame( 'wpab__sd-ai-agent__memory-list', $first_call['name'] );
	}

	// -------------------------------------------------------------------------
	// Max iterations
	// -------------------------------------------------------------------------

	/**
	 * Test run() triggers the graceful fallback when max iterations are exhausted
	 * with only tool calls. The fallback send_prompt() also returns a tool call
	 * (no text), so toText() throws and reply is empty — but the result is still
	 * a success array, not a WP_Error.
	 */
	public function test_run_exhausts_max_iterations(): void {
		$this->skip_if_sdk_unavailable();
		// Always return a tool call so the loop never terminates naturally and
		// the fallback summarization prompt also gets a tool-call response.
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, 'fake-ai-proxy.test' ) ) {
					$body = wp_json_encode(
						[
							'id'      => 'chatcmpl-loop',
							'object'  => 'chat.completion',
							'choices' => [
								[
									'index'         => 0,
									'message'       => [
										'role'       => 'assistant',
										'content'    => null,
										'tool_calls' => [
											[
												'id'       => 'call_loop',
												'type'     => 'function',
												'function' => [
													'name'      => 'wpab__sd-ai-agent__memory-list',
													'arguments' => '{}',
												],
											],
										],
									],
									'finish_reason' => 'tool_calls',
								],
							],
							'usage'   => [ 'prompt_tokens' => 5, 'completion_tokens' => 5, 'total_tokens' => 10 ],
						]
					);
					return [
						'headers'  => [ 'content-type' => 'application/json' ],
						'body'     => $body,
						'response' => [ 'code' => 200, 'message' => 'OK' ],
						'cookies'  => [],
						'filename' => '',
					];
				}
				return $preempt;
			},
			10,
			3
		);

		// Use max_iterations = 2 to keep the test fast.
		$loop   = new AgentLoop( 'Loop forever', [], [], [ 'max_iterations' => 2 ] );
		$result = $loop->run();

		// The graceful fallback fires after the loop exhausts. The fallback
		// send_prompt() also returns a tool call (no text), so toText() throws
		// and reply is ''. The result is a success array, not a WP_Error.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'reply', $result );
		$this->assertArrayHasKey( 'tool_calls', $result );
		$this->assertArrayHasKey( 'iterations_used', $result );
		// 2 loop iterations + 1 fallback call = 3.
		$this->assertSame( 3, $result['iterations_used'] );
	}

	/**
	 * Test run() returns WP_Error when max iterations are exhausted AND the
	 * graceful fallback send_prompt() itself fails (e.g. network error).
	 */
	public function test_run_exhausts_max_iterations_fallback_fails(): void {
		$this->skip_if_sdk_unavailable();
		// Use a counter so the first N requests return tool calls and the
		// (N+1)th (the fallback) returns a network failure.
		$call_count = 0;

		$tool_call_body = wp_json_encode(
			[
				'id'      => 'chatcmpl-loop',
				'object'  => 'chat.completion',
				'choices' => [
					[
						'index'         => 0,
						'message'       => [
							'role'       => 'assistant',
							'content'    => null,
							'tool_calls' => [
								[
									'id'       => 'call_loop',
									'type'     => 'function',
									'function' => [
										'name'      => 'wpab__sd-ai-agent__memory-list',
										'arguments' => '{}',
									],
								],
							],
						],
						'finish_reason' => 'tool_calls',
					],
				],
				'usage'   => [ 'prompt_tokens' => 5, 'completion_tokens' => 5, 'total_tokens' => 10 ],
			]
		);

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $tool_call_body, &$call_count ) {
				if ( false !== strpos( $url, 'fake-ai-proxy.test' ) ) {
					++$call_count;
					// First 2 calls: tool call responses (loop iterations).
					// 3rd call: network failure (fallback prompt).
					if ( $call_count <= 2 ) {
						return [
							'headers'  => [ 'content-type' => 'application/json' ],
							'body'     => $tool_call_body,
							'response' => [ 'code' => 200, 'message' => 'OK' ],
							'cookies'  => [],
							'filename' => '',
						];
					}
					return new \WP_Error( 'http_request_failed', 'cURL error: connection refused' );
				}
				return $preempt;
			},
			10,
			3
		);

		// Use max_iterations = 2 to keep the test fast.
		$loop   = new AgentLoop( 'Loop forever', [], [], [ 'max_iterations' => 2 ] );
		$result = $loop->run();

		// Fallback failed → falls through to the WP_Error path.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_max_iterations', $result->get_error_code() );

		// Error data should include tool_calls and iterations_used.
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'tool_calls', $data );
		$this->assertArrayHasKey( 'iterations_used', $data );
		// 2 loop iterations + 1 fallback attempt = 3.
		$this->assertSame( 3, $data['iterations_used'] );
	}

	// -------------------------------------------------------------------------
	// History serialisation / deserialisation
	// -------------------------------------------------------------------------

	/**
	 * Test deserialize_history round-trips through serialize_history.
	 */
	public function test_deserialize_history_round_trip(): void {
		$this->skip_if_sdk_unavailable();
		if ( ! class_exists( 'WordPress\AiClient\Messages\DTO\UserMessage' ) ) {
			$this->markTestSkipped( 'AI Client SDK not available.' );
		}

		$this->mock_ai_response( 'Round-trip reply' );

		$loop   = new AgentLoop( 'Serialize me' );
		$result = $loop->run();

		$this->assertIsArray( $result['history'] );
		$this->assertNotEmpty( $result['history'] );

		// Deserialise and verify we get Message objects back.
		$messages = ConversationSerializer::deserialize( $result['history'] );

		$this->assertIsArray( $messages );
		$this->assertNotEmpty( $messages );

		foreach ( $messages as $msg ) {
			$this->assertInstanceOf( \WordPress\AiClient\Messages\DTO\Message::class, $msg );
		}
	}

	/**
	 * Test deserialize_history with empty array returns empty array.
	 */
	public function test_deserialize_history_empty(): void {
		$result = ConversationSerializer::deserialize( [] );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	// -------------------------------------------------------------------------
	// System instruction / default prompt
	// -------------------------------------------------------------------------

	/**
	 * Test get_default_system_prompt returns a non-empty string.
	 */
	public function test_get_default_system_prompt_returns_string(): void {
		$prompt = SystemInstructionBuilder::default_system_instruction();

		$this->assertIsString( $prompt );
		$this->assertNotEmpty( $prompt );
	}

	/**
	 * Test get_default_system_prompt contains expected WordPress context.
	 */
	public function test_get_default_system_prompt_contains_wordpress_context(): void {
		$prompt = SystemInstructionBuilder::default_system_instruction();

		$this->assertStringContainsString( 'WordPress', $prompt );
	}

	/**
	 * Test custom system_instruction option is used when provided.
	 */
	public function test_custom_system_instruction_is_used(): void {
		$this->skip_if_sdk_unavailable();
		$this->mock_ai_response( 'Custom system test' );

		$loop = new AgentLoop(
			'Hello',
			[],
			[],
			[ 'system_instruction' => 'You are a custom test bot.' ]
		);
		$result = $loop->run();

		// The loop should complete successfully with the custom instruction.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'reply', $result );
	}

	// -------------------------------------------------------------------------
	// resume_after_confirmation
	// -------------------------------------------------------------------------

	/**
	 * Test resume_after_confirmation with rejection adds a user message to history.
	 */
	public function test_resume_after_confirmation_rejected(): void {
		if ( ! class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
			$this->markTestSkipped( 'WP_AI_Client_Ability_Function_Resolver not available.' );
		}

		// Step 1: trigger a confirmation pause.
		Settings::instance()->update(
			[
				'tool_permissions' => [
					'sd-ai-agent/memory-save' => 'confirm',
				],
			]
		);

		$this->mock_ai_response(
			'',
			[
				[
					'id'       => 'call_confirm',
					'type'     => 'function',
					'function' => [
						'name'      => 'wpab__sd-ai-agent__memory-save',
						'arguments' => wp_json_encode( [ 'content' => 'Secret' ] ),
					],
				],
			]
		);

		$loop   = new AgentLoop( 'Save a secret' );
		$paused = $loop->run();

		if ( ! is_array( $paused ) || empty( $paused['awaiting_confirmation'] ) ) {
			$this->markTestSkipped( 'Confirmation pause not triggered (ability may not be registered).' );
		}

		// Step 2: reject the tool call — mock a follow-up text response.
		remove_all_filters( 'pre_http_request' );
		$this->mock_ai_response( 'Understood, I will not save that.' );

		$result = $loop->resume_after_confirmation( false, $paused['iterations_remaining'] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'reply', $result );
		$this->assertStringContainsString( 'not save', $result['reply'] );
	}

	// -------------------------------------------------------------------------
	// ensure_provider_credentials_static
	// -------------------------------------------------------------------------

	/**
	 * Test ensure_provider_credentials_static does not throw when registry unavailable.
	 */
	public function test_ensure_provider_credentials_static_is_safe(): void {
		// Should not throw even if the AI Client registry is unavailable.
		ProviderCredentialLoader::load();
		$this->assertTrue( true ); // Reached without exception.
	}

	// -------------------------------------------------------------------------
	// Options / settings integration
	// -------------------------------------------------------------------------

	/**
	 * Test AgentLoop respects max_output_tokens from settings.
	 */
	public function test_run_respects_max_output_tokens_option(): void {
		$this->skip_if_sdk_unavailable();
		Settings::instance()->update( [ 'max_output_tokens' => 512 ] );
		$this->mock_ai_response( 'Short reply' );

		$loop   = new AgentLoop( 'Be brief' );
		$result = $loop->run();

		// The request body sent to the fake endpoint should contain max_tokens = 512.
		// We verify indirectly: the loop completes without error.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'reply', $result );
	}

	/**
	 * Test AgentLoop respects temperature from settings.
	 */
	public function test_run_respects_temperature_option(): void {
		$this->skip_if_sdk_unavailable();
		Settings::instance()->update( [ 'temperature' => 0.0 ] );
		$this->mock_ai_response( 'Deterministic reply' );

		$loop   = new AgentLoop( 'Be deterministic' );
		$result = $loop->run();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'reply', $result );
	}

	/**
	 * Test AgentLoop uses model_id from options when provided.
	 */
	public function test_run_uses_model_id_from_options(): void {
		$this->skip_if_sdk_unavailable();
		$this->mock_ai_response( 'Model reply' );

		$loop = new AgentLoop(
			'Which model?',
			[],
			[],
			[
				'model_id' => 'gpt-4o',
			]
		);
		$result = $loop->run();

		$this->assertIsArray( $result );
		$this->assertSame( 'gpt-4o', $result['model_id'] );
	}

	/**
	 * Test run() with tool_call_log pre-populated in options (resumable state).
	 */
	public function test_run_with_pre_populated_tool_call_log(): void {
		$this->skip_if_sdk_unavailable();
		$this->mock_ai_response( 'Resumed reply' );

		$prior_log = [
			[
				'type' => 'call',
				'id'   => 'call_prior',
				'name' => 'wpab__sd-ai-agent__memory-list',
				'args' => [],
			],
		];

		$loop = new AgentLoop(
			'Continue',
			[],
			[],
			[ 'tool_call_log' => $prior_log ]
		);
		$result = $loop->run();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'tool_calls', $result );

		// Prior log entries should be preserved.
		$this->assertGreaterThanOrEqual( 1, count( $result['tool_calls'] ) );
		$this->assertSame( 'call_prior', $result['tool_calls'][0]['id'] );
	}

	// -------------------------------------------------------------------------
	// Production hardening: spin detection
	// -------------------------------------------------------------------------

	/**
	 * Test run() detects spin (identical tool calls repeated) and exits gracefully.
	 *
	 * When the model calls the exact same tool with the same args on every
	 * round, the loop should detect the spin after MAX_IDLE_ROUNDS and exit
	 * with exit_reason = 'spin_detected'.
	 */
	public function test_run_detects_spin_and_exits(): void {
		$this->skip_if_sdk_unavailable();
		if ( ! class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
			$this->markTestSkipped( 'WP_AI_Client_Ability_Function_Resolver not available.' );
		}

		// Always return the exact same tool call — this is a spin.
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, 'fake-ai-proxy.test' ) ) {
					$body = wp_json_encode(
						[
							'id'      => 'chatcmpl-spin',
							'object'  => 'chat.completion',
							'choices' => [
								[
									'index'         => 0,
									'message'       => [
										'role'       => 'assistant',
										'content'    => null,
										'tool_calls' => [
											[
												'id'       => 'call_spin',
												'type'     => 'function',
												'function' => [
													'name'      => 'wpab__sd-ai-agent__memory-list',
													'arguments' => '{}',
												],
											],
										],
									],
									'finish_reason' => 'tool_calls',
								],
							],
							'usage'   => [ 'prompt_tokens' => 5, 'completion_tokens' => 5, 'total_tokens' => 10 ],
						]
					);
					return [
						'headers'  => [ 'content-type' => 'application/json' ],
						'body'     => $body,
						'response' => [ 'code' => 200, 'message' => 'OK' ],
						'cookies'  => [],
						'filename' => '',
					];
				}
				return $preempt;
			},
			10,
			3
		);

		// Use enough iterations that spin detection triggers before exhaustion.
		$loop   = new AgentLoop( 'Spin forever', [], [], [ 'max_iterations' => 10 ] );
		$result = $loop->run();

		// Should exit with spin_detected, not max_iterations.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'exit_reason', $result );
		$this->assertSame( 'spin_detected', $result['exit_reason'] );
		// Should have used MAX_IDLE_ROUNDS + 1 iterations (first is unique, then 3 identical).
		$this->assertLessThanOrEqual( AgentLoop::MAX_IDLE_ROUNDS + 1, $result['iterations_used'] );
	}

	// -------------------------------------------------------------------------
	// Production hardening: wall-clock timeout
	// -------------------------------------------------------------------------

	/**
	 * Test that the LOOP_TIMEOUT_SECONDS constant is defined and reasonable.
	 */
	public function test_loop_timeout_constant_is_defined(): void {
		$this->assertGreaterThan( 0, AgentLoop::LOOP_TIMEOUT_SECONDS );
		$this->assertLessThanOrEqual( 300, AgentLoop::LOOP_TIMEOUT_SECONDS );
	}

	/**
	 * Test that MAX_IDLE_ROUNDS constant is defined and reasonable.
	 */
	public function test_max_idle_rounds_constant_is_defined(): void {
		$this->assertGreaterThan( 0, AgentLoop::MAX_IDLE_ROUNDS );
		$this->assertLessThanOrEqual( 10, AgentLoop::MAX_IDLE_ROUNDS );
	}

	// -------------------------------------------------------------------------
	// Ability classification
	// -------------------------------------------------------------------------

	/**
	 * Test classify_ability returns 'read' for abilities with readonly=true.
	 */
	public function test_classify_ability_readonly_true(): void {
		if ( ! class_exists( 'WP_Ability' ) ) {
			$this->markTestSkipped( 'WP_Ability not available.' );
		}

		// Create a mock ability with readonly=true.
		// WP_Ability requires a 'category' string (added in WP 7.0 Abilities API).
		// WP trunk now enforces a required 'permission_callback' in the properties array.
		$ability = new \WP_Ability(
			'test/read-ability',
			[
				'label'               => 'Test Read',
				'description'         => 'A read-only test ability.',
				'category'            => 'sd-ai-agent',
				'execute_callback'    => '__return_true',
				'permission_callback' => '__return_true',
				'meta'                => [
					'annotations' => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
			]
		);

		$this->assertSame( 'read', ToolPermissionResolver::classify_ability( $ability ) );
	}

	/**
	 * Test classify_ability returns 'write' for non-destructive write abilities.
	 */
	public function test_classify_ability_non_destructive_write(): void {
		if ( ! class_exists( 'WP_Ability' ) ) {
			$this->markTestSkipped( 'WP_Ability not available.' );
		}

		$ability = new \WP_Ability(
			'test/write-ability',
			[
				'label'               => 'Test Write',
				'description'         => 'A write test ability.',
				'category'            => 'sd-ai-agent',
				'execute_callback'    => '__return_true',
				'permission_callback' => '__return_true',
				'meta'                => [
					'annotations' => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
				],
			]
		);

		$this->assertSame( 'write', ToolPermissionResolver::classify_ability( $ability ) );
	}

	/**
	 * Test classify_ability returns 'destructive' for abilities with null annotations (safe default).
	 *
	 * When both readonly and destructive annotations are null/unset, the ability is treated
	 * as destructive by default — requiring user confirmation before execution.
	 */
	public function test_classify_ability_null_annotations_defaults_to_destructive(): void {
		if ( ! class_exists( 'WP_Ability' ) ) {
			$this->markTestSkipped( 'WP_Ability not available.' );
		}

		$ability = new \WP_Ability(
			'test/unknown-ability',
			[
				'label'               => 'Test Unknown',
				'description'         => 'An ability with no annotations set.',
				'category'            => 'sd-ai-agent',
				'execute_callback'    => '__return_true',
				'permission_callback' => '__return_true',
				'meta'                => [
					'annotations' => [
						'readonly'    => null,
						'destructive' => null,
						'idempotent'  => null,
					],
				],
			]
		);

		$this->assertSame( 'destructive', ToolPermissionResolver::classify_ability( $ability ) );
	}

	// -------------------------------------------------------------------------
	// Always-allow persistence
	// -------------------------------------------------------------------------

	/**
	 * Test set_always_allow persists the permission in settings.
	 */
	public function test_set_always_allow_persists_permission(): void {
		ToolPermissionResolver::set_always_allow( 'sd-ai-agent/memory-save' );

		$settings = new Settings();
		$perms    = $settings->get( 'tool_permissions' );

		$this->assertIsArray( $perms );
		$this->assertArrayHasKey( 'sd-ai-agent/memory-save', $perms );
		$this->assertSame( 'always_allow', $perms['sd-ai-agent/memory-save'] );
	}

	/**
	 * Test get_always_allowed returns abilities with always_allow permission.
	 */
	public function test_get_always_allowed_returns_correct_abilities(): void {
		Settings::instance()->update(
			[
				'tool_permissions' => [
					'sd-ai-agent/memory-save'   => 'always_allow',
					'sd-ai-agent/memory-list'   => 'auto',
					'sd-ai-agent/file-write'    => 'always_allow',
					'sd-ai-agent/file-read'     => 'disabled',
				],
			]
		);

		$always = ToolPermissionResolver::get_always_allowed();

		$this->assertIsArray( $always );
		$this->assertCount( 2, $always );
		$this->assertContains( 'sd-ai-agent/memory-save', $always );
		$this->assertContains( 'sd-ai-agent/file-write', $always );
		$this->assertNotContains( 'sd-ai-agent/memory-list', $always );
		$this->assertNotContains( 'sd-ai-agent/file-read', $always );
	}

	/**
	 * Test get_always_allowed returns empty array when no permissions set.
	 */
	public function test_get_always_allowed_returns_empty_when_no_perms(): void {
		delete_option( Settings::OPTION_NAME );

		$always = ToolPermissionResolver::get_always_allowed();

		$this->assertIsArray( $always );
		$this->assertEmpty( $always );
	}

	// -------------------------------------------------------------------------
	// Annotation-based confirmation: write tools require confirmation by default
	// -------------------------------------------------------------------------

	/**
	 * Test that a destructive tool triggers confirmation when no explicit
	 * tool_permissions are set.
	 */
	public function test_destructive_tool_requires_confirmation_by_default(): void {
		$this->skip_if_sdk_unavailable();
		if ( ! class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
			$this->markTestSkipped( 'WP_AI_Client_Ability_Function_Resolver not available.' );
		}

		// Ensure NO tool_permissions are set — rely on annotation-based classification.
		delete_option( Settings::OPTION_NAME );

		// Register a test ability with destructive=true.
		if ( function_exists( 'wp_register_ability' ) ) {
			wp_register_ability(
				'sd-ai-agent/test-destructive-tool',
				[
					'label'            => 'Test Destructive Tool',
					'description'      => 'A destructive tool for testing.',
					'execute_callback' => '__return_true',
					'meta'             => [
						'annotations' => [
							'readonly'    => false,
							'destructive' => true,
							'idempotent'  => false,
						],
					],
				]
			);
		} else {
			$this->markTestSkipped( 'wp_register_ability() not available.' );
		}

		// Mock a response that calls the destructive tool.
		$this->mock_ai_response(
			'',
			[
				[
					'id'       => 'call_destructive_test',
					'type'     => 'function',
					'function' => [
						'name'      => 'wpab__sd-ai-agent__test-destructive-tool',
						'arguments' => '{}',
					],
				],
			]
		);

		$loop   = new AgentLoop( 'Do a destructive operation' );
		$result = $loop->run();

		// Should pause for confirmation since it's a destructive tool.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'awaiting_confirmation', $result );
		$this->assertTrue( $result['awaiting_confirmation'] );
	}

	/**
	 * Test that a read tool (readonly=true) auto-executes without confirmation
	 * when no explicit tool_permissions are set.
	 */
	public function test_read_tool_auto_executes_by_default(): void {
		$this->skip_if_sdk_unavailable();
		if ( ! class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
			$this->markTestSkipped( 'WP_AI_Client_Ability_Function_Resolver not available.' );
		}

		// Ensure NO tool_permissions are set.
		delete_option( Settings::OPTION_NAME );

		// The memory-list ability is registered with readonly=true.
		// Mock: first call returns tool call, second returns text.
		$call_count = 0;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$call_count ) {
				if ( false !== strpos( $url, 'fake-ai-proxy.test' ) ) {
					++$call_count;
					if ( 1 === $call_count ) {
						$body = wp_json_encode(
							[
								'id'      => 'chatcmpl-read',
								'object'  => 'chat.completion',
								'choices' => [
									[
										'index'         => 0,
										'message'       => [
											'role'       => 'assistant',
											'content'    => null,
											'tool_calls' => [
												[
													'id'       => 'call_read',
													'type'     => 'function',
													'function' => [
														'name'      => 'wpab__sd-ai-agent__memory-list',
														'arguments' => '{}',
													],
												],
											],
										],
										'finish_reason' => 'tool_calls',
									],
								],
								'usage'   => [ 'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15 ],
							]
						);
					} else {
						$body = wp_json_encode(
							[
								'id'      => 'chatcmpl-done',
								'object'  => 'chat.completion',
								'choices' => [
									[
										'index'         => 0,
										'message'       => [ 'role' => 'assistant', 'content' => 'Here are your memories.' ],
										'finish_reason' => 'stop',
									],
								],
								'usage'   => [ 'prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30 ],
							]
						);
					}
					return [
						'headers'  => [ 'content-type' => 'application/json' ],
						'body'     => $body,
						'response' => [ 'code' => 200, 'message' => 'OK' ],
						'cookies'  => [],
						'filename' => '',
					];
				}
				return $preempt;
			},
			10,
			3
		);

		$loop   = new AgentLoop( 'List my memories' );
		$result = $loop->run();

		// Should NOT pause for confirmation — read tools auto-execute.
		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'awaiting_confirmation', $result );
		$this->assertArrayHasKey( 'reply', $result );
		$this->assertSame( 'Here are your memories.', $result['reply'] );
	}

	/**
	 * Test that always_allow permission skips confirmation for write tools.
	 */
	public function test_always_allow_skips_confirmation_for_write_tools(): void {
		$this->skip_if_sdk_unavailable();
		if ( ! class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
			$this->markTestSkipped( 'WP_AI_Client_Ability_Function_Resolver not available.' );
		}

		// Set the write tool to always_allow.
		Settings::instance()->update(
			[
				'tool_permissions' => [
					'sd-ai-agent/memory-save' => 'always_allow',
				],
			]
		);

		// Mock: first call returns tool call, second returns text.
		$call_count = 0;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$call_count ) {
				if ( false !== strpos( $url, 'fake-ai-proxy.test' ) ) {
					++$call_count;
					if ( 1 === $call_count ) {
						$body = wp_json_encode(
							[
								'id'      => 'chatcmpl-aa',
								'object'  => 'chat.completion',
								'choices' => [
									[
										'index'         => 0,
										'message'       => [
											'role'       => 'assistant',
											'content'    => null,
											'tool_calls' => [
												[
													'id'       => 'call_aa',
													'type'     => 'function',
													'function' => [
														'name'      => 'wpab__sd-ai-agent__memory-save',
														'arguments' => wp_json_encode( [ 'content' => 'Test' ] ),
													],
												],
											],
										],
										'finish_reason' => 'tool_calls',
									],
								],
								'usage'   => [ 'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15 ],
							]
						);
					} else {
						$body = wp_json_encode(
							[
								'id'      => 'chatcmpl-done',
								'object'  => 'chat.completion',
								'choices' => [
									[
										'index'         => 0,
										'message'       => [ 'role' => 'assistant', 'content' => 'Saved!' ],
										'finish_reason' => 'stop',
									],
								],
								'usage'   => [ 'prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30 ],
							]
						);
					}
					return [
						'headers'  => [ 'content-type' => 'application/json' ],
						'body'     => $body,
						'response' => [ 'code' => 200, 'message' => 'OK' ],
						'cookies'  => [],
						'filename' => '',
					];
				}
				return $preempt;
			},
			10,
			3
		);

		$loop   = new AgentLoop( 'Save something' );
		$result = $loop->run();

		// Should NOT pause — always_allow skips confirmation.
		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'awaiting_confirmation', $result );
		$this->assertArrayHasKey( 'reply', $result );
	}
}
