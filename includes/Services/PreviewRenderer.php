<?php

declare(strict_types=1);
/**
 * Preview Renderer service.
 *
 * Generates desktop (1280×800) and mobile (375×812) screenshots of HTML
 * preview files written by the Theme Builder agent during design-direction
 * selection. Falls back to client-side iframe display when server-side
 * headless rendering is not available (no Chromium binary, exec() disabled,
 * or Node.js / Playwright not installed).
 *
 * @package SdAiAgent\Services
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders HTML preview files to PNG screenshots at multiple viewport sizes.
 *
 * Rendering pipeline:
 *   1. Check if cached screenshots already exist (skip re-rendering).
 *   2. If not cached: probe for Node.js + Playwright and run the bundled
 *      `bin/render-preview.js` script to capture PNG screenshots.
 *   3. If server-side rendering is unavailable, return the HTML file URL so
 *      the front-end can display responsive iframes instead (fallback).
 *
 * @since 1.15.0
 */
final class PreviewRenderer {

	/**
	 * Desktop viewport width in pixels.
	 */
	public const DESKTOP_WIDTH = 1280;

	/**
	 * Desktop viewport height in pixels.
	 */
	public const DESKTOP_HEIGHT = 800;

	/**
	 * Mobile viewport width in pixels.
	 */
	public const MOBILE_WIDTH = 375;

	/**
	 * Mobile viewport height in pixels.
	 */
	public const MOBILE_HEIGHT = 812;

	/**
	 * Render preview screenshots for a single HTML preview file.
	 *
	 * Returns a structured array describing available preview URLs and the
	 * rendering method used. `rendering_method` is 'screenshot' when PNG
	 * files were produced server-side, or 'iframe' when the front-end should
	 * use responsive iframes.
	 *
	 * @param string $html_path Absolute filesystem path to the HTML preview file.
	 * @return array{
	 *   html_url: string,
	 *   desktop_url: string|null,
	 *   mobile_url: string|null,
	 *   desktop_unavailable: bool,
	 *   mobile_unavailable: bool,
	 *   rendering_method: string
	 * }
	 */
	public static function render( string $html_path ): array {
		$html_url = self::path_to_url( $html_path );

		// Generate output paths alongside the HTML file.
		$basename     = pathinfo( $html_path, PATHINFO_FILENAME );
		$dir          = dirname( $html_path );
		$desktop_path = $dir . '/' . $basename . '-desktop.png';
		$mobile_path  = $dir . '/' . $basename . '-mobile.png';
		$desktop_url  = self::path_to_url( $desktop_path );
		$mobile_url   = self::path_to_url( $mobile_path );

		// Serve from cache if both screenshots already exist.
		if ( file_exists( $desktop_path ) && file_exists( $mobile_path ) ) {
			return [
				'html_url'            => $html_url,
				'desktop_url'         => $desktop_url,
				'mobile_url'          => $mobile_url,
				'desktop_unavailable' => false,
				'mobile_unavailable'  => false,
				'rendering_method'    => 'screenshot',
			];
		}

		// Attempt server-side rendering only when exec() and Node.js are available.
		if ( self::can_render_server_side() ) {
			$desktop_ok = file_exists( $desktop_path )
				|| self::run_screenshot( $html_path, $desktop_path, self::DESKTOP_WIDTH, self::DESKTOP_HEIGHT );
			$mobile_ok  = file_exists( $mobile_path )
				|| self::run_screenshot( $html_path, $mobile_path, self::MOBILE_WIDTH, self::MOBILE_HEIGHT );

			if ( $desktop_ok || $mobile_ok ) {
				return [
					'html_url'            => $html_url,
					'desktop_url'         => $desktop_ok ? $desktop_url : null,
					'mobile_url'          => $mobile_ok ? $mobile_url : null,
					'desktop_unavailable' => ! $desktop_ok,
					'mobile_unavailable'  => ! $mobile_ok,
					'rendering_method'    => 'screenshot',
				];
			}
		}

		// Fallback: instruct the front-end to render iframes client-side.
		return [
			'html_url'            => $html_url,
			'desktop_url'         => null,
			'mobile_url'          => null,
			'desktop_unavailable' => false,
			'mobile_unavailable'  => false,
			'rendering_method'    => 'iframe',
		];
	}

	/**
	 * Check whether server-side screenshot rendering is possible.
	 *
	 * Requires: exec() not disabled + Node.js binary available.
	 *
	 * @return bool
	 */
	public static function can_render_server_side(): bool {
		return self::exec_is_available() && self::find_node() !== null;
	}

	/**
	 * Determine whether exec() is usable (not listed in disable_functions).
	 *
	 * @return bool
	 */
	public static function exec_is_available(): bool {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}
		$disabled = ini_get( 'disable_functions' );
		if ( ! is_string( $disabled ) ) {
			return false;
		}
		$disabled_list = array_map( 'trim', explode( ',', $disabled ) );
		return ! in_array( 'exec', $disabled_list, true );
	}

	/**
	 * Find the Node.js binary path by probing common locations.
	 *
	 * @return string|null Absolute path to the node binary, or null if not found.
	 */
	public static function find_node(): ?string {
		$candidates = [ 'node', 'nodejs', '/usr/local/bin/node', '/usr/bin/node', '/opt/homebrew/bin/node' ];
		foreach ( $candidates as $binary ) {
			$path = self::which( $binary );
			if ( $path !== null ) {
				return $path;
			}
		}
		return null;
	}

	/**
	 * Convert an absolute filesystem path to a public URL.
	 *
	 * Maps paths under WP_CONTENT_DIR to WP_CONTENT_URL equivalents.
	 * Returns an empty string if the path is outside wp-content.
	 *
	 * @param string $path Absolute filesystem path.
	 * @return string Public URL, or empty string when the path cannot be mapped.
	 */
	public static function path_to_url( string $path ): string {
		$content_dir = untrailingslashit( WP_CONTENT_DIR );
		$content_url = untrailingslashit( content_url() );

		if ( 0 !== strncmp( $path, $content_dir, strlen( $content_dir ) ) ) {
			return '';
		}

		$relative = substr( $path, strlen( $content_dir ) );
		return $content_url . $relative;
	}

	/**
	 * Get the absolute path to the bundled render-preview.js script.
	 *
	 * @return string
	 */
	public static function get_script_path(): string {
		return SD_AI_AGENT_DIR . 'bin/render-preview.js';
	}

	/**
	 * Run the Node.js screenshot helper script for a single viewport.
	 *
	 * @param string $html_path Absolute path to the HTML preview file.
	 * @param string $out_path  Absolute path for the output PNG file.
	 * @param int    $width     Viewport width in pixels.
	 * @param int    $height    Viewport height in pixels.
	 * @return bool True when the screenshot was captured successfully.
	 */
	private static function run_screenshot( string $html_path, string $out_path, int $width, int $height ): bool {
		$node   = self::find_node();
		$script = self::get_script_path();

		if ( null === $node || ! file_exists( $script ) ) {
			return false;
		}

		// Ensure output directory exists.
		$out_dir = dirname( $out_path );
		if ( ! is_dir( $out_dir ) ) {
			wp_mkdir_p( $out_dir );
		}

		$cmd = sprintf(
			'%s %s --html %s --output %s --width %d --height %d 2>/dev/null',
			escapeshellarg( $node ),
			escapeshellarg( $script ),
			escapeshellarg( $html_path ),
			escapeshellarg( $out_path ),
			$width,
			$height
		);

		$output    = [];
		$exit_code = -1;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $cmd, $output, $exit_code );

		return 0 === $exit_code && file_exists( $out_path );
	}

	/**
	 * Locate a binary in the system PATH using `which`.
	 *
	 * @param string $binary Binary name or absolute path.
	 * @return string|null Resolved executable path, or null if not found.
	 */
	private static function which( string $binary ): ?string {
		if ( ! self::exec_is_available() ) {
			return null;
		}

		// Absolute path: check directly.
		if ( str_starts_with( $binary, '/' ) && is_executable( $binary ) ) {
			return $binary;
		}

		$output    = [];
		$exit_code = -1;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( 'which ' . escapeshellarg( $binary ) . ' 2>/dev/null', $output, $exit_code );

		if ( 0 === $exit_code && ! empty( $output[0] ) && is_executable( $output[0] ) ) {
			return trim( $output[0] );
		}

		return null;
	}
}
