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
	 * Ability IDs approved for exactly this confirmed tool-resume request.
	 *
	 * @var array<string, true>
	 */
	private static array $one_turn_approved_abilities = array();

	/**
	 * @param bool                     $yolo_mode        When true, skip all confirmations.
	 * @param array<int|string, mixed> $tool_permissions Tool permission levels from settings.
	 * @param bool                     $requiresMutationClarification Whether the current prompt lacks an actionable objective.
	 * @param bool                     $allowsExplicitDraftProposal   Whether the user explicitly requested a draft proposal.
	 */
	public function __construct(
		private bool $yolo_mode = false,
		private array $tool_permissions = array(),
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Project property naming guidance requires camelCase.
		private bool $requiresMutationClarification = false,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Project property naming guidance requires camelCase.
		private bool $allowsExplicitDraftProposal = false
	) {}

	/**
	 * Check which tool calls in an assistant message require user confirmation.
	 *
	 * Permission resolution order (first match wins):
	 * 1. An underspecified request → require confirmation for every mutation,
	 *    except a user-requested, explicitly identified create-post draft proposal.
	 * 2. YOLO mode → skip all other confirmations.
	 * 3. Explicit tool_permissions setting:
	 *    - confirm → require confirmation.
	 *    - always_allow/disabled → do not pause here (disabled is enforced before execution).
	 *    - auto or unset → continue to the default annotation policy.
	 * 4. Annotation-based classification:
	 *    - readonly=true      → auto-execute (read-only, safe).
	 *    - destructive=false  → auto-execute (non-destructive write).
	 *    - destructive=true or unknown → require confirmation.
	 * 5. For sd-ai-agent/ability-call, classify the nested target ability so the
	 *    meta-tool cannot bypass a target tool's confirmation policy.
	 *
	 * @param Message $message The assistant's tool-call message.
	 * @return list<array<string, mixed>> Array of tool details needing confirmation (empty if none).
	 */
	public function get_tools_needing_confirmation( Message $message ): array {
		// YOLO mode: skip all confirmations and execute immediately. A prompt
		// with no stated objective is the exception: it must not manufacture a
		// mutation merely because a site-wide convenience setting is enabled.
		if ( $this->yolo_mode && ! $this->requiresMutationClarification ) {
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

			$args                    = self::normalize_function_call_args( $call->getArgs() );
			$confirmation_ability_id = self::get_confirmation_ability_id( $ability_name, $args, $all_abilities );
			$ability                 = $all_abilities[ $confirmation_ability_id ] ?? null;
			$ability                 = $ability instanceof \WP_Ability ? $ability : null;
			$is_explicit_draft       = $this->allowsExplicitDraftProposal
				&& self::is_explicit_draft_creation( $ability_name, $confirmation_ability_id, $args );

			$requires_clarification = $this->requiresMutationClarification
				&& $ability instanceof \WP_Ability
				&& 'read' !== self::classify_ability( $ability )
				&& ! $is_explicit_draft;

			if ( $requires_clarification || self::ability_needs_confirmation( $confirmation_ability_id, $ability, $this->tool_permissions ) ) {
				$confirm[] = array(
					'id'      => $call->getId(),
					'name'    => $fn_name,
					'ability' => $confirmation_ability_id,
					'args'    => $call->getArgs(),
					'reason'  => $requires_clarification ? 'underspecified_request' : 'tool_permission',
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
	 * Whether a tool-call message contains at least one non-read-only ability.
	 *
	 * Meta ability calls are classified by their nested target. Unknown registered
	 * abilities are treated as mutations so an incomplete annotation cannot make a
	 * potentially destructive call look like a harmless inspection.
	 *
	 * @param Message $message Assistant tool-call message.
	 */
	public static function message_has_mutating_tools( Message $message ): bool {
		$all_abilities = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();

		foreach ( $message->getParts() as $part ) {
			$call = $part->getFunctionCall();
			if ( ! $call ) {
				continue;
			}

			$function_name = (string) $call->getName();
			if ( ! str_starts_with( $function_name, 'wpab__' ) ) {
				continue;
			}

			$ability_name = $function_name;
			if ( class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
				$ability_name = \WP_AI_Client_Ability_Function_Resolver::function_name_to_ability_name( $function_name );
			}

			$args        = self::normalize_function_call_args( $call->getArgs() );
			$governed_id = self::get_confirmation_ability_id( $ability_name, $args, $all_abilities );
			$ability     = $all_abilities[ $governed_id ] ?? null;
			if ( ! $ability instanceof \WP_Ability || 'read' !== self::classify_ability( $ability ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an ability should pause for user confirmation.
	 *
	 * @param string                   $ability_name     Ability ID.
	 * @param \WP_Ability|null         $ability          Registered ability, if known.
	 * @param array<int|string, mixed> $tool_permissions Per-tool permission map.
	 */
	public static function ability_needs_confirmation( string $ability_name, ?\WP_Ability $ability, array $tool_permissions ): bool {
		$permission = $tool_permissions[ $ability_name ] ?? null;

		if ( 'confirm' === $permission ) {
			return true;
		}

		if ( 'always_allow' === $permission || 'disabled' === $permission ) {
			return false;
		}

		if ( ! $ability instanceof \WP_Ability ) {
			return true;
		}

		return 'destructive' === self::classify_ability( $ability );
	}

	/**
	 * Store one-turn approvals for the current confirmed resume request.
	 *
	 * @param array<int, mixed> $ability_names Ability IDs.
	 */
	public static function set_one_turn_approved_abilities( array $ability_names ): void {
		self::$one_turn_approved_abilities = array();

		foreach ( $ability_names as $ability_name ) {
			if ( is_string( $ability_name ) && '' !== $ability_name ) {
				self::$one_turn_approved_abilities[ $ability_name ] = true;
			}
		}
	}

	/**
	 * Clear request-scoped one-turn approvals.
	 */
	public static function clear_one_turn_approved_abilities(): void {
		self::$one_turn_approved_abilities = array();
	}

	/**
	 * Whether an ability was approved for this confirmed resume request.
	 */
	public static function is_one_turn_approved( string $ability_name ): bool {
		return isset( self::$one_turn_approved_abilities[ $ability_name ] );
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

	/**
	 * Return the ability ID whose policy should govern a tool call.
	 *
	 * For direct tools this is the tool itself. For sd-ai-agent/ability-call it is
	 * the nested registered target ability, when present, so the meta-tool cannot
	 * bypass the target's confirmation policy.
	 *
	 * @param string                         $ability_name  Direct ability ID.
	 * @param array<string, mixed>           $args          Normalized tool-call args.
	 * @param array<int|string, \WP_Ability> $all_abilities Registered abilities.
	 */
	private static function get_confirmation_ability_id( string $ability_name, array $args, array $all_abilities ): string {
		if ( 'sd-ai-agent/ability-call' !== $ability_name ) {
			return $ability_name;
		}

		$target = isset( $args['ability'] ) && is_string( $args['ability'] ) ? trim( $args['ability'] ) : '';
		if ( '' === $target ) {
			return $ability_name;
		}

		if ( class_exists( \SdAiAgent\Tools\ToolDiscovery::class ) ) {
			$target = \SdAiAgent\Tools\ToolDiscovery::canonicalise_ability_id( $target );
		}

		return isset( $all_abilities[ $target ] ) ? $target : $ability_name;
	}

	/**
	 * Whether a user-requested proposal call deliberately creates a private draft.
	 *
	 * A create-post handler defaults an omitted status to draft, but an omitted
	 * value is not an explicit bounded proposal. The caller additionally gates
	 * this on user-supplied draft/proposal intent, so model-supplied arguments
	 * alone cannot bypass the underspecified-request confirmation boundary.
	 *
	 * @param string               $ability_name            Direct ability ID.
	 * @param string               $confirmation_ability_id Target ability ID.
	 * @param array<string, mixed> $args                    Normalized direct call arguments.
	 */
	private static function is_explicit_draft_creation( string $ability_name, string $confirmation_ability_id, array $args ): bool {
		if ( 'sd-ai-agent/create-post' !== $confirmation_ability_id ) {
			return false;
		}

		$creation_args = $args;
		if ( 'sd-ai-agent/ability-call' === $ability_name ) {
			$creation_args = self::normalize_function_call_args( $args['arguments'] ?? array() );
		}

		$status = $creation_args['status'] ?? null;
		return is_string( $status ) && 'draft' === strtolower( trim( $status ) );
	}

	/**
	 * Normalize function-call args to a string-keyed array.
	 *
	 * @param mixed $args Raw function-call arguments.
	 * @return array<string, mixed>
	 */
	private static function normalize_function_call_args( $args ): array {
		if ( is_string( $args ) && '' !== $args ) {
			$decoded = json_decode( $args, true );
			return is_array( $decoded ) ? self::string_keyed_array( $decoded ) : array();
		}

		if ( is_array( $args ) ) {
			return self::string_keyed_array( $args );
		}

		if ( is_object( $args ) ) {
			$encoded = wp_json_encode( $args );
			$decoded = is_string( $encoded ) ? json_decode( $encoded, true ) : null;
			return is_array( $decoded ) ? self::string_keyed_array( $decoded ) : array();
		}

		return array();
	}

	/**
	 * Keep only string-keyed values from a decoded JSON object.
	 *
	 * @param array<mixed> $value Decoded function-call args.
	 * @return array<string, mixed>
	 */
	private static function string_keyed_array( array $value ): array {
		$result = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $item;
			}
		}

		return $result;
	}
}
