<?php

declare(strict_types=1);
/**
 * Catalog of client-side (browser-executed) abilities in the sd-ai-agent-js namespace.
 *
 * This class is the single source of truth for the metadata of abilities that
 * run in the browser. The JS registry (src/abilities/registry.js) mirrors these
 * definitions. AgentLoop uses this catalog to validate client-posted descriptors
 * and reject any name not in the catalog.
 *
 * @package SdAiAgent\Abilities\Js
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities\Js;

class JsAbilityCatalog {

	/**
	 * Return all registered client-side ability descriptors.
	 *
	 * Each descriptor matches the shape expected by AgentLoop::resolve_abilities()
	 * when building synthetic WP_Ability objects from client-posted descriptors.
	 *
	 * @return list<array{
	 *   name: string,
	 *   label: string,
	 *   description: string,
	 *   category: string,
	 *   input_schema: array<string, mixed>,
	 *   output_schema: array<string, mixed>,
	 *   annotations: array<string, mixed>,
	 *   screens: string[]
	 * }>
	 */
	public static function get_descriptors(): array {
		return array(
			array(
				'name'          => 'sd-ai-agent-js/navigate-to',
				'label'         => 'Navigate to Admin Page',
				'description'   => 'Navigate to a WordPress admin page without a full page reload when inside the admin SPA.',
				'category'      => 'sd-ai-agent-js',
				'input_schema'  => array(
					'type'       => 'object',
					'properties' => array(
						'path' => array(
							'type'        => 'string',
							'description' => 'wp-admin-relative path, e.g. "plugins.php" or "edit.php?post_type=page".',
						),
					),
					'required'   => array( 'path' ),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'navigated' => array( 'type' => 'boolean' ),
						'path'      => array( 'type' => 'string' ),
					),
				),
				'annotations'   => array(
					'readonly' => true,
				),
				'screens'       => array( 'all' ),
			),
			array(
				'name'          => 'sd-ai-agent-js/refresh-page',
				'label'         => 'Refresh Current Page',
				'description'   => 'Refresh the current browser page while preserving the open AI Agent widget and current session. Use after site changes that did not return an affected descriptor for live preview.',
				'category'      => 'sd-ai-agent-js',
				'input_schema'  => array(
					'type'       => 'object',
					'properties' => array(),
					'required'   => array(),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'refresh_scheduled' => array( 'type' => 'boolean' ),
						'url'               => array( 'type' => 'string' ),
					),
				),
				'annotations'   => array(
					'readonly' => true,
				),
				'screens'       => array( 'all' ),
			),
			array(
				'name'          => 'sd-ai-agent-js/insert-block',
				'label'         => 'Insert Block',
				'description'   => 'Insert a Gutenberg block into the active block editor. Only available on editor screens.',
				'category'      => 'sd-ai-agent-js',
				'input_schema'  => array(
					'type'       => 'object',
					'properties' => array(
						'blockName'  => array(
							'type'        => 'string',
							'description' => 'Block name, e.g. "core/paragraph".',
						),
						'attributes' => array(
							'type'        => 'object',
							'description' => 'Block attributes.',
						),
						'innerHTML'  => array(
							'type'        => 'string',
							'description' => 'Optional inner HTML for the block.',
						),
					),
					'required'   => array( 'blockName' ),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'inserted'  => array( 'type' => 'boolean' ),
						'clientId'  => array( 'type' => 'string' ),
						'blockName' => array( 'type' => 'string' ),
					),
				),
				'annotations'   => array(
					'readonly' => false,
				),
				'screens'       => array( 'editor' ),
			),
			array(
				'name'          => 'sd-ai-agent-js/capture-screenshot',
				'label'         => 'Capture Screenshot',
				'description'   => 'Capture a screenshot of the current page the user is viewing. Optionally target a specific element with a CSS selector. Returns a base64 JPEG image for visual review by the AI.',
				'category'      => 'sd-ai-agent-js',
				'input_schema'  => array(
					'type'       => 'object',
					'properties' => array(
						'selector' => array(
							'type'        => 'string',
							'description' => 'CSS selector to capture a specific element (e.g. "#main-content", ".entry-content"). Leave empty to capture the full page body.',
						),
						'fullPage' => array(
							'type'        => 'boolean',
							'description' => 'If true, captures the full scrollable page height instead of just the viewport. Default: false.',
						),
					),
					'required'   => array(),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'image'     => array(
							'type'        => 'string',
							'description' => 'Base64-encoded JPEG data URL of the screenshot.',
						),
						'width'     => array( 'type' => 'integer' ),
						'height'    => array( 'type' => 'integer' ),
						'url'       => array( 'type' => 'string' ),
						'truncated' => array(
							'type'        => 'boolean',
							'description' => 'True if fullPage capture was clamped to the maximum height.',
						),
						'error'     => array( 'type' => 'string' ),
					),
				),
				'annotations'   => array(
					'readonly' => true,
				),
				'screens'       => array( 'all' ),
			),
			array(
				'name'          => 'sd-ai-agent-js/screenshot-url',
				'label'         => 'Screenshot URL',
				'description'   => 'Capture a same-origin WordPress URL for visual review. Open multisite subdomains from that origin first.',
				'category'      => 'sd-ai-agent-js',
				'input_schema'  => array(
					'type'       => 'object',
					'properties' => array(
						'url'      => array(
							'type'        => 'string',
							'description' => 'URL to screenshot. Can be a full URL on this site or a relative path (e.g. "/about/", "/contact/", "/").',
						),
						'width'    => array(
							'type'        => 'integer',
							'description' => 'Viewport width in pixels for the capture. Default: 1280.',
						),
						'height'   => array(
							'type'        => 'integer',
							'description' => 'Viewport height in pixels for the capture. Default: 800.',
						),
						'fullPage' => array(
							'type'        => 'boolean',
							'description' => 'If true, captures the full scrollable page height instead of just the viewport. Default: false.',
						),
					),
					'required'   => array( 'url' ),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'image'     => array(
							'type'        => 'string',
							'description' => 'Base64-encoded JPEG data URL of the screenshot.',
						),
						'width'     => array( 'type' => 'integer' ),
						'height'    => array( 'type' => 'integer' ),
						'url'       => array( 'type' => 'string' ),
						'truncated' => array(
							'type'        => 'boolean',
							'description' => 'True if fullPage capture was clamped to the maximum height.',
						),
						'error'     => array( 'type' => 'string' ),
					),
				),
				'annotations'   => array(
					'readonly' => true,
				),
				'screens'       => array( 'all' ),
			),
			array(
				'name'          => 'sd-ai-agent-js/validate-theme-completion',
				'label'         => 'Validate Generated Theme Completion',
				'description'   => 'Validate the active generated WordPress theme on the real homepage and one interior page at mobile, tablet, and desktop viewports. Returns deterministic render, accessibility, responsive, content, and remediation evidence; previews and screenshots do not satisfy this check.',
				'category'      => 'sd-ai-agent-js',
				'input_schema'  => array(
					'type'       => 'object',
					'properties' => array(
						'stylesheet'  => array(
							'type'        => 'string',
							'description' => 'Expected active generated theme stylesheet from the current project validation.',
						),
						'fingerprint' => array(
							'type'        => 'string',
							'description' => 'Current fingerprint returned by validate-block-theme-project.',
						),
						'urls'        => array(
							'type'        => 'array',
							'minItems'    => 2,
							'maxItems'    => 2,
							'items'       => array( 'type' => 'string' ),
							'description' => 'Exactly the active homepage URL and one published interior page URL.',
						),
					),
					'required'   => array( 'stylesheet', 'fingerprint', 'urls' ),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'success'              => array( 'type' => 'boolean' ),
						'complete'             => array( 'type' => 'boolean' ),
						'passed'               => array( 'type' => 'boolean' ),
						'fatal_render_failure' => array( 'type' => 'boolean' ),
						'stylesheet'           => array( 'type' => 'string' ),
						'fingerprint'          => array( 'type' => 'string' ),
						'reports'              => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'violations'           => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
				),
				'annotations'   => array(
					'readonly' => true,
				),
				'screens'       => array( 'all' ),
			),
		);
	}

	/**
	 * Return a map of ability name → descriptor for fast lookup.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_descriptors_by_name(): array {
		$map = array();
		foreach ( self::get_descriptors() as $descriptor ) {
			$map[ $descriptor['name'] ] = $descriptor;
		}
		return $map;
	}

	/**
	 * Check whether a given ability name is in the catalog.
	 *
	 * @param string $name Ability name to check.
	 * @return bool
	 */
	public static function has( string $name ): bool {
		$map = self::get_descriptors_by_name();
		return isset( $map[ $name ] );
	}
}
