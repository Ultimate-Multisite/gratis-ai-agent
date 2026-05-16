<?php
/**
 * AI Client Event Trace Logger — captures SDK events and writes structured trace rows.
 *
 * Listens on wp_ai_client_before_generate_result and wp_ai_client_after_generate_result
 * to capture structured trace data including capability, provider, model, finish reason,
 * token usage, and result metadata.
 *
 * Correlates Before/After event pairs using spl_object_id() to compute duration and
 * handle nested/concurrent SDK calls safely.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Core;

use SdAiAgent\Models\ProviderTrace;
use WordPress\AiClient\Events\AfterGenerateResultEvent;
use WordPress\AiClient\Events\BeforeGenerateResultEvent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AiClientEventTraceLogger {

	/**
	 * In-flight Before-event data, ordered as a LIFO stack.
	 *
	 * Each entry stores the data captured when a BeforeGenerateResultEvent
	 * fires; the matching AfterGenerateResultEvent pops the most recent
	 * entry to compute duration and write the trace row.
	 *
	 * NB: the original implementation keyed this by spl_object_id() of the
	 * event object, but the SDK dispatches two distinct event objects (one
	 * BeforeGenerateResultEvent and one AfterGenerateResultEvent — see
	 * lib/php-ai-client/src/Builders/PromptBuilder.php:831-835), so the
	 * After event's spl_object_id never matched the Before's and the trace
	 * row was never written. PHP's single-threaded request model guarantees
	 * a Before is followed by either its After or by a nested Before (for
	 * nested generation calls); a LIFO stack handles both cases correctly.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $inflight = [];

	/**
	 * Hook: wp_ai_client_before_generate_result — capture request metadata.
	 *
	 * Pushes a new in-flight entry onto the LIFO stack containing the
	 * request timestamp, messages, model, provider, and capability so the
	 * matching After event can compute duration and write a complete trace row.
	 *
	 * @param BeforeGenerateResultEvent $event The before-generate event.
	 * @return void
	 */
	public static function on_before_generate_result( BeforeGenerateResultEvent $event ): void {
		if ( ! ProviderTrace::is_enabled() ) {
			return;
		}

		// Extract model and provider metadata.
		$model       = $event->getModel();
		$model_id    = $model->metadata()->getId();
		$provider_id = $model->providerMetadata()->getId();

		// Extract capability if present.
		$capability       = $event->getCapability();
		$capability_value = null !== $capability ? $capability->value : null;

		// Push onto in-flight stack for pairing with the After event.
		self::$inflight[] = [
			'model_id'    => $model_id,
			'provider_id' => $provider_id,
			'capability'  => $capability_value,
			'messages'    => $event->getMessages(),
			'start_time'  => microtime( true ),
		];
	}

	/**
	 * Hook: wp_ai_client_after_generate_result — capture response and write trace row.
	 *
	 * Pops the most recent Before entry from the LIFO stack, computes
	 * duration, extracts finish reason and token usage from the result,
	 * and writes a structured trace row.
	 *
	 * NB: cache_creation_tokens / cache_read_tokens are kept on the schema
	 * for forward-compat (Anthropic exposes them on its native API), but the
	 * shipped php-ai-client TokenUsage DTO only carries
	 * promptTokens/completionTokens/totalTokens/thoughtTokens. Until the
	 * SDK adds dedicated cache fields, both columns are written as 0 here;
	 * the HTTP trace channel still captures the raw provider response and
	 * exposes the cache tokens there if needed.
	 *
	 * @param AfterGenerateResultEvent $event The after-generate event.
	 * @return void
	 */
	public static function on_after_generate_result( AfterGenerateResultEvent $event ): void {
		if ( ! ProviderTrace::is_enabled() ) {
			return;
		}

		$inflight = array_pop( self::$inflight );

		if ( null === $inflight ) {
			// No matching Before event was recorded. This can happen when
			// tracing was toggled on between the Before and After events,
			// or when the After event fires without a Before (shouldn't
			// happen under the SDK's PromptBuilder dispatch, but we guard
			// against it anyway).
			return;
		}

		$start_time  = (float) ( $inflight['start_time'] ?? microtime( true ) );
		$duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );

		$result = $event->getResult();

		// Serialize messages and result for storage. (Finish reason and token
		// counts are extracted inside serialize_result so the JSON payload
		// carries them; the dedicated trace columns below capture only the
		// fields that have first-class schema columns.)
		$messages      = isset( $inflight['messages'] ) && is_array( $inflight['messages'] ) ? $inflight['messages'] : [];
		$messages_json = self::serialize_messages( $messages );
		$result_json   = self::serialize_result( $result );

		// Write the structured trace row.
		ProviderTrace::insert(
			[
				'provider_id'           => $inflight['provider_id'] ?? '',
				'model_id'              => $inflight['model_id'] ?? '',
				'url'                   => '', // SDK events don't have a URL; HTTP trace captures that.
				'method'                => 'SDK',
				'status_code'           => 200, // SDK events only fire on success; exceptions don't reach here.
				'duration_ms'           => $duration_ms,
				// SDK TokenUsage DTO has no cache token fields; HTTP trace
				// still captures them from the raw provider response.
				'cache_creation_tokens' => 0,
				'cache_read_tokens'     => 0,
				'request_headers'       => '{}', // SDK events don't have HTTP headers.
				'request_body'          => $messages_json,
				'response_headers'      => '{}', // SDK events don't have HTTP headers.
				'response_body'         => $result_json,
				'error'                 => '', // SDK events only fire on success.
			]
		);
	}

	/**
	 * Write a synthetic trace row for a Before event that never received an After.
	 *
	 * Called by the watchdog cleanup to record stalled/exception cases where
	 * the SDK request was initiated but never completed (SDK exception, timeout,
	 * malformed response, etc.).
	 *
	 * @param array<string, mixed> $inflight The in-flight Before event data.
	 * @return void
	 */
	private static function write_stalled_trace( array $inflight ): void {
		// Serialize messages for storage. (`$inflight` is typed array<string, mixed>
		// so we narrow the messages slot to array<int, mixed> here before passing
		// it to serialize_messages, which filters non-Message entries internally.)
		$messages      = isset( $inflight['messages'] ) && is_array( $inflight['messages'] ) ? $inflight['messages'] : [];
		$messages_json = self::serialize_messages( $messages );

		// Write a synthetic trace row with error='no_result_event'.
		ProviderTrace::insert(
			[
				'provider_id'           => $inflight['provider_id'] ?? '',
				'model_id'              => $inflight['model_id'] ?? '',
				'url'                   => '', // SDK events don't have a URL.
				'method'                => 'SDK',
				'status_code'           => 0, // No response received.
				'duration_ms'           => (int) round( ( microtime( true ) - (float) ( $inflight['start_time'] ?? microtime( true ) ) ) * 1000 ),
				'cache_creation_tokens' => 0,
				'cache_read_tokens'     => 0,
				'request_headers'       => '{}',
				'request_body'          => $messages_json,
				'response_headers'      => '{}',
				'response_body'         => '{}',
				'error'                 => 'no_result_event', // Indicates Before without After.
			]
		);
	}

	/**
	 * Cleanup stalled Before events that never received an After.
	 *
	 * Called via a shutdown hook to detect and record any Before events that
	 * were recorded but never matched with an After event. This catches SDK
	 * exceptions, timeouts, and other failure modes that prevent the After
	 * event from firing.
	 *
	 * @return void
	 */
	public static function cleanup_stalled_events(): void {
		if ( ! ProviderTrace::is_enabled() ) {
			return;
		}

		foreach ( self::$inflight as $inflight ) {
			self::write_stalled_trace( $inflight );
		}

		// Clear the in-flight stack.
		self::$inflight = [];
	}

	/**
	 * Reset the in-flight stack.
	 *
	 * Used by tests to clear state between cases. Production code does
	 * not need to call this — `array_pop()` in on_after_generate_result
	 * and `cleanup_stalled_events()` keep the stack bounded.
	 *
	 * @internal Test helper. Do not call from production code.
	 * @return void
	 */
	public static function reset_inflight_for_tests(): void {
		self::$inflight = [];
	}

	/**
	 * Serialize messages to JSON for storage.
	 *
	 * Converts the Message[] array to a JSON string for storage in the
	 * request_body field of the trace row. Walks each Message's parts and
	 * concatenates text-typed parts; function-call/file parts are summarised
	 * with a short type marker so trace viewers can still see them without
	 * the JSON ballooning.
	 *
	 * Accepts `array<array-key, mixed>` so callers that pull from the
	 * untyped `$inflight['messages']` slot (which originates from the SDK
	 * Before event's getMessages() return value) can pass it through without
	 * a cast. Non-Message entries are silently skipped, matching the
	 * trace-channel "lossy but never crashes" contract.
	 *
	 * @param array<array-key, mixed> $messages The messages array.
	 * @return string JSON-encoded messages.
	 */
	private static function serialize_messages( array $messages ): string {
		$serialized = [];
		foreach ( $messages as $message ) {
			if ( ! ( $message instanceof \WordPress\AiClient\Messages\DTO\Message ) ) {
				continue;
			}
			$serialized[] = [
				'role'    => $message->getRole()->value,
				'content' => self::extract_message_text( $message ),
			];
		}
		$encoded = wp_json_encode( $serialized );
		return false !== $encoded ? $encoded : '[]';
	}

	/**
	 * Pull a plain-text representation out of a Message's parts.
	 *
	 * For text parts this is just MessagePart::getText(). For
	 * function-call / function-response / file parts we emit a short tag
	 * (`[function_call:<name>]`, `[function_response]`, `[file]`) so the
	 * payload remains scannable without dumping large binary blobs.
	 *
	 * @param \WordPress\AiClient\Messages\DTO\Message $message Message to flatten.
	 * @return string Concatenated text representation.
	 */
	private static function extract_message_text( \WordPress\AiClient\Messages\DTO\Message $message ): string {
		$pieces = [];
		foreach ( $message->getParts() as $part ) {
			$type = $part->getType()->value;
			if ( 'text' === $type ) {
				$text = $part->getText();
				if ( null !== $text && '' !== $text ) {
					$pieces[] = $text;
				}
				continue;
			}
			if ( 'function_call' === $type ) {
				$fc       = $part->getFunctionCall();
				$pieces[] = '[function_call:' . ( $fc ? $fc->getName() : '' ) . ']';
				continue;
			}
			if ( 'function_response' === $type ) {
				$pieces[] = '[function_response]';
				continue;
			}
			if ( 'file' === $type ) {
				$pieces[] = '[file]';
				continue;
			}
			$pieces[] = '[' . $type . ']';
		}
		return implode( "\n", $pieces );
	}

	/**
	 * Serialize result to JSON for storage.
	 *
	 * Converts the GenerativeAiResult object to a JSON string for storage in
	 * the response_body field of the trace row.
	 *
	 * @param \WordPress\AiClient\Results\DTO\GenerativeAiResult $result The result object.
	 * @return string JSON-encoded result.
	 */
	private static function serialize_result( \WordPress\AiClient\Results\DTO\GenerativeAiResult $result ): string {
		$token_usage    = $result->getTokenUsage();
		$prompt_tokens  = $token_usage->getPromptTokens();
		$compl_tokens   = $token_usage->getCompletionTokens();
		$thought_tokens = $token_usage->getThoughtTokens();

		$serialized = [
			'id'    => $result->getId(),
			'model' => $result->getModelMetadata()->getId(),
			'usage' => [
				// Use input/output here for cross-provider readability — the
				// SDK uses prompt/completion (OpenAI's naming).
				'input_tokens'   => $prompt_tokens,
				'output_tokens'  => $compl_tokens,
				'total_tokens'   => $token_usage->getTotalTokens(),
				'thought_tokens' => null !== $thought_tokens ? $thought_tokens : 0,
			],
		];

		// Add candidates with finish reasons + flattened content.
		$candidates = $result->getCandidates();
		if ( ! empty( $candidates ) ) {
			$serialized['candidates'] = [];
			foreach ( $candidates as $candidate ) {
				$serialized['candidates'][] = [
					'finish_reason' => $candidate->getFinishReason()->value,
					'content'       => self::extract_message_text( $candidate->getMessage() ),
				];
			}
		}

		$encoded = wp_json_encode( $serialized );
		return false !== $encoded ? $encoded : '{}';
	}
}
