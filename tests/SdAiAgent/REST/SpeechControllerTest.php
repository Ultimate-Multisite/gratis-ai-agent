<?php
/**
 * Tests for the authenticated speech REST boundary.
 *
 * @package SdAiAgent\Tests\REST
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\REST;

use SdAiAgent\Bootstrap\SuperdavAiProviderHandler;
use SdAiAgent\Core\RolePermissions;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiProvider;
use SdAiAgent\REST\SpeechController;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/** Covers permission, upload, capability, synthesis, and redaction contracts. */
final class SpeechControllerTest extends WP_UnitTestCase {

	private \WP_REST_Server $server;

	private int $editor_id;
	private int $subscriber_id;
	private HttpTransporterInterface $original_transporter;
	private SpeechControllerTransporter $transporter;
	private SpeechController $controller;

	public function set_up(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress test global.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Standard WordPress REST action.
		do_action( 'rest_api_init' );

		parent::set_up();
		if ( ! class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'WordPress AI Client SDK is unavailable.' );
		}

		( new SuperdavAiProviderHandler() )->register_provider();
		$registry                   = AiClient::defaultRegistry();
		$this->original_transporter = $registry->getHttpTransporter();
		$this->transporter          = new SpeechControllerTransporter();
		$registry->setHttpTransporter( $this->transporter );
		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, 'controller-secret-token', false );

		$this->editor_id     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->controller    = new SpeechController( null, null, static fn( string $path ): bool => is_file( $path ) );
	}

	public function tear_down(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress test global.
		global $wp_rest_server;
		$wp_rest_server = null;
		AiClient::defaultRegistry()->setHttpTransporter( $this->original_transporter );
		delete_option( SuperdavAiProvider::CREDENTIAL_OPTION );
		delete_option( RolePermissions::OPTION_NAME );
		parent::tear_down();
	}

	/** DI registers all private speech routes and executes their permission guard. */
	public function test_registers_guarded_speech_routes(): void {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/sd-ai-agent/v1/speech/capabilities', $routes );
		$this->assertArrayHasKey( '/sd-ai-agent/v1/speech/transcriptions', $routes );
		$this->assertArrayHasKey( '/sd-ai-agent/v1/speech/synthesis', $routes );

		wp_set_current_user( 0 );
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/sd-ai-agent/v1/speech/capabilities' ) );
		$this->assertSame( 401, $response->get_status() );
	}

	/** Logged-out and denied users fail while a configured editor may use speech. */
	public function test_enforces_chat_permissions_for_every_speech_route(): void {
		wp_set_current_user( 0 );
		$logged_out = $this->controller->check_chat_permission();
		$this->assertInstanceOf( WP_Error::class, $logged_out );
		$this->assertSame( 401, $logged_out->get_error_data()['status'] );

		wp_set_current_user( $this->subscriber_id );
		$denied = $this->controller->check_chat_permission();
		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 403, $denied->get_error_data()['status'] );

		wp_set_current_user( $this->editor_id );
		$this->assertTrue( $this->controller->check_chat_permission() );
	}

	/** Capabilities expose only public bounded speech and locale fields. */
	public function test_returns_sanitized_capabilities_and_locale_hints(): void {
		wp_set_current_user( $this->editor_id );
		$response = $this->controller->handle_capabilities( new WP_REST_Request( 'GET', '/sd-ai-agent/v1/speech/capabilities' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( array( 'available' => true, 'reason' => 'available' ), $data['availability'] );
		$this->assertSame( 'superdav-tts', $data['text_to_speech']['model'] );
		$this->assertSame( SpeechController::MAX_SYNTHESIS_CHARACTERS, $data['text_to_speech']['max_input_characters'] );
		$this->assertSame( 'superdav-transcribe', $data['transcription']['model'] );
		$this->assertSame( array( 'audio/wav' ), $data['transcription']['accepted_input_mime_types'] );
		$this->assertArrayHasKey( 'user_locale', $data['locales'] );
		$this->assertArrayNotHasKey( 'upstream_private', $data );
		$serialized = (string) wp_json_encode( $data );
		$this->assertStringNotContainsString( 'controller-secret-token', $serialized );
		$this->assertStringNotContainsString( 'discard-me', $serialized );
	}

	/** A valid synthetic WAV is forwarded once and its temporary file is removed. */
	public function test_transcribes_valid_wav_and_deletes_temporary_file(): void {
		wp_set_current_user( $this->editor_id );
		$path = $this->write_temporary_audio( $this->valid_wav() );
		$request = $this->transcription_request( $path, array( 'language' => 'en-US', 'prompt' => 'Meeting notes' ) );

		$response = $this->controller->handle_transcription( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame(
			array(
				'text'        => 'Synthetic transcript',
				'request_id'  => 'transcription-request',
				'language'    => 'en-US',
				'duration_ms' => 100,
			),
			$response->get_data()
		);
		$this->assertFileDoesNotExist( $path );
		$this->assertSame( 1, $this->transporter->request_count_for( '/audio/transcriptions' ) );
		$service_request = $this->transporter->last_request_for( '/audio/transcriptions' );
		$this->assertNotNull( $service_request );
		$this->assertStringNotContainsString( 'browser-recording.wav', (string) $service_request->getBody() );
		$serialized = (string) wp_json_encode( $response->get_data() );
		$this->assertStringNotContainsString( $path, $serialized );
		$this->assertStringNotContainsString( 'controller-secret-token', $serialized );
	}

	/** Bad WAV signatures fail locally, delete the upload, and make no service call. */
	public function test_rejects_bad_magic_bytes_before_network_access(): void {
		wp_set_current_user( $this->editor_id );
		$path    = $this->write_temporary_audio( str_repeat( 'x', 64 ) );
		$request = $this->transcription_request( $path );

		$result = $this->controller->handle_transcription( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_invalid_audio', $result->get_error_code() );
		$this->assertFileDoesNotExist( $path );
		$this->assertCount( 0, $this->transporter->requests );
	}

	/** Extra or non-scalar transcription fields fail before any upstream request. */
	public function test_rejects_uncontrolled_transcription_fields_and_releases_upload(): void {
		wp_set_current_user( $this->editor_id );
		$path    = $this->write_temporary_audio( $this->valid_wav() );
		$request = $this->transcription_request( $path, array( 'extra' => 'not-forwarded' ) );

		$result = $this->controller->handle_transcription( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_invalid_audio', $result->get_error_code() );
		$this->assertFileDoesNotExist( $path );
		$this->assertCount( 0, $this->transporter->requests );
	}

	/** Bounded plain text resolves the bundled SDK model and returns inline audio. */
	public function test_synthesizes_text_through_ai_client_model(): void {
		wp_set_current_user( $this->editor_id );
		$request = $this->synthesis_request(
			array(
				'text'      => '<p>Hello <strong>world</strong>.</p>',
				'voice'     => 'alloy',
				'language'  => 'en-US',
				'mime_type' => 'audio/wav',
				'speed'     => 1.25,
			)
		);

		$response = $this->controller->handle_synthesis( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( base64_encode( 'synthetic-audio' ), $data['audio'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Verifies the explicit inline audio response contract.
		$this->assertSame( 'audio/wav', $data['mime_type'] );
		$this->assertStringStartsWith( 'superdav-tts-', $data['request_id'] );
		$this->assertSame( 1, $this->transporter->request_count_for( '/audio/speech' ) );
		$speech_request = $this->transporter->last_request_for( '/audio/speech' );
		$this->assertNotNull( $speech_request );
		$this->assertSame( 'Hello world.', $speech_request->getData()['input'] ?? null );
		$this->assertSame( 'Bearer controller-secret-token', $speech_request->getHeaderAsString( 'authorization' ) );
		$this->assertSame( 35.0, $speech_request->getOptions()?->getTimeout() );
		$this->assertSame( 5.0, $speech_request->getOptions()?->getConnectTimeout() );
		$this->assertSame( 0, $speech_request->getOptions()?->getMaxRedirects() );
	}

	/** Invalid synthesis fields fail before service capability discovery. */
	public function test_rejects_invalid_synthesis_before_network_access(): void {
		wp_set_current_user( $this->editor_id );
		$result = $this->controller->handle_synthesis( $this->synthesis_request( array( 'text' => 'Hello', 'voice' => array( 'alloy' ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_invalid_speech_text', $result->get_error_code() );
		$this->assertCount( 0, $this->transporter->requests );
	}

	/** Malformed SDK audio output is scrubbed behind the stable response error. */
	public function test_rejects_malformed_synthesis_response_without_raw_body(): void {
		wp_set_current_user( $this->editor_id );
		$this->transporter->speech_response = new Response( 200, array( 'content-type' => 'text/plain' ), 'private malformed body' );

		$result = $this->controller->handle_synthesis( $this->synthesis_request( array( 'text' => 'Hello' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_speech_malformed_response', $result->get_error_code() );
		$this->assertStringNotContainsString( 'private malformed body', $result->get_error_message() );
	}

	/** @param array<string, mixed> $fields Request body fields. */
	private function transcription_request( string $path, array $fields = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/speech/transcriptions' );
		$request->set_body_params( $fields );
		$request->set_file_params(
			array(
				SpeechController::UPLOAD_FIELD => array(
					'error'    => UPLOAD_ERR_OK,
					'tmp_name' => $path,
					'name'     => 'browser-recording.wav',
					'type'     => 'audio/wav',
				),
			)
		);

		return $request;
	}

	/** @param array<string, mixed> $fields JSON request fields. */
	private function synthesis_request( array $fields ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/speech/synthesis' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $fields ) );
		return $request;
	}

	private function write_temporary_audio( string $audio ): string {
		$path = wp_tempnam( 'sd-ai-agent-speech-test.wav' );
		$this->assertIsString( $path );
		$this->assertNotFalse( file_put_contents( $path, $audio ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Creates an isolated synthetic upload fixture.
		return $path;
	}

	/** Return a 100ms mono 16-bit PCM WAV fixture. */
	private function valid_wav(): string {
		$data = str_repeat( "\0", 3200 );
		$fmt  = pack( 'vvVVvv', 1, 1, 16000, 32000, 2, 16 );
		return 'RIFF' . pack( 'V', 36 + strlen( $data ) ) . 'WAVEfmt ' . pack( 'V', 16 ) . $fmt . 'data' . pack( 'V', strlen( $data ) ) . $data;
	}
}

/** Routes deterministic service responses by the managed request path. */
final class SpeechControllerTransporter implements HttpTransporterInterface {

	/** @var list<Request> */
	public array $requests = array();

	public Response $speech_response;

	public function __construct() {
		$this->speech_response = new Response( 200, array( 'content-type' => 'audio/wav' ), 'synthetic-audio' );
	}

	public function send( Request $request, ?RequestOptions $options = null ): Response {
		$this->requests[] = $request;
		$uri              = $request->getUri();
		if ( str_ends_with( $uri, '/audio/capabilities' ) ) {
			return $this->json_response( $this->capabilities() );
		}
		if ( str_ends_with( $uri, '/models' ) ) {
			return $this->json_response(
				array(
					'object' => 'list',
					'data'   => array(
						array(
							'id'           => 'superdav-tts',
							'name'         => 'Superdav Speech',
							'capabilities' => array( 'text_to_speech_conversion' => true ),
						),
					),
				)
			);
		}
		if ( str_ends_with( $uri, '/audio/transcriptions' ) ) {
			return $this->json_response(
				array(
					'text'       => 'Synthetic transcript',
					'language'   => 'en-US',
					'duration'   => 0.1,
					'request_id' => 'transcription-request',
				)
			);
		}
		if ( str_ends_with( $uri, '/audio/speech' ) ) {
			return $this->speech_response;
		}

		throw new \RuntimeException( 'Unexpected test request.' );
	}

	public function request_count_for( string $suffix ): int {
		return count( array_filter( $this->requests, static fn( Request $request ): bool => str_ends_with( $request->getUri(), $suffix ) ) );
	}

	public function last_request_for( string $suffix ): ?Request {
		$matches = array_values( array_filter( $this->requests, static fn( Request $request ): bool => str_ends_with( $request->getUri(), $suffix ) ) );
		return array() === $matches ? null : $matches[ count( $matches ) - 1 ];
	}

	/** @return array<string, mixed> */
	private function capabilities(): array {
		return array(
			'text_to_speech' => array(
				'model'                => 'superdav-tts',
				'output_formats'       => array( 'mp3', 'opus', 'aac', 'flac', 'wav', 'pcm', 'unsafe' ),
				'max_input_characters' => 4096,
				'speed'                => array( 'minimum' => 0.25, 'maximum' => 4 ),
				'voices'               => array(
					array( 'id' => 'alloy', 'name' => 'Alloy', 'locales' => array( 'en-US', '../unsafe' ) ),
					array( 'id' => 'unsafe-voice', 'name' => 'Discard me', 'locales' => array( 'en-US' ) ),
				),
			),
			'transcription'  => array(
				'model'                        => 'superdav-transcribe',
				'accepted_input_mime_types'    => array( 'audio/wav', 'application/octet-stream' ),
				'max_bytes'                    => 10 * 1024 * 1024,
				'max_duration_seconds'         => 1500,
				'response_formats'             => array( 'json', 'verbose_json' ),
				'automatic_language_detection' => true,
			),
			'upstream_private' => 'discard-me',
			'request_id'       => 'capabilities-request',
		);
	}

	/** @param array<string, mixed> $data Response body. */
	private function json_response( array $data ): Response {
		return new Response( 200, array( 'content-type' => 'application/json' ), (string) wp_json_encode( $data ) );
	}
}
