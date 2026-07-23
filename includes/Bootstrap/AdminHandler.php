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
use SdAiAgent\Core\ActiveJobsCleanupService;
use SdAiAgent\Core\Database;
use SdAiAgent\Core\SuperdavSiteConnectionService;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiProvider;
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
	 * Admin init hooks.
	 *
	 * - DB schema safety-net (dbDelta is no-op when schema is current).
	 * - Active job stale-cleanup cron safety-net for cloned/provisioned tenants.
	 * - Per-tool capabilities for role-management plugins.
	 * - Legacy URL redirects to unified menu.
	 * - Handle third-party ability notice dismissal.
	 * - Handle invalid-default-model notice dismissal (GH#1494).
	 */
	#[Action( tag: 'admin_init', priority: 10 )]
	public function on_admin_init(): void {
		Database::install();
		ActiveJobsCleanupService::schedule();
		ToolCapabilities::register_capabilities( ToolCapabilities::all_ability_ids() );
		UnifiedAdminMenu::handleLegacyRedirects();
		ThirdPartyAbilityNoticeHandler::handle_dismiss();
		DefaultModelNoticeHandler::handle_dismiss();
		$this->enqueue_superdav_connector_card();
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
	 * Enqueue the custom card only on the WordPress Connectors screen.
	 */
	public function enqueue_superdav_connector_card(): void {
		if ( ! $this->is_connectors_screen() || ! function_exists( 'wp_register_script_module' ) ) {
			return;
		}

		$asset_path = SD_AI_AGENT_DIR . '/build/superdav-connector-card.js';
		if ( ! file_exists( $asset_path ) ) {
			return;
		}

		wp_register_script_module(
			'@sd-ai-agent/superdav-connector-card',
			SD_AI_AGENT_URL . 'build/superdav-connector-card.js',
			array(
				array(
					'id'     => '@wordpress/connectors',
					'import' => 'static',
				),
			),
			(string) filemtime( $asset_path )
		);
		wp_enqueue_script_module( '@sd-ai-agent/superdav-connector-card' );
		add_filter( 'script_module_data_@sd-ai-agent/superdav-connector-card', array( $this, 'get_superdav_connector_card_data' ) );
	}

	/**
	 * Return safe initial card data.
	 *
	 * @param array<string, mixed> $data Existing script-module data.
	 * @return array<string, mixed>
	 */
	public function get_superdav_connector_card_data( array $data ): array {
		return array_merge(
			$data,
			array(
				'providerId'      => SuperdavAiProvider::PROVIDER_ID,
				'name'            => __( 'Superdav AI', 'superdav-ai-agent' ),
				'description'     => __( 'Managed AI models and account credits for Superdav AI Agent.', 'superdav-ai-agent' ),
				'account'         => ( new SuperdavSiteConnectionService() )->get_status(),
				'accountEndpoint' => rest_url( 'sd-ai-agent/v1/superdav-account' ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'settingsUrl'     => admin_url( 'admin.php?page=sd-ai-agent#/settings' ),
			)
		);
	}

	/**
	 * Check core and Gutenberg Connectors screen IDs.
	 */
	private function is_connectors_screen(): bool {
		global $pagenow;

		if ( 'options-connectors.php' === $pagenow ) {
			return true;
		}

		$screen = get_current_screen();

		return $screen && in_array( $screen->id, array( 'options-connectors', 'settings_page_options-connectors-wp-admin' ), true );
	}

	/**
	 * Add action links to the plugin row on plugins.php.
	 *
	 * Uses the plugin-specific filter
	 * `plugin_action_links_superdav-ai-agent/superdav-ai-agent.php` so the
	 * callback only runs for this plugin's row — no per-row basename guard
	 * needed, and no risk of accidentally mutating other plugins' links.
	 *
	 * The hash route uses the canonical `#/<route>` form recognised by the
	 * unified-admin React app's hash router (see `src/unified-admin/index.js`).
	 * The previous `#chat` (no slash) silently fell through to the default
	 * route, which is why "Start Chat" appeared to land on the wrong screen.
	 *
	 * @param array<string, string> $actions Plugin action links.
	 * @return array<string, string> Modified action links.
	 */
	#[Filter( tag: 'plugin_action_links_superdav-ai-agent/superdav-ai-agent.php', priority: 10 )]
	#[Filter( tag: 'network_admin_plugin_action_links_superdav-ai-agent/superdav-ai-agent.php', priority: 10 )]
	public function add_plugin_action_links( array $actions ): array {
		$chat_url     = admin_url( 'admin.php?page=sd-ai-agent#/chat' );
		$settings_url = admin_url( 'admin.php?page=sd-ai-agent#/settings' );

		// Prepend so they appear before the WP core "Deactivate" link.
		return array(
			'sd_settings' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $settings_url ),
				esc_html__( 'Settings', 'superdav-ai-agent' )
			),
			'sd_chat'     => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $chat_url ),
				esc_html__( 'Open AI Agent', 'superdav-ai-agent' )
			),
		) + $actions;
	}
}
