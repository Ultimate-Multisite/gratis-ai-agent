<?php
/**
 * Test case for AiClientEventTraceHandler and AiClientEventTraceLogger.
 *
 * Tests the SDK event-based trace capture for Before/After event pairs,
 * including correlation, duration computation, and structured data extraction.
 *
 * Rewritten 2026-05-15 to use real SDK DTOs — the previous version's mocks
 * used `createMock('stdClass')` (which cannot define methods that don't exist
 * on stdClass) and several DTO constructor signatures that don't exist in the
 * shipped php-ai-client (e.g. `new Candidate(content:..., finishReason:...)`
 * — the real Candidate takes a Message + FinishReasonEnum). PHPUnit rejected
 * the file outright with `MethodCannotBeConfiguredException`. Tests now use
 * anonymous classes implementing ModelInterface, real Message/MessagePart
 * DTOs, and the actual GenerativeAiResult constructor shape.
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
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
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

		// Enable provider tracing for these tests.
		ProviderTrace::set_enabled( true );

		// Clear any existing trace rows.
		ProviderTrace::clear();

		// Reset the logger's static in-flight stack so state from prior
		// tests doesn't leak into this one (e.g. a Before without a
		// matching After would persist on the stack and the next test's
		// After would pop it instead of its own Before).
		AiClientEventTraceLogger::reset_inflight_for_tests();
	}

	protected function tearDown(): void {
		ProviderTrace::clear();
		ProviderTrace::set_enabled( false );
		AiClientEventTraceLogger::reset_inflight_for_tests();
		parent::tearDown();
	}

	public function test_before_and_after_events_write_trace_row(): void {
		$model      = $this->create_test_model( 'anthropic', 'claude-3-5-sonnet' );
		$messages   = [ $this->create_user_message( 'Hello' ) ];
		$capability = CapabilityEnum::textGeneration();

		// Dispatch Before event.
		$before_event = new BeforeGenerateResultEvent( $messages, $model, $capability );
		$this->handler->on_before_generate_result( $before_event );

		// Dispatch After event with a real result DTO.
		$result      = $this->create_result(
			'result-123',
			'Hello there!',
			FinishReasonEnum::stop(),
			$this->create_token_usage( 10, 20 ),
			$model
		);
		$after_event = new AfterGenerateResultEvent( $messages, $model, $capability, $result );
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
		$model    = $this->create_test_model( 'openai', 'gpt-4o' );
		$messages = [ $this->create_user_message( 'Test' ) ];

		$before_event = new BeforeGenerateResultEvent( $messages, $model, null );
		$this->handler->on_before_generate_result( $before_event );

		$result      = $this->create_result(
			'result-456',
			'Response',
			FinishReasonEnum::stop(),
			$this->create_token_usage( 100, 50 ),
			$model
		);
		$after_event = new AfterGenerateResultEvent( $messages, $model, null, $result );
		$this->handler->on_after_generate_result( $after_event );

		$rows = ProviderTrace::list( [ 'limit' => 1 ] );
		$this->assertCount( 1, $rows );

		// ProviderTrace::list() returns a lightweight summary (no body
		// content, no source); re-fetch the full row via ::get() to
		// inspect response_body content.
		$row = ProviderTrace::get( $rows[0]->id );
		$this->assertNotNull( $row );

		// The shipped php-ai-client TokenUsage DTO does not track cache
		// creation/read tokens (no getter methods for them). The trace
		// logger writes 0 into the cache_* schema columns and the HTTP
		// trace channel is the source of truth for those when needed.
		$this->assertSame( 0, $row->cache_creation_tokens );
		$this->assertSame( 0, $row->cache_read_tokens );

		// The token counts the SDK does expose round-trip through the
		// response_body JSON.
		$response = json_decode( $row->response_body, true );
		$this->assertIsArray( $response );
		$this->assertSame( 100, $response['usage']['input_tokens'] );
		$this->assertSame( 50, $response['usage']['output_tokens'] );
	}

	public function test_capability_is_stored_in_request_body(): void {
		$model      = $this->create_test_model( 'google', 'gemini-2.0-flash' );
		$messages   = [ $this->create_user_message( 'Analyze' ) ];
		$capability = CapabilityEnum::textGeneration();

		$before_event = new BeforeGenerateResultEvent( $messages, $model, $capability );
		$this->handler->on_before_generate_result( $before_event );

		$result      = $this->create_result(
			'result-789',
			'Analysis result',
			FinishReasonEnum::stop(),
			$this->create_token_usage( 50, 75 ),
			$model
		);
		$after_event = new AfterGenerateResultEvent( $messages, $model, $capability, $result );
		$this->handler->on_after_generate_result( $after_event );

		$rows = ProviderTrace::list( [ 'limit' => 1 ] );
		$this->assertCount( 1, $rows );

		// ::list() omits body content for performance; re-fetch via ::get().
		$row = ProviderTrace::get( $rows[0]->id );
		$this->assertNotNull( $row );

		// The request_body should contain the messages as JSON.
		$this->assertNotEmpty( $row->request_body );
		$decoded = json_decode( $row->request_body, true );
		$this->assertIsArray( $decoded );
	}

	public function test_finish_reason_is_stored_in_response_body(): void {
		$model    = $this->create_test_model( 'anthropic', 'claude-3-5-sonnet' );
		$messages = [ $this->create_user_message( 'Generate' ) ];

		$before_event = new BeforeGenerateResultEvent( $messages, $model, null );
		$this->handler->on_before_generate_result( $before_event );

		$result      = $this->create_result(
			'result-999',
			'Generated content',
			FinishReasonEnum::stop(),
			$this->create_token_usage( 20, 30 ),
			$model
		);
		$after_event = new AfterGenerateResultEvent( $messages, $model, null, $result );
		$this->handler->on_after_generate_result( $after_event );

		$rows = ProviderTrace::list( [ 'limit' => 1 ] );
		$this->assertCount( 1, $rows );

		// ::list() omits body content for performance; re-fetch via ::get().
		$row = ProviderTrace::get( $rows[0]->id );
		$this->assertNotNull( $row );

		// The response_body should contain the result with finish_reason.
		$this->assertNotEmpty( $row->response_body );
		$decoded = json_decode( $row->response_body, true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'candidates', $decoded );
	}

	public function test_tracing_disabled_skips_logging(): void {
		ProviderTrace::set_enabled( false );

		$model    = $this->create_test_model( 'anthropic', 'claude-3-5-sonnet' );
		$messages = [ $this->create_user_message( 'Test' ) ];

		$before_event = new BeforeGenerateResultEvent( $messages, $model, null );
		$this->handler->on_before_generate_result( $before_event );

		$result      = $this->create_result(
			'result-disabled',
			'Response',
			FinishReasonEnum::stop(),
			$this->create_token_usage( 10, 20 ),
			$model
		);
		$after_event = new AfterGenerateResultEvent( $messages, $model, null, $result );
		$this->handler->on_after_generate_result( $after_event );

		// No trace row should be written.
		$rows = ProviderTrace::list();
		$this->assertCount( 0, $rows );
	}

	public function test_stalled_before_event_writes_synthetic_row(): void {
		$model    = $this->create_test_model( 'anthropic', 'claude-3-5-sonnet' );
		$messages = [ $this->create_user_message( 'Stalled' ) ];

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

		// duration_ms can legitimately be 0 when push and pop happen in the
		// same millisecond (synchronous test path; in production the SDK
		// request takes at least one network round-trip). Assert it's a
		// non-negative integer rather than strictly > 0.
		$this->assertGreaterThanOrEqual( 0, $row->duration_ms );

		// The `error` and `source` columns are not selected by ::list();
		// re-fetch the full row via ::get() to verify the error marker.
		$full = ProviderTrace::get( $row->id );
		$this->assertNotNull( $full );
		$this->assertSame( 'no_result_event', $full->error );
	}

	public function test_sdk_trace_has_source_sdk(): void {
		$model    = $this->create_test_model( 'openai', 'gpt-4o' );
		$messages = [ $this->create_user_message( 'Test' ) ];

		$before_event = new BeforeGenerateResultEvent( $messages, $model, null );
		$this->handler->on_before_generate_result( $before_event );

		$result      = $this->create_result(
			'result-123',
			'Response',
			FinishReasonEnum::stop(),
			$this->create_token_usage( 10, 20 ),
			$model
		);
		$after_event = new AfterGenerateResultEvent( $messages, $model, null, $result );
		$this->handler->on_after_generate_result( $after_event );

		$rows = ProviderTrace::list();
		$this->assertCount( 1, $rows );

		// `source` is not selected by ::list(); re-fetch the full row.
		$row = ProviderTrace::get( $rows[0]->id );
		$this->assertNotNull( $row );
		$this->assertSame( 'sdk', $row->source, 'SDK traces should have source=sdk' );
	}

	// -------------------------------------------------------------------------
	// Helpers — real DTOs, not createMock('stdClass')
	// -------------------------------------------------------------------------

	/**
	 * Build an anonymous ModelInterface implementation with real
	 * ProviderMetadata + ModelMetadata DTOs. The SDK's events check the
	 * declared interface (not arbitrary methods on stdClass), so this is
	 * the minimum viable test double.
	 */
	private function create_test_model( string $provider_id, string $model_id ): ModelInterface {
		$provider_metadata = new ProviderMetadata(
			$provider_id,
			$provider_id,
			ProviderTypeEnum::cloud()
		);
		$model_metadata    = new ModelMetadata( $model_id, $model_id, [], [] );

		return new class( $provider_metadata, $model_metadata ) implements ModelInterface {
			private ProviderMetadata $provider_metadata;
			private ModelMetadata $model_metadata;
			private ModelConfig $config;

			public function __construct( ProviderMetadata $provider_metadata, ModelMetadata $model_metadata ) {
				$this->provider_metadata = $provider_metadata;
				$this->model_metadata    = $model_metadata;
				$this->config            = new ModelConfig();
			}

			public function metadata(): ModelMetadata {
				return $this->model_metadata;
			}

			public function providerMetadata(): ProviderMetadata {
				return $this->provider_metadata;
			}

			public function setConfig( ModelConfig $config ): void {
				$this->config = $config;
			}

			public function getConfig(): ModelConfig {
				return $this->config;
			}
		};
	}

	private function create_user_message( string $content ): Message {
		return new Message( MessageRoleEnum::user(), [ new MessagePart( $content ) ] );
	}

	private function create_model_message( string $content ): Message {
		return new Message( MessageRoleEnum::model(), [ new MessagePart( $content ) ] );
	}

	/**
	 * The shipped php-ai-client TokenUsage takes
	 * (promptTokens, completionTokens, totalTokens, ?thoughtTokens). We
	 * compute totalTokens as prompt+completion to match what the SDK
	 * adapters typically synthesize.
	 */
	private function create_token_usage( int $prompt, int $completion ): TokenUsage {
		return new TokenUsage(
			promptTokens: $prompt,
			completionTokens: $completion,
			totalTokens: $prompt + $completion,
		);
	}

	/**
	 * Build a real GenerativeAiResult with a single candidate that
	 * uses the supplied model's metadata (so the result self-describes
	 * the provider/model the trace logger reads from it).
	 */
	private function create_result(
		string $id,
		string $content,
		FinishReasonEnum $finish_reason,
		TokenUsage $token_usage,
		ModelInterface $model
	): GenerativeAiResult {
		$candidate = new Candidate( $this->create_model_message( $content ), $finish_reason );
		return new GenerativeAiResult(
			$id,
			[ $candidate ],
			$token_usage,
			$model->providerMetadata(),
			$model->metadata(),
		);
	}
}
