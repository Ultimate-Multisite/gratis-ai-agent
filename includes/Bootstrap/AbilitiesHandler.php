<?php
/**
 * DI handler that registers all WordPress Abilities.
 *
 * Replaces the 35 inline `XxxAbilities::register()` calls in
 * `sd-ai-agent.php` with a single DI-managed handler. Each ability
 * class's `register_abilities()` method is called on the
 * `wp_abilities_api_init` hook — bypassing the now-removed `register()`
 * stub layer since the DI system handles hook attachment directly.
 *
 * Also owns the `init`-time hooks previously embedded in the three ability
 * classes that needed extra wiring beyond abilities registration:
 *  - CustomPostTypeAbilities::restore_persisted_post_types() at priority 5
 *  - CustomTaxonomyAbilities::restore_persisted_taxonomies() at priority 5
 *  - PluginSandbox::auto_deactivate_fatal_plugins() at priority 10
 *
 * @package SdAiAgent\Bootstrap
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Bootstrap;

use SdAiAgent\Abilities\AiImageAbilities;
use SdAiAgent\Abilities\BlockAbilities;
use SdAiAgent\Abilities\ContentAbilities;
use SdAiAgent\Abilities\CustomPostTypeAbilities;
use SdAiAgent\Abilities\CustomTaxonomyAbilities;
use SdAiAgent\Abilities\DatabaseAbilities;
use SdAiAgent\Abilities\DesignSystemAbilities;
use SdAiAgent\Abilities\EditorialAbilities;
use SdAiAgent\Abilities\FeedbackAbilities;
use SdAiAgent\Abilities\FileAbilities;
use SdAiAgent\Abilities\GitAbilities;
use SdAiAgent\Abilities\GlobalStylesAbilities;
use SdAiAgent\Abilities\GoogleAnalyticsAbilities;
use SdAiAgent\Abilities\GscAbilities;
use SdAiAgent\Abilities\ImageAbilities;
use SdAiAgent\Abilities\InternetSearchAbilities;
use SdAiAgent\Abilities\SiteScrapeAbility;
use SdAiAgent\Abilities\KnowledgeAbilities;
use SdAiAgent\Abilities\MarketingAbilities;
use SdAiAgent\Abilities\MediaAbilities;
use SdAiAgent\Abilities\MemoryAbilities;
use SdAiAgent\Abilities\MenuAbilities;
use SdAiAgent\Abilities\NavigationAbilities;
use SdAiAgent\Abilities\OptionsAbilities;
use SdAiAgent\Abilities\PluginBuilderAbilities;
use SdAiAgent\Abilities\PluginDownloadAbilities;
use SdAiAgent\PluginBuilder\PluginSandbox;
use SdAiAgent\Abilities\PostAbilities;
use SdAiAgent\Core\Features;
use SdAiAgent\Abilities\SeoAbilities;
use SdAiAgent\Abilities\SiteHealthAbilities;
use SdAiAgent\Abilities\SkillAbilities;
use SdAiAgent\Abilities\ThemeBuilderAbilities;
use SdAiAgent\Abilities\UserAbilities;
use SdAiAgent\Abilities\WordPressAbilities;
use SdAiAgent\Abilities\WpCliAbilities;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers all ability groups on `wp_abilities_api_init` and wires
 * the `init`-time hooks that were previously inside the per-class
 * `register()` stubs.
 *
 * Uses `INIT_IMMEDIATELY` so all callbacks are queued during
 * `plugins_loaded` — well before `init` or `wp_abilities_api_init` fires.
 */
#[Handler(
	container: 'sd-ai-agent',
	strategy: Handler::INIT_JUST_IN_TIME,
)]
final class AbilitiesHandler {

	/**
	 * Register all ability groups.
	 *
	 * Called on `wp_abilities_api_init` which fires during `init`.
	 * Each class's `register_abilities()` method calls
	 * `wp_register_ability()` for its individual abilities.
	 */
	#[Action( tag: 'wp_abilities_api_init', priority: 10 )]
	public function register_all_abilities(): void {
		MemoryAbilities::register_abilities();
		FeedbackAbilities::register_abilities();
		SkillAbilities::register_abilities();
		KnowledgeAbilities::register_abilities();
		ImageAbilities\StockImageAbility::register();
		AiImageAbilities::register_abilities();
		InternetSearchAbilities::register_abilities();
		wp_register_ability(
			'sd-ai-agent/site-scrape',
			[
				'label'         => __( 'Scrape Existing Site', 'superdav-ai-agent' ),
				'description'   => __( 'Fetch and parse an existing website to extract structured brand and contact data (name, tagline, logo, address, phone, email, opening hours, social links). Use this at the start of a Theme Builder session when the user has an existing site they are rebuilding.', 'superdav-ai-agent' ),
				'ability_class' => SiteScrapeAbility::class,
			]
		);
		SeoAbilities::register_abilities();
		GscAbilities::register_abilities();
		ContentAbilities::register_abilities();
		MarketingAbilities::register_abilities();
		GoogleAnalyticsAbilities::register_abilities();
		BlockAbilities::register_abilities();
		GlobalStylesAbilities::register_abilities();
		FileAbilities::register_abilities();
		ThemeBuilderAbilities::register_abilities();
		GitAbilities::register_abilities();
		// Plugin-download abilities expose download URLs for AI-modified
		// plugins, so they only make sense when the plugin builder is
		// available. Gated under the same flag, and the class_exists()
		// guard handles the case where bin/build.sh --target=wporg has
		// also stripped the source file.
		if (
			Features::is_enabled( Features::PLUGIN_BUILDER )
			&& class_exists( PluginDownloadAbilities::class )
		) {
			PluginDownloadAbilities::register_abilities();
		}
		// Plugin builder abilities are gated behind a feature flag so the
		// WordPress.org distribution build can disable arbitrary PHP code
		// generation/execution. The class_exists() guard handles the case
		// where the source files are also stripped from the zip — see
		// bin/build.sh --target=wporg.
		if (
			Features::is_enabled( Features::PLUGIN_BUILDER )
			&& class_exists( PluginBuilderAbilities::class )
		) {
			PluginBuilderAbilities::register_abilities();
		}
		DatabaseAbilities::register_abilities();
		WordPressAbilities::register_abilities();
		WpCliAbilities::register_ability();
		OptionsAbilities::register_abilities();
		// WooCommerce abilities are now registered by WooCommerceIntegrationHandler
		// via WooCommerce's own AbilitiesRestBridge, making WooCommerce's native
		// woocommerce/products-* and woocommerce/orders-* abilities available to the
		// WP AI Client SDK without maintaining a duplicate implementation here.
		SiteHealthAbilities::register_abilities();
		NavigationAbilities::register_abilities();
		MenuAbilities::register_abilities();
		PostAbilities::register_abilities();
		CustomPostTypeAbilities::register_abilities();
		CustomTaxonomyAbilities::register_abilities();
		UserAbilities::register_abilities();
		MediaAbilities::register_abilities();
		EditorialAbilities::register_abilities();
		ImageAbilities::register_abilities();
		DesignSystemAbilities::register_abilities();
	}

	/**
	 * Register the WP-CLI ability category.
	 *
	 * WpCliAbilities uses a separate hook (`wp_abilities_api_categories_init`)
	 * for its category registration, unlike the other ability classes.
	 */
	#[Action( tag: 'wp_abilities_api_categories_init', priority: 10 )]
	public function register_wpcli_category(): void {
		WpCliAbilities::register_category();
	}

	/**
	 * Re-register persisted custom post types and taxonomies on `init`.
	 *
	 * Runs at priority 5 (before most plugins) so AI-created CPTs and
	 * taxonomies are available to the rest of WordPress on every request.
	 * Previously wired by CustomPostTypeAbilities::register() and
	 * CustomTaxonomyAbilities::register() via add_action() — those
	 * register() stubs have been removed.
	 */
	#[Action( tag: 'init', priority: 5 )]
	public function restore_persisted_types(): void {
		CustomPostTypeAbilities::restore_persisted_post_types();
		CustomTaxonomyAbilities::restore_persisted_taxonomies();
	}

	/**
	 * Auto-deactivate plugins that triggered a fatal error on a previous activation.
	 *
	 * Previously wired by PluginBuilderAbilities::register() via add_action() —
	 * that register() stub has been removed.
	 *
	 * Skipped when the plugin-builder feature is disabled (the safety net only
	 * matters when this plugin is actively installing plugins it generated)
	 * or when the PluginSandbox class has been stripped from the build.
	 */
	#[Action( tag: 'init', priority: 10 )]
	public function auto_deactivate_fatal_plugins(): void {
		if (
			! Features::is_enabled( Features::PLUGIN_BUILDER )
			|| ! class_exists( PluginSandbox::class )
		) {
			return;
		}

		PluginSandbox::auto_deactivate_fatal_plugins();
	}
}
