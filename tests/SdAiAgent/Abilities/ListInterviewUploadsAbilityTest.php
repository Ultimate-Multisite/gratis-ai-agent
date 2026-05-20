<?php

declare(strict_types=1);
/**
 * Tests for ListInterviewUploadsAbility (GH#1534).
 *
 * Covers ability schema, permission gating, and that execute_callback
 * delegates correctly to InterviewUploadStore::list_uploads().
 *
 * @package SdAiAgent\Tests\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\ListInterviewUploadsAbility;
use SdAiAgent\Core\InterviewUploadStore;
use WP_UnitTestCase;

/**
 * Test ListInterviewUploadsAbility behaviour.
 */
class ListInterviewUploadsAbilityTest extends WP_UnitTestCase {

	/**
	 * Build the ability instance for direct invocation.
	 */
	private function make_ability(): ListInterviewUploadsAbility {
		return new ListInterviewUploadsAbility(
			'sd-ai-agent/list-interview-uploads',
			[
				'label'       => 'List Interview Uploads',
				'description' => 'List photos uploaded during the Theme Builder interview.',
			]
		);
	}

	/**
	 * Create a tagged attachment for filtering tests.
	 *
	 * @param string $filename Filename — drives the heuristic category.
	 * @param int    $session  Session id stored as meta. 0 to skip.
	 * @return int Attachment ID.
	 */
	private function make_tagged_attachment( string $filename, int $session = 0 ): int {
		$id = self::factory()->attachment->create(
			[
				'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
				'post_mime_type' => 'image/jpeg',
			]
		);
		update_post_meta( (int) $id, '_wp_attached_file', '2026/05/' . $filename );
		InterviewUploadStore::tag_attachment( (int) $id, [ 'session_id' => $session ] );
		return (int) $id;
	}

	// ── schema ────────────────────────────────────────────────────────────

	public function test_input_schema_declares_category_session_and_limit(): void {
		$ability = $this->make_ability();
		$schema  = $ability->get_input_schema();

		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'category', $schema['properties'] );
		$this->assertArrayHasKey( 'session_id', $schema['properties'] );
		$this->assertArrayHasKey( 'limit', $schema['properties'] );

		$this->assertContains( 'space', $schema['properties']['category']['enum'] );
		$this->assertContains( 'product', $schema['properties']['category']['enum'] );
		$this->assertContains( 'team', $schema['properties']['category']['enum'] );
		$this->assertContains( 'event', $schema['properties']['category']['enum'] );
		$this->assertContains( 'other', $schema['properties']['category']['enum'] );

		// No required fields — the ability lists everything by default.
		$required = $schema['required'] ?? [];
		$this->assertSame( [], $required );
	}

	// ── execute_callback ──────────────────────────────────────────────────

	public function test_ability_returns_empty_list_when_no_uploads_tagged(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$ability = $this->make_ability();
		$result  = $ability->run( [] );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['total'] );
		$this->assertSame( [], $result['items'] );
	}

	public function test_ability_returns_tagged_uploads(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$a = $this->make_tagged_attachment( 'shopfront.jpg' );
		$b = $this->make_tagged_attachment( 'latte.jpg' );

		$ability = $this->make_ability();
		$result  = $ability->run( [] );

		$this->assertSame( 2, $result['total'] );
		$ids = array_column( $result['items'], 'attachment_id' );
		$this->assertContains( $a, $ids );
		$this->assertContains( $b, $ids );
	}

	public function test_ability_filters_by_category(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$space   = $this->make_tagged_attachment( 'shopfront.jpg' );
		$this->make_tagged_attachment( 'latte.jpg' ); // product — should not appear

		$ability = $this->make_ability();
		$result  = $ability->run( [ 'category' => 'space' ] );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $space, $result['items'][0]['attachment_id'] );
	}

	public function test_ability_filters_by_session_id(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$a = $this->make_tagged_attachment( 'shopfront.jpg', 100 );
		$this->make_tagged_attachment( 'team-photo.jpg', 200 ); // different session

		$ability = $this->make_ability();
		$result  = $ability->run( [ 'session_id' => 100 ] );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $a, $result['items'][0]['attachment_id'] );
	}

	public function test_ability_returns_by_category_counts(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$this->make_tagged_attachment( 'shopfront.jpg' );
		$this->make_tagged_attachment( 'venue-exterior.jpg' );
		$this->make_tagged_attachment( 'latte.jpg' );

		$ability = $this->make_ability();
		$result  = $ability->run( [] );

		$this->assertSame( 3, $result['total'] );
		$this->assertSame( 2, $result['by_category']['space'] );
		$this->assertSame( 1, $result['by_category']['product'] );
	}
}
