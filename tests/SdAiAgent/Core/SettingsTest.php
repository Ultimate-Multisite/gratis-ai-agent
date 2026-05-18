<?php
/**
 * Test case for Settings class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\ModelCapabilityRegistry;
use SdAiAgent\Core\Settings;
use WP_UnitTestCase;

/**
 * Test double used to satisfy Settings::maybe_auto_enable_woo_abilities().
 */
final class WooCommerceTestDouble {}

/**
 * Test Settings functionality.
 */
class SettingsTest extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		parent::tear_down();
		delete_option( Settings::OPTION_NAME );
		delete_option( Settings::WOO_AUTO_ENABLED_OPTION );
		delete_option( Settings::INVALID_DEFAULT_NOTICE_OPTION );

		// Filter registered by the default-model validation tests.
		remove_all_filters( 'sd_ai_agent_registered_models_for_validation' );
		remove_all_filters( 'sd_ai_agent_default_model' );

		// ModelCapabilityRegistry writes transients keyed by md5(model_id);
		// clear the handful this suite touches so cases stay independent.
		foreach (
			array(
				'gpt-4o',
				'hf:moonshotai/Kimi-K2.6',
				'hf:moonshotai/Kimi-K2.7',
				'wholly-made-up-model-9000',
			) as $model_id
		) {
			ModelCapabilityRegistry::forget( $model_id );
		}
	}

	/**
	 * Test get_defaults returns expected keys.
	 */
	public function test_get_defaults_returns_expected_keys() {
		$defaults = Settings::instance()->get_defaults();

		$this->assertIsArray( $defaults );
		$this->assertArrayHasKey( 'default_provider', $defaults );
		$this->assertArrayHasKey( 'default_model', $defaults );
		$this->assertArrayHasKey( 'max_iterations', $defaults );
		$this->assertArrayHasKey( 'greeting_message', $defaults );
		$this->assertArrayHasKey( 'system_prompt', $defaults );
		$this->assertArrayHasKey( 'auto_memory', $defaults );
		$this->assertArrayHasKey( 'temperature', $defaults );
		$this->assertArrayHasKey( 'max_output_tokens', $defaults );
		$this->assertArrayHasKey( 'max_history_turns', $defaults );
	}

	/**
	 * Test get_defaults returns expected default values.
	 */
	public function test_get_defaults_returns_expected_values() {
		$defaults = Settings::instance()->get_defaults();

		$this->assertSame( 100, $defaults['max_iterations'] );
		$this->assertSame( true, $defaults['auto_memory'] );
		$this->assertSame( 0.2, $defaults['temperature'] );
		// 0 is the "auto / per-model" sentinel — see Settings::MAX_OUTPUT_TOKENS_AUTO
		// and Settings::get_max_output_tokens_for_model(). Was 4096 until sd-ai-7rl.
		$this->assertSame( 0, $defaults['max_output_tokens'] );
		$this->assertSame( 20, $defaults['max_history_turns'] );
	}

	/**
	 * Test get returns all settings merged with defaults.
	 */
	public function test_get_returns_merged_settings() {
		$settings = Settings::instance()->get();

		$this->assertIsArray( $settings );
		$this->assertArrayHasKey( 'max_iterations', $settings );
		$this->assertArrayHasKey( 'temperature', $settings );
	}

	/**
	 * Test get returns single setting when key provided.
	 */
	public function test_get_returns_single_setting() {
		$max_iterations = Settings::instance()->get( 'max_iterations' );

		$this->assertSame( 100, $max_iterations );
	}

	/**
	 * Test get returns null for unknown key.
	 */
	public function test_get_returns_null_for_unknown_key() {
		$result = Settings::instance()->get( 'nonexistent_setting' );

		$this->assertNull( $result );
	}

	/**
	 * Test update saves settings.
	 */
	public function test_update_saves_settings() {
		$result = Settings::instance()->update( [ 'max_iterations' => 50 ] );

		$this->assertTrue( $result );
		$this->assertSame( 50, Settings::instance()->get( 'max_iterations' ) );
	}

	/**
	 * Test update only allows known keys.
	 */
	public function test_update_only_allows_known_keys() {
		Settings::instance()->update( [ 'unknown_key' => 'test_value' ] );

		$settings = Settings::instance()->get();
		$this->assertArrayNotHasKey( 'unknown_key', $settings );
	}

	/**
	 * Test update merges with existing settings.
	 */
	public function test_update_merges_with_existing_settings() {
		Settings::instance()->update( [ 'max_iterations' => 30 ] );
		Settings::instance()->update( [ 'temperature' => 0.5 ] );

		$this->assertSame( 30, Settings::instance()->get( 'max_iterations' ) );
		$this->assertSame( 0.5, Settings::instance()->get( 'temperature' ) );
	}

	/**
	 * Test OPTION_NAME constant.
	 */
	public function test_option_name_constant() {
		$this->assertSame( 'sd_ai_agent_settings', Settings::OPTION_NAME );
	}

	/**
	 * Test tool_permissions default is empty array.
	 */
	public function test_tool_permissions_default_is_empty_array() {
		$defaults = Settings::instance()->get_defaults();

		$this->assertIsArray( $defaults['tool_permissions'] );
		$this->assertEmpty( $defaults['tool_permissions'] );
	}

	/**
	 * Test update can save array values (using tool_permissions, the
	 * remaining curated ability-gating setting).
	 */
	public function test_update_can_save_array_values() {
		$perms = [ 'tool1' => 'disabled', 'tool2' => 'confirm' ];
		Settings::instance()->update( [ 'tool_permissions' => $perms ] );

		$result = Settings::instance()->get( 'tool_permissions' );
		$this->assertSame( $perms, $result );
	}

	/**
	 * Test WooCommerce auto-enable grants native WooCommerce REST abilities.
	 */
	public function test_maybe_auto_enable_woo_abilities_grants_native_woocommerce_abilities(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			class_alias( WooCommerceTestDouble::class, 'WooCommerce' );
		}

		Settings::instance()->update(
			[
				'default_provider'  => 'openai',
				'tool_permissions' => [ 'sd-ai-agent/create-post' => 'confirm' ],
			]
		);

		Settings::instance()->maybe_auto_enable_woo_abilities();

		$permissions = Settings::instance()->get( 'tool_permissions' );
		$this->assertSame( 'confirm', $permissions['sd-ai-agent/create-post'] );
		$this->assertSame( 'auto', $permissions['woocommerce/products-list'] );
		$this->assertSame( 'auto', $permissions['woocommerce/products-create'] );
		$this->assertSame( 'auto', $permissions['woocommerce/orders-list'] );
		$this->assertArrayNotHasKey( 'sd-ai-agent/woo-get-products', $permissions );
		$this->assertTrue( (bool) get_option( Settings::WOO_AUTO_ENABLED_OPTION ) );
	}

	// ─────────────────────────────────────────────────────────────────────
	// get_max_output_tokens_for_model() — sd-ai-7rl
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Empty model id falls back to the safe default, not 0.
	 */
	public function test_max_output_tokens_for_empty_model_returns_fallback(): void {
		$this->assertSame(
			Settings::MAX_OUTPUT_TOKENS_FALLBACK,
			Settings::get_max_output_tokens_for_model( '' )
		);
	}

	/**
	 * Unknown model id falls back to the safe default rather than 0 or the
	 * ceiling — protects against the SDK refusing requests when a third-party
	 * connector advertises an ID we don't catalogue.
	 */
	public function test_max_output_tokens_for_unknown_model_returns_fallback(): void {
		$this->assertSame(
			Settings::MAX_OUTPUT_TOKENS_FALLBACK,
			Settings::get_max_output_tokens_for_model( 'wholly-made-up-model-9000' )
		);
	}

	/**
	 * Exact-match Claude Opus 4.x resolves to its documented family caps.
	 *
	 * Originally guarded the session-16 regression (claude-opus-4-7 silently
	 * using the 4096 default and truncating tool_use input JSON mid-payload).
	 * Now also locks in the post-#1448 catalog values where newer Opus point
	 * releases get the larger advertised caps (128K) and older 4.0/4.1 stay
	 * at 32K. Newer point releases keep higher caps than older ones in the
	 * same family, so the entries are NOT collapsed into a single
	 * `claude-opus-4` entry.
	 */
	public function test_max_output_tokens_resolves_claude_opus_4_family(): void {
		// Bare family prefix — still 32K (Opus 4.0).
		$this->assertSame(
			32000,
			Settings::get_max_output_tokens_for_model( 'claude-opus-4' )
		);
		// Opus 4.7 documents 128K output.
		$this->assertSame(
			128000,
			Settings::get_max_output_tokens_for_model( 'claude-opus-4-7' )
		);
		// Dated variants must longest-prefix-match the 4.7 entry, not the
		// bare `claude-opus-4` one.
		$this->assertSame(
			128000,
			Settings::get_max_output_tokens_for_model( 'claude-opus-4-7-20260513' )
		);
		// Opus 4.5 sits between: 64K documented cap.
		$this->assertSame(
			64000,
			Settings::get_max_output_tokens_for_model( 'claude-opus-4-5' )
		);
		// Opus 4.1 stayed at 32K.
		$this->assertSame(
			32000,
			Settings::get_max_output_tokens_for_model( 'claude-opus-4-1' )
		);
	}

	/**
	 * GPT-4.x and o-series resolve via longest-prefix matching.
	 *
	 * Locks in the post-#1448 catalog: GPT-5 documents 128K, GPT-4.1 32,768,
	 * GPT-4o 16,384, o1/o3/o4 all document a 100K envelope (which includes
	 * reasoning tokens).
	 */
	public function test_max_output_tokens_resolves_openai_families(): void {
		$this->assertSame( 128000, Settings::get_max_output_tokens_for_model( 'gpt-5' ) );
		$this->assertSame( 128000, Settings::get_max_output_tokens_for_model( 'gpt-5-mini' ) );
		$this->assertSame( 32768, Settings::get_max_output_tokens_for_model( 'gpt-4.1' ) );
		$this->assertSame( 32768, Settings::get_max_output_tokens_for_model( 'gpt-4.1-mini' ) );
		$this->assertSame( 32768, Settings::get_max_output_tokens_for_model( 'gpt-4.1-nano' ) );
		$this->assertSame( 16384, Settings::get_max_output_tokens_for_model( 'gpt-4o' ) );
		$this->assertSame( 16384, Settings::get_max_output_tokens_for_model( 'gpt-4o-mini' ) );
		$this->assertSame( 100000, Settings::get_max_output_tokens_for_model( 'o3-mini' ) );
		$this->assertSame( 100000, Settings::get_max_output_tokens_for_model( 'o4-mini' ) );
	}

	/**
	 * Gemini families resolve to documented caps. Post-#1448, both 2.5 Pro
	 * and 2.5 Flash document a 65,535 max output. Older 2.0 and 1.5 stay
	 * conservatively at 8K.
	 */
	public function test_max_output_tokens_resolves_gemini_families(): void {
		$this->assertSame( 65535, Settings::get_max_output_tokens_for_model( 'gemini-2.5-pro' ) );
		$this->assertSame( 65535, Settings::get_max_output_tokens_for_model( 'gemini-2.5-flash' ) );
		$this->assertSame( 8192, Settings::get_max_output_tokens_for_model( 'gemini-2.0-flash' ) );
		$this->assertSame( 8192, Settings::get_max_output_tokens_for_model( 'gemini-1.5-pro' ) );
	}

	/**
	 * Synthetic-hosted HuggingFace models resolve to their per-model caps,
	 * verified against the live Synthetic `/openai/v1/models` payload. The
	 * broad `hf:` fallback catches unknown models at 32K (conservative
	 * relative to the Synthetic-typical 65K, but safe).
	 */
	public function test_max_output_tokens_resolves_synthetic_hf_models(): void {
		// Explicit catalog entries.
		$this->assertSame( 65536, Settings::get_max_output_tokens_for_model( 'hf:moonshotai/Kimi-K2.6' ) );
		$this->assertSame( 32768, Settings::get_max_output_tokens_for_model( 'hf:moonshotai/Kimi-K2.5' ) );
		$this->assertSame( 65536, Settings::get_max_output_tokens_for_model( 'hf:zai-org/GLM-5.1' ) );
		// Unknown hf: model falls through to the broad prefix.
		$this->assertSame( 32768, Settings::get_max_output_tokens_for_model( 'hf:unknown/random-model' ) );
	}

	/**
	 * The filter can raise or lower the value, but the result is clamped to
	 * [1, MAX_OUTPUT_TOKENS_CEILING]. Bad filter returns fall back to the
	 * safe default.
	 */
	public function test_max_output_tokens_filter_is_clamped(): void {
		add_filter(
			'sd_ai_agent_max_output_tokens_for_model',
			static function () {
				return 9999999;
			}
		);
		$this->assertSame(
			Settings::MAX_OUTPUT_TOKENS_CEILING,
			Settings::get_max_output_tokens_for_model( 'gpt-4o' )
		);
		remove_all_filters( 'sd_ai_agent_max_output_tokens_for_model' );

		add_filter(
			'sd_ai_agent_max_output_tokens_for_model',
			static function () {
				return -50;
			}
		);
		$this->assertSame(
			Settings::MAX_OUTPUT_TOKENS_FALLBACK,
			Settings::get_max_output_tokens_for_model( 'gpt-4o' )
		);
		remove_all_filters( 'sd_ai_agent_max_output_tokens_for_model' );
	}

	// ─────────────────────────────────────────────────────────────────────
	// ModelCapabilityRegistry precedence — sd-ai-2zf
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * A live provider entry overrides the static catalog. Kimi K2.6 is in
	 * the catalog at 65536, but if Synthetic later raises the cap to 131072
	 * the registry must reflect the new value without a code change.
	 */
	public function test_registry_entry_overrides_static_catalog(): void {
		// Verify catalog baseline.
		$this->assertSame(
			65536,
			Settings::get_max_output_tokens_for_model( 'hf:moonshotai/Kimi-K2.6' )
		);

		// Provider /models response says 131072 — that wins.
		ModelCapabilityRegistry::set( 'hf:moonshotai/Kimi-K2.6', 131072, 200000 );

		$this->assertSame(
			131072,
			Settings::get_max_output_tokens_for_model( 'hf:moonshotai/Kimi-K2.6' )
		);
	}

	/**
	 * A registry value above {@see Settings::MAX_OUTPUT_TOKENS_CEILING} is
	 * still clamped by the resolver. Defends against a misreported provider
	 * payload (e.g. context length surfaced as max_output_length).
	 */
	public function test_registry_value_above_ceiling_is_clamped(): void {
		ModelCapabilityRegistry::set( 'gpt-4o', 999999, 0 );

		$this->assertSame(
			Settings::MAX_OUTPUT_TOKENS_CEILING,
			Settings::get_max_output_tokens_for_model( 'gpt-4o' )
		);
	}

	/**
	 * A model not in the catalog gets the registry value when one is
	 * cached, and falls back to {@see Settings::MAX_OUTPUT_TOKENS_FALLBACK}
	 * only when there is neither a catalog match nor a registry entry.
	 */
	public function test_registry_promotes_unknown_model_above_fallback(): void {
		// Baseline: unknown HF model resolves via the broad `hf:` prefix
		// (32768) — confirming the catalog hit path is intact.
		$this->assertSame(
			32768,
			Settings::get_max_output_tokens_for_model( 'hf:moonshotai/Kimi-K2.7' )
		);

		// Provider response advertises 200000 — registry wins.
		ModelCapabilityRegistry::set( 'hf:moonshotai/Kimi-K2.7', 200000, 256000 );

		// Resolver result is the registry value, clamped to ceiling.
		$this->assertSame(
			Settings::MAX_OUTPUT_TOKENS_CEILING,
			Settings::get_max_output_tokens_for_model( 'hf:moonshotai/Kimi-K2.7' )
		);
	}

	/**
	 * The `sd_ai_agent_max_output_tokens_for_model` filter still wins over
	 * the registry — deployments must be able to pin a value no matter what
	 * the provider advertises (e.g. cost control, output truncation tests).
	 */
	public function test_filter_overrides_registry(): void {
		ModelCapabilityRegistry::set( 'hf:moonshotai/Kimi-K2.6', 131072, 200000 );

		add_filter(
			'sd_ai_agent_max_output_tokens_for_model',
			static function ( $value, $model_id ) {
				return 'hf:moonshotai/Kimi-K2.6' === $model_id ? 16384 : $value;
			},
			10,
			2
		);

		$this->assertSame(
			16384,
			Settings::get_max_output_tokens_for_model( 'hf:moonshotai/Kimi-K2.6' )
		);

		remove_all_filters( 'sd_ai_agent_max_output_tokens_for_model' );
	}

	/**
	 * resolve_max_output_tokens_from_catalog() is the pure static-catalog
	 * lookup — used by the registry to consult the catalog without
	 * re-entering the filterable resolver. Empty model id returns 0;
	 * unknown model returns 0; known model returns the catalog value
	 * unclamped (the resolver clamps).
	 */
	public function test_resolve_from_catalog_is_pure_lookup(): void {
		$this->assertSame( 0, Settings::resolve_max_output_tokens_from_catalog( '' ) );
		$this->assertSame( 0, Settings::resolve_max_output_tokens_from_catalog( 'wholly-made-up-model-9000' ) );
		$this->assertSame( 16384, Settings::resolve_max_output_tokens_from_catalog( 'gpt-4o' ) );
		$this->assertSame( 128000, Settings::resolve_max_output_tokens_from_catalog( 'claude-opus-4-7-20260513' ) );
	}

	// ─────────────────────────────────────────────────────────────────────
	// get_default_model() / get_default_provider() validation — GH#1494
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Short-circuit the registry walk with a fixed (provider → models) map.
	 *
	 * @param array<string, array<int, string>> $map Provider ID → list of advertised model IDs.
	 */
	private function fake_registry( array $map ): void {
		add_filter(
			'sd_ai_agent_registered_models_for_validation',
			static function () use ( $map ) {
				return $map;
			}
		);
	}

	/**
	 * When the saved (provider, model) pair is advertised by an authenticated
	 * provider, the resolver returns it verbatim and does NOT record a notice.
	 */
	public function test_get_default_model_returns_saved_value_when_advertised(): void {
		$this->fake_registry( array(
			'anthropic' => array( 'claude-sonnet-4-6', 'claude-opus-4-7' ),
			'openai'    => array( 'gpt-4o', 'gpt-5' ),
		) );

		Settings::instance()->update(
			array(
				'default_provider' => 'openai',
				'default_model'    => 'gpt-4o',
			)
		);

		$this->assertSame( 'gpt-4o', Settings::instance()->get_default_model() );
		$this->assertSame( 'openai', Settings::instance()->get_default_provider() );
		$this->assertFalse( (bool) get_option( Settings::INVALID_DEFAULT_NOTICE_OPTION ) );
	}

	/**
	 * Production regression test — GH#1494.
	 *
	 * When the saved value is a bogus `(provider, model)` pair (the demo
	 * site's `gemma4:e4b` situation), the resolver MUST fall back to a
	 * registered model rather than letting the SDK reject the request with
	 * "model not available". A notice is recorded for the admin to see.
	 */
	public function test_get_default_model_falls_back_when_saved_value_unregistered(): void {
		$this->fake_registry( array(
			'anthropic' => array( 'claude-sonnet-4', 'claude-sonnet-4-6' ),
		) );

		Settings::instance()->update(
			array(
				'default_provider' => 'some-bogus-provider',
				'default_model'    => 'gemma4:e4b',
			)
		);

		// SD_AI_AGENT_DEFAULT_MODEL is `claude-sonnet-4`, which the fake
		// registry advertises under anthropic — the resolver picks it.
		$this->assertSame( 'claude-sonnet-4', Settings::instance()->get_default_model() );
		$this->assertSame( 'anthropic', Settings::instance()->get_default_provider() );

		$notice = get_option( Settings::INVALID_DEFAULT_NOTICE_OPTION );
		$this->assertIsArray( $notice );
		$this->assertSame( 'gemma4:e4b', $notice['model'] );
		$this->assertSame( 'some-bogus-provider', $notice['provider'] );
		$this->assertSame( 'claude-sonnet-4', $notice['replacement_model'] );
		$this->assertSame( 'anthropic', $notice['replacement_provider'] );
	}

	/**
	 * When the constant default is not advertised either, the resolver falls
	 * through to the first model of the first authenticated provider.
	 */
	public function test_get_default_model_falls_through_to_first_registered_model(): void {
		$this->fake_registry( array(
			'ai-provider-for-any-openai-compatible' => array(
				'hf:moonshotai/Kimi-K2.6',
				'hf:zai-org/GLM-5.1',
			),
		) );

		Settings::instance()->update(
			array(
				'default_provider' => 'gone-provider',
				'default_model'    => 'gone:model',
			)
		);

		$this->assertSame(
			'hf:moonshotai/Kimi-K2.6',
			Settings::instance()->get_default_model()
		);
		$this->assertSame(
			'ai-provider-for-any-openai-compatible',
			Settings::instance()->get_default_provider()
		);
	}

	/**
	 * When the saved provider is still authenticated but its specific saved
	 * model has been retired, the resolver keeps the provider stable and
	 * picks the constant default model when that provider advertises it.
	 */
	public function test_get_default_model_keeps_saved_provider_when_only_model_is_invalid(): void {
		$this->fake_registry( array(
			'anthropic' => array( 'claude-sonnet-4', 'claude-opus-4-7' ),
			'openai'    => array( 'gpt-5' ),
		) );

		Settings::instance()->update(
			array(
				'default_provider' => 'anthropic',
				'default_model'    => 'claude-was-retired-9000',
			)
		);

		$this->assertSame( 'claude-sonnet-4', Settings::instance()->get_default_model() );
		$this->assertSame( 'anthropic', Settings::instance()->get_default_provider() );
	}

	/**
	 * The `sd_ai_agent_default_model` filter override is itself validated
	 * before being returned. A filter that pins an unregistered model falls
	 * through to the next candidate rather than re-introducing the bug the
	 * filter is meant to fix.
	 */
	public function test_get_default_model_validates_filter_override(): void {
		$this->fake_registry( array(
			'anthropic' => array( 'claude-sonnet-4' ),
		) );

		add_filter(
			'sd_ai_agent_default_model',
			static fn() => 'gpt-5-not-installed-yet',
		);

		// Empty saved value — filter would normally be returned verbatim.
		$this->assertSame( 'claude-sonnet-4', Settings::instance()->get_default_model() );
	}

	/**
	 * Empty saved value (clean install) returns `''` for the model — the
	 * chat path then falls through to the SDK's per-provider default. The
	 * provider hint is co-resolved so the chat-path still gets a usable
	 * `default_provider`. No notice is recorded because an empty saved
	 * value is not a rejection.
	 *
	 * Regression test for the design choice in GH#1494: substituting the
	 * `SD_AI_AGENT_DEFAULT_MODEL` constant when no user preference was ever
	 * expressed would pin every install to a fixed model ID even when the
	 * provider supports something newer in the same family.
	 */
	public function test_get_default_model_empty_saved_returns_empty_with_provider_hint(): void {
		$this->fake_registry( array(
			'anthropic' => array( 'claude-sonnet-4-6', 'claude-opus-4-7' ),
		) );

		// No update() call — saved value is the empty default.
		$this->assertSame( '', Settings::instance()->get_default_model() );
		$this->assertSame( 'anthropic', Settings::instance()->get_default_provider() );
		$this->assertFalse( (bool) get_option( Settings::INVALID_DEFAULT_NOTICE_OPTION ) );
	}

	/**
	 * Saved provider but empty model — keep the saved provider and let the
	 * SDK pick a model for that provider. Notice is NOT recorded (empty
	 * saved model is not a rejection).
	 */
	public function test_get_default_provider_keeps_saved_provider_when_model_is_empty(): void {
		$this->fake_registry( array(
			'anthropic' => array( 'claude-sonnet-4-6' ),
			'openai'    => array( 'gpt-5' ),
		) );

		Settings::instance()->update( array( 'default_provider' => 'openai' ) );

		$this->assertSame( '', Settings::instance()->get_default_model() );
		$this->assertSame( 'openai', Settings::instance()->get_default_provider() );
		$this->assertFalse( (bool) get_option( Settings::INVALID_DEFAULT_NOTICE_OPTION ) );
	}

	/**
	 * When the registry reports no authenticated providers at all, both
	 * resolvers return '' so the AgentLoop short-circuits to the "no provider
	 * configured" error rather than sending an unusable request to the SDK.
	 *
	 * A notice IS recorded when a saved value was rejected so the admin
	 * sees why the dropdown selection no longer applies.
	 */
	public function test_get_default_model_empty_registry_returns_empty(): void {
		$this->fake_registry( array() );

		Settings::instance()->update(
			array(
				'default_provider' => 'gone-provider',
				'default_model'    => 'gone:model',
			)
		);

		$this->assertSame( '', Settings::instance()->get_default_model() );
		$this->assertSame( '', Settings::instance()->get_default_provider() );

		$notice = get_option( Settings::INVALID_DEFAULT_NOTICE_OPTION );
		$this->assertIsArray( $notice );
		$this->assertSame( '', $notice['replacement_model'] );
	}

	/**
	 * `is_model_advertised()` is the public defense-in-depth helper used by
	 * {@see \SdAiAgent\Core\AgentLoop::configure_model()} to validate the
	 * legacy OpenAI-compatible connector default before adopting it. The
	 * GH#1494 demo regression came in via that path even though the saved
	 * `sd_ai_agent_settings.default_model` was empty.
	 */
	public function test_is_model_advertised_validates_pair(): void {
		$this->fake_registry( array(
			'openai' => array( 'gpt-4o' ),
		) );

		$this->assertTrue( Settings::is_model_advertised( 'openai', 'gpt-4o' ) );
		$this->assertTrue( Settings::is_model_advertised( '', 'gpt-4o' ) );
		$this->assertFalse( Settings::is_model_advertised( 'openai', 'gemma4:e4b' ) );
		$this->assertFalse( Settings::is_model_advertised( 'anthropic', 'gpt-4o' ) );
		$this->assertFalse( Settings::is_model_advertised( 'openai', '' ) );
	}
}
