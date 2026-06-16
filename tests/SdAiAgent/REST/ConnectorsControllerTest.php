<?php
/**
 * Tests for ConnectorsController.
 *
 * @package SdAiAgent\Tests\REST
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\REST;

use SdAiAgent\REST\ConnectorsController;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WP_UnitTestCase;

if ( interface_exists( '\WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface' )
	&& interface_exists( '\WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface' )
	&& interface_exists( '\WordPress\AiClient\Providers\Contracts\ProviderInterface' )
	&& interface_exists( '\WordPress\AiClient\Providers\Models\Contracts\ModelInterface' )
	&& ! class_exists( __NAMESPACE__ . '\ConnectorsControllerTestOpenAiProvider', false )
) {
	/**
	 * Test model metadata directory for the OpenAI connector card.
	 */
	class ConnectorsControllerTestOpenAiModelMetadataDirectory implements \WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface {

		/**
		 * @return list<ModelMetadata>
		 */
		public function listModelMetadata(): array {
			return array( $this->getModelMetadata( 'test-text-model' ) );
		}

		public function hasModelMetadata( string $modelId ): bool {
			return 'test-text-model' === $modelId;
		}

		public function getModelMetadata( string $modelId ): ModelMetadata {
			if ( 'test-text-model' !== $modelId ) {
				throw new \WordPress\AiClient\Common\Exception\InvalidArgumentException( 'Unknown test model.' );
			}

			return new ModelMetadata(
				'test-text-model',
				'Test Text Model',
				array( CapabilityEnum::textGeneration() ),
				array()
			);
		}
	}

	/**
	 * Test provider registered as `openai` so the connector card uses public SDK APIs.
	 */
	class ConnectorsControllerTestOpenAiProvider implements \WordPress\AiClient\Providers\Contracts\ProviderInterface {

		public static function metadata(): ProviderMetadata {
			return new ProviderMetadata(
				'openai',
				'OpenAI Test Provider',
				ProviderTypeEnum::cloud(),
				null,
				RequestAuthenticationMethod::apiKey()
			);
		}

		public static function model( string $modelId, ?ModelConfig $modelConfig = null ): \WordPress\AiClient\Providers\Models\Contracts\ModelInterface {
			$metadata = self::modelMetadataDirectory()->getModelMetadata( $modelId );
			$config   = $modelConfig ?? new ModelConfig();

			return new class( $metadata, $config ) implements \WordPress\AiClient\Providers\Models\Contracts\ModelInterface {
				private ModelMetadata $metadata;
				private ModelConfig $config;

				public function __construct( ModelMetadata $metadata, ModelConfig $config ) {
					$this->metadata = $metadata;
					$this->config   = $config;
				}

				public function metadata(): ModelMetadata {
					return $this->metadata;
				}

				public function providerMetadata(): ProviderMetadata {
					return ConnectorsControllerTestOpenAiProvider::metadata();
				}

				public function setConfig( ModelConfig $config ): void {
					$this->config = $config;
				}

				public function getConfig(): ModelConfig {
					return $this->config;
				}
			};
		}

		public static function availability(): \WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface {
			return new class() implements \WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface {
				public function isConfigured(): bool {
					return true;
				}
			};
		}

		public static function modelMetadataDirectory(): \WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface {
			return new ConnectorsControllerTestOpenAiModelMetadataDirectory();
		}
	}
}

/**
 * Covers safe connector credential reporting.
 */
final class ConnectorsControllerTest extends WP_UnitTestCase {

	/**
	 * Snapshot of registry internals restored after each test.
	 *
	 * @var array<string, mixed>
	 */
	private array $registrySnapshot = array();

	/**
	 * Snapshot the SDK registry before adding test providers/authentication.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->snapshot_registry();
	}

	/**
	 * Restore SDK registry state.
	 */
	public function tear_down(): void {
		$this->restore_registry();
		parent::tear_down();
	}

	/**
	 * The connector list uses public SDK model support and never exposes raw keys.
	 */
	public function test_list_reports_configured_connector_status_without_raw_key(): void {
		$this->register_openai_text_provider();

		$response  = ( new ConnectorsController() )->handle_list();
		$data      = $response->get_data();
		$providers = $data['providers'];
		$openai    = $this->find_provider( $providers, 'openai' );

		$this->assertNotNull( $openai );
		$this->assertTrue( $openai['configured'] );
		$this->assertSame( '', $openai['masked_key'] );
		$this->assertStringNotContainsString( 'test-key', wp_json_encode( $openai ) ?: '' );
	}

	/**
	 * Register a public SDK provider for the OpenAI connector card.
	 */
	private function register_openai_text_provider(): void {
		if ( ! class_exists( AiClient::class ) || ! class_exists( ConnectorsControllerTestOpenAiProvider::class ) ) {
			$this->markTestSkipped( 'AI Client SDK test provider classes are unavailable.' );
		}

		$registry = AiClient::defaultRegistry();
		if ( ! $registry->hasProvider( 'openai' ) ) {
			$registry->registerProvider( ConnectorsControllerTestOpenAiProvider::class );
		}

		$registry->setProviderRequestAuthentication(
			'openai',
			new ApiKeyRequestAuthentication( 'test-key' )
		);
	}

	/**
	 * Snapshot registry internals before registering the test provider.
	 */
	private function snapshot_registry(): void {
		if ( ! class_exists( AiClient::class ) ) {
			return;
		}

		try {
			$registry = AiClient::defaultRegistry();
			foreach ( array( 'registeredIdsToClassNames', 'registeredClassNamesToIds', 'providerAuthenticationInstances' ) as $property_name ) {
				$property = new \ReflectionProperty( $registry, $property_name );
				$property->setAccessible( true );
				$this->registrySnapshot[ $property_name ] = $property->getValue( $registry );
			}
		} catch ( \Throwable $e ) {
			$this->registrySnapshot = array();
		}
	}

	/**
	 * Restore registry internals after registering the test provider.
	 */
	private function restore_registry(): void {
		if ( array() === $this->registrySnapshot || ! class_exists( AiClient::class ) ) {
			return;
		}

		try {
			$registry = AiClient::defaultRegistry();
			foreach ( $this->registrySnapshot as $property_name => $value ) {
				$property = new \ReflectionProperty( $registry, (string) $property_name );
				$property->setAccessible( true );
				$property->setValue( $registry, $value );
			}
		} catch ( \Throwable $e ) {
			// Best-effort cleanup; subsequent tests can still reset their own state.
		}
	}

	/**
	 * Find a provider entry by ID.
	 *
	 * @param array<int, array<string, mixed>> $providers Provider rows.
	 * @param string                          $provider_id Provider ID.
	 * @return array<string, mixed>|null
	 */
	private function find_provider( array $providers, string $provider_id ): ?array {
		foreach ( $providers as $provider ) {
			if ( $provider_id === $provider['id'] ) {
				return $provider;
			}
		}

		return null;
	}
}
