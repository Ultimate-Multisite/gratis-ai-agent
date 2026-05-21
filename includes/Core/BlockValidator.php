<?php

declare(strict_types=1);
/**
 * Block content validator service.
 *
 * Phase 1 implementation (GH#1584): deep server-side validator that mirrors
 * Gutenberg's `wp.blocks.validateBlock()` save-comparison for known core
 * blocks. For each block we know the required wrapper tag, required class,
 * and (where applicable) attribute → markup mapping (e.g. `level` → `<hN>`).
 * When a mismatch is detected, an `expectedContent` string is reconstructed
 * by patching the originalContent so the model receives a concrete diff
 * rather than a vague "invalid" flag — exactly the shape Studio uses.
 *
 * For third-party / unknown blocks PHP cannot run the registered JS save()
 * function. Those results are merged in by {@see BlockValidatorBridge::merge_cached()}
 * when a browser session has previously POSTed live `wp.blocks.validateBlock()`
 * results for the same content hash. When no cached result exists, unknown
 * blocks pass through with `isValid: true` and `expectedContent` equal to
 * `originalContent`.
 *
 * Phase 2 (GH#1585): {@see BlockContentPolicy::apply()} is applied to every
 * core/html result, forcing `isValid => false` and appending a policy message
 * when the content should instead use editable core blocks.
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
	 * Save-rule registry for core blocks.
	 *
	 * Each entry describes the rules `wp.blocks.validateBlock()` would enforce
	 * for that block's `save()` function. Three rule shapes are supported:
	 *
	 *  - `wrapper_tag`     (string)  Required outermost HTML tag for `innerHTML`.
	 *  - `required_class`  (string)  Class that must appear on the wrapper.
	 *  - `wrapper_tag_attr`(string)  Attribute name that determines the tag
	 *                                 (currently only used by core/heading).
	 *  - `wrapper_tag_map` (string)  Template for computing the expected tag
	 *                                 from `wrapper_tag_attr` (e.g. `h{level}`).
	 *
	 * Third-party blocks have no entry here and pass through unless a browser
	 * session has POSTed live validation results for the same content.
	 *
	 * @since 1.11.0
	 * @var array<string, array<string, string>>
	 */
	private const CORE_BLOCK_RULES = [
		'core/heading'         => [
			'wrapper_tag_attr' => 'level',
			'wrapper_tag_map'  => 'h{level}',
			'required_class'   => 'wp-block-heading',
		],
		'core/list'            => [
			// Tag is ul OR ol depending on `ordered` attr; we only enforce the class.
			'required_class' => 'wp-block-list',
		],
		'core/image'           => [
			'wrapper_tag'    => 'figure',
			'required_class' => 'wp-block-image',
		],
		'core/quote'           => [
			'wrapper_tag'    => 'blockquote',
			'required_class' => 'wp-block-quote',
		],
		'core/code'            => [
			'wrapper_tag'    => 'pre',
			'required_class' => 'wp-block-code',
		],
		'core/preformatted'    => [
			'wrapper_tag'    => 'pre',
			'required_class' => 'wp-block-preformatted',
		],
		'core/separator'       => [
			'wrapper_tag'    => 'hr',
			'required_class' => 'wp-block-separator',
		],
		'core/spacer'          => [
			'wrapper_tag'    => 'div',
			'required_class' => 'wp-block-spacer',
		],
		'core/buttons'         => [
			'wrapper_tag'    => 'div',
			'required_class' => 'wp-block-buttons',
		],
		'core/button'          => [
			'wrapper_tag'    => 'div',
			'required_class' => 'wp-block-button',
		],
		'core/columns'         => [
			'wrapper_tag'    => 'div',
			'required_class' => 'wp-block-columns',
		],
		'core/column'          => [
			'wrapper_tag'    => 'div',
			'required_class' => 'wp-block-column',
		],
		'core/group'           => [
			// Tag depends on `tagName` attr (default div). We only enforce the class.
			'required_class' => 'wp-block-group',
		],
		'core/cover'           => [
			'wrapper_tag'    => 'div',
			'required_class' => 'wp-block-cover',
		],
		'core/media-text'      => [
			'wrapper_tag'    => 'div',
			'required_class' => 'wp-block-media-text',
		],
		'core/table'           => [
			'wrapper_tag'    => 'figure',
			'required_class' => 'wp-block-table',
		],
		'core/gallery'         => [
			'wrapper_tag'    => 'figure',
			'required_class' => 'wp-block-gallery',
		],
		'core/embed'           => [
			'wrapper_tag'    => 'figure',
			'required_class' => 'wp-block-embed',
		],
		'core/details'         => [
			'wrapper_tag'    => 'details',
			'required_class' => 'wp-block-details',
		],
		'core/pullquote'       => [
			'wrapper_tag'    => 'figure',
			'required_class' => 'wp-block-pullquote',
		],
		'core/audio'           => [
			'wrapper_tag'    => 'figure',
			'required_class' => 'wp-block-audio',
		],
		'core/video'           => [
			'wrapper_tag'    => 'figure',
			'required_class' => 'wp-block-video',
		],
		'core/file'            => [
			'wrapper_tag'    => 'div',
			'required_class' => 'wp-block-file',
		],
		'core/verse'           => [
			'wrapper_tag'    => 'pre',
			'required_class' => 'wp-block-verse',
		],
		'core/search'          => [
			'wrapper_tag'    => 'form',
			'required_class' => 'wp-block-search',
		],
		'core/social-links'    => [
			'wrapper_tag'    => 'ul',
			'required_class' => 'wp-block-social-links',
		],
		'core/latest-posts'    => [
			// Tag varies (ul/div). Only enforce the class.
			'required_class' => 'wp-block-latest-posts',
		],
		'core/latest-comments' => [
			'required_class' => 'wp-block-latest-comments',
		],
		'core/calendar'        => [
			'required_class' => 'wp-block-calendar',
		],
		'core/categories'      => [
			'required_class' => 'wp-block-categories',
		],
		'core/tag-cloud'       => [
			'required_class' => 'wp-block-tag-cloud',
		],
		'core/archives'        => [
			'required_class' => 'wp-block-archives',
		],
		'core/rss'             => [
			'required_class' => 'wp-block-rss',
		],
	];

	/**
	 * Validate the given Gutenberg block content string.
	 *
	 * Parses the content with parse_blocks(), performs deep save-rule checks
	 * on every recognised block (see {@see CORE_BLOCK_RULES}), then applies
	 * the content policy (BlockContentPolicy) to every core/html block result.
	 *
	 * When the {@see BlockValidatorBridge} cache contains live JS-validator
	 * results for the same content (keyed by SHA-256), those override the
	 * server-side report so third-party blocks get true `wp.blocks.validateBlock()`
	 * coverage.
	 *
	 * @since 1.11.0
	 *
	 * @param string $content Raw Gutenberg block markup.
	 * @return array{
	 *   totalBlocks: int,
	 *   validBlocks: int,
	 *   invalidBlocks: int,
	 *   results: list<array<string, mixed>>,
	 *   source: string,
	 * } Studio-shaped validation report. `source` is one of: `php`, `js-cached`.
	 */
	public function validate( string $content ): array {
		// Prefer browser-validated cached result when available.
		$cached = BlockValidatorBridge::get_cached( $content );
		if ( null !== $cached ) {
			// Still apply BlockContentPolicy on top of cached JS results so
			// the core/html policy stays consistent between the two engines.
			$cached_results = [];
			foreach ( (array) ( $cached['results'] ?? [] ) as $result ) {
				/** @var array<string, mixed> $result */
				$cached_results[] = BlockContentPolicy::apply( $result );
			}

			$total                   = count( $cached_results );
			$invalid                 = count( array_filter( $cached_results, static fn( $r ) => empty( $r['isValid'] ) ) );
			$cached['results']       = $cached_results;
			$cached['totalBlocks']   = $total;
			$cached['validBlocks']   = $total - $invalid;
			$cached['invalidBlocks'] = $invalid;
			$cached['source']        = 'js-cached';

			return $cached;
		}

		$parsed  = parse_blocks( $content );
		$results = [];

		foreach ( $parsed as $block ) {
			foreach ( $this->validate_block_recursive( $block ) as $entry ) {
				$results[] = $entry;
			}
		}

		// Apply BlockContentPolicy to all core/html results.
		$policied = [];
		foreach ( $results as $result ) {
			$policied[] = BlockContentPolicy::apply( $result );
		}

		$total   = count( $policied );
		$invalid = count( array_filter( $policied, static fn( $r ) => empty( $r['isValid'] ) ) );

		return [
			'totalBlocks'   => $total,
			'validBlocks'   => $total - $invalid,
			'invalidBlocks' => $invalid,
			'results'       => $policied,
			'source'        => 'php',
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

		$inner_html       = (string) ( $block['innerHTML'] ?? '' );
		$attrs            = (array) ( $block['attrs'] ?? [] );
		$check            = $this->check_block( (string) $block_name, $attrs, $inner_html );
		$issues           = $check['issues'];
		$expected_content = $check['expected'];
		$is_valid         = empty( $issues );

		$result = [
			'blockName'       => $block_name,
			'isValid'         => $is_valid,
			'issues'          => $issues,
			'originalContent' => $inner_html,
			'expectedContent' => $expected_content,
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
	 * Apply the save-rule registry to a single parsed block.
	 *
	 * Returns an `issues[]` list and an `expected` HTML string. When the
	 * block is valid `expected` equals the original `innerHTML`; when it is
	 * invalid `expected` is the originalContent rewritten so the discoverable
	 * issues are corrected (e.g. heading tag swapped to match the `level`
	 * attribute, missing class injected into the wrapper).
	 *
	 * @since 1.11.0
	 *
	 * @param string               $block_name Block name (e.g. `core/heading`).
	 * @param array<string, mixed> $attrs      Block attributes from parse_blocks().
	 * @param string               $inner_html innerHTML from parse_blocks().
	 * @return array{ issues: string[], expected: string }
	 */
	private function check_block( string $block_name, array $attrs, string $inner_html ): array {
		$issues   = [];
		$expected = $inner_html;

		if ( ! isset( self::CORE_BLOCK_RULES[ $block_name ] ) ) {
			// Unknown / third-party block — PHP cannot validate save() output
			// without running its JS. Pass through; browser-side validator
			// (BlockValidatorBridge cache) covers this case.
			return [
				'issues'   => $issues,
				'expected' => $expected,
			];
		}

		$rule = self::CORE_BLOCK_RULES[ $block_name ];

		// Rule 1: wrapper_tag_attr + wrapper_tag_map (currently used by core/heading).
		if ( isset( $rule['wrapper_tag_attr'], $rule['wrapper_tag_map'] ) ) {
			$attr_name = $rule['wrapper_tag_attr'];
			if ( isset( $attrs[ $attr_name ] ) ) {
				$expected_tag = strtolower(
					str_replace(
						'{' . $attr_name . '}',
						(string) $attrs[ $attr_name ],
						$rule['wrapper_tag_map']
					)
				);
				$actual_tag   = $this->extract_outer_tag( $inner_html );

				if ( null !== $actual_tag && $actual_tag !== $expected_tag ) {
					$issues[] = sprintf(
						'%s: attribute "%s" is %s but markup uses <%s> (expected <%s>).',
						$block_name,
						$attr_name,
						(string) $attrs[ $attr_name ],
						$actual_tag,
						$expected_tag
					);
					$expected = $this->rewrite_outer_tag( $inner_html, $actual_tag, $expected_tag );
				}
			}
		}

		// Rule 2: wrapper_tag — verify outer tag matches.
		if ( isset( $rule['wrapper_tag'] ) ) {
			$expected_tag = $rule['wrapper_tag'];
			$actual_tag   = $this->extract_outer_tag( $expected );
			if ( null !== $actual_tag && $actual_tag !== $expected_tag ) {
				$issues[] = sprintf(
					'%s: expected wrapper <%s> but markup uses <%s>.',
					$block_name,
					$expected_tag,
					$actual_tag
				);
				$expected = $this->rewrite_outer_tag( $expected, $actual_tag, $expected_tag );
			}
		}

		// Rule 3: required_class — verify the class is present on the wrapper.
		// Every entry in CORE_BLOCK_RULES carries a required_class, so this
		// rule unconditionally applies once we have matched a known block.
		$required_class = $rule['required_class'];
		if ( ! $this->wrapper_has_class( $expected, $required_class ) ) {
			$issues[] = sprintf(
				'%s: wrapper element is missing required class "%s".',
				$block_name,
				$required_class
			);
			$expected = $this->inject_wrapper_class( $expected, $required_class );
		}

		return [
			'issues'   => $issues,
			'expected' => $expected,
		];
	}

	/**
	 * Extract the outermost HTML tag name from a snippet of block innerHTML.
	 *
	 * Skips leading whitespace and HTML comments (so block-comment delimiters
	 * in already-serialised content are tolerated). Returns null when no tag
	 * can be found in the first 200 characters.
	 *
	 * @since 1.11.0
	 *
	 * @param string $html innerHTML to inspect.
	 * @return string|null Lower-cased tag name, or null when none found.
	 */
	private function extract_outer_tag( string $html ): ?string {
		// Strip leading whitespace + HTML comments before looking for the first tag.
		$stripped = (string) preg_replace( '/^(?:\s|<!--.*?-->)+/s', '', $html );
		if ( 1 === preg_match( '/^<([a-zA-Z][a-zA-Z0-9-]*)/', $stripped, $m ) ) {
			return strtolower( $m[1] );
		}
		return null;
	}

	/**
	 * Rewrite the outermost tag name in a snippet of HTML, preserving attributes
	 * on the opening tag and a matching closing tag if present.
	 *
	 * @since 1.11.0
	 *
	 * @param string $html        Source HTML.
	 * @param string $actual_tag  Current tag name (lower-case).
	 * @param string $expected_tag Replacement tag name (lower-case).
	 * @return string Rewritten HTML.
	 */
	private function rewrite_outer_tag( string $html, string $actual_tag, string $expected_tag ): string {
		// Replace the first opening tag (with optional attributes).
		$html = (string) preg_replace(
			'/<' . preg_quote( $actual_tag, '/' ) . '(?=[\s>\/])/i',
			'<' . $expected_tag,
			$html,
			1
		);

		// Replace the final closing tag if it matches the actual_tag.
		$html = (string) preg_replace_callback(
			'/<\/' . preg_quote( $actual_tag, '/' ) . '\s*>(?=[^<]*$)/i',
			static fn() => '</' . $expected_tag . '>',
			$html,
			1
		);

		return $html;
	}

	/**
	 * Determine whether the wrapper element's class attribute contains a class.
	 *
	 * Only inspects the first opening tag; subsequent elements are ignored.
	 *
	 * @since 1.11.0
	 *
	 * @param string $html  innerHTML to inspect.
	 * @param string $class Class to look for (without leading dot).
	 * @return bool True when the class is present on the wrapper.
	 */
	private function wrapper_has_class( string $html, string $class ): bool {
		if ( 1 !== preg_match( '/<[a-zA-Z][a-zA-Z0-9-]*\s+[^>]*class=(?:"([^"]*)"|\'([^\']*)\')/', $html, $m ) ) {
			return false;
		}
		$class_attr = '' !== $m[1] ? $m[1] : ( $m[2] ?? '' );
		$classes    = preg_split( '/\s+/', trim( $class_attr ) );

		return is_array( $classes ) && in_array( $class, $classes, true );
	}

	/**
	 * Inject a class into the wrapper element's class attribute. When the
	 * wrapper has no class attribute, one is added.
	 *
	 * @since 1.11.0
	 *
	 * @param string $html  innerHTML to mutate.
	 * @param string $class Class to add (without leading dot).
	 * @return string innerHTML with the class added to the wrapper element.
	 */
	private function inject_wrapper_class( string $html, string $class ): string {
		// Locate the first opening tag.
		if ( 1 !== preg_match( '/<([a-zA-Z][a-zA-Z0-9-]*)([^>]*)>/', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$tag_name   = $m[1][0];
		$attrs_part = $m[2][0];
		$tag_offset = $m[0][1];
		$tag_length = strlen( $m[0][0] );

		if ( preg_match( '/class=("([^"]*)"|\'([^\']*)\')/', $attrs_part, $cm ) ) {
			$existing  = '' !== ( $cm[2] ?? '' ) ? $cm[2] : ( $cm[3] ?? '' );
			$updated   = trim( $existing . ' ' . $class );
			$new_attrs = (string) preg_replace(
				'/class=("[^"]*"|\'[^\']*\')/',
				'class="' . $updated . '"',
				$attrs_part,
				1
			);
		} else {
			$new_attrs = $attrs_part . ' class="' . $class . '"';
		}

		$new_open = '<' . $tag_name . $new_attrs . '>';
		return substr_replace( $html, $new_open, $tag_offset, $tag_length );
	}
}
