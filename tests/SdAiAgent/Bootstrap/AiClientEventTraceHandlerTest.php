<?php
/**
 * Test case for AiClientEventTraceHandler and AiClientEventTraceLogger.
 *
 * Tests the SDK event-based trace capture for Before/After event pairs,
 * including correlation, duration computation, structured data extraction,
 * and the watchdog cleanup that records stalled Before events on shutdown.
 *
 * Regression coverage for the bug where serialize_messages() called
 * Message::getContent() and serialize_result() called TokenUsage::getInputTokens(),
 * GenerativeAiResult::getModel(), and Candidate::getContent() — none of which
 * exist on the WordPress AI Client SDK DTOs (the methods are getParts(),
 * getPromptTokens(), getModelMetadata(), and getMessage() respectively). That
 * combination produced a fatal on every successful AI call (silently swallowed
 * by the WP hook system in the After handler) and a visible fatal in the
 * shutdown-hook watchdog whenever any Before event went un-completed.
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
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
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

		ProviderTrace::set_enabled( true );
		ProviderTrace::clear();
		// Reset the LIFO inflight stack so a stalled Before from a prior
		// test case (or a tearDown that didn't fire on_after) can't pair
		// with this case's on_after.
		AiClientEventTraceLogger::reset_inflight_for_tests();
	}

	protected function tearDown(): void {
		AiClientEventTraceLogger::reset_inflight_for_tests();
		ProviderTrace::clear();
		ProviderTrace::set_enabled( false );
		parent::tearDown();
	}

	public function test_before_and_after_events_write_trace_row(): void {
		$model      = $this->create_model( 'anthropic', 'claude-3-5-sonnet' );
		$messages   = [ $this->create_user_message( 'Hello' ) ];
		$capability = CapabilityEnum::textGeneration();

		$this->handler->on_before_generate_result(
			new BeforeGenerateResultEvent( $messages, $model, $capability )
		);

		$result = $this->create_result( 'result-123', $model, 'Hello there!' );

		$this->handler->on_after_generate_result(
			new AfterGenerateResultEvent( $messages, $model, $capability, $result )
		);

		$rows = ProviderTrace::list( [ 'limit' => 1 ] );
		$this->assertCount( 1, $rows, 'One trace row should be written.' );

		$row = $rows[0];
		$this->assertSame( 'anthropic', $row->provider_id );
		$this->assertSame( 'claude-3-5-sonnet', $row->model_id );
		$this->assertSame( 'SDK', $row->method );
		$this->assertSame( 200, $row->status_code );
		$this->assertGreaterThanOrEqual( 0, $row->duration_ms );
	}

	public function test_after_handler_does_not_fatal_with_real_sdk_dtos(): void {
		// Regression: every call to on_after_generate_result used to fatal
		// silently inside the WP hook system because TokenUsage::getInputTokens
		// (and friends) do not exist. The fatal swallowed the trace write,
		// so even a basic "trace row exists" assertion catches the regression.
		$model    = $this->create_model( 'openai', 'gpt-4o' );
		$messages = [ $this->create_user_message( 'Test' ) ];

		$this->handler->on_before_generate_result(
			new BeforeGenerateResultEvent( $messages, $model, null )
		);

		$result = $this->create_result( 'result-no-fatal', $model, 'Response' );

		$this->handler->on_after_generate_result(
			new AfterGenerateResultEvent( $messages, $model, null, $result )
		);

		$rows = ProviderTrace::list( [ 'limit' => 1 ] );
		$this->assertCount( 1, $rows, 'After handler must complete without fatal.' );
	}

	public function test_request_body_is_valid_message_json(): void {
		$model    = $this->create_model( 'google', 'gemini-2.0-flash' );
		$messages = [ $this->create_user_message( 'Analyze this' ) ];

		$this->handler->on_before_generate_result(
			new BeforeGenerateResultEvent( $messages, $model, CapabilityEnum::textGeneration() )
		);

		$result = $this->create_result( 'result-789', $model, 'Analysis result' );

		$this->handler->on_after_generate_result(
			new AfterGenerateResultEvent( $messages, $model, CapabilityEnum::textGeneration(), $result )
		);

		$rows = ProviderTrace::list( [ 'limit' => 1 ] );
		$this->assertCount( 1, $rows );
		$full = ProviderTrace::get( $rows[0]->id );
		$this->assertNotNull( $full );

		$decoded = json_decode( $full->request_body, true );
		$this->assertIsArray( $decoded );
		$this->assertCount( 1, $decoded );
		$this->assertSame( 'user', $decoded[0]['role'] );
		$this->assertIsArray( $decoded[0]['parts'] );
		$this->assertSame( 'Analyze this', $decoded[0]['parts'][0]['text'] );
	}

	public function test_response_body_uses_real_token_usage_and_candidate_getters(): void {
		$model    = $this->create_model( 'anthropic', 'claude-3-5-sonnet' );
		$messages = [ $this->create_user_message( 'Generate' ) ];

		$this->handler->on_before_generate_result(
			new BeforeGenerateResultEvent( $messages, $model, null )
		);

		$result = $this->create_result(
			id: 'result-999',
			model: $model,
			reply_text: 'Generated content',
			prompt_tokens: 20,
			completion_tokens: 30,
			total_tokens: 50,
			thought_tokens: 7,
		);

		$this->handler->on_after_generate_result(
			new AfterGenerateResultEvent( $messages, $model, null, $result )
		);

		$rows = ProviderTrace::list( [ 'limit' => 1 ] );
		$this->assertCount( 1, $rows );
		$full = ProviderTrace::get( $rows[0]->id );
		$this->assertNotNull( $full );

		$decoded = json_decode( $full->response_body, true );
		$this->assertIsArray( $decoded );

		// Real GenerativeAiResult getters: getId(), getModelMetadata()->getId().
		$this->assertSame( 'result-999', $decoded['id'] );
		$this->assertSame( 'claude-3-5-sonnet', $decoded['model'] );

		// Real TokenUsage getters: getPromptTokens / getCompletionTokens /
		// getTotalTokens / getThoughtTokens. The previous implementation
		// called getInput/Output/CacheCreation/CacheRead which do not exist.
		$this->assertSame( 20, $decoded['usage']['prompt_tokens'] );
		$this->assertSame( 30, $decoded['usage']['completion_tokens'] );
		$this->assertSame( 50, $decoded['usage']['total_tokens'] );
		$this->assertSame( 7, $decoded['usage']['thought_tokens'] );

		// Real Candidate getter: getMessage()->toArray(). The previous
		// implementation called getContent() which does not exist.
		$this->assertArrayHasKey( 'candidates', $decoded );
		$this->assertCount( 1, $decoded['candidates'] );
		$this->assertSame( 'stop', $decoded['candidates'][0]['finish_reason'] );
		$this->assertSame( 'model', $decoded['candidates'][0]['message']['role'] );
		$this->assertSame( 'Generated content', $decoded['candidates'][0]['message']['parts'][0]['text'] );
	}

	public function test_tracing_disabled_skips_logging(): void {
		ProviderTrace::set_enabled( false );

		$model    = $this->create_model( 'anthropic', 'claude-3-5-sonnet' );
		$messages = [ $this->create_user_message( 'Test' ) ];

		$this->handler->on_before_generate_result(
			new BeforeGenerateResultEvent( $messages, $model, null )
		);

		$result = $this->create_result( 'result-disabled', $model, 'Response' );

		$this->handler->on_after_generate_result(
			new AfterGenerateResultEvent( $messages, $model, null, $result )
		);

		$this->assertCount( 0, ProviderTrace::list() );
	}

	public function test_stack_cleared_when_tracing_disabled_between_before_and_after(): void {
		// Regression: CodeRabbit major fix. If tracing is toggled off between
		// Before and After events, the stack must still be popped to prevent
		// stale entries from accumulating. This test verifies that the After
		// handler pops the stack even when tracing is disabled.
		$model    = $this->create_model( 'anthropic', 'claude-3-5-sonnet' );
		$messages = [ $this->create_user_message( 'Test' ) ];

		// Enable tracing and push a Before event.
		ProviderTrace::set_enabled( true );
		$this->handler->on_before_generate_result(
			new BeforeGenerateResultEvent( $messages, $model, null )
		);

		// Disable tracing before the After event.
		ProviderTrace::set_enabled( false );

		$result = $this->create_result( 'result-stale', $model, 'Response' );
		$this->handler->on_after_generate_result(
			new AfterGenerateResultEvent( $messages, $model, null, $result )
		);

		// Re-enable tracing and push another Before event. If the stack was
		// not cleared, this Before would pair with the stale After from the
		// previous call, causing incorrect trace rows.
		ProviderTrace::set_enabled( true );
		$model_2    = $this->create_model( 'openai', 'gpt-4o' );
		$messages_2 = [ $this->create_user_message( 'Second call' ) ];

		$this->handler->on_before_generate_result(
			new BeforeGenerateResultEvent( $messages_2, $model_2, null )
		);

		$result_2 = $this->create_result( 'result-2', $model_2, 'Response 2' );
		$this->handler->on_after_generate_result(
			new AfterGenerateResultEvent( $messages_2, $model_2, null, $result_2 )
		);

		// Verify only one trace row was written (the second call), not two.
		// If the stack was not cleared, we would have two rows.
		$rows = ProviderTrace::list();
		$this->assertCount( 1, $rows, 'Only the second call should be traced; the first was disabled.' );
		$this->assertSame( 'openai', $rows[0]->provider_id );
		$this->assertSame( 'gpt-4o', $rows[0]->model_id );
	}

	public function test_stalled_before_event_writes_synthetic_row(): void {
		$model    = $this->create_model( 'anthropic', 'claude-3-5-sonnet' );
		$messages = [ $this->create_user_message( 'Stalled' ) ];

		$this->handler->on_before_generate_result(
			new BeforeGenerateResultEvent( $messages, $model, null )
		);

		AiClientEventTraceLogger::cleanup_stalled_events();

		$rows = ProviderTrace::list( [ 'limit' => 1 ] );
		$this->assertCount( 1, $rows );

		$row = $rows[0];
		$this->assertSame( 'anthropic', $row->provider_id );
		$this->assertSame( 'claude-3-5-sonnet', $row->model_id );
		$this->assertSame( 'SDK', $row->method );
		$this->assertSame( 0, $row->status_code );
		$this->assertSame( 'no_result_event', $row->error );
		$this->assertGreaterThanOrEqual( 0, $row->duration_ms );
	}

	public function test_cleanup_clears_stack_even_when_tracing_disabled(): void {
		// Regression: CodeRabbit major fix. The cleanup_stalled_events()
		// watchdog must clear the stack even when tracing is disabled,
		// to prevent stale entries from accumulating across requests.
		$model    = $this->create_model( 'anthropic', 'claude-3-5-sonnet' );
		$messages = [ $this->create_user_message( 'Stalled' ) ];

		// Enable tracing and push a Before event.
		ProviderTrace::set_enabled( true );
		$this->handler->on_before_generate_result(
			new BeforeGenerateResultEvent( $messages, $model, null )
		);

		// Disable tracing and call cleanup. The stack must still be cleared.
		ProviderTrace::set_enabled( false );
		AiClientEventTraceLogger::cleanup_stalled_events();

		// Re-enable tracing and push another Before event. If the stack was
		// not cleared, this Before would be the second entry, and a subsequent
		// After would pop the wrong entry.
		ProviderTrace::set_enabled( true );
		$model_2    = $this->create_model( 'openai', 'gpt-4o' );
		$messages_2 = [ $this->create_user_message( 'Second call' ) ];

		$this->handler->on_before_generate_result(
			new BeforeGenerateResultEvent( $messages_2, $model_2, null )
		);

		$result_2 = $this->create_result( 'result-2', $model_2, 'Response 2' );
		$this->handler->on_after_generate_result(
			new AfterGenerateResultEvent( $messages_2, $model_2, null, $result_2 )
		);

		// Verify the trace row pairs with model_2, not model_1 (which would
		// indicate the stack was not cleared).
		$rows = ProviderTrace::list();
		$this->assertCount( 1, $rows, 'Only the second call should be traced.' );
		$this->assertSame( 'openai', $rows[0]->provider_id );
		$this->assertSame( 'gpt-4o', $rows[0]->model_id );
	}

	public function test_stalled_watchdog_serialises_messages_with_thought_parts(): void {
		// Direct regression for the user-visible fatal:
		//
		//     Fatal error: Uncaught Error: Call to undefined method
		//     WordPress\AiClient\Messages\DTO\UserMessage::getContent()
		//
		// The watchdog runs on shutdown and calls serialize_messages() on the
		// in-flight messages. With the previous implementation, ANY in-flight
		// Before event would crash the shutdown hook. We assert that messages
		// containing both content and thought-channel parts serialise cleanly
		// to JSON without throwing.
		$model = $this->create_model( 'deepseek', 'deepseek-v4-pro' );
		$messages = [
			$this->create_user_message( 'Plan a trip' ),
			new ModelMessage(
				[
					new MessagePart( 'Thinking about cities…', MessagePartChannelEnum::thought() ),
					new MessagePart( 'Sure, where to?' ),
				]
			),
		];

		$this->handler->on_before_generate_result(
			new BeforeGenerateResultEvent( $messages, $model, null )
		);

		AiClientEventTraceLogger::cleanup_stalled_events();

		$rows = ProviderTrace::list( [ 'limit' => 1 ] );
		$this->assertCount( 1, $rows );
		$full = ProviderTrace::get( $rows[0]->id );
		$this->assertNotNull( $full );

		$decoded = json_decode( $full->request_body, true );
		$this->assertIsArray( $decoded );
		$this->assertCount( 2, $decoded );
		$this->assertSame( 'user', $decoded[0]['role'] );
		$this->assertSame( 'model', $decoded[1]['role'] );
		$this->assertCount( 2, $decoded[1]['parts'] );
		$this->assertSame( 'thought', $decoded[1]['parts'][0]['channel'] );
		$this->assertSame( 'Thinking about cities…', $decoded[1]['parts'][0]['text'] );
	}

	public function test_serialize_messages_skips_non_message_entries_defensively(): void {
		// If a malformed in-flight payload ever leaks (e.g. a stale array
		// rehydrated from cache), the watchdog must not crash on shutdown.
		// We invoke the path by injecting a mixed in-flight via a stalled
		// Before event whose messages list contains a non-Message value.
		$model    = $this->create_model( 'openai', 'gpt-4o' );
		$messages = [
			$this->create_user_message( 'Real message' ),
			[ 'not', 'a', 'Message' ],
		];

		$this->handler->on_before_generate_result(
			new BeforeGenerateResultEvent( $messages, $model, null )
		);

		AiClientEventTraceLogger::cleanup_stalled_events();

		$rows = ProviderTrace::list( [ 'limit' => 1 ] );
		$this->assertCount( 1, $rows );
		$full = ProviderTrace::get( $rows[0]->id );
		$decoded = json_decode( $full->request_body, true );
		$this->assertIsArray( $decoded );
		$this->assertCount( 1, $decoded, 'Non-Message entries are skipped.' );
		$this->assertSame( 'user', $decoded[0]['role'] );
	}

	public function test_sdk_trace_has_source_sdk(): void {
		$model    = $this->create_model( 'openai', 'gpt-4o' );
		$messages = [ $this->create_user_message( 'Test' ) ];

		$this->handler->on_before_generate_result(
			new BeforeGenerateResultEvent( $messages, $model, null )
		);

		$result = $this->create_result( 'result-source', $model, 'Response' );

		$this->handler->on_after_generate_result(
			new AfterGenerateResultEvent( $messages, $model, null, $result )
		);

		$rows = ProviderTrace::list( [ 'limit' => 1 ] );
		$this->assertCount( 1, $rows );
		$full = ProviderTrace::get( $rows[0]->id );
		$this->assertNotNull( $full );
		$this->assertSame( 'sdk', $full->source, 'SDK traces should have source=sdk.' );
	}

	public function test_nested_lifo_correlation_before_a_before_b_after_b_after_a(): void {
		// Regression: nested provider calls must pair Before/After correctly
		// using LIFO stack semantics. This test verifies that when two Before
		// events are pushed (A, then B), the After events pop in reverse order
		// (B, then A), and each After pairs with its corresponding Before.
		$model_a = $this->create_model( 'anthropic', 'claude-3-5-sonnet' );
		$model_b = $this->create_model( 'openai', 'gpt-4o' );

		$messages_a = [ $this->create_user_message( 'First call' ) ];
		$messages_b = [ $this->create_user_message( 'Nested call' ) ];

		// Push Before(A) onto the stack.
		$this->handler->on_before_generate_result(
			new BeforeGenerateResultEvent( $messages_a, $model_a, null )
		);

		// Push Before(B) onto the stack (now stack is [A, B]).
		$this->handler->on_before_generate_result(
			new BeforeGenerateResultEvent( $messages_b, $model_b, null )
		);

		// Pop and pair After(B) with Before(B).
		$result_b = $this->create_result( 'result-b', $model_b, 'Nested response' );
		$this->handler->on_after_generate_result(
			new AfterGenerateResultEvent( $messages_b, $model_b, null, $result_b )
		);

		// Pop and pair After(A) with Before(A).
		$result_a = $this->create_result( 'result-a', $model_a, 'First response' );
		$this->handler->on_after_generate_result(
			new AfterGenerateResultEvent( $messages_a, $model_a, null, $result_a )
		);

		// Verify two trace rows were written, one for each call.
		$rows = ProviderTrace::list( [ 'limit' => 2 ] );
		$this->assertCount( 2, $rows, 'Two nested calls should produce two trace rows.' );

		// Build a map of rows by result ID to avoid order-dependent assertions.
		$by_result_id = [];
		foreach ( $rows as $row ) {
			$row_full = ProviderTrace::get( $row->id );
			$this->assertNotNull( $row_full );
			$decoded = json_decode( $row_full->response_body, true );
			$by_result_id[ $decoded['id'] ?? '' ] = [
				'summary' => $row,
				'full'    => $row_full,
			];
		}

		// Verify both result IDs are present.
		$this->assertArrayHasKey( 'result-a', $by_result_id );
		$this->assertArrayHasKey( 'result-b', $by_result_id );

		// Verify row A paired with model A.
		$this->assertSame( 'anthropic', $by_result_id['result-a']['summary']->provider_id );
		$this->assertSame( 'claude-3-5-sonnet', $by_result_id['result-a']['summary']->model_id );

		// Verify row B paired with model B.
		$this->assertSame( 'openai', $by_result_id['result-b']['summary']->provider_id );
		$this->assertSame( 'gpt-4o', $by_result_id['result-b']['summary']->model_id );
	}

	/**
	 * Build a real ModelInterface stub with the metadata getters the trace
	 * handler reads on the Before event hot path.
	 */
	private function create_model( string $provider_id, string $model_id ): ModelInterface {
		$provider_metadata = new ProviderMetadata(
			$provider_id,
			ucfirst( $provider_id ),
			ProviderTypeEnum::cloud()
		);
		$model_metadata    = new ModelMetadata(
			$model_id,
			$model_id,
			[ CapabilityEnum::textGeneration() ],
			[]
		);

		$model = $this->createMock( ModelInterface::class );
		$model->method( 'providerMetadata' )->willReturn( $provider_metadata );
		$model->method( 'metadata' )->willReturn( $model_metadata );

		return $model;
	}

	/**
	 * Build a UserMessage carrying a single text part.
	 */
	private function create_user_message( string $text ): UserMessage {
		return new UserMessage( [ new MessagePart( $text ) ] );
	}

	/**
	 * Build a single-candidate GenerativeAiResult mirroring the real SDK shape.
	 *
	 * Wraps the reply text in a ModelMessage so Candidate's role check passes,
	 * and uses real ProviderMetadata/ModelMetadata on the result so
	 * getModelMetadata()->getId() returns the expected model_id.
	 */
	private function create_result(
		string $id,
		ModelInterface $model,
		string $reply_text,
		int $prompt_tokens = 10,
		int $completion_tokens = 20,
		int $total_tokens = 30,
		?int $thought_tokens = null,
	): GenerativeAiResult {
		$candidate = new Candidate(
			new ModelMessage( [ new MessagePart( $reply_text ) ] ),
			FinishReasonEnum::stop()
		);

		return new GenerativeAiResult(
			$id,
			[ $candidate ],
			new TokenUsage( $prompt_tokens, $completion_tokens, $total_tokens, $thought_tokens ),
			$model->providerMetadata(),
			$model->metadata()
		);
	}
}
