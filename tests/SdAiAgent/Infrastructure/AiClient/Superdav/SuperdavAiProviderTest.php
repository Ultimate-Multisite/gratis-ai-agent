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
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiModelMetadataDirectory;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiProvider;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiTextGenerationModel;
use WP_UnitTestCase;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
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
		parent::tear_down();
	}

	/**
	 * Metadata uses the canonical provider identifiers.
	 */
	public function test_metadata_uses_canonical_provider_identifiers(): void {
		$metadata = SuperdavAiProvider::metadata();

		$this->assertSame( 'sd-ai-agent-cloud', $metadata->getId() );
		$this->assertSame( 'Superdav AI', $metadata->getName() );
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
		$this->assertSame( 5, $hook->prio );
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

		$this->assertSame( 'example-model', $model->getId() );
		$this->assertSame( 'Example Model', $model->getName() );
		$this->assertContainsEquals( CapabilityEnum::textGeneration(), $model->getSupportedCapabilities() );

		$entry = ModelCapabilityRegistry::get( 'example-model' );
		$this->assertSame( 4096, $entry['max_output_tokens'] );
		$this->assertSame( 32768, $entry['context_length'] );
		$this->assertSame( 'Example Model', $entry['display_name'] );
		$this->assertSame( array( 'supports_tool_calling' => true ), $entry['provider_capabilities'] );
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
	 * Skip when the SDK was not loaded in the current test environment.
	 */
	private function skip_if_sdk_unavailable(): void {
		if ( ! class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'WordPress AI Client SDK is unavailable.' );
		}
	}
}
