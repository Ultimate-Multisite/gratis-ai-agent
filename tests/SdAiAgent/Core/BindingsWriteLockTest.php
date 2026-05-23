<?php
/**
 * Test case for Block Bindings API write-lock (GH#1751).
 *
 * Covers the acceptance criteria:
 *   AC1: Bound content + update-attrs → WP_Error('bound_attribute') with data.bound_attributes.
 *   AC2: Same with allow_bound_writes: true → succeeds.
 *   AC3: Non-bound key writes still work.
 *   AC4: Atomic batch with one bound violation → entire batch rejects.
 *   AC5: replace-block removing the binding → subsequent writes allowed.
 *   AC8: Writing metadata itself is NOT locked.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1751
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\BlockMutator;
use SdAiAgent\Core\BlockReferences;
use WP_UnitTestCase;

/**
 * Integration tests for Block Bindings write-lock in BlockMutator.
 */
class BindingsWriteLockTest extends WP_UnitTestCase {

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

	/**
	 * Build a block with a stable sd_ref.
	 *
	 * @param string              $name  Block name.
	 * @param string              $ref   sd_ref value.
	 * @param array<string,mixed> $attrs Additional attributes.
	 * @return array<string,mixed>
	 */
	private function make_ref_block( string $name, string $ref, array $attrs = [] ): array {
		$attrs['metadata'][ BlockReferences::REF_KEY ] = $ref;
		return $this->make_block( $name, $attrs );
	}

	/**
	 * Build a block with bindings on the specified keys.
	 *
	 * @param string              $name        Block name.
	 * @param string              $ref         sd_ref value.
	 * @param array<string,mixed> $bindings    Bindings map (key => source config).
	 * @param array<string,mixed> $extra_attrs Additional top-level attributes.
	 * @return array<string,mixed>
	 */
	private function make_bound_block( string $name, string $ref, array $bindings, array $extra_attrs = [] ): array {
		$attrs = array_merge( $extra_attrs, [
			'metadata' => [
				BlockReferences::REF_KEY => $ref,
				'bindings'               => $bindings,
			],
		] );
		return $this->make_block( $name, $attrs );
	}

	// ── AC1: Bound content + update-attrs → WP_Error ──────────────────────

	/**
	 * Writing a bound attribute returns bound_attribute error.
	 */
	public function test_update_attrs_rejects_bound_attribute(): void {
		$bindings = [
			'content' => [ 'source' => 'core/post-meta', 'args' => [ 'key' => 'subtitle' ] ],
		];
		$blocks = [ $this->make_bound_block( 'core/paragraph', 'blk_test1234', $bindings ) ];

		$result = BlockMutator::apply( $blocks, 'update-attrs', [
			'ref'        => 'blk_test1234',
			'attributes' => [ 'content' => 'agent overwrite' ],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bound_attribute', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertContains( 'content', $data['bound_attributes'] );
		$this->assertSame( 'blk_test1234', $data['block_ref'] );
	}

	/**
	 * Writing multiple bound attributes surfaces all violations.
	 */
	public function test_update_attrs_rejects_multiple_bound_attributes(): void {
		$bindings = [
			'content' => [ 'source' => 'core/post-meta', 'args' => [ 'key' => 'subtitle' ] ],
			'url'     => [ 'source' => 'core/post-meta', 'args' => [ 'key' => 'link' ] ],
		];
		$blocks = [ $this->make_bound_block( 'core/paragraph', 'blk_multi123', $bindings ) ];

		$result = BlockMutator::apply( $blocks, 'update-attrs', [
			'ref'        => 'blk_multi123',
			'attributes' => [ 'content' => 'new', 'url' => 'https://example.com' ],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bound_attribute', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertContains( 'content', $data['bound_attributes'] );
		$this->assertContains( 'url', $data['bound_attributes'] );
	}

	// ── AC2: allow_bound_writes: true → succeeds ──────────────────────────

	/**
	 * With allow_bound_writes: true, writing bound attrs succeeds.
	 */
	public function test_update_attrs_with_allow_bound_writes_succeeds(): void {
		$bindings = [
			'content' => [ 'source' => 'core/post-meta', 'args' => [ 'key' => 'subtitle' ] ],
		];
		$blocks = [ $this->make_bound_block( 'core/paragraph', 'blk_allowed1', $bindings ) ];

		$result = BlockMutator::apply( $blocks, 'update-attrs', [
			'ref'                => 'blk_allowed1',
			'attributes'         => [ 'content' => 'force write' ],
			'allow_bound_writes' => true,
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'force write', $result[0]['attrs']['content'] );
	}

	// ── AC3: Non-bound key writes still work ──────────────────────────────

	/**
	 * Writing a non-bound attribute on a block with bindings succeeds.
	 */
	public function test_update_attrs_non_bound_key_succeeds(): void {
		$bindings = [
			'content' => [ 'source' => 'core/post-meta', 'args' => [ 'key' => 'subtitle' ] ],
		];
		$blocks = [ $this->make_bound_block( 'core/paragraph', 'blk_nonbound', $bindings ) ];

		$result = BlockMutator::apply( $blocks, 'update-attrs', [
			'ref'        => 'blk_nonbound',
			'attributes' => [ 'className' => 'highlight' ],
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'highlight', $result[0]['attrs']['className'] );
	}

	// ── AC4: Atomic batch with one bound violation → entire batch rejects ─

	/**
	 * Batch with one bound-attribute violation rejects the entire batch.
	 */
	public function test_batch_rejects_entirely_on_single_bound_violation(): void {
		$bindings = [
			'content' => [ 'source' => 'core/post-meta', 'args' => [ 'key' => 'subtitle' ] ],
		];
		$blocks = [
			$this->make_ref_block( 'core/heading', 'blk_heading1', [ 'level' => 2 ] ),
			$this->make_bound_block( 'core/paragraph', 'blk_bound001', $bindings ),
		];

		$updates = [
			// Update 0: valid write to unbound heading block.
			[
				'op'         => 'update-attrs',
				'ref'        => 'blk_heading1',
				'attributes' => [ 'level' => 3 ],
			],
			// Update 1: invalid write to bound paragraph content.
			[
				'op'         => 'update-attrs',
				'ref'        => 'blk_bound001',
				'attributes' => [ 'content' => 'bad write' ],
			],
		];

		$result = BlockMutator::apply_batch( $blocks, $updates );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'batch_validation_failed', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertNotEmpty( $data['errors'] );

		// The bound violation should be reported.
		$error_codes = array_column( $data['errors'], 'code' );
		$this->assertContains( 'bound_attribute', $error_codes );
	}

	// ── AC5: replace-block removing binding → subsequent writes allowed ────

	/**
	 * After replace-block removes bindings, writing the previously bound key succeeds.
	 */
	public function test_replace_block_removes_binding_then_write_succeeds(): void {
		$bindings = [
			'content' => [ 'source' => 'core/post-meta', 'args' => [ 'key' => 'subtitle' ] ],
		];
		$blocks = [ $this->make_bound_block( 'core/paragraph', 'blk_replace1', $bindings ) ];

		// Replace with a new block that has no bindings.
		$new_block_def = [
			'blockName'    => 'core/paragraph',
			'attrs'        => [
				'metadata' => [ BlockReferences::REF_KEY => 'blk_replace1' ],
			],
			'innerBlocks'  => [],
			'innerHTML'    => '<p>Replaced</p>',
			'innerContent' => [ '<p>Replaced</p>' ],
		];

		$result = BlockMutator::apply( $blocks, 'replace-block', [
			'ref'       => 'blk_replace1',
			'block_def' => $new_block_def,
		] );

		$this->assertIsArray( $result );

		// Now write to the previously-bound key on the replaced tree.
		$result2 = BlockMutator::apply( $result, 'update-attrs', [
			'ref'        => 'blk_replace1',
			'attributes' => [ 'content' => 'now writable' ],
		] );

		$this->assertIsArray( $result2 );
		$this->assertSame( 'now writable', $result2[0]['attrs']['content'] );
	}

	// ── AC8: Writing metadata itself is NOT locked ─────────────────────────

	/**
	 * Writing the metadata key (which carries bindings + sd_ref) is allowed.
	 */
	public function test_writing_metadata_itself_is_allowed(): void {
		$bindings = [
			'content' => [ 'source' => 'core/post-meta', 'args' => [ 'key' => 'subtitle' ] ],
		];
		$blocks = [ $this->make_bound_block( 'core/paragraph', 'blk_metawrite', $bindings ) ];

		$new_metadata = [
			BlockReferences::REF_KEY => 'blk_metawrite',
			'bindings'               => [
				'content' => [ 'source' => 'core/post-meta', 'args' => [ 'key' => 'subtitle' ] ],
			],
		];

		$result = BlockMutator::apply( $blocks, 'update-attrs', [
			'ref'        => 'blk_metawrite',
			'attributes' => [ 'metadata' => $new_metadata ],
		] );

		$this->assertIsArray( $result );
		$this->assertSame( $new_metadata, $result[0]['attrs']['metadata'] );
	}

	// ── assert_no_bound_attribute_writes() unit tests ─────────────────────

	/**
	 * No bindings means no violation.
	 */
	public function test_assert_passes_when_no_bindings(): void {
		$block = $this->make_ref_block( 'core/paragraph', 'blk_nobind01' );

		$result = BlockMutator::assert_no_bound_attribute_writes(
			$block,
			[ 'content' => 'anything' ]
		);

		$this->assertTrue( $result );
	}

	/**
	 * Empty new_attrs means no violation even with bindings.
	 */
	public function test_assert_passes_when_no_attrs_written(): void {
		$bindings = [
			'content' => [ 'source' => 'core/post-meta', 'args' => [ 'key' => 'x' ] ],
		];
		$block = $this->make_bound_block( 'core/paragraph', 'blk_empty001', $bindings );

		$result = BlockMutator::assert_no_bound_attribute_writes(
			$block,
			[]
		);

		$this->assertTrue( $result );
	}

	/**
	 * allow_bound_writes=true bypasses the check entirely.
	 */
	public function test_assert_passes_when_override_set(): void {
		$bindings = [
			'content' => [ 'source' => 'core/post-meta', 'args' => [ 'key' => 'x' ] ],
		];
		$block = $this->make_bound_block( 'core/paragraph', 'blk_ovr00001', $bindings );

		$result = BlockMutator::assert_no_bound_attribute_writes(
			$block,
			[ 'content' => 'overwrite' ],
			true
		);

		$this->assertTrue( $result );
	}
}
