<?php

declare(strict_types=1);

namespace SdAiAgent\Infrastructure\AiClient\Superdav;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleImageGenerationModel;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenAI-compatible image generation and editing model for Superdav AI.
 *
 * Text-only prompts use the Images API's `/images/generations` endpoint.
 * Prompts that include a source image are sent as multipart form data to
 * `/images/edits`, matching OpenAI's image-editing contract.
 */
final class SuperdavAiImageGenerationModel extends AbstractOpenAiCompatibleImageGenerationModel {

	/**
	 * Generate or edit an image according to the prompt modalities.
	 *
	 * The upstream OpenAI-compatible base class always sends image requests to
	 * `/images/generations`. The Superdav service proxies OpenAI's Images API,
	 * where reference images require the distinct `/images/edits` endpoint.
	 *
	 * @param array<int, Message> $prompt Prompt messages.
	 * @phpstan-param list<Message> $prompt
	 * @return GenerativeAiResult Generated or edited image result.
	 */
	public function generateImageResult( array $prompt ): GenerativeAiResult {
		if ( ! $this->prompt_contains_image( $prompt ) ) {
			return parent::generateImageResult( $prompt );
		}

		return $this->generate_image_edit_result( $prompt );
	}

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
		return new Request( $method, SuperdavAiProvider::url( $path ), SuperdavAiProvider::with_session_attribution( $headers ), $data, $this->getRequestOptions() );
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
	 * Check whether the prompt includes an image file.
	 *
	 * @param array<int, Message> $prompt Prompt messages.
	 * @phpstan-param list<Message> $prompt
	 * @return bool Whether the prompt contains an image file.
	 */
	private function prompt_contains_image( array $prompt ): bool {
		foreach ( $prompt as $message ) {
			foreach ( $message->getParts() as $part ) {
				$file = $part->getFile();
				if ( $file instanceof File && $file->isImage() ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Send an image edit request to the OpenAI-compatible Images API.
	 *
	 * @param array<int, Message> $prompt Prompt containing a user instruction and source image.
	 * @phpstan-param list<Message> $prompt
	 * @return GenerativeAiResult Edited image result.
	 */
	private function generate_image_edit_result( array $prompt ): GenerativeAiResult {
		$image_file = $this->extract_edit_image( $prompt );
		$params     = $this->prepareGenerateImageParams( $prompt );
		$boundary   = bin2hex( random_bytes( 16 ) );
		$body       = $this->build_multipart_body( $params, $image_file, $boundary );

		$request  = $this->createRequest(
			HttpMethodEnum::POST(),
			'images/edits',
			array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
			$body
		);
		$request  = $this->getRequestAuthentication()->authenticateRequest( $request );
		$response = $this->getHttpTransporter()->send( $request );
		$this->throwIfNotSuccessful( $response );

		$output_format = isset( $params['output_format'] ) && is_string( $params['output_format'] )
			? $params['output_format']
			: 'png';

		return $this->parseResponseToGenerativeAiResult( $response, 'image/' . $output_format );
	}

	/**
	 * Extract the first image file from an edit prompt.
	 *
	 * @param array<int, Message> $prompt Prompt messages.
	 * @phpstan-param list<Message> $prompt
	 * @return File Source image file.
	 * @throws InvalidArgumentException When the prompt has no editable image.
	 */
	private function extract_edit_image( array $prompt ): File {
		foreach ( $prompt as $message ) {
			foreach ( $message->getParts() as $part ) {
				$file = $part->getFile();
				if ( $file instanceof File && $file->isImage() ) {
					return $file;
				}
			}
		}

		throw new InvalidArgumentException( 'The prompt must contain an image file to edit.' );
	}

	/**
	 * Build the multipart payload required by the `/images/edits` endpoint.
	 *
	 * The SDK represents local files and data URIs as inline base64 data. OpenAI
	 * image edits require binary upload content, while a remote URL cannot be
	 * forwarded as the required `image` form field.
	 *
	 * @param array<string, mixed> $params Scalar request fields.
	 * @param File                 $image_file Source image to upload.
	 * @param string               $boundary Multipart boundary.
	 * @return string Multipart request body.
	 * @throws InvalidArgumentException When the source image or a field is invalid.
	 */
	private function build_multipart_body( array $params, File $image_file, string $boundary ): string {
		if ( $image_file->isRemote() ) {
			throw new InvalidArgumentException( 'Remote image URLs are not supported for image editing. Please provide an inline image.' );
		}

		$base64_data = $image_file->getBase64Data();
		if ( ! is_string( $base64_data ) || '' === $base64_data ) {
			throw new InvalidArgumentException( 'The image file has no base64 data.' );
		}

		$binary_data = base64_decode( $base64_data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $binary_data ) {
			throw new InvalidArgumentException( 'Failed to decode the base64 image data.' );
		}

		$body = '';
		foreach ( $params as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				throw new InvalidArgumentException( sprintf( 'The parameter "%s" must be a scalar value for multipart requests.', esc_html( $key ) ) );
			}

			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="' . $key . '"' . "\r\n\r\n";
			$body .= (string) $value . "\r\n";
		}

		$mime_type = $image_file->getMimeType();
		$extension = 'image/jpeg' === $mime_type ? 'jpg' : substr( $mime_type, strlen( 'image/' ) );
		if ( '' === $extension ) {
			throw new InvalidArgumentException( 'The image file has an invalid MIME type.' );
		}

		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="image"; filename="image.' . $extension . '"' . "\r\n";
		$body .= 'Content-Type: ' . $mime_type . "\r\n\r\n";
		$body .= $binary_data . "\r\n";
		$body .= '--' . $boundary . '--' . "\r\n";

		return $body;
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
