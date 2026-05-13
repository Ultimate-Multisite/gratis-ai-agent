<?php
/**
 * Plugin entry-point module for the Superdav AI Agent DI container.
 *
 * This class is decorated with `#[Module]` so the `x-wp/di` library discovers
 * it when bootstrapping the container via `xwp_load_app()`.
 *
 * All hook wiring flows through DI-managed handler classes listed in the
 * `#[Module]` handlers array. Each handler uses `#[Action]` / `#[Filter]`
 * attributes to declare its hooks declaratively — no `add_action()` /
 * `add_filter()` calls appear outside of the handler classes themselves.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent;

use SdAiAgent\Bootstrap\AbilitiesHandler;
use SdAiAgent\Bootstrap\AdminHandler;
use SdAiAgent\Bootstrap\WooCommerceIntegrationHandler;
use SdAiAgent\Bootstrap\AutomationsHandler;
use SdAiAgent\Bootstrap\ChangeLoggingHandler;
use SdAiAgent\Bootstrap\CliHandler;
use SdAiAgent\Bootstrap\FrontendAssetsHandler;
use SdAiAgent\Bootstrap\GitTrackingHandler;
use SdAiAgent\Bootstrap\HttpTraceHandler;
use SdAiAgent\Bootstrap\KnowledgeHooksHandler;
use SdAiAgent\Bootstrap\OnboardingHandler;
use SdAiAgent\Bootstrap\ToolDiscoveryHandler;
use SdAiAgent\Contracts\BudgetCheckerInterface;
use SdAiAgent\Contracts\SessionRepositoryInterface;
use SdAiAgent\Contracts\SettingsProviderInterface;
use SdAiAgent\Infrastructure\Adapters\BudgetManagerAdapter;
use SdAiAgent\Infrastructure\Adapters\DatabaseSessionAdapter;
use SdAiAgent\Infrastructure\Adapters\StaticSettingsAdapter;
use SdAiAgent\Infrastructure\AiClient\RequestTimeoutFilter;
use SdAiAgent\Infrastructure\WordPress\Abilities\AbilityCategoryRegistrar;
use SdAiAgent\Infrastructure\WordPress\Abilities\AbilitySchemaFilter;
use SdAiAgent\Infrastructure\WordPress\Abilities\UsageInstructionsFilter;
use SdAiAgent\REST\AgentController;
use SdAiAgent\REST\AutomationController;
use SdAiAgent\REST\BannerController;
use SdAiAgent\REST\ConnectorsController;
use SdAiAgent\REST\ChangesController;
use SdAiAgent\REST\FeedbackController;
use SdAiAgent\REST\KnowledgeController;
use SdAiAgent\REST\McpController;
use SdAiAgent\REST\MemoryController;
use SdAiAgent\REST\RestController;
use SdAiAgent\REST\SessionController;
use SdAiAgent\REST\SettingsController;
use SdAiAgent\REST\SkillController;
use SdAiAgent\REST\ToolController;
use SdAiAgent\REST\TraceController;
use SdAiAgent\REST\WebhookController;
use XWP\DI\Decorators\Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Root application module for the DI container.
 *
 * The `#[Module]` attribute is consumed by `x-wp/di` when the plugin calls
 * {@see xwp_load_app()} in `sd-ai-agent.php`. The container ID
 * `sd-ai-agent` matches the plugin slug and is the key used by the rest of
 * the codebase to resolve the container through {@see xwp_app()}.
 *
 * `extendable: true` publishes the `xwp_extend_import_sd-ai-agent` filter so
 * companion plugins (e.g. Ultimate Multisite add-ons) can register additional
 * modules against our container without having to patch this class.
 */
#[Module(
	container: 'sd-ai-agent',
	hook: 'plugins_loaded',
	priority: 1,
	imports: array(),
	handlers: array(
		AbilitySchemaFilter::class,
		AbilityCategoryRegistrar::class,
		UsageInstructionsFilter::class,
		RequestTimeoutFilter::class,
		CliHandler::class,
		AbilitiesHandler::class,
		WooCommerceIntegrationHandler::class,
		AdminHandler::class,
		// Core background service handlers (replaced CoreServicesHandler).
		ChangeLoggingHandler::class,
		HttpTraceHandler::class,
		KnowledgeHooksHandler::class,
		ToolDiscoveryHandler::class,
		AutomationsHandler::class,
		GitTrackingHandler::class,
		OnboardingHandler::class,
		FrontendAssetsHandler::class,
		MemoryController::class,
		SkillController::class,
		BannerController::class,
		FeedbackController::class,
		TraceController::class,
		McpController::class,
		RestController::class,
		ToolController::class,
		AgentController::class,
		ChangesController::class,
		AutomationController::class,
		KnowledgeController::class,
		SessionController::class,
		SettingsController::class,
		ConnectorsController::class,
		WebhookController::class,
	),
	extendable: true,
)]
final class Plugin {

	/**
	 * PHP-DI container definitions exposed by the root module.
	 *
	 * Keys are intentionally namespaced under `plugin.*` so later modules can
	 * inject the plugin version / paths via `#[Infuse('plugin.version')]`
	 * without relying on the legacy `SD_AI_AGENT_*` constants.
	 *
	 * @see https://php-di.org/doc/php-definitions.html
	 *
	 * @return array<string, mixed>
	 */
	public static function configure(): array {
		return array(
			// Note: Using factory() instead of value() ensures these resolve at runtime
			// from the constants defined in sd-ai-agent.php, not at compile-time.
			// This allows the compiled container to ship in distributions
			// while still resolving to the correct paths on each installation.
			'plugin.version'                  => \DI\factory( static fn(): string => defined( 'SD_AI_AGENT_VERSION' ) ? (string) constant( 'SD_AI_AGENT_VERSION' ) : '' ),
			'plugin.dir'                      => \DI\factory( static fn(): string => defined( 'SD_AI_AGENT_DIR' ) ? (string) constant( 'SD_AI_AGENT_DIR' ) : '' ),
			'plugin.url'                      => \DI\factory( static fn(): string => defined( 'SD_AI_AGENT_URL' ) ? (string) constant( 'SD_AI_AGENT_URL' ) : '' ),

			// Interface → implementation bindings (t197).
			// These adapters delegate to the existing static classes so all
			// current static call-sites continue to work unchanged.
			// Swap to real implementations once t189 (SessionRepository) and
			// t192 (Settings DI singleton) are complete.
			SessionRepositoryInterface::class => \DI\autowire( DatabaseSessionAdapter::class ),
			SettingsProviderInterface::class  => \DI\autowire( StaticSettingsAdapter::class ),
			BudgetCheckerInterface::class     => \DI\autowire( BudgetManagerAdapter::class ),
		);
	}
}
