<?php

declare(strict_types=1);
/**
 * Tests for the bundled site-specification skill.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Models;

use SdAiAgent\Enums\MemoryCategory;
use SdAiAgent\Models\Skill;
use WP_UnitTestCase;

/**
 * Verifies the Phase 1 site-specification deliverables stay wired together.
 */
class SiteSpecificationSkillTest extends WP_UnitTestCase {

	/**
	 * The bundled skill is registered as an enabled built-in skill.
	 */
	public function test_site_specification_skill_is_registered_as_builtin(): void {
		$definitions = Skill::get_builtin_definitions();

		$this->assertArrayHasKey( 'site-specification', $definitions );
		$this->assertSame( 'Site Specification', $definitions['site-specification']['name'] );
		$this->assertTrue( $definitions['site-specification']['enabled'] );
		$this->assertStringContainsString( 'siteBrief', $definitions['site-specification']['content'] );
	}

	/**
	 * The skill documents the dedicated site_brief memory category.
	 */
	public function test_site_specification_skill_uses_site_brief_memory_category(): void {
		$content = Skill::get_builtin_definitions()['site-specification']['content'];

		$this->assertSame( 'site_brief', MemoryCategory::SiteBrief->value );
		$this->assertStringContainsString( 'category `site_brief`', $content );
		$this->assertStringContainsString( 'category=site_brief', $content );
	}

	/**
	 * The skill keeps the seven required site-type inference tables.
	 */
	public function test_site_specification_skill_includes_required_site_type_tables(): void {
		$content = Skill::get_builtin_definitions()['site-specification']['content'];

		$required_headings = [
			'### SaaS / Technology',
			'### E-commerce / Retail',
			'### Professional Services (Law, Finance, Consulting)',
			'### Restaurant / Food Service',
			'### Creative / Portfolio',
			'### Blog / Media',
			'### Non-profit / Organisation',
		];

		foreach ( $required_headings as $heading ) {
			$this->assertStringContainsString( $heading, $content );
		}
	}

	/**
	 * The skill keeps representative worked examples for downstream theme generation.
	 */
	public function test_site_specification_skill_includes_required_worked_examples(): void {
		$content = Skill::get_builtin_definitions()['site-specification']['content'];

		$this->assertStringContainsString( '### Example 1: Coffee Shop', $content );
		$this->assertStringContainsString( '### Example 2: Law Firm', $content );
		$this->assertStringContainsString( '### Example 3: Esports Team', $content );
	}
}
