<?php
/**
 * Test case for the deep server-side BlockValidator (GH#1584 Phase 1).
 *
 * Covers the acceptance criteria from GH#1584:
 *
 *  - validate-block-content returns isValid: false for a heading whose
 *    `level` attribute does not match its rendered tag, with `expectedContent`
 *    containing the corrected `<hN>` opening + closing tag.
 *  - Nested inner blocks (core/columns > core/column > core/paragraph) are
 *    validated recursively and each block appears in `results[]`.
 *  - Missing wrapper classes (e.g. `wp-block-heading`, `wp-block-buttons`) are
 *    flagged and `expectedContent` injects the missing class.
 *  - Wrong wrapper tag (e.g. `<section>` instead of `<figure>` for core/image)
 *    is flagged and rewritten.
 *  - Unknown / third-party blocks pass through with isValid: true when no
 *    cached JS result is available.
 *  - {@see \SdAiAgent\Core\BlockValidatorBridge} cache results override the PHP
 *    report when present.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1584
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\BlockValidator;
use SdAiAgent\Core\BlockValidatorBridge;
use WP_UnitTestCase;

/**
 * Tests for the deep BlockValidator (Phase 1, GH#1584).
 */
class BlockValidatorTest extends WP_UnitTestCase {

	/**
	 * Reset the bridge cache so transient leakage between tests cannot mask
	 * regressions.
	 */
	public function tear_down(): void {
		BlockValidatorBridge::reset_memory_cache();
		parent::tear_down();
	}

	/**
	 * Locate the result entry for the given block name.
	 *
	 * @param array<int, array<string, mixed>> $results Validator result list.
	 * @param string                            $name    Block name to find.
	 * @return array<string, mixed>|null Result entry or null.
	 */
	private function find_result( array $results, string $name ): ?array {
		foreach ( $results as $result ) {
			if ( isset( $result['blockName'] ) && $result['blockName'] === $name ) {
				return $result;
			}
		}
		return null;
	}

	// ------------------------------------------------------------------
	// core/heading level-vs-tag mismatch (primary AC)
	// ------------------------------------------------------------------

	/**
	 * AC: A heading block with `{"level":3}` but `<h2>` markup returns
	 * isValid: false AND expectedContent contains `<h3>`.
	 */
	public function test_heading_level_mismatch_produces_expected_h3(): void {
		$content   = "<!-- wp:heading {\"level\":3} -->\n<h2 class=\"wp-block-heading\">Bad</h2>\n<!-- /wp:heading -->";
		$validator = new BlockValidator();
		$report    = $validator->validate( $content );

		$heading = $this->find_result( $report['results'], 'core/heading' );
		$this->assertNotNull( $heading, 'core/heading should appear in results.' );
		$this->assertFalse( $heading['isValid'], 'Heading with level/tag mismatch should be invalid.' );
		$this->assertStringContainsString(
			'<h3',
			$heading['expectedContent'],
			'expectedContent should contain the corrected <h3> opening tag.'
		);
		$this->assertStringContainsString(
			'</h3>',
			$heading['expectedContent'],
			'expectedContent should contain the corrected </h3> closing tag.'
		);
	}

	/**
	 * Valid headings — `level:2` + `<h2>` — should be isValid: true.
	 */
	public function test_heading_matching_level_is_valid(): void {
		$content   = "<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">Good</h2>\n<!-- /wp:heading -->";
		$validator = new BlockValidator();
		$report    = $validator->validate( $content );

		$heading = $this->find_result( $report['results'], 'core/heading' );
		$this->assertNotNull( $heading );
		$this->assertTrue( $heading['isValid'], 'Heading with matching level should be valid.' );
		$this->assertSame( 0, $report['invalidBlocks'] );
	}

	// ------------------------------------------------------------------
	// Recursive validation (nested inner blocks AC)
	// ------------------------------------------------------------------

	/**
	 * AC: nested core/columns > core/column > core/paragraph are validated
	 * recursively and each block appears in results[].
	 */
	public function test_nested_inner_blocks_are_recursively_validated(): void {
		$content = "<!-- wp:columns -->\n<div class=\"wp-block-columns\">\n<!-- wp:column -->\n<div class=\"wp-block-column\">\n<!-- wp:paragraph -->\n<p>Left</p>\n<!-- /wp:paragraph -->\n</div>\n<!-- /wp:column -->\n<!-- wp:column -->\n<div class=\"wp-block-column\">\n<!-- wp:paragraph -->\n<p>Right</p>\n<!-- /wp:paragraph -->\n</div>\n<!-- /wp:column -->\n</div>\n<!-- /wp:columns -->";

		$validator = new BlockValidator();
		$report    = $validator->validate( $content );

		$block_names = array_column( $report['results'], 'blockName' );
		$this->assertContains( 'core/columns', $block_names );
		$this->assertSame( 2, count( array_filter( $block_names, static fn( $n ) => 'core/column' === $n ) ) );
		$this->assertSame( 2, count( array_filter( $block_names, static fn( $n ) => 'core/paragraph' === $n ) ) );
		$this->assertSame( 5, $report['totalBlocks'] );
	}

	// ------------------------------------------------------------------
	// Required wrapper class checks
	// ------------------------------------------------------------------

	/**
	 * Missing `wp-block-heading` class should be flagged and injected into
	 * expectedContent.
	 */
	public function test_heading_missing_required_class_is_flagged(): void {
		$content   = "<!-- wp:heading {\"level\":2} -->\n<h2>No class</h2>\n<!-- /wp:heading -->";
		$validator = new BlockValidator();
		$report    = $validator->validate( $content );

		$heading = $this->find_result( $report['results'], 'core/heading' );
		$this->assertNotNull( $heading );
		$this->assertFalse( $heading['isValid'] );
		$this->assertStringContainsString( 'wp-block-heading', $heading['issues'][0] );
		$this->assertStringContainsString( 'wp-block-heading', $heading['expectedContent'] );
	}

	/**
	 * Missing `wp-block-buttons` class on core/buttons should be flagged.
	 */
	public function test_buttons_missing_required_class_is_flagged(): void {
		$content   = "<!-- wp:buttons -->\n<div><!-- wp:button --><div class=\"wp-block-button\"><a class=\"wp-block-button__link\">Click</a></div><!-- /wp:button --></div>\n<!-- /wp:buttons -->";
		$validator = new BlockValidator();
		$report    = $validator->validate( $content );

		$buttons = $this->find_result( $report['results'], 'core/buttons' );
		$this->assertNotNull( $buttons );
		$this->assertFalse( $buttons['isValid'] );
		$this->assertStringContainsString( 'wp-block-buttons', $buttons['expectedContent'] );
	}

	// ------------------------------------------------------------------
	// Wrong wrapper tag
	// ------------------------------------------------------------------

	/**
	 * core/image with `<section>` wrapper instead of `<figure>` should be
	 * flagged and rewritten to `<figure>` in expectedContent.
	 */
	public function test_image_wrong_wrapper_tag_is_rewritten(): void {
		$content   = "<!-- wp:image -->\n<section class=\"wp-block-image\"><img src=\"x.jpg\" alt=\"\"/></section>\n<!-- /wp:image -->";
		$validator = new BlockValidator();
		$report    = $validator->validate( $content );

		$image = $this->find_result( $report['results'], 'core/image' );
		$this->assertNotNull( $image );
		$this->assertFalse( $image['isValid'] );
		$this->assertStringContainsString( '<figure', $image['expectedContent'] );
		$this->assertStringContainsString( '</figure>', $image['expectedContent'] );
	}

	// ------------------------------------------------------------------
	// Third-party / unknown blocks
	// ------------------------------------------------------------------

	/**
	 * Unknown blocks should pass through unchanged when no JS cache entry is
	 * present — PHP cannot run third-party save() functions.
	 */
	public function test_unknown_block_passes_through_when_no_cache(): void {
		$content   = "<!-- wp:kadence/heading -->\n<h2 class=\"kt-heading\">Custom</h2>\n<!-- /wp:kadence/heading -->";
		$validator = new BlockValidator();
		$report    = $validator->validate( $content );

		$result = $this->find_result( $report['results'], 'kadence/heading' );
		$this->assertNotNull( $result );
		$this->assertTrue( $result['isValid'], 'Unknown blocks should pass through without false positives.' );
		$this->assertSame( $result['originalContent'], $result['expectedContent'] );
	}

	/**
	 * When the bridge cache holds a JS-validated report for the same content,
	 * `BlockValidator::validate()` should return it instead of the PHP report.
	 */
	public function test_bridge_cache_overrides_php_validator(): void {
		$content       = "<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">Valid in PHP</h2>\n<!-- /wp:heading -->";
		$cached_report = [
			'totalBlocks'   => 1,
			'validBlocks'   => 0,
			'invalidBlocks' => 1,
			'results'       => [
				[
					'blockName'       => 'core/heading',
					'isValid'         => false,
					'issues'          => [ 'JS validator says invalid' ],
					'originalContent' => '<h2 class="wp-block-heading">Valid in PHP</h2>',
					'expectedContent' => '<h2 class="wp-block-heading">JS expected</h2>',
				],
			],
			'source'        => 'js',
		];

		BlockValidatorBridge::store( $content, $cached_report );

		$validator = new BlockValidator();
		$report    = $validator->validate( $content );

		$this->assertSame( 'js-cached', $report['source'] );
		$this->assertSame( 1, $report['invalidBlocks'] );
		$this->assertStringContainsString( 'JS validator', $report['results'][0]['issues'][0] );
	}

	// ------------------------------------------------------------------
	// Report shape + back-compat
	// ------------------------------------------------------------------

	/**
	 * Report shape mirrors Studio (totalBlocks / validBlocks / invalidBlocks /
	 * results) and includes the `source` field added by Phase 1.
	 */
	public function test_report_shape_mirrors_studio(): void {
		$content   = "<!-- wp:paragraph -->\n<p>Hello</p>\n<!-- /wp:paragraph -->";
		$validator = new BlockValidator();
		$report    = $validator->validate( $content );

		$this->assertArrayHasKey( 'totalBlocks', $report );
		$this->assertArrayHasKey( 'validBlocks', $report );
		$this->assertArrayHasKey( 'invalidBlocks', $report );
		$this->assertArrayHasKey( 'results', $report );
		$this->assertArrayHasKey( 'source', $report );
		$this->assertSame( 'php', $report['source'] );

		$result = $report['results'][0];
		$this->assertArrayHasKey( 'blockName', $result );
		$this->assertArrayHasKey( 'isValid', $result );
		$this->assertArrayHasKey( 'issues', $result );
		$this->assertArrayHasKey( 'originalContent', $result );
		$this->assertArrayHasKey( 'expectedContent', $result );
	}
}
