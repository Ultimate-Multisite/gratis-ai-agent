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

use SdAiAgent\Abilities\Js\JsAbilityCatalog;
use SdAiAgent\Abilities\KnowledgeAbilities;
use SdAiAgent\Core\ActiveJobFailureDiagnostic;
use SdAiAgent\Core\AgentLoop;
use SdAiAgent\Core\ClientAbilityRouter;
use SdAiAgent\Core\ConversationSerializer;
use SdAiAgent\Core\ConversationTrimmer;
use SdAiAgent\Core\Database;
use SdAiAgent\Core\ProviderCredentialLoader;
use SdAiAgent\Core\Settings;
use SdAiAgent\Core\SystemInstructionBuilder;
use SdAiAgent\Core\ToolPermissionResolver;
use SdAiAgent\Models\ActiveJobRepository;
use SdAiAgent\Tools\ToolDiscovery;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use WP_Error;
use WP_UnitTestCase;

/** Deterministic provider boundary used to exercise recovery without credentials. */
class ScriptedAgentLoop extends AgentLoop {

	/** @var list<GenerativeAiResult|WP_Error> */
	private array $scriptedResults;

	/** @var list<int> */
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- Project property naming guidance requires camelCase.
	public array $requestSizes = array();

	/** @var list<int> */
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- Project property naming guidance requires camelCase.
	public array $requestAttemptLimits = array();

	/** @var list<array{ability_mode:bool,collection_mode:bool,knowledge_allowed:bool}> */
	public array $policySnapshots = array();

	/**
	 * @param string                                  $user_message User prompt.
	 * @param string[]                                $abilities    Enabled abilities.
	 * @param Message[]                               $history      Prior history.
	 * @param array<string, mixed>                    $options      Agent options.
	 * @param list<GenerativeAiResult|WP_Error>       $results      Scripted provider results.
	 */
	public function __construct( string $user_message, array $abilities, array $history, array $options, array $results ) {
		$this->scriptedResults = $results;
		parent::__construct( $user_message, $abilities, $history, $options );
	}

	/** Scripted loops provide their own provider boundary and do not need the SDK function. */
	protected function is_ai_client_available(): bool {
		return true;
	}

	/** Return the next scripted result while recording the outgoing history size. */
	protected function send_prompt( string $provider_id, string $model_id ): GenerativeAiResult|WP_Error {
		$attempts_property = new \ReflectionProperty( AgentLoop::class, 'provider_retry_max_attempts' );
		$attempts_property->setAccessible( true );
		$this->requestAttemptLimits[] = (int) $attempts_property->getValue( $this );

		$this->policySnapshots[] = array(
			'ability_mode'     => ToolDiscovery::is_anonymous_ability_mode(),
			'collection_mode'  => KnowledgeAbilities::is_public_collection_mode(),
			'knowledge_allowed' => ToolDiscovery::anonymous_mode_allows( 'sd-ai-agent/knowledge-search' ),
		);

		$history_property = new \ReflectionProperty( AgentLoop::class, 'history' );
		$history_property->setAccessible( true );
		$history = $history_property->getValue( $this );
		if ( is_array( $history ) ) {
			/** @var Message[] $history */
			$this->requestSizes[] = ConversationTrimmer::estimate_total_bytes( $history );
		}

		$result = array_shift( $this->scriptedResults );
		if ( $result instanceof WP_Error && 'sd_ai_agent_test_provider_timeout' === $result->get_error_code() ) {
			$retry_error = new \ReflectionMethod( AgentLoop::class, 'build_provider_retry_failed_error' );

			return $retry_error->invoke( $this, $result, 0 );
		}
		if ( $result instanceof GenerativeAiResult || $result instanceof WP_Error ) {
			return $result;
		}

		return new WP_Error( 'sd_ai_agent_test_script_exhausted', 'Scripted provider results exhausted.' );
	}
}

/** Scripted server-tool boundary used for deterministic mixed-resume coverage. */
final class ScriptedAbilityAgentLoop extends ScriptedAgentLoop {

	/** @var list<Message> */
	public array $executedAbilityMessages = array();

	private Message $scriptedAbilityResponse;

	/**
	 * @param string                            $user_message             User prompt.
	 * @param string[]                          $abilities                Enabled abilities.
	 * @param Message[]                         $history                  Prior history.
	 * @param array<string, mixed>              $options                  Agent options.
	 * @param list<GenerativeAiResult|WP_Error> $results                  Scripted provider results.
	 * @param Message                           $scripted_ability_response Server tool response.
	 */
	public function __construct( string $user_message, array $abilities, array $history, array $options, array $results, Message $scripted_ability_response ) {
		$this->scriptedAbilityResponse = $scripted_ability_response;
		parent::__construct( $user_message, $abilities, $history, $options, $results );
	}

	/** Record the PHP partition without constructing an SDK ability resolver. */
	protected function execute_abilities( Message $message ): Message {
		$this->executedAbilityMessages[] = $message;
		return $this->scriptedAbilityResponse;
	}
}

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
		ToolDiscovery::clear_anonymous_allowed_abilities();
		KnowledgeAbilities::clear_public_collection_allowlist();
	}

	/**
	 * DeepSeek tool-call messages must keep their thought channel attached.
	 *
	 * The DeepSeek provider serializes thought-channel text as the
	 * `reasoning_content` sibling on the assistant wire message. Splitting a
	 * thought+tool_calls assistant turn into separate ModelMessages severs that
	 * pairing and can trigger DeepSeek's 400: "reasoning_content ... must be
	 * passed back" on the next request.
	 */
	public function test_deepseek_tool_call_assistant_message_is_not_split(): void {
		if ( ! class_exists( 'WordPress\AiClient\Messages\DTO\ModelMessage' ) ) {
			$this->markTestSkipped( 'WP AI Client message classes are not available.' );
		}

		$loop = new AgentLoop(
			'Test prompt',
			[],
			[],
			[
				'provider_id' => 'deepseek',
				'model_id'    => 'deepseek-v4-flash',
			]
		);

		$message = new \WordPress\AiClient\Messages\DTO\ModelMessage(
			[
				new \WordPress\AiClient\Messages\DTO\MessagePart(
					'I need two tool calls.',
					\WordPress\AiClient\Messages\Enums\MessagePartChannelEnum::thought()
				),
				new \WordPress\AiClient\Messages\DTO\MessagePart(
					new \WordPress\AiClient\Tools\DTO\FunctionCall( 'call_1', 'wpab__sd-ai-agent__list-posts', [] )
				),
				new \WordPress\AiClient\Messages\DTO\MessagePart(
					new \WordPress\AiClient\Tools\DTO\FunctionCall( 'call_2', 'wpab__sd-ai-agent__site-health-summary', [] )
				),
			]
		);

		$method = new \ReflectionMethod( AgentLoop::class, 'append_assistant_message_to_history' );
		$method->setAccessible( true );
		$method->invoke( $loop, $message );

		$history_property = new \ReflectionProperty( AgentLoop::class, 'history' );
		$history_property->setAccessible( true );
		$history = $history_property->getValue( $loop );

		$this->assertCount( 1, $history, 'DeepSeek tool-call assistant messages must remain one history message.' );
		$this->assertSame( $message, $history[0] );
		$this->assertCount( 3, $history[0]->getParts() );
	}

	/**
	 * Non-DeepSeek providers still use the generic split needed by OpenAI Responses.
	 */
	public function test_non_deepseek_tool_call_assistant_message_still_splits(): void {
		if ( ! class_exists( 'WordPress\AiClient\Messages\DTO\ModelMessage' ) ) {
			$this->markTestSkipped( 'WP AI Client message classes are not available.' );
		}

		$loop = new AgentLoop(
			'Test prompt',
			[],
			[],
			[
				'provider_id' => 'openai',
				'model_id'    => 'gpt-5.5',
			]
		);

		$message = new \WordPress\AiClient\Messages\DTO\ModelMessage(
			[
				new \WordPress\AiClient\Messages\DTO\MessagePart( 'Short answer.' ),
				new \WordPress\AiClient\Messages\DTO\MessagePart(
					new \WordPress\AiClient\Tools\DTO\FunctionCall( 'call_1', 'wpab__sd-ai-agent__list-posts', [] )
				),
				new \WordPress\AiClient\Messages\DTO\MessagePart(
					new \WordPress\AiClient\Tools\DTO\FunctionCall( 'call_2', 'wpab__sd-ai-agent__site-health-summary', [] )
				),
			]
		);

		$method = new \ReflectionMethod( AgentLoop::class, 'append_assistant_message_to_history' );
		$method->setAccessible( true );
		$method->invoke( $loop, $message );

		$history_property = new \ReflectionProperty( AgentLoop::class, 'history' );
		$history_property->setAccessible( true );
		$history = $history_property->getValue( $loop );

		$this->assertCount( 3, $history, 'Non-DeepSeek providers should keep the generic split behavior.' );
	}

	/**
	 * Thought-channel text must not be logged as live assistant preamble.
	 */
	public function test_thought_channel_text_is_not_logged_as_preamble(): void {
		if ( ! class_exists( 'WordPress\AiClient\Messages\DTO\ModelMessage' ) ) {
			$this->markTestSkipped( 'WP AI Client message classes are not available.' );
		}

		$loop = new AgentLoop(
			'Test prompt',
			[],
			[],
			[
				'provider_id' => 'openai_compat',
				'model_id'    => 'moonshotai/Kimi-K2.6',
			]
		);

		$message = new \WordPress\AiClient\Messages\DTO\ModelMessage(
			[
				new \WordPress\AiClient\Messages\DTO\MessagePart(
					'The user wants me to expose hidden reasoning.',
					\WordPress\AiClient\Messages\Enums\MessagePartChannelEnum::thought()
				),
				new \WordPress\AiClient\Messages\DTO\MessagePart( 'Visible preamble.' ),
				new \WordPress\AiClient\Messages\DTO\MessagePart(
					new \WordPress\AiClient\Tools\DTO\FunctionCall( 'call_1', 'wpab__sd-ai-agent__site-info', [] )
				),
			]
		);

		$method = new \ReflectionMethod( AgentLoop::class, 'log_tool_calls' );
		$method->setAccessible( true );
		$method->invoke( $loop, $message );

		$message_log_property = new \ReflectionProperty( AgentLoop::class, 'message_log' );
		$message_log_property->setAccessible( true );
		$message_log = $message_log_property->getValue( $loop );

		$this->assertCount( 1, $message_log, 'Only content-channel text should be logged as preamble.' );
		$this->assertSame( 'Visible preamble.', $message_log[0]['text'] );
		$this->assertStringNotContainsString( 'hidden reasoning', (string) wp_json_encode( $message_log ) );
	}

	/**
	 * XML-ish tool-call text should become an ability-call function part, not a final reply.
	 */
	public function test_intercepts_xml_tool_call_text_as_ability_call(): void {
		if ( ! class_exists( 'WordPress\AiClient\Messages\DTO\ModelMessage' ) ) {
			$this->markTestSkipped( 'WP AI Client message classes are not available.' );
		}

		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'sd-ai-agent/get-themes' ) ) {
			$this->markTestSkipped( 'sd-ai-agent/get-themes ability is not registered.' );
		}

		$loop    = new AgentLoop( 'List installed themes.' );
		$message = new \WordPress\AiClient\Messages\DTO\ModelMessage(
			array(
				new \WordPress\AiClient\Messages\DTO\MessagePart( '<tool_call>wpab__sd-ai-agent__get-themes</tool_call>' ),
			)
		);

		$method = new \ReflectionMethod( AgentLoop::class, 'intercept_text_tool_call' );
		$method->setAccessible( true );

		$result = $method->invoke( $loop, $message );

		$this->assertInstanceOf( \WordPress\AiClient\Messages\DTO\Message::class, $result );
		$this->assertCount( 1, $result->getParts() );

		$call = $result->getParts()[0]->getFunctionCall();
		$this->assertInstanceOf( \WordPress\AiClient\Tools\DTO\FunctionCall::class, $call );
		$this->assertSame( 'wpab__sd-ai-agent__ability-call', $call->getName() );
		$this->assertSame(
			array(
				'ability'   => 'sd-ai-agent/get-themes',
				'arguments' => array(),
			),
			$call->getArgs()
		);
	}

	/**
	 * Unknown XML-ish tool-call text should produce corrective guidance for another loop turn.
	 */
	public function test_unknown_xml_tool_call_text_gets_corrective_prompt(): void {
		if ( ! class_exists( 'WordPress\AiClient\Messages\DTO\ModelMessage' ) ) {
			$this->markTestSkipped( 'WP AI Client message classes are not available.' );
		}

		$loop    = new AgentLoop( 'Do something.' );
		$message = new \WordPress\AiClient\Messages\DTO\ModelMessage(
			array(
				new \WordPress\AiClient\Messages\DTO\MessagePart( '<function_call name="wpab__sd-ai-agent__missing-tool"/>' ),
			)
		);

		$method = new \ReflectionMethod( AgentLoop::class, 'intercept_text_tool_call' );
		$method->setAccessible( true );

		$result = $method->invoke( $loop, $message );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not invokable as assistant text', $result );
		$this->assertStringContainsString( 'sd-ai-agent/ability-call', $result );
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

	/**
	 * Options that keep retry tests fast by disabling sleep between attempts.
	 *
	 * @param int $max_attempts Maximum provider attempts.
	 * @return array<string, mixed>
	 */
	private function no_sleep_retry_options( int $max_attempts = 4 ): array {
		return [
			'provider_retry_max_attempts' => $max_attempts,
			'provider_retry_delays'       => array_fill( 0, $max_attempts, 0 ),
		];
	}

	/**
	 * Register a `pre_http_request` filter that returns queued responses.
	 *
	 * @param list<array<string,mixed>> $responses HTTP response specs.
	 * @param int                       $call_count Number of intercepted provider calls.
	 */
	private function mock_ai_response_sequence( array $responses, int &$call_count ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $responses, &$call_count ) {
				if ( false === strpos( $url, 'fake-ai-proxy.test' ) ) {
					return $preempt;
				}

				$index = min( $call_count, count( $responses ) - 1 );
				++$call_count;
				$spec = $responses[ $index ];

				if ( isset( $spec['wp_error'] ) && $spec['wp_error'] instanceof \WP_Error ) {
					return $spec['wp_error'];
				}

				$status = (int) ( $spec['status'] ?? 200 );
				$body   = (string) ( $spec['body'] ?? '' );
				if ( '' === $body ) {
					$body = wp_json_encode(
						[
							'id'      => 'chatcmpl-sequence',
							'object'  => 'chat.completion',
							'choices' => [
								[
									'index'         => 0,
									'message'       => [ 'role' => 'assistant', 'content' => (string) ( $spec['reply'] ?? 'Recovered' ) ],
									'finish_reason' => 'stop',
								],
							],
							'usage'   => [ 'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15 ],
						]
					);
				}

				return [
					'headers'  => [ 'content-type' => 'application/json' ],
					'body'     => $body,
					'response' => [ 'code' => $status, 'message' => (string) ( $spec['message'] ?? 'OK' ) ],
					'cookies'  => [],
					'filename' => '',
				];
			},
			10,
			3
		);
	}

	/** Build a deterministic SDK result for scripted provider recovery tests. */
	private function create_scripted_result( string $reply, ?FunctionCall $function_call = null ): GenerativeAiResult {
		$provider_metadata = new ProviderMetadata(
			'scripted-provider',
			'Scripted Provider',
			ProviderTypeEnum::cloud(),
			null,
			RequestAuthenticationMethod::apiKey()
		);
		$model_metadata    = new ModelMetadata(
			'scripted-model',
			'Scripted Model',
			array( CapabilityEnum::textGeneration() ),
			array()
		);
		$candidate         = new Candidate(
			new ModelMessage( array( new MessagePart( $function_call ?? $reply ) ) ),
			FinishReasonEnum::stop()
		);

		return new GenerativeAiResult(
			'scripted-result',
			array( $candidate ),
			new TokenUsage( 10, 5, 15 ),
			$provider_metadata,
			$model_metadata
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
		$this->assertArrayHasKey( 'messages', $result );
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

	/** Durable planning accepts only a compact JSON shape and never saves raw model JSON to history. */
	public function test_durable_plan_mode_returns_a_compact_definition_without_raw_history(): void {
		$raw_response = (string) wp_json_encode(
			[
				'scope'   => 'MODEL-ONLY-SCOPE-MARKER',
				'summary' => 'MODEL-ONLY-SUMMARY-MARKER',
				'steps'   => [
					[
						'title'             => 'Inspect the current configuration',
						'instruction'       => 'Inspect the current configuration without changing the site.',
						'classification'    => 'read',
						'preconditions'     => 'An administrator session is active.',
						'expected_evidence' => 'A concise configuration inventory.',
						'rollback_guidance' => 'No rollback is required.',
					],
				],
			]
		);
		$loop         = new ScriptedAgentLoop(
			'Prepare a safe plan for the current site operation.',
			[],
			[],
			[
				'durable_plan_mode' => true,
				'provider_id'       => 'scripted-provider',
				'model_id'          => 'scripted-model',
			],
			[ $this->create_scripted_result( $raw_response ) ]
		);
		$result       = $loop->run();

		$this->assertIsArray( $result );
		$this->assertSame( 'I prepared a durable plan. Review each phase before continuing.', $result['reply'] );
		$this->assertSame( 'MODEL-ONLY-SCOPE-MARKER', $result['durable_plan_definition']['scope'] );
		$this->assertSame( 'read', $result['durable_plan_definition']['steps'][0]['classification'] );
		$this->assertSame( [], $result['tool_calls'] );
		$this->assertStringNotContainsString( 'MODEL-ONLY-SCOPE-MARKER', (string) wp_json_encode( $result['history'] ) );
		$this->assertStringNotContainsString( 'MODEL-ONLY-SUMMARY-MARKER', (string) wp_json_encode( $result['history'] ) );
	}

	/** Planner-only turns reject a provider tool call before it can execute or pause. */
	public function test_durable_plan_mode_rejects_model_tool_calls_without_recording_them(): void {
		$tool_name = 'wpab__sd-ai-agent__site-info';
		$loop      = new ScriptedAgentLoop(
			'Prepare a safe plan for the current site operation.',
			array(),
			array(),
			array(
				'durable_plan_mode' => true,
				'provider_id'       => 'scripted-provider',
				'model_id'          => 'scripted-model',
			),
			array( $this->create_scripted_result( '', new FunctionCall( 'plan_tool_call', $tool_name, array() ) ) )
		);
		$result    = $loop->run();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_durable_plan_tool_call', $result->get_error_code() );
		$error_data = $result->get_error_data();
		$this->assertIsArray( $error_data );
		$this->assertSame( array(), $error_data['tool_calls'] ?? array() );
		$this->assertStringNotContainsString( $tool_name, (string) wp_json_encode( $error_data['history'] ?? array() ) );
	}

	/** Planner-only turns reject unknown model fields without reflecting their raw response into history. */
	public function test_durable_plan_mode_rejects_unknown_model_fields_without_raw_history(): void {
		$raw_response = (string) wp_json_encode(
			[
				'scope'           => 'Review the site configuration.',
				'summary'         => 'Create a reviewed plan.',
				'idempotency_key' => 'MODEL-ONLY-UNTRUSTED-KEY-MARKER',
				'steps'           => [
					[
						'title'             => 'Inspect configuration',
						'instruction'       => 'Inspect the current configuration.',
						'classification'    => 'read',
						'preconditions'     => '',
						'expected_evidence' => 'Configuration inventory.',
						'rollback_guidance' => '',
					],
				],
			]
		);
		$loop         = new ScriptedAgentLoop(
			'Prepare a safe plan for the current site operation.',
			[],
			[],
			[
				'durable_plan_mode' => true,
				'provider_id'       => 'scripted-provider',
				'model_id'          => 'scripted-model',
			],
			[ $this->create_scripted_result( $raw_response ) ]
		);
		$result       = $loop->run();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_durable_plan_invalid_response', $result->get_error_code() );
		$error_data = $result->get_error_data();
		$this->assertIsArray( $error_data );
		$this->assertStringNotContainsString( 'MODEL-ONLY-UNTRUSTED-KEY-MARKER', (string) wp_json_encode( $error_data['history'] ?? [] ) );
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
	 * Managed Superdav calls tolerate a short edge outage while other providers
	 * retain the existing retry budget. Explicit per-run overrides still win.
	 */
	public function test_managed_provider_uses_longer_default_retry_policy(): void {
		$attempts = new \ReflectionProperty( AgentLoop::class, 'provider_retry_max_attempts' );
		$delays   = new \ReflectionProperty( AgentLoop::class, 'provider_retry_delays' );
		$jitter   = new \ReflectionProperty( AgentLoop::class, 'provider_retry_jitter' );
		$resolve  = new \ReflectionMethod( AgentLoop::class, 'get_provider_retry_delay' );

		$managed = new AgentLoop(
			'Inspect the site.',
			[],
			[],
			[
				'provider_id' => 'sd-ai-agent-cloud',
				'model_id'    => 'superdav-chat-pro',
			]
		);
		$this->assertSame( 6, $attempts->getValue( $managed ) );
		$this->assertSame( [ 1, 2, 4, 8, 16 ], $delays->getValue( $managed ) );
		$this->assertTrue( $jitter->getValue( $managed ) );
		for ( $sample = 0; $sample < 20; ++$sample ) {
			$resolved_delay = $resolve->invoke( $managed, 5, null );
			$this->assertGreaterThanOrEqual( 16, $resolved_delay );
			$this->assertLessThanOrEqual( 20, $resolved_delay );
		}

		$other = new AgentLoop(
			'Inspect the site.',
			[],
			[],
			[
				'provider_id' => 'another-provider',
				'model_id'    => 'another-model',
			]
		);
		$this->assertSame( 4, $attempts->getValue( $other ) );
		$this->assertSame( [ 1, 2, 4 ], $delays->getValue( $other ) );
		$this->assertFalse( $jitter->getValue( $other ) );

		$overridden = new AgentLoop(
			'Inspect the site.',
			[],
			[],
			[
				'provider_id'                => 'sd-ai-agent-cloud',
				'model_id'                   => 'superdav-chat-pro',
				'provider_retry_max_attempts' => 2,
				'provider_retry_delays'       => [ 0 ],
			]
		);
		$this->assertSame( 2, $attempts->getValue( $overridden ) );
		$this->assertSame( [ 0 ], $delays->getValue( $overridden ) );
		$this->assertFalse( $jitter->getValue( $overridden ) );
		$this->assertSame( 0, $resolve->invoke( $overridden, 1, null ) );
	}

	/**
	 * Longer retry waits heartbeat the active continuation instead of leaving
	 * the accepted client-result job looking stale.
	 */
	public function test_provider_retry_wait_heartbeats_active_job(): void {
		global $wpdb;

		$job_id = '44444444-5555-6666-7777-888888888888';
		$this->assertNotFalse( ActiveJobRepository::create( 1, $job_id, 1 ) );
		$wpdb->update(
			ActiveJobRepository::table_name(),
			[ 'updated_at' => '2000-01-01 00:00:00' ],
			[ 'job_id' => $job_id ],
			[ '%s' ],
			[ '%s' ]
		);

		$loop = new AgentLoop(
			'Inspect the site.',
			[],
			[],
			[ 'active_job_id' => $job_id ]
		);
		$wait = new \ReflectionMethod( AgentLoop::class, 'wait_for_provider_retry' );
		$wait->invoke( $loop, 1 );

		$row = ActiveJobRepository::get_by_job_id( $job_id );
		$this->assertNotNull( $row );
		$this->assertNotSame( '2000-01-01 00:00:00', $row->updated_at );
	}

	/**
	 * Test run() returns WP_Error when the AI proxy returns an HTTP error.
	 */
	public function test_run_retries_http_error_response_then_succeeds(): void {
		$this->skip_if_sdk_unavailable();
		$call_count = 0;
		$progress   = [];
		$this->mock_ai_response_sequence(
			[
				[
					'status'  => 500,
					'message' => 'Internal Server Error',
					'body'    => wp_json_encode( [ 'error' => [ 'message' => 'Internal server error' ] ] ),
				],
				[
					'status'  => 502,
					'message' => 'Bad Gateway',
					'body'    => wp_json_encode( [ 'error' => [ 'message' => 'Bad gateway' ] ] ),
				],
				[
					'status'  => 503,
					'message' => 'Service Unavailable',
					'body'    => wp_json_encode( [ 'error' => [ 'message' => 'Unavailable' ] ] ),
				],
				[
					'status' => 200,
					'reply'  => 'Recovered after retry',
				],
			],
			$call_count
		);

		$options                      = $this->no_sleep_retry_options( 4 );
		$options['progress_callback'] = static function ( array $tool_call_log ) use ( &$progress ): void {
			$progress[] = $tool_call_log;
		};

		$loop   = new AgentLoop(
			'Hello',
			[],
			[],
			$options
		);
		$result = $loop->run();

		$this->assertIsArray( $result );
		$this->assertSame( 'Recovered after retry', $result['reply'] );
		$this->assertSame( 4, $call_count );
		$retry_entries = array_filter( $result['messages'], static fn( $entry ) => 'provider_retry' === ( $entry['type'] ?? '' ) );
		$this->assertCount( 3, $retry_entries );
		$this->assertCount( 3, $progress );
	}

	/**
	 * Test run() returns clear WP_Error after retry attempts are exhausted.
	 */
	public function test_run_returns_clear_wp_error_after_retry_exhaustion(): void {
		$this->skip_if_sdk_unavailable();
		$call_count = 0;
		$this->mock_ai_response_sequence(
			[
				[
					'status'  => 503,
					'message' => 'Service Unavailable',
					'body'    => wp_json_encode( [ 'error' => [ 'message' => 'Sub2API is unavailable.' ] ] ),
				],
			],
			$call_count
		);

		$loop   = new AgentLoop(
			'Hello',
			[],
			[],
			$this->no_sleep_retry_options( 3 )
		);
		$result = $loop->run();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_provider_retry_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'The AI service is temporarily unavailable after 3 attempts', $result->get_error_message() );
		$this->assertStringNotContainsString( 'Sub2API', $result->get_error_message() );
		$this->assertSame( 3, $call_count );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$retry_entries = array_filter( $data['messages'], static fn( $entry ) => 'provider_retry' === ( $entry['type'] ?? '' ) );
		$this->assertCount( 2, $retry_entries );
	}

	/** A large exhausted request compacts and retries without browser recovery. */
	public function test_large_provider_retry_exhaustion_compacts_and_recovers_automatically(): void {
		$session_id = Database::create_session(
			array(
				'user_id' => 1,
				'title'   => 'Automatic provider retry compaction',
			)
		);
		$history    = array(
			new UserMessage( array( new MessagePart( 'Build the complete onboarding site.' ) ) ),
			new ModelMessage(
				array(
					new MessagePart(
						new FunctionCall(
							'call_large_result',
							'wpab__sd-ai-agent__site-info',
							array()
						)
					),
				)
			),
			new UserMessage(
				array(
					new MessagePart(
						new FunctionResponse(
							'call_large_result',
							'wpab__sd-ai-agent__site-info',
							array( 'content' => str_repeat( 'large completed tool output ', 4000 ) )
						)
					),
				)
			),
		);
		$loop       = new ScriptedAgentLoop(
			'',
			array(),
			$history,
			array(
				'session_id'  => $session_id,
				'provider_id' => 'sd-ai-agent-cloud',
				'model_id'    => 'superdav-chat-pro',
			),
			array(
				new WP_Error( 'sd_ai_agent_test_provider_timeout', 'Managed service unavailable.' ),
				$this->create_scripted_result( 'Recovered from compact context.' ),
			)
		);

		$result = $loop->resume_from_checkpoint( 3 );

		$this->assertIsArray( $result );
		$this->assertSame( 'Recovered from compact context.', $result['reply'] );
		$this->assertCount( 2, $loop->requestSizes );
		$this->assertSame( array( 6, 1 ), $loop->requestAttemptLimits );
		$this->assertGreaterThan( ConversationTrimmer::COMPACT_MAX_BYTES, $loop->requestSizes[0] );
		$this->assertLessThanOrEqual( ConversationTrimmer::COMPACT_MAX_BYTES, $loop->requestSizes[1] );
		$this->assertLessThan( $loop->requestSizes[0], $loop->requestSizes[1] );
		$this->assertStringContainsString( 'large completed tool output', (string) wp_json_encode( $result['history'] ) );
		$recovery_entries = array_filter( $result['messages'], static fn( $entry ) => 'provider_retry_compaction' === ( $entry['type'] ?? '' ) );
		$this->assertCount( 1, $recovery_entries );
		$this->assertNull( Database::load_and_clear_paused_state( $session_id ) );
	}

	/** A recovered agentic loop keeps later provider calls on compact context. */
	public function test_large_provider_retry_compaction_stays_active_for_followup_provider_calls(): void {
		$history = array(
			new UserMessage( array( new MessagePart( 'Complete the remaining onboarding checks.' ) ) ),
			new ModelMessage(
				array(
					new MessagePart( new FunctionCall( 'call_large_followup', 'wpab__sd-ai-agent__site-info', array() ) ),
				)
			),
			new UserMessage(
				array(
					new MessagePart(
						new FunctionResponse(
							'call_large_followup',
							'wpab__sd-ai-agent__site-info',
							array( 'content' => str_repeat( 'large completed tool output ', 4000 ) )
						)
					),
				)
			),
		);
		$loop    = new ScriptedAgentLoop(
			'',
			array(),
			$history,
			array(
				'provider_id' => 'sd-ai-agent-cloud',
				'model_id'    => 'superdav-chat-pro',
			),
			array(
				new WP_Error( 'sd_ai_agent_test_provider_timeout', 'Managed service unavailable.' ),
				$this->create_scripted_result( '' ),
				$this->create_scripted_result( 'Completed after the compact-context follow-up.' ),
			)
		);

		$result = $loop->resume_from_checkpoint( 4 );

		$this->assertIsArray( $result );
		$this->assertSame( 'Completed after the compact-context follow-up.', $result['reply'] );
		$this->assertCount( 3, $loop->requestSizes );
		$this->assertSame( array( 6, 1, 6 ), $loop->requestAttemptLimits );
		$this->assertGreaterThan( ConversationTrimmer::COMPACT_MAX_BYTES, $loop->requestSizes[0] );
		$this->assertLessThanOrEqual( ConversationTrimmer::COMPACT_MAX_BYTES, $loop->requestSizes[1] );
		$this->assertLessThanOrEqual( ConversationTrimmer::COMPACT_MAX_BYTES, $loop->requestSizes[2] );
		$this->assertStringContainsString( 'large completed tool output', (string) wp_json_encode( $result['history'] ) );
	}

	/** A persisted recovery marker bounds the first request after a browser resume. */
	public function test_persisted_provider_retry_compaction_bounds_resumed_request(): void {
		$large_evidence = str_repeat( 'persisted completed tool output ', 4000 );
		$history        = array(
			new UserMessage( array( new MessagePart( 'Resume the remaining onboarding checks.' ) ) ),
			new ModelMessage(
				array(
					new MessagePart( new FunctionCall( 'call_persisted_followup', 'wpab__sd-ai-agent__site-info', array() ) ),
				)
			),
			new UserMessage(
				array(
					new MessagePart(
						new FunctionResponse(
							'call_persisted_followup',
							'wpab__sd-ai-agent__site-info',
							array( 'content' => $large_evidence )
						)
					),
				)
			),
		);
		$loop           = new ScriptedAgentLoop(
			'',
			array(),
			$history,
			array(
				'provider_id' => 'sd-ai-agent-cloud',
				'model_id'    => 'superdav-chat-pro',
				'message_log' => array(
					array(
						'type'            => 'provider_retry_compaction',
						'payload_reduced' => true,
					),
				),
			),
			array( $this->create_scripted_result( 'Resumed with compact provider context.' ) )
		);

		$result = $loop->resume_from_checkpoint( 2 );

		$this->assertIsArray( $result );
		$this->assertSame( 'Resumed with compact provider context.', $result['reply'] );
		$this->assertCount( 1, $loop->requestSizes );
		$this->assertLessThanOrEqual( ConversationTrimmer::COMPACT_MAX_BYTES, $loop->requestSizes[0] );
		$this->assertStringContainsString( $large_evidence, (string) wp_json_encode( $result['history'] ) );
	}

	/** A failed continued compact request persists the full durable history. */
	public function test_persisted_provider_retry_compaction_failure_preserves_durable_history(): void {
		$session_id    = Database::create_session(
			array(
				'user_id' => 1,
				'title'   => 'Continued compact retry failure',
			)
		);
		$large_evidence = str_repeat( 'durable completed tool evidence ', 4000 );
		$history        = array(
			new UserMessage( array( new MessagePart( 'Resume the remaining onboarding checks.' ) ) ),
			new ModelMessage(
				array(
					new MessagePart( new FunctionCall( 'call_failed_followup', 'wpab__sd-ai-agent__site-info', array() ) ),
				)
			),
			new UserMessage(
				array(
					new MessagePart(
						new FunctionResponse(
							'call_failed_followup',
							'wpab__sd-ai-agent__site-info',
							array( 'content' => $large_evidence )
						)
					),
				)
			),
		);
		$loop           = new ScriptedAgentLoop(
			'',
			array(),
			$history,
			array(
				'session_id'  => $session_id,
				'provider_id' => 'sd-ai-agent-cloud',
				'model_id'    => 'superdav-chat-pro',
				'message_log' => array(
					array(
						'type'            => 'provider_retry_compaction',
						'payload_reduced' => true,
					),
				),
			),
			array( new WP_Error( 'sd_ai_agent_test_provider_timeout', 'Managed service unavailable.' ) )
		);

		$result = $loop->resume_from_checkpoint( 2 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_provider_retry_failed', $result->get_error_code() );
		$this->assertCount( 1, $loop->requestSizes );
		$this->assertLessThanOrEqual( ConversationTrimmer::COMPACT_MAX_BYTES, $loop->requestSizes[0] );
		$error_data = $result->get_error_data();
		$this->assertIsArray( $error_data );
		$this->assertStringContainsString( $large_evidence, (string) wp_json_encode( $error_data['history'] ?? array() ) );
		$paused_state = Database::load_and_clear_paused_state( $session_id );
		$this->assertIsArray( $paused_state );
		$this->assertStringContainsString( $large_evidence, (string) wp_json_encode( $paused_state['history'] ) );
	}

	/** A failed compact retry retains the full original recovery checkpoint. */
	public function test_large_provider_compact_retry_failure_preserves_original_paused_state(): void {
		$session_id = Database::create_session(
			array(
				'user_id' => 1,
				'title'   => 'Preserved provider retry checkpoint',
			)
		);
		$history    = array(
			new UserMessage( array( new MessagePart( 'Finish the onboarding workflow.' ) ) ),
			new ModelMessage(
				array(
					new MessagePart( new FunctionCall( 'call_preserved', 'wpab__sd-ai-agent__site-info', array() ) ),
				)
			),
			new UserMessage(
				array(
					new MessagePart(
						new FunctionResponse(
							'call_preserved',
							'wpab__sd-ai-agent__site-info',
							array( 'content' => str_repeat( 'original checkpoint evidence ', 4000 ) )
						)
					),
				)
			),
		);
		$loop       = new ScriptedAgentLoop(
			'',
			array(),
			$history,
			array(
				'session_id'  => $session_id,
				'provider_id' => 'sd-ai-agent-cloud',
				'model_id'    => 'superdav-chat-pro',
			),
			array(
				new WP_Error( 'sd_ai_agent_test_provider_timeout', 'Managed service unavailable.' ),
				new WP_Error(
					'sd_ai_agent_provider_payload_too_large',
					'Compacted request rejected.',
					array( 'status_code' => 413 )
				),
			)
		);

		$result = $loop->resume_from_checkpoint( 3 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_provider_payload_too_large', $result->get_error_code() );
		$this->assertCount( 2, $loop->requestSizes );
		$this->assertSame( array( 6, 1 ), $loop->requestAttemptLimits );
		$this->assertLessThan( $loop->requestSizes[0], $loop->requestSizes[1] );
		$paused_state = Database::load_and_clear_paused_state( $session_id );
		$this->assertIsArray( $paused_state );
		$this->assertStringContainsString( 'original checkpoint evidence', (string) wp_json_encode( $paused_state['history'] ) );
		$this->assertStringNotContainsString( 'Conversation compacted server-side', (string) wp_json_encode( $paused_state['history'] ) );
	}

	/** A successful reduced 413 retry clears the checkpoint restored after compaction. */
	public function test_compact_retry_413_reduction_success_clears_restored_paused_state(): void {
		$session_id = Database::create_session(
			array(
				'user_id' => 1,
				'title'   => 'Successful compact and payload recovery',
			)
		);
		$history    = array(
			new UserMessage( array( new MessagePart( str_repeat( 'older completed context ', 4000 ) ) ) ),
			new ModelMessage( array( new MessagePart( 'The earlier phase is complete.' ) ) ),
			new UserMessage( array( new MessagePart( 'Finish the current phase.' ) ) ),
			new ModelMessage(
				array(
					new MessagePart( new FunctionCall( 'call_current_phase', 'wpab__sd-ai-agent__site-info', array() ) ),
				)
			),
			new UserMessage(
				array(
					new MessagePart(
						new FunctionResponse(
							'call_current_phase',
							'wpab__sd-ai-agent__site-info',
							array( 'content' => str_repeat( 'current phase evidence ', 1500 ) )
						)
					),
				)
			),
		);
		$loop       = new ScriptedAgentLoop(
			'',
			array(),
			$history,
			array(
				'session_id'  => $session_id,
				'provider_id' => 'sd-ai-agent-cloud',
				'model_id'    => 'superdav-chat-pro',
			),
			array(
				new WP_Error( 'sd_ai_agent_test_provider_timeout', 'Managed service unavailable.' ),
				new WP_Error(
					'sd_ai_agent_provider_payload_too_large',
					'Compacted request rejected.',
					array(
						'status_code'             => 413,
						'request_size_source'     => 'complete_envelope',
						'request_bytes'           => 131072,
						'request_tokens_estimate' => 32768,
					)
				),
				$this->create_scripted_result( 'Recovered after compact and payload fallbacks.' ),
			)
		);

		$result = $loop->resume_from_checkpoint( 3 );

		$this->assertIsArray( $result );
		$this->assertSame( 'Recovered after compact and payload fallbacks.', $result['reply'] );
		$this->assertCount( 3, $loop->requestSizes );
		$this->assertSame( array( 6, 1, 6 ), $loop->requestAttemptLimits );
		$this->assertLessThan( $loop->requestSizes[0], $loop->requestSizes[1] );
		$this->assertLessThan( $loop->requestSizes[0], $loop->requestSizes[2] );
		$this->assertLessThan( (int) floor( $loop->requestSizes[1] * 0.9 ), $loop->requestSizes[2] );
		$this->assertNull( Database::load_and_clear_paused_state( $session_id ) );
	}

	/** A failed reduced 413 retry restores the checkpoint from before compaction. */
	public function test_compact_retry_413_reduction_failure_preserves_original_paused_state(): void {
		$session_id = Database::create_session(
			array(
				'user_id' => 1,
				'title'   => 'Failed compact and payload recovery',
			)
		);
		$history    = array(
			new UserMessage( array( new MessagePart( str_repeat( 'original tool evidence ', 5000 ) ) ) ),
			new ModelMessage( array( new MessagePart( 'The completed work must remain resumable.' ) ) ),
			new UserMessage( array( new MessagePart( 'Finish the current phase.' ) ) ),
		);
		$loop       = new ScriptedAgentLoop(
			'',
			array(),
			$history,
			array(
				'session_id'  => $session_id,
				'provider_id' => 'sd-ai-agent-cloud',
				'model_id'    => 'superdav-chat-pro',
			),
			array(
				new WP_Error( 'sd_ai_agent_test_provider_timeout', 'Managed service unavailable.' ),
				new WP_Error(
					'sd_ai_agent_provider_payload_too_large',
					'Compacted request rejected.',
					array(
						'status_code'             => 413,
						'request_size_source'     => 'complete_envelope',
						'request_bytes'           => 131072,
						'request_tokens_estimate' => 32768,
					)
				),
				new WP_Error( 'sd_ai_agent_test_provider_timeout', 'Reduced request unavailable.' ),
			)
		);

		$result = $loop->resume_from_checkpoint( 3 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_provider_retry_failed', $result->get_error_code() );
		$this->assertCount( 3, $loop->requestSizes );
		$this->assertSame( array( 6, 1, 6 ), $loop->requestAttemptLimits );
		$this->assertLessThan( $loop->requestSizes[0], $loop->requestSizes[1] );
		$this->assertLessThan( (int) floor( $loop->requestSizes[1] * 0.9 ), $loop->requestSizes[2] );
		$paused_state = Database::load_and_clear_paused_state( $session_id );
		$this->assertIsArray( $paused_state );
		$this->assertStringContainsString( 'original tool evidence', (string) wp_json_encode( $paused_state['history'] ) );
		$this->assertStringNotContainsString( 'Conversation compacted server-side', (string) wp_json_encode( $paused_state['history'] ) );
	}

	/**
	 * Accepted screenshot results survive provider exhaustion and resume from
	 * their post-results checkpoint without producing another browser-tool pause.
	 */
	public function test_client_results_provider_timeout_resumes_without_replay(): void {
		$session_id = Database::create_session( [
			'user_id' => 1,
			'title'   => 'Client result provider recovery',
		] );
		$job_id     = '55555555-6666-7777-8888-999999999999';
		$this->assertNotFalse( ActiveJobRepository::create( $session_id, $job_id, 1 ) );
		$options = [
			'session_id'    => $session_id,
			'active_job_id' => $job_id,
			'provider_id'   => 'scripted-provider',
			'model_id'      => 'scripted-model',
		];
		$history = [
			new UserMessage( [ new MessagePart( 'Review the theme code and rendered output.' ) ] ),
			new ModelMessage(
				[
					new MessagePart( new FunctionCall( 'call_screen_one', 'sd-ai-agent-js/screenshot-url', [ 'url' => '/theme/' ] ) ),
					new MessagePart( new FunctionCall( 'call_screen_two', 'sd-ai-agent-js/screenshot-url', [ 'url' => '/theme/post/' ] ) ),
				]
			),
		];

		$loop   = new ScriptedAgentLoop(
			'',
			[],
			$history,
			$options,
			[ new WP_Error( 'sd_ai_agent_test_provider_timeout', 'Managed service unavailable.' ) ]
		);
		$accepted_results = [
			[
				'id'     => 'call_screen_one',
				'name'   => 'sd-ai-agent-js/screenshot-url',
				'result' => [ 'success' => true, 'url' => '/theme/' ],
			],
			[
				'id'     => 'call_screen_two',
				'name'   => 'sd-ai-agent-js/screenshot-url',
				'result' => [ 'success' => true, 'url' => '/theme/post/' ],
			],
		];
		$result          = $loop->resume_after_client_tools( $accepted_results, 3 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_provider_retry_failed', $result->get_error_code() );
		$this->assertCount( 1, $loop->requestSizes );
		$state = Database::load_and_clear_paused_state( $session_id );
		$this->assertIsArray( $state );
		$serialized = (string) wp_json_encode( $state['history'] );
		$this->assertStringContainsString( 'Review the theme code and rendered output.', $serialized );
		$this->assertStringContainsString( 'call_screen_one', $serialized );
		$this->assertStringContainsString( 'call_screen_two', $serialized );
		$this->assertArrayNotHasKey( 'pending_client_tool_calls', $state );
		$this->assertFalse(
			ClientAbilityRouter::matches_pending_results(
				$state['pending_client_tool_calls'] ?? [],
				$accepted_results
			),
			'Accepted screenshot results must be invalid for replay against the post-results checkpoint.'
		);

		$job = ActiveJobRepository::get_by_job_id( $job_id );
		$this->assertNotNull( $job );
		$this->assertSame( AgentLoop::CHECKPOINT_BEFORE_PROVIDER_CALL, $job->checkpoint_phase );
		$this->assertStringContainsString( 'call_screen_one', $job->checkpoint );
		$this->assertStringContainsString( 'call_screen_two', $job->checkpoint );

		$resumed_loop = new ScriptedAgentLoop(
			'',
			[],
			ConversationSerializer::deserialize( $state['history'] ),
			[
				'provider_id' => 'scripted-provider',
				'model_id'    => 'scripted-model',
			],
			[ $this->create_scripted_result( 'Theme review continued from accepted screenshots.' ) ]
		);
		$resumed = $resumed_loop->resume_from_checkpoint( (int) ( $state['iterations_remaining'] ?? 1 ) );

		$this->assertIsArray( $resumed );
		$this->assertSame( 'Theme review continued from accepted screenshots.', $resumed['reply'] );
		$this->assertArrayNotHasKey( 'pending_client_tool_calls', $resumed );
		$this->assertCount( 1, $resumed_loop->requestSizes );
	}

	/**
	 * Test run() returns WP_Error on network failure (wp_remote_post returns WP_Error).
	 */
	public function test_run_returns_wp_error_on_network_failure(): void {
		$this->skip_if_sdk_unavailable();
		$this->mock_ai_network_failure();

		$loop   = new AgentLoop( 'Hello', [], [], $this->no_sleep_retry_options( 1 ) );
		$result = $loop->run();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_provider_retry_failed', $result->get_error_code() );
	}

	/**
	 * A crashed checkpoint ending in a model turn must not be sent back to the provider.
	 */
	public function test_resume_from_checkpoint_rejects_model_ended_history_before_provider_call(): void {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'wp_ai_client_prompt() is not available.' );
		}
		if ( ! class_exists( 'WordPress\AiClient\Messages\DTO\UserMessage' ) ) {
			$this->markTestSkipped( 'AI Client SDK message classes are not available.' );
		}

		$history = [
			new \WordPress\AiClient\Messages\DTO\UserMessage(
				[ new \WordPress\AiClient\Messages\DTO\MessagePart( 'Build the page.' ) ]
			),
			new \WordPress\AiClient\Messages\DTO\ModelMessage(
				[ new \WordPress\AiClient\Messages\DTO\MessagePart( 'I found the theme details.' ) ]
			),
		];

		$loop   = new AgentLoop( '', [], $history );
		$result = $loop->resume_from_checkpoint( 1 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_history_needs_user_turn', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertTrue( $data['recoverable'] );
		$this->assertCount( 2, $data['history'] );
		$this->assertSame( 'model', $data['history'][1]['role'] );
	}

	/** Constrained ability and collection policy is scoped around every server resume. */
	public function test_resume_paths_reapply_and_clear_anonymous_policy_context(): void {
		$options = array(
			'anonymous_allowed_abilities'   => array( 'sd-ai-agent/knowledge-search' ),
			'anonymous_allowed_collections' => array( 'support-docs' ),
			'anonymous_policy_active'       => true,
		);

		$checkpoint_loop = new ScriptedAgentLoop(
			'',
			array(),
			array( new UserMessage( array( new MessagePart( 'Continue safely.' ) ) ) ),
			$options,
			array( $this->create_scripted_result( 'Checkpoint resumed safely.' ) )
		);
		$checkpoint_result = $checkpoint_loop->resume_from_checkpoint( 1 );
		$this->assertIsArray( $checkpoint_result );
		$this->assertSame(
			array(
				'ability_mode'      => true,
				'collection_mode'   => true,
				'knowledge_allowed' => true,
			),
			$checkpoint_loop->policySnapshots[0]
		);
		$this->assertFalse( ToolDiscovery::is_anonymous_ability_mode() );
		$this->assertFalse( KnowledgeAbilities::is_public_collection_mode() );

		$confirmation_loop = new ScriptedAgentLoop(
			'',
			array(),
			array(
				new UserMessage( array( new MessagePart( 'Use a safe tool.' ) ) ),
				new ModelMessage(
					array(
						new MessagePart(
							new FunctionCall( 'call_resume_policy', 'wpab__sd-ai-agent__knowledge-search', array() )
						),
					)
				),
			),
			$options,
			array( $this->create_scripted_result( 'Confirmation resumed safely.' ) )
		);
		$confirmation_result = $confirmation_loop->resume_after_confirmation( false, 1 );
		$this->assertIsArray( $confirmation_result );
		$this->assertSame(
			array(
				'ability_mode'      => true,
				'collection_mode'   => true,
				'knowledge_allowed' => true,
			),
			$confirmation_loop->policySnapshots[0]
		);
		$this->assertFalse( ToolDiscovery::is_anonymous_ability_mode() );
		$this->assertFalse( KnowledgeAbilities::is_public_collection_mode() );

		$client_tool_loop = new ScriptedAgentLoop(
			'',
			array(),
			array(
				new UserMessage( array( new MessagePart( 'Continue after the browser tool.' ) ) ),
				new ModelMessage(
					array(
						new MessagePart(
							new FunctionCall( 'call_client_resume_policy', 'browser-safe-tool', array() )
						),
					)
				),
			),
			$options,
			array( $this->create_scripted_result( 'Client tool resumed safely.' ) )
		);
		$client_tool_result = $client_tool_loop->resume_after_client_tools(
			array(
				array(
					'id'     => 'call_client_resume_policy',
					'name'   => 'browser-safe-tool',
					'result' => array( 'ok' => true ),
				),
			),
			1
		);
		$this->assertIsArray( $client_tool_result );
		$this->assertSame(
			array(
				'ability_mode'      => true,
				'collection_mode'   => true,
				'knowledge_allowed' => true,
			),
			$client_tool_loop->policySnapshots[0]
		);
		$this->assertFalse( ToolDiscovery::is_anonymous_ability_mode() );
		$this->assertFalse( KnowledgeAbilities::is_public_collection_mode() );
	}

	/**
	 * Test run() returns WP_Error with 401 Unauthorized response.
	 */
	public function test_run_returns_wp_error_on_unauthorized(): void {
		$this->skip_if_sdk_unavailable();
		$call_count = 0;
		$this->mock_ai_response_sequence(
			[
				[
					'status'  => 401,
					'message' => 'Unauthorized',
					'body'    => wp_json_encode( [ 'error' => [ 'message' => 'Invalid API key' ] ] ),
				],
			],
			$call_count
		);

		$loop   = new AgentLoop( 'Hello' );
		$result = $loop->run();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_provider_unavailable', $result->get_error_code() );
		$this->assertSame( 1, $call_count );
	}

	/**
	 * A provider 413 must explain how to reduce the request instead of exposing
	 * the SDK transport error, which offers no recovery action to the user.
	 */
	public function test_run_returns_actionable_error_on_payload_too_large(): void {
		$loop   = new AgentLoop( 'Hello' );
		$method = new \ReflectionMethod( AgentLoop::class, 'provider_error_to_wp_error' );
		$method->setAccessible( true );
		$result = $method->invoke(
			$loop,
			new \WP_Error( 'http_request_failed', 'Client error (413): Payload Too Large' ),
			413
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_provider_payload_too_large', $result->get_error_code() );
		$this->assertStringContainsString( 'Start a new chat', $result->get_error_message() );
		$this->assertSame( 413, $result->get_error_data()['status_code'] );
	}

	/** Resolved managed-provider metadata survives error conversion and recovery wrapping. */
	public function test_provider_error_retains_resolved_provider_for_safe_diagnostics(): void {
		$loop       = new AgentLoop( 'Hello' );
		$conversion = new \ReflectionMethod( AgentLoop::class, 'provider_error_to_wp_error' );
		$conversion->setAccessible( true );
		$result = $conversion->invoke(
			$loop,
			new \WP_Error( 'prompt_client_error', 'Client error (402): PRIVATE_PROVIDER_RESPONSE' ),
			402,
			'sd-ai-agent-cloud'
		);

		$recovery = new \ReflectionMethod( AgentLoop::class, 'with_error_recovery_data' );
		$recovery->setAccessible( true );
		$result = $recovery->invoke( $loop, $result );
		$data   = $result->get_error_data();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 402, $data['status_code'] );
		$this->assertSame( 'sd-ai-agent-cloud', $data['provider_id'] );
		$this->assertSame(
			ActiveJobFailureDiagnostic::REASON_CREDIT_EXHAUSTED,
			ActiveJobFailureDiagnostic::reason_from_error( $result, $data['provider_id'] )
		);
		$this->assertStringNotContainsString( 'PRIVATE_PROVIDER_RESPONSE', wp_json_encode( $data ) );
	}

	/**
	 * Each distinct provider invocation may make one demonstrably smaller 413 retry.
	 */
	public function test_provider_413_recovery_is_scoped_to_each_provider_invocation(): void {
		$history = array(
			new \WordPress\AiClient\Messages\DTO\UserMessage(
				array( new \WordPress\AiClient\Messages\DTO\MessagePart( str_repeat( 'Old provider context. ', 1200 ) ) )
			),
			new \WordPress\AiClient\Messages\DTO\ModelMessage(
				array( new \WordPress\AiClient\Messages\DTO\MessagePart( 'Old response.' ) )
			),
			new \WordPress\AiClient\Messages\DTO\UserMessage(
				array( new \WordPress\AiClient\Messages\DTO\MessagePart( str_repeat( 'More recent context. ', 300 ) ) )
			),
			new \WordPress\AiClient\Messages\DTO\ModelMessage(
				array( new \WordPress\AiClient\Messages\DTO\MessagePart( 'More recent response.' ) )
			),
		);

		$options = array(
			'provider_id' => 'scripted-provider',
			'model_id'    => 'scripted-model',
		);
		$loop    = new ScriptedAgentLoop(
			'Current request.',
			array(),
			$history,
			$options,
			array(
				new WP_Error( 'sd_ai_agent_provider_payload_too_large', 'Payload too large.', array( 'status_code' => 413, 'request_bytes' => 131072, 'request_size_source' => 'complete_envelope' ) ),
				$this->create_scripted_result( '' ),
				new WP_Error( 'sd_ai_agent_provider_payload_too_large', 'Payload too large.', array( 'status_code' => 413, 'request_bytes' => 131072, 'request_size_source' => 'complete_envelope' ) ),
				$this->create_scripted_result( 'Recovered after the follow-up retry.' ),
			)
		);
		$result = $loop->run();

		$this->assertIsArray( $result );
		$this->assertSame( 'Recovered after the follow-up retry.', $result['reply'] );
		$this->assertCount( 4, $loop->requestSizes );
		$this->assertLessThan( $loop->requestSizes[0], $loop->requestSizes[1] );
		$this->assertLessThan( $loop->requestSizes[2], $loop->requestSizes[3] );

		$recoveries = array_filter(
			$result['messages'],
			static fn( array $entry ): bool => 'provider_payload_recovery' === ( $entry['type'] ?? '' )
		);
		$this->assertCount( 2, $recoveries );
	}

	/**
	 * A repeated 413 from the auxiliary follow-up is returned with resumable data.
	 */
	public function test_followup_propagates_repeated_payload_error_with_recovery_data(): void {
		$history = array(
			new \WordPress\AiClient\Messages\DTO\UserMessage(
				array( new \WordPress\AiClient\Messages\DTO\MessagePart( str_repeat( 'Earlier context. ', 1200 ) ) )
			),
			new \WordPress\AiClient\Messages\DTO\ModelMessage(
				array( new \WordPress\AiClient\Messages\DTO\MessagePart( 'Earlier response.' ) )
			),
		);

		$options = array(
			'provider_id' => 'scripted-provider',
			'model_id'    => 'scripted-model',
		);
		$loop    = new ScriptedAgentLoop(
			'Current request.',
			array(),
			$history,
			$options,
			array(
				$this->create_scripted_result( '' ),
				new WP_Error( 'sd_ai_agent_provider_payload_too_large', 'Payload too large.', array( 'status_code' => 413, 'request_bytes' => 131072, 'request_size_source' => 'complete_envelope' ) ),
				new WP_Error( 'sd_ai_agent_provider_payload_too_large', 'Payload too large.', array( 'status_code' => 413, 'request_bytes' => 78643, 'request_size_source' => 'complete_envelope' ) ),
			)
		);
		$result = $loop->run();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_provider_payload_too_large', $result->get_error_code() );
		$this->assertCount( 3, $loop->requestSizes );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertTrue( $data['fallback_attempted'] );
		$this->assertTrue( $data['payload_reduced'] );
		$this->assertTrue( $data['recoverable'] );
		$this->assertNotEmpty( $data['history'] );
	}

	/**
	 * An irreducible newest turn is rejected locally before any provider call.
	 */
	public function test_oversized_current_turn_is_rejected_locally_with_recoverable_history(): void {
		Settings::instance()->update(
			array(
				'provider_request_max_bytes'  => 1024,
				'provider_request_max_tokens' => 256,
			)
		);

		$current = str_repeat( 'Oversized current request. ', 400 );
		$loop    = new ScriptedAgentLoop(
			$current,
			array(),
			array(),
			array(
				'provider_id' => 'scripted-provider',
				'model_id'    => 'scripted-model',
				'session_id'  => 47,
			),
			array( $this->create_scripted_result( 'Should not be sent.' ) )
		);
		$result  = $loop->run();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_provider_payload_budget_exceeded', $result->get_error_code() );
		$this->assertCount( 0, $loop->requestSizes );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertTrue( $data['local_rejection'] );
		$this->assertFalse( $data['fallback_attempted'] );
		$this->assertSame( 'compact_session_available', $data['recovery_outcome'] );
		$this->assertTrue( $data['recoverable'] );
		$this->assertSame( 'compact_session', $data['recovery']['action'] );
		$this->assertSame( 47, $data['recovery']['source_session_id'] );
		$this->assertStringContainsString( $current, (string) wp_json_encode( $data['history'] ) );
	}

	/** A measured local transport preflight rejection receives one reduced retry. */
	public function test_local_transport_payload_rejection_retries_once_with_reduced_history(): void {
		$history = array(
			new UserMessage( array( new MessagePart( str_repeat( 'Completed tool evidence. ', 1200 ) ) ) ),
			new ModelMessage( array( new MessagePart( 'The completed work remains available.' ) ) ),
		);
		$loop    = new ScriptedAgentLoop(
			'Current request.',
			array(),
			$history,
			array(
				'provider_id' => 'scripted-provider',
				'model_id'    => 'scripted-model',
				'session_id'  => 48,
			),
			array(
				new WP_Error(
					'sd_ai_agent_provider_payload_budget_exceeded',
					'Complete request envelope exceeded the local budget.',
					array(
						'status_code'         => 413,
						'local_rejection'     => true,
						'request_bytes'       => 131072,
						'request_size_source' => 'complete_envelope',
					)
				),
				$this->create_scripted_result( 'Recovered after local envelope reduction.' ),
			)
		);

		$result = $loop->run();

		$this->assertIsArray( $result );
		$this->assertSame( 'Recovered after local envelope reduction.', $result['reply'] );
		$this->assertCount( 2, $loop->requestSizes );
		$this->assertLessThan( $loop->requestSizes[0], $loop->requestSizes[1] );
		$recoveries = array_filter(
			$result['messages'],
			static fn( array $entry ): bool => 'provider_payload_recovery' === ( $entry['type'] ?? '' )
		);
		$this->assertCount( 1, $recoveries );
	}

	/** A failed measured local retry must not request another compaction loop. */
	public function test_repeated_local_transport_payload_rejection_stops_after_reduced_retry(): void {
		$history = array(
			new UserMessage( array( new MessagePart( str_repeat( 'Completed tool evidence. ', 1200 ) ) ) ),
			new ModelMessage( array( new MessagePart( 'The completed work remains available.' ) ) ),
		);
		$loop    = new ScriptedAgentLoop(
			'Current request.',
			array(),
			$history,
			array(
				'provider_id' => 'scripted-provider',
				'model_id'    => 'scripted-model',
				'session_id'  => 49,
			),
			array(
				new WP_Error(
					'sd_ai_agent_provider_payload_budget_exceeded',
					'Complete request envelope exceeded the local budget.',
					array(
						'status_code'         => 413,
						'local_rejection'     => true,
						'request_bytes'       => 131072,
						'request_size_source' => 'complete_envelope',
					)
				),
				new WP_Error(
					'sd_ai_agent_provider_payload_budget_exceeded',
					'Reduced request still exceeded the local budget.',
					array(
						'status_code'         => 413,
						'local_rejection'     => true,
						'request_bytes'       => 78643,
						'request_size_source' => 'complete_envelope',
					)
				),
			)
		);

		$result = $loop->run();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_provider_payload_budget_exceeded', $result->get_error_code() );
		$this->assertCount( 2, $loop->requestSizes );
		$this->assertLessThan( $loop->requestSizes[0], $loop->requestSizes[1] );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertTrue( $data['fallback_attempted'] );
		$this->assertTrue( $data['payload_reduced'] );
		$this->assertArrayNotHasKey( 'recovery', $data );
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

	/**
	 * Test malformed direct tool names still receive matching error tool results.
	 */
	public function test_run_pairs_invalid_direct_function_call_with_error_response(): void {
		$this->skip_if_sdk_unavailable();
		if ( ! class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
			$this->markTestSkipped( 'WP_AI_Client_Ability_Function_Resolver not available.' );
		}

		$call_count          = 0;
		$second_request_body = '';
		$tool_call_body      = wp_json_encode(
			[
				'id'      => 'chatcmpl-invalid-tool',
				'object'  => 'chat.completion',
				'choices' => [
					[
						'index'         => 0,
						'message'       => [
							'role'       => 'assistant',
							'content'    => null,
							'tool_calls' => [
								[
									'id'       => 'call_invalid_direct',
									'type'     => 'function',
									'function' => [
										'name'      => 'sd-ai-agent/ability-search',
										'arguments' => '{"query":"stress-test","max_results":1}',
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

		$reply_body = wp_json_encode(
			[
				'id'      => 'chatcmpl-invalid-tool-reply',
				'object'  => 'chat.completion',
				'choices' => [
					[
						'index'         => 0,
						'message'       => [ 'role' => 'assistant', 'content' => 'Invalid tool call handled.' ],
						'finish_reason' => 'stop',
					],
				],
				'usage'   => [ 'prompt_tokens' => 20, 'completion_tokens' => 5, 'total_tokens' => 25 ],
			]
		);

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$call_count, &$second_request_body, $tool_call_body, $reply_body ) {
				if ( false !== strpos( $url, 'fake-ai-proxy.test' ) ) {
					++$call_count;
					if ( 2 === $call_count ) {
						$second_request_body = (string) ( $args['body'] ?? '' );
					}

					return [
						'headers'  => [ 'content-type' => 'application/json' ],
						'body'     => 1 === $call_count ? $tool_call_body : $reply_body,
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

		$loop   = new AgentLoop( 'Run invalid direct tool call regression' );
		$result = $loop->run();

		$this->assertIsArray( $result );
		$this->assertSame( 'Invalid tool call handled.', $result['reply'] ?? '' );
		$this->assertSame( 2, $call_count );
		$this->assertStringContainsString( 'invalid_ability_call', $second_request_body );
		$this->assertStringContainsString( 'call_invalid_direct', $second_request_body );
	}

	/**
	 * Test length-capped tool calls are discarded and converted to guidance.
	 */
	public function test_run_discards_truncated_tool_call_and_continues(): void {
		$this->skip_if_sdk_unavailable();
		if ( ! class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
			$this->markTestSkipped( 'WP_AI_Client_Ability_Function_Resolver not available.' );
		}

		$call_count          = 0;
		$second_request_body = '';
		$truncated_body      = wp_json_encode(
			[
				'id'      => 'chatcmpl-truncated',
				'object'  => 'chat.completion',
				'choices' => [
					[
						'index'         => 0,
						'message'       => [
							'role'       => 'assistant',
							'content'    => null,
							'tool_calls' => [
								[
									'id'       => 'call_truncated',
									'type'     => 'function',
									'function' => [
										'name'      => 'wpab__sd-ai-agent__memory-list',
										'arguments' => '{"arguments":{"query":"unterminated',
									],
								],
							],
						],
						'finish_reason' => 'length',
					],
				],
				'usage'   => [ 'prompt_tokens' => 10, 'completion_tokens' => 4096, 'total_tokens' => 4106 ],
			]
		);

		$reply_body = wp_json_encode(
			[
				'id'      => 'chatcmpl-recovered',
				'object'  => 'chat.completion',
				'choices' => [
					[
						'index'         => 0,
						'message'       => [ 'role' => 'assistant', 'content' => 'Recovered with smaller work.' ],
						'finish_reason' => 'stop',
					],
				],
				'usage'   => [ 'prompt_tokens' => 20, 'completion_tokens' => 8, 'total_tokens' => 28 ],
			]
		);

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$call_count, &$second_request_body, $truncated_body, $reply_body ) {
				if ( false !== strpos( $url, 'fake-ai-proxy.test' ) ) {
					++$call_count;
					if ( 2 === $call_count ) {
						$second_request_body = is_string( $args['body'] ?? null ) ? $args['body'] : (string) wp_json_encode( $args['body'] ?? [] );
					}

					return [
						'headers'  => [ 'content-type' => 'application/json' ],
						'body'     => ( 1 === $call_count ) ? $truncated_body : $reply_body,
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

		$loop   = new AgentLoop( 'List memories with a large filter', [], [], [ 'max_iterations' => 3 ] );
		$result = $loop->run();

		$this->assertIsArray( $result );
		$this->assertSame( 'Recovered with smaller work.', $result['reply'] );
		$this->assertSame( 2, $call_count );
		$this->assertStringContainsString( 'previous response was truncated', $second_request_body );
		$this->assertStringContainsString( 'max_tokens cap', $second_request_body );

		$calls = array_filter( $result['tool_calls'], fn( $entry ) => 'call' === $entry['type'] );
		$this->assertEmpty( $calls, 'The truncated tool call must not be dispatched or logged as a call.' );

		$events = array_filter( $result['messages'], fn( $entry ) => 'truncated_tool_call' === ( $entry['reason'] ?? '' ) );
		$this->assertNotEmpty( $events, 'The truncation should be visible in the message log.' );
	}

	/**
	 * Regression test for the Kimi K2.6 stall: model emits a preamble, hits
	 * finish=length before opening any tool call, agent loop must inject
	 * distinct guidance and retry instead of silently exiting with the
	 * preamble as the final reply.
	 */
	public function test_run_recovers_from_preamble_only_truncation(): void {
		$this->skip_if_sdk_unavailable();
		if ( ! class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
			$this->markTestSkipped( 'WP_AI_Client_Ability_Function_Resolver not available.' );
		}

		$call_count          = 0;
		$second_request_body = '';

		// Turn 1: preamble-only with length finish (the Kimi K2.6 stall shape).
		$preamble_body = wp_json_encode(
			[
				'id'      => 'chatcmpl-preamble',
				'object'  => 'chat.completion',
				'choices' => [
					[
						'index'         => 0,
						'message'       => [
							'role'    => 'assistant',
							'content' => "Now I'll create the full landing page with professional Gutenberg block markup:",
						],
						'finish_reason' => 'length',
					],
				],
				'usage'   => [ 'prompt_tokens' => 10, 'completion_tokens' => 8192, 'total_tokens' => 8202 ],
			]
		);

		// Turn 2: model takes the guidance and ends with a normal reply.
		$reply_body = wp_json_encode(
			[
				'id'      => 'chatcmpl-recovered',
				'object'  => 'chat.completion',
				'choices' => [
					[
						'index'         => 0,
						'message'       => [ 'role' => 'assistant', 'content' => 'Created hero. Will append rest now.' ],
						'finish_reason' => 'stop',
					],
				],
				'usage'   => [ 'prompt_tokens' => 50, 'completion_tokens' => 8, 'total_tokens' => 58 ],
			]
		);

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$call_count, &$second_request_body, $preamble_body, $reply_body ) {
				if ( false !== strpos( $url, 'fake-ai-proxy.test' ) ) {
					++$call_count;
					if ( 2 === $call_count ) {
						$second_request_body = is_string( $args['body'] ?? null ) ? $args['body'] : (string) wp_json_encode( $args['body'] ?? [] );
					}

					return [
						'headers'  => [ 'content-type' => 'application/json' ],
						'body'     => ( 1 === $call_count ) ? $preamble_body : $reply_body,
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

		$loop   = new AgentLoop( 'Build me a landing page', [], [], [ 'max_iterations' => 3 ] );
		$result = $loop->run();

		$this->assertIsArray( $result, 'Loop must recover, not return WP_Error after a single preamble truncation.' );
		$this->assertSame( 'Created hero. Will append rest now.', $result['reply'] );
		$this->assertSame( 2, $call_count, 'Loop must retry once after preamble truncation.' );

		// Verify the guidance was injected into the next request body so the
		// model actually receives the steering signal.
		$this->assertStringContainsString( 'hit the max_tokens cap', $second_request_body );
		$this->assertStringContainsString( 'before you opened a tool call', $second_request_body );
		$this->assertStringContainsString( 'sd-ai-agent/append-post-content', $second_request_body );

		// The preamble must not be returned as the final reply.
		$this->assertNotEquals(
			"Now I'll create the full landing page with professional Gutenberg block markup:",
			$result['reply'],
			'The truncated preamble must not leak through as the final reply.'
		);

		// And the event should be visible in the message log.
		$events = array_filter(
			$result['messages'],
			static fn( $entry ) => 'truncated_before_tool_call' === ( $entry['reason'] ?? '' )
		);
		$this->assertNotEmpty( $events, 'The preamble truncation should be visible in the message log.' );
	}

	/**
	 * Test that repeated preamble-only truncations abort cleanly with a
	 * WP_Error instead of burning every iteration on the same stall.
	 */
	public function test_run_aborts_on_repeated_preamble_truncation(): void {
		$this->skip_if_sdk_unavailable();
		if ( ! class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
			$this->markTestSkipped( 'WP_AI_Client_Ability_Function_Resolver not available.' );
		}

		$call_count    = 0;
		$preamble_body = wp_json_encode(
			[
				'id'      => 'chatcmpl-stuck',
				'object'  => 'chat.completion',
				'choices' => [
					[
						'index'         => 0,
						'message'       => [
							'role'    => 'assistant',
							'content' => 'Working on it now.',
						],
						'finish_reason' => 'length',
					],
				],
				'usage'   => [ 'prompt_tokens' => 10, 'completion_tokens' => 8192, 'total_tokens' => 8202 ],
			]
		);

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$call_count, $preamble_body ) {
				if ( false !== strpos( $url, 'fake-ai-proxy.test' ) ) {
					++$call_count;
					return [
						'headers'  => [ 'content-type' => 'application/json' ],
						'body'     => $preamble_body,
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

		// Allow plenty of iterations — we want to prove the abort, not max_iterations.
		$loop   = new AgentLoop( 'Build me a landing page', [], [], [ 'max_iterations' => 10 ] );
		$result = $loop->run();

		$this->assertInstanceOf( \WP_Error::class, $result, 'Repeated preamble truncations must abort with a WP_Error.' );
		$this->assertSame( 'preamble_truncation_loop', $result->get_error_code() );
		$this->assertStringContainsString( 'output cap', $result->get_error_message() );

		// PREAMBLE_TRUNCATION_MAX_RETRIES = 2 → after the first stall we retry
		// twice more (3 total provider calls) and then abort. Not all 10
		// iterations should have burned.
		$this->assertLessThanOrEqual( 4, $call_count, 'Loop must abort early, not burn every iteration.' );
		$this->assertGreaterThanOrEqual( 3, $call_count, 'Loop must allow at least one retry before aborting.' );
	}

	// -------------------------------------------------------------------------
	// Max iterations
	// -------------------------------------------------------------------------

	/**
	 * Test run() triggers the graceful fallback when max iterations are exhausted
	 * with only tool calls. If the fallback provider response is another tool call,
	 * the loop must replace it with an honest terminal response rather than
	 * persisting an unexecuted call and showing an empty reply.
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

		// The graceful fallback fires after the loop exhausts. The mocked provider
		// still returns a tool call, so the deterministic terminal response must win.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'reply', $result );
		$this->assertArrayHasKey( 'tool_calls', $result );
		$this->assertArrayHasKey( 'iterations_used', $result );
		$this->assertStringContainsString( 'tool-call limit', $result['reply'] );
		$this->assertSame( 'max_iterations', $result['exit_reason'] );
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

		// Use max_iterations = 2 and one no-sleep provider attempt to keep the test fast.
		$options                   = $this->no_sleep_retry_options( 1 );
		$options['max_iterations'] = 2;
		$loop                      = new AgentLoop( 'Loop forever', [], [], $options );
		$result = $loop->run();

		// The auxiliary fallback must propagate its provider failure rather than
		// replacing it with an unrelated max-iterations error.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_provider_retry_failed', $result->get_error_code() );

		// Error data should keep resumable history and loop metadata.
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'tool_calls', $data );
		$this->assertArrayHasKey( 'iterations_used', $data );
		$this->assertTrue( $data['recoverable'] );
		$this->assertNotEmpty( $data['history'] );
		// 2 loop iterations + 1 fallback attempt = 3.
		$this->assertSame( 3, $data['iterations_used'] );
	}

	/**
	 * Empty calls to different abilities must not bypass the validation storm guard.
	 */
	public function test_empty_required_input_failures_stop_after_bounded_attempts(): void {
		if ( ! class_exists( 'WordPress\\AiClient\\Messages\\DTO\\ModelMessage' ) ) {
			$this->markTestSkipped( 'WP AI Client message classes are not available.' );
		}

		$loop   = new AgentLoop( 'Update a template screenshot' );
		$method = new \ReflectionMethod( AgentLoop::class, 'record_empty_required_input_failures' );
		$method->setAccessible( true );

		for ( $attempt = 1; $attempt <= AgentLoop::MAX_CONSECUTIVE_EMPTY_TOOL_CALL_FAILURES; ++$attempt ) {
			$call_id      = 'call_empty_' . $attempt;
			$ability_name = 1 === $attempt % 2
				? 'wpab__sd-ai-agent__ability-search'
				: 'wpab__sd-ai-agent__memory-list';
			$calls   = new \WordPress\AiClient\Messages\DTO\ModelMessage(
				array(
					new \WordPress\AiClient\Messages\DTO\MessagePart(
						new FunctionCall( $call_id, $ability_name, array() )
					)
				)
			);
			$responses = new \WordPress\AiClient\Messages\DTO\UserMessage(
				array(
					new \WordPress\AiClient\Messages\DTO\MessagePart(
						new FunctionResponse(
							$call_id,
							$ability_name,
							array(
								'code'                    => 'ability_invalid_input',
								'missing_required_fields' => array( 'query' ),
							)
						)
					)
				)
			);

			$this->assertSame(
				AgentLoop::MAX_CONSECUTIVE_EMPTY_TOOL_CALL_FAILURES === $attempt,
				$method->invoke( $loop, $calls, $responses )
			);
		}
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

	/**
	 * Durable checkpoints retain a compact request snapshot rather than growing
	 * with page context, activity logs, or raw client-ability descriptors.
	 */
	public function test_checkpoint_save_bounds_resume_state(): void {
		$job_id = 'test-bounded-checkpoint-1';
		$this->assertNotFalse( ActiveJobRepository::create( 1, $job_id, 1 ) );

		try {
			$history = array();
			for ( $i = 0; $i < 12; ++$i ) {
				$history[] = new UserMessage( array( new MessagePart( str_repeat( 'checkpoint context ', 500 ) ) ) );
			}

			$catalog = JsAbilityCatalog::get_descriptors_by_name();
			$loop    = new AgentLoop(
				'',
				array(),
				$history,
				array(
					'active_job_id'    => $job_id,
					'provider_id'      => 'openai_compat',
					'model_id'         => 'test-model',
					'page_context'     => array(
						'summary' => str_repeat( 'too-large-context ', 300 ),
						'url'     => 'https://example.test/wp-admin/',
						'unknown' => str_repeat( 'not-needed ', 300 ),
					),
					'client_abilities' => array( $catalog['sd-ai-agent-js/navigate-to'] ),
					'tool_call_log'    => array( array( 'details' => str_repeat( 'tool-log ', 300 ) ) ),
					'message_log'      => array( array( 'text' => str_repeat( 'message-log ', 300 ) ) ),
				)
			);

			$method = new \ReflectionMethod( AgentLoop::class, 'save_active_job_checkpoint' );
			$method->setAccessible( true );
			$method->invoke( $loop, AgentLoop::CHECKPOINT_BEFORE_PROVIDER_CALL, 3 );

			$row = ActiveJobRepository::get_by_job_id( $job_id );
			$this->assertNotNull( $row );
			$checkpoint = json_decode( (string) $row->checkpoint, true );
			$this->assertIsArray( $checkpoint );
			$this->assertArrayHasKey( 'history', $checkpoint );
			$this->assertArrayHasKey( 'checkpoint_resume_metadata', $checkpoint );
			$this->assertArrayNotHasKey( 'tool_call_log', $checkpoint );
			$this->assertArrayNotHasKey( 'message_log', $checkpoint );
			$this->assertArrayNotHasKey( 'client_abilities', $checkpoint );
			$this->assertSame( array( 'sd-ai-agent-js/navigate-to' ), $checkpoint['client_ability_names'] );
			$this->assertSame( 'https://example.test/wp-admin/', $checkpoint['page_context']['url'] );
			$this->assertArrayNotHasKey( 'summary', $checkpoint['page_context'] );

			$request = AgentLoop::describe_checkpoint_request(
				$checkpoint['history'],
				AgentLoop::CHECKPOINT_BEFORE_PROVIDER_CALL,
				$checkpoint['provider_id'],
				$checkpoint['model_id']
			);
			$this->assertLessThanOrEqual( ConversationTrimmer::COMPACT_MAX_BYTES, $request['request_bytes'] );
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $checkpoint['checkpoint_resume_metadata']['next_request']['fingerprint'] );
			$this->assertLessThan( ConversationTrimmer::COMPACT_MAX_BYTES + 20000, strlen( (string) $row->checkpoint ) );
		} finally {
			ActiveJobRepository::delete( $job_id );
		}
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
	 * Test the Advanced companion guidance gives administrators a manual path.
	 */
	public function test_advanced_companion_guidance_links_to_manual_download(): void {
		$builder     = new SystemInstructionBuilder();
		$guidance    = SystemInstructionBuilder::build_advanced_companion_section();
		$instruction = $builder->build( array() );

		$this->assertStringContainsString( 'sd_ai_agent_advanced_plugin_required', $guidance );
		$this->assertStringContainsString( 'https://sdaiagent.com/advanced/', $guidance );
		$this->assertStringContainsString( 'Do not attempt to download, install, activate, or update', $guidance );
		$this->assertStringContainsString( 'sd_ai_agent_advanced_plugin_required', $instruction );
		$this->assertStringContainsString( 'https://sdaiagent.com/advanced/', $instruction );
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

	/**
	 * Test confirmed tools that were not in the original direct-tool set are
	 * allowed for the single resumed execution.
	 */
	public function test_resume_after_confirmation_allows_confirmed_tool_once(): void {
		$this->skip_if_sdk_unavailable();
		if ( ! class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
			$this->markTestSkipped( 'WP_AI_Client_Ability_Function_Resolver not available.' );
		}

		Settings::instance()->update(
			[
				'tool_permissions' => [
					'sd-ai-agent/memory-save' => 'confirm',
				],
			]
		);

		$first_body = wp_json_encode(
			[
				'id'      => 'chatcmpl-confirm-once',
				'object'  => 'chat.completion',
				'choices' => [
					[
						'index'         => 0,
						'message'       => [
							'role'       => 'assistant',
							'content'    => null,
							'tool_calls' => [
								[
									'id'       => 'call_confirm_once',
									'type'     => 'function',
									'function' => [
										'name'      => 'wpab__sd-ai-agent__memory-save',
										'arguments' => wp_json_encode(
											[
												'category' => 'general',
												'content'  => 'Confirmed one-time tool execution',
											]
										),
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

		$call_count = 0;
		$this->mock_ai_response_sequence(
			[
				[ 'body' => $first_body ],
				[ 'reply' => 'Saved after approval.' ],
			],
			$call_count
		);

		$loop   = new AgentLoop( 'Save this after approval', [ 'sd-ai-agent/memory-list' ] );
		$paused = $loop->run();

		$this->assertIsArray( $paused );
		$this->assertTrue( $paused['awaiting_confirmation'] ?? false );
		$this->assertContains( 'sd-ai-agent/memory-save', $paused['approved_once_abilities'] ?? [] );

		$result = $loop->resume_after_confirmation( true, (int) $paused['iterations_remaining'] );

		$this->assertIsArray( $result );
		$this->assertSame( 'Saved after approval.', $result['reply'] );
		$this->assertStringNotContainsString( 'ability_not_allowed', wp_json_encode( $result ) ?: '' );
	}

	/** Confirmed browser-only calls must not be sent through the PHP resolver. */
	public function test_confirmation_resume_returns_confirmed_client_call_to_browser(): void {
		$catalog = JsAbilityCatalog::get_descriptors_by_name();
		$loop    = new ScriptedAgentLoop(
			'',
			array(),
			array(
				new UserMessage( array( new MessagePart( 'Insert a block after approval.' ) ) ),
				new ModelMessage(
					array(
						new MessagePart(
							new FunctionCall(
								'call_confirmed_insert',
								'sd-ai-agent-js/insert-block',
								array( 'blockName' => 'core/paragraph' )
							)
						)
					)
				),
			),
			array(
				'approved_once_abilities' => array( 'sd-ai-agent-js/insert-block' ),
				'client_abilities'        => array( $catalog['sd-ai-agent-js/insert-block'] ),
			),
			array()
		);

		$result = $loop->resume_after_confirmation( true, 1 );

		$this->assertIsArray( $result );
		$this->assertSame( 'sd-ai-agent-js/insert-block', $result['pending_client_tool_calls'][0]['name'] );
		$this->assertFalse( $result['pending_client_tool_calls'][0]['annotations']['readonly'] );
		$this->assertTrue( $result['pending_client_tool_calls'][0]['user_confirmed'] ?? false );
	}

	/**
	 * A confirmed mixed response executes only the PHP partition, persists the
	 * browser call, and continues after its result without a live provider.
	 */
	public function test_confirmation_resume_partitions_mixed_server_and_client_calls_without_provider(): void {
		$catalog        = JsAbilityCatalog::get_descriptors_by_name();
		$history_before = array(
			new UserMessage( array( new MessagePart( 'Save this, then insert a paragraph after approval.' ) ) ),
		);
		$confirmation_message = new ModelMessage(
			array(
				new MessagePart(
					new FunctionCall(
						'call_confirmed_memory',
						'wpab__sd-ai-agent__memory-save',
						array( 'category' => 'general', 'content' => 'Confirmed mixed call.' )
					)
				),
				new MessagePart(
					new FunctionCall(
						'call_confirmed_insert',
						'wpab__sd-ai-agent-js__insert-block',
						array( 'blockName' => 'core/paragraph' )
					)
				),
			)
		);
		$history = $history_before;
		ConversationSerializer::append_assistant_message( $history, $confirmation_message );
		$persisted_history = ConversationSerializer::serialize( $history );
		$this->assertCount( 3, $persisted_history );
		$server_response = new UserMessage(
			array(
				new MessagePart(
					new FunctionResponse(
						'call_confirmed_memory',
						'wpab__sd-ai-agent__memory-save',
						wp_json_encode( array( 'saved' => true ) ) ?: '{}'
					)
				)
			)
		);
		$loop            = new ScriptedAbilityAgentLoop(
			'',
			array(),
			ConversationSerializer::deserialize( $persisted_history ),
			array(
				'provider_id'             => 'scripted-provider',
				'model_id'                => 'scripted-model',
				'approved_once_abilities' => array( 'sd-ai-agent/memory-save', 'sd-ai-agent-js/insert-block' ),
				'client_abilities'        => array( $catalog['sd-ai-agent-js/insert-block'] ),
				'confirmation_message'    => $confirmation_message->toArray(),
				'confirmation_history_before' => ConversationSerializer::serialize( $history_before ),
			),
			array(),
			$server_response
		);

		$paused = $loop->resume_after_confirmation( true, 4 );

		$this->assertIsArray( $paused );
		$this->assertCount( 1, $loop->executedAbilityMessages );
		$this->assertCount( 1, $loop->executedAbilityMessages[0]->getParts() );
		$this->assertSame(
			'wpab__sd-ai-agent__memory-save',
			$loop->executedAbilityMessages[0]->getParts()[0]->getFunctionCall()->getName()
		);
		$this->assertSame( 'sd-ai-agent-js/insert-block', $paused['pending_client_tool_calls'][0]['name'] );
		$this->assertFalse( $paused['pending_client_tool_calls'][0]['annotations']['readonly'] );
		$this->assertTrue( $paused['pending_client_tool_calls'][0]['user_confirmed'] ?? false );

		$continuation_loop = new ScriptedAgentLoop(
			'',
			array(),
			ConversationSerializer::deserialize( $paused['history'] ),
			array(
				'provider_id'      => 'scripted-provider',
				'model_id'         => 'scripted-model',
				'client_abilities' => array( $catalog['sd-ai-agent-js/insert-block'] ),
			),
			array( $this->create_scripted_result( 'Mixed confirmation completed.' ) )
		);
		$result            = $continuation_loop->resume_after_client_tools(
			array(
				array(
					'id'     => 'call_confirmed_insert',
					'name'   => 'sd-ai-agent-js/insert-block',
					'result' => array( 'inserted' => true ),
				)
			),
			(int) $paused['iterations_remaining']
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Mixed confirmation completed.', $result['reply'] );
		$this->assertCount( 1, $continuation_loop->requestSizes );
	}

	/** Rejection must discard every transport-split call in a confirmation batch. */
	public function test_confirmation_rejection_discards_entire_split_tool_batch_without_provider(): void {
		$history_before = array(
			new UserMessage( array( new MessagePart( 'Make a change after approval.' ) ) ),
		);
		$history        = $history_before;
		ConversationSerializer::append_assistant_message(
			$history,
			new ModelMessage(
				array(
					new MessagePart(
						new FunctionCall(
							'call_rejected_server',
							'wpab__sd-ai-agent__memory-save',
							array( 'category' => 'general', 'content' => 'Do not save.' )
						)
					),
					new MessagePart(
						new FunctionCall(
							'call_rejected_client',
							'wpab__sd-ai-agent-js__insert-block',
							array( 'blockName' => 'core/paragraph' )
						)
					),
				)
			)
		);
		$this->assertCount( 3, $history );
		$loop = new ScriptedAgentLoop(
			'',
			array(),
			ConversationSerializer::deserialize( ConversationSerializer::serialize( $history ) ),
			array(
				'provider_id'                 => 'scripted-provider',
				'model_id'                    => 'scripted-model',
				'confirmation_history_before' => ConversationSerializer::serialize( $history_before ),
			),
			array( $this->create_scripted_result( 'I will not make that change.' ) )
		);

		$result = $loop->resume_after_confirmation( false, 1 );

		$this->assertIsArray( $result );
		$this->assertSame( 'I will not make that change.', $result['reply'] );
		$history_property = new \ReflectionProperty( AgentLoop::class, 'history' );
		$history_property->setAccessible( true );
		$remaining_history = $history_property->getValue( $loop );

		$this->assertIsArray( $remaining_history );
		foreach ( $remaining_history as $message ) {
			$this->assertInstanceOf( Message::class, $message );
			foreach ( $message->getParts() as $part ) {
				$this->assertNull( $part->getFunctionCall() );
			}
		}
	}

	/**
	 * A valid browser-result continuation after a mixed confirmed server/browser
	 * ability response must continue the same turn rather than treating the
	 * paused model tool call as a malformed assistant-ended history.
	 */
	public function test_confirmation_then_mixed_client_tool_continuation_completes_without_new_user_turn(): void {
		$this->skip_if_sdk_unavailable();
		if ( ! class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
			$this->markTestSkipped( 'WP_AI_Client_Ability_Function_Resolver not available.' );
		}

		Settings::instance()->update(
			array(
				'tool_permissions' => array(
					'sd-ai-agent/memory-save' => 'confirm',
				),
			)
		);

		$confirmation_body = wp_json_encode(
			array(
				'id'      => 'chatcmpl-confirm-client-tool',
				'object'  => 'chat.completion',
				'choices' => array(
					array(
						'index'         => 0,
						'message'       => array(
							'role'       => 'assistant',
							'content'    => null,
							'tool_calls' => array(
								array(
									'id'       => 'call_confirm_then_client',
									'type'     => 'function',
									'function' => array(
										'name'      => 'wpab__sd-ai-agent__memory-save',
										'arguments' => wp_json_encode(
											array(
												'category' => 'general',
												'content'  => 'Confirmed before browser validation.',
											)
										),
									),
								),
							array(
								'id'       => 'call_theme_completion',
								'type'     => 'function',
								'function' => array(
									'name'      => 'wpab__sd-ai-agent-js__validate-theme-completion',
									'arguments' => wp_json_encode(
										array(
											'stylesheet' => 'test-theme',
										)
									),
								),
							),
						),
						),
						'finish_reason' => 'tool_calls',
					),
				),
				'usage'   => array( 'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15 ),
			)
		);
		$completed_body    = wp_json_encode(
			array(
				'id'      => 'chatcmpl-client-tool-complete',
				'object'  => 'chat.completion',
				'choices' => array(
					array(
						'index'         => 0,
						'message'       => array(
							'role'    => 'assistant',
							'content' => 'Theme completion validated after approval.',
						),
						'finish_reason' => 'stop',
					),
				),
				'usage'   => array( 'prompt_tokens' => 16, 'completion_tokens' => 6, 'total_tokens' => 22 ),
			)
		);
		$call_count        = 0;
		$this->mock_ai_response_sequence(
			array(
				array( 'body' => $confirmation_body ),
				array( 'body' => $completed_body ),
			),
			$call_count
		);

		$catalog            = JsAbilityCatalog::get_descriptors_by_name();
		$client_abilities   = array( $catalog['sd-ai-agent-js/validate-theme-completion'] );
		$initial_loop       = new AgentLoop(
			'Save the completion record, then validate the generated theme.',
			array(),
			array(),
			array( 'client_abilities' => $client_abilities )
		);
		$confirmation_pause = $initial_loop->run();

		$this->assertIsArray( $confirmation_pause );
		$this->assertTrue( $confirmation_pause['awaiting_confirmation'] ?? false );
		$this->assertArrayHasKey( 'confirmation_message', $confirmation_pause );
		$this->assertArrayHasKey( 'confirmation_history_before', $confirmation_pause );

		$confirmed_loop = new AgentLoop(
			'',
			array(),
			ConversationSerializer::deserialize( $confirmation_pause['history'] ),
			array(
				'approved_once_abilities' => $confirmation_pause['approved_once_abilities'],
				'client_abilities'        => $client_abilities,
				'confirmation_message'    => $confirmation_pause['confirmation_message'],
				'confirmation_history_before' => $confirmation_pause['confirmation_history_before'],
			)
		);
		$client_pause   = $confirmed_loop->resume_after_confirmation(
			true,
			(int) $confirmation_pause['iterations_remaining']
		);

		$this->assertIsArray( $client_pause );
		$this->assertArrayHasKey( 'pending_client_tool_calls', $client_pause );
		$this->assertSame(
			'sd-ai-agent-js/validate-theme-completion',
			$client_pause['pending_client_tool_calls'][0]['name']
		);

		$continuation_loop = new AgentLoop(
			'',
			array(),
			ConversationSerializer::deserialize( $client_pause['history'] ),
			array( 'client_abilities' => $client_abilities )
		);
		$result            = $continuation_loop->resume_after_client_tools(
			array(
				array(
					'id'     => 'call_theme_completion',
					'name'   => 'sd-ai-agent-js/validate-theme-completion',
					'result' => array( 'passed' => true ),
				),
			),
			(int) $client_pause['iterations_remaining']
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Theme completion validated after approval.', $result['reply'] );
		$this->assertSame( 2, $call_count );
	}

	/**
	 * A confirmed server response followed by a client response survives the
	 * serialized paused-state round trip and reaches the provider boundary.
	 *
	 * This uses ScriptedAgentLoop rather than a real provider resolver so the
	 * history boundary is covered when the full ability resolver is unavailable
	 * in the local WordPress test environment.
	 */
	public function test_serialized_confirmation_then_client_result_continues_same_turn(): void {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'wp_ai_client_prompt() is not available — requires WordPress 7.0+.' );
		}

		$history = array(
			new UserMessage( array( new MessagePart( 'Activate the generated theme, then validate it in the browser.' ) ) ),
			new ModelMessage(
				array(
					new MessagePart(
						new FunctionCall(
							'call_activate_theme',
							'wpab__sd-ai-agent__activate-theme',
							array( 'stylesheet' => 'test-theme' )
						)
					)
				)
			),
			new UserMessage(
				array(
					new MessagePart(
						new FunctionResponse(
							'call_activate_theme',
							'wpab__sd-ai-agent__activate-theme',
							wp_json_encode( array( 'stylesheet' => 'test-theme', 'activated' => true ) ) ?: '{}'
						)
					)
				)
			),
			new ModelMessage(
				array(
					new MessagePart(
						new FunctionCall(
							'call_theme_completion',
							'wpab__sd-ai-agent-js__validate-theme-completion',
							array( 'stylesheet' => 'test-theme' )
						)
					)
				)
			),
		);

		$loop = new ScriptedAgentLoop(
			'',
			array(),
			ConversationSerializer::deserialize( ConversationSerializer::serialize( $history ) ),
			array(
				'provider_id' => 'scripted-provider',
				'model_id'    => 'scripted-model',
			),
			array( $this->create_scripted_result( 'Theme completion validated after approval.' ) )
		);

		$result = $loop->resume_after_client_tools(
			array(
				array(
					'id'     => 'call_theme_completion',
					'name'   => 'sd-ai-agent-js/validate-theme-completion',
					'result' => array( 'passed' => true ),
				)
			),
			4
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Theme completion validated after approval.', $result['reply'] );
		$this->assertCount( 1, $loop->requestSizes );
	}

	/**
	 * Test one-time confirmed abilities are merged into the resolver tool set.
	 */
	public function test_resolve_abilities_includes_approved_once_abilities(): void {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'Abilities API not available.' );
		}

		$loop = new AgentLoop(
			'Test prompt',
			[ 'sd-ai-agent/memory-list' ],
			[],
			[
				'approved_once_abilities' => [ 'sd-ai-agent/memory-save' ],
			]
		);

		$method = new \ReflectionMethod( AgentLoop::class, 'resolve_abilities' );
		$method->setAccessible( true );

		$resolved = $method->invoke( $loop );
		$this->assertIsArray( $resolved );

		$names = array_map(
			static function ( $ability ): string {
				return $ability instanceof \WP_Ability ? $ability->get_name() : '';
			},
			$resolved
		);

		$this->assertContains( 'sd-ai-agent/memory-list', $names );
		$this->assertContains( 'sd-ai-agent/memory-save', $names );
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
	 * Capture the next outgoing wp_remote_post() body for assertion.
	 *
	 * Used by the builder-config regression tests below so we can prove the
	 * `max_tokens` and `temperature` values computed by AgentLoop actually
	 * land in the outgoing request body. Without capture, those values are
	 * unobservable from a passing/failing assertion on the parsed reply
	 * alone — which is exactly what hid the
	 * `method_exists()`-vs-`__call` bug for months.
	 *
	 * @param string|null &$captured_body Reference populated with the JSON body string.
	 */
	private function capture_next_request_body( ?string &$captured_body ): void {
		$body = wp_json_encode(
			[
				'id'      => 'chatcmpl-capture',
				'object'  => 'chat.completion',
				'choices' => [
					[
						'index'         => 0,
						'message'       => [ 'role' => 'assistant', 'content' => 'ok' ],
						'finish_reason' => 'stop',
					],
				],
				'usage'   => [ 'prompt_tokens' => 5, 'completion_tokens' => 1, 'total_tokens' => 6 ],
			]
		);

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$captured_body, $body ) {
				if ( false !== strpos( $url, 'fake-ai-proxy.test' ) && null === $captured_body ) {
					$captured_body = is_string( $args['body'] ?? null )
						? $args['body']
						: (string) wp_json_encode( $args['body'] ?? [] );
				}

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
	 * Regression test: builder config calls must NOT be guarded by
	 * `method_exists()`, because the underlying builder routes its
	 * snake_case API (`using_max_tokens`, `using_temperature`) through
	 * `__call` — which `method_exists()` does not detect.
	 *
	 * Before the fix:
	 *   - `method_exists( $builder, 'using_max_tokens' )` returned false,
	 *   - both `using_*` calls were silently skipped,
	 *   - `$config->getMaxTokens()` was null at provider time,
	 *   - the anthropic-max connector fell back to its hard-coded 4096
	 *     default, causing frequent `stop_reason=max_tokens` truncations
	 *     and slow retry-with-guidance round-trips.
	 *
	 * After the fix (`is_callable()` instead of `method_exists()`):
	 *   - both setters are invoked,
	 *   - the outgoing request body carries `max_tokens` and `temperature`.
	 *
	 * The fake endpoint is OpenAI-compatible (chat.completion), so the
	 * body is JSON with `max_tokens` and `temperature` at the top level.
	 */
	public function test_builder_receives_max_tokens_and_temperature(): void {
		$this->skip_if_sdk_unavailable();

		// Pick an explicit, non-legacy value so the resolver short-circuits
		// to the honoured-override branch and we can pin the exact number.
		Settings::instance()->update(
			[
				'max_output_tokens' => 9999,
				'temperature'       => 0.42,
			]
		);

		$captured = null;
		$this->capture_next_request_body( $captured );

		$loop   = new AgentLoop( 'Reply succinctly' );
		$result = $loop->run();

		$this->assertIsArray( $result );
		$this->assertIsString( $captured, 'Expected to capture an outgoing request body.' );

		$decoded = json_decode( (string) $captured, true );
		$this->assertIsArray( $decoded, 'Outgoing body should be JSON.' );

		$this->assertArrayHasKey(
			'max_tokens',
			$decoded,
			'max_tokens must reach the provider — the builder magic-method guard regressed.'
		);
		$this->assertSame(
			9999,
			(int) $decoded['max_tokens'],
			'The configured max_output_tokens must reach the provider unchanged.'
		);

		$this->assertArrayHasKey(
			'temperature',
			$decoded,
			'temperature must reach the provider — the builder magic-method guard regressed.'
		);
		$this->assertEqualsWithDelta(
			0.42,
			(float) $decoded['temperature'],
			0.0001,
			'The configured temperature must reach the provider unchanged.'
		);
	}

	/**
	 * Regression test: the legacy 4096 default must NOT reach the provider.
	 *
	 * Existing installs that upgraded from pre-7rl carry a saved
	 * `max_output_tokens=4096` they never explicitly chose. AgentLoop's
	 * resolver maps that exact value to AUTO so the per-model catalog
	 * picks a sensible cap (64K for Sonnet 4). This test proves the
	 * resolver's output actually reaches the outgoing request body — i.e.
	 * that the builder's `using_max_tokens()` call is wired up.
	 */
	public function test_builder_emits_catalog_value_when_legacy_4096_saved(): void {
		$this->skip_if_sdk_unavailable();

		Settings::instance()->update( [ 'max_output_tokens' => 4096 ] );

		$captured = null;
		$this->capture_next_request_body( $captured );

		$loop = new AgentLoop( 'Reply succinctly', [], [], [ 'model_id' => 'claude-sonnet-4-6' ] );
		$loop->run();

		$this->assertIsString( $captured, 'Expected to capture an outgoing request body.' );
		$decoded = json_decode( (string) $captured, true );
		$this->assertIsArray( $decoded );

		$this->assertArrayHasKey( 'max_tokens', $decoded );
		$this->assertGreaterThan(
			4096,
			(int) $decoded['max_tokens'],
			'Saved 4096 must be remapped via the catalog (Sonnet 4 documents 64K), not honoured verbatim.'
		);
	}

	/**
	 * Regression test: `temperature` MUST be omitted from the outgoing
	 * request body for OpenAI reasoning models, because those endpoints
	 * reject the field with HTTP 400. OpenAI reasoning models return
	 * "Unsupported parameter: 'temperature' is not supported with this model.";
	 * Anthropic Max Claude Opus 4.7 returns "`temperature` is deprecated for
	 * this model."
	 *
	 * Reproduction before the fix (2026-05-16, live OpenAI API):
	 *
	 *     wp sd-ai-agent prompt 'test' --provider=openai --model=gpt-5.5 \
	 *         --skip-tools --verbose
	 *     => Warning: Bad Request (400) - Unsupported parameter:
	 *        'temperature' is not supported with this model.
	 *
	 * The fix adds {@see AgentLoop::model_omits_temperature()} and short-circuits
	 * the `using_temperature()` call in send_prompt() for any matched ID.
	 *
	 * This test exercises the agent loop end-to-end against the fake OpenAI-
	 * compatible endpoint with matched model IDs and asserts the captured
	 * outgoing JSON body has NO `temperature` key. `max_tokens` is still
	 * expected because output-token caps remain valid for these models.
	 *
	 * @dataProvider temperature_omitting_model_id_provider
	 */
	public function test_builder_omits_temperature_for_models_that_reject_it( string $model_id ): void {
		$this->skip_if_sdk_unavailable();

		// A non-default temperature so the assertion can distinguish "not
		// sent" from "sent at the AgentLoop default of 0.7".
		Settings::instance()->update(
			[
				'max_output_tokens' => 8192,
				'temperature'       => 0.42,
			]
		);

		$captured = null;
		$this->capture_next_request_body( $captured );

		$loop = new AgentLoop( 'Reply succinctly', [], [], [ 'model_id' => $model_id ] );
		$loop->run();

		$this->assertIsString( $captured, 'Expected to capture an outgoing request body for ' . $model_id );
		$decoded = json_decode( (string) $captured, true );
		$this->assertIsArray( $decoded, 'Outgoing body should be JSON for ' . $model_id );

		$this->assertArrayNotHasKey(
			'temperature',
			$decoded,
			sprintf(
				'Model %s must not receive a `temperature` field — the provider returns HTTP 400 when it is present.',
				$model_id
			)
		);

		// Sanity: max_tokens still goes through.
		$this->assertArrayHasKey(
			'max_tokens',
			$decoded,
			'max_tokens must still reach the provider for ' . $model_id
		);
	}

	/**
	 * Data provider covering model families enumerated by
	 * {@see AgentLoop::model_omits_temperature()}.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function temperature_omitting_model_id_provider(): array {
		return [
			'gpt-5'              => [ 'gpt-5' ],
			'gpt-5.4'            => [ 'gpt-5.4' ],
			'gpt-5.4-mini'       => [ 'gpt-5.4-mini' ],
			'gpt-5.5'            => [ 'gpt-5.5' ],
			'gpt-5.5-pro'        => [ 'gpt-5.5-pro' ],
			'gpt-5-codex'        => [ 'gpt-5-codex' ],
			'gpt-5.5 dated snap' => [ 'gpt-5.5-2026-04-23' ],
			'o1'                 => [ 'o1' ],
			'o1-mini'            => [ 'o1-mini' ],
			'o3'                 => [ 'o3' ],
			'o3-mini'            => [ 'o3-mini' ],
			'o4-mini'            => [ 'o4-mini' ],
			'claude-opus-4-7'    => [ 'claude-opus-4-7' ],
			'claude-opus-4-7 dated snap' => [ 'claude-opus-4-7-20260513' ],
			'claude-opus-4-8'    => [ 'claude-opus-4-8' ],
			'claude-opus-4-8 dated snap' => [ 'claude-opus-4-8-20260513' ],
		];
	}

	/**
	 * Counter-test: `temperature` MUST still reach non-reasoning OpenAI
	 * models (gpt-4*, gpt-4o, gpt-4.1, gpt-3.5*) and other providers. This
	 * guards against an over-broad temperature-omission detector accidentally
	 * stripping temperature for models that accept it.
	 *
	 * @dataProvider non_reasoning_model_id_provider
	 */
	public function test_builder_keeps_temperature_for_non_reasoning_models( string $model_id ): void {
		$this->skip_if_sdk_unavailable();

		Settings::instance()->update(
			[
				'max_output_tokens' => 8192,
				'temperature'       => 0.33,
			]
		);

		$captured = null;
		$this->capture_next_request_body( $captured );

		$loop = new AgentLoop( 'Reply succinctly', [], [], [ 'model_id' => $model_id ] );
		$loop->run();

		$this->assertIsString( $captured, 'Expected to capture an outgoing request body for ' . $model_id );
		$decoded = json_decode( (string) $captured, true );
		$this->assertIsArray( $decoded );

		$this->assertArrayHasKey(
			'temperature',
			$decoded,
			'Non-reasoning model ' . $model_id . ' must still receive the `temperature` field.'
		);
		$this->assertEqualsWithDelta(
			0.33,
			(float) $decoded['temperature'],
			0.0001,
			'Configured temperature must reach non-reasoning model ' . $model_id . ' unchanged.'
		);
	}

	/**
	 * Data provider for models that MUST keep receiving `temperature`.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function non_reasoning_model_id_provider(): array {
		return [
			'gpt-4'             => [ 'gpt-4' ],
			'gpt-4-turbo'       => [ 'gpt-4-turbo' ],
			'gpt-4o'            => [ 'gpt-4o' ],
			'gpt-4.1'           => [ 'gpt-4.1' ],
			'gpt-3.5-turbo'     => [ 'gpt-3.5-turbo' ],
			'claude-sonnet-4-6' => [ 'claude-sonnet-4-6' ],
			'claude-opus-4-6'   => [ 'claude-opus-4-6' ],
			'claude-opus-4-5'   => [ 'claude-opus-4-5' ],
			'gemini-2.5-pro'    => [ 'gemini-2.5-pro' ],
		];
	}

	/**
	 * Resolve the private get_effective_max_output_tokens() with a given
	 * saved value and model_id so we can exercise the legacy / AUTO / ceiling
	 * branches without spinning up the full agent loop.
	 *
	 * @param int    $saved    Value as it would be saved in settings.
	 * @param string $model_id Model the loop thinks it is talking to.
	 * @return int Effective cap after resolution.
	 */
	private function resolve_effective_tokens( int $saved, string $model_id ): int {
		$rc        = new \ReflectionClass( AgentLoop::class );
		$loop      = $rc->newInstanceWithoutConstructor();
		$model_p   = $rc->getProperty( 'model_id' );
		$tokens_p  = $rc->getProperty( 'max_output_tokens' );
		$method    = $rc->getMethod( 'get_effective_max_output_tokens' );
		$model_p->setAccessible( true );
		$tokens_p->setAccessible( true );
		$method->setAccessible( true );

		$model_p->setValue( $loop, $model_id );
		$tokens_p->setValue( $loop, $saved );

		return (int) $method->invoke( $loop );
	}

	/**
	 * Test AUTO sentinel (0) resolves via the per-model catalog.
	 */
	public function test_effective_max_tokens_auto_resolves_via_catalog(): void {
		$this->assertSame(
			64000,
			$this->resolve_effective_tokens( 0, 'claude-sonnet-4-6' ),
			'AUTO should prefix-match claude-sonnet-4 -> 64000 (documented Sonnet 4 output cap).'
		);
	}

	/**
	 * Test the legacy 4096 default is treated as AUTO so existing installs
	 * benefit from the per-model catalog without a settings migration.
	 *
	 * Regression test for the truncated-tool-call class of bug where existing
	 * installs upgraded from pre-7rl carry max_output_tokens=4096 that they
	 * never explicitly chose, and modern models cannot complete a single
	 * landing-page tool call within that budget.
	 */
	public function test_effective_max_tokens_legacy_4096_treated_as_auto(): void {
		$this->assertSame(
			64000,
			$this->resolve_effective_tokens( 4096, 'claude-sonnet-4-6' ),
			'Saved 4096 (the legacy default) should resolve via catalog, not be honoured as an explicit cap.'
		);
	}

	/**
	 * Test that a deliberately chosen non-legacy value is honoured verbatim.
	 *
	 * Anything that is not exactly the legacy 4096 sentinel must be treated
	 * as an explicit user override (subject to the ceiling clamp).
	 */
	public function test_effective_max_tokens_explicit_override_honored(): void {
		$this->assertSame(
			8000,
			$this->resolve_effective_tokens( 8000, 'claude-sonnet-4-6' ),
			'A non-legacy explicit cap should pass through unchanged.'
		);
		$this->assertSame(
			4095,
			$this->resolve_effective_tokens( 4095, 'claude-sonnet-4-6' ),
			'4095 is not the legacy default and must be honoured as an explicit cap.'
		);
		$this->assertSame(
			4097,
			$this->resolve_effective_tokens( 4097, 'claude-sonnet-4-6' ),
			'4097 is not the legacy default and must be honoured as an explicit cap.'
		);
	}

	/**
	 * Test ceiling clamp applies to absurdly large saved values.
	 */
	public function test_effective_max_tokens_clamped_at_ceiling(): void {
		$this->assertSame(
			Settings::MAX_OUTPUT_TOKENS_CEILING,
			$this->resolve_effective_tokens( 9_999_999, 'claude-sonnet-4-6' ),
			'Values above MAX_OUTPUT_TOKENS_CEILING must be clamped.'
		);
	}

	/**
	 * Regression test: Opus 4.6 and 4.7 document a 128K output cap, which
	 * is HIGHER than Sonnet 4.6's 64K. An earlier version of the catalog
	 * inverted this (all Opus = 32K, Sonnet = 64K) which would have made
	 * the more capable model artificially worse at long-form generation.
	 */
	public function test_effective_max_tokens_opus_47_higher_than_sonnet_46(): void {
		$opus_47   = $this->resolve_effective_tokens( 0, 'claude-opus-4-7' );
		$sonnet_46 = $this->resolve_effective_tokens( 0, 'claude-sonnet-4-6' );

		$this->assertSame( 128000, $opus_47, 'Opus 4.7 documents 128K output.' );
		$this->assertSame( 64000, $sonnet_46, 'Sonnet 4.6 documents 64K output.' );
		$this->assertGreaterThan(
			$sonnet_46,
			$opus_47,
			'Opus must not have a lower cap than Sonnet of the same generation.'
		);
	}

	/**
	 * Regression test: the longest-prefix matcher must pick the most
	 * specific Opus point release rather than falling back to the family
	 * default. `claude-opus-4-1` documents 32K while `claude-opus-4-5`
	 * documents 64K and `claude-opus-4-7` documents 128K — these must
	 * each resolve independently.
	 */
	public function test_effective_max_tokens_opus_point_releases_resolve_independently(): void {
		$this->assertSame( 32000, $this->resolve_effective_tokens( 0, 'claude-opus-4-1' ) );
		$this->assertSame( 64000, $this->resolve_effective_tokens( 0, 'claude-opus-4-5' ) );
		$this->assertSame( 128000, $this->resolve_effective_tokens( 0, 'claude-opus-4-6' ) );
		$this->assertSame( 128000, $this->resolve_effective_tokens( 0, 'claude-opus-4-7' ) );
		// Dated snapshot suffix must still resolve to the right point release.
		$this->assertSame(
			128000,
			$this->resolve_effective_tokens( 0, 'claude-opus-4-7-20260513' )
		);
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

	/**
	 * Empty update-global-styles calls must be blocked before ability dispatch.
	 */
	public function test_run_guards_empty_global_styles_update_and_recovers(): void {
		$this->skip_if_sdk_unavailable();

		$call_count           = 0;
		$second_request_body  = '';
		$empty_tool_call_body = static function ( string $call_id ): string {
			return (string) wp_json_encode(
				[
					'id'      => 'chatcmpl-empty-global-styles-' . $call_id,
					'object'  => 'chat.completion',
					'choices' => [
						[
							'index'         => 0,
							'message'       => [
								'role'       => 'assistant',
								'content'    => null,
								'tool_calls' => [
									[
										'id'       => $call_id,
										'type'     => 'function',
										'function' => [
											'name'      => 'wpab__sd-ai-agent__update-global-styles',
											'arguments' => '{"styles":[],"settings":[],"site_url":""}',
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
		};

		$final_body = wp_json_encode(
			[
				'id'      => 'chatcmpl-empty-global-styles-recovered',
				'object'  => 'chat.completion',
				'choices' => [
					[
						'index'         => 0,
						'message'       => [ 'role' => 'assistant', 'content' => 'I could not apply global styles because no concrete style partial was available, so I stopped that step and kept the homepage build moving.' ],
						'finish_reason' => 'stop',
					],
				],
				'usage'   => [ 'prompt_tokens' => 20, 'completion_tokens' => 12, 'total_tokens' => 32 ],
			]
		);

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$call_count, &$second_request_body, $empty_tool_call_body, $final_body ) {
				if ( false !== strpos( $url, 'fake-ai-proxy.test' ) ) {
					++$call_count;
					if ( 2 === $call_count ) {
						$second_request_body = is_string( $args['body'] ?? null ) ? $args['body'] : (string) wp_json_encode( $args['body'] ?? [] );
					}

					return [
						'headers'  => [ 'content-type' => 'application/json' ],
						'body'     => $call_count < 3 ? $empty_tool_call_body( 'call_empty_' . $call_count ) : $final_body,
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

		$loop   = new AgentLoop( 'Build a homepage', [], [], [ 'max_iterations' => 4 ] );
		$result = $loop->run();

		$this->assertIsArray( $result );
		$this->assertSame( 'I could not apply global styles because no concrete style partial was available, so I stopped that step and kept the homepage build moving.', $result['reply'] );
		$this->assertStringContainsString( 'Empty global styles updates are not dispatched', $second_request_body );
		$this->assertStringContainsString( 'Do not retry that call unchanged', $second_request_body );

		$responses = array_filter(
			$result['tool_calls'],
			static fn( $entry ) => 'response' === ( $entry['type'] ?? '' )
		);
		$this->assertCount( 2, $responses, 'The empty global-styles calls should receive synthetic guard responses.' );
		foreach ( $responses as $response ) {
			$this->assertStringContainsString( 'sd_ai_agent_empty_global_styles_update_guarded', (string) $response['response'] );
			$this->assertStringNotContainsString( 'Either styles or settings is required.', (string) $response['response'] );
		}
	}

	/**
	 * The second bounded stock call must import automatically instead of spending
	 * the final stock slot on another candidate search.
	 */
	public function test_onboarding_media_budget_normalizes_second_stock_search(): void {
		if ( ! class_exists( FunctionCall::class ) ) {
			$this->markTestSkipped( 'WP AI Client message classes are not available.' );
		}

		$method = new \ReflectionMethod( AgentLoop::class, 'enforce_onboarding_media_budget' );
		$method->setAccessible( true );

		$loop   = new AgentLoop( 'Build a photographer site', array(), array(), array( 'agent_slug' => 'onboarding' ) );
		$result = $method->invoke(
			$loop,
			new ModelMessage(
				array(
					new MessagePart(
						new FunctionCall(
							'stock-search-one',
							'wpab__sd-ai-agent__stock-image',
							array( 'action' => 'search', 'keyword' => 'newlywed couple', 'usage' => 'hero', 'orientation' => 'landscape', 'min_width' => 1200, 'limit' => 12 )
						)
					),
					new MessagePart(
						new FunctionCall(
							'stock-search-two',
							'wpab__sd-ai-agent__stock-image',
							array( 'action' => 'search', 'provider' => 'openverse', 'keyword' => 'portrait photography studio natural light', 'limit' => 12 )
						)
					),
				)
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['removed'] );
		$parts = $result['message']->getParts();
		$this->assertCount( 2, $parts );
		$firstArgs  = $parts[0]->getFunctionCall()->getArgs();
		$secondArgs = $parts[1]->getFunctionCall()->getArgs();
		$this->assertSame( 'search', $firstArgs['action'] );
		$this->assertArrayNotHasKey( 'action', $secondArgs );
		$this->assertArrayNotHasKey( 'provider', $secondArgs );
		$this->assertArrayNotHasKey( 'limit', $secondArgs );
		$this->assertArrayNotHasKey( 'min_width', $secondArgs );
		$this->assertSame( 'newlywed couple', $secondArgs['keyword'] );
		$this->assertSame( 'hero', $secondArgs['usage'] );
		$this->assertSame( 'landscape', $secondArgs['orientation'] );

		$dispatcherLoop = new AgentLoop(
			'Build a photographer site',
			array(),
			array(
				new ModelMessage(
					array(
						new MessagePart( new FunctionCall( 'prior-stock-search', 'wpab__sd-ai-agent__stock-image', array( 'action' => 'search', 'keyword' => 'wedding' ) ) ),
					)
				),
			),
			array( 'agent_slug' => 'onboarding' )
		);
		$dispatcherResult = $method->invoke(
			$dispatcherLoop,
			new ModelMessage(
				array(
					new MessagePart(
						new FunctionCall(
							'dispatcher-stock-search',
							'wpab__sd-ai-agent__ability-call',
							array(
								'ability'   => 'sd-ai-agent/stock-image',
								'arguments' => array( 'keyword' => 'outdoor family portrait golden hour', 'usage' => 'gallery', 'orientation' => 'portrait' ),
							)
						)
					),
				)
			)
		);

		$dispatcherCall = $dispatcherResult['message']->getParts()[0]->getFunctionCall();
		$dispatcherArgs = $dispatcherCall->getArgs();
		$this->assertSame( 'sd-ai-agent/stock-image', $dispatcherArgs['ability'] );
		$this->assertArrayNotHasKey( 'action', $dispatcherArgs['arguments'] );
		$this->assertArrayNotHasKey( 'provider', $dispatcherArgs['arguments'] );
		$this->assertSame( 'wedding', $dispatcherArgs['arguments']['keyword'] );
		$this->assertSame( 'gallery', $dispatcherArgs['arguments']['usage'] );
		$this->assertSame( 'portrait', $dispatcherArgs['arguments']['orientation'] );

		$candidateImportResult = $method->invoke(
			$dispatcherLoop,
			new ModelMessage(
				array(
					new MessagePart(
						new FunctionCall(
							'candidate-import',
							'wpab__sd-ai-agent__stock-image',
							array( 'action' => 'import', 'provider' => 'openverse', 'image_id' => 'reviewed-image', 'keyword' => 'wedding' )
						)
					),
				)
			)
		);
		$candidateImportArgs = $candidateImportResult['message']->getParts()[0]->getFunctionCall()->getArgs();
		$this->assertSame( 'import', $candidateImportArgs['action'] );
		$this->assertSame( 'openverse', $candidateImportArgs['provider'] );
		$this->assertSame( 'reviewed-image', $candidateImportArgs['image_id'] );
	}

	/**
	 * Setup Assistant media acquisition must stay bounded across direct and
	 * dispatcher calls, including several calls emitted in one model turn.
	 */
	public function test_onboarding_media_budget_removes_excess_calls(): void {
		if ( ! class_exists( FunctionCall::class ) ) {
			$this->markTestSkipped( 'WP AI Client message classes are not available.' );
		}

		$history = array(
			new ModelMessage(
				array(
					new MessagePart( new FunctionCall( 'stock-one', 'wpab__sd-ai-agent__stock-image', array( 'keyword' => 'wedding' ) ) ),
				)
			),
			new ModelMessage(
				array(
					new MessagePart(
						new FunctionCall(
							'stock-two',
							'wpab__sd-ai-agent__ability-call',
							array(
								'ability'   => 'sd-ai-agent/stock-image',
								'arguments' => array( 'keyword' => 'family' ),
							)
						)
					),
				)
			),
		);
		$loop    = new AgentLoop( 'Build a photographer site', array(), $history, array( 'agent_slug' => 'onboarding' ) );
		$message = new ModelMessage(
			array(
				new MessagePart( new FunctionCall( 'stock-three', 'wpab__sd-ai-agent__stock-image', array( 'keyword' => 'portrait' ) ) ),
				new MessagePart( new FunctionCall( 'generate-one', 'wpab__sd-ai-agent__generate-image', array( 'prompt' => 'portrait' ) ) ),
				new MessagePart( new FunctionCall( 'generate-two', 'wpab__sd-ai-agent__generate-image', array( 'prompt' => 'wedding' ) ) ),
				new MessagePart( new FunctionCall( 'other-tool', 'wpab__sd-ai-agent__list-posts', array() ) ),
			)
		);

		$method = new \ReflectionMethod( AgentLoop::class, 'enforce_onboarding_media_budget' );
		$method->setAccessible( true );
		$result = $method->invoke( $loop, $message );

		$this->assertIsArray( $result );
		$this->assertSame(
			array(
				'sd-ai-agent/stock-image'    => 1,
				'sd-ai-agent/generate-image' => 1,
			),
			$result['removed']
		);
		$this->assertStringContainsString( 'media budget is exhausted', $result['guidance'] );
		$this->assertStringContainsString( 'stock-image (2 calls total)', $result['guidance'] );
		$this->assertStringContainsString( 'generate-image (1 call total)', $result['guidance'] );

		$kept_names = array();
		foreach ( $result['message']->getParts() as $part ) {
			$call = $part->getFunctionCall();
			if ( $call ) {
				$kept_names[] = $call->getName();
			}
		}
		$this->assertSame(
			array( 'wpab__sd-ai-agent__generate-image', 'wpab__sd-ai-agent__list-posts' ),
			$kept_names
		);

		$exhausted_history   = array_merge(
			$history,
			array(
				new ModelMessage(
					array(
						new MessagePart( new FunctionCall( 'generate-used', 'wpab__sd-ai-agent__generate-image', array( 'prompt' => 'portrait' ) ) ),
					)
				),
			)
		);
		$exhausted_loop      = new AgentLoop( 'Build a photographer site', array(), $exhausted_history, array( 'agent_slug' => 'onboarding' ) );
		$fully_blocked       = new ModelMessage(
			array(
				new MessagePart( new FunctionCall( 'stock-four', 'wpab__sd-ai-agent__stock-image', array( 'keyword' => 'event' ) ) ),
				new MessagePart( new FunctionCall( 'generate-three', 'wpab__sd-ai-agent__generate-image', array( 'prompt' => 'event' ) ) ),
			)
		);
		$fully_blocked_result = $method->invoke( $exhausted_loop, $fully_blocked );

		$fully_blocked_parts = $fully_blocked_result['message']->getParts();
		if ( ! is_array( $fully_blocked_parts ) ) {
			$this->fail( 'Expected guarded message parts to be an array.' );
		}
		$this->assertCount( 1, $fully_blocked_parts );
		$this->assertNull( $fully_blocked_parts[0]->getFunctionCall() );
		$this->assertStringContainsString(
			'Media acquisition call blocked',
			$fully_blocked_parts[0]->getText()
		);
		$stock_only_result = $method->invoke(
			$loop,
			new ModelMessage(
				array(
					new MessagePart( new FunctionCall( 'stock-five', 'wpab__sd-ai-agent__stock-image', array( 'keyword' => 'studio' ) ) ),
				)
			)
		);
		$this->assertStringContainsString( 'generate-image (1 call remaining)', $stock_only_result['guidance'] );

		$generation_history = array(
			new ModelMessage(
				array(
					new MessagePart( new FunctionCall( 'generate-first', 'wpab__sd-ai-agent__generate-image', array( 'prompt' => 'studio' ) ) ),
				)
			),
		);
		$generation_loop    = new AgentLoop( 'Build a photographer site', array(), $generation_history, array( 'agent_slug' => 'onboarding' ) );
		$generation_result  = $method->invoke(
			$generation_loop,
			new ModelMessage(
				array(
					new MessagePart( new FunctionCall( 'generate-fourth', 'wpab__sd-ai-agent__generate-image', array( 'prompt' => 'event' ) ) ),
				)
			)
		);
		$this->assertStringContainsString( 'stock-image (2 calls remaining)', $generation_result['guidance'] );

		$terminal_history = array(
			new UserMessage( array( new MessagePart( 'Earlier media work.' ) ) ),
			new ModelMessage( array( new MessagePart( new FunctionCall( 'prior-stock-one', 'wpab__sd-ai-agent__stock-image', array() ) ) ) ),
			new UserMessage( array( new MessagePart( new FunctionResponse( 'prior-stock-one', 'wpab__sd-ai-agent__stock-image', array( 'ok' => true ) ) ) ) ),
			new ModelMessage( array( new MessagePart( new FunctionCall( 'prior-stock-two', 'wpab__sd-ai-agent__stock-image', array() ) ) ) ),
			new UserMessage( array( new MessagePart( new FunctionResponse( 'prior-stock-two', 'wpab__sd-ai-agent__stock-image', array( 'ok' => true ) ) ) ) ),
			new ModelMessage( array( new MessagePart( new FunctionCall( 'prior-generate', 'wpab__sd-ai-agent__generate-image', array() ) ) ) ),
			new UserMessage( array( new MessagePart( new FunctionResponse( 'prior-generate', 'wpab__sd-ai-agent__generate-image', array( 'ok' => true ) ) ) ) ),
		);
		$terminal_loop = new ScriptedAgentLoop(
			'Finish the photographer site',
			array(),
			$terminal_history,
			array(
				'agent_slug'    => 'onboarding',
				'max_iterations' => 1,
			),
			array(
				$this->create_scripted_result(
					'',
					new FunctionCall( 'stock-final', 'wpab__sd-ai-agent__stock-image', array( 'keyword' => 'final' ) )
				),
			)
		);
		$terminal_result = $terminal_loop->run();

		$this->assertIsArray( $terminal_result );
		$this->assertStringContainsString( 'media budget is exhausted', $terminal_result['reply'] );
	}

	/**
	 * Denied block-theme scaffolding should stop dependent theme-writing steps.
	 */
	public function test_scaffold_block_theme_permission_denial_builds_terminal_recovery_reply(): void {
		if ( ! class_exists( 'WordPress\AiClient\Messages\DTO\UserMessage' ) ) {
			$this->markTestSkipped( 'WP AI Client message classes are not available.' );
		}

		$message = new \WordPress\AiClient\Messages\DTO\UserMessage(
			[
				new \WordPress\AiClient\Messages\DTO\MessagePart(
					new \WordPress\AiClient\Tools\DTO\FunctionResponse(
						'call_scaffold_denied',
						'wpab__sd-ai-agent__scaffold-block-theme',
						'ERROR=Ability "sd-ai-agent/scaffold-block-theme" does not have necessary permission.'
					)
				),
			]
		);

		$loop   = new AgentLoop( 'Build a block theme' );
		$method = new \ReflectionMethod( AgentLoop::class, 'extract_scaffold_block_theme_permission_denial' );
		$method->setAccessible( true );

		$reply = $method->invoke( $loop, $message );

		$this->assertIsString( $reply );
		$this->assertStringContainsString( 'scaffold-block-theme permission was denied or stale', $reply );
		$this->assertStringContainsString( 'stopped the dependent theme-writing steps', $reply );
		$this->assertStringContainsString( 're-grant permission', $reply );
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

	/**
	 * Explicit stored "auto" means use the default annotation policy, not force
	 * execution without confirmation.
	 */
	public function test_auto_permission_uses_default_destructive_policy(): void {
		if ( ! class_exists( 'WP_Ability' ) ) {
			$this->markTestSkipped( 'WP_Ability not available.' );
		}

		$ability = new \WP_Ability(
			'test/auto-default-destructive',
			[
				'label'               => 'Auto Default Destructive',
				'description'         => 'A destructive ability with an explicit auto setting.',
				'category'            => 'sd-ai-agent',
				'execute_callback'    => '__return_true',
				'permission_callback' => '__return_true',
				'meta'                => [
					'annotations' => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
				],
			]
		);

		$this->assertTrue(
			ToolPermissionResolver::ability_needs_confirmation(
				'test/auto-default-destructive',
				$ability,
				[ 'test/auto-default-destructive' => 'auto' ]
			)
		);
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
	 * sd-ai-agent/ability-call must inherit the nested target ability's
	 * confirmation policy instead of auto-executing the meta-tool wrapper.
	 */
	public function test_ability_call_target_requires_confirmation_by_default(): void {
		$this->skip_if_sdk_unavailable();
		if ( ! class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
			$this->markTestSkipped( 'WP_AI_Client_Ability_Function_Resolver not available.' );
		}
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'wp_register_ability() not available.' );
		}

		delete_option( Settings::OPTION_NAME );

		$target = 'sd-ai-agent/test-ability-call-destructive-target';

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress hook stack global.
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init';

		try {
			wp_register_ability(
				$target,
				[
					'label'               => 'Test Ability Call Destructive Target',
					'description'         => 'A destructive target called through ability-call.',
					'category'            => 'sd-ai-agent',
					'execute_callback'    => '__return_true',
					'permission_callback' => '__return_true',
					'meta'                => [
						'annotations' => [
							'readonly'    => false,
							'destructive' => true,
							'idempotent'  => false,
						],
					],
				]
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		try {
			$this->mock_ai_response(
				'',
				[
					[
						'id'       => 'call_ability_call_target',
						'type'     => 'function',
						'function' => [
							'name'      => 'wpab__sd-ai-agent__ability-call',
							'arguments' => wp_json_encode(
								[
									'ability'   => $target,
									'arguments' => [],
								]
							),
						],
					],
				]
			);

			$loop   = new AgentLoop( 'Call a destructive target through ability-call' );
			$result = $loop->run();

			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'awaiting_confirmation', $result );
			$this->assertTrue( $result['awaiting_confirmation'] );
			$this->assertSame( $target, $result['pending_tools'][0]['ability'] ?? '' );
			$this->assertSame( 'wpab__sd-ai-agent__ability-call', $result['pending_tools'][0]['name'] ?? '' );
		} finally {
			if ( function_exists( 'wp_unregister_ability' ) ) {
				wp_unregister_ability( $target );
			}
		}
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

	/**
	 * A generic first prompt cannot use an always-allowed write tool to publish
	 * invented content, even when YOLO mode is enabled. The normal confirmation
	 * response gives the UI a proposal boundary instead of executing the call.
	 */
	public function test_underspecified_prompt_requires_confirmation_before_publishing(): void {
		if (
			! function_exists( 'wp_get_abilities' )
			|| ! wp_has_ability( 'sd-ai-agent/create-post' )
			|| ! class_exists( 'WP_AI_Client_Ability_Function_Resolver' )
		) {
			$this->markTestSkipped( 'Required ability resolver is not available.' );
		}

		$loop = new ScriptedAgentLoop(
			'do anything',
			array(),
			array(),
			array(
				'provider_id'       => 'scripted-provider',
				'model_id'          => 'scripted-model',
				'yolo_mode'         => true,
				'tool_permissions' => array( 'sd-ai-agent/create-post' => 'always_allow' ),
			),
			array(
				$this->create_scripted_result(
					'',
					new FunctionCall(
						'call_underspecified_publish',
						'wpab__sd-ai-agent__create-post',
						array(
							'title'   => 'Invented post',
							'content' => 'This must not be published from a vague prompt.',
							'status'  => 'publish',
						)
					)
				),
			)
		);

		$result = $loop->run();

		$this->assertIsArray( $result );
		$this->assertTrue( $result['awaiting_confirmation'] ?? false );
		$this->assertSame( 'sd-ai-agent/create-post', $result['pending_tools'][0]['ability'] ?? '' );
		$this->assertSame( 'underspecified_request', $result['pending_tools'][0]['reason'] ?? '' );
	}

	/** A vague-turn guard must survive a rejected confirmation resume. */
	public function test_underspecified_mutation_policy_survives_confirmation_rejection_resume(): void {
		if ( ! function_exists( 'wp_get_abilities' ) || ! wp_has_ability( 'sd-ai-agent/create-post' ) ) {
			$this->markTestSkipped( 'sd-ai-agent/create-post ability is not registered.' );
		}

		$options = array(
			'provider_id'       => 'scripted-provider',
			'model_id'          => 'scripted-model',
			'yolo_mode'         => true,
			'tool_permissions' => array( 'sd-ai-agent/create-post' => 'always_allow' ),
		);
		$initial_loop = new ScriptedAgentLoop(
			'do anything',
			array(),
			array(),
			$options,
			array(
				$this->create_scripted_result(
					'',
					new FunctionCall(
						'call_initial_vague_publish',
						'wpab__sd-ai-agent__create-post',
						array(
							'title'   => 'Invented post',
							'content' => 'This must remain behind a confirmation boundary.',
							'status'  => 'publish',
						)
					)
				),
			)
		);
		$paused       = $initial_loop->run();

		$this->assertIsArray( $paused );
		$this->assertTrue( $paused['awaiting_confirmation'] ?? false );
		$this->assertSame(
			array(
				'requires_clarification'          => true,
				'allows_explicit_draft_proposal' => false,
			),
			$paused['mutation_policy_context'] ?? array()
		);

		$resume_options = array_merge(
			$options,
			array(
				'confirmation_message'        => $paused['confirmation_message'] ?? array(),
				'confirmation_history_before' => $paused['confirmation_history_before'] ?? null,
				'mutation_policy_context'     => $paused['mutation_policy_context'] ?? array(),
			)
		);
		$resumed_loop   = new ScriptedAgentLoop(
			'',
			array(),
			ConversationSerializer::deserialize( $paused['history'] ?? array() ),
			$resume_options,
			array(
				$this->create_scripted_result(
					'',
					new FunctionCall(
						'call_retry_vague_publish',
						'wpab__sd-ai-agent__create-post',
						array(
							'title'   => 'Second invented post',
							'content' => 'A rejection must not make a vague turn permissive.',
							'status'  => 'publish',
						)
					)
				),
			)
		);
		$resumed        = $resumed_loop->resume_after_confirmation( false, 1 );

		$this->assertIsArray( $resumed );
		$this->assertTrue( $resumed['awaiting_confirmation'] ?? false );
		$this->assertSame( 'underspecified_request', $resumed['pending_tools'][0]['reason'] ?? '' );
	}

	/**
	 * Mixed client and PHP calls must be classified before the PHP partition can
	 * execute, so a generic prompt cannot publish while a read-only browser call
	 * is pending.
	 */
	public function test_underspecified_prompt_confirms_before_mixed_client_and_publish_calls(): void {
		$this->skip_if_sdk_unavailable();
		if ( ! class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
			$this->markTestSkipped( 'WP_AI_Client_Ability_Function_Resolver not available.' );
		}

		Settings::instance()->update(
			[
				'tool_permissions' => [
					'sd-ai-agent/create-post' => 'always_allow',
				],
			]
		);

		$this->mock_ai_response(
			'',
			[
				[
					'id'       => 'call_vague_navigate',
					'type'     => 'function',
					'function' => [
						'name'      => 'wpab__sd-ai-agent-js__navigate-to',
						'arguments' => wp_json_encode( [ 'path' => 'edit.php' ] ),
					],
				],
				[
					'id'       => 'call_vague_publish',
					'type'     => 'function',
					'function' => [
						'name'      => 'wpab__sd-ai-agent__create-post',
						'arguments' => wp_json_encode(
							[
								'title'   => 'Invented post',
								'content' => 'This must not be published from a vague prompt.',
								'status'  => 'publish',
							]
						),
					],
				],
			]
		);

		$catalog = JsAbilityCatalog::get_descriptors_by_name();
		$loop    = new AgentLoop(
			'do anything',
			array(),
			array(),
			array( 'client_abilities' => array( $catalog['sd-ai-agent-js/navigate-to'] ) )
		);
		$result  = $loop->run();

		$this->assertTrue( $result['awaiting_confirmation'] ?? false );
		$this->assertCount( 1, $result['pending_tools'] );
		$this->assertSame( 'sd-ai-agent/create-post', $result['pending_tools'][0]['ability'] ?? '' );
		$this->assertSame( 'underspecified_request', $result['pending_tools'][0]['reason'] ?? '' );
		$this->assertArrayNotHasKey( 'pending_client_tool_calls', $result );
	}

	/**
	 * The deterministic boundary remains covered without an authenticated AI
	 * provider: an always-allowed create-post call from a generic website request
	 * is held as an underspecified-request proposal before the resolver can execute it.
	 */
	public function test_underspecified_prompt_holds_always_allowed_create_post_at_permission_boundary(): void {
		if ( ! function_exists( 'wp_get_abilities' ) || ! wp_has_ability( 'sd-ai-agent/create-post' ) ) {
			$this->markTestSkipped( 'sd-ai-agent/create-post ability is not registered.' );
		}

		$requires_clarification = SystemInstructionBuilder::requires_clarification_before_mutation( 'Improve our website' );
		$resolver               = new ToolPermissionResolver(
			true,
			[ 'sd-ai-agent/create-post' => 'always_allow' ],
			$requires_clarification
		);
		$message  = new ModelMessage(
			[
				new MessagePart(
					new FunctionCall(
						'call_vague_publish',
						'wpab__sd-ai-agent__create-post',
						[
							'title'   => 'Invented post',
							'content' => 'This must not be published from a vague prompt.',
							'status'  => 'publish',
						]
					)
				),
			]
		);

		$pending = $resolver->get_tools_needing_confirmation( $message );

		$this->assertTrue( $requires_clarification );
		$this->assertCount( 1, $pending );
		$this->assertSame( 'sd-ai-agent/create-post', $pending[0]['ability'] );
		$this->assertSame( 'underspecified_request', $pending[0]['reason'] );
	}

	/** Read-only inspection remains available to form a bounded recommendation. */
	public function test_underspecified_prompt_allows_readonly_inspection(): void {
		if ( ! function_exists( 'wp_get_abilities' ) || ! wp_has_ability( 'sd-ai-agent/get-post' ) ) {
			$this->markTestSkipped( 'sd-ai-agent/get-post ability is not registered.' );
		}

		$resolver = new ToolPermissionResolver(
			false,
			array(),
			SystemInstructionBuilder::requires_clarification_before_mutation( 'do anything' )
		);
		$message  = new ModelMessage(
			array(
				new MessagePart(
					new FunctionCall(
						'call_vague_inspection',
						'wpab__sd-ai-agent__get-post',
						array( 'id' => 1 )
					)
				)
			)
		);

		$this->assertSame( array(), $resolver->get_tools_needing_confirmation( $message ) );
	}

	/** Nested ability calls must not bypass the underspecified mutation guard. */
	public function test_underspecified_prompt_holds_nested_create_post_at_permission_boundary(): void {
		if ( ! function_exists( 'wp_get_abilities' ) || ! wp_has_ability( 'sd-ai-agent/create-post' ) ) {
			$this->markTestSkipped( 'sd-ai-agent/create-post ability is not registered.' );
		}

		$resolver = new ToolPermissionResolver(
			false,
			array( 'sd-ai-agent/create-post' => 'always_allow' ),
			SystemInstructionBuilder::requires_clarification_before_mutation( 'do anything' )
		);
		$message  = new ModelMessage(
			array(
				new MessagePart(
					new FunctionCall(
						'call_vague_nested_publish',
						'wpab__sd-ai-agent__ability-call',
						array(
							'ability'   => 'sd-ai-agent/create-post',
							'arguments' => array(
								'title'   => 'Invented post',
								'content' => 'This must not be published from a vague prompt.',
								'status'  => 'publish',
							),
						)
					)
				)
			)
		);

		$pending = $resolver->get_tools_needing_confirmation( $message );

		$this->assertCount( 1, $pending );
		$this->assertSame( 'sd-ai-agent/create-post', $pending[0]['ability'] );
		$this->assertSame( 'underspecified_request', $pending[0]['reason'] );
	}

	/** A model-supplied draft status cannot bypass the vague-request confirmation gate. */
	public function test_underspecified_prompt_holds_model_supplied_draft_create_post_directly_or_through_ability_call(): void {
		if ( ! function_exists( 'wp_get_abilities' ) || ! wp_has_ability( 'sd-ai-agent/create-post' ) ) {
			$this->markTestSkipped( 'sd-ai-agent/create-post ability is not registered.' );
		}

		$resolver = new ToolPermissionResolver(
			false,
			array( 'sd-ai-agent/create-post' => 'always_allow' ),
			SystemInstructionBuilder::requires_clarification_before_mutation( 'make it better' )
		);
		$direct   = new ModelMessage(
			array(
				new MessagePart(
					new FunctionCall(
						'call_vague_draft',
						'wpab__sd-ai-agent__create-post',
						array(
							'title'   => 'Draft demonstration',
							'content' => 'A bounded draft proposal.',
							'status'  => 'draft',
						)
					)
				)
			)
		);
		$nested   = new ModelMessage(
			array(
				new MessagePart(
					new FunctionCall(
						'call_vague_nested_draft',
						'wpab__sd-ai-agent__ability-call',
						array(
							'ability'   => 'sd-ai-agent/create-post',
							'arguments' => array(
								'title'   => 'Nested draft demonstration',
								'content' => 'A bounded nested draft proposal.',
								'status'  => 'draft',
							),
						)
					)
				)
			)
		);

		$direct_pending = $resolver->get_tools_needing_confirmation( $direct );
		$nested_pending = $resolver->get_tools_needing_confirmation( $nested );

		$this->assertCount( 1, $direct_pending );
		$this->assertSame( 'underspecified_request', $direct_pending[0]['reason'] );
		$this->assertCount( 1, $nested_pending );
		$this->assertSame( 'underspecified_request', $nested_pending[0]['reason'] );
	}

	/** A user-requested draft proposal remains available through both call paths. */
	public function test_underspecified_prompt_allows_user_requested_draft_create_post_directly_or_through_ability_call(): void {
		if ( ! function_exists( 'wp_get_abilities' ) || ! wp_has_ability( 'sd-ai-agent/create-post' ) ) {
			$this->markTestSkipped( 'sd-ai-agent/create-post ability is not registered.' );
		}

		$this->assertTrue(
			SystemInstructionBuilder::explicitly_requests_draft_proposal( 'Create a draft proposal for a gardening post.' )
		);
		$resolver = new ToolPermissionResolver(
			false,
			array( 'sd-ai-agent/create-post' => 'always_allow' ),
			true,
			true
		);
		$direct   = new ModelMessage(
			array(
				new MessagePart(
					new FunctionCall(
						'call_user_requested_draft',
						'wpab__sd-ai-agent__create-post',
						array(
							'title'   => 'Draft proposal',
							'content' => 'A user-requested draft proposal.',
							'status'  => 'draft',
						)
					)
				)
			)
		);
		$nested   = new ModelMessage(
			array(
				new MessagePart(
					new FunctionCall(
						'call_user_requested_nested_draft',
						'wpab__sd-ai-agent__ability-call',
						array(
							'ability'   => 'sd-ai-agent/create-post',
							'arguments' => array(
								'title'   => 'Nested draft proposal',
								'content' => 'A user-requested nested draft proposal.',
								'status'  => 'draft',
							),
						)
					)
				)
			)
		);

		$this->assertSame( array(), $resolver->get_tools_needing_confirmation( $direct ) );
		$this->assertSame( array(), $resolver->get_tools_needing_confirmation( $nested ) );
	}

	/** Explicit publish intent preserves the always-allowed direct-post path. */
	public function test_explicit_publish_request_allows_always_allowed_create_post(): void {
		if ( ! function_exists( 'wp_get_abilities' ) || ! wp_has_ability( 'sd-ai-agent/create-post' ) ) {
			$this->markTestSkipped( 'sd-ai-agent/create-post ability is not registered.' );
		}

		$requires_clarification = SystemInstructionBuilder::requires_clarification_before_mutation( 'Publish a post about gardening.' );
		$resolver               = new ToolPermissionResolver(
			false,
			array( 'sd-ai-agent/create-post' => 'always_allow' ),
			$requires_clarification
		);
		$message                = new ModelMessage(
			array(
				new MessagePart(
					new FunctionCall(
						'call_explicit_publish',
						'wpab__sd-ai-agent__create-post',
						array(
							'title'   => 'Gardening',
							'content' => 'A clearly requested post.',
							'status'  => 'publish',
						)
					)
				)
			)
		);

		$this->assertFalse( $requires_clarification );
		$this->assertSame( array(), $resolver->get_tools_needing_confirmation( $message ) );
	}

	/**
	 * Identical function calls in one model response should be dispatched once.
	 */
	public function test_run_deduplicates_identical_tool_calls_within_iteration(): void {
		$this->skip_if_sdk_unavailable();
		if ( ! class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
			$this->markTestSkipped( 'WP_AI_Client_Ability_Function_Resolver not available.' );
		}

		$call_count      = 0;
		$duplicate_calls = wp_json_encode(
			[
				'id'      => 'chatcmpl-duplicate-tools',
				'object'  => 'chat.completion',
				'choices' => [
					[
						'index'         => 0,
						'message'       => [
							'role'       => 'assistant',
							'content'    => null,
							'tool_calls' => [
								[
									'id'       => 'call_dup_1',
									'type'     => 'function',
									'function' => [
										'name'      => 'wpab__sd-ai-agent__memory-list',
										'arguments' => '{"query":"same"}',
									],
								],
								[
									'id'       => 'call_dup_2',
									'type'     => 'function',
									'function' => [
										'name'      => 'wpab__sd-ai-agent__memory-list',
										'arguments' => '{"query":"same"}',
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
		$final_reply     = wp_json_encode(
			[
				'id'      => 'chatcmpl-deduped-final',
				'object'  => 'chat.completion',
				'choices' => [
					[
						'index'         => 0,
						'message'       => [ 'role' => 'assistant', 'content' => 'Done.' ],
						'finish_reason' => 'stop',
					],
				],
				'usage'   => [ 'prompt_tokens' => 20, 'completion_tokens' => 5, 'total_tokens' => 25 ],
			]
		);

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$call_count, $duplicate_calls, $final_reply ) {
				if ( false !== strpos( $url, 'fake-ai-proxy.test' ) ) {
					++$call_count;
					return [
						'headers'  => [ 'content-type' => 'application/json' ],
						'body'     => ( 1 === $call_count ) ? $duplicate_calls : $final_reply,
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

		$loop   = new AgentLoop( 'List memories once' );
		$result = $loop->run();

		$this->assertIsArray( $result );
		$calls = array_values( array_filter( $result['tool_calls'], static fn( $entry ) => 'call' === ( $entry['type'] ?? '' ) ) );
		$this->assertCount( 1, $calls, 'Duplicate identical calls in one iteration must be dispatched once.' );
		$this->assertSame( 'call_dup_1', $calls[0]['id'] );

		$responses = array_values( array_filter( $result['tool_calls'], static fn( $entry ) => 'response' === ( $entry['type'] ?? '' ) ) );
		$this->assertCount( 1, $responses, 'Only one response should be logged for the deduped call.' );

		$events = array_values( array_filter( $result['messages'], static fn( $entry ) => 'tool_call_deduplicated' === ( $entry['reason'] ?? '' ) ) );
		$this->assertCount( 1, $events );
		$this->assertSame( 1, $events[0]['count'] );
	}

	/**
	 * Regression test: preamble text emitted alongside a tool call must appear
	 * in the live message log so the polling frontend can render it above
	 * the tool card while the loop is still running.
	 *
	 * The mock turn returns an assistant message that contains both a text
	 * preamble ("Let me look that up first.") and a function call in the same
	 * choice — the chat-completion shape used by every model we support that
	 * narrates before invoking tools. The first call returns the
	 * preamble+tool-call payload; the second call returns the final text reply
	 * so the loop terminates cleanly.
	 */
	public function test_run_logs_preamble_text_before_tool_call(): void {
		$this->skip_if_sdk_unavailable();
		if ( ! class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
			$this->markTestSkipped( 'WP_AI_Client_Ability_Function_Resolver not available.' );
		}

		$call_count           = 0;
		$progress_snapshots   = [];
		$preamble_text        = 'Let me look that up first.';
		$preamble_and_call    = wp_json_encode(
			[
				'id'      => 'chatcmpl-preamble',
				'object'  => 'chat.completion',
				'choices' => [
					[
						'index'         => 0,
						'message'       => [
							'role'       => 'assistant',
							'content'    => $preamble_text,
							'tool_calls' => [
								[
									'id'       => 'call_preamble_1',
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
		$final_reply_body     = wp_json_encode(
			[
				'id'      => 'chatcmpl-final',
				'object'  => 'chat.completion',
				'choices' => [
					[
						'index'         => 0,
						'message'       => [ 'role' => 'assistant', 'content' => 'Done.' ],
						'finish_reason' => 'stop',
					],
				],
				'usage'   => [ 'prompt_tokens' => 20, 'completion_tokens' => 5, 'total_tokens' => 25 ],
			]
		);

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$call_count, $preamble_and_call, $final_reply_body ) {
				if ( false !== strpos( $url, 'fake-ai-proxy.test' ) ) {
					++$call_count;
					$body = ( 1 === $call_count ) ? $preamble_and_call : $final_reply_body;
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

		$options                      = [];
		$options['progress_callback'] = static function ( array $log, array $messages = array() ) use ( &$progress_snapshots ): void {
			$progress_snapshots[] = array(
				'tool_calls' => $log,
				'messages'   => $messages,
			);
		};

		$loop   = new AgentLoop( 'Find my notes', [], [], $options );
		$result = $loop->run();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'tool_calls', $result );

		$preamble_entries = array_values(
			array_filter(
				$result['messages'],
				static fn( $entry ) => 'preamble' === ( $entry['type'] ?? '' )
			)
		);
		$call_entries     = array_values(
			array_filter(
				$result['tool_calls'],
				static fn( $entry ) => 'call' === ( $entry['type'] ?? '' )
			)
		);

		$this->assertNotEmpty( $preamble_entries, 'A preamble entry must be present in messages when the model emits text alongside a tool call.' );
		$this->assertNotEmpty( $call_entries, 'The tool call entry must still be present.' );
		$this->assertSame( $preamble_text, $preamble_entries[0]['text'] );
		$this->assertLessThan( $call_entries[0]['sequence'], $preamble_entries[0]['sequence'], 'Preamble must be sequenced before the tool call to match emission order.' );

		// The progress callback must have observed the preamble in at least
		// one snapshot so the polling frontend can render it incrementally.
		$saw_preamble_in_progress = false;
		foreach ( $progress_snapshots as $snapshot ) {
			foreach ( $snapshot['messages'] as $entry ) {
				if ( 'preamble' === ( $entry['type'] ?? '' ) && ( $entry['text'] ?? '' ) === $preamble_text ) {
					$saw_preamble_in_progress = true;
					break 2;
				}
			}
		}
		$this->assertTrue( $saw_preamble_in_progress, 'progress_callback must surface preamble entries so live UI can show running commentary.' );
	}

	/**
	 * Whitespace-only assistant text emitted alongside a tool call must NOT be
	 * logged as a preamble. Some providers normalise null content to an empty
	 * string or a stray newline; surfacing that as a "preamble" would render
	 * blank speech bubbles in the running message.
	 */
	public function test_run_skips_whitespace_only_preamble(): void {
		$this->skip_if_sdk_unavailable();
		if ( ! class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
			$this->markTestSkipped( 'WP_AI_Client_Ability_Function_Resolver not available.' );
		}

		$call_count = 0;
		$body_tool  = wp_json_encode(
			[
				'id'      => 'chatcmpl-blank',
				'object'  => 'chat.completion',
				'choices' => [
					[
						'index'         => 0,
						'message'       => [
							'role'       => 'assistant',
							'content'    => "  \n  ",
							'tool_calls' => [
								[
									'id'       => 'call_blank_1',
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
				'usage'   => [ 'prompt_tokens' => 10, 'completion_tokens' => 0, 'total_tokens' => 10 ],
			]
		);
		$body_reply = wp_json_encode(
			[
				'id'      => 'chatcmpl-final-blank',
				'object'  => 'chat.completion',
				'choices' => [
					[
						'index'         => 0,
						'message'       => [ 'role' => 'assistant', 'content' => 'Here.' ],
						'finish_reason' => 'stop',
					],
				],
				'usage'   => [ 'prompt_tokens' => 12, 'completion_tokens' => 1, 'total_tokens' => 13 ],
			]
		);

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$call_count, $body_tool, $body_reply ) {
				if ( false !== strpos( $url, 'fake-ai-proxy.test' ) ) {
					++$call_count;
					$body = ( 1 === $call_count ) ? $body_tool : $body_reply;
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

		$loop   = new AgentLoop( 'Find my notes' );
		$result = $loop->run();

		$this->assertIsArray( $result );
		$preamble_entries = array_filter(
			$result['messages'],
			static fn( $entry ) => 'preamble' === ( $entry['type'] ?? '' )
		);
		$this->assertEmpty( $preamble_entries, 'Whitespace-only assistant text must not be logged as a preamble entry.' );
	}

	// -------------------------------------------------------------------------
	// model_omits_temperature() direct unit tests
	// -------------------------------------------------------------------------

	/**
	 * Invoke the private static {@see AgentLoop::model_omits_temperature()} helper.
	 *
	 * @param string $model_id Model ID to classify.
	 * @return bool Helper return value.
	 */
	private function invoke_model_omits_temperature( string $model_id ): bool {
		$rc     = new \ReflectionClass( AgentLoop::class );
		$method = $rc->getMethod( 'model_omits_temperature' );
		$method->setAccessible( true );
		return (bool) $method->invoke( null, $model_id );
	}

	/**
	 * Direct (no-SDK) coverage of the temperature-omission classifier.
	 *
	 * @dataProvider temperature_omission_classification_provider
	 */
	public function test_model_omits_temperature_classification( string $model_id, bool $expected ): void {
		$this->assertSame(
			$expected,
			$this->invoke_model_omits_temperature( $model_id ),
			sprintf( 'model_omits_temperature(%s) should be %s', var_export( $model_id, true ), $expected ? 'true' : 'false' )
		);
	}

	/**
	 * Classification corpus. Keep the negative cases honest — `o1magic`
	 * style IDs must NOT match (the helper guards against an over-broad
	 * prefix match by requiring `o1-...` or exact `o1`).
	 *
	 * @return array<string, array{0:string, 1:bool}>
	 */
	public function temperature_omission_classification_provider(): array {
		return [
			// GPT-5 family — all reasoning.
			'gpt-5'                       => [ 'gpt-5', true ],
			'gpt-5-pro'                   => [ 'gpt-5-pro', true ],
			'gpt-5-codex'                 => [ 'gpt-5-codex', true ],
			'gpt-5.4'                     => [ 'gpt-5.4', true ],
			'gpt-5.4-mini'                => [ 'gpt-5.4-mini', true ],
			'gpt-5.5'                     => [ 'gpt-5.5', true ],
			'gpt-5.5-pro'                 => [ 'gpt-5.5-pro', true ],
			'gpt-5.5-dated'               => [ 'gpt-5.5-2026-04-23', true ],
			'GPT-5.5 uppercase'           => [ 'GPT-5.5', true ],
			'gpt-5 padded'                => [ '  gpt-5.5  ', true ],
			// o-series reasoning.
			'o1'                          => [ 'o1', true ],
			'o1-mini'                     => [ 'o1-mini', true ],
			'o3'                          => [ 'o3', true ],
			'o3-mini'                     => [ 'o3-mini', true ],
			'o3-preview'                  => [ 'o3-preview', true ],
			'o4'                          => [ 'o4', true ],
			'o4-mini'                     => [ 'o4-mini', true ],
			// Non-reasoning OpenAI — must NOT match.
			'gpt-4'                       => [ 'gpt-4', false ],
			'gpt-4-turbo'                 => [ 'gpt-4-turbo', false ],
			'gpt-4o'                      => [ 'gpt-4o', false ],
			'gpt-4.1'                     => [ 'gpt-4.1', false ],
			'gpt-3.5-turbo'               => [ 'gpt-3.5-turbo', false ],
			// Anthropic Max Claude Opus 4.7 and 4.8 — rejects/deprecates temperature.
			'claude-opus-4-7'             => [ 'claude-opus-4-7', true ],
			'claude-opus-4-7-dated'       => [ 'claude-opus-4-7-20260513', true ],
			'Claude Opus 4.7 uppercase'   => [ 'CLAUDE-OPUS-4-7', true ],
			'Claude Opus 4.7 padded'      => [ '  claude-opus-4-7  ', true ],
			'claude-opus-4-8'             => [ 'claude-opus-4-8', true ],
			'claude-opus-4-8-dated'       => [ 'claude-opus-4-8-20260513', true ],
			'Claude Opus 4.8 uppercase'   => [ 'CLAUDE-OPUS-4-8', true ],
			// Other providers/models — must NOT match.
			'claude-sonnet-4-6'           => [ 'claude-sonnet-4-6', false ],
			'claude-opus-4-6'             => [ 'claude-opus-4-6', false ],
			'claude-opus-4-5'             => [ 'claude-opus-4-5', false ],
			'gemini-2.5-pro'              => [ 'gemini-2.5-pro', false ],
			'deepseek-chat'               => [ 'deepseek-chat', false ],
			// Defensive negatives — must NOT match (no `-` separator).
			'o1magic (no separator)'      => [ 'o1magic', false ],
			'o3xyz (no separator)'        => [ 'o3xyz', false ],
			'orion (starts with o but no o<digit>)' => [ 'orion', false ],
			'empty string'                => [ '', false ],
			'whitespace'                  => [ '   ', false ],
		];
	}
}
