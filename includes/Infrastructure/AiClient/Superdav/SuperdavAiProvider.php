<?php

declare(strict_types=1);

namespace SdAiAgent\Infrastructure\AiClient\Superdav;

use SdAiAgent\Core\ProviderTraceLogger;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * First-party provider for the Superdav-hosted OpenAI-compatible AI service.
 */
final class SuperdavAiProvider extends AbstractApiProvider {

	public const PROVIDER_ID                = 'sd-ai-agent-cloud';
	public const CREDENTIAL_OPTION          = 'connectors_ai_sd_ai_agent_cloud_api_key';
	public const DEFAULT_BASE_URL           = 'https://api.sdaiagent.com/v1';
	public const FAST_MODEL_ID              = 'superdav-chat-fast';
	public const DEFAULT_MODEL_ID           = 'superdav-chat-pro';
	public const STRONG_MODEL_ID            = 'superdav-chat-strong';
	public const IMAGE_MODEL_ID             = 'superdav-image';
	public const SESSION_ATTRIBUTION_HEADER = 'X-Superdav-Session-ID';

	/**
	 * Reasoning effort hints for managed Superdav model aliases.
	 *
	 * The plugin sends these as OpenAI-compatible `reasoning_effort` custom
	 * options so the managed service receives an explicit effort level instead
	 * of relying on an opaque backend default. Values intentionally stay within
	 * the OpenAI-compatible low/medium/high vocabulary.
	 *
	 * @var array<string, string>
	 */
	private const MODEL_REASONING_EFFORTS = array(
		self::FAST_MODEL_ID    => 'low',
		self::DEFAULT_MODEL_ID => 'medium',
		self::STRONG_MODEL_ID  => 'high',
	);

	/**
	 * Add non-secret local chat attribution to a managed inference request.
	 *
	 * The site-scoped service token remains the authorization boundary. The
	 * numeric session ID is used only to group metered usage for the same site.
	 *
	 * @param array<string, string|list<string>> $headers Existing request headers.
	 * @return array<string, string|list<string>> Headers with safe attribution.
	 */
	public static function with_session_attribution( array $headers ): array {
		$session_id = ProviderTraceLogger::get_runtime_session_id();
		if ( $session_id > 0 ) {
			$headers[ self::SESSION_ATTRIBUTION_HEADER ] = (string) $session_id;
		}

		return $headers;
	}

	/**
	 * Return the managed model that new Superdav-provider installs should prefer.
	 */
	public static function default_model_id(): string {
		/**
		 * Filter the preferred managed Superdav model ID for clean installs.
		 *
		 * The returned value is still validated against the provider's advertised
		 * models before Settings uses it.
		 *
		 * @param string $model_id Built-in preferred model ID.
		 */
		$model_id = apply_filters( 'sd_ai_agent_superdav_default_model', self::DEFAULT_MODEL_ID );

		return is_string( $model_id ) && '' !== trim( $model_id )
			? trim( $model_id )
			: self::DEFAULT_MODEL_ID;
	}

	/**
	 * Return the reasoning-effort hint to send for a managed model alias.
	 */
	public static function reasoning_effort_for_model( string $model_id ): string {
		$normalised = strtolower( trim( $model_id ) );
		$effort     = self::MODEL_REASONING_EFFORTS[ $normalised ] ?? '';

		/**
		 * Filter the OpenAI-compatible `reasoning_effort` sent to Superdav cloud.
		 *
		 * Return an empty string to omit the field. Non-standard values are ignored
		 * to avoid sending request bodies the managed endpoint may reject.
		 *
		 * @param string $effort   Built-in effort hint: '', 'low', 'medium', or 'high'.
		 * @param string $model_id Model ID from the outgoing chat-completions body.
		 */
		$effort = apply_filters( 'sd_ai_agent_superdav_reasoning_effort', $effort, $model_id );
		if ( ! is_string( $effort ) ) {
			return '';
		}

		$effort = strtolower( trim( $effort ) );
		return in_array( $effort, array( 'low', 'medium', 'high' ), true ) ? $effort : '';
	}

	/**
	 * Create a model instance for the provider.
	 *
	 * @param ModelMetadata    $model_metadata    Model metadata.
	 * @param ProviderMetadata $provider_metadata Provider metadata.
	 * @return ModelInterface
	 */
	protected static function createModel( ModelMetadata $model_metadata, ProviderMetadata $provider_metadata ): ModelInterface {
		foreach ( $model_metadata->getSupportedCapabilities() as $capability ) {
			if ( $capability->equals( CapabilityEnum::imageGeneration() ) ) {
				return new SuperdavAiImageGenerationModel( $model_metadata, $provider_metadata );
			}
		}

		if ( self::responses_tool_search_enabled( $provider_metadata->getId(), $model_metadata->getId() ) ) {
			return new SuperdavAiResponsesToolSearchTextGenerationModel( $model_metadata, $provider_metadata );
		}

		return new SuperdavAiTextGenerationModel( $model_metadata, $provider_metadata );
	}

	/**
	 * Whether the Responses API tool-search experiment should handle a model.
	 *
	 * Tool search is an OpenAI Responses API feature (not Chat Completions) and
	 * is documented for GPT-5.4+ models. Keep the switch filterable so managed
	 * aliases can opt in once the service confirms the underlying model/endpoint
	 * supports `/responses` with `tool_search`.
	 */
	public static function responses_tool_search_enabled( string $provider_id, string $model_id ): bool {
		if ( self::PROVIDER_ID !== $provider_id ) {
			return false;
		}

		$supported = self::model_supports_responses_tool_search( $model_id );

		/**
		 * Filter whether the Superdav provider should use OpenAI Responses tool search.
		 *
		 * Return true to opt a managed alias into the experiment after verifying the
		 * endpoint supports `/responses` and the backing model is GPT-5.4 or later.
		 *
		 * @param bool   $enabled     Default true only for explicit GPT-5.4+ IDs.
		 * @param string $provider_id AI Client provider ID.
		 * @param string $model_id    Provider model ID.
		 * @param bool   $supported   Whether the model ID itself matches GPT-5.4+.
		 */
		$enabled = apply_filters( 'sd_ai_agent_openai_tool_search_enabled', $supported, $provider_id, $model_id, $supported );

		return (bool) $enabled;
	}

	/**
	 * Whether a model ID is in the documented OpenAI tool-search support range.
	 */
	public static function model_supports_responses_tool_search( string $model_id ): bool {
		$normalised = strtolower( trim( $model_id ) );
		if ( '' === $normalised ) {
			return false;
		}

		if ( preg_match( '/^gpt-5\.(\d+)(?:[^0-9]|$)/', $normalised, $matches ) ) {
			return (int) $matches[1] >= 4;
		}

		if ( preg_match( '/^gpt-(\d+)(?:[.\-]|$)/', $normalised, $matches ) ) {
			return (int) $matches[1] >= 6;
		}

		return false;
	}

	/**
	 * Create provider metadata.
	 *
	 * @return ProviderMetadata
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			self::PROVIDER_ID,
			'SD AI',
			ProviderTypeEnum::cloud(),
			null,
			RequestAuthenticationMethod::apiKey(),
			'OpenAI-compatible AI service hosted for SD AI Agent.'
		);
	}

	/**
	 * Create provider availability checker.
	 *
	 * @return ProviderAvailabilityInterface
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new SuperdavAiProviderAvailability();
	}

	/**
	 * Create model metadata directory.
	 *
	 * @return ModelMetadataDirectoryInterface
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new SuperdavAiModelMetadataDirectory();
	}

	/**
	 * Resolve the OpenAI-compatible API base URL.
	 *
	 * @return string
	 */
	protected static function baseUrl(): string {
		$base_url = defined( 'SD_AI_AGENT_CLOUD_BASE_URL' ) && is_string( constant( 'SD_AI_AGENT_CLOUD_BASE_URL' ) )
			? (string) constant( 'SD_AI_AGENT_CLOUD_BASE_URL' )
			: self::DEFAULT_BASE_URL;

		/**
		 * Filter the Superdav AI cloud OpenAI-compatible API base URL.
		 *
		 * The URL must not include credentials. Authentication is supplied via
		 * {@see SuperdavAiProvider::CREDENTIAL_OPTION} and SDK request auth.
		 *
		 * @param string $base_url Base URL, optionally including an API version path.
		 */
		$base_url = apply_filters( 'sd_ai_agent_cloud_base_url', $base_url );

		return is_string( $base_url ) ? rtrim( $base_url, '/' ) : '';
	}

	/**
	 * Return the configured OpenAI-compatible API base URL.
	 *
	 * @return string Base URL without a trailing slash, or empty when not configured.
	 */
	public static function configured_base_url(): string {
		return self::baseUrl();
	}

	/**
	 * Build a Superdav service URL from the configured cloud base URL.
	 *
	 * @param string $path Service path relative to the configured base URL.
	 * @return string Full URL, or empty when the cloud base URL is not configured.
	 */
	public static function configured_service_url( string $path ): string {
		$base_url = self::configured_base_url();
		if ( '' === $base_url ) {
			return '';
		}

		return $base_url . '/' . ltrim( $path, '/' );
	}

	/**
	 * Return the configured API host for provider `/models` ingestion gates.
	 *
	 * @return string Lowercase host, or an empty string when no base URL is configured.
	 */
	public static function configured_base_host(): string {
		$parts = wp_parse_url( self::baseUrl() );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		return strtolower( (string) $parts['host'] );
	}
}
