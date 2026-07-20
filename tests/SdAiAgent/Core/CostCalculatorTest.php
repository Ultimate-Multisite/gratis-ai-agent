<?php
/**
 * Test case for CostCalculator class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\CostCalculator;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiProvider;
use WP_UnitTestCase;

/**
 * Test CostCalculator functionality.
 */
class CostCalculatorTest extends WP_UnitTestCase {

	/**
	 * Test calculate_cost with Claude Sonnet 4 model.
	 */
	public function test_calculate_cost_claude_sonnet_4() {
		// 1M input tokens at $3.00 + 1M output tokens at $15.00 = $18.00
		$cost = CostCalculator::calculate_cost( 'claude-sonnet-4-20250514', 1_000_000, 1_000_000 );
		$this->assertSame( 18.0, $cost );
	}

	/**
	 * Test calculate_cost with small token counts.
	 */
	public function test_calculate_cost_small_tokens() {
		// 1000 input tokens at $3.00/M + 500 output tokens at $15.00/M
		// = 0.001 * 3.00 + 0.0005 * 15.00 = 0.003 + 0.0075 = 0.0105
		$cost = CostCalculator::calculate_cost( 'claude-sonnet-4', 1000, 500 );
		$this->assertSame( 0.0105, $cost );
	}

	/**
	 * Test calculate_cost with GPT-4o model.
	 */
	public function test_calculate_cost_gpt_4o() {
		// 1M input tokens at $2.50 + 1M output tokens at $10.00 = $12.50
		$cost = CostCalculator::calculate_cost( 'gpt-4o', 1_000_000, 1_000_000 );
		$this->assertSame( 12.5, $cost );
	}

	/**
	 * Test calculate_cost with GPT-4o-mini (cheap model).
	 */
	public function test_calculate_cost_gpt_4o_mini() {
		// 1M input tokens at $0.15 + 1M output tokens at $0.60 = $0.75
		$cost = CostCalculator::calculate_cost( 'gpt-4o-mini', 1_000_000, 1_000_000 );
		$this->assertSame( 0.75, $cost );
	}

	/**
	 * Test calculate_cost with the managed Superdav pro model.
	 */
	public function test_calculate_cost_superdav_pro() {
		// 1M input tokens at $5.00 + 1M output tokens at $30.00 = $35.00
		$cost = CostCalculator::calculate_cost( SuperdavAiProvider::DEFAULT_MODEL_ID, 1_000_000, 1_000_000 );
		$this->assertSame( 35.0, $cost );
	}

	/**
	 * Test calculate_cost with unknown model returns 0.
	 */
	public function test_calculate_cost_unknown_model() {
		$cost = CostCalculator::calculate_cost( 'unknown-model-xyz', 1_000_000, 1_000_000 );
		$this->assertSame( 0.0, $cost );
	}

	/**
	 * Test calculate_cost with zero tokens.
	 */
	public function test_calculate_cost_zero_tokens() {
		$cost = CostCalculator::calculate_cost( 'claude-sonnet-4', 0, 0 );
		$this->assertSame( 0.0, $cost );
	}

	/**
	 * Test get_pricing returns correct array for known model.
	 */
	public function test_get_pricing_known_model() {
		$pricing = CostCalculator::get_pricing( 'claude-sonnet-4' );

		$this->assertIsArray( $pricing );
		$this->assertCount( 2, $pricing );
		$this->assertSame( 3.00, $pricing[0] );  // Input price per million
		$this->assertSame( 15.00, $pricing[1] ); // Output price per million
	}

	/**
	 * Test get_pricing returns null for unknown model.
	 */
	public function test_get_pricing_unknown_model() {
		$pricing = CostCalculator::get_pricing( 'nonexistent-model' );
		$this->assertNull( $pricing );
	}

	/**
	 * Test get_pricing uses prefix matching.
	 */
	public function test_get_pricing_prefix_matching() {
		// A model with date suffix should match the base model
		$pricing = CostCalculator::get_pricing( 'claude-sonnet-4-20251231' );

		$this->assertIsArray( $pricing );
		$this->assertSame( 3.00, $pricing[0] );
		$this->assertSame( 15.00, $pricing[1] );
	}

	/**
	 * Test get_all_pricing returns all pricing data.
	 */
	public function test_get_all_pricing() {
		$all = CostCalculator::get_all_pricing();

		$this->assertIsArray( $all );
		$this->assertNotEmpty( $all );
		$this->assertArrayHasKey( 'claude-sonnet-4', $all );
		$this->assertArrayHasKey( 'gpt-4o', $all );
		$this->assertArrayHasKey( 'gpt-4o-mini', $all );
		$this->assertArrayHasKey( SuperdavAiProvider::DEFAULT_MODEL_ID, $all );
	}

	/**
	 * Test cost calculation is rounded to 6 decimal places.
	 */
	public function test_calculate_cost_precision() {
		// Very small token count to test precision
		$cost = CostCalculator::calculate_cost( 'claude-sonnet-4', 1, 1 );

		// 1 token at $3/M input + 1 token at $15/M output
		// = 0.000003 + 0.000015 = 0.000018
		$this->assertSame( 0.000018, $cost );
	}

	/**
	 * Test Claude Opus pricing (expensive model).
	 */
	public function test_calculate_cost_claude_opus() {
		// 1M input at $15 + 1M output at $75 = $90
		$cost = CostCalculator::calculate_cost( 'claude-opus-4', 1_000_000, 1_000_000 );
		$this->assertSame( 90.0, $cost );
	}

	/**
	 * Test Claude Haiku pricing (cheap model).
	 */
	public function test_calculate_cost_claude_haiku() {
		// 1M input at $0.80 + 1M output at $4.00 = $4.80
		$cost = CostCalculator::calculate_cost( 'claude-haiku-4', 1_000_000, 1_000_000 );
		$this->assertSame( 4.8, $cost );
	}

	/**
	 * Test Claude 3.5 Haiku pricing (budget Anthropic model).
	 */
	public function test_calculate_cost_claude_3_5_haiku() {
		// 1M input at $0.80 + 1M output at $4.00 = $4.80
		$cost = CostCalculator::calculate_cost( 'claude-3-5-haiku-20241022', 1_000_000, 1_000_000 );
		$this->assertSame( 4.8, $cost );
	}

	/**
	 * Test Gemini 2.0 Flash pricing (budget Google model).
	 */
	public function test_calculate_cost_gemini_2_0_flash() {
		// 1M input at $0.10 + 1M output at $0.40 = $0.50
		$cost = CostCalculator::calculate_cost( 'gemini-2.0-flash', 1_000_000, 1_000_000 );
		$this->assertSame( 0.5, $cost );
	}

	/**
	 * Test Gemini 2.0 Flash Lite pricing (cheapest Google model).
	 */
	public function test_calculate_cost_gemini_2_0_flash_lite() {
		// 1M input at $0.075 + 1M output at $0.30 = $0.375
		$cost = CostCalculator::calculate_cost( 'gemini-2.0-flash-lite', 1_000_000, 1_000_000 );
		$this->assertSame( 0.375, $cost );
	}

	/**
	 * Test get_all_pricing includes new models.
	 */
	public function test_get_all_pricing_includes_new_models() {
		$all = CostCalculator::get_all_pricing();

		$this->assertArrayHasKey( 'claude-3-5-haiku-20241022', $all );
		$this->assertArrayHasKey( 'gemini-2.0-flash', $all );
		$this->assertArrayHasKey( 'gemini-2.0-flash-lite', $all );
	}

	/**
	 * Test Claude 3.5 Haiku pricing values.
	 */
	public function test_get_pricing_claude_3_5_haiku() {
		$pricing = CostCalculator::get_pricing( 'claude-3-5-haiku-20241022' );

		$this->assertIsArray( $pricing );
		$this->assertSame( 0.80, $pricing[0] );
		$this->assertSame( 4.00, $pricing[1] );
	}

	/**
	 * Test Gemini 2.0 Flash pricing values.
	 */
	public function test_get_pricing_gemini_2_0_flash() {
		$pricing = CostCalculator::get_pricing( 'gemini-2.0-flash' );

		$this->assertIsArray( $pricing );
		$this->assertSame( 0.10, $pricing[0] );
		$this->assertSame( 0.40, $pricing[1] );
	}

	/**
	 * Test Gemini 2.0 Flash Lite pricing values.
	 */
	public function test_get_pricing_gemini_2_0_flash_lite() {
		$pricing = CostCalculator::get_pricing( 'gemini-2.0-flash-lite' );

		$this->assertIsArray( $pricing );
		$this->assertSame( 0.075, $pricing[0] );
		$this->assertSame( 0.30, $pricing[1] );
	}

	/**
	 * Test Gemini 2.5 Flash pricing ($0.30/1M input, $2.50/1M output via OpenRouter).
	 */
	public function test_calculate_cost_gemini_2_5_flash() {
		// 1M input at $0.30 + 1M output at $2.50 = $2.80
		$cost = CostCalculator::calculate_cost( 'google/gemini-2.5-flash-preview', 1_000_000, 1_000_000 );
		$this->assertSame( 2.8, $cost );
	}

	/**
	 * Test Gemini 2.5 Flash Lite pricing ($0.10/1M input, $0.40/1M output via OpenRouter).
	 */
	public function test_calculate_cost_gemini_2_5_flash_lite() {
		// 1M input at $0.10 + 1M output at $0.40 = $0.50
		$cost = CostCalculator::calculate_cost( 'google/gemini-2.5-flash-lite-preview', 1_000_000, 1_000_000 );
		$this->assertSame( 0.5, $cost );
	}

	/**
	 * Test Gemini 2.5 Flash pricing values.
	 */
	public function test_get_pricing_gemini_2_5_flash() {
		$pricing = CostCalculator::get_pricing( 'google/gemini-2.5-flash-preview' );

		$this->assertIsArray( $pricing );
		$this->assertSame( 0.30, $pricing[0] );
		$this->assertSame( 2.50, $pricing[1] );
	}

	/**
	 * Test Gemini 2.5 Flash Lite pricing values.
	 */
	public function test_get_pricing_gemini_2_5_flash_lite() {
		$pricing = CostCalculator::get_pricing( 'google/gemini-2.5-flash-lite-preview' );

		$this->assertIsArray( $pricing );
		$this->assertSame( 0.10, $pricing[0] );
		$this->assertSame( 0.40, $pricing[1] );
	}

	/**
	 * Test get_all_pricing includes Gemini 2.5 Flash models.
	 */
	public function test_get_all_pricing_includes_gemini_2_5_flash_models() {
		$all = CostCalculator::get_all_pricing();

		$this->assertArrayHasKey( 'google/gemini-2.5-flash-preview', $all );
		$this->assertArrayHasKey( 'google/gemini-2.5-flash-lite-preview', $all );
	}
}
