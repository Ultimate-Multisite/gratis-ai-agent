<?php

declare(strict_types=1);
/**
 * Block content policy enforcement and namespace preference scoring.
 *
 * Two responsibilities:
 *
 * 1. **HTML-content policy** (original): Flags core/html blocks whose inner
 *    HTML should use editable core blocks instead (single-script, SVG, and
 *    marquee carve-outs are preserved).
 *
 * 2. **Namespace tier policy** (t249): Each block namespace (and individual
 *    full block names) carries a 0–100 preference score that maps to one of
 *    four action tiers:
 *
 *    | Tier        | Score    | Insert behaviour                                          |
 *    |-------------|----------|-----------------------------------------------------------|
 *    | preferred   | ≥ 80     | Allow silently                                            |
 *    | acceptable  | 50–79    | Allow silently                                            |
 *    | avoid       | 10–49    | Allow + response warning with `suggested_replacement`     |
 *    | legacy      | < 10     | Reject on insert; allow on update (existing posts safe)   |
 *
 * Scores live in two WordPress options that can also be overridden via filters:
 *   - `sd_ai_agent_block_preferences`  (namespace/block → int score)
 *   - `sd_ai_agent_block_replacements` (legacy-block → modern-block)
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 * @since   1.11.0
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1585
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1712
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless policy checker for Gutenberg block content and namespace tiers.
 *
 * All methods are static; there is no instance state.
 *
 * @since 1.11.0
 */
class BlockContentPolicy {

	// -----------------------------------------------------------------------
	// Option / filter names
	// -----------------------------------------------------------------------

	/**
	 * WordPress option name (and filter hook name) for namespace preference scores.
	 *
	 * @since 1.16.0
	 * @var string
	 */
	const OPTION_PREFERENCES = 'sd_ai_agent_block_preferences';

	/**
	 * WordPress option name (and filter hook name) for the legacy-block replacement map.
	 *
	 * @since 1.16.0
	 * @var string
	 */
	const OPTION_REPLACEMENTS = 'sd_ai_agent_block_replacements';

	// -----------------------------------------------------------------------
	// Tier score thresholds
	// -----------------------------------------------------------------------

	/**
	 * Minimum score to be considered "preferred".
	 *
	 * @since 1.16.0
	 */
	const SCORE_PREFERRED = 80;

	/**
	 * Minimum score to be considered "acceptable".
	 *
	 * @since 1.16.0
	 */
	const SCORE_ACCEPTABLE = 50;

	/**
	 * Minimum score to be considered "avoid" (below this = "legacy").
	 *
	 * @since 1.16.0
	 */
	const SCORE_AVOID = 10;

	// -----------------------------------------------------------------------
	// Default preferences
	// -----------------------------------------------------------------------

	/**
	 * Default namespace/block preference scores shipped with the plugin.
	 *
	 * Keys without a `/` are namespace prefixes (match all blocks in that
	 * namespace). Keys containing `/` are exact full block name overrides and
	 * take priority over a namespace match.
	 *
	 * @since 1.16.0
	 * @var array<string, int>
	 */
	private const DEFAULT_PREFERENCES = array(
		// Namespaces
		'core'               => 90,  // WordPress core blocks — preferred.

		// Full block name overrides — specific legacy/deprecated blocks.
		'core/freeform'      => 5,   // Classic Editor block — legacy.
		'core/legacy-widget' => 5,   // Legacy Widget block — legacy.
		'core/html'          => 30,  // Custom HTML — avoid (use specific blocks).
	);

	/**
	 * Default replacement map shipped with the plugin.
	 *
	 * Maps legacy block names to their recommended modern replacements.
	 *
	 * @since 1.16.0
	 * @var array<string, string>
	 */
	private const DEFAULT_REPLACEMENTS = array(
		'core/freeform'      => 'core/group',
		'core/legacy-widget' => 'core/html',
	);

	// -----------------------------------------------------------------------
	// HTML-content policy constants (original — 1.11.0)
	// -----------------------------------------------------------------------

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

	// -----------------------------------------------------------------------
	// Namespace / tier scoring (t249)
	// -----------------------------------------------------------------------

	/**
	 * Return the merged namespace/block preference scores.
	 *
	 * Reads the `sd_ai_agent_block_preferences` option, deep-merges with
	 * defaults, and passes the result through the same-name filter so
	 * companion plugins can override scores without saving to the DB.
	 *
	 * @since 1.16.0
	 *
	 * @return array<string, int> Assoc array of namespace/block-name → score (0–100).
	 */
	public static function get_preferences(): array {
		$stored = get_option( self::OPTION_PREFERENCES, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		// Stored values override defaults; build typed array explicitly.
		$merged = self::DEFAULT_PREFERENCES;
		foreach ( $stored as $k => $v ) {
			$merged[ (string) $k ] = (int) $v;
		}

		/**
		 * Filter the block namespace/name preference scores.
		 *
		 * @since 1.16.0
		 *
		 * @param array<string, int> $merged Merged scores (defaults + stored).
		 */
		return (array) apply_filters( self::OPTION_PREFERENCES, $merged );
	}

	/**
	 * Return the merged legacy-block replacement map.
	 *
	 * Reads the `sd_ai_agent_block_replacements` option, deep-merges with
	 * defaults, and passes the result through the same-name filter.
	 *
	 * @since 1.16.0
	 *
	 * @return array<string, string> Assoc array of legacy-block-name → modern-block-name.
	 */
	public static function get_replacements(): array {
		$stored = get_option( self::OPTION_REPLACEMENTS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		// Build typed replacement map explicitly.
		$merged = self::DEFAULT_REPLACEMENTS;
		foreach ( $stored as $k => $v ) {
			$merged[ (string) $k ] = (string) $v;
		}

		/**
		 * Filter the block replacement map.
		 *
		 * @since 1.16.0
		 *
		 * @param array<string, string> $merged Merged replacement map.
		 */
		return (array) apply_filters( self::OPTION_REPLACEMENTS, $merged );
	}

	/**
	 * Look up the preference score for a given block name.
	 *
	 * Resolution order:
	 * 1. Exact full block-name match (e.g. `core/freeform`).
	 * 2. Namespace prefix match (e.g. `core` for `core/paragraph`).
	 * 3. Default score of 50 (acceptable) for unknown namespaces.
	 *
	 * @since 1.16.0
	 *
	 * @param string $block_name Full block name (e.g. `core/paragraph`).
	 * @return int Score in the range 0–100.
	 */
	public static function get_namespace_score( string $block_name ): int {
		$prefs = self::get_preferences();

		// 1. Exact full block-name match.
		if ( isset( $prefs[ $block_name ] ) ) {
			return (int) $prefs[ $block_name ];
		}

		// 2. Namespace prefix match (everything before the first `/`).
		$slash = strpos( $block_name, '/' );
		if ( false !== $slash ) {
			$namespace = substr( $block_name, 0, $slash );
			if ( isset( $prefs[ $namespace ] ) ) {
				return (int) $prefs[ $namespace ];
			}
		}

		// 3. Unknown namespace → acceptable default.
		return 50;
	}

	/**
	 * Convert a numeric preference score to a tier label.
	 *
	 * @since 1.16.0
	 *
	 * @param int $score Preference score (0–100).
	 * @return string One of: `preferred`, `acceptable`, `avoid`, `legacy`.
	 */
	public static function score_to_tier( int $score ): string {
		if ( $score >= self::SCORE_PREFERRED ) {
			return 'preferred';
		}
		if ( $score >= self::SCORE_ACCEPTABLE ) {
			return 'acceptable';
		}
		if ( $score >= self::SCORE_AVOID ) {
			return 'avoid';
		}
		return 'legacy';
	}

	/**
	 * Return the suggested replacement block name for a given block, or null.
	 *
	 * @since 1.16.0
	 *
	 * @param string $block_name Full block name.
	 * @return string|null Modern replacement block name, or null if unmapped.
	 */
	public static function get_replacement( string $block_name ): ?string {
		$map = self::get_replacements();
		return isset( $map[ $block_name ] ) ? (string) $map[ $block_name ] : null;
	}

	/**
	 * Check whether inserting a block is permitted by the tier policy.
	 *
	 * Returns null when the block is allowed without restrictions, an array
	 * with a `warnings` key when the block is in the "avoid" tier, or a
	 * WP_Error when the block is in the "legacy" tier and must be rejected.
	 *
	 * Update operations (`$is_update = true`) relax the legacy gate so that
	 * existing posts containing legacy blocks are not bricked by attribute
	 * updates.
	 *
	 * @since 1.16.0
	 *
	 * @param string $block_name Full block name (e.g. `core/freeform`).
	 * @param bool   $is_update  True when the caller is updating attributes on
	 *                           an existing block rather than inserting a new one.
	 *                           Legacy blocks are allowed through on update.
	 * @return null|array{warnings: list<array{code: string, block_name: string, score: int, tier: string, suggested_replacement: string|null}>}|\WP_Error
	 */
	public static function check_insert( string $block_name, bool $is_update = false ): null|\WP_Error|array {
		$score       = self::get_namespace_score( $block_name );
		$tier        = self::score_to_tier( $score );
		$replacement = self::get_replacement( $block_name );

		// Legacy tier: reject on insert; allow on update (existing posts safe).
		if ( 'legacy' === $tier && ! $is_update ) {
			return new \WP_Error(
				'legacy_block',
				sprintf(
					/* translators: %s: Gutenberg block name such as "core/freeform" */
					__( 'Block %1$s is in the legacy tier (score %2$d) and cannot be inserted. Use the suggested replacement instead.', 'superdav-ai-agent' ),
					$block_name,
					$score
				),
				array(
					'block_name'            => $block_name,
					'score'                 => $score,
					'tier'                  => $tier,
					'suggested_replacement' => $replacement,
				)
			);
		}

		// Avoid tier: allow with advisory warning.
		if ( 'avoid' === $tier ) {
			return array(
				'warnings' => array(
					array(
						'code'                  => 'avoid_block',
						'block_name'            => $block_name,
						'score'                 => $score,
						'tier'                  => $tier,
						'suggested_replacement' => $replacement,
					),
				),
			);
		}

		// Preferred / acceptable — silent allow.
		return null;
	}

	// -----------------------------------------------------------------------
	// HTML-content policy (original — 1.11.0)
	// -----------------------------------------------------------------------

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
