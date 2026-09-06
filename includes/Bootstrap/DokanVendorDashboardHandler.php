<?php

declare(strict_types=1);
/**
 * Dokan vendor dashboard integration for the authenticated chat widget.
 *
 * @package SdAiAgent\Bootstrap
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Bootstrap;

use SdAiAgent\Admin\FloatingWidget;
use SdAiAgent\Core\RolePermissions;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Filter;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds an AI Assistant screen to the public Dokan vendor dashboard.
 */
#[Handler(
	container: 'sd-ai-agent',
	// Rewrite registration also runs from wp-admin and WP-CLI, so the Dokan
	// endpoint filter must not disappear outside frontend request context.
	context: Handler::CTX_GLOBAL,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class DokanVendorDashboardHandler {

	private const ENDPOINT = 'ai-assistant';

	/**
	 * Add the assistant endpoint to Dokan's rewrite endpoint list.
	 *
	 * @param array<int|string, string> $query_vars Registered Dokan endpoint query variables.
	 * @return array<int|string, string> Updated endpoint query variables.
	 */
	#[Filter( tag: 'dokan_query_var_filter', priority: 10 )]
	public function add_query_var( array $query_vars ): array {
		if ( ! in_array( self::ENDPOINT, $query_vars, true ) ) {
			$query_vars[] = self::ENDPOINT;
		}

		return $query_vars;
	}

	/**
	 * Add the assistant to the current vendor's dashboard navigation.
	 *
	 * @param array<string, array<string, mixed>> $menus Dokan dashboard menu entries.
	 * @return array<string, array<string, mixed>> Updated dashboard menu entries.
	 */
	#[Filter( tag: 'dokan_get_dashboard_nav', priority: 40 )]
	public function add_dashboard_navigation( array $menus ): array {
		if ( ! $this->current_user_can_use_vendor_chat() || ! function_exists( 'dokan_get_navigation_url' ) ) {
			return $menus;
		}

		$menus[ self::ENDPOINT ] = array(
			'title'      => __( 'AI Assistant', 'superdav-ai-agent' ),
			'icon'       => '<i class="fas fa-robot" aria-hidden="true"></i>',
			'icon_name'  => 'Bot',
			'url'        => dokan_get_navigation_url( self::ENDPOINT ),
			'pos'        => 40,
			'permission' => 'read',
		);

		return $menus;
	}

	/** Enqueue the existing widget bundle only on the assistant dashboard screen. */
	#[Action( tag: 'wp_enqueue_scripts', priority: 20 )]
	public function enqueue_assets(): void {
		if ( ! $this->is_assistant_request() || ! $this->current_user_can_use_vendor_chat() ) {
			return;
		}

		FloatingWidget::enqueue_assets_frontend( true, 'embedded', 'vendor_simple' );
	}

	/**
	 * Render the Dokan dashboard shell and the React mount point.
	 *
	 * @param array<string, mixed> $query_vars Current Dokan endpoint query variables.
	 */
	#[Action( tag: 'dokan_load_custom_template', priority: 10 )]
	public function render_dashboard( array $query_vars ): void {
		if ( ! isset( $query_vars[ self::ENDPOINT ] ) ) {
			return;
		}

		if ( ! $this->current_user_can_use_vendor_chat() ) {
			if ( function_exists( 'dokan_get_template_part' ) ) {
				dokan_get_template_part( 'global/no-permission' );
				return;
			}

			echo esc_html__( 'You do not have permission to use the AI Assistant.', 'superdav-ai-agent' );
			return;
		}

		do_action( 'dokan_dashboard_wrap_start' );
		echo '<div class="dokan-dashboard-wrap">';
		do_action( 'dokan_dashboard_content_before' );
		echo '<div class="dokan-dashboard-content sd-ai-agent-dokan-dashboard">';
		do_action( 'dokan_dashboard_content_inside_before' );
		printf(
			'<article class="dashboard-content-area"><header class="dokan-dashboard-header"><h1>%s</h1></header><div id="sdaa-floating-root" class="sd-ai-agent-dokan-chat" data-display-mode="embedded"></div></article>',
			esc_html__( 'AI Assistant', 'superdav-ai-agent' )
		);
		do_action( 'dokan_dashboard_content_inside_after' );
		echo '</div>';
		do_action( 'dokan_dashboard_content_after' );
		echo '</div>';
		do_action( 'dokan_dashboard_wrap_end' );
	}

	/** Determine whether the current request targets the assistant endpoint. */
	private function is_assistant_request(): bool {
		$request_uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$request_path   = wp_parse_url( $request_uri, PHP_URL_PATH );
		$dashboard_url  = function_exists( 'dokan_get_navigation_url' ) ? dokan_get_navigation_url() : '';
		$dashboard_path = wp_parse_url( $dashboard_url, PHP_URL_PATH );
		$expected_path  = is_string( $dashboard_path )
			? untrailingslashit( $dashboard_path ) . '/' . self::ENDPOINT
			: '';

		$is_request = is_string( $request_path )
			&& '' !== $expected_path
			&& $expected_path === untrailingslashit( $request_path );

		return (bool) apply_filters( 'sd_ai_agent_is_dokan_assistant_request', $is_request );
	}

	/** Require both a real Dokan vendor and an explicit restricted chat policy. */
	private function current_user_can_use_vendor_chat(): bool {
		$is_vendor = function_exists( 'dokan_is_user_seller' )
			&& dokan_is_user_seller( get_current_user_id() );
		$is_vendor = (bool) apply_filters( 'sd_ai_agent_is_dokan_vendor', $is_vendor, get_current_user_id() );

		if ( ! $is_vendor || ! RolePermissions::current_user_has_chat_access() ) {
			return false;
		}

		$allowed = RolePermissions::get_allowed_abilities_for_current_user();

		return is_array( $allowed ) && [] !== $allowed;
	}
}
