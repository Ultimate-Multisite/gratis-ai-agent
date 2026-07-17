<?php

declare(strict_types=1);

namespace SdAiAgent\Infrastructure\AiClient\Superdav;

use SdAiAgent\Bootstrap\ModelCapabilityHandler;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Model metadata directory backed by an OpenAI-compatible `/models` endpoint.
 */
final class SuperdavAiModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory {

	/**
	 * Create an authenticated API request.
	 *
	 * @param HttpMethodEnum                     $method  HTTP method.
	 * @param string                             $path    Endpoint path.
	 * @param array<string, string|list<string>> $headers Request headers.
	 * @param string|array<string, mixed>|null   $data    Request body/query data.
	 * @return Request
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		return new Request( $method, SuperdavAiProvider::url( $path ), $headers, $data );
	}

	/**
	 * Parse OpenAI-compatible model listing data into SDK metadata DTOs.
	 *
	 * @param Response $response HTTP response.
	 * @return list<ModelMetadata>
	 */
	protected function parseResponseToModelMetadataList( Response $response ): array {
		$data = $response->getData();

		if ( ! is_array( $data ) || ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
			return array();
		}

		ModelCapabilityHandler::ingest_models_payload( $data );

		$models = array();
		foreach ( $data['data'] as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['id'] ) || ! is_string( $item['id'] ) || '' === $item['id'] ) {
				continue;
			}

			$name = isset( $item['name'] ) && is_string( $item['name'] ) && '' !== $item['name']
				? $item['name']
				: $item['id'];

			$capabilities = self::supported_capabilities( $item );

			$models[] = new ModelMetadata(
				$item['id'],
				$name,
				$capabilities,
				self::supported_options( $capabilities )
			);
		}

		return $models;
	}

	/**
	 * Common OpenAI-compatible options for the advertised model capabilities.
	 *
	 * @param array $capabilities Model capabilities.
	 * @phpstan-param list<CapabilityEnum> $capabilities
	 * @return list<SupportedOption>
	 */
	private static function supported_options( array $capabilities ): array {
		if ( self::has_capability( $capabilities, CapabilityEnum::imageGeneration() ) ) {
			return self::image_supported_options();
		}

		return self::text_supported_options();
	}

	/**
	 * Common OpenAI-compatible text generation options.
	 *
	 * @return list<SupportedOption>
	 */
	private static function text_supported_options(): array {
		return array(
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::topP() ),
			new SupportedOption( OptionEnum::stopSequences() ),
			new SupportedOption( OptionEnum::presencePenalty() ),
			new SupportedOption( OptionEnum::frequencyPenalty() ),
			new SupportedOption( OptionEnum::functionDeclarations() ),
			new SupportedOption( OptionEnum::outputMimeType(), array( 'text/plain', 'application/json' ) ),
			new SupportedOption( OptionEnum::outputSchema() ),
			new SupportedOption( OptionEnum::customOptions() ),
		);
	}

	/**
	 * Common OpenAI-compatible image generation options.
	 *
	 * @return list<SupportedOption>
	 */
	private static function image_supported_options(): array {
		return array(
			new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
			new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::image() ) ) ),
			new SupportedOption( OptionEnum::candidateCount() ),
			new SupportedOption( OptionEnum::outputMimeType(), array( 'image/png', 'image/jpeg', 'image/webp' ) ),
			new SupportedOption( OptionEnum::outputFileType(), array( FileTypeEnum::inline() ) ),
			new SupportedOption(
				OptionEnum::outputMediaOrientation(),
				array(
					MediaOrientationEnum::square(),
					MediaOrientationEnum::landscape(),
					MediaOrientationEnum::portrait(),
				)
			),
			new SupportedOption( OptionEnum::outputMediaAspectRatio(), array( '1:1', '3:2', '2:3' ) ),
			new SupportedOption( OptionEnum::customOptions() ),
		);
	}

	/**
	 * Determine whether a capability list contains a specific capability.
	 *
	 * @param array          $capabilities Model capabilities.
	 * @phpstan-param list<CapabilityEnum> $capabilities
	 * @param CapabilityEnum $target Capability to find.
	 * @return bool
	 */
	private static function has_capability( array $capabilities, CapabilityEnum $target ): bool {
		foreach ( $capabilities as $capability ) {
			if ( $capability->equals( $target ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Parse provider-advertised capability flags into SDK capability enums.
	 *
	 * @param array<string, mixed> $item Model item from `/v1/models`.
	 * @return list<CapabilityEnum>
	 */
	private static function supported_capabilities( array $item ): array {
		$values = array();

		foreach ( array( 'capabilities', 'supported_capabilities' ) as $key ) {
			if ( ! isset( $item[ $key ] ) || ! is_array( $item[ $key ] ) ) {
				continue;
			}
			foreach ( $item[ $key ] as $capability_key => $capability_value ) {
				if ( is_string( $capability_key ) && true === $capability_value ) {
					$values[] = $capability_key;
					continue;
				}
				if ( is_string( $capability_value ) ) {
					$values[] = $capability_value;
				}
			}
		}

		foreach ( $item as $key => $value ) {
			if ( is_string( $key ) && str_starts_with( $key, 'supports_' ) && true === $value ) {
				$values[] = substr( $key, strlen( 'supports_' ) );
			}
		}

		if ( array() === $values ) {
			return array( CapabilityEnum::textGeneration() );
		}

		$capabilities = array();
		foreach ( array_unique( $values ) as $value ) {
			$capability = CapabilityEnum::tryFrom( str_replace( '-', '_', strtolower( $value ) ) );
			if ( $capability instanceof CapabilityEnum ) {
				$capabilities[] = $capability;
			}
		}

		return array() === $capabilities ? array( CapabilityEnum::textGeneration() ) : $capabilities;
	}
}
