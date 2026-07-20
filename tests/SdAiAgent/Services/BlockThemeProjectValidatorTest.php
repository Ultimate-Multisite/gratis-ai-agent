<?php

declare(strict_types=1);
/**
 * Tests for read-only generated block-theme project validation.
 *
 * @package SdAiAgent\Tests\Services
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Services;

use SdAiAgent\Core\BlockValidatorBridge;
use SdAiAgent\Services\BlockThemeProjectValidator;
use WP_UnitTestCase;

/**
 * Verifies deterministic, bounded validation of generated theme projects.
 */
class BlockThemeProjectValidatorTest extends WP_UnitTestCase {

	/**
	 * Disposable project root.
	 */
	private string $theme_dir;

	public function setUp(): void {
		parent::setUp();
		$this->theme_dir = trailingslashit( get_theme_root() ) . 'sd-ai-validator-' . strtolower( wp_generate_password( 8, false ) );
		wp_mkdir_p( $this->theme_dir );
	}

	public function tear_down(): void {
		BlockValidatorBridge::reset_memory_cache();
		self::rrmdir( $this->theme_dir );
		parent::tear_down();
	}

	/**
	 * A complete marked project produces a stable, empty diagnostic report.
	 */
	public function test_valid_marked_project_returns_a_deterministic_relative_report(): void {
		$this->stage_valid_project();
		$validator = new BlockThemeProjectValidator();

		$first  = $validator->validate( $this->theme_dir );
		$second = $validator->validate( $this->theme_dir );

		$this->assertTrue( $first['valid'], (string) wp_json_encode( $first['errors'] ) );
		$this->assertTrue( $first['marked'] );
		$this->assertSame( BlockThemeProjectValidator::MARKER_SCHEMA_VERSION, $first['project_version'] );
		$this->assertSame( [], $first['errors'] );
		$this->assertSame( [], $first['warnings'] );
		$this->assertGreaterThanOrEqual( 4, $first['files_scanned'] );
		$this->assertSame( $first, $second, 'Repeated read-only validation must produce an identical report.' );
	}

	/**
	 * Browser-posted block validation cache entries must not affect generated
	 * project diagnostics or the activation gate that consumes them.
	 */
	public function test_ignores_browser_cached_block_validation_results(): void {
		$this->stage_valid_project();
		$contents = "<!-- wp:heading {\"level\":3} -->\n<h2 class=\"wp-block-heading\">Incorrect heading</h2>\n<!-- /wp:heading -->";
		$this->write_fixture_file( 'templates/index.html', $contents );
		$validator = new BlockThemeProjectValidator();
		$expected  = $validator->validate( $this->theme_dir );

		BlockValidatorBridge::store(
			$contents,
			[
				'totalBlocks'   => 1,
				'validBlocks'   => 1,
				'invalidBlocks' => 0,
				'results'       => [
					[
						'blockName'       => 'core/heading',
						'isValid'         => true,
						'issues'          => [],
						'originalContent' => '<h2 class="wp-block-heading">Incorrect heading</h2>',
						'expectedContent' => '<h2 class="wp-block-heading">Incorrect heading</h2>',
					],
				],
				'source'        => 'js',
			]
		);

		try {
			$actual = $validator->validate( $this->theme_dir );
		} finally {
			BlockValidatorBridge::reset_memory_cache();
			delete_transient( BlockValidatorBridge::TRANSIENT_PREFIX . hash( 'sha256', $contents ) );
		}

		$this->assertFalse( $actual['valid'] );
		$this->assertContains( 'invalid_block_markup', array_column( $actual['errors'], 'code' ) );
		$this->assertSame( $expected, $actual );
	}

	/**
	 * Diagnostics stay theme-relative and report common unsafe generated content.
	 */
	public function test_reports_relative_diagnostics_for_placeholder_and_remote_asset_content(): void {
		$this->stage_valid_project();
		$this->write_fixture_file(
			'templates/index.html',
			'<!-- wp:paragraph --><p>Replace this text.</p><!-- /wp:paragraph --><img src="https://cdn.invalid/hero.jpg" alt="">'
		);

		$report = ( new BlockThemeProjectValidator() )->validate( $this->theme_dir );
		$codes  = array_column( $report['errors'], 'code' );

		$this->assertFalse( $report['valid'] );
		$this->assertContains( 'placeholder_content', $codes );
		$this->assertContains( 'remote_asset_url', $codes );

		foreach ( $report['errors'] as $diagnostic ) {
			$this->assertStringNotContainsString( $this->theme_dir, $diagnostic['path'] );
		}
	}

	/**
	 * A malformed marker without a schema version reports a diagnostic without
	 * producing a PHP undefined-array-key warning.
	 */
	public function test_missing_marker_schema_version_uses_zero_project_version(): void {
		$this->stage_valid_project();
		$this->write_fixture_file(
			BlockThemeProjectValidator::MARKER_PATH,
			"{\n\t\"generator\": \"sd-ai-agent\",\n\t\"generator_version\": \"1.0.0\",\n\t\"validation_version\": 1\n}\n"
		);

		$report = ( new BlockThemeProjectValidator() )->validate( $this->theme_dir );

		$this->assertFalse( $report['valid'] );
		$this->assertSame( 0, $report['project_version'] );
		$this->assertContains( 'unknown_marker_version', array_column( $report['errors'], 'code' ) );
	}

	/**
	 * Internal generated-project metadata is not theme source and must not
	 * produce asset diagnostics from preserved release metadata.
	 */
	public function test_ignores_internal_project_metadata_during_text_source_validation(): void {
		$this->stage_valid_project();
		$this->write_fixture_file(
			'.sd-ai-agent/design-artifacts/manifest.json',
			'{"url":"https://cdn.invalid/release.json","src":"https://cdn.invalid/release.css"}'
		);

		$report = ( new BlockThemeProjectValidator() )->validate( $this->theme_dir );

		$this->assertTrue( $report['valid'], (string) wp_json_encode( $report['errors'] ) );
		$this->assertNotContains( 'remote_asset_url', array_column( $report['errors'], 'code' ) );
	}

	/**
	 * Filesystem pattern PHP is inspected as text and never evaluated.
	 */
	public function test_never_executes_pattern_php_while_reporting_executable_content(): void {
		$this->stage_valid_project();
		$this->write_fixture_file(
			'patterns/static.php',
			"<?php\n/**\n * Title: Static pattern\n * Slug: " . basename( $this->theme_dir ) . "/static\n */\n?>\n<?php throw new \\RuntimeException( 'must not execute' ); ?>\n<!-- wp:paragraph --><p>Static content.</p><!-- /wp:paragraph -->\n"
		);

		$report = ( new BlockThemeProjectValidator() )->validate( $this->theme_dir );
		$codes  = array_column( $report['errors'], 'code' );

		$this->assertFalse( $report['valid'] );
		$this->assertContains( 'executable_pattern_content', $codes );
	}

	/**
	 * Malformed CSS var() syntax is rejected consistently in root documents,
	 * style variations, and text sources while valid fallbacks remain accepted.
	 */
	public function test_reports_malformed_css_variable_references_in_governed_theme_sources(): void {
		$this->stage_valid_project();
		$this->write_fixture_file(
			'theme.json',
			"{\n\t\"\$schema\": \"https://schemas.wp.org/trunk/theme.json\",\n\t\"version\": 3,\n\t\"settings\": {\n\t\t\"custom\": { \"brand\": \"#123456\" }\n\t},\n\t\"styles\": { \"color\": { \"text\": \"var(--wp--custom--brand\" } }\n}\n"
		);
		$this->write_fixture_file(
			'styles/malformed.json',
			"{\n\t\"\$schema\": \"https://schemas.wp.org/trunk/theme.json\",\n\t\"version\": 3,\n\t\"slug\": \"malformed\",\n\t\"title\": \"Malformed\",\n\t\"settings\": {},\n\t\"styles\": { \"color\": { \"text\": \"var()\" } }\n}\n"
		);
		$this->write_fixture_file( 'templates/index.html', '<!-- wp:paragraph --><p style="color:var(--wp--custom--)">Welcome.</p><!-- /wp:paragraph -->' );
		$this->write_fixture_file( 'assets/theme.css', '.example { color: var(--wp--custom--brand, #123456); }' );

		$report      = ( new BlockThemeProjectValidator() )->validate( $this->theme_dir );
		$diagnostics = array_values(
			array_filter(
				$report['errors'],
				static fn( array $diagnostic ): bool => 'malformed_css_variable_reference' === $diagnostic['code']
			)
		);
		$diagnostics_by_path = array_column( $diagnostics, null, 'path' );
		ksort( $diagnostics_by_path );
		$diagnostic_reasons = [];
		foreach ( $diagnostics_by_path as $path => $diagnostic ) {
			$diagnostic_reasons[ $path ] = $diagnostic['location']['reason'];
		}

		$this->assertFalse( $report['valid'] );
		$this->assertSame(
			[
				'styles/malformed.json' => 'empty_variable_name',
				'templates/index.html'  => 'invalid_wordpress_token_name',
				'theme.json'            => 'missing_closing_parenthesis',
			],
			$diagnostic_reasons
		);
		$this->assertNotContains( 'assets/theme.css', array_column( $diagnostics, 'path' ) );
	}

	/**
	 * Stage the smallest complete generated block-theme project.
	 */
	private function stage_valid_project(): void {
		$this->write_fixture_file( BlockThemeProjectValidator::MARKER_PATH, BlockThemeProjectValidator::marker_contents() );
		$this->write_fixture_file(
			'style.css',
			"/*\nTheme Name: Validator fixture\nVersion: 1.0.0\n*/\n"
		);
		$this->write_fixture_file(
			'theme.json',
			"{\n\t\"\$schema\": \"https://schemas.wp.org/trunk/theme.json\",\n\t\"version\": 3,\n\t\"settings\": {}\n}\n"
		);
		$this->write_fixture_file(
			'templates/index.html',
			'<!-- wp:paragraph --><p class="wp-block-paragraph">Welcome.</p><!-- /wp:paragraph -->'
		);
	}

	/**
	 * Write a fixture file beneath the disposable project root.
	 *
	 * @param string $relative Theme-relative fixture path.
	 * @param string $contents Fixture contents.
	 */
	private function write_fixture_file( string $relative, string $contents ): void {
		$path = $this->theme_dir . '/' . ltrim( $relative, '/' );
		wp_mkdir_p( dirname( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup requires direct local writes.
		$this->assertNotFalse( file_put_contents( $path, $contents ) );
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
