<?php

declare(strict_types=1);

namespace SdAiAgent\Infrastructure\AiClient\Superdav;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextToSpeechConversion\Contracts\TextToSpeechConversionModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Text-to-speech conversion model for the managed Superdav service.
 */
final class SuperdavAiTextToSpeechConversionModel extends AbstractApiBasedModel implements TextToSpeechConversionModelInterface {

	public const DEFAULT_VOICE        = 'alloy';
	public const DEFAULT_MIME_TYPE    = 'audio/mpeg';
	public const MAX_INPUT_CHARACTERS = 4096;
	public const MAX_RESPONSE_BYTES   = 20 * 1024 * 1024;
	public const MIN_SPEED            = 0.25;
	public const MAX_SPEED            = 4.0;

	/** @var list<string> */
	public const SUPPORTED_VOICES = array( self::DEFAULT_VOICE );

	/** @var list<string> */
	public const SUPPORTED_LANGUAGES = array( 'en-US' );

	/** @var array<string, string> */
	public const MIME_TYPE_TO_RESPONSE_FORMAT = array(
		'audio/mpeg' => 'mp3',
		'audio/ogg'  => 'opus',
		'audio/aac'  => 'aac',
		'audio/flac' => 'flac',
		'audio/wav'  => 'wav',
		'audio/l16'  => 'pcm',
	);

	/**
	 * Convert text messages into one inline audio file.
	 *
	 * @param array<int, Message> $prompt Prompt messages.
	 * @phpstan-param list<Message> $prompt
	 * @return GenerativeAiResult Speech result.
	 */
	public function convertTextToSpeechResult( array $prompt ): GenerativeAiResult {
		$mime_type = $this->prepare_output_mime_type();
		$request   = $this->createRequest(
			HttpMethodEnum::POST(),
			'audio/speech',
			array( 'Content-Type' => 'application/json' ),
			$this->prepare_convert_params( $prompt, $mime_type )
		);
		$request   = $this->getRequestAuthentication()->authenticateRequest( $request );
		$response  = $this->getHttpTransporter()->send( $request );

		ResponseUtil::throwIfNotSuccessful( $response );

		return $this->parse_response_to_generative_ai_result( $response, $mime_type );
	}

	/**
	 * Create an authenticated-service request with SDK transport options.
	 *
	 * @param HttpMethodEnum                     $method  HTTP method.
	 * @param string                             $path    Service path.
	 * @param array<string, string|list<string>> $headers Request headers.
	 * @param string|array<string, mixed>|null   $data    Request data.
	 * @return Request
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), mixed $data = null ): Request {
		return new Request( $method, SuperdavAiProvider::url( $path ), SuperdavAiProvider::with_session_attribution( $headers ), $data, $this->getRequestOptions() );
	}

	/**
	 * Prepare the allowlisted service request parameters.
	 *
	 * @param array<int, Message> $prompt    Prompt messages.
	 * @param string              $mime_type Requested output MIME type.
	 * @phpstan-param list<Message> $prompt
	 * @return array<string, mixed>
	 */
	private function prepare_convert_params( array $prompt, string $mime_type ): array {
		$instructions = $this->getConfig()->getSystemInstruction();
		if ( is_string( $instructions ) && '' !== trim( $instructions ) ) {
			throw new InvalidArgumentException( 'Instructions are not supported for managed text-to-speech conversion.' );
		}

		$params = array(
			'model'           => $this->metadata()->getId(),
			'input'           => $this->prepare_prompt_text( $prompt ),
			'voice'           => $this->prepare_voice(),
			'response_format' => self::MIME_TYPE_TO_RESPONSE_FORMAT[ $mime_type ],
		);

		foreach ( $this->getConfig()->getCustomOptions() as $key => $value ) {
			if ( array_key_exists( $key, $params ) ) {
				throw new InvalidArgumentException( sprintf( 'The custom option "%s" conflicts with a required text-to-speech parameter.', esc_html( (string) $key ) ) );
			}

			switch ( $key ) {
				case 'speed':
					if ( ( ! is_int( $value ) && ! is_float( $value ) ) || ! is_finite( (float) $value ) || $value < self::MIN_SPEED || $value > self::MAX_SPEED ) {
						throw new InvalidArgumentException( 'The custom option "speed" must be a finite number from 0.25 through 4.' );
					}
					$params['speed'] = $value;
					break;

				case 'language':
					if ( ! is_string( $value ) || ! in_array( $value, self::SUPPORTED_LANGUAGES, true ) ) {
						throw new InvalidArgumentException( 'The custom option "language" is not supported by the managed text-to-speech service.' );
					}
					$params['language'] = $value;
					break;

				default:
					throw new InvalidArgumentException( sprintf( 'The custom option "%s" is not supported for managed text-to-speech conversion.', esc_html( (string) $key ) ) );
			}
		}

		return $params;
	}

	/**
	 * Join only text parts and enforce the service input ceiling.
	 *
	 * @param array<int, Message> $prompt Prompt messages.
	 * @phpstan-param list<Message> $prompt
	 * @return string
	 */
	private function prepare_prompt_text( array $prompt ): string {
		$text_parts = array();
		foreach ( $prompt as $message ) {
			foreach ( $message->getParts() as $part ) {
				if ( ! $part instanceof MessagePart || ! $part->getType()->isText() ) {
					continue;
				}
				$text = $part->getText();
				if ( is_string( $text ) ) {
					$text_parts[] = $text;
				}
			}
		}

		$input = implode( "\n", $text_parts );
		if ( '' === trim( $input ) ) {
			throw new InvalidArgumentException( 'The prompt must contain text to convert to speech.' );
		}

		if ( self::unicode_length( $input ) > self::MAX_INPUT_CHARACTERS ) {
			throw new InvalidArgumentException( 'The text-to-speech prompt exceeds the 4096-character service limit.' );
		}

		return $input;
	}

	/** Resolve and validate the configured voice. */
	private function prepare_voice(): string {
		$voice = $this->getConfig()->getOutputSpeechVoice();
		$voice = null === $voice || '' === trim( $voice ) ? self::DEFAULT_VOICE : trim( $voice );

		if ( ! in_array( $voice, self::SUPPORTED_VOICES, true ) ) {
			throw new InvalidArgumentException( 'The configured output speech voice is not supported by the managed service.' );
		}

		return $voice;
	}

	/** Resolve and validate the configured output MIME type. */
	private function prepare_output_mime_type(): string {
		$mime_type = $this->getConfig()->getOutputMimeType();
		$mime_type = null === $mime_type || '' === trim( $mime_type ) ? self::DEFAULT_MIME_TYPE : strtolower( trim( $mime_type ) );

		if ( ! isset( self::MIME_TYPE_TO_RESPONSE_FORMAT[ $mime_type ] ) ) {
			throw new InvalidArgumentException( 'The configured output MIME type is not supported for managed text-to-speech conversion.' );
		}

		return $mime_type;
	}

	/**
	 * Convert validated binary audio into an inline SDK result.
	 */
	private function parse_response_to_generative_ai_result( Response $response, string $requested_mime_type ): GenerativeAiResult {
		$body = $response->getBody();
		if ( null === $body || '' === $body ) {
			throw ResponseException::fromMissingData( 'SD AI', 'body' );
		}
		if ( strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
			throw ResponseException::fromInvalidData( 'SD AI', 'body', 'The audio response exceeds the supported size limit.' );
		}

		$content_type       = $response->getHeaderAsString( 'content-type' );
		$response_mime_type = is_string( $content_type )
			? strtolower( trim( explode( ';', $content_type, 2 )[0] ) )
			: '';
		if ( ! isset( self::MIME_TYPE_TO_RESPONSE_FORMAT[ $response_mime_type ] ) ) {
			throw ResponseException::fromInvalidData( 'SD AI', 'content-type', 'The audio response MIME type is unsupported.' );
		}
		if ( $response_mime_type !== $requested_mime_type ) {
			throw ResponseException::fromInvalidData( 'SD AI', 'content-type', 'The audio response MIME type does not match the requested format.' );
		}

		$file      = new File( base64_encode( $body ), $response_mime_type ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary audio must be represented as an inline SDK file.
		$message   = new Message( MessageRoleEnum::model(), array( new MessagePart( $file ) ) );
		$candidate = new Candidate( $message, FinishReasonEnum::stop() );

		return new GenerativeAiResult(
			'superdav-tts-' . wp_generate_uuid4(),
			array( $candidate ),
			new TokenUsage( 0, 0, 0 ),
			$this->providerMetadata(),
			$this->metadata()
		);
	}

	/** Count Unicode code points without silently accepting invalid UTF-8. */
	private static function unicode_length( string $value ): int {
		if ( 1 !== preg_match( '//u', $value ) ) {
			throw new InvalidArgumentException( 'The text-to-speech prompt must be valid UTF-8 text.' );
		}

		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value, 'UTF-8' );
		}

		$count = preg_match_all( '/./us', $value );
		if ( false === $count ) {
			throw new InvalidArgumentException( 'The text-to-speech prompt must be valid UTF-8 text.' );
		}

		return $count;
	}
}
