<?php
/**
 * Test case for SystemInstructionBuilder class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\SystemInstructionBuilder;
use WP_UnitTestCase;

/**
 * Test SystemInstructionBuilder functionality.
 */
class SystemInstructionBuilderTest extends WP_UnitTestCase {

	/**
	 * Test that the default system instruction includes site configuration guidance.
	 *
	 * This test verifies the fix for issue #1497: the system prompt should
	 * explicitly instruct the agent to call tools for setting the site title,
	 * front page, and creating navigation menus.
	 *
	 * @see https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1497
	 */
	public function test_default_system_instruction_includes_site_configuration_guidance(): void {
		$instruction = SystemInstructionBuilder::default_system_instruction();

		// Verify the instruction includes the Site Configuration section.
		$this->assertStringContainsString(
			'## Site Configuration (IMPORTANT)',
			$instruction,
			'System instruction should include Site Configuration section'
		);

		// Verify it includes guidance for setting the site title.
		$this->assertStringContainsString(
			'update-option',
			$instruction,
			'System instruction should mention update-option ability'
		);
		$this->assertStringContainsString(
			'blogname',
			$instruction,
			'System instruction should mention blogname option for site title'
		);

		// Verify it includes guidance for setting the static front page.
		$this->assertStringContainsString(
			'show_on_front',
			$instruction,
			'System instruction should mention show_on_front option'
		);
		$this->assertStringContainsString(
			'page_on_front',
			$instruction,
			'System instruction should mention page_on_front option'
		);

		// Verify it includes guidance for creating and assigning menus.
		$this->assertStringContainsString(
			'create-menu',
			$instruction,
			'System instruction should mention create-menu ability'
		);
		$this->assertStringContainsString(
			'add-menu-item',
			$instruction,
			'System instruction should mention add-menu-item ability'
		);
		$this->assertStringContainsString(
			'assign-menu-location',
			$instruction,
			'System instruction should mention assign-menu-location ability'
		);

		// Verify it includes the honesty principle.
		$this->assertStringContainsString(
			'Only claim completion for work you actually performed',
			$instruction,
			'System instruction should include honesty principle about claiming completion'
		);
	}

	/**
	 * Test that the system instruction builder includes the base instruction.
	 */
	public function test_build_includes_default_instruction(): void {
		$builder = new SystemInstructionBuilder();
		$settings = array();

		$instruction = $builder->build( $settings );

		// Verify it includes core principles.
		$this->assertStringContainsString(
			'## Core Principles',
			$instruction,
			'Built instruction should include Core Principles section'
		);

		// Verify it includes the WordPress environment section.
		$this->assertStringContainsString(
			'## WordPress Environment',
			$instruction,
			'Built instruction should include WordPress Environment section'
		);
	}

	/**
	 * Test that custom system prompt overrides the default.
	 */
	public function test_build_uses_custom_system_prompt(): void {
		$builder = new SystemInstructionBuilder();
		$custom_prompt = 'This is a custom prompt.';
		$settings = array(
			'system_prompt' => $custom_prompt,
		);

		$instruction = $builder->build( $settings );

		// Verify the custom prompt is included.
		$this->assertStringContainsString(
			$custom_prompt,
			$instruction,
			'Built instruction should include custom system prompt'
		);
	}
}
