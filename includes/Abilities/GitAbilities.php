<?php

declare(strict_types=1);
/**
 * Git-style file tracking abilities for the AI agent.
 *
 * Exposes snapshot, diff, restore, and list operations backed by
 * GitTracker / GitTrackerManager (Models layer). Allows the AI to:
 *   - Snapshot a file before editing (sd-ai-agent/git-snapshot)
 *   - Diff current vs original (sd-ai-agent/git-diff)
 *   - Restore original content (sd-ai-agent/git-restore)
 *   - List all tracked files (sd-ai-agent/git-list)
 *   - Get a summary for a package (sd-ai-agent/git-package-summary)
 *   - Revert all changes for a package (sd-ai-agent/git-revert-package)
 *
 * Note: FileAbilities automatically fires `sd_ai_agent_before_file_write`
 * and `sd_ai_agent_before_file_edit` hooks, which GitTrackerManager hooks
 * into to snapshot files transparently. These abilities provide explicit
 * control and visibility for the AI agent.
 *
 * Mutation gating: `git-restore` and `git-revert-package` write to
 * tracked files via `$wp_filesystem->put_contents()`, so they are gated
 * behind `Features::FILE_WRITE` and disabled in the WordPress.org
 * distribution build. Read-only abilities (`git-snapshot`, `git-diff`,
 * `git-list`, `git-package-summary`) remain available — `git-snapshot`
 * only writes to the plugin's own DB tracking table, not to the
 * filesystem.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Core\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GitAbilities {

	/**
	 * Register all git tracking abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/git-snapshot',
			[
				'label'         => __( 'Snapshot File', 'superdav-ai-agent' ),
				'description'   => __( 'Explicitly snapshot a file before editing. Note: FileAbilities automatically snapshots files on write/edit — use this for manual control.', 'superdav-ai-agent' ),
				'ability_class' => GitSnapshotAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/git-diff',
			[
				'label'         => __( 'Diff File', 'superdav-ai-agent' ),
				'description'   => __( 'Show a unified diff between the original snapshot and the current file content.', 'superdav-ai-agent' ),
				'ability_class' => GitDiffAbility::class,
			]
		);

		// git-restore and git-revert-package mutate tracked files via
		// $wp_filesystem->put_contents() inside wp-content (including
		// plugins/ and themes/), so they are gated behind FILE_WRITE
		// alongside the FileAbilities mutation surface.
		if ( Features::is_enabled( Features::FILE_WRITE ) ) {
			wp_register_ability(
				'sd-ai-agent/git-restore',
				[
					'label'         => __( 'Restore File', 'superdav-ai-agent' ),
					'description'   => __( 'Restore a file to its original snapshotted content, undoing all AI modifications.', 'superdav-ai-agent' ),
					'ability_class' => GitRestoreAbility::class,
				]
			);
		}

		wp_register_ability(
			'sd-ai-agent/git-list',
			[
				'label'         => __( 'List Tracked Files', 'superdav-ai-agent' ),
				'description'   => __( 'List all files that have been snapshotted, with their modification status.', 'superdav-ai-agent' ),
				'ability_class' => GitListAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/git-package-summary',
			[
				'label'         => __( 'Package Change Summary', 'superdav-ai-agent' ),
				'description'   => __( 'Get a summary of tracked and modified files for a specific plugin or theme package.', 'superdav-ai-agent' ),
				'ability_class' => GitPackageSummaryAbility::class,
			]
		);

		if ( Features::is_enabled( Features::FILE_WRITE ) ) {
			wp_register_ability(
				'sd-ai-agent/git-revert-package',
				[
					'label'         => __( 'Revert Package', 'superdav-ai-agent' ),
					'description'   => __( 'Revert all modified files in a plugin or theme back to their original snapshotted content.', 'superdav-ai-agent' ),
					'ability_class' => GitRevertPackageAbility::class,
				]
			);
		}
	}
}
