<?php
/**
 * Tests for the explicit managed transcription client.
 *
 * @package SdAiAgent\Tests\Infrastructure\AiClient\Superdav
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Infrastructure\AiClient\Superdav;

use SdAiAgent\Bootstrap\SuperdavAiProviderHandler;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiProvider;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiTranscriptionClient;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WP_Error;
use WP_UnitTestCase;

/** Covers bounded requests, response normalization, and scrubbed failure mapping. */
final class SuperdavAiTranscriptionClientTest extends WP_UnitTestCase {

	private HttpTransporterInterface $original_transporter;

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'WordPress AI Client SDK is unavailable.' );
		}

		( new SuperdavAiProviderHandler() )->register_provider();
		$registry                   = AiClient::defaultRegistry();
		$this->original_transporter = $registry->getHttpTransporter();
		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, 'test-speech-token', false );
	}

	public function tear_down(): void {
		AiClient::defaultRegistry()->setHttpTransporter( $this->original_transporter );
		delete_option( SuperdavAiProvider::CREDENTIAL_OPTION );
		parent::tear_down();
	}

	/** Capabilities use current SDK auth/options and return only decoded data. */
	public function test_fetches_authenticated_capabilities(): void {
		$transporter = $this->use_outcomes(
			array(
				$this->json_response( array( 'text_to_speech' => array( 'model' => 'superdav-tts' ) ) ),
			)
		);

		$result = ( new SuperdavAiTranscriptionClient() )->get_capabilities();

		$this->assertIsArray( $result );
		$this->assertSame( 'superdav-tts', $result['text_to_speech']['model'] );
		$this->assertCount( 1, $transporter->requests );
		$request = $transporter->requests[0];
		$this->assertSame( 'https://api.sdaiagent.com/v1/audio/capabilities', $request->getUri() );
		$this->assertSame( 'Bearer test-speech-token', $request->getHeaderAsString( 'authorization' ) );
		$this->assertSame( 35.0, $request->getOptions()?->getTimeout() );
		$this->assertSame( 5.0, $request->getOptions()?->getConnectTimeout() );
		$this->assertSame( 0, $request->getOptions()?->getMaxRedirects() );
	}

	/** One controlled multipart request returns normalized public transcript fields. */
	public function test_transcribes_bounded_audio_once(): void {
		$transporter = $this->use_outcomes(
			array(
				$this->json_response(
					array(
						'text'       => 'Olá mundo',
						'language'   => 'PT-br',
						'duration'   => 1.25,
						'request_id' => 'request-123',
						'internal'   => 'discard-me',
					)
				),
			)
		);

		$result = ( new SuperdavAiTranscriptionClient() )->transcribe( 'RIFF-controlled-audio', 'PT-br', 'Meeting notes' );

		$this->assertSame(
			array(
				'text'        => 'Olá mundo',
				'request_id'  => 'request-123',
				'language'    => 'pt-BR',
				'duration_ms' => 1250,
			),
			$result
		);
		$this->assertCount( 1, $transporter->requests );
		$request = $transporter->requests[0];
		$this->assertSame( 'https://api.sdaiagent.com/v1/audio/transcriptions', $request->getUri() );
		$this->assertSame( 'Bearer test-speech-token', $request->getHeaderAsString( 'authorization' ) );
		$this->assertNotSame( '', $request->getHeaderAsString( 'idempotency-key' ) );
		$body = $request->getBody();
		$this->assertIsString( $body );
		$this->assertStringContainsString( 'name="model"', $body );
		$this->assertStringContainsString( SuperdavAiTranscriptionClient::MODEL_ID, $body );
		$this->assertStringContainsString( 'name="file"; filename="audio.wav"', $body );
		$this->assertStringContainsString( 'Content-Type: audio/wav', $body );
		$this->assertStringContainsString( 'RIFF-controlled-audio', $body );
	}

	/** Oversized input fails before the transport can receive audio. */
	public function test_rejects_oversized_audio_before_transport(): void {
		$transporter = $this->use_outcomes( array() );

		$result = ( new SuperdavAiTranscriptionClient() )->transcribe( str_repeat( 'a', SuperdavAiTranscriptionClient::MAX_AUDIO_BYTES + 1 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_audio_too_large', $result->get_error_code() );
		$this->assertCount( 0, $transporter->requests );
	}

	/** Wrong content types and upstream rate limits become stable scrubbed errors. */
	public function test_maps_malformed_and_rate_limited_responses(): void {
		$this->use_outcomes( array( new Response( 200, array( 'content-type' => 'text/plain' ), '{"secret":"hidden"}' ) ) );
		$malformed = ( new SuperdavAiTranscriptionClient() )->get_capabilities();
		$this->assertInstanceOf( WP_Error::class, $malformed );
		$this->assertSame( 'sd_ai_agent_speech_malformed_response', $malformed->get_error_code() );
		$this->assertStringNotContainsString( 'hidden', $malformed->get_error_message() );

		$this->use_outcomes( array( new Response( 429, array( 'content-type' => 'application/json' ), '{"error":"secret detail"}' ) ) );
		$limited = ( new SuperdavAiTranscriptionClient() )->get_capabilities();
		$this->assertInstanceOf( WP_Error::class, $limited );
		$this->assertSame( 'sd_ai_agent_speech_limit_exceeded', $limited->get_error_code() );
		$this->assertStringNotContainsString( 'secret detail', $limited->get_error_message() );
	}

	/** Transport timeout detail is reduced to the public timeout contract. */
	public function test_maps_transport_timeout_without_leaking_detail(): void {
		$this->use_outcomes( array( new \RuntimeException( 'Connection timed out with private host detail' ) ) );

		$result = ( new SuperdavAiTranscriptionClient() )->get_capabilities();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_speech_timeout', $result->get_error_code() );
		$this->assertStringNotContainsString( 'private host detail', $result->get_error_message() );
	}

	/** @param list<Response|\Throwable> $outcomes Queued transport outcomes. */
	private function use_outcomes( array $outcomes ): QueuedTranscriptionTransporter {
		$transporter = new QueuedTranscriptionTransporter( $outcomes );
		AiClient::defaultRegistry()->setHttpTransporter( $transporter );
		return $transporter;
	}

	/** @param array<string, mixed> $data JSON response data. */
	private function json_response( array $data ): Response {
		return new Response( 200, array( 'content-type' => 'application/json; charset=utf-8' ), (string) wp_json_encode( $data ) );
	}
}

/** Deterministic SDK transport used to inspect requests without network access. */
final class QueuedTranscriptionTransporter implements HttpTransporterInterface {

	/** @var list<Request> */
	public array $requests = array();

	/** @param list<Response|\Throwable> $outcomes Queued outcomes. */
	public function __construct( private array $outcomes ) {}

	public function send( Request $request, ?RequestOptions $options = null ): Response {
		$this->requests[] = $request;
		$outcome          = array_shift( $this->outcomes );
		if ( $outcome instanceof \Throwable ) {
			throw $outcome;
		}
		if ( ! $outcome instanceof Response ) {
			throw new \RuntimeException( 'No test response configured.' );
		}

		return $outcome;
	}
}
