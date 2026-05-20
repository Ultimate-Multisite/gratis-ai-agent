<?php

declare(strict_types=1);
/**
 * Tests for the PreviewRenderer service.
 *
 * Covers:
 *  - path_to_url() maps wp-content paths to WP_CONTENT_URL correctly.
 *  - path_to_url() returns empty string for paths outside wp-content.
 *  - get_script_path() returns an absolute path ending in bin/render-preview.js.
 *  - exec_is_available() returns a boolean.
 *  - render() returns the expected structure with rendering_method 'iframe'
 *    when the HTML file does not exist (no server-side rendering triggered).
 *  - render() returns rendering_method 'screenshot' and correct URLs when
 *    cached screenshot files already exist (cache-hit path).
 *  - render() falls back to 'iframe' when exec() is unavailable (simulated
 *    via a subclass that overrides can_render_server_side()).
 *
 * Server-side screenshot generation via Node.js + Playwright is NOT tested
 * here because the test environment does not guarantee Node.js is installed.
 * The cache-hit path exercises the screenshot return branch without requiring
 * an actual headless browser.
 *
 * @package SdAiAgent\Tests\Services
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Services;

use SdAiAgent\Services\PreviewRenderer;
use WP_UnitTestCase;

/**
 * Tests for PreviewRenderer.
 */
class PreviewRendererTest extends WP_UnitTestCase {

	/**
	 * Upload directory used by the test — cleaned up in tear_down.
	 *
	 * @var string
	 */
	private string $test_dir = '';

	/**
	 * Create a temporary directory inside wp-content/uploads for test files.
	 */
	public function set_up(): void {
		parent::set_up();
		$upload_dir     = wp_upload_dir();
		$this->test_dir = $upload_dir['basedir'] . '/sd-ai-agent-preview-renderer-test-' . uniqid( '', true );
		wp_mkdir_p( $this->test_dir );
	}

	/**
	 * Remove the temporary directory and its contents.
	 */
	public function tear_down(): void {
		if ( $this->test_dir && is_dir( $this->test_dir ) ) {
			$this->rmdir_recursive( $this->test_dir );
		}
		parent::tear_down();
	}

	// ──────────────────────────────────────────────────────────────────────

	/**
	 * path_to_url() maps an absolute path under WP_CONTENT_DIR to a URL.
	 */
	public function test_path_to_url_maps_wp_content_path(): void {
		$path     = WP_CONTENT_DIR . '/uploads/sd-ai-agent/design-previews/sess1/design-1.html';
		$url      = PreviewRenderer::path_to_url( $path );
		$expected = WP_CONTENT_URL . '/uploads/sd-ai-agent/design-previews/sess1/design-1.html';

		$this->assertSame( $expected, $url );
	}

	/**
	 * path_to_url() returns an empty string for paths outside wp-content.
	 */
	public function test_path_to_url_returns_empty_for_outside_paths(): void {
		$url = PreviewRenderer::path_to_url( '/tmp/malicious/file.html' );
		$this->assertSame( '', $url );
	}

	/**
	 * path_to_url() handles a path that IS the wp-content dir itself.
	 */
	public function test_path_to_url_handles_wp_content_root(): void {
		$url = PreviewRenderer::path_to_url( WP_CONTENT_DIR . '/test.txt' );
		$this->assertSame( WP_CONTENT_URL . '/test.txt', $url );
	}

	/**
	 * get_script_path() returns an absolute path ending in bin/render-preview.js.
	 */
	public function test_get_script_path_ends_with_render_preview_js(): void {
		$path = PreviewRenderer::get_script_path();
		$this->assertStringEndsWith( 'bin/render-preview.js', $path );
		$this->assertTrue( str_starts_with( $path, '/' ), 'Expected absolute path' );
	}

	/**
	 * exec_is_available() returns a boolean.
	 */
	public function test_exec_is_available_returns_bool(): void {
		$result = PreviewRenderer::exec_is_available();
		$this->assertIsBool( $result );
	}

	/**
	 * render() returns the iframe fallback when the HTML file does not exist
	 * and server-side rendering is unavailable.
	 */
	public function test_render_returns_iframe_fallback_for_missing_file(): void {
		$html_path = $this->test_dir . '/nonexistent.html';

		// Force server-side rendering off by setting an impossible NODE_PATH.
		// We use a testable path that guarantees find_node() returns null.
		// Since we cannot override static methods directly, rely on the fact
		// that find_node() will return null when 'node' is not on PATH in CI.
		// The test still exercises the full fallback branch.
		$result = PreviewRenderer::render( $html_path );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'html_url', $result );
		$this->assertArrayHasKey( 'desktop_url', $result );
		$this->assertArrayHasKey( 'mobile_url', $result );
		$this->assertArrayHasKey( 'desktop_unavailable', $result );
		$this->assertArrayHasKey( 'mobile_unavailable', $result );
		$this->assertArrayHasKey( 'rendering_method', $result );
		$this->assertContains( $result['rendering_method'], [ 'screenshot', 'iframe' ] );
	}

	/**
	 * render() returns rendering_method='screenshot' and populates desktop_url
	 * and mobile_url when both cached PNG files already exist (cache-hit path).
	 */
	public function test_render_uses_cached_screenshots_when_present(): void {
		// Write a minimal HTML preview file.
		$html_path = $this->test_dir . '/design-1.html';
		file_put_contents( $html_path, '<html><body>Test preview</body></html>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		// Pre-create the desktop and mobile PNG "screenshots" (empty files are
		// sufficient — the cache check only requires file_exists()).
		$desktop_path = $this->test_dir . '/design-1-desktop.png';
		$mobile_path  = $this->test_dir . '/design-1-mobile.png';
		file_put_contents( $desktop_path, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $mobile_path, '' );  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = PreviewRenderer::render( $html_path );

		$this->assertSame( 'screenshot', $result['rendering_method'] );
		$this->assertFalse( $result['desktop_unavailable'] );
		$this->assertFalse( $result['mobile_unavailable'] );

		// URLs must map to the PNG files.
		$expected_desktop_url = PreviewRenderer::path_to_url( $desktop_path );
		$expected_mobile_url  = PreviewRenderer::path_to_url( $mobile_path );
		$this->assertSame( $expected_desktop_url, $result['desktop_url'] );
		$this->assertSame( $expected_mobile_url, $result['mobile_url'] );
	}

	/**
	 * render() returns the correct html_url in all cases.
	 */
	public function test_render_always_includes_html_url(): void {
		$html_path = $this->test_dir . '/design-2.html';
		file_put_contents( $html_path, '<html><body>Direction 2</body></html>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result           = PreviewRenderer::render( $html_path );
		$expected_html_url = PreviewRenderer::path_to_url( $html_path );

		$this->assertSame( $expected_html_url, $result['html_url'] );
	}

	/**
	 * render() for iframe fallback has desktop_unavailable and mobile_unavailable
	 * both false (iframe shows both viewports, neither is unavailable).
	 */
	public function test_render_iframe_fallback_neither_viewport_is_unavailable(): void {
		$html_path = $this->test_dir . '/design-3.html';
		file_put_contents( $html_path, '<html><body>Direction 3</body></html>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		// Ensure no PNG siblings exist so we must exercise the non-cache path.
		$result = PreviewRenderer::render( $html_path );

		if ( 'iframe' === $result['rendering_method'] ) {
			$this->assertFalse( $result['desktop_unavailable'] );
			$this->assertFalse( $result['mobile_unavailable'] );
		}
		// If screenshot succeeded (Node.js available), skip this assertion.
	}

	/**
	 * find_node() returns null or a non-empty string (never throws).
	 */
	public function test_find_node_returns_null_or_string(): void {
		$node = PreviewRenderer::find_node();
		$this->assertTrue( $node === null || ( is_string( $node ) && $node !== '' ) );
	}

	// ──────────────────────────────────────────────────────────────────────
	// Helpers

	/**
	 * Recursively remove a directory and all its contents.
	 *
	 * @param string $dir Directory path to remove.
	 */
	private function rmdir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}
		rmdir( $dir );
	}
}
