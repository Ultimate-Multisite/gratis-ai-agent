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
				'description'   => 'Capture a screenshot of the current page the user is viewing. For routine review, prefer a viewport capture or a specific CSS selector. Full-page capture is opt-in and should only be used when the user explicitly requests the whole page or a viewport/selector capture is insufficient. Returns a base64 JPEG image for visual review by the AI.',
				'category'      => 'sd-ai-agent-js',
				'input_schema'  => array(
					'type'       => 'object',
					'properties' => array(
						'selector' => array(
							'type'        => 'string',
							'description' => 'CSS selector to capture a specific element (e.g. "#main-content", ".entry-content"). Prefer this for section-level review. Leave empty to capture the viewport of the page body.',
						),
						'fullPage' => array(
							'type'        => 'boolean',
							'description' => 'If true, captures the full scrollable page height instead of just the viewport. Default: false. Use only when the user explicitly requests a whole-page screenshot or a viewport/selector capture is insufficient.',
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
				'description'   => 'Capture a same-origin WordPress URL for visual review. For routine review, use the default viewport capture; full-page capture is only for an explicit whole-page request or when a viewport capture is insufficient. Open multisite subdomains from that origin first.',
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
							'description' => 'If true, captures the full scrollable page height instead of just the viewport. Default: false. Use only when the user explicitly requests a whole-page screenshot or a viewport capture is insufficient.',
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
				'name'          => 'sd-ai-agent-js/validate-page-quality',
				'label'         => 'Validate Rendered Page Quality',
				'description'   => 'Validate current affected published pages at the agent profile\'s required viewports. Setup performs strict first-impression, composition, branding, media, accessibility, and responsive checks; General performs focused regression-safe page checks. Reports are bound to the current page mutation token.',
				'category'      => 'sd-ai-agent-js',
				'input_schema'  => array(
					'type'       => 'object',
					'properties' => array(
						'profile'       => array(
							'type' => 'string',
							'enum' => array( 'setup', 'incremental' ),
						),
						'quality_token' => array( 'type' => 'string' ),
						'pages'         => array(
							'type'  => 'array',
							'items' => self::page_quality_page_schema(),
						),
						'hero_contract' => self::page_quality_hero_contract_schema(),
						'viewports'     => array(
							'type'  => 'array',
							'items' => self::page_quality_viewport_schema(),
						),
					),
					'required'   => array( 'profile', 'quality_token', 'pages', 'hero_contract', 'viewports' ),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'complete'      => array( 'type' => 'boolean' ),
						'passed'        => array( 'type' => 'boolean' ),
						'profile'       => array( 'type' => 'string' ),
						'quality_token' => array( 'type' => 'string' ),
						'viewports'     => array(
							'type'  => 'array',
							'items' => self::page_quality_viewport_schema(),
						),
						'reports'       => array(
							'type'  => 'array',
							'items' => self::page_quality_report_schema(),
						),
						'violations'    => array(
							'type'  => 'array',
							'items' => self::page_quality_finding_schema(),
						),
						'warnings'      => array(
							'type'  => 'array',
							'items' => self::page_quality_finding_schema(),
						),
						'screenshots'   => array(
							'type'  => 'array',
							'items' => self::page_quality_screenshot_schema(),
						),
						'minimum_score' => array( 'type' => 'number' ),
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
						'stylesheet'   => array(
							'type'        => 'string',
							'description' => 'Expected active generated theme stylesheet from the current project validation.',
						),
						'fingerprint'  => array(
							'type'        => 'string',
							'description' => 'Current fingerprint returned by validate-block-theme-project.',
						),
						'homepage_url' => array(
							'type'        => 'string',
							'description' => 'Active homepage URL.',
						),
						'interior_url' => array(
							'type'        => 'string',
							'description' => 'Published interior page URL, distinct from homepage_url.',
						),
					),
					'required'   => array( 'stylesheet', 'fingerprint', 'homepage_url', 'interior_url' ),
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

	/** @return array<string,mixed> */
	private static function page_quality_viewport_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'label'  => array(
					'type' => 'string',
					'enum' => array( 'mobile', 'tablet', 'desktop' ),
				),
				'width'  => array( 'type' => 'integer' ),
				'height' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'label', 'width', 'height' ),
		);
	}

	/** @return array<string,mixed> */
	private static function page_quality_page_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'     => array( 'type' => 'integer' ),
				'revision_id' => array( 'type' => 'integer' ),
				'url'         => array( 'type' => 'string' ),
				'fields'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'role'        => array(
					'type' => 'string',
					'enum' => array( 'homepage', 'page' ),
				),
			),
			'required'   => array( 'post_id', 'revision_id', 'url', 'fields', 'role' ),
		);
	}

	/** @return array<string,mixed> */
	private static function page_quality_hero_contract_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'strategy'                         => array(
					'type' => 'string',
					'enum' => array( 'balanced', 'immersive-media', 'split-media', 'editorial-feature', 'product-focus' ),
				),
				'media_role'                       => array( 'type' => 'string' ),
				'desktop_media_min_viewport_ratio' => array( 'type' => 'number' ),
				'desktop_min_height_vh'            => array( 'type' => 'integer' ),
				'primary_cta_above_fold'           => array( 'type' => 'boolean' ),
			),
			'required'   => array( 'strategy', 'media_role', 'desktop_media_min_viewport_ratio', 'desktop_min_height_vh', 'primary_cta_above_fold' ),
		);
	}

	/** @return array<string,mixed> */
	private static function page_quality_finding_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'code'        => array( 'type' => 'string' ),
				'url'         => array( 'type' => 'string' ),
				'viewport'    => self::page_quality_viewport_schema(),
				'selector'    => array( 'type' => 'string' ),
				'evidence'    => array( 'type' => 'string' ),
				'severity'    => array( 'type' => 'string' ),
				'remediation' => array( 'type' => 'string' ),
			),
			'required'   => array( 'code', 'url', 'selector', 'evidence', 'severity', 'remediation' ),
		);
	}

	/** @return array<string,mixed> */
	private static function page_quality_report_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'           => array( 'type' => 'integer' ),
				'revision_id'       => array( 'type' => 'integer' ),
				'requested_url'     => array( 'type' => 'string' ),
				'final_url'         => array( 'type' => 'string' ),
				'role'              => array( 'type' => 'string' ),
				'is_homepage'       => array( 'type' => 'boolean' ),
				'viewport'          => self::page_quality_viewport_schema(),
				'success'           => array( 'type' => 'boolean' ),
				'violations'        => array(
					'type'  => 'array',
					'items' => self::page_quality_finding_schema(),
				),
				'warnings'          => array(
					'type'  => 'array',
					'items' => self::page_quality_finding_schema(),
				),
				'checks'            => array(
					'type'       => 'object',
					'properties' => array(
						'composition_score' => array( 'type' => 'number' ),
					),
				),
				'score'             => array( 'type' => 'number' ),
				'active_stylesheet' => array( 'type' => 'string' ),
			),
			'required'   => array( 'post_id', 'revision_id', 'requested_url', 'final_url', 'role', 'viewport', 'success', 'violations', 'warnings' ),
		);
	}

	/** @return array<string,mixed> */
	private static function page_quality_screenshot_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'  => array( 'type' => 'integer' ),
				'url'      => array( 'type' => 'string' ),
				'viewport' => self::page_quality_viewport_schema(),
				'success'  => array( 'type' => 'boolean' ),
				'image'    => array( 'type' => 'string' ),
				'width'    => array( 'type' => 'integer' ),
				'height'   => array( 'type' => 'integer' ),
				'error'    => array( 'type' => 'string' ),
			),
			'required'   => array( 'post_id', 'url', 'viewport', 'success', 'image', 'width', 'height', 'error' ),
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
