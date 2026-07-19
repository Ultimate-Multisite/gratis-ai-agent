<?php

declare(strict_types=1);
/**
 * Tests for transactional generated design artifact releases.
 *
 * @package SdAiAgent\Tests\DesignSystem
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\DesignSystem;

use SdAiAgent\DesignSystem\ArtifactManifest;
use SdAiAgent\DesignSystem\ArtifactReleaseManager;
use WP_UnitTestCase;

/**
 * Covers staging, compensation, safe ownership, and exact retained rollback.
 */
class ArtifactReleaseManagerTest extends WP_UnitTestCase {

	/**
	 * Temporary theme directory used for direct release-manager tests.
	 */
	private string $themeDir;

	/**
	 * Set up a disposable theme root without registering it as a user theme.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->themeDir = trailingslashit( get_theme_root() ) . 'sd-ai-artifact-' . strtolower( wp_generate_password( 8, false ) );
		wp_mkdir_p( $this->themeDir );
	}

	/**
	 * Remove all generated local files and manager-owned test records.
	 */
	public function tear_down(): void {
		self::rrmdir( $this->themeDir );
		foreach ( [ 'wp_block', 'wp_global_styles' ] as $post_type ) {
			$posts = get_posts(
				[
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'meta_key'       => '_sd_ai_agent_design_artifact_record',
					'no_found_rows'  => true,
				]
			);
			foreach ( $posts as $post ) {
				wp_delete_post( $post->ID, true );
			}
		}
		parent::tear_down();
	}

	/**
	 * Releases stage file and manager-owned wp_block writes, preserving ordinary records.
	 */
	public function test_apply_materializes_declared_targets_without_touching_user_pattern(): void {
		$user_pattern = wp_insert_post(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Ordinary user pattern',
				'post_name'    => 'ordinary-user-pattern',
				'post_content' => '<!-- wp:paragraph --><p>User owned.</p><!-- /wp:paragraph -->',
			],
			true
		);
		$this->assertNotWPError( $user_pattern );

		$result = ( new ArtifactReleaseManager() )->apply(
			$this->themeDir,
			$this->manifest( [ $this->pattern_artifact( '1.0.0', 'Version one' ) ] ),
			$this->context()
		);

		$this->assertNotWPError( $result );
		$this->assertFalse( $result['no_op'] );
		$this->assertFileExists( $this->themeDir . '/patterns/hero.php' );
		$this->assertStringContainsString( 'Version one', (string) file_get_contents( $this->themeDir . '/patterns/hero.php' ) );
		$this->assertNotEmpty( $this->managed_records( 'wp_block' ) );
		$this->assertSame( 'Ordinary user pattern', get_post( $user_pattern )->post_title );
		$this->assertFileExists( $this->themeDir . '/.sd-ai-agent/design-artifacts/active.json' );
	}

	/**
	 * A failure after a file write restores the exact prior manager-owned release.
	 */
	public function test_injected_file_failure_restores_manager_owned_file(): void {
		$initial = ( new ArtifactReleaseManager() )->apply(
			$this->themeDir,
			$this->manifest( [ $this->pattern_artifact( '1.0.0', 'Initial managed release' ) ] ),
			$this->context()
		);
		$this->assertNotWPError( $initial );

		$manager = new ArtifactReleaseManager(
			null,
			static fn( string $step, string $target ): bool => 'after_file_write' === $step && 'patterns/hero.php' === $target
		);
		$result  = $manager->apply(
			$this->themeDir,
			$this->manifest( [ $this->pattern_artifact( '1.1.0', 'Should roll back' ) ] ),
			$this->context()
		);

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'Initial managed release', (string) file_get_contents( $this->themeDir . '/patterns/hero.php' ) );
		$this->assertSame( $initial['release_id'], json_decode( (string) file_get_contents( $this->themeDir . '/.sd-ai-agent/design-artifacts/active.json' ), true )['release_id'] );
	}

	/**
	 * An initial release must not silently claim a user-authored theme.json file.
	 */
	public function test_apply_refuses_to_overwrite_unmanaged_theme_json(): void {
		$path = $this->themeDir . '/theme.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Fixture represents a user-authored theme customization.
		file_put_contents( $path, '{"version":3,"settings":{"custom":{"userOwned":true}}}' );

		$result = ( new ArtifactReleaseManager() )->apply(
			$this->themeDir,
			$this->manifest( [ $this->token_artifact( '1.0.0', '#112233' ) ] ),
			$this->context()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_design_artifact_unmanaged_file_conflict', $result->get_error_code() );
		$this->assertSame( '{"version":3,"settings":{"custom":{"userOwned":true}}}', file_get_contents( $path ) );
		$this->assertFileDoesNotExist( $this->themeDir . '/.sd-ai-agent/design-artifacts/active.json' );
	}

	/**
	 * Failures after wp_block and wp_global_styles writes compensate every prior mutation.
	 */
	public function test_injected_record_failures_restore_wp_block_and_global_styles_state(): void {
		$block_manager = new ArtifactReleaseManager(
			null,
			static fn( string $step, string $target ): bool => 'after_record_write' === $step
		);
		$block_result  = $block_manager->apply(
			$this->themeDir,
			$this->manifest( [ $this->pattern_artifact( '1.0.0', 'Block rollback' ) ] ),
			$this->context()
		);
		$this->assertWPError( $block_result );
		$this->assertFileDoesNotExist( $this->themeDir . '/patterns/hero.php' );
		$this->assertSame( [], $this->managed_records( 'wp_block' ) );

		$styles_dir = $this->themeDir . '-styles';
		wp_mkdir_p( $styles_dir );
		$styles_manager = new ArtifactReleaseManager(
			null,
			static fn( string $step, string $target ): bool => 'after_record_write' === $step
		);
		$styles_result  = $styles_manager->apply(
			$styles_dir,
			$this->manifest( [ $this->token_artifact( '1.0.0', '#112233' ) ] ),
			$this->context()
		);
		$this->assertWPError( $styles_result );
		$this->assertFileDoesNotExist( $styles_dir . '/theme.json' );
		$this->assertSame( [], $this->managed_records( 'wp_global_styles' ) );
		self::rrmdir( $styles_dir );
	}

	/**
	 * Compensation restores a removed managed record with its original post ID.
	 */
	public function test_compensation_restores_deleted_managed_record_with_original_id(): void {
		$initial = ( new ArtifactReleaseManager() )->apply(
			$this->themeDir,
			$this->manifest( [ $this->pattern_artifact( '1.0.0', 'Original managed record' ) ] ),
			$this->context()
		);
		$this->assertNotWPError( $initial );
		$records     = $this->managed_records( 'wp_block' );
		$original_id = (int) $records[0]->ID;

		$manager = new ArtifactReleaseManager(
			null,
			static fn( string $step, string $target ): bool => 'after_record_delete' === $step && 'sd-ai-agent/pattern/hero:synced-hero' === $target
		);
		$result  = $manager->apply(
			$this->themeDir,
			$this->manifest( [ $this->pattern_artifact( '1.1.0', 'Recordless update', false ) ] ),
			$this->context()
		);

		$this->assertWPError( $result );
		$restored = get_post( $original_id );
		$this->assertInstanceOf( \WP_Post::class, $restored );
		$this->assertSame( 'Original managed record', $restored->post_title );
		$this->assertSame( $original_id, (int) $this->managed_records( 'wp_block' )[0]->ID );
	}

	/**
	 * Exact retained rollback works after a later registry has replaced the active release.
	 */
	public function test_rolls_back_exact_retained_release_after_newer_registry_entry(): void {
		$manager = new ArtifactReleaseManager();
		$first   = $manager->apply(
			$this->themeDir,
			$this->manifest( [ $this->pattern_artifact( '1.0.0', 'First retained release' ) ] ),
			$this->context()
		);
		$this->assertNotWPError( $first );

		$second = $manager->apply(
			$this->themeDir,
			$this->manifest( [ $this->pattern_artifact( '1.1.0', 'Newer registry release' ) ] ),
			$this->context()
		);
		$this->assertNotWPError( $second );
		$this->assertStringContainsString( 'Newer registry release', (string) file_get_contents( $this->themeDir . '/patterns/hero.php' ) );

		$rollback = $manager->rollback( $this->themeDir, $first['release_id'] );
		$this->assertNotWPError( $rollback );
		$this->assertSame( $first['release_id'], $rollback['release_id'] );
		$this->assertStringContainsString( 'First retained release', (string) file_get_contents( $this->themeDir . '/patterns/hero.php' ) );
	}

	/**
	 * Build a generated pattern that writes an artifact-owned pattern file and wp_block record.
	 *
	 * @return array<string,mixed> Valid artifact.
	 */
	private function pattern_artifact( string $version, string $label, bool $include_record = true ): array {
		$artifact = ArtifactManifest::create_artifact(
			[
				'id'            => 'sd-ai-agent/pattern/hero',
				'kind'          => 'pattern',
				'version'       => $version,
				'maturity'      => 'stable',
				'provenance'    => $this->provenance( $version ),
				'compatibility' => $this->compatibility(),
				'payload'       => [
					'files'   => [
						[
							'path'    => 'patterns/hero.php',
							'content' => "<?php\n/** {$label} */\n",
						],
					],
					'records' => $include_record ? [
						[
							'id'           => 'synced-hero',
							'post_type'    => 'wp_block',
							'post_title'   => $label,
							'post_name'    => 'generated-hero',
							'post_content' => '<!-- wp:paragraph --><p>' . $label . '</p><!-- /wp:paragraph -->',
						],
					] : [],
				],
			]
		);
		$this->assertNotWPError( $artifact );

		return $artifact;
	}

	/**
	 * Build a generated token set that writes theme.json and a managed global-style record.
	 *
	 * @return array<string,mixed> Valid artifact.
	 */
	private function token_artifact( string $version, string $color ): array {
		$artifact = ArtifactManifest::create_artifact(
			[
				'id'            => 'sd-ai-agent/token_set/brand',
				'kind'          => 'token_set',
				'version'       => $version,
				'maturity'      => 'stable',
				'provenance'    => $this->provenance( 'token-' . $version ),
				'compatibility' => $this->compatibility(),
				'payload'       => [
					'files'   => [
						[
							'path'    => 'theme.json',
							'content' => wp_json_encode( [ 'version' => 3, 'settings' => [ 'color' => [ 'palette' => [ [ 'slug' => 'brand', 'color' => $color ] ] ] ] ] ),
						],
					],
					'records' => [
						[
							'id'           => 'brand-settings',
							'post_type'    => 'wp_global_styles',
							'post_title'   => 'Generated brand settings',
							'post_name'    => 'generated-brand-settings',
							'post_content' => wp_json_encode( [ 'version' => 3, 'settings' => [ 'color' => [ 'palette' => [ [ 'slug' => 'brand', 'color' => $color ] ] ] ] ] ),
						],
					],
				],
			]
		);
		$this->assertNotWPError( $artifact );

		return $artifact;
	}

	/**
	 * Build shared strict provenance.
	 *
	 * @return array<string,string> Provenance.
	 */
	private function provenance( string $source ): array {
		return [
			'generator_version' => '1.0.0',
			'source_type'       => 'generated',
			'source_reference'  => 'release-test-' . $source,
			'generated_at'      => '2026-07-18T00:00:00Z',
			'input_hash'        => hash( 'sha256', $source ),
		];
	}

	/**
	 * Build shared conservative compatibility.
	 *
	 * @return array<string,mixed> Compatibility.
	 */
	private function compatibility(): array {
		return [
			'wordpress'        => [ 'min' => '7.0', 'max' => null ],
			'theme_json'       => [ 'min' => 3, 'max' => 3 ],
			'required_blocks'   => [],
			'required_features' => [],
			'theme_constraints' => [],
		];
	}

	/**
	 * Wrap artifacts in a v1 manifest.
	 *
	 * @param list<array<string,mixed>> $artifacts Artifacts.
	 * @return array<string,mixed> Manifest.
	 */
	private function manifest( array $artifacts ): array {
		return [
			'schema_version' => ArtifactManifest::SCHEMA_VERSION,
			'theme'          => [ 'stylesheet' => 'sd-ai-artifact-test' ],
			'artifacts'      => $artifacts,
		];
	}

	/**
	 * Build deterministic resolver context.
	 *
	 * @return array<string,mixed> Context.
	 */
	private function context(): array {
		return [
			'wordpress_version' => '7.0',
			'theme_json_version' => 3,
			'blocks'            => [],
			'features'          => [],
		];
	}

	/**
	 * Retrieve manager-owned records without selecting user-authored rows.
	 *
	 * @return list<\WP_Post> Managed records.
	 */
	private function managed_records( string $post_type ): array {
		return get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_key'       => '_sd_ai_agent_design_artifact_record',
				'no_found_rows'  => true,
			]
		);
	}

	/**
	 * Recursively remove a test-only theme directory.
	 */
	private static function rrmdir( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- Cleaning up a test-only temporary theme directory.
		$entries = scandir( $directory );
		if ( false === $entries ) {
			return;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $directory . '/' . $entry;
			if ( is_dir( $path ) ) {
				self::rrmdir( $path );
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleaning up a test-only temporary theme file.
			unlink( $path );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir -- Removing a test-only temporary theme directory.
		rmdir( $directory );
	}
}
