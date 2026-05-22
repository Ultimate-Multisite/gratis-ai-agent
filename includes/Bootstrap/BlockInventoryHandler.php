<?php
/**
 * DI handler for the block-inventory daily cron refresh.
 *
 * Wires {@see \SdAiAgent\Core\BlockInventory::run_cron_refresh()} to the
 * `sd_ai_agent_refresh_block_usage` cron action so WordPress fires the
 * refresh on the configured daily schedule.
 *
 * CTX_GLOBAL is required because WP-Cron runs in a context that is neither
 * admin nor REST — it needs to be reachable from every request type.
 *
 * @package SdAiAgent\Bootstrap
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1716
 */

declare(strict_types=1);

namespace SdAiAgent\Bootstrap;

use SdAiAgent\Core\BlockInventory;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the daily block-inventory cron refresh hook.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_GLOBAL,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class BlockInventoryHandler {

	/**
	 * Run the scheduled daily block-inventory scan.
	 *
	 * The hook name matches BlockInventory::CRON_HOOK. The scan itself is
	 * handled entirely by {@see BlockInventory::run_cron_refresh()}.
	 *
	 * @return void
	 */
	#[Action( tag: BlockInventory::CRON_HOOK, priority: 10 )]
	public function handle_cron_refresh(): void {
		BlockInventory::run_cron_refresh();
	}
}
