<?php

declare(strict_types=1);
/**
 * REST API controller for Connectors provider status.
 *
 * WordPress 7.0+'s native Connectors page at options-connectors.php handles
 * provider API key management. This controller only reports provider/plugin
 * status for the plugin UI and never reads or writes raw connector credentials.
 *
 * @package SdAiAgent\REST
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\REST;

use WP_REST_Response;
use WP_REST_Server;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;
use SdAiAgent\Admin\UnifiedAdminMenu;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the provider-status endpoint used by the Connectors UI.
 *
 * Plugin install, activation, and credential management are handled by native
 * WordPress admin/REST surfaces.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_REST,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class ConnectorsController {

	use PermissionTrait;

	/**
	 * Known AI provider connectors with their plugin metadata.
	 *
	 * @var array<string, array{name: string, plugin_file: string, plugin_slug: string, description: string}>
	 */
	const PROVIDERS = array(
		'openai'    => array(
			'name'        => 'OpenAI',
			'plugin_file' => 'ai-provider-for-openai/ai-provider-for-openai.php',
			'plugin_slug' => 'ai-provider-for-openai',
			'description' => 'GPT-4.1, o3, o4-mini, and other OpenAI models.',
		),
		'anthropic' => array(
			'name'        => 'Anthropic',
			'plugin_file' => 'ai-provider-for-anthropic/ai-provider-for-anthropic.php',
			'plugin_slug' => 'ai-provider-for-anthropic',
			'description' => 'Claude Opus, Sonnet, and Haiku models.',
		),
		'google'    => array(
			'name'        => 'Google AI',
			'plugin_file' => 'ai-provider-for-google/ai-provider-for-google.php',
			'plugin_slug' => 'ai-provider-for-google',
			'description' => 'Gemini 2.5 Pro, Flash, and other Google models.',
		),
	);

	/**
	 * Register REST routes.
	 */
	#[Action( tag: 'rest_api_init', priority: 10 )]
	public function register_routes(): void {
		// List all providers with status.
		register_rest_route(
			RestController::NAMESPACE,
			'/connectors',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
			)
		);
	}

	/**
	 * GET /sd-ai-agent/v1/connectors
	 *
	 * Returns all known AI providers with their plugin install/activation status
	 * and whether an API key is configured.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_list(): WP_REST_Response {
		$this->maybe_load_plugin_functions();

		$providers = array();
		foreach ( self::PROVIDERS as $provider_id => $meta ) {
			$providers[] = $this->build_provider_data( $provider_id, $meta );
		}

		return new WP_REST_Response(
			array(
				'providers'     => $providers,
				'wp_has_native' => UnifiedAdminMenu::hasNativeConnectorsPage(),
			),
			200
		);
	}

	/**
	 * Build the provider data array for the REST response.
	 *
	 * @param string                                                                                                 $provider_id Provider ID.
	 * @param array{name: string, plugin_file: string, plugin_slug: string, option_key: string, description: string} $meta Provider metadata.
	 * @return array<string, mixed>
	 */
	private function build_provider_data( string $provider_id, array $meta ): array {
		$installed        = $this->is_plugin_installed( $meta['plugin_file'] );
		$active           = $this->is_plugin_active( $meta['plugin_file'] );
		$credential_state = $this->get_connector_credential_state( $provider_id );

		return array(
			'id'          => $provider_id,
			'name'        => $meta['name'],
			'description' => $meta['description'],
			'plugin_file' => $meta['plugin_file'],
			'plugin_slug' => $meta['plugin_slug'],
			'installed'   => $installed,
			'active'      => $active,
			'configured'  => $credential_state['configured'],
			'masked_key'  => $credential_state['masked_key'],
		);
	}

	/**
	 * Return safe credential state for a connector provider without reading raw keys.
	 *
	 * WordPress 7 stores AI provider credentials behind the Connectors API. This
	 * controller avoids both direct option reads and private `_wp_connectors_*()`
	 * helpers; it asks the public AI Client registry whether the provider has a
	 * configured text-generation model. The raw key is never read, so no masked
	 * preview is returned.
	 *
	 * @param string $provider_id Provider ID.
	 * @return array{configured: bool, masked_key: string}
	 */
	private function get_connector_credential_state( string $provider_id ): array {
		return array(
			'configured' => $this->provider_has_configured_text_model( $provider_id ),
			'masked_key' => '',
		);
	}

	/**
	 * Check whether a provider has a configured text-generation model.
	 *
	 * Uses public AI Client SDK APIs rather than reading `connectors_ai_*_api_key`
	 * options or calling private WordPress Connectors helpers.
	 *
	 * @param string $provider_id Provider ID.
	 * @return bool True when the provider can supply at least one text model.
	 */
	private function provider_has_configured_text_model( string $provider_id ): bool {
		if ( ! class_exists( AiClient::class ) || ! class_exists( CapabilityEnum::class ) ) {
			return false;
		}

		try {
			$registry = AiClient::defaultRegistry();
			if ( ! $registry->hasProvider( $provider_id ) ) {
				return false;
			}

			if ( null === $registry->getProviderRequestAuthentication( $provider_id ) ) {
				return false;
			}

			$provider_class = $registry->getProviderClassName( $provider_id );
			$model_metadata = $provider_class::modelMetadataDirectory()->listModelMetadata();

			foreach ( $model_metadata as $model_meta ) {
				foreach ( $model_meta->getSupportedCapabilities() as $capability ) {
					if ( $capability->equals( CapabilityEnum::TEXT_GENERATION ) ) {
						return true;
					}
				}
			}

			return false;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Check whether a plugin is installed (present in the plugins directory).
	 *
	 * @param string $plugin_file Relative plugin file path (folder/file.php).
	 * @return bool
	 */
	private function is_plugin_installed( string $plugin_file ): bool {
		$plugins = get_plugins();
		return array_key_exists( $plugin_file, $plugins );
	}

	/**
	 * Check whether a plugin is active.
	 *
	 * @param string $plugin_file Relative plugin file path (folder/file.php).
	 * @return bool
	 */
	private function is_plugin_active( string $plugin_file ): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			return false;
		}
		return is_plugin_active( $plugin_file );
	}

	/**
	 * Load plugin-related functions if not already available.
	 *
	 * Get_plugins() and is_plugin_active() require plugin.php to be loaded,
	 * which happens automatically on admin pages but not on REST requests.
	 */
	private function maybe_load_plugin_functions(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}
}
