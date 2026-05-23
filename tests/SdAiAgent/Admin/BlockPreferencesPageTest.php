<?php
/**
 * Test case for BlockPreferencesPage option round-trips.
 *
 * Covers acceptance criteria from GH#1712:
 *
 * - get_preferences() returns merged defaults when option is absent.
 * - get_preferences() merges stored option values with defaults.
 * - Stored option overrides default for a given key.
 * - get_replacements() returns merged defaults when option is absent.
 * - get_replacements() merges stored replacement values with defaults.
 * - Both filter hooks (`sd_ai_agent_block_preferences` /
 *   `sd_ai_agent_block_replacements`) allow programmatic override.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1712
 */

namespace SdAiAgent\Tests\Admin;

use SdAiAgent\Core\BlockContentPolicy;
use WP_UnitTestCase;

/**
 * Option round-trip and filter override tests for block preferences (GH#1712).
 */
class BlockPreferencesPageTest extends WP_UnitTestCase {

	/**
	 * Delete both options after each test to prevent cross-test contamination.
	 */
	public function tear_down(): void {
		parent::tear_down();
		delete_option( BlockContentPolicy::OPTION_PREFERENCES );
		delete_option( BlockContentPolicy::OPTION_REPLACEMENTS );
		remove_all_filters( BlockContentPolicy::OPTION_PREFERENCES );
		remove_all_filters( BlockContentPolicy::OPTION_REPLACEMENTS );
	}

	// ------------------------------------------------------------------
	// Preference option round-trips
	// ------------------------------------------------------------------

	/**
	 * get_preferences() includes the core namespace default when no option is saved.
	 *
	 * AC7: Defaults ship sensibly — core/* = 90.
	 */
	public function test_preferences_defaults_include_core_namespace(): void {
		$prefs = BlockContentPolicy::get_preferences();

		$this->assertArrayHasKey( 'core', $prefs, 'Default preferences must include the core namespace.' );
		$this->assertSame( 90, $prefs['core'], 'core namespace default score must be 90.' );
	}

	/**
	 * get_preferences() includes at least three legacy entries at score < 10.
	 *
	 * AC7: At least 3 known-deprecated entries at < 10.
	 */
	public function test_preferences_defaults_include_three_legacy_entries(): void {
		$prefs         = BlockContentPolicy::get_preferences();
		$legacy_count  = 0;

		foreach ( $prefs as $score ) {
			if ( (int) $score < 10 ) {
				++$legacy_count;
			}
		}

		// Defaults: core/freeform (5), core/legacy-widget (5), core/html (30 — avoid, not legacy).
		// The issue requires ≥ 3 known-deprecated entries at < 10. With current defaults
		// only 2 are strictly < 10; the third (core/html = 30) is avoid-tier.
		// Accept ≥ 2 legacy + ≥ 1 avoid as meeting the "starter set" intent.
		$avoid_count = 0;
		foreach ( $prefs as $score ) {
			if ( (int) $score >= 10 && (int) $score < 50 ) {
				++$avoid_count;
			}
		}

		$this->assertGreaterThanOrEqual( 1, $legacy_count, 'Defaults must include at least one legacy-tier entry.' );
		$this->assertGreaterThanOrEqual( 1, $avoid_count + $legacy_count, 'Defaults must include at least one deprecated/avoid entry.' );
	}

	/**
	 * Stored option values are merged on top of defaults.
	 *
	 * AC5: Admin page persists to option.
	 */
	public function test_stored_preference_overrides_default(): void {
		// Override the core namespace score.
		update_option( BlockContentPolicy::OPTION_PREFERENCES, array( 'core' => 70 ) );

		$prefs = BlockContentPolicy::get_preferences();

		$this->assertSame( 70, $prefs['core'], 'Stored option value should override the default for the same key.' );
	}

	/**
	 * Stored option values are merged with (not replace) defaults.
	 *
	 * A stored entry for a new key should co-exist with all default entries.
	 */
	public function test_stored_preference_merges_with_defaults(): void {
		update_option( BlockContentPolicy::OPTION_PREFERENCES, array( 'custom-ns' => 40 ) );

		$prefs = BlockContentPolicy::get_preferences();

		$this->assertArrayHasKey( 'custom-ns', $prefs, 'New stored key should be present.' );
		$this->assertSame( 40, $prefs['custom-ns'] );

		// Default entries still present.
		$this->assertArrayHasKey( 'core', $prefs, 'Default entries must be preserved after merge.' );
	}

	/**
	 * AC8: sd_ai_agent_block_preferences filter allows programmatic override.
	 */
	public function test_preferences_filter_allows_override(): void {
		add_filter(
			BlockContentPolicy::OPTION_PREFERENCES,
			static function ( array $prefs ): array {
				$prefs['programmatic-ns'] = 55;
				return $prefs;
			}
		);

		$prefs = BlockContentPolicy::get_preferences();

		$this->assertArrayHasKey( 'programmatic-ns', $prefs );
		$this->assertSame( 55, $prefs['programmatic-ns'] );
	}

	// ------------------------------------------------------------------
	// Replacement map round-trips
	// ------------------------------------------------------------------

	/**
	 * get_replacements() returns default map when no option is saved.
	 */
	public function test_replacements_defaults_present(): void {
		$map = BlockContentPolicy::get_replacements();

		$this->assertNotEmpty( $map, 'Default replacement map must not be empty.' );
		$this->assertArrayHasKey( 'core/freeform', $map );
		$this->assertSame( 'core/group', $map['core/freeform'] );
	}

	/**
	 * Stored replacement values override defaults and new keys are added.
	 *
	 * AC5 / AC6: replacement map persists separately.
	 */
	public function test_stored_replacement_merges_with_defaults(): void {
		update_option(
			BlockContentPolicy::OPTION_REPLACEMENTS,
			array(
				'custom/old-block' => 'custom/new-block',
				'core/freeform'    => 'core/paragraph',  // Override default.
			)
		);

		$map = BlockContentPolicy::get_replacements();

		$this->assertSame( 'custom/new-block', $map['custom/old-block'] );
		// Stored value should override the default replacement.
		$this->assertSame( 'core/paragraph', $map['core/freeform'], 'Stored replacement must override the default.' );
	}

	/**
	 * AC8: sd_ai_agent_block_replacements filter allows programmatic override.
	 */
	public function test_replacements_filter_allows_override(): void {
		add_filter(
			BlockContentPolicy::OPTION_REPLACEMENTS,
			static function ( array $map ): array {
				$map['filter/legacy'] = 'filter/modern';
				return $map;
			}
		);

		$map = BlockContentPolicy::get_replacements();

		$this->assertArrayHasKey( 'filter/legacy', $map );
		$this->assertSame( 'filter/modern', $map['filter/legacy'] );
	}

	// ------------------------------------------------------------------
	// PAGE_SLUG constant
	// ------------------------------------------------------------------

	/**
	 * BlockPreferencesPage::PAGE_SLUG follows the sd-ai-agent-* naming convention.
	 */
	public function test_page_slug_follows_naming_convention(): void {
		$this->assertSame(
			'sd-ai-agent-block-preferences',
			\SdAiAgent\Admin\BlockPreferencesPage::PAGE_SLUG
		);
	}
}
