<?php

declare(strict_types=1);
/**
 * Tests for the theme-builder built-in agent seeding.
 *
 * Covers: seed_defaults() creates the agent on fresh install; seed_defaults()
 * is idempotent; reset_defaults() restores canonical fields after edits;
 * tier_1_tools contains the required theme-builder abilities.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Models;

use SdAiAgent\Models\Agent;
use WP_UnitTestCase;

/**
 * Tests for the theme-builder built-in agent.
 *
 * @since 1.6.0
 */
class AgentThemeBuilderTest extends WP_UnitTestCase {

	/**
	 * Slug constant under test.
	 */
	private const SLUG = 'theme-builder';

	/**
	 * Remove the theme-builder agent row from the database (helper).
	 */
	private function delete_theme_builder_agent(): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup; caching not applicable.
		$wpdb->delete( Agent::table_name(), [ 'slug' => self::SLUG ], [ '%s' ] );
	}

	/**
	 * Set up: ensure no stale theme-builder row from a previous run.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->delete_theme_builder_agent();
	}

	/**
	 * Tear down: remove any theme-builder agent rows created during the test
	 * so they don't bleed into subsequent tests.
	 */
	public function tear_down(): void {
		$this->delete_theme_builder_agent();
		parent::tear_down();
	}

	// ─── THEME_BUILDER_AGENT_SLUG constant ───────────────────────────────────

	/**
	 * THEME_BUILDER_AGENT_SLUG constant has the expected value.
	 */
	public function test_theme_builder_agent_slug_constant(): void {
		$this->assertSame( self::SLUG, Agent::THEME_BUILDER_AGENT_SLUG );
	}

	// ─── seed_defaults() ─────────────────────────────────────────────────────

	/**
	 * seed_defaults() creates the theme-builder agent when it does not exist.
	 */
	public function test_seed_defaults_creates_theme_builder_agent(): void {
		// Ensure no pre-existing row.
		$this->assertNull( Agent::get_by_slug( self::SLUG ) );

		Agent::seed_defaults();

		$agent = Agent::get_by_slug( self::SLUG );
		$this->assertNotNull( $agent, 'theme-builder agent must exist after seed_defaults()' );
		$this->assertSame( self::SLUG, $agent->slug );
	}

	/**
	 * seed_defaults() is idempotent — running it twice does not create a duplicate.
	 */
	public function test_seed_defaults_is_idempotent(): void {
		Agent::seed_defaults();
		Agent::seed_defaults();

		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion; caching not applicable.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE slug = %s',
				Agent::table_name(),
				self::SLUG
			)
		);

		$this->assertSame( 1, $count, 'seed_defaults() must not create duplicate theme-builder rows' );
	}

	// ─── reset_defaults() ────────────────────────────────────────────────────

	/**
	 * reset_defaults() restores the canonical system_prompt after it has been edited.
	 */
	public function test_reset_defaults_restores_system_prompt(): void {
		Agent::seed_defaults();

		$agent = Agent::get_by_slug( self::SLUG );
		$this->assertNotNull( $agent );

		// Corrupt the system_prompt.
		Agent::update( $agent->id, [ 'system_prompt' => 'CORRUPTED' ] );
		$corrupted = Agent::get_by_slug( self::SLUG );
		$this->assertNotNull( $corrupted );
		$this->assertSame( 'CORRUPTED', $corrupted->system_prompt );

		Agent::reset_defaults();

		$restored = Agent::get_by_slug( self::SLUG );
		$this->assertNotNull( $restored );
		$this->assertNotSame(
			'CORRUPTED',
			$restored->system_prompt,
			'reset_defaults() must restore the canonical system_prompt'
		);
		$this->assertStringContainsString(
			'site-specification',
			$restored->system_prompt,
			'Restored system_prompt must reference the site-specification skill'
		);
	}

	/**
	 * reset_defaults() restores the canonical greeting after it has been edited.
	 */
	public function test_reset_defaults_restores_greeting(): void {
		Agent::seed_defaults();

		$agent = Agent::get_by_slug( self::SLUG );
		$this->assertNotNull( $agent );

		Agent::update( $agent->id, [ 'greeting' => 'CORRUPTED GREETING' ] );

		Agent::reset_defaults();

		$restored = Agent::get_by_slug( self::SLUG );
		$this->assertNotNull( $restored );
		$this->assertNotSame(
			'CORRUPTED GREETING',
			$restored->greeting,
			'reset_defaults() must restore the canonical greeting'
		);
	}

	/**
	 * reset_defaults() restores the canonical tier_1_tools after they have been edited.
	 */
	public function test_reset_defaults_restores_tier_1_tools(): void {
		Agent::seed_defaults();

		$agent = Agent::get_by_slug( self::SLUG );
		$this->assertNotNull( $agent );

		Agent::update( $agent->id, [ 'tier_1_tools' => [] ] );

		Agent::reset_defaults();

		$restored = Agent::get_by_slug( self::SLUG );
		$this->assertNotNull( $restored );

		$tools = $restored->tier_1_tools;
		$this->assertNotEmpty( $tools, 'reset_defaults() must restore tier_1_tools' );
	}

	// ─── tier_1_tools content ────────────────────────────────────────────────

	/**
	 * The seeded theme-builder agent includes all four core theme abilities
	 * plus the required support tools in tier_1_tools.
	 */
	public function test_tier_1_tools_contains_required_theme_abilities(): void {
		Agent::seed_defaults();

		$agent = Agent::get_by_slug( self::SLUG );
		$this->assertNotNull( $agent );

		$tools = $agent->tier_1_tools;
		$this->assertIsArray( $tools, 'tier_1_tools must be an array' );

		$required = [
			'sd-ai-agent/scaffold-block-theme',
			'sd-ai-agent/activate-theme',
			'sd-ai-agent/file-write',
			'sd-ai-agent/validate-block-content',
			'sd-ai-agent/memory-save',
			'sd-ai-agent/skill-load',
			'sd-ai-agent/get-theme-json',
			'sd-ai-agent/update-global-styles',
		];

		foreach ( $required as $ability ) {
			$this->assertContains(
				$ability,
				$tools,
				sprintf( 'tier_1_tools must contain %s', $ability )
			);
		}
	}

	// ─── builtin definition fields ───────────────────────────────────────────

	/**
	 * The theme-builder definition in get_builtin_definitions() contains all
	 * 8 required fields.
	 */
	public function test_builtin_definition_has_all_required_fields(): void {
		$reflection = new \ReflectionClass( Agent::class );
		$method     = $reflection->getMethod( 'get_builtin_definitions' );
		$method->setAccessible( true );
		/** @var list<array<string, mixed>> $definitions */
		$definitions = $method->invoke( null );

		$theme_builder_def = null;
		foreach ( $definitions as $def ) {
			if ( isset( $def['slug'] ) && $def['slug'] === self::SLUG ) {
				$theme_builder_def = $def;
				break;
			}
		}

		$this->assertNotNull( $theme_builder_def, 'get_builtin_definitions() must include a theme-builder entry' );

		$required_fields = [ 'slug', 'name', 'description', 'system_prompt', 'greeting', 'tier_1_tools', 'suggestions', 'avatar_icon' ];
		foreach ( $required_fields as $field ) {
			$this->assertArrayHasKey( $field, $theme_builder_def, sprintf( 'theme-builder definition must have field: %s', $field ) );
			$this->assertNotEmpty( $theme_builder_def[ $field ], sprintf( 'theme-builder definition field must not be empty: %s', $field ) );
		}
	}

	/**
	 * The system_prompt references the skills and contract keywords required
	 * by the issue acceptance criteria.
	 */
	public function test_system_prompt_references_required_skills_and_contract(): void {
		Agent::seed_defaults();

		$agent = Agent::get_by_slug( self::SLUG );
		$this->assertNotNull( $agent );

		$prompt = $agent->system_prompt;

		$this->assertStringContainsString(
			'site-specification',
			$prompt,
			'system_prompt must reference the site-specification skill'
		);
		$this->assertStringContainsString(
			'block-themes',
			$prompt,
			'system_prompt must reference the block-themes skill'
		);
		// The 4-phase contract: all four phase headings must appear.
		$this->assertStringContainsString( 'Phase 1', $prompt, 'system_prompt must define Phase 1 (Interview)' );
		$this->assertStringContainsString( 'Phase 2', $prompt, 'system_prompt must define Phase 2 (Designs)' );
		$this->assertStringContainsString( 'Phase 3', $prompt, 'system_prompt must define Phase 3 (Choose)' );
		$this->assertStringContainsString( 'Phase 4', $prompt, 'system_prompt must define Phase 4 (Build)' );
		// No-stock-images rule.
		$this->assertStringContainsString(
			'stock image',
			strtolower( $prompt ),
			'system_prompt must include the no-stock-images rule'
		);
	}
}
