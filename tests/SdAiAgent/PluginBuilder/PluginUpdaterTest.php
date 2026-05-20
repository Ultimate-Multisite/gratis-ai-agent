<?php
/**
 * Test case for PluginUpdater class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\PluginBuilder;

use SdAiAgent\PluginBuilder\PluginUpdater;
use WP_UnitTestCase;

/**
 * Tests for PluginUpdater — sandboxed live update flow.
 *
 * Each test uses a unique slug prefixed with 'sd-test-updater-phpunit' and
 * cleans up all created directories in tearDown().
 */
class PluginUpdaterTest extends WP_UnitTestCase {

	/**
	 * Plugin slug used across tests.
	 *
	 * @var string
	 */
	private string $slug = 'sd-test-updater-phpunit';

	/**
	 * Absolute path to the test plugin directory.
	 *
	 * @var string
	 */
	private string $plugin_dir;

	/**
	 * PluginUpdater instance under test.
	 *
	 * @var PluginUpdater
	 */
	private PluginUpdater $updater;

	/**
	 * Temporary upload basedir used during this test class.
	 *
	 * Redirects wp_upload_dir() basedir to a writable location so that
	 * PluginUpdater::copy_directory() can call wp_mkdir_p() for the staging
	 * and backup paths without hitting permission failures in the
	 * tests-wordpress container (where wp-content/uploads is typically owned
	 * by www-data while PHPUnit runs as the host user, e.g. 1000:1000).
	 *
	 * @var string
	 */
	private string $tmp_upload_basedir;

	/**
	 * Set up test directories and a minimal plugin before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->tmp_upload_basedir = sys_get_temp_dir() . '/sd-ai-agent-phpunit';
		add_filter( 'upload_dir', [ $this, 'filter_upload_dir_to_tmp' ] );
		$this->plugin_dir = trailingslashit( WP_PLUGIN_DIR ) . $this->slug . '/';
		$this->updater    = new PluginUpdater();
		$this->cleanup_all();
		$this->create_minimal_plugin();
	}

	/**
	 * Remove all created directories after each test.
	 */
	public function tearDown(): void {
		$this->cleanup_all();
		remove_filter( 'upload_dir', [ $this, 'filter_upload_dir_to_tmp' ] );
		parent::tearDown();
	}

	/**
	 * Redirect wp_upload_dir() basedir to a writable tmp directory.
	 *
	 * PluginUpdater::staging_root() and ::backups_root() derive their paths
	 * from wp_upload_dir()['basedir']. The helper methods backups_root() and
	 * staging_root() in this class do the same, so both production and test
	 * paths stay consistent and point to a location writable by any user.
	 *
	 * @param array<string,string> $uploads Upload directory info passed by WP.
	 * @return array<string,string>
	 */
	public function filter_upload_dir_to_tmp( array $uploads ): array {
		$uploads['basedir'] = $this->tmp_upload_basedir;
		$uploads['path']    = $this->tmp_upload_basedir;
		$uploads['baseurl'] = 'http://example.org/tmp-uploads';
		$uploads['url']     = 'http://example.org/tmp-uploads';
		return $uploads;
	}

	// ─── backup() ────────────────────────────────────────────────────────────

	/**
	 * backup() should return a path to a new directory containing the plugin files.
	 */
	public function test_backup_creates_backup_dir(): void {
		$result = $this->updater->backup( $this->slug );

		$this->assertIsString( $result );
		$this->assertDirectoryExists( $result );
		$this->assertFileExists( $result . $this->slug . '.php' );
	}

	/**
	 * backup() should return WP_Error for an empty slug.
	 */
	public function test_backup_empty_slug_returns_error(): void {
		$result = $this->updater->backup( '' );
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_invalid_slug', $result->get_error_code() );
	}

	/**
	 * backup() should return WP_Error when the plugin directory does not exist.
	 */
	public function test_backup_missing_plugin_dir_returns_error(): void {
		$result = $this->updater->backup( 'non-existent-plugin-phpunit' );
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_plugin_not_found', $result->get_error_code() );
	}

	/**
	 * Backup directory name should follow the {slug}-YYYY-MM-DD-HHiiss pattern.
	 */
	public function test_backup_dir_name_format(): void {
		$result = $this->updater->backup( $this->slug );

		$this->assertIsString( $result );
		$basename = basename( rtrim( $result, '/' ) );
		$this->assertMatchesRegularExpression(
			'/^' . preg_quote( $this->slug, '/' ) . '-\d{4}-\d{2}-\d{2}-\d{6}$/',
			$basename
		);
	}

	// ─── stage() ─────────────────────────────────────────────────────────────

	/**
	 * stage() should create a staging directory containing all live plugin files.
	 */
	public function test_stage_creates_staging_dir_with_all_live_files(): void {
		$result = $this->updater->stage( $this->slug, [] );

		$this->assertIsString( $result );
		$this->assertDirectoryExists( $result );
		// Main plugin file should be copied from live.
		$this->assertFileExists( $result . $this->slug . '.php' );
	}

	/**
	 * stage() should overlay the provided modified files on top of the live copy.
	 */
	public function test_stage_overlays_modified_files(): void {
		$new_content = '<?php /* updated content */';
		$result      = $this->updater->stage( $this->slug, [ $this->slug . '.php' => $new_content ] );

		$this->assertIsString( $result );
		$staged_file = $result . $this->slug . '.php';
		$this->assertFileExists( $staged_file );
		$this->assertStringContainsString( 'updated content', (string) file_get_contents( $staged_file ) );
	}

	/**
	 * stage() should return WP_Error for an empty slug.
	 */
	public function test_stage_empty_slug_returns_error(): void {
		$result = $this->updater->stage( '', [] );
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_invalid_slug', $result->get_error_code() );
	}

	/**
	 * stage() should return WP_Error when the plugin directory does not exist.
	 */
	public function test_stage_missing_plugin_returns_error(): void {
		$result = $this->updater->stage( 'non-existent-plugin-phpunit', [] );
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_plugin_not_found', $result->get_error_code() );
	}

	// ─── test_staged() ───────────────────────────────────────────────────────

	/**
	 * test_staged() should return an array with `passed` key.
	 */
	public function test_test_staged_returns_array_with_passed_key(): void {
		$staging_dir = $this->updater->stage( $this->slug, [] );
		$this->assertIsString( $staging_dir );

		$result = $this->updater->test_staged( $this->slug, $staging_dir );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'passed', $result );
		$this->assertArrayHasKey( 'errors', $result );
	}

	/**
	 * test_staged() should pass for a valid PHP plugin file.
	 */
	public function test_test_staged_passes_for_valid_plugin(): void {
		$staging_dir = $this->updater->stage( $this->slug, [] );
		$this->assertIsString( $staging_dir );

		$result = $this->updater->test_staged( $this->slug, $staging_dir );

		$this->assertIsArray( $result );
		$this->assertTrue( (bool) $result['passed'] );
		$this->assertEmpty( $result['errors'] );
	}

	// ─── rollback() ──────────────────────────────────────────────────────────

	/**
	 * rollback() should restore plugin files from a backup directory.
	 */
	public function test_rollback_restores_from_backup(): void {
		$backup_dir = $this->updater->backup( $this->slug );
		$this->assertIsString( $backup_dir );

		// Corrupt the live plugin.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test: write corrupted content to simulate a failed swap.
		file_put_contents( $this->plugin_dir . $this->slug . '.php', '<?php /* corrupted */' );

		$result = $this->updater->rollback( $this->slug, $backup_dir );

		$this->assertIsArray( $result );
		$this->assertTrue( (bool) $result['rolled_back'] );

		// Restored file should have original content.
		$restored_content = (string) file_get_contents( $this->plugin_dir . $this->slug . '.php' );
		$this->assertStringContainsString( 'Plugin Name', $restored_content );
	}

	/**
	 * rollback() should return WP_Error when backup directory does not exist.
	 */
	public function test_rollback_missing_backup_returns_error(): void {
		$result = $this->updater->rollback( $this->slug, '/tmp/non-existent-backup-phpunit/' );
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_backup_not_found', $result->get_error_code() );
	}

	// ─── cleanup_old_backups() ───────────────────────────────────────────────

	/**
	 * cleanup_old_backups() should return 0 when there are no backup directories.
	 */
	public function test_cleanup_old_backups_returns_zero_when_no_backups(): void {
		$count = $this->updater->cleanup_old_backups();
		$this->assertSame( 0, $count );
	}

	/**
	 * cleanup_old_backups() should preserve the most recent backup regardless of age.
	 */
	public function test_cleanup_old_backups_preserves_most_recent(): void {
		// Create two backup directories for the slug.
		$backups_root = $this->backups_root();
		wp_mkdir_p( $backups_root );

		$old_dir = $backups_root . $this->slug . '-2020-01-01-000001/';
		$new_dir = $backups_root . $this->slug . '-2020-01-02-000001/';
		wp_mkdir_p( $old_dir );
		wp_mkdir_p( $new_dir );

		// Backdate the old dir's mtime so it's well outside the 7-day window.
		touch( $old_dir, strtotime( '-30 days' ) );

		$count = $this->updater->cleanup_old_backups( 7 );

		// Old backup should be removed; most recent (new_dir) should survive.
		$this->assertSame( 1, $count );
		$this->assertDirectoryDoesNotExist( $old_dir );
		$this->assertDirectoryExists( $new_dir );
	}

	// ─── update() ────────────────────────────────────────────────────────────

	/**
	 * update() should return WP_Error when the plugin slug is invalid.
	 */
	public function test_update_invalid_slug_returns_error(): void {
		$result = $this->updater->update( '', [] );
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_invalid_slug', $result->get_error_code() );
	}

	/**
	 * update() should return WP_Error when the plugin directory does not exist.
	 */
	public function test_update_missing_plugin_returns_error(): void {
		$result = $this->updater->update( 'non-existent-plugin-phpunit', [ 'main.php' => '<?php' ] );
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_plugin_not_found', $result->get_error_code() );
	}

	/**
	 * update() should replace live plugin files and include backup_dir in the result.
	 */
	public function test_update_replaces_live_plugin_files(): void {
		$new_content = '<?php /* Plugin Name: Updated Test Plugin */';

		$result = $this->updater->update(
			$this->slug,
			[ $this->slug . '.php' => $new_content ]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'backup_dir', $result );
		$this->assertTrue( (bool) $result['swapped'] );

		// Main plugin file should contain the updated content.
		$live_content = (string) file_get_contents( $this->plugin_dir . $this->slug . '.php' );
		$this->assertStringContainsString( 'Updated Test Plugin', $live_content );
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Create a minimal valid plugin in the test plugin directory.
	 *
	 * @return void
	 */
	private function create_minimal_plugin(): void {
		wp_mkdir_p( $this->plugin_dir );
		$content = '<?php /* Plugin Name: Test Updater Plugin */' . "\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test setup: creating a minimal plugin file.
		file_put_contents( $this->plugin_dir . $this->slug . '.php', $content );
	}

	/**
	 * Remove all directories created during the test.
	 *
	 * @return void
	 */
	private function cleanup_all(): void {
		$dirs_to_clean = [
			trailingslashit( WP_PLUGIN_DIR ) . $this->slug . '/',
			trailingslashit( WP_PLUGIN_DIR ) . 'non-existent-plugin-phpunit/',
			$this->staging_root() . $this->slug . '/',
		];

		foreach ( $dirs_to_clean as $dir ) {
			$this->remove_dir( $dir );
		}

		// Remove all backup directories for this slug.
		$backups_root = $this->backups_root();
		if ( is_dir( $backups_root ) ) {
			$entries = glob( $backups_root . $this->slug . '-*', GLOB_ONLYDIR );
			if ( false !== $entries ) {
				foreach ( $entries as $entry ) {
					$this->remove_dir( $entry );
				}
			}
		}
	}

	/**
	 * Mirror PluginUpdater::backups_root() so tests can locate backup dirs.
	 */
	private function backups_root(): string {
		$uploads = wp_upload_dir( null, false );
		$basedir = ! empty( $uploads['basedir'] ) ? (string) $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
		return trailingslashit( $basedir ) . 'sd-ai-backups/';
	}

	/**
	 * Mirror PluginUpdater::staging_root() so tests can locate staging dirs.
	 */
	private function staging_root(): string {
		$uploads = wp_upload_dir( null, false );
		$basedir = ! empty( $uploads['basedir'] ) ? (string) $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
		return trailingslashit( $basedir ) . 'sd-ai-staging/';
	}

	/**
	 * Recursively remove a directory using WP_Filesystem_Direct.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		$fs = new \WP_Filesystem_Direct( [] );
		$fs->rmdir( $dir, true );
	}
}
