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

use SdAiAgent\Abilities\CalendarReminderAbilities;
use SdAiAgent\Abilities\GoogleAnalyticsAbilities;
use SdAiAgent\Abilities\InternetSearchAbilities;
use SdAiAgent\Abilities\MessagingAbilities;
use SdAiAgent\Abilities\SmsAbilities;
use SdAiAgent\Admin\UnifiedAdminMenu;
use SdAiAgent\Core\AgentLoop;
use SdAiAgent\Core\AdvancedPluginManager;
use SdAiAgent\Core\BudgetManager;
use SdAiAgent\Core\Database;
use SdAiAgent\Core\Features;
use SdAiAgent\Core\ModelCapabilityRegistry;
use SdAiAgent\Core\ProviderCredentialLoader;
use SdAiAgent\Core\ProviderModelDiscovery;
use SdAiAgent\Core\RolePermissions;
use SdAiAgent\Core\Settings;
use SdAiAgent\Core\SuperdavSiteConnectionService;
use SdAiAgent\Core\SystemInstructionBuilder;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiProvider;
use SdAiAgent\Models\ContactMapping;
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
				'permission_callback' => array( $this, 'check_chat_permission' ),
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
					'permission_callback' => array( $this, 'check_chat_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_update_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Superdav AI account balance and account-portal metadata.
		register_rest_route(
			RestController::NAMESPACE,
			'/superdav-account',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_get_superdav_account' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_refresh_superdav_account' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/superdav-account/action',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_superdav_account_action' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				'args'                => array(
					'action' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static fn( mixed $value ): bool => is_string( $value ) && in_array( $value, array( 'account_portal', 'purchase_credits', 'payment_methods', 'link_account' ), true ),
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/superdav-account/redeem-coupon',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_redeem_superdav_coupon' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				'args'                => array(
					'coupon_code' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static fn( mixed $value ): bool => is_string( $value ) && '' !== trim( $value ),
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/superdav-account/advanced-plugin/install',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_install_advanced_plugin' ),
				'permission_callback' => array( AdvancedPluginManager::class, 'current_user_can_manage' ),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/superdav-account/advanced-plugin/auto-updates',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_advanced_plugin_auto_updates' ),
				'permission_callback' => array( AdvancedPluginManager::class, 'current_user_can_manage' ),
				'args'                => array(
					'enabled' => array(
						'required' => true,
						'type'     => 'boolean',
					),
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

		// SMS provider credentials endpoint.
		register_rest_route(
			RestController::NAMESPACE,
			'/settings/sms-provider',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_get_sms_provider' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_set_sms_provider' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'handle_delete_sms_provider' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				),
			)
		);

		foreach ( [ 'whatsapp', 'telegram' ] as $messaging_provider ) {
			register_rest_route(
				RestController::NAMESPACE,
				'/settings/' . $messaging_provider . '-provider',
				[
					[
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => [ $this, 'handle_get_' . $messaging_provider . '_provider' ],
						'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
					],
					[
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => [ $this, 'handle_set_' . $messaging_provider . '_provider' ],
						'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
					],
					[
						'methods'             => WP_REST_Server::DELETABLE,
						'callback'            => [ $this, 'handle_delete_' . $messaging_provider . '_provider' ],
						'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
					],
				]
			);
		}

		// Attendee contact mapping endpoints.
		register_rest_route(
			RestController::NAMESPACE,
			'/settings/contact-mappings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_list_contact_mappings' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_create_contact_mapping' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/settings/contact-mappings/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_get_contact_mapping' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'handle_update_contact_mapping' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'handle_delete_contact_mapping' ),
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

		// Google Calendar OAuth2 credentials endpoint.
		register_rest_route(
			RestController::NAMESPACE,
			'/settings/google-calendar',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_get_google_calendar_credentials' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_set_google_calendar_credentials' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'handle_delete_google_calendar_credentials' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				),
			)
		);

		// Safe calendar SMS reminder test endpoints for the admin setup UI.
		register_rest_route(
			RestController::NAMESPACE,
			'/settings/sms-provider/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_test_sms_provider' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/settings/whatsapp-provider/test',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_test_whatsapp_provider' ],
				'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			]
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/settings/telegram-provider/test',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_test_telegram_provider' ],
				'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			]
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/settings/calendar-reminders/dry-run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_calendar_reminders_dry_run' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
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
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_REST_Response( $this->chat_settings( $settings ), 200 );
		}

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

		$calendar_creds = $this->settings->get_google_calendar_credentials();
		// @phpstan-ignore-next-line
		$settings['_google_calendar_credentials'] = array(
			'configured'          => $this->settings->has_google_calendar_credentials(),
			'type'                => $calendar_creds['type'] ?? null,
			'default_calendar_id' => $calendar_creds['default_calendar_id'] ?? null,
		);

		// Indicate whether search provider API keys are configured (boolean only, no key values).
		// @phpstan-ignore-next-line
		$settings['_brave_search_key_configured'] = '' !== InternetSearchAbilities::get_brave_api_key();
		// @phpstan-ignore-next-line
		$settings['_tavily_api_key_configured'] = '' !== InternetSearchAbilities::get_tavily_api_key();
		// @phpstan-ignore-next-line
		$settings['_sms_provider'] = $this->get_sms_provider_metadata();
		// @phpstan-ignore-next-line
		$settings['_whatsapp_provider'] = $this->get_whatsapp_provider_metadata();
		// @phpstan-ignore-next-line
		$settings['_telegram_provider'] = $this->get_telegram_provider_metadata();

		// Indicate whether a feedback-report receiver API key is configured (boolean only, no key value — t180).
		// @phpstan-ignore-next-line
		$settings['_feedback_api_key_configured'] = true;

		// Expose active feature flags so the JS UI can gate sections consistently.
		// @phpstan-ignore-next-line
		$settings['_features'] = Features::all();

		return new WP_REST_Response( $settings, 200 );
	}

	/**
	 * Return only presentation settings needed by authenticated chat clients.
	 *
	 * @param array<string, mixed> $settings Full plugin settings.
	 * @return array<string, mixed>
	 */
	private function chat_settings( array $settings ): array {
		$allowed_keys  = array_flip(
			array(
				'keyboard_shortcut',
				'greeting_message',
				'show_token_costs',
				'show_tool_call_details',
				'context_window_default',
			)
		);
		$chat_settings = array_intersect_key( $settings, $allowed_keys );

		$chat_settings['_defaults'] = array(
			'greeting_message' => __( 'Send a message to start a conversation.', 'superdav-ai-agent' ),
		);

		return $chat_settings;
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
	 * Return safe account metadata for the Superdav AI account manager.
	 */
	public function handle_get_superdav_account(): WP_REST_Response {
		$status = ( new SuperdavSiteConnectionService() )->get_status();

		$status['advanced_plugin'] = $this->advanced_plugin_manager()->get_status();

		return new WP_REST_Response( $this->add_local_chat_session_details( $status ), 200 );
	}

	/**
	 * Refresh the account balance from the managed Superdav service.
	 *
	 * @return WP_REST_Response|WP_Error Safe account status or service error.
	 */
	public function handle_refresh_superdav_account(): WP_REST_Response|WP_Error {
		$status = ( new SuperdavSiteConnectionService() )->refresh_account_status();
		if ( $status instanceof WP_Error ) {
			return $status;
		}
		$status['advanced_plugin'] = $this->advanced_plugin_manager()->get_status();

		return new WP_REST_Response( $this->add_local_chat_session_details( $status ), 200 );
	}

	/** Install and activate the public Advanced package. */
	public function handle_install_advanced_plugin(): WP_REST_Response|WP_Error {
		$status = $this->advanced_plugin_manager()->install_and_activate();
		if ( $status instanceof WP_Error ) {
			return $status;
		}

		return new WP_REST_Response( $status, 200 );
	}

	/** Persist the Advanced automatic-update preference. */
	public function handle_advanced_plugin_auto_updates( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$enabled = $request->get_param( 'enabled' );
		if ( ! is_bool( $enabled ) && ! is_int( $enabled ) && ! is_string( $enabled ) ) {
			return new WP_Error( 'sd_ai_agent_advanced_auto_updates_invalid', __( 'Choose whether automatic updates should be enabled.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
		}

		$status = $this->advanced_plugin_manager()->set_auto_updates_enabled( rest_sanitize_boolean( $enabled ) );
		if ( $status instanceof WP_Error ) {
			return $status;
		}

		return new WP_REST_Response( $status, 200 );
	}

	/**
	 * Mint one fresh, action-specific browser URL without exposing the site token.
	 *
	 * @param WP_REST_Request $request Current administrator request.
	 * @return WP_REST_Response|WP_Error Safe action URL or service error.
	 */
	public function handle_superdav_account_action( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$action = $request->get_param( 'action' );
		if ( ! is_string( $action ) ) {
			return new WP_Error( 'sd_ai_agent_account_action_invalid', __( 'That SD AI account action is invalid.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
		}

		$result = ( new SuperdavSiteConnectionService() )->request_account_action( $action );
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Redeem an opaque Superdav coupon and return the refreshed safe status.
	 */
	public function handle_redeem_superdav_coupon( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$coupon_code = $request->get_param( 'coupon_code' );
		if ( ! is_string( $coupon_code ) || '' === trim( $coupon_code ) ) {
			return new WP_Error( 'sd_ai_agent_coupon_code_required', __( 'Enter a coupon code.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
		}

		$status = ( new SuperdavSiteConnectionService() )->redeem_coupon( $coupon_code );
		if ( $status instanceof WP_Error ) {
			return $status;
		}

		return new WP_REST_Response( $this->add_local_chat_session_details( $status ), 200 );
	}

	/**
	 * Add current-user-owned local details to safe cloud billing summaries.
	 *
	 * The managed service never receives chat titles, messages, or tool payloads.
	 * Session ownership is re-checked locally before a reviewable row is exposed.
	 *
	 * @param array<string, mixed> $status Safe managed account status.
	 * @return array<string, mixed> Status with safe local session details.
	 */
	private function add_local_chat_session_details( array $status ): array {
		if ( ! isset( $status['chat_sessions'] ) || ! is_array( $status['chat_sessions'] ) ) {
			return $status;
		}

		$user_id       = get_current_user_id();
		$chat_sessions = array();
		foreach ( $status['chat_sessions'] as $usage ) {
			if ( ! is_array( $usage ) ) {
				continue;
			}

			$session_id = absint( $usage['session_id'] ?? 0 );
			$session    = $session_id > 0 ? $this->database->get_session( $session_id ) : null;
			if ( ! is_object( $session ) || (int) ( $session->user_id ?? 0 ) !== $user_id ) {
				continue;
			}

			$title = sanitize_text_field( (string) ( $session->title ?? '' ) );
			if ( '' === $title ) {
				$title = sprintf(
					/* translators: %d: chat session ID. */
					__( 'Chat session #%d', 'superdav-ai-agent' ),
					$session_id
				);
			}

			$tool_calls = json_decode( (string) ( $session->tool_calls ?? '[]' ), true );
			$messages   = json_decode( (string) ( $session->messages ?? '[]' ), true );

			$usage['title']           = $title;
			$usage['tool_call_count'] = is_array( $tool_calls ) ? count( $tool_calls ) : 0;
			$usage['message_count']   = is_array( $messages ) ? count( $messages ) : 0;
			$chat_sessions[]          = $usage;
		}

		$status['chat_sessions'] = $chat_sessions;

		return $status;
	}

	/** Build the package manager outside DI so existing controller tests remain stable. */
	private function advanced_plugin_manager(): AdvancedPluginManager {
		return new AdvancedPluginManager( new SuperdavSiteConnectionService() );
	}

	/**
	 * Handle GET /settings/contact-mappings.
	 *
	 * @param WP_REST_Request $request The request object.
	 */
	public function handle_list_contact_mappings( WP_REST_Request $request ): WP_REST_Response {
		$limit  = (int) $request->get_param( 'limit' );
		$offset = (int) $request->get_param( 'offset' );

		return new WP_REST_Response(
			array(
				'contacts' => ContactMapping::list( $limit > 0 ? $limit : 100, $offset ),
			),
			200
		);
	}

	/**
	 * Handle GET /settings/contact-mappings/{id}.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_get_contact_mapping( WP_REST_Request $request ) {
		$contact = ContactMapping::get( (int) $request->get_param( 'id' ) );
		if ( null === $contact ) {
			return new WP_Error( 'sd_ai_agent_contact_not_found', __( 'Contact mapping not found.', 'superdav-ai-agent' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $contact, 200 );
	}

	/**
	 * Handle POST /settings/contact-mappings.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_create_contact_mapping( WP_REST_Request $request ) {
		$data    = $request->get_json_params();
		$contact = ContactMapping::create( is_array( $data ) ? $data : array() );

		if ( is_wp_error( $contact ) ) {
			return $contact;
		}

		return new WP_REST_Response( $contact, 201 );
	}

	/**
	 * Handle PATCH /settings/contact-mappings/{id}.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_update_contact_mapping( WP_REST_Request $request ) {
		$data    = $request->get_json_params();
		$contact = ContactMapping::update( (int) $request->get_param( 'id' ), is_array( $data ) ? $data : array() );

		if ( is_wp_error( $contact ) ) {
			return $contact;
		}

		return new WP_REST_Response( $contact, 200 );
	}

	/**
	 * Handle DELETE /settings/contact-mappings/{id}.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_delete_contact_mapping( WP_REST_Request $request ) {
		$deleted = ContactMapping::delete( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
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
	 * Handle GET /settings/google-calendar — return metadata only.
	 */
	public function handle_get_google_calendar_credentials(): WP_REST_Response {
		$creds = $this->settings->get_google_calendar_credentials();
		return new WP_REST_Response(
			array(
				'has_credentials'     => $this->settings->has_google_calendar_credentials(),
				'type'                => $creds['type'] ?? null,
				'default_calendar_id' => $creds['default_calendar_id'] ?? null,
			),
			200
		);
	}

	/**
	 * Handle POST /settings/google-calendar — save OAuth2 refresh-token credentials.
	 *
	 * @param WP_REST_Request $request The request object.
	 */
	public function handle_set_google_calendar_credentials( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();
		if ( empty( $params ) || ! is_array( $params ) ) {
			return new WP_REST_Response( array( 'error' => __( 'No data provided.', 'superdav-ai-agent' ) ), 400 );
		}

		$type = sanitize_text_field( (string) ( $params['type'] ?? '' ) );
		if ( 'oauth2_refresh_token' !== $type ) {
			return new WP_REST_Response( array( 'error' => __( 'type must be "oauth2_refresh_token".', 'superdav-ai-agent' ) ), 400 );
		}

		$client_id           = sanitize_text_field( (string) ( $params['client_id'] ?? '' ) );
		$client_secret       = sanitize_text_field( (string) ( $params['client_secret'] ?? '' ) );
		$refresh_token       = sanitize_text_field( (string) ( $params['refresh_token'] ?? '' ) );
		$default_calendar_id = sanitize_text_field( (string) ( $params['default_calendar_id'] ?? 'primary' ) );

		if ( '' === $client_id || '' === $client_secret || '' === $refresh_token ) {
			return new WP_REST_Response( array( 'error' => __( 'client_id, client_secret, and refresh_token are required.', 'superdav-ai-agent' ) ), 400 );
		}

		$success = $this->settings->set_google_calendar_credentials(
			array(
				'type'                => $type,
				'client_id'           => $client_id,
				'client_secret'       => $client_secret,
				'refresh_token'       => $refresh_token,
				'default_calendar_id' => '' !== $default_calendar_id ? $default_calendar_id : 'primary',
			)
		);

		if ( ! $success ) {
			return new WP_REST_Response( array( 'error' => __( 'Failed to save Google Calendar credentials.', 'superdav-ai-agent' ) ), 500 );
		}

		return new WP_REST_Response(
			array(
				'saved'               => true,
				'has_credentials'     => true,
				'type'                => $type,
				'default_calendar_id' => '' !== $default_calendar_id ? $default_calendar_id : 'primary',
			),
			200
		);
	}

	/**
	 * Handle DELETE /settings/google-calendar — clear Google Calendar credentials.
	 */
	public function handle_delete_google_calendar_credentials(): WP_REST_Response {
		$this->settings->set_google_calendar_credentials( array() );
		return new WP_REST_Response(
			array(
				'deleted'         => true,
				'has_credentials' => false,
			),
			200
			);
	}

	/**
	 * Handle POST /settings/sms-provider/test — send an explicit test SMS.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_test_sms_provider( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params    = $request->get_json_params();
		$params    = is_array( $params ) ? $params : array();
		$recipient = sanitize_text_field( (string) ( $params['recipient'] ?? '' ) );
		$message   = sanitize_textarea_field( (string) ( $params['message'] ?? __( 'This is a Superdav AI Agent TextBee test message.', 'superdav-ai-agent' ) ) );

		$result = SmsAbilities::handle_sms_send(
			array(
				'recipients' => array( $recipient ),
				'message'    => $message,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Handle POST /settings/calendar-reminders/dry-run — preview reminders without sending SMS.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_calendar_reminders_dry_run( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$result = CalendarReminderAbilities::handle_send_sms_reminders(
			array(
				'calendar_id'      => sanitize_text_field( (string) ( $params['calendar_id'] ?? 'primary' ) ),
				'lookahead_hours'  => absint( $params['lookahead_hours'] ?? 24 ),
				'approval_mode'    => 'dry_run',
				'message_template' => sanitize_textarea_field( (string) ( $params['message_template'] ?? '' ) ),
				'max_events'       => absint( $params['max_events'] ?? 10 ),
				'max_recipients'   => absint( $params['max_recipients'] ?? 50 ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
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
	 * Handle GET /settings/sms-provider — return safe SMS provider metadata.
	 */
	public function handle_get_sms_provider(): WP_REST_Response {
		return new WP_REST_Response( $this->get_sms_provider_metadata(), 200 );
	}

	/**
	 * Handle POST /settings/sms-provider — save SMS provider credentials.
	 *
	 * @param WP_REST_Request $request The request object.
	 */
	public function handle_set_sms_provider( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();
		if ( empty( $params ) || ! is_array( $params ) ) {
			return new WP_REST_Response( array( 'error' => 'No data provided.' ), 400 );
		}

		$provider     = sanitize_text_field( (string) ( $params['provider'] ?? 'textbee' ) );
		$api_key      = sanitize_text_field( (string) ( $params['api_key'] ?? '' ) );
		$device_id    = sanitize_text_field( (string) ( $params['device_id'] ?? '' ) );
		$api_base_url = sanitize_text_field( (string) ( $params['api_base_url'] ?? SmsAbilities::DEFAULT_API_BASE_URL ) );

		if ( 'textbee' !== $provider ) {
			return new WP_REST_Response( array( 'error' => 'provider must be "textbee".' ), 400 );
		}

		if ( '' === $api_key ) {
			return new WP_REST_Response( array( 'error' => 'api_key is required.' ), 400 );
		}

		if ( '' === $device_id ) {
			return new WP_REST_Response( array( 'error' => 'device_id is required.' ), 400 );
		}

		$normalised_api_base_url = SmsAbilities::normalise_api_base_url( $api_base_url );
		if ( is_wp_error( $normalised_api_base_url ) ) {
			return new WP_REST_Response( array( 'error' => $normalised_api_base_url->get_error_message() ), 400 );
		}

		$success = $this->settings->set_sms_provider(
			array(
				'provider'     => 'textbee',
				'api_key'      => $api_key,
				'device_id'    => $device_id,
				'api_base_url' => $normalised_api_base_url,
			)
		);

		if ( ! $success ) {
			return new WP_REST_Response( array( 'error' => 'Failed to save SMS provider credentials.' ), 500 );
		}

		return new WP_REST_Response( array_merge( array( 'saved' => true ), $this->get_sms_provider_metadata() ), 200 );
	}

	/**
	 * Handle DELETE /settings/sms-provider — remove SMS provider credentials.
	 *
	 * @param WP_REST_Request $request The request object.
	 */
	public function handle_delete_sms_provider( WP_REST_Request $request ): WP_REST_Response {
		$this->settings->set_sms_provider( array() );

		return new WP_REST_Response(
			array(
				'deleted'     => true,
				'configured'  => false,
				'provider'    => null,
				'has_api_key' => false,
			),
			200
		);
	}

	/**
	 * Return safe SMS provider metadata for REST responses.
	 *
	 * @return array<string, mixed>
	 */
	private function get_sms_provider_metadata(): array {
		$config    = $this->settings->get_sms_provider();
		$device_id = (string) ( $config['device_id'] ?? '' );

		return array(
			'configured'         => $this->settings->has_sms_provider(),
			'provider'           => $config['provider'] ?? null,
			'has_api_key'        => ! empty( $config['api_key'] ),
			'has_device_id'      => '' !== $device_id,
			'device_id_redacted' => '' !== $device_id ? $this->redact_device_id( $device_id ) : null,
			'api_base_url'       => $config['api_base_url'] ?? SmsAbilities::DEFAULT_API_BASE_URL,
		);
	}

	/** Return safe WhatsApp provider metadata. */
	public function handle_get_whatsapp_provider(): WP_REST_Response {
		return new WP_REST_Response( $this->get_whatsapp_provider_metadata(), 200 );
	}

	/** Save WhatsApp Cloud API credentials. */
	public function handle_set_whatsapp_provider( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new WP_REST_Response( [ 'error' => 'No data provided.' ], 400 );
		}

		$existing        = $this->settings->get_whatsapp_provider();
		$access_token    = sanitize_text_field( (string) ( $params['access_token'] ?? '' ) );
		$phone_number_id = sanitize_text_field( (string) ( $params['phone_number_id'] ?? '' ) );
		$api_version     = sanitize_text_field( (string) ( $params['api_version'] ?? $existing['api_version'] ?? MessagingAbilities::WHATSAPP_API_VERSION ) );
		if ( '' === $access_token ) {
			$access_token = (string) ( $existing['access_token'] ?? '' );
		}
		if ( '' === $phone_number_id ) {
			$phone_number_id = (string) ( $existing['phone_number_id'] ?? '' );
		}

		$api_version = MessagingAbilities::normalise_graph_api_version( $api_version );
		if ( '' === $access_token || '' === $phone_number_id || is_wp_error( $api_version ) ) {
			return new WP_REST_Response( [ 'error' => 'access_token, phone_number_id, and a valid api_version are required.' ], 400 );
		}

		$this->settings->set_whatsapp_provider(
			[
				'provider'        => 'meta_cloud',
				'access_token'    => $access_token,
				'phone_number_id' => $phone_number_id,
				'api_version'     => $api_version,
			]
		);

		return new WP_REST_Response( array_merge( [ 'saved' => true ], $this->get_whatsapp_provider_metadata() ), 200 );
	}

	/** Clear WhatsApp Cloud API credentials. */
	public function handle_delete_whatsapp_provider(): WP_REST_Response {
		$this->settings->set_whatsapp_provider( [] );
		return new WP_REST_Response(
			[
				'deleted'    => true,
				'configured' => false,
			],
			200
			);
	}

	/** Send an explicit WhatsApp test message. */
	public function handle_test_whatsapp_provider( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : [];
		$result = MessagingAbilities::handle_whatsapp_send(
			[
				'recipients' => [ sanitize_text_field( (string) ( $params['recipient'] ?? '' ) ) ],
				'message'    => sanitize_textarea_field( (string) ( $params['message'] ?? __( 'This is an SD AI Agent WhatsApp test message.', 'superdav-ai-agent' ) ) ),
			]
		);

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	/** Return safe Telegram provider metadata. */
	public function handle_get_telegram_provider(): WP_REST_Response {
		return new WP_REST_Response( $this->get_telegram_provider_metadata(), 200 );
	}

	/** Save Telegram Bot API credentials. */
	public function handle_set_telegram_provider( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new WP_REST_Response( [ 'error' => 'No data provided.' ], 400 );
		}

		$existing  = $this->settings->get_telegram_provider();
		$bot_token = sanitize_text_field( (string) ( $params['bot_token'] ?? '' ) );
		if ( '' === $bot_token ) {
			$bot_token = (string) ( $existing['bot_token'] ?? '' );
		}
		if ( '' === $bot_token ) {
			return new WP_REST_Response( [ 'error' => 'bot_token is required.' ], 400 );
		}

		$this->settings->set_telegram_provider(
			[
				'provider'  => 'bot_api',
				'bot_token' => $bot_token,
			]
			);
		return new WP_REST_Response( array_merge( [ 'saved' => true ], $this->get_telegram_provider_metadata() ), 200 );
	}

	/** Clear Telegram Bot API credentials. */
	public function handle_delete_telegram_provider(): WP_REST_Response {
		$this->settings->set_telegram_provider( [] );
		return new WP_REST_Response(
			[
				'deleted'    => true,
				'configured' => false,
			],
			200
			);
	}

	/** Send an explicit Telegram test message. */
	public function handle_test_telegram_provider( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : [];
		$result = MessagingAbilities::handle_telegram_send(
			[
				'chat_ids' => [ sanitize_text_field( (string) ( $params['chat_id'] ?? '' ) ) ],
				'message'  => sanitize_textarea_field( (string) ( $params['message'] ?? __( 'This is an SD AI Agent Telegram test message.', 'superdav-ai-agent' ) ) ),
			]
		);

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	/**
	 * Return safe WhatsApp provider metadata.
	 *
	 * @return array<string, mixed>
	 */
	private function get_whatsapp_provider_metadata(): array {
		$config          = $this->settings->get_whatsapp_provider();
		$phone_number_id = (string) ( $config['phone_number_id'] ?? '' );
		return [
			'configured'               => $this->settings->has_whatsapp_provider(),
			'provider'                 => $config['provider'] ?? null,
			'has_access_token'         => ! empty( $config['access_token'] ),
			'phone_number_id_redacted' => '' !== $phone_number_id ? '********' . substr( $phone_number_id, -4 ) : null,
			'api_version'              => $config['api_version'] ?? MessagingAbilities::WHATSAPP_API_VERSION,
		];
	}

	/**
	 * Return safe Telegram provider metadata.
	 *
	 * @return array<string, mixed>
	 */
	private function get_telegram_provider_metadata(): array {
		$config = $this->settings->get_telegram_provider();
		return [
			'configured'    => $this->settings->has_telegram_provider(),
			'provider'      => $config['provider'] ?? null,
			'has_bot_token' => ! empty( $config['bot_token'] ),
		];
	}

	/**
	 * Redact a TextBee device ID for metadata responses.
	 *
	 * @param string $device_id TextBee device ID.
	 * @return string
	 */
	private function redact_device_id( string $device_id ): string {
		$visible = substr( $device_id, -4 );
		return '********' . $visible;
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

		// The WP AI Client SDK registry is the single source of truth for which
		// providers exist and which models each exposes. Provider plugins such
		// as `ai-provider-for-openai`, `ai-provider-for-anthropic-max`, the
		// OpenAI-compatible connector, etc. each register themselves there;
		// credentials are bridged in by ProviderCredentialLoader::load(). A
		// provider is exposed by this endpoint only when authentication has
		// been configured for it.
		if ( ! class_exists( '\\WordPress\\AiClient\\AiClient' ) ) {
			return new WP_REST_Response( $providers, 200 );
		}

		try {
			$registry     = \WordPress\AiClient\AiClient::defaultRegistry();
			$provider_ids = $registry->getRegisteredProviderIds();
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( $providers, 200 );
		}

		// Provisioning mutates site credentials and remains administrator-only.
		// Chat-enabled non-admins may discover already configured providers.
		if ( current_user_can( 'manage_options' ) ) {
			$this->maybe_auto_provision_superdav_provider();
		}
		ProviderCredentialLoader::load();

		foreach ( $provider_ids as $provider_id ) {
			try {
				// Only include providers that have authentication set.
				$auth = $registry->getProviderRequestAuthentication( $provider_id );
				if ( null === $auth ) {
					continue;
				}

				$class           = $registry->getProviderClassName( $provider_id );
				$metadata        = $class::metadata();
				$models          = array();
				$model_discovery = null;

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
					$discovery       = ProviderModelDiscovery::discover( $provider_id, $class );
					$models          = self::format_model_metadata_list_for_response( $discovery['metadata'] );
					$model_discovery = $discovery['failure'];
				}

				$models = self::filter_text_generation_models( $models );

				$provider_response = array(
					'id'         => $provider_id,
					'name'       => $metadata->getName(),
					'type'       => (string) $metadata->getType(),
					'configured' => true,
					'models'     => $models,
				);
				if ( is_array( $model_discovery ) ) {
					$provider_response['model_discovery'] = $model_discovery;
				}

				if ( SuperdavAiProvider::PROVIDER_ID === $provider_id ) {
					$provider_response['default_model'] = SuperdavAiProvider::default_model_id();
					if ( current_user_can( 'manage_options' ) ) {
						$provider_response['status'] = ( new SuperdavSiteConnectionService() )->get_status();
					}
				}

				$providers[] = $provider_response;
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		return new WP_REST_Response( $providers, 200 );
	}

	/**
	 * Provision the bundled managed Superdav provider before the provider list is returned.
	 *
	 * First-install screens call /providers before onboarding can start. If the
	 * activation hook could not provision yet, retry here so users can move
	 * straight into the Setup Assistant instead of seeing a manual connect gate.
	 */
	private function maybe_auto_provision_superdav_provider(): void {
		try {
			( new SuperdavSiteConnectionService() )->ensure_site_token();
		} catch ( \Throwable ) {
			return;
		}
	}

	/**
	 * Format SDK model metadata entries for the provider picker response.
	 *
	 * @param array<int, mixed> $model_metadata SDK model metadata entries.
	 * @return array<int, array<string, mixed>> Safe model rows for the provider picker.
	 */
	private static function format_model_metadata_list_for_response( array $model_metadata ): array {
		$models = array();
		foreach ( $model_metadata as $model_meta ) {
			if ( ! is_object( $model_meta ) ) {
				continue;
			}
			$models[] = self::format_model_metadata_for_response( $model_meta );
		}

		return $models;
	}

	/**
	 * Keep only models that advertise the SDK text-generation capability.
	 *
	 * The agent can use text models with additional capabilities, such as image
	 * input, but must not offer image, video, speech, music, embedding, or
	 * unknown models as chat selections. Both SDK-formatted rows and the raw
	 * OpenAI-compatible connector response carry this metadata under one of the
	 * fields inspected here.
	 *
	 * @param array<mixed> $models Provider model rows.
	 * @return list<array<mixed>> Agent-selectable model rows.
	 */
	private static function filter_text_generation_models( array $models ): array {
		$selectable_models = array();

		foreach ( $models as $model ) {
			if ( ! is_array( $model ) || ! self::model_supports_text_generation( $model ) ) {
				continue;
			}

			$selectable_models[] = $model;
		}

		return $selectable_models;
	}

	/**
	 * Determine whether a provider model row explicitly supports text generation.
	 *
	 * @param array<string, mixed> $model Provider model row.
	 * @return bool Whether the model is safe to present to the agent.
	 */
	private static function model_supports_text_generation( array $model ): bool {
		foreach ( array( 'capabilities', 'supported_capabilities' ) as $key ) {
			if ( ! isset( $model[ $key ] ) || ! is_array( $model[ $key ] ) ) {
				continue;
			}

			foreach ( $model[ $key ] as $capability_key => $capability_value ) {
				if ( is_string( $capability_key ) && true === $capability_value && self::is_text_generation_capability( $capability_key ) ) {
					return true;
				}

				if ( is_string( $capability_value ) && self::is_text_generation_capability( $capability_value ) ) {
					return true;
				}
			}
		}

		return isset( $model['supports_text_generation'] ) && true === $model['supports_text_generation'];
	}

	/**
	 * Check a provider capability value against the SDK text-generation value.
	 *
	 * @param string $capability Provider capability value.
	 * @return bool Whether the value denotes text generation.
	 */
	private static function is_text_generation_capability( string $capability ): bool {
		return 'text_generation' === str_replace( '-', '_', strtolower( $capability ) );
	}

	/**
	 * Convert SDK model metadata to the safe provider picker response shape.
	 *
	 * @param object $model_meta SDK ModelMetadata-like DTO.
	 * @return array<string, mixed>
	 */
	private static function format_model_metadata_for_response( object $model_meta ): array {
		$model_id = method_exists( $model_meta, 'getId' ) ? (string) $model_meta->getId() : '';
		$name     = method_exists( $model_meta, 'getName' ) ? (string) $model_meta->getName() : $model_id;
		$entry    = ModelCapabilityRegistry::get( $model_id );

		$context_window = (int) ( $entry['context_length'] ?? 0 );
		if ( $context_window <= 0 ) {
			$context_window = Settings::MODEL_CONTEXT_WINDOWS[ $model_id ] ?? 128000;
		}

		$response = array(
			'id'                    => $model_id,
			'name'                  => '' !== $name ? $name : $model_id,
			'context_window'        => $context_window,
			'max_output_length'     => (int) ( $entry['max_output_tokens'] ?? 0 ),
			'max_output_tokens'     => (int) ( $entry['max_output_tokens'] ?? 0 ),
			'capabilities'          => self::capabilities_to_strings( method_exists( $model_meta, 'getSupportedCapabilities' ) ? $model_meta->getSupportedCapabilities() : array() ),
			'supported_options'     => self::supported_options_to_arrays( method_exists( $model_meta, 'getSupportedOptions' ) ? $model_meta->getSupportedOptions() : array() ),
			'provider_capabilities' => $entry['provider_capabilities'] ?? array(),
		);

		return $response;
	}

	/**
	 * Convert SDK capability enum objects to scalar strings.
	 *
	 * @param mixed $capabilities SDK capability list.
	 * @return list<string>
	 */
	private static function capabilities_to_strings( $capabilities ): array {
		if ( ! is_array( $capabilities ) ) {
			return array();
		}

		$values = array();
		foreach ( $capabilities as $capability ) {
			if ( ! is_object( $capability ) ) {
				continue;
			}

			$value = $capability->value;
			if ( is_string( $value ) ) {
				$values[] = $value;
			}
		}
		return array_values( array_unique( $values ) );
	}

	/**
	 * Convert SDK supported options to safe arrays for REST output.
	 *
	 * @param mixed $options SDK supported option list.
	 * @return list<array<string, mixed>>
	 */
	private static function supported_options_to_arrays( $options ): array {
		if ( ! is_array( $options ) ) {
			return array();
		}

		$values = array();
		foreach ( $options as $option ) {
			if ( is_object( $option ) && method_exists( $option, 'toArray' ) ) {
				$option_data = $option->toArray();
				if ( is_array( $option_data ) ) {
					$safe_option_data = array();
					foreach ( $option_data as $key => $value ) {
						if ( is_string( $key ) ) {
							$safe_option_data[ $key ] = $value;
						}
					}
					$values[] = $safe_option_data;
				}
			}
		}
		return $values;
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

		// A provider is considered configured iff some registered provider in
		// the WP AI Client SDK registry has authentication set. Credentials are
		// bridged from the WP 7.0 Connectors API (and 6.9 polyfill) by
		// ProviderCredentialLoader::load(), so this single check covers both
		// SDK-native plugins and the connector API.
		if ( ! ProviderCredentialLoader::has_any_authenticated_provider() ) {
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
