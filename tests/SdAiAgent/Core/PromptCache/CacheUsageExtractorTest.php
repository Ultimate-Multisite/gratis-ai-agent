<?php
/**
 * Test case for CacheUsageExtractor.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core\PromptCache;

use SdAiAgent\Core\PromptCache\CacheUsageExtractor;
use WP_UnitTestCase;

/**
 * @covers \SdAiAgent\Core\PromptCache\CacheUsageExtractor
 */
class CacheUsageExtractorTest extends WP_UnitTestCase {

	public function test_extracts_anthropic_cache_tokens(): void {
		$response = array(
			'usage' => array(
				'input_tokens'                 => 2000,
				'cache_creation_input_tokens'  => 1500,
				'cache_read_input_tokens'      => 18000,
				'output_tokens'                => 200,
			),
		);

		$result = CacheUsageExtractor::extract( 'anthropic', $response );

		$this->assertSame( 1500, $result['creation'] );
		$this->assertSame( 18000, $result['read'] );
	}

	public function test_extracts_openai_cached_tokens(): void {
		$response = array(
			'usage' => array(
				'prompt_tokens'         => 20000,
				'completion_tokens'     => 300,
				'prompt_tokens_details' => array(
					'cached_tokens' => 18000,
				),
			),
		);

		$result = CacheUsageExtractor::extract( 'openai', $response );

		$this->assertSame( 0, $result['creation'] ); // OpenAI doesn't report writes.
		$this->assertSame( 18000, $result['read'] );
	}

	public function test_extracts_deepseek_hit_miss_tokens(): void {
		$response = array(
			'usage' => array(
				'prompt_tokens'            => 21000,
				'prompt_cache_hit_tokens'  => 17000,
				'prompt_cache_miss_tokens' => 4000,
				'completion_tokens'        => 300,
			),
		);

		$result = CacheUsageExtractor::extract( 'deepseek', $response );

		$this->assertSame( 4000, $result['creation'] );
		$this->assertSame( 17000, $result['read'] );
	}

	public function test_extracts_google_cached_content_tokens(): void {
		$response = array(
			'usageMetadata' => array(
				'promptTokenCount'        => 12000,
				'cachedContentTokenCount' => 9000,
				'candidatesTokenCount'    => 200,
			),
		);

		$result = CacheUsageExtractor::extract( 'google', $response );

		$this->assertSame( 0, $result['creation'] );
		$this->assertSame( 9000, $result['read'] );
	}

	public function test_returns_zero_for_missing_usage_block(): void {
		$this->assertSame(
			array( 'creation' => 0, 'read' => 0 ),
			CacheUsageExtractor::extract( 'anthropic', array() )
		);
		$this->assertSame(
			array( 'creation' => 0, 'read' => 0 ),
			CacheUsageExtractor::extract( 'openai', array( 'error' => 'overloaded' ) )
		);
	}

	public function test_returns_zero_for_non_array_response(): void {
		$this->assertSame(
			array( 'creation' => 0, 'read' => 0 ),
			CacheUsageExtractor::extract( 'anthropic', null )
		);
		$this->assertSame(
			array( 'creation' => 0, 'read' => 0 ),
			CacheUsageExtractor::extract( 'anthropic', 'string-response' )
		);
	}

	public function test_clamps_negative_values_to_zero(): void {
		$response = array(
			'usage' => array(
				'cache_creation_input_tokens' => -5,
				'cache_read_input_tokens'     => -100,
			),
		);

		$result = CacheUsageExtractor::extract( 'anthropic', $response );

		$this->assertSame( 0, $result['creation'] );
		$this->assertSame( 0, $result['read'] );
	}

	public function test_handles_string_numeric_values(): void {
		$response = array(
			'usage' => array(
				'cache_creation_input_tokens' => '1500',
				'cache_read_input_tokens'     => '18000',
			),
		);

		$result = CacheUsageExtractor::extract( 'anthropic', $response );

		$this->assertSame( 1500, $result['creation'] );
		$this->assertSame( 18000, $result['read'] );
	}

	public function test_unknown_provider_falls_back_to_openai_shape(): void {
		$response = array(
			'usage' => array(
				'prompt_tokens_details' => array(
					'cached_tokens' => 7000,
				),
			),
		);

		// xAI, Groq, Cerebras, Together, Fireworks, and any unknown
		// provider all default to the OpenAI shape because that's the
		// de-facto standard.
		foreach ( array( 'xai', 'groq', 'cerebras', 'together', 'fireworks', 'unknown-provider' ) as $provider_id ) {
			$result = CacheUsageExtractor::extract( $provider_id, $response );
			$this->assertSame( 7000, $result['read'], "Failed for provider {$provider_id}." );
			$this->assertSame( 0, $result['creation'], "Failed for provider {$provider_id}." );
		}
	}
}
