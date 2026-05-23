<?php
/**
 * Test case for Block Bindings read-side surfacing (GH#1751).
 *
 * Covers acceptance criteria:
 *   AC7: get-page-blocks response includes `bindings` and `bound_attributes`
 *        on bound blocks; absent on unbound blocks.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1751
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Abilities\BlockAbilities;
use SdAiAgent\Core\BlockReferences;
use WP_UnitTestCase;

/**
 * Integration tests for Block Bindings read-surface in get-page-blocks.
 */
class BindingsReadSurfaceTest extends WP_UnitTestCase {

	/**
	 * get-page-blocks includes bindings and bound_attributes for bound blocks.
	 */
	public function test_bound_block_surfaces_bindings_in_response(): void {
		// Create a post with a block that has bindings.
		$block_content = '<!-- wp:paragraph {"metadata":{"' . BlockReferences::REF_KEY . '":"blk_readsurf","bindings":{"content":{"source":"core/post-meta","args":{"key":"subtitle"}}}}} -->'
			. "\n<p>Bound paragraph</p>\n"
			. '<!-- /wp:paragraph -->';

		$post_id = self::factory()->post->create( [
			'post_content' => $block_content,
			'post_status'  => 'publish',
		] );

		$result = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => false,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'blocks', $result );
		$this->assertNotEmpty( $result['blocks'] );

		$block_entry = $result['blocks'][0];

		// Verify bindings are surfaced.
		$this->assertArrayHasKey( 'bindings', $block_entry );
		$this->assertArrayHasKey( 'bound_attributes', $block_entry );
		$this->assertSame( [ 'content' ], $block_entry['bound_attributes'] );
		$this->assertArrayHasKey( 'content', $block_entry['bindings'] );
		$this->assertSame( 'core/post-meta', $block_entry['bindings']['content']['source'] );
	}

	/**
	 * get-page-blocks does NOT include bindings/bound_attributes for unbound blocks.
	 */
	public function test_unbound_block_has_no_bindings_in_response(): void {
		$block_content = '<!-- wp:paragraph {"metadata":{"' . BlockReferences::REF_KEY . '":"blk_unbnd001"}} -->'
			. "\n<p>Normal paragraph</p>\n"
			. '<!-- /wp:paragraph -->';

		$post_id = self::factory()->post->create( [
			'post_content' => $block_content,
			'post_status'  => 'publish',
		] );

		$result = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => false,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'blocks', $result );
		$this->assertNotEmpty( $result['blocks'] );

		$block_entry = $result['blocks'][0];

		// Verify bindings are NOT present.
		$this->assertArrayNotHasKey( 'bindings', $block_entry );
		$this->assertArrayNotHasKey( 'bound_attributes', $block_entry );
	}

	/**
	 * Multiple bindings are all surfaced.
	 */
	public function test_multiple_bindings_surfaced(): void {
		$block_content = '<!-- wp:image {"metadata":{"' . BlockReferences::REF_KEY . '":"blk_multibnd","bindings":{"url":{"source":"core/post-meta","args":{"key":"hero_image"}},"alt":{"source":"core/post-meta","args":{"key":"hero_alt"}}}}} -->'
			. "\n<figure class=\"wp-block-image\"><img src=\"\" alt=\"\"/></figure>\n"
			. '<!-- /wp:image -->';

		$post_id = self::factory()->post->create( [
			'post_content' => $block_content,
			'post_status'  => 'publish',
		] );

		$result = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => false,
		] );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['blocks'] );

		$block_entry = $result['blocks'][0];

		$this->assertArrayHasKey( 'bindings', $block_entry );
		$this->assertArrayHasKey( 'bound_attributes', $block_entry );
		$this->assertCount( 2, $block_entry['bound_attributes'] );
		$this->assertContains( 'url', $block_entry['bound_attributes'] );
		$this->assertContains( 'alt', $block_entry['bound_attributes'] );
	}

	/**
	 * Bindings are surfaced even when fields allowlist is used (if bindings is in the list).
	 */
	public function test_bindings_surfaced_with_fields_allowlist(): void {
		$block_content = '<!-- wp:paragraph {"metadata":{"' . BlockReferences::REF_KEY . '":"blk_fldtest","bindings":{"content":{"source":"core/post-meta","args":{"key":"sub"}}}}} -->'
			. "\n<p>Hello</p>\n"
			. '<!-- /wp:paragraph -->';

		$post_id = self::factory()->post->create( [
			'post_content' => $block_content,
			'post_status'  => 'publish',
		] );

		$result = BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => false,
			'fields'       => 'name,ref,bindings,bound_attributes',
		] );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['blocks'] );

		$block_entry = $result['blocks'][0];

		$this->assertArrayHasKey( 'bindings', $block_entry );
		$this->assertArrayHasKey( 'bound_attributes', $block_entry );
		// Fields allowlist should strip other keys.
		$this->assertArrayNotHasKey( 'attributes', $block_entry );
		$this->assertArrayNotHasKey( 'text_preview', $block_entry );
	}
}
