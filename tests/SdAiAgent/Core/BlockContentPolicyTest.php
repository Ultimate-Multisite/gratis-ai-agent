<?php
/**
 * Test case for BlockContentPolicy and related classes.
 *
 * Covers the acceptance criteria from GH#1585:
 *
 * - Layout HTML inside core/html → isValid: false, generic core-blocks message.
 * - Single <svg> inside core/html → isValid: true (carve-out).
 * - Single <script> inside core/html → isValid: true (carve-out).
 * - <form> inside core/html → Jetpack-specific message, not generic.
 * - PluginRecommendations::build_system_prompt_section() returns a section
 *   with the ## Plugin Recommendations heading when recommendations are
 *   registered, and empty string when none are.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1585
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\BlockContentPolicy;
use SdAiAgent\Core\BlockValidator;
use SdAiAgent\Core\PluginRecommendation;
use SdAiAgent\Core\PluginRecommendations;
use WP_UnitTestCase;

/**
 * Tests for BlockContentPolicy, PluginRecommendations, and BlockValidator
 * integration (Phase 2, GH#1585; tier system, GH#1712).
 */
class BlockContentPolicyTest extends WP_UnitTestCase {

	/**
	 * Reset the PluginRecommendations cache and delete the test options between
	 * tests so filter overrides and option mutations do not bleed across.
	 */
	public function tear_down(): void {
		parent::tear_down();
		PluginRecommendations::reset();
		delete_option( BlockContentPolicy::OPTION_PREFERENCES );
		delete_option( BlockContentPolicy::OPTION_REPLACEMENTS );
	}

	// ------------------------------------------------------------------
	// BlockContentPolicy::get_html_block_policy_issues()
	// ------------------------------------------------------------------

	/**
	 * Layout HTML (div with headings) should be flagged as a policy violation
	 * with the generic core-blocks message.
	 *
	 * AC: `<!-- wp:html --><div><h2>Hero</h2><p>Text</p></div><!-- /wp:html -->` →
	 *     issues[0] contains "use editable core blocks".
	 */
	public function test_layout_html_returns_generic_policy_message(): void {
		$content = '<div><h2>Hero</h2><p>Text</p></div>';
		$issues  = BlockContentPolicy::get_html_block_policy_issues( $content );

		$this->assertNotEmpty( $issues, 'Layout HTML inside core/html should produce at least one policy issue.' );
		$this->assertStringContainsString(
			'use editable core blocks',
			$issues[0],
			'Generic policy message should mention editable core blocks.'
		);
	}

	/**
	 * A single <svg> tag should pass the carve-out and produce no issues.
	 *
	 * AC: `<!-- wp:html --><svg>…</svg><!-- /wp:html -->` → isValid: true.
	 */
	public function test_single_svg_passes_policy(): void {
		$content = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2L2 22h20L12 2z"/></svg>';
		$issues  = BlockContentPolicy::get_html_block_policy_issues( $content );

		$this->assertEmpty( $issues, 'A single inline SVG should be allowed inside core/html.' );
	}

	/**
	 * A single <script> tag should pass the carve-out and produce no issues.
	 *
	 * AC: `<!-- wp:html --><script>analytics()</script><!-- /wp:html -->` → isValid: true.
	 */
	public function test_single_script_passes_policy(): void {
		$content = '<script>analytics();</script>';
		$issues  = BlockContentPolicy::get_html_block_policy_issues( $content );

		$this->assertEmpty( $issues, 'A single script block should be allowed inside core/html.' );
	}

	/**
	 * A <script> with attributes should also pass.
	 */
	public function test_single_script_with_attributes_passes_policy(): void {
		$content = '<script type="text/javascript" async src="https://example.com/track.js"></script>';
		$issues  = BlockContentPolicy::get_html_block_policy_issues( $content );

		$this->assertEmpty( $issues, 'A single script with attributes should be allowed inside core/html.' );
	}

	/**
	 * Interaction markup (<marquee>) should pass the carve-out.
	 */
	public function test_marquee_passes_policy(): void {
		$content = '<marquee direction="left">Scrolling text</marquee>';
		$issues  = BlockContentPolicy::get_html_block_policy_issues( $content );

		$this->assertEmpty( $issues, '<marquee> has no block equivalent and should be allowed inside core/html.' );
	}

	/**
	 * A <form> element should return the Jetpack-specific policy message.
	 *
	 * AC: `<form>` inside core/html returns Jetpack-specific message, not generic.
	 */
	public function test_form_element_returns_jetpack_specific_message(): void {
		$content = '<form><input name="q" type="text"><button type="submit">Search</button></form>';
		$issues  = BlockContentPolicy::get_html_block_policy_issues( $content );

		$this->assertNotEmpty( $issues, '<form> inside core/html should produce at least one policy issue.' );
		$this->assertStringContainsString(
			'Jetpack',
			$issues[0],
			'The policy message for a form element should reference Jetpack Forms.'
		);
		$this->assertStringNotContainsString(
			'core/group',
			$issues[0],
			'The Jetpack-specific message should NOT fall through to the generic core-blocks message.'
		);
	}

	/**
	 * An <input> element outside a <form> should also return the Jetpack message
	 * because the form-element pattern covers <input> directly.
	 */
	public function test_standalone_input_returns_jetpack_message(): void {
		$content = '<div><input type="email" name="email"><button>Subscribe</button></div>';
		$issues  = BlockContentPolicy::get_html_block_policy_issues( $content );

		$this->assertNotEmpty( $issues );
		$this->assertStringContainsString( 'Jetpack', $issues[0] );
	}

	/**
	 * Empty content should produce no issues.
	 */
	public function test_empty_content_produces_no_issues(): void {
		$issues = BlockContentPolicy::get_html_block_policy_issues( '' );
		$this->assertEmpty( $issues );
	}

	/**
	 * Content consisting only of whitespace should produce no issues.
	 */
	public function test_whitespace_only_content_produces_no_issues(): void {
		$issues = BlockContentPolicy::get_html_block_policy_issues( '   ' );
		$this->assertEmpty( $issues );
	}

	/**
	 * Block comment delimiters should be stripped before policy evaluation.
	 */
	public function test_block_comment_delimiters_are_stripped(): void {
		// SVG wrapped in block comments should still pass.
		$content = "<!-- wp:html -->\n<svg><circle cx=\"12\" cy=\"12\" r=\"10\"/></svg>\n<!-- /wp:html -->";
		$issues  = BlockContentPolicy::get_html_block_policy_issues( $content );
		$this->assertEmpty( $issues, 'Block comment delimiters should be stripped before SVG carve-out check.' );
	}

	// ------------------------------------------------------------------
	// BlockContentPolicy::apply()
	// ------------------------------------------------------------------

	/**
	 * apply() should force isValid: false on a core/html result with layout HTML.
	 */
	public function test_apply_forces_invalid_on_layout_html_result(): void {
		$result = [
			'blockName'       => 'core/html',
			'isValid'         => true,
			'issues'          => [],
			'originalContent' => '<div><h2>Hero</h2></div>',
			'expectedContent' => '<div><h2>Hero</h2></div>',
		];

		$applied = BlockContentPolicy::apply( $result );

		$this->assertFalse( $applied['isValid'], 'apply() should force isValid: false for layout HTML.' );
		$this->assertNotEmpty( $applied['issues'], 'apply() should append a policy issue.' );
	}

	/**
	 * apply() should leave a core/html result unchanged when content is an SVG.
	 */
	public function test_apply_leaves_svg_result_unchanged(): void {
		$result = [
			'blockName'       => 'core/html',
			'isValid'         => true,
			'issues'          => [],
			'originalContent' => '<svg><path d="M0 0"/></svg>',
			'expectedContent' => '<svg><path d="M0 0"/></svg>',
		];

		$applied = BlockContentPolicy::apply( $result );

		$this->assertTrue( $applied['isValid'] );
		$this->assertEmpty( $applied['issues'] );
	}

	/**
	 * apply() should not affect non-core/html blocks.
	 */
	public function test_apply_ignores_non_html_blocks(): void {
		$result = [
			'blockName'       => 'core/paragraph',
			'isValid'         => true,
			'issues'          => [],
			'originalContent' => '<p>Hello</p>',
			'expectedContent' => '<p>Hello</p>',
		];

		$applied = BlockContentPolicy::apply( $result );

		$this->assertSame( $result, $applied, 'apply() should return non-html blocks unchanged.' );
	}

	/**
	 * apply() should append (not replace) issues when the result already has
	 * existing issues from live validation.
	 */
	public function test_apply_appends_to_existing_issues(): void {
		$result = [
			'blockName'       => 'core/html',
			'isValid'         => false,
			'issues'          => [ 'Pre-existing issue from live validator' ],
			'originalContent' => '<div><p>Layout content</p></div>',
			'expectedContent' => '',
		];

		$applied = BlockContentPolicy::apply( $result );

		$this->assertCount( 2, $applied['issues'], 'apply() should append the policy issue to existing issues.' );
		$this->assertSame( 'Pre-existing issue from live validator', $applied['issues'][0] );
	}

	// ------------------------------------------------------------------
	// BlockContentPolicy — tier / score system (GH#1712)
	// ------------------------------------------------------------------

	/**
	 * score_to_tier() boundary: score 80 → preferred.
	 */
	public function test_score_to_tier_80_is_preferred(): void {
		$this->assertSame( 'preferred', BlockContentPolicy::score_to_tier( 80 ) );
	}

	/**
	 * score_to_tier() boundary: score 79 → acceptable.
	 */
	public function test_score_to_tier_79_is_acceptable(): void {
		$this->assertSame( 'acceptable', BlockContentPolicy::score_to_tier( 79 ) );
	}

	/**
	 * score_to_tier() boundary: score 50 → acceptable.
	 */
	public function test_score_to_tier_50_is_acceptable(): void {
		$this->assertSame( 'acceptable', BlockContentPolicy::score_to_tier( 50 ) );
	}

	/**
	 * score_to_tier() boundary: score 49 → avoid.
	 */
	public function test_score_to_tier_49_is_avoid(): void {
		$this->assertSame( 'avoid', BlockContentPolicy::score_to_tier( 49 ) );
	}

	/**
	 * score_to_tier() boundary: score 10 → avoid.
	 */
	public function test_score_to_tier_10_is_avoid(): void {
		$this->assertSame( 'avoid', BlockContentPolicy::score_to_tier( 10 ) );
	}

	/**
	 * score_to_tier() boundary: score 9 → legacy.
	 */
	public function test_score_to_tier_9_is_legacy(): void {
		$this->assertSame( 'legacy', BlockContentPolicy::score_to_tier( 9 ) );
	}

	/**
	 * score_to_tier() boundary: score 0 → legacy.
	 */
	public function test_score_to_tier_0_is_legacy(): void {
		$this->assertSame( 'legacy', BlockContentPolicy::score_to_tier( 0 ) );
	}

	/**
	 * get_namespace_score() resolves exact full block name first.
	 *
	 * core/freeform has a default score of 5 (< namespace default of 90).
	 */
	public function test_get_namespace_score_exact_match_wins(): void {
		$score = BlockContentPolicy::get_namespace_score( 'core/freeform' );
		$this->assertSame( 5, $score, 'core/freeform exact override should be 5, not the core namespace score of 90.' );
	}

	/**
	 * get_namespace_score() falls back to namespace prefix when no exact match.
	 *
	 * core/paragraph has no exact override; core namespace score is 90.
	 */
	public function test_get_namespace_score_namespace_fallback(): void {
		$score = BlockContentPolicy::get_namespace_score( 'core/paragraph' );
		$this->assertSame( 90, $score, 'core/paragraph should inherit the core namespace score of 90.' );
	}

	/**
	 * get_namespace_score() returns 50 (acceptable) for an unknown namespace.
	 */
	public function test_get_namespace_score_unknown_namespace_returns_50(): void {
		$score = BlockContentPolicy::get_namespace_score( 'unknown-vendor/my-block' );
		$this->assertSame( 50, $score, 'Unknown namespaces should default to 50 (acceptable).' );
	}

	/**
	 * get_namespace_score() reads stored option values.
	 */
	public function test_get_namespace_score_reads_stored_option(): void {
		update_option( BlockContentPolicy::OPTION_PREFERENCES, array( 'custom-ns' => 15 ) );
		$score = BlockContentPolicy::get_namespace_score( 'custom-ns/my-block' );
		$this->assertSame( 15, $score, 'Stored option score should be returned for the matching namespace.' );
	}

	/**
	 * get_preferences() applies the sd_ai_agent_block_preferences filter.
	 */
	public function test_get_preferences_filter_overrides_score(): void {
		add_filter(
			BlockContentPolicy::OPTION_PREFERENCES,
			static function ( array $prefs ): array {
				$prefs['filter-override'] = 99;
				return $prefs;
			}
		);

		$prefs = BlockContentPolicy::get_preferences();
		$this->assertSame( 99, $prefs['filter-override'], 'Filter should be able to inject a preference score.' );

		remove_all_filters( BlockContentPolicy::OPTION_PREFERENCES );
	}

	/**
	 * get_replacement() returns the mapped block name for a known legacy block.
	 */
	public function test_get_replacement_returns_mapped_block(): void {
		$replacement = BlockContentPolicy::get_replacement( 'core/freeform' );
		$this->assertSame( 'core/group', $replacement );
	}

	/**
	 * get_replacement() returns null when no mapping is defined.
	 */
	public function test_get_replacement_returns_null_for_unmapped_block(): void {
		$replacement = BlockContentPolicy::get_replacement( 'some/unmapped-block' );
		$this->assertNull( $replacement );
	}

	/**
	 * get_replacements() applies the sd_ai_agent_block_replacements filter.
	 */
	public function test_get_replacements_filter_override(): void {
		add_filter(
			BlockContentPolicy::OPTION_REPLACEMENTS,
			static function ( array $map ): array {
				$map['custom/legacy'] = 'custom/modern';
				return $map;
			}
		);

		$map = BlockContentPolicy::get_replacements();
		$this->assertArrayHasKey( 'custom/legacy', $map );
		$this->assertSame( 'custom/modern', $map['custom/legacy'] );

		remove_all_filters( BlockContentPolicy::OPTION_REPLACEMENTS );
	}

	/**
	 * check_insert() returns null for a preferred block.
	 *
	 * AC3: Insert of a namespace scored ≥ 50 succeeds silently.
	 */
	public function test_check_insert_preferred_returns_null(): void {
		$result = BlockContentPolicy::check_insert( 'core/paragraph' );
		$this->assertNull( $result, 'check_insert() should return null for preferred-tier blocks.' );
	}

	/**
	 * check_insert() returns null for an acceptable block.
	 *
	 * AC3: insert ≥ 50 succeeds silently.
	 */
	public function test_check_insert_acceptable_returns_null(): void {
		// Set unknown-vendor to 60 (acceptable).
		add_filter(
			BlockContentPolicy::OPTION_PREFERENCES,
			static function ( array $prefs ): array {
				$prefs['acceptable-ns'] = 60;
				return $prefs;
			}
		);

		$result = BlockContentPolicy::check_insert( 'acceptable-ns/block' );
		$this->assertNull( $result );

		remove_all_filters( BlockContentPolicy::OPTION_PREFERENCES );
	}

	/**
	 * check_insert() returns an array with warnings for an avoid-tier block.
	 *
	 * AC2: Insert 10–49 succeeds but carries warnings.
	 */
	public function test_check_insert_avoid_returns_warnings(): void {
		// core/html has default score 30 (avoid).
		$result = BlockContentPolicy::check_insert( 'core/html' );

		$this->assertIsArray( $result, 'check_insert() should return an array for avoid-tier blocks.' );
		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertNotEmpty( $result['warnings'] );
		$this->assertSame( 'avoid_block', $result['warnings'][0]['code'] );
		$this->assertSame( 'core/html', $result['warnings'][0]['block_name'] );
		$this->assertSame( 'avoid', $result['warnings'][0]['tier'] );
	}

	/**
	 * check_insert() returns WP_Error for a legacy-tier block on insert.
	 *
	 * AC1: Insert < 10 returns legacy_block error with suggested_replacement.
	 */
	public function test_check_insert_legacy_returns_wp_error(): void {
		$result = BlockContentPolicy::check_insert( 'core/freeform' );

		$this->assertInstanceOf( \WP_Error::class, $result, 'check_insert() should return WP_Error for legacy-tier blocks.' );
		$this->assertSame( 'legacy_block', $result->get_error_code() );

		$data = $result->get_error_data( 'legacy_block' );
		$this->assertIsArray( $data );
		$this->assertSame( 'core/freeform', $data['block_name'] );
		$this->assertSame( 'legacy', $data['tier'] );
		$this->assertSame( 'core/group', $data['suggested_replacement'] );
	}

	/**
	 * check_insert() returns WP_Error with suggested_replacement null for unmapped legacy block.
	 *
	 * AC1: suggested_replacement is null when no mapping is defined.
	 */
	public function test_check_insert_legacy_unmapped_suggested_replacement_is_null(): void {
		// Create a legacy-scored block with no replacement.
		add_filter(
			BlockContentPolicy::OPTION_PREFERENCES,
			static function ( array $prefs ): array {
				$prefs['custom/unmapped-legacy'] = 5;
				return $prefs;
			}
		);

		$result = BlockContentPolicy::check_insert( 'custom/unmapped-legacy' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$data = $result->get_error_data( 'legacy_block' );
		$this->assertNull( $data['suggested_replacement'] );

		remove_all_filters( BlockContentPolicy::OPTION_PREFERENCES );
	}

	/**
	 * check_insert() allows a legacy block through when $is_update = true.
	 *
	 * AC4: update-attrs on existing legacy block succeeds (insert-only enforcement).
	 */
	public function test_check_insert_legacy_allowed_on_update(): void {
		$result = BlockContentPolicy::check_insert( 'core/freeform', true );

		// Updates are always allowed; expect null (silent allow).
		$this->assertNull( $result, 'Legacy blocks should be allowed through when is_update = true.' );
	}

	// ------------------------------------------------------------------
	// BlockValidator integration
	// ------------------------------------------------------------------

	/**
	 * validate() on layout HTML inside core/html should return isValid: false
	 * in the results array.
	 *
	 * AC: `<!-- wp:html --><div>layout</div><!-- /wp:html -->` →
	 *     isValid: false with generic core-blocks message.
	 */
	public function test_block_validator_flags_layout_html(): void {
		$content   = "<!-- wp:html -->\n<div><h2>Hero</h2><p>Text</p></div>\n<!-- /wp:html -->";
		$validator = new BlockValidator();
		$report    = $validator->validate( $content );

		$this->assertGreaterThanOrEqual( 1, $report['totalBlocks'] );
		$this->assertGreaterThanOrEqual( 1, $report['invalidBlocks'] );

		$html_result = $this->find_result_by_block_name( $report['results'], 'core/html' );
		$this->assertNotNull( $html_result, 'core/html block should appear in results.' );
		$this->assertFalse( $html_result['isValid'], 'Layout HTML inside core/html should be isValid: false.' );
		$this->assertNotEmpty( $html_result['issues'] );
		$this->assertStringContainsString( 'use editable core blocks', $html_result['issues'][0] );
	}

	/**
	 * validate() on a single SVG inside core/html should keep isValid: true.
	 *
	 * AC: `<!-- wp:html --><svg>…</svg><!-- /wp:html -->` → isValid: true.
	 */
	public function test_block_validator_allows_svg_in_html_block(): void {
		$content   = "<!-- wp:html -->\n<svg xmlns=\"http://www.w3.org/2000/svg\"><circle cx=\"12\" cy=\"12\" r=\"10\"/></svg>\n<!-- /wp:html -->";
		$validator = new BlockValidator();
		$report    = $validator->validate( $content );

		$html_result = $this->find_result_by_block_name( $report['results'], 'core/html' );
		$this->assertNotNull( $html_result );
		$this->assertTrue( $html_result['isValid'], 'A single SVG inside core/html should be isValid: true.' );
	}

	/**
	 * validate() on a single <script> inside core/html should keep isValid: true.
	 *
	 * AC: `<!-- wp:html --><script>analytics()</script><!-- /wp:html -->` → isValid: true.
	 */
	public function test_block_validator_allows_script_in_html_block(): void {
		$content   = "<!-- wp:html -->\n<script>analytics();</script>\n<!-- /wp:html -->";
		$validator = new BlockValidator();
		$report    = $validator->validate( $content );

		$html_result = $this->find_result_by_block_name( $report['results'], 'core/html' );
		$this->assertNotNull( $html_result );
		$this->assertTrue( $html_result['isValid'], 'A single script inside core/html should be isValid: true.' );
	}

	/**
	 * validate() on a <form> inside core/html should return the Jetpack message.
	 *
	 * AC: `<form>` inside core/html returns Jetpack-specific message, not generic.
	 */
	public function test_block_validator_returns_jetpack_message_for_form(): void {
		$content   = "<!-- wp:html -->\n<form><input name=\"q\"></form>\n<!-- /wp:html -->";
		$validator = new BlockValidator();
		$report    = $validator->validate( $content );

		$html_result = $this->find_result_by_block_name( $report['results'], 'core/html' );
		$this->assertNotNull( $html_result );
		$this->assertFalse( $html_result['isValid'] );
		$this->assertStringContainsString( 'Jetpack', $html_result['issues'][0] );
		$this->assertStringNotContainsString( 'core/group', $html_result['issues'][0] );
	}

	// ------------------------------------------------------------------
	// PluginRecommendations
	// ------------------------------------------------------------------

	/**
	 * build_system_prompt_section() should return a string containing
	 * "## Plugin Recommendations" when at least one recommendation is registered.
	 *
	 * AC: System prompt contains ## Plugin Recommendations when ≥1 recommendation registered.
	 */
	public function test_build_system_prompt_section_contains_heading(): void {
		// Default registry has Jetpack Forms.
		$section = PluginRecommendations::build_system_prompt_section();

		$this->assertStringContainsString(
			'## Plugin Recommendations',
			$section,
			'Section should start with the ## Plugin Recommendations heading.'
		);
		$this->assertStringContainsString(
			'Jetpack',
			$section,
			'Section should contain the Jetpack Forms guidance.'
		);
	}

	/**
	 * build_system_prompt_section() should return an empty string when the
	 * registry is empty.
	 *
	 * AC: Section is absent when no recommendations are registered.
	 */
	public function test_build_system_prompt_section_empty_when_no_recommendations(): void {
		// Override the filter to return an empty registry.
		add_filter( 'sd_ai_agent_plugin_recommendations', '__return_empty_array' );
		PluginRecommendations::reset();

		$section = PluginRecommendations::build_system_prompt_section();

		remove_filter( 'sd_ai_agent_plugin_recommendations', '__return_empty_array' );

		$this->assertSame( '', $section, 'Section should be empty when no recommendations are registered.' );
	}

	/**
	 * get_html_policy_message() should return null when no pattern matches.
	 */
	public function test_get_html_policy_message_returns_null_for_unmatched_content(): void {
		$message = PluginRecommendations::get_html_policy_message( '<div><p>Simple layout</p></div>' );

		$this->assertNull( $message, 'get_html_policy_message() should return null when no pattern matches.' );
	}

	/**
	 * Third-party code can register additional recommendations via the filter.
	 */
	public function test_filter_allows_third_party_recommendations(): void {
		add_filter(
			'sd_ai_agent_plugin_recommendations',
			static function ( array $recs ): array {
				$recs[] = new PluginRecommendation(
					name: 'WooCommerce',
					plugin_slug: 'woocommerce',
					blocks: [ 'woocommerce/product-price' ],
					guidance: "## E-commerce\n\nUse WooCommerce blocks for product displays.",
					html_patterns: [ '/<(table class="woocommerce)\b/i' ],
					html_policy_message: 'Use WooCommerce blocks instead.',
				);
				return $recs;
			}
		);

		PluginRecommendations::reset();

		$all = PluginRecommendations::get_all();

		$this->assertCount( 2, $all, 'Filter should allow adding a second recommendation.' );
		$this->assertSame( 'WooCommerce', $all[1]->name );

		remove_all_filters( 'sd_ai_agent_plugin_recommendations' );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Find the first result entry with a given block name.
	 *
	 * @param list<array<string, mixed>> $results
	 * @param string                     $block_name
	 * @return array<string, mixed>|null
	 */
	private function find_result_by_block_name( array $results, string $block_name ): ?array {
		foreach ( $results as $result ) {
			if ( ( $result['blockName'] ?? '' ) === $block_name ) {
				return $result;
			}
		}
		return null;
	}
}
