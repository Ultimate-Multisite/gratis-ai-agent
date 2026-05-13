<?php
/**
 * Test case for ContentAbilities class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\ContentAbilities;
use WP_UnitTestCase;

/**
 * Test ContentAbilities handler methods.
 */
class ContentAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Test handle_content_analyze with no posts returns empty message.
	 */
	public function test_handle_content_analyze_no_posts() {
		$result = ContentAbilities::handle_content_analyze( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'post_type', $result );
		$this->assertSame( 'post', $result['post_type'] );
	}

	/**
	 * Test handle_content_analyze with published posts returns analysis.
	 */
	public function test_handle_content_analyze_with_posts() {
		// Create some test posts.
		$this->factory->post->create_many( 3, [
			'post_status'  => 'publish',
			'post_content' => 'This is test content with enough words to count properly for the analysis.',
		] );

		$result = ContentAbilities::handle_content_analyze( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'total_analyzed', $result );
		$this->assertGreaterThanOrEqual( 3, $result['total_analyzed'] );
		$this->assertArrayHasKey( 'avg_word_count', $result );
		$this->assertArrayHasKey( 'min_word_count', $result );
		$this->assertArrayHasKey( 'max_word_count', $result );
		$this->assertArrayHasKey( 'posts_without_featured_image', $result );
		$this->assertArrayHasKey( 'posts_without_meta_description', $result );
		$this->assertArrayHasKey( 'thin_content_count', $result );
	}

	/**
	 * Test handle_content_analyze with custom post_type.
	 */
	public function test_handle_content_analyze_custom_post_type() {
		$result = ContentAbilities::handle_content_analyze( [
			'post_type' => 'page',
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'page', $result['post_type'] );
	}

	/**
	 * Test handle_content_analyze with limit parameter.
	 */
	public function test_handle_content_analyze_with_limit() {
		// Create 5 posts.
		$this->factory->post->create_many( 5, [
			'post_status' => 'publish',
		] );

		$result = ContentAbilities::handle_content_analyze( [
			'limit' => 2,
		] );

		$this->assertIsArray( $result );
		if ( isset( $result['total_analyzed'] ) ) {
			$this->assertLessThanOrEqual( 2, $result['total_analyzed'] );
		}
	}

	/**
	 * Test handle_content_analyze avg_word_count is integer.
	 */
	public function test_handle_content_analyze_word_count_is_int() {
		$this->factory->post->create( [
			'post_status'  => 'publish',
			'post_content' => 'Word one two three four five six seven eight nine ten.',
		] );

		$result = ContentAbilities::handle_content_analyze( [] );

		if ( isset( $result['avg_word_count'] ) ) {
			$this->assertIsInt( $result['avg_word_count'] );
		}
	}

	/**
	 * Test handle_content_analyze posts_without_featured_image is array.
	 */
	public function test_handle_content_analyze_without_featured_image_is_array() {
		$this->factory->post->create( [
			'post_status' => 'publish',
		] );

		$result = ContentAbilities::handle_content_analyze( [] );

		if ( isset( $result['posts_without_featured_image'] ) ) {
			$this->assertIsArray( $result['posts_without_featured_image'] );
		}
	}

	// ─── performance report ───────────────────────────────────────

	/**
	 * Test handle_performance_report returns expected structure.
	 */
	public function test_handle_performance_report_structure() {
		$result = ContentAbilities::handle_performance_report( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'period_days', $result );
		$this->assertArrayHasKey( 'posts_published', $result );
		$this->assertArrayHasKey( 'previous_period_published', $result );
		$this->assertArrayHasKey( 'avg_word_count', $result );
		$this->assertArrayHasKey( 'posts_by_category', $result );
		$this->assertArrayHasKey( 'posts_by_author', $result );
		$this->assertArrayHasKey( 'all_posts_by_status', $result );
		$this->assertArrayHasKey( 'drafts_pending_review', $result );
		$this->assertArrayHasKey( 'drafts_pending_count', $result );
	}

	/**
	 * Test handle_performance_report default period is 30 days.
	 */
	public function test_handle_performance_report_default_period() {
		$result = ContentAbilities::handle_performance_report( [] );

		$this->assertSame( 30, $result['period_days'] );
	}

	/**
	 * Test handle_performance_report with custom days.
	 */
	public function test_handle_performance_report_custom_days() {
		$result = ContentAbilities::handle_performance_report( [
			'days' => 7,
		] );

		$this->assertSame( 7, $result['period_days'] );
	}

	/**
	 * Test handle_performance_report posts_published is integer.
	 */
	public function test_handle_performance_report_posts_published_is_int() {
		$result = ContentAbilities::handle_performance_report( [] );

		$this->assertIsInt( $result['posts_published'] );
		$this->assertGreaterThanOrEqual( 0, $result['posts_published'] );
	}

	/**
	 * Test handle_performance_report counts recently published posts.
	 */
	public function test_handle_performance_report_counts_recent_posts() {
		// Create a post published now.
		$this->factory->post->create( [
			'post_status' => 'publish',
			'post_date'   => gmdate( 'Y-m-d H:i:s' ),
		] );

		$result = ContentAbilities::handle_performance_report( [
			'days' => 30,
		] );

		$this->assertGreaterThanOrEqual( 1, $result['posts_published'] );
	}

	/**
	 * Test handle_performance_report drafts_pending_count matches list.
	 */
	public function test_handle_performance_report_drafts_count_matches_list() {
		$result = ContentAbilities::handle_performance_report( [] );

		$this->assertSame(
			count( $result['drafts_pending_review'] ),
			$result['drafts_pending_count']
		);
	}

	/**
	 * Test handle_performance_report days clamped to 1 minimum.
	 */
	public function test_handle_performance_report_days_minimum() {
		$result = ContentAbilities::handle_performance_report( [
			'days' => 0,
		] );

		$this->assertSame( 1, $result['period_days'] );
	}

	/**
	 * Test handle_performance_report days clamped to 365 maximum.
	 */
	public function test_handle_performance_report_days_maximum() {
		$result = ContentAbilities::handle_performance_report( [
			'days' => 9999,
		] );

		$this->assertSame( 365, $result['period_days'] );
	}

	// ─── create contact form ───────────────────────────────────────

	/**
	 * Test handle_create_contact_form returns a dependency-free HTML block fallback.
	 */
	public function test_handle_create_contact_form_returns_html_block_fallback() {
		$result = ContentAbilities::handle_create_contact_form( [
			'title'           => 'Get In Touch',
			'recipient_email' => 'hello@example.test',
			'submit_label'    => 'Send Now',
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'html', $result['provider'] );
		$this->assertSame( 'Get In Touch', $result['title'] );
		$this->assertSame( '', $result['shortcode'] );
		$this->assertSame( 0, $result['form_id'] );
		$this->assertStringContainsString( '<!-- wp:html -->', $result['block'] );
		$this->assertStringContainsString( 'sd-ai-agent-contact-form', $result['block'] );
		$this->assertStringContainsString( 'Send Now', $result['block'] );
	}

	/**
	 * Test handle_create_contact_form falls back to defaults for empty inputs.
	 */
	public function test_handle_create_contact_form_uses_defaults_for_empty_inputs() {
		$result = ContentAbilities::handle_create_contact_form( [
			'title'           => '',
			'recipient_email' => 'not-an-email',
			'submit_label'    => '',
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'Contact Form', $result['title'] );
		$this->assertStringContainsString( 'Send Message', $result['block'] );
	}
}
