<?php

declare(strict_types=1);
/**
 * REST API controller for settings, providers, budget, usage, roles/permissions,
 * WooCommerce status, and alerts.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\REST;

use SdAiAgent\Abilities\GoogleAnalyticsAbilities;
use SdAiAgent\Abilities\InternetSearchAbilities;
use SdAiAgent\Admin\UnifiedAdminMenu;
use SdAiAgent\Core\AgentLoop;
use SdAiAgent\Core\BudgetManager;
use SdAiAgent\Core\Database;
use SdAiAgent\Core\Features;
use SdAiAgent\Core\ProviderCredentialLoader;
use SdAiAgent\Core\RolePermissions;
use SdAiAgent\Core\Settings;
use SdAiAgent\Core\SystemInstructionBuilder;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages settings, providers, budget, usage, roles, and alerts via REST.
 *
 * Uses #[Handler] + #[Action] because this controller serves multiple
 * basenames (/settings, /providers, /budget, /usage, /role-permissions, etc.).
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_REST,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class SettingsController {

	use PermissionTrait;

	/** @var Settings Injected settings dependency. */
	private Settings $settings;

	/** @var Database Injected database dependency. */
	private Database $database;

	/**
	 * Constructor — receives injected dependencies from the DI container.
	 *
	 * @param Settings $settings  Injected Settings service.
	 * @param Database $database  Injected Database service.
	 */
	public function __construct( Settings $settings, Database $database ) {
		$this->settings = $settings;
		$this->database = $database;
	}

	/**
	 * Register REST routes.
	 */
	#[Action( tag: 'rest_api_init', priority: 10 )]
	public function register_routes(): void {

		// Providers endpoint.
		register_rest_route(
			RestController::NAMESPACE,
			'/providers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_providers' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Alerts endpoint.
		register_rest_route(
			RestController::NAMESPACE,
			'/alerts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_alerts' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// WooCommerce store status endpoint.
		register_rest_route(
			RestController::NAMESPACE,
			'/woocommerce/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_woocommerce_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Settings endpoints.
		register_rest_route(
			RestController::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_get_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_update_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Role permissions endpoints — only registered when access control feature is enabled.
		if ( Features::is_enabled( Features::ACCESS_CONTROL ) ) {
			register_rest_route(
				RestController::NAMESPACE,
				'/role-permissions',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'handle_get_role_permissions' ),
						'permission_callback' => array( $this, 'check_permission' ),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'handle_update_role_permissions' ),
						'permission_callback' => array( $this, 'check_permission' ),
						'args'                => array(
							'permissions' => array(
								'required' => true,
								'type'     => 'object',
							),
						),
					),
				)
			);

			// Role permissions — available roles list.
			register_rest_route(
				RestController::NAMESPACE,
				'/role-permissions/roles',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'handle_get_roles' ),
						'permission_callback' => array( $this, 'check_permission' ),
					),
				)
			);
		}

		// Google Search Console credentials endpoint.
		register_rest_route(
			RestController::NAMESPACE,
			'/settings/gsc-credentials',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_set_gsc_credentials' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'handle_delete_gsc_credentials' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				),
			)
		);

		// Brave Search API key endpoint.
		register_rest_route(
			RestController::NAMESPACE,
			'/settings/brave-search-key',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_set_brave_search_key' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => array(
						'api_key' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'handle_delete_brave_search_key' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				),
			)
		);

		// Tavily API key endpoint.
		register_rest_route(
			RestController::NAMESPACE,
			'/settings/tavily-api-key',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_set_tavily_api_key' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => array(
						'api_key' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'handle_delete_tavily_api_key' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				),
			)
		);

		// Google Analytics credentials endpoint.
		register_rest_route(
			RestController::NAMESPACE,
			'/settings/google-analytics',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_get_ga_credentials' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_set_ga_credentials' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => array(
						'property_id'          => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'service_account_json' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'handle_clear_ga_credentials' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				),
			)
		);

		// Google Search Console credentials endpoint.
		register_rest_route(
			RestController::NAMESPACE,
			'/settings/gsc-credentials',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_set_gsc_credentials' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'handle_delete_gsc_credentials' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				),
			)
		);

		// Usage endpoint.
		register_rest_route(
			RestController::NAMESPACE,
			'/usage',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_usage' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'period'     => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'start_date' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'end_date'   => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Budget status endpoint.
		register_rest_route(
			RestController::NAMESPACE,
			'/budget',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_budget' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Handle GET /settings.
	 */
	public function handle_get_settings(): WP_REST_Response {
		$settings = $this->settings->get();

		// Include built-in defaults so the UI can show them as placeholders.
		// @phpstan-ignore-next-line
		$settings['_defaults'] = array(
			'system_prompt'    => SystemInstructionBuilder::default_system_instruction(),
			'greeting_message' => __( 'Send a message to start a conversation.', 'superdav-ai-agent' ),
		);

		// Indicate whether GSC credentials are configured (boolean + type only, no credential values).
		$gsc_creds = $this->settings->get_gsc_credentials();
		// @phpstan-ignore-next-line
		$settings['_gsc_credentials'] = array(
			'configured'       => $this->settings->has_gsc_credentials(),
			'type'             => $gsc_creds['type'] ?? null,
			'default_site_url' => $gsc_creds['default_site_url'] ?? null,
		);

		// Indicate whether search provider API keys are configured (boolean only, no key values).
		// @phpstan-ignore-next-line
		$settings['_brave_search_key_configured'] = '' !== InternetSearchAbilities::get_brave_api_key();
		// @phpstan-ignore-next-line
		$settings['_tavily_api_key_configured'] = '' !== InternetSearchAbilities::get_tavily_api_key();

		// Indicate whether a feedback-report receiver API key is configured (boolean only, no key value — t180).
		// @phpstan-ignore-next-line
		$settings['_feedback_api_key_configured'] = true;

		// Expose active feature flags so the JS UI can gate sections consistently.
		// @phpstan-ignore-next-line
		$settings['_features'] = Features::all();

		return new WP_REST_Response( $settings, 200 );
	}

	/**
	 * Handle POST /settings — partial update.
	 *
	 * @param WP_REST_Request $request The request object.
	 */
	public function handle_update_settings( WP_REST_Request $request ): WP_REST_Response {
		$data = $request->get_json_params();

		if ( empty( $data ) || ! is_array( $data ) ) {
			return new WP_REST_Response( array( 'error' => 'No data provided.' ), 400 );
		}

		// @phpstan-ignore-next-line
		$this->settings->update( $data );

		return new WP_REST_Response( $this->settings->get(), 200 );
	}

	/**
	 * Handle GET /role-permissions — return current role permissions config.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_get_role_permissions(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'permissions'    => RolePermissions::get(),
				'always_allowed' => RolePermissions::ALWAYS_ALLOWED_ROLES,
			),
			200
		);
	}

	/**
	 * Handle POST /role-permissions — update role permissions config.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_update_role_permissions( WP_REST_Request $request ) {
		$permissions = $request->get_param( 'permissions' );

		if ( ! is_array( $permissions ) ) {
			return new WP_Error(
				'invalid_permissions',
				__( 'Invalid permissions data.', 'superdav-ai-agent' ),
				array( 'status' => 400 )
			);
		}

		// @phpstan-ignore-next-line
		$success = RolePermissions::update( $permissions );

		if ( ! $success ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to save role permissions.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'permissions'    => RolePermissions::get(),
				'always_allowed' => RolePermissions::ALWAYS_ALLOWED_ROLES,
			),
			200
		);
	}

	/**
	 * Handle GET /role-permissions/roles — return all registered WordPress roles.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_get_roles(): WP_REST_Response {
		return new WP_REST_Response( RolePermissions::get_all_roles(), 200 );
	}

	/**
	 * Handle GET /settings/google-analytics — return whether credentials are configured.
	 */
	public function handle_get_ga_credentials(): WP_REST_Response {
		$creds = GoogleAnalyticsAbilities::get_credentials();
		return new WP_REST_Response(
			array(
				'has_credentials' => '' !== $creds['property_id'] && '' !== $creds['service_account_json'],
				'has_property_id' => '' !== $creds['property_id'],
				'property_id'     => $creds['property_id'],
				'has_service_key' => '' !== $creds['service_account_json'],
			),
			200
		);
	}

	/**
	 * Handle POST /settings/google-analytics — save GA4 credentials.
	 *
	 * @param WP_REST_Request $request The request object.
	 */
	public function handle_set_ga_credentials( WP_REST_Request $request ): WP_REST_Response {
		// @phpstan-ignore-next-line
		$property_id = (string) $request->get_param( 'property_id' );
		// @phpstan-ignore-next-line
		$service_account_json = (string) $request->get_param( 'service_account_json' );

		// Validate property ID format (numeric string).
		$property_id = preg_replace( '/[^0-9]/', '', $property_id );
		if ( empty( $property_id ) ) {
			return new WP_REST_Response( array( 'error' => __( 'property_id must be a numeric GA4 property ID.', 'superdav-ai-agent' ) ), 400 );
		}

		// Validate service account JSON structure.
		$sa = json_decode( $service_account_json, true );
		if ( ! is_array( $sa ) || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
			return new WP_REST_Response(
				array( 'error' => __( 'service_account_json must be a valid Google service account JSON key containing client_email and private_key.', 'superdav-ai-agent' ) ),
				400
			);
		}

		$success = GoogleAnalyticsAbilities::set_credentials( $property_id, $service_account_json );
		if ( ! $success ) {
			return new WP_REST_Response( array( 'error' => __( 'Failed to save Google Analytics credentials.', 'superdav-ai-agent' ) ), 500 );
		}

		return new WP_REST_Response(
			array(
				'saved'           => true,
				'property_id'     => $property_id,
				'has_service_key' => true,
			),
			200
		);
	}

	/**
	 * Handle DELETE /settings/google-analytics — clear GA4 credentials.
	 */
	public function handle_clear_ga_credentials(): WP_REST_Response {
		GoogleAnalyticsAbilities::clear_credentials();
		return new WP_REST_Response( array( 'cleared' => true ), 200 );
	}

	/**
	 * Handle POST /settings/gsc-credentials — save Google Search Console credentials.
	 *
	 * @param WP_REST_Request $request The request object.
	 */
	public function handle_set_gsc_credentials( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();

		if ( empty( $params ) || ! is_array( $params ) ) {
			return new WP_REST_Response( array( 'error' => 'No data provided.' ), 400 );
		}

		// @phpstan-ignore-next-line
		$type         = sanitize_text_field( $params['type'] ?? '' );
		$default_site = esc_url_raw( $params['default_site_url'] ?? '' );

		if ( 'service_account' === $type ) {
			$credentials_json = $params['credentials_json'] ?? '';

			// Accept either a JSON string or a pre-decoded object/array.
			if ( is_string( $credentials_json ) ) {
				$decoded = json_decode( $credentials_json, true );
			} else {
				$decoded = $credentials_json;
			}

			if ( ! is_array( $decoded ) ) {
				return new WP_REST_Response( array( 'error' => 'Invalid service account JSON.' ), 400 );
			}

			$required = array( 'client_email', 'private_key' );
			foreach ( $required as $field ) {
				if ( empty( $decoded[ $field ] ) ) {
					return new WP_REST_Response(
						/* translators: %s: field name */
						array( 'error' => sprintf( 'Missing required field: %s', $field ) ),
						400
					);
				}
			}

			$creds = array(
				'type'             => 'service_account',
				'client_email'     => sanitize_email( $decoded['client_email'] ),
				'private_key'      => $decoded['private_key'],
				'default_site_url' => $default_site,
			);

		} elseif ( 'access_token' === $type ) {
			$access_token = sanitize_text_field( $params['access_token'] ?? '' );

			if ( empty( $access_token ) ) {
				return new WP_REST_Response( array( 'error' => 'access_token is required.' ), 400 );
			}

			$creds = array(
				'type'             => 'access_token',
				'access_token'     => $access_token,
				'default_site_url' => $default_site,
			);

		} else {
			return new WP_REST_Response(
				array( 'error' => 'type must be "service_account" or "access_token".' ),
				400
			);
		}

		$success = $this->settings->set_gsc_credentials( $creds );

		if ( ! $success ) {
			return new WP_REST_Response( array( 'error' => 'Failed to save GSC credentials.' ), 500 );
		}

		return new WP_REST_Response(
			array(
				'saved'            => true,
				'type'             => $creds['type'],
				'has_credentials'  => true,
				'default_site_url' => $creds['default_site_url'],
			),
			200
		);
	}

	/**
	 * Handle DELETE /settings/gsc-credentials — remove Google Search Console credentials.
	 *
	 * @param WP_REST_Request $request The request object.
	 */
	public function handle_delete_gsc_credentials( WP_REST_Request $request ): WP_REST_Response {
		$this->settings->set_gsc_credentials( array() );

		return new WP_REST_Response(
			array(
				'deleted'         => true,
				'has_credentials' => false,
			),
			200
		);
	}

	/**
	 * Handle POST /settings/brave-search-key — save the Brave Search API key.
	 *
	 * @param WP_REST_Request $request The request object.
	 */
	public function handle_set_brave_search_key( WP_REST_Request $request ): WP_REST_Response {
		// @phpstan-ignore-next-line
		$api_key = sanitize_text_field( (string) $request->get_param( 'api_key' ) );

		if ( '' === $api_key ) {
			return new WP_REST_Response( array( 'error' => 'api_key is required.' ), 400 );
		}

		$success = InternetSearchAbilities::set_brave_api_key( $api_key );

		if ( ! $success ) {
			return new WP_REST_Response( array( 'error' => 'Failed to save Brave Search API key.' ), 500 );
		}

		return new WP_REST_Response(
			array(
				'saved'      => true,
				'configured' => true,
			),
			200
		);
	}

	/**
	 * Handle DELETE /settings/brave-search-key — remove the Brave Search API key.
	 *
	 * @param WP_REST_Request $request The request object.
	 */
	public function handle_delete_brave_search_key( WP_REST_Request $request ): WP_REST_Response {
		InternetSearchAbilities::set_brave_api_key( '' );

		return new WP_REST_Response(
			array(
				'deleted'    => true,
				'configured' => false,
			),
			200
		);
	}

	/**
	 * Handle POST /settings/tavily-api-key — save the Tavily API key.
	 *
	 * @param WP_REST_Request $request The request object.
	 */
	public function handle_set_tavily_api_key( WP_REST_Request $request ): WP_REST_Response {
		// @phpstan-ignore-next-line
		$api_key = sanitize_text_field( (string) $request->get_param( 'api_key' ) );

		if ( '' === $api_key ) {
			return new WP_REST_Response( array( 'error' => 'api_key is required.' ), 400 );
		}

		$success = InternetSearchAbilities::set_tavily_api_key( $api_key );

		if ( ! $success ) {
			return new WP_REST_Response( array( 'error' => 'Failed to save Tavily API key.' ), 500 );
		}

		return new WP_REST_Response(
			array(
				'saved'      => true,
				'configured' => true,
			),
			200
		);
	}

	/**
	 * Handle DELETE /settings/tavily-api-key — remove the Tavily API key.
	 *
	 * @param WP_REST_Request $request The request object.
	 */
	public function handle_delete_tavily_api_key( WP_REST_Request $request ): WP_REST_Response {
		InternetSearchAbilities::set_tavily_api_key( '' );

		return new WP_REST_Response(
			array(
				'deleted'    => true,
				'configured' => false,
			),
			200
		);
	}

	/**
	 * Handle the /providers endpoint — list registered AI providers and models.
	 *
	 * No caching layer is needed here: the underlying WP AI Client SDK already
	 * caches `listModelMetadata()` results for 24 hours via
	 * `AbstractApiBasedModelMetadataDirectory::getModelMetadataMap()`. Adding a
	 * second cache on top forced us to invent invalidation rules per provider
	 * option key, which broke whenever a new third-party provider plugin
	 * (e.g. `ai-provider-for-anthropic-max`) stored credentials under an
	 * option we did not know about. Dropping the layer keeps `/providers`
	 * fresh by construction and removes the entire stale-cache class of bugs.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_providers(): WP_REST_Response {
		$providers = array();

		// Built-in providers (OpenAI, Anthropic, Google) — listed first when a
		// WP 7.0 Connectors API key is set, so sites without a third-party
		// AI provider plugin still have a usable provider/model list.
		foreach ( Settings::DIRECT_PROVIDERS as $provider_id => $meta ) {
			$key = $this->settings->get_connectors_api_key( $provider_id );
			if ( '' === $key ) {
				continue;
			}
			$providers[] = array(
				'id'         => $provider_id,
				'name'       => $meta['name'],
				'type'       => 'direct',
				'configured' => true,
				'models'     => $meta['models'],
			);
		}

		// Collect IDs already added to avoid duplicates from the WP SDK registry.
		$added_ids = array_column( $providers, 'id' );

		// WP SDK providers (AI Experiments plugin, OpenAI-compatible connector, etc.).
		if ( class_exists( '\\WordPress\\AiClient\\AiClient' ) ) {
			$registry     = null;
			$provider_ids = array();
			try {
				$registry     = \WordPress\AiClient\AiClient::defaultRegistry();
				$provider_ids = $registry->getRegisteredProviderIds();
			} catch ( \Throwable $e ) {
				$provider_ids = array();
			}

			// Ensure credentials are loaded
			ProviderCredentialLoader::load();

			foreach ( $provider_ids as $provider_id ) {
				// Skip if already added as a direct provider.
				if ( in_array( $provider_id, $added_ids, true ) ) {
					continue;
				}

				if ( null === $registry ) {
					continue;
				}

				try {
					$class = $registry->getProviderClassName( $provider_id );

					// Only include providers that have authentication set.
					$auth = $registry->getProviderRequestAuthentication( $provider_id );
					if ( null === $auth ) {
						continue;
					}

					$metadata = $class::metadata();
					$models   = array();

					// For the OpenAI-compatible connector, fetch models directly
					// from the endpoint rather than going through the SDK model
					// directory (which can fail due to SDK transporter issues).
					// Use str_starts_with to handle multi-endpoint setups where each
					// endpoint gets a unique ID (e.g., ai-provider-for-any-openai-compatible-1).
					//
					// Pass the SDK provider_id so the connector can resolve the
					// correct per-provider endpoint URL and API key. Without
					// provider_id, the connector falls back to its primary
					// configured provider and every OpenAI-compatible provider
					// would return the same model list — see PR #127 on
					// ai-provider-for-any-compatible-endpoint.
					if ( str_starts_with( $provider_id, 'ai-provider-for-any-openai-compatible' )
						&& function_exists( 'OpenAiCompatibleConnector\\rest_list_models' )
					) {
						$fake_request = new WP_REST_Request( 'GET' );
						$fake_request->set_param( 'provider_id', $provider_id );
						$result = \OpenAiCompatibleConnector\rest_list_models( $fake_request );
						if ( ! is_wp_error( $result ) ) {
							$data = $result->get_data();
							if ( is_array( $data ) ) {
								$models = $data;
							}
						}
					} else {
						try {
							$directory      = $class::modelMetadataDirectory();
							$model_metadata = $directory->listModelMetadata();

							foreach ( $model_metadata as $model_meta ) {
								$model_id = $model_meta->getId();
								$models[] = array(
									'id'             => $model_id,
									'name'           => $model_meta->getName(),
									'context_window' => Settings::MODEL_CONTEXT_WINDOWS[ $model_id ] ?? 128000,
								);
							}
						} catch ( \Throwable $e ) {
							// Model listing failed — still include the provider.
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

		return new WP_REST_Response( $providers, 200 );
	}

	/**
	 * Handle GET /woocommerce/status — detect WooCommerce and return store info.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_woocommerce_status(): WP_REST_Response {
		$active = class_exists( 'WooCommerce' );

		if ( ! $active ) {
			return new WP_REST_Response(
				array(
					'active'  => false,
					'version' => null,
				),
				200
			);
		}

		// Product counts.
		$product_counts     = wp_count_posts( 'product' );
		$published_products = $product_counts ? (int) ( $product_counts->publish ?? 0 ) : 0;
		$total_products     = 0;
		if ( $product_counts ) {
			foreach ( (array) $product_counts as $count ) {
				$total_products += (int) $count;
			}
		}

		// Order counts.
		$pending_orders    = 0;
		$processing_orders = 0;
		if ( function_exists( 'wc_orders_count' ) ) {
			$pending_orders    = (int) wc_orders_count( 'pending' );
			$processing_orders = (int) wc_orders_count( 'processing' );
		}

		return new WP_REST_Response(
			array(
				'active'             => true,
				'version'            => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
				'currency'           => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
				'published_products' => $published_products,
				'total_products'     => $total_products,
				'pending_orders'     => $pending_orders,
				'processing_orders'  => $processing_orders,
				'shop_url'           => function_exists( 'wc_get_page_id' ) ? ( get_permalink( wc_get_page_id( 'shop' ) ) ?: '' ) : '',
			),
			200
		);
	}

	/**
	 * Handle GET /alerts — return proactive issues.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_alerts(): WP_REST_Response {
		$alerts = array();

		// Check whether at least one AI provider is configured.
		$has_provider = false;

		// Built-in providers (API key stored by the WP 7.0 Connectors API).
		foreach ( array_keys( Settings::DIRECT_PROVIDERS ) as $provider_id ) {
			if ( '' !== $this->settings->get_connectors_api_key( $provider_id ) ) {
				$has_provider = true;
				break;
			}
		}

		// WP SDK providers (AI Experiments plugin, OpenAI-compatible connector, etc.).
		if ( ! $has_provider && class_exists( '\\WordPress\\AiClient\\AiClient' ) ) {
			try {
				$registry     = \WordPress\AiClient\AiClient::defaultRegistry();
				$provider_ids = $registry->getRegisteredProviderIds();
				ProviderCredentialLoader::load();
				foreach ( $provider_ids as $provider_id ) {
					$auth = $registry->getProviderRequestAuthentication( $provider_id );
					if ( null !== $auth ) {
						$has_provider = true;
						break;
					}
				}
			} catch ( \Throwable $e ) {
				// Registry unavailable — treat as no provider.
			}
		}

		if ( ! $has_provider ) {
			$alerts[] = array(
				'type'           => 'no_provider',
				'message'        => __( 'No AI provider configured. Add an API key on the Connectors page to get started.', 'superdav-ai-agent' ),
				'connectors_url' => UnifiedAdminMenu::getConnectorsUrl(),
			);
		}

		$settings = $this->settings->get();

		return new WP_REST_Response(
			array(
				'count'               => count( $alerts ),
				'alerts'              => $alerts,
				// @phpstan-ignore-next-line
				'onboarding_complete' => ! empty( $settings['onboarding_complete'] ),
			),
			200
		);
	}

	/**
	 * Handle GET /usage — get usage summary.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function handle_get_usage( WP_REST_Request $request ): WP_REST_Response {
		$filters = array(
			'user_id' => get_current_user_id(),
		);

		if ( $request->has_param( 'period' ) ) {
			$filters['period'] = $request->get_param( 'period' );
		}
		if ( $request->has_param( 'start_date' ) ) {
			$filters['start_date'] = $request->get_param( 'start_date' );
		}
		if ( $request->has_param( 'end_date' ) ) {
			$filters['end_date'] = $request->get_param( 'end_date' );
		}

		$summary = $this->database->get_usage_summary( $filters );

		return new WP_REST_Response( $summary, 200 );
	}

	/**
	 * Handle GET /budget — return current budget status.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function handle_get_budget( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( BudgetManager::get_status() );
	}
}
