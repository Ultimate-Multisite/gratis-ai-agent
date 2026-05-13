<?php
/**
 * Test case for AnthropicCacheStrategy.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core\PromptCache;

use SdAiAgent\Core\PromptCache\AnthropicCacheStrategy;
use WP_UnitTestCase;

/**
 * @covers \SdAiAgent\Core\PromptCache\AnthropicCacheStrategy
 */
class AnthropicCacheStrategyTest extends WP_UnitTestCase {

	private AnthropicCacheStrategy $strategy;

	protected function setUp(): void {
		parent::setUp();
		$this->strategy = new AnthropicCacheStrategy();
	}

	public function test_id(): void {
		$this->assertSame( 'anthropic', $this->strategy->id() );
	}

	public function test_matches_anthropic_api_host(): void {
		$this->assertTrue( $this->strategy->matches( 'https://api.anthropic.com/v1/messages' ) );
	}

	public function test_matches_anthropic_api_subdomain(): void {
		$this->assertTrue( $this->strategy->matches( 'https://eu.api.anthropic.com/v1/messages' ) );
	}

	public function test_does_not_match_openai(): void {
		$this->assertFalse( $this->strategy->matches( 'https://api.openai.com/v1/chat/completions' ) );
	}

	public function test_does_not_match_malformed_url(): void {
		$this->assertFalse( $this->strategy->matches( '' ) );
		$this->assertFalse( $this->strategy->matches( 'not-a-url' ) );
	}

	/**
	 * Below the minimum char threshold, no markers are added — they would
	 * just burn marker budget without producing a cache hit.
	 */
	public function test_skips_small_requests(): void {
		$body = array(
			'model'    => 'claude-opus-4-7',
			'system'   => 'short prompt',
			'tools'    => array( array( 'name' => 'tool_a', 'description' => 'x' ) ),
			'messages' => array( array( 'role' => 'user', 'content' => 'hi' ) ),
		);

		$result = $this->strategy->apply( $body );

		$this->assertSame( $body, $result, 'Small requests should pass through untouched.' );
	}

	public function test_marks_last_tool_and_system_block_on_large_request(): void {
		$body = $this->large_request_body();

		$result = $this->strategy->apply( $body );

		// Last tool gets a cache_control marker.
		$tools_count = count( $result['tools'] );
		$this->assertArrayHasKey( 'cache_control', $result['tools'][ $tools_count - 1 ] );
		$this->assertSame(
			array( 'type' => 'ephemeral' ),
			$result['tools'][ $tools_count - 1 ]['cache_control']
		);

		// Earlier tools are NOT marked.
		$this->assertArrayNotHasKey( 'cache_control', $result['tools'][0] );

		// Last system block gets a marker.
		$sys_count = count( $result['system'] );
		$this->assertArrayHasKey( 'cache_control', $result['system'][ $sys_count - 1 ] );
		$this->assertSame(
			array( 'type' => 'ephemeral' ),
			$result['system'][ $sys_count - 1 ]['cache_control']
		);

		// Earlier system blocks are NOT marked.
		$this->assertArrayNotHasKey( 'cache_control', $result['system'][0] );
	}

	public function test_promotes_string_system_to_array(): void {
		$body = array(
			'model'  => 'claude-opus-4-7',
			'system' => str_repeat( 'A very long system prompt sentence. ', 200 ),
			'tools'  => array(),
		);

		$result = $this->strategy->apply( $body );

		$this->assertIsArray( $result['system'] );
		$this->assertCount( 1, $result['system'] );
		$this->assertSame( 'text', $result['system'][0]['type'] );
		$this->assertSame(
			array( 'type' => 'ephemeral' ),
			$result['system'][0]['cache_control']
		);
	}

	public function test_idempotent(): void {
		$body = $this->large_request_body();

		$once  = $this->strategy->apply( $body );
		$twice = $this->strategy->apply( $once );

		$this->assertSame( $once, $twice );
	}

	public function test_preserves_caller_supplied_cache_control(): void {
		$body                                = $this->large_request_body();
		$body['system'][1]['cache_control']  = array( 'type' => 'ephemeral', 'ttl' => '1h' );

		$result = $this->strategy->apply( $body );

		// Existing marker is preserved (not overwritten with the default).
		$this->assertSame(
			array( 'type' => 'ephemeral', 'ttl' => '1h' ),
			$result['system'][1]['cache_control']
		);
	}

	public function test_missing_tools_does_not_break(): void {
		$body = array(
			'model'  => 'claude-opus-4-7',
			'system' => array(
				array( 'type' => 'text', 'text' => str_repeat( 'x', 5000 ) ),
			),
		);

		$result = $this->strategy->apply( $body );

		// System still gets marked.
		$this->assertArrayHasKey( 'cache_control', $result['system'][0] );
		// No tools field is fabricated.
		$this->assertArrayNotHasKey( 'tools', $result );
	}

	public function test_missing_system_does_not_break(): void {
		$body = array(
			'model' => 'claude-opus-4-7',
			'tools' => array(
				array( 'name' => 'tool_a', 'description' => str_repeat( 'x', 5000 ) ),
			),
		);

		$result = $this->strategy->apply( $body );

		// Tool still gets marked.
		$this->assertArrayHasKey( 'cache_control', $result['tools'][0] );
	}

	/**
	 * Build a representative large request body — system prompt + tools
	 * combined comfortably exceed the MIN_CHARS_FOR_CACHE threshold.
	 *
	 * @return array<string,mixed>
	 */
	private function large_request_body(): array {
		return array(
			'model'    => 'claude-opus-4-7',
			'system'   => array(
				array( 'type' => 'text', 'text' => "You are Claude Code, Anthropic's official CLI for Claude." ),
				array( 'type' => 'text', 'text' => str_repeat( 'A long system prompt. ', 300 ) ),
			),
			'tools'    => array(
				array(
					'name'         => 'tool_a',
					'description'  => str_repeat( 'desc a ', 50 ),
					'input_schema' => array( 'type' => 'object', 'properties' => array() ),
				),
				array(
					'name'         => 'tool_b',
					'description'  => str_repeat( 'desc b ', 50 ),
					'input_schema' => array( 'type' => 'object', 'properties' => array() ),
				),
			),
			'messages' => array(
				array( 'role' => 'user', 'content' => 'hello' ),
			),
		);
	}
}
