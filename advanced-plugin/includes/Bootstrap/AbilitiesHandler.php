<?php

declare(strict_types=1);

namespace SdAiAgentAdvanced\Bootstrap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SdAiAgent\Abilities\DatabaseAbilities;
use SdAiAgent\Abilities\FileMutationAbilities;
use SdAiAgent\Abilities\GitAbilities;
use SdAiAgent\Abilities\PluginBuilderAbilities;
use SdAiAgent\Abilities\PluginDownloadAbilities;
use SdAiAgent\Abilities\ScaffoldBlockThemeAbility;
use SdAiAgent\Abilities\StyleVariationAbilities;
use SdAiAgent\Abilities\UserManagementAbilities;
use SdAiAgent\Abilities\WordPressAdvancedAbilities;
use SdAiAgent\Abilities\WpCliAbilities;
use SdAiAgent\Abilities\WpRestAbilities;
use SdAiAgent\Core\Filesystem\FileModGate;
use SdAiAgent\PluginBuilder\PluginSandbox;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

/**
 * Registers advanced ability groups supplied by the companion plugin.
 */
#[Handler(
	container: 'sd-ai-agent',
	strategy: Handler::INIT_JUST_IN_TIME,
)]
final class AbilitiesHandler {

	/**
	 * Register advanced abilities on the WordPress Abilities API hook.
	 */
	#[Action( tag: 'wp_abilities_api_init', priority: 20 )]
	public function register_advanced_abilities(): void {
		FileMutationAbilities::register_abilities();
		GitAbilities::register_abilities();
		PluginDownloadAbilities::register_abilities();
		PluginBuilderAbilities::register_abilities();
		DatabaseAbilities::register_abilities();
		WordPressAdvancedAbilities::register_abilities();
		UserManagementAbilities::register_abilities();
		$this->register_scaffold_block_theme();
		StyleVariationAbilities::register_abilities();
		WpCliAbilities::register_ability();
		WpRestAbilities::register_abilities();
	}

	/**
	 * Register advanced dispatcher categories.
	 */
	#[Action( tag: 'wp_abilities_api_categories_init', priority: 20 )]
	public function register_advanced_categories(): void {
		WpCliAbilities::register_category();
		WpRestAbilities::register_category();
	}

	/**
	 * Auto-deactivate plugins that triggered a fatal error on a previous activation.
	 */
	#[Action( tag: 'init', priority: 10 )]
	public function auto_deactivate_fatal_plugins(): void {
		PluginSandbox::auto_deactivate_fatal_plugins();
	}

	/**
	 * Register the advanced-only block-theme scaffolder ability.
	 */
	private function register_scaffold_block_theme(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! FileModGate::shared_code_modifications_allowed() ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/scaffold-block-theme',
			[
				'label'         => __( 'Scaffold Block Theme', 'superdav-ai-agent' ),
				'description'   => __(
					'Create the on-disk skeleton for a new WordPress block theme (theme.json, style.css, functions.php, templates/index.html) inside wp-content/themes/{slug}/. Requires the install_themes capability. If the user mentioned an existing site URL in the conversation, prefer calling sd-ai-agent/site-scrape first to pre-fill brand facts before scaffolding — but never block the build to ask for a URL the user has not already volunteered.',
					'superdav-ai-agent'
				),
				'ability_class' => ScaffoldBlockThemeAbility::class,
			]
		);
	}
}
