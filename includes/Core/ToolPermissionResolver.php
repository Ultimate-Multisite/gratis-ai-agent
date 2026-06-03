<?php

declare(strict_types=1);
/**
 * Resolves tool permission requirements for the agent loop.
 *
 * Extracted from AgentLoop so the permission-checking and ability-classification
 * concern lives in one focused class.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use WordPress\AiClient\Messages\DTO\Message;

class ToolPermissionResolver {

	/**
	 * @param bool                     $yolo_mode        When true, skip all confirmations.
	 * @param array<int|string, mixed> $tool_permissions Tool permission levels from settings.
	 */
	public function __construct(
		private bool $yolo_mode = false,
		private array $tool_permissions = array()
	) {}

	/**
	 * Check which tool calls in an assistant message require user confirmation.
	 *
	 * Permission resolution order (first match wins):
	 * 1. YOLO mode → skip all confirmations.
	 * 2. Explicit tool_permissions setting ('auto'|'confirm'|'disabled'|'always_allow') → use it.
	 * 3. Annotation-based classification:
	 *    - readonly=true  → auto-execute (read-only, safe).
	 *    - readonly=false or null → require confirmation (write operation).
	 *
	 * @param Message $message The assistant's tool-call message.
	 * @return list<array<string, mixed>> Array of tool details needing confirmation (empty if none).
	 */
	public function get_tools_needing_confirmation( Message $message ): array {
		// YOLO mode: skip all confirmations and execute immediately.
		if ( $this->yolo_mode ) {
			return array();
		}

		$confirm       = array();
		$all_abilities = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();

		foreach ( $message->getParts() as $part ) {
			$call = $part->getFunctionCall();
			if ( ! $call ) {
				continue;
			}

			$fn_name = (string) $call->getName();

			// Non-ability function names are not executable WordPress tools.
			// Let the resolver return an invalid_ability_call tool result so the
			// provider receives a matched response for the tool_call ID instead of
			// pausing forever or sending an unpaired tool call back on the next turn.
			if ( ! str_starts_with( $fn_name, 'wpab__' ) ) {
				continue;
			}

			// Convert function name to ability name for lookups.
			$ability_name = $fn_name;
			if ( str_starts_with( $fn_name, 'wpab__' ) && class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
				$ability_name = \WP_AI_Client_Ability_Function_Resolver::function_name_to_ability_name( $fn_name );
			}

			// 1. Check explicit tool_permissions setting first.
			if ( ! empty( $this->tool_permissions ) ) {
				$permission = $this->tool_permissions[ $ability_name ] ?? null;

				if ( null !== $permission ) {
					// Explicit permission set for this tool.
					if ( 'confirm' === $permission ) {
						$confirm[] = array(
							'id'   => $call->getId(),
							'name' => $fn_name,
							'args' => $call->getArgs(),
						);
					}
					// 'auto', 'always_allow', 'disabled' → no confirmation needed.
					continue;
				}
			}

			// 2. No explicit permission — use annotation-based classification.
			$ability = $all_abilities[ $ability_name ] ?? null;

			if ( null !== $ability ) {
				$classification = self::classify_ability( $ability );

				if ( 'destructive' === $classification ) {
					$confirm[] = array(
						'id'   => $call->getId(),
						'name' => $fn_name,
						'args' => $call->getArgs(),
					);
				}
				// 'read' and 'write' → auto-execute.
			} else {
				// Ability not found in registry (e.g. custom tool) — default
				// to requiring confirmation for safety.
				$confirm[] = array(
					'id'   => $call->getId(),
					'name' => $fn_name,
					'args' => $call->getArgs(),
				);
			}
		}

		return $confirm;
	}

	/**
	 * Classify an ability as 'read', 'write', or 'destructive' based on its
	 * meta annotations.
	 *
	 * Uses the WordPress Abilities API annotations:
	 * - readonly=true                → 'read'        (auto-execute)
	 * - destructive=false            → 'write'       (safe write, auto-execute)
	 * - destructive=true             → 'destructive' (needs confirmation)
	 * - destructive=null & !readonly → 'destructive' (unknown risk, confirm)
	 *
	 * @param \WP_Ability $ability The ability to classify.
	 * @return string 'read', 'write', or 'destructive'.
	 */
	public static function classify_ability( \WP_Ability $ability ): string {
		$meta        = $ability->get_meta();
		$annotations = ( isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) )
			? $meta['annotations']
			: array();

		$readonly    = $annotations['readonly'] ?? null;
		$destructive = $annotations['destructive'] ?? null;

		// Explicitly read-only → always auto-execute.
		if ( true === $readonly ) {
			return 'read';
		}

		// Explicitly non-destructive → safe write, auto-execute.
		if ( false === $destructive ) {
			return 'write';
		}

		// Destructive or unknown → require confirmation.
		return 'destructive';
	}

	/**
	 * Persist an "always allow" permission for a specific ability.
	 *
	 * @param string $ability_name The ability name (e.g. 'sd-ai-agent/memory-save').
	 */
	public static function set_always_allow( string $ability_name ): void {
		$all   = Settings::instance()->get();
		$perms = $all['tool_permissions'] ?? array();

		// @phpstan-ignore-next-line
		$perms[ $ability_name ] = 'always_allow';

		Settings::instance()->update( array( 'tool_permissions' => $perms ) );
	}

	/**
	 * Get the list of abilities that have been set to "always allow".
	 *
	 * @return string[] Ability names with always_allow permission.
	 */
	public static function get_always_allowed(): array {
		$perms = Settings::instance()->get( 'tool_permissions' );

		if ( ! is_array( $perms ) ) {
			return array();
		}

		$always = array();
		foreach ( $perms as $name => $level ) {
			if ( 'always_allow' === $level ) {
				$always[] = $name;
			}
		}

		return $always;
	}
}
