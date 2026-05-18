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
use SdAiAgent\Admin\DefaultModelNoticeHandler;
use SdAiAgent\Admin\FloatingWidget;
use SdAiAgent\Admin\ThirdPartyAbilityNoticeHandler;
use SdAiAgent\Admin\UnifiedAdminMenu;
use SdAiAgent\Compat\GutenbergConnectorsBridge;
use SdAiAgent\Core\Database;
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
	 * - Handle third-party ability notice dismissal.
	 * - Handle invalid-default-model notice dismissal (GH#1494).
	 */
	#[Action( tag: 'admin_init', priority: 10 )]
	public function on_admin_init(): void {
		Database::install();
		ToolCapabilities::register_capabilities( ToolCapabilities::all_ability_ids() );
		UnifiedAdminMenu::handleLegacyRedirects();
		ThirdPartyAbilityNoticeHandler::handle_dismiss();
		DefaultModelNoticeHandler::handle_dismiss();
	}

	/**
	 * Display admin notice for unclassified third-party abilities.
	 */
	#[Action( tag: 'admin_notices', priority: 10 )]
	public function display_third_party_notice(): void {
		ThirdPartyAbilityNoticeHandler::maybe_display_notice();
	}

	/**
	 * Display admin notice when the saved default model is no longer
	 * advertised by any authenticated provider and the resolver had to
	 * substitute (GH#1494).
	 */
	#[Action( tag: 'admin_notices', priority: 10 )]
	public function display_default_model_notice(): void {
		DefaultModelNoticeHandler::maybe_display_notice();
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
