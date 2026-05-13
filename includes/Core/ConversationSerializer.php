<?php

declare(strict_types=1);
/**
 * Serializes and deserializes conversation history for the agent loop.
 *
 * Extracted from AgentLoop so the history-transport concern lives in one
 * focused class. Also handles tool-response appending and result truncation.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

class ConversationSerializer {

	/**
	 * Serialize conversation history to transportable arrays.
	 *
	 * @param Message[] $history The conversation history.
	 * @return list<array<string, mixed>>
	 */
	public static function serialize( array $history ): array {
		return array_values(
			array_map(
				static function ( Message $msg ): array {
					return $msg->toArray();
				},
				$history
			)
		);
	}

	/**
	 * Deserialize conversation history from arrays back to Message objects.
	 *
	 * @param list<array<string, mixed>> $data Serialized history arrays.
	 * @return list<Message>
	 */
	public static function deserialize( array $data ): array {
		$messages = array();
		foreach ( $data as $item ) {
			$messages[] = Message::fromArray( $item );
		}
		return $messages;
	}

	/**
	 * Append a tool-response message to history, splitting multi-part
	 * function-response messages into one UserMessage per part.
	 *
	 * Anthropic accepts a single user message containing N function_response
	 * parts; OpenAI-compatible providers (synthetic.new, Ollama, LM Studio,
	 * etc.) require one `tool` role message per `tool_call_id`. The SDK's
	 * OpenAI adapter only special-cases the single-part shape, so we split
	 * here for portability.
	 *
	 * @param Message[] $history The conversation history (passed by reference).
	 * @param Message   $message Tool-response message returned by the resolver.
	 */
	public static function append_tool_response( array &$history, Message $message ): void {
		$parts = $message->getParts();

		// Defensive: never append a zero-parts message. The SDK's
		// PromptBuilder::validateMessages() throws "The last message must
		// have content parts" when it sees one, so silently dropping it
		// here is preferable to a downstream exception that bubbles up
		// as a bare error to the user. The empty case can arise if an
		// upstream resolver was handed only non-function-call parts.
		if ( empty( $parts ) ) {
			return;
		}

		$has_function_response = false;
		foreach ( $parts as $part ) {
			$fr = method_exists( $part, 'getFunctionResponse' ) ? $part->getFunctionResponse() : null;
			if ( $fr ) {
				$has_function_response = true;
				break;
			}
		}

		if ( ! $has_function_response || count( $parts ) <= 1 ) {
			$history[] = $message;
			return;
		}

		foreach ( $parts as $part ) {
			$history[] = new UserMessage( array( $part ) );
		}
	}

	/**
	 * Truncate large tool results in a response message.
	 *
	 * @param Message $message The tool response message.
	 * @return Message A new message with truncated results.
	 */
	public static function truncate_tool_results( Message $message ): Message {
		$new_parts = array();
		$modified  = false;

		foreach ( $message->getParts() as $part ) {
			$fr = method_exists( $part, 'getFunctionResponse' ) ? $part->getFunctionResponse() : null;
			if ( ! $fr ) {
				$new_parts[] = $part;
				continue;
			}

			$original_result = $fr->getResponse();
			$tool_name       = (string) $fr->getName();
			$ability_name    = $tool_name;
			if ( str_starts_with( $tool_name, 'wpab__' ) && class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
				$ability_name = \WP_AI_Client_Ability_Function_Resolver::function_name_to_ability_name( $tool_name );
			}

			$truncated = ToolResultTruncator::truncate( $original_result, $ability_name );

			if ( $truncated !== $original_result ) {
				$modified    = true;
				$new_parts[] = new MessagePart(
					new FunctionResponse(
						(string) $fr->getId(),
						(string) $fr->getName(),
						$truncated
					)
				);
			} else {
				$new_parts[] = $part;
			}
		}

		if ( ! $modified ) {
			return $message;
		}

		return new UserMessage( $new_parts );
	}
}
