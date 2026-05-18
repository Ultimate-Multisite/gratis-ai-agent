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
	 * Option name that records the most recent saved default-model value
	 * which was rejected by {@see resolve_default_provider_and_model()}
	 * because the configured provider/model was not advertised by any
	 * authenticated provider in the WP AI Client SDK registry.
	 *
	 * Used by {@see \SdAiAgent\Admin\DefaultModelNoticeHandler} to render
	 * a one-time admin notice describing the rejected value and the
	 * substitute the plugin chose so the user knows why the dropdown
	 * default no longer matches what they last saved.
	 *
	 * Shape:
	 *   array{
	 *     provider:           string, // saved (possibly invalid) provider ID
	 *     model:              string, // saved (possibly invalid) model ID
	 *     replacement_provider: string, // provider chosen by the resolver
	 *     replacement_model:    string, // model chosen by the resolver
	 *     recorded_at:        int,    // unix timestamp
	 *   }
	 */
	const INVALID_DEFAULT_NOTICE_OPTION = 'sd_ai_agent_invalid_default_model_notice';

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
	 *   1. live transient written by {@see ModelCapabilityRegistry} (populated
	 *      from provider `/models` responses — keeps Synthetic-hosted HF
	 *      models like Kimi K2.6 honest as their advertised caps move),
	 *   2. exact match in {@see MODEL_MAX_OUTPUT_TOKENS},
	 *   3. longest prefix match (so dated/quantized variants resolve to
	 *      their family entry),
	 *   4. {@see MAX_OUTPUT_TOKENS_FALLBACK} (8192).
	 *
	 * Filterable via `sd_ai_agent_max_output_tokens_for_model` (applied last)
	 * to let deployments override both the live registry and the catalog
	 * for custom or self-hosted models.
	 *
	 * @param string $model_id Provider-advertised model identifier. May be
	 *                         empty when no model has been selected yet, in
	 *                         which case the fallback is returned.
	 * @return int Tokens. Always positive.
	 */
	public static function get_max_output_tokens_for_model( string $model_id ): int {
		$model_id = trim( $model_id );

		// Registry consults transient → static catalog → fallback in order;
		// returns the global fallback if nothing matches.
		$resolved = '' !== $model_id
			? ModelCapabilityRegistry::get_max_output_tokens( $model_id )
			: self::MAX_OUTPUT_TOKENS_FALLBACK;

		/**
		 * Filter the resolved max-output-tokens value for a model.
		 *
		 * Applied AFTER the live registry and static catalog lookups so
		 * deployments can pin a value regardless of what the provider
		 * advertises.
		 *
		 * @param int    $resolved Tokens chosen from registry/catalog/fallback.
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

	/**
	 * Pure static-catalog lookup for max-output-tokens.
	 *
	 * Encapsulates the exact-match + longest-prefix-match logic against
	 * {@see MODEL_MAX_OUTPUT_TOKENS} so {@see ModelCapabilityRegistry::get()}
	 * can consult it without ricocheting back through the filterable resolver.
	 *
	 * Returns 0 when no catalog entry matches — the caller decides what to
	 * fall back to (typically {@see MAX_OUTPUT_TOKENS_FALLBACK}).
	 *
	 * @param string $model_id Provider-advertised model identifier.
	 * @return int Tokens from the catalog, or 0 if no match.
	 */
	public static function resolve_max_output_tokens_from_catalog( string $model_id ): int {
		$model_id = trim( $model_id );
		if ( '' === $model_id ) {
			return 0;
		}

		if ( isset( self::MODEL_MAX_OUTPUT_TOKENS[ $model_id ] ) ) {
			return (int) self::MODEL_MAX_OUTPUT_TOKENS[ $model_id ];
		}

		// Longest-prefix match so e.g. `claude-opus-4-7-20260513` resolves
		// to `claude-opus-4-7` and `gpt-4.1-mini` to `gpt-4.1`.
		$best_len = 0;
		$resolved = 0;
		foreach ( self::MODEL_MAX_OUTPUT_TOKENS as $prefix => $value ) {
			$prefix_len = strlen( $prefix );
			if (
				$prefix_len > $best_len
				&& 0 === strncmp( $model_id, $prefix, $prefix_len )
			) {
				$best_len = $prefix_len;
				$resolved = (int) $value;
			}
		}

		return $resolved;
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
	 * The resolver validates the saved (provider, model) pair against the
	 * WP AI Client SDK registry before returning it. If the saved provider
	 * has been uninstalled or no longer advertises the saved model, the
	 * resolver falls through to safer candidates instead of letting the
	 * SDK reject the request with a top-level error banner — the situation
	 * that produced the production `gemma4:e4b` regression (GH#1494).
	 *
	 * Resolution order:
	 *   1. The saved `default_model` from `sd_ai_agent_settings`, paired with
	 *      `default_provider`, when an authenticated provider in the registry
	 *      advertises that model.
	 *   2. The `sd_ai_agent_default_model` filter override, when an
	 *      authenticated provider in the registry advertises that model.
	 *   3. The `SD_AI_AGENT_DEFAULT_MODEL` constant (factory default), when
	 *      an authenticated provider in the registry advertises that model.
	 *   4. The first model advertised by the first authenticated provider —
	 *      so a fresh signup or an install that just rotated providers still
	 *      gets a working chat instead of a hard error.
	 *   5. The filtered/constant value as a last resort when the registry is
	 *      not consultable (e.g. WP < 7.0 without the bundled polyfill) so
	 *      historical behaviour is preserved on unsupported runtimes.
	 *
	 * When the saved value is rejected (path 1 fails because the saved pair
	 * is not advertised), {@see record_invalid_default_notice()} stores a
	 * one-time admin notice so the site owner is told what happened.
	 *
	 * Example — override the default model from a theme or mu-plugin:
	 *
	 *   add_filter( 'sd_ai_agent_default_model', function ( string $model ): string {
	 *       return 'gpt-4o';
	 *   } );
	 *
	 * @return string Model ID. Non-empty in every path except when the
	 *                registry is consultable and reports no authenticated
	 *                providers at all — in which case `''` is returned so
	 *                callers can short-circuit to the "no provider configured"
	 *                error path instead of sending an unusable request.
	 */
	public function get_default_model(): string {
		[ , $model ] = $this->resolve_default_provider_and_model();
		return $model;
	}

	/**
	 * Resolve the effective default provider ID.
	 *
	 * Co-resolves with {@see get_default_model()} so the returned provider
	 * advertises the returned model. When the saved `default_provider` is
	 * not registered or not authenticated, the resolver falls through to
	 * the first authenticated provider in the WP AI Client SDK registry.
	 *
	 * @return string Provider ID, or `''` when no provider can be chosen
	 *                (registry empty, or registry not consultable and no
	 *                provider was saved).
	 */
	public function get_default_provider(): string {
		[ $provider ] = $this->resolve_default_provider_and_model();
		return $provider;
	}

	/**
	 * Resolve the canonical (provider, model) pair used by the chat path.
	 *
	 * Resolution rules (in order):
	 *   - When the registry cannot be consulted (WP < 7.0 without the SDK
	 *     polyfill, SDK boot failure, etc.) the saved pair is returned
	 *     verbatim without validation or notice recording.
	 *   - When the saved `default_model` is **empty AND no
	 *     `sd_ai_agent_default_model` filter pins a value**, the resolver
	 *     returns `''` for the model so callers fall through to the SDK's
	 *     built-in per-provider default (e.g. anthropic picks its newest
	 *     Sonnet point release rather than being pinned to the constant).
	 *     Only the provider hint is co-resolved.
	 *   - Otherwise the **candidate** model is the filter-pinned value if
	 *     the filter overrode the constant, else the saved model. The
	 *     candidate is validated against the saved provider first, then
	 *     against any other authenticated provider; when valid it is
	 *     returned (with a notice only if the provider had to change).
	 *   - When the candidate is not advertised anywhere, the resolver
	 *     substitutes a working value (constant → first registered model)
	 *     and records a one-time admin notice describing the substitution
	 *     so site owners can investigate the rejected configuration.
	 *
	 * @see get_default_model()    for the public API and resolution order.
	 * @see get_default_provider() for the paired provider semantics.
	 *
	 * @return array{0: string, 1: string} `[provider_id, model_id]`. The model
	 *         may be `''` when the saved value was empty (intentional fall
	 *         through to the SDK's per-provider default). Both may be `''`
	 *         only when the registry is consultable but reports no
	 *         authenticated providers.
	 */
	private function resolve_default_provider_and_model(): array {
		$settings = $this->get();
		// @phpstan-ignore-next-line
		$saved_provider = (string) ( $settings['default_provider'] ?? '' );
		// @phpstan-ignore-next-line
		$saved_model = (string) ( $settings['default_model'] ?? '' );

		$builtin = defined( 'SD_AI_AGENT_DEFAULT_MODEL' )
			? (string) SD_AI_AGENT_DEFAULT_MODEL
			: 'claude-sonnet-4';

		/**
		 * Filter the default model ID used when no model is configured in settings.
		 *
		 * Applied BEFORE the registry-validation step so the filter can pin
		 * a value that will then be validated against the live providers.
		 * Filters that pin a model the user does not actually have a provider
		 * for will themselves fall through (with one-time notice) rather
		 * than emit an unrecoverable error from the SDK.
		 *
		 * @param string $model The built-in fallback model ID (SD_AI_AGENT_DEFAULT_MODEL).
		 */
		$filtered_builtin = (string) apply_filters( 'sd_ai_agent_default_model', $builtin );
		if ( '' === $filtered_builtin ) {
			$filtered_builtin = $builtin;
		}

		// If the registry is not consultable (WP < 7.0 without polyfill,
		// SDK boot failure, etc.) we cannot validate — preserve historical
		// behaviour and return the saved values verbatim.
		if ( ! self::can_validate_against_registry() ) {
			return array( $saved_provider, $saved_model );
		}

		$registered = self::collect_registered_provider_models();

		// Empty saved model with no filter override — preserve the historical
		// "let the SDK pick" behaviour. The chat path calls
		// $builder->using_provider() (no explicit model) when this returns
		// `''`, which lets each provider's adapter choose its own newest
		// stable model. Returning the constant unconditionally would force
		// every install onto a fixed ID even when the provider supports
		// something newer in the same family.
		//
		// When a `sd_ai_agent_default_model` filter pins a concrete value,
		// fall through to the regular validation/substitution flow so the
		// pinned value either takes effect (when registered) or is
		// substituted with a working model (when not). A broken filter
		// must not silently degrade to "SDK picks something different".
		$filter_pinned = ( $filtered_builtin !== $builtin );
		if ( '' === $saved_model && ! $filter_pinned ) {
			return array( self::resolve_provider_hint( $saved_provider, $registered ), '' );
		}

		// Candidate model = filter override if set, else saved. The saved
		// provider stays the candidate provider unless we later swap it
		// because the candidate model is registered under a different one.
		$candidate_model = $filter_pinned ? $filtered_builtin : $saved_model;

		// Path 1 — candidate (provider, model) pair, when the provider is
		// authenticated and advertises the candidate model.
		if ( '' !== $saved_provider
			&& self::pair_is_registered( $saved_provider, $candidate_model, $registered )
		) {
			return array( $saved_provider, $candidate_model );
		}

		// Path 2 — candidate model advertised by some authenticated provider
		// other than the saved one. Honour the candidate value but swap
		// to a provider that actually serves it; record a notice only when
		// we had a saved provider that just changed beneath the user.
		$provider_for_candidate = self::find_provider_for_model( $candidate_model, $registered );
		if ( null !== $provider_for_candidate ) {
			if ( '' !== $saved_provider && $saved_provider !== $provider_for_candidate ) {
				self::record_invalid_default_notice( $saved_provider, $saved_model, $provider_for_candidate, $candidate_model );
			}
			return array( $provider_for_candidate, $candidate_model );
		}

		// Candidate (filter result or saved model) is not advertised
		// anywhere — substitute and record a notice. Every path below
		// records a notice so the site owner is informed.
		//
		// Path 3 — the factory-default constant, when advertised by some
		// authenticated provider. Distinct from path 2 only when the filter
		// rewrote the value above; we still try the unfiltered constant
		// before falling all the way through.
		if ( $builtin !== $candidate_model ) {
			$provider_for_builtin = self::find_provider_for_model( $builtin, $registered );
			if ( null !== $provider_for_builtin ) {
				$resolved = array( $provider_for_builtin, $builtin );
				self::record_invalid_default_notice( $saved_provider, $saved_model, $resolved[0], $resolved[1] );
				return $resolved;
			}
		}

		// Path 4 — first advertised model from the first authenticated
		// provider. Prefer the saved provider when it is still authenticated
		// so the user does not see the provider switch out from under them
		// just because the saved model is gone.
		if ( ! empty( $registered ) ) {
			$preferred_provider = ( '' !== $saved_provider && isset( $registered[ $saved_provider ] ) )
				? $saved_provider
				: array_key_first( $registered );

			$models = $registered[ $preferred_provider ] ?? array();
			if ( ! empty( $models ) ) {
				$resolved = array( $preferred_provider, (string) $models[0] );
				self::record_invalid_default_notice( $saved_provider, $saved_model, $resolved[0], $resolved[1] );
				return $resolved;
			}
		}

		// Path 5 — registry consultable but reports no authenticated
		// providers at all. Return '' for both so the caller short-circuits
		// to the "no provider configured" error path with a useful message.
		self::record_invalid_default_notice( $saved_provider, $saved_model, '', '' );
		return array( '', '' );
	}

	/**
	 * Choose a provider hint for the empty-saved-model code path.
	 *
	 * Keeps the saved provider when it is still authenticated so a user who
	 * picked Anthropic at install time doesn't get switched to OpenAI just
	 * because they later cleared the model dropdown. Otherwise returns the
	 * first authenticated provider, or `''` if none.
	 *
	 * @param string                            $saved_provider Saved provider ID (may be '').
	 * @param array<string, array<int, string>> $registered     Map from {@see collect_registered_provider_models()}.
	 */
	private static function resolve_provider_hint( string $saved_provider, array $registered ): string {
		if ( '' !== $saved_provider && isset( $registered[ $saved_provider ] ) ) {
			return $saved_provider;
		}
		if ( ! empty( $registered ) ) {
			return (string) array_key_first( $registered );
		}
		return '';
	}

	/**
	 * Whether the WP AI Client SDK registry is available for validation.
	 *
	 * Returns false on installs that lack the SDK (WP < 7.0 without the
	 * bundled polyfill or where the polyfill could not bootstrap). Callers
	 * MUST treat a false result as "validation cannot run" and degrade
	 * gracefully — never as "no providers are registered".
	 *
	 * @return bool
	 */
	private static function can_validate_against_registry(): bool {
		return class_exists( '\\WordPress\\AiClient\\AiClient' );
	}

	/**
	 * Collect the (provider, model) pairs currently registered with the
	 * WP AI Client SDK registry.
	 *
	 * Per `AGENTS.md → Provider Credentials and Model Discovery`, this method
	 * does NOT add a plugin-level cache: the SDK already caches
	 * `listModelMetadata()` for 24 hours and an extra layer broke whenever
	 * a new third-party provider plugin stored credentials under an option
	 * we did not know about. Each call walks the registry fresh.
	 *
	 * Only providers that have authentication configured are included — an
	 * unauthenticated provider cannot serve a chat anyway.
	 *
	 * Tests can short-circuit the registry walk via the
	 * `sd_ai_agent_registered_models_for_validation` filter (this is a
	 * test affordance, not a cache — the filter receives a fresh list on
	 * every call and may return any list it wants).
	 *
	 * @return array<string, array<int, string>> Map of provider ID to the
	 *         model IDs that provider advertises. Returns an empty array
	 *         when the SDK is unavailable or no providers are authenticated.
	 */
	private static function collect_registered_provider_models(): array {
		$registered = array();

		/**
		 * Filter the registered (provider, model) pairs used for default-model
		 * validation. Return a non-null array to short-circuit the registry
		 * walk — primarily an affordance for unit tests that do not boot
		 * the full SDK.
		 *
		 * The filter MUST return a `array<string, array<int, string>>` map of
		 * provider IDs to advertised model IDs, or `null` to fall through to
		 * the live SDK walk.
		 *
		 * @param array<string, array<int, string>>|null $registered Filter override (null = fall through).
		 */
		$filtered = apply_filters( 'sd_ai_agent_registered_models_for_validation', null );
		if ( is_array( $filtered ) ) {
			/** @var array<string, array<int, string>> $filtered */
			return $filtered;
		}

		if ( ! self::can_validate_against_registry() ) {
			return $registered;
		}

		try {
			ProviderCredentialLoader::load();
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			$ids      = $registry->getRegisteredProviderIds();
		} catch ( \Throwable $e ) {
			return $registered;
		}

		foreach ( $ids as $provider_id ) {
			try {
				// Only include providers that have authentication configured.
				$auth = $registry->getProviderRequestAuthentication( $provider_id );
				if ( null === $auth ) {
					continue;
				}

				$class  = $registry->getProviderClassName( $provider_id );
				$models = array();

				// For the OpenAI-compatible connector, fetch models directly
				// from the endpoint rather than going through the SDK model
				// directory (which can fail due to SDK transporter issues).
				// Mirrors the carve-out in SettingsController::handle_providers().
				if ( str_starts_with( $provider_id, 'ai-provider-for-any-openai-compatible' )
					&& function_exists( 'OpenAiCompatibleConnector\\rest_list_models' )
				) {
					$fake_request = new \WP_REST_Request( 'GET' );
					$fake_request->set_param( 'provider_id', $provider_id );
					$result = \OpenAiCompatibleConnector\rest_list_models( $fake_request );
					if ( ! is_wp_error( $result ) ) {
						$data = $result->get_data();
						if ( is_array( $data ) ) {
							foreach ( $data as $model_entry ) {
								if ( is_array( $model_entry ) && isset( $model_entry['id'] ) ) {
									$models[] = (string) $model_entry['id'];
								}
							}
						}
					}
				} else {
					$directory      = $class::modelMetadataDirectory();
					$model_metadata = $directory->listModelMetadata();
					foreach ( $model_metadata as $model_meta ) {
						$models[] = (string) $model_meta->getId();
					}
				}

				$registered[ (string) $provider_id ] = $models;
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		return $registered;
	}

	/**
	 * Whether `$registered` advertises `$model_id` under `$provider_id`.
	 *
	 * When `$provider_id` is empty the check passes if ANY authenticated
	 * provider advertises the model — the saved-provider hint is allowed
	 * to be missing without forcing the user back to picking from scratch.
	 *
	 * @param string                            $provider_id Saved provider ID hint (may be '').
	 * @param string                            $model_id    Saved model ID to verify.
	 * @param array<string, array<int, string>> $registered  Map from {@see collect_registered_provider_models()}.
	 * @return bool
	 */
	private static function pair_is_registered( string $provider_id, string $model_id, array $registered ): bool {
		if ( '' === $model_id ) {
			return false;
		}

		if ( '' !== $provider_id ) {
			$models = $registered[ $provider_id ] ?? null;
			return is_array( $models ) && in_array( $model_id, $models, true );
		}

		foreach ( $registered as $models ) {
			if ( in_array( $model_id, $models, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the WP AI Client SDK registry has an authenticated provider
	 * that advertises `$model_id`.
	 *
	 * Defense-in-depth check intended for callers that take a model ID from
	 * a source other than {@see get_default_model()} — most notably the
	 * OpenAI-compatible connector's `\OpenAiCompatibleConnector\get_default_model()`
	 * legacy fallback in {@see \SdAiAgent\Core\AgentLoop::configure_model()}.
	 *
	 * When `$provider_id` is non-empty the check is constrained to that
	 * provider's advertised models. When empty, ANY authenticated provider's
	 * model list satisfies the check.
	 *
	 * Returns `true` when the registry is not consultable (WP < 7.0 without
	 * polyfill) so callers degrade gracefully back to historical behaviour
	 * rather than incorrectly rejecting a value that the SDK would have
	 * accepted on a supported runtime.
	 *
	 * @param string $provider_id Provider ID hint (may be '').
	 * @param string $model_id    Model ID to validate.
	 */
	public static function is_model_advertised( string $provider_id, string $model_id ): bool {
		if ( '' === $model_id ) {
			return false;
		}
		if ( ! self::can_validate_against_registry() ) {
			return true;
		}
		$registered = self::collect_registered_provider_models();
		return self::pair_is_registered( $provider_id, $model_id, $registered );
	}

	/**
	 * Return the first provider in `$registered` that advertises `$model_id`,
	 * or `null` when no authenticated provider advertises it.
	 *
	 * @param string                            $model_id   Model ID to search for.
	 * @param array<string, array<int, string>> $registered Map from {@see collect_registered_provider_models()}.
	 */
	private static function find_provider_for_model( string $model_id, array $registered ): ?string {
		if ( '' === $model_id ) {
			return null;
		}
		foreach ( $registered as $provider_id => $models ) {
			if ( in_array( $model_id, $models, true ) ) {
				return (string) $provider_id;
			}
		}
		return null;
	}

	/**
	 * Record a one-time admin notice for a rejected default-model value.
	 *
	 * Stored in {@see INVALID_DEFAULT_NOTICE_OPTION}. The
	 * {@see \SdAiAgent\Admin\DefaultModelNoticeHandler} renders it on the
	 * next admin page load and clears it when the user dismisses the notice.
	 *
	 * Repeated calls for the same rejected value are coalesced — the option
	 * holds the latest replacement so the notice always reflects what the
	 * resolver is currently doing rather than a stale earlier substitution.
	 *
	 * @param string $rejected_provider    Saved provider ID that no longer validates.
	 * @param string $rejected_model       Saved model ID that no longer validates.
	 * @param string $replacement_provider Provider chosen by the resolver (may be '').
	 * @param string $replacement_model    Model chosen by the resolver (may be '').
	 */
	private static function record_invalid_default_notice(
		string $rejected_provider,
		string $rejected_model,
		string $replacement_provider,
		string $replacement_model
	): void {
		update_option(
			self::INVALID_DEFAULT_NOTICE_OPTION,
			array(
				'provider'             => $rejected_provider,
				'model'                => $rejected_model,
				'replacement_provider' => $replacement_provider,
				'replacement_model'    => $replacement_model,
				'recorded_at'          => time(),
			),
			false
		);
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
