<?php

declare(strict_types=1);
/**
 * Tests for the retired theme-builder built-in agent and the unified Setup Assistant.
 *
 * Covers: seed_defaults() no longer creates the theme-builder agent;
 * seed_defaults()/reset_defaults() remove the retired built-in row on upgrade;
 * the unified onboarding agent keeps the former Theme Builder capabilities.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Models;

use SdAiAgent\Models\Agent;
use WP_UnitTestCase;

/**
 * Tests for the retired theme-builder built-in agent.
 *
 * @since 1.6.0
 */
class AgentThemeBuilderTest extends WP_UnitTestCase {

	/**
	 * Retired theme-builder slug constant under test.
	 */
	private const RETIRED_SLUG = 'theme-builder';

	/**
	 * Remove theme-builder rows that individual tests may create.
	 */
	public function tear_down(): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup; caching not applicable.
		$wpdb->delete( Agent::table_name(), [ 'slug' => self::RETIRED_SLUG ], [ '%s' ] );
		parent::tear_down();
	}

	/**
	 * THEME_BUILDER_AGENT_SLUG constant is retained for upgrade cleanup only.
	 */
	public function test_theme_builder_agent_slug_constant_is_retained_for_legacy_cleanup(): void {
		$this->assertSame( self::RETIRED_SLUG, Agent::THEME_BUILDER_AGENT_SLUG );
	}

	/**
	 * seed_defaults() no longer creates the retired theme-builder agent.
	 */
	public function test_seed_defaults_does_not_create_theme_builder_agent(): void {
		$this->delete_theme_builder_rows();

		Agent::seed_defaults();

		$this->assertNull(
			Agent::get_by_slug( self::RETIRED_SLUG ),
			'theme-builder must not be seeded as a visible built-in agent'
		);
		$this->assertNotNull(
			Agent::get_by_slug( Agent::ONBOARDING_AGENT_SLUG ),
			'onboarding Setup Assistant must remain the single setup/onboarding agent'
		);
	}

	/**
	 * seed_defaults() removes an existing retired built-in theme-builder row on
	 * upgrade so the agent picker no longer shows two setup agents.
	 */
	public function test_seed_defaults_removes_existing_builtin_theme_builder_agent(): void {
		$this->delete_theme_builder_rows();

		$legacy_id = Agent::create(
			[
				'slug'          => self::RETIRED_SLUG,
				'name'          => 'Theme Builder',
				'description'   => 'Legacy built-in row',
				'system_prompt' => 'Legacy prompt',
				'is_builtin'    => true,
				'enabled'       => true,
			]
		);

		$this->assertIsInt( $legacy_id );
		Agent::seed_defaults();

		$this->assertNull(
			Agent::get_by_slug( self::RETIRED_SLUG ),
			'upgrade seeding must remove the retired built-in theme-builder row'
		);
	}

	/**
	 * reset_defaults() also removes the retired built-in row so the Settings →
	 * Agents reset action cannot bring Theme Builder back.
	 */
	public function test_reset_defaults_removes_existing_builtin_theme_builder_agent(): void {
		$this->delete_theme_builder_rows();

		$legacy_id = Agent::create(
			[
				'slug'          => self::RETIRED_SLUG,
				'name'          => 'Theme Builder',
				'description'   => 'Legacy built-in row',
				'system_prompt' => 'Legacy prompt',
				'is_builtin'    => true,
				'enabled'       => true,
			]
		);

		$this->assertIsInt( $legacy_id );
		Agent::reset_defaults();

		$this->assertNull(
			Agent::get_by_slug( self::RETIRED_SLUG ),
			'reset_defaults() must not recreate or retain the retired theme-builder built-in'
		);
	}

	/**
	 * reset_defaults() preserves the unified Setup Assistant prompt contract.
	 */
	public function test_reset_defaults_restores_unified_setup_assistant_prompt(): void {
		Agent::reset_defaults();

		$agent = Agent::get_by_slug( Agent::ONBOARDING_AGENT_SLUG );
		$this->assertNotNull( $agent );

		Agent::update( $agent->id, [ 'system_prompt' => 'CORRUPTED' ] );
		Agent::reset_defaults();

		$restored = Agent::get_by_slug( Agent::ONBOARDING_AGENT_SLUG );
		$this->assertNotNull( $restored );
		$this->assertNotSame( 'CORRUPTED', $restored->system_prompt );
		$this->assertStringContainsString( 'site-specification', $restored->system_prompt );
		$this->assertStringContainsString( 'wp-block-themes', $restored->system_prompt );
		$this->assertStringContainsString( 'Phase 1', $restored->system_prompt );
		$this->assertStringContainsString( 'Phase 2', $restored->system_prompt );
		$this->assertStringContainsString( 'Phase 3', $restored->system_prompt );
	}

	/**
	 * The unified Setup Assistant includes the former Theme Builder tier-1 tools.
	 */
	public function test_setup_assistant_tier_1_tools_contains_theme_build_abilities(): void {
		Agent::reset_defaults();

		$agent = Agent::get_by_slug( Agent::ONBOARDING_AGENT_SLUG );
		$this->assertNotNull( $agent );
		$this->assertIsArray( $agent->tier_1_tools );

		$required = [
			'sd-ai-agent/scaffold-block-theme',
			'sd-ai-agent/activate-theme',
			'sd-ai-agent/file-write',
			'sd-ai-agent/validate-block-content',
			'sd-ai-agent/get-theme-json',
			'sd-ai-agent/update-global-styles',
			'sd-ai-agent/render-design-previews',
			'sd-ai-agent/generate-menu-page',
			'sd-ai-agent/validate-palette-contrast',
			'sd-ai-agent/compile-design-tokens',
			'sd-ai-agent/site-scrape',
			'sd-ai-agent/stock-image',
			'sd-ai-agent/generate-image',
			'sd-ai-agent/generate-logo-svg',
		];

		foreach ( $required as $ability ) {
			$this->assertContains(
				$ability,
				$agent->tier_1_tools,
				sprintf( 'Setup Assistant tier_1_tools must contain %s', $ability )
			);
		}
	}

	/**
	 * The unified Setup Assistant prompt keeps the real-content principle.
	 */
	public function test_setup_assistant_system_prompt_encodes_real_content_principle(): void {
		Agent::reset_defaults();

		$agent = Agent::get_by_slug( Agent::ONBOARDING_AGENT_SLUG );
		$this->assertNotNull( $agent );

		$prompt_lower = strtolower( $agent->system_prompt );
		$this->assertStringContainsString( 'real content or no content', $prompt_lower );
		$this->assertStringContainsString( 'never publish a stub', $prompt_lower );
		$this->assertStringContainsString( 'prerequisite check', $prompt_lower );
	}

	/**
	 * The unified Setup Assistant prompt keeps the placeholder bans.
	 */
	public function test_setup_assistant_system_prompt_bans_placeholder_strings(): void {
		Agent::reset_defaults();

		$agent = Agent::get_by_slug( Agent::ONBOARDING_AGENT_SLUG );
		$this->assertNotNull( $agent );

		foreach ( [ 'Lorem ipsum', 'Replace this', 'Edit this', 'Add your' ] as $phrase ) {
			$this->assertStringContainsString( $phrase, $agent->system_prompt );
		}
	}

	/**
	 * The unified Setup Assistant prompt is vertical-aware.
	 */
	public function test_setup_assistant_system_prompt_is_vertical_aware(): void {
		Agent::reset_defaults();

		$agent = Agent::get_by_slug( Agent::ONBOARDING_AGENT_SLUG );
		$this->assertNotNull( $agent );

		$prompt_lower = strtolower( $agent->system_prompt );
		foreach ( [ 'café', 'restaurant', 'retail', 'service business', 'portfolio', 'blog', 'event venue' ] as $vertical ) {
			$this->assertStringContainsString( $vertical, $prompt_lower );
		}
		$this->assertStringContainsString( 'vertical-aware', $prompt_lower );
	}

	/**
	 * The Setup Assistant must revalidate palette repairs before scaffolding.
	 */
	public function test_setup_assistant_revalidates_palette_before_scaffolding(): void {
		Agent::reset_defaults();

		$agent = Agent::get_by_slug( Agent::ONBOARDING_AGENT_SLUG );
		$this->assertNotNull( $agent );

		$validation_position = strpos( $agent->system_prompt, 'validate-palette-contrast` again' );
		$scaffold_position   = strpos( $agent->system_prompt, 'scaffold-block-theme` only after' );

		$this->assertNotFalse( $validation_position );
		$this->assertNotFalse( $scaffold_position );
		$this->assertLessThan( $scaffold_position, $validation_position );
		$this->assertStringContainsString( '`passed` field is exactly `true`', $agent->system_prompt );
	}

	/**
	 * Delete theme-builder rows regardless of built-in status for test setup.
	 */
	private function delete_theme_builder_rows(): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup; caching not applicable.
		$wpdb->delete( Agent::table_name(), [ 'slug' => self::RETIRED_SLUG ], [ '%s' ] );
	}
}
