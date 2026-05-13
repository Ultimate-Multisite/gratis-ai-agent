<?php
/**
 * Test case for SiteScanner class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\SiteScanner;
use WP_UnitTestCase;

/**
 * Test SiteScanner functionality.
 */
class SiteScannerTest extends WP_UnitTestCase {

	/**
	 * Clean up options and scheduled events after each test.
	 */
	public function tear_down(): void {
		parent::tear_down();
		delete_option( SiteScanner::STATUS_OPTION );
		$ts = wp_next_scheduled( SiteScanner::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, SiteScanner::CRON_HOOK );
		}
	}

	// ── constants ─────────────────────────────────────────────────────────

	/**
	 * Test CRON_HOOK constant.
	 */
	public function test_cron_hook_constant(): void {
		$this->assertSame( 'sd_ai_agent_site_scan', SiteScanner::CRON_HOOK );
	}

	/**
	 * Test STATUS_OPTION constant.
	 */
	public function test_status_option_constant(): void {
		$this->assertSame( 'sd_ai_agent_onboarding_scan', SiteScanner::STATUS_OPTION );
	}

	/**
	 * Test KNOWLEDGE_SEED_LIMIT constant.
	 */
	public function test_knowledge_seed_limit_constant(): void {
		$this->assertSame( 50, SiteScanner::KNOWLEDGE_SEED_LIMIT );
	}

	// ── get_status ────────────────────────────────────────────────────────

	/**
	 * Test get_status returns empty array when no option set.
	 */
	public function test_get_status_returns_empty_array_when_no_option(): void {
		delete_option( SiteScanner::STATUS_OPTION );
		$status = SiteScanner::get_status();
		$this->assertIsArray( $status );
		$this->assertEmpty( $status );
	}

	/**
	 * Test get_status returns saved status.
	 */
	public function test_get_status_returns_saved_status(): void {
		update_option(
			SiteScanner::STATUS_OPTION,
			[
				'status'     => 'complete',
				'site_type'  => 'blog',
				'post_count' => 42,
			]
		);

		$status = SiteScanner::get_status();

		$this->assertIsArray( $status );
		$this->assertSame( 'complete', $status['status'] );
		$this->assertSame( 'blog', $status['site_type'] );
	}

	/**
	 * Test get_status returns empty array when option is not an array.
	 */
	public function test_get_status_returns_empty_array_when_not_array(): void {
		update_option( SiteScanner::STATUS_OPTION, 'invalid' );
		$status = SiteScanner::get_status();
		$this->assertIsArray( $status );
		$this->assertEmpty( $status );
	}

	// ── is_complete ───────────────────────────────────────────────────────

	/**
	 * Test is_complete returns false when no status.
	 */
	public function test_is_complete_returns_false_when_no_status(): void {
		delete_option( SiteScanner::STATUS_OPTION );
		$this->assertFalse( SiteScanner::is_complete() );
	}

	/**
	 * Test is_complete returns true when status is complete.
	 */
	public function test_is_complete_returns_true_when_complete(): void {
		update_option( SiteScanner::STATUS_OPTION, [ 'status' => 'complete' ] );
		$this->assertTrue( SiteScanner::is_complete() );
	}

	/**
	 * Test is_complete returns false when status is running.
	 */
	public function test_is_complete_returns_false_when_running(): void {
		update_option( SiteScanner::STATUS_OPTION, [ 'status' => 'running' ] );
		$this->assertFalse( SiteScanner::is_complete() );
	}

	/**
	 * Test is_complete returns false when status is error.
	 */
	public function test_is_complete_returns_false_when_error(): void {
		update_option( SiteScanner::STATUS_OPTION, [ 'status' => 'error', 'error' => 'Something failed' ] );
		$this->assertFalse( SiteScanner::is_complete() );
	}

	// ── is_pending ────────────────────────────────────────────────────────

	/**
	 * Test is_pending returns false when no status.
	 */
	public function test_is_pending_returns_false_when_no_status(): void {
		delete_option( SiteScanner::STATUS_OPTION );
		$this->assertFalse( SiteScanner::is_pending() );
	}

	/**
	 * Test is_pending returns true when status is pending.
	 */
	public function test_is_pending_returns_true_when_pending(): void {
		update_option( SiteScanner::STATUS_OPTION, [ 'status' => 'pending' ] );
		$this->assertTrue( SiteScanner::is_pending() );
	}

	/**
	 * Test is_pending returns true when status is running.
	 */
	public function test_is_pending_returns_true_when_running(): void {
		update_option( SiteScanner::STATUS_OPTION, [ 'status' => 'running' ] );
		$this->assertTrue( SiteScanner::is_pending() );
	}

	/**
	 * Test is_pending returns false when status is complete.
	 */
	public function test_is_pending_returns_false_when_complete(): void {
		update_option( SiteScanner::STATUS_OPTION, [ 'status' => 'complete' ] );
		$this->assertFalse( SiteScanner::is_pending() );
	}

	// ── collect ───────────────────────────────────────────────────────────

	/**
	 * Test collect returns array with expected keys.
	 */
	public function test_collect_returns_array_with_expected_keys(): void {
		$data = SiteScanner::collect();

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'site_name', $data );
		$this->assertArrayHasKey( 'site_url', $data );
		$this->assertArrayHasKey( 'site_tagline', $data );
		$this->assertArrayHasKey( 'wp_version', $data );
		$this->assertArrayHasKey( 'language', $data );
		$this->assertArrayHasKey( 'active_theme', $data );
		$this->assertArrayHasKey( 'active_plugins', $data );
		$this->assertArrayHasKey( 'post_types', $data );
		$this->assertArrayHasKey( 'post_count', $data );
		$this->assertArrayHasKey( 'categories', $data );
		$this->assertArrayHasKey( 'woocommerce', $data );
		$this->assertArrayHasKey( 'site_type', $data );
	}

	/**
	 * Test collect returns string for site_name.
	 */
	public function test_collect_site_name_is_string(): void {
		$data = SiteScanner::collect();
		$this->assertIsString( $data['site_name'] );
	}

	/**
	 * Test collect returns string for site_url.
	 */
	public function test_collect_site_url_is_string(): void {
		$data = SiteScanner::collect();
		$this->assertIsString( $data['site_url'] );
		$this->assertNotEmpty( $data['site_url'] );
	}

	/**
	 * Test collect returns array for active_theme with expected keys.
	 */
	public function test_collect_active_theme_has_expected_keys(): void {
		$data  = SiteScanner::collect();
		$theme = $data['active_theme'];

		$this->assertIsArray( $theme );
		$this->assertArrayHasKey( 'name', $theme );
		$this->assertArrayHasKey( 'version', $theme );
		$this->assertArrayHasKey( 'author', $theme );
	}

	/**
	 * Test collect returns array for active_plugins.
	 */
	public function test_collect_active_plugins_is_array(): void {
		$data = SiteScanner::collect();
		$this->assertIsArray( $data['active_plugins'] );
	}

	/**
	 * Test collect returns array for post_types containing post and page.
	 */
	public function test_collect_post_types_contains_post_and_page(): void {
		$data = SiteScanner::collect();

		$this->assertIsArray( $data['post_types'] );
		$this->assertContains( 'post', $data['post_types'] );
		$this->assertContains( 'page', $data['post_types'] );
	}

	/**
	 * Test collect returns integer for post_count.
	 */
	public function test_collect_post_count_is_integer(): void {
		$data = SiteScanner::collect();
		$this->assertIsInt( $data['post_count'] );
		$this->assertGreaterThanOrEqual( 0, $data['post_count'] );
	}

	/**
	 * Test collect returns array for categories.
	 */
	public function test_collect_categories_is_array(): void {
		$data = SiteScanner::collect();
		$this->assertIsArray( $data['categories'] );
	}

	/**
	 * Test collect woocommerce returns active=false when WooCommerce not installed.
	 */
	public function test_collect_woocommerce_inactive_when_not_installed(): void {
		$data = SiteScanner::collect();

		// WooCommerce is not installed in test environment.
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'get_woocommerce_currency' ) ) {
			$this->assertIsArray( $data['woocommerce'] );
			$this->assertFalse( $data['woocommerce']['active'] );
		} else {
			$this->assertTrue( $data['woocommerce']['active'] );
		}
	}

	/**
	 * Test collect returns a valid site_type string.
	 */
	public function test_collect_site_type_is_valid_string(): void {
		$data       = SiteScanner::collect();
		$valid_types = [ 'ecommerce', 'lms', 'membership', 'portfolio', 'blog', 'brochure' ];

		$this->assertIsString( $data['site_type'] );
		$this->assertContains( $data['site_type'], $valid_types );
	}

	/**
	 * Test site_type is blog when post_count > 20.
	 */
	public function test_site_type_is_blog_when_many_posts(): void {
		// Create 25 published posts.
		for ( $i = 0; $i < 25; $i++ ) {
			$this->factory->post->create( [
				'post_status'  => 'publish',
				'post_title'   => "Post $i",
				'post_content' => "Content for post $i",
			] );
		}

		$data = SiteScanner::collect();

		// With no WooCommerce and no special plugins, >20 posts = blog.
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'get_woocommerce_currency' ) ) {
			$this->assertSame( 'blog', $data['site_type'] );
		}
	}

	/**
	 * Test site_type is brochure when post_count <= 20 and no special plugins.
	 */
	public function test_site_type_is_brochure_when_few_posts(): void {
		// Ensure no published posts exist.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
		$wpdb->query( "UPDATE {$wpdb->posts} SET post_status = 'draft' WHERE post_type = 'post'" );

		$data = SiteScanner::collect();

		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'get_woocommerce_currency' ) ) {
			$this->assertSame( 'brochure', $data['site_type'] );
		}
	}

	// ── schedule / unschedule ─────────────────────────────────────────────

	/**
	 * Test schedule registers a cron event.
	 */
	public function test_schedule_registers_cron_event(): void {
		// Clear any existing scheduled event.
		$timestamp = wp_next_scheduled( SiteScanner::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, SiteScanner::CRON_HOOK );
		}

		SiteScanner::schedule();

		$this->assertNotFalse( wp_next_scheduled( SiteScanner::CRON_HOOK ) );
	}

	/**
	 * Test schedule is idempotent — calling twice does not create duplicate events.
	 */
	public function test_schedule_is_idempotent(): void {
		// Clear any existing scheduled event.
		$timestamp = wp_next_scheduled( SiteScanner::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, SiteScanner::CRON_HOOK );
		}

		SiteScanner::schedule();
		$first_timestamp = wp_next_scheduled( SiteScanner::CRON_HOOK );

		SiteScanner::schedule();
		$second_timestamp = wp_next_scheduled( SiteScanner::CRON_HOOK );

		$this->assertSame( $first_timestamp, $second_timestamp );
	}

	/**
	 * Test unschedule removes the cron event.
	 */
	public function test_unschedule_removes_cron_event(): void {
		// Ensure it's scheduled first.
		SiteScanner::schedule();
		$this->assertNotFalse( wp_next_scheduled( SiteScanner::CRON_HOOK ) );

		SiteScanner::unschedule();

		$this->assertFalse( wp_next_scheduled( SiteScanner::CRON_HOOK ) );
	}
}
