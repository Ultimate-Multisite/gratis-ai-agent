<?php
/**
 * Tests for SdAiAgent\Core\ModelCapabilityRegistry — sd-ai-2zf.
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
 * Per-model capability registry: transient store, catalog fallback, and
 * source markers.
 */
class ModelCapabilityRegistryTest extends WP_UnitTestCase {

	private const TEST_MODELS = array(
		'hf:moonshotai/Kimi-K2.6',
		'hf:moonshotai/Kimi-K2.7',
		'gpt-4o',
		'unknown-provider-model-zzz',
	);

	/**
	 * Forget any per-test registry writes so cases stay independent.
	 */
	public function tear_down(): void {
		parent::tear_down();
		foreach ( self::TEST_MODELS as $model_id ) {
			ModelCapabilityRegistry::forget( $model_id );
		}
	}

	/**
	 * set() round-trips through get() with the provider source marker and
	 * a positive fetched_at timestamp.
	 */
	public function test_set_then_get_round_trip(): void {
		$before = time();
		$ok     = ModelCapabilityRegistry::set( 'hf:moonshotai/Kimi-K2.6', 131072, 200000 );
		$this->assertTrue( $ok );

		$entry = ModelCapabilityRegistry::get( 'hf:moonshotai/Kimi-K2.6' );

		$this->assertSame( 'hf:moonshotai/Kimi-K2.6', $entry['model_id'] );
		$this->assertSame( 131072, $entry['max_output_tokens'] );
		$this->assertSame( 200000, $entry['context_length'] );
		$this->assertSame( ModelCapabilityRegistry::SOURCE_PROVIDER, $entry['source'] );
		$this->assertGreaterThanOrEqual( $before, $entry['fetched_at'] );
	}

	/**
	 * Bad inputs (empty model id, zero/negative max_output) return false and
	 * write nothing.
	 */
	public function test_set_rejects_bad_inputs(): void {
		$this->assertFalse( ModelCapabilityRegistry::set( '', 100, 0 ) );
		$this->assertFalse( ModelCapabilityRegistry::set( 'gpt-4o', 0, 0 ) );
		$this->assertFalse( ModelCapabilityRegistry::set( 'gpt-4o', -1, 0 ) );

		// get_max_output_tokens returns the fallback (no transient written).
		$this->assertSame(
			Settings::MAX_OUTPUT_TOKENS_FALLBACK,
			ModelCapabilityRegistry::get_max_output_tokens( 'unknown-provider-model-zzz' )
		);
	}

	/**
	 * Empty model id is normalised to the fallback in both accessors.
	 */
	public function test_empty_model_id_uses_fallback(): void {
		$this->assertSame(
			Settings::MAX_OUTPUT_TOKENS_FALLBACK,
			ModelCapabilityRegistry::get_max_output_tokens( '' )
		);

		$entry = ModelCapabilityRegistry::get( '' );
		$this->assertSame( 0, $entry['max_output_tokens'] );
		$this->assertSame( ModelCapabilityRegistry::SOURCE_FALLBACK, $entry['source'] );
	}

	/**
	 * With no transient written, get() falls back to the static catalog and
	 * reports SOURCE_CATALOG so callers can tell live data from seeded data.
	 */
	public function test_get_falls_through_to_catalog(): void {
		// Ensure no transient exists.
		ModelCapabilityRegistry::forget( 'gpt-4o' );

		$entry = ModelCapabilityRegistry::get( 'gpt-4o' );

		$this->assertSame( 16384, $entry['max_output_tokens'] );
		$this->assertSame( ModelCapabilityRegistry::SOURCE_CATALOG, $entry['source'] );
		$this->assertSame( 0, $entry['fetched_at'] );
	}

	/**
	 * Unknown model id (no transient, no catalog match) reports
	 * SOURCE_FALLBACK and zero tokens.
	 */
	public function test_get_returns_fallback_for_unknown_model(): void {
		$entry = ModelCapabilityRegistry::get( 'unknown-provider-model-zzz' );

		$this->assertSame( 'unknown-provider-model-zzz', $entry['model_id'] );
		$this->assertSame( 0, $entry['max_output_tokens'] );
		$this->assertSame( ModelCapabilityRegistry::SOURCE_FALLBACK, $entry['source'] );

		// But the int accessor still gives callers a safe non-zero value.
		$this->assertSame(
			Settings::MAX_OUTPUT_TOKENS_FALLBACK,
			ModelCapabilityRegistry::get_max_output_tokens( 'unknown-provider-model-zzz' )
		);
	}

	/**
	 * forget() removes a previously written entry and returns true; a second
	 * forget() returns false because nothing is left to delete.
	 */
	public function test_forget_clears_entry(): void {
		ModelCapabilityRegistry::set( 'gpt-4o', 32768, 128000 );
		$this->assertSame( 32768, ModelCapabilityRegistry::get_max_output_tokens( 'gpt-4o' ) );

		$this->assertTrue( ModelCapabilityRegistry::forget( 'gpt-4o' ) );
		$this->assertFalse( ModelCapabilityRegistry::forget( 'gpt-4o' ) );

		// After forget, lookup falls through to the catalog (gpt-4o catalog
		// value is 16384).
		$this->assertSame( 16384, ModelCapabilityRegistry::get_max_output_tokens( 'gpt-4o' ) );
	}

	/**
	 * transient_key() produces the documented prefix + md5(model_id) shape.
	 * Tests rely on this for invalidation and inspection.
	 */
	public function test_transient_key_shape(): void {
		$key = ModelCapabilityRegistry::transient_key( 'hf:moonshotai/Kimi-K2.6' );
		$this->assertSame(
			ModelCapabilityRegistry::TRANSIENT_PREFIX . md5( 'hf:moonshotai/Kimi-K2.6' ),
			$key
		);
	}
}
