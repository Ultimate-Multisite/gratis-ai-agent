<?php

declare(strict_types=1);
/**
 * Tests for the read-only landing-page pattern catalog abilities.
 *
 * @package SdAiAgent\Tests\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\LandingPagePatternAbilities;
use SdAiAgent\Abilities\ToolCapabilities;
use SdAiAgent\Services\LandingPagePatternCatalog;
use WP_UnitTestCase;

/**
 * Verifies catalog completeness, deterministic selection, and pure abilities.
 */
class LandingPagePatternAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Both public catalog abilities are registered as REST-visible read-only tools.
	 */
	public function test_registered_abilities_are_public_readonly_and_capability_gated(): void {
		$list   = wp_get_ability( 'sd-ai-agent/list-landing-page-pattern-families' );
		$select = wp_get_ability( 'sd-ai-agent/select-landing-page-pattern-family' );

		$this->assertNotNull( $list );
		$this->assertNotNull( $select );
		foreach ( [ $list, $select ] as $ability ) {
			$this->assertTrue( $ability->get_meta()['mcp']['public'] );
			$this->assertTrue( $ability->get_meta()['annotations']['readonly'] );
			$this->assertFalse( $ability->get_meta()['annotations']['destructive'] );
			$this->assertTrue( $ability->get_meta()['annotations']['idempotent'] );
			$this->assertTrue( $ability->get_meta()['show_in_rest'] );
		}
		$this->assertSame( 'edit_theme_options', ToolCapabilities::CORE_CAP_MAP['sd-ai-agent/list-landing-page-pattern-families'] );
		$this->assertSame( 'edit_theme_options', ToolCapabilities::CORE_CAP_MAP['sd-ai-agent/select-landing-page-pattern-family'] );
		$this->assertFalse( $select->get_input_schema()['additionalProperties'] );
	}

	/**
	 * Every bounded family and variant exposes complete structural governance.
	 */
	public function test_catalog_contains_complete_governed_family_metadata(): void {
		$families = LandingPagePatternCatalog::get_families();

		$this->assertIsArray( $families, is_wp_error( $families ) ? $families->get_error_message() : '' );
		$this->assertSame(
			[
				'lead-generation',
				'focused-product-conversion',
				'booking-reservation',
				'local-visit-contact',
				'portfolio-inquiry',
				'donation-volunteering',
				'content-subscription',
			],
			array_column( $families, 'slug' )
		);

		foreach ( $families as $family ) {
			$this->assertNotEmpty( $family['visitor_goal'] );
			$this->assertNotEmpty( $family['required_content'] );
			$this->assertNotEmpty( $family['optional_content'] );
			$this->assertNotEmpty( $family['section_roles'] );
			$this->assertSame( [ 'mobile', 'tablet', 'desktop' ], array_keys( $family['responsive_behavior'] ) );
			$this->assertArrayHasKey( 'heading_hierarchy', $family['accessibility_requirements'] );
			$this->assertSame( 'stable', $family['governance']['maturity'] );
			$this->assertStringStartsWith( 'sd-ai-agent/pattern/', $family['governance']['id'] );
			foreach ( $family['core_block_allowlist'] as $block ) {
				$this->assertStringStartsWith( 'core/', $block );
			}
			foreach ( $family['variants'] as $variant ) {
				$this->assertNotEmpty( $variant['section_roles'] );
				$this->assertNotEmpty( $variant['layout_cues'] );
				$this->assertSame( 'stable', $variant['governance']['maturity'] );
				$this->assertStringStartsWith( 'sd-ai-agent/pattern/', $variant['governance']['id'] );
			}
		}
	}

	/**
	 * Incomplete catalog records fail validation before they reach an agent.
	 */
	public function test_catalog_rejects_incomplete_variant_metadata(): void {
		$families = LandingPagePatternCatalog::get_families();
		$this->assertIsArray( $families, is_wp_error( $families ) ? $families->get_error_message() : '' );

		unset( $families[0]['variants'][0]['accessibility_requirements'] );
		$result = LandingPagePatternCatalog::validate_catalog( $families );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_landing_page_pattern_incomplete_variant', $result->get_error_code() );
	}

	/**
	 * Explicit goals outrank broader site-type inference and selection is stable.
	 */
	public function test_selector_prioritizes_explicit_goal_and_is_deterministic(): void {
		$input = [
			'site_brief'        => [
				'siteName'    => 'Northstar',
				'siteType'    => 'SaaS',
				'primaryGoal' => 'Book a reservation',
			],
			'available_content' => [ 'booking_method' => 'https://example.test/reserve' ],
			'layout_notes'      => [ 'Show a menu before the reservation action.' ],
			'section_requests'  => [ 'menu' ],
		];

		$first  = LandingPagePatternAbilities::handle_select( $input );
		$second = LandingPagePatternAbilities::handle_select( $input );

		$this->assertIsArray( $first, is_wp_error( $first ) ? $first->get_error_message() : '' );
		$this->assertSame( $first, $second );
		$this->assertFalse( $first['requires_clarification'] );
		$this->assertSame( 'booking-reservation', $first['selected_family']['slug'] );
		$this->assertSame( 'menu-led', $first['selected_variant']['slug'] );
		$this->assertSame( 1, $first['score_breakdown']['primary_goal']['score'] );
		$this->assertArrayNotHasKey( 'copy', $first['selected_family'] );
		$this->assertArrayNotHasKey( 'media_url', $first['selected_variant'] );
	}

	/**
	 * Missing required business content produces a clarification, not a fake page.
	 */
	public function test_selector_rejects_missing_required_content_without_fabrication(): void {
		$result = LandingPagePatternAbilities::handle_select(
			[
				'site_brief' => [
					'siteName'    => 'Example Shop',
					'siteType'    => 'E-commerce',
					'primaryGoal' => 'Purchase a product',
				],
			]
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( $result['requires_clarification'] );
		$this->assertNull( $result['selected_family'] );
		$this->assertSame( 'focused-product-conversion', $result['fallback']['slug'] );
		$this->assertContains( 'product', $result['missing_content'] );
		$this->assertContains( 'cta_destination', $result['missing_content'] );
		$this->assertStringContainsString( 'will not fabricate', implode( ' ', $result['reasons'] ) );
	}

	/**
	 * A compatible site type outranks an unrelated family with complete content.
	 */
	public function test_selector_requests_content_for_matching_site_type_before_unrelated_family(): void {
		$result = LandingPagePatternAbilities::handle_select(
			[
				'site_brief'        => [
					'siteName' => 'Northstar',
					'siteType' => 'SaaS',
				],
				'available_content' => [ 'location_or_contact' ],
			]
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( $result['requires_clarification'] );
		$this->assertNull( $result['selected_family'] );
		$this->assertSame( 'lead-generation', $result['fallback']['slug'] );
		$this->assertContains( 'offer', $result['missing_content'] );
		$this->assertContains( 'cta_destination', $result['missing_content'] );
	}

	/**
	 * Explicit section requests consider structural cues owned by variants.
	 */
	public function test_selector_uses_variant_cues_for_family_section_request_scoring(): void {
		$result = LandingPagePatternAbilities::handle_select(
			[
				'available_content' => [
					'site_name',
					'offer',
					'cta_destination',
					'product',
					'booking_method',
					'location_or_contact',
					'portfolio_items',
					'inquiry_method',
					'mission',
					'donation_or_volunteer_path',
					'publication_or_topic',
					'subscription_method',
				],
				'section_requests'  => [ 'comparison' ],
			]
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertFalse( $result['requires_clarification'] );
		$this->assertSame( 'focused-product-conversion', $result['selected_family']['slug'] );
		$this->assertSame( 'comparison-led', $result['selected_variant']['slug'] );
		$this->assertSame( [ 'comparison' ], $result['score_breakdown']['section_requests']['matched_terms'] );
	}

	/**
	 * Catalog handlers leave WordPress posts, options, and memory untouched.
	 */
	public function test_handlers_do_not_fire_mutation_actions(): void {
		$before_save_post      = did_action( 'save_post' );
		$before_updated_option = did_action( 'updated_option' );
		$before_added_option   = did_action( 'added_option' );

		$list = LandingPagePatternAbilities::handle_list( [] );
		$selection = LandingPagePatternAbilities::handle_select(
			[
				'site_brief'        => [
					'siteName'    => 'Example Studio',
					'siteType'    => 'Portfolio',
					'primaryGoal' => 'Portfolio inquiry',
				],
				'available_content' => [
					'portfolio_items' => [ 'project-1' ],
					'inquiry_method'  => 'https://example.test/contact',
				],
			]
		);

		$this->assertIsArray( $list, is_wp_error( $list ) ? $list->get_error_message() : '' );
		$this->assertIsArray( $selection, is_wp_error( $selection ) ? $selection->get_error_message() : '' );
		$this->assertSame( $before_save_post, did_action( 'save_post' ) );
		$this->assertSame( $before_updated_option, did_action( 'updated_option' ) );
		$this->assertSame( $before_added_option, did_action( 'added_option' ) );
	}
}
