<?php

declare(strict_types=1);
/**
 * Tests for the bundled block-themes skill.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Models;

use SdAiAgent\Models\Skill;
use WP_UnitTestCase;

/**
 * Verifies the Phase 2 block-themes expansion remains available to agents.
 */
class BlockThemesSkillTest extends WP_UnitTestCase {

	/**
	 * The bundled skill is registered as a built-in block theme skill.
	 */
	public function test_block_themes_skill_is_registered_as_builtin(): void {
		$definitions = Skill::get_builtin_definitions();

		$this->assertArrayHasKey( 'block-themes', $definitions );
		$this->assertSame( 'Block Themes (FSE)', $definitions['block-themes']['name'] );
		$this->assertFalse( $definitions['block-themes']['enabled'] );
		$this->assertStringContainsString( 'theme.json', $definitions['block-themes']['content'] );
	}

	/**
	 * The Phase 2 expansion keeps the documented size envelope from the parent task.
	 */
	public function test_block_themes_skill_keeps_phase_two_size_envelope(): void {
		$content    = Skill::get_builtin_definitions()['block-themes']['content'];
		$line_count = substr_count( $content, "\n" ) + 1;

		$this->assertGreaterThanOrEqual( 350, $line_count );
		$this->assertLessThanOrEqual( 500, $line_count );
	}

	/**
	 * The skill includes the required theme.json presets and template composition guidance.
	 */
	public function test_block_themes_skill_includes_theme_json_and_template_part_guidance(): void {
		$content = Skill::get_builtin_definitions()['block-themes']['content'];

		$required_patterns = [
			'Always declare `$schema` and `version: 3`',
			'5–7 entries (`primary`, `secondary`, `accent`, `background`, `surface`',
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
		$content = Skill::get_builtin_definitions()['block-themes']['content'];

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
		$content = Skill::get_builtin_definitions()['block-themes']['content'];

		$this->assertStringContainsString( '**No HTML blocks.**', $content );
		$this->assertStringContainsString( '**No decorative HTML comments.**', $content );
		$this->assertStringContainsString( '**No stock image URLs.**', $content );
		$this->assertStringContainsString( '**Validate before write.**', $content );
		$this->assertStringContainsString( 'sd-ai-agent/validate-block-content', $content );
	}
}
