<?php

declare(strict_types=1);
/**
 * Tests for the explicit advanced style-variation lifecycle abilities.
 *
 * @package SdAiAgent\Tests\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use Closure;
use SdAiAgent\Abilities\StyleVariationAbilities;
use SdAiAgent\Services\GlobalStylesService;
use SdAiAgent\Services\StyleVariationManager;
use WP_Theme_JSON;
use WP_UnitTestCase;

/**
 * Covers active-theme style-variation lifecycle safety guarantees.
 */
class StyleVariationAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Original stylesheet restored after each disposable-theme test.
	 */
	private string $originalStylesheet;

	/**
	 * Disposable parent theme slug.
	 */
	private string $parentSlug;

	/**
	 * Disposable active child theme slug.
	 */
	private string $childSlug;

	/**
	 * Disposable parent theme directory.
	 */
	private string $parentDir;

	/**
	 * Disposable active child theme directory.
	 */
	private string $childDir;

	/**
	 * File-modification filter used only for the disposable test themes.
	 */
	private Closure $fileModAllowedFilter;

	/**
	 * Create a temporary parent/child block-theme pair and activate the child.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->originalStylesheet = (string) get_stylesheet();
		$suffix                   = strtolower( wp_generate_password( 8, false ) );
		$this->parentSlug         = 'sd-ai-style-parent-' . $suffix;
		$this->childSlug          = 'sd-ai-style-child-' . $suffix;
		$this->parentDir          = trailingslashit( get_theme_root() ) . $this->parentSlug;
		$this->childDir           = trailingslashit( get_theme_root() ) . $this->childSlug;

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->fileModAllowedFilter = static function ( bool $allowed, string $context ): bool {
			return 'theme_files' === $context ? true : $allowed;
		};
		add_filter( 'file_mod_allowed', $this->fileModAllowedFilter, 10, 2 );

		$this->stage_theme( $this->parentDir, $this->parentSlug, 'Style Variation Parent', null );
		$this->stage_theme( $this->childDir, $this->childSlug, 'Style Variation Child', $this->parentSlug );
		wp_clean_themes_cache();
		switch_theme( $this->childSlug );
		wp_clean_themes_cache();
		wp_clean_theme_json_cache();
		delete_option( StyleVariationManager::STATE_OPTION );
	}

	/**
	 * Restore the original theme and remove all test-owned filesystem/database state.
	 */
	public function tear_down(): void {
		$this->delete_child_global_styles();
		delete_option( StyleVariationManager::STATE_OPTION );

		if ( $this->originalStylesheet !== get_stylesheet() ) {
			switch_theme( $this->originalStylesheet );
		}
		wp_clean_themes_cache();
		wp_clean_theme_json_cache();

		self::rrmdir( $this->childDir );
		self::rrmdir( $this->parentDir );
		remove_filter( 'file_mod_allowed', $this->fileModAllowedFilter, 10 );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Every lifecycle operation exposes its own explicit capability-gated contract.
	 */
	public function test_registers_explicit_lifecycle_ability_contracts(): void {
		if ( null === wp_get_ability( 'sd-ai-agent/list-style-variations' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress hook stack global required by core ability registration.
			global $wp_current_filter;
			$wp_current_filter[] = 'wp_abilities_api_init';
			try {
				StyleVariationAbilities::register_abilities();
			} finally {
				array_pop( $wp_current_filter );
			}
		}

		$expected = [
			'sd-ai-agent/list-style-variations'     => [ true, false, true ],
			'sd-ai-agent/create-style-variation'   => [ false, true, false ],
			'sd-ai-agent/update-style-variation'   => [ false, true, false ],
			'sd-ai-agent/validate-style-variation' => [ true, false, true ],
			'sd-ai-agent/preview-style-variation'  => [ true, false, true ],
			'sd-ai-agent/select-style-variation'   => [ false, true, true ],
			'sd-ai-agent/reset-style-variation'    => [ false, true, false ],
		];

		foreach ( $expected as $id => $annotations ) {
			$ability = wp_get_ability( $id );

			$this->assertNotNull( $ability, $id );
			$this->assertTrue( $ability->get_meta()['mcp']['public'], $id );
			$this->assertTrue( $ability->get_meta()['show_in_rest'], $id );
			$this->assertSame( $annotations[0], $ability->get_meta()['annotations']['readonly'], $id );
			$this->assertSame( $annotations[1], $ability->get_meta()['annotations']['destructive'], $id );
			$this->assertSame( $annotations[2], $ability->get_meta()['annotations']['idempotent'], $id );
		}
	}

	/**
	 * Create and update use canonical hashes and never let a stale writer replace a file.
	 */
	public function test_create_and_update_require_current_hashes(): void {
		$manager  = new StyleVariationManager();
		$original = $this->variation_document( 'sunset', '#8b1e3f' );
		$created  = $manager->create( $original );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );
		$this->assertSame( 'created', $created['action'] );
		$this->assertFileExists( $this->childDir . '/styles/sunset.json' );

		$duplicate = $manager->create( $original );
		$this->assertWPError( $duplicate );
		$this->assertSame( 'sd_ai_agent_style_variation_exists', $duplicate->get_error_code() );

		$replacement = $this->variation_document( 'sunset', '#155e75' );
		$stale       = $manager->update( 'sunset', $replacement, str_repeat( '0', 64 ) );
		$this->assertWPError( $stale );
		$this->assertSame( 'sd_ai_agent_style_variation_stale_hash', $stale->get_error_code() );
		$this->assertSame( '#8b1e3f', $this->read_document( $this->childDir . '/styles/sunset.json' )['styles']['color']['text'] );
		$stale_select = $manager->select( 'sunset', str_repeat( '0', 64 ) );
		$this->assertWPError( $stale_select );
		$this->assertSame( 'sd_ai_agent_style_variation_stale_hash', $stale_select->get_error_code() );

		$updated = $manager->update( 'sunset', $replacement, $created['hash'] );
		$this->assertIsArray( $updated, is_wp_error( $updated ) ? $updated->get_error_message() : '' );
		$this->assertSame( 'updated', $updated['action'] );
		$this->assertNotSame( $created['hash'], $updated['hash'] );
		$this->assertSame( '#155e75', $this->read_document( $this->childDir . '/styles/sunset.json' )['styles']['color']['text'] );
	}

	/**
	 * FileModGate rejects theme mutation before a variation file is created.
	 */
	public function test_create_respects_file_mod_gate(): void {
		$deny_theme_writes = static function ( bool $allowed, string $context ): bool {
			return 'theme_files' === $context ? false : $allowed;
		};
		add_filter( 'file_mod_allowed', $deny_theme_writes, 20, 2 );
		try {
			$result = ( new StyleVariationManager() )->create( $this->variation_document( 'blocked', '#991b1b' ) );
		} finally {
			remove_filter( 'file_mod_allowed', $deny_theme_writes, 20 );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'file_mod_not_allowed', $result->get_error_code() );
		$this->assertFileDoesNotExist( $this->childDir . '/styles/blocked.json' );
	}

	/**
	 * A target created while the staged write is pending is never overwritten.
	 */
	public function test_create_refuses_to_overwrite_a_concurrently_created_variation(): void {
		$target   = $this->childDir . '/styles/race.json';
		$injected = false;
		$race     = static function ( string $path ) use ( $target, &$injected ): void {
			if ( $target !== $path || $injected ) {
				return;
			}
			$injected = true;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Simulates an independent writer winning the create race.
			file_put_contents( $target, 'independent writer' );
		};
		add_action( 'sd_ai_agent_before_file_write', $race );
		try {
			$result = ( new StyleVariationManager() )->create( $this->variation_document( 'race', '#9f1239' ) );
		} finally {
			remove_action( 'sd_ai_agent_before_file_write', $race );
		}

		$this->assertTrue( $injected );
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_style_variation_exists', $result->get_error_code() );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Verifies the independent race winner remains untouched.
		$this->assertSame( 'independent writer', file_get_contents( $target ) );
	}

	/**
	 * A competing lifecycle request is rejected while a manager-owned mutation holds the site lock.
	 */
	public function test_lifecycle_lock_refuses_reentrant_mutation(): void {
		$manager       = new StyleVariationManager();
		$outer_document = $this->variation_document( 'outer', '#9f1239' );
		$nested_result  = null;
		$target         = $this->childDir . '/styles/outer.json';
		$reentrant      = static function ( string $path ) use ( $manager, $target, &$nested_result, $outer_document ): void {
			if ( $target !== $path ) {
				return;
			}

			$nested_result = $manager->create( array_replace( $outer_document, [ 'slug' => 'nested', 'title' => 'Nested' ] ) );
		};
		add_action( 'sd_ai_agent_before_file_write', $reentrant );
		try {
			$created = $manager->create( $outer_document );
		} finally {
			remove_action( 'sd_ai_agent_before_file_write', $reentrant );
		}

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );
		$this->assertWPError( $nested_result );
		$this->assertSame( 'sd_ai_agent_style_variation_busy', $nested_result->get_error_code() );
		$this->assertFileExists( $target );
		$this->assertFileDoesNotExist( $this->childDir . '/styles/nested.json' );
	}

	/**
	 * An external edit between the optimistic update check and replacement remains untouched.
	 */
	public function test_update_rechecks_hash_immediately_before_replacement(): void {
		$manager      = new StyleVariationManager();
		$original     = $this->variation_document( 'update-race', '#7c2d12' );
		$created      = $manager->create( $original );
		$replacement  = $this->variation_document( 'update-race', '#0f766e' );
		$intervening  = $this->variation_document( 'update-race', '#4338ca' );
		$target       = $this->childDir . '/styles/update-race.json';
		$injected     = false;
		$independent  = function ( string $path ) use ( $target, $intervening, &$injected ): void {
			if ( $target !== $path || $injected ) {
				return;
			}

			$injected = true;
			$this->write_document( $target, $intervening );
		};
		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );
		add_action( 'sd_ai_agent_before_file_edit', $independent );
		try {
			$result = $manager->update( 'update-race', $replacement, $created['hash'] );
		} finally {
			remove_action( 'sd_ai_agent_before_file_edit', $independent );
		}

		$this->assertTrue( $injected );
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_style_variation_stale_hash', $result->get_error_code() );
		$this->assertSame( '#4338ca', $this->read_document( $target )['styles']['color']['text'] );
	}

	/**
	 * Selection rechecks its exact source immediately before persisting Global Styles state.
	 */
	public function test_select_refuses_source_changed_while_preparing_global_styles(): void {
		$manager     = new StyleVariationManager();
		$original    = $this->variation_document( 'select-race', '#be123c' );
		$created     = $manager->create( $original );
		$replacement = $this->variation_document( 'select-race', '#1d4ed8' );
		$target      = $this->childDir . '/styles/select-race.json';
		$injected    = false;
		$independent = function ( string $slug, string $relative_path ) use ( $target, $replacement, &$injected ): void {
			if ( 'select-race' !== $slug || 'styles/select-race.json' !== $relative_path || $injected ) {
				return;
			}

			$injected = true;
			$this->write_document( $target, $replacement );
		};
		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );
		add_action( 'sd_ai_agent_before_style_variation_select', $independent, 10, 2 );
		try {
			$result = $manager->select( 'select-race', $created['hash'] );
		} finally {
			remove_action( 'sd_ai_agent_before_style_variation_select', $independent, 10 );
		}

		$this->assertTrue( $injected );
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_style_variation_stale_hash', $result->get_error_code() );
		$this->assertFalse( get_option( StyleVariationManager::STATE_OPTION, false ) );
	}

	/**
	 * Existing file mutation hooks remain available to change tracking for both write paths.
	 */
	public function test_create_and_update_emit_existing_file_mutation_hooks(): void {
		$manager  = new StyleVariationManager();
		$events   = [];
		$record   = static function ( string $event ) use ( &$events ): callable {
			return static function () use ( $event, &$events ): void {
				$events[] = $event;
			};
		};
		$before_write = $record( 'before-write' );
		$after_write  = $record( 'after-write' );
		$before_edit  = $record( 'before-edit' );
		$after_edit   = $record( 'after-edit' );
		add_action( 'sd_ai_agent_before_file_write', $before_write );
		add_action( 'sd_ai_agent_after_file_write', $after_write );
		add_action( 'sd_ai_agent_before_file_edit', $before_edit );
		add_action( 'sd_ai_agent_after_file_edit', $after_edit );
		try {
			$created = $manager->create( $this->variation_document( 'hooked', '#7e22ce' ) );
			$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );
			$updated = $manager->update( 'hooked', $this->variation_document( 'hooked', '#0f766e' ), $created['hash'] );
		} finally {
			remove_action( 'sd_ai_agent_before_file_write', $before_write );
			remove_action( 'sd_ai_agent_after_file_write', $after_write );
			remove_action( 'sd_ai_agent_before_file_edit', $before_edit );
			remove_action( 'sd_ai_agent_after_file_edit', $after_edit );
		}

		$this->assertIsArray( $updated, is_wp_error( $updated ) ? $updated->get_error_message() : '' );
		$this->assertSame( [ 'before-write', 'after-write', 'before-edit', 'after-edit' ], $events );
	}

	/**
	 * Parent variation files remain visible but cannot be mutated, while child files win by slug.
	 */
	public function test_child_precedence_and_parent_read_only_behavior(): void {
		$shared_document = $this->variation_document( 'shared', '#6b21a8' );
		$this->write_document(
			$this->parentDir . '/styles/shared.json',
			$shared_document
		);
		$manager = new StyleVariationManager();
		$child   = $manager->create( $shared_document );
		$this->assertIsArray( $child, is_wp_error( $child ) ? $child->get_error_message() : '' );

		$inventory = $manager->list();
		$this->assertIsArray( $inventory, is_wp_error( $inventory ) ? $inventory->get_error_message() : '' );
		$shared = array_values(
			array_filter(
				$inventory['variations'],
				static fn( array $variation ): bool => 'shared' === $variation['slug']
			)
		);

		$this->assertCount( 2, $shared );
		$this->assertSame( 'child', $shared[0]['origin'] );
		$this->assertFalse( $shared[0]['read_only'] );
		$this->assertSame( 'parent', $shared[1]['origin'] );
		$this->assertTrue( $shared[1]['read_only'] );

		$resolved = $manager->validate_existing( 'shared' );
		$this->assertIsArray( $resolved, is_wp_error( $resolved ) ? $resolved->get_error_message() : '' );
		$this->assertSame( 'child', $resolved['origin'] );
		$this->assertSame( $child['hash'], $resolved['hash'] );
		$this->assertSame( $child['hash'], $shared[1]['hash'] );

		$selected = $manager->select( 'shared', $child['hash'] );
		$this->assertIsArray( $selected, is_wp_error( $selected ) ? $selected->get_error_message() : '' );
		$selected_inventory = $manager->list();
		$this->assertIsArray( $selected_inventory, is_wp_error( $selected_inventory ) ? $selected_inventory->get_error_message() : '' );
		$selected_records = array_values(
			array_filter(
				$selected_inventory['variations'],
				static fn( array $variation ): bool => 'shared' === $variation['slug'] && $variation['selected']
			)
		);
		$this->assertCount( 1, $selected_records );
		$this->assertSame( 'child', $selected_records[0]['origin'] );

		$this->write_document(
			$this->parentDir . '/styles/parent-only.json',
			$this->variation_document( 'parent-only', '#4c1d95' )
		);
		$parent = $manager->validate_existing( 'parent-only' );
		$this->assertIsArray( $parent, is_wp_error( $parent ) ? $parent->get_error_message() : '' );
		$this->assertTrue( $parent['read_only'] );

		$update = $manager->update(
			'parent-only',
			$this->variation_document( 'parent-only', '#7e22ce' ),
			$parent['hash']
		);
		$this->assertWPError( $update );
		$this->assertSame( 'sd_ai_agent_style_variation_read_only', $update->get_error_code() );
	}

	/**
	 * Preview is pure; selection and reset restore exact pre-selection bytes.
	 */
	public function test_preview_is_pure_and_reset_restores_exact_global_styles_baseline(): void {
		$manager  = new StyleVariationManager();
		$document = $this->variation_document( 'ocean', '#0e7490' );
		$created  = $manager->create( $document );
		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		$baseline = ( new GlobalStylesService() )->merge_user_document(
			[
				'settings' => [ 'custom' => [ 'userOwned' => 'preserve-me' ] ],
				'styles'   => [ 'color' => [ 'text' => '#111111' ] ],
			]
		);
		$this->assertIsArray( $baseline, is_wp_error( $baseline ) ? $baseline->get_error_message() : '' );
		$before_content = (string) get_post( $baseline['post_id'] )->post_content;

		$preview = $manager->preview( $document );
		$this->assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$this->assertNotEmpty( $preview['changed_paths'] );
		$this->assertIsString( $preview['css'] );
		$this->assertSame( $before_content, (string) get_post( $baseline['post_id'] )->post_content );
		$this->assertFalse( get_option( StyleVariationManager::STATE_OPTION, false ) );

		$selected = $manager->select( 'ocean', $created['hash'] );
		$this->assertIsArray( $selected, is_wp_error( $selected ) ? $selected->get_error_message() : '' );
		$this->assertTrue( $selected['selected'] );

		$selected_content = (string) get_post( $baseline['post_id'] )->post_content;
		$this->assertNotSame( $before_content, $selected_content );
		$selected_document = json_decode( $selected_content, true );
		$this->assertIsArray( $selected_document );
		$this->assertSame( 'preserve-me', $selected_document['settings']['custom']['userOwned'] );
		$this->assertSame( '#0e7490', $selected_document['styles']['color']['text'] );

		$state = get_option( StyleVariationManager::STATE_OPTION );
		$this->assertIsArray( $state );
		$this->assertSame( $before_content, $state[ $this->childSlug ]['baseline']['content'] );

		$reset = $manager->reset();
		$this->assertIsArray( $reset, is_wp_error( $reset ) ? $reset->get_error_message() : '' );
		$this->assertTrue( $reset['reset'] );
		$this->assertSame( $before_content, (string) get_post( $baseline['post_id'] )->post_content );
		$this->assertFalse( get_option( StyleVariationManager::STATE_OPTION, false ) );
	}

	/**
	 * Reset refuses to overwrite intervening Site Editor changes.
	 */
	public function test_reset_refuses_intervening_global_styles_changes(): void {
		$manager  = new StyleVariationManager();
		$created  = $manager->create( $this->variation_document( 'forest', '#166534' ) );
		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );
		$selected = $manager->select( 'forest', $created['hash'] );
		$this->assertIsArray( $selected, is_wp_error( $selected ) ? $selected->get_error_message() : '' );

		$state       = get_option( StyleVariationManager::STATE_OPTION );
		$post_id     = $state[ $this->childSlug ]['selected']['post_id'];
		$site_editor = wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => wp_json_encode(
					[
						'version'                     => WP_Theme_JSON::LATEST_SCHEMA,
						'isGlobalStylesUserThemeJSON' => true,
						'settings'                    => [ 'custom' => [ 'siteEditor' => 'do-not-overwrite' ] ],
					]
				),
			],
			true
		);
		$this->assertNotWPError( $site_editor );
		$changed_content = (string) get_post( $post_id )->post_content;

		$reset = $manager->reset();
		$this->assertWPError( $reset );
		$this->assertSame( 'sd_ai_agent_style_variation_global_styles_changed', $reset->get_error_code() );
		$this->assertSame( $changed_content, (string) get_post( $post_id )->post_content );
		$this->assertIsArray( get_option( StyleVariationManager::STATE_OPTION ) );
	}

	/**
	 * Generated semantic-map documents must preserve every design-token colour role.
	 */
	public function test_validate_rejects_incomplete_compiled_semantic_map(): void {
		$document = $this->variation_document( 'incomplete', '#1d4ed8' );
		$document['settings']['custom']['sdAiAgent'] = [
			'semantic' => [
				'color' => [ 'primary' => 'var:preset|color|brand' ],
			],
		];

		$result = ( new StyleVariationManager() )->validate_document( $document );
		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_style_variation_semantics_invalid', $result->get_error_code() );
	}

	/**
	 * Lifecycle validation shares the generated-project CSS var() syntax guard
	 * before create or update can persist an invalid style variation.
	 */
	public function test_validate_rejects_malformed_css_variable_references(): void {
		$document = $this->variation_document( 'invalid-variable', '#1d4ed8' );
		$document['styles']['typography'] = [
			'fontFamily' => 'var(--wp--custom--font-family',
		];

		$result = ( new StyleVariationManager() )->validate_document( $document );

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_style_variation_css_variable_invalid', $result->get_error_code() );
		$this->assertSame( 'styles.typography.fontFamily', $result->get_error_data()['path'] );
		$this->assertSame( 'missing_closing_parenthesis', $result->get_error_data()['reason'] );
	}

	/**
	 * Stage one minimal block theme that WordPress can activate and resolve.
	 */
	private function stage_theme( string $directory, string $slug, string $name, ?string $parent_slug ): void {
		wp_mkdir_p( $directory . '/styles' );
		wp_mkdir_p( $directory . '/templates' );

		$template_header = null === $parent_slug ? '' : "Template: {$parent_slug}\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only disposable theme fixture.
		file_put_contents(
			$directory . '/style.css',
			"/*\nTheme Name: {$name}\n{$template_header}Version: 1.0.0\n*/\n"
		);
		$this->write_document(
			$directory . '/theme.json',
			[
				'$schema'  => 'https://schemas.wp.org/trunk/theme.json',
				'version'  => WP_Theme_JSON::LATEST_SCHEMA,
				'settings' => [],
				'styles'   => [],
			]
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only disposable block template fixture.
		file_put_contents( $directory . '/templates/index.html', '<!-- wp:paragraph --><p>Fixture</p><!-- /wp:paragraph -->' );
	}

	/**
	 * Return one valid complete style-variation document.
	 *
	 * @return array<string,mixed>
	 */
	private function variation_document( string $slug, string $color ): array {
		return [
			'$schema'  => 'https://schemas.wp.org/trunk/theme.json',
			'version'  => WP_Theme_JSON::LATEST_SCHEMA,
			'slug'     => $slug,
			'title'    => ucwords( str_replace( '-', ' ', $slug ) ),
			'settings' => [
				'color' => [
					'palette' => [
						[
							'slug'  => 'brand',
							'name'  => 'Brand',
							'color' => $color,
						],
					],
				],
			],
			'styles'   => [
				'color' => [
					'text'       => $color,
					'background' => '#ffffff',
				],
			],
		];
	}

	/**
	 * Write one test-only JSON document.
	 *
	 * @param array<string,mixed> $document JSON document.
	 */
	private function write_document( string $path, array $document ): void {
		$json = wp_json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$this->assertIsString( $json );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $path, $json );
	}

	/**
	 * Read one test-only JSON document.
	 *
	 * @return array<string,mixed>
	 */
	private function read_document( string $path ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture assertion.
		$document = json_decode( (string) file_get_contents( $path ), true );
		$this->assertIsArray( $document );

		return $document;
	}

	/**
	 * Delete only Global Styles posts assigned to this disposable child stylesheet.
	 */
	private function delete_child_global_styles(): void {
		$posts = get_posts(
			[
				'post_type'      => 'wp_global_styles',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'tax_query'      => [
					[
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => $this->childSlug,
					],
				],
				'no_found_rows'  => true,
			]
		);

		foreach ( $posts as $post ) {
			wp_delete_post( $post->ID, true );
		}
	}

	/**
	 * Recursively remove a disposable test theme.
	 */
	private static function rrmdir( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- Test-only disposable theme cleanup.
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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only disposable theme cleanup.
			unlink( $path );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir -- Test-only disposable theme cleanup.
		rmdir( $directory );
	}
}
