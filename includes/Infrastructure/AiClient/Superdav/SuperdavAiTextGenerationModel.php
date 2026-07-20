<?php

declare(strict_types=1);

namespace SdAiAgent\Infrastructure\AiClient\Superdav;

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenAI-compatible text generation model for the Superdav AI service.
 */
final class SuperdavAiTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel {

	/**
	 * Prepare OpenAI-compatible chat messages for the Superdav managed edge.
	 *
	 * The upstream OpenAI-compatible SDK adapter parses response
	 * `reasoning_content` into thought-channel message parts, but it deliberately
	 * omits thought parts from later requests because OpenAI's public Chat
	 * Completions API has no matching input field. Superdav's managed endpoint
	 * does accept the DeepSeek-style `reasoning_content` sibling and needs that
	 * context round-tripped on later turns, so restore it after the parent adapter
	 * prepares the normal message content/tool-call shape.
	 *
	 * @param array       $messages           Conversation messages.
	 * @param string|null $system_instruction Optional system instruction.
	 * @return list<array<string, mixed>>
	 * @phpstan-param list<Message> $messages
	 */
	protected function prepareMessagesParam( array $messages, ?string $system_instruction = null ): array {
		$messages_param = parent::prepareMessagesParam( $messages, $system_instruction );
		$offset         = $system_instruction ? 1 : 0;

		foreach ( $messages as $index => $message ) {
			$reasoning_content = self::extract_reasoning_content( $message );
			if ( '' === $reasoning_content ) {
				continue;
			}

			$param_index = $index + $offset;
			if ( ! isset( $messages_param[ $param_index ] ) ) {
				continue;
			}

			if ( 'assistant' !== ( $messages_param[ $param_index ]['role'] ?? '' ) ) {
				continue;
			}

			$messages_param[ $param_index ]['reasoning_content'] = $reasoning_content;
		}

		return array_values( $messages_param );
	}

	/**
	 * Create an authenticated API request.
	 *
	 * @param HttpMethodEnum                     $method  HTTP method.
	 * @param string                             $path    Endpoint path.
	 * @param array<string, string|list<string>> $headers Request headers.
	 * @param string|array<string, mixed>|null   $data    Request body/query data.
	 * @return Request
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), mixed $data = null ): Request {
		if ( 'chat/completions' === trim( $path, '/' ) && is_array( $data ) && ! array_key_exists( 'reasoning_effort', $data ) ) {
			$model_id = isset( $data['model'] ) && is_string( $data['model'] ) ? $data['model'] : '';
			$effort   = SuperdavAiProvider::reasoning_effort_for_model( $model_id );
			if ( '' !== $effort ) {
				$data['reasoning_effort'] = $effort;
			}
		}

		return new Request( $method, SuperdavAiProvider::url( $path ), $headers, $data, $this->getRequestOptions() );
	}

	/**
	 * Extract thought-channel text that must be sent back as reasoning context.
	 *
	 * @param Message $message Message to inspect.
	 * @return string Newline-joined thought text, or an empty string when absent.
	 */
	private static function extract_reasoning_content( Message $message ): string {
		$reasoning_parts = array();

		foreach ( $message->getParts() as $part ) {
			if ( ! self::is_thought_text_part( $part ) ) {
				continue;
			}

			$text_value = $part->getText();
			if ( ! is_string( $text_value ) ) {
				continue;
			}

			$text = trim( $text_value );
			if ( '' !== $text ) {
				$reasoning_parts[] = $text;
			}
		}

		return implode( "\n", $reasoning_parts );
	}

	/**
	 * Determine whether a message part is hidden reasoning text.
	 *
	 * @param MessagePart $part Message part.
	 * @return bool
	 */
	private static function is_thought_text_part( MessagePart $part ): bool {
		$type = $part->getType();
		if ( ! $type->isText() ) {
			return false;
		}

		if ( ! method_exists( $part, 'getChannel' ) ) {
			return false;
		}

		$channel = $part->getChannel();
		if ( ! is_object( $channel ) || ! is_callable( array( $channel, 'isThought' ) ) ) {
			return false;
		}

		return (bool) $channel->isThought();
	}
}
