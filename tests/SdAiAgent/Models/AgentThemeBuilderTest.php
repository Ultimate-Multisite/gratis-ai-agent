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

	// ─── Real-content principle (issue #1526) ────────────────────────────────

	/**
	 * The system_prompt encodes the "real content or no content" principle
	 * required by issue #1526.
	 */
	public function test_system_prompt_encodes_real_content_principle(): void {
		Agent::seed_defaults();

		$agent = Agent::get_by_slug( self::SLUG );
		$this->assertNotNull( $agent );

		$prompt_lower = strtolower( $agent->system_prompt );

		// The top-level principle heading must be present.
		$this->assertStringContainsString(
			'real content or no content',
			$prompt_lower,
			'system_prompt must encode the "real content or no content" principle'
		);
		// The stub-prevention rule must be explicit.
		$this->assertStringContainsString(
			'never publish a stub',
			$prompt_lower,
			'system_prompt must include the "never publish a stub" rule'
		);
	}

	/**
	 * The system_prompt explicitly bans placeholder strings that must never
	 * appear in generated pages (issue #1526 acceptance criterion).
	 */
	public function test_system_prompt_bans_placeholder_strings(): void {
		Agent::seed_defaults();

		$agent = Agent::get_by_slug( self::SLUG );
		$this->assertNotNull( $agent );

		$prompt = $agent->system_prompt;

		// The Rules section must name the banned strings so the model
		// knows exactly what NOT to output.
		$banned_phrases = [
			'Lorem ipsum',
			'Replace this',
			'Edit this',
			'Add your',
		];

		foreach ( $banned_phrases as $phrase ) {
			$this->assertStringContainsString(
				$phrase,
				$prompt,
				sprintf(
					'system_prompt must explicitly ban the placeholder string "%s"',
					$phrase
				)
			);
		}
	}

	/**
	 * The system_prompt includes the page-creation prerequisite self-check
	 * required by issue #1526 section 2.
	 */
	public function test_system_prompt_encodes_page_creation_prerequisite_check(): void {
		Agent::seed_defaults();

		$agent = Agent::get_by_slug( self::SLUG );
		$this->assertNotNull( $agent );

		$prompt_lower = strtolower( $agent->system_prompt );

		// The prerequisite check section must be present.
		$this->assertStringContainsString(
			'prerequisite check',
			$prompt_lower,
			'system_prompt must include a page-creation prerequisite check section'
		);
		// The check must be linked to create-post calls.
		$this->assertStringContainsString(
			'create-post',
			$agent->system_prompt,
			'page-creation prerequisite check must reference create-post'
		);
		// No draft stubs.
		$this->assertStringContainsString(
			'draft stubs',
			$prompt_lower,
			'system_prompt must forbid draft stubs'
		);
	}

	/**
	 * The system_prompt is vertical-aware: it includes question packs for
	 * the major verticals named in issue #1526 (café/restaurant, retail/shop,
	 * service business, portfolio, blog, event venue).
	 */
	public function test_system_prompt_is_vertical_aware(): void {
		Agent::seed_defaults();

		$agent = Agent::get_by_slug( self::SLUG );
		$this->assertNotNull( $agent );

		$prompt_lower = strtolower( $agent->system_prompt );

		// Each key vertical type must appear in the interview question pack.
		$verticals = [
			'café',
			'restaurant',
			'retail',
			'service business',
			'portfolio',
			'blog',
			'event venue',
		];

		foreach ( $verticals as $vertical ) {
			$this->assertStringContainsString(
				$vertical,
				$prompt_lower,
				sprintf(
					'system_prompt must include interview questions for the "%s" vertical',
					$vertical
				)
			);
		}

		// The interview expansion label must be present.
		$this->assertStringContainsString(
			'vertical-aware',
			$prompt_lower,
			'system_prompt must describe itself as vertical-aware in Phase 1'
		);
	}

	// ─── Image generation tools (issue #1529) ────────────────────────────────

	/**
	 * The theme-builder's tier_1_tools includes both image tools required for
	 * brand-specific imagery and generic photography (issue #1529).
	 */
	public function test_tier_1_tools_contains_image_tools(): void {
		Agent::seed_defaults();

		$agent = Agent::get_by_slug( self::SLUG );
		$this->assertNotNull( $agent );

		$tools = $agent->tier_1_tools;
		$this->assertIsArray( $tools );

		$this->assertContains(
			'sd-ai-agent/generate-image',
			$tools,
			'tier_1_tools must contain sd-ai-agent/generate-image for brand-specific imagery'
		);

		$this->assertContains(
			'sd-ai-agent/stock-image',
			$tools,
			'tier_1_tools must contain sd-ai-agent/stock-image for generic photography'
		);
	}

	/**
	 * The system_prompt teaches the model when to use stock-image vs generate-image.
	 *
	 * Generic photography uses stock-image; brand-specific / illustration /
	 * pattern backgrounds use generate-image (issue #1529 acceptance criterion).
	 */
	public function test_system_prompt_contains_image_selection_guidance(): void {
		Agent::seed_defaults();

		$agent = Agent::get_by_slug( self::SLUG );
		$this->assertNotNull( $agent );

		$prompt = $agent->system_prompt;

		$this->assertStringContainsString(
			'sd-ai-agent/stock-image',
			$prompt,
			'system_prompt must reference sd-ai-agent/stock-image'
		);

		$this->assertStringContainsString(
			'sd-ai-agent/generate-image',
			$prompt,
			'system_prompt must reference sd-ai-agent/generate-image'
		);

		// The prompt must explain when to choose each tool.
		$prompt_lower = strtolower( $prompt );

		$this->assertStringContainsString(
			'stock',
			$prompt_lower,
			'system_prompt must mention stock imagery guidance'
		);

		$this->assertStringContainsString(
			'brand',
			$prompt_lower,
			'system_prompt must mention brand-specific imagery as a generate-image use-case'
		);
	}

	/**
	 * The system_prompt includes the "no external image URLs in theme files" rule,
	 * which is enforced by the image-selection guidance (issue #1529).
	 */
	public function test_system_prompt_bans_external_image_urls_in_theme_files(): void {
		Agent::seed_defaults();

		$agent = Agent::get_by_slug( self::SLUG );
		$this->assertNotNull( $agent );

		$prompt_lower = strtolower( $agent->system_prompt );

		$this->assertStringContainsString(
			'attachment_id',
			$agent->system_prompt,
			'system_prompt must instruct use of attachment_id (not external URLs) in theme files'
		);
	}
}
