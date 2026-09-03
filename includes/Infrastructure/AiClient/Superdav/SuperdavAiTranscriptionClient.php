<?php

declare(strict_types=1);

namespace SdAiAgent\Infrastructure\AiClient\Superdav;

use SdAiAgent\Core\ProviderCredentialLoader;
use SdAiAgent\Core\SpeechLocaleResolver;
use SdAiAgent\Core\SuperdavSiteConnectionService;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bounded explicit client for Superdav speech capabilities and transcription.
 *
 * WordPress AI Client does not yet expose a speech-to-text model interface, so
 * this class reuses its provider registry, authentication, and HTTP transport
 * without advertising transcription as an SDK model capability.
 */
final class SuperdavAiTranscriptionClient {

	public const MODEL_ID              = 'superdav-transcribe';
	public const INPUT_MIME_TYPE       = 'audio/wav';
	public const MAX_REQUEST_BYTES     = 10 * 1024 * 1024;
	public const MAX_AUDIO_BYTES       = self::MAX_REQUEST_BYTES - 4096;
	public const MAX_DURATION_SECONDS  = 25 * 60;
	public const MAX_PROMPT_CHARACTERS = 2048;
	public const MAX_RESPONSE_BYTES    = 64 * 1024;
	public const REQUEST_TIMEOUT       = 35.0;

	private SuperdavSiteConnectionService $connection;
	private SpeechLocaleResolver $locale_resolver;

	public function __construct( ?SuperdavSiteConnectionService $connection = null, ?SpeechLocaleResolver $locale_resolver = null ) {
		$this->connection      = $connection ?? new SuperdavSiteConnectionService();
		$this->locale_resolver = $locale_resolver ?? new SpeechLocaleResolver();
	}

	/**
	 * Fetch the current authenticated speech capability contract.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_capabilities(): array|WP_Error {
		$response = $this->send_request(
			HttpMethodEnum::GET(),
			'audio/capabilities',
			array( 'Accept' => 'application/json' )
		);
		if ( $response instanceof WP_Error ) {
			return $response;
		}

		return $this->decode_json_response( $response );
	}

	/**
	 * Forward one already-validated WAV buffer and return normalized text only.
	 *
	 * @return array{text:string,language?:string,duration_ms?:int,request_id:string}|WP_Error
	 */
	public function transcribe( string $audio, ?string $language = null, ?string $prompt = null ): array|WP_Error {
		if ( '' === $audio || strlen( $audio ) > self::MAX_AUDIO_BYTES ) {
			return $this->error( 'sd_ai_agent_audio_too_large', __( 'The audio recording exceeds the supported size limit.', 'superdav-ai-agent' ), 413 );
		}
		if ( null !== $language ) {
			$language = $this->locale_resolver->normalize_client_locale( $language );
			if ( null === $language ) {
				return $this->error( 'sd_ai_agent_invalid_speech_language', __( 'The speech language is invalid.', 'superdav-ai-agent' ), 400 );
			}
		}
		if ( null !== $prompt && ( '' === trim( $prompt ) || $this->unicode_length( $prompt ) > self::MAX_PROMPT_CHARACTERS ) ) {
			return $this->error( 'sd_ai_agent_invalid_transcription_prompt', __( 'The transcription prompt is invalid.', 'superdav-ai-agent' ), 400 );
		}

		try {
			$boundary = 'sd-ai-agent-' . bin2hex( random_bytes( 16 ) );
		} catch ( \Random\RandomException ) {
			return $this->unavailable_error();
		}
		$body = $this->build_multipart_body( $audio, $language, $prompt, $boundary );
		if ( strlen( $body ) > self::MAX_REQUEST_BYTES ) {
			return $this->error( 'sd_ai_agent_audio_too_large', __( 'The audio recording exceeds the supported size limit.', 'superdav-ai-agent' ), 413 );
		}

		$response = $this->send_request(
			HttpMethodEnum::POST(),
			'audio/transcriptions',
			array(
				'Accept'          => 'application/json',
				'Content-Type'    => 'multipart/form-data; boundary=' . $boundary,
				'Idempotency-Key' => wp_generate_uuid4(),
			),
			$body
		);
		unset( $body, $audio );
		if ( $response instanceof WP_Error ) {
			return $response;
		}

		$data = $this->decode_json_response( $response );
		if ( $data instanceof WP_Error ) {
			return $data;
		}

		$text = $data['text'] ?? null;
		if ( ! is_string( $text ) || '' === trim( $text ) || 1 !== preg_match( '//u', $text ) || $this->unicode_length( $text ) > 4096 ) {
			return $this->malformed_response_error();
		}

		$result = array(
			'text'       => $text,
			'request_id' => $this->sanitize_request_id( $data['request_id'] ?? null ),
		);
		if ( array_key_exists( 'language', $data ) ) {
			$response_language = is_string( $data['language'] ) ? $this->locale_resolver->normalize_client_locale( $data['language'] ) : null;
			if ( null === $response_language ) {
				return $this->malformed_response_error();
			}
			$result['language'] = $response_language;
		}
		if ( array_key_exists( 'duration', $data ) ) {
			if ( ! is_numeric( $data['duration'] ) ) {
				return $this->malformed_response_error();
			}
			$duration = (float) $data['duration'];
			if ( ! is_finite( $duration ) || $duration < 0 || $duration > self::MAX_DURATION_SECONDS ) {
				return $this->malformed_response_error();
			}
			$result['duration_ms'] = (int) ceil( $duration * 1000 );
		}

		return $result;
	}

	/**
	 * Send an authenticated request through the WordPress AI Client transport.
	 *
	 * @param HttpMethodEnum                     $method  HTTP method.
	 * @param string                             $path    Service-relative path.
	 * @param array<string, string|list<string>> $headers Request headers.
	 * @param string|null                        $body    Optional raw request body.
	 */
	private function send_request( HttpMethodEnum $method, string $path, array $headers, ?string $body = null ): Response|WP_Error {
		try {
			$status = $this->connection->ensure_site_token();
			if ( $status instanceof WP_Error || empty( $status['configured'] ) ) {
				return $this->unavailable_error();
			}

			ProviderCredentialLoader::load();
			$registry = AiClient::defaultRegistry();
			if ( ! $registry->hasProvider( SuperdavAiProvider::PROVIDER_ID ) ) {
				return $this->unavailable_error();
			}

			$authentication = $registry->getProviderRequestAuthentication( SuperdavAiProvider::PROVIDER_ID );
			if ( null === $authentication ) {
				return $this->unavailable_error();
			}

			$options = new RequestOptions();
			$options->setTimeout( self::REQUEST_TIMEOUT );
			$options->setConnectTimeout( 5.0 );
			$options->setMaxRedirects( 0 );
			$request = new Request(
				$method,
				SuperdavAiProvider::configured_service_url( $path ),
				SuperdavAiProvider::with_session_attribution( $headers ),
				$body,
				$options
			);
			$request = $authentication->authenticateRequest( $request );

			$response = $registry->getHttpTransporter()->send( $request );
		} catch ( \Throwable $e ) {
			return $this->is_timeout_exception( $e )
				? $this->error( 'sd_ai_agent_speech_timeout', __( 'The speech service timed out. Please try again.', 'superdav-ai-agent' ), 504 )
				: $this->unavailable_error();
		}

		if ( ! $response->isSuccessful() ) {
			return $this->map_response_error( $response->getStatusCode() );
		}

		return $response;
	}

	/** @return array<string, mixed>|WP_Error */
	private function decode_json_response( Response $response ): array|WP_Error {
		$body         = $response->getBody();
		$content_type = $response->getHeaderAsString( 'content-type' );
		$content_type = is_string( $content_type ) ? strtolower( trim( explode( ';', $content_type, 2 )[0] ) ) : '';
		if ( 'application/json' !== $content_type || null === $body || '' === $body || strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
			return $this->malformed_response_error();
		}

		$data = $response->getData();
		return is_array( $data ) ? $data : $this->malformed_response_error();
	}

	private function map_response_error( int $status ): WP_Error {
		if ( in_array( $status, array( 408, 504 ), true ) ) {
			return $this->error( 'sd_ai_agent_speech_timeout', __( 'The speech service timed out. Please try again.', 'superdav-ai-agent' ), 504 );
		}
		if ( 429 === $status ) {
			return $this->error( 'sd_ai_agent_speech_limit_exceeded', __( 'The speech service limit was reached. Please try again later.', 'superdav-ai-agent' ), 429 );
		}

		return $this->unavailable_error();
	}

	private function unavailable_error(): WP_Error {
		return $this->error( 'sd_ai_agent_speech_unavailable', __( 'The speech service is temporarily unavailable.', 'superdav-ai-agent' ), 503 );
	}

	private function malformed_response_error(): WP_Error {
		return $this->error( 'sd_ai_agent_speech_malformed_response', __( 'The speech service returned an invalid response.', 'superdav-ai-agent' ), 502 );
	}

	private function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	private function sanitize_request_id( mixed $request_id ): string {
		return is_string( $request_id ) && 1 === preg_match( '/^[A-Za-z0-9._:-]{1,128}$/', $request_id )
			? $request_id
			: 'speech-' . wp_generate_uuid4();
	}

	/** Detect transport timeout failures without exposing exception details. */
	private function is_timeout_exception( \Throwable $exception ): bool {
		$depth = 0;
		do {
			$message = strtolower( $exception->getMessage() );
			if ( str_contains( $message, 'timed out' ) || str_contains( $message, 'timeout' ) || str_contains( $message, 'time-out' ) ) {
				return true;
			}

			$exception = $exception->getPrevious();
			++$depth;
		} while ( $exception instanceof \Throwable && $depth < 5 );

		return false;
	}

	private function build_multipart_body( string $audio, ?string $language, ?string $prompt, string $boundary ): string {
		$fields = array(
			'model'           => self::MODEL_ID,
			'response_format' => 'json',
		);
		if ( null !== $language && '' !== $language ) {
			$fields['language'] = $language;
		}
		if ( null !== $prompt && '' !== $prompt ) {
			$fields['prompt'] = $prompt;
		}

		$body = '';
		foreach ( $fields as $name => $value ) {
			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
			$body .= $value . "\r\n";
		}
		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="file"; filename="audio.wav"' . "\r\n";
		$body .= 'Content-Type: ' . self::INPUT_MIME_TYPE . "\r\n\r\n";
		$body .= $audio . "\r\n";
		$body .= '--' . $boundary . '--' . "\r\n";

		return $body;
	}

	private function unicode_length( string $value ): int {
		if ( 1 !== preg_match( '//u', $value ) ) {
			return PHP_INT_MAX;
		}

		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : (int) preg_match_all( '/./us', $value );
	}
}
