<?php

declare(strict_types=1);
/**
 * Tests for the customer conversation review controller.
 *
 * @package SdAiAgent\Tests\REST
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\REST;

use SdAiAgent\Core\Database;
use SdAiAgent\Models\CustomerConversationReviewRepository;
use SdAiAgent\REST\CustomerConversationController;
use WP_REST_Request;
use WP_UnitTestCase;

/** Covers administrator authorization and the safe review-controller DTO boundary. */
final class CustomerConversationControllerTest extends WP_UnitTestCase {

	private CustomerConversationController $controller;

	private int $admin_id;

	private int $subscriber_id;

	/** Set up the controller, custom tables, and test users. */
	public function set_up(): void {
		parent::set_up();
		delete_option( Database::DB_VERSION_OPTION );
		Database::install();
		$this->clear_reviews();
		$this->controller    = new CustomerConversationController();
		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/** Clear custom review rows. */
	public function tear_down(): void {
		$this->clear_reviews();
		parent::tear_down();
	}

	/** The shared controller guard accepts only administrators. */
	public function test_permission_guard_requires_manage_options(): void {
		wp_set_current_user( 0 );
		$this->assertFalse( $this->controller->check_permission() );

		wp_set_current_user( $this->subscriber_id );
		$this->assertFalse( $this->controller->check_permission() );

		wp_set_current_user( $this->admin_id );
		$this->assertTrue( $this->controller->check_permission() );
	}

	/** Admin list and detail responses exclude private runtime source identifiers. */
	public function test_admin_list_and_detail_return_only_sanitized_review_data(): void {
		wp_set_current_user( $this->admin_id );
		$review_id       = wp_generate_uuid4();
		$conversation_id = wp_generate_uuid4();
		$this->assertTrue(
			CustomerConversationReviewRepository::create_runtime_review(
				$review_id,
				$conversation_id,
				'provider',
				'model',
				gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				5
			)
		);
		$this->assertTrue(
			CustomerConversationReviewRepository::append_runtime_turn(
				$conversation_id,
				'event-1',
				'user',
				'Customer-visible question',
				'queued'
			)
		);

		$list = $this->controller->handle_list_customer_conversations(
			new WP_REST_Request( 'GET', '/sd-ai-agent/v1/customer-conversations' )
		);
		$this->assertSame( 200, $list->get_status() );
		$this->assertSame( $review_id, $list->get_data()['conversations'][0]['id'] );
		$this->assertStringNotContainsString( $conversation_id, (string) wp_json_encode( $list->get_data() ) );
		$this->assertArrayNotHasKey( 'transcript', $list->get_data()['conversations'][0] );

		$request = new WP_REST_Request(
			'GET',
			'/sd-ai-agent/v1/customer-conversations/' . $review_id
		);
		$request->set_param( 'id', $review_id );
		$detail = $this->controller->handle_get_customer_conversation( $request );
		$this->assertSame( 200, $detail->get_status() );
		$this->assertStringNotContainsString( $conversation_id, (string) wp_json_encode( $detail->get_data() ) );
		$this->assertSame( 'Customer-visible question', $detail->get_data()['transcript'][0]['content'] );
	}

	/** Purge rejects omitted confirmation and deletes reviews only after confirmation. */
	public function test_purge_requires_confirmation_and_tombstones_retained_reviews(): void {
		wp_set_current_user( $this->admin_id );
		$review_id = wp_generate_uuid4();
		$this->assertTrue(
			CustomerConversationReviewRepository::create_public_review(
				$review_id,
				0,
				'',
				'',
				gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS )
			)
		);

		$unconfirmed = $this->controller->handle_purge_customer_conversations(
			new WP_REST_Request( 'POST', '/sd-ai-agent/v1/customer-conversations/purge' )
		);
		$this->assertWPError( $unconfirmed );
		$this->assertSame(
			'sd_ai_agent_customer_conversation_purge_confirmation_required',
			$unconfirmed->get_error_code()
		);

		$request = new WP_REST_Request(
			'POST',
			'/sd-ai-agent/v1/customer-conversations/purge'
		);
		$request->set_param( 'confirm', true );
		$request->set_param( 'limit', 1 );
		$confirmed = $this->controller->handle_purge_customer_conversations( $request );
		$this->assertSame( 200, $confirmed->get_status() );
		$this->assertSame( 1, $confirmed->get_data()['purged'] );
		$this->assertNull( CustomerConversationReviewRepository::get_review( $review_id ) );
	}

	/** Remove dependent turns before review shells. */
	private function clear_reviews(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-only cleanup of minimized review turns.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i',
				CustomerConversationReviewRepository::turns_table_name()
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-only cleanup of review shells.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i',
				CustomerConversationReviewRepository::table_name()
			)
		);
	}
}
