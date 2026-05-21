<?php
/**
 * DI handler that registers the hidden block validator admin page (GH#1584).
 *
 * Wires {@see \SdAiAgent\Admin\BlockValidatorPage::register()} on `admin_menu`
 * and {@see \SdAiAgent\Admin\BlockValidatorPage::enqueue_assets()} on
 * `admin_enqueue_scripts` so the page only loads its JS bundle when the
 * `sd-ai-agent-block-validator` page is being viewed.
 *
 * Context CTX_ADMIN ensures this handler only registers on admin requests —
 * it has no effect on frontend, REST, or CLI traffic.
 *
 * @package SdAiAgent\Bootstrap
 * @license GPL-2.0-or-later
 * @since   1.11.0
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1584
 */

declare(strict_types=1);

namespace SdAiAgent\Bootstrap;

use SdAiAgent\Admin\BlockValidatorPage;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the hidden block validator admin page and its assets.
 *
 * @since 1.11.0
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_ADMIN,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class BlockValidatorPageHandler {

	/**
	 * Register the hidden admin page.
	 *
	 * @since 1.11.0
	 *
	 * @return void
	 */
	#[Action( tag: 'admin_menu', priority: 99 )]
	public function register_page(): void {
		BlockValidatorPage::register();
	}

	/**
	 * Enqueue validator assets on the validator page only.
	 *
	 * @since 1.11.0
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	#[Action( tag: 'admin_enqueue_scripts', priority: 10 )]
	public function enqueue_assets( string $hook_suffix ): void {
		BlockValidatorPage::enqueue_assets( $hook_suffix );
	}
}
