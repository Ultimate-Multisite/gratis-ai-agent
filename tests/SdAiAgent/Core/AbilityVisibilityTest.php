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
use WP_Ability;
use WP_UnitTestCase;

/**
 * Behavioural tests for the visibility classifier.
 */
class AbilityVisibilityTest extends WP_UnitTestCase {

	/**
	 * Skip the suite when WP 7.0+ Abilities API is unavailable.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WP_Ability' ) ) {
			$this->markTestSkipped( 'WP_Ability not available — requires WP 7.0+.' );
		}
	}

	/**
	 * Drop test-registered filters between cases.
	 */
	public function tearDown(): void {
		remove_all_filters( 'sd_ai_agent_partner_namespaces' );
		remove_all_filters( 'sd_ai_agent_partner_categories' );
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
