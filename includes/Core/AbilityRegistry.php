<?php

declare(strict_types=1);
/**
 * Safe helpers for reading the WordPress Abilities registry.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AbilityRegistry {

	/**
	 * Return a registered ability without triggering WordPress 6.9+ not-found notices.
	 *
	 * WordPress 6.9 changed wp_get_ability() so missing names emit a
	 * doing_it_wrong notice via WP_Abilities_Registry::get_registered(). Probe
	 * with wp_has_ability() first when available, then fetch only known names.
	 *
	 * @param string $name Ability name.
	 * @return \WP_Ability|null
	 */
	public static function get( string $name ): ?\WP_Ability {
		if ( '' === $name || ! function_exists( 'wp_get_ability' ) ) {
			return null;
		}

		if ( function_exists( 'wp_has_ability' ) && ! wp_has_ability( $name ) ) {
			return null;
		}

		// @phpstan-ignore-next-line WordPress defines WP_Ability at runtime.
		$ability = wp_get_ability( $name );

		return $ability instanceof \WP_Ability ? $ability : null;
	}
}
