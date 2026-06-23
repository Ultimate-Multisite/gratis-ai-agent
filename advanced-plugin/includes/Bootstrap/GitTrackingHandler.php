<?php
/**
 * DI handler for git file-change tracking hooks.
 *
 * Replaces the `GitTrackerManager::register()` call in CoreServicesHandler by
 * wiring each file-lifecycle action directly via `#[Action]` attributes.
 *
 * The snapshot and diff logic lives in
 * {@see \SdAiAgent\Models\GitTrackerManager}. This handler is a thin DI
 * bridge — its only job is hook registration and arg forwarding.
 *
 * @package SdAiAgent\Bootstrap
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Bootstrap;

use SdAiAgent\Models\GitTrackerManager;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Snapshots files before and after AI-initiated write/edit operations.
 *
 * The plugin fires the four `sd_ai_agent_*_file_*` actions from
 * {@see \SdAiAgent\Abilities\FileAbilities}. CTX_GLOBAL is required
 * because file operations can originate from any request context.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_GLOBAL,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class GitTrackingHandler {

	/**
	 * Snapshot a file before a write operation begins.
	 *
	 * @param string $absolute_path Absolute path to the file being written.
	 */
	#[Action( tag: 'sd_ai_agent_before_file_write', priority: 10 )]
	public function on_before_file_write( string $absolute_path ): void {
		GitTrackerManager::on_before_file_write( $absolute_path );
	}

	/**
	 * Snapshot a file before an edit operation begins.
	 *
	 * @param string $absolute_path Absolute path to the file being edited.
	 */
	#[Action( tag: 'sd_ai_agent_before_file_edit', priority: 10 )]
	public function on_before_file_edit( string $absolute_path ): void {
		GitTrackerManager::on_before_file_edit( $absolute_path );
	}

	/**
	 * Record a file modification after a write operation completes.
	 *
	 * @param string $absolute_path Absolute path to the file that was written.
	 */
	#[Action( tag: 'sd_ai_agent_after_file_write', priority: 10 )]
	public function on_after_file_write( string $absolute_path ): void {
		GitTrackerManager::on_after_file_write( $absolute_path );
	}

	/**
	 * Record a file modification after an edit operation completes.
	 *
	 * @param string $absolute_path Absolute path to the file that was edited.
	 */
	#[Action( tag: 'sd_ai_agent_after_file_edit', priority: 10 )]
	public function on_after_file_edit( string $absolute_path ): void {
		GitTrackerManager::on_after_file_edit( $absolute_path );
	}
}
