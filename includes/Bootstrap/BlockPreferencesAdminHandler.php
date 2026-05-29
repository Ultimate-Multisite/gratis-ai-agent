<?php
/**
 * DI handler for the Block Preferences admin sub-page.
 *
 * Wires {@see \SdAiAgent\Admin\BlockPreferencesPage::register()} on
 * `admin_menu`, handles the save POST action on `admin_init` before
 * output begins, and enqueues the page-scoped JS+CSS bundle on
 * `admin_enqueue_scripts` (no inline <script>/<style> tags per the
 * WordPress.org Plugin Review guideline).
 *
 * Context CTX_ADMIN ensures this handler only loads on admin requests.
 *
 * @package SdAiAgent\Bootstrap
 * @license GPL-2.0-or-later
 * @since   1.16.0
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1712
 */

declare(strict_types=1);

namespace SdAiAgent\Bootstrap;

use SdAiAgent\Admin\BlockPreferencesPage;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Block Preferences admin sub-page and handles form saves.
 *
 * @since 1.16.0
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_ADMIN,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class BlockPreferencesAdminHandler {

	/**
	 * Hook suffix returned by {@see add_submenu_page()} for our page.
	 *
	 * WordPress passes this suffix to `admin_enqueue_scripts` so we can
	 * scope the enqueue to this page only and avoid loading static
	 * assets on every admin screen. For sub-pages registered under
	 * {@see \SdAiAgent\Admin\UnifiedAdminMenu::SLUG} the suffix is
	 * `{parent_menu_hook}_page_{page_slug}`. We resolve it dynamically
	 * via `get_plugin_page_hookname()` so any future parent-menu
	 * rename remains transparent.
	 *
	 * @since 1.16.0
	 *
	 * @return string Empty string if the page is not registered yet.
	 */
	private static function get_hook_suffix(): string {
		if ( ! function_exists( 'get_plugin_page_hookname' ) ) {
			return '';
		}
		return (string) get_plugin_page_hookname(
			BlockPreferencesPage::PAGE_SLUG,
			\SdAiAgent\Admin\UnifiedAdminMenu::SLUG
		);
	}

	/**
	 * Register the Block Preferences sub-page under the Superdav AI Agent menu.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	#[Action( tag: 'admin_menu', priority: 10 )]
	public function register_page(): void {
		BlockPreferencesPage::register();
	}

	/**
	 * Handle the save form POST before output begins.
	 *
	 * Nonce + capability validation is performed inside
	 * {@see BlockPreferencesPage::handle_save_request()}.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	#[Action( tag: 'admin_init', priority: 10 )]
	public function handle_save(): void {
		BlockPreferencesPage::handle_save_request();
	}

	/**
	 * Enqueue the Block Preferences JS+CSS bundle only on the Block
	 * Preferences sub-page.
	 *
	 * Both files live under `assets/admin/` and are versioned with
	 * `SD_AI_AGENT_VERSION` so cache-busting follows the plugin
	 * release cadence. The JS receives translated strings via
	 * `wp_localize_script()` so the source file ships no server-side
	 * strings.
	 *
	 * @since 1.17.0
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	#[Action( tag: 'admin_enqueue_scripts', priority: 10 )]
	public function enqueue_assets( string $hook_suffix = '' ): void {
		$expected = self::get_hook_suffix();
		if ( '' === $expected || $hook_suffix !== $expected ) {
			return;
		}

		wp_enqueue_style(
			'sd-ai-agent-block-preferences',
			SD_AI_AGENT_URL . 'assets/admin/block-preferences.css',
			array(),
			SD_AI_AGENT_VERSION
		);

		wp_enqueue_script(
			'sd-ai-agent-block-preferences',
			SD_AI_AGENT_URL . 'assets/admin/block-preferences.js',
			array(),
			SD_AI_AGENT_VERSION,
			true
		);

		wp_localize_script(
			'sd-ai-agent-block-preferences',
			'sdAiAgentBlockPrefs',
			array(
				'removeLabel' => __( 'Remove', 'superdav-ai-agent' ),
				'defaultTier' => __( 'acceptable', 'superdav-ai-agent' ),
			)
		);
	}
}
