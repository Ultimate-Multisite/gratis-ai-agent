<?php

declare(strict_types=1);

namespace SdAiAgent\Infrastructure\AiClient\Superdav;

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleImageGenerationModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenAI-compatible image generation model for the Superdav AI service.
 */
final class SuperdavAiImageGenerationModel extends AbstractOpenAiCompatibleImageGenerationModel {

	/**
	 * Create an authenticated API request.
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
	 * Prepare image generation parameters for the managed Superdav image alias.
	 *
	 * Superdav forwards this request to the configured OpenAI-compatible image
	 * backend. Newer GPT image models do not accept `response_format`, so the
	 * service returns inline image data using the backend default.
	 *
	 * @param array<int, mixed> $prompt Prompt messages.
	 * @return array<string, mixed>
	 */
	protected function prepareGenerateImageParams( array $prompt ): array {
		$params = parent::prepareGenerateImageParams( $prompt );
		unset( $params['response_format'] );

		return $params;
	}

	/**
	 * The Images API may return `created` rather than an explicit result ID.
	 *
	 * @param array<string, mixed> $response_data Response data.
	 * @return string
	 */
	protected function getResultId( array $response_data ): string {
		if ( isset( $response_data['id'] ) && is_string( $response_data['id'] ) ) {
			return $response_data['id'];
		}

		return isset( $response_data['created'] ) && is_int( $response_data['created'] )
			? 'img-' . $response_data['created']
			: '';
	}
}
