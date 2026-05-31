<?php

declare(strict_types=1);
/**
 * Test case for FloatingWidget class.
 *
 * @package SdAiAgent
 * @subpackage Tests\Admin
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Admin;

use SdAiAgent\Admin\FloatingWidget;
use SdAiAgent\Admin\UnifiedAdminMenu;
use SdAiAgent\Core\Settings;
use WP_UnitTestCase;

/**
 * Test FloatingWidget functionality.
 */
class FloatingWidgetTest extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected int $admin_id;

	/**
	 * Subscriber user ID (no manage_options).
	 *
	 * @var int
	 */
	protected int $subscriber_id;

	/**
	 * Set up test users before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	/**
	 * Clean up settings and dequeue scripts after each test.
	 */
	public function tear_down(): void {
		parent::tear_down();
		delete_option( Settings::OPTION_NAME );
		wp_dequeue_script( 'sd-ai-agent-floating-widget' );
		wp_deregister_script( 'sd-ai-agent-floating-widget' );
	}

	// ─── Hook Registration ────────────────────────────────────────────────────

	/**
	 * Test register() hooks admin_enqueue_scripts.
	 */
	public function test_register_hooks_admin_enqueue_scripts(): void {
		FloatingWidget::register();

		$this->assertGreaterThan(
			0,
			has_action( 'admin_enqueue_scripts', [ 'SdAiAgent\Admin\FloatingWidget', 'enqueue_assets_admin' ] )
		);
	}

	/**
	 * Test register() hooks wp_enqueue_scripts.
	 */
	public function test_register_hooks_wp_enqueue_scripts(): void {
		FloatingWidget::register();

		$this->assertGreaterThan(
			0,
			has_action( 'wp_enqueue_scripts', [ 'SdAiAgent\Admin\FloatingWidget', 'enqueue_assets_frontend' ] )
		);
	}

	// ─── enqueue_assets_admin ─────────────────────────────────────────────────

	/**
	 * Test enqueue_assets_admin() skips the unified admin top-level page.
	 */
	public function test_enqueue_assets_admin_skips_unified_admin_page(): void {
		wp_set_current_user( $this->admin_id );

		FloatingWidget::enqueue_assets_admin( 'toplevel_page_' . UnifiedAdminMenu::SLUG );

		$this->assertFalse( wp_script_is( 'sd-ai-agent-floating-widget', 'enqueued' ) );
	}

	/**
	 * Test enqueue_assets_admin() skips submenu pages under unified admin.
	 */
	public function test_enqueue_assets_admin_skips_unified_admin_subpages(): void {
		wp_set_current_user( $this->admin_id );

		FloatingWidget::enqueue_assets_admin( 'sd-ai-agent_page_' . UnifiedAdminMenu::SLUG );

		$this->assertFalse( wp_script_is( 'sd-ai-agent-floating-widget', 'enqueued' ) );
	}

	/**
	 * Test enqueue_assets_admin() skips users without manage_options.
	 */
	public function test_enqueue_assets_admin_skips_non_admin(): void {
		wp_set_current_user( $this->subscriber_id );

		FloatingWidget::enqueue_assets_admin( 'dashboard' );

		$this->assertFalse( wp_script_is( 'sd-ai-agent-floating-widget', 'enqueued' ) );
	}

	/**
	 * Test enqueue_assets_admin() skips when asset file does not exist.
	 */
	public function test_enqueue_assets_admin_skips_missing_asset_file(): void {
		wp_set_current_user( $this->admin_id );

		// Override build dir to a path that does not exist so file_exists() returns false.
		add_filter( 'sd_ai_agent_build_dir', static fn() => '/nonexistent/path' );

		FloatingWidget::enqueue_assets_admin( 'dashboard' );

		remove_all_filters( 'sd_ai_agent_build_dir' );

		$this->assertFalse( wp_script_is( 'sd-ai-agent-floating-widget', 'enqueued' ) );
	}

	// ─── enqueue_assets_frontend ──────────────────────────────────────────────

	/**
	 * Test enqueue_assets_frontend() skips when show_on_frontend is disabled.
	 */
	public function test_enqueue_assets_frontend_skips_when_disabled(): void {
		wp_set_current_user( $this->admin_id );

		// Explicitly disabled sites should still skip the frontend widget.
		Settings::instance()->update( [ 'show_on_frontend' => false ] );

		FloatingWidget::enqueue_assets_frontend();

		$this->assertFalse( wp_script_is( 'sd-ai-agent-floating-widget', 'enqueued' ) );
	}

	/**
	 * Test enqueue_assets_frontend() skips users without configured chat access.
	 */
	public function test_enqueue_assets_frontend_skips_non_admin(): void {
		wp_set_current_user( $this->subscriber_id );

		Settings::instance()->update( [ 'show_on_frontend' => true ] );

		FloatingWidget::enqueue_assets_frontend();

		$this->assertFalse( wp_script_is( 'sd-ai-agent-floating-widget', 'enqueued' ) );
	}

	/**
	 * Test enqueue_assets_frontend() skips when asset file does not exist.
	 */
	public function test_enqueue_assets_frontend_skips_missing_asset_file(): void {
		wp_set_current_user( $this->admin_id );

		Settings::instance()->update( [ 'show_on_frontend' => true ] );

		// Override build dir to a path that does not exist so file_exists() returns false.
		add_filter( 'sd_ai_agent_build_dir', static fn() => '/nonexistent/path' );

		FloatingWidget::enqueue_assets_frontend();

		remove_all_filters( 'sd_ai_agent_build_dir' );

		$this->assertFalse( wp_script_is( 'sd-ai-agent-floating-widget', 'enqueued' ) );
	}
}
