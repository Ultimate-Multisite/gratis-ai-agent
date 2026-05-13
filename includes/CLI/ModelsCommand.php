<?php

declare(strict_types=1);
/**
 * WP-CLI command: wp sd-ai-agent models
 *
 * Lists all configured AI providers and their available models.
 * Mirrors the logic of SettingsController::handle_providers() in-process
 * so the output reflects the same data the chat UI and benchmark runner see.
 *
 * @package SdAiAgent\CLI
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\CLI;

use SdAiAgent\Core\ProviderCredentialLoader;
use SdAiAgent\Core\Settings;
use WP_CLI;
use WP_CLI_Command;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List configured AI providers and their models.
 *
 * ## EXAMPLES
 *
 *   # List all providers
 *   wp sd-ai-agent models
 *
 *   # List models for a specific provider
 *   wp sd-ai-agent models --provider=ultimate-ai-connector-anthropic-max
 *
 *   # Machine-readable JSON
 *   wp sd-ai-agent models --format=json
 *   wp sd-ai-agent models --provider=openai --format=json
 */
class ModelsCommand extends WP_CLI_Command {

	/**
	 * List AI providers and models.
	 *
	 * Without --provider, prints one row per provider with model count.
	 * With --provider, prints one row per model for that provider.
	 *
	 * ## OPTIONS
	 *
	 * [--provider=<id>]
	 * : Provider ID. When set, lists every model for this provider.
	 *
	 * [--format=<format>]
	 * : Output format: table (default), json, csv, yaml, ids.
	 *
	 * ## EXAMPLES
	 *
	 *   wp sd-ai-agent models
	 *   wp sd-ai-agent models --provider=openai
	 *   wp sd-ai-agent models --format=json
	 *
	 * @param array<int, string>   $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		ProviderCredentialLoader::load();

		$providers = $this->fetch_providers();

		if ( empty( $providers ) ) {
			WP_CLI::warning( 'No configured providers found. Add API keys in Settings > AI Agent.' );
			return;
		}

		$filter_provider = (string) ( $assoc_args['provider'] ?? '' );
		$format          = (string) ( $assoc_args['format'] ?? 'table' );

		if ( '' !== $filter_provider ) {
			$this->show_models( $providers, $filter_provider, $format );
		} else {
			$this->show_providers( $providers, $format );
		}
	}

	/**
	 * Show one row per provider.
	 *
	 * @param list<array<string, mixed>> $providers All providers.
	 * @param string                     $format    Output format.
	 */
	private function show_providers( array $providers, string $format ): void {
		$rows = array_map(
			fn( $p ) => array(
				'id'     => $p['id'],
				'name'   => $p['name'],
				'type'   => $p['type'] ?? 'cloud',
				'models' => count( $p['models'] ?? array() ),
			),
			$providers
		);

		WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'name', 'type', 'models' ) );
	}

	/**
	 * Show one row per model for a given provider.
	 *
	 * @param list<array<string, mixed>> $providers   All providers.
	 * @param string                     $provider_id Provider to filter on.
	 * @param string                     $format      Output format.
	 */
	private function show_models( array $providers, string $provider_id, string $format ): void {
		$match = null;
		foreach ( $providers as $p ) {
			if ( $p['id'] === $provider_id ) {
				$match = $p;
				break;
			}
		}

		if ( null === $match ) {
			$available = implode( ', ', array_column( $providers, 'id' ) );
			WP_CLI::error( "Provider '{$provider_id}' not found. Available: {$available}" );
			return;
		}

		$models = $match['models'] ?? array();

		if ( empty( $models ) ) {
			WP_CLI::warning( "Provider '{$provider_id}' has no models listed (SDK will use its default)." );
			return;
		}

		$rows = array_map(
			fn( $m ) => array(
				'id'             => $m['id'],
				'name'           => $m['name'] ?? $m['id'],
				'context_window' => isset( $m['context_window'] ) ? number_format( (int) $m['context_window'] ) : '—',
			),
			$models
		);

		WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'name', 'context_window' ) );
	}

	/**
	 * Fetch configured providers and their model lists.
	 *
	 * Mirrors the logic of SettingsController::handle_providers() but runs
	 * entirely in-process without needing REST routes to be registered
	 * (REST handlers are not loaded in CLI context).
	 *
	 * @return list<array<string, mixed>>
	 */
	private function fetch_providers(): array {
		$settings  = new Settings();
		$providers = array();

		// Built-in providers (OpenAI, Anthropic, Google) — only include when a
		// WP 7.0 Connectors API key is set.
		foreach ( Settings::DIRECT_PROVIDERS as $provider_id => $meta ) {
			if ( '' === $settings->get_connectors_api_key( $provider_id ) ) {
				continue;
			}

			$providers[] = array(
				'id'         => $provider_id,
				'name'       => $meta['name'],
				'type'       => 'direct',
				'configured' => true,
				'models'     => $meta['models'] ?? array(),
			);
		}

		$added_ids = array_column( $providers, 'id' );

		// WP SDK providers (registered connectors, compatible endpoints, etc.).
		if ( class_exists( '\\WordPress\\AiClient\\AiClient' ) ) {
			$registry     = null;
			$provider_ids = array();

			try {
				$registry     = \WordPress\AiClient\AiClient::defaultRegistry();
				$provider_ids = $registry->getRegisteredProviderIds();
			} catch ( \Throwable $e ) {
				$provider_ids = array();
			}

			foreach ( $provider_ids as $provider_id ) {
				if ( in_array( $provider_id, $added_ids, true ) || null === $registry ) {
					continue;
				}

				try {
					$auth = $registry->getProviderRequestAuthentication( $provider_id );
					if ( null === $auth ) {
						continue;
					}

					$class    = $registry->getProviderClassName( $provider_id );
					$metadata = $class::metadata();
					$models   = array();

					// OpenAI-compatible connectors expose a model list endpoint.
					if ( str_starts_with( $provider_id, 'ai-provider-for-any-openai-compatible' )
						&& function_exists( 'OpenAiCompatibleConnector\\rest_list_models' )
					) {
						$result = \OpenAiCompatibleConnector\rest_list_models( new \WP_REST_Request( 'GET' ) );
						if ( ! is_wp_error( $result ) ) {
							$data = $result->get_data();
							if ( is_array( $data ) ) {
								$models = $data;
							}
						}
					} else {
						try {
							$directory = $class::modelMetadataDirectory();
							foreach ( $directory->listModelMetadata() as $model_meta ) {
								$model_id = $model_meta->getId();
								$models[] = array(
									'id'             => $model_id,
									'name'           => $model_meta->getName(),
									'context_window' => Settings::MODEL_CONTEXT_WINDOWS[ $model_id ] ?? 128000,
								);
							}
						} catch ( \Throwable $e ) {
							// Model listing failed — include provider without models.
						}
					}

					$providers[] = array(
						'id'         => $provider_id,
						'name'       => $metadata->getName(),
						'type'       => (string) $metadata->getType(),
						'configured' => true,
						'models'     => $models,
					);
				} catch ( \Throwable $e ) {
					continue;
				}
			}
		}

		return $providers;
	}
}
