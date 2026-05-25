<?php

declare(strict_types=1);
/**
 * Test case for ListAllowedRootsAbility class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\ListAllowedRootsAbility;
use WP_UnitTestCase;

/**
 * Test ListAllowedRootsAbility functionality.
 */
class ListAllowedRootsAbilityTest extends WP_UnitTestCase {

	/**
	 * Build a ListAllowedRootsAbility instance for testing.
	 *
	 * @return ListAllowedRootsAbility
	 */
	private function make_ability(): ListAllowedRootsAbility {
		return new ListAllowedRootsAbility(
			'sd-ai-agent/list-allowed-roots',
			[
				'label'       => 'List Allowed Roots',
				'description' => 'Returns the list of filesystem directories where the AI is permitted to read or write.',
			]
		);
	}

	// ── execute_callback — basic functionality ────────────────────────────

	/**
	 * execute_callback() returns array with roots key.
	 */
	public function test_execute_returns_roots_array(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$ability = $this->make_ability();
		$result  = $ability->run( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'roots', $result );
		$this->assertIsArray( $result['roots'] );
	}

	/**
	 * execute_callback() includes plugins root.
	 */
	public function test_execute_includes_plugins_root(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$ability = $this->make_ability();
		$result  = $ability->run( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'roots', $result );
		$this->assertArrayHasKey( 'plugins', $result['roots'] );
		$this->assertIsString( $result['roots']['plugins'] );
		$this->assertSame( realpath( WP_PLUGIN_DIR ), $result['roots']['plugins'] );
	}

	/**
	 * execute_callback() includes themes root.
	 */
	public function test_execute_includes_themes_root(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$ability = $this->make_ability();
		$result  = $ability->run( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'roots', $result );
		$this->assertArrayHasKey( 'themes', $result['roots'] );
		$this->assertIsString( $result['roots']['themes'] );
		$this->assertSame( realpath( get_theme_root() ), $result['roots']['themes'] );
	}

	/**
	 * execute_callback() includes uploads root.
	 */
	public function test_execute_includes_uploads_root(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$ability = $this->make_ability();
		$result  = $ability->run( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'roots', $result );
		$this->assertArrayHasKey( 'uploads', $result['roots'] );
		$this->assertIsString( $result['roots']['uploads'] );
		$uploads_dir = wp_upload_dir();
		$this->assertSame( realpath( $uploads_dir['basedir'] ), $result['roots']['uploads'] );
	}

	// ── execute_callback — path resolution ────────────────────────────────

	/**
	 * execute_callback() returns absolute paths.
	 */
	public function test_execute_returns_absolute_paths(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$ability = $this->make_ability();
		$result  = $ability->run( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'roots', $result );

		foreach ( $result['roots'] as $label => $path ) {
			$this->assertTrue(
				0 === strpos( $path, '/' ),
				"Path for label '$label' should be absolute, got: $path"
			);
		}
	}

	/**
	 * execute_callback() returns realpath-resolved paths.
	 */
	public function test_execute_returns_realpath_resolved_paths(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$ability = $this->make_ability();
		$result  = $ability->run( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'roots', $result );

		foreach ( $result['roots'] as $label => $path ) {
			$this->assertSame(
				$path,
				realpath( $path ),
				"Path for label '$label' should be realpath-resolved, got: $path"
			);
		}
	}

	// ── execute_callback — deduplication ──────────────────────────────────

	/**
	 * execute_callback() deduplicates by resolved path.
	 */
	public function test_execute_deduplicates_by_resolved_path(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		// Add a filter that returns a duplicate path (same as plugins).
		$callback = function ( $roots ) {
			$roots['duplicate-plugins'] = WP_PLUGIN_DIR;
			return $roots;
		};
		add_filter( 'sd_ai_agent_allowed_roots', $callback );

		$ability = $this->make_ability();
		$result  = $ability->run( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'roots', $result );

		// Count how many entries resolve to the same path as plugins.
		$plugins_real = realpath( WP_PLUGIN_DIR );
		$count        = 0;
		foreach ( $result['roots'] as $path ) {
			if ( $path === $plugins_real ) {
				$count++;
			}
		}

		// Should only have one entry for the plugins path.
		$this->assertSame( 1, $count, 'Duplicate paths should be deduplicated' );

		remove_filter( 'sd_ai_agent_allowed_roots', $callback );
	}

	// ── execute_callback — optional roots ─────────────────────────────────

	/**
	 * execute_callback() includes mu-plugins only if directory exists.
	 */
	public function test_execute_includes_mu_plugins_only_if_exists(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$ability = $this->make_ability();
		$result  = $ability->run( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'roots', $result );

		if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
			$this->assertArrayHasKey( 'mu-plugins', $result['roots'] );
		} else {
			$this->assertArrayNotHasKey( 'mu-plugins', $result['roots'] );
		}
	}

	/**
	 * execute_callback() includes ai-edits only if directory exists.
	 */
	public function test_execute_includes_ai_edits_only_if_exists(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$ability = $this->make_ability();
		$result  = $ability->run( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'roots', $result );

		$ai_edits_dir = WP_CONTENT_DIR . '/uploads/ai-edits';
		if ( is_dir( $ai_edits_dir ) ) {
			$this->assertArrayHasKey( 'ai-edits', $result['roots'] );
		} else {
			$this->assertArrayNotHasKey( 'ai-edits', $result['roots'] );
		}
	}

	// ── execute_callback — filter integration ─────────────────────────────

	/**
	 * execute_callback() respects sd_ai_agent_allowed_roots filter.
	 */
	public function test_execute_respects_allowed_roots_filter(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		// Add a custom root via filter.
		$callback = function ( $roots ) {
			$roots['custom'] = WP_CONTENT_DIR;
			return $roots;
		};
		add_filter( 'sd_ai_agent_allowed_roots', $callback );

		$ability = $this->make_ability();
		$result  = $ability->run( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'roots', $result );
		$this->assertArrayHasKey( 'custom', $result['roots'] );

		remove_filter( 'sd_ai_agent_allowed_roots', $callback );
	}

	// ── execute_callback — result shape ───────────────────────────────────

	/**
	 * execute_callback() returns expected shape.
	 */
	public function test_execute_returns_expected_shape(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$ability = $this->make_ability();
		$result  = $ability->run( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'roots', $result );
		$this->assertIsArray( $result['roots'] );

		// Each entry should be label => path.
		foreach ( $result['roots'] as $label => $path ) {
			$this->assertIsString( $label );
			$this->assertIsString( $path );
		}
	}

	// ── meta ──────────────────────────────────────────────────────────────

	/**
	 * Ability is marked as readonly and idempotent.
	 */
	public function test_ability_is_readonly_and_idempotent(): void {
		$ability = $this->make_ability();
		$meta    = $ability->get_meta();

		$this->assertIsArray( $meta );
		$this->assertArrayHasKey( 'annotations', $meta );
		$this->assertIsArray( $meta['annotations'] );
		$this->assertTrue( $meta['annotations']['readonly'] );
		$this->assertTrue( $meta['annotations']['idempotent'] );
		$this->assertFalse( $meta['annotations']['destructive'] );
	}
}
