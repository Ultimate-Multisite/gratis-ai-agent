<?php

declare(strict_types=1);
/**
 * Tests for Design System Global Styles persistence.
 *
 * @package SdAiAgent\Tests\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\DesignSystemAbilities;
use SdAiAgent\Abilities\GlobalStylesAbilities;
use WP_Theme_JSON;
use WP_UnitTestCase;

/**
 * Tests the theme-json-presets façade against the shared persistence service.
 */
class DesignSystemAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Run persistence calls with theme permissions and a clean resolver cache.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		wp_clean_theme_json_cache();
	}

	/**
	 * Clear resolver state after each test.
	 */
	public function tear_down(): void {
		wp_clean_theme_json_cache();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Both public façades read and mutate the same active-theme document.
	 */
	public function test_updates_are_visible_across_both_global_styles_facades(): void {
		$global_update = GlobalStylesAbilities::handle_update_global_styles(
			[
				'styles' => [
					'color' => [ 'text' => '#102030' ],
				],
			]
		);
		$this->assertIsArray( $global_update );

		$design_get = DesignSystemAbilities::handle_theme_json_presets( [ 'action' => 'get' ] );
		$this->assertIsArray( $design_get );
		$this->assertSame( 'get', $design_get['action'] );
		$this->assertSame( $global_update['post_id'], $design_get['post_id'] );
		$this->assertSame( '#102030', $design_get['global_styles']['styles']['color']['text'] );
		$this->assertTrue( $design_get['global_styles']['isGlobalStylesUserThemeJSON'] );

		$design_update = DesignSystemAbilities::handle_theme_json_presets(
			[
				'action' => 'update',
				'styles' => [
					'settings' => [
						'color' => [
							'palette' => [
								[
									'slug'  => 'primary',
									'color' => '#102030',
									'name'  => 'Primary',
								],
							],
						],
					],
					'styles'   => [
						'typography' => [ 'lineHeight' => '1.7' ],
					],
				],
			]
		);

		$this->assertIsArray( $design_update );
		$this->assertSame( $global_update['post_id'], $design_update['post_id'] );
		$this->assertSame( '#102030', $design_update['global_styles']['styles']['color']['text'] );
		$this->assertSame( 'primary', $design_update['global_styles']['settings']['color']['palette'][0]['slug'] );

		$global_get = GlobalStylesAbilities::handle_get_global_styles( [] );
		$this->assertIsArray( $global_get );
		$this->assertSame( '#102030', $global_get['styles']['color']['text'] );
		$this->assertSame( '1.7', $global_get['styles']['typography']['lineHeight'] );
	}

	/**
	 * Theme-json-presets no longer falls back to an arbitrary latest post.
	 */
	public function test_get_ignores_unidentified_global_styles_post(): void {
		$post_id = wp_insert_post(
			[
				'post_title'   => 'Unidentified Styles',
				'post_name'    => 'unidentified-styles',
				'post_content' => wp_json_encode(
					[
						'version'                     => WP_Theme_JSON::LATEST_SCHEMA,
						'isGlobalStylesUserThemeJSON' => true,
						'styles'                      => [
							'color' => [ 'text' => '#ff0000' ],
						],
					]
				),
				'post_status'  => 'publish',
				'post_type'    => 'wp_global_styles',
			],
			true
		);
		$this->assertNotWPError( $post_id );

		$result = DesignSystemAbilities::handle_theme_json_presets( [ 'action' => 'get' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['post_id'] );
		$this->assertEquals( (object) [], $result['global_styles'] );
		$this->assertNotNull( get_post( $post_id ) );
	}

	/**
	 * Reset remains idempotent and preserves the existing response contract.
	 */
	public function test_reset_returns_empty_document_contract(): void {
		$result = DesignSystemAbilities::handle_theme_json_presets( [ 'action' => 'reset' ] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'reset', $result['action'] );
		$this->assertSame( 0, $result['post_id'] );
		$this->assertEquals( (object) [], $result['global_styles'] );
	}
}
