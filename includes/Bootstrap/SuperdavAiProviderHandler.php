<?php

declare(strict_types=1);

namespace SdAiAgent\Bootstrap;

use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiProvider;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the first-party Superdav AI provider with the SDK registry.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_GLOBAL,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class SuperdavAiProviderHandler {

	/**
	 * Register the provider if the WordPress AI Client SDK is available.
	 */
	#[Action( tag: 'plugins_loaded', priority: 2 )]
	public function register_provider(): void {
		if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			return;
		}

		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			if ( ! $registry->hasProvider( SuperdavAiProvider::PROVIDER_ID ) ) {
				$registry->registerProvider( SuperdavAiProvider::class );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}
}
