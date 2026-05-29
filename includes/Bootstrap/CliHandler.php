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
use SdAiAgent\CLI\ModelsCommand;
use SdAiAgent\CLI\SkillsCommand;
use SdAiAgent\CLI\TraceCommand;
use SdAiAgent\Core\Features;
use SdAiAgent\Models\ProviderTrace;
use WP_CLI;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's WP-CLI subcommands under both the canonical
 * `ai-agent` namespace and the legacy `sd-ai-agent` alias.
 *
 * Uses the `#[Handler(context: CTX_CLI)]` guard so the container skips
 * loading this class outside of WP-CLI requests. Each subcommand class
 * (`CliCommand`, `TraceCommand`, `BenchmarkCommand`) remains a plain
 * `WP_CLI_Command` subclass — we are not yet migrating them to the
 * `#[CLI_Handler]` / `#[CLI_Command]` decorators, which would require
 * deeper restructuring of their docblock-driven subcommand APIs.
 *
 * PR 5 of the DI refactor will migrate the CLI command classes themselves
 * into attribute-driven handlers; this PR simply moves the registration
 * wiring out of the plugin root file.
 *
 * Note: the `benchmark` subcommand is registered conditionally via
 * `Features::is_enabled( Features::BENCHMARK )` and its FQCN is resolved
 * with a string literal rather than a `use` import — the WordPress.org
 * distribution build physically strips `includes/CLI/BenchmarkCommand.php`
 * and `includes/Benchmark/`, so importing the symbol at the top of this
 * file would point at a missing class in the shipped zip. The feature
 * flag is forced to `false` in the wporg main plugin file by
 * `bin/build.sh --target=wporg`, so the conditional branch never fires
 * there even if the file were re-introduced.
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
	 * @var array<string,class-string>
	 */
	private const COMMANDS = array(
		'prompt' => CliCommand::class,
		'models' => ModelsCommand::class,
		'skills' => SkillsCommand::class,
	);

	/**
	 * Fully-qualified class name of the WP-CLI benchmark command.
	 *
	 * Stored as a string literal (not a `::class` constant on the
	 * imported symbol) because the WordPress.org distribution build
	 * strips the source file. See the class-level docblock.
	 */
	private const BENCHMARK_COMMAND_CLASS = 'SdAiAgent\\CLI\\BenchmarkCommand';

	/**
	 * Commands only registered when WP_DEBUG is active.
	 *
	 * @var array<string,class-string>
	 */
	private const DEBUG_COMMANDS = array(
		'trace' => TraceCommand::class,
	);

	/**
	 * Primary and alias root namespaces under which every subcommand is exposed.
	 *
	 * @var list<string>
	 */
	private const NAMESPACES = array( 'ai-agent', 'superdav-ai-agent', 'sd-ai-agent' );

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

		// Benchmark command is gated on a feature flag *and* on the source
		// file being present. The wporg build forces the flag to false AND
		// physically removes the file; the class_exists() check is the
		// belt-and-braces guard for the full build's flag-only override.
		if (
			Features::is_enabled( Features::BENCHMARK )
			&& class_exists( self::BENCHMARK_COMMAND_CLASS )
		) {
			$commands['benchmark'] = self::BENCHMARK_COMMAND_CLASS;
		}

		if ( ProviderTrace::is_debug_mode() ) {
			$commands = array_merge( $commands, self::DEBUG_COMMANDS );
		}

		foreach ( self::NAMESPACES as $ns ) {
			foreach ( $commands as $sub => $class ) {
				WP_CLI::add_command( "{$ns} {$sub}", $class );
			}
		}
	}
}
