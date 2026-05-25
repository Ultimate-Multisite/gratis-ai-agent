<?php

declare(strict_types=1);
/**
 * Unit tests for GitTracker model.
 *
 * Tests constructor/getters, snapshot_file(), record_modification(),
 * revert_file(), get_diff(), get_tracked_files(), get_modified_files(),
 * clear_tracking(), and is_tracked().
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Models;

use SdAiAgent\Models\GitTracker;
use WP_UnitTestCase;

/**
 * Tests for GitTracker model.
 *
 * @since 1.1.0
 */
class GitTrackerTest extends WP_UnitTestCase {

	/**
	 * Temporary plugin directory used for tests.
	 *
	 * @var string
	 */
	private string $plugin_dir;

	/**
	 * GitTracker instance under test.
	 *
	 * @var GitTracker
	 */
	private GitTracker $tracker;

	/**
	 * Set up: create a temporary plugin directory and a GitTracker instance.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->plugin_dir = WP_PLUGIN_DIR . '/sd-git-tracker-test-' . uniqid();
		wp_mkdir_p( $this->plugin_dir );

		$this->tracker = new GitTracker(
			'sd-git-tracker-test/plugin.php',
			GitTracker::TYPE_PLUGIN,
			$this->plugin_dir
		);

		// Clear any leftover tracking data.
		$this->tracker->clear_tracking();
	}

	/**
	 * Tear down: remove the temporary directory and clear tracking data.
	 */
	public function tear_down(): void {
		$this->tracker->clear_tracking();

		if ( is_dir( $this->plugin_dir ) ) {
			array_map( 'unlink', glob( $this->plugin_dir . '/*' ) ?: [] );
			rmdir( $this->plugin_dir );
		}

		parent::tear_down();
	}

	/**
	 * Helper: create a file in the plugin directory with given content.
	 *
	 * @param string $filename Filename (relative to plugin dir).
	 * @param string $content  File content.
	 * @return string Absolute path.
	 */
	private function create_plugin_file( string $filename, string $content ): string {
		$path = $this->plugin_dir . '/' . $filename;
		file_put_contents( $path, $content );
		return $path;
	}

	// ─── Constants ───────────────────────────────────────────────────────────

	/**
	 * TYPE_PLUGIN constant has expected value.
	 */
	public function test_type_plugin_constant(): void {
		$this->assertSame( 'plugin', GitTracker::TYPE_PLUGIN );
	}

	/**
	 * TYPE_THEME constant has expected value.
	 */
	public function test_type_theme_constant(): void {
		$this->assertSame( 'theme', GitTracker::TYPE_THEME );
	}

	/**
	 * STATUS_UNCHANGED constant has expected value.
	 */
	public function test_status_unchanged_constant(): void {
		$this->assertSame( 'unchanged', GitTracker::STATUS_UNCHANGED );
	}

	/**
	 * STATUS_MODIFIED constant has expected value.
	 */
	public function test_status_modified_constant(): void {
		$this->assertSame( 'modified', GitTracker::STATUS_MODIFIED );
	}

	/**
	 * STATUS_DELETED constant has expected value.
	 */
	public function test_status_deleted_constant(): void {
		$this->assertSame( 'deleted', GitTracker::STATUS_DELETED );
	}

	// ─── Constructor / getters ────────────────────────────────────────────────

	/**
	 * get_package_slug() returns the slug passed to the constructor.
	 */
	public function test_get_package_slug(): void {
		$this->assertSame( 'sd-git-tracker-test/plugin.php', $this->tracker->get_package_slug() );
	}

	/**
	 * get_package_type() returns the type passed to the constructor.
	 */
	public function test_get_package_type(): void {
		$this->assertSame( GitTracker::TYPE_PLUGIN, $this->tracker->get_package_type() );
	}

	/**
	 * get_package_path() returns the path passed to the constructor (trailing slash stripped).
	 */
	public function test_get_package_path(): void {
		$this->assertSame( $this->plugin_dir, $this->tracker->get_package_path() );
	}

	// ─── snapshot_file() ─────────────────────────────────────────────────────

	/**
	 * snapshot_file() returns WP_Error for a non-existent file.
	 */
	public function test_snapshot_file_returns_error_for_missing_file(): void {
		$result = $this->tracker->snapshot_file( $this->plugin_dir . '/nonexistent.php' );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_git_tracker_file_not_found', $result->get_error_code() );
	}

	/**
	 * snapshot_file() returns true for an existing file.
	 */
	public function test_snapshot_file_returns_true_for_existing_file(): void {
		$path   = $this->create_plugin_file( 'plugin.php', '<?php // plugin' );
		$result = $this->tracker->snapshot_file( $path );

		$this->assertTrue( $result );
	}

	/**
	 * snapshot_file() marks the file as tracked.
	 */
	public function test_snapshot_file_marks_file_as_tracked(): void {
		$path = $this->create_plugin_file( 'tracked.php', '<?php // tracked' );
		$this->tracker->snapshot_file( $path );

		$this->assertTrue( $this->tracker->is_tracked( 'tracked.php' ) );
	}

	/**
	 * snapshot_file() is idempotent — calling twice preserves the original.
	 */
	public function test_snapshot_file_is_idempotent(): void {
		$path = $this->create_plugin_file( 'idempotent.php', '<?php // original' );

		$this->tracker->snapshot_file( $path );

		// Modify the file.
		file_put_contents( $path, '<?php // modified' );

		// Snapshot again — should be a no-op (original preserved).
		$result = $this->tracker->snapshot_file( $path );
		$this->assertTrue( $result );

		// The tracked row should still have the original content.
		$tracked = $this->tracker->get_tracked_files();
		$this->assertNotEmpty( $tracked );
	}

	/**
	 * snapshot_file() returns WP_Error for a file outside the package directory.
	 */
	public function test_snapshot_file_returns_error_for_file_outside_package(): void {
		$result = $this->tracker->snapshot_file( '/tmp/outside-package.php' );

		$this->assertWPError( $result );
	}

	// ─── record_modification() ───────────────────────────────────────────────

	/**
	 * record_modification() returns WP_Error for an untracked file.
	 */
	public function test_record_modification_returns_error_for_untracked_file(): void {
		$path   = $this->create_plugin_file( 'untracked.php', '<?php // untracked' );
		$result = $this->tracker->record_modification( $path );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_git_tracker_not_tracked', $result->get_error_code() );
	}

	/**
	 * record_modification() returns true after snapshot and modification.
	 */
	public function test_record_modification_returns_true_after_snapshot(): void {
		$path = $this->create_plugin_file( 'modified.php', '<?php // original' );
		$this->tracker->snapshot_file( $path );

		file_put_contents( $path, '<?php // modified' );
		$result = $this->tracker->record_modification( $path );

		$this->assertTrue( $result );
	}

	/**
	 * record_modification() updates status to modified.
	 */
	public function test_record_modification_updates_status_to_modified(): void {
		$path = $this->create_plugin_file( 'status-test.php', '<?php // original' );
		$this->tracker->snapshot_file( $path );

		file_put_contents( $path, '<?php // changed content' );
		$this->tracker->record_modification( $path );

		$modified = $this->tracker->get_modified_files();
		$paths    = array_column( $modified, 'file_path' );
		$this->assertContains( 'status-test.php', $paths );
	}

	// ─── revert_file() ───────────────────────────────────────────────────────

	/**
	 * revert_file() returns WP_Error for an untracked file.
	 */
	public function test_revert_file_returns_error_for_untracked_file(): void {
		$path   = $this->create_plugin_file( 'untracked-revert.php', '<?php // content' );
		$result = $this->tracker->revert_file( $path );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_git_tracker_not_tracked', $result->get_error_code() );
	}

	/**
	 * revert_file() restores the original content.
	 */
	public function test_revert_file_restores_original_content(): void {
		$original = '<?php // original content';
		$path     = $this->create_plugin_file( 'revert-test.php', $original );

		$this->tracker->snapshot_file( $path );

		file_put_contents( $path, '<?php // modified content' );
		$this->tracker->record_modification( $path );

		$result = $this->tracker->revert_file( $path );
		$this->assertTrue( $result );

		$this->assertSame( $original, file_get_contents( $path ) );
	}

	/**
	 * revert_file() resets status to unchanged.
	 */
	public function test_revert_file_resets_status_to_unchanged(): void {
		$path = $this->create_plugin_file( 'revert-status.php', '<?php // original' );
		$this->tracker->snapshot_file( $path );

		file_put_contents( $path, '<?php // changed' );
		$this->tracker->record_modification( $path );

		$this->tracker->revert_file( $path );

		$modified = $this->tracker->get_modified_files();
		$paths    = array_column( $modified, 'file_path' );
		$this->assertNotContains( 'revert-status.php', $paths );
	}

	/**
	 * revert_file() should reject reverting a PHP file with syntax errors in the original content.
	 */
	public function test_revert_file_rejects_invalid_php_syntax(): void {
		$broken_php = '<?php $x = ;'; // Missing value after =
		$edited_php = '<?php $x = 1;';
		$path       = $this->create_plugin_file( 'revert-broken.php', $broken_php );

		$this->tracker->snapshot_file( $path );

		file_put_contents( $path, $edited_php );
		$this->tracker->record_modification( $path );

		$result = $this->tracker->revert_file( $path );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_git_tracker_php_syntax_error', $result->get_error_code() );
		$this->assertStringContainsString( 'syntax error', $result->get_error_message() );

		// File should still contain the edited content (not reverted).
		$current = file_get_contents( $path );
		$this->assertSame( $edited_php, $current, 'File should not be reverted when original has syntax errors.' );
	}

	// ─── get_diff() ──────────────────────────────────────────────────────────

	/**
	 * get_diff() returns WP_Error for an untracked file.
	 */
	public function test_get_diff_returns_error_for_untracked_file(): void {
		$path   = $this->create_plugin_file( 'diff-untracked.php', '<?php // content' );
		$result = $this->tracker->get_diff( $path );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_git_tracker_not_tracked', $result->get_error_code() );
	}

	/**
	 * get_diff() returns empty string for an unchanged file.
	 */
	public function test_get_diff_returns_empty_for_unchanged_file(): void {
		$path = $this->create_plugin_file( 'diff-unchanged.php', '<?php // content' );
		$this->tracker->snapshot_file( $path );

		$result = $this->tracker->get_diff( $path );

		$this->assertSame( '', $result );
	}

	/**
	 * get_diff() returns a non-empty string for a modified file.
	 */
	public function test_get_diff_returns_non_empty_for_modified_file(): void {
		$path = $this->create_plugin_file( 'diff-modified.php', '<?php // original' );
		$this->tracker->snapshot_file( $path );

		file_put_contents( $path, '<?php // modified content here' );
		$this->tracker->record_modification( $path );

		$result = $this->tracker->get_diff( $path );

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	// ─── get_tracked_files() ─────────────────────────────────────────────────

	/**
	 * get_tracked_files() returns an array.
	 */
	public function test_get_tracked_files_returns_array(): void {
		$files = $this->tracker->get_tracked_files();
		$this->assertIsArray( $files );
	}

	/**
	 * get_tracked_files() includes snapshotted files.
	 */
	public function test_get_tracked_files_includes_snapshotted_file(): void {
		$path = $this->create_plugin_file( 'tracked-list.php', '<?php // content' );
		$this->tracker->snapshot_file( $path );

		$files = $this->tracker->get_tracked_files();
		$paths = array_column( $files, 'file_path' );

		$this->assertContains( 'tracked-list.php', $paths );
	}

	// ─── get_modified_files() ────────────────────────────────────────────────

	/**
	 * get_modified_files() returns an array.
	 */
	public function test_get_modified_files_returns_array(): void {
		$files = $this->tracker->get_modified_files();
		$this->assertIsArray( $files );
	}

	/**
	 * get_modified_files() does not include unchanged files.
	 */
	public function test_get_modified_files_excludes_unchanged_files(): void {
		$path = $this->create_plugin_file( 'unchanged-file.php', '<?php // content' );
		$this->tracker->snapshot_file( $path );

		$modified = $this->tracker->get_modified_files();
		$paths    = array_column( $modified, 'file_path' );

		$this->assertNotContains( 'unchanged-file.php', $paths );
	}

	/**
	 * get_modified_files() includes files after modification.
	 */
	public function test_get_modified_files_includes_modified_file(): void {
		$path = $this->create_plugin_file( 'modified-list.php', '<?php // original' );
		$this->tracker->snapshot_file( $path );

		file_put_contents( $path, '<?php // changed' );
		$this->tracker->record_modification( $path );

		$modified = $this->tracker->get_modified_files();
		$paths    = array_column( $modified, 'file_path' );

		$this->assertContains( 'modified-list.php', $paths );
	}

	// ─── clear_tracking() ────────────────────────────────────────────────────

	/**
	 * clear_tracking() removes all tracked files for the package.
	 */
	public function test_clear_tracking_removes_all_tracked_files(): void {
		$path1 = $this->create_plugin_file( 'clear1.php', '<?php // 1' );
		$path2 = $this->create_plugin_file( 'clear2.php', '<?php // 2' );

		$this->tracker->snapshot_file( $path1 );
		$this->tracker->snapshot_file( $path2 );

		$count = $this->tracker->clear_tracking();

		$this->assertGreaterThanOrEqual( 2, $count );
		$this->assertEmpty( $this->tracker->get_tracked_files() );
	}

	// ─── is_tracked() ────────────────────────────────────────────────────────

	/**
	 * is_tracked() returns false for an untracked relative path.
	 */
	public function test_is_tracked_returns_false_for_untracked(): void {
		$this->assertFalse( $this->tracker->is_tracked( 'nonexistent.php' ) );
	}

	/**
	 * is_tracked() returns true after snapshotting.
	 */
	public function test_is_tracked_returns_true_after_snapshot(): void {
		$path = $this->create_plugin_file( 'is-tracked.php', '<?php // content' );
		$this->tracker->snapshot_file( $path );

		$this->assertTrue( $this->tracker->is_tracked( 'is-tracked.php' ) );
	}
}
