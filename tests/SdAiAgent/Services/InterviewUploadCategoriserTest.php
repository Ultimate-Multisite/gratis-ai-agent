<?php

declare(strict_types=1);
/**
 * Tests for InterviewUploadCategoriser (GH#1534).
 *
 * Pure-function tests — no WordPress fixtures are required. The categoriser
 * classifies a filename into one of the five interview-upload buckets
 * (space, product, team, event, other) using whole-word keyword matching
 * on the normalised filename stem.
 *
 * @package SdAiAgent\Tests\Services
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Services;

use SdAiAgent\Services\InterviewUploadCategoriser;
use WP_UnitTestCase;

/**
 * Test InterviewUploadCategoriser behaviour.
 */
class InterviewUploadCategoriserTest extends WP_UnitTestCase {

	// ── space keywords ───────────────────────────────────────────────────

	public function test_shopfront_classified_as_space(): void {
		$this->assertSame(
			InterviewUploadCategoriser::CATEGORY_SPACE,
			InterviewUploadCategoriser::categorise( 'shopfront-2024.jpg' )
		);
	}

	public function test_interior_classified_as_space(): void {
		$this->assertSame(
			InterviewUploadCategoriser::CATEGORY_SPACE,
			InterviewUploadCategoriser::categorise( 'cafe_interior.png' )
		);
	}

	public function test_exterior_classified_as_space(): void {
		$this->assertSame(
			InterviewUploadCategoriser::CATEGORY_SPACE,
			InterviewUploadCategoriser::categorise( 'venue-exterior.webp' )
		);
	}

	// ── product keywords ─────────────────────────────────────────────────

	public function test_latte_classified_as_product(): void {
		$this->assertSame(
			InterviewUploadCategoriser::CATEGORY_PRODUCT,
			InterviewUploadCategoriser::categorise( 'latte-art-1.jpg' )
		);
	}

	public function test_burger_classified_as_product(): void {
		$this->assertSame(
			InterviewUploadCategoriser::CATEGORY_PRODUCT,
			InterviewUploadCategoriser::categorise( 'menu_burger_special.jpg' )
		);
	}

	public function test_drink_classified_as_product(): void {
		$this->assertSame(
			InterviewUploadCategoriser::CATEGORY_PRODUCT,
			InterviewUploadCategoriser::categorise( 'signature-drink.png' )
		);
	}

	// ── team keywords ────────────────────────────────────────────────────

	public function test_team_keyword_classified_as_team(): void {
		$this->assertSame(
			InterviewUploadCategoriser::CATEGORY_TEAM,
			InterviewUploadCategoriser::categorise( 'team-photo-2025.jpg' )
		);
	}

	public function test_headshot_classified_as_team(): void {
		$this->assertSame(
			InterviewUploadCategoriser::CATEGORY_TEAM,
			InterviewUploadCategoriser::categorise( 'jane-headshot.jpg' )
		);
	}

	public function test_barista_classified_as_team(): void {
		$this->assertSame(
			InterviewUploadCategoriser::CATEGORY_TEAM,
			InterviewUploadCategoriser::categorise( 'barista_portrait.jpg' )
		);
	}

	// ── event keywords ───────────────────────────────────────────────────

	public function test_event_classified_as_event(): void {
		$this->assertSame(
			InterviewUploadCategoriser::CATEGORY_EVENT,
			InterviewUploadCategoriser::categorise( 'summer-event-2025.jpg' )
		);
	}

	public function test_festival_classified_as_event(): void {
		$this->assertSame(
			InterviewUploadCategoriser::CATEGORY_EVENT,
			InterviewUploadCategoriser::categorise( 'jazz_festival_night.png' )
		);
	}

	// ── other fallback ───────────────────────────────────────────────────

	public function test_generic_filename_falls_back_to_other(): void {
		$this->assertSame(
			InterviewUploadCategoriser::CATEGORY_OTHER,
			InterviewUploadCategoriser::categorise( 'IMG_4729.jpg' )
		);
	}

	public function test_empty_filename_falls_back_to_other(): void {
		$this->assertSame(
			InterviewUploadCategoriser::CATEGORY_OTHER,
			InterviewUploadCategoriser::categorise( '' )
		);
	}

	public function test_extension_only_falls_back_to_other(): void {
		$this->assertSame(
			InterviewUploadCategoriser::CATEGORY_OTHER,
			InterviewUploadCategoriser::categorise( '.jpg' )
		);
	}

	// ── whole-word matching ──────────────────────────────────────────────

	public function test_workshop_does_not_match_shop_substring(): void {
		// "workshop" must NOT classify as space — "shop" is only a match as
		// a whole word, otherwise common words trigger false positives.
		$this->assertSame(
			InterviewUploadCategoriser::CATEGORY_OTHER,
			InterviewUploadCategoriser::categorise( 'workshop-recap.jpg' )
		);
	}

	public function test_team_does_not_match_tea_substring(): void {
		// "tea" is a product keyword; "team" must NOT pick it up.
		$this->assertSame(
			InterviewUploadCategoriser::CATEGORY_TEAM,
			InterviewUploadCategoriser::categorise( 'team-snapshot.jpg' )
		);
	}

	// ── case insensitivity ───────────────────────────────────────────────

	public function test_categoriser_is_case_insensitive(): void {
		$this->assertSame(
			InterviewUploadCategoriser::CATEGORY_SPACE,
			InterviewUploadCategoriser::categorise( 'SHOPFRONT.JPG' )
		);
	}

	// ── basename handling ────────────────────────────────────────────────

	public function test_categoriser_uses_basename_only(): void {
		// Directory components must not influence categorisation, only the
		// final filename matters.
		$this->assertSame(
			InterviewUploadCategoriser::CATEGORY_PRODUCT,
			InterviewUploadCategoriser::categorise( '/random/path/team-folder/latte.jpg' )
		);
	}

	// ── category validation ──────────────────────────────────────────────

	public function test_is_valid_category_accepts_all_five_buckets(): void {
		foreach ( InterviewUploadCategoriser::ALL_CATEGORIES as $cat ) {
			$this->assertTrue(
				InterviewUploadCategoriser::is_valid_category( $cat ),
				"Category '{$cat}' should be valid."
			);
		}
	}

	public function test_is_valid_category_rejects_unknown_slug(): void {
		$this->assertFalse(
			InterviewUploadCategoriser::is_valid_category( 'mystery' )
		);
	}

	public function test_is_valid_category_rejects_empty_string(): void {
		$this->assertFalse( InterviewUploadCategoriser::is_valid_category( '' ) );
	}
}
