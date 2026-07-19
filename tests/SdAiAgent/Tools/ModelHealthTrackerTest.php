<?php
/**
 * Test case for the ModelHealthTracker.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Tools;

use SdAiAgent\Tools\ModelHealthTracker;
use WP_UnitTestCase;

class ModelHealthTrackerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		ModelHealthTracker::reset();
	}

	public function tear_down(): void {
		parent::tear_down();
		ModelHealthTracker::reset();
	}

	// ─── name heuristic ───────────────────────────────────────────────

	public function test_name_heuristic_flags_small_open_models(): void {
		$this->assertTrue( ModelHealthTracker::matches_weak_name( 'hf:moonshotai/Kimi-K2-Instruct-0905' ) === false, 'Kimi K2 is not flagged purely by name (no -7b/-8b token).' );

		$this->assertTrue( ModelHealthTracker::matches_weak_name( 'meta-llama/Meta-Llama-3-8b' ) );
		$this->assertTrue( ModelHealthTracker::matches_weak_name( 'mistral-7b-instruct' ) );
		$this->assertTrue( ModelHealthTracker::matches_weak_name( 'gemma-2-9b' ) );
		$this->assertTrue( ModelHealthTracker::matches_weak_name( 'phi-3-mini' ) );
		$this->assertTrue( ModelHealthTracker::matches_weak_name( 'tinyllama' ) );
		$this->assertTrue( ModelHealthTracker::matches_weak_name( 'mistral-7b-q4_k_m.gguf' ) );
		$this->assertTrue( ModelHealthTracker::matches_weak_name( 'gpt-5.6-luna' ) );
	}

	public function test_name_heuristic_does_not_flag_strong_models(): void {
		$this->assertFalse( ModelHealthTracker::matches_weak_name( 'claude-sonnet-4-6' ) );
		$this->assertFalse( ModelHealthTracker::matches_weak_name( 'claude-opus-4-6' ) );
		$this->assertFalse( ModelHealthTracker::matches_weak_name( 'gpt-4o' ) );
		$this->assertFalse( ModelHealthTracker::matches_weak_name( 'gemini-2.5-pro' ) );
		$this->assertFalse( ModelHealthTracker::matches_weak_name( 'meta-llama/Meta-Llama-3.1-405b-Instruct' ) );
	}

	public function test_empty_model_id_is_not_weak(): void {
		$this->assertFalse( ModelHealthTracker::matches_weak_name( '' ) );
		$this->assertFalse( ModelHealthTracker::is_weak( '' ) );
	}

	// ─── recording ────────────────────────────────────────────────────

	public function test_record_requires_current_model_to_be_set(): void {
		// No current model → no-op.
		ModelHealthTracker::record_success();
		$this->assertNull( ModelHealthTracker::get_health( 'no-such-model' ) );
	}

	public function test_record_success_updates_current_model_health(): void {
		ModelHealthTracker::set_current_model( 'claude-sonnet-4-6' );

		ModelHealthTracker::record_success();
		ModelHealthTracker::record_success();

		$health = ModelHealthTracker::get_health( 'claude-sonnet-4-6' );
		$this->assertNotNull( $health );
		$this->assertSame( 2, $health['success'] );
		$this->assertSame( 0, $health['validation_error'] );
		$this->assertSame( 0, $health['nudge'] );
	}

	public function test_record_validation_error_and_nudge(): void {
		ModelHealthTracker::set_current_model( 'hf:weak-model' );

		ModelHealthTracker::record_validation_error();
		ModelHealthTracker::record_nudge();
		ModelHealthTracker::record_nudge();

		$health = ModelHealthTracker::get_health( 'hf:weak-model' );
		$this->assertSame( 1, $health['validation_error'] );
		$this->assertSame( 2, $health['nudge'] );
	}

	// ─── quality score ────────────────────────────────────────────────

	public function test_quality_score_is_one_when_no_history(): void {
		$this->assertSame( 1.0, ModelHealthTracker::quality_score( 'never-seen' ) );
	}

	public function test_quality_score_drops_with_failures(): void {
		ModelHealthTracker::set_current_model( 'claude-sonnet-4-6' );
		for ( $i = 0; $i < 9; $i++ ) {
			ModelHealthTracker::record_success();
		}
		ModelHealthTracker::record_validation_error();

		// 9 / (9 + 1) = 0.9
		$this->assertEqualsWithDelta( 0.9, ModelHealthTracker::quality_score( 'claude-sonnet-4-6' ), 0.001 );
	}

	public function test_quality_score_penalises_nudges_more_than_errors(): void {
		ModelHealthTracker::set_current_model( 'kimi' );
		ModelHealthTracker::record_success();
		ModelHealthTracker::record_nudge();

		// 1 / (1 + 0 + 5*1) = 0.166...
		$score = ModelHealthTracker::quality_score( 'kimi' );
		$this->assertLessThan( 0.2, $score );
	}

	// ─── is_weak() resolution ────────────────────────────────────────

	public function test_is_weak_falls_back_to_name_heuristic_below_threshold(): void {
		// 'mistral-7b' matches the name heuristic but has zero history.
		$this->assertTrue( ModelHealthTracker::is_weak( 'mistral-7b-instruct' ) );

		// Strong-named model with no history is not weak.
		$this->assertFalse( ModelHealthTracker::is_weak( 'claude-sonnet-4-6' ) );
	}

	public function test_telemetry_overrides_name_heuristic_above_threshold(): void {
		// A model with a "weak" name but consistently strong telemetry should
		// be reclassified as strong once we hit the sample threshold.
		ModelHealthTracker::set_current_model( 'mistral-7b-instruct' );
		for ( $i = 0; $i < ModelHealthTracker::MIN_SAMPLES_FOR_TELEMETRY; $i++ ) {
			ModelHealthTracker::record_success();
		}

		$this->assertFalse( ModelHealthTracker::is_weak( 'mistral-7b-instruct' ) );
	}

	public function test_telemetry_demotes_a_strong_named_model(): void {
		// And conversely: a "strong" name with bad telemetry should flip
		// to weak. (Forces the system to learn from real-world behaviour.)
		ModelHealthTracker::set_current_model( 'gpt-4o' );
		for ( $i = 0; $i < 8; $i++ ) {
			ModelHealthTracker::record_nudge();
		}
		ModelHealthTracker::record_validation_error();
		ModelHealthTracker::record_validation_error();

		$this->assertTrue( ModelHealthTracker::is_weak( 'gpt-4o' ) );
	}

	// ─── skill_load_count ─────────────────────────────────────────────

	public function test_record_skill_load_increments_count(): void {
		ModelHealthTracker::set_current_model( 'claude-sonnet-4-6' );

		ModelHealthTracker::record_skill_load();
		ModelHealthTracker::record_skill_load();
		ModelHealthTracker::record_skill_load();

		$health = ModelHealthTracker::get_health( 'claude-sonnet-4-6' );
		$this->assertNotNull( $health );
		$this->assertSame( 3, $health['skill_load_count'] );
		// skill_load_count should not affect success/validation_error/nudge.
		$this->assertSame( 0, $health['success'] );
		$this->assertSame( 0, $health['validation_error'] );
		$this->assertSame( 0, $health['nudge'] );
	}

	public function test_record_skill_load_noop_without_current_model(): void {
		// No current model set → no-op.
		ModelHealthTracker::record_skill_load();
		$this->assertNull( ModelHealthTracker::get_health( '' ) );
	}

	public function test_skill_load_count_defaults_to_zero_for_existing_records(): void {
		// Records created before t217 (missing skill_load_count key) should
		// normalize to 0 when loaded.
		ModelHealthTracker::set_current_model( 'claude-sonnet-4-6' );
		ModelHealthTracker::record_success();

		$health = ModelHealthTracker::get_health( 'claude-sonnet-4-6' );
		$this->assertNotNull( $health );
		$this->assertArrayHasKey( 'skill_load_count', $health );
		$this->assertSame( 0, $health['skill_load_count'] );
	}

	// ─── prompt nudge ─────────────────────────────────────────────────

	public function test_weak_model_prompt_nudge_contains_key_phrases(): void {
		$nudge = ModelHealthTracker::weak_model_prompt_nudge();
		$this->assertStringContainsString( 'Tool-Use Discipline', $nudge );
		$this->assertStringContainsString( 'input_schema', $nudge );
		$this->assertStringContainsString( 'NEVER call an ability with empty arguments', $nudge );
	}

	// ─── lifecycle ────────────────────────────────────────────────────

	public function test_reset_clears_persisted_state(): void {
		ModelHealthTracker::set_current_model( 'claude-sonnet-4-6' );
		ModelHealthTracker::record_success();

		ModelHealthTracker::reset();

		$this->assertNull( ModelHealthTracker::get_health( 'claude-sonnet-4-6' ) );
	}
}
