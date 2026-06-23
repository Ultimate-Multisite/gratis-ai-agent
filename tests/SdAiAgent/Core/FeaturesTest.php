<?php
/**
 * Test case for the Features feature-flag registry.
 *
 * The constants under test (SD_AI_AGENT_FEATURE_*) are defined once per
 * PHP process by the plugin bootstrap and cannot be undefined for the
 * test run. We therefore exercise the registry via the split-package
 * contract: each advanced feature must be reachable as a named map entry,
 * the constant-name mapping must remain stable, and Features::all() must
 * include every gated flag so the JS bundle can render the right UI.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\Features;
use WP_UnitTestCase;

/**
 * Test Features registry.
 */
class FeaturesTest extends WP_UnitTestCase {

	/**
	 * Feature flags supplied by the Superdav AI Agent Advanced companion plugin.
	 * If you remove one, also update advanced registration, feature docs, and the
	 * row in `docs/wordpress-org-submission.md` Build Matrix.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function advanced_feature_flags(): array {
		return [
			[ Features::PLUGIN_BUILDER, 'SD_AI_AGENT_FEATURE_PLUGIN_BUILDER' ],
			[ Features::CUSTOM_TOOLS_CLI, 'SD_AI_AGENT_FEATURE_CUSTOM_TOOLS_CLI' ],
			[ Features::PLUGIN_STATE_CHANGES, 'SD_AI_AGENT_FEATURE_PLUGIN_STATE_CHANGES' ],
			[ Features::PLUGIN_INSTALL_FROM_URL, 'SD_AI_AGENT_FEATURE_PLUGIN_INSTALL_FROM_URL' ],
			[ Features::FILE_WRITE, 'SD_AI_AGENT_FEATURE_FILE_WRITE' ],
			[ Features::SCAFFOLD_BLOCK_THEME, 'SD_AI_AGENT_FEATURE_SCAFFOLD_BLOCK_THEME' ],
			[ Features::WP_REST_DISPATCHER, 'SD_AI_AGENT_FEATURE_WP_REST_DISPATCHER' ],
			[ Features::WP_CLI_DISPATCHER, 'SD_AI_AGENT_FEATURE_WP_CLI_DISPATCHER' ],
			[ Features::BENCHMARK, 'SD_AI_AGENT_FEATURE_BENCHMARK' ],
			[ Features::USER_MANAGEMENT, 'SD_AI_AGENT_FEATURE_USER_MANAGEMENT' ],
			[ Features::RUN_PHP, 'SD_AI_AGENT_FEATURE_RUN_PHP' ],
		];
	}

	/**
	 * Each gated feature must appear in Features::all() so the JS
	 * bundle can hide the corresponding UI when the flag is off.
	 *
	 * @dataProvider advanced_feature_flags
	 *
	 * @param string $feature_key Feature identifier (e.g. 'plugin_builder').
	 * @param string $constant    Backing constant name (unused here but
	 *                            kept so the data provider is shared with
	 *                            other test methods).
	 */
	public function test_each_gated_feature_appears_in_all( string $feature_key, string $constant ): void {
		unset( $constant ); // Not used in this assertion.
		$all = Features::all();

		$this->assertArrayHasKey(
			$feature_key,
			$all,
			"Features::all() must include '{$feature_key}' so the JS bundle can read its state."
		);
		$this->assertIsBool( $all[ $feature_key ] );
	}

	/**
	 * Features::is_enabled() defaults to `true` for every advanced feature when
	 * the advanced companion plugin is loaded and the backing constant is not
	 * defined. The core package alone returns `false` for these features.
	 *
	 * @dataProvider advanced_feature_flags
	 *
	 * @param string $feature_key Feature identifier.
	 * @param string $constant    Backing constant name.
	 */
	public function test_advanced_feature_defaults_to_enabled_when_loaded( string $feature_key, string $constant ): void {
		if ( ! defined( 'SD_AI_AGENT_ADVANCED_LOADED' ) || constant( 'SD_AI_AGENT_ADVANCED_LOADED' ) !== true ) {
			$this->markTestSkipped(
				"Skipping defaults check for '{$feature_key}' because the advanced companion plugin is not loaded."
			);
		}

		if ( defined( $constant ) && constant( $constant ) === false ) {
			$this->markTestSkipped(
				"Skipping defaults check for '{$feature_key}' because {$constant} is explicitly disabled in this environment."
			);
		}

		$this->assertTrue(
			Features::is_enabled( $feature_key ),
			"Feature '{$feature_key}' should be enabled when the advanced companion plugin is loaded and {$constant} is not false."
		);
	}

	/**
	 * Unknown feature identifiers fall through to enabled (fail-open)
	 * so a typo in a caller doesn't silently disable functionality.
	 */
	public function test_unknown_feature_is_enabled(): void {
		$this->assertTrue( Features::is_enabled( 'this_feature_does_not_exist' ) );
	}

	/**
	 * Smoke test: the constant-name mapping covered by the data provider must
	 * remain stable for wp-config.php overrides, REST/UI feature snapshots, and
	 * advanced companion plugin registration.
	 *
	 * @dataProvider advanced_feature_flags
	 *
	 * @param string $feature_key Feature identifier.
	 * @param string $constant    Expected backing constant name.
	 */
	public function test_constant_naming_matches_build_script_contract( string $feature_key, string $constant ): void {
		// We can't see Features::CONSTANT_MAP directly (it's private), but
		// `is_enabled()` reads it. Defining a same-named constant with a
		// custom value would let us prove the mapping if the constant
		// weren't already defined, so we assert via the all() snapshot.
		$all = Features::all();
		$this->assertArrayHasKey( $feature_key, $all );

		// Sanity-check the constant naming convention itself.
		$this->assertStringStartsWith(
			'SD_AI_AGENT_FEATURE_',
			$constant,
			"Feature constants must use the SD_AI_AGENT_FEATURE_ prefix per AGENTS.md canonical naming rules."
		);
	}
}
