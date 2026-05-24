<?php
/**
 * Registry for block enrichers.
 *
 * Manages a collection of BlockEnricherInterface instances and runs
 * them against parsed blocks during read-side serialisation (e.g.
 * `get-page-blocks`). Each enricher's output is namespaced under
 * `block.enriched.<enricher_id>` so it never collides with `attributes`
 * or `innerHTML`.
 *
 * Third-party plugins can register enrichers via the
 * `sd_ai_agent_register_block_enrichers` action which receives this
 * registry instance.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 * @since   1.13.0
 */

declare(strict_types=1);

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block enricher registry and dispatcher.
 *
 * Registration order matters: when multiple enrichers support the same
 * block name, they are run in registration order. If two enrichers
 * share the same `get_id()`, the later registration wins (overwrites).
 */
final class BlockEnricherRegistry {

	/**
	 * Registered enrichers keyed by their ID.
	 *
	 * Preserves insertion order for deterministic dispatch.
	 *
	 * @var array<string,BlockEnricherInterface>
	 */
	private array $enrichers = [];

	/**
	 * Whether the third-party registration action has been fired.
	 *
	 * @var bool
	 */
	private bool $action_fired = false;

	/**
	 * Register an enricher.
	 *
	 * If an enricher with the same ID is already registered, it is
	 * silently replaced (last-write-wins).
	 *
	 * @param BlockEnricherInterface $enricher The enricher to register.
	 */
	public function register( BlockEnricherInterface $enricher ): void {
		$this->enrichers[ $enricher->get_id() ] = $enricher;
	}

	/**
	 * Unregister an enricher by ID.
	 *
	 * @param string $enricher_id The enricher ID to remove.
	 * @return bool True if the enricher was found and removed.
	 */
	public function unregister( string $enricher_id ): bool {
		if ( ! isset( $this->enrichers[ $enricher_id ] ) ) {
			return false;
		}

		unset( $this->enrichers[ $enricher_id ] );

		return true;
	}

	/**
	 * Check whether an enricher with the given ID is registered.
	 *
	 * @param string $enricher_id The enricher ID.
	 * @return bool
	 */
	public function has( string $enricher_id ): bool {
		return isset( $this->enrichers[ $enricher_id ] );
	}

	/**
	 * Get all registered enricher IDs.
	 *
	 * @return string[]
	 */
	public function get_registered_ids(): array {
		return array_keys( $this->enrichers );
	}

	/**
	 * Fire the third-party registration action.
	 *
	 * Called once per request before the first enrich() call. Allows
	 * third-party plugins to register their enrichers via:
	 *
	 *   add_action( 'sd_ai_agent_register_block_enrichers', function( $registry ) {
	 *       $registry->register( new My_Custom_Enricher() );
	 *   } );
	 */
	public function fire_registration_action(): void {
		if ( $this->action_fired ) {
			return;
		}

		$this->action_fired = true;

		/**
		 * Fires when block enrichers should be registered.
		 *
		 * @since 1.13.0
		 *
		 * @param BlockEnricherRegistry $registry The enricher registry instance.
		 */
		do_action( 'sd_ai_agent_register_block_enrichers', $this );
	}

	/**
	 * Enrich a single parsed block.
	 *
	 * Walks all registered enrichers in registration order and calls
	 * `enrich()` on those whose `supports()` returns true for the
	 * block's name. Results are collected under the `enriched` key.
	 *
	 * @param array<string,mixed> $block   Parsed block array.
	 * @param array<string,mixed> $context Request context.
	 * @return array<string,mixed> The block array with `enriched` key added (if any enricher matched).
	 */
	public function enrich( array $block, array $context ): array {
		$block_name = (string) ( $block['blockName'] ?? '' );

		if ( '' === $block_name ) {
			return $block;
		}

		$enriched = [];

		foreach ( $this->enrichers as $enricher ) {
			if ( $enricher->supports( $block_name ) ) {
				$data = $enricher->enrich( $block, $context );
				if ( ! empty( $data ) ) {
					$enriched[ $enricher->get_id() ] = $data;
				}
			}
		}

		if ( ! empty( $enriched ) ) {
			$block['enriched'] = $enriched;
		}

		return $block;
	}

	/**
	 * Enrich a full block tree recursively.
	 *
	 * Walks the block tree depth-first, enriching each named block
	 * and recursing into innerBlocks.
	 *
	 * @param array<int|string,mixed> $blocks  Parsed block tree.
	 * @param array<string,mixed>     $context Request context.
	 * @return array<int|string,mixed> Enriched block tree.
	 */
	public function enrich_block_tree( array $blocks, array $context ): array {
		foreach ( $blocks as $idx => $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			// Enrich the block itself.
			$blocks[ $idx ] = $this->enrich( $block, $context );

			// Recurse into inner blocks.
			if ( ! empty( $blocks[ $idx ]['innerBlocks'] ) && is_array( $blocks[ $idx ]['innerBlocks'] ) ) {
				// @phpstan-ignore-next-line -- innerBlocks is always int-keyed from parse_blocks().
				$blocks[ $idx ]['innerBlocks'] = $this->enrich_block_tree(
					$blocks[ $idx ]['innerBlocks'],
					$context
				);
			}
		}

		return $blocks;
	}
}
