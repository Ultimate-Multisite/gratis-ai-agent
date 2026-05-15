<?php
/**
 * Test case for AiClientEventTraceHandler and AiClientEventTraceLogger.
 *
 * Tests the SDK event-based trace capture for Before/After event pairs,
 * including correlation, duration computation, and structured data extraction.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Bootstrap;

use SdAiAgent\Bootstrap\AiClientEventTraceHandler;
use SdAiAgent\Core\AiClientEventTraceLogger;
use SdAiAgent\Models\ProviderTrace;
use WordPress\AiClient\Events\AfterGenerateResultEvent;
use WordPress\AiClient\Events\BeforeGenerateResultEvent;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\Enums\RoleEnum;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WP_UnitTestCase;

/**
 * @covers \SdAiAgent\Bootstrap\AiClientEventTraceHandler
 * @covers \SdAiAgent\Core\AiClientEventTraceLogger
 */
class AiClientEventTraceHandlerTest extends WP_UnitTestCase {

	private AiClientEventTraceHandler $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new AiClientEventTraceHandler();

		// Enable provider tracing for these tests.
		ProviderTrace::set_enabled( true );

		// Clear any existing trace rows.
		ProviderTrace::clear();
	}

	protected function tearDown(): void {
		ProviderTrace::clear();
		ProviderTrace::set_enabled( false );
		parent::tearDown();
	}

	public function test_before_and_after_events_write_trace_row(): void {
		// Create mock model and events.
		$model = $this->create_mock_model( 'anthropic', 'claude-3-5-sonnet' );
		$messages = [ $this->create_mock_message( 'user', 'Hello' ) ];
		$capability = CapabilityEnum::TextGeneration;

		// Create Before event.
		$before_event = new BeforeGenerateResultEvent( $messages, $model, $capability );

		// Dispatch Before event.
		$this->handler->on_before_generate_result( $before_event );

		// Create After event with result.
		$token_usage = new TokenUsage(
			inputTokens: 10,
			outputTokens: 20,
			cacheCreationTokens: 0,
			cacheReadTokens: 0
		);

		$candidate = new Candidate(
			content: 'Hello there!',
			finishReason: FinishReasonEnum::Stop
		);

		$result = new GenerativeAiResult(
			id: 'result-123',
			model: 'claude-3-5-sonnet',
			candidates: [ $candidate ],
			tokenUsage: $token_usage
		);

		$after_event = new AfterGenerateResultEvent( $messages, $model, $capability, $result );

		// Dispatch After event.
		$this->handler->on_after_generate_result( $after_event );

		// Verify trace row was written.
		$rows = ProviderTrace::list( [ 'limit' => 1 ] );
		$this->assertCount( 1, $rows, 'One trace row should be written.' );

		$row = $rows[0];
		$this->assertSame( 'anthropic', $row->provider_id );
		$this->assertSame( 'claude-3-5-sonnet', $row->model_id );
		$this->assertSame( 'SDK', $row->method );
		$this->assertSame( 200, $row->status_code );
		$this->assertGreaterThan( 0, $row->duration_ms );
	}

	public function test_token_usage_is_extracted(): void {
		$model = $this->create_mock_model( 'openai', 'gpt-4o' );
		$messages = [ $this->create_mock_message( 'user', 'Test' ) ];

		$before_event = new BeforeGenerateResultEvent( $messages, $model, null );
		$this->handler->on_before_generate_result( $before_event );

		$token_usage = new TokenUsage(
			inputTokens: 100,
			outputTokens: 50,
			cacheCreationTokens: 5,
			cacheReadTokens: 10
		);

		$candidate = new Candidate(
			content: 'Response',
			finishReason: FinishReasonEnum::Stop
		);

		$result = new GenerativeAiResult(
			id: 'result-456',
			model: 'gpt-4o',
			candidates: [ $candidate ],
			tokenUsage: $token_usage
		);

		$after_event = new AfterGenerateResultEvent( $messages, $model, null, $result );
		$this->handler->on_after_generate_result( $after_event );

		$rows = ProviderTrace::list( [ 'limit' => 1 ] );
		$this->assertCount( 1, $rows );

		$row = $rows[0];
		$this->assertSame( 5, $row->cache_creation_tokens );
		$this->assertSame( 10, $row->cache_read_tokens );
	}

	public function test_capability_is_stored_in_request_body(): void {
		$model = $this->create_mock_model( 'google', 'gemini-2.0-flash' );
		$messages = [ $this->create_mock_message( 'user', 'Analyze' ) ];
		$capability = CapabilityEnum::TextGeneration;

		$before_event = new BeforeGenerateResultEvent( $messages, $model, $capability );
		$this->handler->on_before_generate_result( $before_event );

		$token_usage = new TokenUsage(
			inputTokens: 50,
			outputTokens: 75,
			cacheCreationTokens: 0,
			cacheReadTokens: 0
		);

		$candidate = new Candidate(
			content: 'Analysis result',
			finishReason: FinishReasonEnum::Stop
		);

		$result = new GenerativeAiResult(
			id: 'result-789',
			model: 'gemini-2.0-flash',
			candidates: [ $candidate ],
			tokenUsage: $token_usage
		);

		$after_event = new AfterGenerateResultEvent( $messages, $model, $capability, $result );
		$this->handler->on_after_generate_result( $after_event );

		$rows = ProviderTrace::list( [ 'limit' => 1 ] );
		$this->assertCount( 1, $rows );

		$row = $rows[0];
		// The request_body should contain the messages as JSON.
		$this->assertNotEmpty( $row->request_body );
		$decoded = json_decode( $row->request_body, true );
		$this->assertIsArray( $decoded );
	}

	public function test_finish_reason_is_stored_in_response_body(): void {
		$model = $this->create_mock_model( 'anthropic', 'claude-3-5-sonnet' );
		$messages = [ $this->create_mock_message( 'user', 'Generate' ) ];

		$before_event = new BeforeGenerateResultEvent( $messages, $model, null );
		$this->handler->on_before_generate_result( $before_event );

		$token_usage = new TokenUsage(
			inputTokens: 20,
			outputTokens: 30,
			cacheCreationTokens: 0,
			cacheReadTokens: 0
		);

		$candidate = new Candidate(
			content: 'Generated content',
			finishReason: FinishReasonEnum::Stop
		);

		$result = new GenerativeAiResult(
			id: 'result-999',
			model: 'claude-3-5-sonnet',
			candidates: [ $candidate ],
			tokenUsage: $token_usage
		);

		$after_event = new AfterGenerateResultEvent( $messages, $model, null, $result );
		$this->handler->on_after_generate_result( $after_event );

		$rows = ProviderTrace::list( [ 'limit' => 1 ] );
		$this->assertCount( 1, $rows );

		$row = $rows[0];
		// The response_body should contain the result with finish_reason.
		$this->assertNotEmpty( $row->response_body );
		$decoded = json_decode( $row->response_body, true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'candidates', $decoded );
	}

	public function test_tracing_disabled_skips_logging(): void {
		ProviderTrace::set_enabled( false );

		$model = $this->create_mock_model( 'anthropic', 'claude-3-5-sonnet' );
		$messages = [ $this->create_mock_message( 'user', 'Test' ) ];

		$before_event = new BeforeGenerateResultEvent( $messages, $model, null );
		$this->handler->on_before_generate_result( $before_event );

		$token_usage = new TokenUsage(
			inputTokens: 10,
			outputTokens: 20,
			cacheCreationTokens: 0,
			cacheReadTokens: 0
		);

		$candidate = new Candidate(
			content: 'Response',
			finishReason: FinishReasonEnum::Stop
		);

		$result = new GenerativeAiResult(
			id: 'result-disabled',
			model: 'claude-3-5-sonnet',
			candidates: [ $candidate ],
			tokenUsage: $token_usage
		);

		$after_event = new AfterGenerateResultEvent( $messages, $model, null, $result );
		$this->handler->on_after_generate_result( $after_event );

		// No trace row should be written.
		$rows = ProviderTrace::list();
		$this->assertCount( 0, $rows );
	}

	public function test_stalled_before_event_writes_synthetic_row(): void {
		$model = $this->create_mock_model( 'anthropic', 'claude-3-5-sonnet' );
		$messages = [ $this->create_mock_message( 'user', 'Stalled' ) ];

		// Record a Before event but don't dispatch the After event.
		$before_event = new BeforeGenerateResultEvent( $messages, $model, null );
		$this->handler->on_before_generate_result( $before_event );

		// Simulate the watchdog cleanup (normally called on shutdown).
		AiClientEventTraceLogger::cleanup_stalled_events();

		// Verify a synthetic trace row was written with error='no_result_event'.
		$rows = ProviderTrace::list();
		$this->assertCount( 1, $rows );

		$row = $rows[0];
		$this->assertSame( 'anthropic', $row->provider_id );
		$this->assertSame( 'claude-3-5-sonnet', $row->model_id );
		$this->assertSame( 'SDK', $row->method );
		$this->assertSame( 0, $row->status_code );
		$this->assertSame( 'no_result_event', $row->error );
		$this->assertGreaterThan( 0, $row->duration_ms );
	}

	public function test_sdk_trace_has_source_sdk(): void {
		$model = $this->create_mock_model( 'openai', 'gpt-4o' );
		$messages = [ $this->create_mock_message( 'user', 'Test' ) ];

		$before_event = new BeforeGenerateResultEvent( $messages, $model, null );
		$this->handler->on_before_generate_result( $before_event );

		$token_usage = new TokenUsage(
			inputTokens: 10,
			outputTokens: 20,
			cacheCreationTokens: 0,
			cacheReadTokens: 0
		);

		$candidate = new Candidate(
			content: 'Response',
			finishReason: FinishReasonEnum::Stop
		);

		$result = new GenerativeAiResult(
			id: 'result-123',
			model: 'gpt-4o',
			candidates: [ $candidate ],
			tokenUsage: $token_usage
		);

		$after_event = new AfterGenerateResultEvent( $messages, $model, null, $result );
		$this->handler->on_after_generate_result( $after_event );

		$rows = ProviderTrace::list();
		$this->assertCount( 1, $rows );

		$row = $rows[0];
		$this->assertSame( 'sdk', $row->source, 'SDK traces should have source=sdk' );
	}

	/**
	 * Create a mock model object for testing.
	 *
	 * @param string $provider_id Provider ID.
	 * @param string $model_id Model ID.
	 * @return object Mock model object.
	 */
	private function create_mock_model( string $provider_id, string $model_id ): object {
		$provider_metadata = $this->createMock( 'stdClass' );
		$provider_metadata->method( 'getId' )->willReturn( $provider_id );

		$model_metadata = $this->createMock( 'stdClass' );
		$model_metadata->method( 'getId' )->willReturn( $model_id );

		$model = $this->createMock( 'stdClass' );
		$model->method( 'providerMetadata' )->willReturn( $provider_metadata );
		$model->method( 'metadata' )->willReturn( $model_metadata );

		return $model;
	}

	/**
	 * Create a mock message object for testing.
	 *
	 * @param string $role Message role.
	 * @param string $content Message content.
	 * @return Message Mock message object.
	 */
	private function create_mock_message( string $role, string $content ): Message {
		$role_enum = RoleEnum::from( $role );
		return new Message( role: $role_enum, content: $content );
	}
}
