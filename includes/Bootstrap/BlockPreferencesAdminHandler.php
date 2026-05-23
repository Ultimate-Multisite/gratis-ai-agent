<?php
/**
 * DI handler for the Block Preferences admin sub-page.
 *
 * Wires {@see \SdAiAgent\Admin\BlockPreferencesPage::register()} on
 * `admin_menu` and handles the save POST action on `admin_init` before
 * output begins.
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
}
