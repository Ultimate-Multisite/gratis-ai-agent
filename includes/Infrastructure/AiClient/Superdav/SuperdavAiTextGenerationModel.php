<?php

declare(strict_types=1);

namespace SdAiAgent\Infrastructure\AiClient\Superdav;

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenAI-compatible text generation model for the Superdav AI service.
 */
final class SuperdavAiTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel {

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
		if ( 'chat/completions' === trim( $path, '/' ) && is_array( $data ) && ! array_key_exists( 'reasoning_effort', $data ) ) {
			$model_id = isset( $data['model'] ) && is_string( $data['model'] ) ? $data['model'] : '';
			$effort   = SuperdavAiProvider::reasoning_effort_for_model( $model_id );
			if ( '' !== $effort ) {
				$data['reasoning_effort'] = $effort;
			}
		}

		return new Request( $method, SuperdavAiProvider::url( $path ), $headers, $data, $this->getRequestOptions() );
	}
}
