<?php

declare(strict_types=1);
/**
 * AI Client provider registry helpers.
 *
 * Provider credentials are owned by WordPress core's Connectors API and the
 * public AI Client SDK registry. This class intentionally does not read
 * connector option values or construct request-authentication objects from raw
 * keys; it exists only as a small compatibility facade for older call sites
 * that used to ask the plugin to "load" credentials before inspecting the SDK
 * registry.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

class ProviderCredentialLoader {

	/**
	 * Check whether at least one provider in the WP AI Client SDK registry has
	 * authentication configured by the public SDK/Core Connectors bootstrap.
	 *
	 * @return bool
	 */
	public static function has_any_authenticated_provider(): bool {
		if ( ! class_exists( '\\WordPress\\AiClient\\AiClient' ) ) {
			return false;
		}

		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			foreach ( $registry->getRegisteredProviderIds() as $provider_id ) {
				if ( null !== $registry->getProviderRequestAuthentication( $provider_id ) ) {
					return true;
				}
			}
		} catch ( \Throwable $e ) {
			return false;
		}

		return false;
	}

	/**
	 * Compatibility facade for older call sites.
	 *
	 * Credentials are populated by WordPress core's Connectors API during normal
	 * bootstrap. Do not read `connectors_ai_*` options or legacy credential stores
	 * here; callers should inspect/use the public AI Client SDK registry directly.
	 */
	public static function load(): void {
		// Intentionally no-op.
	}
}
