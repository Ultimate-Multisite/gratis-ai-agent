<?php
/**
 * Test case for HttpTraceHandler::on_http_request_args (cache marker injection).
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Bootstrap;

use SdAiAgent\Bootstrap\HttpTraceHandler;
use SdAiAgent\Core\Settings;
use WP_UnitTestCase;

/**
 * Verifies that the cache-strategy http_request_args filter mutates
 * Anthropic request bodies and leaves everything else alone.
 *
 * @covers \SdAiAgent\Bootstrap\HttpTraceHandler::on_http_request_args
 */
class HttpTraceHandlerCacheTest extends WP_UnitTestCase {

	private HttpTraceHandler $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new HttpTraceHandler();

		// Default-on for these tests — explicitly reset.
		update_option(
			Settings::OPTION_NAME,
			array( 'prompt_caching_enabled' => true )
		);
	}

	protected function tearDown(): void {
		delete_option( Settings::OPTION_NAME );
		parent::tearDown();
	}

	public function test_anthropic_request_body_gets_cache_markers(): void {
		$body = $this->large_anthropic_body();

		$args = array(
			'method' => 'POST',
			'body'   => (string) wp_json_encode( $body ),
		);

		$result = $this->handler->on_http_request_args( $args, 'https://api.anthropic.com/v1/messages' );

		$this->assertNotSame( $args['body'], $result['body'], 'Body should be mutated.' );

		$decoded = json_decode( (string) $result['body'], true );
		$this->assertIsArray( $decoded );

		// Last tool marker.
		$last_tool = end( $decoded['tools'] );
		$this->assertArrayHasKey( 'cache_control', $last_tool );

		// Last system block marker.
		$last_sys = end( $decoded['system'] );
		$this->assertArrayHasKey( 'cache_control', $last_sys );
	}

	public function test_openai_request_body_is_unchanged(): void {
		$body = array(
			'model'    => 'gpt-4o',
			'messages' => array( array( 'role' => 'user', 'content' => 'hi' ) ),
		);

		$args = array(
			'method' => 'POST',
			'body'   => (string) wp_json_encode( $body ),
		);

		$result = $this->handler->on_http_request_args( $args, 'https://api.openai.com/v1/chat/completions' );

		$this->assertSame( $args['body'], $result['body'], 'OpenAI body must not be mutated — caching is automatic server-side.' );
	}

	public function test_unknown_host_passes_through(): void {
		$body = array( 'anything' => 'goes' );
		$args = array(
			'method' => 'POST',
			'body'   => (string) wp_json_encode( $body ),
		);

		$result = $this->handler->on_http_request_args( $args, 'https://example.com/api/anything' );

		$this->assertSame( $args, $result );
	}

	public function test_disabled_setting_skips_mutation(): void {
		update_option(
			Settings::OPTION_NAME,
			array( 'prompt_caching_enabled' => false )
		);

		$body = $this->large_anthropic_body();
		$args = array(
			'method' => 'POST',
			'body'   => (string) wp_json_encode( $body ),
		);

		$result = $this->handler->on_http_request_args( $args, 'https://api.anthropic.com/v1/messages' );

		$this->assertSame( $args['body'], $result['body'] );
	}

	public function test_non_string_body_passes_through(): void {
		$args = array(
			'method' => 'GET',
			'body'   => null,
		);

		$result = $this->handler->on_http_request_args( $args, 'https://api.anthropic.com/v1/messages' );

		$this->assertSame( $args, $result );
	}

	public function test_non_json_body_passes_through(): void {
		$args = array(
			'method' => 'POST',
			'body'   => 'form-encoded=value&another=1',
		);

		$result = $this->handler->on_http_request_args( $args, 'https://api.anthropic.com/v1/messages' );

		$this->assertSame( $args, $result );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function large_anthropic_body(): array {
		return array(
			'model'    => 'claude-opus-4-7',
			'system'   => array(
				array( 'type' => 'text', 'text' => "You are Claude Code." ),
				array( 'type' => 'text', 'text' => str_repeat( 'long system prompt sentence. ', 200 ) ),
			),
			'tools'    => array(
				array(
					'name'         => 'tool_a',
					'description'  => str_repeat( 'desc a ', 50 ),
					'input_schema' => array( 'type' => 'object' ),
				),
				array(
					'name'         => 'tool_b',
					'description'  => str_repeat( 'desc b ', 50 ),
					'input_schema' => array( 'type' => 'object' ),
				),
			),
			'messages' => array(
				array( 'role' => 'user', 'content' => 'hello' ),
			),
		);
	}
}
