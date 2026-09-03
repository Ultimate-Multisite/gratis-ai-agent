<?php
/**
 * Tests for the managed Superdav text-to-speech model.
 *
 * @package SdAiAgent\Tests\Infrastructure\AiClient\Superdav
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Infrastructure\AiClient\Superdav;

use SdAiAgent\Core\ModelCapabilityRegistry;
use SdAiAgent\Core\ProviderTraceLogger;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiImageGenerationModel;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiModelMetadataDirectory;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiProvider;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiTextGenerationModel;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiTextToSpeechConversionModel;
use WP_UnitTestCase;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Builders\PromptBuilder;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * Covers metadata, request validation, forwarding, and binary result mapping.
 */
final class SuperdavAiTextToSpeechConversionModelTest extends WP_UnitTestCase {

	/** Clean up provider runtime state. */
	public function tear_down(): void {
		ProviderTraceLogger::clear_runtime_context();
		ModelCapabilityRegistry::forget( 'superdav-tts' );
		ModelCapabilityRegistry::forget( 'superdav-transcribe' );
		ModelCapabilityRegistry::forget( 'superdav-transcribe-flag' );
		ModelCapabilityRegistry::forget( 'legacy-chat' );
		$this->restore_provider_dependencies();
		parent::tear_down();
	}

	/** TTS metadata is usable while unsupported-only transcription stays out of the SDK directory. */
	public function test_metadata_exposes_tts_and_skips_unsupported_transcription(): void {
		$models = $this->parse_models(
			array(
				array(
					'id'           => 'superdav-tts',
					'name'         => 'Superdav Speech',
					'capabilities' => array(
						'text_generation'          => false,
						'text_to_speech_conversion' => true,
						'audio_transcription'       => false,
					),
				),
				array(
					'id'           => 'superdav-transcribe',
					'capabilities' => array( 'audio_transcription' => true ),
				),
				array(
					'id'                           => 'superdav-transcribe-flag',
					'supports_audio_transcription' => true,
				),
				array(
					'id'   => 'legacy-chat',
					'name' => 'Legacy Chat',
				),
			)
		);

		$this->assertSame( array( 'superdav-tts', 'legacy-chat' ), array_map( static fn( ModelMetadata $model ): string => $model->getId(), $models ) );
		$tts = $models[0];
		$this->assertContainsEquals( CapabilityEnum::textToSpeechConversion(), $tts->getSupportedCapabilities() );
		$this->assertContainsEquals( CapabilityEnum::textGeneration(), $models[1]->getSupportedCapabilities() );

		$options = array();
		foreach ( $tts->getSupportedOptions() as $option ) {
			$options[ $option->getName()->value ] = $option;
		}
		$this->assertTrue( $options['inputModalities']->isSupportedValue( array( ModalityEnum::text() ) ) );
		$this->assertTrue( $options['outputModalities']->isSupportedValue( array( ModalityEnum::audio() ) ) );
		$this->assertTrue( $options['outputFileType']->isSupportedValue( FileTypeEnum::inline() ) );
		$this->assertSame( array_keys( SuperdavAiTextToSpeechConversionModel::MIME_TYPE_TO_RESPONSE_FORMAT ), $options['outputMimeType']->getSupportedValues() );
		$this->assertSame( array( 'alloy' ), $options['outputSpeechVoice']->getSupportedValues() );
		$this->assertArrayHasKey( 'customOptions', $options );
	}

	/** Provider dispatch selects the specialized TTS model without changing image or text dispatch. */
	public function test_provider_dispatches_models_by_supported_capability(): void {
		$method = new \ReflectionMethod( SuperdavAiProvider::class, 'createModel' );
		$method->setAccessible( true );

		$tts = $method->invoke( null, $this->tts_metadata(), SuperdavAiProvider::metadata() );
		$image = $method->invoke( null, new ModelMetadata( 'image', 'Image', array( CapabilityEnum::imageGeneration() ), array() ), SuperdavAiProvider::metadata() );
		$text = $method->invoke( null, new ModelMetadata( 'text', 'Text', array( CapabilityEnum::textGeneration() ), array() ), SuperdavAiProvider::metadata() );

		$this->assertInstanceOf( SuperdavAiTextToSpeechConversionModel::class, $tts );
		$this->assertInstanceOf( SuperdavAiImageGenerationModel::class, $image );
		$this->assertInstanceOf( SuperdavAiTextGenerationModel::class, $text );
	}

	/** The SDK builder sends safe attributed options and returns one inline audio file. */
	public function test_prompt_builder_converts_joined_text_to_inline_audio(): void {
		list( $model, $transporter ) = $this->configured_model( new Response( 200, array( 'content-type' => 'audio/wav' ), "\x00\x01\x02" ) );
		$config = new ModelConfig();
		$config->setOutputMimeType( 'audio/wav' );
		$config->setOutputSpeechVoice( 'alloy' );
		$config->setCustomOptions(
			array(
				'speed'    => 1.25,
				'language' => 'en-US',
			)
		);
		$model->setConfig( $config );
		$model->setRequestOptions( RequestOptions::fromArray( array( RequestOptions::KEY_TIMEOUT => 123.0 ) ) );
		ProviderTraceLogger::set_runtime_context( SuperdavAiProvider::PROVIDER_ID, 'superdav-tts', 42 );

		$registry = new ProviderRegistry();
		$registry->setHttpTransporter( $transporter );
		$registry->registerProvider( SuperdavAiProvider::class );
		$registry->setProviderRequestAuthentication( SuperdavAiProvider::class, new ApiKeyRequestAuthentication( 'test-token' ) );
		$builder = new PromptBuilder(
			$registry,
			array(
				new UserMessage( array( new MessagePart( 'First sentence.' ), new MessagePart( new File( 'AQ==', 'audio/mpeg' ) ) ) ),
				new UserMessage( array( new MessagePart( 'Second sentence.' ) ) ),
			)
		);
		$file = $builder->usingModel( $model )->convertTextToSpeech();

		$this->assertNotNull( $transporter->request );
		$this->assertSame( 'https://api.sdaiagent.com/v1/audio/speech', $transporter->request->getUri() );
		$this->assertSame( 'application/json', $transporter->request->getHeaderAsString( 'content-type' ) );
		$this->assertSame( 'Bearer test-token', $transporter->request->getHeaderAsString( 'authorization' ) );
		$this->assertSame( '42', $transporter->request->getHeaderAsString( SuperdavAiProvider::SESSION_ATTRIBUTION_HEADER ) );
		$this->assertSame( 123.0, $transporter->request->getOptions()?->getTimeout() );
		$this->assertSame(
			array(
				'model'           => 'superdav-tts',
				'input'           => "First sentence.\nSecond sentence.",
				'voice'           => 'alloy',
				'response_format' => 'wav',
				'speed'           => 1.25,
				'language'        => 'en-US',
			),
			$transporter->request->getData()
		);
		$this->assertTrue( $file->isInline() );
		$this->assertSame( 'audio/wav', $file->getMimeType() );
		$this->assertSame( base64_encode( "\x00\x01\x02" ), $file->getBase64Data() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Verifies the SDK's required inline binary representation.
	}

	/**
	 * Every service-advertised MIME type maps to its exact request format.
	 *
	 * @dataProvider mime_type_provider
	 */
	public function test_maps_every_supported_mime_type( string $mime_type, string $response_format ): void {
		list( $model, $transporter ) = $this->configured_model( new Response( 200, array( 'content-type' => $mime_type . '; charset=binary' ), 'audio' ) );
		$config = new ModelConfig();
		$config->setOutputMimeType( $mime_type );
		$model->setConfig( $config );

		$result = $model->convertTextToSpeechResult( array( new UserMessage( array( new MessagePart( 'Hello.' ) ) ) ) );
		$file   = $result->getCandidates()[0]->getMessage()->getParts()[0]->getFile();

		$this->assertSame( $response_format, $transporter->request?->getData()['response_format'] ?? null );
		$this->assertNotNull( $file );
		$this->assertSame( $mime_type, $file->getMimeType() );
		$this->assertStringStartsWith( 'superdav-tts-', $result->getId() );
		$this->assertSame( 'stop', (string) $result->getCandidates()[0]->getFinishReason() );
	}

	/** @return array<string, array{0:string, 1:string}> */
	public function mime_type_provider(): array {
		return array(
			'mp3'  => array( 'audio/mpeg', 'mp3' ),
			'opus' => array( 'audio/ogg', 'opus' ),
			'aac'  => array( 'audio/aac', 'aac' ),
			'flac' => array( 'audio/flac', 'flac' ),
			'wav'  => array( 'audio/wav', 'wav' ),
			'pcm'  => array( 'audio/l16', 'pcm' ),
		);
	}

	/** Defaults use the service's canonical voice and MP3 format. */
	public function test_uses_service_defaults_when_voice_and_mime_are_unset(): void {
		list( $model, $transporter ) = $this->configured_model( new Response( 200, array( 'content-type' => 'audio/mpeg' ), 'audio' ) );

		$model->convertTextToSpeechResult( array( new UserMessage( array( new MessagePart( 'Hello.' ) ) ) ) );

		$this->assertSame( 'alloy', $transporter->request?->getData()['voice'] ?? null );
		$this->assertSame( 'mp3', $transporter->request?->getData()['response_format'] ?? null );
	}

	/**
	 * Empty and oversized prompts fail before transport.
	 *
	 * @dataProvider invalid_prompt_provider
	 */
	public function test_rejects_invalid_text_prompt( string $text ): void {
		list( $model ) = $this->configured_model( new Response( 200, array( 'content-type' => 'audio/mpeg' ), 'audio' ) );
		$this->expectException( InvalidArgumentException::class );

		$model->convertTextToSpeechResult( array( new UserMessage( array( new MessagePart( $text ) ) ) ) );
	}

	/** @return array<string, array{0:string}> */
	public function invalid_prompt_provider(): array {
		return array(
			'empty'         => array( '' ),
			'whitespace'    => array( " \n\t " ),
			'invalid UTF-8' => array( "\xC3\x28" ),
			'over limit'    => array( str_repeat( 'é', SuperdavAiTextToSpeechConversionModel::MAX_INPUT_CHARACTERS + 1 ) ),
		);
	}

	/** A prompt containing only non-text parts is rejected. */
	public function test_rejects_non_text_only_prompt(): void {
		list( $model ) = $this->configured_model( new Response( 200, array( 'content-type' => 'audio/mpeg' ), 'audio' ) );
		$this->expectException( InvalidArgumentException::class );

		$model->convertTextToSpeechResult( array( new UserMessage( array( new MessagePart( new File( 'AQ==', 'audio/mpeg' ) ) ) ) ) );
	}

	/** Instructions are explicitly unsupported by the managed service contract. */
	public function test_rejects_system_instruction(): void {
		list( $model ) = $this->configured_model( new Response( 200, array( 'content-type' => 'audio/mpeg' ), 'audio' ) );
		$config = new ModelConfig();
		$config->setSystemInstruction( 'Speak softly.' );
		$model->setConfig( $config );
		$this->expectException( InvalidArgumentException::class );

		$model->convertTextToSpeechResult( array( new UserMessage( array( new MessagePart( 'Hello.' ) ) ) ) );
	}

	/**
	 * Custom options are a strict allowlist with value validation.
	 *
	 * @param array<string, mixed> $custom_options Custom options.
	 * @dataProvider invalid_custom_options_provider
	 */
	public function test_rejects_invalid_or_colliding_custom_options( array $custom_options ): void {
		list( $model ) = $this->configured_model( new Response( 200, array( 'content-type' => 'audio/mpeg' ), 'audio' ) );
		$config = new ModelConfig();
		$config->setCustomOptions( $custom_options );
		$model->setConfig( $config );
		$this->expectException( InvalidArgumentException::class );

		$model->convertTextToSpeechResult( array( new UserMessage( array( new MessagePart( 'Hello.' ) ) ) ) );
	}

	/** @return array<string, array{0:array<string, mixed>}> */
	public function invalid_custom_options_provider(): array {
		return array(
			'required collision'      => array( array( 'model' => 'other' ) ),
			'unsupported instructions' => array( array( 'instructions' => 'Whisper.' ) ),
			'unknown option'           => array( array( 'arbitrary' => true ) ),
			'non-numeric speed'        => array( array( 'speed' => '1.0' ) ),
			'out-of-range speed'       => array( array( 'speed' => 4.1 ) ),
			'invalid language'         => array( array( 'language' => '../en' ) ),
			'unsupported language'     => array( array( 'language' => 'fr-FR' ) ),
		);
	}

	/** Unsupported configured voice and MIME values are rejected. */
	public function test_rejects_unsupported_voice_and_mime(): void {
		list( $model ) = $this->configured_model( new Response( 200, array( 'content-type' => 'audio/mpeg' ), 'audio' ) );
		$config = new ModelConfig();
		$config->setOutputSpeechVoice( 'unknown' );
		$model->setConfig( $config );

		try {
			$model->convertTextToSpeechResult( array( new UserMessage( array( new MessagePart( 'Hello.' ) ) ) ) );
			$this->fail( 'An unsupported voice should fail.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'voice', $e->getMessage() );
		}

		$config = new ModelConfig();
		$config->setOutputMimeType( 'audio/webm' );
		$model->setConfig( $config );
		$this->expectException( InvalidArgumentException::class );
		$model->convertTextToSpeechResult( array( new UserMessage( array( new MessagePart( 'Hello.' ) ) ) ) );
	}

	/**
	 * Malformed successful audio responses fail through the SDK response exception path.
	 *
	 * @dataProvider malformed_response_provider
	 */
	public function test_rejects_malformed_successful_response( ?string $content_type, ?string $body ): void {
		$headers = null === $content_type ? array() : array( 'content-type' => $content_type );
		list( $model ) = $this->configured_model( new Response( 200, $headers, $body ) );
		$this->expectException( ResponseException::class );

		$model->convertTextToSpeechResult( array( new UserMessage( array( new MessagePart( 'Hello.' ) ) ) ) );
	}

	/** @return array<string, array{0:string|null, 1:string|null}> */
	public function malformed_response_provider(): array {
		return array(
			'empty body'       => array( 'audio/mpeg', '' ),
			'missing MIME'     => array( null, 'audio' ),
			'unsupported MIME' => array( 'application/json', 'audio' ),
			'mismatched MIME'  => array( 'audio/wav', 'audio' ),
		);
	}

	/** Oversized binary responses fail before inline base64 expansion. */
	public function test_rejects_oversized_audio_response(): void {
		list( $model ) = $this->configured_model(
			new Response( 200, array( 'content-type' => 'audio/mpeg' ), str_repeat( 'a', SuperdavAiTextToSpeechConversionModel::MAX_RESPONSE_BYTES + 1 ) )
		);
		$this->expectException( ResponseException::class );

		$model->convertTextToSpeechResult( array( new UserMessage( array( new MessagePart( 'Hello.' ) ) ) ) );
	}

	/** Non-success statuses use the SDK's normal scrubbed response exception mapping. */
	public function test_maps_non_success_response_with_sdk_utility(): void {
		list( $model ) = $this->configured_model( new Response( 400, array( 'content-type' => 'application/json' ), '{"error":{"message":"invalid"}}' ) );
		$this->expectException( ClientException::class );

		$model->convertTextToSpeechResult( array( new UserMessage( array( new MessagePart( 'Hello.' ) ) ) ) );
	}

	/**
	 * @return array{0:SuperdavAiTextToSpeechConversionModel, 1:RecordingAudioTransporter}
	 */
	private function configured_model( Response $response ): array {
		$model       = new SuperdavAiTextToSpeechConversionModel( $this->tts_metadata(), SuperdavAiProvider::metadata() );
		$transporter = new RecordingAudioTransporter( $response );
		$model->setHttpTransporter( $transporter );
		$model->setRequestAuthentication( new TestAudioRequestAuthentication() );

		return array( $model, $transporter );
	}

	/** Return metadata through the same service-directory parser used in production. */
	private function tts_metadata(): ModelMetadata {
		$models = $this->parse_models(
			array(
				array(
					'id'           => 'superdav-tts',
					'name'         => 'Superdav Speech',
					'capabilities' => array( 'text_to_speech_conversion' => true ),
				),
			)
		);

		return $models[0];
	}

	/**
	 * @param list<array<string, mixed>> $items Service model entries.
	 * @return list<ModelMetadata>
	 */
	private function parse_models( array $items ): array {
		$directory = new SuperdavAiModelMetadataDirectory();
		$method    = new \ReflectionMethod( $directory, 'parseResponseToModelMetadataList' );
		$method->setAccessible( true );
		$models = $method->invoke(
			$directory,
			new Response( 200, array( 'content-type' => 'application/json' ), (string) wp_json_encode( array( 'data' => $items ) ) )
		);

		return is_array( $models ) ? $models : array();
	}

	/** Restore singleton provider dependencies changed by the isolated builder registry. */
	private function restore_provider_dependencies(): void {
		$registry = AiClient::defaultRegistry();
		try {
			$transporter   = $registry->getHttpTransporter();
			$authentication = $registry->getProviderRequestAuthentication( SuperdavAiProvider::class );
		} catch ( \Throwable $e ) {
			return;
		}

		$components = array( SuperdavAiProvider::availability(), SuperdavAiProvider::modelMetadataDirectory() );
		foreach ( $components as $component ) {
			if ( method_exists( $component, 'setHttpTransporter' ) ) {
				$component->setHttpTransporter( $transporter );
			}
			if ( null !== $authentication && method_exists( $component, 'setRequestAuthentication' ) ) {
				$component->setRequestAuthentication( $authentication );
			}
		}
	}
}

/** Records the exact outgoing SDK request and returns a configured response. */
final class RecordingAudioTransporter implements HttpTransporterInterface {
	public ?Request $request = null;

	public function __construct( private readonly Response $response ) {}

	public function send( Request $request, ?RequestOptions $options = null ): Response {
		$this->request = $request;
		return $this->response;
	}
}

/** Adds deterministic test authentication without exposing a real credential. */
final class TestAudioRequestAuthentication implements RequestAuthenticationInterface {
	public function authenticateRequest( Request $request ): Request {
		return $request->withHeader( 'Authorization', 'Bearer test-token' );
	}

	public static function getJsonSchema(): array {
		return array();
	}
}
