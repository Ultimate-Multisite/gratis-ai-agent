<?php

declare(strict_types=1);
/**
 * Tests for the Working Cadence section in SystemInstructionBuilder.
 *
 * Verifies that the "Working cadence" section is injected into the system
 * prompt when content-generation or theme-modification abilities are active,
 * and omitted for pure Q&A turns.
 *
 * Acceptance criteria (GH#1587 Phase 4):
 * - System prompt contains "Working cadence" when a content-generation tool is in scope.
 * - System prompt does NOT contain it for pure-Q&A turns.
 * - build_working_cadence_section() returns the expected key phrases.
 * - has_content_generation_ability() correctly identifies trigger abilities.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\SystemInstructionBuilder;
use WP_UnitTestCase;

/**
 * Test Working-cadence injection in SystemInstructionBuilder.
 *
 * @covers \SdAiAgent\Core\SystemInstructionBuilder::build
 * @covers \SdAiAgent\Core\SystemInstructionBuilder::build_working_cadence_section
 * @covers \SdAiAgent\Core\SystemInstructionBuilder::has_content_generation_ability
 */
class PromptBuilderCadenceTest extends WP_UnitTestCase {

	// ── build_working_cadence_section ─────────────────────────────────────

	/**
	 * The cadence section must start with the canonical heading.
	 */
	public function test_cadence_section_has_heading(): void {
		$section = SystemInstructionBuilder::build_working_cadence_section();

		$this->assertStringContainsString(
			'## Working cadence',
			$section,
			'Cadence section must contain the "## Working cadence" heading'
		);
	}

	/**
	 * The cadence section must explain the one-edit-per-turn rule.
	 */
	public function test_cadence_section_has_one_per_turn_rule(): void {
		$section = SystemInstructionBuilder::build_working_cadence_section();

		$this->assertStringContainsString(
			'One Write or Edit per turn',
			$section,
			'Cadence section must describe the one-write-per-turn rule'
		);
	}

	/**
	 * The cadence section must describe the style.css skeleton approach.
	 */
	public function test_cadence_section_has_skeleton_rule(): void {
		$section = SystemInstructionBuilder::build_working_cadence_section();

		$this->assertStringContainsString(
			'style.css',
			$section,
			'Cadence section must reference style.css'
		);
		$this->assertStringContainsString(
			'skeleton',
			$section,
			'Cadence section must describe the skeleton-first approach'
		);
	}

	/**
	 * The cadence section must warn against overwriting a freshly scaffolded style.css.
	 */
	public function test_cadence_section_has_never_overwrite_rule(): void {
		$section = SystemInstructionBuilder::build_working_cadence_section();

		$this->assertStringContainsString(
			'Never overwrite a freshly scaffolded',
			$section,
			'Cadence section must warn against overwriting style.css'
		);
	}

	/**
	 * The cadence section must describe the page-content anchor approach.
	 */
	public function test_cadence_section_has_anchor_rule(): void {
		$section = SystemInstructionBuilder::build_working_cadence_section();

		$this->assertStringContainsString(
			'section:hero',
			$section,
			'Cadence section must reference the anchor comment pattern'
		);
	}

	// ── has_content_generation_ability ───────────────────────────────────

	/**
	 * create-post triggers cadence injection.
	 */
	public function test_create_post_triggers_cadence(): void {
		$this->assertTrue(
			SystemInstructionBuilder::has_content_generation_ability( array( 'sd-ai-agent/create-post' ) ),
			'create-post should trigger cadence injection'
		);
	}

	/**
	 * update-post triggers cadence injection.
	 */
	public function test_update_post_triggers_cadence(): void {
		$this->assertTrue(
			SystemInstructionBuilder::has_content_generation_ability( array( 'sd-ai-agent/update-post' ) ),
			'update-post should trigger cadence injection'
		);
	}

	/**
	 * scaffold-block-theme triggers cadence injection.
	 */
	public function test_scaffold_block_theme_triggers_cadence(): void {
		$this->assertTrue(
			SystemInstructionBuilder::has_content_generation_ability( array( 'sd-ai-agent/scaffold-block-theme' ) ),
			'scaffold-block-theme should trigger cadence injection'
		);
	}

	/**
	 * file-write triggers cadence injection (used for theme file output).
	 */
	public function test_file_write_triggers_cadence(): void {
		$this->assertTrue(
			SystemInstructionBuilder::has_content_generation_ability( array( 'sd-ai-agent/file-write' ) ),
			'file-write should trigger cadence injection'
		);
	}

	/**
	 * file-edit triggers cadence injection (used for incremental CSS fills).
	 */
	public function test_file_edit_triggers_cadence(): void {
		$this->assertTrue(
			SystemInstructionBuilder::has_content_generation_ability( array( 'sd-ai-agent/file-edit' ) ),
			'file-edit should trigger cadence injection'
		);
	}

	/**
	 * A pure-read ability should NOT trigger cadence injection.
	 */
	public function test_readonly_ability_does_not_trigger_cadence(): void {
		$this->assertFalse(
			SystemInstructionBuilder::has_content_generation_ability( array( 'sd-ai-agent/get-post', 'sd-ai-agent/list-posts' ) ),
			'Read-only abilities should not trigger cadence injection'
		);
	}

	/**
	 * An empty ability list should not trigger cadence injection.
	 */
	public function test_empty_ability_list_does_not_trigger_cadence(): void {
		$this->assertFalse(
			SystemInstructionBuilder::has_content_generation_ability( array() ),
			'Empty ability list should not trigger cadence injection'
		);
	}

	/**
	 * Mixed read+write abilities trigger cadence injection via the write ability.
	 */
	public function test_mixed_abilities_triggers_cadence_if_any_write(): void {
		$this->assertTrue(
			SystemInstructionBuilder::has_content_generation_ability(
				array(
					'sd-ai-agent/get-post',
					'sd-ai-agent/list-posts',
					'sd-ai-agent/create-post',
				)
			),
			'Mixed read+write set should trigger cadence injection'
		);
	}

	// ── build() integration ───────────────────────────────────────────────

	/**
	 * Cadence section IS injected when create-post is among the active abilities.
	 */
	public function test_build_injects_cadence_for_create_post(): void {
		$builder  = new SystemInstructionBuilder();
		$settings = array();

		$instruction = $builder->build( $settings, array( 'sd-ai-agent/create-post' ) );

		$this->assertStringContainsString(
			'## Working cadence',
			$instruction,
			'build() must inject the cadence section when create-post is active'
		);
	}

	/**
	 * Cadence section IS injected when scaffold-block-theme is active.
	 */
	public function test_build_injects_cadence_for_scaffold_block_theme(): void {
		$builder  = new SystemInstructionBuilder();
		$settings = array();

		$instruction = $builder->build( $settings, array( 'sd-ai-agent/scaffold-block-theme' ) );

		$this->assertStringContainsString(
			'## Working cadence',
			$instruction,
			'build() must inject the cadence section when scaffold-block-theme is active'
		);
	}

	/**
	 * Cadence section is NOT injected for a pure Q&A turn (no abilities).
	 *
	 * This is the regression test that prevents the cadence block from bloating
	 * system prompts on read-only or conversational turns.
	 */
	public function test_build_omits_cadence_for_empty_ability_list(): void {
		$builder  = new SystemInstructionBuilder();
		$settings = array();

		$instruction = $builder->build( $settings, array() );

		$this->assertStringNotContainsString(
			'## Working cadence',
			$instruction,
			'build() must NOT inject the cadence section when no abilities are active'
		);
	}

	/**
	 * Cadence section is NOT injected when only read-only abilities are active.
	 *
	 * Regression: ensures get-post / list-posts turns stay lean.
	 */
	public function test_build_omits_cadence_for_readonly_abilities(): void {
		$builder  = new SystemInstructionBuilder();
		$settings = array();

		$instruction = $builder->build(
			$settings,
			array(
				'sd-ai-agent/get-post',
				'sd-ai-agent/list-posts',
				'sd-ai-agent/memory-list',
			)
		);

		$this->assertStringNotContainsString(
			'## Working cadence',
			$instruction,
			'build() must NOT inject the cadence section for read-only ability lists'
		);
	}

	/**
	 * build() still works with the default empty $ability_names (backward compat).
	 */
	public function test_build_backward_compat_no_ability_names(): void {
		$builder  = new SystemInstructionBuilder();
		$settings = array();

		// Calling build() without the second argument must not raise an error.
		$instruction = $builder->build( $settings );

		$this->assertStringContainsString(
			'## Core Principles',
			$instruction,
			'build() must return a valid instruction even without ability_names argument'
		);
		$this->assertStringNotContainsString(
			'## Working cadence',
			$instruction,
			'build() must NOT inject cadence when ability_names is omitted (backward compat)'
		);
	}
}
