<?php
/**
 * Test case for the PartnerAllowlist registry.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Abilities\ThirdParty;

use SdAiAgent\Abilities\ThirdParty\PartnerAllowlist;
use WP_UnitTestCase;

/**
 * Covers the static accessors and the documented filter contracts.
 */
class PartnerAllowlistTest extends WP_UnitTestCase {

	/**
	 * Drop any test-registered filter callbacks between cases.
	 */
	public function tearDown(): void {
		remove_all_filters( 'sd_ai_agent_partner_namespaces' );
		remove_all_filters( 'sd_ai_agent_partner_categories' );
		parent::tearDown();
	}

	// ─── namespaces() ────────────────────────────────────────────────────

	/**
	 * The default namespace list contains the first-party slugs we ship.
	 */
	public function test_namespaces_contain_first_party(): void {
		$namespaces = PartnerAllowlist::namespaces();

		$this->assertContains( 'sd-ai-agent', $namespaces );
		$this->assertContains( 'wp-cli', $namespaces );
	}

	/**
	 * The default namespace list contains the documented partner slugs.
	 */
	public function test_namespaces_contain_verified_partners(): void {
		$namespaces = PartnerAllowlist::namespaces();

		$this->assertContains( 'woocommerce', $namespaces );
		$this->assertContains( 'multisite-ultimate', $namespaces );
		$this->assertContains( 'mcp-adapter', $namespaces );
	}

	/**
	 * The filter can add new entries without losing the defaults.
	 */
	public function test_namespaces_filter_can_append(): void {
		add_filter(
			'sd_ai_agent_partner_namespaces',
			static function ( array $existing ): array {
				$existing[] = 'my-trusted-plugin';
				return $existing;
			}
		);

		$namespaces = PartnerAllowlist::namespaces();

		$this->assertContains( 'my-trusted-plugin', $namespaces );
		$this->assertContains( 'sd-ai-agent', $namespaces, 'Defaults must be retained alongside additions.' );
	}

	/**
	 * Non-string entries returned by the filter are dropped silently.
	 */
	public function test_namespaces_filter_strips_non_strings(): void {
		add_filter(
			'sd_ai_agent_partner_namespaces',
			static function (): array {
				return array( 'good', 42, null, '', new \stdClass(), 'also-good' );
			}
		);

		$namespaces = PartnerAllowlist::namespaces();

		$this->assertSame( array( 'good', 'also-good' ), $namespaces );
	}

	/**
	 * A non-array filter return falls back to the documented defaults.
	 */
	public function test_namespaces_filter_non_array_falls_back_to_default(): void {
		add_filter(
			'sd_ai_agent_partner_namespaces',
			static function () {
				return 'not an array';
			}
		);

		$namespaces = PartnerAllowlist::namespaces();

		$this->assertContains( 'sd-ai-agent', $namespaces );
		$this->assertContains( 'woocommerce', $namespaces );
	}

	/**
	 * Duplicate entries (case-insensitive) collapse to a single slug.
	 */
	public function test_namespaces_dedup_case_insensitive(): void {
		add_filter(
			'sd_ai_agent_partner_namespaces',
			static function (): array {
				return array( 'TrustMe', 'trustme', 'TRUSTME', '  trustme  ' );
			}
		);

		$namespaces = PartnerAllowlist::namespaces();

		$this->assertSame( array( 'trustme' ), $namespaces );
	}

	// ─── categories() ────────────────────────────────────────────────────

	/**
	 * Core ability categories are present by default.
	 */
	public function test_categories_contain_core_slugs(): void {
		$categories = PartnerAllowlist::categories();

		$this->assertContains( 'site', $categories );
		$this->assertContains( 'user', $categories );
		$this->assertContains( 'ai-experiments', $categories );
	}

	/**
	 * The categories filter is honoured.
	 */
	public function test_categories_filter_can_append(): void {
		add_filter(
			'sd_ai_agent_partner_categories',
			static function ( array $existing ): array {
				$existing[] = 'my-category';
				return $existing;
			}
		);

		$categories = PartnerAllowlist::categories();

		$this->assertContains( 'my-category', $categories );
		$this->assertContains( 'site', $categories );
	}

	// ─── is_partner_namespace() ──────────────────────────────────────────

	/**
	 * First-party namespaces match.
	 */
	public function test_is_partner_namespace_matches_first_party(): void {
		$this->assertTrue( PartnerAllowlist::is_partner_namespace( 'sd-ai-agent/memory-save' ) );
		$this->assertTrue( PartnerAllowlist::is_partner_namespace( 'wp-cli/execute' ) );
	}

	/**
	 * Verified-partner namespaces match.
	 */
	public function test_is_partner_namespace_matches_partner(): void {
		$this->assertTrue( PartnerAllowlist::is_partner_namespace( 'woocommerce/products-list' ) );
		$this->assertTrue( PartnerAllowlist::is_partner_namespace( 'multisite-ultimate/site-create-item' ) );
	}

	/**
	 * Unknown namespaces never match.
	 */
	public function test_is_partner_namespace_rejects_unknown(): void {
		$this->assertFalse( PartnerAllowlist::is_partner_namespace( 'totally-random/foo' ) );
		$this->assertFalse( PartnerAllowlist::is_partner_namespace( 'evil/exec' ) );
	}

	/**
	 * Ability ids without a slash separator do not match.
	 */
	public function test_is_partner_namespace_rejects_unnamespaced(): void {
		$this->assertFalse( PartnerAllowlist::is_partner_namespace( 'no-slash-here' ) );
		$this->assertFalse( PartnerAllowlist::is_partner_namespace( '' ) );
	}

	/**
	 * Matching is case-insensitive on the namespace component.
	 */
	public function test_is_partner_namespace_case_insensitive(): void {
		$this->assertTrue( PartnerAllowlist::is_partner_namespace( 'SD-AI-AGENT/Memory-Save' ) );
		$this->assertTrue( PartnerAllowlist::is_partner_namespace( 'WooCommerce/products-list' ) );
	}

	// ─── is_first_party_namespace() ──────────────────────────────────────

	/**
	 * First-party check excludes verified partners.
	 */
	public function test_is_first_party_namespace_only_matches_first_party(): void {
		$this->assertTrue( PartnerAllowlist::is_first_party_namespace( 'sd-ai-agent/memory-save' ) );
		$this->assertTrue( PartnerAllowlist::is_first_party_namespace( 'wp-cli/execute' ) );
		$this->assertTrue( PartnerAllowlist::is_first_party_namespace( 'sd-ai-agent-js/screenshot' ) );

		$this->assertFalse( PartnerAllowlist::is_first_party_namespace( 'woocommerce/orders-list' ) );
		$this->assertFalse( PartnerAllowlist::is_first_party_namespace( 'random/foo' ) );
	}

	// ─── is_partner_category() ───────────────────────────────────────────

	/**
	 * Core categories match.
	 */
	public function test_is_partner_category_matches_core_slugs(): void {
		$this->assertTrue( PartnerAllowlist::is_partner_category( 'site' ) );
		$this->assertTrue( PartnerAllowlist::is_partner_category( 'user' ) );
	}

	/**
	 * Unknown categories do not match.
	 */
	public function test_is_partner_category_rejects_unknown(): void {
		$this->assertFalse( PartnerAllowlist::is_partner_category( 'unknown-cat' ) );
		$this->assertFalse( PartnerAllowlist::is_partner_category( '' ) );
		$this->assertFalse( PartnerAllowlist::is_partner_category( '   ' ) );
	}

	/**
	 * Whitespace and casing are normalised.
	 */
	public function test_is_partner_category_normalises_input(): void {
		$this->assertTrue( PartnerAllowlist::is_partner_category( '  SITE  ' ) );
		$this->assertTrue( PartnerAllowlist::is_partner_category( 'WooCommerce-Rest' ) );
	}
}
