<?php

declare(strict_types=1);
/**
 * Tests for canonical plugin naming boundaries.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests;

use WP_UnitTestCase;

/**
 * Guard the deliberately different public slug and internal ability namespace.
 */
class CanonicalNamingTest extends WP_UnitTestCase {

	/**
	 * Return the repository root path.
	 *
	 * @return string Repository root path.
	 */
	private function root_dir(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * AbstractAbility must keep internal ability IDs separate from the text domain.
	 */
	public function test_abstract_ability_keeps_sd_ai_agent_ability_namespace(): void {
		$contents = (string) file_get_contents( $this->root_dir() . '/includes/Abilities/AbstractAbility.php' );

		$this->assertStringContainsString( "wp_register_ability( 'sd-ai-agent/my-ability'", $contents );
		$this->assertStringContainsString( "return 'sd-ai-agent';", $contents );
		$this->assertStringContainsString( "__( 'My Ability', 'superdav-ai-agent' )", $contents );
		$this->assertStringNotContainsString( "wp_register_ability( 'superdav-ai-agent/", $contents );
		$this->assertStringNotContainsString( "return 'superdav-ai-agent';", $contents );
		$this->assertStringNotContainsString( 'gratis-ai-agent', $contents );
	}

	/**
	 * The WordPress.org-facing plugin header must keep the public slug text domain.
	 */
	public function test_plugin_header_uses_superdav_text_domain(): void {
		$contents = (string) file_get_contents( $this->root_dir() . '/superdav-ai-agent.php' );

		$this->assertStringContainsString( 'Text Domain: superdav-ai-agent', $contents );
		$this->assertStringNotContainsString( 'Text Domain: sd-ai-agent', $contents );
		$this->assertStringNotContainsString( 'Text Domain: gratis-ai-agent', $contents );
	}
}
