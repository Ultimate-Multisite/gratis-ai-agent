<?php

declare(strict_types=1);

namespace SdAiAgentAdvanced;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SdAiAgent\Bootstrap\GitTrackingHandler;
use SdAiAgentAdvanced\Bootstrap\AbilitiesHandler;
use SdAiAgentAdvanced\Bootstrap\CliHandler;
use SdAiAgentAdvanced\Bootstrap\CustomToolCliHandler;
use SdAiAgentAdvanced\REST\PluginDownloadController;
use XWP\DI\Decorators\Module;

/**
 * Advanced companion module imported into the core sd-ai-agent container.
 */
#[Module(
	container: 'sd-ai-agent',
	hook: 'plugins_loaded',
	priority: 1,
	imports: array(),
	handlers: array(
		AbilitiesHandler::class,
		CliHandler::class,
		CustomToolCliHandler::class,
		GitTrackingHandler::class,
		PluginDownloadController::class,
	),
)]
final class Plugin {

	/**
	 * Container definitions exposed by the advanced module.
	 *
	 * @return array<string,mixed>
	 */
	public static function configure(): array {
		return array(
			'advanced_plugin.version' => \DI\factory( static fn(): string => defined( 'SD_AI_AGENT_ADVANCED_VERSION' ) ? (string) constant( 'SD_AI_AGENT_ADVANCED_VERSION' ) : '' ),
			'advanced_plugin.dir'     => \DI\factory( static fn(): string => defined( 'SD_AI_AGENT_ADVANCED_DIR' ) ? (string) constant( 'SD_AI_AGENT_ADVANCED_DIR' ) : '' ),
			'advanced_plugin.url'     => \DI\factory( static fn(): string => defined( 'SD_AI_AGENT_ADVANCED_URL' ) ? (string) constant( 'SD_AI_AGENT_ADVANCED_URL' ) : '' ),
		);
	}
}
