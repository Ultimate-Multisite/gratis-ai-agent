<?php

declare(strict_types=1);
/**
 * Preview Renderer service.
 *
 * Generates desktop (1280×800) and mobile (375×812) screenshots of HTML
 * preview files written by the Setup Assistant during design-direction
 * selection. Core falls back to client-side iframe display; server-side
 * headless screenshot rendering is provided by the advanced companion plugin.
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
 *   2. Return the HTML file URL so
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

		$advanced_result = apply_filters( 'sd_ai_agent_preview_renderer_result', null, $html_path, $desktop_path, $mobile_path );
		if ( is_array( $advanced_result ) ) {
			$advanced_html_url         = isset( $advanced_result['html_url'] ) && is_string( $advanced_result['html_url'] ) ? $advanced_result['html_url'] : $html_url;
			$advanced_desktop_url      = isset( $advanced_result['desktop_url'] ) && is_string( $advanced_result['desktop_url'] ) ? $advanced_result['desktop_url'] : null;
			$advanced_mobile_url       = isset( $advanced_result['mobile_url'] ) && is_string( $advanced_result['mobile_url'] ) ? $advanced_result['mobile_url'] : null;
			$desktop_unavailable       = isset( $advanced_result['desktop_unavailable'] ) ? (bool) $advanced_result['desktop_unavailable'] : false;
			$mobile_unavailable        = isset( $advanced_result['mobile_unavailable'] ) ? (bool) $advanced_result['mobile_unavailable'] : false;
			$advanced_rendering_method = isset( $advanced_result['rendering_method'] ) && is_string( $advanced_result['rendering_method'] ) ? $advanced_result['rendering_method'] : 'screenshot';

			return [
				'html_url'            => $advanced_html_url,
				'desktop_url'         => $advanced_desktop_url,
				'mobile_url'          => $advanced_mobile_url,
				'desktop_unavailable' => $desktop_unavailable,
				'mobile_unavailable'  => $mobile_unavailable,
				'rendering_method'    => $advanced_rendering_method,
			];
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
	 * Check whether core server-side screenshot rendering is available.
	 *
	 * @return bool
	 */
	public static function can_render_server_side(): bool {
		return false;
	}

	/**
	 * Core never uses shell execution for preview rendering.
	 *
	 * @return bool
	 */
	public static function exec_is_available(): bool {
		return false;
	}

	/**
	 * Core does not probe for a Node.js binary.
	 *
	 * @return null Always null in the core plugin.
	 */
	public static function find_node(): null {
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
}
