<?php

declare(strict_types=1);
/**
 * Routes tool calls to PHP or client-side (JS) handlers.
 *
 * Extracted from AgentLoop so the client-ability partitioning concern lives
 * in one focused class. Handles stub registration, name resolution, and
 * message partitioning.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use SdAiAgent\Abilities\Js\JsAbilityCatalog;
use SdAiAgent\Abilities\NavigationAbilities;
use SdAiAgent\Abilities\ToolCapabilities;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\ModelMessage;

final class ClientAbilityRouter {

	/**
	 * @param list<array<string, mixed>> $client_abilities Validated client-side ability descriptors.
	 */
	public function __construct( private array $client_abilities = array() ) {}

	/**
	 * Validate and filter raw client ability descriptors against JsAbilityCatalog.
	 *
	 * Only accepts names that exist in JsAbilityCatalog to prevent the client
	 * from injecting arbitrary ability names into the model's tool list.
	 *
	 * @param array<int|string, mixed> $raw_descriptors Unvalidated descriptors from the request.
	 * @return self A new instance with validated descriptors.
	 */
	public static function from_raw( array $raw_descriptors ): self {
		$catalog   = JsAbilityCatalog::get_descriptors_by_name();
		$validated = array();

		foreach ( $raw_descriptors as $descriptor ) {
			if ( ! is_array( $descriptor ) ) {
				continue;
			}
			$name = (string) ( $descriptor['name'] ?? '' );
			if ( '' !== $name && isset( $catalog[ $name ] ) ) {
				/** @var array<string, mixed> $descriptor */
				$validated[] = $descriptor;
			}
		}

		return new self( $validated );
	}

	/**
	 * Return the set of client ability names validated for this run.
	 *
	 * @return list<string>
	 */
	public function get_names(): array {
		return array_values(
			array_map(
				static function ( array $d ): string {
					return (string) ( $d['name'] ?? '' );
				},
				$this->client_abilities
			)
		);
	}

	/**
	 * Return whether there are any client abilities configured.
	 *
	 * @return bool
	 */
	public function has_client_abilities(): bool {
		return ! empty( $this->client_abilities );
	}

	/**
	 * Build synthetic WP_Ability stubs for validated client-side descriptors.
	 *
	 * These stubs expose the client ability schemas to the model's tool list.
	 * The loop intercepts calls to these names and returns them as
	 * pending_client_tool_calls instead of executing them server-side.
	 *
	 * @return \WP_Ability[]
	 */
	public function build_stubs(): array {
		if ( empty( $this->client_abilities ) ) {
			return array();
		}

		if ( ! function_exists( 'wp_register_ability' ) ) {
			return array();
		}

		$stubs = array();
		foreach ( $this->client_abilities as $descriptor ) {
			$name = (string) ( $descriptor['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}

			// Check if already registered in the global registry.
			$existing = AbilityRegistry::get( $name );
			if ( $existing instanceof \WP_Ability ) {
				$stubs[] = $existing;
				continue;
			}

			// Register a transient stub for this request only.
			// The stub has a no-op callback — the loop never actually calls it.
			// @phpstan-ignore-next-line
			wp_register_ability(
				$name,
				array(
					'label'               => (string) ( $descriptor['label'] ?? $name ),
					'description'         => (string) ( $descriptor['description'] ?? '' ),
					'category'            => 'sd-ai-agent-js',
					'callback'            => static function ( array $args ): array {
						// No-op: client-side abilities are never executed server-side.
						return array( 'error' => 'Client-side ability cannot be executed server-side.' );
					},
					// Client-side stubs are never meant to execute server-side, so deny
					// any server-side execution attempt at the permission layer.
					'permission_callback' => '__return_false',
					'input_schema'        => $descriptor['input_schema'] ?? array(),
					'annotations'         => array(
						'readonly' => (bool) ( $descriptor['annotations']['readonly'] ?? true ),
					),
				)
			);

			$stub = AbilityRegistry::get( $name );
			if ( $stub instanceof \WP_Ability ) {
				$stubs[] = $stub;
			}
		}

		return $stubs;
	}

	/**
	 * Partition the tool calls in an assistant message into PHP-executable
	 * and client-side (JS) sets.
	 *
	 * Returns an array with two keys:
	 * - 'php':    list of MessagePart objects for PHP-executable calls.
	 * - 'client': list of pending call descriptors for JS execution.
	 *
	 * Only function-call parts are routed; text parts and other narration on
	 * the assistant message are intentionally dropped from `php` because
	 * `AbilityFunctionResolver::execute_abilities()` only emits responses
	 * for function-call parts. Including text here would cause the resolver
	 * to return a zero-part UserMessage that gets appended to history and
	 * later trips the SDK's "last message must have content parts" guard.
	 *
	 * @param Message  $message      The assistant message containing tool calls.
	 * @param string[] $client_names Names of client-side abilities.
	 * @return array{php: list<\WordPress\AiClient\Messages\DTO\MessagePart>, client: list<array<string, mixed>>}
	 */
	public function partition( Message $message, array $client_names ): array {
		$php_parts = array();
		$client    = array();

		// Build a name→annotations lookup once for O(1) access inside the loop.
		$annotations_by_name = array();
		foreach ( $this->client_abilities as $descriptor ) {
			$name = (string) ( $descriptor['name'] ?? '' );
			if ( '' !== $name ) {
				$annotations_by_name[ $name ] = $descriptor['annotations'] ?? array();
			}
		}

		foreach ( $message->getParts() as $part ) {
			$call = $part->getFunctionCall();
			if ( ! $call ) {
				// Non-function-call parts (text, etc.) are not executable
				// and the resolver would skip them anyway — exclude from
				// `php` so an all-JS-tools-plus-narration message does not
				// trigger an empty tool-response append (which would later
				// throw "last message must have content parts").
				continue;
			}

			$fn_name      = (string) $call->getName();
			$ability_name = $fn_name;
			if ( str_starts_with( $fn_name, 'wpab__' ) && class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
				$ability_name = \WP_AI_Client_Ability_Function_Resolver::function_name_to_ability_name( $fn_name );
			}
			$browser_navigation_args = $this->get_browser_navigation_args( $ability_name, $call->getArgs(), $client_names );

			if ( in_array( $ability_name, $client_names, true ) ) {
				$client[] = array(
					'id'          => (string) $call->getId(),
					'name'        => $ability_name,
					'args'        => $call->getArgs() ?: array(),
					// Annotations are forwarded so the browser can decide whether
					// to auto-execute (readonly=true) or show a confirmation dialog.
					'annotations' => $annotations_by_name[ $ability_name ] ?? array(),
				);
			} elseif ( null !== $browser_navigation_args ) {
				// Tier-2 abilities normally arrive inside ability-call rather than as
				// direct function calls. Preserve the outer call identity so the
				// browser result can be matched to the paused server-side batch, but
				// route the nested navigation to the browser callback.
				$client[] = array(
					'id'          => (string) $call->getId(),
					'name'        => $ability_name,
					'client_name' => 'sd-ai-agent-js/navigate-to',
					'args'        => $browser_navigation_args,
					'annotations' => $annotations_by_name['sd-ai-agent-js/navigate-to'] ?? array(),
				);
			} else {
				$php_parts[] = $part;
			}
		}

		return array(
			'php'    => $php_parts,
			'client' => $client,
		);
	}

	/**
	 * Return validated browser arguments for a nested navigation call.
	 *
	 * Keep this allowlist narrow: client descriptors are request supplied, while
	 * the nested target remains subject to the same WordPress capability gate as
	 * the server-side navigation ability.
	 *
	 * @param string        $ability_name Resolved outer ability name.
	 * @param mixed         $args         Outer ability arguments.
	 * @param array<string> $client_names Validated browser ability names.
	 * @return array{url: string}|null Validated browser arguments, or null when
	 *                                the call must remain server-side.
	 */
	private function get_browser_navigation_args(
		string $ability_name,
		mixed $args,
		array $client_names
	): ?array {
		if (
			'sd-ai-agent/ability-call' !== $ability_name
			|| ! is_array( $args )
			|| 'sd-ai-agent/navigate' !== ( $args['ability'] ?? '' )
			|| ! is_array( $args['arguments'] ?? null )
			|| ! in_array( 'sd-ai-agent-js/navigate-to', $client_names, true )
			|| ! ToolCapabilities::current_user_can( 'sd-ai-agent/navigate' )
		) {
			return null;
		}

		$validated = NavigationAbilities::handle_navigate( $args['arguments'] );
		if ( is_wp_error( $validated ) || ! is_array( $validated ) || empty( $validated['url'] ) ) {
			return null;
		}

		return array( 'url' => (string) $validated['url'] );
	}

	/**
	 * Return whether browser results exactly match one paused client-tool batch.
	 *
	 * Browser payloads are untrusted at the REST boundary. Matching both the
	 * opaque call ID and the ability name prevents a result for one pending call
	 * from being substituted for another call in the same paused loop.
	 *
	 * @param array<mixed,mixed> $expected_calls Persisted pending calls.
	 * @param array<mixed,mixed> $tool_results   Browser-submitted results.
	 */
	public static function matches_pending_results( array $expected_calls, array $tool_results ): bool {
		if ( empty( $expected_calls ) || count( $expected_calls ) !== count( $tool_results ) ) {
			return false;
		}

		$expected_by_id = array();
		foreach ( $expected_calls as $call ) {
			$id   = (string) ( $call['id'] ?? '' );
			$name = (string) ( $call['name'] ?? '' );
			if ( '' === $id || '' === $name || isset( $expected_by_id[ $id ] ) ) {
				return false;
			}
			$expected_by_id[ $id ] = $name;
		}

		$seen_ids = array();
		foreach ( $tool_results as $result ) {
			if ( ! is_array( $result ) ) {
				return false;
			}

			$id         = (string) ( $result['id'] ?? '' );
			$name       = (string) ( $result['name'] ?? '' );
			$has_result = array_key_exists( 'result', $result );
			$has_error  = array_key_exists( 'error', $result );
			if (
				'' === $id
				|| ! isset( $expected_by_id[ $id ] )
				|| $expected_by_id[ $id ] !== $name
				|| isset( $seen_ids[ $id ] )
				|| $has_result === $has_error
			) {
				return false;
			}

			$seen_ids[ $id ] = true;
		}

		return count( $seen_ids ) === count( $expected_by_id );
	}

	/**
	 * Build a new Message containing only the given MessagePart objects.
	 *
	 * Used to construct a PHP-only sub-message when a mixed assistant message
	 * contains both PHP and JS tool calls.
	 *
	 * @param Message                                            $original Original message (for role/type).
	 * @param list<\WordPress\AiClient\Messages\DTO\MessagePart> $parts    Parts to include.
	 * @return Message
	 */
	// phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- Generic list<T> not supported by PHPCS.
	public static function build_message_from_parts( Message $original, array $parts ): Message {
		// Reconstruct as a ModelMessage with the filtered parts.
		return new ModelMessage( $parts );
	}

	/**
	 * Return the validated client ability descriptors.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function get_descriptors(): array {
		return $this->client_abilities;
	}
}
