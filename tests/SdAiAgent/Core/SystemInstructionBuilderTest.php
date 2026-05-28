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

	/**
	 * Tool-routing guidance must prevent prompt-only ability names from being
	 * emitted as direct tool calls when they are absent from the current tool list.
	 */
	public function test_build_includes_active_tool_routing_guidance(): void {
		$builder = new SystemInstructionBuilder();

		$instruction = $builder->build(
			array(),
			array(
				'sd-ai-agent/ability-search',
				'sd-ai-agent/ability-call',
				'sd-ai-agent/list-posts',
			)
		);

		$this->assertStringContainsString( '## Tool routing', $instruction );
		$this->assertStringContainsString( '`sd-ai-agent/list-posts`', $instruction );
		$this->assertStringContainsString(
			'do not emit its direct `wpab__...` tool call',
			$instruction
		);
		$this->assertStringContainsString(
			'then invoke it through `sd-ai-agent/ability-call`',
			$instruction
		);
	}

	/**
	 * The build-vs-install section should appear when plugin-discovery
	 * abilities are active, nudging the model to search wp.org before
	 * hand-coding multi-file features.
	 *
	 * Regression: session #25 (NerdLove dating site) — agent wrote ~2,000
	 * LOC of custom plugin code without ever calling search-plugin-directory
	 * or install-plugin.
	 */
	public function test_build_vs_install_section_present_when_discovery_active(): void {
		$section = SystemInstructionBuilder::build_build_vs_install_section(
			array(
				'sd-ai-agent/search-plugin-directory',
				'sd-ai-agent/install-plugin',
			)
		);

		$this->assertStringContainsString( '## Build vs install', $section );
		$this->assertStringContainsString( 'sd-ai-agent/search-plugin-directory', $section );
		$this->assertStringContainsString( 'sd-ai-agent/install-plugin', $section );
		$this->assertStringContainsString( '60%', $section );
	}

	/**
	 * The build-vs-install section should be empty when no plugin-discovery
	 * ability is active so the prompt does not advertise unreachable tools.
	 */
	public function test_build_vs_install_section_empty_without_discovery(): void {
		$section = SystemInstructionBuilder::build_build_vs_install_section(
			array(
				'sd-ai-agent/list-posts',
				'sd-ai-agent/create-post',
			)
		);

		$this->assertSame( '', $section );
	}

	/**
	 * The seeding/batching sub-section should appear when run-php or db-query
	 * is active alongside plugin-discovery abilities. Targets the failure mode
	 * where the agent made 56 serial wp-cli calls to seed 6 demo profiles.
	 */
	public function test_build_vs_install_section_includes_batching_when_runphp_active(): void {
		$section = SystemInstructionBuilder::build_build_vs_install_section(
			array(
				'sd-ai-agent/search-plugin-directory',
				'sd-ai-agent/run-php',
			)
		);

		$this->assertStringContainsString( '### Seeding & batch updates', $section );
		$this->assertStringContainsString( 'sd-ai-agent/run-php', $section );
		$this->assertStringContainsString( 'serial `wp-cli` invocations', $section );
	}

	/**
	 * The seeding/batching sub-section should be absent when neither run-php
	 * nor db-query is in the ability list.
	 */
	public function test_build_vs_install_section_omits_batching_when_no_bulk_ability(): void {
		$section = SystemInstructionBuilder::build_build_vs_install_section(
			array(
				'sd-ai-agent/search-plugin-directory',
			)
		);

		$this->assertStringNotContainsString( '### Seeding & batch updates', $section );
	}

	/**
	 * The build() method should integrate the new section when a
	 * content-generation ability and a plugin-discovery ability are both
	 * active (the realistic dating-site / theme-build scenario).
	 */
	public function test_build_includes_build_vs_install_section_for_content_sessions(): void {
		$builder = new SystemInstructionBuilder();

		$instruction = $builder->build(
			array(),
			array(
				'sd-ai-agent/scaffold-block-theme',
				'sd-ai-agent/file-write',
				'sd-ai-agent/search-plugin-directory',
				'sd-ai-agent/install-plugin',
			)
		);

		$this->assertStringContainsString( '## Build vs install', $instruction );
	}
}
