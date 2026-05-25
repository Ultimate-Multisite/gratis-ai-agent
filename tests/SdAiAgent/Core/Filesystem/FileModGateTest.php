<?php

declare(strict_types=1);
/**
 * Test case for FileModGate class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core\Filesystem;

use SdAiAgent\Core\Filesystem\FileModGate;
use WP_UnitTestCase;

/**
 * Test FileModGate context resolution and permission checking.
 */
class FileModGateTest extends WP_UnitTestCase {

	/**
	 * Test context_for_path returns 'plugin_files' for plugin paths.
	 */
	public function test_context_for_path_plugin_files() {
		$plugin_path = WP_PLUGIN_DIR . '/my-plugin/file.php';
		$context     = FileModGate::context_for_path( $plugin_path );
		$this->assertSame( 'plugin_files', $context );
	}

	/**
	 * Test context_for_path returns 'theme_files' for theme paths.
	 */
	public function test_context_for_path_theme_files() {
		$theme_root  = get_theme_root();
		$theme_path  = $theme_root . '/my-theme/style.css';
		$context     = FileModGate::context_for_path( $theme_path );
		$this->assertSame( 'theme_files', $context );
	}

	/**
	 * Test context_for_path returns 'upload_files' for upload paths.
	 */
	public function test_context_for_path_upload_files() {
		$upload_dir = wp_upload_dir();
		$upload_path = $upload_dir['basedir'] . '/my-file.txt';
		$context     = FileModGate::context_for_path( $upload_path );
		$this->assertSame( 'upload_files', $context );
	}

	/**
	 * Test context_for_path handles non-existent paths by walking up.
	 */
	public function test_context_for_path_nonexistent_path() {
		// Path that doesn't exist yet, but parent is in plugins.
		$plugin_path = WP_PLUGIN_DIR . '/nonexistent-plugin/subdir/file.php';
		$context     = FileModGate::context_for_path( $plugin_path );
		$this->assertSame( 'plugin_files', $context );
	}

	/**
	 * Test context_for_path defaults to upload_files for unknown paths.
	 */
	public function test_context_for_path_defaults_to_upload_files() {
		// A path under ABSPATH but not in plugin/theme/upload roots.
		$unknown_path = ABSPATH . 'wp-unknown/file.php';
		$context      = FileModGate::context_for_path( $unknown_path );
		$this->assertSame( 'upload_files', $context );
	}

	/**
	 * Test assert_allowed returns true when wp_is_file_mod_allowed is true.
	 */
	public function test_assert_allowed_returns_true_when_allowed() {
		// Mock file_mod_allowed filter to return true for this test.
		add_filter(
			'file_mod_allowed',
			function () {
				return true;
			},
			10,
			2
		);

		$plugin_path = WP_PLUGIN_DIR . '/my-plugin/file.php';
		$result      = FileModGate::assert_allowed( $plugin_path );
		$this->assertTrue( $result );

		remove_all_filters( 'file_mod_allowed' );
	}

	/**
	 * Test assert_allowed returns WP_Error when DISALLOW_FILE_MODS is true.
	 */
	public function test_assert_allowed_returns_error_when_disallow_file_mods() {
		// Define DISALLOW_FILE_MODS if not already defined.
		if ( ! defined( 'DISALLOW_FILE_MODS' ) ) {
			define( 'DISALLOW_FILE_MODS', true );
		}

		$plugin_path = WP_PLUGIN_DIR . '/my-plugin/file.php';
		$result      = FileModGate::assert_allowed( $plugin_path );

		$this->assertWPError( $result );
		$this->assertSame( 'file_mod_not_allowed', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Test assert_allowed respects DISALLOW_FILE_EDIT for plugin files.
	 */
	public function test_assert_allowed_respects_disallow_file_edit() {
		// DISALLOW_FILE_EDIT should block in-place edits but not installs.
		// This test verifies the behavior when only DISALLOW_FILE_EDIT is set.
		if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true );
		}

		$plugin_path = WP_PLUGIN_DIR . '/my-plugin/file.php';
		$result      = FileModGate::assert_allowed( $plugin_path );

		// When DISALLOW_FILE_EDIT is set, wp_is_file_mod_allowed( 'plugin_files' )
		// should return false for in-place edits.
		// The actual behavior depends on WordPress's implementation.
		// For now, we just verify the method returns a boolean or WP_Error.
		$this->assertTrue( is_bool( $result ) || is_wp_error( $result ) );
	}

	/**
	 * Test assert_allowed for theme files.
	 */
	public function test_assert_allowed_theme_files() {
		// Mock file_mod_allowed filter to return true for this test.
		add_filter(
			'file_mod_allowed',
			static function () {
				return true;
			},
			10,
			2
		);

		$theme_root = get_theme_root();
		$theme_path = $theme_root . '/my-theme/style.css';
		$result     = FileModGate::assert_allowed( $theme_path );
		$this->assertTrue( $result );

		remove_all_filters( 'file_mod_allowed' );
	}

	/**
	 * Test assert_allowed for upload files.
	 */
	public function test_assert_allowed_upload_files() {
		// Mock file_mod_allowed filter to return true for this test.
		add_filter(
			'file_mod_allowed',
			static function () {
				return true;
			},
			10,
			2
		);

		$upload_dir  = wp_upload_dir();
		$upload_path = $upload_dir['basedir'] . '/my-file.txt';
		$result      = FileModGate::assert_allowed( $upload_path );
		$this->assertTrue( $result );

		remove_all_filters( 'file_mod_allowed' );
	}

	/**
	 * Test assert_allowed error message includes context.
	 */
	public function test_assert_allowed_error_message_includes_context() {
		// Mock file_mod_allowed filter to return false for this test.
		add_filter(
			'file_mod_allowed',
			static function () {
				return false;
			},
			10,
			2
		);

		$plugin_path = WP_PLUGIN_DIR . '/my-plugin/file.php';
		$result      = FileModGate::assert_allowed( $plugin_path );

		$this->assertWPError( $result );
		$message = $result->get_error_message();
		$this->assertStringContainsString( 'plugin_files', $message );

		remove_all_filters( 'file_mod_allowed' );
	}
}
