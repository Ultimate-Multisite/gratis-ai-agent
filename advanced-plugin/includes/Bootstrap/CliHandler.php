<?php

declare(strict_types=1);

namespace SdAiAgentAdvanced\Bootstrap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_CLI;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

/**
 * Registers advanced WP-CLI subcommands supplied by the companion plugin.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_CLI,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class CliHandler {

	/**
	 * Primary and alias root namespaces under which advanced subcommands are exposed.
	 *
	 * @var list<string>
	 */
	private const NAMESPACES = array( 'ai-agent', 'superdav-ai-agent', 'sd-ai-agent' );

	/**
	 * Register advanced WP-CLI commands.
	 */
	#[Action( tag: 'cli_init', priority: 20 )]
	public function register_commands(): void {
		foreach ( self::NAMESPACES as $namespace ) {
			WP_CLI::add_command( "{$namespace} benchmark", 'SdAiAgent\\CLI\\BenchmarkCommand' );
		}
	}
}
