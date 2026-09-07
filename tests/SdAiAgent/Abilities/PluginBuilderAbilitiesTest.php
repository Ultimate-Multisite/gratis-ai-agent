<?php

declare(strict_types=1);
/**
 * Test case for PluginBuilderAbilities class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\GeneratePluginAbility;
use SdAiAgent\Abilities\SandboxActivatePluginAbility;
use SdAiAgent\Abilities\SandboxTestPluginAbility;
use SdAiAgent\Abilities\ScanPluginHooksAbility;
use SdAiAgent\Abilities\ScanThemeHooksAbility;
use SdAiAgent\Abilities\UpdatePluginSandboxedAbility;
use WP_UnitTestCase;

/**
 * Test PluginBuilderAbilities functionality.
 */
class PluginBuilderAbilitiesTest extends WP_UnitTestCase {

	// ── GeneratePluginAbility ─────────────────────────────────────────────

	/**
	 * GeneratePluginAbility returns WP_Error when description is empty.
	 */
	public function test_generate_plugin_returns_wp_error_for_empty_description(): void {
		$ability = new GeneratePluginAbility( 'sd-ai-agent/generate-plugin' );

		$result = $ability->run( [ 'description' => '' ] );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_empty_description', $result->get_error_code() );
	}

	/**
	 * GeneratePluginAbility returns WP_Error when description is missing.
	 */
	public function test_generate_plugin_returns_wp_error_for_missing_description(): void {
		$ability = new GeneratePluginAbility( 'sd-ai-agent/generate-plugin' );

		$result = $ability->run( [] );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_empty_description', $result->get_error_code() );
	}

	/**
	 * GeneratePluginAbility declares plan as an object to match the returned plan array.
	 */
	public function test_generate_plugin_output_schema_matches_plan_array(): void {
		$ability = new GeneratePluginAbility( 'sd-ai-agent/generate-plugin' );
		$schema  = $ability->get_output_schema();

		$this->assertSame( 'object', $schema['properties']['plan']['type'] );
	}

	/**
	 * Provider/model context is scoped to one resolver dispatch and can be cleared.
	 */
	public function test_generate_plugin_provider_model_context_is_clearable(): void {
		$ability  = new GeneratePluginAbility( 'sd-ai-agent/generate-plugin' );
		$property = new \ReflectionProperty( GeneratePluginAbility::class, 'provider_model_context' );
		$property->setAccessible( true );

		$ability->set_provider_model_context( 'sd-ai-agent-cloud', 'superdav-chat-strong' );
		$this->assertSame(
			array(
				'provider_id' => 'sd-ai-agent-cloud',
				'model_id'    => 'superdav-chat-strong',
			),
			$property->getValue( $ability )
		);

		$ability->clear_provider_model_context();
		$this->assertSame(
			array(
				'provider_id' => '',
				'model_id'    => '',
			),
			$property->getValue( $ability )
		);
	}

	// ── SandboxTestPluginAbility ───────────────────────────────────────────

	/**
	 * SandboxTestPluginAbility returns WP_Error when slug is empty.
	 */
	public function test_sandbox_test_returns_wp_error_for_empty_slug(): void {
		$ability = new SandboxTestPluginAbility( 'sd-ai-agent/sandbox-test-plugin' );

		$result = $ability->run( [ 'slug' => '', 'plugin_file' => 'my-plugin.php' ] );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_invalid_slug', $result->get_error_code() );
	}

	/**
	 * SandboxTestPluginAbility returns WP_Error when plugin_file is missing.
	 */
	public function test_sandbox_test_returns_wp_error_for_missing_plugin_file(): void {
		$ability = new SandboxTestPluginAbility( 'sd-ai-agent/sandbox-test-plugin' );

		$result = $ability->run( [ 'slug' => 'my-plugin', 'plugin_file' => '' ] );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_invalid_plugin_file', $result->get_error_code() );
	}

	// ── SandboxActivatePluginAbility ───────────────────────────────────────

	/**
	 * SandboxActivatePluginAbility returns WP_Error when plugin_file is empty.
	 */
	public function test_sandbox_activate_returns_wp_error_for_empty_plugin_file(): void {
		$ability = new SandboxActivatePluginAbility( 'sd-ai-agent/sandbox-activate-plugin' );

		$result = $ability->run( [ 'plugin_file' => '' ] );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_invalid_plugin_file', $result->get_error_code() );
	}

	/**
	 * SandboxActivatePluginAbility returns WP_Error when plugin_file is missing.
	 */
	public function test_sandbox_activate_returns_wp_error_for_missing_plugin_file(): void {
		$ability = new SandboxActivatePluginAbility( 'sd-ai-agent/sandbox-activate-plugin' );

		$result = $ability->run( [] );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_invalid_plugin_file', $result->get_error_code() );
	}

	// ── UpdatePluginSandboxedAbility ───────────────────────────────────────

	/**
	 * UpdatePluginSandboxedAbility returns WP_Error when slug is empty.
	 */
	public function test_update_plugin_sandboxed_returns_wp_error_for_empty_slug(): void {
		$ability = new UpdatePluginSandboxedAbility( 'sd-ai-agent/update-plugin-sandboxed' );

		$result = $ability->run( [ 'slug' => '', 'files' => [ 'my-plugin.php' => '<?php' ] ] );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_invalid_slug', $result->get_error_code() );
	}

	/**
	 * UpdatePluginSandboxedAbility returns WP_Error when files is empty.
	 */
	public function test_update_plugin_sandboxed_returns_wp_error_for_empty_files(): void {
		$ability = new UpdatePluginSandboxedAbility( 'sd-ai-agent/update-plugin-sandboxed' );

		$result = $ability->run( [ 'slug' => 'my-plugin', 'files' => [] ] );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_no_files', $result->get_error_code() );
	}

	/**
	 * UpdatePluginSandboxedAbility returns WP_Error when plugin directory does not exist.
	 *
	 * Since plugin_file is no longer required (derived from slug), verify the
	 * ability correctly propagates the plugin_not_found error from PluginUpdater.
	 */
	public function test_update_plugin_sandboxed_returns_wp_error_for_nonexistent_plugin(): void {
		$ability = new UpdatePluginSandboxedAbility( 'sd-ai-agent/update-plugin-sandboxed' );

		$result = $ability->run( [ 'slug' => 'non-existent-plugin-phpunit', 'files' => [ 'main.php' => '<?php' ] ] );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_plugin_not_found', $result->get_error_code() );
	}

	// ── ScanPluginHooksAbility ─────────────────────────────────────────────

	/**
	 * ScanPluginHooksAbility returns WP_Error when slug is empty.
	 */
	public function test_scan_plugin_hooks_returns_wp_error_for_empty_slug(): void {
		$ability = new ScanPluginHooksAbility( 'sd-ai-agent/scan-plugin-hooks' );

		$result = $ability->run( [ 'slug' => '' ] );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_invalid_slug', $result->get_error_code() );
	}

	/**
	 * ScanPluginHooksAbility returns WP_Error when plugin does not exist.
	 */
	public function test_scan_plugin_hooks_returns_wp_error_for_nonexistent_plugin(): void {
		$ability = new ScanPluginHooksAbility( 'sd-ai-agent/scan-plugin-hooks' );

		$result = $ability->run( [ 'slug' => 'nonexistent-plugin-xyz-99999' ] );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_plugin_not_found', $result->get_error_code() );
	}

	// ── ScanThemeHooksAbility ──────────────────────────────────────────────

	/**
	 * ScanThemeHooksAbility returns WP_Error when slug is empty.
	 */
	public function test_scan_theme_hooks_returns_wp_error_for_empty_slug(): void {
		$ability = new ScanThemeHooksAbility( 'sd-ai-agent/scan-theme-hooks' );

		$result = $ability->run( [ 'slug' => '' ] );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_invalid_slug', $result->get_error_code() );
	}

	/**
	 * ScanThemeHooksAbility returns WP_Error when theme does not exist.
	 */
	public function test_scan_theme_hooks_returns_wp_error_for_nonexistent_theme(): void {
		$ability = new ScanThemeHooksAbility( 'sd-ai-agent/scan-theme-hooks' );

		$result = $ability->run( [ 'slug' => 'nonexistent-theme-xyz-99999' ] );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_theme_not_found', $result->get_error_code() );
	}
}
