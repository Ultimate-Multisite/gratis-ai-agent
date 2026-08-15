<?php

declare(strict_types=1);
/**
 * Reuses safe, local read results within one agent-loop run.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

/**
 * Keeps a deliberately narrow cache of immutable local file reads.
 *
 * Browser and network tools are volatile by definition. Other readonly
 * abilities can also depend on mutable WordPress state, so they remain opt-in
 * until they declare a stronger cache contract. This prevents accidental
 * caching from changing the behaviour of an existing ability.
 */
class RunLocalReadonlyToolCache {

	/** @var array<string, true> Fingerprints executed in this run. */
	private array $executed = array();

	/** @var int Number of calls replaced by prior-result references. */
	private int $reused_calls = 0;

	/** @var int Number of batches which repeated a prior inspection. */
	private int $repeated_call_warnings = 0;

	/**
	 * Split a model tool-call message into calls that must execute and concise
	 * responses for safe calls that already executed in this run.
	 *
	 * @param Message $message Assistant tool-call message.
	 * @return array{execute:Message,reused:?Message,count:int}
	 */
	public function reuse( Message $message ): array {
		$execute_parts  = array();
		$response_parts = array();
		$reused         = 0;

		if ( $this->message_has_mutation( $message ) ) {
			$this->executed = array();
		}

		foreach ( $message->getParts() as $part ) {
			$call = $part->getFunctionCall();
			if ( ! $call ) {
				$execute_parts[] = $part;
				continue;
			}

			$key = $this->fingerprint( $call );
			if (
				'' === $key
				|| ! $this->is_cacheable_local_read( $call )
				|| ! isset( $this->executed[ $key ] )
			) {
				$execute_parts[] = $part;
				continue;
			}

			++$reused;
			$response_parts[] = new MessagePart(
				new FunctionResponse(
					(string) $call->getId(),
					(string) $call->getName(),
					array(
						'reused'  => true,
						'message' => 'This unchanged local file was already read earlier in this run. Use the prior result in the conversation rather than reading it again.',
					)
				)
			);
		}

		if ( $reused > 0 ) {
			$this->reused_calls += $reused;
			++$this->repeated_call_warnings;
		}

		return array(
			'execute' => new ModelMessage( $execute_parts ),
			'reused'  => empty( $response_parts ) ? null : new UserMessage( $response_parts ),
			'count'   => $reused,
		);
	}

	/**
	 * Record safe local reads which completed successfully.
	 *
	 * @param Message      $message   Assistant tool-call message.
	 * @param Message|null $responses Tool responses, when available.
	 */
	public function record( Message $message, ?Message $responses = null ): void {
		foreach ( $message->getParts() as $part ) {
			$call = $part->getFunctionCall();
			if (
				! $call
				|| ! $this->is_cacheable_local_read( $call )
				|| ( null !== $responses && ! $this->has_successful_response( $call, $responses ) )
			) {
				continue;
			}

			$key = $this->fingerprint( $call );
			if ( '' !== $key ) {
				$this->executed[ $key ] = true;
			}
		}
	}

	/**
	 * @param FunctionCall $call      File read that completed.
	 * @param Message      $responses Tool response message.
	 * @return bool True when the matching response did not report an error.
	 */
	private function has_successful_response( FunctionCall $call, Message $responses ): bool {
		foreach ( $responses->getParts() as $part ) {
			$response = $part->getFunctionResponse();
			if ( ! $response || (string) $response->getId() !== (string) $call->getId() ) {
				continue;
			}

			$result = $response->getResponse();
			return ! $result instanceof \WP_Error
				&& ( ! is_array( $result ) || ! array_key_exists( 'error', $result ) );
		}

		return false;
	}

	/**
	 * Return lightweight per-run diagnostics for result payloads and logs.
	 *
	 * @return array{unique_readonly_calls:int,reused_readonly_calls:int,repeated_call_warnings:int}
	 */
	public function get_diagnostics(): array {
		return array(
			'unique_readonly_calls'  => count( $this->executed ),
			'reused_readonly_calls'  => $this->reused_calls,
			'repeated_call_warnings' => $this->repeated_call_warnings,
		);
	}

	/**
	 * @param Message $message Assistant tool-call message.
	 * @return bool True when the batch contains a potentially invalidating call.
	 */
	private function message_has_mutation( Message $message ): bool {
		foreach ( $message->getParts() as $part ) {
			$call = $part->getFunctionCall();
			if ( $call && ! $this->is_cacheable_local_read( $call ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param FunctionCall $call Function call to evaluate.
	 * @return bool True when the call is a safe local file read.
	 */
	private function is_cacheable_local_read( FunctionCall $call ): bool {
		$name = self::canonical_name( (string) $call->getName() );
		if ( 'sd-ai-agent/file-read' !== $name ) {
			return false;
		}

		$args = $call->getArgs();
		return is_array( $args )
			&& isset( $args['path'] )
			&& is_string( $args['path'] )
			&& '' !== $args['path'];
	}

	/**
	 * @param FunctionCall $call Function call to fingerprint.
	 * @return string Stable call fingerprint, or an empty string when unavailable.
	 */
	private function fingerprint( FunctionCall $call ): string {
		$name = self::canonical_name( (string) $call->getName() );
		if ( '' === $name ) {
			return '';
		}

		$args = wp_json_encode( self::canonicalize_args( $call->getArgs() ) );
		return is_string( $args ) ? hash( 'sha256', $name . "\n" . $args ) : '';
	}

	/**
	 * @param mixed $value Raw function arguments.
	 * @return mixed Canonical arguments.
	 */
	private static function canonicalize_args( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize_args( $item );
		}

		return $value;
	}

	private static function canonical_name( string $name ): string {
		if ( str_starts_with( $name, 'wpab__sd-ai-agent__' ) ) {
			return 'sd-ai-agent/' . substr( $name, strlen( 'wpab__sd-ai-agent__' ) );
		}

		return $name;
	}
}
