<?php
/**
 * Test case for the AbilityVisibility resolver.
 *
 * Covers the full classification decision tree and the three surface
 * predicates (`for_ai_chat()`, `for_mcp()`, `for_admin_picker()`).
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\AbilityVisibility;
use SdAiAgent\Core\Settings;
use WP_Ability;
use WP_UnitTestCase;

/**
 * Behavioural tests for the visibility classifier.
 */
class AbilityVisibilityTest extends WP_UnitTestCase {

	/**
	 * Skip the suite when WP 7.0+ Abilities API is unavailable.
	 * Set third_party_mode to 'auto' so classification-tree tests exercise the
	 * full tiered resolver rather than the legacy all-pass shim.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WP_Ability' ) ) {
			$this->markTestSkipped( 'WP_Ability not available — requires WP 7.0+.' );
		}
		// Drive the resolver with the full tiered model so classification
		// tier assertions (partner, heuristic, private-unknown) are exercised.
		update_option( Settings::OPTION_NAME, array( 'third_party_mode' => 'auto' ) );
	}

	/**
	 * Drop test-registered filters and restore default settings between cases.
	 */
	public function tearDown(): void {
		remove_all_filters( 'sd_ai_agent_partner_namespaces' );
		remove_all_filters( 'sd_ai_agent_partner_categories' );
		delete_option( Settings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Construct an in-memory WP_Ability with optional overrides.
	 *
	 * Tests intentionally instantiate WP_Ability directly rather than going
	 * through `wp_register_ability()` so the global registry state does not
	 * leak between cases.
	 *
	 * @param string              $name     Ability id (defaults to a synthetic namespace).
	 * @param array<string,mixed> $overrides Partial property overrides.
	 * @return WP_Ability
	 */
	private function make_ability( string $name = 'random-plugin/example', array $overrides = array() ): WP_Ability {
		$defaults = array(
			'label'               => 'Example',
			'description'         => 'An example ability.',
			'category'            => 'random-plugin',
			'execute_callback'    => '__return_true',
			'permission_callback' => '__return_true',
		);

		$properties = array_replace( $defaults, $overrides );

		return new WP_Ability( $name, $properties );
	}

	// ─── classify(): explicit private ────────────────────────────────────

	/**
	 * `ai_hidden === true` always wins, regardless of other meta.
	 */
	public function test_ai_hidden_true_classifies_private_explicit(): void {
		$ability = $this->make_ability(
			'sd-ai-agent/internal',
			array(
				'meta' => array(
					'ai_hidden' => true,
					// Even when paired with mcp.public = true, ai_hidden wins.
					'mcp'       => array( 'public' => true ),
				),
			)
		);

		$this->assertSame(
			AbilityVisibility::CLASSIFICATION_PRIVATE_EXPLICIT,
			AbilityVisibility::classify( $ability )
		);
		$this->assertFalse( AbilityVisibility::for_ai_chat( $ability ) );
		$this->assertFalse( AbilityVisibility::for_mcp( $ability ) );
		$this->assertFalse( AbilityVisibility::for_admin_picker( $ability ) );
	}

	/**
	 * `meta.mcp.public === false` is treated as explicit private.
	 */
	public function test_mcp_public_false_classifies_private_explicit(): void {
		$ability = $this->make_ability(
			'sd-ai-agent/internal',
			array(
				'meta' => array(
					'mcp' => array( 'public' => false ),
				),
			)
		);

		$this->assertSame(
			AbilityVisibility::CLASSIFICATION_PRIVATE_EXPLICIT,
			AbilityVisibility::classify( $ability )
		);
	}

	/**
	 * Flat `meta.mcp_public === false` alias is honoured.
	 */
	public function test_flat_mcp_public_false_classifies_private_explicit(): void {
		$ability = $this->make_ability(
			'sd-ai-agent/legacy-shape',
			array(
				'meta' => array(
					'mcp_public' => false,
				),
			)
		);

		$this->assertSame(
			AbilityVisibility::CLASSIFICATION_PRIVATE_EXPLICIT,
			AbilityVisibility::classify( $ability )
		);
	}

	// ─── classify(): explicit public ─────────────────────────────────────

	/**
	 * `meta.mcp.public === true` from a stranger namespace still wins.
	 *
	 * This is the canonical signal — if the third-party author opts in, we
	 * trust them.
	 */
	public function test_mcp_public_true_classifies_public_explicit(): void {
		$ability = $this->make_ability(
			'random-plugin/foo',
			array(
				'meta' => array(
					'mcp' => array( 'public' => true ),
				),
			)
		);

		$this->assertSame(
			AbilityVisibility::CLASSIFICATION_PUBLIC_EXPLICIT,
			AbilityVisibility::classify( $ability )
		);
		$this->assertTrue( AbilityVisibility::for_ai_chat( $ability ) );
		$this->assertTrue( AbilityVisibility::for_mcp( $ability ) );
	}

	/**
	 * Non-boolean `meta.mcp.public` is ignored (falls through to heuristics).
	 *
	 * Junk values must not unlock either side of the gate.
	 */
	public function test_non_boolean_mcp_public_is_ignored(): void {
		$ability = $this->make_ability(
			'random-plugin/junk',
			array(
				'meta' => array(
					'mcp' => array( 'public' => 'yes' ),
				),
			)
		);

		// `random-plugin` is unknown; ability still has description + category,
		// so it should fall through to the heuristic tier rather than
		// being treated as explicit-public from the malformed value.
		$this->assertSame(
			AbilityVisibility::CLASSIFICATION_PUBLIC_HEURISTIC,
			AbilityVisibility::classify( $ability )
		);
	}

	// ─── classify(): partner namespace ───────────────────────────────────

	/**
	 * Unflagged first-party abilities classify as partner-public.
	 */
	public function test_first_party_namespace_classifies_public_partner(): void {
		$ability = $this->make_ability(
			'sd-ai-agent/some-ability',
			array(
				'category' => 'sd-ai-agent',
			)
		);

		$this->assertSame(
			AbilityVisibility::CLASSIFICATION_PUBLIC_PARTNER,
			AbilityVisibility::classify( $ability )
		);
	}

	/**
	 * Documented partner namespaces classify as partner-public.
	 */
	public function test_partner_namespace_classifies_public_partner(): void {
		$ability = $this->make_ability(
			'woocommerce/products-list',
			array(
				'category' => 'woocommerce-rest',
			)
		);

		$this->assertSame(
			AbilityVisibility::CLASSIFICATION_PUBLIC_PARTNER,
			AbilityVisibility::classify( $ability )
		);
	}

	/**
	 * Runtime filter additions to the namespace list are honoured.
	 */
	public function test_runtime_partner_namespace_filter_promotes_to_partner(): void {
		add_filter(
			'sd_ai_agent_partner_namespaces',
			static function ( array $existing ): array {
				$existing[] = 'random-plugin';
				return $existing;
			}
		);

		$ability = $this->make_ability( 'random-plugin/foo' );

		$this->assertSame(
			AbilityVisibility::CLASSIFICATION_PUBLIC_PARTNER,
			AbilityVisibility::classify( $ability )
		);
	}

	// ─── classify(): partner category ────────────────────────────────────

	/**
	 * Trusted-category match wins even when the namespace is unknown.
	 */
	public function test_partner_category_classifies_public_partner(): void {
		$ability = $this->make_ability(
			'random-plugin/get-something',
			array(
				'category' => 'site', // Core ability category.
			)
		);

		$this->assertSame(
			AbilityVisibility::CLASSIFICATION_PUBLIC_PARTNER,
			AbilityVisibility::classify( $ability )
		);
	}

	// ─── classify(): heuristic ───────────────────────────────────────────

	/**
	 * Description + category present, no other signal → heuristic public.
	 */
	public function test_well_formed_unknown_namespace_classifies_heuristic(): void {
		$ability = $this->make_ability(
			'random-plugin/heuristic',
			array(
				'description' => 'A well-described ability with a category.',
				'category'    => 'random-plugin',
			)
		);

		$this->assertSame(
			AbilityVisibility::CLASSIFICATION_PUBLIC_HEURISTIC,
			AbilityVisibility::classify( $ability )
		);
	}

	// ─── classify(): private-unknown ─────────────────────────────────────

	/**
	 * Whitespace-only description does not satisfy the heuristic.
	 *
	 * `WP_Ability::prepare_properties()` rejects truly empty descriptions
	 * (`description => ''`) at construction time via `empty()`, so the
	 * resolver only ever sees whitespace-or-better. This case ensures that
	 * `trim()`-equivalent whitespace still falls through to private-unknown
	 * rather than satisfying the heuristic.
	 */
	public function test_whitespace_only_description_classifies_private_unknown(): void {
		$ability = $this->make_ability(
			'random-plugin/whitespace-desc',
			array(
				'description' => "   \t\n  ",
				'category'    => 'random-plugin',
			)
		);

		$this->assertSame(
			AbilityVisibility::CLASSIFICATION_PRIVATE_UNKNOWN,
			AbilityVisibility::classify( $ability )
		);
	}

	// ─── Admin picker leniency ───────────────────────────────────────────

	/**
	 * The admin picker shows everything except explicit-private classifications.
	 *
	 * This includes private-unknown abilities — the agent builder UI is
	 * authoritative for surfacing the full registry to administrators so they
	 * can opt-in unrecognised abilities.
	 */
	public function test_admin_picker_surfaces_private_unknown(): void {
		$ability = $this->make_ability(
			'random-plugin/blank-desc',
			array(
				// `WP_Ability` rejects truly empty descriptions, so we use
				// whitespace — the resolver still classifies private-unknown
				// because the heuristic trims before testing for emptiness.
				'description' => "   \t  ",
				'category'    => 'random-plugin',
			)
		);

		$this->assertSame(
			AbilityVisibility::CLASSIFICATION_PRIVATE_UNKNOWN,
			AbilityVisibility::classify( $ability )
		);
		$this->assertTrue( AbilityVisibility::for_admin_picker( $ability ) );
		$this->assertFalse( AbilityVisibility::for_ai_chat( $ability ) );
	}

	/**
	 * Admin picker still hides explicit-private abilities.
	 */
	public function test_admin_picker_hides_ai_hidden(): void {
		$ability = $this->make_ability(
			'sd-ai-agent/internal',
			array(
				'meta' => array(
					'ai_hidden' => true,
				),
			)
		);

		$this->assertFalse( AbilityVisibility::for_admin_picker( $ability ) );
	}

	// ─── Helper invariants ───────────────────────────────────────────────

	// ─── Legacy mode shim ───────────────────────────────────────────────

	/**
	 * In legacy mode every non-hidden ability is treated as public-explicit.
	 *
	 * The legacy shim preserves the pre-1.9.0 behaviour where only the
	 * `ai_hidden` meta key controlled visibility.
	 */
	public function test_legacy_mode_classifies_unknown_namespace_as_public_explicit(): void {
		update_option( Settings::OPTION_NAME, array( 'third_party_mode' => 'legacy' ) );

		// Use 'random-plugin' (not a partner category) and a whitespace-only
		// description so this ability would be private-unknown in auto mode.
		// In legacy mode it must be public-explicit instead.
		$ability = $this->make_ability(
			'random-unknown/some-ability',
			array(
				'description' => "   \t  ", // Would be private-unknown in auto mode.
				'category'    => 'random-plugin',
			)
		);

		$this->assertSame(
			AbilityVisibility::CLASSIFICATION_PUBLIC_EXPLICIT,
			AbilityVisibility::classify( $ability )
		);
		$this->assertTrue( AbilityVisibility::for_ai_chat( $ability ) );
		$this->assertTrue( AbilityVisibility::for_mcp( $ability ) );
	}

	/**
	 * In legacy mode explicit-private abilities (ai_hidden) are still hidden.
	 */
	public function test_legacy_mode_still_hides_ai_hidden(): void {
		update_option( Settings::OPTION_NAME, array( 'third_party_mode' => 'legacy' ) );

		$ability = $this->make_ability(
			'random-unknown/hidden',
			array(
				'meta' => array( 'ai_hidden' => true ),
			)
		);

		$this->assertSame(
			AbilityVisibility::CLASSIFICATION_PRIVATE_EXPLICIT,
			AbilityVisibility::classify( $ability )
		);
		$this->assertFalse( AbilityVisibility::for_ai_chat( $ability ) );
		$this->assertFalse( AbilityVisibility::for_mcp( $ability ) );
	}

	/**
	 * In strict mode only mcp.public === true passes; all others are private-unknown.
	 */
	public function test_strict_mode_hides_partner_namespace_without_mcp_public(): void {
		update_option( Settings::OPTION_NAME, array( 'third_party_mode' => 'strict' ) );

		// A first-party ability that is not explicitly flagged mcp.public.
		$ability = $this->make_ability(
			'sd-ai-agent/some-ability',
			array(
				'category' => 'sd-ai-agent',
			)
		);

		$this->assertSame(
			AbilityVisibility::CLASSIFICATION_PRIVATE_UNKNOWN,
			AbilityVisibility::classify( $ability )
		);
		$this->assertFalse( AbilityVisibility::for_mcp( $ability ) );
		// Admin picker still shows it (not explicit-private).
		$this->assertTrue( AbilityVisibility::for_admin_picker( $ability ) );
	}

	/**
	 * In strict mode mcp.public === true still passes.
	 */
	public function test_strict_mode_allows_explicit_mcp_public(): void {
		update_option( Settings::OPTION_NAME, array( 'third_party_mode' => 'strict' ) );

		$ability = $this->make_ability(
			'random-plugin/opted-in',
			array(
				'meta' => array( 'mcp' => array( 'public' => true ) ),
			)
		);

		$this->assertSame(
			AbilityVisibility::CLASSIFICATION_PUBLIC_EXPLICIT,
			AbilityVisibility::classify( $ability )
		);
		$this->assertTrue( AbilityVisibility::for_mcp( $ability ) );
	}

	// ─── Settings helper ──────────────────────────────────────────────────────

	/**
	 * get_third_party_mode() returns 'legacy' when no setting is stored.
	 */
	public function test_get_third_party_mode_defaults_to_legacy(): void {
		delete_option( Settings::OPTION_NAME );
		$this->assertSame( 'legacy', Settings::get_third_party_mode() );
	}

	/**
	 * get_third_party_mode() returns the stored value when valid.
	 */
	public function test_get_third_party_mode_returns_stored_value(): void {
		update_option( Settings::OPTION_NAME, array( 'third_party_mode' => 'auto' ) );
		$this->assertSame( 'auto', Settings::get_third_party_mode() );

		update_option( Settings::OPTION_NAME, array( 'third_party_mode' => 'strict' ) );
		$this->assertSame( 'strict', Settings::get_third_party_mode() );
	}

	/**
	 * get_third_party_mode() rejects invalid values and falls back to 'legacy'.
	 */
	public function test_get_third_party_mode_rejects_invalid_values(): void {
		update_option( Settings::OPTION_NAME, array( 'third_party_mode' => 'unknown-value' ) );
		$this->assertSame( 'legacy', Settings::get_third_party_mode() );
	}

	// ─── Helper invariants ───────────────────────────────────────────────

	/**
	 * `is_public_classification()` agrees with itself for every constant.
	 */
	public function test_is_public_classification_constants(): void {
		$this->assertTrue(
			AbilityVisibility::is_public_classification( AbilityVisibility::CLASSIFICATION_PUBLIC_EXPLICIT )
		);
		$this->assertTrue(
			AbilityVisibility::is_public_classification( AbilityVisibility::CLASSIFICATION_PUBLIC_PARTNER )
		);
		$this->assertTrue(
			AbilityVisibility::is_public_classification( AbilityVisibility::CLASSIFICATION_PUBLIC_HEURISTIC )
		);
		$this->assertFalse(
			AbilityVisibility::is_public_classification( AbilityVisibility::CLASSIFICATION_PRIVATE_EXPLICIT )
		);
		$this->assertFalse(
			AbilityVisibility::is_public_classification( AbilityVisibility::CLASSIFICATION_PRIVATE_UNKNOWN )
		);
	}

	/**
	 * Missing `meta` entirely is handled gracefully (treated as no flags set).
	 */
	public function test_missing_meta_falls_through_to_heuristic(): void {
		$ability = $this->make_ability(
			'random-plugin/no-meta',
			array(
				'category' => 'random-plugin',
				// meta not set at all.
			)
		);

		$this->assertSame(
			AbilityVisibility::CLASSIFICATION_PUBLIC_HEURISTIC,
			AbilityVisibility::classify( $ability )
		);
	}
}
