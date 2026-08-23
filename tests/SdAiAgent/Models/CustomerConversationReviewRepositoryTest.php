<?php

declare(strict_types=1);
/**
 * Tests for privacy-safe customer conversation review projections.
 *
 * @package SdAiAgent\Tests\Models
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Models;

use SdAiAgent\Core\Database;
use SdAiAgent\Models\CustomerConversationReviewRepository;
use WP_UnitTestCase;

/** Covers sanitization, redaction, projection boundaries, and tombstones. */
final class CustomerConversationReviewRepositoryTest extends WP_UnitTestCase {

	/** Install and clear the review tables before each test. */
	public function set_up(): void {
		parent::set_up();
		delete_option( Database::DB_VERSION_OPTION );
		Database::install();
		$this->clear_reviews();
	}

	/** Remove review rows after each test. */
	public function tear_down(): void {
		$this->clear_reviews();
		parent::tear_down();
	}

	/** Direct turns remove hidden reasoning and durable credential values. */
	public function test_direct_turns_strip_hidden_reasoning_and_redact_credentials(): void {
		$review_id = wp_generate_uuid4();
		$this->assertTrue(
			CustomerConversationReviewRepository::create_public_review(
				$review_id,
				7,
				'provider',
				'model',
				gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS )
			)
		);
		$this->assertTrue(
			CustomerConversationReviewRepository::append_public_turn(
				$review_id,
				'event-1',
				'assistant',
				'Visible answer <thinking>private chain of thought</thinking> Authorization: Bearer review-secret.',
				'complete'
			)
		);

		$review = CustomerConversationReviewRepository::get_review( $review_id );
		$this->assertIsArray( $review );
		$this->assertSame( 'Visible answer  Authorization: [redacted]', $review['transcript'][0]['content'] );
		$this->assertStringNotContainsString( 'private chain of thought', $review['transcript'][0]['content'] );
		$this->assertStringNotContainsString( 'review-secret', $review['transcript'][0]['content'] );
		$this->assertArrayNotHasKey( 'runtime_conversation_id', $review );
		$this->assertArrayNotHasKey( 'profile_id', $review );
	}

	/** Runtime summaries never expose the private source conversation identifier. */
	public function test_runtime_summary_omits_private_source_identifiers_and_transcripts(): void {
		$review_id       = wp_generate_uuid4();
		$conversation_id = wp_generate_uuid4();
		$this->assertTrue(
			CustomerConversationReviewRepository::create_runtime_review(
				$review_id,
				$conversation_id,
				'provider',
				'model',
				gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				42
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

		$summaries = CustomerConversationReviewRepository::list_reviews(
			array(
				'source' => CustomerConversationReviewRepository::SOURCE_CUSTOMER_RUNTIME,
				'agent'  => '42',
			)
		);
		$this->assertCount( 1, $summaries );
		$this->assertSame( $review_id, $summaries[0]['id'] );
		$this->assertArrayNotHasKey( 'runtime_conversation_id', $summaries[0] );
		$this->assertArrayNotHasKey( 'transcript', $summaries[0] );
		$this->assertStringNotContainsString( $conversation_id, (string) wp_json_encode( $summaries[0] ) );
		$this->assertCount(
			0,
			CustomerConversationReviewRepository::list_reviews(
				array( 'agent' => '43' )
			)
		);
	}

	/** Tombstones delete retained turns and prevent later retry writes from restoring them. */
	public function test_delete_tombstones_content_idempotently(): void {
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
		$this->assertTrue(
			CustomerConversationReviewRepository::append_public_turn(
				$review_id,
				'event-1',
				'user',
				'Remove this retained text.',
				'complete'
			)
		);

		$this->assertTrue( CustomerConversationReviewRepository::delete_review( $review_id ) );
		$this->assertTrue( CustomerConversationReviewRepository::delete_review( $review_id ) );
		$this->assertTrue(
			CustomerConversationReviewRepository::append_public_turn(
				$review_id,
				'event-1',
				'assistant',
				'Retry must not restore text.',
				'complete'
			)
		);
		$this->assertNull( CustomerConversationReviewRepository::get_review( $review_id ) );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verifies a test tombstone physically removed retained turn content.
		$turn_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE review_id = %s',
				CustomerConversationReviewRepository::turns_table_name(),
				$review_id
			)
		);
		$this->assertSame( 0, $turn_count );
	}

	/** Expired review content is physically removed while the shell becomes a tombstone. */
	public function test_expiry_cleanup_tombstones_retained_content(): void {
		$review_id = wp_generate_uuid4();
		$this->assertTrue(
			CustomerConversationReviewRepository::create_public_review(
				$review_id,
				0,
				'',
				'',
				gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
			)
		);
		$this->assertTrue(
			CustomerConversationReviewRepository::append_public_turn(
				$review_id,
				'event-1',
				'user',
				'Expired retained text.',
				'complete'
			)
		);

		$this->assertSame( 1, CustomerConversationReviewRepository::purge_expired_reviews() );
		$this->assertNull( CustomerConversationReviewRepository::get_review( $review_id ) );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verifies expiry cleanup removed bounded retained turn content.
		$turn_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE review_id = %s',
				CustomerConversationReviewRepository::turns_table_name(),
				$review_id
			)
		);
		$this->assertSame( 0, $turn_count );
	}

	/** Destroying a runtime source removes its review projection rather than retaining it. */
	public function test_runtime_source_cleanup_removes_its_review_projection(): void {
		$review_id       = wp_generate_uuid4();
		$conversation_id = wp_generate_uuid4();
		$this->assertTrue(
			CustomerConversationReviewRepository::create_runtime_review(
				$review_id,
				$conversation_id,
				'provider',
				'model',
				gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS )
			)
		);
		$this->assertTrue(
			CustomerConversationReviewRepository::append_runtime_turn(
				$conversation_id,
				'event-1',
				'user',
				'Runtime content to remove.',
				'complete'
			)
		);

		$this->assertTrue(
			CustomerConversationReviewRepository::delete_by_runtime_conversation(
				$conversation_id
			)
		);
		$this->assertNull( CustomerConversationReviewRepository::get_review( $review_id ) );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verifies source lifecycle cleanup removes the projection shell.
		$review_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE review_id = %s',
				CustomerConversationReviewRepository::table_name(),
				$review_id
			)
		);
		$this->assertSame( 0, $review_count );
	}

	/** Clear dependent turns before their review shells. */
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
