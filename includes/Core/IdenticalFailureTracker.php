<?php

declare(strict_types=1);
/**
 * IdenticalFailureTracker
 *
 * Per-request bookkeeping for ability calls that return the *same* error in
 * response to the *same* arguments. Used by the agent loop to detect a model
 * spinning on a single failing call (typical of weaker tool-use models like
 * Kimi or smaller open-weight Llamas) and inject a hard-nudge message after
 * the second identical failure so the model is forced to either supply
 * different arguments or call a different ability.
 *
 * Lifetime: per HTTP request / per CLI invocation. {@see reset()} is called
 * from {@see AgentLoop::run()} at the top of every run.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IdenticalFailureTracker {

	/**
	 * Number of identical failures that triggers the hard-nudge response.
	 */
	public const NUDGE_AT = 2;

	/**
	 * Map of failure-signature => count.
	 *
	 * @var array<string, int>
	 */
	private static array $counts = array();

	/**
	 * Clear all failure history. Called once at the start of each agent run.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$counts = array();
	}

	/**
	 * Record one failure and return the new count.
	 *
	 * @param string $ability_name Fully qualified ability id.
	 * @param mixed  $args         Arguments passed to the ability (any JSON-serialisable shape).
	 * @param string $error_code   Error code returned by the ability (e.g. "ability_invalid_input").
	 * @return int The number of times this exact (ability, args, error_code) tuple has been seen.
	 */
	public static function record( string $ability_name, $args, string $error_code ): int {
		$key                  = self::signature( $ability_name, $args, $error_code );
		self::$counts[ $key ] = ( self::$counts[ $key ] ?? 0 ) + 1;
		return self::$counts[ $key ];
	}

	/**
	 * Whether the given count should trigger the hard-nudge response.
	 *
	 * @param int $count Number returned from {@see record()}.
	 * @return bool
	 */
	public static function should_nudge( int $count ): bool {
		return $count >= self::NUDGE_AT;
	}

	/**
	 * Build a hard-nudge message that tells the model to stop retrying and
	 * either supply different arguments or call a different ability.
	 *
	 * The message intentionally uses imperative language and inlines a
	 * copy-paste-ready example_arguments stub so the model has everything
	 * it needs in one place.
	 *
	 * @param string $ability_name The ability the model is spinning on.
	 * @param mixed  $input_schema The ability's input schema (will be JSON-encoded).
	 * @return string
	 */
	public static function nudge_message( string $ability_name, $input_schema ): string {
		$example      = \SdAiAgent\Tools\SchemaExampleBuilder::build_example( $input_schema );
		$example_json = empty( $example ) ? '{}' : (string) wp_json_encode( $example );

		return sprintf(
			"STOP. You have called `%s` with the same arguments and received the same error twice in a row. Do not retry with empty or unchanged arguments.\n\nYou MUST either:\n  (1) Retry only with arguments that look like this stub (replace every `<placeholder>` with a real value):\n      %s\n  (2) Or call a different ability that does not need those fields.\n\nIf you do not know a value, call a different ability to fetch it first.",
			$ability_name,
			$example_json
		);
	}

	/**
	 * Build a stable signature for a (ability, args, error_code) tuple.
	 *
	 * @param string $ability_name Fully qualified ability name.
	 * @param mixed  $args         Arguments passed to the ability.
	 * @param string $error_code   Error code returned by the ability.
	 * @return string
	 */
	private static function signature( string $ability_name, $args, string $error_code ): string {
		return $ability_name . '|' . md5( (string) wp_json_encode( $args ) ) . '|' . $error_code;
	}
}
