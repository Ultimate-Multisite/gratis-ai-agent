<?php

declare(strict_types=1);
/**
 * Lightweight result wrapper for direct OpenAI-compatible proxy calls.
 *
 * The AgentLoop expects a result object with toMessage(), toText(), and
 * getUsage(). When we call the OpenAI-compat proxy directly via wp_remote_post()
 * instead of through the WordPress AI SDK (to avoid a fatal autoloader conflict),
 * we wrap the raw response in this class so the loop can handle it uniformly.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;

class SimpleAiResult {

	/** @var string */
	private string $text;

	/** @var array<string, mixed> */
	private array $raw;

	/** @var Message|null */
	private ?Message $message = null;

	/**
	 * @param string               $text The text content from the response.
	 * @param array<string, mixed> $raw  The raw API response data.
	 */
	public function __construct( string $text, array $raw = [] ) {
		$this->text = $text;
		$this->raw  = $raw;
	}

	/**
	 * Get the text content of the response.
	 *
	 * @return string The response text.
	 */
	public function toText(): string {
		return $this->text;
	}

	/**
	 * Get the provider finish/stop reason from the raw response when available.
	 *
	 * @return string Finish reason or an empty string when unavailable.
	 */
	public function getFinishReason(): string {
		$choice = $this->raw['choices'][0] ?? [];
		if ( is_array( $choice ) ) {
			foreach ( [ 'finish_reason', 'stop_reason', 'finishReason' ] as $key ) {
				$reason = $choice[ $key ] ?? null;
				if ( is_string( $reason ) && '' !== $reason ) {
					return $reason;
				}
			}
		}

		foreach ( [ 'finish_reason', 'stop_reason', 'finishReason' ] as $key ) {
			$reason = $this->raw[ $key ] ?? null;
			if ( is_string( $reason ) && '' !== $reason ) {
				return $reason;
			}
		}

		return '';
	}

	/**
	 * Convert the response to a Message object for the conversation history.
	 *
	 * Parses OpenAI-format tool_calls from the raw response and creates
	 * appropriate MessagePart objects for text and function calls.
	 *
	 * @return Message The message representation.
	 */
	public function toMessage(): Message {
		if ( null === $this->message ) {
			$parts = [];

			// Parse OpenAI-format tool_calls from the raw response.
			// @phpstan-ignore-next-line
			$tool_calls = $this->raw['choices'][0]['message']['tool_calls'] ?? [];
			// Filter out empty tool_calls arrays (some models return []).
			if ( ! is_array( $tool_calls ) ) {
				$tool_calls = [];
			}
			$tool_calls = array_filter(
				$tool_calls,
				function ( $tc ) {
					return ! empty( $tc['function']['name'] );
				}
			);

			// If no structured tool_calls, try to parse text-based tool calls.
			// Some models (Kimi K2.5, DeepSeek V3.2 via Synthetic API) output
			// tool calls as text tokens instead of using the function calling API.
			if ( empty( $tool_calls ) && '' !== $this->text ) {
				$parsed = self::parse_text_tool_calls( $this->text );
				if ( ! empty( $parsed['tool_calls'] ) ) {
					$tool_calls = $parsed['tool_calls'];
					// Remove the tool call markup from the text content.
					$this->text = $parsed['clean_text'];
				}
			}

			// Add text part if present.
			if ( '' !== $this->text ) {
				$parts[] = new MessagePart( $this->text );
			}

			// @phpstan-ignore-next-line
			foreach ( $tool_calls as $tc ) {
				// @phpstan-ignore-next-line
				$fn_name = $tc['function']['name'] ?? '';
				// @phpstan-ignore-next-line
				$fn_id = $tc['id'] ?? $fn_name;
				// @phpstan-ignore-next-line
				$fn_args = $tc['function']['arguments'] ?? '{}';

				if ( is_string( $fn_args ) ) {
					$fn_args = json_decode( $fn_args, true ) ?: [];
				}

				// Empty list args [] are left as-is — WP core's execute() sees
				// empty($args)=true, passes null, and normalize_input() returns
				// the ability's 'default' value ([] for no-arg abilities).
				$parts[] = new MessagePart(
					// @phpstan-ignore-next-line
					new FunctionCall( $fn_id, $fn_name, $fn_args )
				);
			}

			// Fallback: if no parts at all, add empty text.
			if ( empty( $parts ) ) {
				$parts[] = new MessagePart( '' );
			}

			$this->message = new ModelMessage( $parts );
		}
		return $this->message;
	}

	/**
	 * Parse tool calls embedded as text tokens in the response content.
	 *
	 * Some models (Kimi K2.5, DeepSeek V3.2 via Synthetic API proxies) output
	 * tool calls as text instead of using the OpenAI function calling format.
	 *
	 * Supported formats:
	 *
	 * 1. Kimi/DeepSeek special tokens:
	 *    <|tool_calls_section_begin|> <|tool_call_begin|>
	 *    functions.namespace/tool-name:0
	 *    <|tool_call_argument_begin|> {"key": "value"} <|tool_call_end|>
	 *    <|tool_calls_section_end|>
	 *
	 * 2. JSON code blocks with tool/function schema:
	 *    ```json
	 *    {"tool": "namespace/tool-name", "args": {"key": "value"}}
	 *    ```
	 *
	 * @param string $text The response text to parse.
	 * @return array{tool_calls: list<array<string, mixed>>, clean_text: string}
	 */
	private static function parse_text_tool_calls( string $text ): array {
		$tool_calls = [];
		$clean_text = $text;

		// Pattern 1: Kimi/DeepSeek special token format.
		// Match: <|tool_calls_section_begin|> ... <|tool_calls_section_end|>
		if ( str_contains( $text, '<|tool_call' ) ) {
			// Extract individual tool calls.
			$pattern = '/<\|tool_call_begin\|>\s*(?:functions\.)?([^\s:]+)(?::\d+)?\s*<\|tool_call_argument_begin\|>\s*(\{[^}]*\})\s*<\|tool_call_end\|>/s';
			if ( preg_match_all( $pattern, $text, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $i => $match ) {
					$fn_name = trim( $match[1] );
					$fn_args = $match[2];

					// Convert ability name format (namespace/tool) to wpab__ format.
					$wpab_name = 'wpab__' . str_replace( '/', '__', $fn_name );

					$tool_calls[] = [
						'id'       => 'parsed_' . $i,
						'type'     => 'function',
						'function' => [
							'name'      => $wpab_name,
							'arguments' => $fn_args,
						],
					];
				}
			}

			// Remove the entire tool calls section from text.
			$clean_text = preg_replace( '/<\|tool_calls_section_begin\|>.*?<\|tool_calls_section_end\|>/s', '', $text );
			$clean_text = trim( $clean_text ?? '' );
		}

		// Pattern 2: JSON code blocks with tool/function schema.
		// Match: ```json\n{"tool": "...", "args": {...}}\n```
		if ( empty( $tool_calls ) && preg_match_all( '/```(?:json)?\s*\n?\s*(\{[^`]*?"(?:tool|function)"[^`]*?\})\s*\n?\s*```/s', $text, $matches ) ) {
			foreach ( $matches[1] as $i => $json_str ) {
				$parsed = json_decode( $json_str, true );
				if ( ! is_array( $parsed ) ) {
					continue;
				}

				$fn_name = $parsed['tool'] ?? $parsed['function'] ?? $parsed['name'] ?? '';
				$fn_args = $parsed['args'] ?? $parsed['arguments'] ?? $parsed['parameters'] ?? [];

				if ( empty( $fn_name ) ) {
					continue;
				}

				// Convert ability name format to wpab__ format.
				$wpab_name = 'wpab__' . str_replace( '/', '__', $fn_name );

				$tool_calls[] = [
					'id'       => 'parsed_json_' . $i,
					'type'     => 'function',
					'function' => [
						'name'      => $wpab_name,
						'arguments' => is_string( $fn_args ) ? $fn_args : wp_json_encode( $fn_args ),
					],
				];
			}

			if ( ! empty( $tool_calls ) ) {
				// Remove the matched code blocks from text.
				$clean_text = preg_replace( '/```(?:json)?\s*\n?\s*\{[^`]*?"(?:tool|function)"[^`]*?\}\s*\n?\s*```/s', '', $text );
				$clean_text = trim( $clean_text ?? '' );
			}
		}

		return [
			'tool_calls' => $tool_calls,
			'clean_text' => $clean_text,
		];
	}

	/**
	 * Get token usage information from the response.
	 *
	 * @return object|null Usage object with getPromptTokens() and getCompletionTokens() methods, or null.
	 */
	public function getUsage() {
		$usage = $this->raw['usage'] ?? null;
		if ( ! is_array( $usage ) ) {
			return null;
		}
		// @phpstan-ignore-next-line
		$prompt     = (int) ( $usage['prompt_tokens'] ?? 0 );
		$completion = (int) ( $usage['completion_tokens'] ?? 0 );
		return new class( $prompt, $completion ) {
			private int $prompt;
			private int $completion;

			/**
			 * @param int $prompt     Number of prompt tokens.
			 * @param int $completion Number of completion tokens.
			 */
			public function __construct( int $prompt, int $completion ) {
				$this->prompt     = $prompt;
				$this->completion = $completion;
			}

			/**
			 * Get the number of prompt (input) tokens used.
			 *
			 * @return int
			 */
			public function getPromptTokens(): int {
				return $this->prompt;
			}

			/**
			 * Get the number of completion (output) tokens used.
			 *
			 * @return int
			 */
			public function getCompletionTokens(): int {
				return $this->completion;
			}
		};
	}
}
