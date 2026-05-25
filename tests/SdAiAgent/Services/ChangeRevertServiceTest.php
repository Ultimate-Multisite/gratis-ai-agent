<?php

declare(strict_types=1);
/**
 * Tests for ChangeRevertService.
 *
 * Covers the apply_revert() method for all supported object types, the
 * [REDACTED] guard (BUG-3), correct maybe_unserialize() of array options
 * (BUG-2), and handling of custom post types (BUG-4).
 *
 * @package SdAiAgent\Tests\Services
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Services;

use SdAiAgent\Models\ChangesLog;
use SdAiAgent\Services\ChangeRevertService;
use WP_UnitTestCase;

/**
 * Tests for ChangeRevertService::apply_revert().
 */
class ChangeRevertServiceTest extends WP_UnitTestCase {

	/**
	 * IDs of change records created during tests, for cleanup.
	 *
	 * @var int[]
	 */
	private array $created_ids = [];

	/**
	 * Tear down: remove any change records created during the test.
	 */
	public function tear_down(): void {
		foreach ( $this->created_ids as $id ) {
			ChangesLog::delete( $id );
		}
		$this->created_ids = [];
		parent::tear_down();
	}

	/**
	 * Build a minimal stdClass change record suitable for apply_revert().
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 * @return \stdClass
	 */
	private function make_change( array $overrides = [] ): \stdClass {
		return (object) array_merge(
			[
				'id'           => 0,
				'session_id'   => 1,
				'object_type'  => 'post',
				'object_id'    => 0,
				'object_title' => 'Test',
				'ability_name' => 'test',
				'field_name'   => 'post_title',
				'before_value' => 'Before',
				'after_value'  => 'After',
				'reverted'     => 0,
				'reverted_at'  => null,
				'created_at'   => current_time( 'mysql', true ),
			],
			$overrides
		);
	}

	// ── REDACTED guard (BUG-3) ───────────────────────────────────────────────

	/**
	 * apply_revert() returns WP_Error when before_value is [REDACTED].
	 *
	 * Without this guard, reverting a redacted field would overwrite the
	 * real data with the literal string "[REDACTED]".
	 */
	public function test_apply_revert_blocks_redacted_before_value(): void {
		$change = $this->make_change( [ 'before_value' => '[REDACTED]' ] );

		$result = ChangeRevertService::apply_revert( $change );

		$this->assertWPError( $result );
		$this->assertSame( 'cannot_revert_redacted', $result->get_error_code() );
	}

	// ── post revert ──────────────────────────────────────────────────────────

	/**
	 * apply_revert() restores a post field to its before_value.
	 */
	public function test_apply_revert_post_restores_field(): void {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Current Title' ] );

		$change = $this->make_change( [
			'object_type'  => 'post',
			'object_id'    => $post_id,
			'field_name'   => 'post_title',
			'before_value' => 'Original Title',
			'after_value'  => 'Current Title',
		] );

		$result = ChangeRevertService::apply_revert( $change );

		$this->assertTrue( $result );
		$this->assertSame( 'Original Title', get_post( $post_id )->post_title );
	}

	/**
	 * apply_revert() restores a PAGE field — object_type 'page' must work via
	 * post_type_exists() fallback (BUG-4 fix).
	 */
	public function test_apply_revert_page_restores_field(): void {
		$page_id = self::factory()->post->create( [
			'post_title' => 'Current Page Title',
			'post_type'  => 'page',
		] );

		$change = $this->make_change( [
			'object_type'  => 'page',
			'object_id'    => $page_id,
			'field_name'   => 'post_title',
			'before_value' => 'Original Page Title',
		] );

		$result = ChangeRevertService::apply_revert( $change );

		$this->assertTrue( $result );
		$this->assertSame( 'Original Page Title', get_post( $page_id )->post_title );
	}

	/**
	 * apply_revert() returns WP_Error for a post that does not exist.
	 */
	public function test_apply_revert_post_nonexistent_returns_wp_error(): void {
		$change = $this->make_change( [
			'object_type'  => 'post',
			'object_id'    => 999999,
			'field_name'   => 'post_title',
			'before_value' => 'Anything',
		] );

		$result = ChangeRevertService::apply_revert( $change );

		$this->assertWPError( $result );
	}

	// ── option revert (BUG-2) ────────────────────────────────────────────────

	/**
	 * apply_revert() restores a scalar option value.
	 */
	public function test_apply_revert_option_restores_scalar_value(): void {
		update_option( 'sd_test_blogname_revert', 'New Name' );

		$change = $this->make_change( [
			'object_type'  => 'option',
			'object_id'    => 0,
			'field_name'   => 'sd_test_blogname_revert',
			'before_value' => 'Old Name',
		] );

		$result = ChangeRevertService::apply_revert( $change );

		$this->assertTrue( $result );
		$this->assertSame( 'Old Name', get_option( 'sd_test_blogname_revert' ) );

		delete_option( 'sd_test_blogname_revert' );
	}

	/**
	 * apply_revert() correctly restores an array option that was stored via
	 * maybe_serialize() — the value must come back as a PHP array, not a
	 * serialised string.
	 *
	 * This validates the BUG-2 fix end-to-end: logger stores with
	 * maybe_serialize(), revert service restores with maybe_unserialize().
	 */
	public function test_apply_revert_option_restores_array_value(): void {
		$option_name = 'sd_test_array_option_revert';
		$original    = [ 'mode' => 'dark', 'count' => 7 ];

		// Store the option's before_value as maybe_serialize() produces.
		$serialised_before = maybe_serialize( $original );

		update_option( $option_name, [ 'mode' => 'light', 'count' => 9 ] );

		$change = $this->make_change( [
			'object_type'  => 'option',
			'object_id'    => 0,
			'field_name'   => $option_name,
			'before_value' => $serialised_before,
		] );

		$result = ChangeRevertService::apply_revert( $change );

		$this->assertTrue( $result );
		$restored = get_option( $option_name );
		$this->assertIsArray( $restored, 'Restored value must be a PHP array, not a serialised string.' );
		$this->assertSame( 'dark', $restored['mode'] );
		$this->assertSame( 7, $restored['count'] );

		delete_option( $option_name );
	}

	// ── term revert ──────────────────────────────────────────────────────────

	/**
	 * apply_revert() restores a term name to its before_value.
	 */
	public function test_apply_revert_term_restores_name(): void {
		$term = self::factory()->term->create_and_get( [
			'taxonomy' => 'category',
			'name'     => 'New Category Name',
		] );

		$change = $this->make_change( [
			'object_type'  => 'term',
			'object_id'    => $term->term_id,
			'field_name'   => 'category',
			'before_value' => 'Original Category Name',
		] );

		$result = ChangeRevertService::apply_revert( $change );

		$this->assertTrue( $result );
		$restored_term = get_term( $term->term_id, 'category' );
		$this->assertSame( 'Original Category Name', $restored_term->name );
	}

	// ── user revert ──────────────────────────────────────────────────────────

	/**
	 * apply_revert() restores a user's display_name to its before_value.
	 */
	public function test_apply_revert_user_restores_display_name(): void {
		$user_id = self::factory()->user->create( [ 'display_name' => 'New Name' ] );

		$change = $this->make_change( [
			'object_type'  => 'user',
			'object_id'    => $user_id,
			'field_name'   => 'display_name',
			'before_value' => 'Old Name',
		] );

		$result = ChangeRevertService::apply_revert( $change );

		$this->assertTrue( $result );
		$user = get_userdata( $user_id );
		$this->assertSame( 'Old Name', $user->display_name );
	}

	// ── unsupported type ─────────────────────────────────────────────────────

	/**
	 * apply_revert() returns WP_Error for an unknown object type with no filter
	 * handler registered.
	 */
	public function test_apply_revert_unknown_type_returns_wp_error(): void {
		$change = $this->make_change( [
			'object_type'  => 'totally_unknown_type_xyz',
			'object_id'    => 1,
			'field_name'   => 'some_field',
			'before_value' => 'anything',
		] );

		$result = ChangeRevertService::apply_revert( $change );

		$this->assertWPError( $result );
		$this->assertSame( 'unsupported_object_type', $result->get_error_code() );
	}

	/**
	 * apply_revert() delegates unknown types to the sd_ai_agent_revert_change
	 * filter, allowing third-party code to handle custom object types.
	 */
	public function test_apply_revert_unknown_type_respects_filter(): void {
		$filter_called = false;
		$filter        = function ( $default, $change ) use ( &$filter_called ) {
			$filter_called = true;
			return true; // Indicate success.
		};
		add_filter( 'sd_ai_agent_revert_change', $filter, 10, 2 );

		$change = $this->make_change( [
			'object_type'  => 'totally_unknown_type_xyz',
			'before_value' => 'anything',
		] );

		$result = ChangeRevertService::apply_revert( $change );

		remove_filter( 'sd_ai_agent_revert_change', $filter );

		$this->assertTrue( $filter_called, 'The sd_ai_agent_revert_change filter should have been called.' );
		$this->assertTrue( $result );
	}

	// ── file revert ──────────────────────────────────────────────────────────

	/**
	 * apply_revert() deletes a newly-written file (before_value is empty).
	 */
	public function test_apply_revert_file_new_write_deletes_file(): void {
		// Create a temporary test file in wp-content.
		$test_file = WP_CONTENT_DIR . '/test-revert-new-file.txt';
		file_put_contents( $test_file, 'Test content' );
		$this->assertTrue( file_exists( $test_file ), 'Test file should exist before revert.' );

		$change = $this->make_change( [
			'object_type'  => 'file',
			'object_id'    => 0,
			'object_title' => 'test-revert-new-file.txt',
			'field_name'   => $test_file,
			'before_value' => '',
			'after_value'  => 'Test content',
		] );

		$result = ChangeRevertService::apply_revert( $change );

		$this->assertTrue( $result );
		$this->assertFalse( file_exists( $test_file ), 'File should be deleted after revert.' );
	}

	/**
	 * apply_revert() restores prior content for an edited file.
	 */
	public function test_apply_revert_file_edit_restores_content(): void {
		// Create a temporary test file.
		$test_file = WP_CONTENT_DIR . '/test-revert-edit-file.txt';
		$original  = "Line 1\nLine 2\nLine 3";
		$edited    = "Line 1\nLine 2 EDITED\nLine 3";
		file_put_contents( $test_file, $edited );

		$change = $this->make_change( [
			'object_type'  => 'file',
			'object_id'    => 0,
			'object_title' => 'test-revert-edit-file.txt',
			'field_name'   => $test_file,
			'before_value' => $original,
			'after_value'  => $edited,
		] );

		$result = ChangeRevertService::apply_revert( $change );

		$this->assertTrue( $result );
		$this->assertTrue( file_exists( $test_file ), 'File should still exist after revert.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local test file.
		$restored = file_get_contents( $test_file );
		$this->assertSame( $original, $restored, 'File content should be restored to before_value.' );

		// Cleanup.
		unlink( $test_file );
	}

	/**
	 * apply_revert() recreates a deleted file.
	 */
	public function test_apply_revert_file_delete_recreates_file(): void {
		$test_file = WP_CONTENT_DIR . '/test-revert-deleted-file.txt';
		$original  = 'Original file content';

		// Ensure file does not exist before the test.
		if ( file_exists( $test_file ) ) {
			unlink( $test_file );
		}

		$change = $this->make_change( [
			'object_type'  => 'file',
			'object_id'    => 0,
			'object_title' => 'test-revert-deleted-file.txt',
			'field_name'   => $test_file,
			'before_value' => $original,
			'after_value'  => '',
		] );

		$result = ChangeRevertService::apply_revert( $change );

		$this->assertTrue( $result );
		$this->assertTrue( file_exists( $test_file ), 'File should be recreated after revert.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local test file.
		$restored = file_get_contents( $test_file );
		$this->assertSame( $original, $restored, 'File content should match before_value.' );

		// Cleanup.
		unlink( $test_file );
	}

	/**
	 * apply_revert() returns WP_Error when file path is outside wp-content.
	 */
	public function test_apply_revert_file_path_outside_wpcontent_returns_error(): void {
		$change = $this->make_change( [
			'object_type'  => 'file',
			'object_id'    => 0,
			'field_name'   => '/etc/passwd',
			'before_value' => 'content',
		] );

		$result = ChangeRevertService::apply_revert( $change );

		$this->assertWPError( $result );
		$this->assertSame( 'file_revert_path_outside_wpcontent', $result->get_error_code() );
	}

	/**
	 * apply_revert() returns WP_Error when file path is empty.
	 */
	public function test_apply_revert_file_empty_path_returns_error(): void {
		$change = $this->make_change( [
			'object_type'  => 'file',
			'object_id'    => 0,
			'field_name'   => '',
			'before_value' => 'content',
		] );

		$result = ChangeRevertService::apply_revert( $change );

		$this->assertWPError( $result );
		$this->assertSame( 'file_revert_no_path', $result->get_error_code() );
	}
}
