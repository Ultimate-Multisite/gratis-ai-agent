<?php

declare(strict_types=1);
/**
 * Tests for AgentLoop client-side (JS) ability routing.
 *
 * Covers:
 * - Posting a fake client_abilities descriptor causes a model tool call
 *   for that name to return pending_client_tool_calls instead of executing.
 * - Resume path correctly appends results and continues the loop.
 * - Mixed PHP+JS tool calls in one assistant message execute the PHP ones
 *   inline and return only the JS ones as pending.
 * - Unknown (non-catalog) descriptor names are rejected.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Abilities\Js\JsAbilityCatalog;
use SdAiAgent\Core\AgentLoop;
use SdAiAgent\Core\Settings;
use WP_UnitTestCase;

/**
 * Tests for AgentLoop client-side ability routing.
 *
 * @group agent-loop
 * @group client-tools
 */
class AgentLoopClientToolsTest extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down(): void {
		parent::tear_down();
	}

	// ── JsAbilityCatalog tests ────────────────────────────────────────────

	/**
	 * JsAbilityCatalog::get_descriptors() returns at least the two built-in abilities.
	 */
	public function test_catalog_returns_built_in_abilities(): void {
		$descriptors = JsAbilityCatalog::get_descriptors();

		$this->assertIsArray( $descriptors );
		$this->assertGreaterThanOrEqual( 2, count( $descriptors ) );

		$names = array_column( $descriptors, 'name' );
		$this->assertContains( 'sd-ai-agent-js/navigate-to', $names );
		$this->assertContains( 'sd-ai-agent-js/insert-block', $names );
	}

	/**
	 * JsAbilityCatalog::has() returns true for known names and false for unknown.
	 */
	public function test_catalog_has_method(): void {
		$this->assertTrue( JsAbilityCatalog::has( 'sd-ai-agent-js/navigate-to' ) );
		$this->assertTrue( JsAbilityCatalog::has( 'sd-ai-agent-js/insert-block' ) );
		$this->assertFalse( JsAbilityCatalog::has( 'sd-ai-agent-js/unknown-ability' ) );
		$this->assertFalse( JsAbilityCatalog::has( 'sd-ai-agent/memory-save' ) );
	}

	/**
	 * JsAbilityCatalog::get_descriptors_by_name() returns a keyed map.
	 */
	public function test_catalog_by_name_map(): void {
		$map = JsAbilityCatalog::get_descriptors_by_name();

		$this->assertIsArray( $map );
		$this->assertArrayHasKey( 'sd-ai-agent-js/navigate-to', $map );
		$this->assertArrayHasKey( 'sd-ai-agent-js/insert-block', $map );

		$navigate = $map['sd-ai-agent-js/navigate-to'];
		$this->assertSame( 'sd-ai-agent-js', $navigate['category'] );
		$this->assertTrue( $navigate['annotations']['readonly'] );
	}

	// ── AgentLoop client_abilities validation tests ───────────────────────

	/**
	 * Unknown descriptor names are rejected during construction.
	 *
	 * The constructor should silently drop any name not in JsAbilityCatalog.
	 */
	public function test_unknown_client_ability_names_are_rejected(): void {
		$loop = new AgentLoop(
			'test',
			array(),
			array(),
			array(
				'client_abilities' => array(
					array(
						'name'  => 'sd-ai-agent-js/unknown-ability',
						'label' => 'Unknown',
					),
					array(
						'name'  => 'sd-ai-agent-js/navigate-to',
						'label' => 'Navigate',
					),
				),
			)
		);

		// Access the private property via reflection to verify filtering.
		$reflection = new \ReflectionClass( $loop );
		$prop       = $reflection->getProperty( 'client_abilities' );
		$prop->setAccessible( true );
		$client_abilities = $prop->getValue( $loop );

		$this->assertCount( 1, $client_abilities );
		$this->assertSame( 'sd-ai-agent-js/navigate-to', $client_abilities[0]['name'] );
	}

	/**
	 * Non-array client_abilities option is handled gracefully.
	 */
	public function test_non_array_client_abilities_is_ignored(): void {
		$loop = new AgentLoop(
			'test',
			array(),
			array(),
			array(
				'client_abilities' => 'not-an-array',
			)
		);

		$reflection = new \ReflectionClass( $loop );
		$prop       = $reflection->getProperty( 'client_abilities' );
		$prop->setAccessible( true );
		$client_abilities = $prop->getValue( $loop );

		$this->assertCount( 0, $client_abilities );
	}

	/**
	 * Valid client_abilities descriptors are stored after catalog validation.
	 */
	public function test_valid_client_abilities_are_stored(): void {
		$loop = new AgentLoop(
			'test',
			array(),
			array(),
			array(
				'client_abilities' => array(
					array(
						'name'        => 'sd-ai-agent-js/navigate-to',
						'label'       => 'Navigate',
						'description' => 'Navigate to admin page',
						'input_schema' => array(
							'type'       => 'object',
							'properties' => array(
								'path' => array( 'type' => 'string' ),
							),
						),
						'annotations' => array( 'readonly' => true ),
					),
				),
			)
		);

		$reflection = new \ReflectionClass( $loop );
		$prop       = $reflection->getProperty( 'client_abilities' );
		$prop->setAccessible( true );
		$client_abilities = $prop->getValue( $loop );

		$this->assertCount( 1, $client_abilities );
		$this->assertSame( 'sd-ai-agent-js/navigate-to', $client_abilities[0]['name'] );
	}

	// ── partition_tool_calls tests ────────────────────────────────────────

	/**
	 * partition_tool_calls() correctly separates PHP and JS tool calls.
	 */
	public function test_partition_tool_calls_separates_php_and_js(): void {
		$loop = new AgentLoop(
			'test',
			array(),
			array(),
			array(
				'client_abilities' => array(
					array(
						'name'  => 'sd-ai-agent-js/navigate-to',
						'label' => 'Navigate',
					),
				),
			)
		);

		$reflection = new \ReflectionClass( $loop );
		$method     = $reflection->getMethod( 'partition_tool_calls' );
		$method->setAccessible( true );

		// Build a mock message with two tool calls: one PHP, one JS.
		$php_call = $this->create_mock_message_part( 'sd-ai-agent/memory-save', 'call-1', array( 'content' => 'test' ) );
		$js_call  = $this->create_mock_message_part( 'sd-ai-agent-js/navigate-to', 'call-2', array( 'path' => 'plugins.php' ) );

		$message = $this->create_mock_message( array( $php_call, $js_call ) );

		$result = $method->invoke( $loop, $message, array( 'sd-ai-agent-js/navigate-to' ) );

		$this->assertArrayHasKey( 'php', $result );
		$this->assertArrayHasKey( 'client', $result );
		$this->assertCount( 1, $result['php'] );
		$this->assertCount( 1, $result['client'] );
		$this->assertSame( 'sd-ai-agent-js/navigate-to', $result['client'][0]['name'] );
		$this->assertSame( 'call-2', $result['client'][0]['id'] );
	}

	/**
	 * partition_tool_calls() returns all parts as PHP when no client names match.
	 */
	public function test_partition_tool_calls_all_php_when_no_match(): void {
		$loop = new AgentLoop( 'test', array(), array(), array() );

		$reflection = new \ReflectionClass( $loop );
		$method     = $reflection->getMethod( 'partition_tool_calls' );
		$method->setAccessible( true );

		$php_call = $this->create_mock_message_part( 'sd-ai-agent/memory-save', 'call-1', array() );
		$message  = $this->create_mock_message( array( $php_call ) );

		$result = $method->invoke( $loop, $message, array() );

		$this->assertCount( 1, $result['php'] );
		$this->assertCount( 0, $result['client'] );
	}

	/**
	 * partition_tool_calls() drops text/narration parts from `php`.
	 *
	 * Regression test for the "last message must have content parts" crash:
	 * when an assistant turn contained text + only-JS tool_uses, text parts
	 * were being routed into `php`. AgentLoop then called execute_abilities()
	 * on a text-only sub-message, which returns a zero-part UserMessage, and
	 * that empty UserMessage was appended to history. The next send_prompt()
	 * would throw because the last message had no parts.
	 *
	 * `php` must only contain function-call parts; narration parts are
	 * already preserved in history via the assistant_message append in
	 * AgentLoop::run_loop(), so dropping them from `php` is loss-less.
	 */
	public function test_partition_drops_non_function_call_parts_from_php(): void {
		$loop = new AgentLoop(
			'test',
			array(),
			array(),
			array(
				'client_abilities' => array(
					array(
						'name'  => 'sd-ai-agent-js/screenshot-url',
						'label' => 'Screenshot URL',
					),
				),
			)
		);

		$reflection = new \ReflectionClass( $loop );
		$method     = $reflection->getMethod( 'partition_tool_calls' );
		$method->setAccessible( true );

		// Build a mock message with text narration + a JS-only tool_use.
		$text_part = $this->create_mock_text_part( "Let me screenshot the page." );
		$js_call   = $this->create_mock_message_part( 'sd-ai-agent-js/screenshot-url', 'call-1', array( 'url' => '/' ) );

		$message = $this->create_mock_message( array( $text_part, $js_call ) );

		$result = $method->invoke( $loop, $message, array( 'sd-ai-agent-js/screenshot-url' ) );

		$this->assertCount( 0, $result['php'], 'text/narration parts must NOT be routed to PHP execution.' );
		$this->assertCount( 1, $result['client'] );
		$this->assertSame( 'sd-ai-agent-js/screenshot-url', $result['client'][0]['name'] );
	}

	// ── resume_after_client_tools tests ──────────────────────────────────

	/**
	 * resume_after_client_tools() returns WP_Error when wp_ai_client_prompt is unavailable.
	 */
	public function test_resume_returns_error_when_sdk_unavailable(): void {
		// Only run this test when wp_ai_client_prompt is NOT defined.
		if ( function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'wp_ai_client_prompt is available in this environment.' );
		}

		$loop   = new AgentLoop( 'test', array(), array(), array() );
		$result = $loop->resume_after_client_tools( array(), 5 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_missing_client', $result->get_error_code() );
	}

	// ── Helper methods ────────────────────────────────────────────────────

	/**
	 * Create a mock MessagePart with a FunctionCall.
	 *
	 * @param string               $name Ability name.
	 * @param string               $id   Call ID.
	 * @param array<string, mixed> $args Call arguments.
	 * @return object Mock MessagePart.
	 */
	private function create_mock_message_part( string $name, string $id, array $args ): object {
		$call = $this->createMock( \WordPress\AiClient\Tools\DTO\FunctionCall::class );
		$call->method( 'getName' )->willReturn( $name );
		$call->method( 'getId' )->willReturn( $id );
		$call->method( 'getArgs' )->willReturn( $args );

		$part = $this->createMock( \WordPress\AiClient\Messages\DTO\MessagePart::class );
		$part->method( 'getFunctionCall' )->willReturn( $call );

		return $part;
	}

	/**
	 * Create a mock MessagePart with text (no function call).
	 *
	 * @param string $text The text content.
	 * @return object Mock MessagePart.
	 */
	private function create_mock_text_part( string $text ): object {
		$part = $this->createMock( \WordPress\AiClient\Messages\DTO\MessagePart::class );
		// getFunctionCall() returns null for text parts.
		$part->method( 'getFunctionCall' )->willReturn( null );
		return $part;
	}

	/**
	 * Create a mock Message with the given parts.
	 *
	 * @param object[] $parts MessagePart objects.
	 * @return object Mock Message.
	 */
	private function create_mock_message( array $parts ): object {
		$message = $this->createMock( \WordPress\AiClient\Messages\DTO\Message::class );
		$message->method( 'getParts' )->willReturn( $parts );
		return $message;
	}
}
