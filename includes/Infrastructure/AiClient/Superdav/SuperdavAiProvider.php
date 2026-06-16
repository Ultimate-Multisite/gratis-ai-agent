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

	public const PROVIDER_ID = 'sd-ai-agent-cloud';

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
		 * The URL must not include credentials. Authentication is supplied by
		 * WordPress core's Connectors bootstrap through SDK request auth.
		 *
		 * @param string $base_url Base URL, optionally including an API version path.
		 */
		$base_url = apply_filters( 'sd_ai_agent_cloud_base_url', $base_url );

		return is_string( $base_url ) ? rtrim( $base_url, '/' ) : '';
	}
}
