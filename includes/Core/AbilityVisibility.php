<?php

declare(strict_types=1);
/**
 * Centralised visibility resolver for WordPress abilities.
 *
 * Why this exists
 * ---------------
 *
 * Visibility of registered abilities to the AI agent and to the public MCP
 * endpoint used to be enforced by four separate `! empty( $meta['ai_hidden'] )`
 * checks scattered across the codebase. Every additional surface or rule risked
 * drift. This class collapses the decision into one classifier and three
 * surface-specific predicates, so every reader and writer shares the same
 * answer.
 *
 * Visibility model
 * ----------------
 *
 * Decision precedence — first match wins:
 *
 *   1. Explicit private:
 *        - `meta.ai_hidden === true`
 *        - `meta.mcp.public === false`
 *   2. Explicit public:
 *        - `meta.mcp.public === true`
 *   3. First-party namespace (`sd-ai-agent/*`, `wp-cli/*`, …) → public-partner
 *   4. Trusted partner namespace (woocommerce, multisite-ultimate, …) → public-partner
 *   5. Trusted partner category (`site`, `user`, `ai-experiments`, …) → public-partner
 *   6. Heuristic: ability has a non-empty `description` AND a non-empty
 *      `category` → public-heuristic.
 *   7. Otherwise → private-unknown.
 *
 * The `meta.mcp.public` flag is the canonical signal published by the
 * WordPress AI Building Blocks initiative (see WordPress/mcp-adapter).
 * Adopting it as the primary opt-in aligns the plugin with WooCommerce 10.3+
 * and the broader ecosystem; the tier-3-onwards heuristics only kick in for
 * abilities that pre-date the convention.
 *
 * Surface predicates
 * ------------------
 *
 * Three call sites consume this class:
 *
 *   - `for_ai_chat()`   — Tier-1 FunctionDeclarations + Tier-2 system-prompt manifest.
 *   - `for_mcp()`       — public `/sd-ai-agent/v1/mcp` `list_tools` response.
 *   - `for_admin_picker()` — admin abilities list in the agent builder UI.
 *
 * They currently agree (everything `public-*` is exposed, everything `private-*`
 * is hidden), but kept distinct so future policy (e.g. hiding write-capable
 * abilities from the MCP endpoint by default) can land without re-touching the
 * call sites.
 *
 * Pure functions, no side effects, no dependencies on the DI container.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use SdAiAgent\Abilities\ThirdParty\PartnerAllowlist;
use WP_Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classify abilities and resolve per-surface visibility.
 *
 * @since 1.9.0
 */
final class AbilityVisibility {

	/**
	 * Classification result: declared public via `meta.mcp.public === true`.
	 */
	public const CLASSIFICATION_PUBLIC_EXPLICIT = 'public-explicit';

	/**
	 * Classification result: namespace or category is on the partner allowlist.
	 */
	public const CLASSIFICATION_PUBLIC_PARTNER = 'public-partner';

	/**
	 * Classification result: passes the description + category heuristic.
	 */
	public const CLASSIFICATION_PUBLIC_HEURISTIC = 'public-heuristic';

	/**
	 * Classification result: declared private via `ai_hidden` or `meta.mcp.public === false`.
	 */
	public const CLASSIFICATION_PRIVATE_EXPLICIT = 'private-explicit';

	/**
	 * Classification result: not declared public and fails every heuristic.
	 *
	 * The site owner has not opted this ability in; the ability author has
	 * not flagged it. The admin notice in
	 * {@see \SdAiAgent\Admin\ThirdPartyAbilityNoticeHandler} surfaces these.
	 */
	public const CLASSIFICATION_PRIVATE_UNKNOWN = 'private-unknown';

	/**
	 * Determine whether an ability should be visible to the in-chat AI agent.
	 *
	 * @param WP_Ability $ability The ability under consideration.
	 * @return bool True when the ability may be loaded as a tool or listed in
	 *              the system-prompt manifest.
	 */
	public static function for_ai_chat( WP_Ability $ability ): bool {
		return self::is_public_classification( self::classify( $ability ) );
	}

	/**
	 * Determine whether an ability should be advertised on the public MCP
	 * endpoint that external clients (Claude Desktop, Cursor, …) consume.
	 *
	 * @param WP_Ability $ability The ability under consideration.
	 * @return bool True when the ability is safe to expose to external MCP
	 *              clients.
	 */
	public static function for_mcp( WP_Ability $ability ): bool {
		return self::is_public_classification( self::classify( $ability ) );
	}

	/**
	 * Determine whether an ability should appear in the admin agent-builder
	 * picker.
	 *
	 * The admin picker is more permissive than the runtime surfaces: it shows
	 * partner and heuristic-public abilities, but excludes only the
	 * explicit-private ones. The intent is that an administrator selecting
	 * tier-1 tools can see every ability the plugin author declared
	 * AI-callable, including those the heuristic might dismiss as low-signal.
	 *
	 * @param WP_Ability $ability The ability under consideration.
	 * @return bool
	 */
	public static function for_admin_picker( WP_Ability $ability ): bool {
		return self::classify( $ability ) !== self::CLASSIFICATION_PRIVATE_EXPLICIT;
	}

	/**
	 * Return the classification tier for an ability.
	 *
	 * @param WP_Ability $ability The ability under consideration.
	 * @return string One of the `CLASSIFICATION_*` constants.
	 */
	public static function classify( WP_Ability $ability ): string {
		$meta = $ability->get_meta();
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		// 1. Explicit private wins over everything else.
		if ( ! empty( $meta['ai_hidden'] ) ) {
			return self::CLASSIFICATION_PRIVATE_EXPLICIT;
		}

		$mcp_public = self::read_mcp_public( $meta );
		if ( false === $mcp_public ) {
			return self::CLASSIFICATION_PRIVATE_EXPLICIT;
		}

		// 2. Explicit public.
		if ( true === $mcp_public ) {
			return self::CLASSIFICATION_PUBLIC_EXPLICIT;
		}

		// 3. & 4. Partner-allowlist (first-party + verified partners).
		$name = (string) $ability->get_name();
		if ( PartnerAllowlist::is_partner_namespace( $name ) ) {
			return self::CLASSIFICATION_PUBLIC_PARTNER;
		}

		// 5. Partner category.
		$category = (string) $ability->get_category();
		if ( '' !== $category && PartnerAllowlist::is_partner_category( $category ) ) {
			return self::CLASSIFICATION_PUBLIC_PARTNER;
		}

		// 6. Heuristic: well-formed registration.
		$description = trim( (string) $ability->get_description() );
		if ( '' !== $description && '' !== $category ) {
			return self::CLASSIFICATION_PUBLIC_HEURISTIC;
		}

		// 7. Fall-through.
		return self::CLASSIFICATION_PRIVATE_UNKNOWN;
	}

	/**
	 * Whether the classification represents a "publicly exposed" tier.
	 *
	 * @param string $classification Classification string.
	 * @return bool
	 */
	public static function is_public_classification( string $classification ): bool {
		return in_array(
			$classification,
			array(
				self::CLASSIFICATION_PUBLIC_EXPLICIT,
				self::CLASSIFICATION_PUBLIC_PARTNER,
				self::CLASSIFICATION_PUBLIC_HEURISTIC,
			),
			true
		);
	}

	/**
	 * Extract the `meta.mcp.public` flag from an ability meta array.
	 *
	 * Returns a tri-state:
	 *   - `true`  → explicitly public
	 *   - `false` → explicitly private
	 *   - `null`  → not declared (caller should fall through to heuristics)
	 *
	 * Accepts both the canonical nested form `meta.mcp.public` and the flat
	 * `meta.mcp_public` form some early adopters used. Non-boolean values are
	 * treated as "not declared" to avoid accidental positive classifications
	 * when a registrar populates the slot with junk.
	 *
	 * @param array<string,mixed> $meta The ability's `meta` array.
	 * @return bool|null
	 */
	private static function read_mcp_public( array $meta ): ?bool {
		if ( isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) && array_key_exists( 'public', $meta['mcp'] ) ) {
			$flag = $meta['mcp']['public'];
			if ( is_bool( $flag ) ) {
				return $flag;
			}
		}

		if ( array_key_exists( 'mcp_public', $meta ) && is_bool( $meta['mcp_public'] ) ) {
			return $meta['mcp_public'];
		}

		return null;
	}
}
