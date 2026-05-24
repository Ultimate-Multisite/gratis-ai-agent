<?php
/**
 * Interface for block enrichers.
 *
 * Enrichers augment the read-side output of `get-page-blocks` with
 * derived metadata specific to a block type. Each enricher inspects
 * the block name and optionally returns additional fields under the
 * `enriched.<enricher_id>` key.
 *
 * Third-party plugins can implement this interface and register their
 * enrichers via the `sd_ai_agent_register_block_enrichers` action.
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
 * Contract for block enrichers.
 *
 * Each enricher provides:
 * - A unique identifier (used as the key under `enriched.<id>`).
 * - A `supports()` check against the block name.
 * - An `enrich()` method that returns a key-value map of derived data.
 */
interface BlockEnricherInterface {

	/**
	 * Return the unique enricher identifier.
	 *
	 * This ID is used as the key under `enriched.<id>` in the block
	 * response. Must be a non-empty string using only lowercase
	 * alphanumeric characters and underscores.
	 *
	 * @return string Enricher identifier (e.g. 'core_image').
	 */
	public function get_id(): string;

	/**
	 * Check whether this enricher supports the given block name.
	 *
	 * @param string $block_name Block name (e.g. 'core/image').
	 * @return bool True if this enricher should run for the block.
	 */
	public function supports( string $block_name ): bool;

	/**
	 * Enrich a block with derived metadata.
	 *
	 * The returned associative array is placed under
	 * `block.enriched.<get_id()>` in the read response. The enricher
	 * must never throw — return an appropriate fallback (e.g.
	 * `['missing' => true]`) instead.
	 *
	 * @param array<string,mixed> $block   Parsed block array (blockName, attrs, innerHTML, innerBlocks).
	 * @param array<string,mixed> $context Request context (e.g. post_id, render flag).
	 * @return array<string,mixed> Enrichment data to attach.
	 */
	public function enrich( array $block, array $context ): array;
}
