<?php

declare(strict_types=1);
/**
 * Tests for the public read-only block-theme project validation ability.
 *
 * @package SdAiAgent\Tests\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\ValidateBlockThemeProjectAbility;
use SdAiAgent\Services\BlockThemeProjectValidator;
use WP_Error;
use WP_UnitTestCase;

/**
 * Verifies the ability exposes deterministic project validation safely.
 */
class ValidateBlockThemeProjectAbilityTest extends WP_UnitTestCase {

	/**
	 * Theme slugs created during tests, removed in tear_down.
	 *
	 * @var list<string>
	 */
	private array $created_slugs = [];

	private function ability(): ValidateBlockThemeProjectAbility {
		return new ValidateBlockThemeProjectAbility( 'sd-ai-agent/validate-block-theme-project' );
	}

	public function tear_down(): void {
		foreach ( $this->created_slugs as $slug ) {
			self::rrmdir( trailingslashit( get_theme_root() ) . $slug );
		}
		wp_clean_themes_cache();
		parent::tear_down();
	}

	/**
	 * The public registration exposes the declared read-only contract.
	 */
	public function test_registered_ability_is_public_readonly_and_rest_visible(): void {
		$ability = wp_get_ability( 'sd-ai-agent/validate-block-theme-project' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'sd-ai-agent/validate-block-theme-project', $ability->get_name() );
		$this->assertTrue( $ability->get_meta()['mcp']['public'] );
		$this->assertTrue( $ability->get_meta()['annotations']['readonly'] );
		$this->assertFalse( $ability->get_meta()['annotations']['destructive'] );
		$this->assertTrue( $ability->get_meta()['annotations']['idempotent'] );
		$this->assertTrue( $ability->get_meta()['show_in_rest'] );
		$this->assertSame( [ 'stylesheet' ], $ability->get_input_schema()['required'] );
	}

	/**
	 * The direct ability surface accepts an installed marked theme and returns the report.
	 */
	public function test_run_returns_validation_report_for_an_installed_marked_theme(): void {
		$slug = $this->unique_slug();
		$this->stage_valid_theme( $slug );
		wp_clean_themes_cache();

		$result = $this->ability()->run( [ 'stylesheet' => $slug ] );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( $result['valid'], (string) wp_json_encode( $result['errors'] ) );
		$this->assertTrue( $result['marked'] );
		$this->assertSame( BlockThemeProjectValidator::MARKER_SCHEMA_VERSION, $result['project_version'] );
		$this->assertSame( [], $result['errors'] );
	}

	/**
	 * Sanitized empty stylesheet values return the documented validation error.
	 */
	public function test_run_rejects_an_invalid_stylesheet(): void {
		$result = $this->ability()->run( [ 'stylesheet' => '!!!' ] );

		if ( ! $result instanceof WP_Error ) {
			$this->fail( 'Expected an invalid stylesheet to return WP_Error.' );
		}
		$this->assertSame( 'sd_ai_agent_invalid_stylesheet', $result->get_error_code() );
	}

	/**
	 * Create and track a unique valid WordPress theme stylesheet slug.
	 */
	private function unique_slug(): string {
		$slug                  = 'sd-ai-validator-ability-' . strtolower( wp_generate_password( 8, false ) );
		$this->created_slugs[] = $slug;
		return $slug;
	}

	/**
	 * Stage a valid marked project within the installed themes directory.
	 *
	 * @param string $slug Theme directory name.
	 */
	private function stage_valid_theme( string $slug ): void {
		$theme_dir = trailingslashit( get_theme_root() ) . $slug;
		wp_mkdir_p( $theme_dir . '/templates' );
		wp_mkdir_p( $theme_dir . '/.sd-ai-agent' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup requires direct local writes.
		file_put_contents( $theme_dir . '/.sd-ai-agent/block-theme-project.json', BlockThemeProjectValidator::marker_contents() );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup requires direct local writes.
		file_put_contents( $theme_dir . '/style.css', "/*\nTheme Name: Ability validator fixture\nVersion: 1.0.0\n*/\n" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup requires direct local writes.
		file_put_contents( $theme_dir . '/theme.json', "{\n\t\"\$schema\": \"https://schemas.wp.org/trunk/theme.json\",\n\t\"version\": 3,\n\t\"settings\": {}\n}\n" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup requires direct local writes.
		file_put_contents( $theme_dir . '/templates/index.html', '<!-- wp:paragraph --><p class="wp-block-paragraph">Welcome.</p><!-- /wp:paragraph -->' );
	}

	/**
	 * Recursively delete a fixture directory.
	 *
	 * @param string $dir Absolute directory path.
	 */
	private static function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: [] as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) ) {
				self::rrmdir( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}
}
