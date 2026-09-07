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

	/** Default reserve below a provider's request limit for envelope variance. */
	const DEFAULT_REQUEST_SAFETY_MARGIN_BYTES = 32768;

	/** Default maximum estimated input tokens retained in conversation history. */
	const DEFAULT_MAX_REQUEST_TOKENS = 100000;

	/** Maximum history size copied into a deterministically compacted session. */
	const COMPACT_MAX_BYTES = 65536;

	/** Maximum estimated tokens copied into a deterministically compacted session. */
	const COMPACT_MAX_TOKENS = 16000;

	/** Maximum characters copied from any single message into compact context. */
	private const COMPACT_MAX_MESSAGE_CHARS = 2000;

	/** Maximum serialized bytes held for one streamed source message. */
	private const STREAMED_MESSAGE_MAX_BYTES = 1048576;

	/** Maximum JSON characters retained for callable ability-search schemas. */
	private const COMPACT_MAX_ABILITY_SCHEMA_RECEIPT_CHARS = 1600;

	/** Exact marker at the start of a deterministic compact-context message. */
	private const COMPACT_CONTEXT_MARKER = 'Conversation compacted server-side to avoid provider payload limits.';

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
	 * Build a bounded deterministic seed message for a compacted session.
	 *
	 * This is intentionally not an AI summary request. It runs server-side against
	 * the already-persisted session, keeps newest useful excerpts until the compact
	 * budget is reached, and omits attachment bytes plus raw tool arguments/results.
	 * The client can switch to the returned session without submitting the whole
	 * transcript back through `/run` as a new prompt.
	 *
	 * @param array<int, mixed> $messages   Serialized session messages.
	 * @param int               $max_bytes  Maximum serialized seed-message bytes.
	 * @param int               $max_tokens Maximum estimated seed-message tokens.
	 * @return array{messages:list<array<string,mixed>>,meta:array<string,int|bool>}
	 */
	public static function compact_serialized_history( array $messages, int $max_bytes = self::COMPACT_MAX_BYTES, int $max_tokens = self::COMPACT_MAX_TOKENS ): array {
		$normalized = array();
		foreach ( $messages as $message ) {
			if ( is_array( $message ) ) {
				$normalized[] = $message;
			}
		}

		$source_count = 0;
		$max_bytes    = max( 1024, $max_bytes );
		$max_tokens   = max( 256, $max_tokens );

		$per_message_chars = max(
			160,
			min( self::COMPACT_MAX_MESSAGE_CHARS, (int) floor( $max_bytes / 4 ) )
		);

		$available_lines = array();
		foreach ( $normalized as $message ) {
			$expanded        = self::serialized_message_to_compact_excerpts( $message, $per_message_chars );
			$source_count   += $expanded['source_count'];
			$available_lines = array_merge( $available_lines, $expanded['lines'] );
		}

		$lines = array();
		for ( $i = count( $available_lines ) - 1; $i >= 0; --$i ) {
			$excerpt = $available_lines[ $i ];

			$candidate = $lines;
			array_unshift( $candidate, $excerpt );
			if ( self::compact_lines_fit_budget( $candidate, $source_count, $max_bytes, $max_tokens ) ) {
				$lines = $candidate;
			}
		}

		$retained_count = count( $lines );
		if ( empty( $lines ) ) {
			$lines          = array( '[No individual message excerpt fit within the compact budget.]' );
			$retained_count = 0;
		}

		$text       = self::build_compact_context_text( $source_count, $retained_count, $lines );
		$message    = self::compact_text_to_message( $text );
		$line_count = count( $lines );

		while ( ! self::fits_budget( array( $message ), $max_bytes, $max_tokens ) && $line_count > 1 ) {
			array_shift( $lines );
			$retained_count = count( $lines );
			$line_count     = $retained_count;
			$text           = self::build_compact_context_text( $source_count, $retained_count, $lines );
			$message        = self::compact_text_to_message( $text );
		}

		if ( ! self::fits_budget( array( $message ), $max_bytes, $max_tokens ) ) {
			$retained_count = 0;
			$text           = self::build_compact_context_text(
				$source_count,
				$retained_count,
				array( '[Conversation details were omitted because the compact budget is smaller than the required metadata.]' )
			);
			$message        = self::compact_text_to_message( $text );
		}

		$estimated_bytes  = self::estimate_bytes( $message );
		$estimated_tokens = self::estimate_tokens( $message );

		return array(
			'messages' => array( $message->toArray() ),
			'meta'     => array(
				'source_message_count'   => $source_count,
				'retained_excerpt_count' => $retained_count,
				'boundary_omitted_count' => max( 0, $source_count - $retained_count ),
				'estimated_bytes'        => $estimated_bytes,
				'estimated_tokens'       => $estimated_tokens,
				'max_bytes'              => $max_bytes,
				'max_tokens'             => $max_tokens,
				'attachments_omitted'    => true,
				'tool_payloads_omitted'  => true,
			),
		);
	}

	/**
	 * Build a bounded compact seed from JSON-array chunks without materializing the
	 * full persisted history in PHP memory.
	 *
	 * Complete serialized tool call/result cycles are retained together or omitted
	 * together. A malformed or incomplete stream is reported in meta so callers
	 * can fail closed instead of compacting a partial conversation.
	 *
	 * @param iterable<int, string> $chunks     Serialized JSON-array slices.
	 * @param int                   $max_bytes  Maximum serialized seed-message bytes.
	 * @param int                   $max_tokens Maximum estimated seed-message tokens.
	 * @return array{messages:list<array<string,mixed>>,meta:array<string,int|bool>}
	 */
	public static function compact_serialized_history_chunks( iterable $chunks, int $max_bytes = self::COMPACT_MAX_BYTES, int $max_tokens = self::COMPACT_MAX_TOKENS ): array {
		$stream_complete = false;
		$stream_valid    = true;
		$result          = self::compact_serialized_history_iterable(
			self::decode_serialized_history_chunks( $chunks, $stream_complete, $stream_valid ),
			$max_bytes,
			$max_tokens
		);

		$result['meta']['stream_complete'] = $stream_complete;
		$result['meta']['stream_valid']    = $stream_valid;

		return $result;
	}

	/**
	 * Build bounded compact context from a streamed serialized-message iterable.
	 *
	 * @param iterable<int, array<string,mixed>> $messages   Serialized messages.
	 * @param int                                $max_bytes  Maximum serialized seed-message bytes.
	 * @param int                                $max_tokens Maximum estimated seed-message tokens.
	 * @return array{messages:list<array<string,mixed>>,meta:array<string,int|bool>}
	 */
	private static function compact_serialized_history_iterable( iterable $messages, int $max_bytes, int $max_tokens ): array {
		$max_bytes             = max( 1024, $max_bytes );
		$max_tokens            = max( 256, $max_tokens );
		$per_message_chars     = max( 160, min( self::COMPACT_MAX_MESSAGE_CHARS, (int) floor( $max_bytes / 4 ) ) );
		$source_count          = 0;
		$retained_groups       = array();
		$pending_tool_call_lines = null;

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$expanded     = self::serialized_message_to_compact_excerpts( $message, $per_message_chars );
			$source_count += $expanded['source_count'];
			$has_call     = self::serialized_message_has_function_call( $message );
			$has_response = self::serialized_message_has_function_response( $message );

			if ( null !== $pending_tool_call_lines ) {
				if ( $has_response ) {
					self::retain_compact_group(
						$retained_groups,
						array_merge( $pending_tool_call_lines, $expanded['lines'] ),
						$source_count,
						$max_bytes,
						$max_tokens
					);
					$pending_tool_call_lines = null;
					continue;
				}

				// Do not retain a lone call when its result is absent from the stream.
				$pending_tool_call_lines = null;
				self::trim_compact_groups( $retained_groups, $source_count, $max_bytes, $max_tokens );
			}

			if ( $has_call ) {
				$pending_tool_call_lines = $expanded['lines'];
				self::trim_compact_groups( $retained_groups, $source_count, $max_bytes, $max_tokens );
				continue;
			}

			if ( $has_response ) {
				// A result without a preceding call cannot form a complete tool cycle.
				self::trim_compact_groups( $retained_groups, $source_count, $max_bytes, $max_tokens );
				continue;
			}

			self::retain_compact_group( $retained_groups, $expanded['lines'], $source_count, $max_bytes, $max_tokens );
		}

		if ( null !== $pending_tool_call_lines ) {
			self::trim_compact_groups( $retained_groups, $source_count, $max_bytes, $max_tokens );
		}

		$lines          = self::flatten_compact_groups( $retained_groups );
		$retained_count = count( $lines );
		if ( empty( $lines ) ) {
			$lines          = array( '[No individual message excerpt fit within the compact budget.]' );
			$retained_count = 0;
		}

		$text       = self::build_compact_context_text( $source_count, $retained_count, $lines );
		$message    = self::compact_text_to_message( $text );
		$line_count = count( $lines );

		while ( ! self::fits_budget( array( $message ), $max_bytes, $max_tokens ) && $line_count > 1 ) {
			array_shift( $lines );
			$retained_count = count( $lines );
			$line_count     = $retained_count;
			$text           = self::build_compact_context_text( $source_count, $retained_count, $lines );
			$message        = self::compact_text_to_message( $text );
		}

		if ( ! self::fits_budget( array( $message ), $max_bytes, $max_tokens ) ) {
			$retained_count = 0;
			$text           = self::build_compact_context_text(
				$source_count,
				$retained_count,
				array( '[Conversation details were omitted because the compact budget is smaller than the required metadata.]' )
			);
			$message        = self::compact_text_to_message( $text );
		}

		return array(
			'messages' => array( $message->toArray() ),
			'meta'     => array(
				'source_message_count'   => $source_count,
				'retained_excerpt_count' => $retained_count,
				'boundary_omitted_count' => max( 0, $source_count - $retained_count ),
				'estimated_bytes'        => self::estimate_bytes( $message ),
				'estimated_tokens'       => self::estimate_tokens( $message ),
				'max_bytes'              => $max_bytes,
				'max_tokens'             => $max_tokens,
				'attachments_omitted'    => true,
				'tool_payloads_omitted'  => true,
			),
		);
	}

	/**
	 * Incrementally decode an array of serialized message objects.
	 *
	 * @param iterable<int, string> $chunks   Serialized JSON-array slices.
	 * @param bool                  $complete Receives whether the closing array token was read.
	 * @param bool                  $valid    Receives whether every decoded element was valid.
	 * @return \Generator<int, array<string,mixed>>
	 */
	private static function decode_serialized_history_chunks( iterable $chunks, bool &$complete, bool &$valid ): \Generator {
		$complete      = false;
		$valid         = true;
		$started       = false;
		$depth         = 0;
		$element       = '';
		$element_bytes = 0;
		$in_string     = false;
		$escaped       = false;
		$discarding    = false;

		foreach ( $chunks as $chunk ) {
			if ( ! is_string( $chunk ) ) {
				$valid = false;
				continue;
			}

			for ( $index = 0, $length = strlen( $chunk ); $index < $length; ++$index ) {
				$character = $chunk[ $index ];

				if ( ! $started ) {
					if ( ' ' === $character || "\n" === $character || "\r" === $character || "\t" === $character ) {
						continue;
					}
					if ( '[' !== $character ) {
						$valid = false;
						return;
					}

					$started = true;
					continue;
				}

				if ( 0 === $depth ) {
					if ( ' ' === $character || "\n" === $character || "\r" === $character || "\t" === $character || ',' === $character ) {
						continue;
					}
					if ( ']' === $character ) {
						$complete = true;
						return;
					}
					if ( '{' !== $character ) {
						$valid = false;
						return;
					}

					$depth         = 1;
					$element       = '{';
					$element_bytes = 1;
					$in_string     = false;
					$escaped       = false;
					$discarding    = false;
					continue;
				}

				++$element_bytes;
				if ( ! $discarding ) {
					if ( $element_bytes > self::STREAMED_MESSAGE_MAX_BYTES ) {
						$discarding = true;
						$element    = '';
					} else {
						$element .= $character;
					}
				}

				if ( $in_string ) {
					if ( $escaped ) {
						$escaped = false;
						continue;
					}
					if ( '\\' === $character ) {
						$escaped = true;
						continue;
					}
					if ( '"' === $character ) {
						$in_string = false;
					}
					continue;
				}

				if ( '"' === $character ) {
					$in_string = true;
					continue;
				}
				if ( '{' === $character || '[' === $character ) {
					++$depth;
					continue;
				}
				if ( '}' !== $character && ']' !== $character ) {
					continue;
				}

				--$depth;
				if ( $depth < 0 ) {
					$valid = false;
					return;
				}
				if ( 0 !== $depth ) {
					continue;
				}

				if ( $discarding ) {
					yield self::oversized_streamed_message_placeholder( $element_bytes );
				} else {
					$decoded = json_decode( $element, true );
					if ( ! is_array( $decoded ) ) {
						$valid = false;
					} else {
						yield $decoded;
					}
				}

				$element       = '';
				$element_bytes = 0;
				$in_string     = false;
				$escaped       = false;
				$discarding    = false;
			}
		}

		$valid = false;
	}

	/**
	 * Build a bounded marker for one pathological serialized message.
	 *
	 * @param int $bytes Source message size.
	 * @return array<string,mixed>
	 */
	private static function oversized_streamed_message_placeholder( int $bytes ): array {
		return array(
			'role'  => 'user',
			'parts' => array(
				array(
					'text' => sprintf( '[A persisted message of %d bytes was omitted during maintenance compaction.]', $bytes ),
				),
			),
		);
	}

	/**
	 * Check whether a serialized message contains a function call.
	 *
	 * @param array<string,mixed> $message Serialized message.
	 */
	private static function serialized_message_has_function_call( array $message ): bool {
		$parts = isset( $message['parts'] ) && is_array( $message['parts'] ) ? $message['parts'] : array();
		foreach ( $parts as $part ) {
			if ( is_array( $part ) && ( isset( $part['functionCall'] ) || isset( $part['function_call'] ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a serialized message contains a function response.
	 *
	 * @param array<string,mixed> $message Serialized message.
	 */
	private static function serialized_message_has_function_response( array $message ): bool {
		$parts = isset( $message['parts'] ) && is_array( $message['parts'] ) ? $message['parts'] : array();
		foreach ( $parts as $part ) {
			if ( is_array( $part ) && ( isset( $part['functionResponse'] ) || isset( $part['function_response'] ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Retain a complete compact group only while it fits the bounded seed budget.
	 *
	 * @param list<list<string>> $groups      Retained chronological compact groups.
	 * @param list<string>       $lines       One logical group of excerpts.
	 * @param int                $source_count Original source message count.
	 * @param int                $max_bytes    Maximum serialized seed-message bytes.
	 * @param int                $max_tokens   Maximum estimated seed-message tokens.
	 */
	private static function retain_compact_group( array &$groups, array $lines, int $source_count, int $max_bytes, int $max_tokens ): void {
		$group = array();
		foreach ( $lines as $line ) {
			if ( is_string( $line ) && '' !== $line ) {
				$group[] = $line;
			}
		}
		if ( ! empty( $group ) ) {
			$groups[] = $group;
		}

		self::trim_compact_groups( $groups, $source_count, $max_bytes, $max_tokens );
	}

	/**
	 * Drop oldest whole groups until the candidate seed fits the current budget.
	 *
	 * @param list<list<string>> $groups       Retained chronological compact groups.
	 * @param int                $source_count Original source message count.
	 * @param int                $max_bytes    Maximum serialized seed-message bytes.
	 * @param int                $max_tokens   Maximum estimated seed-message tokens.
	 */
	private static function trim_compact_groups( array &$groups, int $source_count, int $max_bytes, int $max_tokens ): void {
		while ( ! empty( $groups ) && ! self::compact_lines_fit_budget( self::flatten_compact_groups( $groups ), $source_count, $max_bytes, $max_tokens ) ) {
			array_shift( $groups );
		}
	}

	/**
	 * Flatten chronological compact groups without splitting their ordering.
	 *
	 * @param list<list<string>> $groups Retained compact groups.
	 * @return list<string>
	 */
	private static function flatten_compact_groups( array $groups ): array {
		$lines = array();
		foreach ( $groups as $group ) {
			foreach ( $group as $line ) {
				$lines[] = $line;
			}
		}

		return $lines;
	}

	/**
	 * Test whether compact context lines fit the target budgets.
	 *
	 * @param string[] $lines        Candidate context lines.
	 * @param int      $source_count Original message count.
	 * @param int      $max_bytes    Byte budget.
	 * @param int      $max_tokens   Estimated-token budget.
	 */
	private static function compact_lines_fit_budget( array $lines, int $source_count, int $max_bytes, int $max_tokens ): bool {
		$message = self::compact_text_to_message(
			self::build_compact_context_text( $source_count, count( $lines ), $lines )
		);

		return self::fits_budget( array( $message ), $max_bytes, $max_tokens );
	}

	/**
	 * Build the compact-context prompt text.
	 *
	 * @param int      $source_count   Original message count.
	 * @param int      $retained_count Retained excerpt count.
	 * @param string[] $lines          Retained message excerpts.
	 */
	private static function build_compact_context_text( int $source_count, int $retained_count, array $lines ): string {
		$omitted_count = max( 0, $source_count - $retained_count );

		$header = array(
			self::COMPACT_CONTEXT_MARKER,
			"Source messages: {$source_count}; retained excerpts: {$retained_count}; omitted messages: {$omitted_count}.",
			'Use this compact context to continue the current task. File attachments, inline image bytes, and raw tool payloads were omitted.',
			'Bounded inspection receipts are evidence of completed reads. Do not repeat an inspection solely because its raw result was omitted.',
			'Ability-search receipts retain callable input-schema shapes. Use ability-call directly with those schemas instead of searching for the same ability again.',
		);

		return implode( "\n", $header ) . "\n\n" . implode( "\n\n", $lines );
	}

	/**
	 * Expand an existing deterministic compact seed instead of nesting it.
	 *
	 * Provider recovery can compact the same durable history repeatedly. Treating
	 * an earlier seed as an ordinary user message collapses all of its structured
	 * receipts into one 2,000-character excerpt, so later compaction loses the
	 * evidence needed to continue. A strictly recognized seed contributes its
	 * original excerpts and source count directly, making compaction idempotent.
	 *
	 * @param array<string, mixed> $message   Serialized message array.
	 * @param int                  $max_chars Character limit for each excerpt body.
	 * @return array{lines:list<string>,source_count:int}
	 */
	private static function serialized_message_to_compact_excerpts( array $message, int $max_chars ): array {
		$parts = isset( $message['parts'] ) && is_array( $message['parts'] ) ? array_values( $message['parts'] ) : array();
		if (
			1 === count( $parts )
			&& is_array( $parts[0] )
			&& isset( $parts[0]['text'] )
			&& is_string( $parts[0]['text'] )
		) {
			$expanded = self::parse_compact_context_text( $parts[0]['text'], $max_chars );
			if ( null !== $expanded ) {
				return $expanded;
			}
		}

		$excerpt = self::serialized_message_to_compact_excerpt( $message, $max_chars );

		return array(
			'lines'        => '' === $excerpt ? array() : array( $excerpt ),
			'source_count' => 1,
		);
	}

	/**
	 * Parse a compact seed produced by {@see build_compact_context_text()}.
	 *
	 * Recognition is deliberately strict so ordinary user prose that happens to
	 * mention compaction is not reinterpreted as server-produced history.
	 *
	 * @param string $text      Candidate compact-context text.
	 * @param int    $max_chars Character limit for each recovered excerpt.
	 * @return array{lines:list<string>,source_count:int}|null
	 */
	private static function parse_compact_context_text( string $text, int $max_chars ): ?array {
		$text  = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$lines = explode( "\n", $text );
		if (
			count( $lines ) < 7
			|| self::COMPACT_CONTEXT_MARKER !== $lines[0]
			|| ! preg_match( '/^Source messages: (\d+); retained excerpts: (\d+); omitted messages: (\d+)\.$/', $lines[1], $matches )
			|| 'Use this compact context to continue the current task. File attachments, inline image bytes, and raw tool payloads were omitted.' !== $lines[2]
			|| 'Bounded inspection receipts are evidence of completed reads. Do not repeat an inspection solely because its raw result was omitted.' !== $lines[3]
			|| 'Ability-search receipts retain callable input-schema shapes. Use ability-call directly with those schemas instead of searching for the same ability again.' !== $lines[4]
			|| '' !== $lines[5]
		) {
			return null;
		}

		$body      = implode( "\n", array_slice( $lines, 6 ) );
		$excerpts  = preg_split( '/\n{2,}/', $body );
		$excerpts  = is_array( $excerpts ) ? $excerpts : array();
		$recovered = array();
		foreach ( $excerpts as $excerpt ) {
			$excerpt = trim( $excerpt );
			if ( '' === $excerpt ) {
				continue;
			}

			if ( strlen( $excerpt ) > $max_chars ) {
				$excerpt = substr( $excerpt, 0, max( 0, $max_chars - 1 ) ) . '…';
			}
			$recovered[] = $excerpt;
		}

		return array(
			'lines'        => $recovered,
			'source_count' => max( count( $recovered ), (int) $matches[1] ),
		);
	}

	/** Convert compact text into a single user-message seed. */
	private static function compact_text_to_message( string $text ): UserMessage {
		return new UserMessage(
			array(
				new MessagePart( $text ),
			)
		);
	}

	/**
	 * Convert a serialized message to a bounded compact-context excerpt.
	 *
	 * @param array<string, mixed> $message   Serialized message array.
	 * @param int                  $max_chars Character limit for the excerpt body.
	 */
	private static function serialized_message_to_compact_excerpt( array $message, int $max_chars ): string {
		$role  = self::compact_role_label( (string) ( $message['role'] ?? 'message' ) );
		$parts = isset( $message['parts'] ) && is_array( $message['parts'] ) ? $message['parts'] : array();

		$pieces = array();
		foreach ( $parts as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}

			$piece = self::compact_piece_from_part( $part );
			if ( '' !== $piece ) {
				$pieces[] = $piece;
			}
		}

		if ( empty( $pieces ) && isset( $message['content'] ) && is_string( $message['content'] ) ) {
			$pieces[] = $message['content'];
		}

		$text = self::normalize_compact_text( implode( ' ', $pieces ) );
		if ( '' === $text ) {
			return '';
		}

		if ( strlen( $text ) > $max_chars ) {
			$text = substr( $text, 0, max( 0, $max_chars - 1 ) ) . '…';
		}

		return $role . ': ' . $text;
	}

	/**
	 * Return a safe compact-context fragment for a serialized message part.
	 *
	 * @param array<string, mixed> $part Serialized message part.
	 */
	private static function compact_piece_from_part( array $part ): string {
		if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
			return $part['text'];
		}

		$function_call = $part['functionCall'] ?? $part['function_call'] ?? null;
		if ( is_array( $function_call ) ) {
			$mutation_receipt = self::compact_post_mutation_call_receipt( $function_call );
			if ( '' !== $mutation_receipt ) {
				return $mutation_receipt;
			}

			$inspection_receipt = self::compact_inspection_call_receipt( $function_call );
			if ( '' !== $inspection_receipt ) {
				return $inspection_receipt;
			}

			$resource_receipt = self::compact_resource_call_receipt( $function_call );
			if ( '' !== $resource_receipt ) {
				return $resource_receipt;
			}

			$name = self::compact_tool_name( $function_call['name'] ?? 'tool' );
			return '[tool call: ' . $name . ']';
		}

		$function_response = $part['functionResponse'] ?? $part['function_response'] ?? null;
		if ( is_array( $function_response ) ) {
			$mutation_receipt = self::compact_post_mutation_response_receipt( $function_response );
			if ( '' !== $mutation_receipt ) {
				return $mutation_receipt;
			}

			$inspection_receipt = self::compact_inspection_response_receipt( $function_response );
			if ( '' !== $inspection_receipt ) {
				return $inspection_receipt;
			}

			$resource_receipt = self::compact_resource_response_receipt( $function_response );
			if ( '' !== $resource_receipt ) {
				return $resource_receipt;
			}

			$name = self::compact_tool_name( $function_response['name'] ?? 'tool' );
			return '[tool result omitted: ' . $name . ']';
		}

		if ( isset( $part['image_url'] ) || isset( $part['inlineData'] ) || isset( $part['inline_data'] ) ) {
			$name = isset( $part['image_name'] ) && is_string( $part['image_name'] ) ? self::normalize_compact_text( $part['image_name'] ) : '';
			return '' !== $name ? '[image attachment omitted: ' . $name . ']' : '[image attachment omitted]';
		}

		return '';
	}

	/**
	 * Preserve a bounded, non-content receipt for post-creation calls.
	 *
	 * Checkpoint compaction normally omits raw tool arguments. Post titles and
	 * counts are retained for create calls so a resumed setup run knows which
	 * pages it already attempted instead of recreating them after a timeout.
	 *
	 * @param array<string,mixed> $function_call Serialized function call.
	 */
	private static function compact_post_mutation_call_receipt( array $function_call ): string {
		[ $name, $args ] = self::compact_call_identity( $function_call );
		if ( ! self::is_post_creation_tool( $name ) ) {
			return '';
		}
		if ( self::tool_name_has_suffix( $name, 'batch-create-posts' ) ) {
			$posts  = isset( $args['posts'] ) && is_array( $args['posts'] ) ? $args['posts'] : array();
			$titles = array();
			foreach ( array_slice( $posts, 0, 20 ) as $post ) {
				if ( is_array( $post ) && isset( $post['title'] ) && is_scalar( $post['title'] ) ) {
					$titles[] = self::compact_receipt_value( (string) $post['title'] );
				}
			}

			return sprintf(
				'[tool call: %s posts=%d titles=%s]',
				$name,
				count( $posts ),
				self::compact_receipt_list( $titles )
			);
		}

		$title = isset( $args['title'] ) && is_scalar( $args['title'] )
			? self::compact_receipt_value( (string) $args['title'] )
			: 'unknown';

		return sprintf( '[tool call: %s title=%s]', $name, self::compact_receipt_list( array( $title ) ) );
	}

	/**
	 * Preserve successful post IDs and titles without copying raw tool output.
	 *
	 * @param array<string,mixed> $function_response Serialized function response.
	 */
	private static function compact_post_mutation_response_receipt( array $function_response ): string {
		[ $name, $response ] = self::compact_response_identity( $function_response );
		if ( ! self::is_post_creation_tool( $name ) ) {
			return '';
		}
		if ( self::tool_name_has_suffix( $name, 'batch-create-posts' ) ) {
			$results  = isset( $response['results'] ) && is_array( $response['results'] ) ? $response['results'] : array();
			$entities = array();
			foreach ( array_slice( $results, 0, 20 ) as $result ) {
				if ( ! is_array( $result ) || empty( $result['post_id'] ) ) {
					continue;
				}

				$title      = isset( $result['title'] ) && is_scalar( $result['title'] ) ? self::compact_receipt_value( (string) $result['title'] ) : 'post';
				$entities[] = $title . '#' . absint( $result['post_id'] );
			}

			return sprintf(
				'[tool result: %s created=%d posts=%s]',
				$name,
				isset( $response['created_count'] ) ? absint( $response['created_count'] ) : count( $entities ),
				self::compact_receipt_list( $entities )
			);
		}

		$post_id = isset( $response['post_id'] ) ? absint( $response['post_id'] ) : 0;
		return sprintf( '[tool result: %s post_id=%d]', $name, $post_id );
	}

	/** Whether a compacted tool name creates one or more posts. */
	private static function is_post_creation_tool( string $name ): bool {
		return self::tool_name_has_suffix( $name, 'create-post' )
			|| self::tool_name_has_suffix( $name, 'batch-create-posts' );
	}

	/** Preserve the stable identifiers returned by media and form creation tools. */
	/**
	 * @param array<string,mixed> $function_response Serialized function response.
	 */
	private static function compact_resource_response_receipt( array $function_response ): string {
		[ $name, $response ] = self::compact_response_identity( $function_response );
		if ( ! self::is_compact_resource_tool( $name ) ) {
			return '';
		}

		$summary = self::compact_receipt_fields(
			$response,
			array( 'success', 'attachment_id', 'title', 'alt', 'source', 'attribution', 'provider', 'form_id', 'shortcode', 'error' )
		);

		return '[tool result: ' . $name . ' resource=' . self::compact_receipt_json( $summary ) . ']';
	}

	/** Preserve bounded intent for media and form creation calls. */
	/**
	 * @param array<string,mixed> $function_call Serialized function call.
	 */
	private static function compact_resource_call_receipt( array $function_call ): string {
		[ $name, $args ] = self::compact_call_identity( $function_call );
		if ( ! self::is_compact_resource_tool( $name ) ) {
			return '';
		}

		$summary = self::compact_receipt_fields(
			$args,
			array( 'action', 'keyword', 'usage', 'orientation', 'title', 'recipient_email' )
		);

		return '[tool call: ' . $name . ' args=' . self::compact_receipt_json( $summary ) . ']';
	}

	/** Whether a tool creates a reusable media or form resource. */
	private static function is_compact_resource_tool( string $name ): bool {
		return self::tool_name_has_suffix( $name, 'stock-image' )
			|| self::tool_name_has_suffix( $name, 'generate-image' )
			|| self::tool_name_has_suffix( $name, 'create-contact-form' );
	}

	/**
	 * Preserve bounded query parameters for read-only setup inspections.
	 *
	 * @param array<string,mixed> $function_call Serialized function call.
	 */
	private static function compact_inspection_call_receipt( array $function_call ): string {
		$name = self::compact_tool_name( $function_call['name'] ?? 'tool' );
		if ( ! self::is_compact_inspection_tool( $name ) ) {
			return '';
		}

		$args    = self::compact_tool_payload_array( $function_call['args'] ?? array() );
		$summary = self::compact_receipt_fields(
			$args,
			array( 'query', 'search', 'prefix', 'post_type', 'post_status', 'mime_type', 'limit', 'autoload', 'stylesheet', 'area' )
		);

		return '[inspection call: ' . $name . ' args=' . self::compact_receipt_json( $summary ) . ']';
	}

	/**
	 * Preserve bounded, non-content evidence returned by read-only setup inspections.
	 *
	 * @param array<string,mixed> $function_response Serialized function response.
	 */
	private static function compact_inspection_response_receipt( array $function_response ): string {
		$name = self::compact_tool_name( $function_response['name'] ?? 'tool' );
		if ( ! self::is_compact_inspection_tool( $name ) ) {
			return '';
		}

		$response = self::compact_tool_payload_array( $function_response['response'] ?? array() );
		if ( self::tool_name_has_suffix( $name, 'ability-search' ) ) {
			return self::compact_ability_search_response_receipt( $name, $response );
		}

		$summary = self::compact_receipt_fields(
			$response,
			array( 'success', 'total', 'count', 'active', 'active_count', 'valid', 'passed', 'query', 'stylesheet', 'code', 'error' )
		);

		$collection_fields = array(
			'results'   => array( 'id', 'label', 'name', 'title', 'status', 'post_id', 'success', 'error' ),
			'posts'     => array( 'id', 'title', 'status', 'post_type' ),
			'items'     => array( 'id', 'title', 'name', 'mime_type', 'status' ),
			'themes'    => array( 'slug', 'name', 'active', 'status', 'version' ),
			'plugins'   => array( 'name', 'active', 'status' ),
			'menus'     => array( 'id', 'name', 'slug', 'count' ),
			'options'   => array( 'option_name' ),
			'templates' => array( 'slug', 'title' ),
		);
		foreach ( $collection_fields as $key => $fields ) {
			if ( ! isset( $response[ $key ] ) || ! is_array( $response[ $key ] ) ) {
				continue;
			}

			$entities = array();
			foreach ( array_slice( $response[ $key ], 0, 10 ) as $entity ) {
				if ( is_array( $entity ) ) {
					$entities[] = self::compact_receipt_fields( $entity, $fields );
				}
			}
			$summary[ $key ] = $entities;
		}

		return '[inspection result: ' . $name . ' summary=' . self::compact_receipt_json( $summary ) . ']';
	}

	/**
	 * Preserve enough of ability-search results to call selected abilities.
	 *
	 * Continued provider compaction runs before every later model call. Omitting
	 * input schemas here means a newly fetched Tier-2 ability becomes unusable on
	 * the very next turn, causing the model to search again or fall back to the
	 * Tier-1 site-inspection tools. Retain only the schema's structural shape;
	 * descriptions, defaults, examples, and output schemas remain omitted.
	 *
	 * @param string              $name     Compacted ability-search tool name.
	 * @param array<string,mixed> $response Ability-search response payload.
	 */
	private static function compact_ability_search_response_receipt( string $name, array $response ): string {
		$query   = isset( $response['query'] ) && is_scalar( $response['query'] )
			? self::compact_receipt_value( (string) $response['query'] )
			: '';
		$results = isset( $response['results'] ) && is_array( $response['results'] )
			? array_values( $response['results'] )
			: array();
		$kept    = array();

		foreach ( array_slice( $results, 0, 10 ) as $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}

			$ability = self::compact_receipt_fields( $result, array( 'id', 'label' ) );
			$schema  = self::compact_tool_payload_array( $result['input_schema'] ?? array() );
			if ( ! empty( $schema ) ) {
				$ability['input_schema'] = self::compact_schema_shape( $schema );
			}

			$candidate = array_merge( $kept, array( $ability ) );
			$payload   = array(
				'query'     => $query,
				'abilities' => $candidate,
				'omitted'   => max( 0, count( $results ) - count( $candidate ) ),
			);
			$encoded   = wp_json_encode( $payload );
			if ( ! is_string( $encoded ) || strlen( $encoded ) > self::COMPACT_MAX_ABILITY_SCHEMA_RECEIPT_CHARS ) {
				break;
			}

			$kept = $candidate;
		}

		$payload = array(
			'query'     => $query,
			'abilities' => $kept,
			'omitted'   => max( 0, count( $results ) - count( $kept ) ),
		);
		$encoded = wp_json_encode( $payload );

		return '[inspection result: ' . $name . ' callable_schemas=' . ( is_string( $encoded ) ? $encoded : '{}' ) . ']';
	}

	/**
	 * Build a bounded schema shape without descriptions or example/default data.
	 *
	 * @param array<string,mixed> $schema Input schema.
	 * @param int                 $depth  Current nested-schema depth.
	 * @return array<string,mixed>
	 */
	private static function compact_schema_shape( array $schema, int $depth = 0 ): array {
		$shape = self::compact_receipt_fields(
			$schema,
			array( 'type', 'format', 'enum', 'minimum', 'maximum', 'minItems', 'maxItems' )
		);

		if ( isset( $schema['additionalProperties'] ) && is_bool( $schema['additionalProperties'] ) ) {
			$shape['additionalProperties'] = $schema['additionalProperties'];
		}

		if ( $depth >= 2 ) {
			return $shape;
		}

		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			$properties = array();

			$retained_property_names = array();
			foreach ( array_slice( $schema['properties'], 0, 12, true ) as $property_name => $property_schema ) {
				if ( ! is_string( $property_name ) || ! is_array( $property_schema ) ) {
					continue;
				}

				$compacted_property_name = self::compact_receipt_value( $property_name );

				$properties[ $compacted_property_name ] = self::compact_schema_shape( $property_schema, $depth + 1 );

				$retained_property_names[ $property_name ] = $compacted_property_name;
			}
			if ( ! empty( $properties ) ) {
				$shape['properties'] = $properties;
			}

			if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
				$required = array();
				foreach ( $schema['required'] as $required_property_name ) {
					if ( is_string( $required_property_name ) && isset( $retained_property_names[ $required_property_name ] ) ) {
						$required[] = $retained_property_names[ $required_property_name ];
					}
				}
				if ( ! empty( $required ) ) {
					$shape['required'] = $required;
				}
			}
		}

		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			$shape['items'] = self::compact_schema_shape( $schema['items'], $depth + 1 );
		}

		return $shape;
	}

	/** Whether a tool is a read-only inspection whose bounded result aids continuation. */
	private static function is_compact_inspection_tool( string $name ): bool {
		foreach (
			array(
				'ability-search',
				'get-plugins',
				'get-themes',
				'list-block-templates',
				'list-media',
				'list-menus',
				'list-options',
				'list-posts',
			) as $suffix
		) {
			if ( self::tool_name_has_suffix( $name, $suffix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Select and bound scalar or scalar-list receipt fields.
	 *
	 * @param array<string,mixed> $source         Source payload.
	 * @param string[]            $allowed_fields Fields safe to retain.
	 * @return array<string,mixed>
	 */
	private static function compact_receipt_fields( array $source, array $allowed_fields ): array {
		$summary = array();
		foreach ( $allowed_fields as $field ) {
			if ( ! array_key_exists( $field, $source ) ) {
				continue;
			}

			$value = $source[ $field ];
			if ( is_scalar( $value ) || null === $value ) {
				$summary[ $field ] = is_string( $value ) ? self::compact_receipt_value( $value ) : $value;
				continue;
			}

			if ( is_array( $value ) ) {
				$items = array();
				foreach ( array_slice( $value, 0, 10 ) as $item ) {
					if ( is_scalar( $item ) || null === $item ) {
						$items[] = is_string( $item ) ? self::compact_receipt_value( $item ) : $item;
					}
				}
				$summary[ $field ] = $items;
			}
		}

		return $summary;
	}

	/**
	 * Encode one bounded receipt summary.
	 *
	 * @param array<string,mixed> $summary Safe receipt fields.
	 */
	private static function compact_receipt_json( array $summary ): string {
		$encoded = wp_json_encode( $summary );
		if ( ! is_string( $encoded ) ) {
			return '{}';
		}

		return strlen( $encoded ) > 1200 ? substr( $encoded, 0, 1199 ) . '…' : $encoded;
	}

	/** Match direct and dispatcher-safe function names by their ability suffix. */
	private static function tool_name_has_suffix( string $name, string $suffix ): bool {
		return str_ends_with( strtolower( $name ), '/' . $suffix )
			|| str_ends_with( strtolower( $name ), '__' . $suffix );
	}

	/**
	 * Resolve a direct tool call or an ability-call dispatcher envelope.
	 *
	 * @return array{0:string,1:array<string,mixed>}
	 */
	/**
	 * @param array<string,mixed> $function_call Serialized function call.
	 * @return array{0:string,1:array<string,mixed>}
	 */
	private static function compact_call_identity( array $function_call ): array {
		$name = self::compact_tool_name( $function_call['name'] ?? 'tool' );
		$args = self::compact_tool_payload_array( $function_call['args'] ?? array() );
		if ( self::tool_name_has_suffix( $name, 'ability-call' ) && isset( $args['ability'] ) && is_scalar( $args['ability'] ) ) {
			$name = self::compact_tool_name( $args['ability'] );
			$args = self::compact_tool_payload_array( $args['arguments'] ?? array() );
		}

		return array( $name, $args );
	}

	/**
	 * Resolve a direct tool result or an ability-call dispatcher envelope.
	 *
	 * @return array{0:string,1:array<string,mixed>}
	 */
	/**
	 * @param array<string,mixed> $function_response Serialized function response.
	 * @return array{0:string,1:array<string,mixed>}
	 */
	private static function compact_response_identity( array $function_response ): array {
		$name     = self::compact_tool_name( $function_response['name'] ?? 'tool' );
		$response = self::compact_tool_payload_array( $function_response['response'] ?? array() );
		if ( self::tool_name_has_suffix( $name, 'ability-call' ) && isset( $response['ability'] ) && is_scalar( $response['ability'] ) ) {
			$name   = self::compact_tool_name( $response['ability'] );
			$result = self::compact_tool_payload_array( $response['result'] ?? array() );
			if ( isset( $response['success'] ) && ! isset( $result['success'] ) ) {
				$result['success'] = (bool) $response['success'];
			}
			$response = $result;
		}

		return array( $name, $response );
	}

	/**
	 * Normalize a JSON-or-array tool payload without retaining it beyond the caller.
	 *
	 * @return array<string,mixed>
	 */
	private static function compact_tool_payload_array( mixed $payload ): array {
		if ( is_array( $payload ) ) {
			/** @var array<string,mixed> $normalized */
			$normalized = self::string_keyed_payload( $payload );
			return $normalized;
		}

		if ( is_string( $payload ) ) {
			$decoded = json_decode( $payload, true );
			if ( is_array( $decoded ) ) {
				return self::string_keyed_payload( $decoded );
			}
			return array();
		}

		return array();
	}

	/**
	 * @param array<string|int,mixed> $payload Tool payload.
	 * @return array<string,mixed> String-keyed payload.
	 */
	private static function string_keyed_payload( array $payload ): array {
		$normalized = array();
		foreach ( $payload as $key => $value ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $value;
			}
		}
		return $normalized;
	}

	/** Normalize and bound one safe receipt value. */
	private static function compact_receipt_value( string $value ): string {
		$value = self::normalize_compact_text( wp_strip_all_tags( $value ) );
		return strlen( $value ) > 80 ? substr( $value, 0, 79 ) . '…' : $value;
	}

	/**
	 * Render bounded receipt values without copying arbitrary JSON payloads.
	 *
	 * @param string[] $values Safe receipt values.
	 */
	private static function compact_receipt_list( array $values ): string {
		$non_empty = array();
		foreach ( $values as $value ) {
			if ( '' !== $value ) {
				$non_empty[] = $value;
			}
		}

		$encoded = wp_json_encode( $non_empty );
		return is_string( $encoded ) ? $encoded : '[]';
	}

	/** Convert serialized roles into compact-context labels. */
	private static function compact_role_label( string $role ): string {
		$role = strtolower( trim( $role ) );
		return match ( $role ) {
			'model', 'assistant' => 'Assistant',
			'user' => 'User',
			'tool' => 'Tool',
			default => 'Message',
		};
	}

	/** Normalize a tool name for compact context. */
	private static function compact_tool_name( mixed $name ): string {
		$name = is_scalar( $name ) ? (string) $name : 'tool';
		$name = self::normalize_compact_text( $name );
		if ( '' === $name ) {
			return 'tool';
		}

		return strlen( $name ) > 80 ? substr( $name, 0, 79 ) . '…' : $name;
	}

	/** Normalize compact text while removing inline binary/data payloads. */
	private static function normalize_compact_text( string $text ): string {
		$text = preg_replace( '/data:[^;\s]+;base64,[A-Za-z0-9+\/=\r\n]+/i', '[inline data omitted]', $text );
		$text = is_string( $text ) ? wp_strip_all_tags( $text ) : '';
		$text = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text );
		$text = is_string( $text ) ? preg_replace( '/\s+/', ' ', $text ) : '';

		return is_string( $text ) ? trim( $text ) : '';
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
	 * Resolve the byte reserve retained below the provider's request limit.
	 *
	 * The final provider body contains more than conversation history: system
	 * instructions, tool schemas, attachments, model options, and transport
	 * framing are serialized after the history trimmer runs. Keep a configurable
	 * reserve for those components while retaining a minimum usable request size.
	 *
	 * @param string $provider_id Runtime-selected provider ID.
	 * @param string $model_id    Runtime-selected model ID.
	 * @return int Effective safety-margin bytes.
	 */
	public static function get_request_safety_margin_bytes( string $provider_id = '', string $model_id = '' ): int {
		$request_limit = self::get_request_byte_budget( $provider_id, $model_id );

		return self::resolve_request_safety_margin_bytes( $request_limit, $provider_id, $model_id );
	}

	/**
	 * Resolve the effective full-envelope byte budget.
	 *
	 * This budget applies to both history trimming and the final serialized HTTP
	 * body guard. The latter remains authoritative because it sees provider-
	 * specific serialization that is unavailable to the trimmer.
	 *
	 * @param string $provider_id Runtime-selected provider ID.
	 * @param string $model_id    Runtime-selected model ID.
	 * @return int Effective full-envelope byte budget.
	 */
	public static function get_request_envelope_byte_budget( string $provider_id = '', string $model_id = '' ): int {
		$request_limit = self::get_request_byte_budget( $provider_id, $model_id );
		$safety_margin = self::resolve_request_safety_margin_bytes( $request_limit, $provider_id, $model_id );

		return max( 1024, $request_limit - $safety_margin );
	}

	/**
	 * Apply the configurable request safety margin without reducing a request
	 * below the minimum size supported by the local guard.
	 *
	 * @param int    $request_limit Provider request limit before the reserve.
	 * @param string $provider_id   Runtime-selected provider ID.
	 * @param string $model_id      Runtime-selected model ID.
	 * @return int Effective safety-margin bytes.
	 */
	private static function resolve_request_safety_margin_bytes( int $request_limit, string $provider_id, string $model_id ): int {
		$configured = self::DEFAULT_REQUEST_SAFETY_MARGIN_BYTES;

		/**
		 * Filter the reserve retained below a provider's request-size limit.
		 *
		 * @param int    $configured    Configured margin bytes.
		 * @param string $provider_id   Runtime-selected provider ID.
		 * @param string $model_id      Runtime-selected model ID.
		 * @param int    $request_limit Provider request limit before the reserve.
		 */
		$filtered = (int) apply_filters(
			'sd_ai_agent_provider_request_safety_margin_bytes',
			$configured,
			$provider_id,
			$model_id,
			$request_limit
		);

		return min( max( 0, $filtered ), max( 0, $request_limit - 1024 ) );
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

			// Check if every tool call has its own matching response. Counts matter
			// because older Gemini models may omit IDs from parallel calls, causing
			// multiple calls and responses to share the empty-string compatibility ID.
			if ( self::has_matching_tool_responses( $tool_call_ids, $response_ids ) ) {
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
	 * Determine whether each tool call ID has a distinct matching response ID.
	 *
	 * @param string[] $tool_call_ids Tool call IDs, including duplicate empty IDs.
	 * @param string[] $response_ids  Tool response IDs, including duplicate empty IDs.
	 */
	private static function has_matching_tool_responses( array $tool_call_ids, array $response_ids ): bool {
		$response_counts = array_count_values( $response_ids );

		foreach ( $tool_call_ids as $tool_call_id ) {
			if ( empty( $response_counts[ $tool_call_id ] ) ) {
				return false;
			}

			--$response_counts[ $tool_call_id ];
		}

		return true;
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
