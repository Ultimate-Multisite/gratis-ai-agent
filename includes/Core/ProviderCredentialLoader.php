<?php

declare(strict_types=1);
/**
 * Loads AI provider credentials into the SDK registry.
 *
 * Extracted from AgentLoop::ensure_provider_credentials_static() so the
 * credential-loading concern lives in one focused class.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

class ProviderCredentialLoader {

	/**
	 * Check whether at least one provider in the WP AI Client SDK registry
	 * has authentication configured.
	 *
	 * Idempotently calls {@see load()} first so callers don't have to.
	 * Returns false when the SDK is unavailable (WP < 7.0 without polyfill,
	 * registry boot failure, etc.) — treated as "no provider" for alerting.
	 *
	 * Use this in lieu of walking option keys directly: the SDK registry is
	 * the single source of truth for which providers exist and which have
	 * usable credentials.
	 *
	 * @return bool
	 */
	public static function has_any_authenticated_provider(): bool {
		if ( ! class_exists( '\\WordPress\\AiClient\\AiClient' ) ) {
			return false;
		}

		try {
			self::load();
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
	 * Ensure AI provider credentials are loaded from the database.
	 *
	 * In loopback/background requests the AI Experiments plugin's init
	 * chain may not fully pass credentials to the registry. This method
	 * reads the stored credentials option and sets auth on any provider
	 * that doesn't already have it configured.
	 */
	public static function load(): void {
		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
		} catch ( \Throwable $e ) {
			return;
		}

		$auth_class = '\\WordPress\\AiClient\\Providers\\Http\\DTO\\ApiKeyRequestAuthentication';

		if ( ! class_exists( $auth_class ) ) {
			return;
		}

		// Source 1: WordPress 7.0 Connectors API (connectors_ai_*_api_key options).
		if ( function_exists( '_wp_connectors_get_provider_settings' ) ) {
			foreach ( _wp_connectors_get_provider_settings() as $setting_name => $config ) {
				$api_key = _wp_connectors_get_real_api_key( $setting_name, $config['mask'] );

				if ( '' === $api_key || ! $registry->hasProvider( $config['provider'] ) ) {
					continue;
				}

				$registry->setProviderRequestAuthentication(
					$config['provider'],
					new $auth_class( $api_key )
				);
			}
		}

		// Source 2: AI Experiments plugin credentials option.
		$credentials = CredentialResolver::getAiExperimentsCredentials();

		if ( ! empty( $credentials ) ) {
			foreach ( $credentials as $provider_id => $api_key ) {
				if ( ! is_string( $api_key ) || '' === $api_key ) {
					continue;
				}

				if ( ! $registry->hasProvider( $provider_id ) ) {
					continue;
				}

				$registry->setProviderRequestAuthentication(
					$provider_id,
					new $auth_class( $api_key )
				);
			}
		}
	}
}
