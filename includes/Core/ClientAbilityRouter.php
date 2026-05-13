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
	 * @return string[]
	 */
	public function get_names(): array {
		return array_map(
			static function ( array $d ): string {
				return (string) ( $d['name'] ?? '' );
			},
			$this->client_abilities
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
			// @phpstan-ignore-next-line
			$existing = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
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

			// @phpstan-ignore-next-line
			$stub = wp_get_ability( $name );
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

			if ( in_array( $ability_name, $client_names, true ) ) {
				$client[] = array(
					'id'          => (string) $call->getId(),
					'name'        => $ability_name,
					'args'        => $call->getArgs() ?: array(),
					// Annotations are forwarded so the browser can decide whether
					// to auto-execute (readonly=true) or show a confirmation dialog.
					'annotations' => $annotations_by_name[ $ability_name ] ?? array(),
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
