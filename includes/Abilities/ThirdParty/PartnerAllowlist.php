<?php

declare(strict_types=1);
/**
 * Curated allowlists of third-party ability namespaces and categories the
 * plugin trusts as "intended for AI consumption" even when the ability author
 * has not yet adopted the canonical `meta.mcp.public` flag.
 *
 * Why this exists
 * ---------------
 *
 * The WordPress AI Building Blocks initiative defines `meta.mcp.public = true`
 * (see WordPress/mcp-adapter) as the canonical signal that an ability is meant
 * for AI / MCP consumption. The convention is new — most third-party plugins
 * registered against the Abilities API today do not set it. {@see AbilityVisibility}
 * uses the namespace/category entries here to keep those plugins working out of
 * the box without forcing the site owner to triage every ability by hand.
 *
 * Editorial policy
 * ----------------
 *
 * 1. Entries must be plugins/projects we have inspected and consider safe to
 *    expose to the in-chat agent AND to external MCP clients on a default
 *    install.
 * 2. Prefer narrow prefixes that match a single plugin's namespace. Avoid
 *    overly generic slugs (e.g. `core`) that could be hijacked by an
 *    unrelated registrar.
 * 3. Keep the lists short. The escape valve for end-users is the runtime
 *    filter, not a sprawling default allowlist.
 *
 * @package SdAiAgent\Abilities\ThirdParty
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities\ThirdParty;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only registry of trusted third-party namespaces and categories.
 *
 * All accessors are static — the class is never instantiated. Runtime
 * extension happens through the documented `sd_ai_agent_partner_namespaces`
 * and `sd_ai_agent_partner_categories` filters, not by mutating the
 * underlying constants.
 *
 * @since 1.9.0
 */
final class PartnerAllowlist {

	/**
	 * First-party ability namespaces. Abilities whose name (before the slash)
	 * matches an entry here are always classified as `public-partner`.
	 *
	 * First-party namespaces are the ones this plugin ships or directly
	 * integrates. They are listed here as a single source of truth so the
	 * tests can guard them and downstream readers do not have to grep for
	 * scattered `'sd-ai-agent/'` string literals.
	 *
	 * @var list<string>
	 */
	private const FIRST_PARTY_NAMESPACES = array(
		'sd-ai-agent',
		'wp-cli',
		'sd-ai-agent-js',
	);

	/**
	 * Verified third-party ability namespaces. Maintained by hand against the
	 * plugin's docs entry on third-party support.
	 *
	 * @var list<string>
	 */
	private const PARTNER_NAMESPACES = array(
		'woocommerce',
		'woocommerce-rest',
		'multisite-ultimate',
		'mcp-adapter',
	);

	/**
	 * Trusted category slugs. An ability registered under one of these
	 * categories is classified as `public-partner` even when its namespace is
	 * not on either allowlist above. These are categories shipped by core or
	 * by upstream projects we have audited.
	 *
	 * @var list<string>
	 */
	private const PARTNER_CATEGORIES = array(
		// Core ability categories (WordPress 6.9+).
		'site',
		'user',
		'ai-experiments',
		// First-party.
		'sd-ai-agent',
		'sd-ai-agent-js',
		// Verified third-party.
		'woocommerce-rest',
		'multisite-ultimate',
		'mcp-adapter',
	);

	/**
	 * Return the full list of namespaces (first-party plus partner) that
	 * should be classified as `public-partner`.
	 *
	 * The result is filterable so site operators can extend the list at
	 * runtime without forking the plugin.
	 *
	 * @return list<string> Lowercased namespace slugs.
	 */
	public static function namespaces(): array {
		$base = array_merge( self::FIRST_PARTY_NAMESPACES, self::PARTNER_NAMESPACES );

		/**
		 * Filter the list of trusted ability namespaces.
		 *
		 * Abilities whose name begins with `{namespace}/` are classified as
		 * `public-partner` by {@see AbilityVisibility::classify()}.
		 *
		 * @since 1.9.0
		 *
		 * @param list<string> $namespaces Trusted namespace slugs.
		 */
		$filtered = apply_filters( 'sd_ai_agent_partner_namespaces', $base );

		return self::normalise_string_list( $filtered, $base );
	}

	/**
	 * Return the list of category slugs treated as trusted by default.
	 *
	 * @return list<string> Lowercased category slugs.
	 */
	public static function categories(): array {
		/**
		 * Filter the list of trusted ability categories.
		 *
		 * Abilities registered under one of these categories are classified
		 * as `public-partner` by {@see AbilityVisibility::classify()} even
		 * when their namespace is not on the namespace allowlist.
		 *
		 * @since 1.9.0
		 *
		 * @param list<string> $categories Trusted category slugs.
		 */
		$filtered = apply_filters( 'sd_ai_agent_partner_categories', self::PARTNER_CATEGORIES );

		return self::normalise_string_list( $filtered, self::PARTNER_CATEGORIES );
	}

	/**
	 * Whether the given ability name has a first-party or partner namespace.
	 *
	 * Comparison is case-insensitive on the namespace component (everything
	 * before the first `/`). Abilities without a `/` separator never match.
	 *
	 * @param string $ability_name Fully-qualified ability id (`namespace/slug`).
	 * @return bool
	 */
	public static function is_partner_namespace( string $ability_name ): bool {
		if ( ! str_contains( $ability_name, '/' ) ) {
			return false;
		}

		[ $namespace ] = explode( '/', $ability_name, 2 );
		$namespace     = strtolower( trim( $namespace ) );

		if ( '' === $namespace ) {
			return false;
		}

		return in_array( $namespace, self::namespaces(), true );
	}

	/**
	 * Whether the given category slug is on the trusted-categories list.
	 *
	 * @param string $category Category slug to test.
	 * @return bool
	 */
	public static function is_partner_category( string $category ): bool {
		$category = strtolower( trim( $category ) );

		if ( '' === $category ) {
			return false;
		}

		return in_array( $category, self::categories(), true );
	}

	/**
	 * Whether the namespace is on the first-party portion of the allowlist.
	 *
	 * Kept separate from {@see is_partner_namespace()} so the classifier can
	 * distinguish a first-party ability from a vetted third-party one when
	 * surfacing diagnostics.
	 *
	 * @param string $ability_name Fully-qualified ability id.
	 * @return bool
	 */
	public static function is_first_party_namespace( string $ability_name ): bool {
		if ( ! str_contains( $ability_name, '/' ) ) {
			return false;
		}

		[ $namespace ] = explode( '/', $ability_name, 2 );
		$namespace     = strtolower( trim( $namespace ) );

		if ( '' === $namespace ) {
			return false;
		}

		return in_array( $namespace, self::FIRST_PARTY_NAMESPACES, true );
	}

	/**
	 * Internal: coerce a filter result into a normalised, deduplicated
	 * list of lowercased non-empty strings. Falls back to the supplied
	 * default when the filtered value is not an array.
	 *
	 * @param mixed             $value    Raw filter return value.
	 * @param array<int,string> $fallback List used when $value cannot be coerced.
	 * @return list<string>
	 */
	private static function normalise_string_list( mixed $value, array $fallback ): array {
		if ( ! is_array( $value ) ) {
			$value = $fallback;
		}

		$out = array();
		foreach ( $value as $entry ) {
			if ( ! is_string( $entry ) ) {
				continue;
			}
			$slug = strtolower( trim( $entry ) );
			if ( '' === $slug ) {
				continue;
			}
			$out[ $slug ] = true; // Dedup via assoc keys.
		}

		return array_keys( $out );
	}
}
