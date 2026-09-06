<?php

declare(strict_types=1);
/**
 * Tests for the Dokan vendor dashboard assistant integration.
 *
 * @package SdAiAgent
 * @subpackage Tests\Bootstrap
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Bootstrap;

use SdAiAgent\Bootstrap\DokanVendorDashboardHandler;
use SdAiAgent\Core\RolePermissions;
use WP_UnitTestCase;

final class DokanVendorDashboardHandlerTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( RolePermissions::OPTION_NAME );
		remove_role( 'seller' );
		remove_all_filters( 'sd_ai_agent_is_dokan_vendor' );

		parent::tear_down();
	}

	/** The integration registers a stable Dokan query variable. */
	public function test_add_query_var_registers_assistant_endpoint(): void {
		$handler    = new DokanVendorDashboardHandler();
		$query_vars = $handler->add_query_var( array( 'products' ) );
		$query_vars = $handler->add_query_var( $query_vars );

		$this->assertContains( 'ai-assistant', $query_vars );
		$this->assertSame( 1, array_count_values( $query_vars )['ai-assistant'] );
	}

	/** A configured vendor receives the embedded React assistant mount. */
	public function test_render_dashboard_outputs_assistant_for_configured_vendor(): void {
		$this->set_up_seller_policy( array( 'sd-ai-agent/report-inability' ) );
		add_filter( 'sd_ai_agent_is_dokan_vendor', '__return_true' );

		ob_start();
		( new DokanVendorDashboardHandler() )->render_dashboard( array( 'ai-assistant' => 'ai-assistant' ) );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'sd-ai-agent-dokan-dashboard', $output );
		$this->assertStringContainsString( 'id="sdaa-floating-root"', $output );
		$this->assertStringContainsString( 'data-display-mode="embedded"', $output );
	}

	/** A seller without an explicit allowlist receives no assistant mount. */
	public function test_render_dashboard_denies_vendor_without_allowlist(): void {
		$this->set_up_seller_policy( array() );
		add_filter( 'sd_ai_agent_is_dokan_vendor', '__return_true' );

		ob_start();
		( new DokanVendorDashboardHandler() )->render_dashboard( array( 'ai-assistant' => 'ai-assistant' ) );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'do not have permission', $output );
		$this->assertStringNotContainsString( 'id="sdaa-floating-root"', $output );
	}

	/**
	 * Configure the current test user as a seller.
	 *
	 * @param string[] $abilities Explicit role allowlist.
	 */
	private function set_up_seller_policy( array $abilities ): void {
		add_role( 'seller', 'Seller', array( 'read' => true ) );
		RolePermissions::update(
			array(
				'seller' => array(
					'chat_access'       => true,
					'allowed_abilities' => $abilities,
				),
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'seller' ) ) );
	}
}
