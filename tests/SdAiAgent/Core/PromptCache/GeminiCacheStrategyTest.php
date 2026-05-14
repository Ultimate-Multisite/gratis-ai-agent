<?php
/**
 * Test case for GeminiCacheStrategy.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core\PromptCache;

use SdAiAgent\Core\PromptCache\GeminiCacheManagerInterface;
use SdAiAgent\Core\PromptCache\GeminiCacheStrategy;
use SdAiAgent\Core\PromptCache\RequestContextAwareCacheStrategyInterface;
use WP_UnitTestCase;

/**
 * @covers \SdAiAgent\Core\PromptCache\GeminiCacheStrategy
 */
class GeminiCacheStrategyTest extends WP_UnitTestCase {

	private GeminiCacheStrategy $strategy;

	protected function setUp(): void {
		parent::setUp();
		$this->strategy = new GeminiCacheStrategy();
	}

	// -------------------------------------------------------------------------
	// Interface compliance
	// -------------------------------------------------------------------------

	public function test_implements_request_context_aware_interface(): void {
		$this->assertInstanceOf( RequestContextAwareCacheStrategyInterface::class, $this->strategy );
	}

	public function test_id(): void {
		$this->assertSame( 'google', $this->strategy->id() );
	}

	// -------------------------------------------------------------------------
	// matches()
	// -------------------------------------------------------------------------

	public function test_matches_generate_content_url(): void {
		$this->assertTrue(
			$this->strategy->matches(
				'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro-preview-05-06:generateContent'
			)
		);
	}

	public function test_matches_generate_content_url_with_key_param(): void {
		$this->assertTrue(
			$this->strategy->matches(
				'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent?key=abc123'
			)
		);
	}

	public function test_does_not_match_cached_contents_endpoint(): void {
		$this->assertFalse(
			$this->strategy->matches(
				'https://generativelanguage.googleapis.com/v1beta/cachedContents'
			)
		);
	}

	public function test_does_not_match_openai(): void {
		$this->assertFalse( $this->strategy->matches( 'https://api.openai.com/v1/chat/completions' ) );
	}

	public function test_does_not_match_anthropic(): void {
		$this->assertFalse( $this->strategy->matches( 'https://api.anthropic.com/v1/messages' ) );
	}

	public function test_does_not_match_malformed_url(): void {
		$this->assertFalse( $this->strategy->matches( '' ) );
		$this->assertFalse( $this->strategy->matches( 'not-a-url' ) );
	}

	// -------------------------------------------------------------------------
	// apply() — no API key
	// -------------------------------------------------------------------------

	public function test_apply_returns_body_unchanged_without_api_key(): void {
		$body = $this->large_request_body();
		// Do NOT call set_request_context — simulates missing key.

		$result = $this->strategy->apply( $body );

		$this->assertSame( $body, $result, 'Without API key the body must pass through untouched.' );
	}

	// -------------------------------------------------------------------------
	// apply() — below minimum size
	// -------------------------------------------------------------------------

	public function test_apply_skips_small_requests(): void {
		$this->strategy->set_request_context( array( 'Authorization' => 'Bearer sk-test' ), 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent' );

		$body   = array(
			'model'    => 'gemini-2.5-pro',
			'contents' => array(
				array( 'role' => 'user', 'parts' => array( array( 'text' => 'short prompt' ) ) ),
				array( 'role' => 'model', 'parts' => array( array( 'text' => 'ok' ) ) ),
				array( 'role' => 'user', 'parts' => array( array( 'text' => 'new turn' ) ) ),
			),
		);
		$result = $this->strategy->apply( $body );

		$this->assertSame( $body, $result, 'Small request must pass through without modification.' );
	}

	// -------------------------------------------------------------------------
	// apply() — too few turns to build stable prefix
	// -------------------------------------------------------------------------

	public function test_apply_skips_single_turn_conversations(): void {
		$this->strategy->set_request_context( array( 'Authorization' => 'Bearer sk-test' ), 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent' );

		$body = array(
			'model'              => 'gemini-2.5-pro',
			'systemInstruction'  => array( 'parts' => array( array( 'text' => str_repeat( 'system ', 50_000 ) ) ) ),
			'contents'           => array(
				array( 'role' => 'user', 'parts' => array( array( 'text' => 'only one turn' ) ) ),
			),
		);

		$result = $this->strategy->apply( $body );

		// Single-turn: stable prefix is empty → no mutation.
		$this->assertArrayNotHasKey( 'cachedContent', $result );
	}

	// -------------------------------------------------------------------------
	// apply() — successful cache hit
	// -------------------------------------------------------------------------

	public function test_apply_splices_cached_content_on_cache_hit(): void {
		$mock_manager = $this->createMock( GeminiCacheManagerInterface::class );
		$mock_manager->method( 'find_or_create' )->willReturn( 'cachedContents/abc123' );
		$mock_manager->method( 'build_hash' )->willReturn( str_repeat( 'a', 32 ) );

		$strategy = new GeminiCacheStrategy( $mock_manager );
		$strategy->set_request_context(
			array( 'Authorization' => 'Bearer sk-live-key' ),
			'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent'
		);

		$body   = $this->large_request_body();
		$result = $strategy->apply( $body );

		$this->assertArrayHasKey( 'cachedContent', $result );
		$this->assertSame( 'cachedContents/abc123', $result['cachedContent'] );
	}

	public function test_apply_strips_system_instruction_and_tools_on_cache_hit(): void {
		$mock_manager = $this->createMock( GeminiCacheManagerInterface::class );
		$mock_manager->method( 'find_or_create' )->willReturn( 'cachedContents/abc123' );
		$mock_manager->method( 'build_hash' )->willReturn( str_repeat( 'a', 32 ) );

		$strategy = new GeminiCacheStrategy( $mock_manager );
		$strategy->set_request_context(
			array( 'Authorization' => 'Bearer sk-live-key' ),
			'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent'
		);

		$body   = $this->large_request_body();
		$result = $strategy->apply( $body );

		$this->assertArrayNotHasKey( 'systemInstruction', $result, 'systemInstruction must be stripped when served from cache.' );
		$this->assertArrayNotHasKey( 'tools', $result, 'tools must be stripped when served from cache.' );
	}

	public function test_apply_trims_contents_to_current_turn_only(): void {
		$mock_manager = $this->createMock( GeminiCacheManagerInterface::class );
		$mock_manager->method( 'find_or_create' )->willReturn( 'cachedContents/abc123' );
		$mock_manager->method( 'build_hash' )->willReturn( str_repeat( 'a', 32 ) );

		$strategy = new GeminiCacheStrategy( $mock_manager );
		$strategy->set_request_context(
			array( 'Authorization' => 'Bearer sk-live-key' ),
			'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent'
		);

		$body   = $this->large_request_body();
		$result = $strategy->apply( $body );

		// Only the final (new) turn should remain in `contents`.
		$this->assertIsArray( $result['contents'] );
		$this->assertCount( 1, $result['contents'], 'Only the current turn must remain in contents.' );
		$this->assertSame( 'user', $result['contents'][0]['role'] );
		$this->assertStringContainsString( 'New question', $result['contents'][0]['parts'][0]['text'] );
	}

	// -------------------------------------------------------------------------
	// apply() — cache manager returns null (failure path)
	// -------------------------------------------------------------------------

	public function test_apply_returns_body_unchanged_when_manager_returns_null(): void {
		$mock_manager = $this->createMock( GeminiCacheManagerInterface::class );
		$mock_manager->method( 'find_or_create' )->willReturn( null );
		$mock_manager->method( 'build_hash' )->willReturn( str_repeat( 'a', 32 ) );

		$strategy = new GeminiCacheStrategy( $mock_manager );
		$strategy->set_request_context(
			array( 'Authorization' => 'Bearer sk-live-key' ),
			'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent'
		);

		$body   = $this->large_request_body();
		$result = $strategy->apply( $body );

		// Should degrade gracefully — return original body unchanged.
		$this->assertSame( $body, $result );
	}

	// -------------------------------------------------------------------------
	// set_request_context() — API key extraction
	// -------------------------------------------------------------------------

	public function test_set_request_context_extracts_bearer_token(): void {
		$called_with_key = null;

		$mock_manager = $this->createMock( GeminiCacheManagerInterface::class );
		$mock_manager->method( 'find_or_create' )
			->willReturnCallback(
				static function ( string $api_key ) use ( &$called_with_key ) {
					$called_with_key = $api_key;
					return null;
				}
			);
		$mock_manager->method( 'build_hash' )->willReturn( str_repeat( 'a', 32 ) );

		$strategy = new GeminiCacheStrategy( $mock_manager );
		$strategy->set_request_context(
			array( 'Authorization' => 'Bearer my-api-key-abc' ),
			'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent'
		);

		// Trigger apply so find_or_create is called.
		$strategy->apply( $this->large_request_body() );

		$this->assertSame( 'my-api-key-abc', $called_with_key );
	}

	public function test_set_request_context_falls_back_to_query_param(): void {
		$called_with_key = null;

		$mock_manager = $this->createMock( GeminiCacheManagerInterface::class );
		$mock_manager->method( 'find_or_create' )
			->willReturnCallback(
				static function ( string $api_key ) use ( &$called_with_key ) {
					$called_with_key = $api_key;
					return null;
				}
			);
		$mock_manager->method( 'build_hash' )->willReturn( str_repeat( 'a', 32 ) );

		$strategy = new GeminiCacheStrategy( $mock_manager );
		$strategy->set_request_context(
			array(), // No Authorization header.
			'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent?key=query-key-xyz'
		);

		$strategy->apply( $this->large_request_body() );

		$this->assertSame( 'query-key-xyz', $called_with_key );
	}

	public function test_set_request_context_authorization_takes_priority_over_query_param(): void {
		$called_with_key = null;

		$mock_manager = $this->createMock( GeminiCacheManagerInterface::class );
		$mock_manager->method( 'find_or_create' )
			->willReturnCallback(
				static function ( string $api_key ) use ( &$called_with_key ) {
					$called_with_key = $api_key;
					return null;
				}
			);
		$mock_manager->method( 'build_hash' )->willReturn( str_repeat( 'a', 32 ) );

		$strategy = new GeminiCacheStrategy( $mock_manager );
		$strategy->set_request_context(
			array( 'Authorization' => 'Bearer header-key' ),
			'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent?key=param-key'
		);

		$strategy->apply( $this->large_request_body() );

		$this->assertSame( 'header-key', $called_with_key );
	}

	// -------------------------------------------------------------------------
	// Idempotency
	// -------------------------------------------------------------------------

	public function test_apply_is_idempotent(): void {
		$mock_manager = $this->createMock( GeminiCacheManagerInterface::class );
		$mock_manager->method( 'find_or_create' )->willReturn( 'cachedContents/abc123' );
		$mock_manager->method( 'build_hash' )->willReturn( str_repeat( 'a', 32 ) );

		$strategy = new GeminiCacheStrategy( $mock_manager );
		$strategy->set_request_context(
			array( 'Authorization' => 'Bearer sk-live' ),
			'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent'
		);

		$body  = $this->large_request_body();
		$once  = $strategy->apply( $body );

		// Applying again to the already-mutated body must yield the same result.
		$strategy->set_request_context(
			array( 'Authorization' => 'Bearer sk-live' ),
			'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent'
		);
		$twice = $strategy->apply( $once );

		$this->assertSame( $once, $twice );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a request body that passes the MIN_CHARS_FOR_CACHE threshold
	 * (128 000 chars combined) and has multiple conversation turns.
	 *
	 * @return array<string,mixed>
	 */
	private function large_request_body(): array {
		return array(
			'model'             => 'gemini-2.5-pro',
			'systemInstruction' => array(
				'parts' => array(
					array( 'text' => str_repeat( 'You are a helpful assistant. ', 5_000 ) ),
				),
			),
			'tools'             => array(
				array(
					'function_declarations' => array(
						array(
							'name'        => 'search',
							'description' => str_repeat( 'Searches the web for information. ', 200 ),
						),
					),
				),
			),
			'contents'          => array(
				// Turn 1 — stable (will be cached).
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => 'Tell me about PHP 8.2 features.' ) ),
				),
				// Turn 2 — stable (will be cached).
				array(
					'role'  => 'model',
					'parts' => array( array( 'text' => str_repeat( 'PHP 8.2 introduced fibers, enums, and more. ', 100 ) ) ),
				),
				// Turn 3 — current (dynamic, NOT cached).
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => 'New question: what about readonly classes?' ) ),
				),
			),
		);
	}
}
