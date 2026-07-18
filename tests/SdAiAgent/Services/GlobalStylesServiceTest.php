<?php

declare(strict_types=1);
/**
 * Tests for the canonical Global Styles persistence service.
 *
 * @package SdAiAgent\Tests\Services
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Services;

use SdAiAgent\Services\GlobalStylesService;
use WP_Theme_JSON;
use WP_UnitTestCase;

/**
 * Tests active-theme Global Styles reads and persistence.
 */
class GlobalStylesServiceTest extends WP_UnitTestCase {

	/**
	 * Run service calls as a user who can assign the private wp_theme taxonomy.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		wp_clean_theme_json_cache();
	}

	/**
	 * Prevent the resolver's static cache from leaking between tests.
	 */
	public function tear_down(): void {
		wp_clean_theme_json_cache();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Repeated partial merges reuse one active-theme post and retain the full document.
	 */
	public function test_merge_user_document_reuses_canonical_active_theme_post(): void {
		$service = new GlobalStylesService();

		$first = $service->merge_user_document(
			[
				'styles' => [
					'color' => [ 'text' => '#111111' ],
				],
			]
		);

		$this->assertIsArray( $first );
		$this->assertGreaterThan( 0, $first['post_id'] );

		$second = $service->merge_user_document(
			[
				'settings' => [
					'color' => [ 'custom' => false ],
				],
				'styles'   => [
					'typography' => [ 'lineHeight' => '1.6' ],
				],
			]
		);

		$this->assertIsArray( $second );
		$this->assertSame( $first['post_id'], $second['post_id'] );
		$this->assertSame( '#111111', $second['document']['styles']['color']['text'] );
		$this->assertSame( '1.6', $second['document']['styles']['typography']['lineHeight'] );
		$this->assertFalse( $second['document']['settings']['color']['custom'] );
		$this->assertTrue( $second['document']['isGlobalStylesUserThemeJSON'] );
		$this->assertSame( WP_Theme_JSON::LATEST_SCHEMA, $second['document']['version'] );
		$this->assertSame( $second['document'], $service->get_user_document() );
		$this->assertSame( $second['post_id'], $service->get_user_post_id() );

		$theme_terms = wp_get_object_terms( $second['post_id'], 'wp_theme', [ 'fields' => 'names' ] );
		$this->assertNotWPError( $theme_terms );
		$this->assertSame( [ get_stylesheet() ], $theme_terms );
	}

	/**
	 * Resolved reads use WordPress's core/theme/user merger.
	 */
	public function test_get_resolved_styles_includes_active_theme_user_styles(): void {
		$service = new GlobalStylesService();
		$result  = $service->merge_user_document(
			[
				'styles' => [
					'color' => [ 'text' => '#123456' ],
				],
			]
		);

		$this->assertIsArray( $result );

		$resolved = $service->get_resolved_styles();

		$this->assertSame( '#123456', $resolved['color']['text'] );
	}

	/**
	 * One provably active pre-service record is adopted without a bulk migration.
	 */
	public function test_exact_legacy_active_theme_record_is_safely_adopted(): void {
		$stylesheet = get_stylesheet();
		$post_id    = wp_insert_post(
			[
				'post_content' => wp_json_encode(
					[
						'version' => 2,
						'styles'  => [
							'color' => [ 'text' => '#778899' ],
						],
					]
				),
				'post_status'  => 'publish',
				'post_title'   => 'Custom Styles',
				'post_type'    => 'wp_global_styles',
				'post_name'    => 'wp-global-styles-' . $stylesheet,
			],
			true
		);
		$this->assertNotWPError( $post_id );
		update_post_meta( $post_id, 'link', 'wp-global-styles-' . $stylesheet );

		$service  = new GlobalStylesService();
		$document = $service->get_user_document();

		$this->assertSame( $post_id, $service->get_user_post_id() );
		$this->assertSame( '#778899', $document['styles']['color']['text'] );
		$this->assertTrue( $document['isGlobalStylesUserThemeJSON'] );
		$this->assertSame( [ $stylesheet ], wp_get_object_terms( $post_id, 'wp_theme', [ 'fields' => 'names' ] ) );
		$this->assertSame( '#778899', $service->get_resolved_styles()['color']['text'] );
	}

	/**
	 * A record assigned to another stylesheet is never returned, changed, or deleted.
	 */
	public function test_competing_theme_record_is_isolated_from_active_theme_operations(): void {
		$other_document = [
			'version'                     => WP_Theme_JSON::LATEST_SCHEMA,
			'isGlobalStylesUserThemeJSON' => true,
			'styles'                      => [
				'color' => [ 'text' => '#abcdef' ],
			],
		];
		$other_id       = $this->create_theme_document( 'competing-theme', $other_document );
		$other_content  = get_post( $other_id )->post_content;
		$service        = new GlobalStylesService();

		$this->assertSame( [], $service->get_user_document() );
		$this->assertNull( $service->get_user_post_id() );

		$created = $service->merge_user_document(
			[
				'styles' => [
					'color' => [ 'text' => '#654321' ],
				],
			]
		);

		$this->assertIsArray( $created );
		$this->assertNotSame( $other_id, $created['post_id'] );
		$this->assertSame( $other_content, get_post( $other_id )->post_content );
		$this->assertTrue( $service->delete_user_document() );
		$this->assertNull( get_post( $created['post_id'] ) );
		$this->assertSame( $other_content, get_post( $other_id )->post_content );
	}

	/**
	 * JSON encoding failure leaves the previous persisted document unchanged.
	 */
	public function test_merge_encoding_failure_preserves_existing_document(): void {
		$service = new GlobalStylesService();
		$created = $service->merge_user_document(
			[
				'styles' => [
					'color' => [ 'text' => '#222222' ],
				],
			]
		);
		$this->assertIsArray( $created );

		$before = get_post( $created['post_id'] )->post_content;
		$stream = fopen( 'php://memory', 'r' );
		if ( false === $stream ) {
			$this->fail( 'Could not create the in-memory stream used by this test.' );
		}

		try {
			$result = $service->merge_user_document(
				[
					'styles' => [ 'invalid' => $stream ],
				]
			);
		} finally {
			fclose( $stream );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'json_encode_failed', $result->get_error_code() );
		$this->assertSame( $before, get_post( $created['post_id'] )->post_content );
	}

	/**
	 * Deleting when no active-theme customization exists is idempotent.
	 */
	public function test_delete_user_document_returns_false_when_nothing_exists(): void {
		$service = new GlobalStylesService();

		$this->assertFalse( $service->delete_user_document() );
	}

	/**
	 * Create a canonical Global Styles post assigned to a specific stylesheet.
	 *
	 * @param string              $stylesheet Stylesheet slug.
	 * @param array<string,mixed> $document   User-level theme.json document.
	 * @return int Post ID.
	 */
	private function create_theme_document( string $stylesheet, array $document ): int {
		$post_id = wp_insert_post(
			[
				'post_content' => wp_json_encode( $document ),
				'post_status'  => 'publish',
				'post_title'   => 'Custom Styles',
				'post_type'    => 'wp_global_styles',
				'post_name'    => sprintf( 'wp-global-styles-%s', urlencode( $stylesheet ) ),
			],
			true
		);
		$this->assertNotWPError( $post_id );

		$terms = wp_set_object_terms( $post_id, $stylesheet, 'wp_theme' );
		$this->assertNotWPError( $terms );

		return $post_id;
	}
}
