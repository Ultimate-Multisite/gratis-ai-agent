<?php

declare(strict_types=1);
/**
 * Render Design Previews ability.
 *
 * Generates desktop (1280×800) and mobile (375×812) preview screenshots for
 * a set of HTML design-direction preview files written by the Theme Builder
 * agent. Returns public URLs so the chat UI can render both viewports
 * side-by-side with click-to-zoom.
 *
 * When server-side headless rendering is unavailable (no exec(), no Node.js,
 * or Playwright not installed), the ability returns `rendering_method: iframe`
 * and the front-end falls back to responsive iframes.
 *
 * @package SdAiAgent\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Services\PreviewRenderer;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render Design Previews ability.
 *
 * @since 1.15.0
 */
class RenderDesignPreviewsAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Render Design Previews', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __(
			'Generate desktop (1280×800) and mobile (375×812) preview screenshots for the HTML design-direction files produced by the Theme Builder. Returns public URLs for each viewport. Falls back to iframe display when server-side headless rendering is unavailable.',
			'superdav-ai-agent'
		);
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'preview_paths' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'Paths relative to wp-content for each HTML preview file, e.g. ["uploads/sd-ai-agent/design-previews/session123/design-1.html"]. The ability resolves them to absolute paths, renders screenshots, and returns public URLs.',
				],
			],
			'required'   => [ 'preview_paths' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'design_previews'  => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'name'                => [ 'type' => 'string' ],
							'html_url'            => [ 'type' => 'string' ],
							'desktop_url'         => [ 'type' => [ 'string', 'null' ] ],
							'mobile_url'          => [ 'type' => [ 'string', 'null' ] ],
							'desktop_unavailable' => [ 'type' => 'boolean' ],
							'mobile_unavailable'  => [ 'type' => 'boolean' ],
							'rendering_method'    => [
								'type' => 'string',
								'enum' => [ 'screenshot', 'iframe' ],
							],
						],
					],
				],
				'rendering_method' => [
					'type'        => 'string',
					'description' => '"screenshot" when PNG files were produced, "iframe" when the front-end should use responsive iframes.',
				],
				'message'          => [ 'type' => 'string' ],
				'error'            => [ 'type' => 'string' ],
			],
		];
	}

	/**
	 * Execute the render-design-previews ability.
	 *
	 * @param array<string,mixed> $input Input with 'preview_paths'.
	 * @return array<string,mixed>|\WP_Error
	 */
	protected function execute_callback( $input ) {
		/** @var array<string,mixed> $input */
		$preview_paths = $input['preview_paths'] ?? [];

		if ( ! is_array( $preview_paths ) || empty( $preview_paths ) ) {
			return new WP_Error( 'missing_preview_paths', 'preview_paths must be a non-empty array of wp-content-relative file paths.' );
		}

		$wp_content_dir  = untrailingslashit( WP_CONTENT_DIR );
		$design_previews = [];
		$any_screenshot  = false;
		$any_iframe      = false;

		foreach ( $preview_paths as $idx => $relative_path ) {
			$relative_path = (string) $relative_path;

			// Strip any leading 'wp-content/' prefix the agent might include.
			$relative_path = ltrim( $relative_path, '/' );
			if ( str_starts_with( $relative_path, 'wp-content/' ) ) {
				$relative_path = substr( $relative_path, strlen( 'wp-content/' ) );
			}

			$abs_path = $wp_content_dir . '/' . $relative_path;

			// Normalise path separators and prevent directory traversal.
			$real = realpath( $abs_path );
			if ( false === $real ) {
				// File does not exist yet — use the unresolved path.
				// PreviewRenderer::render() will return an iframe fallback.
				$real = $abs_path;
			}

			// Safety: ensure the resolved path stays under wp-content.
			if ( 0 !== strncmp( $real, $wp_content_dir, strlen( $wp_content_dir ) ) ) {
				return new WP_Error(
					'path_traversal',
					// @phpstan-ignore-next-line
					sprintf( 'Path traversal attempt detected: %s', $relative_path )
				);
			}

			// Generate a human-readable name from the filename.
			$name = $this->name_from_path( $relative_path, $idx );

			$result = PreviewRenderer::render( $real );

			$design_previews[] = array_merge( [ 'name' => $name ], $result );

			if ( 'screenshot' === $result['rendering_method'] ) {
				$any_screenshot = true;
			} else {
				$any_iframe = true;
			}
		}

		$rendering_method = ( $any_screenshot && ! $any_iframe ) ? 'screenshot'
			: ( ( ! $any_screenshot && $any_iframe ) ? 'iframe' : 'mixed' );

		$count = count( $design_previews );

		return [
			'design_previews'  => $design_previews,
			'rendering_method' => $rendering_method,
			'message'          => sprintf(
				/* translators: %d: number of design previews rendered */
				_n(
					'%d design preview rendered.',
					'%d design previews rendered.',
					$count,
					'superdav-ai-agent'
				),
				$count
			),
		];
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name )
			&& current_user_can( 'edit_theme_options' );
	}

	protected function meta(): array {
		return [
			'mcp'         => [ 'public' => true ],
			'annotations' => [
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			],
		];
	}

	/**
	 * Derive a human-readable design direction name from the file path.
	 *
	 * "uploads/sd-ai-agent/design-previews/sess123/design-2.html" → "Design 2"
	 *
	 * @param string $relative_path Path relative to wp-content.
	 * @param int    $idx           Zero-based index (used as fallback).
	 * @return string
	 */
	private function name_from_path( string $relative_path, int $idx ): string {
		$filename = pathinfo( $relative_path, PATHINFO_FILENAME );

		// "design-1" → "Design 1", "my-direction" → "My Direction".
		$name = str_replace( '-', ' ', $filename );
		$name = ucwords( $name );

		return $name ?: sprintf(
			/* translators: %d: design direction number */
			__( 'Design %d', 'superdav-ai-agent' ),
			$idx + 1
		);
	}
}
