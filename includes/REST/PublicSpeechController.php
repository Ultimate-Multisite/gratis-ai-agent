<?php

declare(strict_types=1);

namespace SdAiAgent\REST;

use SdAiAgent\Core\PublicChatSecurity;
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
 * Anonymous speech boundary tied to an active public-chat session.
 */
#[REST_Handler(
	namespace: RestController::NAMESPACE,
	basename: 'public-chat/speech',
	container: 'sd-ai-agent',
)]
final class PublicSpeechController extends XWP_REST_Controller {

	private PublicChatSecurity $security;
	private SpeechController $speech;

	public function __construct( ?PublicChatSecurity $security = null, ?SpeechController $speech = null ) {
		$this->security = $security ?? new PublicChatSecurity();
		$this->speech   = $speech ?? new SpeechController();

		add_filter( 'rest_post_dispatch', array( $this, 'add_cors_to_speech_response' ), 10, 3 );
	}

	/** Public routes perform their complete authorization inside each handler. */
	public function allow_public_request(): bool {
		return true;
	}

	/** Transcribe one strict WAV upload for an active public-chat session. */
	#[REST_Route(
		route: 'transcriptions',
		methods: WP_REST_Server::CREATABLE,
		guard: 'allow_public_request',
	)]
	public function handle_transcription( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$started_at       = microtime( true );
		$audio_bytes      = 0;
		$duration_seconds = 0.0;
		$result           = $this->process_transcription( $request, $audio_bytes, $duration_seconds );
		$this->record_metric( 'transcription', $result, $started_at, $audio_bytes, $duration_seconds );

		return $result;
	}

	/** Process one public transcription after the telemetry timer starts. */
	private function process_transcription( WP_REST_Request $request, int &$audio_bytes, float &$duration_seconds ): WP_REST_Response|WP_Error {
		$fields = $request->get_body_params();
		if ( ! is_array( $fields ) || array_diff( array_keys( $fields ), array( 'token', 'embed_id', 'language' ) ) ) {
			return $this->invalid_audio_error();
		}

		$token    = $this->required_string( $fields['token'] ?? null );
		$embed_id = sanitize_key( $this->required_string( $fields['embed_id'] ?? null ) );
		if ( '' === $token || '' === $embed_id ) {
			return $this->invalid_audio_error();
		}

		$context = $this->security->authorize_session( $request, $token, $embed_id, true );
		if ( $context instanceof WP_Error ) {
			return $context;
		}

		$files = $request->get_file_params();
		if ( 1 !== count( $files ) || ! isset( $files[ SpeechController::UPLOAD_FIELD ] ) || ! is_array( $files[ SpeechController::UPLOAD_FIELD ] ) ) {
			return $this->invalid_audio_error();
		}
		$upload = $files[ SpeechController::UPLOAD_FIELD ];
		if ( 'recording.wav' !== ( $upload['name'] ?? null ) || 'audio/wav' !== strtolower( (string) ( $upload['type'] ?? '' ) ) ) {
			return $this->invalid_audio_error();
		}

		$language = $this->security->normalize_locale( $fields['language'] ?? null );
		if ( $language instanceof WP_Error ) {
			return $language;
		}

		$rate = $this->security->check_speech_rate_limit( $context['session_uuid'] );
		if ( true !== $rate ) {
			return $rate;
		}
		$lock = $this->security->acquire_speech_lock( $context['session_uuid'] );
		if ( $lock instanceof WP_Error ) {
			return $lock;
		}

		$config = $this->security->settings();
		try {
			$bounded_request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/speech/transcriptions' );
			$bounded_request->set_body_params( null === $language ? array() : array( 'language' => $language ) );
			$bounded_request->set_file_params(
				array(
					SpeechController::UPLOAD_FIELD => array(
						'error'    => $upload['error'] ?? null,
						'tmp_name' => $upload['tmp_name'] ?? null,
						'name'     => 'recording.wav',
						'type'     => 'audio/wav',
					),
				)
			);
			$result = $this->speech->handle_bounded_transcription(
				$bounded_request,
				(int) $config['speech_max_audio_bytes'],
				(int) $config['speech_max_recording_seconds'],
				function ( int $file_size, float $duration ) use ( $context, &$audio_bytes, &$duration_seconds ): true|WP_Error {
					$audio_bytes      = $file_size;
					$duration_seconds = $duration;

					return $this->security->consume_speech_budget(
						$context['session_uuid'],
						'transcription',
						max( 1, (int) ceil( $duration ) )
					);
				}
			);
			if ( $result instanceof WP_Error ) {
				return $result;
			}

			$data = $result->get_data();
			if ( is_array( $data ) && isset( $data['language'] ) ) {
				$this->security->update_session_language( $context['session_uuid'], $data['language'] );
			}

			return $this->security->add_cors( $result, $context['origin'], $config['origins'] );
		} finally {
			$this->security->release_speech_lock( $lock );
		}
	}

	/** Synthesize only the exact completed assistant text represented by a grant. */
	#[REST_Route(
		route: 'synthesis',
		methods: WP_REST_Server::CREATABLE,
		guard: 'allow_public_request',
	)]
	public function handle_synthesis( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$started_at = microtime( true );
		$result     = $this->process_synthesis( $request );
		$this->record_metric( 'synthesis', $result, $started_at );

		return $result;
	}

	/** Add allowlisted CORS headers after WordPress converts public speech errors to responses. */
	public function add_cors_to_speech_response( WP_REST_Response $response, WP_REST_Server $server, WP_REST_Request $request ): WP_REST_Response {
		if ( ! in_array( $request->get_route(), array( '/sd-ai-agent/v1/public-chat/speech/transcriptions', '/sd-ai-agent/v1/public-chat/speech/synthesis' ), true ) ) {
			return $response;
		}

		$config = $this->security->settings();

		return $this->security->add_cors( $response, $this->security->request_origin( $request ), $config['origins'] );
	}

	/** Process one public synthesis after the telemetry timer starts. */
	private function process_synthesis( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$fields = $request->get_json_params();
		if ( ! is_array( $fields ) || array_diff( array_keys( $fields ), array( 'token', 'embed_id', 'grant' ) ) ) {
			return $this->invalid_synthesis_error();
		}

		$token    = $this->required_string( $fields['token'] ?? null );
		$embed_id = sanitize_key( $this->required_string( $fields['embed_id'] ?? null ) );
		$grant    = $this->required_string( $fields['grant'] ?? null );
		if ( '' === $token || '' === $embed_id || '' === $grant ) {
			return $this->invalid_synthesis_error();
		}

		$context = $this->security->authorize_session( $request, $token, $embed_id, true );
		if ( $context instanceof WP_Error ) {
			return $context;
		}
		$rate = $this->security->check_speech_rate_limit( $context['session_uuid'] );
		if ( true !== $rate ) {
			return $rate;
		}
		$lock = $this->security->acquire_speech_lock( $context['session_uuid'] );
		if ( $lock instanceof WP_Error ) {
			return $lock;
		}

		$config = $this->security->settings();
		try {
			$granted = $this->security->consume_synthesis_grant(
				$grant,
				$context['session_uuid'],
				$context['token_hash'],
				$context['origin'],
				$embed_id
			);
			if ( $granted instanceof WP_Error ) {
				return $granted;
			}

			$budget = $this->security->consume_speech_budget( $context['session_uuid'], 'synthesis', $this->unicode_length( $granted['text'] ) );
			if ( true !== $budget ) {
				return $budget;
			}

			$payload = array(
				'text'      => $granted['text'],
				'voice'     => $granted['voice'],
				'mime_type' => 'audio/mpeg',
				'speed'     => 1.0,
			);
			if ( '' !== $granted['language'] ) {
				$payload['language'] = $granted['language'];
			}
			$bounded_request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/speech/synthesis' );
			$bounded_request->set_header( 'content-type', 'application/json' );
			$bounded_request->set_body( (string) wp_json_encode( $payload ) );
			$result = $this->speech->handle_bounded_synthesis( $bounded_request, (int) $config['speech_max_tts_characters'] );
			if ( $result instanceof WP_Error ) {
				return $result;
			}

			return $this->security->add_cors( $result, $context['origin'], $config['origins'] );
		} finally {
			$this->security->release_speech_lock( $lock );
		}
	}

	private function record_metric( string $operation, WP_REST_Response|WP_Error $result, float $started_at, int $audio_bytes = 0, float $duration_seconds = 0.0 ): void {
		$error_data  = $result instanceof WP_Error ? $result->get_error_data() : null;
		$status_code = $result instanceof WP_REST_Response
			? $result->get_status()
			: ( is_array( $error_data ) ? (int) ( $error_data['status'] ?? 500 ) : 500 );
		$outcome     = $this->metric_outcome( $result, $status_code );
		$this->security->record_speech_metric( $operation, $outcome, $status_code, $started_at, $audio_bytes, $duration_seconds );
	}

	private function metric_outcome( WP_REST_Response|WP_Error $result, int $status_code ): string {
		if ( $result instanceof WP_REST_Response ) {
			return $status_code >= 200 && $status_code < 300 ? 'success' : 'failed';
		}

		$code = $result->get_error_code();
		if ( in_array( $code, array( 'sd_ai_agent_public_chat_origin_forbidden', 'sd_ai_agent_public_chat_invalid_token', 'sd_ai_agent_public_chat_session_expired', 'sd_ai_agent_public_speech_invalid_grant' ), true ) ) {
			return 'permission_denied';
		}
		if ( in_array( $code, array( 'sd_ai_agent_public_chat_rate_limited', 'sd_ai_agent_public_speech_busy', 'sd_ai_agent_public_speech_limit_exceeded', 'sd_ai_agent_audio_too_large', 'sd_ai_agent_audio_too_long' ), true ) ) {
			return 'limit_denied';
		}
		if ( in_array( $code, array( 'sd_ai_agent_public_chat_disabled', 'sd_ai_agent_public_chat_unconfigured', 'sd_ai_agent_public_speech_disabled', 'sd_ai_agent_speech_unavailable', 'sd_ai_agent_speech_unsupported' ), true ) ) {
			return 'unavailable';
		}

		return $status_code >= 400 && $status_code < 500 ? 'validation_failed' : 'failed';
	}

	private function required_string( mixed $value ): string {
		return is_string( $value ) ? trim( $value ) : '';
	}

	private function unicode_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : (int) preg_match_all( '/./us', $value );
	}

	private function invalid_audio_error(): WP_Error {
		return new WP_Error( 'sd_ai_agent_invalid_audio', __( 'The audio recording is invalid.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
	}

	private function invalid_synthesis_error(): WP_Error {
		return new WP_Error( 'sd_ai_agent_invalid_speech_text', __( 'The speech request is invalid.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
	}
}
