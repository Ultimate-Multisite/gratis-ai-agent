<?php

declare(strict_types=1);
/**
 * REST API controller for the polyfill Connectors admin page (WP 6.9 compat).
 *
 * On WordPress 7.0+, the native Connectors page at options-connectors.php
 * handles provider API key management. On WordPress 6.9, this controller
 * provides the same functionality so the Superdav AI Agent Connectors page can
 * read/write provider credentials and check plugin install/activation status.
 *
 * Credential option names mirror WP 7.0's Connectors API:
 *   connectors_ai_{provider_id}_api_key
 *
 * This ensures zero-migration when users upgrade from 6.9 to 7.0 — the same
 * option values are read by the native Connectors API.
 *
 * @package SdAiAgent\REST
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\REST;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;
use SdAiAgent\Admin\UnifiedAdminMenu;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides REST endpoints for the polyfill Connectors admin page.
 *
 * Endpoints:
 *   GET  /sd-ai-agent/v1/connectors           — list all providers with status
 *   POST /sd-ai-agent/v1/connectors/{id}/key  — set an API key
 *   DELETE /sd-ai-agent/v1/connectors/{id}/key — clear an API key
 *
 * Plugin install and activation are handled client-side via the native
 * /wp/v2/plugins REST endpoint.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_REST,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class ConnectorsController {

	use PermissionTrait;

	/**
	 * Known AI provider connectors with their plugin and option metadata.
	 *
	 * Option_key mirrors WP 7.0's Connectors API naming convention
	 * (connectors_ai_{provider}_api_key) for zero-migration on upgrade.
	 *
	 * @var array<string, array{name: string, plugin_file: string, plugin_slug: string, option_key: string, description: string}>
	 */
	const PROVIDERS = array(
		'openai'    => array(
			'name'        => 'OpenAI',
			'plugin_file' => 'ai-provider-for-openai/ai-provider-for-openai.php',
			'plugin_slug' => 'ai-provider-for-openai',
			'option_key'  => 'connectors_ai_openai_api_key',
			'description' => 'GPT-4.1, o3, o4-mini, and other OpenAI models.',
		),
		'anthropic' => array(
			'name'        => 'Anthropic',
			'plugin_file' => 'ai-provider-for-anthropic/ai-provider-for-anthropic.php',
			'plugin_slug' => 'ai-provider-for-anthropic',
			'option_key'  => 'connectors_ai_anthropic_api_key',
			'description' => 'Claude Opus, Sonnet, and Haiku models.',
		),
		'google'    => array(
			'name'        => 'Google AI',
			'plugin_file' => 'ai-provider-for-google/ai-provider-for-google.php',
			'plugin_slug' => 'ai-provider-for-google',
			'option_key'  => 'connectors_ai_google_api_key',
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

		// Set API key for a specific provider.
		register_rest_route(
			RestController::NAMESPACE,
			'/connectors/(?P<provider>[a-z0-9_-]+)/key',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_set_key' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => array(
						'provider' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'api_key'  => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'handle_clear_key' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => array(
						'provider' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
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
	 * POST /sd-ai-agent/v1/connectors/{provider}/key
	 *
	 * Stores the API key in the connectors_ai_{provider}_api_key option.
	 * Empty string clears the key.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_set_key( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$provider_id = (string) $request->get_param( 'provider' );
		$api_key     = (string) $request->get_param( 'api_key' );

		if ( ! array_key_exists( $provider_id, self::PROVIDERS ) ) {
			return new WP_Error(
				'invalid_provider',
				__( 'Unknown provider ID.', 'superdav-ai-agent' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $api_key ) {
			return new WP_Error(
				'empty_api_key',
				__( 'API key cannot be empty. Use DELETE to clear.', 'superdav-ai-agent' ),
				array( 'status' => 400 )
			);
		}

		$option_key = self::PROVIDERS[ $provider_id ]['option_key'];
		update_option( $option_key, $api_key, false );

		return new WP_REST_Response(
			array(
				'success'    => true,
				'provider'   => $provider_id,
				'configured' => true,
			),
			200
		);
	}

	/**
	 * DELETE /sd-ai-agent/v1/connectors/{provider}/key
	 *
	 * Clears the stored API key for the given provider.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_clear_key( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$provider_id = (string) $request->get_param( 'provider' );

		if ( ! array_key_exists( $provider_id, self::PROVIDERS ) ) {
			return new WP_Error(
				'invalid_provider',
				__( 'Unknown provider ID.', 'superdav-ai-agent' ),
				array( 'status' => 400 )
			);
		}

		$option_key = self::PROVIDERS[ $provider_id ]['option_key'];
		delete_option( $option_key );

		return new WP_REST_Response(
			array(
				'success'    => true,
				'provider'   => $provider_id,
				'configured' => false,
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
		$installed  = $this->is_plugin_installed( $meta['plugin_file'] );
		$active     = $this->is_plugin_active( $meta['plugin_file'] );
		$api_key    = (string) get_option( $meta['option_key'], '' );
		$configured = '' !== $api_key;

		// Build the masked key preview (last 4 chars only).
		$masked_key = '';
		if ( $configured ) {
			$len        = strlen( $api_key );
			$masked_key = str_repeat( '•', max( 0, $len - 4 ) ) . substr( $api_key, -4 );
		}

		return array(
			'id'          => $provider_id,
			'name'        => $meta['name'],
			'description' => $meta['description'],
			'plugin_file' => $meta['plugin_file'],
			'plugin_slug' => $meta['plugin_slug'],
			'installed'   => $installed,
			'active'      => $active,
			'configured'  => $configured,
			'masked_key'  => $masked_key,
		);
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
