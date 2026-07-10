<?php

declare(strict_types=1);

namespace SdAiAgent\Infrastructure\AiClient\Superdav;

use SdAiAgent\Tools\ToolDiscovery;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Experimental OpenAI Responses API text model with hosted tool search.
 *
 * OpenAI documents `tool_search` as a Responses API feature for GPT-5.4+
 * models. The WordPress AI Client OpenAI-compatible base class targets Chat
 * Completions, so this model performs the small request/response translation
 * needed for the Superdav provider while returning the same SDK result DTOs
 * AgentLoop already consumes.
 */
final class SuperdavAiResponsesToolSearchTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface {

	/** Keep namespaces small as recommended by the OpenAI tool-search guide. */
	private const NAMESPACE_TOOL_LIMIT = 10;

	/**
	 * Generate text through `/responses`, falling back to Chat Completions if the
	 * experimental endpoint/tool is unavailable.
	 *
	 * @param Message[] $prompt Prompt messages.
	 * @phpstan-param list<Message> $prompt
	 * @return GenerativeAiResult
	 */
	public function generateTextResult( array $prompt ): GenerativeAiResult {
		try {
			$params  = $this->prepare_responses_params( $prompt );
			$request = $this->createRequest(
				HttpMethodEnum::POST(),
				'responses',
				array( 'Content-Type' => 'application/json' ),
				$params
			);
			$request = $this->getRequestAuthentication()->authenticateRequest( $request );

			$response = $this->getHttpTransporter()->send( $request );
			ResponseUtil::throwIfNotSuccessful( $response );

			return $this->parse_response_to_generative_ai_result( $response );
		} catch ( ClientException $e ) {
			if ( $this->should_fallback_to_chat_completions( $e ) ) {
				return $this->generate_chat_completions_fallback( $prompt );
			}

			throw $e;
		}
	}

	/**
	 * Create an API request.
	 *
	 * @param HttpMethodEnum                     $method  HTTP method.
	 * @param string                             $path    Endpoint path.
	 * @param array<string, string|list<string>> $headers Request headers.
	 * @param string|array<string, mixed>|null   $data    Request body/query data.
	 * @return Request
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), mixed $data = null ): Request {
		return new Request( $method, SuperdavAiProvider::url( $path ), $headers, $data, $this->getRequestOptions() );
	}

	/**
	 * Prepare OpenAI Responses API parameters from SDK prompt/config DTOs.
	 *
	 * @param Message[] $prompt Prompt messages.
	 * @phpstan-param list<Message> $prompt
	 * @return array<string, mixed>
	 */
	protected function prepare_responses_params( array $prompt ): array {
		$config = $this->getConfig();
		$params = array(
			'model' => $this->metadata()->getId(),
			'input' => $this->prepare_input_param( $prompt ),
		);

		$system_instruction = $config->getSystemInstruction();
		if ( is_string( $system_instruction ) && '' !== $system_instruction ) {
			$params['instructions'] = $system_instruction;
		}

		$max_tokens = $config->getMaxTokens();
		if ( null !== $max_tokens ) {
			$params['max_output_tokens'] = $max_tokens;
		}

		$temperature = $config->getTemperature();
		if ( null !== $temperature ) {
			$params['temperature'] = $temperature;
		}

		$top_p = $config->getTopP();
		if ( null !== $top_p ) {
			$params['top_p'] = $top_p;
		}

		$effort = SuperdavAiProvider::reasoning_effort_for_model( $this->metadata()->getId() );
		if ( '' !== $effort ) {
			$params['reasoning'] = array( 'effort' => $effort );
		}

		$tools = $this->prepare_tool_search_tools_param();
		if ( ! empty( $tools ) ) {
			$params['tools']               = $tools;
			$params['parallel_tool_calls'] = false;
		}

		foreach ( $config->getCustomOptions() as $key => $value ) {
			if ( isset( $params[ $key ] ) ) {
				throw new InvalidArgumentException( sprintf( 'The custom option "%s" conflicts with an existing Responses parameter.', esc_html( (string) $key ) ) );
			}
			$params[ $key ] = $value;
		}

		return $params;
	}

	/**
	 * Convert SDK messages to Responses `input` items.
	 *
	 * @param Message[] $messages Prompt messages.
	 * @phpstan-param list<Message> $messages
	 * @return list<array<string, mixed>>
	 */
	private function prepare_input_param( array $messages ): array {
		$input = array();

		foreach ( $messages as $message ) {
			if ( ! $message instanceof Message ) {
				continue;
			}
			array_push( $input, ...$this->prepare_message_input_items( $message ) );
		}

		return $input;
	}

	/**
	 * Convert one SDK message into one or more Responses input items.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function prepare_message_input_items( Message $message ): array {
		$items = array();
		$text  = array();

		foreach ( $message->getParts() as $part ) {
			$part_text = $part->getText();
			if ( is_string( $part_text ) && '' !== $part_text ) {
				$text[] = $part_text;
			}
		}

		if ( ! empty( $text ) ) {
			$items[] = array(
				'role'    => $this->responses_role_for_message( $message ),
				'content' => implode( "\n", $text ),
			);
		}

		foreach ( $message->getParts() as $part ) {
			$call = $part->getFunctionCall();
			if ( $call instanceof FunctionCall ) {
				$items[] = $this->function_call_to_input_item( $call );
				continue;
			}

			$response = $part->getFunctionResponse();
			if ( null !== $response ) {
				$items[] = array(
					'type'    => 'function_call_output',
					'call_id' => $response->getId() ?? $response->getName() ?? 'unknown',
					'output'  => $this->json_encode_for_api( $response->getResponse() ),
				);
			}
		}

		return $items;
	}

	/** Return the Responses role for an SDK message. */
	private function responses_role_for_message( Message $message ): string {
		$role = $message->getRole();

		return $role->isModel() ? 'assistant' : 'user';
	}

	/**
	 * Convert a FunctionCall DTO into a Responses input item.
	 *
	 * @return array<string, mixed>
	 */
	private function function_call_to_input_item( FunctionCall $call ): array {
		return array(
			'type'      => 'function_call',
			'call_id'   => $call->getId() ?? 'unknown',
			'name'      => $call->getName() ?? 'unknown',
			'arguments' => $this->json_encode_for_api( $call->getArgs() ?? new \stdClass() ),
		);
	}

	/**
	 * Convert configured functions into OpenAI namespace + tool_search tools.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function prepare_tool_search_tools_param(): array {
		$declarations = $this->getConfig()->getFunctionDeclarations();
		if ( ! is_array( $declarations ) || empty( $declarations ) ) {
			return array();
		}

		$immediate_function_names = $this->immediate_tool_function_names();
		$groups                   = array();
		foreach ( $declarations as $declaration ) {
			if ( ! $declaration instanceof FunctionDeclaration ) {
				continue;
			}

			$function_name    = $declaration->getName();
			$key              = $this->namespace_key_for_function( $function_name );
			$groups[ $key ][] = $this->function_declaration_to_tool( $declaration, ! isset( $immediate_function_names[ $function_name ] ) );
		}

		$tools = array();
		foreach ( $groups as $namespace => $functions ) {
			$chunks = array_chunk( $functions, self::NAMESPACE_TOOL_LIMIT );
			foreach ( $chunks as $index => $chunk ) {
				$name    = 0 === $index ? $namespace : $namespace . '_' . ( $index + 1 );
				$tools[] = array(
					'type'        => 'namespace',
					'name'        => $name,
					'description' => $this->namespace_description( $name ),
					'tools'       => $chunk,
				);
			}
		}

		if ( ! empty( $tools ) ) {
			$tools[] = array( 'type' => 'tool_search' );
		}

		return $tools;
	}

	/** Convert a FunctionDeclaration DTO to a Responses function tool. */
	private function function_declaration_to_tool( FunctionDeclaration $declaration, bool $defer_loading ): array {
		$tool = array(
			'type'        => 'function',
			'name'        => $declaration->getName(),
			'description' => $declaration->getDescription(),
		);
		if ( $defer_loading ) {
			$tool['defer_loading'] = true;
		}

		$parameters = $declaration->getParameters();
		if ( is_array( $parameters ) && ! empty( $parameters ) ) {
			$tool['parameters'] = $parameters;
		}

		return $tool;
	}

	/**
	 * Return function names that should remain immediately visible to the model.
	 *
	 * Native Responses tool search receives the whole visible catalog. Keeping the
	 * established Tier-1 abilities non-deferred preserves the cold-start direct
	 * tool path while the long tail stays searchable behind `tool_search`.
	 *
	 * @return array<string, true>
	 */
	private function immediate_tool_function_names(): array {
		$ability_names = ToolDiscovery::DEFAULT_TIER_1;
		foreach ( ToolDiscovery::tier_1_for_run() as $ability_name ) {
			$ability_names[] = $ability_name;
		}

		$function_names = array();
		foreach ( array_unique( $ability_names ) as $ability_name ) {
			if ( class_exists( '\\WP_AI_Client_Ability_Function_Resolver' ) ) {
				$function_names[ \WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( $ability_name ) ] = true;
				continue;
			}

			$function_names[ 'wpab__' . str_replace( '/', '__', $ability_name ) ] = true;
		}

		return $function_names;
	}

	/** Return a stable namespace key for a WordPress ability function name. */
	private function namespace_key_for_function( string $function_name ): string {
		$raw = 'general';
		if ( str_starts_with( $function_name, 'wpab__' ) ) {
			$without_prefix = substr( $function_name, strlen( 'wpab__' ) );
			$parts          = explode( '__', $without_prefix );
			$raw            = (string) ( $parts[0] ?? 'general' );
		}

		$slug = strtolower( (string) preg_replace( '/[^A-Za-z0-9_]+/', '_', $raw ) );
		$slug = trim( $slug, '_' );

		return 'wp_abilities_' . ( '' !== $slug ? $slug : 'general' );
	}

	/** Return a concise namespace description for the model-visible search surface. */
	private function namespace_description( string $namespace_name ): string {
		$label = str_replace( '_', ' ', preg_replace( '/^wp_abilities_/', '', $namespace_name ) ?? $namespace_name );

		return sprintf( 'Searchable WordPress abilities for %s tasks on the current site.', $label );
	}

	/** Parse a Responses API response into the SDK result DTO shape. */
	protected function parse_response_to_generative_ai_result( Response $response ): GenerativeAiResult {
		$data = $response->getData();
		if ( ! is_array( $data ) ) {
			throw ResponseException::fromMissingData( 'OpenAI Responses', 'response body' );
		}

		$output = $data['output'] ?? null;
		if ( ! is_array( $output ) ) {
			throw ResponseException::fromMissingData( 'OpenAI Responses', 'output' );
		}

		$parts             = array();
		$has_function_call = false;

		foreach ( $output as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$type = isset( $item['type'] ) ? (string) $item['type'] : '';
			if ( 'message' === $type ) {
				$text = $this->extract_message_output_text( $item );
				if ( '' !== $text ) {
					$parts[] = new MessagePart( $text );
				}
				continue;
			}

			if ( 'function_call' === $type ) {
				$name = isset( $item['name'] ) && is_string( $item['name'] ) ? $item['name'] : '';
				if ( '' === $name ) {
					continue;
				}

				$parts[]           = new MessagePart(
					new FunctionCall(
						isset( $item['call_id'] ) && is_string( $item['call_id'] ) ? $item['call_id'] : null,
						$name,
						$this->decode_function_arguments( $item['arguments'] ?? null )
					)
				);
				$has_function_call = true;
			}
		}

		if ( empty( $parts ) ) {
			$parts[] = new MessagePart( '' );
		}

		$finish_reason = $this->finish_reason_for_response_data( $data, $has_function_call );
		$candidate     = new Candidate( new ModelMessage( $parts ), $finish_reason );
		$usage         = $this->parse_token_usage( is_array( $data['usage'] ?? null ) ? $data['usage'] : array() );

		return new GenerativeAiResult(
			isset( $data['id'] ) && is_string( $data['id'] ) ? $data['id'] : '',
			array( $candidate ),
			$usage,
			$this->providerMetadata(),
			$this->metadata(),
			array( 'responses_output' => $output )
		);
	}

	/**
	 * Return the SDK finish reason for a Responses API payload.
	 *
	 * @param array<string, mixed> $data              Decoded Responses payload.
	 * @param bool                 $has_function_call Whether the output included a function call.
	 */
	private function finish_reason_for_response_data( array $data, bool $has_function_call ): FinishReasonEnum {
		$incomplete_details = $data['incomplete_details'] ?? null;
		$incomplete_reason  = is_array( $incomplete_details ) && isset( $incomplete_details['reason'] )
			? (string) $incomplete_details['reason']
			: '';

		if ( 'incomplete' === (string) ( $data['status'] ?? '' ) && 'max_output_tokens' === $incomplete_reason ) {
			return FinishReasonEnum::length();
		}

		return $has_function_call ? FinishReasonEnum::toolCalls() : FinishReasonEnum::stop();
	}

	/**
	 * Extract assistant text from a Responses `message` output item.
	 *
	 * @param array<string, mixed> $item Responses message output item.
	 */
	private function extract_message_output_text( array $item ): string {
		$content = $item['content'] ?? '';
		if ( is_string( $content ) ) {
			return $content;
		}

		if ( ! is_array( $content ) ) {
			return '';
		}

		$text = array();
		foreach ( $content as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}
			$type = isset( $part['type'] ) ? (string) $part['type'] : '';
			if ( in_array( $type, array( 'output_text', 'text' ), true ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
				$text[] = $part['text'];
			}
		}

		return implode( "\n", $text );
	}

	/** Decode a Responses function-call arguments payload. */
	private function decode_function_arguments( mixed $arguments ): mixed {
		if ( ! is_string( $arguments ) ) {
			return $arguments;
		}

		if ( '' === trim( $arguments ) ) {
			return null;
		}

		$decoded = json_decode( $arguments, true );
		return JSON_ERROR_NONE === json_last_error() ? $decoded : $arguments;
	}

	/**
	 * Parse Responses token usage into the SDK token DTO.
	 *
	 * @param array<string, mixed> $usage Responses usage payload.
	 */
	private function parse_token_usage( array $usage ): TokenUsage {
		$prompt_tokens     = isset( $usage['input_tokens'] ) && is_numeric( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : 0;
		$completion_tokens = isset( $usage['output_tokens'] ) && is_numeric( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0;
		$total_tokens      = isset( $usage['total_tokens'] ) && is_numeric( $usage['total_tokens'] ) ? (int) $usage['total_tokens'] : $prompt_tokens + $completion_tokens;
		$details           = is_array( $usage['output_tokens_details'] ?? null ) ? $usage['output_tokens_details'] : array();
		$thought_tokens    = isset( $details['reasoning_tokens'] ) && is_numeric( $details['reasoning_tokens'] ) ? (int) $details['reasoning_tokens'] : null;

		return new TokenUsage( $prompt_tokens, $completion_tokens, $total_tokens, $thought_tokens );
	}

	/** Return whether a Responses failure should fall back to Chat Completions. */
	private function should_fallback_to_chat_completions( ClientException $e ): bool {
		$status = (int) $e->getCode();
		if ( 404 === $status ) {
			return true;
		}

		if ( ! in_array( $status, array( 400, 422 ), true ) ) {
			return false;
		}

		return (bool) preg_match( '/\b(tool_search|defer_loading|responses|unsupported|unknown parameter)\b/i', $e->getMessage() );
	}

	/**
	 * Generate through the existing Chat Completions model as a compatibility fallback.
	 *
	 * @param Message[] $prompt Prompt messages.
	 * @phpstan-param list<Message> $prompt
	 */
	private function generate_chat_completions_fallback( array $prompt ): GenerativeAiResult {
		$fallback = new SuperdavAiTextGenerationModel( $this->metadata(), $this->providerMetadata() );
		$fallback->setConfig( $this->getConfig() );
		$fallback->setHttpTransporter( $this->getHttpTransporter() );
		$fallback->setRequestAuthentication( $this->getRequestAuthentication() );

		$options = $this->getRequestOptions();
		if ( null !== $options ) {
			$fallback->setRequestOptions( $options );
		}

		return $fallback->generateTextResult( $prompt );
	}

	/** JSON encode a value for OpenAI string fields. */
	private function json_encode_for_api( mixed $value ): string {
		$encoded = wp_json_encode( $value );

		return is_string( $encoded ) ? $encoded : 'null';
	}
}
