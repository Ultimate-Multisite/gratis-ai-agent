<?php

declare(strict_types=1);
/**
 * Test case for benchmark suite definitions.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Benchmark;

use SdAiAgent\Benchmark\BenchmarkSuite;
use WP_UnitTestCase;

/**
 * Test benchmark suite question definitions.
 */
class BenchmarkSuiteTest extends WP_UnitTestCase {

	/**
	 * Test adev-005 targets a file available under the file ability wp-content root.
	 */
	public function test_adev_005_uses_accessible_wp_content_root_file(): void {
		$question = $this->find_question( 'abilities-developer-v1', 'adev-005' );

		$this->assertIsArray( $question );

		$prompt = (string) $question['prompt'];
		$this->assertStringContainsString( 'path "."', $prompt );
		$this->assertStringContainsString( 'pattern "index.php"', $prompt );
		$this->assertStringNotContainsString( '*Abilities.php', $prompt );
	}

	/**
	 * Test fn-004 requires benchmark-compatible zero-row WP-CLI output.
	 */
	public function test_fn_004_requires_zero_row_wp_cli_summary_output(): void {
		$question = $this->find_question( 'functional-v1', 'fn-004' );

		$this->assertIsArray( $question );

		$prompt = (string) $question['prompt'];
		$this->assertStringContainsString( 'Processed 0/0 posts', $prompt );
		$this->assertStringContainsString( 'Migration complete: 0 posts updated', $prompt );
		$this->assertStringContainsString( 'do not return early', $prompt );
	}

	/**
	 * Find a benchmark question by suite and ID.
	 *
	 * @param string $suite Suite slug.
	 * @param string $id    Question ID.
	 * @return array<string, mixed>|null
	 */
	private function find_question( string $suite, string $id ): ?array {
		$questions = BenchmarkSuite::get_questions( $suite );

		foreach ( $questions as $candidate ) {
			if ( $id === ( $candidate['id'] ?? '' ) ) {
				return $candidate;
			}
		}

		return null;
	}
}
