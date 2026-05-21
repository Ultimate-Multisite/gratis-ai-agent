<?php

declare(strict_types=1);
/**
 * Block content policy enforcement for core/html blocks.
 *
 * Ported from Automattic/studio apps/cli/ai/block-content-policy.ts (36 lines,
 * line-for-line translation). Flags core/html content that should instead use
 * editable core blocks, while allowing legitimate uses (single script, inline
 * SVG, interaction markup with no block equivalent such as <marquee>).
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 * @since   1.11.0
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1585
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless policy checker for core/html block content.
 *
 * All methods are static; there is no instance state.
 *
 * @since 1.11.0
 */
class BlockContentPolicy {

	/**
	 * Matches Gutenberg block comment delimiters so they can be stripped before
	 * testing the raw inner HTML.
	 *
	 * @since 1.11.0
	 */
	private const BLOCK_COMMENT_PATTERN = '/<!--\s*\/?wp:[^>]*-->/';

	/**
	 * A single <script> element (with optional attributes) occupying the entire
	 * trimmed content — the canonical allowed use of core/html for analytics and
	 * tracking snippets.
	 *
	 * @since 1.11.0
	 */
	private const SINGLE_SCRIPT_PATTERN = '/^<script(?:\s[^>]*)?>[\s\S]*<\/script>\s*$/i';

	/**
	 * A single <svg> element (with optional attributes) occupying the entire
	 * trimmed content — the canonical allowed use of core/html for inline SVG
	 * icons and illustrations.
	 *
	 * @since 1.11.0
	 */
	private const SINGLE_SVG_PATTERN = '/^<svg(?:\s[^>]*)?>[\s\S]*<\/svg>\s*$/i';

	/**
	 * Interaction markup with no block equivalent — <marquee> and similar
	 * elements that have a legitimate reason to live inside core/html.
	 *
	 * @since 1.11.0
	 */
	private const INTERACTION_MARKUP_PATTERN = '/<(marquee)\b/i';

	/**
	 * Default policy violation message (verbatim from Studio).
	 *
	 * @since 1.11.0
	 */
	public const DEFAULT_MESSAGE = 'core/html contains markup that should use editable core blocks. Use core/group, core/columns, core/heading, core/paragraph, core/list, core/image, core/buttons, and theme CSS instead. Keep core/html only for inline SVG, interaction markup with no block equivalent (marquee, cursor), or a single script block.';

	/**
	 * Determine whether core/html inner content violates the content policy.
	 *
	 * Returns an empty array when the content is allowed, or an array containing
	 * exactly one human-readable issue string when it is not. The array shape
	 * mirrors the `issues[]` field in the BlockValidator report.
	 *
	 * @since 1.11.0
	 *
	 * @param string $content Raw inner HTML of the core/html block (may include
	 *                        surrounding wp: block comment delimiters).
	 * @return string[] Empty when content is allowed; one element when not.
	 */
	public static function get_html_block_policy_issues( string $content ): array {
		// Strip Gutenberg block comment delimiters before matching.
		$stripped = (string) preg_replace( self::BLOCK_COMMENT_PATTERN, '', $content );
		$stripped = trim( $stripped );

		if ( '' === $stripped ) {
			return [];
		}

		// Single <script> tag — allowed (analytics / tracking snippets).
		if ( 1 === preg_match( self::SINGLE_SCRIPT_PATTERN, $stripped ) ) {
			return [];
		}

		// Single <svg> tag — allowed (inline icon / illustration).
		if ( 1 === preg_match( self::SINGLE_SVG_PATTERN, $stripped ) ) {
			return [];
		}

		// Interaction markup with no block equivalent — allowed.
		if ( 1 === preg_match( self::INTERACTION_MARKUP_PATTERN, $stripped ) ) {
			return [];
		}

		// Check plugin recommendations for a pattern-specific message first.
		$specific = PluginRecommendations::get_html_policy_message( $stripped );
		if ( null !== $specific ) {
			return [ $specific ];
		}

		return [ self::DEFAULT_MESSAGE ];
	}

	/**
	 * Apply the content policy to a single block validation result.
	 *
	 * Forces `isValid => false` and appends the policy message to `issues[]`
	 * when the result is for a `core/html` block that violates the policy.
	 * All other block types are returned unchanged.
	 *
	 * @since 1.11.0
	 *
	 * @param array<string, mixed> $result A single entry from BlockValidator::validate()['results'].
	 * @return array<string, mixed> The result, possibly with `isValid` forced false and issues appended.
	 */
	public static function apply( array $result ): array {
		if ( 'core/html' !== ( $result['blockName'] ?? '' ) ) {
			return $result;
		}

		$original_content = (string) ( $result['originalContent'] ?? '' );
		$issues           = self::get_html_block_policy_issues( $original_content );

		if ( empty( $issues ) ) {
			return $result;
		}

		$result['isValid'] = false;
		$result['issues']  = array_merge( (array) ( $result['issues'] ?? [] ), $issues );

		return $result;
	}
}
