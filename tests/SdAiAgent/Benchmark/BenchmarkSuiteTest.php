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
		$questions = BenchmarkSuite::get_questions( 'abilities-developer-v1' );
		$question  = null;

		foreach ( $questions as $candidate ) {
			if ( 'adev-005' === ( $candidate['id'] ?? '' ) ) {
				$question = $candidate;
				break;
			}
		}

		$this->assertIsArray( $question );

		$prompt = (string) $question['prompt'];
		$this->assertStringContainsString( 'path "."', $prompt );
		$this->assertStringContainsString( 'pattern "index.php"', $prompt );
		$this->assertStringNotContainsString( '*Abilities.php', $prompt );
	}
}
