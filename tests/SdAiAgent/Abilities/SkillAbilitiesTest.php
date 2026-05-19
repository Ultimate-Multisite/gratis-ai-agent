<?php
/**
 * Test case for SkillAbilities class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\SkillAbilities;
use SdAiAgent\Models\Skill;
use SdAiAgent\Repositories\SkillUsageRepository;
use WP_UnitTestCase;

/**
 * Test SkillAbilities handler methods.
 */
class SkillAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Test handle_skill_list returns message when no enabled skills exist.
	 *
	 * Built-in skills cannot be deleted via Skill::delete() (returns 'builtin').
	 * We disable all skills via a direct UPDATE to simulate an empty enabled list,
	 * then restore them after the assertion. TRUNCATE causes an implicit commit in
	 * MariaDB and bypasses WP's transaction-based test isolation.
	 */
	public function test_handle_skill_list_empty() {
		global $wpdb;

		// Disable all skills directly — avoids TRUNCATE's implicit commit.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'UPDATE ' . Skill::table_name() . ' SET enabled = 0' );

		$result = SkillAbilities::handle_skill_list();

		// Re-enable built-in skills so subsequent tests are not affected.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'UPDATE ' . Skill::table_name() . ' SET enabled = 1 WHERE is_builtin = 1' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertStringContainsString( 'No skills', $result['message'] );
	}

	/**
	 * Test handle_skill_list returns skills when they exist.
	 */
	public function test_handle_skill_list_with_skills() {
		// Create a test skill.
		Skill::create( [
			'slug'        => 'test-skill',
			'name'        => 'Test Skill',
			'description' => 'A test skill description',
			'content'     => 'Test skill content',
			'enabled'     => true,
		] );

		$result = SkillAbilities::handle_skill_list();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'skills', $result );
		$this->assertIsArray( $result['skills'] );
		$this->assertNotEmpty( $result['skills'] );

		// Each skill should have slug, name, description.
		$skill = $result['skills'][0];
		$this->assertArrayHasKey( 'slug', $skill );
		$this->assertArrayHasKey( 'name', $skill );
		$this->assertArrayHasKey( 'description', $skill );
	}

	/**
	 * Test handle_skill_list only returns enabled skills.
	 */
	public function test_handle_skill_list_only_enabled() {
		// Create enabled and disabled skills.
		Skill::create( [ 'slug' => 'enabled-skill', 'name' => 'Enabled Skill', 'description' => 'Enabled', 'content' => 'Content', 'enabled' => true ] );
		Skill::create( [ 'slug' => 'disabled-skill', 'name' => 'Disabled Skill', 'description' => 'Disabled', 'content' => 'Content', 'enabled' => false ] );

		$result = SkillAbilities::handle_skill_list();

		if ( isset( $result['skills'] ) ) {
			$slugs = array_column( $result['skills'], 'slug' );
			$this->assertContains( 'enabled-skill', $slugs );
			$this->assertNotContains( 'disabled-skill', $slugs );
		}
	}

	/**
	 * Test handle_skill_load with empty slug returns WP_Error.
	 */
	public function test_handle_skill_load_empty_slug() {
		$result = SkillAbilities::handle_skill_load( [
			'slug' => '',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'required', $result->get_error_message() );
	}

	/**
	 * Test handle_skill_load with missing slug returns WP_Error.
	 */
	public function test_handle_skill_load_missing_slug() {
		$result = SkillAbilities::handle_skill_load( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test handle_skill_load with non-existent slug returns WP_Error.
	 */
	public function test_handle_skill_load_not_found() {
		$result = SkillAbilities::handle_skill_load( [
			'slug' => 'nonexistent-skill-xyz-12345',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'not found', $result->get_error_message() );
	}

	/**
	 * Test handle_skill_load with disabled skill returns WP_Error.
	 */
	public function test_handle_skill_load_disabled_skill() {
		Skill::create( [ 'slug' => 'disabled-load-test', 'name' => 'Disabled Load Test', 'description' => 'Desc', 'content' => 'Content', 'enabled' => false ] );

		$result = SkillAbilities::handle_skill_load( [
			'slug' => 'disabled-load-test',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'disabled', $result->get_error_message() );
	}

	/**
	 * Test handle_skill_load with enabled skill returns content.
	 */
	public function test_handle_skill_load_enabled_skill() {
		Skill::create( [ 'slug' => 'enabled-load-test', 'name' => 'Enabled Load Test', 'description' => 'Description', 'content' => 'Skill content here', 'enabled' => true ] );

		$result = SkillAbilities::handle_skill_load( [
			'slug' => 'enabled-load-test',
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'slug', $result );
		$this->assertArrayHasKey( 'content', $result );
		$this->assertSame( 'enabled-load-test', $result['slug'] );
		$this->assertSame( 'Skill content here', $result['content'] );
	}

	/**
	 * Loading a skill through the ability records tool-call telemetry.
	 */
	public function test_handle_skill_load_records_tool_call_usage() {
		$skill_id = Skill::create( [ 'slug' => 'telemetry-load-test', 'name' => 'Telemetry Load Test', 'description' => 'Description', 'content' => 'Skill content for telemetry', 'enabled' => true ] );
		$this->assertIsInt( $skill_id );

		$result = SkillAbilities::handle_skill_load( [
			'slug' => 'telemetry-load-test',
		] );

		$this->assertIsArray( $result );

		$rows = SkillUsageRepository::get_by_skill( $skill_id, 1 );
		$this->assertNotEmpty( $rows );
		$this->assertSame( $skill_id, $rows[0]->skill_id );
		$this->assertSame( 'tool_call', $rows[0]->trigger_type );
		$this->assertSame( 'unknown', $rows[0]->outcome );
		$this->assertGreaterThan( 0, $rows[0]->injected_tokens );
	}

	// ─── Built-in Skill Loading ──────────────────────────────────────────────

	/**
	 * Every built-in skill can be loaded successfully via handle_skill_load().
	 *
	 * Seeds built-ins, enables all, then verifies each one loads with
	 * the expected slug, a non-empty name, and non-empty content.
	 */
	public function test_handle_skill_load_all_builtins() {
		global $wpdb;

		// Ensure built-in skills are seeded.
		Skill::seed_builtins();

		// Enable all built-in skills for this test.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'UPDATE ' . Skill::table_name() . ' SET enabled = 1 WHERE is_builtin = 1' );

		$definitions = Skill::get_builtin_definitions();

		foreach ( array_keys( $definitions ) as $slug ) {
			$result = SkillAbilities::handle_skill_load( [ 'slug' => $slug ] );

			$this->assertIsArray(
				$result,
				"Built-in skill '$slug' should load successfully, got WP_Error: "
				. ( is_wp_error( $result ) ? $result->get_error_message() : '' )
			);
			$this->assertSame( $slug, $result['slug'] );
			$this->assertNotEmpty( $result['name'], "Built-in skill '$slug' has empty name." );
			$this->assertNotEmpty( $result['content'], "Built-in skill '$slug' has empty content." );
		}
	}

	/**
	 * Loaded built-in skill content matches the definition.
	 *
	 * Resets a built-in skill and verifies the loaded content matches
	 * what get_builtin_definitions() returns.
	 */
	public function test_handle_skill_load_builtin_content_matches_definition() {
		global $wpdb;

		Skill::seed_builtins();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'UPDATE ' . Skill::table_name() . ' SET enabled = 1 WHERE is_builtin = 1' );

		// Pick the first built-in and reset it to guarantee pristine content.
		$definitions = Skill::get_builtin_definitions();
		$slug        = array_key_first( $definitions );
		$skill       = Skill::get_by_slug( $slug );

		if ( $skill ) {
			Skill::reset_builtin( (int) $skill->id );
		}

		$result = SkillAbilities::handle_skill_load( [ 'slug' => $slug ] );
		$this->assertIsArray( $result );
		$this->assertSame( $definitions[ $slug ]['content'], $result['content'] );
	}

	// ─── Skill List Field Completeness ───────────────────────────────────────

	/**
	 * Each skill in the list has all required fields.
	 */
	public function test_handle_skill_list_fields_complete() {
		Skill::create( [
			'slug'        => 'field-check-skill',
			'name'        => 'Field Check Skill',
			'description' => 'Tests field completeness',
			'content'     => 'Content here',
			'enabled'     => true,
		] );

		$result = SkillAbilities::handle_skill_list();
		$this->assertArrayHasKey( 'skills', $result );

		foreach ( $result['skills'] as $skill ) {
			$this->assertArrayHasKey( 'slug', $skill, 'Skill missing slug field.' );
			$this->assertArrayHasKey( 'name', $skill, 'Skill missing name field.' );
			$this->assertArrayHasKey( 'description', $skill, 'Skill missing description field.' );
			$this->assertNotEmpty( $skill['slug'], 'Skill has empty slug.' );
			$this->assertNotEmpty( $skill['name'], 'Skill has empty name.' );
		}
	}

	/**
	 * Skill list count matches the number of enabled skills.
	 */
	public function test_handle_skill_list_count_matches_enabled() {
		global $wpdb;

		// Disable all, create exactly 3 enabled skills.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'UPDATE ' . Skill::table_name() . ' SET enabled = 0' );

		Skill::create( [ 'slug' => 'count-a', 'name' => 'Count A', 'content' => 'C', 'enabled' => true ] );
		Skill::create( [ 'slug' => 'count-b', 'name' => 'Count B', 'content' => 'C', 'enabled' => true ] );
		Skill::create( [ 'slug' => 'count-c', 'name' => 'Count C', 'content' => 'C', 'enabled' => true ] );
		Skill::create( [ 'slug' => 'count-d', 'name' => 'Count D', 'content' => 'C', 'enabled' => false ] );

		$result = SkillAbilities::handle_skill_list();
		$this->assertArrayHasKey( 'skills', $result );
		$this->assertCount( 3, $result['skills'] );

		// Re-enable built-in skills.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'UPDATE ' . Skill::table_name() . ' SET enabled = 1 WHERE is_builtin = 1' );
	}

	// ─── Prompt-to-Skill Relevance ───────────────────────────────────────────

	/**
	 * Skill descriptions are distinct enough that keyword overlap identifies
	 * the correct skill for representative prompts.
	 *
	 * This is a heuristic test that simulates what the AI model sees in the
	 * system prompt. It verifies that each test prompt has the highest keyword
	 * overlap with the expected skill description, not a competing one.
	 *
	 * @dataProvider provide_prompt_skill_matches
	 *
	 * @param string $prompt   A representative user prompt.
	 * @param string $expected The skill slug that should rank highest.
	 */
	public function test_prompt_matches_expected_skill( string $prompt, string $expected ) {
		$definitions = Skill::get_builtin_definitions();
		$this->assertArrayHasKey( $expected, $definitions );

		// Score each skill's index entry (slug: description) against the prompt,
		// matching the format produced by Skill::get_index_for_prompt().
		$scores = [];
		foreach ( $definitions as $slug => $def ) {
			$scores[ $slug ] = self::keyword_overlap_score(
				$prompt,
				$slug . ': ' . $def['description']
			);
		}

		arsort( $scores );

		// The expected skill must have a positive score.
		$this->assertGreaterThan(
			0,
			$scores[ $expected ],
			"Skill '$expected' should have positive keyword overlap with: \"$prompt\""
		);

		// The expected skill should be the top match, or tied for top if multiple
		// skills share the same highest score.
		$top_score = reset( $scores );
		$top_slugs = array_keys( array_filter( $scores, fn( $s ) => $s === $top_score ) );
		$this->assertContains(
			$expected,
			$top_slugs,
			"Expected '$expected' to rank first (or tied) for \"$prompt\", "
			. "but top slug(s) were: " . implode( ', ', $top_slugs )
			. ". Scores: $expected=" . round( $scores[ $expected ], 3 )
		);
	}

	/**
	 * Data provider: prompts mapped to expected skill slugs.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provide_prompt_skill_matches(): array {
		return [
			'wp-admin: update core'       => [ 'How do I update WordPress and manage settings?', 'wordpress-admin' ],
			'wp-admin: user management'   => [ 'List users and administration options', 'wordpress-admin' ],
			'content: manage posts'        => [ 'Managing posts pages and media taxonomies', 'content-management' ],
			'content: manage categories'   => [ 'Manage categories and taxonomies for pages', 'content-management' ],
			'seo: audit meta tags'         => [ 'SEO auditing and on-page meta optimization', 'seo-optimization' ],
			'seo: technical checks'        => [ 'Technical SEO checks and meta tag optimization', 'seo-optimization' ],
			'blocks: convert markdown'     => [ 'Convert markdown to Gutenberg blocks', 'gutenberg-blocks' ],
			'blocks: build layout'         => [ 'Building layouts with Gutenberg blocks', 'gutenberg-blocks' ],
			'troubleshoot: errors'         => [ 'Debugging errors and performance diagnosis', 'site-troubleshooting' ],
			'troubleshoot: site health'    => [ 'Site health diagnosis and debugging errors', 'site-troubleshooting' ],
			'marketing: content strategy'  => [ 'Content strategy and editorial workflows', 'content-marketing' ],
			'marketing: content audit'     => [ 'Content audits and publishing analysis', 'content-marketing' ],
			'analytics: metrics report'    => [ 'Site growth metrics and publishing analytics', 'analytics-reporting' ],
			'analytics: performance'       => [ 'Content performance reports and analytics', 'analytics-reporting' ],
			'woo: products and orders'     => [ 'WooCommerce store products and orders management', 'woocommerce' ],
			'multisite: network admin'     => [ 'WordPress Multisite network administration', 'multisite-management' ],
			'competitive: analyze sites'   => [ 'Analyzing competitor sites and tech stack', 'competitive-analysis' ],
			'fse: block theme templates'   => [ 'Block theme templates and template parts', 'block-themes' ],
			'classic: customizer setup'    => [ 'Classic theme customizer and child theme functions', 'classic-themes' ],
			'kadence: rowlayout markup'    => [ 'Kadence rowlayout colLayout and kbVersion', 'kadence-blocks' ],
			'kadence: header builder'      => [ 'Kadence theme header builder and footer builder', 'kadence-theme' ],
		];
	}

	// ─── Auto-Enable Rules (Regression Tests for Issue #1500) ──────────────────

	/**
	 * handle_skill_load respects auto-enable rules for theme-aware skills.
	 *
	 * Regression test for issue #1500: block-themes and classic-themes should
	 * be loadable via the ability even when disabled in the DB, if the current
	 * theme matches their auto-enable predicate.
	 *
	 * This test seeds built-ins, disables block-themes and classic-themes,
	 * then verifies that handle_skill_load returns content (not skill_disabled)
	 * when the active theme matches the skill's auto-enable rule.
	 */
	public function test_handle_skill_load_respects_theme_auto_enable(): void {
		global $wpdb;

		// Ensure built-in skills are seeded.
		Skill::seed_builtins();

		// Force block-themes and classic-themes to disabled so we test auto-enable.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Skill::table_name() . " SET enabled = 0 WHERE slug IN (%s, %s)", 'block-themes', 'classic-themes' ) );

		$is_block = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

		// On a block theme, block-themes should load successfully.
		if ( $is_block ) {
			$result = SkillAbilities::handle_skill_load( [ 'slug' => 'block-themes' ] );
			$this->assertIsArray(
				$result,
				'block-themes should load on block themes even when disabled, got WP_Error: '
				. ( is_wp_error( $result ) ? $result->get_error_message() : '' )
			);
			$this->assertArrayHasKey( 'content', $result );
			$this->assertNotEmpty( $result['content'] );

			// classic-themes should NOT load on block themes.
			$result = SkillAbilities::handle_skill_load( [ 'slug' => 'classic-themes' ] );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertStringContainsString( 'disabled', $result->get_error_message() );
		} else {
			// On a classic theme, classic-themes should load successfully.
			$result = SkillAbilities::handle_skill_load( [ 'slug' => 'classic-themes' ] );
			$this->assertIsArray(
				$result,
				'classic-themes should load on classic themes even when disabled, got WP_Error: '
				. ( is_wp_error( $result ) ? $result->get_error_message() : '' )
			);
			$this->assertArrayHasKey( 'content', $result );
			$this->assertNotEmpty( $result['content'] );

			// block-themes should NOT load on classic themes.
			$result = SkillAbilities::handle_skill_load( [ 'slug' => 'block-themes' ] );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertStringContainsString( 'disabled', $result->get_error_message() );
		}
	}

	/**
	 * handle_skill_load still rejects truly disabled skills.
	 *
	 * Regression test for issue #1500: a skill that is disabled AND does not
	 * match any auto-enable rule should still return skill_disabled.
	 */
	public function test_handle_skill_load_rejects_truly_disabled_skill(): void {
		// Create a skill with no auto-enable rule and disabled=false.
		Skill::create( [
			'slug'        => 'truly-disabled-test',
			'name'        => 'Truly Disabled Test',
			'description' => 'A skill with no auto-enable rule',
			'content'     => 'This skill is disabled',
			'enabled'     => false,
		] );

		$result = SkillAbilities::handle_skill_load( [ 'slug' => 'truly-disabled-test' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'disabled', $result->get_error_message() );
	}

	/**
	 * Compute a keyword overlap score between a prompt and a description.
	 *
	 * @param string $prompt      User prompt text.
	 * @param string $description Skill name + description text.
	 * @return float Ratio of prompt keywords found in description (0.0–1.0).
	 */
	private static function keyword_overlap_score( string $prompt, string $description ): float {
		$stopwords = [
			'the', 'a', 'an', 'is', 'are', 'my', 'i', 'to', 'do', 'how',
			'in', 'of', 'and', 'for', 'on', 'with', 'this', 'that', 'from',
			'all', 'or', 'it', 'be', 'me', 'can', 'what', 'show', 'new',
		];

		$prompt_words = array_unique( array_map( 'strtolower', preg_split( '/\W+/', $prompt ) ) );
		$desc_words   = array_unique( array_map( 'strtolower', preg_split( '/\W+/', $description ) ) );

		$prompt_words = array_values( array_diff( $prompt_words, $stopwords, [ '' ] ) );
		$desc_words   = array_values( array_diff( $desc_words, $stopwords, [ '' ] ) );

		$overlap = array_intersect( $prompt_words, $desc_words );

		if ( count( $prompt_words ) === 0 ) {
			return 0.0;
		}

		return count( $overlap ) / count( $prompt_words );
	}
}
