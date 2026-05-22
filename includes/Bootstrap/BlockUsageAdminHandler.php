<?php
/**
 * DI handler for the Block Usage admin sub-page.
 *
 * Wires {@see \SdAiAgent\Admin\BlockUsagePage::register()} on `admin_menu`
 * and handles the refresh POST action on `admin_init` before output begins.
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
}
