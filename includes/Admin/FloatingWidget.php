<?php

declare(strict_types=1);
/**
 * Floating chat widget available on all admin pages and optionally on the frontend.
 *
 * Enqueues a lightweight React app that renders a FAB button
 * and expandable chat panel in the bottom-right corner.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Admin;

use SdAiAgent\Core\Features;
use SdAiAgent\Core\OnboardingManager;
use SdAiAgent\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FloatingWidget {

	/**
	 * Register the admin_enqueue_scripts and (optionally) wp_enqueue_scripts hooks.
	 */
	public static function register(): void {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets_admin' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets_frontend' ] );
	}

	/**
	 * Enqueue the floating widget on every admin page.
	 *
	 * Skips the dedicated AI Agent page (it has its own full-page UI).
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public static function enqueue_assets_admin( string $hook_suffix ): void {
		// Skip the dedicated full-page admin page (UnifiedAdminMenu registers as
		// a top-level menu page, so the hook suffix is toplevel_page_<slug>).
		// Submenu pages under the same slug use <parent>_page_<slug> suffixes.
		if ( 'toplevel_page_' . UnifiedAdminMenu::SLUG === $hook_suffix ) {
			return;
		}
		// Also skip any submenu page under the unified admin (e.g. abilities, settings).
		if ( strpos( $hook_suffix, '_page_' . UnifiedAdminMenu::SLUG ) !== false ) {
			return;
		}

		// Only for users who can access the agent.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Note: wp_ai_client_prompt() availability is NOT checked here.
		// The floating widget UI (FAB + panel) renders independently of the AI
		// client. The REST API handles provider availability at message-send time.

		self::enqueue_widget_assets();
	}

	/**
	 * Enqueue the floating widget on frontend pages when enabled in settings.
	 *
	 * Only loads for logged-in users with manage_options capability.
	 */
	public static function enqueue_assets_frontend(): void {
		$settings = Settings::instance()->get();

		// Only when the frontend display setting is enabled.
		// @phpstan-ignore-next-line
		if ( empty( $settings['show_on_frontend'] ) ) {
			return;
		}

		// Only for users who can access the agent.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Note: wp_ai_client_prompt() availability is NOT checked here.
		// The floating widget UI (FAB + panel) renders independently of the AI
		// client. The REST API handles provider availability at message-send time.

		self::enqueue_widget_assets();
	}

	/**
	 * Shared asset enqueueing logic for both admin and frontend contexts.
	 */
	private static function enqueue_widget_assets(): void {
		$build_dir  = (string) apply_filters( 'sd_ai_agent_build_dir', SD_AI_AGENT_DIR . '/build' );
		$asset_file = $build_dir . '/floating-widget.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		/** @var array{dependencies: string[], version: string} $asset */
		$asset = require $asset_file;

		wp_enqueue_style(
			'sd-ai-agent-floating-widget',
			SD_AI_AGENT_URL . 'build/floating-widget.css',
			[ 'wp-components' ],
			$asset['version']
		);

		wp_style_add_data( 'sd-ai-agent-floating-widget', 'rtl', 'replace' );

		wp_enqueue_script(
			'sd-ai-agent-floating-widget',
			SD_AI_AGENT_URL . 'build/floating-widget.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'sd-ai-agent-floating-widget', 'superdav-ai-agent' );

		// WP 7.0+: enqueue the `@wordpress/abilities` script module so our
		// client-side ability registry (src/abilities/*) can resolve the
		// bare specifier via the document import map at runtime. Without
		// this, the dynamic import() in registry.js throws a module
		// resolution error and the sd-ai-agent-js/* abilities are never
		// registered. (t165 — fixes the missing enqueue in #815.)
		//
		// Also enqueue `@wordpress/core-abilities` explicitly. Despite the
		// WP 7.0 dev note claiming core enqueues it on all admin pages, the
		// module is only *registered* by core — never added to the queue.
		// Without this enqueue, the REST fetch that populates the
		// `core/abilities` wp.data store never runs, so
		// wp.data.select('core/abilities').getAbilities() returns 0 items
		// even though wp.abilities.getAbilities() returns the full list.
		// Root-cause investigation: t169 / GH#825.
		if ( function_exists( 'wp_enqueue_script_module' ) ) {
			wp_enqueue_script_module( '@wordpress/abilities' );
			wp_enqueue_script_module( '@wordpress/core-abilities' );
		}

		// Pass white-label branding values to the widget (t075).
		// Only applied when the branding feature is enabled.
		if ( Features::is_enabled( Features::BRANDING ) ) {
			$branding = Settings::instance()->get();
			wp_localize_script(
				'sd-ai-agent-floating-widget',
				'sdAiAgentBranding',
				array(
					// @phpstan-ignore-next-line
					'agentName'       => (string) ( $branding['agent_name'] ?? '' ),
					// @phpstan-ignore-next-line
					'primaryColor'    => (string) ( $branding['brand_primary_color'] ?? '' ),
					// @phpstan-ignore-next-line
					'textColor'       => (string) ( $branding['brand_text_color'] ?? '' ),
					// @phpstan-ignore-next-line
					'logoUrl'         => (string) ( $branding['brand_logo_url'] ?? '' ),
					// @phpstan-ignore-next-line
					'greetingMessage' => (string) ( $branding['greeting_message'] ?? '' ),
				)
			);
		}

		wp_localize_script(
			'sd-ai-agent-floating-widget',
			'sdAiAgentData',
			[
				'currentUserId'       => get_current_user_id(),
				'onboarding_complete' => OnboardingManager::is_complete(),
				'changesPageUrl'      => admin_url( 'admin.php?page=sd-ai-agent#/changes' ),
			]
		);
	}
}
