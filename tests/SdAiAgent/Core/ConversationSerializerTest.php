<?php
/**
 * Test case for ConversationSerializer class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\ConversationSerializer;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use WP_UnitTestCase;

/**
 * Test ConversationSerializer functionality.
 *
 * Focuses on append_assistant_message — the splitter that protects the
 * OpenAI Responses API contract (each `function_call` must be its own
 * top-level input item; assistant messages containing function_call plus
 * any other part are rejected by the official `ai-provider-for-openai`
 * plugin's `validateMessages()` before any HTTP request is sent).
 *
 * @group ai-client
 */
class ConversationSerializerTest extends WP_UnitTestCase {

	/**
	 * Skip the test if the AI Client SDK isn't autoloaded in this environment.
	 */
	private function skip_if_sdk_unavailable(): void {
		if ( ! class_exists( ModelMessage::class ) ) {
			$this->markTestSkipped( 'AI Client SDK not available.' );
		}
	}

	/**
	 * Build a real MessagePart wrapping a FunctionCall (no mocks — the
	 * splitter dispatches on `getType()->isFunctionCall()` which only works
	 * on real MessagePart instances).
	 */
	private function function_call_part( string $id, string $name, array $args = [] ): MessagePart {
		return new MessagePart( new FunctionCall( $id, $name, $args ) );
	}

	/**
	 * Build a real MessagePart wrapping plain text.
	 */
	private function text_part( string $text ): MessagePart {
		return new MessagePart( $text );
	}

	/**
	 * Assert that every assistant Message in $history is compliant with the
	 * OpenAI Responses API `validateMessages` rule: no Message may contain
	 * more than one part if any of those parts is a function_call (the rule
	 * applies symmetrically to function_response, but the splitter only
	 * concerns the assistant side).
	 *
	 * @param Message[] $history
	 */
	private function assertHistoryPassesOpenAiValidator( array $history ): void {
		foreach ( $history as $i => $message ) {
			$parts        = $message->getParts();
			$has_func_call = false;
			foreach ( $parts as $part ) {
				if ( $part->getType()->isFunctionCall() ) {
					$has_func_call = true;
					break;
				}
			}
			if ( $has_func_call && count( $parts ) > 1 ) {
				$this->fail(
					sprintf(
						'history[%d] would trigger OpenAI Responses API validator: %d parts, contains function_call',
						$i,
						count( $parts )
					)
				);
			}
		}
		$this->assertTrue( true );
	}

	/**
	 * Single-part assistant message must be appended verbatim.
	 */
	public function test_append_assistant_message_single_text_part(): void {
		$this->skip_if_sdk_unavailable();

		$message = new ModelMessage( [ $this->text_part( 'Hello' ) ] );
		$history = [];

		ConversationSerializer::append_assistant_message( $history, $message );

		$this->assertCount( 1, $history );
		$this->assertSame( $message, $history[0] );
		$this->assertHistoryPassesOpenAiValidator( $history );
	}

	/**
	 * Single-part function_call message must be appended verbatim
	 * (it's already compliant — one part, one function_call).
	 */
	public function test_append_assistant_message_single_function_call(): void {
		$this->skip_if_sdk_unavailable();

		$message = new ModelMessage(
			[ $this->function_call_part( 'call_1', 'wpab__sd-ai-agent__skill-load', [ 'slug' => 'foo' ] ) ]
		);
		$history = [];

		ConversationSerializer::append_assistant_message( $history, $message );

		$this->assertCount( 1, $history );
		$this->assertSame( $message, $history[0] );
		$this->assertHistoryPassesOpenAiValidator( $history );
	}

	/**
	 * Multi-part text-only assistant message must be appended verbatim
	 * (no function_call → no violation possible).
	 */
	public function test_append_assistant_message_multi_text_no_function_call(): void {
		$this->skip_if_sdk_unavailable();

		$message = new ModelMessage(
			[ $this->text_part( 'part one' ), $this->text_part( 'part two' ) ]
		);
		$history = [];

		ConversationSerializer::append_assistant_message( $history, $message );

		$this->assertCount( 1, $history );
		$this->assertSame( $message, $history[0] );
		$this->assertHistoryPassesOpenAiValidator( $history );
	}

	/**
	 * Regression: trace 3571 msg[1] — Anthropic parallel tool_use → two
	 * function_calls in one assistant message. Must split into two
	 * ModelMessages, one per function_call.
	 */
	public function test_append_assistant_message_parallel_function_calls_no_text(): void {
		$this->skip_if_sdk_unavailable();

		$message = new ModelMessage(
			[
				$this->function_call_part( 'toolu_a', 'wpab__sd-ai-agent__skill-load', [ 'slug' => 'site-specification' ] ),
				$this->function_call_part( 'toolu_b', 'wpab__sd-ai-agent__skill-load', [ 'slug' => 'block-themes' ] ),
			]
		);
		$history = [];

		ConversationSerializer::append_assistant_message( $history, $message );

		$this->assertCount( 2, $history );
		foreach ( $history as $i => $msg ) {
			$this->assertInstanceOf( Message::class, $msg );
			$this->assertTrue( $msg->getRole()->isModel(), "history[$i] role must be model" );
			$this->assertCount( 1, $msg->getParts(), "history[$i] must have exactly one part" );
		}
		$this->assertSame( 'toolu_a', $history[0]->getParts()[0]->getFunctionCall()->getId() );
		$this->assertSame( 'toolu_b', $history[1]->getParts()[0]->getFunctionCall()->getId() );
		$this->assertHistoryPassesOpenAiValidator( $history );
	}

	/**
	 * Regression: trace 3571 msg[44] — assistant text plus a single
	 * function_call. Must split into one text-only ModelMessage followed
	 * by one function_call-only ModelMessage.
	 */
	public function test_append_assistant_message_text_then_function_call(): void {
		$this->skip_if_sdk_unavailable();

		$message = new ModelMessage(
			[
				$this->text_part( 'Excellent choice! Loading the next skill.' ),
				$this->function_call_part( 'call_14', 'wpab__sd-ai-agent__skill-load', [ 'slug' => 'design-system' ] ),
			]
		);
		$history = [];

		ConversationSerializer::append_assistant_message( $history, $message );

		$this->assertCount( 2, $history );
		// First message: text only.
		$this->assertCount( 1, $history[0]->getParts() );
		$this->assertTrue( $history[0]->getParts()[0]->getType()->isText() );
		$this->assertSame(
			'Excellent choice! Loading the next skill.',
			$history[0]->getParts()[0]->getText()
		);
		// Second message: function_call only.
		$this->assertCount( 1, $history[1]->getParts() );
		$this->assertTrue( $history[1]->getParts()[0]->getType()->isFunctionCall() );
		$this->assertSame( 'call_14', $history[1]->getParts()[0]->getFunctionCall()->getId() );
		$this->assertHistoryPassesOpenAiValidator( $history );
	}

	/**
	 * Regression: trace 3571 msg[46] — text + 3 parallel function_calls.
	 * Must split into one text message + 3 function_call messages, in order.
	 */
	public function test_append_assistant_message_text_plus_three_parallel_calls(): void {
		$this->skip_if_sdk_unavailable();

		$message = new ModelMessage(
			[
				$this->text_part( "I'll write three design previews now." ),
				$this->function_call_part( 'call_15', 'wpab__sd-ai-agent__file-write', [ 'path' => 'design-1.html' ] ),
				$this->function_call_part( 'call_16', 'wpab__sd-ai-agent__file-write', [ 'path' => 'design-2.html' ] ),
				$this->function_call_part( 'call_17', 'wpab__sd-ai-agent__file-write', [ 'path' => 'design-3.html' ] ),
			]
		);
		$history = [];

		ConversationSerializer::append_assistant_message( $history, $message );

		$this->assertCount( 4, $history );
		$this->assertTrue( $history[0]->getParts()[0]->getType()->isText() );
		$this->assertSame( 'call_15', $history[1]->getParts()[0]->getFunctionCall()->getId() );
		$this->assertSame( 'call_16', $history[2]->getParts()[0]->getFunctionCall()->getId() );
		$this->assertSame( 'call_17', $history[3]->getParts()[0]->getFunctionCall()->getId() );
		$this->assertHistoryPassesOpenAiValidator( $history );
	}

	/**
	 * Regression: trace 3571 msg[54] — text + 2 parallel ability-calls.
	 */
	public function test_append_assistant_message_text_plus_two_parallel_calls(): void {
		$this->skip_if_sdk_unavailable();

		$message = new ModelMessage(
			[
				$this->text_part( 'Building the theme now.' ),
				$this->function_call_part( 'call_19', 'wpab__sd-ai-agent__ability-call', [ 'ability' => 'sd-ai-agent/scaffold-block-theme' ] ),
				$this->function_call_part( 'call_20', 'wpab__sd-ai-agent__ability-call', [ 'ability' => 'sd-ai-agent/get-theme-json' ] ),
			]
		);
		$history = [];

		ConversationSerializer::append_assistant_message( $history, $message );

		$this->assertCount( 3, $history );
		$this->assertTrue( $history[0]->getParts()[0]->getType()->isText() );
		$this->assertTrue( $history[1]->getParts()[0]->getType()->isFunctionCall() );
		$this->assertTrue( $history[2]->getParts()[0]->getType()->isFunctionCall() );
		$this->assertHistoryPassesOpenAiValidator( $history );
	}

	/**
	 * Empty parts must be silently dropped (matches existing
	 * append_tool_response contract — never append zero-parts messages).
	 */
	public function test_append_assistant_message_drops_empty(): void {
		$this->skip_if_sdk_unavailable();

		$message = new ModelMessage( [] );
		$history = [];

		ConversationSerializer::append_assistant_message( $history, $message );

		$this->assertSame( [], $history );
	}

	/**
	 * Existing history must be preserved — splitter appends, never replaces.
	 */
	public function test_append_assistant_message_preserves_existing_history(): void {
		$this->skip_if_sdk_unavailable();

		$existing_user = new UserMessage( [ $this->text_part( 'prior user' ) ] );
		$history       = [ $existing_user ];

		$message = new ModelMessage(
			[
				$this->text_part( 'reply' ),
				$this->function_call_part( 'c1', 'tool', [] ),
			]
		);

		ConversationSerializer::append_assistant_message( $history, $message );

		$this->assertCount( 3, $history );
		$this->assertSame( $existing_user, $history[0] );
		$this->assertHistoryPassesOpenAiValidator( $history );
	}

	/**
	 * Sanity: the existing append_tool_response splitter still works for
	 * the mirror case (multi-part function_response in a UserMessage). This
	 * guards against accidental coupling between the two helpers.
	 */
	public function test_append_tool_response_still_splits_multi_part(): void {
		$this->skip_if_sdk_unavailable();

		$message = new UserMessage(
			[
				new MessagePart( new FunctionResponse( 'c1', 'tool', '{"ok":true}' ) ),
				new MessagePart( new FunctionResponse( 'c2', 'tool', '{"ok":true}' ) ),
			]
		);
		$history = [];

		ConversationSerializer::append_tool_response( $history, $message );

		$this->assertCount( 2, $history );
		foreach ( $history as $msg ) {
			$this->assertCount( 1, $msg->getParts() );
			$this->assertNotNull( $msg->getParts()[0]->getFunctionResponse() );
		}
	}
}
