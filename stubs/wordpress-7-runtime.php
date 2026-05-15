<?php
/**
 * Stubs for WordPress 7.0+ runtime APIs not yet covered by php-stubs/wordpress-stubs
 * or the intelephense built-in WordPress stub set.
 *
 * Covers:
 *   - WordPress\AiClient SDK (WP 7.0 core AI Client)
 *   - WP_AI_Client_Ability_Function_Resolver (WP 7.0 compat class)
 *   - WP_Ability / wp_register_ability / wp_get_abilities (WP 7.0 Abilities API)
 *   - wp_register_ability_category (WP 7.0 Abilities API)
 *   - OpenAiCompatibleConnector namespace functions (WP Connectors API)
 *   - _wp_connectors_get_* internal functions (WP Connectors API)
 *   - WP_CLI class and constant (WP-CLI)
 *
 * These are provided at runtime by WordPress 7.0+ core or WP-CLI.
 * This file exists solely for LSP (intelephense) type resolution and is
 * never loaded at runtime.
 *
 * @package SdAiAgent
 */

// phpcs:disable

namespace WordPress\AiClient\Messages\Enums {

	/**
	 * Enum for message roles (stub).
	 */
	class MessageRoleEnum {
		/** @var string */
		public string $value = '';

		/**
		 * Get the role value string.
		 *
		 * @return string
		 */
		public function getValue(): string {
			return $this->value;
		}

		/**
		 * Allow casting to string.
		 *
		 * @return string
		 */
		public function __toString(): string {
			return $this->value;
		}
	}
}

namespace WordPress\AiClient\Tools\DTO {

	/**
	 * Represents an AI function call (stub).
	 */
	class FunctionCall {
		/**
		 * Constructor.
		 *
		 * @param string               $id   Function call ID.
		 * @param string               $name Function name.
		 * @param array<string, mixed> $args Function arguments.
		 */
		public function __construct( string $id, string $name, array $args = array() ) {}

		/** @return string */
		public function getId(): string { return ''; }

		/** @return string */
		public function getName(): string { return ''; }

		/**
		 * Provider JSON decoders may return a top-level stdClass for
		 * object-typed arguments, or mixed when the decoder is permissive.
		 *
		 * @return array<string, mixed>|\stdClass|mixed
		 */
		public function getArgs(): mixed { return array(); }
	}

	/**
	 * Represents an AI function response (stub).
	 */
	class FunctionResponse {
		/**
		 * Constructor.
		 *
		 * @param string $id       Function call ID.
		 * @param string $name     Function name.
		 * @param mixed  $response Response data.
		 */
		public function __construct( string $id, string $name, mixed $response = null ) {}

		/** @return string */
		public function getId(): string { return ''; }

		/** @return string */
		public function getName(): string { return ''; }

		/** @return mixed */
		public function getResponse(): mixed { return null; }
	}
}

namespace WordPress\AiClient\Messages\DTO {

	use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
	use WordPress\AiClient\Tools\DTO\FunctionCall;
	use WordPress\AiClient\Tools\DTO\FunctionResponse;

	/**
	 * Represents the type of a message part (stub).
	 */
	class MessagePartType {
		/** @return bool */
		public function isFunctionCall(): bool { return false; }

		/** @return bool */
		public function isFunctionResponse(): bool { return false; }

		/** @return bool */
		public function isText(): bool { return false; }
	}

	/**
	 * Represents a single part of an AI message (stub).
	 */
	class MessagePart {
		/**
		 * Constructor.
		 *
		 * @param string|FunctionCall|\WordPress\AiClient\Tools\DTO\FunctionResponse $content Text, function call, or function response.
		 */
		public function __construct( string|FunctionCall|\WordPress\AiClient\Tools\DTO\FunctionResponse $content = '' ) {}

		/** @return string */
		public function getText(): string { return ''; }

		/** @return MessagePartType */
		public function getType(): MessagePartType { return new MessagePartType(); }

		/** @return FunctionCall|null */
		public function getFunctionCall(): ?FunctionCall { return null; }

		/** @return FunctionResponse|null */
		public function getFunctionResponse(): ?FunctionResponse { return null; }
	}

	/**
	 * Base class for AI conversation messages (stub).
	 */
	class Message {
		/**
		 * Get the message role.
		 *
		 * @return MessageRoleEnum
		 */
		public function getRole(): MessageRoleEnum { return new MessageRoleEnum(); }

		/**
		 * Get the message parts.
		 *
		 * @return MessagePart[]
		 */
		public function getParts(): array { return array(); }

		/**
		 * Serialize the message to an array.
		 *
		 * @return array<string, mixed>
		 */
		public function toArray(): array { return array(); }

		/**
		 * Deserialize a message from an array.
		 *
		 * @param array<string, mixed> $data Serialized message data.
		 * @return static
		 */
		public static function fromArray( array $data ): static { return new static(); }
	}

	/**
	 * Represents a user message in an AI conversation (stub).
	 */
	class UserMessage extends Message {
		/**
		 * Constructor.
		 *
		 * @param MessagePart[] $parts Message parts.
		 */
		public function __construct( array $parts = array() ) {}
	}

	/**
	 * Represents a model (assistant) message in an AI conversation (stub).
	 */
	class ModelMessage extends Message {
		/**
		 * Constructor.
		 *
		 * @param MessagePart[] $parts Message parts.
		 */
		public function __construct( array $parts = array() ) {}
	}
}

namespace WordPress\AiClient\Results\DTO {

	use WordPress\AiClient\Messages\DTO\Message;

	/**
	 * Token usage data from a generative AI request (stub).
	 */
	class TokenUsage {
		/**
		 * Get the number of prompt/input tokens used.
		 *
		 * @return int
		 */
		public function getPromptTokens(): int { return 0; }

		/**
		 * Get the number of completion/output tokens used.
		 *
		 * @return int
		 */
		public function getCompletionTokens(): int { return 0; }

		/**
		 * Get the total number of tokens used.
		 *
		 * @return int
		 */
		public function getTotalTokens(): int { return 0; }
	}

	/**
	 * Result from a generative AI request (stub).
	 */
	class GenerativeAiResult {
		/** @return Message */
		public function getMessage(): Message { return new Message(); }

		/** @return Message[] */
		public function getCandidates(): array { return array(); }

		/**
		 * Convert the result to a Message for conversation history.
		 *
		 * @return Message
		 */
		public function toMessage(): Message { return new Message(); }

		/**
		 * Get the text content of the result.
		 *
		 * @return string
		 */
		public function toText(): string { return ''; }

		/**
		 * Check whether the result contains ability (tool) calls.
		 *
		 * @return bool
		 */
		public function has_ability_calls(): bool { return false; }

		/**
		 * Get token usage data for this result.
		 *
		 * @return TokenUsage
		 */
		public function getTokenUsage(): TokenUsage { return new TokenUsage(); }
	}
}

namespace WordPress\AiClient {

	/**
	 * AI model registry (stub).
	 */
	class ModelRegistry {
		/** @param string $provider_id */
		public function hasProvider( string $provider_id ): bool { return false; }

		/**
		 * @param string $provider_id
		 * @param string $model_id
		 * @return mixed
		 */
		public function getProviderModel( string $provider_id, string $model_id ): mixed { return null; }

		/** @param string $provider_id */
		public function getProviderRequestAuthentication( string $provider_id ): mixed { return null; }

		/**
		 * @param string $provider_id
		 * @param mixed  $authentication
		 */
		public function setProviderRequestAuthentication( string $provider_id, mixed $authentication ): void {}

		/**
		 * Get all registered provider IDs.
		 *
		 * @return string[]
		 */
		public function getRegisteredProviderIds(): array { return array(); }

		/**
		 * Get the class name for a registered provider.
		 *
		 * @param string $provider_id Provider identifier.
		 * @return string Fully-qualified class name.
		 */
		public function getProviderClassName( string $provider_id ): string { return ''; }
	}

	/**
	 * WordPress AI Client (stub).
	 *
	 * @since 7.0.0
	 */
	class AiClient {
		/** @return ModelRegistry */
		public static function defaultRegistry(): ModelRegistry { return new ModelRegistry(); }
	}
}

namespace OpenAiCompatibleConnector {

	/**
	 * Get the default model ID for the OpenAI-compatible connector (stub).
	 *
	 * @return string
	 */
	function get_default_model(): string { return ''; }

	/**
	 * List available models via REST (stub).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	function rest_list_models( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return new \WP_REST_Response();
	}
}

namespace {

	/** WP-CLI is active (stub constant — false at analysis time). */
	const WP_CLI = false;

	/**
	 * WP-CLI framework class (stub).
	 */
	class WP_CLI {
		/**
		 * @param string          $name
		 * @param callable|string $callable
		 * @param array           $args
		 */
		public static function add_command( string $name, $callable, array $args = array() ): void {}

		/** @param string $message */
		public static function success( string $message ): void {}

		/**
		 * @param string $message
		 * @param bool   $exit
		 */
		public static function error( string $message, bool $exit = true ): void {}

		/** @param string $message */
		public static function log( string $message ): void {}

		/** @param string $message */
		public static function warning( string $message ): void {}
	}

	/**
	 * In-memory ability registry for PHPUnit test environments.
	 *
	 * Shared across wp_register_ability(), wp_get_ability(),
	 * wp_unregister_ability(), and wp_get_abilities() via `global`.
	 * Using a named global avoids the PHP `static`-per-function isolation
	 * problem (PHP `static` variables are per-function, not shared).
	 *
	 * @var array<string, WP_Ability>
	 */
	$_wp_ability_registry = array();

	/**
	 * WordPress Ability class (stub).
	 *
	 * @since 7.0.0
	 */
	if ( ! class_exists( 'WP_Ability' ) ) {
		class WP_Ability {
			/**
			 * The namespaced ability name (e.g. 'sd-ai-agent/memory-save').
			 *
			 * @var string
			 */
			public string $name = '';

			/**
			 * Constructor.
			 *
			 * @param string               $name       The namespaced ability name.
			 * @param array<string, mixed> $args       Ability configuration args.
			 */
			public function __construct( string $name, array $args = array() ) {
				$this->name = $name;
			}

			/**
			 * Prepare and validate ability properties from args.
			 *
			 * @param array<string, mixed> $args The ability args array.
			 * @return array<string, mixed> The validated and prepared properties.
			 */
			protected function prepare_properties( array $args ): array { return $args; }

			/** @return string */
			public function get_name(): string { return $this->name; }

			/** @return string */
			public function get_label(): string { return ''; }

			/** @return string */
			public function get_description(): string { return ''; }

			/** @return array<string, mixed> */
			public function get_params(): array { return array(); }

			/**
			 * Get the JSON Schema for the ability's input parameters.
			 *
			 * @return array<string, mixed>
			 */
			public function get_input_schema(): array { return array(); }

			/**
			 * Get the JSON Schema for the ability's output.
			 *
			 * @return array<string, mixed>
			 */
			public function get_output_schema(): array { return array(); }

			/**
			 * Get the ability category slug.
			 *
			 * @return string
			 */
			public function get_category(): string { return ''; }

			/**
			 * Get ability metadata.
			 *
			 * @return array<string, mixed>
			 */
			public function get_meta(): array { return array(); }

			/** @return mixed */
			public function call( array $params ): mixed { return null; }

			/**
			 * Execute the ability with the given arguments.
			 *
			 * @param array<string, mixed>|null $args Input arguments.
			 * @return mixed|\WP_Error
			 */
			public function execute( ?array $args ): mixed { return null; }

			/**
			 * Validate input against the ability's input schema.
			 *
			 * @param mixed $input Input to validate.
			 * @return true|\WP_Error
			 */
			public function validate_input( mixed $input ): true|\WP_Error { return true; }
		}
	}

	/**
	 * Resolves between WP Ability names and AI function call names (stub).
	 *
	 * @since 7.0.0
	 */
	class WP_AI_Client_Ability_Function_Resolver {
		/**
		 * Constructor.
		 *
		 * Accepts ability objects or ability name strings.
		 *
		 * @param WP_Ability|string ...$abilities Abilities to register (objects or name strings).
		 */
		public function __construct( WP_Ability|string ...$abilities ) {}

		/** @param string $ability_name */
		public static function ability_name_to_function_name( string $ability_name ): string { return ''; }

		/** @param string $function_name */
		public static function function_name_to_ability_name( string $function_name ): string { return ''; }

		/** @return array<int, array<string, mixed>> */
		public function get_tools(): array { return array(); }

		/**
		 * Check whether a message contains ability (tool) calls.
		 *
		 * @param \WordPress\AiClient\Messages\DTO\Message $message
		 * @return bool
		 */
		public function has_ability_calls( \WordPress\AiClient\Messages\DTO\Message $message ): bool { return false; }

		/**
		 * Check whether a single function call is an ability call.
		 *
		 * @param \WordPress\AiClient\Tools\DTO\FunctionCall $call The function call to check.
		 * @return bool
		 */
		public function is_ability_call( \WordPress\AiClient\Tools\DTO\FunctionCall $call ): bool { return false; }

		/**
		 * Execute all ability calls in a message and return the response message.
		 *
		 * @param \WordPress\AiClient\Messages\DTO\Message $message
		 * @return \WordPress\AiClient\Messages\DTO\Message
		 */
		public function execute_abilities( \WordPress\AiClient\Messages\DTO\Message $message ): \WordPress\AiClient\Messages\DTO\Message {
			return new \WordPress\AiClient\Messages\DTO\UserMessage();
		}

		/**
		 * Execute a single ability call and return the function response.
		 *
		 * @param \WordPress\AiClient\Tools\DTO\FunctionCall $call The function call to execute.
		 * @return \WordPress\AiClient\Tools\DTO\FunctionResponse
		 */
		public function execute_ability( \WordPress\AiClient\Tools\DTO\FunctionCall $call ): \WordPress\AiClient\Tools\DTO\FunctionResponse {
			return new \WordPress\AiClient\Tools\DTO\FunctionResponse( '', '' );
		}
	}

	/**
	 * Register a WordPress ability.
	 *
	 * @since 7.0.0
	 *
	 * @param string               $name Namespaced ability name.
	 * @param array<string, mixed> $args Ability configuration.
	 * @return WP_Ability|null
	 */
	function wp_register_ability( string $name, array $args ): ?WP_Ability {
		global $_wp_ability_registry;
		$ability                       = new WP_Ability( $name, $args );
		$_wp_ability_registry[ $name ] = $ability;
		return $ability;
	}

	/**
	 * Unregister a WordPress ability.
	 *
	 * @since 7.0.0
	 *
	 * @param string $name Namespaced ability name.
	 * @return WP_Ability|null
	 */
	function wp_unregister_ability( string $name ): ?WP_Ability {
		global $_wp_ability_registry;
		$ability = $_wp_ability_registry[ $name ] ?? null;
		unset( $_wp_ability_registry[ $name ] );
		return $ability;
	}

	/**
	 * Get a registered WordPress ability by name.
	 *
	 * @since 7.0.0
	 *
	 * @param string $name Namespaced ability name.
	 * @return WP_Ability|null
	 */
	function wp_get_ability( string $name ): ?WP_Ability {
		global $_wp_ability_registry;
		return $_wp_ability_registry[ $name ] ?? null;
	}

	/**
	 * Get all registered WordPress abilities.
	 *
	 * @since 7.0.0
	 *
	 * @return WP_Ability[]
	 */
	function wp_get_abilities(): array {
		global $_wp_ability_registry;
		return array_values( $_wp_ability_registry );
	}

	/**
	 * Register a WordPress ability category.
	 *
	 * @since 7.0.0
	 *
	 * @param string               $slug Category slug.
	 * @param array<string, mixed> $args Category configuration.
	 * @return mixed
	 */
	function wp_register_ability_category( string $slug, array $args ): mixed { return null; }

	/**
	 * Get all registered connector provider settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	function _wp_connectors_get_provider_settings(): array { return array(); }

	/**
	 * Get the real (unmasked) API key for a connector setting.
	 *
	 * @param string $setting_name Setting name.
	 * @param string $mask         Masked key value.
	 * @return string
	 */
	function _wp_connectors_get_real_api_key( string $setting_name, string $mask ): string { return ''; }

	/**
	 * WordPress 7.0+ AI Client prompt builder (stub).
	 *
	 * Returned by wp_ai_client_prompt(). All configuration methods return
	 * `static` to support fluent chaining.
	 *
	 * @since 7.0.0
	 */
	class WP_AI_Client_Prompt_Builder {

		/**
		 * Constructor.
		 *
		 * @param string $prompt Initial prompt text.
		 */
		public function __construct( string $prompt = '' ) {}

		/**
		 * Set the system instruction for this prompt.
		 *
		 * @param string $instruction System instruction text.
		 * @return static
		 */
		public function using_system_instruction( string $instruction ): static { return $this; }

		/**
		 * Set the sampling temperature.
		 *
		 * @param float $temperature Temperature value (0.0–1.0).
		 * @return static
		 */
		public function using_temperature( float $temperature ): static { return $this; }

		/**
		 * Set the number of response candidates to generate.
		 *
		 * @param int $count Candidate count.
		 * @return static
		 */
		public function using_candidate_count( int $count ): static { return $this; }

		/**
		 * Set a model preference by model ID string.
		 *
		 * @param string $model_id Model identifier.
		 * @return static
		 */
		public function using_model_preference( string $model_id ): static { return $this; }

		/**
		 * Set the model object (from ModelRegistry::getProviderModel()).
		 *
		 * @param mixed $model Model instance.
		 * @return static
		 */
		public function using_model( mixed $model ): static { return $this; }

		/**
		 * Set the provider by provider ID.
		 *
		 * @param string $provider_id Provider identifier.
		 * @return static
		 */
		public function using_provider( string $provider_id ): static { return $this; }

		/**
		 * Set the maximum number of output tokens.
		 *
		 * @param int $tokens Token limit.
		 * @return static
		 */
		public function using_max_tokens( int $tokens ): static { return $this; }

		/**
		 * Register abilities (tools) available to the model.
		 *
		 * @param WP_Ability ...$abilities Ability instances.
		 * @return static
		 */
		public function using_abilities( WP_Ability ...$abilities ): static { return $this; }

		/**
		 * Provide conversation history.
		 *
		 * @param \WordPress\AiClient\Messages\DTO\Message ...$history History messages.
		 * @return static
		 */
		public function with_history( \WordPress\AiClient\Messages\DTO\Message ...$history ): static { return $this; }

		/**
		 * Attach a file (data URI) to the prompt.
		 *
		 * @param string $file Data URI string.
		 * @return static
		 */
		public function with_file( string $file ): static { return $this; }

		/**
		 * Request a structured JSON response conforming to the given schema.
		 *
		 * @param mixed $schema JSON Schema array or object.
		 * @return static
		 */
		public function as_json_response( mixed $schema ): static { return $this; }

		/**
		 * Generate a single text response.
		 *
		 * @return string|\WP_Error
		 */
		public function generate_text(): string|\WP_Error { return ''; }

		/**
		 * Generate multiple candidate text responses.
		 *
		 * @return string[]|\WP_Error
		 */
		public function generate_texts(): array|\WP_Error { return array(); }

		/**
		 * Generate a response and return the full GenerativeAiResult object.
		 *
		 * @return \WordPress\AiClient\Results\DTO\GenerativeAiResult|\WP_Error
		 */
		public function generate_text_result(): \WordPress\AiClient\Results\DTO\GenerativeAiResult|\WP_Error {
			return new \WordPress\AiClient\Results\DTO\GenerativeAiResult();
		}

		/**
		 * Check if the prompt is supported for image generation.
		 *
		 * @return bool
		 */
		public function is_supported_for_image_generation(): bool { return false; }

		/**
		 * Generate an image from the prompt.
		 *
		 * @return \WordPress\AiClient\Files\DTO\File|\WP_Error
		 */
		public function generate_image(): \WordPress\AiClient\Files\DTO\File|\WP_Error {
			return new \WP_Error( 'not_implemented', 'Stub only.' );
		}
	}

	/**
	 * Create a new WP AI Client prompt builder.
	 *
	 * Returns a fluent WP_AI_Client_Prompt_Builder instance pre-configured
	 * with the given prompt text. Call configuration methods and then one
	 * of the generate_*() methods to send the request.
	 *
	 * @since 7.0.0
	 *
	 * @param string $prompt Initial prompt text (optional — may also be set
	 *                       via using_system_instruction()).
	 * @return WP_AI_Client_Prompt_Builder
	 */
	function wp_ai_client_prompt( string $prompt = '' ): WP_AI_Client_Prompt_Builder {
		return new WP_AI_Client_Prompt_Builder( $prompt );
	}
}
