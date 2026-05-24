<?php
/**
 * Test case for BlockEnricherRegistry (t266).
 *
 * Covers:
 * - Register/unregister/has/get_registered_ids.
 * - Dispatch order (registration order preserved).
 * - Multiple enrichers per block name.
 * - Last-write-wins when two enrichers share the same ID.
 * - Third-party action hook fires once.
 * - enrich_block_tree recurses into innerBlocks.
 * - Blocks without matching enrichers are left unchanged.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\BlockEnricherInterface;
use SdAiAgent\Core\BlockEnricherRegistry;
use WP_UnitTestCase;

/**
 * Unit tests for BlockEnricherRegistry.
 */
class BlockEnricherRegistryTest extends WP_UnitTestCase {

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Create a stub enricher implementing BlockEnricherInterface.
	 *
	 * @param string               $id         Enricher ID.
	 * @param string               $block_name Block name to support.
	 * @param array<string,mixed>  $data       Data to return from enrich().
	 * @return BlockEnricherInterface
	 */
	private function make_enricher( string $id, string $block_name, array $data = [] ): BlockEnricherInterface {
		return new class( $id, $block_name, $data ) implements BlockEnricherInterface {
			private string $id;
			private string $block_name;
			/** @var array<string,mixed> */
			private array $data;

			/**
			 * Constructor.
			 *
			 * @param string              $id         Enricher ID.
			 * @param string              $block_name Block name.
			 * @param array<string,mixed> $data       Enrichment data.
			 */
			public function __construct( string $id, string $block_name, array $data ) {
				$this->id         = $id;
				$this->block_name = $block_name;
				$this->data       = $data;
			}

			public function get_id(): string {
				return $this->id;
			}

			public function supports( string $block_name ): bool {
				return $this->block_name === $block_name;
			}

			public function enrich( array $block, array $context ): array {
				return $this->data;
			}
		};
	}

	/**
	 * Build a minimal parsed-block array.
	 *
	 * @param string              $name         Block name.
	 * @param array<string,mixed> $attrs        Block attributes.
	 * @param array<int,mixed>    $inner_blocks Inner blocks.
	 * @return array<string,mixed>
	 */
	private function make_block( string $name, array $attrs = [], array $inner_blocks = [] ): array {
		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => '<p>test</p>',
			'innerContent' => [ '<p>test</p>' ],
		];
	}

	// ── Registration ──────────────────────────────────────────────────────

	/**
	 * register() adds an enricher; has() returns true.
	 */
	public function test_register_and_has(): void {
		$registry = new BlockEnricherRegistry();
		$enricher = $this->make_enricher( 'test_a', 'core/paragraph' );

		$this->assertFalse( $registry->has( 'test_a' ) );
		$registry->register( $enricher );
		$this->assertTrue( $registry->has( 'test_a' ) );
	}

	/**
	 * unregister() removes an enricher; returns true when found.
	 */
	public function test_unregister(): void {
		$registry = new BlockEnricherRegistry();
		$enricher = $this->make_enricher( 'test_b', 'core/image' );

		$registry->register( $enricher );
		$this->assertTrue( $registry->unregister( 'test_b' ) );
		$this->assertFalse( $registry->has( 'test_b' ) );
	}

	/**
	 * unregister() returns false for unknown ID.
	 */
	public function test_unregister_unknown_returns_false(): void {
		$registry = new BlockEnricherRegistry();
		$this->assertFalse( $registry->unregister( 'nonexistent' ) );
	}

	/**
	 * get_registered_ids() returns all registered enricher IDs.
	 */
	public function test_get_registered_ids(): void {
		$registry = new BlockEnricherRegistry();
		$registry->register( $this->make_enricher( 'alpha', 'core/paragraph' ) );
		$registry->register( $this->make_enricher( 'beta', 'core/heading' ) );

		$ids = $registry->get_registered_ids();
		$this->assertContains( 'alpha', $ids );
		$this->assertContains( 'beta', $ids );
		$this->assertCount( 2, $ids );
	}

	// ── Dispatch ──────────────────────────────────────────────────────────

	/**
	 * enrich() adds enriched.<id> for matching blocks.
	 */
	public function test_enrich_adds_enriched_key(): void {
		$registry = new BlockEnricherRegistry();
		$registry->register( $this->make_enricher( 'test_para', 'core/paragraph', [ 'word_count' => 42 ] ) );

		$block  = $this->make_block( 'core/paragraph' );
		$result = $registry->enrich( $block, [] );

		$this->assertArrayHasKey( 'enriched', $result );
		$this->assertArrayHasKey( 'test_para', $result['enriched'] );
		$this->assertSame( 42, $result['enriched']['test_para']['word_count'] );
	}

	/**
	 * enrich() does not add enriched key for non-matching blocks.
	 */
	public function test_enrich_skips_non_matching_blocks(): void {
		$registry = new BlockEnricherRegistry();
		$registry->register( $this->make_enricher( 'test_img', 'core/image', [ 'url' => 'test.jpg' ] ) );

		$block  = $this->make_block( 'core/heading' );
		$result = $registry->enrich( $block, [] );

		$this->assertArrayNotHasKey( 'enriched', $result );
	}

	/**
	 * enrich() skips blocks without a blockName.
	 */
	public function test_enrich_skips_empty_block_name(): void {
		$registry = new BlockEnricherRegistry();
		$registry->register( $this->make_enricher( 'test_para', 'core/paragraph', [ 'x' => 1 ] ) );

		$block = [
			'blockName'    => null,
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];

		$result = $registry->enrich( $block, [] );
		$this->assertArrayNotHasKey( 'enriched', $result );
	}

	/**
	 * Multiple enrichers per block name — all fire in registration order.
	 */
	public function test_multiple_enrichers_per_block(): void {
		$registry = new BlockEnricherRegistry();
		$registry->register( $this->make_enricher( 'enricher_a', 'core/image', [ 'source' => 'a' ] ) );
		$registry->register( $this->make_enricher( 'enricher_b', 'core/image', [ 'source' => 'b' ] ) );

		$block  = $this->make_block( 'core/image' );
		$result = $registry->enrich( $block, [] );

		$this->assertArrayHasKey( 'enriched', $result );
		$this->assertSame( 'a', $result['enriched']['enricher_a']['source'] );
		$this->assertSame( 'b', $result['enriched']['enricher_b']['source'] );
	}

	/**
	 * Last-write-wins: re-registering with the same ID replaces.
	 */
	public function test_last_write_wins_same_id(): void {
		$registry = new BlockEnricherRegistry();
		$registry->register( $this->make_enricher( 'dup', 'core/paragraph', [ 'v' => 1 ] ) );
		$registry->register( $this->make_enricher( 'dup', 'core/paragraph', [ 'v' => 2 ] ) );

		$block  = $this->make_block( 'core/paragraph' );
		$result = $registry->enrich( $block, [] );

		$this->assertSame( 2, $result['enriched']['dup']['v'] );
		$this->assertCount( 1, $registry->get_registered_ids() );
	}

	// ── enrich_block_tree ─────────────────────────────────────────────────

	/**
	 * enrich_block_tree() recurses into innerBlocks.
	 */
	public function test_enrich_block_tree_recurses(): void {
		$registry = new BlockEnricherRegistry();
		$registry->register( $this->make_enricher( 'img_data', 'core/image', [ 'url' => 'nested.jpg' ] ) );

		$inner_image = $this->make_block( 'core/image', [ 'id' => 1 ] );
		$group       = $this->make_block( 'core/group', [], [ $inner_image ] );
		$tree        = [ $group ];

		$result = $registry->enrich_block_tree( $tree, [] );

		// The root group should not have enriched (no enricher for core/group).
		$this->assertArrayNotHasKey( 'enriched', $result[0] );

		// The inner image block should be enriched.
		$this->assertArrayHasKey( 'enriched', $result[0]['innerBlocks'][0] );
		$this->assertSame( 'nested.jpg', $result[0]['innerBlocks'][0]['enriched']['img_data']['url'] );
	}

	/**
	 * enrich_block_tree() handles mixed block types.
	 */
	public function test_enrich_block_tree_mixed_types(): void {
		$registry = new BlockEnricherRegistry();
		$registry->register( $this->make_enricher( 'para_meta', 'core/paragraph', [ 'chars' => 100 ] ) );

		$blocks = [
			$this->make_block( 'core/paragraph' ),
			$this->make_block( 'core/heading' ),
			$this->make_block( 'core/paragraph' ),
		];

		$result = $registry->enrich_block_tree( $blocks, [] );

		$this->assertArrayHasKey( 'enriched', $result[0] );
		$this->assertArrayNotHasKey( 'enriched', $result[1] );
		$this->assertArrayHasKey( 'enriched', $result[2] );
	}

	// ── Action hook ───────────────────────────────────────────────────────

	/**
	 * fire_registration_action() fires the action with the registry instance.
	 */
	public function test_fire_registration_action_fires_once(): void {
		$registry = new BlockEnricherRegistry();
		$call_count = 0;
		$received_registry = null;

		add_action(
			'sd_ai_agent_register_block_enrichers',
			function ( $reg ) use ( &$call_count, &$received_registry ) {
				++$call_count;
				$received_registry = $reg;
			}
		);

		$registry->fire_registration_action();
		$registry->fire_registration_action(); // Should not fire again.

		$this->assertSame( 1, $call_count );
		$this->assertSame( $registry, $received_registry );
	}

	/**
	 * Third-party enricher registered via action hook is invoked.
	 */
	public function test_third_party_enricher_via_action(): void {
		$registry = new BlockEnricherRegistry();
		$enricher = $this->make_enricher( 'third_party', 'core/quote', [ 'citation' => 'test' ] );

		add_action(
			'sd_ai_agent_register_block_enrichers',
			function ( BlockEnricherRegistry $reg ) use ( $enricher ) {
				$reg->register( $enricher );
			}
		);

		$registry->fire_registration_action();

		$this->assertTrue( $registry->has( 'third_party' ) );

		$block  = $this->make_block( 'core/quote' );
		$result = $registry->enrich( $block, [] );

		$this->assertSame( 'test', $result['enriched']['third_party']['citation'] );
	}

	// ── Context passthrough ───────────────────────────────────────────────

	/**
	 * Context is passed through to the enricher's enrich() method.
	 */
	public function test_context_passed_to_enricher(): void {
		$captured_context = null;
		$enricher = new class( $captured_context ) implements BlockEnricherInterface {
			/** @var array<string,mixed>|null */
			private $captured;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed>|null &$captured Reference for capturing context.
			 */
			public function __construct( &$captured ) {
				$this->captured = &$captured;
			}

			public function get_id(): string {
				return 'ctx_test';
			}

			public function supports( string $block_name ): bool {
				return 'core/paragraph' === $block_name;
			}

			public function enrich( array $block, array $context ): array {
				$this->captured = $context;

				return [ 'ok' => true ];
			}
		};

		$registry = new BlockEnricherRegistry();
		$registry->register( $enricher );

		$block   = $this->make_block( 'core/paragraph' );
		$context = [ 'post_id' => 42, 'render' => true ];
		$registry->enrich( $block, $context );

		$this->assertSame( 42, $captured_context['post_id'] );
		$this->assertTrue( $captured_context['render'] );
	}
}
