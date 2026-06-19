<?php

declare(strict_types=1);

namespace SdAiAgent\Infrastructure\AiClient\Superdav;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * First-party provider for the Superdav-hosted OpenAI-compatible AI service.
 */
final class SuperdavAiProvider extends AbstractApiProvider {

	public const PROVIDER_ID       = 'sd-ai-agent-cloud';
	public const CREDENTIAL_OPTION = 'connectors_ai_sd_ai_agent_cloud_api_key';

	/**
	 * Create a model instance for the provider.
	 *
	 * @param ModelMetadata    $model_metadata    Model metadata.
	 * @param ProviderMetadata $provider_metadata Provider metadata.
	 * @return ModelInterface
	 */
	protected static function createModel( ModelMetadata $model_metadata, ProviderMetadata $provider_metadata ): ModelInterface {
		return new SuperdavAiTextGenerationModel( $model_metadata, $provider_metadata );
	}

	/**
	 * Create provider metadata.
	 *
	 * @return ProviderMetadata
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			self::PROVIDER_ID,
			'Superdav AI',
			ProviderTypeEnum::cloud(),
			null,
			RequestAuthenticationMethod::apiKey(),
			'OpenAI-compatible AI service hosted for Superdav AI Agent.'
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
			: '';

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
