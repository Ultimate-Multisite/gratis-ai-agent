<?php
// SPDX-License-Identifier: MIT
// SPDX-FileCopyrightText: 2025-2026 Marcus Quinn
/**
 * Scenario 11: HtmlTransformer auto-transform combinatorics (ported from block-mcp).
 *
 * HtmlTransformer::apply() drives 4 categories of transforms:
 *   - Regex tag swaps (heading level, list ordered, group tagName).
 *   - HTML attribute transforms (url→href/src/alt, boolean attrs).
 *   - CSS inline-style transforms (height/width).
 *   - Text-content regex (citation, etc.).
 *
 * Each branch is regex- or Tag_Processor-driven, both of which are
 * easy to mangle on adversarial inputs (minified HTML, comments inside
 * the target tag, the same tag nested inside itself). This battery runs
 * each branch against a small fixture table of realistic inputs and a
 * handful of adversarial inputs.
 *
 * A helper `auto_transform_html()` wraps HtmlTransformer::apply() to
 * preserve the original test semantics: returns null when no transform
 * applied (unchanged innerHTML), or the transformed HTML string.
 *
 * Ported one-for-one from block-mcp tests/Stress/AutoTransformCombinatoricsTest.php
 * (GPL-2.0-or-later). Namespace and class name updated per AGENTS.md.
 *
 * @package SdAiAgent\Tests\Stress
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1788
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Stress;

use SdAiAgent\Core\HtmlTransformer;
use WP_UnitTestCase;

/**
 * Combinatoric coverage of HtmlTransformer::apply() transform branches.
 */
class AutoTransformCombinatoricsTest extends WP_UnitTestCase {

	// ── Helper ────────────────────────────────────────────────────────────

	/**
	 * Thin wrapper that mimics the block-mcp HTML_Transformer::auto_transform_html()
	 * API: returns the transformed HTML string when a transform applied, or null
	 * when the innerHTML was not changed (no matching transform branch).
	 *
	 * @param string              $block_name   Block type name.
	 * @param array<string,mixed> $changed_attrs Attribute changes being applied.
	 * @param string              $html          Current innerHTML of the block.
	 * @return string|null Transformed HTML, or null if no transform matched.
	 */
	private function auto_transform_html( string $block_name, array $changed_attrs, string $html ): ?string {
		$block = [
			'blockName'    => $block_name,
			'attrs'        => [],
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
			'innerBlocks'  => [],
		];
		$result = HtmlTransformer::apply( $block, $changed_attrs );
		// apply() returns the block unchanged when no transform applies.
		return $result['innerHTML'] !== $html ? $result['innerHTML'] : null;
	}

	// ── Heading level swap ────────────────────────────────────────────────

	public function test_heading_level_swap_h2_to_h3(): void {
		$out = $this->auto_transform_html( 'core/heading', [ 'level' => 3 ], '<h2>Title</h2>' );
		$this->assertIsString( $out );
		$this->assertStringContainsString( '<h3', $out );
		$this->assertStringNotContainsString( '<h2', $out );
		$this->assertStringContainsString( 'Title', $out );
	}

	public function test_heading_level_swap_h1_to_h6_extremes(): void {
		$out = $this->auto_transform_html( 'core/heading', [ 'level' => 6 ], '<h1>X</h1>' );
		$this->assertIsString( $out );
		$this->assertStringContainsString( '<h6', $out );
		$this->assertStringNotContainsString( '<h1', $out );
	}

	public function test_heading_level_swap_preserves_attributes(): void {
		$out = $this->auto_transform_html( 'core/heading', [ 'level' => 4 ], '<h2 class="hero">Hello</h2>' );
		$this->assertIsString( $out );
		$this->assertStringContainsString( 'class="hero"', $out );
	}

	/**
	 * Outer is h2; inner content references h3 as text — the swap must target
	 * the OUTERMOST tag, not the text mention.
	 */
	public function test_heading_level_swap_does_not_mangle_unrelated_h_tags_in_content(): void {
		$out = $this->auto_transform_html( 'core/heading', [ 'level' => 5 ], '<h2>About <code>&lt;h3&gt;</code></h2>' );
		$this->assertIsString( $out );
		$this->assertStringStartsWith( '<h5', $out );
		$this->assertStringContainsString( '&lt;h3&gt;', $out, 'text-content h3 reference must survive untouched' );
	}

	// ── List ordered toggle ───────────────────────────────────────────────

	public function test_list_ordered_toggle_ul_to_ol(): void {
		$out = $this->auto_transform_html( 'core/list', [ 'ordered' => true ], '<ul><li>a</li></ul>' );
		$this->assertIsString( $out );
		$this->assertStringContainsString( '<ol', $out );
		$this->assertStringContainsString( '</ol>', $out );
		$this->assertStringNotContainsString( '<ul', $out );
	}

	public function test_list_ordered_toggle_ol_to_ul(): void {
		$out = $this->auto_transform_html( 'core/list', [ 'ordered' => false ], '<ol><li>a</li></ol>' );
		$this->assertIsString( $out );
		$this->assertStringContainsString( '<ul', $out );
		$this->assertStringContainsString( '</ul>', $out );
		$this->assertStringNotContainsString( '<ol', $out );
	}

	public function test_list_swap_does_not_mangle_nested_list_text(): void {
		// A list containing the literal text "ul" — must not touch it.
		$out = $this->auto_transform_html( 'core/list', [ 'ordered' => true ], '<ul><li>ul stands for unordered</li></ul>' );
		$this->assertIsString( $out );
		$this->assertStringContainsString( 'ul stands for unordered', $out );
	}

	// ── Group tagName swap ────────────────────────────────────────────────

	public function test_group_tagname_swap_div_to_section(): void {
		$out = $this->auto_transform_html( 'core/group', [ 'tagName' => 'section' ], '<div class="wp-block-group"></div>' );
		$this->assertIsString( $out );
		$this->assertStringContainsString( '<section', $out );
		$this->assertStringContainsString( '</section>', $out );
	}

	public function test_group_tagname_swap_to_aside(): void {
		$out = $this->auto_transform_html( 'core/group', [ 'tagName' => 'aside' ], '<div></div>' );
		$this->assertIsString( $out );
		$this->assertStringContainsString( '<aside', $out );
	}

	// ── Ambiguous / no-op inputs ──────────────────────────────────────────

	public function test_unchanged_attrs_returns_null_or_unchanged(): void {
		// No attr changes — transformer may decline (null) or echo input.
		$out = $this->auto_transform_html( 'core/paragraph', [], '<p>same</p>' );
		if ( null !== $out ) {
			$this->assertStringContainsString( '<p>same</p>', $out );
		}
		$this->addToAssertionCount( 1 ); // no-op is acceptable.
	}

	public function test_unknown_block_returns_null(): void {
		$out = $this->auto_transform_html( 'unknown/block', [ 'level' => 3 ], '<x>1</x>' );
		$this->assertNull( $out, 'unknown blocks must fall through so the safety guard runs' );
	}

	public function test_unrelated_attr_change_returns_null_for_known_block(): void {
		// 'flubber' is not in any auto-transform branch for core/heading.
		$out = $this->auto_transform_html( 'core/heading', [ 'flubber' => 'green' ], '<h2>x</h2>' );
		$this->assertNull( $out, 'unrecognized attr for known block must fall through' );
	}

	// ── Adversarial wrappers ──────────────────────────────────────────────

	/**
	 * No whitespace anywhere — exercises regex anchors / boundary detection.
	 */
	public function test_heading_swap_with_minified_input(): void {
		$out = $this->auto_transform_html( 'core/heading', [ 'level' => 3 ], '<h2 id="x"><strong>bold</strong>tail</h2>' );
		$this->assertIsString( $out );
		$this->assertStringStartsWith( '<h3', $out );
		$this->assertStringContainsString( '</h3>', $out );
		$this->assertStringContainsString( '<strong>bold</strong>', $out, 'inner markup must be preserved' );
	}

	public function test_list_swap_preserves_inner_list_items(): void {
		$out = $this->auto_transform_html(
			'core/list',
			[ 'ordered' => true ],
			'<ul class="wp-block-list"><li>a</li><li>b</li><li>c</li></ul>'
		);
		$this->assertIsString( $out );
		// All three list items must survive the ul→ol swap.
		$this->assertSame(
			3,
			substr_count( $out, '<li>' ),
			'all three list items must survive the ul→ol swap'
		);
	}
}
