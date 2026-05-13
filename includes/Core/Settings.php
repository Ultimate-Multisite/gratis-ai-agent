<?php

declare(strict_types=1);
/**
 * Plugin settings management.
 *
 * Stores all Superdav AI Agent settings in a single WordPress option and provides
 * a React-based settings page under Tools > Superdav AI Agent Settings.
 *
 * This class is designed as an injectable DI service. Use constructor injection
 * to receive a Settings instance in DI-managed handlers. For non-DI code, use
 * the static {@see Settings::instance()} factory as a bridge.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	/**
	 * Option name in the wp_options table.
	 */
	const OPTION_NAME = 'sd_ai_agent_settings';

	/**
	 * Option name for Google Search Console credentials.
	 * Stored separately from general settings to avoid leaking credentials
	 * through the GET /settings endpoint.
	 */
	const GSC_CREDENTIALS_OPTION = 'sd_ai_agent_gsc_credentials';

	/**
	 * Known context window sizes per model (tokens).
	 * Used as a fallback lookup for WP SDK provider models that do not carry
	 * context_window metadata from the provider registry.
	 *
	 * @var array<string, int>
	 */
	const MODEL_CONTEXT_WINDOWS = array(
		// Anthropic.
		'claude-opus-4-6'               => 200000,
		'claude-sonnet-4-6'             => 200000,
		'claude-opus-4-5'               => 200000,
		'claude-sonnet-4-5'             => 200000,
		'claude-haiku-3-5'              => 200000,
		'claude-3-5-haiku-20241022'     => 200000,
		'claude-opus-4-20250514'        => 200000,
		'claude-sonnet-4-20250514'      => 200000,
		'claude-haiku-3-20241022'       => 200000,
		// OpenAI GPT-4.1 family.
		'gpt-4.1'                       => 1000000,
		'gpt-4.1-mini'                  => 1000000,
		'gpt-4.1-nano'                  => 1000000,
		// OpenAI GPT-4o family.
		'gpt-4o'                        => 128000,
		'gpt-4o-mini'                   => 128000,
		'gpt-4-turbo'                   => 128000,
		// OpenAI o-series.
		'o1'                            => 200000,
		'o1-mini'                       => 128000,
		'o3'                            => 200000,
		'o3-mini'                       => 200000,
		'o4-mini'                       => 200000,
		// Google Gemini.
		'gemini-2.5-pro-preview-05-06'  => 1048576,
		'gemini-2.5-flash-preview'      => 1048576,
		'gemini-2.5-flash-lite-preview' => 1048576,
		'gemini-2.0-flash'              => 1048576,
		'gemini-2.0-flash-lite'         => 1048576,
		'gemini-1.5-pro'                => 2000000,
		'gemini-1.5-flash'              => 1048576,
	);

	/**
	 * Built-in catalog of provider IDs, display names, default models, and
	 * model lists for OpenAI, Anthropic, and Google.
	 *
	 * This is a static catalog of metadata only — it does NOT imply that any
	 * of these providers are configured. Credentials are stored exclusively by
	 * the WordPress 7.0 Connectors API in `connectors_ai_{provider}_api_key`
	 * options; see {@see Settings::get_connectors_api_key()} and
	 * {@see ProviderCredentialLoader::load()}.
	 *
	 * The catalog is consulted by `/providers`, `/alerts`, and the
	 * `wp ai-agent models` CLI command so that sites without a third-party
	 * AI provider plugin (`ai-provider-for-openai` etc.) can still discover
	 * which models exist for a connector that has a key set.
	 *
	 * @var array<string, array{name: string, default_model: string, models: list<array{id: string, name: string, context_window: int}>}>
	 */
	const DIRECT_PROVIDERS = array(
		'openai'    => array(
			'name'          => 'OpenAI',
			'default_model' => 'gpt-4.1-nano',
			'models'        => array(
				array(
					'id'             => 'gpt-4.1-nano',
					'name'           => 'GPT-4.1 Nano',
					'context_window' => 1000000,
				),
				array(
					'id'             => 'gpt-4.1-mini',
					'name'           => 'GPT-4.1 Mini',
					'context_window' => 1000000,
				),
				array(
					'id'             => 'gpt-4.1',
					'name'           => 'GPT-4.1',
					'context_window' => 1000000,
				),
				array(
					'id'             => 'gpt-4o',
					'name'           => 'GPT-4o',
					'context_window' => 128000,
				),
				array(
					'id'             => 'gpt-4o-mini',
					'name'           => 'GPT-4o Mini',
					'context_window' => 128000,
				),
				array(
					'id'             => 'gpt-4-turbo',
					'name'           => 'GPT-4 Turbo',
					'context_window' => 128000,
				),
				array(
					'id'             => 'o1',
					'name'           => 'o1',
					'context_window' => 200000,
				),
				array(
					'id'             => 'o1-mini',
					'name'           => 'o1 Mini',
					'context_window' => 128000,
				),
				array(
					'id'             => 'o3-mini',
					'name'           => 'o3 Mini',
					'context_window' => 200000,
				),
				array(
					'id'             => 'o3',
					'name'           => 'o3',
					'context_window' => 200000,
				),
				array(
					'id'             => 'o4-mini',
					'name'           => 'o4 Mini',
					'context_window' => 200000,
				),
			),
		),
		'anthropic' => array(
			'name'          => 'Anthropic',
			'default_model' => 'claude-sonnet-4-6',
			'models'        => array(
				array(
					'id'             => 'claude-opus-4-6',
					'name'           => 'Claude Opus 4.6',
					'context_window' => 200000,
				),
				array(
					'id'             => 'claude-sonnet-4-6',
					'name'           => 'Claude Sonnet 4.6',
					'context_window' => 200000,
				),
				array(
					'id'             => 'claude-opus-4-5',
					'name'           => 'Claude Opus 4.5',
					'context_window' => 200000,
				),
				array(
					'id'             => 'claude-sonnet-4-5',
					'name'           => 'Claude Sonnet 4.5',
					'context_window' => 200000,
				),
				array(
					'id'             => 'claude-haiku-3-5',
					'name'           => 'Claude Haiku 3.5',
					'context_window' => 200000,
				),
				array(
					'id'             => 'claude-3-5-haiku-20241022',
					'name'           => 'Claude 3.5 Haiku',
					'context_window' => 200000,
				),
				array(
					'id'             => 'claude-opus-4-20250514',
					'name'           => 'Claude Opus 4',
					'context_window' => 200000,
				),
				array(
					'id'             => 'claude-sonnet-4-20250514',
					'name'           => 'Claude Sonnet 4',
					'context_window' => 200000,
				),
				array(
					'id'             => 'claude-haiku-3-20241022',
					'name'           => 'Claude Haiku 3',
					'context_window' => 200000,
				),
			),
		),
		'google'    => array(
			'name'          => 'Google',
			'default_model' => 'gemini-2.0-flash',
			'models'        => array(
				array(
					'id'             => 'gemini-2.5-pro-preview-05-06',
					'name'           => 'Gemini 2.5 Pro',
					'context_window' => 1048576,
				),
				array(
					'id'             => 'gemini-2.5-flash-preview',
					'name'           => 'Gemini 2.5 Flash',
					'context_window' => 1048576,
				),
				array(
					'id'             => 'gemini-2.5-flash-lite-preview',
					'name'           => 'Gemini 2.5 Flash Lite',
					'context_window' => 1048576,
				),
				array(
					'id'             => 'gemini-2.0-flash',
					'name'           => 'Gemini 2.0 Flash',
					'context_window' => 1048576,
				),
				array(
					'id'             => 'gemini-2.0-flash-lite',
					'name'           => 'Gemini 2.0 Flash Lite',
					'context_window' => 1048576,
				),
				array(
					'id'             => 'gemini-1.5-pro',
					'name'           => 'Gemini 1.5 Pro',
					'context_window' => 2000000,
				),
				array(
					'id'             => 'gemini-1.5-flash',
					'name'           => 'Gemini 1.5 Flash',
					'context_window' => 1048576,
				),
			),
		),
	);

	// ── Static factory (bridge for non-DI code) ───────────────────────────────

	/**
	 * Return the shared Settings instance for use in non-DI-managed code.
	 *
	 * DI-managed handlers should receive Settings via constructor injection
	 * instead of calling this method. This factory exists as a bridge for
	 * legacy static callers during the transition period.
	 *
	 * @return self
	 */
	public static function instance(): self {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}
		return $instance;
	}

	// ── Instance methods ──────────────────────────────────────────────────────

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_defaults(): array {
		return array(
			'default_provider'         => '',
			'default_model'            => '',
			'max_iterations'           => 50,
			'greeting_message'         => '',
			'system_prompt'            => '',
			'auto_memory'              => true,
			'tool_permissions'         => array(),
			'temperature'              => 0.2,
			'max_output_tokens'        => 4096,
			'context_window_default'   => 128000,
			'onboarding_complete'      => false,
			'knowledge_enabled'        => true,
			'knowledge_auto_index'     => true,
			'max_history_turns'        => 20,
			'suggestion_count'         => 3,
			'yolo_mode'                => false,
			'site_builder_mode'        => false,
			'show_on_frontend'         => false,
			'keyboard_shortcut'        => 'alt+a',
			'image_generation_size'    => '1024x1024',
			'image_generation_quality' => 'standard',
			'image_generation_style'   => 'vivid',
			// White-label / branding settings (t075).
			'agent_name'               => '',
			'brand_primary_color'      => '',
			'brand_text_color'         => '',
			'brand_logo_url'           => '',
			// Spending limits / budget caps (t110).
			'budget_daily_cap'         => 0.0,
			'budget_monthly_cap'       => 0.0,
			'budget_warning_threshold' => 80,
			'budget_exceeded_action'   => 'pause',
			// Provider trace / debug mode (GH#830).
			'provider_trace_enabled'   => false,
			'provider_trace_max_rows'  => 200,
			// Skill auto-update settings (t218).
			'skill_auto_update'        => true,
			'skill_manifest_url'       => '',
		);
	}

	/**
	 * Read the API key stored by the WordPress 7.0 Connectors API for a
	 * built-in provider in {@see Settings::DIRECT_PROVIDERS}.
	 *
	 * Reads the `connectors_ai_{provider}_api_key` option directly. This is the
	 * same naming convention used by core on WP 7.0+ and by the 6.9 polyfill
	 * in `includes/Compat/wp-connectors-polyfill.php`, so credentials entered
	 * through the Connectors admin page are picked up here without any further
	 * migration.
	 *
	 * Note: this helper is intentionally read-only — credential writes flow
	 * through {@see ConnectorsController}, never through the Settings class.
	 *
	 * @param string $provider_id One of the keys of {@see Settings::DIRECT_PROVIDERS}.
	 * @return string Empty string when not configured.
	 */
	public function get_connectors_api_key( string $provider_id ): string {
		$sanitized_id = str_replace( '-', '_', $provider_id );
		return (string) get_option( "connectors_ai_{$sanitized_id}_api_key", '' );
	}

	/**
	 * Get the stored Google Search Console credentials.
	 *
	 * Returns an associative array with the credential type and fields, or an
	 * empty array when not configured. The raw credential values are NEVER
	 * returned through GET /settings — only a boolean presence flag is exposed.
	 *
	 * Supported shapes:
	 *   Service account: ['type' => 'service_account', 'client_email' => '...', 'private_key' => '...', 'default_site_url' => '...']
	 *   Access token:    ['type' => 'access_token', 'access_token' => '...', 'default_site_url' => '...']
	 *
	 * @return array<string, mixed> Credential array or empty array.
	 */
	public function get_gsc_credentials(): array {
		$creds = get_option( self::GSC_CREDENTIALS_OPTION, array() );
		/** @var array<string, mixed> $result */
		$result = is_array( $creds ) ? $creds : array();
		return $result;
	}

	/**
	 * Persist Google Search Console credentials.
	 *
	 * Pass an empty array to clear the credentials.
	 *
	 * @param array<string, mixed> $credentials Credential array (see get_gsc_credentials() for shape).
	 * @return bool True on success.
	 */
	public function set_gsc_credentials( array $credentials ): bool {
		if ( empty( $credentials ) ) {
			return delete_option( self::GSC_CREDENTIALS_OPTION );
		}

		return update_option( self::GSC_CREDENTIALS_OPTION, $credentials );
	}

	/**
	 * Check whether GSC credentials are configured.
	 *
	 * @return bool
	 */
	public function has_gsc_credentials(): bool {
		$creds = $this->get_gsc_credentials();
		return ! empty( $creds['type'] );
	}

	/**
	 * Resolve the effective default model ID.
	 *
	 * Resolution order (first non-empty value wins):
	 *   1. `default_model` setting saved by the site administrator.
	 *   2. Value returned by the `sd_ai_agent_default_model` filter (allows
	 *      developers to override the default programmatically).
	 *   3. The `SD_AI_AGENT_DEFAULT_MODEL` constant defined in the plugin root.
	 *
	 * Example — override the default model from a theme or mu-plugin:
	 *
	 *   add_filter( 'sd_ai_agent_default_model', function ( string $model ): string {
	 *       return 'gpt-4o';
	 *   } );
	 *
	 * @return string Non-empty model ID.
	 */
	public function get_default_model(): string {
		$settings = $this->get();
		// @phpstan-ignore-next-line
		$model = (string) ( $settings['default_model'] ?? '' );

		if ( '' === $model ) {
			$builtin = defined( 'SD_AI_AGENT_DEFAULT_MODEL' ) ? (string) SD_AI_AGENT_DEFAULT_MODEL : 'claude-sonnet-4';

			/**
			 * Filter the default model ID used when no model is configured in settings.
			 *
			 * @param string $model The built-in fallback model ID (SD_AI_AGENT_DEFAULT_MODEL).
			 */
			$model = (string) apply_filters( 'sd_ai_agent_default_model', $builtin );
		}

		return $model;
	}

	/**
	 * Get a single setting or all settings merged with defaults.
	 *
	 * @param string|null $key Optional key to retrieve.
	 * @return mixed
	 */
	public function get( ?string $key = null ) {
		$saved    = get_option( self::OPTION_NAME, array() );
		$defaults = $this->get_defaults();
		// @phpstan-ignore-next-line
		$merged = wp_parse_args( $saved, $defaults );

		if ( null === $key ) {
			return $merged;
		}

		return $merged[ $key ] ?? ( $defaults[ $key ] ?? null );
	}

	/**
	 * Partial-update settings (merge incoming data with existing).
	 *
	 * @param array<string, mixed> $data Key-value pairs to update.
	 * @return bool
	 */
	public function update( array $data ): bool {
		$current  = get_option( self::OPTION_NAME, array() );
		$defaults = $this->get_defaults();

		// Only allow known keys.
		$allowed = array_keys( $defaults );
		$data    = array_intersect_key( $data, array_flip( $allowed ) );

		// @phpstan-ignore-next-line
		$merged = array_merge( $current, $data );

		return update_option( self::OPTION_NAME, $merged );
	}

	// ── WooCommerce ability auto-enable ───────────────────────────────────────

	/**
	 * Option name that records whether WooCommerce abilities were auto-enabled.
	 * Stored as a standalone option (not inside the settings blob) so it
	 * persists even if the settings are reset.
	 */
	const WOO_AUTO_ENABLED_OPTION = 'sd_ai_agent_woo_abilities_auto_enabled';

	/**
	 * WooCommerce ability IDs managed by this auto-enable routine.
	 *
	 * @var string[]
	 */
	const WOO_ABILITY_IDS = array(
		'sd-ai-agent/woo-get-products',
		'sd-ai-agent/woo-create-product',
		'sd-ai-agent/woo-update-product',
		'sd-ai-agent/woo-delete-product',
		'sd-ai-agent/woo-get-orders',
		'sd-ai-agent/woo-get-store-stats',
	);

	/**
	 * Auto-enable WooCommerce abilities on first load when a provider is
	 * detected and WooCommerce is active.
	 *
	 * Conditions (all must be true):
	 *  1. Auto-enable has not already run (idempotent guard).
	 *  2. WooCommerce is active (class WooCommerce exists).
	 *  3. At least one AI provider is configured (direct API key, Claude Max
	 *     token, or a default_provider set by the WP SDK Connectors API).
	 *
	 * When all conditions are met the WooCommerce ability IDs are added to
	 * `tool_permissions` with the 'auto' level so they execute without
	 * requiring per-call user confirmation.
	 *
	 * @return void
	 */
	public function maybe_auto_enable_woo_abilities(): void {
		// 1. Idempotent guard — only run once per site.
		if ( get_option( self::WOO_AUTO_ENABLED_OPTION ) ) {
			return;
		}

		// 2. WooCommerce must be active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// 3. A provider must be configured.
		if ( ! $this->has_provider_configured() ) {
			return;
		}

		// Merge WooCommerce abilities into the existing tool_permissions map.
		$current_perms = (array) $this->get( 'tool_permissions' );

		foreach ( self::WOO_ABILITY_IDS as $ability_id ) {
			if ( ! isset( $current_perms[ $ability_id ] ) ) {
				$current_perms[ $ability_id ] = 'auto';
			}
		}

		$this->update( array( 'tool_permissions' => $current_perms ) );

		// Mark as done so this routine never runs again.
		update_option( self::WOO_AUTO_ENABLED_OPTION, true, false );
	}

	/**
	 * Check whether at least one AI provider is configured.
	 *
	 * Resolution order:
	 *  1. `default_provider` saved via the WP SDK Connectors settings page.
	 *  2. A WP 7.0 Connectors API key for any built-in provider in
	 *     {@see Settings::DIRECT_PROVIDERS}.
	 *
	 * @return bool
	 */
	private function has_provider_configured(): bool {
		// WP SDK connector.
		$provider = (string) $this->get( 'default_provider' );
		if ( '' !== $provider ) {
			return true;
		}

		// WP 7.0 Connectors API key for a built-in provider.
		foreach ( array_keys( self::DIRECT_PROVIDERS ) as $provider_id ) {
			if ( '' !== $this->get_connectors_api_key( $provider_id ) ) {
				return true;
			}
		}

		return false;
	}
}
