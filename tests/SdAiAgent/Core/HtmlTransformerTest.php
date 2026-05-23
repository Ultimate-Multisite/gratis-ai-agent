<?php
/**
 * Test case for HtmlTransformer (GH#1711).
 *
 * Covers the acceptance criteria from the issue:
 *   AC1: core/heading level ↔ <hN> tag sync.
 *   AC2: core/list ordered ↔ <ul>/<ol> tag sync.
 *   AC3: core/group tagName ↔ wrapper tag sync.
 *   AC4: core/button url ↔ <a href>; text ↔ link text.
 *   AC5: core/image url/alt/id ↔ <img> attributes.
 *   AC6: Unknown block returns unchanged.
 *   AC7: static_block_attrs_changed warning for unsupported blocks.
 *   AC8: wp_kses_post on final output.
 *   AC9: Full PHPUnit + lint + phpstan clean.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1711
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\BlockMutator;
use SdAiAgent\Core\HtmlTransformer;
use WP_UnitTestCase;

/**
 * Unit tests for HtmlTransformer.
 *
 * Uses WP_UnitTestCase so wp_kses_post(), WP_HTML_Tag_Processor, and other
 * WP functions are available.
 */
class HtmlTransformerTest extends WP_UnitTestCase {

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Build a minimal parsed-block array.
	 *
	 * @param string              $name        Block name.
	 * @param array<string,mixed> $attrs       Block attributes.
	 * @param array<int,mixed>    $inner_blocks Inner blocks.
	 * @param string              $inner_html  innerHTML string.
	 * @return array<string,mixed>
	 */
	private function make_block(
		string $name,
		array $attrs = [],
		array $inner_blocks = [],
		string $inner_html = '<p>Content</p>'
	): array {
		$inner_content = [];

		foreach ( $inner_blocks as $ignored ) {
			$inner_content[] = null;
		}

		if ( empty( $inner_blocks ) ) {
			$inner_content = [ $inner_html ];
		}

		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
		];
	}

	// ── core/heading (AC1) ───────────────────────────────────────────────

	/**
	 * AC1: Heading level 2 → 3 rewrites <h2> to <h3>.
	 */
	public function test_heading_level_2_to_3(): void {
		$block  = $this->make_block( 'core/heading', [ 'level' => 2 ], [], '<h2>Hello World</h2>' );
		$result = HtmlTransformer::apply( $block, [ 'level' => 3 ] );

		$this->assertSame( '<h3>Hello World</h3>', $result['innerHTML'] );
	}

	/**
	 * Heading level 5 → 1 rewrites all opening and closing tags.
	 */
	public function test_heading_level_5_to_1(): void {
		$block  = $this->make_block( 'core/heading', [ 'level' => 5 ], [], '<h5 class="has-text-align-center">Title</h5>' );
		$result = HtmlTransformer::apply( $block, [ 'level' => 1 ] );

		$this->assertSame( '<h1 class="has-text-align-center">Title</h1>', $result['innerHTML'] );
	}

	/**
	 * Heading with invalid level (0) leaves HTML unchanged.
	 */
	public function test_heading_invalid_level(): void {
		$block  = $this->make_block( 'core/heading', [ 'level' => 2 ], [], '<h2>Hello</h2>' );
		$result = HtmlTransformer::apply( $block, [ 'level' => 0 ] );

		$this->assertSame( '<h2>Hello</h2>', $result['innerHTML'] );
	}

	/**
	 * Heading innerContent is rebuilt to match new innerHTML.
	 */
	public function test_heading_inner_content_rebuilt(): void {
		$block  = $this->make_block( 'core/heading', [ 'level' => 2 ], [], '<h2>Test</h2>' );
		$result = HtmlTransformer::apply( $block, [ 'level' => 4 ] );

		$this->assertSame( [ '<h4>Test</h4>' ], $result['innerContent'] );
	}

	// ── core/list (AC2) ──────────────────────────────────────────────────

	/**
	 * AC2: List ordered:true converts <ul> to <ol>.
	 */
	public function test_list_unordered_to_ordered(): void {
		$html   = '<ul><li>Item 1</li><li>Item 2</li></ul>';
		$block  = $this->make_block( 'core/list', [ 'ordered' => false ], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'ordered' => true ] );

		$this->assertStringContainsString( '<ol>', $result['innerHTML'] );
		$this->assertStringContainsString( '</ol>', $result['innerHTML'] );
		$this->assertStringNotContainsString( '<ul>', $result['innerHTML'] );
		$this->assertStringNotContainsString( '</ul>', $result['innerHTML'] );
	}

	/**
	 * List ordered:false converts <ol> to <ul>.
	 */
	public function test_list_ordered_to_unordered(): void {
		$html   = '<ol><li>Item 1</li><li>Item 2</li></ol>';
		$block  = $this->make_block( 'core/list', [ 'ordered' => true ], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'ordered' => false ] );

		$this->assertStringContainsString( '<ul>', $result['innerHTML'] );
		$this->assertStringContainsString( '</ul>', $result['innerHTML'] );
		$this->assertStringNotContainsString( '<ol>', $result['innerHTML'] );
	}

	/**
	 * List with nested lists only swaps outer tags.
	 */
	public function test_list_nested_preserves_inner(): void {
		$html   = '<ul><li>Top<ul><li>Nested</li></ul></li></ul>';
		$block  = $this->make_block( 'core/list', [ 'ordered' => false ], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'ordered' => true ] );

		// Outer should be <ol>, inner should remain <ul>.
		$this->assertStringStartsWith( '<ol>', $result['innerHTML'] );
		$this->assertStringContainsString( '<ul><li>Nested</li></ul>', $result['innerHTML'] );
	}

	// ── core/group (AC3) ─────────────────────────────────────────────────

	/**
	 * AC3: Group tagName:'section' rewrites wrapper tag.
	 */
	public function test_group_tag_div_to_section(): void {
		$html   = '<div class="wp-block-group"></div>';
		$block  = $this->make_block( 'core/group', [ 'tagName' => 'div' ], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'tagName' => 'section' ] );

		$this->assertStringContainsString( '<section', $result['innerHTML'] );
		$this->assertStringContainsString( '</section>', $result['innerHTML'] );
		$this->assertStringNotContainsString( '<div', $result['innerHTML'] );
	}

	/**
	 * Group rejects invalid tagName (stays unchanged).
	 */
	public function test_group_invalid_tag_name(): void {
		$html   = '<div class="wp-block-group"></div>';
		$block  = $this->make_block( 'core/group', [ 'tagName' => 'div' ], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'tagName' => 'script' ] );

		$this->assertSame( $html, $result['innerHTML'] );
	}

	/**
	 * Group supports nav tagName.
	 */
	public function test_group_tag_to_nav(): void {
		$html   = '<div class="wp-block-group"></div>';
		$block  = $this->make_block( 'core/group', [ 'tagName' => 'div' ], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'tagName' => 'nav' ] );

		$this->assertStringContainsString( '<nav', $result['innerHTML'] );
		$this->assertStringContainsString( '</nav>', $result['innerHTML'] );
	}

	// ── core/button (AC4) ────────────────────────────────────────────────

	/**
	 * AC4: Button url change rewrites <a href>.
	 */
	public function test_button_url_change(): void {
		$html   = '<div class="wp-block-button"><a class="wp-block-button__link" href="https://old.example.com" target="_blank" rel="noopener">Click</a></div>';
		$block  = $this->make_block( 'core/button', [ 'url' => 'https://old.example.com' ], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'url' => 'https://new.example.com' ] );

		$this->assertStringContainsString( 'href="https://new.example.com"', $result['innerHTML'] );
		// Preserves other attributes.
		$this->assertStringContainsString( 'class="wp-block-button__link"', $result['innerHTML'] );
		$this->assertStringContainsString( 'target="_blank"', $result['innerHTML'] );
		$this->assertStringContainsString( 'rel="noopener"', $result['innerHTML'] );
	}

	/**
	 * AC4: Button text change rewrites link text.
	 */
	public function test_button_text_change(): void {
		$html   = '<div class="wp-block-button"><a class="wp-block-button__link" href="https://example.com">Old Text</a></div>';
		$block  = $this->make_block( 'core/button', [ 'text' => 'Old Text' ], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'text' => 'New Text' ] );

		$this->assertStringContainsString( '>New Text</a>', $result['innerHTML'] );
	}

	/**
	 * Button combined url + text change updates both.
	 */
	public function test_button_url_and_text_change(): void {
		$html   = '<div class="wp-block-button"><a class="wp-block-button__link" href="https://old.example.com">Click</a></div>';
		$block  = $this->make_block( 'core/button', [], [], $html );
		$result = HtmlTransformer::apply( $block, [
			'url'  => 'https://new.example.com',
			'text' => 'New Button',
		] );

		$this->assertStringContainsString( 'href="https://new.example.com"', $result['innerHTML'] );
		$this->assertStringContainsString( '>New Button</a>', $result['innerHTML'] );
	}

	// ── core/image (AC5) ─────────────────────────────────────────────────

	/**
	 * AC5: Image url change rewrites <img src>.
	 */
	public function test_image_url_change(): void {
		$html   = '<figure class="wp-block-image"><img src="https://old.example.com/img.jpg" alt="Photo" class="wp-image-42" /></figure>';
		$block  = $this->make_block( 'core/image', [ 'url' => 'https://old.example.com/img.jpg' ], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'url' => 'https://new.example.com/img.png' ] );

		$this->assertStringContainsString( 'src="https://new.example.com/img.png"', $result['innerHTML'] );
	}

	/**
	 * Image alt change rewrites <img alt>.
	 */
	public function test_image_alt_change(): void {
		$html   = '<figure class="wp-block-image"><img src="https://example.com/img.jpg" alt="Old Alt" class="wp-image-42" /></figure>';
		$block  = $this->make_block( 'core/image', [ 'alt' => 'Old Alt' ], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'alt' => 'New Alt Description' ] );

		$this->assertStringContainsString( 'alt="New Alt Description"', $result['innerHTML'] );
	}

	/**
	 * AC5: Image id change rewrites wp-image-{id} class.
	 */
	public function test_image_id_change(): void {
		$html   = '<figure class="wp-block-image"><img src="https://example.com/img.jpg" alt="Photo" class="wp-image-42" /></figure>';
		$block  = $this->make_block( 'core/image', [ 'id' => 42 ], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'id' => 99 ] );

		$this->assertStringContainsString( 'wp-image-99', $result['innerHTML'] );
		$this->assertStringNotContainsString( 'wp-image-42', $result['innerHTML'] );
	}

	/**
	 * Image combined url + alt + id updates all attributes.
	 */
	public function test_image_combined_changes(): void {
		$html   = '<figure class="wp-block-image"><img src="https://old.example.com/old.jpg" alt="Old" class="wp-image-10" /></figure>';
		$block  = $this->make_block( 'core/image', [], [], $html );
		$result = HtmlTransformer::apply( $block, [
			'url' => 'https://new.example.com/new.jpg',
			'alt' => 'New Alt',
			'id'  => 50,
		] );

		$this->assertStringContainsString( 'src="https://new.example.com/new.jpg"', $result['innerHTML'] );
		$this->assertStringContainsString( 'alt="New Alt"', $result['innerHTML'] );
		$this->assertStringContainsString( 'wp-image-50', $result['innerHTML'] );
	}

	// ── core/spacer ──────────────────────────────────────────────────────

	/**
	 * Spacer height change rewrites inline style.
	 */
	public function test_spacer_height_change(): void {
		$html   = '<div style="height:100px" aria-hidden="true" class="wp-block-spacer"></div>';
		$block  = $this->make_block( 'core/spacer', [ 'height' => '100px' ], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'height' => '200px' ] );

		$this->assertStringContainsString( 'height:200px', $result['innerHTML'] );
		$this->assertStringNotContainsString( 'height:100px', $result['innerHTML'] );
	}

	/**
	 * Spacer width change rewrites inline style.
	 */
	public function test_spacer_width_change(): void {
		$html   = '<div style="height:50px;width:100px" aria-hidden="true" class="wp-block-spacer"></div>';
		$block  = $this->make_block( 'core/spacer', [ 'width' => '100px' ], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'width' => '300px' ] );

		$this->assertStringContainsString( 'width:300px', $result['innerHTML'] );
	}

	// ── core/details ─────────────────────────────────────────────────────

	/**
	 * Details showContent:true adds `open` attribute.
	 */
	public function test_details_show_content_true(): void {
		$html   = '<details class="wp-block-details"><summary>Title</summary><p>Content</p></details>';
		$block  = $this->make_block( 'core/details', [ 'showContent' => false ], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'showContent' => true ] );

		$this->assertStringContainsString( '<details', $result['innerHTML'] );
		$this->assertStringContainsString( 'open', $result['innerHTML'] );
	}

	/**
	 * Details showContent:false removes `open` attribute.
	 */
	public function test_details_show_content_false(): void {
		$html   = '<details class="wp-block-details" open><summary>Title</summary><p>Content</p></details>';
		$block  = $this->make_block( 'core/details', [ 'showContent' => true ], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'showContent' => false ] );

		$this->assertStringNotContainsString( ' open', $result['innerHTML'] );
	}

	// ── core/quote ───────────────────────────────────────────────────────

	/**
	 * Quote citation update replaces existing <cite>.
	 */
	public function test_quote_citation_update(): void {
		$html   = '<blockquote class="wp-block-quote"><p>A wise quote.</p><cite>Old Author</cite></blockquote>';
		$block  = $this->make_block( 'core/quote', [ 'citation' => 'Old Author' ], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'citation' => 'New Author' ] );

		$this->assertStringContainsString( '<cite>New Author</cite>', $result['innerHTML'] );
		$this->assertStringNotContainsString( 'Old Author', $result['innerHTML'] );
	}

	/**
	 * Quote citation adds <cite> when none exists.
	 */
	public function test_quote_citation_add(): void {
		$html   = '<blockquote class="wp-block-quote"><p>A wise quote.</p></blockquote>';
		$block  = $this->make_block( 'core/quote', [], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'citation' => 'New Author' ] );

		$this->assertStringContainsString( '<cite>New Author</cite>', $result['innerHTML'] );
	}

	// ── core/audio & core/video ──────────────────────────────────────────

	/**
	 * Audio loop:true sets loop attribute.
	 */
	public function test_audio_loop_enable(): void {
		$html   = '<figure class="wp-block-audio"><audio controls src="https://example.com/audio.mp3"></audio></figure>';
		$block  = $this->make_block( 'core/audio', [ 'loop' => false ], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'loop' => true ] );

		$this->assertStringContainsString( 'loop', $result['innerHTML'] );
	}

	/**
	 * Video autoplay:true sets autoplay attribute.
	 */
	public function test_video_autoplay_enable(): void {
		$html   = '<figure class="wp-block-video"><video controls src="https://example.com/video.mp4"></video></figure>';
		$block  = $this->make_block( 'core/video', [], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'autoplay' => true ] );

		$this->assertStringContainsString( 'autoplay', $result['innerHTML'] );
	}

	/**
	 * Audio controls:false removes controls attribute.
	 */
	public function test_audio_controls_disable(): void {
		$html   = '<figure class="wp-block-audio"><audio controls src="https://example.com/audio.mp3"></audio></figure>';
		$block  = $this->make_block( 'core/audio', [ 'controls' => true ], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'controls' => false ] );

		$this->assertStringNotContainsString( 'controls', $result['innerHTML'] );
	}

	// ── core/code & core/preformatted & core/paragraph ───────────────────

	/**
	 * Code content update replaces inner text.
	 */
	public function test_code_content_update(): void {
		$html   = '<pre class="wp-block-code"><code>old code here</code></pre>';
		$block  = $this->make_block( 'core/code', [ 'content' => 'old code here' ], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'content' => 'new code here' ] );

		$this->assertStringContainsString( 'new code here', $result['innerHTML'] );
	}

	/**
	 * Preformatted content update replaces inner text.
	 */
	public function test_preformatted_content_update(): void {
		$html   = '<pre class="wp-block-preformatted">old text</pre>';
		$block  = $this->make_block( 'core/preformatted', [ 'content' => 'old text' ], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'content' => 'new formatted text' ] );

		$this->assertStringContainsString( 'new formatted text', $result['innerHTML'] );
	}

	/**
	 * Paragraph content update replaces inner text.
	 */
	public function test_paragraph_content_update(): void {
		$html   = '<p>old paragraph text</p>';
		$block  = $this->make_block( 'core/paragraph', [ 'content' => 'old paragraph text' ], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'content' => 'new paragraph text' ] );

		$this->assertSame( '<p>new paragraph text</p>', $result['innerHTML'] );
	}

	// ── Unknown block / no-op guard (AC6) ────────────────────────────────

	/**
	 * AC6: Unknown block returns the block unchanged.
	 */
	public function test_unknown_block_noop(): void {
		$block  = $this->make_block( 'my-plugin/custom-block', [ 'foo' => 'bar' ], [], '<div>Custom</div>' );
		$result = HtmlTransformer::apply( $block, [ 'foo' => 'baz' ] );

		$this->assertSame( '<div>Custom</div>', $result['innerHTML'] );
	}

	/**
	 * Supported block with irrelevant attr changes returns unchanged.
	 */
	public function test_supported_block_irrelevant_attr_noop(): void {
		$block  = $this->make_block( 'core/heading', [ 'level' => 2, 'textAlign' => 'left' ], [], '<h2>Hello</h2>' );
		$result = HtmlTransformer::apply( $block, [ 'textAlign' => 'center' ] );

		$this->assertSame( '<h2>Hello</h2>', $result['innerHTML'] );
	}

	/**
	 * is_supported returns true for known blocks.
	 */
	public function test_is_supported_true(): void {
		$this->assertTrue( HtmlTransformer::is_supported( 'core/heading' ) );
		$this->assertTrue( HtmlTransformer::is_supported( 'core/list' ) );
		$this->assertTrue( HtmlTransformer::is_supported( 'core/image' ) );
		$this->assertTrue( HtmlTransformer::is_supported( 'core/button' ) );
		$this->assertTrue( HtmlTransformer::is_supported( 'core/spacer' ) );
	}

	/**
	 * is_supported returns false for unknown blocks.
	 */
	public function test_is_supported_false(): void {
		$this->assertFalse( HtmlTransformer::is_supported( 'my-plugin/custom' ) );
		$this->assertFalse( HtmlTransformer::is_supported( 'core/columns' ) );
	}

	// ── static_block_attrs_changed warning (AC7) ─────────────────────────

	/**
	 * AC7: update-attrs on unsupported block emits static_block_attrs_changed warning.
	 */
	public function test_mutator_warns_unsupported_block(): void {
		$blocks = [
			$this->make_block( 'core/table', [ 'hasHeader' => false ], [], '<figure class="wp-block-table"><table></table></figure>' ),
		];

		$result = BlockMutator::apply( $blocks, 'update-attrs', [
			'path'       => [ 0 ],
			'attributes' => [ 'hasHeader' => true ],
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( '_warnings', $result );
		$this->assertContains( 'static_block_attrs_changed', $result['_warnings'] );
	}

	/**
	 * update-attrs on a supported block does NOT emit the warning.
	 */
	public function test_mutator_no_warning_supported_block(): void {
		$blocks = [
			$this->make_block( 'core/heading', [ 'level' => 2 ], [], '<h2>Hello</h2>' ),
		];

		$result = BlockMutator::apply( $blocks, 'update-attrs', [
			'path'       => [ 0 ],
			'attributes' => [ 'level' => 3 ],
		] );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( '_warnings', $result );
	}

	// ── wp_kses_post sanitization (AC8) ──────────────────────────────────

	/**
	 * AC8: Final output runs through wp_kses_post (scripts stripped).
	 */
	public function test_kses_strips_script_from_content(): void {
		$html   = '<p>Hello</p>';
		$block  = $this->make_block( 'core/paragraph', [], [], $html );
		$result = HtmlTransformer::apply( $block, [ 'content' => 'Safe text<script>alert(1)</script>' ] );

		$this->assertStringNotContainsString( '<script>', $result['innerHTML'] );
		$this->assertStringContainsString( 'Safe text', $result['innerHTML'] );
	}

	// ── Integration: update-attrs triggers auto-transform ────────────────

	/**
	 * AC1 integration: update-attrs on heading auto-transforms innerHTML.
	 */
	public function test_mutator_heading_auto_transform(): void {
		$blocks = [
			$this->make_block( 'core/heading', [ 'level' => 2 ], [], '<h2>Hello World</h2>' ),
		];

		$result = BlockMutator::apply( $blocks, 'update-attrs', [
			'path'       => [ 0 ],
			'attributes' => [ 'level' => 3 ],
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 3, $result[0]['attrs']['level'] );
		$this->assertSame( '<h3>Hello World</h3>', $result[0]['innerHTML'] );
	}

	/**
	 * AC2 integration: update-attrs on list auto-transforms innerHTML.
	 */
	public function test_mutator_list_auto_transform(): void {
		$html   = '<ul><li>Item 1</li><li>Item 2</li></ul>';
		$blocks = [
			$this->make_block( 'core/list', [ 'ordered' => false ], [], $html ),
		];

		$result = BlockMutator::apply( $blocks, 'update-attrs', [
			'path'       => [ 0 ],
			'attributes' => [ 'ordered' => true ],
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result[0]['attrs']['ordered'] );
		$this->assertStringContainsString( '<ol>', $result[0]['innerHTML'] );
	}

	/**
	 * AC4 integration: update-attrs on button auto-transforms href.
	 */
	public function test_mutator_button_auto_transform(): void {
		$html   = '<div class="wp-block-button"><a class="wp-block-button__link" href="https://old.example.com">Click</a></div>';
		$blocks = [
			$this->make_block( 'core/button', [ 'url' => 'https://old.example.com' ], [], $html ),
		];

		$result = BlockMutator::apply( $blocks, 'update-attrs', [
			'path'       => [ 0 ],
			'attributes' => [ 'url' => 'https://new.example.com' ],
		] );

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'href="https://new.example.com"', $result[0]['innerHTML'] );
	}

	/**
	 * AC5 integration: update-attrs on image auto-transforms src, alt, class.
	 */
	public function test_mutator_image_auto_transform(): void {
		$html   = '<figure class="wp-block-image"><img src="https://old.example.com/old.jpg" alt="Old" class="wp-image-10" /></figure>';
		$blocks = [
			$this->make_block( 'core/image', [ 'url' => 'https://old.example.com/old.jpg', 'id' => 10 ], [], $html ),
		];

		$result = BlockMutator::apply( $blocks, 'update-attrs', [
			'path'       => [ 0 ],
			'attributes' => [ 'id' => 50 ],
		] );

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'wp-image-50', $result[0]['innerHTML'] );
		$this->assertStringNotContainsString( 'wp-image-10', $result[0]['innerHTML'] );
	}

	// ── Edge cases ───────────────────────────────────────────────────────

	/**
	 * Empty innerHTML returns block unchanged.
	 */
	public function test_empty_inner_html_noop(): void {
		$block  = $this->make_block( 'core/heading', [ 'level' => 2 ], [], '' );
		$result = HtmlTransformer::apply( $block, [ 'level' => 3 ] );

		$this->assertSame( '', $result['innerHTML'] );
	}

	/**
	 * Empty blockName returns block unchanged.
	 */
	public function test_empty_block_name_noop(): void {
		$block  = $this->make_block( '', [], [], '<p>Content</p>' );
		$result = HtmlTransformer::apply( $block, [ 'level' => 3 ] );

		$this->assertSame( '<p>Content</p>', $result['innerHTML'] );
	}

	/**
	 * Container block with innerBlocks preserves null slots in innerContent.
	 */
	public function test_container_inner_content_preserves_nulls(): void {
		$child  = $this->make_block( 'core/paragraph', [], [], '<p>Child</p>' );
		$html   = '<div class="wp-block-group"></div>';
		$block  = $this->make_block( 'core/group', [ 'tagName' => 'div' ], [ $child ], $html );
		$result = HtmlTransformer::apply( $block, [ 'tagName' => 'section' ] );

		// innerContent should have opening HTML, null (for child), closing HTML.
		$null_count = count( array_filter( $result['innerContent'], 'is_null' ) );
		$this->assertSame( 1, $null_count );
		$this->assertStringContainsString( '<section', $result['innerContent'][0] );
	}
}
