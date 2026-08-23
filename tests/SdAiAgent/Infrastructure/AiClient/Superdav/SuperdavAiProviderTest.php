<?php
/**
 * Tests for the first-party Superdav AI Client SDK provider.
 *
 * @package SdAiAgent\Tests\Infrastructure\AiClient\Superdav
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Infrastructure\AiClient\Superdav;

use SdAiAgent\Bootstrap\SuperdavAiProviderHandler;
use SdAiAgent\Core\ModelCapabilityRegistry;
use SdAiAgent\Core\ProviderCredentialLoader;
use SdAiAgent\Core\ProviderTraceLogger;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiImageGenerationModel;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiModelMetadataDirectory;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiProvider;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiResponsesToolSearchTextGenerationModel;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiTextGenerationModel;
use WP_UnitTestCase;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use XWP\DI\Decorators\Action;

/**
 * Covers provider metadata, registration, and credential bridging.
 */
final class SuperdavAiProviderTest extends WP_UnitTestCase {

	/**
	 * Clean up provider-specific options.
	 */
	public function tear_down(): void {
		delete_option( SuperdavAiProvider::CREDENTIAL_OPTION );
		ModelCapabilityRegistry::forget( 'example-model' );
		remove_all_filters( 'sd_ai_agent_cloud_base_url' );
		remove_all_filters( 'sd_ai_agent_superdav_default_model' );
		remove_all_filters( 'sd_ai_agent_superdav_reasoning_effort' );
		remove_all_filters( 'sd_ai_agent_openai_tool_search_enabled' );
		ProviderTraceLogger::clear_runtime_context();
		parent::tear_down();
	}

	/**
	 * Metadata uses the canonical provider identifiers.
	 */
	public function test_metadata_uses_canonical_provider_identifiers(): void {
		$metadata = SuperdavAiProvider::metadata();

		$this->assertSame( 'sd-ai-agent-cloud', $metadata->getId() );
		$this->assertSame( 'SD AI', $metadata->getName() );
		$this->assertNotNull( $metadata->getAuthenticationMethod() );
	}

	/**
	 * Handler registers the first-party provider with the SDK registry.
	 */
	public function test_handler_registers_provider_with_default_registry(): void {
		$this->skip_if_sdk_unavailable();

		( new SuperdavAiProviderHandler() )->register_provider();

		$this->assertTrue( AiClient::defaultRegistry()->hasProvider( SuperdavAiProvider::PROVIDER_ID ) );
	}

	/**
	 * Handler registration waits until early init so SDK classes loaded by later
	 * plugins_loaded callbacks are available before default connectors register.
	 */
	public function test_handler_registers_provider_on_early_init(): void {
		$method     = new \ReflectionMethod( SuperdavAiProviderHandler::class, 'register_provider' );
		$attributes = $method->getAttributes( Action::class );

		$this->assertCount( 1, $attributes );

		$hook = $attributes[0]->newInstance();
		$this->assertSame( 'init', $hook->tag );
		$this->assertSame( 5, $hook->__get( 'priority' ) );
	}

	/**
	 * Credential loader bridges the Superdav connector option into SDK auth.
	 */
	public function test_credential_loader_sets_superdav_provider_authentication(): void {
		$this->skip_if_sdk_unavailable();

		( new SuperdavAiProviderHandler() )->register_provider();
		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, 'test-key' );

		ProviderCredentialLoader::load();

		$auth = AiClient::defaultRegistry()->getProviderRequestAuthentication( SuperdavAiProvider::PROVIDER_ID );
		$this->assertNotNull( $auth );
	}

	/**
	 * The managed provider defaults to the production edge for first-install setup.
	 */
	public function test_default_base_url_points_to_production_edge(): void {
		$this->assertSame( SuperdavAiProvider::DEFAULT_BASE_URL, SuperdavAiProvider::configured_base_url() );
		$this->assertSame(
			'https://api.sdaiagent.com/v1/site/installations',
			SuperdavAiProvider::configured_service_url( 'site/installations' )
		);
	}

	/**
	 * Local development may use the configured loopback edge with the safe SDK transporter.
	 */
	public function test_handler_allows_only_the_configured_loopback_edge(): void {
		add_filter(
			'sd_ai_agent_cloud_base_url',
			static fn(): string => 'http://127.0.0.1:3200/v1'
		);
		$handler = new SuperdavAiProviderHandler();

		$this->assertTrue(
			$handler->allow_configured_loopback_host( false, '127.0.0.1', 'http://127.0.0.1:3200/v1/models' )
		);
		$this->assertFalse(
			$handler->allow_configured_loopback_host( false, '127.0.0.1', 'http://127.0.0.1:3200/admin' )
		);
		$this->assertFalse(
			$handler->allow_configured_loopback_host( false, '127.0.0.1', 'http://127.0.0.1:3200/v1/../admin' )
		);
		$this->assertFalse(
			$handler->allow_configured_loopback_host( false, '127.0.0.1', 'http://127.0.0.1:3200/v1/%2e%2e/admin' )
		);
		$this->assertFalse(
			$handler->allow_configured_loopback_host( false, 'localhost', 'http://localhost:3200/v1/models' )
		);
		$this->assertFalse(
			$handler->allow_configured_loopback_host( false, '127.0.0.1', 'http://127.0.0.1:3300/v1/models' )
		);
		$this->assertFalse(
			$handler->allow_configured_loopback_host( false, '127.0.0.1', 'https://127.0.0.1:3200/v1/models' )
		);
		$this->assertTrue(
			$handler->allow_configured_loopback_host( true, 'unrelated.example', 'https://unrelated.example/models' )
		);
	}

	/**
	 * Only the configured loopback service port is added to WordPress's safe list.
	 */
	public function test_handler_allows_only_the_configured_loopback_port(): void {
		add_filter(
			'sd_ai_agent_cloud_base_url',
			static fn(): string => 'http://127.0.0.1:3200/v1'
		);
		$handler = new SuperdavAiProviderHandler();

		$this->assertSame(
			array( 80, 443, 3200 ),
			$handler->allow_configured_loopback_port(
				array( 80, 443 ),
				'127.0.0.1',
				'http://127.0.0.1:3200/v1/chat/completions'
			)
		);
		$this->assertSame(
			array( 80, 443 ),
			$handler->allow_configured_loopback_port(
				array( 80, 443 ),
				'127.0.0.1',
				'http://127.0.0.1:3300/v1/chat/completions'
			)
		);
	}

	/**
	 * The bundled provider defaults clean installs to the standard managed alias.
	 */
	public function test_default_model_is_managed_standard_alias(): void {
		$this->assertSame( 'superdav-chat-pro', SuperdavAiProvider::default_model_id() );
	}

	/**
	 * Managed Superdav aliases resolve to explicit effort levels.
	 *
	 * @dataProvider managed_model_effort_provider
	 */
	public function test_managed_model_reasoning_effort_mapping( string $model_id, string $expected_effort ): void {
		$this->assertSame( $expected_effort, SuperdavAiProvider::reasoning_effort_for_model( $model_id ) );
	}

	/**
	 * The managed provider sends explicit effort hints for Superdav aliases.
	 *
	 * @dataProvider managed_model_effort_provider
	 */
	public function test_text_generation_request_includes_managed_reasoning_effort( string $model_id, string $expected_effort ): void {
		$this->skip_if_sdk_unavailable();

		$model  = new SuperdavAiTextGenerationModel(
			new ModelMetadata( $model_id, $model_id, array(), array() ),
			SuperdavAiProvider::metadata()
		);
		$method = new \ReflectionMethod( $model, 'createRequest' );
		$method->setAccessible( true );

		$request = $method->invoke(
			$model,
			HttpMethodEnum::POST(),
			'chat/completions',
			array( 'Content-Type' => 'application/json' ),
			array( 'model' => $model_id )
		);

		$this->assertIsObject( $request );
		$this->assertTrue( method_exists( $request, 'getData' ) );
		$data = $request->getData();
		$this->assertIsArray( $data );
		$this->assertSame( $expected_effort, $data['reasoning_effort'] ?? '' );
	}

	/** Managed inference requests carry only the active local session ID. */
	public function test_text_generation_request_includes_safe_session_attribution(): void {
		$this->skip_if_sdk_unavailable();

		$model = new SuperdavAiTextGenerationModel(
			new ModelMetadata( 'example-model', 'Example Model', array(), array() ),
			SuperdavAiProvider::metadata()
		);
		$method = new \ReflectionMethod( $model, 'createRequest' );
		$method->setAccessible( true );

		ProviderTraceLogger::set_runtime_context( SuperdavAiProvider::PROVIDER_ID, 'example-model', 42 );
		$request = $method->invoke(
			$model,
			HttpMethodEnum::POST(),
			'chat/completions',
			array( 'Content-Type' => 'application/json' ),
			array( 'model' => 'example-model' )
		);
		ProviderTraceLogger::clear_runtime_context();

		$this->assertSame( '42', $request->getHeaderAsString( SuperdavAiProvider::SESSION_ATTRIBUTION_HEADER ) );

		$unattributed_request = $method->invoke(
			$model,
			HttpMethodEnum::POST(),
			'chat/completions',
			array( 'Content-Type' => 'application/json' ),
			array( 'model' => 'example-model' )
		);
		$this->assertNull( $unattributed_request->getHeaderAsString( SuperdavAiProvider::SESSION_ATTRIBUTION_HEADER ) );
	}

	/**
	 * The managed provider must round-trip hidden reasoning context on later turns.
	 */
	public function test_text_generation_request_round_trips_reasoning_content(): void {
		$this->skip_if_sdk_unavailable();

		$model = new SuperdavAiTextGenerationModel(
			new ModelMetadata(
				SuperdavAiProvider::DEFAULT_MODEL_ID,
				SuperdavAiProvider::DEFAULT_MODEL_ID,
				array( CapabilityEnum::textGeneration() ),
				array()
			),
			SuperdavAiProvider::metadata()
		);

		$method = new \ReflectionMethod( $model, 'prepareGenerateTextParams' );
		$method->setAccessible( true );

		$params = $method->invoke(
			$model,
			array(
				new ModelMessage(
					array(
						new MessagePart( 'Use the prior tool result.', MessagePartChannelEnum::thought() ),
						new MessagePart( 'Visible assistant preamble.' ),
						new MessagePart( new FunctionCall( 'call_1', 'wpab__sd-ai-agent__site-info', array() ) ),
					)
				),
			)
		);

		$this->assertIsArray( $params );
		$this->assertIsArray( $params['messages'] ?? null );
		$this->assertSame( 'assistant', $params['messages'][0]['role'] ?? '' );
		$this->assertSame( 'Use the prior tool result.', $params['messages'][0]['reasoning_content'] ?? '' );
		$this->assertSame(
			array( array( 'type' => 'text', 'text' => 'Visible assistant preamble.' ) ),
			$params['messages'][0]['content'] ?? array()
		);
		$this->assertNotEmpty( $params['messages'][0]['tool_calls'] ?? array() );
	}

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public function managed_model_effort_provider(): array {
		return array(
			'fast'     => array( SuperdavAiProvider::FAST_MODEL_ID, 'low' ),
			'standard' => array( SuperdavAiProvider::DEFAULT_MODEL_ID, 'medium' ),
			'strong'   => array( SuperdavAiProvider::STRONG_MODEL_ID, 'high' ),
		);
	}

	/**
	 * Model metadata parsing exposes OpenAI-compatible text generation models.
	 */
	public function test_model_metadata_contains_text_generation_capability(): void {
		$this->skip_if_sdk_unavailable();

		$directory = new SuperdavAiModelMetadataDirectory();
		$method    = new \ReflectionMethod( $directory, 'parseResponseToModelMetadataList' );
		$models    = $method->invoke(
			$directory,
			new Response(
				200,
				array( 'content-type' => 'application/json' ),
				'{"data":[{"id":"example-model","name":"Example Model","context_length":32768,"max_output_length":4096,"supports_tool_calling":true}]}'
			)
		);

		$this->assertIsArray( $models );
		$this->assertCount( 1, $models );
		$model = $models[0];
		$this->assertInstanceOf( ModelMetadata::class, $model );

		$this->assertSame( 'example-model', $model->getId() );
		$this->assertSame( 'Example Model', $model->getName() );
		$this->assertContainsEquals( CapabilityEnum::textGeneration(), $model->getSupportedCapabilities() );

		$inputModalities = array_values(
			array_filter(
				$model->getSupportedOptions(),
				static fn( SupportedOption $option ): bool => 'inputModalities' === $option->getName()->value
			)
		);
		$this->assertCount( 1, $inputModalities );
		$this->assertTrue( $inputModalities[0]->isSupportedValue( array( ModalityEnum::text() ) ) );

		$modelConfig = new ModelConfig();
		$modelConfig->setSystemInstruction( 'Generate a WordPress plugin.' );
		$requirements = ModelRequirements::fromPromptData(
			CapabilityEnum::textGeneration(),
			array( new UserMessage( array( new MessagePart( 'Create a shortcode plugin.' ) ) ) ),
			$modelConfig
		);
		$this->assertTrue( $requirements->areMetBy( $model ) );

		$entry = ModelCapabilityRegistry::get( 'example-model' );
		$this->assertSame( 4096, $entry['max_output_tokens'] );
		$this->assertSame( 32768, $entry['context_length'] );
		$this->assertSame( 'Example Model', $entry['display_name'] );
		$this->assertSame( array( 'supports_tool_calling' => true ), $entry['provider_capabilities'] );
	}

	/**
	 * Model metadata parsing exposes Superdav image models to the SDK.
	 */
	public function test_model_metadata_contains_image_generation_capability(): void {
		$this->skip_if_sdk_unavailable();

		$directory = new SuperdavAiModelMetadataDirectory();
		$method    = new \ReflectionMethod( $directory, 'parseResponseToModelMetadataList' );
		$models    = $method->invoke(
			$directory,
			new Response(
				200,
				array( 'content-type' => 'application/json' ),
				'{"data":[{"id":"superdav-image","name":"Superdav Image","capabilities":{"image_generation":true,"text_generation":false}}]}'
			)
		);

		$this->assertIsArray( $models );
		$this->assertCount( 1, $models );
		$model = $models[0];

		$this->assertSame( SuperdavAiProvider::IMAGE_MODEL_ID, $model->getId() );
		$this->assertSame( 'Superdav Image', $model->getName() );
		$this->assertContainsEquals( CapabilityEnum::imageGeneration(), $model->getSupportedCapabilities() );

		$option_names = array_map(
			static fn( SupportedOption $option ): string => $option->getName()->value,
			$model->getSupportedOptions()
		);

		$this->assertContains( 'outputMimeType', $option_names );
		$this->assertContains( 'outputFileType', $option_names );
		$this->assertContains( 'outputMediaOrientation', $option_names );

		$input_modalities = array_values(
			array_filter(
				$model->getSupportedOptions(),
				static fn( SupportedOption $option ): bool => 'inputModalities' === $option->getName()->value
			)
		);
		$this->assertCount( 1, $input_modalities );
		$this->assertTrue( $input_modalities[0]->isSupportedValue( array( ModalityEnum::text(), ModalityEnum::image() ) ) );
	}

	/**
	 * Image-capable metadata creates an image generation model instance.
	 */
	public function test_provider_creates_image_generation_model_for_image_capability(): void {
		$this->skip_if_sdk_unavailable();

		$method = new \ReflectionMethod( SuperdavAiProvider::class, 'createModel' );
		$method->setAccessible( true );
		$model = $method->invoke(
			null,
			new ModelMetadata(
				SuperdavAiProvider::IMAGE_MODEL_ID,
				'Superdav Image',
				array( CapabilityEnum::imageGeneration() ),
				array()
			),
			SuperdavAiProvider::metadata()
		);

		$this->assertInstanceOf( SuperdavAiImageGenerationModel::class, $model );
	}

	/**
	 * Text-only image prompts retain the JSON image generation endpoint.
	 */
	public function test_text_only_image_request_uses_generations_endpoint(): void {
		$this->skip_if_sdk_unavailable();

		$model = new SuperdavAiImageGenerationModel(
			new ModelMetadata(
				SuperdavAiProvider::IMAGE_MODEL_ID,
				'Superdav Image',
				array( CapabilityEnum::imageGeneration() ),
				array()
			),
			SuperdavAiProvider::metadata()
		);
		$transporter = new class() implements HttpTransporterInterface {
			public ?Request $request = null;

			public function send( Request $request, ?RequestOptions $options = null ): Response {
				$this->request = $request;

				return new Response(
					200,
					array( 'content-type' => 'application/json' ),
					'{"created":123,"data":[{"b64_json":"AQ=="}]}'
				);
			}
		};
		$authentication = new class() implements RequestAuthenticationInterface {
			public function authenticateRequest( Request $request ): Request {
				return $request;
			}

			public static function getJsonSchema(): array {
				return array();
			}
		};

		$model->setHttpTransporter( $transporter );
		$model->setRequestAuthentication( $authentication );
		$model->generateImageResult(
			array( new UserMessage( array( new MessagePart( 'Create a beach scene.' ) ) ) )
		);

		$this->assertInstanceOf( Request::class, $transporter->request );
		$this->assertSame( 'https://api.sdaiagent.com/v1/images/generations', $transporter->request->getUri() );
		$this->assertSame( 'application/json', $transporter->request->getHeaderAsString( 'Content-Type' ) );
	}

	/**
	 * Managed image requests translate DALL-E hints to GPT Image options.
	 */
	public function test_image_request_normalizes_managed_model_options(): void {
		$this->skip_if_sdk_unavailable();

		$model = new SuperdavAiImageGenerationModel(
			new ModelMetadata(
				SuperdavAiProvider::IMAGE_MODEL_ID,
				'Superdav Image',
				array( CapabilityEnum::imageGeneration() ),
				array()
			),
			SuperdavAiProvider::metadata()
		);
		$config = new ModelConfig();
		$config->setCustomOptions(
			array(
				'size'    => '1792x1024',
				'style'   => 'natural',
				'quality' => ' HIGH ',
			)
		);
		$model->setConfig( $config );

		$prepare = new \ReflectionMethod( $model, 'prepareGenerateImageParams' );
		$prepare->setAccessible( true );
		$params = $prepare->invoke( $model, array( new UserMessage( array( new MessagePart( 'Create a beach scene.' ) ) ) ) );

		$this->assertIsArray( $params );
		$this->assertSame( '1536x1024', $params['size'] );
		$this->assertSame( 'high', $params['quality'] );
		$this->assertArrayNotHasKey( 'style', $params );
		$this->assertArrayNotHasKey( 'response_format', $params );
	}

	/**
	 * Image prompts use the OpenAI-compatible edit endpoint with a multipart upload.
	 */
	public function test_image_edit_request_uses_multipart_edits_endpoint(): void {
		$this->skip_if_sdk_unavailable();

		$model = new SuperdavAiImageGenerationModel(
			new ModelMetadata(
				SuperdavAiProvider::IMAGE_MODEL_ID,
				'Superdav Image',
				array( CapabilityEnum::imageGeneration() ),
				array()
			),
			SuperdavAiProvider::metadata()
		);
		$transporter = new class() implements HttpTransporterInterface {
			public ?Request $request = null;

			public function send( Request $request, ?RequestOptions $options = null ): Response {
				$this->request = $request;

				return new Response(
					200,
					array( 'content-type' => 'application/json' ),
					'{"created":123,"data":[{"b64_json":"AQ=="}]}'
				);
			}
		};
		$authentication = new class() implements RequestAuthenticationInterface {
			public function authenticateRequest( Request $request ): Request {
				return $request;
			}

			public static function getJsonSchema(): array {
				return array();
			}
		};

		$model->setHttpTransporter( $transporter );
		$model->setRequestAuthentication( $authentication );
		$result = $model->generateImageResult(
			array(
				new UserMessage(
					array(
						new MessagePart( 'Replace the background with a beach.' ),
						new MessagePart( new File( 'data:image/png;base64,AQ==' ) ),
					)
				),
			)
		);

		$this->assertSame( 'img-123', $result->getId() );
		$this->assertInstanceOf( Request::class, $transporter->request );
		$this->assertSame( 'https://api.sdaiagent.com/v1/images/edits', $transporter->request->getUri() );
		$this->assertStringStartsWith( 'multipart/form-data; boundary=', (string) $transporter->request->getHeaderAsString( 'Content-Type' ) );
		$this->assertStringContainsString( 'name="model"', (string) $transporter->request->getBody() );
		$this->assertStringContainsString( 'name="prompt"', (string) $transporter->request->getBody() );
		$this->assertStringContainsString( 'name="image"; filename="image.png"', (string) $transporter->request->getBody() );
		$this->assertStringContainsString( "\r\n\x01\r\n", (string) $transporter->request->getBody() );
	}

	/**
	 * Configured Superdav base URL exposes its host for capability ingestion gates.
	 */
	public function test_configured_base_host_uses_filtered_cloud_base_url(): void {
		add_filter(
			'sd_ai_agent_cloud_base_url',
			static fn(): string => 'https://models.superdav.example/openai/v1'
		);

		$this->assertSame( 'models.superdav.example', SuperdavAiProvider::configured_base_host() );
	}

	/**
	 * Service helper URLs use the same configured `/v1` base as the OpenAI-compatible provider.
	 */
	public function test_configured_service_url_uses_filtered_cloud_base_url(): void {
		add_filter(
			'sd_ai_agent_cloud_base_url',
			static fn(): string => 'https://service.example/v1'
		);

		$this->assertSame(
			'https://service.example/v1/site/installations',
			SuperdavAiProvider::configured_service_url( 'site/installations' )
		);
	}

	/**
	 * Chat completion requests include the SDK request options bound by PromptBuilder.
	 */
	public function test_text_generation_request_attaches_sdk_request_options(): void {
		$this->skip_if_sdk_unavailable();

		$model = new SuperdavAiTextGenerationModel(
			new ModelMetadata(
				'example-model',
				'Example Model',
				array( CapabilityEnum::textGeneration() ),
				array()
			),
			SuperdavAiProvider::metadata()
		);

		$options = RequestOptions::fromArray(
			array(
				RequestOptions::KEY_TIMEOUT => 123.0,
			)
		);
		$model->setRequestOptions( $options );

		$method  = new \ReflectionMethod( $model, 'createRequest' );
		$request = $method->invoke(
			$model,
			HttpMethodEnum::POST(),
			'chat/completions',
			array( 'Content-Type' => 'application/json' ),
			array( 'model' => 'example-model' )
		);

		$this->assertNotNull( $request->getOptions() );
		$this->assertSame( 123.0, $request->getOptions()->getTimeout() );
	}

	/**
	 * Tool search is enabled only for the documented GPT-5.4+ model range by default.
	 *
	 * @dataProvider tool_search_model_support_provider
	 */
	public function test_model_supports_responses_tool_search_range( string $model_id, bool $expected ): void {
		$this->assertSame( $expected, SuperdavAiProvider::model_supports_responses_tool_search( $model_id ) );
	}

	/**
	 * @return array<string, array{0:string, 1:bool}>
	 */
	public function tool_search_model_support_provider(): array {
		return array(
			'gpt-5.3'       => array( 'gpt-5.3', false ),
			'gpt-5.4'       => array( 'gpt-5.4', true ),
			'gpt-5.5-pro'   => array( 'gpt-5.5-pro', true ),
			'gpt-6'         => array( 'gpt-6', true ),
			'gpt-4o'        => array( 'gpt-4o', false ),
			'managed alias' => array( SuperdavAiProvider::DEFAULT_MODEL_ID, false ),
		);
	}

	/**
	 * Provider model creation switches to the Responses tool-search model for GPT-5.5.
	 */
	public function test_create_model_uses_responses_tool_search_model_for_gpt_5_5(): void {
		$this->skip_if_sdk_unavailable();

		$method = new \ReflectionMethod( SuperdavAiProvider::class, 'createModel' );
		$method->setAccessible( true );

		$model = $method->invoke(
			null,
			new ModelMetadata( 'gpt-5.5', 'GPT-5.5', array( CapabilityEnum::textGeneration() ), array() ),
			SuperdavAiProvider::metadata()
		);

		$this->assertInstanceOf( SuperdavAiResponsesToolSearchTextGenerationModel::class, $model );
	}

	/**
	 * Responses requests use `/responses`, namespaces, deferred functions, and `tool_search`.
	 */
	public function test_responses_tool_search_request_shape(): void {
		$this->skip_if_sdk_unavailable();

		$model  = new SuperdavAiResponsesToolSearchTextGenerationModel(
			new ModelMetadata( 'gpt-5.5', 'GPT-5.5', array( CapabilityEnum::textGeneration() ), array() ),
			SuperdavAiProvider::metadata()
		);
		$config = new ModelConfig();
		$config->setSystemInstruction( 'You are a WordPress assistant.' );
		$config->setMaxTokens( 512 );
		$config->setFunctionDeclarations(
			array(
				new FunctionDeclaration(
					'wpab__sd-ai-agent__list-posts',
					'List WordPress posts.',
					array(
						'type'       => 'object',
						'properties' => array(
							'post_type' => array( 'type' => 'string' ),
						),
					)
				),
				new FunctionDeclaration(
					'wpab__example-plugin__long-tail',
					'Long-tail example tool.',
					array(
						'type' => 'object',
					)
				),
			)
		);
		$model->setConfig( $config );

		$prepare = new \ReflectionMethod( $model, 'prepare_responses_params' );
		$prepare->setAccessible( true );
		$params = $prepare->invoke( $model, array( new UserMessage( array( new MessagePart( 'List my pages.' ) ) ) ) );

		$this->assertSame( 'gpt-5.5', $params['model'] );
		$this->assertSame( 512, $params['max_output_tokens'] );
		$this->assertSame( 'You are a WordPress assistant.', $params['instructions'] );
		$this->assertFalse( $params['parallel_tool_calls'] );
		$this->assertSame( array( 'type' => 'tool_search' ), $params['tools'][2] );
		$this->assertSame( 'namespace', $params['tools'][0]['type'] );
		$this->assertSame( 'wp_abilities_sd_ai_agent', $params['tools'][0]['name'] );
		$this->assertSame( 'function', $params['tools'][0]['tools'][0]['type'] );
		$this->assertArrayNotHasKey( 'defer_loading', $params['tools'][0]['tools'][0] );
		$this->assertSame( 'wpab__sd-ai-agent__list-posts', $params['tools'][0]['tools'][0]['name'] );
		$this->assertSame( 'namespace', $params['tools'][1]['type'] );
		$this->assertSame( 'wp_abilities_example_plugin', $params['tools'][1]['name'] );
		$this->assertTrue( $params['tools'][1]['tools'][0]['defer_loading'] );
	}

	/**
	 * Responses function_call output items map back to SDK FunctionCall parts.
	 */
	public function test_responses_tool_search_response_parses_function_call(): void {
		$this->skip_if_sdk_unavailable();

		$model = new SuperdavAiResponsesToolSearchTextGenerationModel(
			new ModelMetadata( 'gpt-5.5', 'GPT-5.5', array( CapabilityEnum::textGeneration() ), array() ),
			SuperdavAiProvider::metadata()
		);

		$parse  = new \ReflectionMethod( $model, 'parse_response_to_generative_ai_result' );
		$result = $parse->invoke(
			$model,
			new Response(
				200,
				array( 'content-type' => 'application/json' ),
				(string) wp_json_encode(
					array(
						'id'     => 'resp_test',
						'output' => array(
							array(
								'type'    => 'tool_search_call',
								'status'  => 'completed',
								'arguments' => array( 'paths' => array( 'wp_abilities_sd_ai_agent' ) ),
							),
							array(
								'type'      => 'function_call',
								'call_id'   => 'call_123',
								'name'      => 'wpab__sd-ai-agent__list-posts',
								'arguments' => '{"post_type":"page"}',
							),
						),
						'usage'  => array(
							'input_tokens'          => 10,
							'output_tokens'         => 5,
							'total_tokens'          => 15,
							'output_tokens_details' => array( 'reasoning_tokens' => 2 ),
						),
					)
				)
			)
		);

		$this->assertSame( 'resp_test', $result->getId() );
		$this->assertSame( 10, $result->getTokenUsage()->getPromptTokens() );
		$this->assertSame( 5, $result->getTokenUsage()->getCompletionTokens() );
		$this->assertSame( 2, $result->getTokenUsage()->getThoughtTokens() );

		$parts = $result->getCandidates()[0]->getMessage()->getParts();
		$call  = $parts[0]->getFunctionCall();
		$this->assertNotNull( $call );
		$this->assertSame( 'call_123', $call->getId() );
		$this->assertSame( 'wpab__sd-ai-agent__list-posts', $call->getName() );
		$this->assertSame( array( 'post_type' => 'page' ), $call->getArgs() );
	}

	/**
	 * Incomplete Responses payloads caused by max_output_tokens surface as length finishes.
	 */
	public function test_responses_tool_search_response_parses_max_output_tokens_as_length(): void {
		$this->skip_if_sdk_unavailable();

		$model = new SuperdavAiResponsesToolSearchTextGenerationModel(
			new ModelMetadata( 'gpt-5.5', 'GPT-5.5', array( CapabilityEnum::textGeneration() ), array() ),
			SuperdavAiProvider::metadata()
		);

		$parse  = new \ReflectionMethod( $model, 'parse_response_to_generative_ai_result' );
		$result = $parse->invoke(
			$model,
			new Response(
				200,
				array( 'content-type' => 'application/json' ),
				(string) wp_json_encode(
					array(
						'id'                 => 'resp_truncated',
						'status'             => 'incomplete',
						'incomplete_details' => array( 'reason' => 'max_output_tokens' ),
						'output'             => array(
							array(
								'type'    => 'message',
								'content' => array(
									array(
										'type' => 'output_text',
										'text' => 'Partially generated text.',
									),
								),
							),
						),
					)
				)
			)
		);

		$this->assertSame( 'length', (string) $result->getCandidates()[0]->getFinishReason() );
	}

	/**
	 * Skip when the SDK was not loaded in the current test environment.
	 */
	private function skip_if_sdk_unavailable(): void {
		if ( ! class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'WordPress AI Client SDK is unavailable.' );
		}
	}
}
