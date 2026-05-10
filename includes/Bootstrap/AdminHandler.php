<?php
/**
 * DI handler for admin-only hooks.
 *
 * Replaces the inline `add_action('admin_menu', ...)` and
 * `add_action('admin_init', ...)` calls in `superdav-ai-agent.php`.
 *
 * @package SdAiAgent\Bootstrap
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Bootstrap;

use SdAiAgent\Abilities\ToolCapabilities;
use SdAiAgent\Admin\FloatingWidget;
use SdAiAgent\Admin\UnifiedAdminMenu;
use SdAiAgent\Compat\GutenbergConnectorsBridge;
use SdAiAgent\Core\Database;
use SdAiAgent\REST\ConnectorsController;
use SdAiAgent\REST\SettingsController;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Filter;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers admin menus, capabilities, DB safety-net, and admin assets.
 *
 * Context CTX_ADMIN ensures this handler only loads on admin pages —
 * saving hook registration overhead on frontend/REST/CLI requests.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_ADMIN,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class AdminHandler {

	#[Action( tag: 'admin_menu', priority: 10 )]
	public function register_menus(): void {
		UnifiedAdminMenu::register();
	}

	/**
	 * Belt-and-braces fallback for the Gutenberg Connectors page on WP 6.9.
	 *
	 * The PRIMARY fix for the missing-Connectors-page bug runs at
	 * `plugins_loaded:1` from `superdav-ai-agent.php`, where we directly
	 * require Gutenberg's `lib/experimental/connectors/load.php`. That
	 * loader registers Gutenberg's own `admin_menu:11` callback which adds
	 * the Settings → Connectors menu item normally.
	 *
	 * This admin-only fallback runs at `admin_menu:12` (one tick after
	 * Gutenberg's own callback) and registers the menu item directly —
	 * but ONLY if it has not already been registered. So in the normal
	 * happy path (primary fix worked) this method is a no-op; it only
	 * creates a menu item if a future Gutenberg release moves the loader
	 * path or otherwise breaks the primary fix, ensuring the page is at
	 * least reachable while we ship a follow-up.
	 *
	 * @see GutenbergConnectorsBridge::force_load_connectors_subsystem()
	 * @see GutenbergConnectorsBridge::maybe_register()
	 */
	#[Action( tag: 'admin_menu', priority: 12 )]
	public function register_gutenberg_connectors_bridge(): void {
		GutenbergConnectorsBridge::maybe_register();
	}

	/**
	 * Admin init hooks.
	 *
	 * - DB schema safety-net (dbDelta is no-op when schema is current).
	 * - Per-tool capabilities for role-management plugins.
	 * - Legacy URL redirects to unified menu.
	 * - Connector option update hooks to invalidate the providers cache when
	 *   credentials are changed via the native WP 7.0 Connectors admin page.
	 */
	#[Action( tag: 'admin_init', priority: 10 )]
	public function on_admin_init(): void {
		Database::install();
		ToolCapabilities::register_capabilities( ToolCapabilities::all_ability_ids() );
		UnifiedAdminMenu::handleLegacyRedirects();
		$this->register_connector_cache_hooks();
	}

	/**
	 * Register update_option hooks for WP Connectors API option keys.
	 *
	 * The native WP 7.0 Connectors page (options-connectors.php) writes
	 * connectors_ai_{provider}_api_key options directly.  Hooking into
	 * update_option_{key} ensures the site-wide providers transient cache is
	 * invalidated whenever an external connector change is saved, so admins
	 * see fresh provider data on the next GET /providers request.
	 *
	 * accepted_args=0 is intentional: flush_providers_cache() takes no
	 * parameters, and update_option_{key} passes 3 args we do not need.
	 */
	private function register_connector_cache_hooks(): void {
		foreach ( ConnectorsController::PROVIDERS as $meta ) {
			add_action(
				'update_option_' . $meta['option_key'],
				[ SettingsController::class, 'flush_providers_cache' ],
				10,
				0
			);
		}

		// Flush when any provider plugin is activated or deactivated so the
		// WP SDK registry change is immediately reflected in the chat UI.
		add_action( 'activated_plugin', [ SettingsController::class, 'flush_providers_cache' ], 10, 0 );
		add_action( 'deactivated_plugin', [ SettingsController::class, 'flush_providers_cache' ], 10, 0 );
	}

	/**
	 * Enqueue admin-only assets for the floating widget.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	#[Action( tag: 'admin_enqueue_scripts', priority: 10 )]
	public function enqueue_admin_assets( string $hook_suffix ): void {
		FloatingWidget::enqueue_assets_admin( $hook_suffix );
	}

	/**
	 * Add action links to the plugin listing on plugins.php.
	 *
	 * @param array<string, string> $actions     Plugin action links.
	 * @param string                $plugin_file Path to plugin file relative to plugins directory.
	 * @return array<string, string> Modified action links.
	 */
	#[Filter( tag: 'plugin_action_links', priority: 10 )]
	public function add_plugin_action_links( array $actions, string $plugin_file ): array {
		// Only modify our plugin.
		if ( $plugin_file !== 'superdav-ai-agent/superdav-ai-agent.php' ) {
			return $actions;
		}

		$connectors_url = UnifiedAdminMenu::hasNativeConnectorsPage()
			? admin_url( 'options-connectors.php' )
			: admin_url( 'options-general.php?page=options-connectors-wp-admin' );

		$chat_url = admin_url( 'admin.php?page=sd-ai-agent#chat' );

		$actions['sd_chat'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $chat_url ),
			esc_html__( 'Start Chat', 'superdav-ai-agent' )
		);

		$actions['sd_connections'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $connectors_url ),
			esc_html__( 'Configure Connections', 'superdav-ai-agent' )
		);

		return $actions;
	}
}
