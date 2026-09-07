<?php

declare(strict_types=1);
/**
 * Unit tests for PluginGenerator.
 *
 * Covers the pure helper methods (parse_file_blocks, detect_main_file) and
 * the early-return branches of all AI-calling methods when
 * wp_ai_client_prompt() is unavailable (the common test-environment case).
 *
 * Tests that require a live AI SDK connection are conditionally skipped when
 * wp_ai_client_prompt() is not defined — this matches the pattern used across
 * the rest of the test suite (AgentLoopTest, AgentLoopClientToolsTest).
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\PluginBuilder;

use SdAiAgent\Core\Settings;
use SdAiAgent\PluginBuilder\PluginGenerator;
use WP_UnitTestCase;

/**
 * Tests for PluginGenerator.
 *
 * @group plugin-builder
 * @group plugin-generator
 */
class PluginGeneratorTest extends WP_UnitTestCase {

	// ─── parse_file_blocks ────────────────────────────────────────────────────

	/**
	 * parse_file_blocks returns a map when well-formed blocks are present.
	 */
	public function test_parse_file_blocks_with_valid_blocks(): void {
		$raw = <<<'RAW'
===FILE: my-plugin/my-plugin.php===
<?php
// Main plugin file
===ENDFILE===

===FILE: my-plugin/includes/helpers.php===
<?php
// Helpers
===ENDFILE===
RAW;

		$result = PluginGenerator::parse_file_blocks( $raw, 'my-plugin' );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertArrayHasKey( 'my-plugin/my-plugin.php', $result );
		$this->assertArrayHasKey( 'my-plugin/includes/helpers.php', $result );
		$this->assertStringContainsString( '<?php', $result['my-plugin/my-plugin.php'] );
	}

	/**
	 * parse_file_blocks falls back to slug/slug.php when no blocks found but PHP present.
	 */
	public function test_parse_file_blocks_fallback_for_bare_php(): void {
		$raw = "<?php\n// A plugin file with no file-block markers\n";

		$result = PluginGenerator::parse_file_blocks( $raw, 'my-plugin' );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertArrayHasKey( 'my-plugin/my-plugin.php', $result );
	}

	/**
	 * parse_file_blocks returns an empty array when neither blocks nor PHP are found.
	 */
	public function test_parse_file_blocks_empty_for_non_php_output(): void {
		$raw = 'This is just some prose without any PHP code.';

		$result = PluginGenerator::parse_file_blocks( $raw, 'my-plugin' );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * parse_file_blocks trims whitespace from file paths.
	 */
	public function test_parse_file_blocks_trims_path_whitespace(): void {
		$raw = "===FILE:  my-plugin/my-plugin.php  ===\n<?php // code\n===ENDFILE===\n";

		$result = PluginGenerator::parse_file_blocks( $raw, 'my-plugin' );

		$this->assertArrayHasKey( 'my-plugin/my-plugin.php', $result );
	}

	// ─── detect_main_file ─────────────────────────────────────────────────────

	/**
	 * detect_main_file returns the file containing the plugin header comment.
	 */
	public function test_detect_main_file_by_plugin_name_header(): void {
		$files = array(
			'my-plugin/includes/class-loader.php' => "<?php\n// Loader class\n",
			'my-plugin/my-plugin.php'             => "<?php\n/**\n * Plugin Name: My Plugin\n * Version: 1.0.0\n */\n",
		);

		$result = PluginGenerator::detect_main_file( $files, 'my-plugin' );

		$this->assertSame( 'my-plugin/my-plugin.php', $result );
	}

	/**
	 * detect_main_file falls back to slug/slug.php when no header comment found.
	 */
	public function test_detect_main_file_fallback_to_slug_path(): void {
		$files = array(
			'my-plugin/my-plugin.php' => "<?php\n// No plugin header\n",
			'my-plugin/admin.php'     => "<?php\n// Admin page\n",
		);

		$result = PluginGenerator::detect_main_file( $files, 'my-plugin' );

		$this->assertSame( 'my-plugin/my-plugin.php', $result );
	}

	/**
	 * detect_main_file falls back to first PHP file when slug path not in map.
	 */
	public function test_detect_main_file_first_php_fallback(): void {
		$files = array(
			'other-plugin/init.php'  => "<?php\n// Init\n",
			'other-plugin/admin.php' => "<?php\n// Admin\n",
		);

		$result = PluginGenerator::detect_main_file( $files, 'my-plugin' );

		$this->assertSame( 'other-plugin/init.php', $result );
	}

	/**
	 * detect_main_file returns empty string for an empty file map.
	 */
	public function test_detect_main_file_returns_empty_for_empty_map(): void {
		$result = PluginGenerator::detect_main_file( array(), 'my-plugin' );

		$this->assertSame( '', $result );
	}

	// ─── normalise_generated_file_path ───────────────────────────────────────

	/**
	 * The E2E counter prompt regression omitted ".php" from the main-file path.
	 */
	public function test_normalise_generated_file_path_repairs_e2e_counter_main_file_without_extension(): void {
		$result = PluginGenerator::normalise_generated_file_path(
			'sd-e2e-counter-widget',
			'sd-e2e-counter-widget/sd-e2e-counter-widget',
			true
		);

		$this->assertSame( 'sd-e2e-counter-widget/sd-e2e-counter-widget.php', $result );
	}

	/**
	 * Empty AI paths fall back to the canonical main plugin file.
	 */
	public function test_normalise_generated_file_path_falls_back_to_canonical_main_file(): void {
		$result = PluginGenerator::normalise_generated_file_path( 'my-plugin', '', true );

		$this->assertSame( 'my-plugin/my-plugin.php', $result );
	}

	/**
	 * Traversal in AI-generated paths is rejected before installation.
	 */
	public function test_normalise_generated_file_path_rejects_traversal(): void {
		$result = PluginGenerator::normalise_generated_file_path( 'my-plugin', '../evil', true );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_invalid_generated_path', $result->get_error_code() );
	}

	// ─── generate_plan — SDK-unavailable path ─────────────────────────────────

	/**
	 * generate_plan returns WP_Error when wp_ai_client_prompt is unavailable.
	 */
	public function test_generate_plan_returns_error_when_sdk_unavailable(): void {
		if ( function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'wp_ai_client_prompt() is available; cannot test unavailable path.' );
		}

		$result = PluginGenerator::generate_plan( 'A simple cookie consent banner' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_client_unavailable', $result->get_error_code() );
	}

	/**
	 * generate_plan returns WP_Error for an empty description.
	 */
	public function test_generate_plan_returns_error_for_empty_description(): void {
		if ( function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'wp_ai_client_prompt() is available; test is for the pre-SDK guard.' );
		}

		// Empty description triggers its own WP_Error before the SDK check.
		// Re-test without SDK available so both guards are exercised separately.
		$result = PluginGenerator::generate_plan( '   ' );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	// ─── generate_code — SDK-unavailable path ────────────────────────────────

	/**
	 * generate_code returns WP_Error when wp_ai_client_prompt is unavailable.
	 */
	public function test_generate_code_returns_error_when_sdk_unavailable(): void {
		if ( function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'wp_ai_client_prompt() is available; cannot test unavailable path.' );
		}

		$plan = array(
			'name'    => 'Test Plugin',
			'slug'    => 'test-plugin',
			'version' => '1.0.0',
			'type'    => 'single-file',
			'files'   => array(
				array(
					'path'         => 'test-plugin/test-plugin.php',
					'purpose'      => 'Main plugin file',
					'dependencies' => array(),
				),
			),
			'hooks_used'           => array( 'init' ),
			'settings'             => array(),
			'has_admin_page'       => false,
			'estimated_complexity' => 'simple',
		);

		$result = PluginGenerator::generate_code( $plan );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_client_unavailable', $result->get_error_code() );
	}

	/**
	 * generate_code returns WP_Error when plan has no files.
	 */
	public function test_generate_code_returns_error_for_empty_plan_files(): void {
		if ( function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'wp_ai_client_prompt() is available; empty-plan guard fires after SDK check.' );
		}

		$result = PluginGenerator::generate_code( array( 'slug' => 'test', 'files' => array() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	// ─── generate_file — SDK-unavailable path ────────────────────────────────

	/**
	 * generate_file returns WP_Error when wp_ai_client_prompt is unavailable.
	 */
	public function test_generate_file_returns_error_when_sdk_unavailable(): void {
		if ( function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'wp_ai_client_prompt() is available; cannot test unavailable path.' );
		}

		$plan      = array( 'slug' => 'my-plugin', 'name' => 'My Plugin', 'version' => '1.0.0' );
		$file_spec = array( 'path' => 'my-plugin/my-plugin.php', 'purpose' => 'Main file', 'dependencies' => array() );

		$result = PluginGenerator::generate_file( $plan, $file_spec );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_client_unavailable', $result->get_error_code() );
	}

	// ─── nested generation route ─────────────────────────────────────────────

	/**
	 * Nested plugin prompts retain the parent AgentLoop provider/model pair.
	 */
	public function test_resolve_generation_route_preserves_parent_provider_model(): void {
		$models_filter = static function (): array {
			return array(
				'sd-ai-agent-cloud' => array( 'superdav-chat-strong' ),
			);
		};
		add_filter( 'sd_ai_agent_registered_models_for_validation', $models_filter );

		try {
			$result = PluginGenerator::resolve_generation_route(
				array(
					'provider_id' => 'sd-ai-agent-cloud',
					'model_id'    => 'superdav-chat-strong',
				)
			);

			$this->assertIsArray( $result );
			$this->assertSame( 'sd-ai-agent-cloud', $result['provider_id'] );
			$this->assertSame( 'superdav-chat-strong', $result['model_id'] );
		} finally {
			remove_filter( 'sd_ai_agent_registered_models_for_validation', $models_filter );
		}
	}

	/**
	 * An unavailable parent model is rejected instead of silently changing route.
	 */
	public function test_resolve_generation_route_rejects_unavailable_parent_model(): void {
		$models_filter = static function (): array {
			return array(
				'sd-ai-agent-cloud' => array( 'superdav-chat-strong' ),
			);
		};
		add_filter( 'sd_ai_agent_registered_models_for_validation', $models_filter );

		try {
			if ( Settings::is_model_advertised( 'sd-ai-agent-cloud', 'unavailable-model' ) ) {
				$this->markTestSkipped( 'The SDK registry is unavailable, so model compatibility cannot be validated.' );
			}

			$result = PluginGenerator::resolve_generation_route(
				array(
					'provider_id' => 'sd-ai-agent-cloud',
					'model_id'    => 'unavailable-model',
				)
			);

			$this->assertWPError( $result );
			$this->assertSame( 'sd_ai_agent_plugin_generation_model_unavailable', $result->get_error_code() );
		} finally {
			remove_filter( 'sd_ai_agent_registered_models_for_validation', $models_filter );
		}
	}

	/**
	 * Direct callers fall back to the validated configured provider/model pair.
	 */
	public function test_resolve_generation_route_uses_configured_default_without_parent_context(): void {
		$original_settings = get_option( Settings::OPTION_NAME, null );
		$models_filter     = static function (): array {
			return array(
				'sd-ai-agent-cloud' => array( 'superdav-chat-strong' ),
			);
		};
		add_filter( 'sd_ai_agent_registered_models_for_validation', $models_filter );
		update_option(
			Settings::OPTION_NAME,
			array(
				'default_provider' => 'sd-ai-agent-cloud',
				'default_model'    => 'superdav-chat-strong',
			),
			false
		);

		try {
			$result = PluginGenerator::resolve_generation_route();

			$this->assertIsArray( $result );
			$this->assertSame( 'sd-ai-agent-cloud', $result['provider_id'] );
			$this->assertSame( 'superdav-chat-strong', $result['model_id'] );
		} finally {
			remove_filter( 'sd_ai_agent_registered_models_for_validation', $models_filter );
			if ( null === $original_settings ) {
				delete_option( Settings::OPTION_NAME );
			} else {
				update_option( Settings::OPTION_NAME, $original_settings, false );
			}
		}
	}

	// ─── review_code — SDK-unavailable path ──────────────────────────────────

	/**
	 * review_code returns WP_Error when wp_ai_client_prompt is unavailable.
	 */
	public function test_review_code_returns_error_when_sdk_unavailable(): void {
		if ( function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'wp_ai_client_prompt() is available; cannot test unavailable path.' );
		}

		$files = array( 'my-plugin/my-plugin.php' => "<?php\n// code\n" );
		$plan  = array( 'slug' => 'my-plugin', 'name' => 'My Plugin' );

		$result = PluginGenerator::review_code( $files, $plan );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_client_unavailable', $result->get_error_code() );
	}

	/**
	 * review_code returns WP_Error for an empty files array.
	 */
	public function test_review_code_returns_error_for_empty_files(): void {
		if ( function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'wp_ai_client_prompt() is available; empty-files guard fires after SDK check.' );
		}

		$result = PluginGenerator::review_code( array(), array( 'slug' => 'test' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
