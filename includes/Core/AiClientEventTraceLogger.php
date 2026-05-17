<?php
/**
 * AI Client Event Trace Logger — captures SDK events and writes structured trace rows.
 *
 * Listens on wp_ai_client_before_generate_result and wp_ai_client_after_generate_result
 * to capture structured trace data including capability, provider, model, finish reason,
 * token usage, and result metadata.
 *
 * Correlates Before/After event pairs with a LIFO stack. The SDK constructs
 * distinct event objects for Before vs After (lib/php-ai-client PromptBuilder.php
 * dispatches `new BeforeGenerateResultEvent(...)` and `new AfterGenerateResultEvent(...)`
 * on the same call), so spl_object_id() can never match. PHP is single-threaded
 * and SDK calls nest Before→{request}→After in last-in-first-out order, so the
 * After handler always pairs with the most recently pushed Before — even across
 * nested provider calls.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Core;

use SdAiAgent\Models\ProviderTrace;
use WordPress\AiClient\Events\AfterGenerateResultEvent;
use WordPress\AiClient\Events\BeforeGenerateResultEvent;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AiClientEventTraceLogger {

	/**
	 * In-flight Before-event data as a LIFO stack.
	 *
	 * Stores Before event metadata so the matching After event can compute
	 * duration and write a complete trace row. Implemented as a LIFO stack
	 * (`array_push` on Before, `array_pop` on After) because the SDK
	 * dispatches distinct event objects for Before/After (so spl_object_id
	 * cannot correlate them) and PHP is single-threaded (so nested calls
	 * naturally pair LIFO).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $inflight = [];

	/**
	 * Hook: wp_ai_client_before_generate_result — capture request metadata.
	 *
	 * Records the event timestamp, messages, model, and capability so the
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

		// Push onto the LIFO stack; the matching After will pop it.
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
	 * Pops the most recent Before entry off the LIFO stack, computes duration,
	 * extracts finish reason and token usage from the result, and writes a
	 * structured trace row. Pairing via LIFO is correct because PHP is
	 * single-threaded and the SDK always dispatches Before→{request}→After
	 * synchronously on the same call, even when calls nest.
	 *
	 * @param AfterGenerateResultEvent $event The after-generate event.
	 * @return void
	 */
	public static function on_after_generate_result( AfterGenerateResultEvent $event ): void {
		// Pop the matching Before from the LIFO stack, regardless of tracing state.
		// This prevents stale entries from accumulating if tracing is toggled
		// between Before and After events.
		$inflight = array_pop( self::$inflight );

		if ( ! ProviderTrace::is_enabled() ) {
			return;
		}
		if ( null === $inflight ) {
			// No Before recorded (tracing toggled on between Before and After,
			// or an unbalanced After fired).
			return;
		}

		$start_time  = (float) ( $inflight['start_time'] ?? microtime( true ) );
		$duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );

		$result = $event->getResult();

		// Extract finish reason from the first candidate (if present).
		$finish_reason = '';
		$candidates    = $result->getCandidates();
		if ( ! empty( $candidates ) ) {
			$first_candidate = $candidates[0];
			$finish_reason   = $first_candidate->getFinishReason()->value ?? '';
		}

		// Cache token breakdown is not exposed by the SDK's TokenUsage DTO;
		// HTTP traces (source=http) capture provider cache tokens from raw
		// responses where available. Prompt/completion/total/thought tokens
		// are written into the structured response_body via serialize_result().
		$cache_creation_tokens = 0;
		$cache_read_tokens     = 0;

		// Serialize messages and result for storage. Narrow the mixed
		// $inflight['messages'] slot to array before passing —
		// serialize_messages instanceof-filters non-Message entries
		// defensively. serialize_result is similarly type-guarded.
		$messages      = is_array( $inflight['messages'] ?? null ) ? $inflight['messages'] : [];
		$messages_json = self::serialize_messages( $messages );
		$result_json   = is_object( $result ) ? self::serialize_result( $result ) : '{}';

		// Write the structured trace row.
		ProviderTrace::insert(
			[
				'provider_id'           => $inflight['provider_id'] ?? '',
				'model_id'              => $inflight['model_id'] ?? '',
				'url'                   => '', // SDK events don't have a URL; HTTP trace captures that.
				'method'                => 'SDK',
				'status_code'           => 200, // SDK events only fire on success; exceptions don't reach here.
				'duration_ms'           => $duration_ms,
				'cache_creation_tokens' => max( 0, (int) $cache_creation_tokens ),
				'cache_read_tokens'     => max( 0, (int) $cache_read_tokens ),
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
		// Serialize messages for storage. Narrow the mixed slot to array.
		$messages      = is_array( $inflight['messages'] ?? null ) ? $inflight['messages'] : [];
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
		// Always clear the stack, regardless of tracing state. This prevents
		// stale entries from accumulating if tracing is toggled off.
		if ( ProviderTrace::is_enabled() ) {
			foreach ( self::$inflight as $inflight ) {
				self::write_stalled_trace( $inflight );
			}
		}

		// Clear the stack.
		self::$inflight = [];
	}

	/**
	 * Reset the in-flight stack. Intended for tests only.
	 *
	 * Allows tests that exercise the LIFO stack across multiple cases to
	 * start each case with a clean stack without relying on shutdown hooks.
	 *
	 * @internal
	 * @return void
	 */
	public static function reset_inflight_for_tests(): void {
		self::$inflight = [];
	}

	/**
	 * Serialize messages to JSON for storage.
	 *
	 * Converts the Message[] array to a JSON string for storage in the
	 * request_body field of the trace row. Each Message is serialised via
	 * its own toArray(), which yields the canonical {role, parts[]} shape
	 * defined by the WordPress AI Client SDK.
	 *
	 * Non-Message entries are skipped defensively so the watchdog cannot
	 * crash on a malformed in-flight payload.
	 *
	 * @param array<array-key, mixed> $messages The messages array (expected: list<Message>; callers may pass an associative array if a Before payload was malformed).
	 * @return string JSON-encoded messages.
	 */
	private static function serialize_messages( array $messages ): string {
		$serialized = [];
		foreach ( $messages as $message ) {
			if ( ! $message instanceof Message ) {
				continue;
			}
			$serialized[] = $message->toArray();
		}
		$encoded = wp_json_encode( $serialized );
		return false !== $encoded ? $encoded : '[]';
	}

	/**
	 * Serialize result to JSON for storage.
	 *
	 * Converts the GenerativeAiResult object to a JSON string for storage in
	 * the response_body field of the trace row. Uses the real SDK getters:
	 * getModelMetadata()->getId() (there is no getModel()), getPromptTokens
	 * /getCompletionTokens/getTotalTokens/getThoughtTokens on TokenUsage
	 * (there are no getInput/Output/CacheCreation/CacheRead getters), and
	 * Candidate::getMessage() (there is no getContent()).
	 *
	 * @param object $result Expected: GenerativeAiResult.
	 * @return string JSON-encoded result.
	 */
	private static function serialize_result( object $result ): string {
		if ( ! $result instanceof GenerativeAiResult ) {
			return '{}';
		}

		$token_usage = $result->getTokenUsage();

		$serialized = [
			'id'    => $result->getId(),
			'model' => $result->getModelMetadata()->getId(),
			'usage' => [
				'prompt_tokens'     => $token_usage->getPromptTokens(),
				'completion_tokens' => $token_usage->getCompletionTokens(),
				'total_tokens'      => $token_usage->getTotalTokens(),
				'thought_tokens'    => $token_usage->getThoughtTokens() ?? 0,
			],
		];

		$candidates = $result->getCandidates();
		if ( ! empty( $candidates ) ) {
			$serialized['candidates'] = [];
			foreach ( $candidates as $candidate ) {
				if ( ! $candidate instanceof Candidate ) {
					continue;
				}
				$serialized['candidates'][] = [
					'finish_reason' => $candidate->getFinishReason()->value ?? '',
					'message'       => $candidate->getMessage()->toArray(),
				];
			}
		}

		$encoded = wp_json_encode( $serialized );
		return false !== $encoded ? $encoded : '{}';
	}
}
