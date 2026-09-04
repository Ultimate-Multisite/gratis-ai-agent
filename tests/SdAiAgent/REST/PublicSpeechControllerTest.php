<?php

declare(strict_types=1);

namespace SdAiAgent\Tests\REST;

use SdAiAgent\Bootstrap\SuperdavAiProviderHandler;
use SdAiAgent\Core\PublicChatSecurity;
use SdAiAgent\Core\Settings;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiProvider;
use SdAiAgent\REST\PublicSpeechController;
use SdAiAgent\REST\SpeechController;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/** Covers the anonymous speech session, upload, grant, and replay boundaries. */
final class PublicSpeechControllerTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private HttpTransporterInterface $original_transporter;
	private PublicSpeechTransporter $transporter;
	private PublicChatSecurity $security;
	private PublicSpeechController $controller;

	public function set_up(): void {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		parent::set_up();
		if ( ! class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'WordPress AI Client SDK is unavailable.' );
		}

		( new SuperdavAiProviderHandler() )->register_provider();
		$registry                   = AiClient::defaultRegistry();
		$this->original_transporter = $registry->getHttpTransporter();
		$this->transporter          = new PublicSpeechTransporter();
		$registry->setHttpTransporter( $this->transporter );
		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, 'public-speech-test-token', false );

		Settings::instance()->update(
			array(
				'public_chat_enabled'                      => true,
				'public_chat_speech_enabled'               => true,
				'public_chat_speech_max_recording_seconds' => 30,
				'public_chat_speech_max_tts_characters'    => 500,
				'public_chat_allowed_origins'              => array( 'https://docs.example.test' ),
				'public_chat_embed_id'                     => 'docs',
				'public_chat_collection_ids'               => array( 'docs' ),
			)
		);
		$this->security   = new PublicChatSecurity();
		$speech          = new SpeechController( null, null, static fn( string $path ): bool => is_file( $path ) );
		$this->controller = new PublicSpeechController( $this->security, $speech );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		AiClient::defaultRegistry()->setHttpTransporter( $this->original_transporter );
		delete_option( SuperdavAiProvider::CREDENTIAL_OPTION );
		delete_option( Settings::OPTION_NAME );
		parent::tear_down();
	}

	/** Public speech routes are separate from the authenticated speech routes. */
	public function test_registers_focused_public_speech_routes(): void {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/sd-ai-agent/v1/public-chat/speech/transcriptions', $routes );
		$this->assertArrayHasKey( '/sd-ai-agent/v1/public-chat/speech/synthesis', $routes );
	}

	/** An allowlisted visitor can transcribe one strict bounded WAV without attachment persistence. */
	public function test_transcribes_session_bound_recording_without_creating_attachment(): void {
		$token = $this->create_session_token();
		$path  = $this->write_temporary_audio( $this->valid_wav( 0.1 ) );
		$before_attachments = wp_count_attachments()->inherit ?? 0;

		$result = $this->controller->handle_transcription( $this->transcription_request( $token, $path ) );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 'Synthetic public transcript', $result->get_data()['text'] );
		$this->assertSame( 'en-US', $result->get_data()['language'] );
		$this->assertFileDoesNotExist( $path );
		$this->assertSame( 1, $this->transporter->request_count_for( '/audio/transcriptions' ) );
		$this->assertSame( $before_attachments, wp_count_attachments()->inherit ?? 0 );
		$this->assertStringNotContainsString( 'public-speech-test-token', (string) wp_json_encode( $result->get_data() ) );
	}

	/** The public duration ceiling rejects locally before capability or transcription spend. */
	public function test_rejects_recording_over_public_duration_before_upstream_spend(): void {
		Settings::instance()->update( array( 'public_chat_speech_max_recording_seconds' => 1 ) );
		$token  = $this->create_session_token();
		$sid    = $this->session_uuid( $token );
		$path   = $this->write_temporary_audio( $this->valid_wav( 2.0, 8000 ) );
		$result = $this->controller->handle_transcription( $this->transcription_request( $token, $path ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_audio_too_long', $result->get_error_code() );
		$this->assertFileDoesNotExist( $path );
		$this->assertCount( 0, $this->transporter->requests );
		$this->assertFalse( get_transient( 'sd_ai_agent_public_speech_seconds_' . md5( $sid ) ) );
	}

	/** Metrics expose only low-cardinality operational dimensions. */
	public function test_emits_content_free_public_speech_metrics(): void {
		$metrics  = array();
		$listener = static function ( array $metric ) use ( &$metrics ): void {
			$metrics[] = $metric;
		};
		add_action( 'sd_ai_agent_public_speech_metric', $listener );

		try {
			$token  = $this->create_session_token();
			$path   = $this->write_temporary_audio( $this->valid_wav( 0.1 ) );
			$result = $this->controller->handle_transcription( $this->transcription_request( $token, $path ) );
			$this->assertInstanceOf( WP_REST_Response::class, $result );
		} finally {
			remove_action( 'sd_ai_agent_public_speech_metric', $listener );
		}

		$this->assertCount( 1, $metrics );
		$this->assertSame( 'transcription', $metrics[0]['operation'] );
		$this->assertSame( 'success', $metrics[0]['outcome'] );
		$this->assertSame( 'up_to_64kb', $metrics[0]['bytes_bucket'] );
		$this->assertSame( 'up_to_5s', $metrics[0]['duration_bucket'] );
		$encoded = (string) wp_json_encode( $metrics[0] );
		$this->assertStringNotContainsString( 'Synthetic public transcript', $encoded );
		$this->assertStringNotContainsString( 'public-speech-test-token', $encoded );
		$this->assertStringNotContainsString( $token, $encoded );
	}

	/** Origin and embed bindings reject a copied token before audio processing. */
	public function test_rejects_token_from_wrong_origin_or_embed(): void {
		$token = $this->create_session_token();
		$path  = $this->write_temporary_audio( $this->valid_wav( 0.1 ) );

		$request = $this->transcription_request( $token, $path, 'https://other.example.test' );
		$result  = $this->controller->handle_transcription( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_public_chat_origin_forbidden', $result->get_error_code() );
		$this->assertFileExists( $path );
		wp_delete_file( $path );

		$path    = $this->write_temporary_audio( $this->valid_wav( 0.1 ) );
		$request = $this->transcription_request( $token, $path );
		$request->set_body_params(
			array(
				'token'    => $token,
				'embed_id' => 'other',
				'language' => 'en-US',
			)
		);
		$result = $this->controller->handle_transcription( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_public_chat_invalid_token', $result->get_error_code() );
		wp_delete_file( $path );
		$this->assertCount( 0, $this->transporter->requests );
	}

	/** Missing origins and uncontrolled upload shapes fail before audio processing. */
	public function test_rejects_missing_origin_invalid_magic_and_oversize_audio_before_spend(): void {
		$token = $this->create_session_token();
		$path  = $this->write_temporary_audio( $this->valid_wav( 0.1 ) );

		$request = $this->transcription_request( $token, $path, '' );
		$result  = $this->controller->handle_transcription( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_public_chat_origin_forbidden', $result->get_error_code() );
		$this->assertFileExists( $path );
		wp_delete_file( $path );

		$path   = $this->write_temporary_audio( 'not a wav recording' );
		$result = $this->controller->handle_transcription( $this->transcription_request( $token, $path ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_invalid_audio', $result->get_error_code() );
		$this->assertFileDoesNotExist( $path );

		$maximum = (int) $this->security->settings()['speech_max_audio_bytes'];
		$path    = $this->write_temporary_audio( str_repeat( "\0", $maximum + 1 ) );
		$result  = $this->controller->handle_transcription( $this->transcription_request( $token, $path ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_audio_too_large', $result->get_error_code() );
		$this->assertFileDoesNotExist( $path );
		$this->assertCount( 0, $this->transporter->requests );
	}

	/** Anonymous request-rate and concurrency controls reject before upstream spend. */
	public function test_enforces_public_speech_rate_and_concurrency_limits(): void {
		$token = $this->create_session_token();
		$sid   = $this->session_uuid( $token );
		for ( $attempt = 0; $attempt < 4; ++$attempt ) {
			$this->assertTrue( $this->security->check_speech_rate_limit( $sid ) );
		}

		$path   = $this->write_temporary_audio( $this->valid_wav( 0.1 ) );
		$result = $this->controller->handle_transcription( $this->transcription_request( $token, $path ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_public_chat_rate_limited', $result->get_error_code() );
		$this->assertFileExists( $path );
		wp_delete_file( $path );

		$token = $this->create_session_token();
		$sid   = $this->session_uuid( $token );
		$lock  = $this->security->acquire_speech_lock( $sid );
		$this->assertIsArray( $lock );
		try {
			$path   = $this->write_temporary_audio( $this->valid_wav( 0.1 ) );
			$result = $this->controller->handle_transcription( $this->transcription_request( $token, $path ) );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'sd_ai_agent_public_speech_busy', $result->get_error_code() );
			$this->assertFileExists( $path );
			wp_delete_file( $path );
		} finally {
			$this->security->release_speech_lock( $lock );
		}
		$this->assertCount( 0, $this->transporter->requests );
	}

	/** A completed reply grant synthesizes once and cannot become an arbitrary text proxy. */
	public function test_synthesizes_only_granted_assistant_reply_once(): void {
		$token = $this->create_session_token();
		$sid   = $this->session_uuid( $token );
		$grant = $this->security->issue_synthesis_grant( $sid, $token, 'https://docs.example.test', 'Read **this answer**.' );
		$this->assertIsArray( $grant );

		$result = $this->controller->handle_synthesis( $this->synthesis_request( $token, $grant['grant'] ) );
		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( base64_encode( 'public-synthetic-audio' ), $result->get_data()['audio'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Verifies explicit inline audio.
		$this->assertSame( 1, $this->transporter->request_count_for( '/audio/speech' ) );
		$request = $this->transporter->last_request_for( '/audio/speech' );
		$this->assertNotNull( $request );
		$this->assertSame( 'Read this answer.', $request->getData()['input'] ?? null );

		$replay = $this->controller->handle_synthesis( $this->synthesis_request( $token, $grant['grant'] ) );
		$this->assertInstanceOf( WP_Error::class, $replay );
		$this->assertSame( 'sd_ai_agent_public_speech_invalid_grant', $replay->get_error_code() );
		$this->assertSame( 1, $this->transporter->request_count_for( '/audio/speech' ) );

		$arbitrary = $this->synthesis_request( $token, $grant['grant'] );
		$arbitrary->set_body(
			(string) wp_json_encode(
				array(
					'token'    => $token,
					'embed_id' => 'docs',
					'grant'    => $grant['grant'],
					'text'     => 'Convert arbitrary visitor text.',
				)
			)
		);
		$rejected = $this->controller->handle_synthesis( $arbitrary );
		$this->assertInstanceOf( WP_Error::class, $rejected );
		$this->assertSame( 'sd_ai_agent_invalid_speech_text', $rejected->get_error_code() );
	}

	/** Missing/expired grants and exhausted session spend fail before synthesis. */
	public function test_rejects_missing_expired_and_over_budget_synthesis(): void {
		$token   = $this->create_session_token();
		$missing = $this->controller->handle_synthesis( $this->synthesis_request( $token, '' ) );
		$this->assertInstanceOf( WP_Error::class, $missing );
		$this->assertSame( 'sd_ai_agent_invalid_speech_text', $missing->get_error_code() );

		$sid   = $this->session_uuid( $token );
		$grant = $this->security->issue_synthesis_grant( $sid, $token, 'https://docs.example.test', 'An expiring reply.' );
		$this->assertIsArray( $grant );
		$grant_key = $this->synthesis_grant_key( $grant['grant'] );
		$state     = get_transient( $grant_key );
		$this->assertIsArray( $state );
		$state['exp'] = time() - 1;
		set_transient( $grant_key, $state, MINUTE_IN_SECONDS );
		$expired = $this->controller->handle_synthesis( $this->synthesis_request( $token, $grant['grant'] ) );
		$this->assertInstanceOf( WP_Error::class, $expired );
		$this->assertSame( 'sd_ai_agent_public_speech_invalid_grant', $expired->get_error_code() );
		delete_transient( $grant_key );

		$token = $this->create_session_token();
		$sid   = $this->session_uuid( $token );
		$this->assertTrue( $this->security->consume_speech_budget( $sid, 'synthesis', 10000 ) );
		$grant = $this->security->issue_synthesis_grant( $sid, $token, 'https://docs.example.test', 'A reply after the budget is exhausted.' );
		$this->assertIsArray( $grant );
		$limited = $this->controller->handle_synthesis( $this->synthesis_request( $token, $grant['grant'] ) );
		$this->assertInstanceOf( WP_Error::class, $limited );
		$this->assertSame( 'sd_ai_agent_public_speech_limit_exceeded', $limited->get_error_code() );
		$this->assertSame( 0, $this->transporter->request_count_for( '/audio/speech' ) );

		delete_transient( 'sd_ai_agent_public_speech_characters_' . md5( $sid ) );
		delete_transient( 'sd_ai_agent_public_speech_site_characters_' . gmdate( 'Ymd' ) );
	}

	/** Disabling speech invalidates existing session use before managed spend. */
	public function test_speech_disable_is_immediate(): void {
		$token = $this->create_session_token();
		Settings::instance()->update( array( 'public_chat_speech_enabled' => false ) );
		$path   = $this->write_temporary_audio( $this->valid_wav( 0.1 ) );
		$result = $this->controller->handle_transcription( $this->transcription_request( $token, $path ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_public_speech_disabled', $result->get_error_code() );
		$this->assertCount( 0, $this->transporter->requests );
		wp_delete_file( $path );
	}

	private function create_session_token(): string {
		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/public-chat/session' );
		$request->set_header( 'Origin', 'https://docs.example.test' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'embed_id' => 'docs', 'locale' => 'en-US' ) ) );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 201, $response->get_status() );
		return (string) $response->get_data()['token'];
	}

	private function transcription_request( string $token, string $path, string $origin = 'https://docs.example.test' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/public-chat/speech/transcriptions' );
		$request->set_header( 'Origin', $origin );
		$request->set_body_params(
			array(
				'token'    => $token,
				'embed_id' => 'docs',
				'language' => 'en-US',
			)
		);
		$request->set_file_params(
			array(
				SpeechController::UPLOAD_FIELD => array(
					'error'    => UPLOAD_ERR_OK,
					'tmp_name' => $path,
					'name'     => 'recording.wav',
					'type'     => 'audio/wav',
				),
			)
		);
		return $request;
	}

	private function synthesis_request( string $token, string $grant ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/public-chat/speech/synthesis' );
		$request->set_header( 'Origin', 'https://docs.example.test' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'token'    => $token,
					'embed_id' => 'docs',
					'grant'    => $grant,
				)
			)
		);
		return $request;
	}

	private function session_uuid( string $token ): string {
		$parsed = $this->security->parse_token( $token );
		$this->assertIsArray( $parsed );
		return (string) $parsed['sid'];
	}

	/** Resolve the private transient key represented by a signed test grant. */
	private function synthesis_grant_key( string $grant ): string {
		$body    = explode( '.', $grant, 2 )[0];
		$decoded = base64_decode( strtr( $body, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes a signed test fixture.
		$this->assertIsString( $decoded );
		$data = json_decode( $decoded, true );
		$this->assertIsArray( $data );
		$this->assertIsString( $data['gid'] ?? null );

		return 'sd_ai_agent_public_speech_grant_' . md5( $data['gid'] );
	}

	private function write_temporary_audio( string $audio ): string {
		$path = wp_tempnam( 'sd-ai-agent-public-speech-test.wav' );
		$this->assertIsString( $path );
		$this->assertNotFalse( file_put_contents( $path, $audio ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Creates an isolated synthetic fixture.
		return $path;
	}

	/** Return a mono 16-bit PCM WAV fixture. */
	private function valid_wav( float $seconds, int $sample_rate = 16000 ): string {
		$byte_rate = $sample_rate * 2;
		$data      = str_repeat( "\0", (int) ( $byte_rate * $seconds ) );
		$fmt       = pack( 'vvVVvv', 1, 1, $sample_rate, $byte_rate, 2, 16 );
		return 'RIFF' . pack( 'V', 36 + strlen( $data ) ) . 'WAVEfmt ' . pack( 'V', 16 ) . $fmt . 'data' . pack( 'V', strlen( $data ) ) . $data;
	}
}

/** Deterministic managed-service responses for public speech tests. */
final class PublicSpeechTransporter implements HttpTransporterInterface {

	/** @var list<Request> */
	public array $requests = array();

	public function send( Request $request, ?RequestOptions $options = null ): Response {
		unset( $options );
		$this->requests[] = $request;
		$uri              = $request->getUri();
		if ( str_ends_with( $uri, '/audio/capabilities' ) ) {
			return $this->json_response(
				array(
					'text_to_speech' => array(
						'model'                => 'superdav-tts',
						'output_formats'       => array( 'mp3' ),
						'max_input_characters' => 4096,
						'speed'                => array( 'minimum' => 0.25, 'maximum' => 4 ),
						'voices'               => array( array( 'id' => 'alloy', 'name' => 'Alloy', 'locales' => array( 'en-US' ) ) ),
					),
					'transcription'  => array(
						'model'                        => 'superdav-transcribe',
						'accepted_input_mime_types'    => array( 'audio/wav' ),
						'max_bytes'                    => 10 * 1024 * 1024,
						'max_duration_seconds'         => 1500,
						'response_formats'             => array( 'json' ),
						'automatic_language_detection' => true,
					),
				)
			);
		}
		if ( str_ends_with( $uri, '/models' ) ) {
			return $this->json_response(
				array(
					'object' => 'list',
					'data'   => array( array( 'id' => 'superdav-tts', 'name' => 'Speech', 'capabilities' => array( 'text_to_speech_conversion' => true ) ) ),
				)
			);
		}
		if ( str_ends_with( $uri, '/audio/transcriptions' ) ) {
			return $this->json_response(
				array(
					'text'       => 'Synthetic public transcript',
					'language'   => 'en-US',
					'duration'   => 0.1,
					'request_id' => 'public-transcription-request',
				)
			);
		}
		if ( str_ends_with( $uri, '/audio/speech' ) ) {
			return new Response( 200, array( 'content-type' => 'audio/mpeg' ), 'public-synthetic-audio' );
		}

		throw new \RuntimeException( 'Unexpected public speech test request.' );
	}

	public function request_count_for( string $suffix ): int {
		return count( array_filter( $this->requests, static fn( Request $request ): bool => str_ends_with( $request->getUri(), $suffix ) ) );
	}

	public function last_request_for( string $suffix ): ?Request {
		$matches = array_values( array_filter( $this->requests, static fn( Request $request ): bool => str_ends_with( $request->getUri(), $suffix ) ) );
		return array() === $matches ? null : $matches[ count( $matches ) - 1 ];
	}

	/** @param array<string,mixed> $data */
	private function json_response( array $data ): Response {
		return new Response( 200, array( 'content-type' => 'application/json' ), (string) wp_json_encode( $data ) );
	}
}
