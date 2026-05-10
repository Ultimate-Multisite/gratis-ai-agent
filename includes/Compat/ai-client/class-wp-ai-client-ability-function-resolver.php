<?php
/**
 * WP AI Client bridge polyfill: WP_AI_Client_Ability_Function_Resolver class
 *
 * Provides the ability function resolver for the AI Client bridge
 * on WordPress < 7.0 where this class is not available in core.
 *
 * On WordPress 7.0+, this file is a no-op — core's definition wins.
 *
 * Source: wp-includes/ai-client/class-wp-ai-client-ability-function-resolver.php (WP 7.0)
 *
 * @package SdAiAgent\Compat
 * @license GPL-2.0-or-later
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WP_AI_Client_Ability_Function_Resolver' ) ) {
	return;
}

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

/**
 * Resolves and executes WordPress Abilities API function calls from AI models.
 *
 * This class must be instantiated with the specific abilities that the AI model
 * is allowed to execute, ensuring that only explicitly specified abilities can
 * be called. This prevents the model from executing arbitrary abilities.
 *
 * @since 7.0.0
 */
class WP_AI_Client_Ability_Function_Resolver {

	/**
	 * Prefix used to identify ability function calls.
	 *
	 * @since 7.0.0
	 * @var string
	 */
	private const ABILITY_PREFIX = 'wpab__';

	/**
	 * Map of allowed ability names for this instance.
	 *
	 * Keys are ability name strings, values are `true` for O(1) lookup.
	 *
	 * @since 7.0.0
	 * @var array<string, true>
	 */
	private array $allowed_abilities;

	/**
	 * Constructor.
	 *
	 * @since 7.0.0
	 *
	 * @param WP_Ability|string ...$abilities The abilities that this resolver is allowed to execute.
	 */
	public function __construct( ...$abilities ) {
		$this->allowed_abilities = array();

		foreach ( $abilities as $ability ) {
			if ( $ability instanceof WP_Ability ) {
				$this->allowed_abilities[ $ability->get_name() ] = true;
			} elseif ( is_string( $ability ) ) {
				$this->allowed_abilities[ $ability ] = true;
			}
		}
	}

	/**
	 * Checks if a function call is an ability call.
	 *
	 * @since 7.0.0
	 *
	 * @param FunctionCall $call The function call to check.
	 * @return bool True if the function call is an ability call, false otherwise.
	 */
	public function is_ability_call( FunctionCall $call ): bool {
		$name = $call->getName();
		if ( null === $name ) {
			return false;
		}

		return str_starts_with( $name, self::ABILITY_PREFIX );
	}

	/**
	 * Executes a WordPress ability from a function call.
	 *
	 * Only abilities that were specified in the constructor are allowed to be
	 * executed. If the ability is not in the allowed list, an error response
	 * with code `ability_not_allowed` is returned.
	 *
	 * @since 7.0.0
	 *
	 * @param FunctionCall $call The function call to execute.
	 * @return FunctionResponse The response from executing the ability.
	 */
	public function execute_ability( FunctionCall $call ): FunctionResponse {
		$function_name = $call->getName() ?? 'unknown';
		$function_id   = $call->getId() ?? 'unknown';

		if ( ! $this->is_ability_call( $call ) ) {
			return new FunctionResponse(
				$function_id,
				$function_name,
				array(
					'error' => __( 'Not an ability function call', 'superdav-ai-agent' ),
					'code'  => 'invalid_ability_call',
				)
			);
		}

		$ability_name = self::function_name_to_ability_name( $function_name );

		if ( ! isset( $this->allowed_abilities[ $ability_name ] ) ) {
			// Include the actual allowed list in the response so the model
			// can self-correct instead of looping with ability-search and
			// burning iterations against an unknown surface. See #1295.
			$allowed_list = array_keys( $this->allowed_abilities );
			sort( $allowed_list );

			return new FunctionResponse(
				$function_id,
				$function_name,
				array(
					/* translators: %s: ability name */
					'error' => sprintf( __( 'Ability "%s" was not specified in the allowed abilities list.', 'superdav-ai-agent' ), $ability_name ),
					'code'  => 'ability_not_allowed',
					'data'  => array(
						'allowed_abilities' => $allowed_list,
						'hint'              => __( 'Pick a different ability from allowed_abilities, or call sd-ai-agent/ability-search to discover others. Do not retry the same name.', 'superdav-ai-agent' ),
					),
				)
			);
		}

		$ability = wp_get_ability( $ability_name );

		if ( ! $ability instanceof WP_Ability ) {
			return new FunctionResponse(
				$function_id,
				$function_name,
				array(
					/* translators: %s: ability name */
					'error' => sprintf( __( 'Ability "%s" not found', 'superdav-ai-agent' ), $ability_name ),
					'code'  => 'ability_not_found',
				)
			);
		}

		$args   = $call->getArgs();
		$result = $ability->execute( ! empty( $args ) ? $args : null );

		if ( is_wp_error( $result ) ) {
			return new FunctionResponse(
				$function_id,
				$function_name,
				array(
					'error' => $result->get_error_message(),
					'code'  => $result->get_error_code(),
					'data'  => $result->get_error_data(),
				)
			);
		}

		// Anthropic's tool_result content must be a string or list of content
		// blocks — booleans/scalars at the top level cause a 400. Wrap so
		// abilities that legitimately return true/false/scalar still serialise.
		if ( is_bool( $result ) || is_scalar( $result ) || null === $result ) {
			$result = array( 'result' => $result );
		}

		return new FunctionResponse(
			$function_id,
			$function_name,
			$result
		);
	}

	/**
	 * Checks if a message contains any ability function calls.
	 *
	 * @since 7.0.0
	 *
	 * @param Message $message The message to check.
	 * @return bool True if the message contains ability calls, false otherwise.
	 */
	public function has_ability_calls( Message $message ): bool {
		foreach ( $message->getParts() as $part ) {
			if ( $part->getType()->isFunctionCall() ) {
				$function_call = $part->getFunctionCall();
				if ( $function_call instanceof FunctionCall && $this->is_ability_call( $function_call ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Executes all ability function calls in a message.
	 *
	 * @since 7.0.0
	 *
	 * @param Message $message The message containing function calls.
	 * @return Message A new message with function responses.
	 */
	public function execute_abilities( Message $message ): Message {
		$response_parts = array();

		foreach ( $message->getParts() as $part ) {
			if ( $part->getType()->isFunctionCall() ) {
				$function_call = $part->getFunctionCall();
				if ( $function_call instanceof FunctionCall ) {
					$function_response = $this->execute_ability( $function_call );
					$response_parts[]  = new MessagePart( $function_response );
				}
			}
		}

		return new UserMessage( $response_parts );
	}

	/**
	 * Converts an ability name to a function name.
	 *
	 * Transforms "tec/create_event" to "wpab__tec__create_event".
	 *
	 * @since 7.0.0
	 *
	 * @param string $ability_name The ability name to convert.
	 * @return string The function name.
	 */
	public static function ability_name_to_function_name( string $ability_name ): string {
		return self::ABILITY_PREFIX . str_replace( '/', '__', $ability_name );
	}

	/**
	 * Converts a function name to an ability name.
	 *
	 * Transforms "wpab__tec__create_event" to "tec/create_event".
	 *
	 * @since 7.0.0
	 *
	 * @param string $function_name The function name to convert.
	 * @return string The ability name.
	 */
	public static function function_name_to_ability_name( string $function_name ): string {
		$without_prefix = substr( $function_name, strlen( self::ABILITY_PREFIX ) );

		return str_replace( '__', '/', $without_prefix );
	}
}
