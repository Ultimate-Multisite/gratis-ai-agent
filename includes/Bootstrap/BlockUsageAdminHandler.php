<?php
/**
 * DI handler for the Block Usage admin sub-page.
 *
 * Wires {@see \SdAiAgent\Admin\BlockUsagePage::register()} on `admin_menu`,
 * handles the refresh POST action on `admin_init` before output begins,
 * and enqueues the page-scoped JS+CSS bundle on `admin_enqueue_scripts`
 * (no inline <script>/<style> tags per the WordPress.org Plugin Review
 * guideline).
 *
 * Context CTX_ADMIN ensures this handler only loads on admin requests.
 *
 * @package SdAiAgent\Bootstrap
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1716
 */

declare(strict_types=1);

namespace SdAiAgent\Bootstrap;

use SdAiAgent\Admin\BlockUsagePage;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Block Usage admin sub-page and handles its form submissions.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_ADMIN,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class BlockUsageAdminHandler {

	/**
	 * Hook suffix returned by {@see add_submenu_page()} for our page.
	 *
	 * Resolved via `get_plugin_page_hookname()` so any future
	 * parent-menu rename stays transparent. See the matching method
	 * in {@see BlockPreferencesAdminHandler} for the rationale.
	 *
	 * @return string Empty string if the page is not registered yet.
	 */
	private static function get_hook_suffix(): string {
		if ( ! function_exists( 'get_plugin_page_hookname' ) ) {
			return '';
		}
		return (string) get_plugin_page_hookname(
			BlockUsagePage::PAGE_SLUG,
			\SdAiAgent\Admin\UnifiedAdminMenu::SLUG
		);
	}

	/**
	 * Register the Block Usage sub-page under the Superdav AI Agent menu.
	 *
	 * @return void
	 */
	#[Action( tag: 'admin_menu', priority: 10 )]
	public function register_page(): void {
		BlockUsagePage::register();
	}

	/**
	 * Handle the refresh form POST before output begins.
	 *
	 * Nonce + capability validation is performed inside
	 * {@see BlockUsagePage::handle_refresh_request()}.
	 *
	 * @return void
	 */
	#[Action( tag: 'admin_init', priority: 10 )]
	public function handle_refresh(): void {
		BlockUsagePage::handle_refresh_request();
	}

	/**
	 * Enqueue the Block Usage JS+CSS bundle only on the Block Usage
	 * sub-page.
	 *
	 * Both files live under `assets/admin/` and are versioned with
	 * `SD_AI_AGENT_VERSION`. The JS receives the translated confirm
	 * message via `wp_localize_script()` so the source file ships no
	 * server-side strings.
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
			'sd-ai-agent-block-usage',
			SD_AI_AGENT_URL . 'assets/admin/block-usage.css',
			array(),
			SD_AI_AGENT_VERSION
		);

		wp_enqueue_script(
			'sd-ai-agent-block-usage',
			SD_AI_AGENT_URL . 'assets/admin/block-usage.js',
			array(),
			SD_AI_AGENT_VERSION,
			true
		);

		wp_localize_script(
			'sd-ai-agent-block-usage',
			'sdAiAgentBlockUsage',
			array(
				'confirmMessage' => __(
					'Refresh block usage stats now? This may take a moment on large sites.',
					'superdav-ai-agent'
				),
			)
		);
	}
}
