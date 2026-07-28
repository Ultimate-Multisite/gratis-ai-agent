<?php

declare(strict_types=1);
/**
 * Tests for reviewed, site-scoped WooCommerce configuration plans.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\WooCommerceAbilities;
use SdAiAgent\Automations\HumanApprovalGate;
use SdAiAgent\Core\ChangeLogger;
use SdAiAgent\Core\Database;
use SdAiAgent\Models\ChangesLog;
use WP_Error;
use WP_UnitTestCase;

class WooCommerceAbilitiesTestDouble {
}

class WooCommerceAbilitiesTest extends WP_UnitTestCase {

	private int $admin_id;
	private int $target_blog_id;
	private int $other_blog_id;
	private bool $registered_product_taxonomy = false;

	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Site-scoped commerce plans require the multisite PHPUnit fixture.' );
		}

		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		grant_super_admin( $this->admin_id );
		wp_set_current_user( $this->admin_id );

		if ( ! taxonomy_exists( 'product_cat' ) ) {
			register_taxonomy( 'product_cat', [ 'post' ] );
			$this->registered_product_taxonomy = true;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			class_alias( WooCommerceAbilitiesTestDouble::class, 'WooCommerce' );
		}

		$this->target_blog_id = self::factory()->blog->create();
		$this->other_blog_id  = self::factory()->blog->create();
		add_user_to_blog( $this->target_blog_id, $this->admin_id, 'administrator' );
		add_user_to_blog( $this->other_blog_id, $this->admin_id, 'administrator' );
		$this->install_changes_log_table_for_blog( $this->target_blog_id );

		Database::install();
		HumanApprovalGate::clear_handlers();
		HumanApprovalGate::register_handler( 'commerce-site-plan', [ WooCommerceAbilities::class, 'execute_approved_plan' ] );
	}

	public function tear_down(): void {
		ChangeLogger::end();
		HumanApprovalGate::clear_handlers();

		if ( $this->registered_product_taxonomy ) {
			unregister_taxonomy( 'product_cat' );
		}

		parent::tear_down();
	}

	public function test_registers_read_only_inspection_and_reviewed_execution_abilities(): void {
		$this->assertNotNull( wp_get_ability( 'sd-ai-agent/commerce-inspect' ) );
		$this->assertNotNull( wp_get_ability( 'sd-ai-agent/commerce-plan' ) );
		$this->assertNotNull( wp_get_ability( 'sd-ai-agent/commerce-execute-approved-plan' ) );

		$inspection = WooCommerceAbilities::handle_inspect_current_site();
		$this->assertSame( get_current_blog_id(), $inspection['blog_id'] );
		$this->assertTrue( $inspection['woocommerce']['runtime_available'] );
		$this->assertContains( 'woocommerce_enable_coupons', array_column( $inspection['supported_setting_keys'], 'key' ) );
	}

	public function test_plan_rejects_network_wide_scope_without_switching_sites(): void {
		$initial_blog_id = get_current_blog_id();
		$result          = WooCommerceAbilities::handle_create_plan(
			[
				'target_blog_id' => $this->target_blog_id,
				'network_wide'   => true,
				'operations'     => [
					[
						'operation' => 'create_category',
						'name'      => 'Blocked Network Category',
					],
				],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_commerce_network_scope_forbidden', $result->get_error_code() );
		$this->assertSame( $initial_blog_id, get_current_blog_id() );
	}

	public function test_vendor_setting_returns_prerequisite_without_creating_approval(): void {
		$result = WooCommerceAbilities::handle_create_plan(
			[
				'target_blog_id' => $this->target_blog_id,
				'operations'     => [
					[
						'operation'   => 'update_setting',
						'setting_key' => 'dokan_vendor_commission_rate',
						'value'       => 'yes',
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'requires_prerequisite', $result['status'] );
		$this->assertSame( 'vendor_plugin_setting_unsupported', $result['prerequisites'][0]['code'] );
		$this->assertArrayNotHasKey( 'approval_request_id', $result );
	}

	public function test_execution_requires_human_approval_and_an_intact_plan(): void {
		$this->set_option_for_blog( $this->target_blog_id, 'woocommerce_enable_coupons', 'yes' );

		$plan = WooCommerceAbilities::handle_create_plan(
			[
				'target_blog_id' => $this->target_blog_id,
				'operations'     => [
					[
						'operation'   => 'update_setting',
						'setting_key' => 'woocommerce_enable_coupons',
						'value'       => 'no',
					],
				],
			]
		);

		$this->assertIsArray( $plan );
		$unapproved = WooCommerceAbilities::handle_execute_approved_plan( [ 'approval_request_id' => $plan['approval_request_id'] ] );
		$this->assertInstanceOf( WP_Error::class, $unapproved );
		$this->assertSame( 'sd_ai_agent_commerce_human_approval_required', $unapproved->get_error_code() );
		$this->assertSame( 'yes', $this->get_option_for_blog( $this->target_blog_id, 'woocommerce_enable_coupons' ) );

		$approval = HumanApprovalGate::get( (int) $plan['approval_request_id'] );
		$this->assertIsArray( $approval );
		$payload = $approval['payload'];
		$payload['plan']['operations'][0]['value'] = 'yes';
		$tampered = WooCommerceAbilities::execute_approved_plan( $payload );

		$this->assertInstanceOf( WP_Error::class, $tampered );
		$this->assertSame( 'sd_ai_agent_commerce_plan_tampered', $tampered->get_error_code() );
		$this->assertSame( 'yes', $this->get_option_for_blog( $this->target_blog_id, 'woocommerce_enable_coupons' ) );
	}

	public function test_approved_plan_changes_only_target_site_and_restores_blog_context(): void {
		$initial_blog_id = get_current_blog_id();
		$this->set_option_for_blog( $this->target_blog_id, 'woocommerce_enable_coupons', 'yes' );
		$this->set_option_for_blog( $this->other_blog_id, 'woocommerce_enable_coupons', 'yes' );

		$plan = WooCommerceAbilities::handle_create_plan(
			[
				'target_blog_id' => $this->target_blog_id,
				'operations'     => [
					[
						'operation' => 'create_category',
						'name'      => 'Target Only Category',
						'slug'      => 'target-only-category',
					],
					[
						'operation'   => 'update_setting',
						'setting_key' => 'woocommerce_enable_coupons',
						'value'       => 'no',
					],
				],
			]
		);

		$this->assertIsArray( $plan );
		$this->assertSame( 'pending_approval', $plan['status'] );
		$this->assertSame( $initial_blog_id, get_current_blog_id(), 'Planning must restore the caller blog.' );
		$this->assertSame( 'yes', $this->get_option_for_blog( $this->target_blog_id, 'woocommerce_enable_coupons' ) );

		$approved = HumanApprovalGate::approve( (int) $plan['approval_request_id'], $this->admin_id );

		$this->assertIsArray( $approved );
		$this->assertSame( HumanApprovalGate::STATUS_EXECUTED, $approved['status'] );
		$this->assertSame( $initial_blog_id, get_current_blog_id(), 'Approved execution must restore the caller blog.' );
		$this->assertSame( 'no', $this->get_option_for_blog( $this->target_blog_id, 'woocommerce_enable_coupons' ) );
		$this->assertSame( 'yes', $this->get_option_for_blog( $this->other_blog_id, 'woocommerce_enable_coupons' ) );
		$this->assertTrue( $this->term_exists_for_blog( $this->target_blog_id, 'Target Only Category' ) );
		$this->assertFalse( $this->term_exists_for_blog( $this->other_blog_id, 'Target Only Category' ) );
		$this->assertGreaterThan( 0, $this->change_log_count_for_blog( $this->target_blog_id ) );
	}

	private function install_changes_log_table_for_blog( int $blog_id ): void {
		switch_to_blog( $blog_id );

		global $wpdb;
		/** @var \wpdb $wpdb */
		$table_name = Database::changes_log_table_name();
		$charset    = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only custom table with an internally generated table name.
		$wpdb->query(
			"CREATE TABLE {$table_name} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				object_type varchar(50) NOT NULL DEFAULT '',
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				object_title varchar(255) NOT NULL DEFAULT '',
				ability_name varchar(100) NOT NULL DEFAULT '',
				field_name varchar(1000) NOT NULL DEFAULT '',
				before_value longtext NOT NULL,
				after_value longtext NOT NULL,
				reverted tinyint(1) NOT NULL DEFAULT 0,
				reverted_at datetime DEFAULT NULL,
				revertable tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at)
			) {$charset}"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		restore_current_blog();
	}

	private function set_option_for_blog( int $blog_id, string $option, string $value ): void {
		switch_to_blog( $blog_id );
		update_option( $option, $value );
		restore_current_blog();
	}

	private function get_option_for_blog( int $blog_id, string $option ): string {
		switch_to_blog( $blog_id );
		$value = (string) get_option( $option, '' );
		restore_current_blog();

		return $value;
	}

	private function term_exists_for_blog( int $blog_id, string $name ): bool {
		switch_to_blog( $blog_id );
		$exists = (bool) term_exists( $name, 'product_cat' );
		restore_current_blog();

		return $exists;
	}

	private function change_log_count_for_blog( int $blog_id ): int {
		switch_to_blog( $blog_id );
		$count = ChangesLog::list( [ 'per_page' => 100 ] )['total'];
		restore_current_blog();

		return (int) $count;
	}
}
