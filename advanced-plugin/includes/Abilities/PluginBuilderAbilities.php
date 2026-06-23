<?php

declare(strict_types=1);
/**
 * Plugin Builder abilities — AI-powered plugin generation, sandboxing, and activation.
 *
 * Registers six abilities via the WordPress 7.0+ Abilities API:
 *   - sd-ai-agent/generate-plugin
 *   - sd-ai-agent/sandbox-test-plugin
 *   - sd-ai-agent/sandbox-activate-plugin
 *   - sd-ai-agent/update-plugin-sandboxed
 *   - sd-ai-agent/scan-plugin-hooks
 *   - sd-ai-agent/scan-theme-hooks
 *
 * Individual ability classes live in their own PSR-4 files in this directory:
 *   - GeneratePluginAbility.php
 *   - SandboxTestPluginAbility.php
 *   - SandboxActivatePluginAbility.php
 *   - UpdatePluginSandboxedAbility.php
 *   - ScanPluginHooksAbility.php
 *   - ScanThemeHooksAbility.php
 *
 * @package SdAiAgent\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PluginBuilderAbilities — static registry and proxy class.
 *
 * @since 1.5.0
 */
class PluginBuilderAbilities {

	/**
	 * Register all plugin builder abilities with the WordPress Abilities API.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/generate-plugin',
			[
				'label'         => __( 'Generate Plugin', 'superdav-ai-agent' ),
				'description'   => __( 'Generate a WordPress plugin from a natural-language description. Returns the implementation plan and complete PHP source code.', 'superdav-ai-agent' ),
				'ability_class' => GeneratePluginAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/sandbox-test-plugin',
			[
				'label'         => __( 'Sandbox Test Plugin', 'superdav-ai-agent' ),
				'description'   => __( 'Run layers 1 and 2 of the sandbox safety check against a plugin: PHP syntax validation and isolated subprocess include test.', 'superdav-ai-agent' ),
				'ability_class' => SandboxTestPluginAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/sandbox-activate-plugin',
			[
				'label'         => __( 'Sandbox Activate Plugin', 'superdav-ai-agent' ),
				'description'   => __( 'Activate a plugin using layer 3 transactional safety: error handler + shutdown guard. Auto-deactivates on fatal error.', 'superdav-ai-agent' ),
				'ability_class' => SandboxActivatePluginAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/update-plugin-sandboxed',
			[
				'label'         => __( 'Update Plugin (Sandboxed)', 'superdav-ai-agent' ),
				'description'   => __( 'Update a running plugin with new code: backup → stage → sandbox test → swap. Rolls back automatically on failure.', 'superdav-ai-agent' ),
				'ability_class' => UpdatePluginSandboxedAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/scan-plugin-hooks',
			[
				'label'         => __( 'Scan Plugin Hooks', 'superdav-ai-agent' ),
				'description'   => __( 'Scan an installed plugin for WordPress hooks (actions and filters) to enable extension-plugin generation.', 'superdav-ai-agent' ),
				'ability_class' => ScanPluginHooksAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/scan-theme-hooks',
			[
				'label'         => __( 'Scan Theme Hooks', 'superdav-ai-agent' ),
				'description'   => __( 'Scan an installed theme for WordPress hooks (actions and filters) to enable extension-plugin generation.', 'superdav-ai-agent' ),
				'ability_class' => ScanThemeHooksAbility::class,
			]
		);
	}
}
