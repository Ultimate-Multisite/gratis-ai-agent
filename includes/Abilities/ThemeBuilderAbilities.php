<?php

declare(strict_types=1);
/**
 * Theme Builder abilities — block-theme scaffolding and activation.
 *
 * Registers two abilities via the WordPress 7.0+ Abilities API that the
 * theme-builder onboarding branch (Phase 3 of t226) relies on:
 *
 *   - sd-ai-agent/scaffold-block-theme
 *   - sd-ai-agent/activate-theme
 *
 * Both abilities also stand alone outside onboarding — any agent flow that
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
 * ThemeBuilderAbilities — static registry for the two theme-builder abilities.
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

		wp_register_ability(
			'sd-ai-agent/scaffold-block-theme',
			[
				'label'         => __( 'Scaffold Block Theme', 'superdav-ai-agent' ),
				'description'   => __(
					'Create the on-disk skeleton for a new WordPress block theme (theme.json, style.css, functions.php, templates/index.html) inside wp-content/themes/{slug}/. Requires the install_themes capability. Before starting the design interview, always ask the user: "Do you have an existing site? If yes, paste the URL and I will pre-fill what I can using the sd-ai-agent/site-scrape ability." This turns a 20-minute interview into a 2-minute confirm-what-we-found session.',
					'superdav-ai-agent'
				),
				'ability_class' => ScaffoldBlockThemeAbility::class,
			]
		);

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
	}
}
