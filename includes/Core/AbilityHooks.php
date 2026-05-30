<?php

declare(strict_types=1);

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability execution hook system.
 *
 * Provides WordPress actions and filters that fire before and after each
 * ability (tool) is executed by the AI agent. This allows third-party plugins
 * and themes to observe, modify, or block ability execution without patching
 * core plugin files.
 *
 * ## Available hooks
 *
 * ### Actions
 *
 * `sd_ai_agent_before_ability`
 *   Fires before an ability is executed.
 *
 *   @param string $ability_name  The ability name (e.g. "sd-ai-agent/memory-save").
 *   @param array  $args          The arguments passed to the ability.
 *   @param string $call_id       The unique function call ID from the model.
 *
 * `sd_ai_agent_after_ability`
 *   Fires after an ability has executed (whether it succeeded or failed).
 *   @param string $ability_name  The ability name.
 *   @param array  $args          The arguments that were passed.
 *   @param mixed  $result        The result returned by the ability (array or WP_Error).
 *   @param string $call_id       The unique function call ID.
 *
 * `sd_ai_agent_ability_error`
 *   Fires when an ability returns a WP_Error.
 *   @param string   $ability_name  The ability name.
 *   @param array    $args          The arguments that were passed.
 *   @param \WP_Error $error        The WP_Error returned by the ability.
 *   @param string   $call_id       The unique function call ID.
 *
 * ### Filters
 *
 * `sd_ai_agent_ability_args`
 *   Filters the arguments before they are passed to an ability.
 *   Return a modified array to change what the ability receives.
 *   Return null to use the original args.
 *   @param array|null $args         The arguments (may be null for no-arg abilities).
 *   @param string     $ability_name The ability name.
 *   @param string     $call_id      The unique function call ID.
 *
 * `sd_ai_agent_ability_result`
 *   Filters the result after an ability executes.
 *   Return a modified array or WP_Error to change what the model sees.
 *   @param mixed  $result        The result returned by the ability.
 *   @param string $ability_name  The ability name.
 *   @param array  $args          The arguments that were passed.
 *   @param string $call_id       The unique function call ID.
 *
 * `sd_ai_agent_ability_blocked`
 *   Filters whether an ability should be blocked before execution.
 *   Return true to block the ability (the model will receive an error response).
 *   @param bool   $blocked       Whether the ability is blocked (default false).
 *   @param string $ability_name  The ability name.
 *   @param array  $args          The arguments that would be passed.
 *   @param string $call_id       The unique function call ID.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */
class AbilityHooks {

	/**
	 * Fire the before-ability action and return filtered args.
	 *
	 * @param string                    $ability_name The ability name.
	 * @param array<string, mixed>|null $args    The raw arguments from the model.
	 * @param string                    $call_id The function call ID.
	 * @return array<string, mixed>|null Filtered arguments.
	 */
	public static function before( string $ability_name, ?array $args, string $call_id ): ?array {
		/**
		 * Fires before an ability is executed.
		 *
		 * @param string     $ability_name The ability name (e.g. "sd-ai-agent/memory-save").
		 * @param array|null $args         The arguments passed to the ability.
		 * @param string     $call_id      The unique function call ID from the model.
		 */
		do_action( 'sd_ai_agent_before_ability', $ability_name, $args, $call_id );

		/**
		 * Filters the arguments before they are passed to an ability.
		 *
		 * @param array|null $args         The arguments (may be null for no-arg abilities).
		 * @param string     $ability_name The ability name.
		 * @param string     $call_id      The unique function call ID.
		 */
		/** @var array<string, mixed>|null $filtered */
		$filtered = apply_filters( 'sd_ai_agent_ability_args', $args, $ability_name, $call_id );
		return $filtered;
	}

	/**
	 * Check whether an ability should be blocked before execution.
	 *
	 * @param string                    $ability_name The ability name.
	 * @param array<string, mixed>|null $args         The arguments that would be passed.
	 * @param string                    $call_id      The function call ID.
	 * @return bool True if the ability should be blocked.
	 */
	public static function is_blocked( string $ability_name, ?array $args, string $call_id ): bool {
		/**
		 * Filters whether an ability should be blocked before execution.
		 *
		 * Return true to block the ability. The model will receive an error
		 * response with code 'ability_blocked'.
		 *
		 * @param bool       $blocked      Whether the ability is blocked (default false).
		 * @param string     $ability_name The ability name.
		 * @param array|null $args         The arguments that would be passed.
		 * @param string     $call_id      The unique function call ID.
		 */
		return (bool) apply_filters( 'sd_ai_agent_ability_blocked', false, $ability_name, $args, $call_id );
	}

	/**
	 * Fire the after-ability action and return filtered result.
	 *
	 * @param string                    $ability_name The ability name.
	 * @param array<string, mixed>|null $args         The arguments that were passed.
	 * @param mixed                     $result       The raw result from the ability.
	 * @param string                    $call_id      The function call ID.
	 * @return mixed Filtered result.
	 */
	public static function after( string $ability_name, ?array $args, mixed $result, string $call_id ): mixed {
		if ( is_wp_error( $result ) ) {
			/**
			 * Fires when an ability returns a WP_Error.
			 *
			 * @param string    $ability_name The ability name.
			 * @param array|null $args        The arguments that were passed.
			 * @param \WP_Error $error        The WP_Error returned by the ability.
			 * @param string    $call_id      The unique function call ID.
			 */
			do_action( 'sd_ai_agent_ability_error', $ability_name, $args, $result, $call_id );
		}

		/**
		 * Fires after an ability has executed (whether it succeeded or failed).
		 *
		 * @param string     $ability_name The ability name.
		 * @param array|null $args         The arguments that were passed.
		 * @param mixed      $result       The result returned by the ability (array or WP_Error).
		 * @param string     $call_id      The unique function call ID.
		 */
		do_action( 'sd_ai_agent_after_ability', $ability_name, $args, $result, $call_id );

		/**
		 * Filters the result after an ability executes.
		 *
		 * Return a modified array or WP_Error to change what the model sees.
		 *
		 * @param mixed      $result       The result returned by the ability.
		 * @param string     $ability_name The ability name.
		 * @param array|null $args         The arguments that were passed.
		 * @param string     $call_id      The unique function call ID.
		 */
		return apply_filters( 'sd_ai_agent_ability_result', $result, $ability_name, $args, $call_id );
	}
}
