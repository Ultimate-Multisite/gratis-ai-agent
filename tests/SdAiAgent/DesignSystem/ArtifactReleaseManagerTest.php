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
	 * Artifact paths cannot use a symbolic-link parent to escape the selected theme.
	 */
	public function test_apply_refuses_symbolic_linked_artifact_parent(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'Symbolic links are unavailable in this PHP runtime.' );
		}
		$outside_directory = $this->themeDir . '-outside';
		$patterns_link     = $this->themeDir . '/patterns';
		wp_mkdir_p( $outside_directory );

		try {
			$this->assertTrue( symlink( $outside_directory, $patterns_link ) );
			$result = ( new ArtifactReleaseManager() )->apply(
				$this->themeDir,
				$this->manifest( [ $this->pattern_artifact( '1.0.0', 'Symlink escape' ) ] ),
				$this->context()
			);

			$this->assertWPError( $result );
			$this->assertSame( 'sd_ai_agent_design_artifact_unsafe_path', $result->get_error_code() );
			$this->assertFileDoesNotExist( $outside_directory . '/hero.php' );
		} finally {
			if ( is_link( $patterns_link ) ) {
				unlink( $patterns_link );
			}
			self::rrmdir( $outside_directory );
		}
	}

	/**
	 * A known scaffold baseline can be adopted and restored by an exact release rollback.
	 */
	public function test_adopts_scaffold_baseline_and_restores_it_on_rollback(): void {
		$baseline = '{"version":3,"settings":{"appearanceTools":true}}';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Fixture represents the just-scaffolded managed baseline.
		file_put_contents( $this->themeDir . '/theme.json', $baseline );
		$manager = new ArtifactReleaseManager();
		$seed    = $manager->seed_empty_manifest(
			$this->themeDir,
			'sd-ai-artifact-test',
			[ 'theme.json' => $baseline ]
		);
		$this->assertNotWPError( $seed );

		$first = $manager->apply(
			$this->themeDir,
			$this->manifest( [ $this->pattern_artifact( '1.0.0', 'Baseline pattern' ) ] ),
			$this->context()
		);
		$this->assertNotWPError( $first );
		$this->assertSame( $baseline, file_get_contents( $this->themeDir . '/theme.json' ) );

		$second = $manager->apply(
			$this->themeDir,
			$this->manifest( [ $this->pattern_artifact( '1.0.0', 'Baseline pattern' ), $this->token_artifact( '1.0.0', '#112233' ) ] ),
			$this->context()
		);
		$this->assertNotWPError( $second );
		$this->assertStringContainsString( '#112233', (string) file_get_contents( $this->themeDir . '/theme.json' ) );

		$rollback = $manager->rollback( $this->themeDir, $first['release_id'] );
		$this->assertNotWPError( $rollback );
		$this->assertSame( $baseline, file_get_contents( $this->themeDir . '/theme.json' ) );
	}

	/**
	 * A user edit after scaffolding must stop baseline adoption before mutation.
	 */
	public function test_rejects_modified_scaffold_baseline(): void {
		$baseline = '{"version":3,"settings":{"appearanceTools":true}}';
		$path     = $this->themeDir . '/theme.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Fixture represents the just-scaffolded managed baseline.
		file_put_contents( $path, $baseline );
		$seed = ( new ArtifactReleaseManager() )->seed_empty_manifest(
			$this->themeDir,
			'sd-ai-artifact-test',
			[ 'theme.json' => $baseline ]
		);
		$this->assertNotWPError( $seed );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Fixture represents a user edit after scaffolding.
		file_put_contents( $path, '{"version":3,"settings":{"custom":{"userOwned":true}}}' );

		$result = ( new ArtifactReleaseManager() )->apply(
			$this->themeDir,
			$this->manifest( [ $this->token_artifact( '1.0.0', '#112233' ) ] ),
			$this->context()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_design_artifact_unmanaged_file_conflict', $result->get_error_code() );
		$this->assertSame( '{"version":3,"settings":{"custom":{"userOwned":true}}}', file_get_contents( $path ) );
	}

	/**
	 * A same-content apply still detects an out-of-band managed file edit.
	 */
	public function test_rechecks_manager_owned_files_before_an_idempotent_apply(): void {
		$manifest = $this->manifest( [ $this->pattern_artifact( '1.0.0', 'Original managed release' ) ] );
		$initial  = ( new ArtifactReleaseManager() )->apply( $this->themeDir, $manifest, $this->context() );
		$this->assertNotWPError( $initial );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Fixture represents an out-of-band user edit to an owned file.
		file_put_contents( $this->themeDir . '/patterns/hero.php', 'user change' );

		$result = ( new ArtifactReleaseManager() )->apply( $this->themeDir, $manifest, $this->context() );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_design_artifact_managed_file_modified', $result->get_error_code() );
		$this->assertSame( 'user change', file_get_contents( $this->themeDir . '/patterns/hero.php' ) );
	}

	/**
	 * A forbidden automatic major upgrade cannot implicitly remove the active release.
	 */
	public function test_rejects_implicit_removal_when_only_major_successor_is_available(): void {
		$initial = ( new ArtifactReleaseManager() )->apply(
			$this->themeDir,
			$this->manifest( [ $this->pattern_artifact( '1.0.0', 'Major-one release' ) ] ),
			$this->context()
		);
		$this->assertNotWPError( $initial );

		$result = ( new ArtifactReleaseManager() )->apply(
			$this->themeDir,
			$this->manifest( [ $this->pattern_artifact( '2.0.0', 'Major-two release' ) ] ),
			$this->context()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_design_artifact_active_selection_unresolved', $result->get_error_code() );
		$this->assertStringContainsString( 'Major-one release', (string) file_get_contents( $this->themeDir . '/patterns/hero.php' ) );
	}

	/**
	 * A post-meta failure after wp_insert_post() compensates the partial record write.
	 */
	public function test_compensates_record_persistence_failure_after_partial_write(): void {
		$filter = static function ( $check, $object_id, $meta_key ) {
			return '_sd_ai_agent_design_artifact_record' === $meta_key ? false : $check;
		};
		add_filter( 'update_post_metadata', $filter, 10, 3 );
		$result = ( new ArtifactReleaseManager() )->apply(
			$this->themeDir,
			$this->manifest( [ $this->pattern_artifact( '1.0.0', 'Partial record write' ) ] ),
			$this->context()
		);
		remove_filter( 'update_post_metadata', $filter, 10 );

		$this->assertWPError( $result );
		$this->assertFileDoesNotExist( $this->themeDir . '/patterns/hero.php' );
		$this->assertSame( [], $this->managed_records( 'wp_block' ) );
		$this->assertSame(
			[],
			get_posts(
				[
					'post_type'      => 'wp_block',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					's'              => 'Partial record write',
					'no_found_rows'  => true,
				]
			)
		);
		$this->assertFileDoesNotExist( $this->themeDir . '/.sd-ai-agent/design-artifacts/active.json' );
	}

	/**
	 * Global-style records are bound to the requested theme, not the active stylesheet.
	 */
	public function test_assigns_global_styles_to_the_manifest_target_theme(): void {
		$stylesheet       = 'sd-ai-artifact-inactive';
		$context          = $this->context();
		$context['theme'] = $stylesheet;
		$result           = ( new ArtifactReleaseManager() )->apply(
			$this->themeDir,
			$this->manifest( [ $this->token_artifact( '1.0.0', '#112233' ) ], $stylesheet ),
			$context
		);

		$this->assertNotWPError( $result );
		$this->assertNotSame( get_stylesheet(), $stylesheet );
		$records = $this->managed_records( 'wp_global_styles' );
		$this->assertCount( 1, $records );
		$themes = wp_get_object_terms( $records[0]->ID, 'wp_theme', [ 'fields' => 'names' ] );
		$this->assertNotWPError( $themes );
		$this->assertSame( [ $stylesheet ], $themes );
	}

	/**
	 * Identical artifact records remain independently owned by each target theme.
	 */
	public function test_scopes_record_ownership_keys_to_the_target_theme(): void {
		$other_theme_dir = $this->themeDir . '-other';
		wp_mkdir_p( $other_theme_dir );

		try {
			$first_context          = $this->context();
			$first_context['theme'] = 'sd-ai-artifact-first';
			$first                  = ( new ArtifactReleaseManager() )->apply(
				$this->themeDir,
				$this->manifest( [ $this->token_artifact( '1.0.0', '#112233' ) ], 'sd-ai-artifact-first' ),
				$first_context
			);
			$this->assertNotWPError( $first );

			$second_context          = $this->context();
			$second_context['theme'] = 'sd-ai-artifact-second';
			$second                  = ( new ArtifactReleaseManager() )->apply(
				$other_theme_dir,
				$this->manifest( [ $this->token_artifact( '1.0.0', '#112233' ) ], 'sd-ai-artifact-second' ),
				$second_context
			);
			$this->assertNotWPError( $second );

			$records = $this->managed_records( 'wp_global_styles' );
			$this->assertCount( 2, $records );
			$keys = array_map(
				static fn( \WP_Post $post ): string => (string) get_post_meta( $post->ID, '_sd_ai_agent_design_artifact_record', true ),
				$records
			);
			sort( $keys, SORT_STRING );
			$this->assertSame(
				[
					'sd-ai-artifact-first:sd-ai-agent/token_set/brand:brand-settings',
					'sd-ai-artifact-second:sd-ai-agent/token_set/brand:brand-settings',
				],
				$keys
			);
		} finally {
			self::rrmdir( $other_theme_dir );
		}
	}

	/**
	 * Applying through a target-theme context rejects a manifest for another theme.
	 */
	public function test_rejects_manifest_theme_that_does_not_match_target_context(): void {
		$context          = $this->context();
		$context['theme'] = 'sd-ai-artifact-other-theme';
		$result           = ( new ArtifactReleaseManager() )->apply(
			$this->themeDir,
			$this->manifest( [ $this->pattern_artifact( '1.0.0', 'Wrong target theme' ) ] ),
			$context
		);

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_design_artifact_manifest_theme_mismatch', $result->get_error_code() );
		$this->assertDirectoryDoesNotExist( $this->themeDir . '/.sd-ai-agent' );
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
			static fn( string $step, string $target ): bool => 'after_record_delete' === $step && 'sd-ai-artifact-test:sd-ai-agent/pattern/hero:synced-hero' === $target
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
	 * A retained source must match the release ID derived from its immutable content.
	 */
	public function test_refuses_tampered_retained_release_even_when_inner_hashes_match(): void {
		$manager = new ArtifactReleaseManager();
		$first   = $manager->apply(
			$this->themeDir,
			$this->manifest( [ $this->pattern_artifact( '1.0.0', 'Original retained release' ) ] ),
			$this->context()
		);
		$this->assertNotWPError( $first );
		$second = $manager->apply(
			$this->themeDir,
			$this->manifest( [ $this->pattern_artifact( '1.1.0', 'Current retained release' ) ] ),
			$this->context()
		);
		$this->assertNotWPError( $second );

		$path    = $this->themeDir . '/.sd-ai-agent/design-artifacts/releases/' . $first['release_id'] . '/release.json';
		$release = json_decode( (string) file_get_contents( $path ), true );
		$this->assertIsArray( $release );
		$release['artifacts'][0]['payload']['files'][0]['content'] = "<?php\n/** Tampered retained release */\n";
		$hash = ArtifactManifest::hash_payload( $release['artifacts'][0]['payload'] );
		$this->assertIsString( $hash );
		$release['artifacts'][0]['integrity']['content_hash']          = $hash;
		$release['artifact_hashes']['sd-ai-agent/pattern/hero'] = $hash;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Fixture simulates retained metadata tampering after a release has been staged.
		file_put_contents( $path, wp_json_encode( $release ) );

		$result = $manager->rollback( $this->themeDir, $first['release_id'] );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_design_artifact_retained_release_integrity_mismatch', $result->get_error_code() );
		$this->assertStringContainsString( 'Current retained release', (string) file_get_contents( $this->themeDir . '/patterns/hero.php' ) );
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
	private function manifest( array $artifacts, string $stylesheet = 'sd-ai-artifact-test' ): array {
		return [
			'schema_version' => ArtifactManifest::SCHEMA_VERSION,
			'theme'          => [ 'stylesheet' => $stylesheet ],
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
