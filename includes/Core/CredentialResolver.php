<?php

declare(strict_types=1);
/**
 * Credential resolution for AI provider API keys and connection settings.
 *
 * Centralises all reads and writes of credential-related WordPress options so
 * that the rest of the plugin never calls get_option() / update_option()
 * directly for secrets.  Handles:
 *
 *  - AI Experiments plugin provider credentials array
 *  - WordPress 7.0 Connectors API (read-only, delegated to WP functions)
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CredentialResolver {

	// ── Option names ──────────────────────────────────────────────────────────

	/**
	 * WordPress option that stores the AI Experiments plugin provider credentials.
	 * Shape: array<string, string>  (provider_id => api_key)
	 *
	 * NOTE: This uses the WordPress AI Client SDK standard option name.
	 * WordPress expects this exact name to discover credentials.
	 * See: https://developer.wordpress.org/plugins/wordpress-api/ai-client-sdk/
	 */
	const AI_EXPERIMENTS_CREDENTIALS_OPTION = 'wp_ai_client_provider_credentials';

	/**
	 * Sentinel value used when no real API key is available but a non-empty
	 * string is required by the HTTP client.
	 */
	const NO_KEY_SENTINEL = 'no-key';

	// ── AI Experiments plugin credentials ─────────────────────────────────────

	/**
	 * Return the full provider-credentials array stored by the AI Experiments plugin.
	 *
	 * @return array<string, string>  Keys are provider IDs, values are API keys.
	 */
	public static function getAiExperimentsCredentials(): array {
		$raw = get_option( self::AI_EXPERIMENTS_CREDENTIALS_OPTION, [] );
		// @phpstan-ignore-next-line
		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * Return the API key for a specific provider from the AI Experiments store.
	 *
	 * @param string $provider_id The provider ID (e.g. 'openai', 'anthropic', 'google').
	 * @return string Empty string when not configured.
	 */
	public static function getAiExperimentsApiKey( string $provider_id ): string {
		$credentials = self::getAiExperimentsCredentials();
		$key         = $credentials[ $provider_id ] ?? '';
		return is_string( $key ) ? $key : '';
	}

	/**
	 * Persist an API key for a specific provider in the AI Experiments store.
	 *
	 * Pass an empty string to remove the entry for that provider.
	 *
	 * @param string $provider_id The provider ID.
	 * @param string $api_key     The API key value.
	 * @return bool True on success.
	 */
	public static function setAiExperimentsApiKey( string $provider_id, string $api_key ): bool {
		$credentials = self::getAiExperimentsCredentials();

		if ( '' === $api_key ) {
			unset( $credentials[ $provider_id ] );
		} else {
			$credentials[ $provider_id ] = $api_key;
		}

		return (bool) update_option( self::AI_EXPERIMENTS_CREDENTIALS_OPTION, $credentials );
	}

	// ── Validation helpers ────────────────────────────────────────────────────

	/**
	 * Return true when the given API key is a real key (not empty and not the
	 * sentinel placeholder).
	 *
	 * @param string $api_key The key to test.
	 * @return bool
	 */
	public static function isValidApiKey( string $api_key ): bool {
		return '' !== $api_key && self::NO_KEY_SENTINEL !== $api_key;
	}
}
