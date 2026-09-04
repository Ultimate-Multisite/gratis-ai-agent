<?php

declare(strict_types=1);

namespace SdAiAgent\REST;

use SdAiAgent\Core\Database;
use SdAiAgent\Core\ProviderCredentialLoader;
use SdAiAgent\Core\ProviderTraceLogger;
use SdAiAgent\Core\SpeechLocaleResolver;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiProvider;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiTextToSpeechConversionModel;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiTranscriptionClient;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\ApiBasedImplementation\Contracts\ApiBasedModelInterface;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\TextToSpeechConversion\Contracts\TextToSpeechConversionModelInterface;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use XWP\DI\Decorators\REST_Handler;
use XWP\DI\Decorators\REST_Route;
use XWP_REST_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authenticated WordPress boundary for managed speech operations.
 */
#[REST_Handler(
	namespace: RestController::NAMESPACE,
	basename: 'speech',
	container: 'sd-ai-agent',
)]
final class SpeechController extends XWP_REST_Controller {

	use PermissionTrait;

	public const UPLOAD_FIELD                   = 'audio';
	public const MAX_SYNTHESIS_CHARACTERS       = 2000;
	public const MAX_SYNTHESIS_RESPONSE_BYTES   = 10 * 1024 * 1024;
	private const MAX_CAPABILITY_RESPONSE_ITEMS = 20;

	private SuperdavAiTranscriptionClient $speech_client;
	private SpeechLocaleResolver $locale_resolver;
	private \Closure $upload_provenance_check;

	public function __construct(
		?SuperdavAiTranscriptionClient $speech_client = null,
		?SpeechLocaleResolver $locale_resolver = null,
		?\Closure $upload_provenance_check = null
	) {
		$this->speech_client           = $speech_client ?? new SuperdavAiTranscriptionClient();
		$this->locale_resolver         = $locale_resolver ?? new SpeechLocaleResolver();
		$this->upload_provenance_check = $upload_provenance_check ?? static fn( string $path ): bool => is_uploaded_file( $path );
	}

	/** Return the current safe service capability surface. */
	#[REST_Route(
		route: 'capabilities',
		methods: WP_REST_Server::READABLE,
		guard: 'check_chat_permission',
	)]
	public function handle_capabilities( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );
		$capabilities = $this->get_sanitized_capabilities();
		if ( $capabilities instanceof WP_Error ) {
			return $capabilities;
		}

		return new WP_REST_Response( $capabilities, 200 );
	}

	/** Transcribe one validated temporary WAV upload. */
	#[REST_Route(
		route: 'transcriptions',
		methods: WP_REST_Server::CREATABLE,
		guard: 'check_chat_permission',
	)]
	public function handle_transcription( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->handle_bounded_transcription(
			$request,
			SuperdavAiTranscriptionClient::MAX_AUDIO_BYTES,
			SuperdavAiTranscriptionClient::MAX_DURATION_SECONDS
		);
	}

	/**
	 * Transcribe one upload under caller-supplied ceilings no broader than the
	 * authenticated service contract.
	 */
	public function handle_bounded_transcription( WP_REST_Request $request, int $max_bytes, int $max_duration_seconds, ?callable $before_transcription = null ): WP_REST_Response|WP_Error {
		$max_bytes            = max( 1, min( SuperdavAiTranscriptionClient::MAX_AUDIO_BYTES, $max_bytes ) );
		$max_duration_seconds = max( 1, min( SuperdavAiTranscriptionClient::MAX_DURATION_SECONDS, $max_duration_seconds ) );
		$temporary_path       = '';
		try {
			$files = $request->get_file_params();
			if ( 1 !== count( $files ) || ! isset( $files[ self::UPLOAD_FIELD ] ) || ! is_array( $files[ self::UPLOAD_FIELD ] ) ) {
				return $this->invalid_audio_error();
			}

			/** @var array<string, mixed> $upload */
			$upload = $files[ self::UPLOAD_FIELD ];
			if ( UPLOAD_ERR_OK !== ( $upload['error'] ?? null )
				|| ! is_string( $upload['tmp_name'] ?? null )
				|| ! is_string( $upload['name'] ?? null )
				|| ! is_string( $upload['type'] ?? null )
			) {
				return $this->invalid_audio_error();
			}

			$temporary_path = $upload['tmp_name'];
			if ( '' === $temporary_path || ! ( $this->upload_provenance_check )( $temporary_path ) || ! is_file( $temporary_path ) ) {
				$temporary_path = '';
				return $this->invalid_audio_error();
			}

			$fields = $request->get_body_params();
			if ( ! is_array( $fields ) || array_diff( array_keys( $fields ), array( 'model', 'language', 'prompt', 'session_id' ) ) ) {
				return $this->invalid_audio_error();
			}

			if ( isset( $fields['model'] ) && ! is_string( $fields['model'] ) ) {
				return $this->invalid_audio_error();
			}
			$model = isset( $fields['model'] ) ? trim( $fields['model'] ) : '';
			if ( '' !== $model && SuperdavAiTranscriptionClient::MODEL_ID !== $model ) {
				return $this->unsupported_error();
			}

			$language = $this->optional_client_locale( $fields['language'] ?? null );
			if ( $language instanceof WP_Error ) {
				return $language;
			}

			$prompt = $this->optional_plain_text( $fields['prompt'] ?? null, SuperdavAiTranscriptionClient::MAX_PROMPT_CHARACTERS );
			if ( $prompt instanceof WP_Error ) {
				return $prompt;
			}

			if ( ! $this->is_safe_upload_filename( $upload['name'] ) || SuperdavAiTranscriptionClient::INPUT_MIME_TYPE !== strtolower( trim( $upload['type'] ) ) ) {
				return $this->invalid_audio_error();
			}

			$file_size = filesize( $temporary_path );
			if ( false === $file_size || $file_size <= 0 ) {
				return $this->invalid_audio_error();
			}
			if ( $file_size > $max_bytes ) {
				return $this->audio_too_large_error();
			}

			$detected_mime = $this->detect_mime_type( $temporary_path );
			if ( '' !== $detected_mime && ! in_array( $detected_mime, array( 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave' ), true ) ) {
				return $this->invalid_audio_error();
			}

			$audio = file_get_contents( $temporary_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads one provenance-checked bounded PHP upload.
			if ( false === $audio || strlen( $audio ) !== $file_size ) {
				return $this->invalid_audio_error();
			}

			$duration = $this->wav_duration_seconds( $audio );
			if ( null === $duration ) {
				return $this->invalid_audio_error();
			}
			if ( $duration > $max_duration_seconds ) {
				return $this->audio_too_long_error();
			}

			$session_id = $this->optional_session_id( $fields['session_id'] ?? null );
			if ( $session_id instanceof WP_Error ) {
				return $session_id;
			}

			$capabilities = $this->get_sanitized_capabilities();
			if ( $capabilities instanceof WP_Error ) {
				return $capabilities;
			}
			if ( $file_size > min( $max_bytes, $capabilities['transcription']['max_bytes'] ) ) {
				return $this->audio_too_large_error();
			}
			if ( $duration > min( $max_duration_seconds, $capabilities['transcription']['max_duration_seconds'] ) ) {
				return $this->audio_too_long_error();
			}
			if ( null !== $before_transcription ) {
				$allowed = $before_transcription( $file_size, $duration );
				if ( true !== $allowed ) {
					return $allowed instanceof WP_Error ? $allowed : $this->speech_unavailable_error();
				}
			}

			ProviderTraceLogger::set_runtime_context( SuperdavAiProvider::PROVIDER_ID, SuperdavAiTranscriptionClient::MODEL_ID, $session_id );
			try {
				$result = $this->speech_client->transcribe( $audio, $language, $prompt );
			} finally {
				ProviderTraceLogger::clear_runtime_context();
				unset( $audio );
			}
			if ( $result instanceof WP_Error ) {
				return $result;
			}

			return new WP_REST_Response( $result, 200 );
		} finally {
			if ( '' !== $temporary_path && is_file( $temporary_path ) ) {
				wp_delete_file( $temporary_path );
			}
		}
	}

	/** Convert one bounded plain-text turn through the bundled AI Client model. */
	#[REST_Route(
		route: 'synthesis',
		methods: WP_REST_Server::CREATABLE,
		guard: 'check_chat_permission',
	)]
	public function handle_synthesis( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->handle_bounded_synthesis( $request, self::MAX_SYNTHESIS_CHARACTERS );
	}

	/** Convert one validated text turn under a stricter caller-supplied ceiling. */
	public function handle_bounded_synthesis( WP_REST_Request $request, int $max_characters ): WP_REST_Response|WP_Error {
		$max_characters = max( 1, min( self::MAX_SYNTHESIS_CHARACTERS, $max_characters ) );
		$fields         = $request->get_json_params();
		if ( ! is_array( $fields ) || array_diff( array_keys( $fields ), array( 'text', 'voice', 'language', 'format', 'mime_type', 'speed', 'session_id' ) ) ) {
			return $this->invalid_synthesis_error();
		}

		$text = $this->required_speakable_text( $fields['text'] ?? null, $max_characters );
		if ( $text instanceof WP_Error ) {
			return $text;
		}

		if ( isset( $fields['voice'] ) && ! is_string( $fields['voice'] ) ) {
			return $this->invalid_synthesis_error();
		}
		$voice = isset( $fields['voice'] ) ? trim( $fields['voice'] ) : SuperdavAiTextToSpeechConversionModel::DEFAULT_VOICE;
		if ( ! in_array( $voice, SuperdavAiTextToSpeechConversionModel::SUPPORTED_VOICES, true ) ) {
			return $this->unsupported_error();
		}

		$language = $this->optional_client_locale( $fields['language'] ?? null );
		if ( $language instanceof WP_Error ) {
			return $language;
		}
		if ( null !== $language && ! in_array( $language, SuperdavAiTextToSpeechConversionModel::SUPPORTED_LANGUAGES, true ) ) {
			return $this->unsupported_error();
		}

		if ( ( isset( $fields['format'] ) && ! is_string( $fields['format'] ) )
			|| ( isset( $fields['mime_type'] ) && ! is_string( $fields['mime_type'] ) )
		) {
			return $this->invalid_synthesis_error();
		}
		$format = isset( $fields['format'] ) ? strtolower( trim( $fields['format'] ) ) : '';
		$mime   = isset( $fields['mime_type'] ) ? strtolower( trim( $fields['mime_type'] ) ) : '';
		if ( '' !== $format && '' !== $mime && ( SuperdavAiTextToSpeechConversionModel::MIME_TYPE_TO_RESPONSE_FORMAT[ $mime ] ?? '' ) !== $format ) {
			return $this->unsupported_error();
		}
		if ( '' === $mime ) {
			$format = '' !== $format ? $format : 'mp3';
			$mime   = array_search( $format, SuperdavAiTextToSpeechConversionModel::MIME_TYPE_TO_RESPONSE_FORMAT, true );
			$mime   = is_string( $mime ) ? $mime : '';
		}
		if ( ! isset( SuperdavAiTextToSpeechConversionModel::MIME_TYPE_TO_RESPONSE_FORMAT[ $mime ] ) ) {
			return $this->unsupported_error();
		}

		$speed = $fields['speed'] ?? 1.0;
		if ( ( ! is_int( $speed ) && ! is_float( $speed ) ) || ! is_finite( (float) $speed ) || $speed < SuperdavAiTextToSpeechConversionModel::MIN_SPEED || $speed > SuperdavAiTextToSpeechConversionModel::MAX_SPEED ) {
			return $this->invalid_synthesis_error();
		}

		$session_id = $this->optional_session_id( $fields['session_id'] ?? null );
		if ( $session_id instanceof WP_Error ) {
			return $session_id;
		}

		$capabilities = $this->get_sanitized_capabilities();
		if ( $capabilities instanceof WP_Error ) {
			return $capabilities;
		}
		$tts       = $capabilities['text_to_speech'];
		$voice_ids = array_column( $tts['voices'], 'id' );
		if ( $this->unicode_length( $text ) > $tts['max_input_characters']
			|| ! in_array( $voice, $voice_ids, true )
			|| ( null !== $language && ! $this->voice_supports_locale( $tts['voices'], $voice, $language ) )
			|| ! in_array( SuperdavAiTextToSpeechConversionModel::MIME_TYPE_TO_RESPONSE_FORMAT[ $mime ], $tts['output_formats'], true )
			|| ! in_array( $mime, $tts['output_mime_types'], true )
			|| $speed < $tts['speed']['minimum']
			|| $speed > $tts['speed']['maximum']
		) {
			return $this->unsupported_error();
		}

		try {
			ProviderCredentialLoader::load();
			$config = new ModelConfig();
			$config->setOutputMimeType( $mime );
			$config->setOutputSpeechVoice( $voice );
			$custom_options = array( 'speed' => (float) $speed );
			if ( null !== $language ) {
				$custom_options['language'] = $language;
			}
			$config->setCustomOptions( $custom_options );

			$model = AiClient::defaultRegistry()->getProviderModel( SuperdavAiProvider::PROVIDER_ID, (string) $tts['model'], $config );
			if ( ! $model instanceof TextToSpeechConversionModelInterface || ! $model instanceof ApiBasedModelInterface ) {
				return $this->speech_unavailable_error();
			}
			$request_options = new RequestOptions();
			$request_options->setTimeout( SuperdavAiTranscriptionClient::REQUEST_TIMEOUT );
			$request_options->setConnectTimeout( 5.0 );
			$request_options->setMaxRedirects( 0 );
			$model->setRequestOptions( $request_options );

			ProviderTraceLogger::set_runtime_context( SuperdavAiProvider::PROVIDER_ID, (string) $tts['model'], $session_id );
			$result = $model->convertTextToSpeechResult( array( new UserMessage( array( new MessagePart( $text ) ) ) ) );
		} catch ( ResponseException ) {
			return $this->malformed_response_error();
		} catch ( \Throwable $e ) {
			return $this->map_synthesis_exception( $e );
		} finally {
			ProviderTraceLogger::clear_runtime_context();
		}

		$candidates = $result->getCandidates();
		$parts      = isset( $candidates[0] ) ? $candidates[0]->getMessage()->getParts() : array();
		$file       = isset( $parts[0] ) ? $parts[0]->getFile() : null;
		if ( ! $file instanceof File || ! $file->isInline() || $file->isRemote() || $file->getMimeType() !== $mime ) {
			return $this->malformed_response_error();
		}

		$audio = $file->getBase64Data();
		if ( ! is_string( $audio ) || '' === $audio ) {
			return $this->malformed_response_error();
		}
		$decoded = base64_decode( $audio, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Validates the SDK inline-audio payload before returning it.
		if ( false === $decoded || '' === $decoded || strlen( $decoded ) > self::MAX_SYNTHESIS_RESPONSE_BYTES ) {
			return $this->malformed_response_error();
		}
		unset( $decoded );

		return new WP_REST_Response(
			array(
				'audio'      => $audio,
				'mime_type'  => $mime,
				'request_id' => $this->sanitize_request_id( $result->getId() ),
			),
			200
		);
	}

	/**
	 * @return array{
	 *     available:true,
	 *     text_to_speech:array{
	 *         model:string,
	 *         output_formats:list<string>,
	 *         output_mime_types:list<string>,
	 *         max_input_characters:int,
	 *         max_response_bytes:int,
	 *         speed:array{minimum:float,maximum:float},
	 *         voices:list<array{id:string,name:string,locales:list<string>}>
	 *     },
	 *     transcription:array{
	 *         model:string,
	 *         accepted_input_mime_types:list<string>,
	 *         max_bytes:int,
	 *         max_duration_seconds:int,
	 *         response_formats:list<string>,
	 *         automatic_language_detection:bool
	 *     },
	 *     locales:array{user_locale:string,site_locale:string,initial_locale:string},
	 *     request_id:string
	 * }|WP_Error
	 */
	private function get_sanitized_capabilities(): array|WP_Error {
		$remote = $this->speech_client->get_capabilities();
		if ( $remote instanceof WP_Error ) {
			return $remote;
		}

		$tts           = isset( $remote['text_to_speech'] ) && is_array( $remote['text_to_speech'] ) ? $remote['text_to_speech'] : array();
		$transcription = isset( $remote['transcription'] ) && is_array( $remote['transcription'] ) ? $remote['transcription'] : array();
		if ( SuperdavAiProvider::TEXT_TO_SPEECH_MODEL_ID !== ( $tts['model'] ?? null )
			|| SuperdavAiTranscriptionClient::MODEL_ID !== ( $transcription['model'] ?? null )
		) {
			return $this->speech_unavailable_error();
		}

		$formats       = $this->sanitize_string_list( $tts['output_formats'] ?? null, array_values( SuperdavAiTextToSpeechConversionModel::MIME_TYPE_TO_RESPONSE_FORMAT ) );
		$mimes         = array_keys(
			array_filter(
				SuperdavAiTextToSpeechConversionModel::MIME_TYPE_TO_RESPONSE_FORMAT,
				static fn( string $format ): bool => in_array( $format, $formats, true )
			)
		);
		$voices        = $this->sanitize_voices( $tts['voices'] ?? null );
		$speed         = isset( $tts['speed'] ) && is_array( $tts['speed'] ) ? $tts['speed'] : array();
		$minimum       = isset( $speed['minimum'] ) && is_numeric( $speed['minimum'] ) ? max( SuperdavAiTextToSpeechConversionModel::MIN_SPEED, (float) $speed['minimum'] ) : NAN;
		$maximum       = isset( $speed['maximum'] ) && is_numeric( $speed['maximum'] ) ? min( SuperdavAiTextToSpeechConversionModel::MAX_SPEED, (float) $speed['maximum'] ) : NAN;
		$maximum_input = isset( $tts['max_input_characters'] ) && is_numeric( $tts['max_input_characters'] )
			? min( self::MAX_SYNTHESIS_CHARACTERS, (int) $tts['max_input_characters'] )
			: 0;

		$accepted_mimes   = $this->sanitize_string_list( $transcription['accepted_input_mime_types'] ?? null, array( SuperdavAiTranscriptionClient::INPUT_MIME_TYPE ) );
		$response_formats = $this->sanitize_string_list( $transcription['response_formats'] ?? null, array( 'json' ) );
		$max_bytes        = isset( $transcription['max_bytes'] ) && is_numeric( $transcription['max_bytes'] )
			? min( SuperdavAiTranscriptionClient::MAX_AUDIO_BYTES, (int) $transcription['max_bytes'] )
			: 0;
		$max_duration     = isset( $transcription['max_duration_seconds'] ) && is_numeric( $transcription['max_duration_seconds'] )
			? min( SuperdavAiTranscriptionClient::MAX_DURATION_SECONDS, (int) $transcription['max_duration_seconds'] )
			: 0;

		if ( array() === $formats || array() === $mimes || array() === $voices || ! is_finite( $minimum ) || ! is_finite( $maximum )
			|| $minimum > $maximum || $maximum_input <= 0 || array() === $accepted_mimes || array() === $response_formats
			|| $max_bytes <= 0 || $max_duration <= 0
		) {
			return $this->speech_unavailable_error();
		}

		return array(
			'available'      => true,
			'text_to_speech' => array(
				'model'                => SuperdavAiProvider::TEXT_TO_SPEECH_MODEL_ID,
				'output_formats'       => $formats,
				'output_mime_types'    => $mimes,
				'max_input_characters' => $maximum_input,
				'max_response_bytes'   => self::MAX_SYNTHESIS_RESPONSE_BYTES,
				'speed'                => array(
					'minimum' => $minimum,
					'maximum' => $maximum,
				),
				'voices'               => $voices,
			),
			'transcription'  => array(
				'model'                        => SuperdavAiTranscriptionClient::MODEL_ID,
				'accepted_input_mime_types'    => $accepted_mimes,
				'max_bytes'                    => $max_bytes,
				'max_duration_seconds'         => $max_duration,
				'response_formats'             => $response_formats,
				'automatic_language_detection' => true === ( $transcription['automatic_language_detection'] ?? false ),
			),
			'locales'        => $this->locale_resolver->resolve(),
			'request_id'     => $this->sanitize_request_id( $remote['request_id'] ?? null ),
		);
	}

	/**
	 * @param mixed $values    Candidate values.
	 * @param array $allowlist Allowed values.
	 * @phpstan-param list<string> $allowlist
	 * @return list<string>
	 */
	private function sanitize_string_list( mixed $values, array $allowlist ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$result = array();
		foreach ( array_slice( $values, 0, self::MAX_CAPABILITY_RESPONSE_ITEMS ) as $value ) {
			if ( is_string( $value ) && in_array( $value, $allowlist, true ) && ! in_array( $value, $result, true ) ) {
				$result[] = $value;
			}
		}

		return $result;
	}

	/** @return list<array{id:string,name:string,locales:list<string>}> */
	private function sanitize_voices( mixed $voices ): array {
		if ( ! is_array( $voices ) ) {
			return array();
		}

		$result = array();
		foreach ( array_slice( $voices, 0, self::MAX_CAPABILITY_RESPONSE_ITEMS ) as $voice ) {
			if ( ! is_array( $voice ) || ! isset( $voice['id'] ) || ! is_string( $voice['id'] )
				|| ! in_array( $voice['id'], SuperdavAiTextToSpeechConversionModel::SUPPORTED_VOICES, true )
			) {
				continue;
			}

			$locales = array();
			if ( isset( $voice['locales'] ) && is_array( $voice['locales'] ) ) {
				foreach ( array_slice( $voice['locales'], 0, self::MAX_CAPABILITY_RESPONSE_ITEMS ) as $locale ) {
					$locale = is_string( $locale ) ? $this->locale_resolver->normalize_client_locale( $locale ) : null;
					if ( null !== $locale && in_array( $locale, SuperdavAiTextToSpeechConversionModel::SUPPORTED_LANGUAGES, true ) ) {
						$locales[] = $locale;
					}
				}
			}
			if ( array() === $locales ) {
				continue;
			}

			$name = isset( $voice['name'] ) && is_string( $voice['name'] ) ? sanitize_text_field( $voice['name'] ) : $voice['id'];
			if ( '' === $name || $this->unicode_length( $name ) > 80 ) {
				$name = $voice['id'];
			}

			$result[] = array(
				'id'      => $voice['id'],
				'name'    => $name,
				'locales' => array_values( array_unique( $locales ) ),
			);
		}

		return $result;
	}

	/** @param list<array{id:string,name:string,locales:list<string>}> $voices */
	private function voice_supports_locale( array $voices, string $voice_id, string $locale ): bool {
		foreach ( $voices as $voice ) {
			if ( $voice_id === $voice['id'] ) {
				return in_array( $locale, $voice['locales'], true );
			}
		}

		return false;
	}

	private function optional_client_locale( mixed $value ): string|null|WP_Error {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( ! is_string( $value ) ) {
			return $this->invalid_language_error();
		}

		$locale = $this->locale_resolver->normalize_client_locale( $value );
		return null !== $locale ? $locale : $this->invalid_language_error();
	}

	private function optional_plain_text( mixed $value, int $maximum ): string|null|WP_Error {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '//u', $value ) ) {
			return $this->invalid_audio_error();
		}

		$value = trim( wp_strip_all_tags( $value ) );
		if ( '' === $value || $this->unicode_length( $value ) > $maximum ) {
			return $this->invalid_audio_error();
		}

		return $value;
	}

	private function required_speakable_text( mixed $value, int $maximum ): string|WP_Error {
		if ( ! is_string( $value ) || 1 !== preg_match( '//u', $value ) || $this->unicode_length( $value ) > $maximum * 4 ) {
			return $this->invalid_synthesis_error();
		}

		$text = html_entity_decode( wp_strip_all_tags( strip_shortcodes( $value ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', trim( $text ) );
		if ( ! is_string( $text ) || '' === $text || 1 !== preg_match( '/[\p{L}\p{N}]/u', $text ) || $this->unicode_length( $text ) > $maximum ) {
			return $this->invalid_synthesis_error();
		}

		return $text;
	}

	private function optional_session_id( mixed $value ): int|WP_Error {
		if ( null === $value || '' === $value || 0 === $value ) {
			return 0;
		}
		if ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) {
			return $this->invalid_synthesis_error();
		}

		$session_id = (int) $value;
		$session    = $session_id > 0 ? Database::get_session( $session_id ) : null;
		if ( ! $session || (int) $session->user_id !== get_current_user_id() ) {
			return new WP_Error( 'rest_forbidden', __( 'You do not have permission to use this chat session.', 'superdav-ai-agent' ), array( 'status' => 403 ) );
		}

		return $session_id;
	}

	private function detect_mime_type( string $path ): string {
		if ( ! class_exists( '\finfo' ) ) {
			return '';
		}

		$finfo = new \finfo( FILEINFO_MIME_TYPE );
		$mime  = $finfo->file( $path );
		return is_string( $mime ) ? strtolower( trim( $mime ) ) : '';
	}

	private function is_safe_upload_filename( string $filename ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $filename )
			&& ! str_contains( $filename, '..' )
			&& ! str_contains( $filename, '/' )
			&& ! str_contains( $filename, '\\' );
	}

	/** Parse the strict PCM WAV subset accepted by the managed service. */
	private function wav_duration_seconds( string $audio ): ?float {
		$length = strlen( $audio );
		if ( $length < 44 || 'RIFF' !== substr( $audio, 0, 4 ) || 'WAVE' !== substr( $audio, 8, 4 ) ) {
			return null;
		}

		$riff_size = $this->little_endian_uint32( $audio, 4 );
		if ( null === $riff_size || $riff_size + 8 !== $length ) {
			return null;
		}

		$offset       = 12;
		$byte_rate    = null;
		$block_align  = null;
		$data_bytes   = 0;
		$found_format = false;
		$allowed      = array( 'fmt ', 'data', 'fact', 'LIST', 'JUNK', 'bext', 'iXML' );
		while ( $offset < $length ) {
			if ( $offset + 8 > $length ) {
				return null;
			}
			$chunk_id   = substr( $audio, $offset, 4 );
			$chunk_size = $this->little_endian_uint32( $audio, $offset + 4 );
			if ( null === $chunk_size || ! in_array( $chunk_id, $allowed, true ) ) {
				return null;
			}
			$chunk_start = $offset + 8;
			$chunk_end   = $chunk_start + $chunk_size;
			$padded_end  = $chunk_end + ( $chunk_size % 2 );
			if ( $chunk_end > $length || $padded_end > $length ) {
				return null;
			}

			if ( 'fmt ' === $chunk_id ) {
				if ( $found_format || $chunk_size < 16 ) {
					return null;
				}
				$audio_format = $this->little_endian_uint16( $audio, $chunk_start );
				$channels     = $this->little_endian_uint16( $audio, $chunk_start + 2 );
				$sample_rate  = $this->little_endian_uint32( $audio, $chunk_start + 4 );
				$byte_rate    = $this->little_endian_uint32( $audio, $chunk_start + 8 );
				$block_align  = $this->little_endian_uint16( $audio, $chunk_start + 12 );
				$sample_bits  = $this->little_endian_uint16( $audio, $chunk_start + 14 );
				if ( 1 !== $audio_format || ! $channels || ! $sample_rate || ! $byte_rate || ! $block_align || ! $sample_bits
					|| 0 !== $sample_bits % 8 || $block_align !== $channels * ( $sample_bits / 8 ) || $byte_rate !== $sample_rate * $block_align
				) {
					return null;
				}
				$found_format = true;
			} elseif ( 'data' === $chunk_id ) {
				if ( ! $block_align || 0 !== $chunk_size % $block_align ) {
					return null;
				}
				$data_bytes += $chunk_size;
			}
			$offset = $padded_end;
		}

		return $found_format && $byte_rate && $data_bytes > 0 && $offset === $length
			? $data_bytes / $byte_rate
			: null;
	}

	private function little_endian_uint16( string $value, int $offset ): ?int {
		$decoded = unpack( 'vvalue', substr( $value, $offset, 2 ) );
		return is_array( $decoded ) && isset( $decoded['value'] ) ? (int) $decoded['value'] : null;
	}

	private function little_endian_uint32( string $value, int $offset ): ?int {
		$decoded = unpack( 'Vvalue', substr( $value, $offset, 4 ) );
		return is_array( $decoded ) && isset( $decoded['value'] ) ? (int) $decoded['value'] : null;
	}

	private function unicode_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : (int) preg_match_all( '/./us', $value );
	}

	private function sanitize_request_id( mixed $request_id ): string {
		return is_string( $request_id ) && 1 === preg_match( '/^[A-Za-z0-9._:-]{1,128}$/', $request_id )
			? $request_id
			: 'speech-' . wp_generate_uuid4();
	}

	private function invalid_audio_error(): WP_Error {
		return new WP_Error( 'sd_ai_agent_invalid_audio', __( 'The audio recording is invalid.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
	}

	private function audio_too_large_error(): WP_Error {
		return new WP_Error( 'sd_ai_agent_audio_too_large', __( 'The audio recording exceeds the supported size limit.', 'superdav-ai-agent' ), array( 'status' => 413 ) );
	}

	private function audio_too_long_error(): WP_Error {
		return new WP_Error( 'sd_ai_agent_audio_too_long', __( 'The audio recording exceeds the supported duration limit.', 'superdav-ai-agent' ), array( 'status' => 413 ) );
	}

	private function invalid_synthesis_error(): WP_Error {
		return new WP_Error( 'sd_ai_agent_invalid_speech_text', __( 'The speech request is invalid.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
	}

	private function invalid_language_error(): WP_Error {
		return new WP_Error( 'sd_ai_agent_invalid_speech_language', __( 'The speech language is invalid.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
	}

	private function unsupported_error(): WP_Error {
		return new WP_Error( 'sd_ai_agent_speech_unsupported', __( 'The requested speech option is not supported.', 'superdav-ai-agent' ), array( 'status' => 422 ) );
	}

	private function speech_unavailable_error(): WP_Error {
		return new WP_Error( 'sd_ai_agent_speech_unavailable', __( 'The speech service is temporarily unavailable.', 'superdav-ai-agent' ), array( 'status' => 503 ) );
	}

	private function malformed_response_error(): WP_Error {
		return new WP_Error( 'sd_ai_agent_speech_malformed_response', __( 'The speech service returned an invalid response.', 'superdav-ai-agent' ), array( 'status' => 502 ) );
	}

	private function map_synthesis_exception( \Throwable $exception ): WP_Error {
		$status = $exception->getCode();
		if ( 429 === $status ) {
			return new WP_Error( 'sd_ai_agent_speech_limit_exceeded', __( 'The speech service limit was reached. Please try again later.', 'superdav-ai-agent' ), array( 'status' => 429 ) );
		}
		if ( in_array( $status, array( 408, 504 ), true ) || $this->is_timeout_exception( $exception ) ) {
			return new WP_Error( 'sd_ai_agent_speech_timeout', __( 'The speech service timed out. Please try again.', 'superdav-ai-agent' ), array( 'status' => 504 ) );
		}

		return $this->speech_unavailable_error();
	}

	/** Detect transport timeout failures without returning exception details. */
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
}
