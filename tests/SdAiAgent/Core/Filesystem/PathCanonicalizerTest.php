<?php
/**
 * Test case for filesystem path canonicalisation helpers.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core\Filesystem;

use SdAiAgent\Core\Filesystem\PathCanonicalizer;
use WP_UnitTestCase;

/**
 * Tests PathCanonicalizer.
 */
class PathCanonicalizerTest extends WP_UnitTestCase {

	/**
	 * Temporary base directory.
	 *
	 * @var string
	 */
	private string $base_dir;

	/**
	 * Set up temporary directories.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->base_dir = trailingslashit( sys_get_temp_dir() ) . 'sd-ai-agent-path-canonicalizer';
		$this->remove_dir( $this->base_dir );
		wp_mkdir_p( $this->base_dir . '/content/plugins' );
		wp_mkdir_p( $this->base_dir . '/outside' );
	}

	/**
	 * Tear down temporary directories.
	 */
	public function tearDown(): void {
		$this->remove_dir( $this->base_dir );
		parent::tearDown();
	}

	/**
	 * Paths with parent-dir references are canonicalised before mkdir use.
	 */
	public function test_canonicalize_missing_path_resolves_parent_segments(): void {
		$path = $this->base_dir . '/content/../content/plugins/probe-plugin/includes';

		$result = PathCanonicalizer::canonicalize_missing_path( $path );

		$this->assertIsString( $result );
		$this->assertSame( $this->base_dir . '/content/plugins/probe-plugin/includes', $result );
	}

	/**
	 * Missing child suffixes are appended after resolving the nearest ancestor.
	 */
	public function test_canonicalize_missing_path_inside_appends_missing_suffix(): void {
		$root = $this->base_dir . '/content/plugins';
		$path = $this->base_dir . '/content/../content/plugins/probe-plugin/deep/dir';

		$result = PathCanonicalizer::canonicalize_missing_path_inside( $path, $root );

		$this->assertIsString( $result );
		$this->assertSame( $root . '/probe-plugin/deep/dir', $result );
	}

	/**
	 * Canonicalisation rejects paths that resolve outside the allowed root.
	 */
	public function test_canonicalize_missing_path_inside_rejects_traversal_outside_root(): void {
		$root = $this->base_dir . '/content/plugins';
		$path = $this->base_dir . '/content/plugins/../../outside/probe-plugin';

		$result = PathCanonicalizer::canonicalize_missing_path_inside( $path, $root );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_path_traversal', $result->get_error_code() );
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
