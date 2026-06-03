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
use SdAiAgent\Abilities\KnowledgeAbilities;
use SdAiAgent\Abilities\ListAllowedRootsAbility;
use SdAiAgent\Abilities\SiteScrapeAbility;
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
use SdAiAgent\Abilities\UrlResolverAbilities;
use SdAiAgent\Abilities\UploadMediaAbility;
use SdAiAgent\Abilities\UserAbilities;
use SdAiAgent\Abilities\UserManagementAbilities;
use SdAiAgent\Abilities\WordPressAbilities;
use SdAiAgent\Abilities\WpCliAbilities;
use SdAiAgent\Abilities\WpRestAbilities;
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
		// Git tracking is paired with the file-mutation surface. The WP.org build
		// forces FILE_WRITE off and strips the Git ability/model source files, so
		// guard both the feature flag and class availability before registration.
		if (
			Features::is_enabled( Features::FILE_WRITE )
			&& class_exists( GitAbilities::class )
		) {
			GitAbilities::register_abilities();
		}
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
		// The WP.org build strips DatabaseAbilities.php to avoid recurring
		// Plugin Check direct-SQL findings on the dynamic SELECT diagnostics
		// ability. Check the physical source path before class_exists() because
		// Jetpack Autoloader can hard-require optimized classmap paths for files
		// that existed before the WP.org strip step.
		$database_abilities_file = dirname( __DIR__ ) . '/Abilities/DatabaseAbilities.php';
		if ( file_exists( $database_abilities_file ) && class_exists( DatabaseAbilities::class ) ) {
			DatabaseAbilities::register_abilities();
		}
		WordPressAbilities::register_abilities();
		// WP-CLI dispatcher: gated by SD_AI_AGENT_FEATURE_WP_CLI_DISPATCHER
		// and class_exists() guarded so the WordPress.org build (which
		// strips includes/Abilities/WpCliAbilities.php via
		// .distignore-wporg + bin/build.sh stripped_paths) does not
		// fatal here.
		if (
			Features::is_enabled( Features::WP_CLI_DISPATCHER )
			&& class_exists( WpCliAbilities::class )
		) {
			WpCliAbilities::register_ability();
		}
		// WP REST dispatcher: gated by SD_AI_AGENT_FEATURE_WP_REST_DISPATCHER
		// and class_exists() guarded for the same wp.org-strip reason.
		if (
			Features::is_enabled( Features::WP_REST_DISPATCHER )
			&& class_exists( WpRestAbilities::class )
		) {
			WpRestAbilities::register_abilities();
		}
		OptionsAbilities::register_abilities();
		// WooCommerce abilities are now registered by WooCommerceIntegrationHandler
		// via WooCommerce's own AbilitiesRestBridge, making WooCommerce's native
		// woocommerce/products-* and woocommerce/orders-* abilities available to the
		// WP AI Client SDK without maintaining a duplicate implementation here.
		SiteHealthAbilities::register_abilities();
		NavigationAbilities::register_abilities();
		MenuAbilities::register_abilities();
		PostAbilities::register_abilities();
		UrlResolverAbilities::register_abilities();
		CustomPostTypeAbilities::register_abilities();
		CustomTaxonomyAbilities::register_abilities();
		TaxonomyAbilities::register_abilities();
		UserAbilities::register_abilities();
		// Mutating user-management abilities (create-user, update-user-role)
		// are gated behind a feature flag so the WordPress.org distribution
		// build can disable the custom user-creation surface entirely (which
		// can bypass security plugins that hook the native register/login
		// flow). The class_exists() guard handles the case where the source
		// file has also been stripped from the zip — see bin/build.sh
		// --target=wporg and .distignore-wporg.
		if (
			Features::is_enabled( Features::USER_MANAGEMENT )
			&& class_exists( UserManagementAbilities::class )
		) {
			UserManagementAbilities::register_abilities();
		}
		MediaAbilities::register_abilities();
		UploadMediaAbility::register_abilities();
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
		// Both categories are feature-gated AND class_exists() guarded
		// so the WordPress.org build (which strips the source files)
		// does not fatal here.
		if (
			Features::is_enabled( Features::WP_CLI_DISPATCHER )
			&& class_exists( WpCliAbilities::class )
		) {
			WpCliAbilities::register_category();
		}
		if (
			Features::is_enabled( Features::WP_REST_DISPATCHER )
			&& class_exists( WpRestAbilities::class )
		) {
			WpRestAbilities::register_category();
		}
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
