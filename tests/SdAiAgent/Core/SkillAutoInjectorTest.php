<?php
/**
 * Test case for SkillAutoInjector.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\SkillAutoInjector;
use SdAiAgent\Models\Skill;
use SdAiAgent\Repositories\SkillUsageRepository;
use WP_UnitTestCase;

/**
 * Tests for SkillAutoInjector — inject_for_message() and get_index_description().
 */
class SkillAutoInjectorTest extends WP_UnitTestCase {

	/**
	 * Ensure built-in skills are seeded and enabled before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		Skill::seed_builtins();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'UPDATE ' . Skill::table_name() . ' SET enabled = 1 WHERE is_builtin = 1' );
	}

	// ─── inject_for_message() ─────────────────────────────────────────

	/**
	 * Empty message returns empty string.
	 */
	public function test_inject_empty_message_returns_empty(): void {
		$this->assertSame( '', SkillAutoInjector::inject_for_message( '' ) );
		$this->assertSame( '', SkillAutoInjector::inject_for_message( '   ' ) );
	}

	/**
	 * A message with no keyword matches returns empty string.
	 */
	public function test_inject_no_match_returns_empty(): void {
		$result = SkillAutoInjector::inject_for_message( 'Tell me a joke about penguins.' );
		$this->assertSame( '', $result );
	}

	/**
	 * A WooCommerce message injects the WooCommerce skill section.
	 */
	public function test_inject_woocommerce_message_injects_skill(): void {
		// Enable WooCommerce skill explicitly for this test.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Skill::table_name() . " SET enabled = 1 WHERE slug = %s", 'woocommerce' ) );

		$result = SkillAutoInjector::inject_for_message( 'How do I add a product to my WooCommerce store?' );

		$this->assertStringContainsString( 'Active Skill Guide', $result );
	}

	/**
	 * Auto-injection records telemetry when model and session context are provided.
	 */
	public function test_inject_records_skill_usage_telemetry(): void {
		$skill = Skill::get_by_slug( 'gutenberg-blocks' );
		$this->assertNotNull( $skill );

		$result = SkillAutoInjector::inject_for_message(
			'Create a landing page with Gutenberg blocks',
			'gpt-test-model',
			123
		);

		$this->assertStringContainsString( 'Active Skill Guide', $result );

		$rows = SkillUsageRepository::get_by_skill( (int) $skill->id, 1 );
		$this->assertNotEmpty( $rows );
		$this->assertSame( (int) $skill->id, $rows[0]->skill_id );
		$this->assertSame( 123, $rows[0]->session_id );
		$this->assertSame( 'auto', $rows[0]->trigger_type );
		$this->assertSame( 'unknown', $rows[0]->outcome );
		$this->assertSame( 'gpt-test-model', $rows[0]->model_id );
		$this->assertGreaterThan( 0, $rows[0]->injected_tokens );
	}

	/**
	 * A Gutenberg-related message injects the gutenberg-blocks skill.
	 */
	public function test_inject_gutenberg_message_injects_skill(): void {
		$result = SkillAutoInjector::inject_for_message( 'Create a landing page with Gutenberg blocks' );

		$this->assertStringContainsString( 'Active Skill Guide', $result );
	}

	/**
	 * MAX_INJECTED_SKILLS is 1 — two pattern matches should still inject
	 * at most one skill.
	 */
	public function test_inject_caps_at_one_skill(): void {
		// This message triggers both seo-optimization AND analytics-reporting.
		// With MAX_INJECTED_SKILLS = 1, only the first match should be injected.
		$result = SkillAutoInjector::inject_for_message(
			'Can you audit my SEO keywords and generate an analytics report for growth metrics?'
		);

		// Result should have exactly one "Active Skill Guide" section header.
		$count = substr_count( $result, '## Active Skill Guide' );
		$this->assertLessThanOrEqual( 1, $count, 'inject_for_message() must inject at most 1 skill (MAX_INJECTED_SKILLS=1).' );
	}

	// ─── get_index_description() ──────────────────────────────────────

	/**
	 * Empty message returns empty string.
	 */
	public function test_get_index_description_empty_message_returns_empty(): void {
		$this->assertSame( '', SkillAutoInjector::get_index_description( '' ) );
		$this->assertSame( '', SkillAutoInjector::get_index_description( '   ' ) );
	}

	/**
	 * A message with no keyword matches returns empty string.
	 */
	public function test_get_index_description_no_match_returns_empty(): void {
		$result = SkillAutoInjector::get_index_description( 'What is the capital of France?' );
		$this->assertSame( '', $result );
	}

	/**
	 * A matching message returns a non-empty hint mentioning the skill slug.
	 */
	public function test_get_index_description_returns_hint_for_match(): void {
		$result = SkillAutoInjector::get_index_description( 'How do I debug a fatal error on my site?' );

		$this->assertNotEmpty( $result );
		$this->assertStringContainsString( 'site-troubleshooting', $result );
		$this->assertStringContainsString( 'skill-load', $result );
	}

	/**
	 * Hint for a Gutenberg message contains the correct slug.
	 */
	public function test_get_index_description_contains_gutenberg_slug(): void {
		$result = SkillAutoInjector::get_index_description( 'Build a page layout with blocks and columns' );

		$this->assertStringContainsString( 'gutenberg-blocks', $result );
	}

	/**
	 * Kadence-specific phrasing routes to kadence-blocks before gutenberg-blocks.
	 *
	 * Order matters: the kadence pattern is declared earlier in TRIGGER_MAP so
	 * a message about kadence/rowlayout markup resolves to kadence-blocks
	 * rather than the generic gutenberg-blocks fallback.
	 */
	public function test_get_index_description_routes_kadence_to_kadence_blocks(): void {
		$result = SkillAutoInjector::get_index_description( 'Help me fix a kadence/rowlayout colLayout validation error.' );

		$this->assertStringContainsString( 'kadence-blocks', $result );
		$this->assertStringNotContainsString( 'gutenberg-blocks', $result );
	}

	/**
	 * "kadence header builder" routes to kadence-theme.
	 */
	public function test_get_index_description_routes_header_builder_to_kadence_theme(): void {
		$result = SkillAutoInjector::get_index_description( 'How do I add a CTA in the Kadence header builder?' );

		$this->assertStringContainsString( 'kadence', $result );
	}

	/**
	 * Customizer / child theme phrasing routes to classic-themes.
	 */
	public function test_get_index_description_routes_classic_theme_phrases(): void {
		$result = SkillAutoInjector::get_index_description( 'I need to register a widget area in my child theme functions.php.' );

		$this->assertStringContainsString( 'classic-themes', $result );
	}

	/**
	 * get_index_description() hint is significantly shorter than the full injection.
	 *
	 * The whole point of the strong-model path is to avoid injecting 1 500+
	 * tokens. Verify the hint is much shorter than inject_for_message().
	 */
	public function test_get_index_description_shorter_than_full_injection(): void {
		$message = 'Create a landing page with Gutenberg blocks';

		$full_injection = SkillAutoInjector::inject_for_message( $message );
		$hint           = SkillAutoInjector::get_index_description( $message );

		if ( '' === $full_injection ) {
			$this->markTestSkipped( 'Full injection returned empty — built-in skill content missing.' );
		}

		$this->assertGreaterThan( strlen( $hint ), strlen( $full_injection ), 'Full injection must be longer than the index hint.' );
		$this->assertLessThan( 200, strlen( $hint ), 'Index hint should be under 200 characters (got ' . strlen( $hint ) . ').' );
	}
}
