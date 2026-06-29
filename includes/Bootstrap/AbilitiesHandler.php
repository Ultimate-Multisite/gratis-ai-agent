<?php

declare(strict_types=1);

namespace SdAiAgent\Bootstrap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

use SdAiAgent\Abilities\AiImageAbilities;
use SdAiAgent\Abilities\BlockAbilities;
use SdAiAgent\Core\BlockEnricherRegistry;
use SdAiAgent\Enrichers\CoreImageEnricher;
use SdAiAgent\Abilities\ContentAbilities;
use SdAiAgent\Abilities\CustomPostTypeAbilities;
use SdAiAgent\Abilities\CustomTaxonomyAbilities;
use SdAiAgent\Abilities\TaxonomyAbilities;
use SdAiAgent\Abilities\DesignSystemAbilities;
use SdAiAgent\Abilities\EditorialAbilities;
use SdAiAgent\Abilities\FeedbackAbilities;
use SdAiAgent\Abilities\FileAbilities;
use SdAiAgent\Abilities\GlobalStylesAbilities;
use SdAiAgent\Abilities\GoogleAnalyticsAbilities;
use SdAiAgent\Abilities\GscAbilities;
use SdAiAgent\Abilities\ImageAbilities;
use SdAiAgent\Abilities\InternetSearchAbilities;
use SdAiAgent\Abilities\KnowledgeAbilities;
use SdAiAgent\Abilities\ListAllowedRootsAbility;
use SdAiAgent\Abilities\SiteScrapeAbility;
use SdAiAgent\Abilities\MarketingAbilities;
use SdAiAgent\Abilities\MediaAbilities;
use SdAiAgent\Abilities\MemoryAbilities;
use SdAiAgent\Abilities\MenuAbilities;
use SdAiAgent\Abilities\NavigationAbilities;
use SdAiAgent\Abilities\OptionsAbilities;
use SdAiAgent\Abilities\PostAbilities;
use SdAiAgent\Abilities\RestMetaAbilities;
use SdAiAgent\Abilities\SeoAbilities;
use SdAiAgent\Abilities\SiteHealthAbilities;
use SdAiAgent\Abilities\SkillAbilities;
use SdAiAgent\Abilities\SmsAbilities;
use SdAiAgent\Abilities\ThemeBuilderAbilities;
use SdAiAgent\Abilities\UrlResolverAbilities;
use SdAiAgent\Abilities\UploadMediaAbility;
use SdAiAgent\Abilities\UserAbilities;
use SdAiAgent\Abilities\WordPressAbilities;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

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
		SmsAbilities::register_abilities();
		// Wire the block enricher registry before registering block
		// abilities so handle_get_page_blocks can use it. The registry
		// is created once (singleton per request), the core/image
		// enricher is registered by default, and the third-party
		// `sd_ai_agent_register_block_enrichers` action fires lazily
		// on the first get-page-blocks call.
		$enricher_registry = new BlockEnricherRegistry();
		$enricher_registry->register( new CoreImageEnricher() );
		BlockAbilities::set_enricher_registry( $enricher_registry );

		BlockAbilities::register_abilities();
		GlobalStylesAbilities::register_abilities();
		FileAbilities::register_abilities();
		wp_register_ability(
			'sd-ai-agent/list-allowed-roots',
			[
				'label'         => __( 'List Allowed Roots', 'superdav-ai-agent' ),
				'description'   => __( 'Returns the list of filesystem directories where the AI is permitted to read or write. Call this before any file write operation to pick a valid target path without trial-and-error.', 'superdav-ai-agent' ),
				'ability_class' => ListAllowedRootsAbility::class,
			]
		);
		ThemeBuilderAbilities::register_abilities();
		// Advanced-only filesystem mutation, git tracking, plugin builder,
		// low-level dispatchers, raw database diagnostics, and mutating
		// user/plugin-management abilities are registered by the companion
		// Superdav AI Agent Advanced plugin.
		WordPressAbilities::register_abilities();
		OptionsAbilities::register_abilities();
		// WooCommerce abilities are now registered by WooCommerceIntegrationHandler
		// via WooCommerce's own AbilitiesRestBridge, making WooCommerce's native
		// woocommerce/products-* and woocommerce/orders-* abilities available to the
		// WP AI Client SDK without maintaining a duplicate implementation here.
		SiteHealthAbilities::register_abilities();
		NavigationAbilities::register_abilities();
		MenuAbilities::register_abilities();
		PostAbilities::register_abilities();
		RestMetaAbilities::register_abilities();
		UrlResolverAbilities::register_abilities();
		CustomPostTypeAbilities::register_abilities();
		CustomTaxonomyAbilities::register_abilities();
		TaxonomyAbilities::register_abilities();
		UserAbilities::register_abilities();
		MediaAbilities::register_abilities();
		UploadMediaAbility::register_abilities();
		EditorialAbilities::register_abilities();
		ImageAbilities::register_abilities();
		DesignSystemAbilities::register_abilities();
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
}
