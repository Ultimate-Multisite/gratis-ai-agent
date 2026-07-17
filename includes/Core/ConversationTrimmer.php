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

	/** Default maximum serialized provider request body size (512 KiB). */
	const DEFAULT_MAX_REQUEST_BYTES = 524288;

	/** Default maximum estimated input tokens retained in conversation history. */
	const DEFAULT_MAX_REQUEST_TOKENS = 100000;

	/** Maximum history size copied into a deterministically compacted session. */
	const COMPACT_MAX_BYTES = 65536;

	/** Maximum estimated tokens copied into a deterministically compacted session. */
	const COMPACT_MAX_TOKENS = 16000;

	/** Marker inserted when older turns are removed by a request-size budget. */
	private const BUDGET_MARKER_TEXT = '[Earlier conversation turns were compacted to stay within the request safety budget.]';

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
	 * Trim history to byte and token budgets while keeping a contiguous turn suffix.
	 *
	 * Unlike the turn-count guard, this path does not permanently retain the first
	 * turn. Complete recent turns are kept newest-first until adding the next older
	 * turn would exceed either budget. Tool-call/result cycles remain inside their
	 * user-turn boundary and are validated again before returning.
	 *
	 * If the newest turn alone exceeds a budget, it is returned unchanged. Callers
	 * can then reject it locally with actionable guidance rather than silently drop
	 * the user's current request or dispatch the same oversized payload upstream.
	 *
	 * @param Message[] $history    Conversation history.
	 * @param int       $max_bytes  Maximum serialized history bytes. 0 = unlimited.
	 * @param int       $max_tokens Maximum estimated history tokens. 0 = unlimited.
	 * @return Message[] Budgeted history with valid tool pairs.
	 */
	public static function trim_to_budget( array $history, int $max_bytes, int $max_tokens = 0 ): array {
		$history = self::validate_tool_pairs( $history );

		if ( empty( $history ) || self::fits_budget( $history, $max_bytes, $max_tokens ) ) {
			return $history;
		}

		$turn_starts = self::find_turn_boundaries( $history );
		if ( empty( $turn_starts ) ) {
			return $history;
		}

		$turns      = array();
		$turn_count = count( $turn_starts );
		for ( $i = 0; $i < $turn_count; ++$i ) {
			$start   = $turn_starts[ $i ];
			$end     = $turn_starts[ $i + 1 ] ?? count( $history );
			$turns[] = array_slice( $history, $start, $end - $start );
		}

		$marker = new UserMessage(
			array(
				new MessagePart( self::BUDGET_MARKER_TEXT ),
			)
		);

		$last_index = count( $turns ) - 1;
		$kept       = $turns[ $last_index ];

		// The current turn is never discarded. An over-budget newest turn must be
		// rejected by the caller rather than converted into an unrelated marker.
		if ( ! self::fits_budget( $kept, $max_bytes, $max_tokens ) ) {
			return self::validate_tool_pairs( $kept );
		}

		for ( $i = $last_index - 1; $i >= 0; --$i ) {
			$candidate = array_merge( array( $marker ), $turns[ $i ], $kept );
			if ( ! self::fits_budget( $candidate, $max_bytes, $max_tokens ) ) {
				break;
			}

			$kept = array_merge( $turns[ $i ], $kept );
		}

		if ( count( $kept ) < count( $history ) ) {
			$marked = array_merge( array( $marker ), $kept );
			if ( self::fits_budget( $marked, $max_bytes, $max_tokens ) ) {
				$kept = $marked;
			}
		}

		return self::validate_tool_pairs( $kept );
	}

	/**
	 * Whether history fits all enabled size budgets.
	 *
	 * @param Message[] $history    Conversation history.
	 * @param int       $max_bytes  Maximum bytes. 0 = unlimited.
	 * @param int       $max_tokens Maximum estimated tokens. 0 = unlimited.
	 * @return bool True when all enabled budgets are satisfied.
	 */
	public static function fits_budget( array $history, int $max_bytes, int $max_tokens = 0 ): bool {
		if ( $max_bytes > 0 && self::estimate_total_bytes( $history ) > $max_bytes ) {
			return false;
		}

		if ( $max_tokens > 0 && self::estimate_total_tokens( $history ) > $max_tokens ) {
			return false;
		}

		return true;
	}

	/**
	 * Resolve the configured provider request byte budget.
	 *
	 * @param string $provider_id Runtime-selected provider ID.
	 * @param string $model_id    Runtime-selected model ID.
	 * @return int Effective request byte budget.
	 */
	public static function get_request_byte_budget( string $provider_id = '', string $model_id = '' ): int {
		// @phpstan-ignore-next-line
		$configured = (int) Settings::instance()->get( 'provider_request_max_bytes' );
		if ( $configured <= 0 ) {
			$configured = self::DEFAULT_MAX_REQUEST_BYTES;
		}

		/**
		 * Filter the local provider request body safety budget.
		 *
		 * @param int    $configured Configured byte budget.
		 * @param string $provider_id Runtime-selected provider ID.
		 * @param string $model_id Runtime-selected model ID.
		 */
		$filtered = (int) apply_filters( 'sd_ai_agent_provider_request_max_bytes', $configured, $provider_id, $model_id );

		return max( 1024, $filtered );
	}

	/**
	 * Resolve the configured conversation input token budget.
	 *
	 * @param string $provider_id Runtime-selected provider ID.
	 * @param string $model_id    Runtime-selected model ID.
	 * @return int Effective estimated-token budget.
	 */
	public static function get_request_token_budget( string $provider_id = '', string $model_id = '' ): int {
		// @phpstan-ignore-next-line
		$configured = (int) Settings::instance()->get( 'provider_request_max_tokens' );
		if ( $configured <= 0 ) {
			$configured = self::DEFAULT_MAX_REQUEST_TOKENS;
		}

		/**
		 * Filter the local conversation input token safety budget.
		 *
		 * @param int    $configured Configured estimated-token budget.
		 * @param string $provider_id Runtime-selected provider ID.
		 * @param string $model_id Runtime-selected model ID.
		 */
		$filtered = (int) apply_filters( 'sd_ai_agent_provider_request_max_tokens', $configured, $provider_id, $model_id );

		return max( 256, $filtered );
	}

	/**
	 * Validate and repair tool_use/tool_result pairing in conversation history.
	 *
	 * Two-pass scrub to satisfy the Anthropic API invariant that every
	 * tool_result has a matching tool_use earlier in the same request:
	 *
	 *   Pass 1 — forward: drop assistant tool-call clusters whose FunctionCall
	 *   parts do not all have matching FunctionResponse messages immediately
	 *   after the cluster. Also drops the partial response cluster.
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

			// Collect consecutive assistant tool-call messages as one logical
			// provider turn. ConversationSerializer::append_assistant_message()
			// splits parallel function calls into separate ModelMessages for the
			// OpenAI Responses API, while append_tool_response() appends the
			// matching FunctionResponses after the whole split call cluster. Treating
			// each split call message independently would falsely drop every call
			// except the final one because the next message is another tool call,
			// not a response. That was visible in traces as skill-load disappearing
			// from history and being loaded again on the next turn.
			$tool_call_ids = [];
			$call_start    = $i;
			$call_end      = $i;
			while ( $call_end < $count ) {
				$current_call_ids = self::extract_tool_call_ids( $history[ $call_end ] );
				if ( empty( $current_call_ids ) ) {
					break;
				}
				foreach ( $current_call_ids as $cid ) {
					$tool_call_ids[] = $cid;
				}
				++$call_end;
			}
			$tool_call_ids = array_values( array_unique( $tool_call_ids ) );

			// Collect the tool-response messages that follow the entire call cluster.
			$response_ids   = [];
			$response_start = $call_end;
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
				// All tool calls have responses — keep the entire split cycle.
				for ( $j = $call_start; $j < $call_end; $j++ ) {
					$result[] = $history[ $j ];
				}
				for ( $j = $response_start; $j < $response_end; $j++ ) {
					$result[] = $history[ $j ];
				}
			}
			// else: orphaned tool calls — skip the entire cycle (assistant
			// message cluster + any partial responses) to prevent the API error.

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
	 * Estimate serialized bytes for one message, including attachment/tool data.
	 *
	 * @param Message $message Conversation message.
	 * @return int Estimated serialized bytes.
	 */
	public static function estimate_bytes( Message $message ): int {
		try {
			$encoded = wp_json_encode( $message->toArray() );
			if ( is_string( $encoded ) ) {
				return strlen( $encoded );
			}
		} catch ( \Throwable $e ) {
			// Fall through to the conservative token-derived estimate.
		}

		return self::estimate_tokens( $message ) * 4;
	}

	/**
	 * Estimate total serialized bytes in a history array.
	 *
	 * @param Message[] $history Conversation history.
	 * @return int Estimated serialized bytes.
	 */
	public static function estimate_total_bytes( array $history ): int {
		$total = 0;
		foreach ( $history as $message ) {
			$total += self::estimate_bytes( $message );
		}

		return $total;
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
