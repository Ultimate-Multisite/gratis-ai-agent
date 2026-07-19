<?php

declare(strict_types=1);
/**
 * Theme Builder abilities — token compilation, block-theme scaffolding, and activation.
 *
 * Registers Theme Builder abilities via the WordPress 7.0+ Abilities API that the
 * theme-builder onboarding branch (Phase 3 of t226) relies on:
 *
 *   - sd-ai-agent/compile-design-tokens
 *   - sd-ai-agent/scaffold-block-theme
 *   - sd-ai-agent/validate-block-theme-project
 *   - sd-ai-agent/activate-theme
 *
 * These abilities also stand alone outside onboarding — any agent flow that
 * needs to generate or switch a theme can call them.
 *
 * @package SdAiAgent\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ThemeBuilderAbilities — static registry for Theme Builder abilities.
 *
 * @since 1.6.0
 */
class ThemeBuilderAbilities {

	/**
	 * Register all theme-builder abilities with the WordPress Abilities API.
	 *
	 * Safe to call before the Abilities API has loaded: returns early when
	 * `wp_register_ability` is not available so the bootstrap order is not
	 * coupled to plugin load order.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		// The block-theme scaffolder is supplied by Superdav AI Agent Advanced.

		wp_register_ability(
			'sd-ai-agent/activate-theme',
			[
				'label'         => __( 'Activate Theme', 'superdav-ai-agent' ),
				'description'   => __(
					'Switch the active WordPress theme. Returns the previously-active stylesheet so the agent can offer an undo step. Requires the switch_themes capability.',
					'superdav-ai-agent'
				),
				'ability_class' => ActivateThemeAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/validate-block-theme-project',
			[
				'label'         => __( 'Validate Block Theme Project', 'superdav-ai-agent' ),
				'description'   => __(
					'Read and validate a complete block-theme project before activation. Returns deterministic diagnostics for theme.json, templates, parts, patterns, style variations, assets, placeholders, and block markup without writing files or executing project PHP.',
					'superdav-ai-agent'
				),
				'ability_class' => ValidateBlockThemeProjectAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/render-design-previews',
			[
				'label'         => __( 'Render Design Previews', 'superdav-ai-agent' ),
				'description'   => __(
					'Generate desktop (1280×800) and mobile (375×812) preview screenshots for the HTML design-direction files produced by the Theme Builder. Returns public URLs for each viewport so the chat UI can show both side-by-side with click-to-zoom.',
					'superdav-ai-agent'
				),
				'ability_class' => RenderDesignPreviewsAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/generate-menu-page',
			[
				'label'         => __( 'Generate Menu Page', 'superdav-ai-agent' ),
				'description'   => __(
					'Create a structured hospitality menu page at /menu/ from categorised items and prices. Accepts menu categories with items (name, price, optional description, allergens, dietary tags). Publishes as a WordPress page with slug "menu". Idempotent: re-running updates the existing page.',
					'superdav-ai-agent'
				),
				'ability_class' => GenerateMenuPageAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/compile-design-tokens',
			[
				'label'         => __( 'Compile Design Tokens', 'superdav-ai-agent' ),
				'description'   => __(
					'Validate and compile a complete design-token contract into deterministic theme.json v3, Global Styles, semantic palette, style variation, and governed artifact manifest outputs. This read-only ability does not write files, options, posts, or Global Styles.',
					'superdav-ai-agent'
				),
				'ability_class' => CompileDesignTokensAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/validate-palette-contrast',
			[
				'label'         => __( 'Validate Palette Contrast', 'superdav-ai-agent' ),
				'description'   => __(
					'Check a theme.json colour palette against WCAG AA contrast minimums (4.5:1 body, 3:1 large text and UI components). Call AT THE END of the direction-selection step, BEFORE sd-ai-agent/scaffold-block-theme, so the agent can either auto-adjust failing pairs or surface options to the user. Returns failing pairs and minimal hex suggestions that nudge the original colour into compliance.',
					'superdav-ai-agent'
				),
				'ability_class' => ValidatePaletteContrastAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/generate-logo-svg',
			[
				'label'         => __( 'Generate Logo SVG', 'superdav-ai-agent' ),
				'description'   => __(
					'Generate sanitised SVG logo candidates for the Theme Builder. Action "generate" returns 1–3 inline SVG candidates (data URIs + media-library attachments). Action "select_candidate" promotes a chosen candidate to the active site logo (sets `custom_logo` theme mod and `site_icon`). All SVG markup is sanitised: <script>/<foreignObject> are stripped, external <image>/<use> refs are removed, javascript: URIs and inline event handlers are scrubbed. Falls back to a type-only wordmark when AI generation is unavailable or returns invalid SVG. Pass `existing_logo_url` to skip generation when the user already has a logo.',
					'superdav-ai-agent'
				),
				'ability_class' => GenerateLogoSvgAbility::class,
			]
		);
	}
}
