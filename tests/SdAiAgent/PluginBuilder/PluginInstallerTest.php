<?php
/**
 * Test case for PluginInstaller class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\PluginBuilder;

use SdAiAgent\PluginBuilder\PluginInstaller;
use WP_UnitTestCase;

/**
 * Test PluginInstaller methods.
 */
class PluginInstallerTest extends WP_UnitTestCase {

	/**
	 * Test slug used across test cases.
	 *
	 * @var string
	 */
	private string $test_slug = 'sd-test-plugin-phpunit';

	/**
	 * Full path to the test plugin directory.
	 *
	 * @var string
	 */
	private string $plugin_dir;

	/**
	 * Set up: ensure the test plugin directory does not exist before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->plugin_dir = trailingslashit( WP_PLUGIN_DIR ) . $this->test_slug . '/';
		$this->cleanup_plugin_dir();
	}

	/**
	 * Tear down: remove test plugin directory and any DB records.
	 */
	public function tearDown(): void {
		$this->cleanup_plugin_dir();
		$this->cleanup_db_records();
		parent::tearDown();
	}

	// ─── validate_plugin_path ────────────────────────────────────────────────

	/**
	 * Valid relative paths should be returned normalised.
	 */
	public function test_validate_plugin_path_valid_returns_normalised(): void {
		$result = PluginInstaller::validate_plugin_path( $this->test_slug, 'includes/class-main.php' );
		$this->assertIsString( $result );
		$this->assertSame( 'includes/class-main.php', $result );
	}

	/**
	 * Leading slashes should be stripped.
	 */
	public function test_validate_plugin_path_strips_leading_slash(): void {
		$result = PluginInstaller::validate_plugin_path( $this->test_slug, '/includes/main.php' );
		$this->assertIsString( $result );
		$this->assertSame( 'includes/main.php', $result );
	}

	/**
	 * Redundant slug prefix should be stripped.
	 */
	public function test_validate_plugin_path_strips_slug_prefix(): void {
		$result = PluginInstaller::validate_plugin_path( $this->test_slug, $this->test_slug . '/main.php' );
		$this->assertIsString( $result );
		$this->assertSame( 'main.php', $result );
	}

	/**
	 * Empty path should return WP_Error.
	 */
	public function test_validate_plugin_path_rejects_empty(): void {
		$result = PluginInstaller::validate_plugin_path( $this->test_slug, '' );
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_empty_path', $result->get_error_code() );
	}

	/**
	 * Null bytes in path should return WP_Error.
	 */
	public function test_validate_plugin_path_rejects_null_bytes(): void {
		$result = PluginInstaller::validate_plugin_path( $this->test_slug, "file\0.php" );
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_invalid_path', $result->get_error_code() );
	}

	/**
	 * Directory traversal sequences should return WP_Error.
	 */
	public function test_validate_plugin_path_rejects_traversal(): void {
		$result = PluginInstaller::validate_plugin_path( $this->test_slug, '../other-plugin/evil.php' );
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_path_traversal', $result->get_error_code() );
	}

	// ─── install_plugin ──────────────────────────────────────────────────────

	/**
	 * install_plugin() should reject invalid slugs.
	 */
	public function test_install_plugin_rejects_invalid_slug(): void {
		$result = PluginInstaller::install_plugin(
			'Invalid Slug!',
			'<?php /* test */',
			'Test',
			[]
		);
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_invalid_slug', $result->get_error_code() );
	}

	/**
	 * install_plugin() should reject slugs with uppercase letters.
	 */
	public function test_install_plugin_rejects_uppercase_slug(): void {
		$result = PluginInstaller::install_plugin(
			'MyPlugin',
			'<?php /* test */',
			'Test',
			[]
		);
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_invalid_slug', $result->get_error_code() );
	}

	/**
	 * install_plugin() writes the main file and records in the DB.
	 */
	public function test_install_plugin_writes_file_and_db_record(): void {
		$content = '<?php /* Plugin Name: Test Plugin */';
		$result  = PluginInstaller::install_plugin(
			$this->test_slug,
			$content,
			'A test plugin',
			[ 'step' => 'write main file' ]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertArrayHasKey( 'plugin_dir', $result );
		$this->assertArrayHasKey( 'plugin_file', $result );
		$this->assertGreaterThan( 0, $result['id'] );

		// File should exist on disk.
		$expected_file = $this->plugin_dir . $this->test_slug . '.php';
		$this->assertFileExists( $expected_file );
		$this->assertStringContainsString( 'Plugin Name: Test Plugin', file_get_contents( $expected_file ) );

		// plugin_file should be slug/slug.php.
		$this->assertSame( $this->test_slug . '/' . $this->test_slug . '.php', $result['plugin_file'] );
	}

	// ─── install_complex_plugin ──────────────────────────────────────────────

	/**
	 * install_complex_plugin() should reject invalid slugs.
	 */
	public function test_install_complex_plugin_rejects_invalid_slug(): void {
		$result = PluginInstaller::install_complex_plugin(
			'Bad Slug',
			[ 'main.php' => '<?php' ],
			'Test',
			[]
		);
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_invalid_slug', $result->get_error_code() );
	}

	/**
	 * install_complex_plugin() should reject empty file map.
	 */
	public function test_install_complex_plugin_rejects_empty_files(): void {
		$result = PluginInstaller::install_complex_plugin(
			$this->test_slug,
			[],
			'Test',
			[]
		);
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_no_files', $result->get_error_code() );
	}

	/**
	 * install_complex_plugin() should reject traversal paths.
	 */
	public function test_install_complex_plugin_rejects_traversal_paths(): void {
		$result = PluginInstaller::install_complex_plugin(
			$this->test_slug,
			[ '../evil.php' => '<?php evil();' ],
			'Test',
			[]
		);
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_path_traversal', $result->get_error_code() );
	}

	/**
	 * install_complex_plugin() writes multiple files and records in the DB.
	 */
	public function test_install_complex_plugin_writes_multiple_files(): void {
		$files = [
			$this->test_slug . '.php' => '<?php /* Plugin Name: Complex Test */',
			'includes/class-loader.php' => '<?php class Loader {}',
		];

		$result = PluginInstaller::install_complex_plugin(
			$this->test_slug,
			$files,
			'A complex test plugin',
			[ 'step' => 'multi-file' ]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertGreaterThan( 0, $result['id'] );

		// Both files should exist.
		$this->assertFileExists( $this->plugin_dir . $this->test_slug . '.php' );
		$this->assertFileExists( $this->plugin_dir . 'includes/class-loader.php' );
	}

	/**
	 * install_complex_plugin() strips redundant slug prefix from file paths.
	 */
	public function test_install_complex_plugin_normalises_slug_prefix(): void {
		$files = [
			$this->test_slug . '/' . $this->test_slug . '.php' => '<?php /* Plugin Name: Normalised */',
		];

		$result = PluginInstaller::install_complex_plugin(
			$this->test_slug,
			$files,
			'Normalise prefix test',
			[]
		);

		$this->assertIsArray( $result );
		// File should be at the normalised path (slug prefix stripped).
		$this->assertFileExists( $this->plugin_dir . $this->test_slug . '.php' );
	}

	// ─── update_plugin_files ─────────────────────────────────────────────────

	/**
	 * update_plugin_files() should return error when plugin directory does not exist.
	 */
	public function test_update_plugin_files_missing_dir_returns_error(): void {
		$result = PluginInstaller::update_plugin_files(
			'non-existent-plugin-phpunit',
			[ 'main.php' => '<?php /* updated */' ]
		);
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_plugin_not_found', $result->get_error_code() );
	}

	/**
	 * update_plugin_files() should reject empty files map.
	 */
	public function test_update_plugin_files_empty_files_returns_error(): void {
		$result = PluginInstaller::update_plugin_files( $this->test_slug, [] );
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_no_files', $result->get_error_code() );
	}

	/**
	 * update_plugin_files() updates an existing file on disk.
	 */
	public function test_update_plugin_files_updates_existing_file(): void {
		// First install the plugin.
		$install = PluginInstaller::install_plugin(
			$this->test_slug,
			'<?php /* original */',
			'Update test',
			[]
		);
		$this->assertIsArray( $install );

		// Now update the file.
		$result = PluginInstaller::update_plugin_files(
			$this->test_slug,
			[ $this->test_slug . '.php' => '<?php /* updated */' ]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'updated', $result );
		$this->assertNotEmpty( $result['updated'] );

		$file_path = $this->plugin_dir . $this->test_slug . '.php';
		$this->assertFileExists( $file_path );
		$this->assertStringContainsString( 'updated', file_get_contents( $file_path ) );
	}

	/**
	 * update_plugin_files() should reject traversal paths.
	 */
	public function test_update_plugin_files_rejects_traversal(): void {
		// Create the plugin dir so we get past the is_dir check.
		wp_mkdir_p( $this->plugin_dir );

		$result = PluginInstaller::update_plugin_files(
			$this->test_slug,
			[ '../other-plugin/evil.php' => '<?php evil();' ]
		);
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_path_traversal', $result->get_error_code() );
	}

	// ─── delete_generated_plugin ─────────────────────────────────────────────

	/**
	 * delete_generated_plugin() should return error when no DB record exists.
	 */
	public function test_delete_generated_plugin_missing_record_returns_error(): void {
		$result = PluginInstaller::delete_generated_plugin( 'non-existent-plugin-phpunit' );
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_plugin_not_found', $result->get_error_code() );
	}

	/**
	 * delete_generated_plugin() removes disk files and DB record.
	 */
	public function test_delete_generated_plugin_removes_files_and_record(): void {
		// Install a plugin first.
		$install = PluginInstaller::install_plugin(
			$this->test_slug,
			'<?php /* Plugin Name: Delete Test */',
			'Delete test plugin',
			[]
		);
		$this->assertIsArray( $install );
		$this->assertFileExists( $this->plugin_dir . $this->test_slug . '.php' );

		// Delete it.
		$result = PluginInstaller::delete_generated_plugin( $this->test_slug );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertFalse( $result['deactivated'] ); // Was never activated.
		$this->assertDirectoryDoesNotExist( $this->plugin_dir );
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Remove the test plugin directory if it exists.
	 *
	 * @return void
	 */
	private function cleanup_plugin_dir(): void {
		if ( is_dir( $this->plugin_dir ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			$fs = new \WP_Filesystem_Direct( [] );
			$fs->rmdir( $this->plugin_dir, true );
		}
	}

	/**
	 * Remove any generated_plugins DB records for the test slug.
	 *
	 * @return void
	 */
	private function cleanup_db_records(): void {
		global $wpdb;
		/** @var \wpdb $wpdb */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
		$wpdb->delete(
			PluginInstaller::table_name(),
			[ 'slug' => $this->test_slug ],
			[ '%s' ]
		);
	}
}
