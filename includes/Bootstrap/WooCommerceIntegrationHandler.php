<?php
/**
 * DI handler that auto-enables WooCommerce's native Abilities API integration.
 *
 * WooCommerce 10.3+ ships its own abilities (products, orders) registered via
 * `AbilitiesRestBridge`. Those abilities are gated behind an `is_mcp_request()`
 * guard so they only activate for MCP protocol requests. This handler bridges
 * the gap so the WP AI Client SDK (which uses `wp-abilities/v1/abilities/{name}/run`
 * — a REST request, but NOT the `/woocommerce/mcp` endpoint) can also use them.
 *
 * Behaviour when WooCommerce ≥ 10.3 is active:
 *
 *   1. Auto-enables `woocommerce_feature_mcp_integration_enabled` so that real
 *      MCP clients (Claude Desktop, etc.) work out of the box. Respects a
 *      `sd_ai_agent_auto_enable_woo_mcp` filter (return false to opt out).
 *
 *   2. Registers the `woocommerce-rest` ability category and all configured
 *      WooCommerce REST abilities (products-list/get/create/update/delete,
 *      orders-list/get/create/update) on `wp_abilities_api_init` at priority 5,
 *      before WooCommerce's own priority-10 hook which bails for non-MCP requests.
 *
 * When WooCommerce is not active this handler is a no-op.
 *
 * @package SdAiAgent\Bootstrap
 * @license GPL-2.0-or-later
 * @since   1.3.0
 */

declare(strict_types=1);

namespace SdAiAgent\Bootstrap;

use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Filter;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auto-enables WooCommerce's native Abilities API for the WP AI Client SDK.
 *
 * @since 1.3.0
 */
#[Handler(
	container: 'sd-ai-agent',
	strategy: Handler::INIT_JUST_IN_TIME,
)]
final class WooCommerceIntegrationHandler {

	/**
	 * Fully-qualified class names for WooCommerce's internal abilities bridge.
	 * We reference them as strings to avoid hard class-import errors when
	 * WooCommerce is not installed.
	 */
	private const BRIDGE_CLASS   = 'Automattic\\WooCommerce\\Internal\\Abilities\\AbilitiesRestBridge';
	private const FACTORY_CLASS  = 'Automattic\\WooCommerce\\Internal\\Abilities\\REST\\RestAbilityFactory';
	private const CATEGORY_CLASS = 'Automattic\\WooCommerce\\Internal\\Abilities\\AbilitiesCategories';

	/**
	 * Option name for WooCommerce's MCP integration feature flag.
	 */
	private const WOO_MCP_OPTION = 'woocommerce_feature_mcp_integration_enabled';

	// ─── Hooks ──────────────────────────────────────────────────────────────

	/**
	 * Auto-enable WooCommerce's MCP integration feature flag when WooCommerce is active.
	 *
	 * Fires on `plugins_loaded` at priority 5 (before most plugins). Respects a
	 * `sd_ai_agent_auto_enable_woo_mcp` filter — return false to opt out.
	 */
	#[Action( tag: 'plugins_loaded', priority: 5 )]
	public function maybe_enable_woo_mcp_feature(): void {
		if ( ! self::is_woocommerce_active() ) {
			return;
		}

		/**
		 * Controls whether sd-ai-agent automatically enables the WooCommerce
		 * MCP integration feature flag when WooCommerce is active.
		 *
		 * Return false to manage the feature flag yourself via WooCommerce settings.
		 *
		 * @since 1.3.0
		 * @param bool $auto_enable Default true.
		 */
		if ( ! apply_filters( 'sd_ai_agent_auto_enable_woo_mcp', true ) ) {
			return;
		}

		if ( get_option( self::WOO_MCP_OPTION ) !== 'yes' ) {
			update_option( self::WOO_MCP_OPTION, 'yes', true );
		}
	}

	/**
	 * Keep WooCommerce's MCP adapter off generic REST requests.
	 *
	 * WooCommerce 10.7 initializes its MCP adapter on every REST request when the
	 * MCP feature flag is enabled. The adapter can recursively initialize the REST
	 * server while `rest_api_init` is already running, exhausting PHP-FPM workers
	 * and hanging unrelated routes such as `/wp-json/` and this plugin's
	 * `/sd-ai-agent/v1/alerts` endpoint. Preserve the stored feature flag for the
	 * real WooCommerce MCP endpoint, but report the feature as disabled for all
	 * other REST requests.
	 *
	 * @param mixed $pre_option Pre-option value from earlier filters.
	 * @return mixed Filtered pre-option value.
	 */
	#[Filter( tag: 'pre_option_' . self::WOO_MCP_OPTION, priority: 10 )]
	public function disable_woo_mcp_for_non_mcp_rest_requests( mixed $pre_option ): mixed {
		if ( ! self::is_woocommerce_active() ) {
			return $pre_option;
		}

		if ( ! function_exists( 'wp_is_serving_rest_request' ) || ! wp_is_serving_rest_request() ) {
			return $pre_option;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( str_contains( $request_uri, '/woocommerce/mcp' ) ) {
			return $pre_option;
		}

		return 'no';
	}

	/**
	 * Remove missing tools from WooCommerce's vendored MCP adapter default server.
	 *
	 * WooCommerce may load the MCP adapter default server before the adapter's own
	 * introspection abilities are registered. Its component registry probes those
	 * tool names with wp_get_ability(), which emits WordPress 6.9+ not-found
	 * notices. Filter the default server config so only currently registered
	 * abilities are handed to the adapter for validation.
	 *
	 * @param mixed $config MCP adapter default server configuration.
	 * @return mixed Filtered configuration.
	 */
	#[Filter( tag: 'mcp_adapter_default_server_config', priority: 5 )]
	public function remove_missing_mcp_adapter_default_tools( mixed $config ): mixed {
		if ( ! is_array( $config ) || ! isset( $config['tools'] ) || ! is_array( $config['tools'] ) ) {
			return $config;
		}

		if ( ! function_exists( 'wp_has_ability' ) ) {
			return $config;
		}

		$config['tools'] = array_values(
			array_filter(
				$config['tools'],
				static function ( mixed $tool ): bool {
					return ! is_string( $tool ) || wp_has_ability( $tool );
				}
			)
		);

		return $config;
	}

	/**
	 * Register the `woocommerce-rest` ability category for the WP Abilities API.
	 *
	 * WooCommerce normally registers this category only when its `AbilitiesRegistry`
	 * is constructed (which only happens during MCP requests). We register it here
	 * at priority 5 so it's always available when WooCommerce is active.
	 */
	#[Action( tag: 'wp_abilities_api_categories_init', priority: 5 )]
	public function register_woo_ability_category(): void {
		if ( ! self::is_woocommerce_active() ) {
			return;
		}

		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'woocommerce-rest' ) ) {
			if ( class_exists( self::CATEGORY_CLASS ) ) {
				remove_action( 'wp_abilities_api_categories_init', array( self::CATEGORY_CLASS, 'register_categories' ) );
			}
			return;
		}

		// WooCommerce 10.8+ registers its categories on this same hook at the
		// default priority. When that hook is present, let WooCommerce own the
		// registration; calling its registrar early would make its later callback
		// emit a duplicate-category doing_it_wrong notice.
		if (
			class_exists( self::CATEGORY_CLASS )
			&& has_action( 'wp_abilities_api_categories_init', array( self::CATEGORY_CLASS, 'register_categories' ) )
		) {
			return;
		}

		// Delegate to WooCommerce's own category registration when available so
		// labels/descriptions stay in sync. Gracefully fall back to our own call
		// if the class doesn't exist or is not hooked (older WooCommerce).
		if ( class_exists( self::CATEGORY_CLASS ) ) {
			( self::CATEGORY_CLASS )::register_categories();
			return;
		}

		// Fallback for WooCommerce versions without AbilitiesCategories.
		wp_register_ability_category(
			'woocommerce-rest',
			array(
				'label'       => __( 'WooCommerce REST API', 'superdav-ai-agent' ),
				'description' => __( 'REST API operations for WooCommerce resources including products, orders, and other store data.', 'superdav-ai-agent' ),
			)
		);
	}

	/**
	 * Register WooCommerce's native REST-bridge abilities for the WP AI Client SDK.
	 *
	 * WooCommerce's `AbilitiesRestBridge::register_abilities()` is gated behind
	 * `MCPAdapterProvider::is_mcp_request()` — it only fires for requests to
	 * `/woocommerce/mcp`, not for the generic `/wp-abilities/v1/abilities/{name}/run`
	 * endpoint used by `wp_ai_client_prompt()`. We use PHP reflection to call the
	 * private `get_configurations()` method and pass each config directly to
	 * `RestAbilityFactory::register_controller_abilities()`, bypassing the guard.
	 *
	 * Fires at priority 5, before WooCommerce's own priority-10 hook, so the
	 * abilities are registered regardless of whether this is an MCP request.
	 * WooCommerce's hook becomes a benign no-op (abilities already registered).
	 *
	 * Falls back gracefully if WooCommerce's internal classes are not available
	 * (e.g., older WooCommerce or WooCommerce not active).
	 */
	#[Action( tag: 'wp_abilities_api_init', priority: 5 )]
	public function register_woo_rest_abilities(): void {
		if ( ! self::is_woocommerce_active() ) {
			return;
		}

		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$bridge_class  = self::BRIDGE_CLASS;
		$factory_class = self::FACTORY_CLASS;

		if ( ! class_exists( $bridge_class ) ) {
			return;
		}

		if ( ! class_exists( $factory_class ) ) {
			return;
		}

		try {
			$ref    = new \ReflectionClass( $bridge_class );
			$method = $ref->getMethod( 'get_configurations' );

			/** @var array<int, array<string, mixed>> $configurations */
			$configurations = $method->invoke( null );

			foreach ( $configurations as $config ) {
				$factory_class::register_controller_abilities( $config );
			}
		} catch ( \ReflectionException $e ) {
			// WooCommerce internals changed. Log and carry on — abilities simply
			// won't be available via the WP AI Client SDK for this version.
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning(
				'sd-ai-agent: Could not reflect on WooCommerce AbilitiesRestBridge::get_configurations(). ' .
				'WooCommerce product/order abilities may not be available via wp_ai_client_prompt(). ' .
				'Error: ' . $e->getMessage(),
					array( 'source' => 'sd-ai-agent-woo-integration' )
				);
			}
		}
	}

	/**
	 * Grant WooCommerce REST ability permissions to users with appropriate capabilities.
	 *
	 * WooCommerce's `RestAbilityFactory::check_permission()` delegates to this filter
	 * with a default of `false`. During MCP requests, WooCommerce's transport layer
	 * hooks in its own handler. For non-MCP requests (the WP AI Client SDK path via
	 * `/wp-abilities/v1/abilities/{name}/run`), we provide the permission check here
	 * based on standard WooCommerce capabilities:
	 *
	 *   - GET operations: require `view_woocommerce_reports` or `manage_woocommerce`
	 *   - POST/PUT/DELETE: require `manage_woocommerce`
	 *
	 * @param bool   $allowed    Current permission state (default false).
	 * @param string $method     HTTP method (GET, POST, PUT, DELETE).
	 * @param object $controller REST controller instance.
	 * @return bool Whether the operation is allowed.
	 */
	#[Filter( tag: 'woocommerce_check_rest_ability_permissions_for_method', priority: 10 )]
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $controller required by WooCommerce filter signature.
	public function check_woo_rest_ability_permissions( bool $allowed, string $method, object $controller ): bool {
		// Already allowed by another filter (e.g., WooCommerce's MCP transport).
		if ( $allowed ) {
			return true;
		}

		if ( ! self::is_woocommerce_active() ) {
			return false;
		}

		// Read operations: view_woocommerce_reports or manage_woocommerce.
		if ( 'GET' === $method ) {
			return current_user_can( 'view_woocommerce_reports' ) || current_user_can( 'manage_woocommerce' );
		}

		// Write operations: manage_woocommerce.
		return current_user_can( 'manage_woocommerce' );
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Check whether WooCommerce is active with its core classes available.
	 *
	 * @return bool
	 */
	private static function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}
}
