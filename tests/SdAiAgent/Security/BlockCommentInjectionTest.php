<?php

declare(strict_types=1);
/**
 * Block comment injection security tests.
 *
 * Feeds innerHTML payloads containing WordPress block-comment delimiters
 * (`<!-- wp:xxx -->`, `<!-- /wp:xxx -->`) into write operations and verifies
 * that the resulting post content is safe.
 *
 * Background: `wp_kses_post()` strips dangerous HTML tags (`<script>`, event
 * handlers, etc.) but does NOT strip HTML comments.  WordPress block comment
 * delimiters (`<!-- wp:html -->`) are HTML comments.  If such a delimiter
 * survives in innerHTML and the post_content is re-parsed, `parse_blocks()`
 * may interpret the delimiter as a real block boundary, creating extra blocks
 * that were not intended by the original content.
 *
 * What IS tested here (and passes):
 *   - `wp_kses_post()` strips the dangerous script payload in every case.
 *   - The saved post_content contains no executable JavaScript.
 *   - parse_blocks() on safe content does not produce unintended blocks.
 *
 * What is marked incomplete (GH#1804):
 *   - The assertion that no extra `core/html` block is created when block
 *     comment delimiters survive sanitisation. That gap requires a custom
 *     block-comment-stripping step beyond `wp_kses_post()`.
 *
 * @package SdAiAgent
 * @subpackage Tests\Security
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1789
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1804
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Security;

use SdAiAgent\Abilities\BlockAbilities;
use SdAiAgent\Core\BlockMutator;
use WP_UnitTestCase;

/**
 * Block comment injection tests for block write operations.
 *
 * @group security
 * @group block-injection
 *
 * @since 1.11.0
 */
class BlockCommentInjectionTest extends WP_UnitTestCase {

	/**
	 * Administrator user ID for tests that need authenticated context.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Set up admin user before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Restore user context after each test.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Build a minimal single-paragraph block tree for direct mutator tests.
	 *
	 * @param string $html Initial safe innerHTML.
	 * @return array<int, mixed>
	 */
	private function make_blocks( string $html = '<p>Original.</p>' ): array {
		return [
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [
					'metadata' => [ 'sd_ref' => 'blk_test_block_comment' ],
				],
				'innerBlocks'  => [],
				'innerHTML'    => $html,
				'innerContent' => [ $html ],
			],
		];
	}

	/**
	 * Count blocks of a given name in a parsed block tree (recursive).
	 *
	 * @param array<int, mixed> $blocks Parsed blocks array.
	 * @param string            $name   Block type name (e.g. 'core/html').
	 * @return int Number of matching blocks.
	 */
	private function count_blocks_by_name( array $blocks, string $name ): int {
		$count = 0;
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( isset( $block['blockName'] ) && $block['blockName'] === $name ) {
				$count++;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$count += $this->count_blocks_by_name( $block['innerBlocks'], $name );
			}
		}
		return $count;
	}

	// ── wp_kses_post strips the script despite block comment delimiters ──

	/**
	 * wp_kses_post strips script tag even when block comment delimiters surround it.
	 *
	 * This confirms the first line of defence: the script payload is removed
	 * even in the block-comment injection scenario.
	 */
	public function test_kses_strips_script_in_block_comment_injection_payload(): void {
		$payload   = '<p>safe</p><!-- wp:html --><script>alert(1)</script><!-- /wp:html -->';
		$sanitised = wp_kses_post( $payload );

		// The <script> executable tag must be stripped.  The text content
		// "alert(1)" may survive as inert text within the block comments — that
		// is safe; it cannot execute without the <script> wrapper.
		$this->assertStringNotContainsString( '<script', $sanitised );
	}

	/**
	 * wp_kses_post strips the closing-tag injection variant.
	 *
	 * Payload: `<!-- /wp:paragraph --><!-- wp:html --><script>x</script><!-- /wp:html -->`
	 * This attempts to close the parent paragraph block prematurely.
	 */
	public function test_kses_strips_script_in_closing_tag_injection(): void {
		$payload   = '<p>safe</p><!-- /wp:paragraph --><!-- wp:html --><script>alert(1)</script><!-- /wp:html -->';
		$sanitised = wp_kses_post( $payload );

		// Executable <script> tag must be stripped.  Inert text "alert(1)"
		// may survive inside the HTML comment delimiters — safe without a script wrapper.
		$this->assertStringNotContainsString( '<script', $sanitised );
	}

	// ── BlockMutator: script stripped in update-html with block comments ─

	/**
	 * op=update-html with block-comment injection payload strips the script.
	 *
	 * The block comment delimiters survive `wp_kses_post` (they are valid HTML
	 * comments), but the dangerous `<script>` is removed. This test verifies
	 * that the first-line sanitisation works at the mutator layer.
	 */
	public function test_update_html_strips_script_in_block_comment_payload(): void {
		$blocks  = $this->make_blocks();
		$payload = '<p>safe</p><!-- wp:html --><script>alert(1)</script><!-- /wp:html -->';

		$result = BlockMutator::apply_batch(
			$blocks,
			[
				[
					'op'        => 'update-html',
					'flat_index' => 0,
					'innerHTML' => $payload,
				],
			]
		);

		$this->assertIsArray( $result );
		$mutated_html = (string) ( $result[0]['innerHTML'] ?? '' );

		// The executable <script> tag must be stripped. Inert text "alert(1)"
		// may survive within the block comment as text — safe without its wrapper.
		$this->assertStringNotContainsString( '<script', $mutated_html );
	}

	/**
	 * Safe content round-trips through parse → serialize → re-parse unchanged.
	 *
	 * A paragraph with safe HTML should survive a full round-trip without
	 * producing any unexpected blocks.
	 */
	public function test_safe_content_round_trip_produces_no_extra_blocks(): void {
		$safe = "<!-- wp:paragraph -->\n<p>This is safe content with no injection.</p>\n<!-- /wp:paragraph -->";

		$parsed      = parse_blocks( $safe );
		$serialised  = serialize_blocks( $parsed );
		$re_parsed   = parse_blocks( $serialised );

		// Only one named block expected.
		$named = array_values( array_filter( $re_parsed, fn( $b ) => ! empty( $b['blockName'] ) ) );
		$this->assertCount( 1, $named );
		$this->assertSame( 'core/paragraph', $named[0]['blockName'] );
	}

	/**
	 * Block comment injection in innerHTML creates an unintended core/html block.
	 *
	 * This test documents the gap tracked in GH#1804.  After wp_kses_post strips
	 * the `<script>`, the block comment delimiters (`<!-- wp:html -->`) survive.
	 * When the resulting post_content is re-parsed, `parse_blocks()` interprets
	 * the delimiters as real block boundaries and creates an extra `core/html`
	 * block (with empty innerHTML since the script was stripped).
	 *
	 * The fix (GH#1804) must add a block-comment-stripping step in
	 * `BlockMutator::sanitize_block_tree()` so the delimiters are escaped
	 * before serialisation.
	 *
	 * @see https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1804
	 */
	public function test_block_comment_injection_does_not_create_core_html_block(): void {
		$this->markTestIncomplete(
			'GH#1804: Block comment delimiters in innerHTML survive wp_kses_post() and cause ' .
			'parse_blocks() to create an unintended core/html block. Fix by escaping ' .
			'<!-- wp:xxx --> patterns in BlockMutator::sanitize_block_tree().'
		);

		$post_id = self::factory()->post->create( [
			'post_status'  => 'draft',
			'post_content' => "<!-- wp:paragraph -->\n<p>Original.</p>\n<!-- /wp:paragraph -->",
		] );

		$page_blocks = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => true,
		] );

		$this->assertIsArray( $page_blocks );
		$this->assertNotEmpty( $page_blocks['blocks'] );
		$ref = $page_blocks['blocks'][0]['ref'] ?? '';
		$this->assertNotEmpty( $ref );

		// Injection payload: block comment delimiter + script.
		// wp_kses_post strips <script> but leaves <!-- wp:html -->.
		$payload = '<p>safe</p><!-- wp:html --><script>alert(1)</script><!-- /wp:html -->';

		BlockAbilities::handle_update_blocks( [
			'post_id' => $post_id,
			'updates' => [
				[
					'op'        => 'update-html',
					'ref'       => $ref,
					'innerHTML' => $payload,
				],
			],
		] );

		// Re-read and re-parse the saved post content.
		$saved_post = get_post( $post_id );
		$re_parsed  = parse_blocks( $saved_post->post_content );

		// Assert: no core/html block was created.
		$html_block_count = $this->count_blocks_by_name( $re_parsed, 'core/html' );
		$this->assertSame(
			0,
			$html_block_count,
			'Block comment injection must not create an unintended core/html block. ' .
			'See GH#1804 for the sanitise_block_tree() fix.'
		);
	}

	/**
	 * Closing-tag injection does not break block structure.
	 *
	 * A `<!-- /wp:paragraph -->` inside innerHTML prematurely closes the parent
	 * block comment, potentially splitting the paragraph and creating sibling
	 * blocks. This test documents the gap (see GH#1804).
	 *
	 * @see https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1804
	 */
	public function test_closing_tag_injection_does_not_break_block_structure(): void {
		$this->markTestIncomplete(
			'GH#1804: A <!-- /wp:paragraph --> inside innerHTML prematurely closes the ' .
			'surrounding block, potentially creating extra blocks when re-parsed. ' .
			'Fix: escape block-comment patterns in BlockMutator::sanitize_block_tree().'
		);

		$post_id = self::factory()->post->create( [
			'post_status'  => 'draft',
			'post_content' => "<!-- wp:paragraph -->\n<p>Original.</p>\n<!-- /wp:paragraph -->",
		] );

		$page_blocks = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => true,
		] );

		$ref = $page_blocks['blocks'][0]['ref'] ?? '';
		$this->assertNotEmpty( $ref );

		// Attempt to close the paragraph early with a closing tag inside innerHTML.
		$payload = '<p>safe</p><!-- /wp:paragraph --><!-- wp:html --><script>alert(1)</script><!-- /wp:html --><p>';

		BlockAbilities::handle_update_blocks( [
			'post_id' => $post_id,
			'updates' => [
				[
					'op'        => 'update-html',
					'ref'       => $ref,
					'innerHTML' => $payload,
				],
			],
		] );

		$saved_post = get_post( $post_id );
		$re_parsed  = parse_blocks( $saved_post->post_content );

		// Only named blocks — expect exactly 1 (the original paragraph).
		$named = array_values( array_filter( $re_parsed, fn( $b ) => ! empty( $b['blockName'] ) ) );
		$this->assertCount(
			1,
			$named,
			'Closing-tag injection must not create extra blocks. See GH#1804.'
		);
		$this->assertSame( 'core/paragraph', $named[0]['blockName'] );
	}
}
