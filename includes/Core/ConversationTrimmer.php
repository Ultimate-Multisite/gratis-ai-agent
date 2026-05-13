<?php

declare(strict_types=1);
/**
 * Smart Conversation Trimmer.
 *
 * Trims conversation history at safe boundaries to prevent context overflow.
 * Never cuts mid-tool-cycle (assistant tool call + tool response are kept together).
 * Always trims before a user message boundary.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;

class ConversationTrimmer {

	/**
	 * Default max history turns (a turn = one user message + one assistant response).
	 */
	const DEFAULT_MAX_TURNS = 20;

	/**
	 * Trim conversation history if it exceeds the configured max turns.
	 *
	 * A "turn" is counted as a user message followed by any number of assistant
	 * messages, tool calls, and tool responses until the next user message.
	 *
	 * The first user message is always preserved (it may contain crucial context).
	 * When trimming, we keep a summary placeholder to indicate content was removed.
	 *
	 * @param Message[] $history   The full conversation history.
	 * @param int       $max_turns Maximum turns to keep. 0 = no trimming.
	 * @return array<Message|UserMessage>
	 */
	public static function trim( array $history, int $max_turns = 0 ): array {
		if ( $max_turns <= 0 ) {
			// @phpstan-ignore-next-line
			$max_turns = (int) Settings::instance()->get( 'max_history_turns' );
		}

		if ( $max_turns <= 0 ) {
			return $history;
		}

		// Find turn boundaries (indices where user messages start).
		$turn_starts = self::find_turn_boundaries( $history );

		// If within limits, no trimming needed.
		if ( count( $turn_starts ) <= $max_turns ) {
			return $history;
		}

		// How many turns to remove from the front (keep last $max_turns).
		// Always keep the first turn (index 0) for context.
		$total_turns = count( $turn_starts );
		$keep_from   = $total_turns - $max_turns;

		// Clamp — always keep at least the first turn.
		if ( $keep_from <= 1 ) {
			return $history;
		}

		// Get the index in $history where we start keeping.
		$cut_at = $turn_starts[ $keep_from ];

		// Build trimmed history:
		// 1. Keep the first turn (messages from index 0 to turn_starts[1]-1).
		// 2. Insert a trimming marker.
		// 3. Keep everything from $cut_at onwards.
		$first_turn_end = isset( $turn_starts[1] ) ? $turn_starts[1] : count( $history );
		$first_turn     = array_slice( $history, 0, $first_turn_end );
		$kept_history   = array_slice( $history, $cut_at );

		// Create a summary marker message.
		$removed_turns = $keep_from - 1; // Minus the first turn we're keeping.
		$marker        = new UserMessage(
			[
				new MessagePart(
					sprintf(
						'[%d earlier conversation turns were trimmed to save context. The conversation continues below.]',
						$removed_turns
					)
				),
			]
		);

		$merged = array_merge( $first_turn, [ $marker ], $kept_history );

		// Safety net: validate tool_use/tool_result pairing after trimming.
		// Even with correct boundary detection, edge cases (serialization
		// round-trips, history corruption) could leave orphaned tool calls.
		return self::validate_tool_pairs( $merged );
	}

	/**
	 * Validate and repair tool_use/tool_result pairing in conversation history.
	 *
	 * Two-pass scrub to satisfy the Anthropic API invariant that every
	 * tool_result has a matching tool_use earlier in the same request:
	 *
	 *   Pass 1 — forward: drop assistant messages whose FunctionCall parts
	 *   do not all have matching FunctionResponse messages immediately after
	 *   them. Also drops the partial response cluster.
	 *
	 *   Pass 2 — orphan tool_result scrub: walk the post-pass-1 history and
	 *   drop any FunctionResponse whose tool_use_id is not present in the
	 *   kept history. This catches the mirror case of pass 1 (orphan
	 *   tool_results with no preceding tool_use) which can arise when
	 *   trimming, serialization round-trips, or interrupt injection severs
	 *   a tool_use from its tool_result. Without this pass, Anthropic
	 *   returns: "messages.N.content.M: unexpected `tool_use_id` found in
	 *   `tool_result` blocks".
	 *
	 * Messages reduced to zero parts after stripping are removed entirely;
	 * mixed-content messages keep their non-orphan parts.
	 *
	 * @param Message[] $history The conversation history to validate.
	 * @return Message[] The validated history with orphaned tool cycles removed.
	 */
	public static function validate_tool_pairs( array $history ): array {
		$result = [];
		$count  = count( $history );
		$i      = 0;

		while ( $i < $count ) {
			$message = $history[ $i ];

			// Check if this is an assistant message with tool calls.
			$tool_call_ids = self::extract_tool_call_ids( $message );

			if ( empty( $tool_call_ids ) ) {
				// Not a tool-call message — keep it.
				$result[] = $message;
				++$i;
				continue;
			}

			// Collect the tool-response messages that follow.
			$response_ids   = [];
			$response_start = $i + 1;
			$response_end   = $response_start;

			while ( $response_end < $count ) {
				$next = $history[ $response_end ];
				if ( self::is_tool_response_message( $next ) ) {
					foreach ( self::extract_tool_response_ids( $next ) as $rid ) {
						$response_ids[] = $rid;
					}
					++$response_end;
				} else {
					break;
				}
			}

			// Check if ALL tool_call IDs have matching responses.
			$missing = array_diff( $tool_call_ids, $response_ids );

			if ( empty( $missing ) ) {
				// All tool calls have responses — keep the entire cycle.
				$result[] = $message;
				for ( $j = $response_start; $j < $response_end; $j++ ) {
					$result[] = $history[ $j ];
				}
			}
			// else: orphaned tool calls — skip the entire cycle (assistant
			// message + any partial responses) to prevent the API error.

			$i = $response_end;
		}

		return self::strip_orphan_tool_responses( $result );
	}

	/**
	 * Strip FunctionResponse parts whose tool_use_id has no matching tool_use.
	 *
	 * Pass 2 of validate_tool_pairs(). Builds the set of valid tool_use IDs
	 * (FunctionCall IDs from earlier messages in the history) and drops any
	 * FunctionResponse part whose ID is not in that set.
	 *
	 * Behaviour:
	 *  - A pure tool-response message (all parts are FunctionResponse) whose
	 *    parts are all orphans is dropped entirely.
	 *  - A mixed-content user message (e.g. text + orphan FunctionResponse)
	 *    is rebuilt with only the non-orphan parts. If the remaining parts
	 *    include at least one non-FunctionResponse part, the rebuilt
	 *    UserMessage is kept; otherwise it is dropped.
	 *  - Non-tool messages are passed through unchanged.
	 *
	 * @param Message[] $history The history after pass 1.
	 * @return Message[] History with orphan tool_results removed.
	 */
	private static function strip_orphan_tool_responses( array $history ): array {
		$valid_tool_use_ids = [];
		foreach ( $history as $message ) {
			foreach ( self::extract_tool_call_ids( $message ) as $cid ) {
				$valid_tool_use_ids[ $cid ] = true;
			}
		}

		$cleaned = [];
		foreach ( $history as $message ) {
			$parts          = $message->getParts();
			$has_response   = false;
			$has_orphan     = false;
			$retained_parts = [];

			foreach ( $parts as $part ) {
				$fr = method_exists( $part, 'getFunctionResponse' ) ? $part->getFunctionResponse() : null;
				if ( $fr ) {
					$has_response = true;
					$fr_id        = (string) $fr->getId();
					if ( ! isset( $valid_tool_use_ids[ $fr_id ] ) ) {
						$has_orphan = true;
						continue;
					}
				}
				$retained_parts[] = $part;
			}

			if ( ! $has_response || ! $has_orphan ) {
				// Nothing to strip — pass through unchanged.
				$cleaned[] = $message;
				continue;
			}

			if ( empty( $retained_parts ) ) {
				// All parts were orphan tool_results — drop the message.
				continue;
			}

			// Rebuild as a UserMessage with the retained parts. Tool-response
			// messages are always UserMessage-roled per the SDK contract.
			$cleaned[] = new UserMessage( $retained_parts );
		}

		return $cleaned;
	}

	/**
	 * Extract FunctionCall IDs from a message.
	 *
	 * @param Message $message The message to inspect.
	 * @return string[] Array of tool call IDs.
	 */
	private static function extract_tool_call_ids( Message $message ): array {
		$ids = [];
		foreach ( $message->getParts() as $part ) {
			if ( method_exists( $part, 'getFunctionCall' ) ) {
				$fc = $part->getFunctionCall();
				if ( $fc ) {
					$ids[] = (string) $fc->getId();
				}
			}
		}
		return $ids;
	}

	/**
	 * Extract FunctionResponse IDs from a message.
	 *
	 * @param Message $message The message to inspect.
	 * @return string[] Array of tool response IDs.
	 */
	private static function extract_tool_response_ids( Message $message ): array {
		$ids = [];
		foreach ( $message->getParts() as $part ) {
			if ( method_exists( $part, 'getFunctionResponse' ) ) {
				$fr = $part->getFunctionResponse();
				if ( $fr ) {
					$ids[] = (string) $fr->getId();
				}
			}
		}
		return $ids;
	}

	/**
	 * Find indices in the history array where user messages start a new turn.
	 *
	 * Tool-response messages (UserMessage containing FunctionResponse parts)
	 * are NOT turn boundaries — they are part of a tool-call cycle that must
	 * stay paired with the preceding assistant message. Only genuine user
	 * text messages count as turn boundaries.
	 *
	 * @param Message[] $history Conversation history.
	 * @return int[] Array of indices.
	 */
	private static function find_turn_boundaries( array $history ): array {
		$boundaries = [];

		foreach ( $history as $i => $message ) {
			try {
				$role     = $message->getRole();
				$role_str = '';

				if ( method_exists( $role, 'value' ) ) {
					$role_str = $role->value;
				} elseif ( method_exists( $role, 'getValue' ) ) {
					$role_str = $role->getValue();
				} else {
					$role_str = (string) $role;
				}

				if ( 'user' !== $role_str ) {
					continue;
				}

				// Skip tool-response messages — they contain FunctionResponse
				// parts and must stay paired with the preceding tool_use.
				if ( self::is_tool_response_message( $message ) ) {
					continue;
				}

				$boundaries[] = $i;
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		return $boundaries;
	}

	/**
	 * Check whether a message is a tool-response (contains FunctionResponse parts).
	 *
	 * Tool-response messages are UserMessage objects with FunctionResponse parts
	 * created by ConversationSerializer::append_tool_response(). They look like
	 * user messages by role but are actually tool results that must stay paired
	 * with their preceding assistant tool_use message.
	 *
	 * @param Message $message The message to check.
	 * @return bool True if the message contains any FunctionResponse parts.
	 */
	private static function is_tool_response_message( Message $message ): bool {
		foreach ( $message->getParts() as $part ) {
			if ( method_exists( $part, 'getFunctionResponse' ) && $part->getFunctionResponse() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Estimate the token count of a message (rough heuristic).
	 *
	 * Uses a simple word-based approximation (1 token ~= 0.75 words).
	 * For more accurate counts, the actual tokenizer would be needed.
	 *
	 * @param Message $message A conversation message.
	 * @return int Estimated token count.
	 */
	public static function estimate_tokens( Message $message ): int {
		$text = '';

		try {
			foreach ( $message->getParts() as $part ) {
				if ( method_exists( $part, 'getText' ) ) {
					$text .= $part->getText() . ' ';
				}
				if ( method_exists( $part, 'getFunctionCall' ) ) {
					$fc = $part->getFunctionCall();
					if ( $fc ) {
						$text .= wp_json_encode( $fc->getArgs() ) . ' ';
					}
				}
				if ( method_exists( $part, 'getFunctionResponse' ) ) {
					$fr = $part->getFunctionResponse();
					if ( $fr ) {
						$text .= wp_json_encode( $fr->getResponse() ) . ' ';
					}
				}
			}
		} catch ( \Throwable $e ) {
			// Best effort.
		}

		// Rough estimate: 1 token ~= 4 characters.
		return (int) ceil( strlen( $text ) / 4 );
	}

	/**
	 * Estimate total tokens in a history array.
	 *
	 * @param Message[] $history Conversation history.
	 * @return int
	 */
	public static function estimate_total_tokens( array $history ): int {
		$total = 0;
		foreach ( $history as $message ) {
			$total += self::estimate_tokens( $message );
		}
		return $total;
	}
}
