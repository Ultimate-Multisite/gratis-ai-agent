<?php

declare(strict_types=1);
/**
 * Unit tests for ScaffoldBlockThemeAbility — theme.json schema version
 * coercion (GH#1511).
 *
 * Covers the server-side guardrail that normalises any caller-supplied
 * theme_json with version < 3 to version 3, which is the only correct
 * value for this plugin's minimum requirement of WordPress 7.0+.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\ScaffoldBlockThemeAbility;
use WP_UnitTestCase;

/**
 * Test ScaffoldBlockThemeAbility version-coercion behaviour.
 */
class ScaffoldBlockThemeAbilityTest extends WP_UnitTestCase {

	/**
	 * Slugs created during tests, removed in tearDown.
	 *
	 * @var array<int,string>
	 */
	private array $created_slugs = [];

	public function tearDown(): void {
		foreach ( $this->created_slugs as $slug ) {
			$dir = trailingslashit( get_theme_root() ) . $slug;
			if ( is_dir( $dir ) ) {
				self::rrmdir( $dir );
			}
		}

		parent::tearDown();
	}

	// ── version coercion ──────────────────────────────────────────────────

	/**
	 * When the caller supplies a theme_json with version 2, the on-disk
	 * theme.json must have version 3.
	 *
	 * This is the primary regression test for GH#1511: the agent was
	 * emitting version 2 in its tool_use input; the server-side guardrail
	 * must silently normalise this before writing the file.
	 */
	public function test_scaffold_coerces_version_2_to_3_on_disk(): void {
		$slug    = $this->unique_slug( 'coerce-v2' );
		$ability = new ScaffoldBlockThemeAbility( 'sd-ai-agent/scaffold-block-theme' );

		$result = $ability->run(
			[
				'slug'       => $slug,
				'name'       => 'Coerce V2 Theme',
				'theme_json' => [
					'$schema'  => 'https://schemas.wp.org/trunk/theme.json',
					'version'  => 2,
					'settings' => [
						'appearanceTools' => true,
					],
				],
			]
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$theme_dir  = trailingslashit( get_theme_root() ) . $slug;
		$theme_json = json_decode( (string) file_get_contents( $theme_dir . '/theme.json' ), true );

		$this->assertIsArray( $theme_json );
		$this->assertSame( 3, $theme_json['version'], 'On-disk theme.json must have version 3 when caller supplied version 2.' );
	}

	/**
	 * When the caller supplies version 2, the result array must report
	 * version_coerced = true for change-log transparency.
	 */
	public function test_scaffold_v2_sets_version_coerced_flag(): void {
		$slug    = $this->unique_slug( 'coerce-flag' );
		$ability = new ScaffoldBlockThemeAbility( 'sd-ai-agent/scaffold-block-theme' );

		$result = $ability->run(
			[
				'slug'       => $slug,
				'name'       => 'Coerce Flag Theme',
				'theme_json' => [
					'$schema' => 'https://schemas.wp.org/trunk/theme.json',
					'version' => 2,
				],
			]
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( $result['version_coerced'], 'version_coerced must be true when the input version was 2.' );
	}

	/**
	 * When the caller supplies a theme_json with version 3, the on-disk
	 * theme.json must also have version 3 and must not be altered.
	 */
	public function test_scaffold_preserves_version_3_unchanged(): void {
		$slug    = $this->unique_slug( 'keep-v3' );
		$ability = new ScaffoldBlockThemeAbility( 'sd-ai-agent/scaffold-block-theme' );

		$result = $ability->run(
			[
				'slug'       => $slug,
				'name'       => 'Keep V3 Theme',
				'theme_json' => [
					'$schema'  => 'https://schemas.wp.org/trunk/theme.json',
					'version'  => 3,
					'settings' => [
						'color' => [
							'palette' => [
								[
									'slug'  => 'brand',
									'name'  => 'Brand',
									'color' => '#123456',
								],
							],
						],
					],
				],
			]
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$theme_dir  = trailingslashit( get_theme_root() ) . $slug;
		$theme_json = json_decode( (string) file_get_contents( $theme_dir . '/theme.json' ), true );

		$this->assertIsArray( $theme_json );
		$this->assertSame( 3, $theme_json['version'], 'Version 3 input must remain version 3 on disk.' );
		// Custom content must survive unchanged.
		$this->assertSame( '#123456', $theme_json['settings']['color']['palette'][0]['color'] );
	}

	/**
	 * When the caller supplies version 3, version_coerced must be false.
	 */
	public function test_scaffold_v3_does_not_set_version_coerced_flag(): void {
		$slug    = $this->unique_slug( 'no-coerce' );
		$ability = new ScaffoldBlockThemeAbility( 'sd-ai-agent/scaffold-block-theme' );

		$result = $ability->run(
			[
				'slug'       => $slug,
				'name'       => 'No Coerce Theme',
				'theme_json' => [
					'$schema' => 'https://schemas.wp.org/trunk/theme.json',
					'version' => 3,
				],
			]
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertFalse( $result['version_coerced'], 'version_coerced must be false when the input version was already 3.' );
	}

	/**
	 * When theme_json is omitted the default scaffold writes version 3.
	 *
	 * Verifies that ScaffoldBlockThemeAbility::default_theme_json() has not
	 * regressed to an older version.
	 */
	public function test_scaffold_default_theme_json_is_version_3(): void {
		$slug    = $this->unique_slug( 'default-v3' );
		$ability = new ScaffoldBlockThemeAbility( 'sd-ai-agent/scaffold-block-theme' );

		$result = $ability->run(
			[
				'slug' => $slug,
				'name' => 'Default V3 Theme',
			]
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$theme_dir  = trailingslashit( get_theme_root() ) . $slug;
		$theme_json = json_decode( (string) file_get_contents( $theme_dir . '/theme.json' ), true );

		$this->assertIsArray( $theme_json );
		$this->assertSame( 3, $theme_json['version'], 'Default scaffold must write version 3.' );
		$this->assertFalse( $result['version_coerced'], 'version_coerced must be false when theme_json was omitted (default path).' );
	}

	// ── front-page CTA ───────────────────────────────────────────────────

	/**
	 * The scaffold must write templates/front-page.html and include it in
	 * the returned files list.
	 */
	public function test_scaffold_writes_front_page_template(): void {
		$slug    = $this->unique_slug( 'fp-written' );
		$ability = new ScaffoldBlockThemeAbility( 'sd-ai-agent/scaffold-block-theme' );

		$result = $ability->run( [ 'slug' => $slug, 'name' => 'FP Written Theme' ] );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertContains(
			'templates/front-page.html',
			$result['files'],
			'scaffold files list must include templates/front-page.html'
		);

		$theme_dir = trailingslashit( get_theme_root() ) . $slug;
		$this->assertFileExists( $theme_dir . '/templates/front-page.html', 'templates/front-page.html must exist on disk after scaffold.' );
	}

	/**
	 * The scaffolded front-page.html must include a wp:button block slot so
	 * the agent can fill in the real CTA URL via file-write.
	 *
	 * This is the primary regression test for GH#1525.
	 */
	public function test_scaffold_front_page_has_button_block_slot(): void {
		$slug    = $this->unique_slug( 'fp-slot' );
		$ability = new ScaffoldBlockThemeAbility( 'sd-ai-agent/scaffold-block-theme' );

		$result = $ability->run( [ 'slug' => $slug, 'name' => 'FP Slot Theme' ] );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$theme_dir  = trailingslashit( get_theme_root() ) . $slug;
		$front_page = (string) file_get_contents( $theme_dir . '/templates/front-page.html' );

		$this->assertStringContainsString(
			'<!-- wp:button',
			$front_page,
			'Scaffold front-page.html must include a wp:button block slot for the hero CTA.'
		);
		$this->assertStringContainsString(
			'wp-block-button__link',
			$front_page,
			'Scaffold front-page.html must contain an anchor with wp-block-button__link class.'
		);
	}

	/**
	 * The scaffold must return cta_warning=true to signal the agent that the
	 * front-page hero CTA is a placeholder that requires replacement.
	 */
	public function test_scaffold_returns_cta_warning_true(): void {
		$slug    = $this->unique_slug( 'cta-warning' );
		$ability = new ScaffoldBlockThemeAbility( 'sd-ai-agent/scaffold-block-theme' );

		$result = $ability->run( [ 'slug' => $slug, 'name' => 'CTA Warning Theme' ] );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue(
			$result['cta_warning'],
			'cta_warning must be true for a freshly scaffolded theme whose front-page CTA is still a placeholder.'
		);
	}

	/**
	 * validate_front_page_has_cta() must return false when the only button
	 * link present uses the placeholder href="#".
	 */
	public function test_validate_front_page_cta_false_for_placeholder_href(): void {
		$html = '<div class="wp-block-button">'
			. '<a class="wp-block-button__link wp-element-button" href="#">Call to action</a>'
			. '</div>';

		$this->assertFalse(
			ScaffoldBlockThemeAbility::validate_front_page_has_cta( $html ),
			'validate_front_page_has_cta() must return false when href="#" (placeholder).'
		);
	}

	/**
	 * validate_front_page_has_cta() must return false when the only button
	 * link has an empty href.
	 */
	public function test_validate_front_page_cta_false_for_empty_href(): void {
		$html = '<div class="wp-block-button">'
			. '<a class="wp-block-button__link wp-element-button" href="">Order now</a>'
			. '</div>';

		$this->assertFalse(
			ScaffoldBlockThemeAbility::validate_front_page_has_cta( $html ),
			'validate_front_page_has_cta() must return false when href is empty.'
		);
	}

	/**
	 * validate_front_page_has_cta() must return true when the button link
	 * has a real page URL.
	 */
	public function test_validate_front_page_cta_true_for_real_link(): void {
		$html = '<div class="wp-block-button">'
			. '<a class="wp-block-button__link wp-element-button" href="/menu/">View menu</a>'
			. '</div>';

		$this->assertTrue(
			ScaffoldBlockThemeAbility::validate_front_page_has_cta( $html ),
			'validate_front_page_has_cta() must return true for a real page URL.'
		);
	}

	/**
	 * validate_front_page_has_cta() must return false when there is no
	 * button link in the HTML at all.
	 */
	public function test_validate_front_page_cta_false_when_no_button(): void {
		$html = '<h1>Welcome</h1><p>Tell visitors what makes you special.</p>';

		$this->assertFalse(
			ScaffoldBlockThemeAbility::validate_front_page_has_cta( $html ),
			'validate_front_page_has_cta() must return false when no button is present.'
		);
	}

	/**
	 * validate_front_page_has_cta() must return true when multiple buttons
	 * are present and at least one has a real URL.
	 */
	public function test_validate_front_page_cta_true_when_one_of_many_is_real(): void {
		$html = '<div class="wp-block-button">'
			. '<a class="wp-block-button__link wp-element-button" href="#">Placeholder</a>'
			. '</div>'
			. '<div class="wp-block-button">'
			. '<a class="wp-block-button__link wp-element-button" href="/shop/">Shop now</a>'
			. '</div>';

		$this->assertTrue(
			ScaffoldBlockThemeAbility::validate_front_page_has_cta( $html ),
			'validate_front_page_has_cta() must return true when at least one button link is real.'
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Generate a unique theme slug and register it for tearDown cleanup.
	 *
	 * @param string $prefix Short label embedded in the slug for log clarity.
	 * @return string
	 */
	private function unique_slug( string $prefix ): string {
		$slug                  = 'sd-ai-test-' . $prefix . '-' . strtolower( wp_generate_password( 8, false ) );
		$this->created_slugs[] = $slug;
		return $slug;
	}

	/**
	 * Recursively delete a directory.
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
