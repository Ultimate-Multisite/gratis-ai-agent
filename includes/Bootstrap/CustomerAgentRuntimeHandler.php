<?php

declare(strict_types=1);
/**
 * DI wiring for the durable customer-agent runtime worker.
 *
 * @package SdAiAgent\Bootstrap
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Bootstrap;

use SdAiAgent\Core\Database;
use SdAiAgent\Services\CustomerAgentRuntimeService;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Wires the private WP-Cron processing hook in every WordPress context. */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_GLOBAL,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class CustomerAgentRuntimeHandler {

	/** Upgrade stale schemas before frontend, REST, CLI, or cron runtime use. */
	#[Action( tag: 'init', priority: 1 )]
	public function ensure_database_schema(): void {
		Database::install();
	}

	/** Execute one opaque queued customer-agent job. */
	#[Action( tag: CustomerAgentRuntimeService::PROCESS_HOOK, priority: 10 )]
	public function process_job( string $job_id ): void {
		CustomerAgentRuntimeService::instance()->process_job( $job_id );
	}
}
