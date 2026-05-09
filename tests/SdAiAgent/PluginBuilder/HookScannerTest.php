<?php

declare(strict_types=1);
/**
 * Unit tests for HookScanner — plugin/theme hook discovery.
 *
 * Coverage:
 *   - scan_plugin(): non-existent plugin → WP_Error
 *   - scan_plugin(): valid plugin → extracts do_action/apply_filters/add_action/add_filter
 *   - scan_plugin(): dynamic (variable) hook names are not matched
 *   - scan_plugin(): vendor/ subdirectory is skipped
 *   - scan_plugin(): node_modules/ subdirectory is skipped
 *   - scan_theme(): non-existent theme → WP_Error
 *   - scan_theme(): valid theme → extracts hooks
 *   - Multiple hooks per file and multi-file plugins
 *   - Nested subdirectory files are scanned
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\PluginBuilder;

use SdAiAgent\PluginBuilder\HookScanner;
use WP_UnitTestCase;

/**
 * Tests for HookScanner.
 *
 * @group plugin-builder
 * @group hook-scanner
 */
class HookScannerTest extends WP_UnitTestCase {

	/**
	 * Temp plugin/theme directories created during tests — removed in tearDown.
	 *
	 * @var string[]
	 */
	private array $temp_dirs = [];

	// ─── Helpers ────────────────────────────────────────────────────

	/**
	 * Create an isolated temp directory under WP_PLUGIN_DIR and track for cleanup.
	 *
	 * @param string $slug Plugin slug to use as the directory name.
	 * @return string Absolute path to the plugin directory.
	 */
	private function make_temp_plugin( string $slug ): string {
		$dir = trailingslashit( WP_PLUGIN_DIR ) . $slug . '/';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		$this->temp_dirs[] = $dir;
		return $dir;
	}

	/**
	 * Create an isolated temp directory under the active theme root and track for cleanup.
	 *
	 * @param string $slug Theme slug to use as the directory name.
	 * @return string Absolute path to the theme directory.
	 */
	private function make_temp_theme( string $slug ): string {
		$dir = trailingslashit( get_theme_root( $slug ) ) . $slug . '/';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		$this->temp_dirs[] = $dir;
		return $dir;
	}

	/**
	 * Write a PHP file with given contents into a directory.
	 *
	 * @param string $dir      Parent directory.
	 * @param string $filename Filename (relative to $dir).
	 * @param string $content  PHP source content.
	 */
	private function write_php_file( string $dir, string $filename, string $content ): void {
		$path = $dir . $filename;
		$parent = dirname( $path );
		if ( ! is_dir( $parent ) ) {
			mkdir( $parent, 0755, true );
		}
		file_put_contents( $path, $content );
	}

	/**
	 * Recursively delete a directory tree.
	 *
	 * @param string $dir Directory to remove.
	 */
	private function rmdir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( (string) $item->getRealPath() );
			} else {
				unlink( (string) $item->getRealPath() );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Remove all temp dirs after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
		foreach ( $this->temp_dirs as $dir ) {
			$this->rmdir_recursive( $dir );
		}
		$this->temp_dirs = [];
	}

	// ─── scan_plugin() ──────────────────────────────────────────────

	/**
	 * scan_plugin() returns WP_Error when plugin slug does not exist.
	 */
	public function test_scan_plugin_returns_wp_error_for_nonexistent_plugin(): void {
		$result = HookScanner::scan_plugin( 'nonexistent-plugin-xyz-99999' );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_plugin_not_found', $result->get_error_code() );
	}

	/**
	 * scan_plugin() returns WP_Error when slug is empty (sanitizes to empty string).
	 */
	public function test_scan_plugin_returns_wp_error_for_empty_slug(): void {
		$result = HookScanner::scan_plugin( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_plugin_not_found', $result->get_error_code() );
	}

	/**
	 * scan_plugin() extracts do_action() calls from a plugin PHP file.
	 */
	public function test_scan_plugin_extracts_do_action_hooks(): void {
		$slug = 'test-hook-scanner-plugin-' . str_replace( '.', '', uniqid( '', true ) );
		$dir  = $this->make_temp_plugin( $slug );

		$this->write_php_file(
			$dir,
			'plugin.php',
			"<?php\ndo_action( 'my_custom_action' );\n"
		);

		$result = HookScanner::scan_plugin( $slug );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'hooks', $result );

		$hooks = $result['hooks'];
		$names = array_column( $hooks, 'name' );
		$this->assertContains( 'my_custom_action', $names );

		$hook = $hooks[ array_search( 'my_custom_action', $names, true ) ];
		$this->assertSame( 'action', $hook['type'] );
		$this->assertSame( 2, $hook['line'] );
	}

	/**
	 * scan_plugin() extracts apply_filters() calls.
	 */
	public function test_scan_plugin_extracts_apply_filters_hooks(): void {
		$slug = 'test-hook-scanner-plugin-' . str_replace( '.', '', uniqid( '', true ) );
		$dir  = $this->make_temp_plugin( $slug );

		$this->write_php_file(
			$dir,
			'plugin.php',
			"<?php\n\$val = apply_filters( 'my_filter_hook', \$value );\n"
		);

		$result = HookScanner::scan_plugin( $slug );

		$this->assertIsArray( $result );
		$hooks = $result['hooks'];
		$names = array_column( $hooks, 'name' );

		$this->assertContains( 'my_filter_hook', $names );
		$hook = $hooks[ array_search( 'my_filter_hook', $names, true ) ];
		$this->assertSame( 'filter', $hook['type'] );
	}

	/**
	 * scan_plugin() extracts add_action() and add_filter() calls.
	 */
	public function test_scan_plugin_extracts_add_action_and_add_filter(): void {
		$slug = 'test-hook-scanner-plugin-' . str_replace( '.', '', uniqid( '', true ) );
		$dir  = $this->make_temp_plugin( $slug );

		$this->write_php_file(
			$dir,
			'plugin.php',
			"<?php\nadd_action( 'init', 'my_callback' );\nadd_filter( 'the_content', 'filter_callback' );\n"
		);

		$result = HookScanner::scan_plugin( $slug );

		$this->assertIsArray( $result );
		$hooks = $result['hooks'];
		$names = array_column( $hooks, 'name' );

		$this->assertContains( 'init', $names );
		$this->assertContains( 'the_content', $names );

		$add_action_hook = $hooks[ array_search( 'init', $names, true ) ];
		$this->assertSame( 'add_action', $add_action_hook['type'] );

		$add_filter_hook = $hooks[ array_search( 'the_content', $names, true ) ];
		$this->assertSame( 'add_filter', $add_filter_hook['type'] );
	}

	/**
	 * scan_plugin() does not match dynamic (variable) hook names.
	 */
	public function test_scan_plugin_skips_dynamic_hook_names(): void {
		$slug = 'test-hook-scanner-plugin-' . str_replace( '.', '', uniqid( '', true ) );
		$dir  = $this->make_temp_plugin( $slug );

		$this->write_php_file(
			$dir,
			'plugin.php',
			"<?php\ndo_action( \$dynamic_hook_name );\ndo_action( 'static_hook' );\n"
		);

		$result = HookScanner::scan_plugin( $slug );

		$this->assertIsArray( $result );
		$names = array_column( $result['hooks'], 'name' );

		// Only the static hook name should be captured.
		$this->assertContains( 'static_hook', $names );
		// Variable-based hook names cannot be captured by the regex.
		$this->assertCount( 1, $names );
	}

	/**
	 * scan_plugin() scans PHP files in subdirectories.
	 */
	public function test_scan_plugin_scans_subdirectories(): void {
		$slug = 'test-hook-scanner-plugin-' . str_replace( '.', '', uniqid( '', true ) );
		$dir  = $this->make_temp_plugin( $slug );

		$this->write_php_file(
			$dir,
			'includes/class-frontend.php',
			"<?php\ndo_action( 'subdirectory_hook' );\n"
		);

		$result = HookScanner::scan_plugin( $slug );

		$this->assertIsArray( $result );
		$names = array_column( $result['hooks'], 'name' );
		$this->assertContains( 'subdirectory_hook', $names );
	}

	/**
	 * scan_plugin() aggregates hooks from multiple PHP files.
	 */
	public function test_scan_plugin_aggregates_hooks_from_multiple_files(): void {
		$slug = 'test-hook-scanner-plugin-' . str_replace( '.', '', uniqid( '', true ) );
		$dir  = $this->make_temp_plugin( $slug );

		$this->write_php_file( $dir, 'main.php', "<?php\ndo_action( 'hook_from_main' );\n" );
		$this->write_php_file( $dir, 'secondary.php', "<?php\napply_filters( 'hook_from_secondary', null );\n" );

		$result = HookScanner::scan_plugin( $slug );

		$this->assertIsArray( $result );
		$names = array_column( $result['hooks'], 'name' );

		$this->assertContains( 'hook_from_main', $names );
		$this->assertContains( 'hook_from_secondary', $names );
	}

	/**
	 * scan_plugin() returns empty hooks array for a plugin with no hook calls.
	 */
	public function test_scan_plugin_returns_empty_hooks_for_no_hook_calls(): void {
		$slug = 'test-hook-scanner-plugin-' . str_replace( '.', '', uniqid( '', true ) );
		$dir  = $this->make_temp_plugin( $slug );

		$this->write_php_file(
			$dir,
			'plugin.php',
			"<?php\n// No hooks here\nfunction my_func() { return true; }\n"
		);

		$result = HookScanner::scan_plugin( $slug );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'hooks', $result );
		$this->assertCount( 0, $result['hooks'] );
	}

	/**
	 * scan_plugin() result includes relative file path and line number.
	 */
	public function test_scan_plugin_result_includes_file_and_line(): void {
		$slug = 'test-hook-scanner-plugin-' . str_replace( '.', '', uniqid( '', true ) );
		$dir  = $this->make_temp_plugin( $slug );

		$this->write_php_file(
			$dir,
			'my-file.php',
			"<?php\n// line 1 comment\ndo_action( 'line3_hook' );\n"
		);

		$result = HookScanner::scan_plugin( $slug );

		$this->assertIsArray( $result );
		$hooks = $result['hooks'];
		$this->assertNotEmpty( $hooks );

		$hook = $hooks[0];
		$this->assertArrayHasKey( 'file', $hook );
		$this->assertArrayHasKey( 'line', $hook );
		$this->assertSame( 'my-file.php', $hook['file'] );
		$this->assertSame( 3, $hook['line'] );
	}

	// ─── scan_theme() ───────────────────────────────────────────────

	/**
	 * scan_theme() returns WP_Error when theme slug does not exist.
	 */
	public function test_scan_theme_returns_wp_error_for_nonexistent_theme(): void {
		$result = HookScanner::scan_theme( 'nonexistent-theme-xyz-99999' );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_theme_not_found', $result->get_error_code() );
	}

	/**
	 * scan_theme() extracts hooks from theme PHP files.
	 */
	public function test_scan_theme_extracts_hooks(): void {
		$slug = 'test-hook-scanner-theme-' . str_replace( '.', '', uniqid( '', true ) );
		$dir  = $this->make_temp_theme( $slug );

		$this->write_php_file(
			$dir,
			'functions.php',
			"<?php\ndo_action( 'theme_custom_hook' );\n"
		);

		$result = HookScanner::scan_theme( $slug );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'hooks', $result );

		$names = array_column( $result['hooks'], 'name' );
		$this->assertContains( 'theme_custom_hook', $names );
	}

	// ─── Directory exclusion ─────────────────────────────────────────

	/**
	 * Files inside a vendor/ subdirectory are skipped during scan.
	 *
	 * Prevents third-party library hooks from polluting the results.
	 */
	public function test_scan_plugin_skips_vendor_directory(): void {
		$slug = 'test-hook-scanner-vendor-' . str_replace( '.', '', uniqid( '', true ) );
		$dir  = $this->make_temp_plugin( $slug );

		// Plugin-level hook — must appear.
		$this->write_php_file(
			$dir,
			$slug . '.php',
			"<?php\n/* Plugin Name: Vendor Skip */\nadd_action( 'plugin_hook', 'cb' );\n"
		);

		// Vendor-level hook — must NOT appear.
		$vendor_dir = $dir . 'vendor/some-lib/';
		mkdir( $vendor_dir, 0755, true );
		$this->write_php_file(
			$vendor_dir,
			'library.php',
			"<?php\nadd_action( 'vendor_hook', 'vendor_cb' );\n"
		);

		$result = HookScanner::scan_plugin( $slug );
		$names  = array_column( $result['hooks'], 'name' );

		$this->assertContains( 'plugin_hook', $names, 'Plugin-level hook must be found.' );
		$this->assertNotContains( 'vendor_hook', $names, 'vendor/ hook must not appear.' );
	}

	/**
	 * Files inside a node_modules/ subdirectory are skipped during scan.
	 */
	public function test_scan_plugin_skips_node_modules_directory(): void {
		$slug = 'test-hook-scanner-nm-' . str_replace( '.', '', uniqid( '', true ) );
		$dir  = $this->make_temp_plugin( $slug );

		$this->write_php_file(
			$dir,
			$slug . '.php',
			"<?php\n/* Plugin Name: NM Skip */\nadd_filter( 'plugin_filter', 'cb' );\n"
		);

		$nm_dir = $dir . 'node_modules/some-package/';
		mkdir( $nm_dir, 0755, true );
		$this->write_php_file(
			$nm_dir,
			'index.php',
			"<?php\nadd_filter( 'node_hook', 'cb' );\n"
		);

		$result = HookScanner::scan_plugin( $slug );
		$names  = array_column( $result['hooks'], 'name' );

		$this->assertContains( 'plugin_filter', $names, 'Plugin-level hook must be found.' );
		$this->assertNotContains( 'node_hook', $names, 'node_modules/ hook must not appear.' );
	}
}
