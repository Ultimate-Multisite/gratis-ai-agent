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
	 * Layout-cascade trigger keywords route to gutenberg-blocks.
	 *
	 * The "Block-theme layout cascade" rules in gutenberg-blocks.md are the
	 * fix for ~80% of "looks broken" page outputs. These trigger phrases
	 * MUST resolve to gutenberg-blocks so the cascade rules are loaded
	 * before the model emits any markup.
	 *
	 * @dataProvider provide_layout_cascade_phrases
	 */
	public function test_get_index_description_routes_layout_cascade_phrases_to_gutenberg_blocks( string $phrase ): void {
		$result = SkillAutoInjector::get_index_description( $phrase );

		$this->assertStringContainsString(
			'gutenberg-blocks',
			$result,
			"Phrase '{$phrase}' must route to gutenberg-blocks so the layout-cascade rules are loaded."
		);
	}

	/**
	 * Phrases that must trigger the gutenberg-blocks skill.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function provide_layout_cascade_phrases(): array {
		return [
			'hero keyword'             => [ 'Design a hero for the homepage.' ],
			'full-width hyphenated'    => [ 'Why is my full-width section only 700px wide?' ],
			'full width spaced'        => [ 'Make this banner full width across the viewport.' ],
			'full-bleed phrasing'      => [ 'I need a full-bleed image grid.' ],
			'landing page'             => [ 'Build a landing page that converts.' ],
			'section keyword'          => [ 'Add a testimonial section.' ],
		];
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

	// ─── WP agent skills trigger tests (Phase 5 / GH#1588) ───────────────

	/**
	 * Messages containing REST API keywords route to wp-rest-api.
	 *
	 * @dataProvider provide_wp_rest_api_phrases
	 */
	public function test_get_index_description_routes_rest_api_phrases( string $phrase ): void {
		$result = SkillAutoInjector::get_index_description( $phrase );

		$this->assertStringContainsString(
			'wp-rest-api',
			$result,
			"Phrase '{$phrase}' must route to wp-rest-api."
		);
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public function provide_wp_rest_api_phrases(): array {
		return [
			'register_rest_route'   => [ 'How do I use register_rest_route to add a custom endpoint?' ],
			'REST_Controller'       => [ 'Extend WP_REST_Controller for my custom API.' ],
			'wp-json path'          => [ 'My /wp-json/ route is returning 404.' ],
			'rest api_init'         => [ 'I hook into rest_api_init to register routes.' ],
			'rest + endpoint combo' => [ 'Create a REST endpoint that returns user data.' ],
		];
	}

	/**
	 * Messages containing block.json / apiVersion keywords route to wp-block-development.
	 *
	 * @dataProvider provide_wp_block_development_phrases
	 */
	public function test_get_index_description_routes_block_development_phrases( string $phrase ): void {
		$result = SkillAutoInjector::get_index_description( $phrase );

		$this->assertStringContainsString(
			'wp-block-development',
			$result,
			"Phrase '{$phrase}' must route to wp-block-development."
		);
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public function provide_wp_block_development_phrases(): array {
		return [
			'block.json'           => [ 'How do I update block.json to add a new attribute?' ],
			'register_block_type'  => [ 'Call register_block_type from my plugin.' ],
			'apiVersion'           => [ 'Should I use apiVersion 3 for my block?' ],
			'edit.js / save.js'    => [ 'My edit.js and save.js are out of sync.' ],
			'viewScriptModule'     => [ 'I need to use viewScriptModule for the Interactivity API.' ],
		];
	}

	/**
	 * Messages containing theme.json / templates / parts route to wp-block-themes.
	 *
	 * @dataProvider provide_wp_block_themes_phrases
	 */
	public function test_get_index_description_routes_block_themes_phrases( string $phrase ): void {
		$result = SkillAutoInjector::get_index_description( $phrase );

		$this->assertStringContainsString(
			'wp-block-themes',
			$result,
			"Phrase '{$phrase}' must route to wp-block-themes."
		);
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public function provide_wp_block_themes_phrases(): array {
		return [
			'theme.json'          => [ 'How do I add a color palette in theme.json?' ],
			'block theme phrase'  => [ 'I am building a block theme from scratch.' ],
			'templates/'          => [ 'Where should I put my templates/ directory?' ],
			'parts/'              => [ 'I added a header template part in parts/ but it does not appear.' ],
			'style variation'     => [ 'How do I add a dark style variation to my theme?' ],
		];
	}

	/**
	 * Messages containing plugin header / add_action keywords route to wp-plugin-development.
	 *
	 * @dataProvider provide_wp_plugin_development_phrases
	 */
	public function test_get_index_description_routes_plugin_development_phrases( string $phrase ): void {
		$result = SkillAutoInjector::get_index_description( $phrase );

		$this->assertStringContainsString(
			'wp-plugin-development',
			$result,
			"Phrase '{$phrase}' must route to wp-plugin-development."
		);
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public function provide_wp_plugin_development_phrases(): array {
		return [
			'Plugin Name header'           => [ 'I need to write the Plugin Name: header for my plugin file.' ],
			'register_activation_hook'     => [ 'How do I use register_activation_hook correctly?' ],
			'add_action call'              => [ 'Use add_action( \'init\', \'my_callback\' ) to register CPTs.' ],
			'add_filter call'              => [ 'I need to use add_filter( \'the_content\', \'my_filter\' ).' ],
		];
	}

	/**
	 * Messages containing WP-CLI / wp search-replace keywords route to wp-wpcli-and-ops.
	 *
	 * @dataProvider provide_wp_wpcli_phrases
	 */
	public function test_get_index_description_routes_wpcli_phrases( string $phrase ): void {
		$result = SkillAutoInjector::get_index_description( $phrase );

		$this->assertStringContainsString(
			'wp-wpcli-and-ops',
			$result,
			"Phrase '{$phrase}' must route to wp-wpcli-and-ops."
		);
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public function provide_wp_wpcli_phrases(): array {
		return [
			'wp-cli explicit'          => [ 'How do I install wp-cli on my server?' ],
			'wp search-replace'        => [ 'Run wp search-replace to update the domain after migration.' ],
			'wp db export'             => [ 'Use wp db export to take a backup before the deployment.' ],
			'wp cron event'            => [ 'List all scheduled tasks with wp cron event list.' ],
			'wp cache flush'           => [ 'Run wp cache flush to clear the object cache.' ],
			'wp-cli.yml config'        => [ 'Set path defaults in wp-cli.yml for this project.' ],
			'wp_cron constant'         => [ 'I disabled wp_cron in wp-config.php to use real cron.' ],
		];
	}
}
