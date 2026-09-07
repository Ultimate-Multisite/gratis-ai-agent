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
	 * Editor user ID (chat access without manage_options).
	 *
	 * @var int
	 */
	protected int $editor_id;

	/**
	 * Set up test users before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->editor_id     = self::factory()->user->create( [ 'role' => 'editor' ] );
	}

	/**
	 * Clean up settings and dequeue scripts after each test.
	 */
	public function tear_down(): void {
		parent::tear_down();
		delete_option( Settings::OPTION_NAME );
		remove_all_filters( 'locale' );
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

	/**
	 * Test frontend administrators receive an absolute account-settings fallback URL.
	 */
	public function test_enqueue_assets_frontend_localizes_settings_page_url(): void {
		wp_set_current_user( $this->admin_id );
		Settings::instance()->update( [ 'show_on_frontend' => true ] );
		$fixture_dir = dirname( __DIR__, 2 ) . '/fixtures/assets';
		add_filter( 'sd_ai_agent_build_dir', static fn() => $fixture_dir );

		FloatingWidget::enqueue_assets_frontend();
		remove_all_filters( 'sd_ai_agent_build_dir' );

		$data = $this->get_localized_widget_data();
		$this->assertSame(
			admin_url( 'admin.php?page=sd-ai-agent#/settings' ),
			$data['settingsPageUrl']
		);
	}

	/**
	 * Test frontend chat users without settings access do not receive that link.
	 */
	public function test_enqueue_assets_frontend_omits_settings_url_for_editor(): void {
		wp_set_current_user( $this->editor_id );
		Settings::instance()->update( [ 'show_on_frontend' => true ] );
		$fixture_dir = dirname( __DIR__, 2 ) . '/fixtures/assets';
		add_filter( 'sd_ai_agent_build_dir', static fn() => $fixture_dir );

		FloatingWidget::enqueue_assets_frontend();
		remove_all_filters( 'sd_ai_agent_build_dir' );

		$data = $this->get_localized_widget_data();
		$this->assertSame( '', $data['settingsPageUrl'] );
	}

	/** The widget receives separate normalized user and site speech hints. */
	public function test_enqueue_assets_frontend_localizes_speech_locale_hints(): void {
		wp_set_current_user( $this->editor_id );
		update_user_meta( $this->editor_id, 'locale', 'fr_FR' );
		add_filter( 'locale', static fn(): string => 'pt_BR' );
		Settings::instance()->update( [ 'show_on_frontend' => true ] );
		$fixture_dir = dirname( __DIR__, 2 ) . '/fixtures/assets';
		add_filter( 'sd_ai_agent_build_dir', static fn() => $fixture_dir );

		FloatingWidget::enqueue_assets_frontend();
		remove_all_filters( 'sd_ai_agent_build_dir' );

		$data = $this->get_localized_widget_data();
		$this->assertSame( 'fr-FR', $data['speechLocales']['user_locale'] );
		$this->assertSame( 'pt-BR', $data['speechLocales']['site_locale'] );
		$this->assertSame( 'fr-FR', $data['speechLocales']['initial_locale'] );
	}

	/**
	 * Decode the floating widget's localized runtime data.
	 *
	 * @return array<string, mixed>
	 */
	private function get_localized_widget_data(): array {
		$script_data = (string) wp_scripts()->get_data( 'sd-ai-agent-floating-widget', 'data' );
		$matched     = preg_match( '/var sdAiAgentData = (\{[^\n]+\});/', $script_data, $matches );
		$this->assertSame( 1, $matched );
		$decoded = json_decode( $matches[1], true );
		$this->assertIsArray( $decoded );

		return $decoded;
	}
}
