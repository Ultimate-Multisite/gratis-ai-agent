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
	 *
	 * The SDK may be loaded by another plugin during `plugins_loaded`, so register
	 * on early `init` after all `plugins_loaded` callbacks have had a chance to
	 * expose their SDK classes and before the default connector registry runs.
	 */
	#[Action( tag: 'init', priority: 5 )]
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
