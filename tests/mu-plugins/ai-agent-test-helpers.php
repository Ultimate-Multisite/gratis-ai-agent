<?php
/**
 * MU-Plugin: Test helpers for AI Agent development.
 *
 * Loaded automatically by wp-env in the development environment.
 * Provides debugging aids and test fixtures.
 *
 * @package AiAgent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Enable error display in development.
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	ini_set( 'display_errors', '1' ); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed
}

/**
 * Late-loaded stub for wp_ai_client_prompt().
 *
 * WordPress 7.0+ provides wp_ai_client_prompt() natively. This stub
 * is a last-resort fallback for test environments where core may not
 * be fully loaded — it provides a no-op so E2E tests don't fatal.
 *
 * Runs at plugins_loaded priority 999 to avoid shadowing the real
 * implementation.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( function_exists( 'wp_ai_client_prompt' ) ) {
			return; // Real function available — nothing to do.
		}
		// Core did not provide it — define a no-op stub for tests.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		function wp_ai_client_prompt( $prompt = null ) {
			return new class() {
				/** @return static */
				public function __call( string $name, array $args ): static {
					return $this;
				}
			};
		}
	},
	999
);

/**
 * Register a deterministic AI Client SDK provider for Playwright E2E tests.
 *
 * The admin chat UI intentionally renders the ConnectorGate when the
 * /sd-ai-agent/v1/providers endpoint returns no authenticated SDK providers.
 * CI does not install third-party ai-provider-for-* plugins, so the workflow
 * opts into this provider with the sd_ai_agent_e2e_register_provider option.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! (bool) get_option( 'sd_ai_agent_e2e_register_provider', false ) ) {
			return;
		}

		if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			return;
		}

		$required_classes = array(
			'\WordPress\AiClient\Providers\Contracts\ProviderInterface',
			'\WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface',
			'\WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface',
			'\WordPress\AiClient\Providers\DTO\ProviderMetadata',
			'\WordPress\AiClient\Providers\Enums\ProviderTypeEnum',
			'\WordPress\AiClient\Providers\Models\Contracts\ModelInterface',
			'\WordPress\AiClient\Providers\Models\DTO\ModelConfig',
			'\WordPress\AiClient\Providers\Models\DTO\ModelMetadata',
			'\WordPress\AiClient\Providers\Models\Enums\CapabilityEnum',
			'\WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication',
		);

		foreach ( $required_classes as $required_class ) {
			if ( ! class_exists( $required_class ) && ! interface_exists( $required_class ) ) {
				return;
			}
		}

		if ( ! class_exists( 'SdAiAgentE2EProvider', false ) ) {
			class SdAiAgentE2EModelMetadataDirectory implements \WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface {
				/**
				 * @return list<\WordPress\AiClient\Providers\Models\DTO\ModelMetadata>
				 */
				public function listModelMetadata(): array {
					return array( $this->getModelMetadata( 'e2e-model' ) );
				}

				public function hasModelMetadata( string $modelId ): bool {
					return 'e2e-model' === $modelId;
				}

				public function getModelMetadata( string $modelId ): \WordPress\AiClient\Providers\Models\DTO\ModelMetadata {
					if ( 'e2e-model' !== $modelId ) {
						throw new \WordPress\AiClient\Common\Exception\InvalidArgumentException( 'Unknown E2E model.' );
					}

					return new \WordPress\AiClient\Providers\Models\DTO\ModelMetadata(
						'e2e-model',
						'E2E Model',
						array( \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration() ),
						array()
					);
				}
			}

			class SdAiAgentE2EProvider implements \WordPress\AiClient\Providers\Contracts\ProviderInterface {
				public static function metadata(): \WordPress\AiClient\Providers\DTO\ProviderMetadata {
					return new \WordPress\AiClient\Providers\DTO\ProviderMetadata(
						'e2e-provider',
						'E2E Provider',
						\WordPress\AiClient\Providers\Enums\ProviderTypeEnum::cloud()
					);
				}

				public static function model( string $modelId, ?\WordPress\AiClient\Providers\Models\DTO\ModelConfig $modelConfig = null ): \WordPress\AiClient\Providers\Models\Contracts\ModelInterface {
					$metadata = self::modelMetadataDirectory()->getModelMetadata( $modelId );
					$config   = $modelConfig ?? new \WordPress\AiClient\Providers\Models\DTO\ModelConfig();

					return new class( $metadata, $config ) implements \WordPress\AiClient\Providers\Models\Contracts\ModelInterface {
						private \WordPress\AiClient\Providers\Models\DTO\ModelMetadata $metadata;
						private \WordPress\AiClient\Providers\Models\DTO\ModelConfig $config;

						public function __construct( \WordPress\AiClient\Providers\Models\DTO\ModelMetadata $metadata, \WordPress\AiClient\Providers\Models\DTO\ModelConfig $config ) {
							$this->metadata = $metadata;
							$this->config   = $config;
						}

						public function metadata(): \WordPress\AiClient\Providers\Models\DTO\ModelMetadata {
							return $this->metadata;
						}

						public function providerMetadata(): \WordPress\AiClient\Providers\DTO\ProviderMetadata {
							return SdAiAgentE2EProvider::metadata();
						}

						public function setConfig( \WordPress\AiClient\Providers\Models\DTO\ModelConfig $config ): void {
							$this->config = $config;
						}

						public function getConfig(): \WordPress\AiClient\Providers\Models\DTO\ModelConfig {
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
					return new SdAiAgentE2EModelMetadataDirectory();
				}
			}
		}

		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			if ( ! $registry->hasProvider( 'e2e-provider' ) ) {
				$registry->registerProvider( 'SdAiAgentE2EProvider' );
			}
			$registry->setProviderRequestAuthentication(
				'e2e-provider',
				new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication( 'e2e-test-key' )
			);
		} catch ( \Throwable $e ) {
			// If the SDK changes shape on trunk, fail closed and let E2E surface it.
			return;
		}
	},
	1
);
