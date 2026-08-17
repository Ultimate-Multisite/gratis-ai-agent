<?php

declare(strict_types=1);
/**
 * Unit tests for GitTrackerManager.
 *
 * Tests the registry/factory behaviour, path resolution, site-wide queries,
 * package revert, and WordPress hook registration.
 *
 * These tests run inside wp-env (real MySQL + WordPress) so WP_PLUGIN_DIR,
 * get_theme_root(), and the database are all available.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Models;

use SdAiAgent\Models\GitTracker;
use SdAiAgent\Models\GitTrackerManager;
use WP_UnitTestCase;

/**
 * Tests for GitTrackerManager.
 *
 * @since 1.1.0
 */
class GitTrackerManagerTest extends WP_UnitTestCase {

	/**
	 * Temporary plugin directory created for tests.
	 *
	 * @var string
	 */
	private string $plugin_dir;

	/**
	 * Plugin file slug (relative to WP_PLUGIN_DIR).
	 *
	 * @var string
	 */
	private string $plugin_slug;

	/**
	 * Temporary theme directory created for tests.
	 *
	 * @var string
	 */
	private string $theme_dir;

	/**
	 * Theme slug (directory name).
	 *
	 * @var string
	 */
	private string $theme_slug;

	/**
	 * Set up: create temporary plugin and theme directories with stub files.
	 */
	public function set_up(): void {
		parent::set_up();

		// Clear the in-memory tracker cache between tests.
		GitTrackerManager::clear_cache();

		// Create a temporary plugin directory.
		$this->plugin_slug = 'sd-test-plugin/sd-test-plugin.php';
		$this->plugin_dir  = WP_PLUGIN_DIR . '/sd-test-plugin';
		wp_mkdir_p( $this->plugin_dir );
		file_put_contents(
			$this->plugin_dir . '/sd-test-plugin.php',
			"<?php\n/*\n * Plugin Name: SD Test Plugin\n */\n"
		);

		// Create a temporary theme directory.
		$this->theme_slug = 'sd-test-theme';
		$this->theme_dir  = get_theme_root() . '/' . $this->theme_slug;
		wp_mkdir_p( $this->theme_dir );
		file_put_contents(
			$this->theme_dir . '/style.css',
			"/*\nTheme Name: SD Test Theme\n*/\n"
		);
	}

	/**
	 * Tear down: remove temporary directories and clear tracker cache.
	 */
	public function tear_down(): void {
		GitTrackerManager::clear_cache();

		// Remove test plugin files.
		if ( is_dir( $this->plugin_dir ) ) {
			array_map( 'unlink', glob( $this->plugin_dir . '/*' ) ?: [] );
			rmdir( $this->plugin_dir );
		}

		// Remove test theme files.
		if ( is_dir( $this->theme_dir ) ) {
			array_map( 'unlink', glob( $this->theme_dir . '/*' ) ?: [] );
			rmdir( $this->theme_dir );
		}

		parent::tear_down();
	}

	// ─── for_plugin() ────────────────────────────────────────────────────────

	/**
	 * for_plugin() returns a GitTracker for a valid plugin directory.
	 */
	public function test_for_plugin_returns_tracker_for_valid_plugin(): void {
		$tracker = GitTrackerManager::for_plugin( $this->plugin_slug );

		$this->assertInstanceOf( GitTracker::class, $tracker );
		$this->assertSame( $this->plugin_slug, $tracker->get_package_slug() );
		$this->assertSame( GitTracker::TYPE_PLUGIN, $tracker->get_package_type() );
	}

	/**
	 * for_plugin() returns WP_Error for a non-existent plugin.
	 */
	public function test_for_plugin_returns_error_for_missing_plugin(): void {
		$result = GitTrackerManager::for_plugin( 'nonexistent-plugin/nonexistent-plugin.php' );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_git_tracker_plugin_not_found', $result->get_error_code() );
	}

	/**
	 * for_plugin() returns the same instance on repeated calls (in-memory cache).
	 */
	public function test_for_plugin_caches_instance(): void {
		$tracker1 = GitTrackerManager::for_plugin( $this->plugin_slug );
		$tracker2 = GitTrackerManager::for_plugin( $this->plugin_slug );

		$this->assertSame( $tracker1, $tracker2 );
	}

	// ─── for_theme() ─────────────────────────────────────────────────────────

	/**
	 * for_theme() returns a GitTracker for a valid theme directory.
	 */
	public function test_for_theme_returns_tracker_for_valid_theme(): void {
		$tracker = GitTrackerManager::for_theme( $this->theme_slug );

		$this->assertInstanceOf( GitTracker::class, $tracker );
		$this->assertSame( $this->theme_slug, $tracker->get_package_slug() );
		$this->assertSame( GitTracker::TYPE_THEME, $tracker->get_package_type() );
	}

	/**
	 * for_theme() returns WP_Error for a non-existent theme.
	 */
	public function test_for_theme_returns_error_for_missing_theme(): void {
		$result = GitTrackerManager::for_theme( 'nonexistent-theme-xyz' );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_git_tracker_theme_not_found', $result->get_error_code() );
	}

	/**
	 * for_theme() returns the same instance on repeated calls (in-memory cache).
	 */
	public function test_for_theme_caches_instance(): void {
		$tracker1 = GitTrackerManager::for_theme( $this->theme_slug );
		$tracker2 = GitTrackerManager::for_theme( $this->theme_slug );

		$this->assertSame( $tracker1, $tracker2 );
	}

	// ─── for_file() ──────────────────────────────────────────────────────────

	/**
	 * for_file() resolves a file inside a plugin directory to the correct tracker.
	 */
	public function test_for_file_resolves_plugin_file(): void {
		$file_path = $this->plugin_dir . '/sd-test-plugin.php';
		$tracker   = GitTrackerManager::for_file( $file_path );

		$this->assertInstanceOf( GitTracker::class, $tracker );
		$this->assertSame( GitTracker::TYPE_PLUGIN, $tracker->get_package_type() );
	}

	/**
	 * for_file() resolves a file inside a theme directory to the correct tracker.
	 */
	public function test_for_file_resolves_theme_file(): void {
		$file_path = $this->theme_dir . '/style.css';
		$tracker   = GitTrackerManager::for_file( $file_path );

		$this->assertInstanceOf( GitTracker::class, $tracker );
		$this->assertSame( GitTracker::TYPE_THEME, $tracker->get_package_type() );
		$this->assertSame( $this->theme_slug, $tracker->get_package_slug() );
	}

	/**
	 * for_file() returns WP_Error for a file outside plugins and themes.
	 */
	public function test_for_file_returns_error_for_file_outside_packages(): void {
		$result = GitTrackerManager::for_file( '/tmp/some-random-file.php' );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_git_tracker_outside_packages', $result->get_error_code() );
	}

	// ─── snapshot_before_modify() ────────────────────────────────────────────

	/**
	 * snapshot_before_modify() returns true for a file inside a plugin.
	 */
	public function test_snapshot_before_modify_succeeds_for_plugin_file(): void {
		$file_path = $this->plugin_dir . '/sd-test-plugin.php';
		$result    = GitTrackerManager::snapshot_before_modify( $file_path );

		$this->assertTrue( $result );
	}

	/**
	 * Different packages may track the same ordinary relative file path.
	 */
	public function test_snapshot_scopes_relative_file_paths_to_their_package(): void {
		$plugin_style = $this->plugin_dir . '/style.css';
		$theme_style  = $this->theme_dir . '/style.css';
		file_put_contents( $plugin_style, '/* Plugin styles. */' );

		$this->assertTrue( GitTrackerManager::snapshot_before_modify( $plugin_style ) );
		$this->assertTrue( GitTrackerManager::snapshot_before_modify( $theme_style ) );

		$plugin_tracker = GitTrackerManager::for_plugin( $this->plugin_slug );
		$theme_tracker  = GitTrackerManager::for_theme( $this->theme_slug );
		$this->assertInstanceOf( GitTracker::class, $plugin_tracker );
		$this->assertInstanceOf( GitTracker::class, $theme_tracker );
		$this->assertTrue( $plugin_tracker->is_tracked( 'style.css' ) );
		$this->assertTrue( $theme_tracker->is_tracked( 'style.css' ) );
	}

	/**
	 * snapshot_before_modify() silently succeeds (returns true) for files outside packages.
	 */
	public function test_snapshot_before_modify_silently_succeeds_outside_packages(): void {
		// A file in /tmp is outside plugins/themes — should not error.
		$result = GitTrackerManager::snapshot_before_modify( '/tmp/not-a-plugin-file.php' );

		$this->assertTrue( $result );
	}

	// ─── record_modification() ───────────────────────────────────────────────

	/**
	 * record_modification() silently succeeds for files outside packages.
	 */
	public function test_record_modification_silently_succeeds_outside_packages(): void {
		$result = GitTrackerManager::record_modification( '/tmp/not-a-plugin-file.php' );

		$this->assertTrue( $result );
	}

	/**
	 * record_modification() records a change after snapshot_before_modify().
	 */
	public function test_record_modification_after_snapshot(): void {
		$file_path = $this->plugin_dir . '/sd-test-plugin.php';

		// Snapshot the original.
		GitTrackerManager::snapshot_before_modify( $file_path );

		// Modify the file.
		file_put_contents( $file_path, "<?php\n// Modified content\n" );

		// Record the modification.
		$result = GitTrackerManager::record_modification( $file_path );

		$this->assertTrue( $result );
	}

	// ─── get_all_tracked_files() ─────────────────────────────────────────────

	/**
	 * get_all_tracked_files() returns an array (empty when nothing tracked).
	 */
	public function test_get_all_tracked_files_returns_array(): void {
		$files = GitTrackerManager::get_all_tracked_files();

		$this->assertIsArray( $files );
	}

	/**
	 * get_all_tracked_files() returns tracked files after snapshotting.
	 */
	public function test_get_all_tracked_files_includes_snapshotted_file(): void {
		$file_path = $this->plugin_dir . '/sd-test-plugin.php';
		GitTrackerManager::snapshot_before_modify( $file_path );

		$files = GitTrackerManager::get_all_tracked_files();

		$this->assertNotEmpty( $files );

		$paths = array_column( (array) $files, 'file_path' );
		$this->assertContains( 'sd-test-plugin.php', $paths );
	}

	/**
	 * get_all_tracked_files() with status filter returns only matching rows.
	 */
	public function test_get_all_tracked_files_filters_by_status(): void {
		$file_path = $this->plugin_dir . '/sd-test-plugin.php';
		GitTrackerManager::snapshot_before_modify( $file_path );

		// Filter for unchanged — should include our freshly snapshotted file.
		$unchanged = GitTrackerManager::get_all_tracked_files( GitTracker::STATUS_UNCHANGED );
		$this->assertIsArray( $unchanged );

		// Filter for modified — should not include our unchanged file.
		$modified = GitTrackerManager::get_all_tracked_files( GitTracker::STATUS_MODIFIED );
		$this->assertIsArray( $modified );

		$unchanged_paths = array_column( (array) $unchanged, 'file_path' );
		$modified_paths  = array_column( (array) $modified, 'file_path' );

		$this->assertContains( 'sd-test-plugin.php', $unchanged_paths );
		$this->assertNotContains( 'sd-test-plugin.php', $modified_paths );
	}

	// ─── get_modified_packages() ─────────────────────────────────────────────

	/**
	 * get_modified_packages() returns an array.
	 */
	public function test_get_modified_packages_returns_array(): void {
		$packages = GitTrackerManager::get_modified_packages();

		$this->assertIsArray( $packages );
	}

	/**
	 * get_modified_packages() includes a package after a file is modified.
	 */
	public function test_get_modified_packages_includes_package_after_modification(): void {
		$file_path = $this->plugin_dir . '/sd-test-plugin.php';

		// Snapshot then modify.
		GitTrackerManager::snapshot_before_modify( $file_path );
		file_put_contents( $file_path, "<?php\n// Changed\n" );
		GitTrackerManager::record_modification( $file_path );

		$packages = GitTrackerManager::get_modified_packages();
		$slugs    = array_column( $packages, 'slug' );

		$this->assertContains( $this->plugin_slug, $slugs );
	}

	// ─── get_package_summary() ───────────────────────────────────────────────

	/**
	 * get_package_summary() returns a structured array for a valid plugin.
	 */
	public function test_get_package_summary_returns_array_for_valid_plugin(): void {
		$file_path = $this->plugin_dir . '/sd-test-plugin.php';
		GitTrackerManager::snapshot_before_modify( $file_path );

		$summary = GitTrackerManager::get_package_summary( $this->plugin_slug, GitTracker::TYPE_PLUGIN );

		$this->assertIsArray( $summary );
		$this->assertArrayHasKey( 'slug', $summary );
		$this->assertArrayHasKey( 'type', $summary );
		$this->assertArrayHasKey( 'path', $summary );
		$this->assertArrayHasKey( 'total_tracked', $summary );
		$this->assertArrayHasKey( 'modified_count', $summary );
		$this->assertArrayHasKey( 'by_status', $summary );
		$this->assertArrayHasKey( 'modified_files', $summary );

		$this->assertSame( $this->plugin_slug, $summary['slug'] );
		$this->assertSame( GitTracker::TYPE_PLUGIN, $summary['type'] );
		$this->assertGreaterThanOrEqual( 1, $summary['total_tracked'] );
		$this->assertSame( 0, $summary['modified_count'] );
	}

	/**
	 * get_package_summary() returns WP_Error for a non-existent plugin.
	 */
	public function test_get_package_summary_returns_error_for_missing_plugin(): void {
		$result = GitTrackerManager::get_package_summary(
			'nonexistent-plugin/nonexistent-plugin.php',
			GitTracker::TYPE_PLUGIN
		);

		$this->assertWPError( $result );
	}

	/**
	 * get_package_summary() reflects modified_count after a file is changed.
	 */
	public function test_get_package_summary_reflects_modified_count(): void {
		$file_path = $this->plugin_dir . '/sd-test-plugin.php';

		GitTrackerManager::snapshot_before_modify( $file_path );
		file_put_contents( $file_path, "<?php\n// Modified\n" );
		GitTrackerManager::record_modification( $file_path );

		$summary = GitTrackerManager::get_package_summary( $this->plugin_slug, GitTracker::TYPE_PLUGIN );

		$this->assertIsArray( $summary );
		$this->assertSame( 1, $summary['modified_count'] );
		$this->assertCount( 1, $summary['modified_files'] );
	}

	// ─── revert_package() ────────────────────────────────────────────────────

	/**
	 * revert_package() returns a result array with reverted/failed/errors keys.
	 */
	public function test_revert_package_returns_result_array(): void {
		$result = GitTrackerManager::revert_package( $this->plugin_slug, GitTracker::TYPE_PLUGIN );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'reverted', $result );
		$this->assertArrayHasKey( 'failed', $result );
		$this->assertArrayHasKey( 'errors', $result );
	}

	/**
	 * revert_package() reverts a modified file and reports reverted count.
	 */
	public function test_revert_package_reverts_modified_file(): void {
		$file_path     = $this->plugin_dir . '/sd-test-plugin.php';
		$original_text = "<?php\n/*\n * Plugin Name: SD Test Plugin\n */\n";

		// Snapshot original.
		GitTrackerManager::snapshot_before_modify( $file_path );

		// Modify the file.
		file_put_contents( $file_path, "<?php\n// Completely different content\n" );
		GitTrackerManager::record_modification( $file_path );

		// Revert.
		$result = GitTrackerManager::revert_package( $this->plugin_slug, GitTracker::TYPE_PLUGIN );

		$this->assertSame( 1, $result['reverted'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertSame( $original_text, file_get_contents( $file_path ) );
	}

	/**
	 * revert_package() returns WP_Error in errors array for a non-existent plugin.
	 */
	public function test_revert_package_returns_error_for_missing_plugin(): void {
		$result = GitTrackerManager::revert_package(
			'nonexistent-plugin/nonexistent-plugin.php',
			GitTracker::TYPE_PLUGIN
		);

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['reverted'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertWPError( $result['errors'][0] );
	}

	// ─── clear_cache() ───────────────────────────────────────────────────────

	/**
	 * clear_cache() causes for_plugin() to return a new instance.
	 */
	public function test_clear_cache_causes_new_instance_on_next_call(): void {
		$tracker1 = GitTrackerManager::for_plugin( $this->plugin_slug );
		GitTrackerManager::clear_cache();
		$tracker2 = GitTrackerManager::for_plugin( $this->plugin_slug );

		// After clearing the cache, a new instance is created.
		$this->assertNotSame( $tracker1, $tracker2 );
	}

	// ─── register() / WordPress hooks ────────────────────────────────────────

	/**
	 * register() adds the expected WordPress action hooks.
	 */
	public function test_register_adds_action_hooks(): void {
		// Remove any existing hooks first to ensure a clean state.
		remove_all_actions( 'sd_ai_agent_before_file_write' );
		remove_all_actions( 'sd_ai_agent_before_file_edit' );
		remove_all_actions( 'sd_ai_agent_after_file_write' );
		remove_all_actions( 'sd_ai_agent_after_file_edit' );

		GitTrackerManager::register();

		$this->assertGreaterThan(
			0,
			has_action( 'sd_ai_agent_before_file_write', [ GitTrackerManager::class, 'on_before_file_write' ] ),
			'Expected sd_ai_agent_before_file_write hook to be registered.'
		);
		$this->assertGreaterThan(
			0,
			has_action( 'sd_ai_agent_before_file_edit', [ GitTrackerManager::class, 'on_before_file_edit' ] ),
			'Expected sd_ai_agent_before_file_edit hook to be registered.'
		);
		$this->assertGreaterThan(
			0,
			has_action( 'sd_ai_agent_after_file_write', [ GitTrackerManager::class, 'on_after_file_write' ] ),
			'Expected sd_ai_agent_after_file_write hook to be registered.'
		);
		$this->assertGreaterThan(
			0,
			has_action( 'sd_ai_agent_after_file_edit', [ GitTrackerManager::class, 'on_after_file_edit' ] ),
			'Expected sd_ai_agent_after_file_edit hook to be registered.'
		);
	}

	/**
	 * Firing sd_ai_agent_before_file_write snapshots the file.
	 */
	public function test_before_file_write_hook_snapshots_file(): void {
		GitTrackerManager::register();

		$file_path = $this->plugin_dir . '/sd-test-plugin.php';

		// Fire the hook as FileAbilities would.
		do_action( 'sd_ai_agent_before_file_write', $file_path );

		// The file should now be tracked.
		$tracker = GitTrackerManager::for_plugin( $this->plugin_slug );
		$this->assertInstanceOf( GitTracker::class, $tracker );

		$tracked = $tracker->get_tracked_files();
		$paths   = array_column( (array) $tracked, 'file_path' );
		$this->assertContains( 'sd-test-plugin.php', $paths );
	}
}
