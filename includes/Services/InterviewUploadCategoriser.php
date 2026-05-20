<?php

declare(strict_types=1);
/**
 * Interview Upload Categoriser — filename-based heuristic for Theme Builder photo uploads.
 *
 * Classifies an interview-stage photo upload into one of five buckets the
 * Theme Builder agent can map to specific page sections:
 *
 *  - `space`   — shopfront, exterior, interior, venue
 *  - `product` — drinks, food, dishes, items for sale
 *  - `team`    — staff, owners, founders, portraits
 *  - `event`   — events, performances, shows
 *  - `other`   — anything that does not match the above keyword sets
 *
 * Pure function on the filename — no WordPress dependencies — so it can be
 * unit tested directly without the WP test bootstrap. A later upgrade can
 * layer image-classification on top of this fallback.
 *
 * @package SdAiAgent\Services
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Heuristic categoriser for interview-stage photo uploads.
 *
 * @since 1.16.0
 */
final class InterviewUploadCategoriser {

	/** Category slug for shopfront / interior / exterior / venue. */
	public const CATEGORY_SPACE = 'space';

	/** Category slug for products / drinks / food / dishes. */
	public const CATEGORY_PRODUCT = 'product';

	/** Category slug for team / staff / owners / portraits. */
	public const CATEGORY_TEAM = 'team';

	/** Category slug for events / performances / shows. */
	public const CATEGORY_EVENT = 'event';

	/** Fallback category when no keyword matches. */
	public const CATEGORY_OTHER = 'other';

	/**
	 * Allowed categories.
	 *
	 * @var list<string>
	 */
	public const ALL_CATEGORIES = [
		self::CATEGORY_SPACE,
		self::CATEGORY_PRODUCT,
		self::CATEGORY_TEAM,
		self::CATEGORY_EVENT,
		self::CATEGORY_OTHER,
	];

	/**
	 * Keyword sets per category. Lowercased and matched as substrings of the
	 * normalised filename (extension stripped, separators replaced with spaces).
	 *
	 * @var array<string, list<string>>
	 */
	private const KEYWORDS = [
		self::CATEGORY_SPACE   => [
			'space',
			'shopfront',
			'storefront',
			'exterior',
			'interior',
			'venue',
			'building',
			'facade',
			'frontage',
			'shop',
			'store',
			'cafe',
			'restaurant',
			'bar',
			'room',
			'studio',
			'office',
			'inside',
			'outside',
		],
		self::CATEGORY_PRODUCT => [
			'product',
			'drink',
			'food',
			'menu',
			'dish',
			'coffee',
			'latte',
			'espresso',
			'beer',
			'wine',
			'cocktail',
			'burger',
			'pizza',
			'plate',
			'bottle',
			'cup',
			'mug',
			'item',
			'pastry',
			'cake',
			'meal',
			'sandwich',
			'tea',
			'cocktail',
		],
		self::CATEGORY_TEAM    => [
			'team',
			'staff',
			'owner',
			'founder',
			'crew',
			'people',
			'portrait',
			'headshot',
			'barista',
			'chef',
			'manager',
			'employee',
			'bio',
		],
		self::CATEGORY_EVENT   => [
			'event',
			'show',
			'concert',
			'party',
			'festival',
			'gig',
			'performance',
			'launch',
			'opening',
			'meetup',
			'wedding',
			'tasting',
		],
	];

	/**
	 * Classify a filename into one of the five interview-upload categories.
	 *
	 * Matching is case-insensitive on the filename stem (without extension).
	 * Separators (`-`, `_`, `.`) are normalised to spaces so that keywords
	 * embedded inside larger filenames are still detected.
	 *
	 * Ties are broken by category priority: space > product > team > event.
	 * This ordering is deliberate: a "shopfront-team" photo is more likely
	 * to be about the space (because team photos usually have a person's
	 * name in them), and a "team-product-launch" is more likely a launch
	 * event captured for marketing.
	 *
	 * @param string $filename Original filename (with or without path).
	 * @return string One of the CATEGORY_* constants.
	 */
	public static function categorise( string $filename ): string {
		$stem = self::normalise_filename( $filename );

		if ( '' === $stem ) {
			return self::CATEGORY_OTHER;
		}

		foreach ( self::KEYWORDS as $category => $keywords ) {
			foreach ( $keywords as $keyword ) {
				if ( self::contains_word( $stem, $keyword ) ) {
					return $category;
				}
			}
		}

		return self::CATEGORY_OTHER;
	}

	/**
	 * Whether a category slug is a recognised interview-upload category.
	 *
	 * @param string $category Candidate category slug.
	 */
	public static function is_valid_category( string $category ): bool {
		return in_array( $category, self::ALL_CATEGORIES, true );
	}

	/**
	 * Normalise the filename to a lowercase space-separated stem.
	 *
	 * @param string $filename Raw filename, possibly with directory components.
	 */
	private static function normalise_filename( string $filename ): string {
		$base = basename( $filename );

		// Strip file extension.
		$dot = strrpos( $base, '.' );
		if ( false !== $dot ) {
			$base = substr( $base, 0, $dot );
		}

		// Lowercase and replace common separators with spaces.
		$base = strtolower( $base );
		$base = (string) preg_replace( '/[-_.]+/', ' ', $base );
		$base = (string) preg_replace( '/\s+/', ' ', $base );

		return trim( $base );
	}

	/**
	 * Whether the normalised stem contains the keyword as a whole word.
	 *
	 * Whole-word matching prevents "shop" from triggering on words like
	 * "workshop", and "tea" from triggering on "team". We pad the stem
	 * with spaces so word boundaries are simple substring lookups.
	 *
	 * @param string $stem    Pre-normalised filename stem.
	 * @param string $keyword Lowercased keyword to match.
	 */
	private static function contains_word( string $stem, string $keyword ): bool {
		return false !== strpos( ' ' . $stem . ' ', ' ' . $keyword . ' ' );
	}
}
