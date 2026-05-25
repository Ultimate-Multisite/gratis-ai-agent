<?php
/**
 * DI handler for the post-mutation health REST endpoint.
 *
 * @package SdAiAgent\Bootstrap
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Bootstrap;

use SdAiAgent\Core\Health\HealthEndpoint;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the private post-mutation health endpoint on the REST hook.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_REST,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class HealthEndpointHandler {

	/**
	 * Register the post-mutation health endpoint on WordPress's REST hook.
	 */
	#[Action( tag: 'rest_api_init', priority: 10 )]
	public function register_health_endpoint(): void {
		HealthEndpoint::register();
	}
}
