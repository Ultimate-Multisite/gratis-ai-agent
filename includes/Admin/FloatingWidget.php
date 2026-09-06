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
use SdAiAgent\Core\RolePermissions;
use SdAiAgent\Core\Settings;
use SdAiAgent\Core\SpeechLocaleResolver;

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

		self::enqueue_widget_assets( 'admin' );
	}

	/**
	 * Enqueue the floating widget on frontend pages when enabled in settings.
	 *
	 * Only loads for logged-in users with configured chat access.
	 */
	public static function enqueue_assets_frontend( bool $force_display = false, string $display_mode = 'floating', string $chat_ui_mode = 'admin' ): void {
		$settings         = Settings::instance()->get();
		$force_onboarding = isset( $_GET['sd_ai_agent_onboarding'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& '1' === sanitize_text_field( wp_unslash( $_GET['sd_ai_agent_onboarding'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Only when the frontend display setting is enabled.
		// @phpstan-ignore-next-line
		if ( empty( $settings['show_on_frontend'] ) && ! $force_onboarding && ! $force_display ) {
			return;
		}

		// Mirror the REST chat gate so frontend visibility matches actual access.
		if ( ! RolePermissions::current_user_has_chat_access() ) {
			return;
		}

		// Note: wp_ai_client_prompt() availability is NOT checked here.
		// The floating widget UI (FAB + panel) renders independently of the AI
		// client. The REST API handles provider availability at message-send time.

		self::enqueue_widget_assets( 'frontend', $display_mode, $chat_ui_mode );
	}

	/**
	 * Shared asset enqueueing logic for both admin and frontend contexts.
	 *
	 * @param string $context       Either 'admin' or 'frontend'.
	 * @param string $display_mode  Either 'floating' or 'embedded'.
	 * @param string $chat_ui_mode  Chat control mode exposed to the client.
	 */
	private static function enqueue_widget_assets( string $context = 'admin', string $display_mode = 'floating', string $chat_ui_mode = 'admin' ): void {
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

		// WP 7.0+: expose the `@wordpress/abilities` script-module exports to
		// our classic bundles through a small module bridge. Script modules do
		// not create a `wp.abilities` global by themselves.
		//
		// Also enqueue `@wordpress/core-abilities` explicitly. Despite the
		// WP 7.0 dev note claiming core enqueues it on all admin pages, the
		// module is only registered by core. Enqueueing it mirrors server
		// abilities into the same store used by the bridge.
		// Root-cause investigation: t169 / GH#825.
		if ( function_exists( 'wp_enqueue_script_module' ) ) {
			wp_enqueue_script_module( '@wordpress/abilities' );
			wp_enqueue_script_module(
				'sd-ai-agent/abilities-global-bridge',
				SD_AI_AGENT_URL . 'assets/admin/abilities-global-bridge.js',
				array( array( 'id' => '@wordpress/abilities' ) ),
				SD_AI_AGENT_VERSION
			);
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

		$onboarding_complete = OnboardingManager::is_complete();
		$speech_locales      = ( new SpeechLocaleResolver() )->resolve();
		$is_frontend         = 'frontend' === $context;
		$force_onboarding    = false;
		$viewed_post_id      = 0;
		$viewed_post_type    = '';
		$viewed_title        = '';

		// Read-only launch hint for the frontend widget; no state changes happen here.
		if ( $is_frontend && isset( $_GET['sd_ai_agent_onboarding'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$force_onboarding = '1' === sanitize_text_field( wp_unslash( $_GET['sd_ai_agent_onboarding'] ) );
		}

		if ( $is_frontend && is_singular() ) {
			$viewed_post_id = (int) get_queried_object_id();
			$viewed_post    = $viewed_post_id > 0 ? get_post( $viewed_post_id ) : null;
			if ( $viewed_post instanceof \WP_Post ) {
				$viewed_post_type = $viewed_post->post_type;
				$viewed_title     = get_the_title( $viewed_post );
			}
		}

		wp_localize_script(
			'sd-ai-agent-floating-widget',
			'sdAiAgentData',
			[
				'currentUserId'       => get_current_user_id(),
				'speechLocales'       => $speech_locales,
				'context'             => $context,
				'isFrontend'          => $is_frontend,
				'frontendOnboarding'  => ( $is_frontend && ( ! $onboarding_complete || $force_onboarding ) ) ? '1' : '',
				'onboarding_complete' => $onboarding_complete,
				'changesPageUrl'      => admin_url( 'admin.php?page=sd-ai-agent#/changes' ),
				'settingsPageUrl'     => current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=sd-ai-agent#/settings' ) : '',
				'viewedPostId'        => $viewed_post_id,
				'viewedPostType'      => $viewed_post_type,
				'viewedTitle'         => $viewed_title,
				'displayMode'         => 'embedded' === $display_mode ? 'embedded' : 'floating',
				'chatUiMode'          => sanitize_key( $chat_ui_mode ),
			]
		);
	}
}
