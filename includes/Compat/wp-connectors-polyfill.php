<?php

/**
 * WP 6.9 polyfill: Connectors API functions.
 *
 * Provides polyfills for private Connectors API helper functions used by
 * ProviderCredentialLoader. Every definition is guarded by function_exists()
 * so that on WP 7.0+ core implementations win.
 *
 * The option naming convention (`connectors_ai_{provider}_api_key`) matches
 * WP 7.0's Connectors API exactly. Credentials entered via our Connectors
 * admin page on WP 6.9 will therefore work without migration on WP 7.0.
 *
 * @package SdAiAgent\Compat
 * @license GPL-2.0-or-later
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Polyfill: _wp_connectors_get_provider_settings()
 *
 * Returns an array of AI provider settings keyed by option name.
 * Matches the data shape expected by ProviderCredentialLoader.
 *
 * On WP 7.0+ this function is provided by wp-includes/connectors.php
 * and loaded before plugins, so the function_exists() guard prevents
 * double-definition.
 *
 * @since 1.8.0 (polyfill)
 *
 * @return array<string, array{provider: string, mask: string}> Provider settings keyed by option name.
 */
if ( ! function_exists( '_wp_connectors_get_provider_settings' ) ) {
	function _wp_connectors_get_provider_settings(): array {
		// Known AI providers and their option naming convention.
		// The setting name format is: connectors_ai_{sanitized_provider_id}_api_key
		// This matches the WP 7.0 Connectors API exactly.
		$providers = [
			'sd-ai-agent-cloud' => 'sd_ai_agent_cloud',
			'anthropic'         => 'anthropic',
			'openai'            => 'openai',
			'google'            => 'google',
		];

		/**
		 * Filters the list of AI providers for the Connectors polyfill.
		 *
		 * Plugin-registered AI providers that follow the connectors_ai_*_api_key
		 * option naming convention can add themselves here.
		 *
		 * @since 1.8.0
		 *
		 * @param array<string, string> $providers Map of provider_id => sanitized_id.
		 */
		$providers = (array) apply_filters( 'sd_ai_agent_connector_providers', $providers );

		$settings = [];
		foreach ( $providers as $provider_id => $sanitized_id ) {
			$sanitized_id = str_replace( '-', '_', (string) $sanitized_id );
			$setting_name = "connectors_ai_{$sanitized_id}_api_key";
			$api_key      = (string) get_option( $setting_name, '' );

			if ( '' === $api_key ) {
				continue;
			}

			$settings[ $setting_name ] = [
				'provider' => (string) $provider_id,
				'mask'     => sd_ai_agent_connectors_mask_api_key( $api_key ),
			];
		}

		return $settings;
	}
}

/**
 * Polyfill: _wp_connectors_get_real_api_key()
 *
 * Returns the real (unmasked) API key for a given connector setting.
 * The $mask parameter is informational only.
 *
 * On WP 7.0+ this function is provided by core and loaded before plugins.
 *
 * @since 1.8.0 (polyfill)
 *
 * @param string $setting_name Option name (e.g. 'connectors_ai_anthropic_api_key').
 * @param string $mask         Masked version of the key (not used to retrieve the real key).
 * @return string The real API key, or empty string if not found.
 */
if ( ! function_exists( '_wp_connectors_get_real_api_key' ) ) {
	function _wp_connectors_get_real_api_key( string $setting_name, string $mask ): string {
		return (string) get_option( $setting_name, '' );
	}
}

/**
 * Internal helper: mask an API key, showing only the last 4 characters.
 *
 * Avoids depending on WP 7.0's _wp_connectors_mask_api_key() which may
 * not be available on 6.9. Carries the plugin's `sd_ai_agent_` prefix so
 * it does not appear to masquerade as a WordPress core private helper
 * (WP.org Plugin Review prefix guideline).
 *
 * Guarded by function_exists() defensively: if a future WordPress version
 * happens to ship a same-named function in the global namespace, ours
 * yields to core.
 *
 * @since 1.8.0
 * @access private
 *
 * @param string $key The API key to mask.
 * @return string Masked key (e.g. "••••••••••••fj39").
 */
if ( ! function_exists( 'sd_ai_agent_connectors_mask_api_key' ) ) {
	function sd_ai_agent_connectors_mask_api_key( string $key ): string {
		if ( strlen( $key ) <= 4 ) {
			return $key;
		}
		return str_repeat( "\u{2022}", min( strlen( $key ) - 4, 16 ) ) . substr( $key, -4 );
	}
}
