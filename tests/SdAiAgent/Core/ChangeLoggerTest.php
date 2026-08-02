<?php

declare(strict_types=1);
/**
 * Test case for ChangeLogger class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\ChangeLogger;
use SdAiAgent\Models\ChangesLog;
use WP_UnitTestCase;

/**
 * Test ChangeLogger functionality.
 */
class ChangeLoggerTest extends WP_UnitTestCase {

	/**
	 * Reset static state before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		ChangeLogger::end();
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i',
				$wpdb->prefix . 'sd_ai_agent_changes_log'
			)
		);
	}

	/**
	 * Reset static state after each test.
	 */
	public function tear_down(): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i',
				$wpdb->prefix . 'sd_ai_agent_changes_log'
			)
		);
		ChangeLogger::end();
		parent::tear_down();
	}

	// ── begin / end / is_active ───────────────────────────────────────────────

	/**
	 * is_active() returns false before begin() is called.
	 */
	public function test_is_active_returns_false_initially(): void {
		$this->assertFalse( ChangeLogger::is_active() );
	}

	/**
	 * is_active() returns true after begin().
	 */
	public function test_is_active_returns_true_after_begin(): void {
		ChangeLogger::begin( 1, 'test_ability' );
		$this->assertTrue( ChangeLogger::is_active() );
	}

	/**
	 * is_active() returns false after end().
	 */
	public function test_is_active_returns_false_after_end(): void {
		ChangeLogger::begin( 1, 'test_ability' );
		ChangeLogger::end();
		$this->assertFalse( ChangeLogger::is_active() );
	}

	/**
	 * begin() with no arguments still activates logging.
	 */
	public function test_begin_with_defaults_activates_logging(): void {
		ChangeLogger::begin();
		$this->assertTrue( ChangeLogger::is_active() );
	}

	// ── register ─────────────────────────────────────────────────────────────

	/**
	 * register() hooks into the expected WordPress actions including the new
	 * edit_terms pre-save hook required to capture before-values for terms.
	 */
	public function test_register_adds_expected_hooks(): void {
		ChangeLogger::register();

		$this->assertNotFalse( has_action( 'post_updated', [ ChangeLogger::class, 'on_post_updated' ] ) );
		$this->assertNotFalse( has_action( 'updated_option', [ ChangeLogger::class, 'on_updated_option' ] ) );
		$this->assertNotFalse( has_action( 'added_option', [ ChangeLogger::class, 'on_added_option' ] ) );
		$this->assertNotFalse( has_action( 'edit_terms', [ ChangeLogger::class, 'on_edit_terms' ] ) );
		$this->assertNotFalse( has_action( 'edited_term', [ ChangeLogger::class, 'on_edited_term' ] ) );
		$this->assertNotFalse( has_action( 'profile_update', [ ChangeLogger::class, 'on_profile_update' ] ) );
	}

	// ── on_post_updated ───────────────────────────────────────────────────────

	/**
	 * on_post_updated() does nothing when logging is inactive.
	 */
	public function test_on_post_updated_skips_when_inactive(): void {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Before' ] );
		$before  = get_post( $post_id );
		$after   = clone $before;
		/** @var \WP_Post $after */
		$after->post_title = 'After';

		// Logging is inactive — no DB write should occur.
		ChangeLogger::on_post_updated( $post_id, $after, $before );

		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE object_id = %d',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				$post_id
			)
		);
		$this->assertSame( '0', (string) $count );
	}

	/** Autosave revisions are working copies, not user-visible committed changes. */
	public function test_on_post_updated_skips_revision_working_copies(): void {
		ChangeLogger::begin( 42, 'sd-ai-agent/update-post' );
		$post_id = self::factory()->post->create( array( 'post_title' => 'Parent' ) );
		$before  = clone get_post( $post_id );
		$after   = clone $before;
		$before->post_type  = 'revision';
		$after->post_type   = 'revision';
		$after->post_title  = 'Private preview';

		ChangeLogger::on_post_updated( $post_id, $after, $before );

		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE object_id = %d',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				$post_id
			)
		);
		$this->assertSame( '0', (string) $count );
	}

	/**
	 * on_post_updated() records a change when logging is active and title differs.
	 * object_type must reflect the actual post_type, not the hardcoded string 'post'.
	 */
	public function test_on_post_updated_records_title_change_when_active(): void {
		ChangeLogger::begin( 42, 'update_post' );

		$post_id = self::factory()->post->create( [ 'post_title' => 'Original Title' ] );
		$before  = get_post( $post_id );
		$after   = clone $before;
		/** @var \WP_Post $after */
		$after->post_title = 'Updated Title';

		ChangeLogger::on_post_updated( $post_id, $after, $before );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE object_id = %d AND field_name = %s',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				$post_id,
				'post_title'
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( 'Original Title', $row->before_value );
		$this->assertSame( 'Updated Title', $row->after_value );
		$this->assertSame( '42', (string) $row->session_id );
		$this->assertSame( 'update_post', $row->ability_name );
		// BUG-4 fix: object_type should be the actual post_type, not hardcoded 'post'.
		$this->assertSame( 'post', $row->object_type );
	}

	/**
	 * on_post_updated() uses the actual post_type as object_type for pages.
	 */
	public function test_on_post_updated_uses_actual_post_type_for_pages(): void {
		ChangeLogger::begin( 1, 'update_page' );

		$page_id = self::factory()->post->create( [
			'post_title' => 'Original Page',
			'post_type'  => 'page',
		] );
		$before = get_post( $page_id );
		$after  = clone $before;
		/** @var \WP_Post $after */
		$after->post_title = 'Updated Page';

		ChangeLogger::on_post_updated( $page_id, $after, $before );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE object_id = %d AND field_name = %s',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				$page_id,
				'post_title'
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( 'page', $row->object_type );
	}

	/**
	 * on_post_updated() does not record a change when field values are identical.
	 */
	public function test_on_post_updated_skips_unchanged_fields(): void {
		ChangeLogger::begin( 1, 'test' );

		$post_id = self::factory()->post->create( [ 'post_title' => 'Same Title' ] );
		$before  = get_post( $post_id );
		$after   = clone $before;
		// No change to any tracked field.

		ChangeLogger::on_post_updated( $post_id, $after, $before );

		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE object_id = %d',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				$post_id
			)
		);
		$this->assertSame( '0', (string) $count );
	}

	// ── on_updated_option ─────────────────────────────────────────────────────

	/**
	 * on_updated_option() skips when logging is inactive.
	 */
	public function test_on_updated_option_skips_when_inactive(): void {
		ChangeLogger::on_updated_option( 'blogname', 'Old Name', 'New Name' );

		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE field_name = %s',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				'blogname'
			)
		);
		$this->assertSame( '0', (string) $count );
	}

	/**
	 * on_updated_option() records a change when active.
	 */
	public function test_on_updated_option_records_change_when_active(): void {
		ChangeLogger::begin( 5, 'update_option' );

		ChangeLogger::on_updated_option( 'blogname', 'Old Name', 'New Name' );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE field_name = %s',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				'blogname'
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( 'Old Name', $row->before_value );
		$this->assertSame( 'New Name', $row->after_value );
	}

	/**
	 * on_updated_option() skips transient options.
	 */
	public function test_on_updated_option_skips_transients(): void {
		ChangeLogger::begin( 1, 'test' );

		ChangeLogger::on_updated_option( '_transient_some_key', 'old', 'new' );

		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE field_name = %s',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				'_transient_some_key'
			)
		);
		$this->assertSame( '0', (string) $count );
	}

	/**
	 * on_updated_option() skips sd_ai_agent_ prefixed options.
	 */
	public function test_on_updated_option_skips_plugin_internal_options(): void {
		ChangeLogger::begin( 1, 'test' );

		ChangeLogger::on_updated_option( 'sd_ai_agent_settings', 'old', 'new' );

		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE field_name = %s',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				'sd_ai_agent_settings'
			)
		);
		$this->assertSame( '0', (string) $count );
	}

	/**
	 * on_updated_option() redacts sensitive option values.
	 */
	public function test_on_updated_option_redacts_sensitive_options(): void {
		ChangeLogger::begin( 1, 'test' );

		ChangeLogger::on_updated_option( 'my_api_key', 'old-secret', 'new-secret' );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE field_name = %s',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				'my_api_key'
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( '[REDACTED]', $row->before_value );
		$this->assertSame( '[REDACTED]', $row->after_value );
	}

	/**
	 * on_updated_option() skips when old and new values are identical.
	 */
	public function test_on_updated_option_skips_identical_values(): void {
		ChangeLogger::begin( 1, 'test' );

		ChangeLogger::on_updated_option( 'blogname', 'Same', 'Same' );

		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE field_name = %s',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				'blogname'
			)
		);
		$this->assertSame( '0', (string) $count );
	}

	/**
	 * on_updated_option() stores array values via maybe_serialize() so they
	 * can be correctly restored by maybe_unserialize() in the revert service.
	 *
	 * Scalar strings must be stored verbatim (maybe_serialize is a no-op for
	 * scalars), so existing scalar-value records remain compatible.
	 */
	public function test_on_updated_option_serializes_array_values(): void {
		ChangeLogger::begin( 1, 'test' );

		$old_array = [ 'key' => 'old_val', 'count' => 3 ];
		$new_array = [ 'key' => 'new_val', 'count' => 5 ];

		ChangeLogger::on_updated_option( 'my_plugin_settings', $old_array, $new_array );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE field_name = %s',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				'my_plugin_settings'
			)
		);

		$this->assertNotNull( $row );
		// Stored value must be WordPress-serialised so maybe_unserialize() can decode it.
		$restored = maybe_unserialize( $row->before_value );
		$this->assertIsArray( $restored );
		$this->assertSame( 'old_val', $restored['key'] );
		$this->assertSame( 3, $restored['count'] );
	}

	// ── on_added_option ───────────────────────────────────────────────────────

	/**
	 * on_added_option() records a new option when active.
	 */
	public function test_on_added_option_records_new_option_when_active(): void {
		ChangeLogger::begin( 3, 'add_option' );

		ChangeLogger::on_added_option( 'my_new_setting', 'hello' );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE field_name = %s',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				'my_new_setting'
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( '', $row->before_value );
		$this->assertSame( 'hello', $row->after_value );
	}

	/**
	 * on_added_option() skips when inactive.
	 */
	public function test_on_added_option_skips_when_inactive(): void {
		ChangeLogger::on_added_option( 'my_new_setting', 'hello' );

		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE field_name = %s',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				'my_new_setting'
			)
		);
		$this->assertSame( '0', (string) $count );
	}

	/**
	 * on_added_option() redacts sensitive option values.
	 */
	public function test_on_added_option_redacts_sensitive_options(): void {
		ChangeLogger::begin( 1, 'test' );

		ChangeLogger::on_added_option( 'stripe_secret_key', 'sk_live_secret' );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE field_name = %s',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				'stripe_secret_key'
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( '[REDACTED]', $row->after_value );
	}

	// ── on_edit_terms / on_edited_term ───────────────────────────────────────

	/**
	 * on_edited_term() skips when inactive.
	 */
	public function test_on_edited_term_skips_when_inactive(): void {
		$term = self::factory()->term->create_and_get( [ 'taxonomy' => 'category' ] );

		// Neither pre-save nor post-save hooks should record when inactive.
		ChangeLogger::on_edit_terms( $term->term_id, 'category' );
		ChangeLogger::on_edited_term( $term->term_id, $term->term_taxonomy_id, 'category' );

		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE object_type = %s',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				'term'
			)
		);
		$this->assertSame( '0', (string) $count );
	}

	/**
	 * Newly created terms can be logged explicitly while an AI ability is active.
	 */
	public function test_record_term_created_records_an_auditable_marker(): void {
		$term = self::factory()->term->create_and_get(
			[
				'taxonomy' => 'category',
				'name'     => 'Created Category',
			]
		);

		ChangeLogger::begin( 14, 'sd-ai-agent/commerce-execute-approved-plan' );
		ChangeLogger::record_term_created( $term->term_id, 'category', $term->name );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE object_type = %s AND object_id = %d',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				'term',
				$term->term_id
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( '', $row->before_value );
		$this->assertSame( 'Created Category', $row->after_value );
		$this->assertSame( '0', (string) $row->revertable );
	}

	/**
	 * on_edited_term() records a term change with correct before_value when
	 * on_edit_terms() is called first to capture the old name.
	 *
	 * Simulates the full WordPress hook pair: edit_terms → edited_term.
	 */
	public function test_on_edited_term_captures_before_value(): void {
		$term = self::factory()->term->create_and_get(
			[
				'taxonomy' => 'category',
				'name'     => 'Original Name',
			]
		);

		ChangeLogger::begin( 7, 'edit_term' );

		// Step 1: pre-save hook captures the old name.
		ChangeLogger::on_edit_terms( $term->term_id, 'category' );

		// Step 2: perform the actual update, then fire the post-save hook.
		wp_update_term( $term->term_id, 'category', [ 'name' => 'Updated Name' ] );
		ChangeLogger::on_edited_term( $term->term_id, $term->term_taxonomy_id, 'category' );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE object_type = %s AND object_id = %d',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				'term',
				$term->term_id
			)
		);

		$this->assertNotNull( $row, 'A change record should have been created.' );
		$this->assertSame( 'Original Name', $row->before_value, 'before_value must be the old term name.' );
		$this->assertSame( 'Updated Name', $row->after_value, 'after_value must be the new term name.' );
		$this->assertSame( 'Updated Name', $row->object_title );
		$this->assertSame( 'category', $row->field_name );
	}

	/**
	 * on_edited_term() does NOT record when the name hasn't actually changed.
	 */
	public function test_on_edited_term_skips_when_name_unchanged(): void {
		$term = self::factory()->term->create_and_get(
			[
				'taxonomy' => 'category',
				'name'     => 'Same Name',
			]
		);

		ChangeLogger::begin( 1, 'test' );

		// Pre-save captures "Same Name"; post-save term is still "Same Name".
		ChangeLogger::on_edit_terms( $term->term_id, 'category' );
		ChangeLogger::on_edited_term( $term->term_id, $term->term_taxonomy_id, 'category' );

		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE object_type = %s AND object_id = %d',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				'term',
				$term->term_id
			)
		);
		$this->assertSame( '0', (string) $count, 'No record should be created when term name did not change.' );
	}

	/**
	 * on_edited_term() skips when term does not exist.
	 */
	public function test_on_edited_term_skips_nonexistent_term(): void {
		ChangeLogger::begin( 1, 'test' );

		ChangeLogger::on_edited_term( 999999, 999999, 'category' );

		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE object_id = %d',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				999999
			)
		);
		$this->assertSame( '0', (string) $count );
	}

	// ── on_profile_update ─────────────────────────────────────────────────────

	/**
	 * on_profile_update() skips when inactive.
	 */
	public function test_on_profile_update_skips_when_inactive(): void {
		$user_id  = self::factory()->user->create();
		$old_user = get_userdata( $user_id );

		ChangeLogger::on_profile_update( $user_id, $old_user );

		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE object_type = %s',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				'user'
			)
		);
		$this->assertSame( '0', (string) $count );
	}

	/**
	 * on_profile_update() records display_name change when active.
	 */
	public function test_on_profile_update_records_display_name_change(): void {
		ChangeLogger::begin( 9, 'update_user' );

		$user_id = self::factory()->user->create( [ 'display_name' => 'Old Name' ] );
		$old_user = get_userdata( $user_id );

		// Update display name.
		wp_update_user( [ 'ID' => $user_id, 'display_name' => 'New Name' ] );
		$new_user = get_userdata( $user_id );

		ChangeLogger::on_profile_update( $user_id, $old_user );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE object_type = %s AND object_id = %d AND field_name = %s',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				'user',
				$user_id,
				'display_name'
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( 'Old Name', $row->before_value );
	}

	/**
	 * on_profile_update() redacts email changes.
	 */
	public function test_on_profile_update_redacts_email(): void {
		ChangeLogger::begin( 9, 'update_user' );

		$user_id  = self::factory()->user->create( [ 'user_email' => 'old@example.com' ] );
		$old_user = get_userdata( $user_id );

		// Simulate email change by modifying old_user data.
		$old_user->user_email = 'old@example.com';
		wp_update_user( [ 'ID' => $user_id, 'user_email' => 'new@example.com' ] );

		ChangeLogger::on_profile_update( $user_id, $old_user );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE object_type = %s AND object_id = %d AND field_name = %s',
				$wpdb->prefix . 'sd_ai_agent_changes_log',
				'user',
				$user_id,
				'user_email'
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( '[REDACTED]', $row->before_value );
		$this->assertSame( '[REDACTED]', $row->after_value );
	}
}
