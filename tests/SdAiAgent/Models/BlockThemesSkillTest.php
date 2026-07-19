<?php

declare(strict_types=1);
/**
 * Tests for the bundled wp-block-themes skill.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Models;

use SdAiAgent\Models\Skill;
use WP_UnitTestCase;

/**
 * Verifies the Phase 2 wp-block-themes expansion remains available to agents.
 */
class BlockThemesSkillTest extends WP_UnitTestCase {

	/**
	 * The bundled skill is registered as a built-in block theme skill.
	 */
	public function test_block_themes_skill_is_registered_as_builtin(): void {
		$definitions = Skill::get_builtin_definitions();

		$this->assertArrayHasKey( 'wp-block-themes', $definitions );
		$this->assertSame( 'WP Block Themes', $definitions['wp-block-themes']['name'] );
		$this->assertFalse( $definitions['wp-block-themes']['enabled'] );
		$this->assertStringContainsString( 'theme.json', $definitions['wp-block-themes']['content'] );
	}

	/**
	 * The Phase 2 expansion keeps the documented size envelope from the parent task.
	 */
	public function test_block_themes_skill_keeps_phase_two_size_envelope(): void {
		$content    = Skill::get_builtin_definitions()['wp-block-themes']['content'];
		$line_count = substr_count( $content, "\n" ) + 1;

		$this->assertGreaterThanOrEqual( 350, $line_count );
		$this->assertLessThanOrEqual( 580, $line_count );
	}

	/**
	 * The skill includes the required theme.json presets and template composition guidance.
	 */
	public function test_block_themes_skill_includes_theme_json_and_template_part_guidance(): void {
		$content = Skill::get_builtin_definitions()['wp-block-themes']['content'];

		$required_patterns = [
			'Always declare `$schema` and `version: 3`',
			'five semantic roles `foreground`, `background`, `surface`, `accent`, and `on-accent`',
			'`primary` and `secondary` may be added as optional aesthetic groupings',
			'`on-accent` on `accent` for controls',
			'6-step scale slugs `20`–`70`',
			'`parts/header.html` — Site header',
			'`parts/footer.html` — Site footer',
			'wp:template-part',
			'Full-bleed wrappers, constrained content',
		];

		foreach ( $required_patterns as $pattern ) {
			$this->assertStringContainsString( $pattern, $content );
		}
	}

	/**
	 * The skill includes the animation, reduced-motion, and editor-visibility safeguards.
	 */
	public function test_block_themes_skill_includes_motion_and_editor_visibility_safeguards(): void {
		$content = Skill::get_builtin_definitions()['wp-block-themes']['content'];

		$required_patterns = [
			'## Animation & Motion',
			'className: "animate-on-scroll"',
			'IntersectionObserver',
			'prefers-reduced-motion',
			'Editor Visibility',
			'editor-styles-wrapper',
			'every custom class that sets `opacity: 0`',
		];

		foreach ( $required_patterns as $pattern ) {
			$this->assertStringContainsString( $pattern, $content );
		}
	}

	/**
	 * The skill keeps the generation safety rules that prevent invalid editor output.
	 */
	public function test_block_themes_skill_keeps_generation_safety_rules(): void {
		$content = Skill::get_builtin_definitions()['wp-block-themes']['content'];

		$this->assertStringContainsString( '**No HTML blocks.**', $content );
		$this->assertStringContainsString( '**No decorative HTML comments.**', $content );
		$this->assertStringContainsString( '**No stock image URLs.**', $content );
		$this->assertStringContainsString( '**Validate before write.**', $content );
		$this->assertStringContainsString( 'sd-ai-agent/validate-block-content', $content );
	}

	/**
	 * Generated token, pattern, and variation producers share one durable release contract.
	 */
	public function test_block_themes_skill_documents_generated_artifact_governance(): void {
		$content = Skill::get_builtin_definitions()['wp-block-themes']['content'];

		$required_patterns = [
			'### Generated design artifact governance',
			'sd-ai-agent/token_set/...',
			'`maturity` (`stable`, `candidate`, `experimental`, or `deprecated`)',
			'`sd-ai-agent/resolve-design-artifacts`',
			'`sd-ai-agent/apply-design-artifact-release`',
			'`sd-ai-agent/rollback-design-artifact-release`',
			'must not be silently imported, rewritten, or',
		];

		foreach ( $required_patterns as $pattern ) {
			$this->assertStringContainsString( $pattern, $content );
		}
	}
}
