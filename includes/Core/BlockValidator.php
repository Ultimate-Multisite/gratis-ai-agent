<?php

declare(strict_types=1);
/**
 * Block content validator service.
 *
 * Phase 1 foundation (GH#1584): validates parsed block structure using
 * parse_blocks() and returns a Studio-shaped per-block report. The live
 * wp.blocks.validateBlock() JS-bridge upgrade (Path A) is tracked in GH#1584
 * and can be added on top of this service without changing the public API.
 *
 * Phase 2 (GH#1585): applies BlockContentPolicy to every core/html result,
 * forcing isValid => false and appending a policy message when the content
 * should instead use editable core blocks.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 * @since   1.11.0
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1584
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1585
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates Gutenberg block content and returns a Studio-shaped report.
 *
 * Report shape (mirrors Automattic/studio apps/cli/ai/block-validator.ts):
 *
 * ```php
 * [
 *   'totalBlocks'   => 12,
 *   'validBlocks'   => 10,
 *   'invalidBlocks' => 2,
 *   'results'       => [
 *     [
 *       'blockName'       => 'core/heading',
 *       'isValid'         => false,
 *       'issues'          => [ 'Expected attribute "level" 3, instead saw 2' ],
 *       'originalContent' => '<h2 class="wp-block-heading">…</h2>',
 *       'expectedContent' => '<h3 class="wp-block-heading">…</h3>',
 *     ],
 *     …
 *   ],
 * ]
 * ```
 *
 * @since 1.11.0
 */
class BlockValidator {

	/**
	 * Validate the given Gutenberg block content string.
	 *
	 * Parses the content with parse_blocks(), performs structural checks on each
	 * block, then applies the content policy (BlockContentPolicy) to every
	 * core/html block result.
	 *
	 * @since 1.11.0
	 *
	 * @param string $content Raw Gutenberg block markup.
	 * @return array{
	 *   totalBlocks: int,
	 *   validBlocks: int,
	 *   invalidBlocks: int,
	 *   results: list<array<string, mixed>>,
	 * } Studio-shaped validation report.
	 */
	public function validate( string $content ): array {
		$parsed  = parse_blocks( $content );
		$results = [];

		foreach ( $parsed as $block ) {
			$results = array_merge( $results, $this->validate_block_recursive( $block ) );
		}

		// Apply BlockContentPolicy to all core/html results.
		$results = array_map(
			static function ( array $result ): array {
				return BlockContentPolicy::apply( $result );
			},
			$results
		);

		$total   = count( $results );
		$invalid = count( array_filter( $results, static fn( $r ) => ! $r['isValid'] ) );

		return [
			'totalBlocks'   => $total,
			'validBlocks'   => $total - $invalid,
			'invalidBlocks' => $invalid,
			'results'       => $results,
		];
	}

	/**
	 * Validate a single parsed block and its inner blocks recursively.
	 *
	 * @since 1.11.0
	 *
	 * @param array<string, mixed> $block Parsed block from parse_blocks().
	 * @return list<array<string, mixed>> One or more result entries.
	 */
	private function validate_block_recursive( array $block ): array {
		$block_name = $block['blockName'] ?? null;

		if ( null === $block_name ) {
			// Freeform / whitespace-only node — omit from results.
			return [];
		}

		$inner_html = (string) ( $block['innerHTML'] ?? '' );
		$issues     = $this->check_block_issues( $block );
		$is_valid   = empty( $issues );

		$result = [
			'blockName'       => $block_name,
			'isValid'         => $is_valid,
			'issues'          => $issues,
			'originalContent' => $inner_html,
			'expectedContent' => $inner_html, // Phase 1 stub: same until live JS bridge lands.
		];

		$results = [ $result ];

		// Recurse into inner blocks.
		foreach ( (array) ( $block['innerBlocks'] ?? [] ) as $inner ) {
			/** @var array<string, mixed> $inner */
			$results = array_merge( $results, $this->validate_block_recursive( $inner ) );
		}

		return $results;
	}

	/**
	 * Perform structural checks on a single parsed block.
	 *
	 * Returns an array of issue strings. An empty array means the block is
	 * structurally valid (from the parse_blocks() perspective; live JS
	 * validation will add further checks when GH#1584 Path A lands).
	 *
	 * @since 1.11.0
	 *
	 * @param array<string, mixed> $block Parsed block from parse_blocks().
	 * @return string[] Issue strings, empty when valid.
	 */
	private function check_block_issues( array $block ): array {
		$issues = [];

		$block_name = (string) ( $block['blockName'] ?? '' );
		$attrs      = (array) ( $block['attrs'] ?? [] );
		$inner_html = (string) ( $block['innerHTML'] ?? '' );

		// core/heading: verify level attribute matches the HTML tag.
		if ( 'core/heading' === $block_name && isset( $attrs['level'] ) ) {
			$level = (int) $attrs['level'];
			if ( $level >= 1 && $level <= 6 ) {
				$expected_tag = 'h' . $level;
				// Check that the opening tag matches.
				if ( '' !== $inner_html && 1 !== preg_match( '/<' . $expected_tag . '[\s>]/i', $inner_html ) ) {
					$issues[] = sprintf(
						'core/heading: attribute "level" is %d but markup does not contain <%s>.',
						$level,
						$expected_tag
					);
				}
			}
		}

		return $issues;
	}
}
