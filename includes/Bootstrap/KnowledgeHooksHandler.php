<?php
/**
 * DI handler for knowledge-base content-sync hooks.
 *
 * Replaces the `KnowledgeHooks::register()` call in CoreServicesHandler by
 * wiring each WordPress action directly via `#[Action]` attributes.
 *
 * The underlying sync logic lives in
 * {@see \SdAiAgent\Knowledge\KnowledgeHooks}. This handler is a thin
 * DI bridge — its only job is hook registration and arg forwarding.
 *
 * @package SdAiAgent\Bootstrap
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Bootstrap;

use SdAiAgent\Knowledge\KnowledgeHooks;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the knowledge base in sync with WordPress post content.
 *
 * CTX_GLOBAL is required because `save_post` fires in admin, REST, CLI,
 * and cron contexts, and the reindex cron hook fires in CTX_CRON.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_GLOBAL,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class KnowledgeHooksHandler {

	/**
	 * Index or update a post in the knowledge base after it is saved.
	 *
	 * Runs at priority 20 (same as the original `KnowledgeHooks::register()`)
	 * so it fires after core post-save processing is complete.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	#[Action( tag: 'save_post', priority: 20 )]
	public function handle_save_post( int $post_id, \WP_Post $post ): void {
		KnowledgeHooks::handle_save_post( $post_id, $post );
	}

	/**
	 * Remove a post from the knowledge base when it is deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	#[Action( tag: 'delete_post', priority: 10 )]
	public function handle_delete_post( int $post_id ): void {
		KnowledgeHooks::handle_delete_post( $post_id );
	}

	/**
	 * Run the scheduled full reindex cron job.
	 */
	#[Action( tag: KnowledgeHooks::CRON_HOOK, priority: 10 )]
	public function handle_cron_reindex(): void {
		KnowledgeHooks::handle_cron_reindex();
	}
}
