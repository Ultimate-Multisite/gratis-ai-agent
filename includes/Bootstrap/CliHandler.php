<?php
/**
 * Handler: register WP-CLI subcommands for the plugin.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Bootstrap;

use SdAiAgent\CLI\CliCommand;
use SdAiAgent\CLI\KnowledgeCommand;
use SdAiAgent\CLI\ModelsCommand;
use SdAiAgent\CLI\SkillsCommand;
use SdAiAgent\CLI\TraceCommand;
use SdAiAgent\Models\ProviderTrace;
use WP_CLI;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's WP-CLI subcommands under the canonical
 * `sd-ai-agent` namespace.
 *
 * Uses the `#[Handler(context: CTX_CLI)]` guard so the container skips
 * loading this class outside of WP-CLI requests. Each subcommand class
 * (`CliCommand`, `KnowledgeCommand`, `ModelsCommand`, `SkillsCommand`,
 * `TraceCommand`) remains a plain
 * `WP_CLI_Command` subclass — we are not yet migrating them to the
 * `#[CLI_Handler]` / `#[CLI_Command]` decorators, which would require
 * deeper restructuring of their docblock-driven subcommand APIs.
 *
 * PR 5 of the DI refactor will migrate the CLI command classes themselves
 * into attribute-driven handlers; this PR simply moves the registration
 * wiring out of the plugin root file.
 *
 * Advanced-only CLI commands such as the benchmark runner are registered by
 * the Superdav AI Agent Advanced companion plugin, not by the core CLI
 * handler that ships to WordPress.org.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_CLI,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class CliHandler {

	/**
	 * Commands always registered regardless of WP_DEBUG.
	 *
	 * @var array<string,array{class:class-string,shortdesc:string}>
	 */
	private const COMMANDS = array(
		'prompt'    => array(
			'class'     => CliCommand::class,
			'shortdesc' => 'Send a prompt to the configured WordPress AI agent and optionally let it use registered abilities/tools.',
		),
		'knowledge' => array(
			'class'     => KnowledgeCommand::class,
			'shortdesc' => 'Import and maintain AI knowledge-base sources used for retrieval and documentation grounding.',
		),
		'models'    => array(
			'class'     => ModelsCommand::class,
			'shortdesc' => 'List configured AI providers and their available models in table, JSON, CSV, YAML, or IDs format.',
		),
		'skills'    => array(
			'class'     => SkillsCommand::class,
			'shortdesc' => 'Discover, inspect, and manage AI agent skills available to the plugin.',
		),
	);

	/**
	 * Commands only registered when WP_DEBUG is active.
	 *
	 * @var array<string,array{class:class-string,shortdesc:string}>
	 */
	private const DEBUG_COMMANDS = array(
		'trace' => array(
			'class'     => TraceCommand::class,
			'shortdesc' => 'Inspect debug traces for AI provider requests, responses, tokens, costs, and related session activity.',
		),
	);

	/**
	 * Canonical root namespace under which every subcommand is exposed.
	 *
	 * @var list<string>
	 */
	private const NAMESPACES = array( 'sd-ai-agent' );

	/**
	 * Register every subcommand with WP-CLI.
	 *
	 * Hooked on `cli_init` — guaranteed to fire only when WP-CLI is active,
	 * which removes the need for the legacy `defined('WP_CLI')` guard that
	 * used to live in the plugin bootstrap file.
	 *
	 * Debug-only commands (e.g. `trace`) are only registered when WP_DEBUG
	 * is defined and truthy, matching the REST and UI availability gates.
	 */
	#[Action( tag: 'cli_init', priority: 10 )]
	public function register_commands(): void {
		$commands = self::COMMANDS;

		if ( ProviderTrace::is_debug_mode() ) {
			$commands = array_merge( $commands, self::DEBUG_COMMANDS );
		}

		foreach ( self::NAMESPACES as $ns ) {
			foreach ( $commands as $sub => $command ) {
				WP_CLI::add_command(
					"{$ns} {$sub}",
					$command['class'],
					array( 'shortdesc' => $command['shortdesc'] )
				);
			}
		}
	}
}
