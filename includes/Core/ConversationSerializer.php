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
use WordPress\AiClient\Messages\DTO\ModelMessage;
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
	 * Append an assistant (model-role) message to history, splitting multi-part
	 * messages that contain function_call parts into one ModelMessage per
	 * function_call (plus, if any non-function_call parts are present, a
	 * leading ModelMessage that carries those text/reasoning parts).
	 *
	 * The OpenAI Responses API contract — enforced client-side by
	 * `OpenAiTextGenerationModel::validateMessages()` in the
	 * `ai-provider-for-openai` plugin — requires each `function_call` to be its
	 * own top-level input item. A single Message containing two function_calls,
	 * or text+function_call, cannot be serialised into one item and the
	 * validator throws `"Function call parts must be the only part in a
	 * message for the OpenAI Responses API."` before any HTTP request is sent.
	 *
	 * Multi-part assistant messages legitimately arise when:
	 *   - Anthropic Claude emits parallel `tool_use` blocks in one assistant
	 *     message (the SDK's `Message` preserves that shape).
	 *   - An OpenAI Responses model returns text plus one or more function_calls
	 *     in the same `message` output item (or PR #26's reasoning support
	 *     prepends a thought-channel part to a function_call candidate).
	 *
	 * Anthropic accepts the split form too (each `Message` becomes one
	 * Anthropic `message` with N content blocks → splitting only changes how
	 * many messages get sent, not the semantics), so this transformation is
	 * provider-agnostic.
	 *
	 * Mirrors {@see self::append_tool_response()}, which already solves the
	 * equivalent problem on the tool-response side.
	 *
	 * @param Message[] $history The conversation history (passed by reference).
	 * @param Message   $message Assistant message returned by the model.
	 */
	public static function append_assistant_message( array &$history, Message $message ): void {
		$parts = $message->getParts();

		// Defensive: never append a zero-parts message. The SDK's
		// PromptBuilder::validateMessages() throws when it sees one, so
		// silently dropping it here is preferable to a downstream exception
		// that bubbles up as a bare error to the user.
		if ( empty( $parts ) ) {
			return;
		}

		// `MessagePartTypeEnum::isFunctionCall()` is a magic method dispatched
		// via AbstractEnum's `__call`, so `method_exists()` would return false.
		// Fall back to the legacy `getFunctionCall()` accessor when the
		// MessagePart implementation is older or mocked without the enum API.
		$function_call_parts = array();
		$other_parts         = array();
		foreach ( $parts as $part ) {
			$is_function_call = false;
			if ( method_exists( $part, 'getType' ) ) {
				$type = $part->getType();
				if ( is_object( $type ) && is_callable( array( $type, 'isFunctionCall' ) ) ) {
					$is_function_call = (bool) $type->isFunctionCall();
				}
			}
			if ( ! $is_function_call && method_exists( $part, 'getFunctionCall' ) ) {
				$is_function_call = null !== $part->getFunctionCall();
			}
			if ( $is_function_call ) {
				$function_call_parts[] = $part;
			} else {
				$other_parts[] = $part;
			}
		}

		// Fast path: ≤1 part total, or zero function_call parts, or exactly
		// one function_call and no other parts — already compliant.
		if ( count( $parts ) <= 1 || empty( $function_call_parts )
			|| ( 1 === count( $function_call_parts ) && empty( $other_parts ) ) ) {
			$history[] = $message;
			return;
		}

		// Preserve "text/reasoning first, then function_calls" ordering — this
		// is how the official OpenAI plugin's `convertMessageToInputItems`
		// would have emitted them across separate messages.
		if ( ! empty( $other_parts ) ) {
			$history[] = new ModelMessage( $other_parts );
		}

		foreach ( $function_call_parts as $fc_part ) {
			$history[] = new ModelMessage( array( $fc_part ) );
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
