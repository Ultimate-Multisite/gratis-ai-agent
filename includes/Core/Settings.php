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
	 * Sentinel value for `max_output_tokens` settings meaning "resolve
	 * automatically per model" via {@see Settings::get_max_output_tokens_for_model()}.
	 */
	const MAX_OUTPUT_TOKENS_AUTO = 0;

	/**
	 * Legacy default `max_output_tokens` value that shipped with pre-7rl
	 * releases. Existing installs upgrading from those versions carry this
	 * value as a saved option even though the user never explicitly chose it.
	 *
	 * {@see AgentLoop::get_effective_max_output_tokens()} treats an exact match
	 * to this value as AUTO so existing installs benefit from the per-model
	 * catalog without requiring a settings migration. Users who genuinely want
	 * to cap at 4096 can set 4095 or 4097 instead.
	 */
	const MAX_OUTPUT_TOKENS_LEGACY_DEFAULT = 4096;

	/**
	 * Hard ceiling for `max_output_tokens`. Above this we refuse to send
	 * the value to the provider — primarily a guard against pathologically
	 * large outputs causing latency spikes or billing surprises. Modern
	 * reasoning models (o3, Claude with extended thinking) can usefully
	 * emit up to ~100K output tokens, so the ceiling is generous.
	 */
	const MAX_OUTPUT_TOKENS_CEILING = 131072;

	/**
	 * Fallback output cap used when the model is unknown to the per-model
	 * catalog. Lifted from the legacy 4096 default — 8192 is supported by
	 * every modern chat model in the catalog while still bounding generation.
	 */
	const MAX_OUTPUT_TOKENS_FALLBACK = 8192;

	/**
	 * Per-model output-tokens cap (max tokens we will request the provider
	 * to generate in a single response). Keys are model IDs as advertised
	 * by the provider SDK and white-label connectors.
	 *
	 * The Anthropic Messages API requires `max_tokens` to be sent on every
	 * request, so a sensible per-model value is mandatory there. OpenAI and
	 * Gemini accept it as optional; we still send a value as a safety belt
	 * against runaway generations and pathological tool-call loops.
	 *
	 * Values follow each provider's documented maximums so that a single
	 * tool-call response (e.g. building a long landing page in one shot) is
	 * not artificially truncated. Users can still lower this per install via
	 * the Settings UI; the ceiling is {@see MAX_OUTPUT_TOKENS_CEILING}.
	 *
	 * Lookup uses longest-prefix match so dated model variants (e.g.
	 * `claude-opus-4-7-20260513`) resolve to the most specific family entry.
	 * Entries are ordered most-specific-first inside each provider block so
	 * that the prefix match picks the right point release before falling
	 * back to the family default.
	 *
	 * Sources (verified Nov 2025):
	 *   - https://docs.anthropic.com/en/docs/about-claude/models/overview
	 *   - https://platform.openai.com/docs/models
	 *   - https://cloud.google.com/vertex-ai/generative-ai/docs/models/gemini
	 *   - Synthetic AI /openai/v1/models (live response — `max_output_length`)
	 *
	 * @var array<string, int>
	 */
	const MODEL_MAX_OUTPUT_TOKENS = array(
		// ── Anthropic ──────────────────────────────────────────────────
		// Opus 4.6 / 4.7 document 128K; Opus 4.5 documents 64K; Opus 4.1
		// and Opus 4 document 32K. Newer point releases have higher caps
		// than older ones in the same family, which is why these are not
		// collapsed into a single `claude-opus-4` entry.
		'claude-opus-4-7'             => 128000,
		'claude-opus-4-6'             => 128000,
		'claude-opus-4-5'             => 64000,
		'claude-opus-4-1'             => 32000,
		'claude-opus-4'               => 32000,
		// Sonnet 4 / 4.5 / 4.6 all document 64K output.
		'claude-sonnet-4'             => 64000,
		// Haiku 4.5 documents 64K output (matches Sonnet 4.x).
		'claude-haiku-4-5'            => 64000,
		'claude-haiku-4'              => 64000,
		// Legacy 3.x models retain older lower caps.
		'claude-3-5-sonnet'           => 8192,
		'claude-3-5-haiku'            => 8192,
		'claude-3-opus'               => 4096,
		'claude-3-sonnet'             => 4096,
		'claude-3-haiku'              => 4096,
		// ── OpenAI ─────────────────────────────────────────────────────
		// GPT-5 family (5, 5.4, 5.5) and o-series reasoning models all
		// document 128K output. o1/o3 budgets include reasoning tokens.
		'gpt-5'                       => 128000,
		// GPT-4.1 documents 32,768; GPT-4o documents 16,384.
		'gpt-4.1'                     => 32768,
		'gpt-4o'                      => 16384,
		'gpt-4-turbo'                 => 4096,
		'gpt-4'                       => 8192,
		'gpt-3.5-turbo'               => 4096,
		// o-series reasoning models: o1 and o3 document 100K; o4 family
		// (o4-mini etc.) inherits the same envelope.
		'o1'                          => 100000,
		'o3'                          => 100000,
		'o4'                          => 100000,
		// ── Google Gemini ──────────────────────────────────────────────
		// Gemini 2.5 Pro and Flash both document 65,535 max output.
		// Older 2.0 and 1.5 families cap at 8K.
		'gemini-2.5-pro'              => 65535,
		'gemini-2.5-flash'            => 65535,
		'gemini-2.0'                  => 8192,
		'gemini-1.5'                  => 8192,
		// ── HuggingFace-served via OpenAI-compatible connectors ────────
		// These IDs come from providers like Synthetic AI that proxy
		// HuggingFace-hosted models through an OpenAI-compatible API and
		// report `max_output_length` in their /models response. Specific
		// entries override the generic `hf:` prefix below via longest-
		// prefix match. Verified against Synthetic's live /models endpoint
		// (`https://api.synthetic.new/openai/v1/models`).
		//
		// Without these entries, model IDs prefixed `hf:` fall through to
		// the 8192 token fallback, which is far below the provider's
		// actual cap (65,536 for the active Synthetic models). On a long
		// single-shot tool call (e.g. emitting a full landing page in one
		// `sd-ai-agent/create-post`) this caused the model to hit the
		// 8192 cap *before* it could open the tool-call JSON, leaving
		// the session idle with a preamble like "Now I'll create the full
		// landing page..." and no follow-through. See PR description.
		'hf:moonshotai/Kimi-K2.6'     => 65536,
		'hf:moonshotai/Kimi-K2.5'     => 32768,
		'hf:zai-org/GLM-5.1'          => 65536,
		'hf:zai-org/GLM-5'            => 65536,
		'hf:zai-org/GLM-4.7-Flash'    => 65536,
		'hf:zai-org/GLM-4.7'          => 65536,
		'hf:MiniMaxAI/MiniMax-M2.5'   => 65536,
		'hf:nvidia/NVIDIA-Nemotron-3' => 65536,
		// Broad fallback for any other `hf:`-prefixed model served via
		// OpenAI-compatible proxies. 32K is a generous but bounded value
		// that's well under the WordPress AI Client SDK's request-body
		// limits while being 4x the global 8192 fallback. Specific
		// entries above take precedence via longest-prefix match.
		'hf:'                         => 32768,
	);

	/**
	 * Resolve the appropriate `max_tokens` value for a given model.
	 *
	 * Falls back through, in order:
	 *   1. exact match in {@see MODEL_MAX_OUTPUT_TOKENS},
	 *   2. longest prefix match (so dated/quantized variants resolve to
	 *      their family entry),
	 *   3. {@see MAX_OUTPUT_TOKENS_FALLBACK} (8192).
	 *
	 * Filterable via `sd_ai_agent_max_output_tokens_for_model` to let
	 * deployments override the catalog for custom or self-hosted models.
	 *
	 * @param string $model_id Provider-advertised model identifier. May be
	 *                         empty when no model has been selected yet, in
	 *                         which case the fallback is returned.
	 * @return int Tokens. Always positive.
	 */
	public static function get_max_output_tokens_for_model( string $model_id ): int {
		$model_id = trim( $model_id );
		$resolved = self::MAX_OUTPUT_TOKENS_FALLBACK;

		if ( '' !== $model_id ) {
			if ( isset( self::MODEL_MAX_OUTPUT_TOKENS[ $model_id ] ) ) {
				$resolved = self::MODEL_MAX_OUTPUT_TOKENS[ $model_id ];
			} else {
				// Longest-prefix match so e.g. `claude-opus-4-7-20260513`
				// resolves to `claude-opus-4` and `gpt-4.1-mini` to `gpt-4.1`.
				$best_len = 0;
				foreach ( self::MODEL_MAX_OUTPUT_TOKENS as $prefix => $value ) {
					$prefix_len = strlen( $prefix );
					if (
						$prefix_len > $best_len
						&& 0 === strncmp( $model_id, $prefix, $prefix_len )
					) {
						$best_len = $prefix_len;
						$resolved = $value;
					}
				}
			}
		}

		/**
		 * Filter the resolved max-output-tokens value for a model.
		 *
		 * @param int    $resolved Tokens chosen from the catalog/fallback.
		 * @param string $model_id Model identifier being resolved.
		 */
		$filtered = (int) apply_filters( 'sd_ai_agent_max_output_tokens_for_model', $resolved, $model_id );

		// Clamp to ceiling and ensure positive — defends against rogue filters.
		if ( $filtered < 1 ) {
			$filtered = self::MAX_OUTPUT_TOKENS_FALLBACK;
		}
		if ( $filtered > self::MAX_OUTPUT_TOKENS_CEILING ) {
			$filtered = self::MAX_OUTPUT_TOKENS_CEILING;
		}

		return $filtered;
	}

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
			'default_provider'                => '',
			'default_model'                   => '',
			'max_iterations'                  => 100,
			'greeting_message'                => '',
			'system_prompt'                   => '',
			'auto_memory'                     => true,
			'tool_permissions'                => array(),
			'temperature'                     => 0.2,
			// 0 = auto-resolve per model via Settings::get_max_output_tokens_for_model().
			// A user-saved positive value overrides the per-model default (clamped to
			// MAX_OUTPUT_TOKENS_CEILING at request time). See AgentLoop::send_prompt().
			'max_output_tokens'               => self::MAX_OUTPUT_TOKENS_AUTO,
			'context_window_default'          => 128000,
			'onboarding_complete'             => false,
			'knowledge_enabled'               => true,
			'knowledge_auto_index'            => true,
			'max_history_turns'               => 20,
			'suggestion_count'                => 3,
			'yolo_mode'                       => false,
			'show_on_frontend'                => false,
			'keyboard_shortcut'               => 'alt+a',
			'image_generation_size'           => '1024x1024',
			'image_generation_quality'        => 'standard',
			'image_generation_style'          => 'vivid',
			// White-label / branding settings (t075).
			'agent_name'                      => '',
			'brand_primary_color'             => '',
			'brand_text_color'                => '',
			'brand_logo_url'                  => '',
			// Spending limits / budget caps (t110).
			'budget_daily_cap'                => 0.0,
			'budget_monthly_cap'              => 0.0,
			'budget_warning_threshold'        => 80,
			'budget_exceeded_action'          => 'pause',
			// Provider trace / debug mode (GH#830).
			'provider_trace_enabled'          => false,
			'provider_trace_max_rows'         => 200,
			// Prompt caching — provider-side KV-cache opt-in (sd-ai-bjv).
			// When true, the HttpTraceHandler injects provider-specific
			// cache markers into outgoing LLM requests (only Anthropic
			// requires explicit markers today; OpenAI/DeepSeek/etc. cache
			// automatically). Safe to leave on — workers without cache
			// support ignore the markers.
			'prompt_caching_enabled'          => true,
			// Skill auto-update settings (t218).
			'skill_auto_update'               => true,
			'skill_manifest_url'              => '',
			// Third-party ability visibility mode (sd-ai-3ns / #1405, sd-ai-u21 / #1407).
			// Controls which abilities are exposed to AI surfaces by AbilityVisibility.
			// 'legacy'  — opt-out behaviour: everything except ai_hidden is public.
			// The AbilityVisibility resolver is forced to allow regardless
			// of namespace/category/heuristic tiers.
			// 'auto'    — full tiered-trust model: namespace allowlist + heuristics.
			// Default since 1.12.0.
			// 'strict'  — only meta.mcp.public === true passes. Future use.
			'third_party_mode'                => 'auto',

			// Third-party namespace visibility decisions (sd-ai-0zq / #1406).
			// Maps namespace slugs to visibility decisions: 'allow', 'block', or 'pending'.
			// Used by ThirdPartyAbilityNoticeHandler to override the heuristic for
			// unclassified abilities. Format: { 'namespace-slug': 'allow|block|pending' }
			'third_party_namespace_decisions' => array(),
		);
	}

	/**
	 * Return the current third-party ability visibility mode.
	 *
	 * Controls which abilities AbilityVisibility exposes to AI and MCP surfaces.
	 * Values: 'legacy' (default) | 'auto' | 'strict'.
	 *
	 * Static helper so AbilityVisibility can call it without DI.
	 *
	 * @return string One of 'legacy', 'auto', 'strict'.
	 */
	public static function get_third_party_mode(): string {
		$option = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $option ) || ! isset( $option['third_party_mode'] ) ) {
			return 'legacy';
		}
		$mode = (string) $option['third_party_mode'];
		if ( ! in_array( $mode, array( 'legacy', 'auto', 'strict' ), true ) ) {
			return 'legacy';
		}
		return $mode;
	}

	/**
	 * Get the namespace-level visibility decisions for third-party abilities.
	 *
	 * Returns a map of namespace slugs to decisions: 'allow', 'block', or 'pending'.
	 * Used by AbilityVisibility::classify() to override the heuristic for
	 * unclassified abilities when the site owner has made an explicit decision.
	 *
	 * Static helper so AbilityVisibility can call it without DI.
	 *
	 * @return array<string, string> Map of namespace => decision.
	 */
	public static function get_third_party_namespace_decisions(): array {
		$option = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $option ) || ! isset( $option['third_party_namespace_decisions'] ) ) {
			return array();
		}
		$decisions = $option['third_party_namespace_decisions'];
		if ( ! is_array( $decisions ) ) {
			return array();
		}
		// Sanitize to ensure all keys and values are strings.
		$sanitized = array();
		foreach ( $decisions as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) ) {
				$sanitized[ $key ] = $value;
			}
		}
		return $sanitized;
	}

	/**
	 * Whether prompt caching is enabled for outgoing LLM requests.
	 *
	 * Provider-aware: Anthropic requests get explicit `cache_control`
	 * markers; providers with automatic server-side caching (OpenAI,
	 * DeepSeek, xAI, Groq, etc.) pass through unchanged. Disabling this
	 * setting opts out entirely — markers are not injected.
	 *
	 * Static helper so HttpTraceHandler can consult the flag without
	 * needing DI access to a Settings instance during filter execution.
	 *
	 * @return bool
	 */
	public static function is_prompt_caching_enabled(): bool {
		$option = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $option ) || ! array_key_exists( 'prompt_caching_enabled', $option ) ) {
			return true; // Default-on.
		}
		return (bool) $option['prompt_caching_enabled'];
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
		'woocommerce/products-list',
		'woocommerce/products-get',
		'woocommerce/products-create',
		'woocommerce/products-update',
		'woocommerce/products-delete',
		'woocommerce/orders-list',
		'woocommerce/orders-get',
		'woocommerce/orders-create',
		'woocommerce/orders-update',
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
	 *  2. Any provider in the WP AI Client SDK registry has authentication
	 *     configured (handles credentials populated by `ai-provider-for-*`
	 *     plugins, the WP 7.0 Connectors API, or the 6.9 polyfill).
	 *
	 * @return bool
	 */
	private function has_provider_configured(): bool {
		// WP SDK connector.
		$provider = (string) $this->get( 'default_provider' );
		if ( '' !== $provider ) {
			return true;
		}

		// Any registered provider with credentials.
		return ProviderCredentialLoader::has_any_authenticated_provider();
	}
}
