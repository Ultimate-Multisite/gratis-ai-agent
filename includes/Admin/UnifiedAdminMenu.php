<?php

declare(strict_types=1);
/**
 * Unified admin menu for all AI Agent pages.
 *
 * Creates a top-level menu with submenu items, all using a single React app
 * with client-side routing.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Admin;

use SdAiAgent\Core\Features;
use SdAiAgent\Core\InstructionsAddendum;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UnifiedAdminMenu {

	const SLUG       = 'sd-ai-agent';
	const CAPABILITY = 'manage_options';

	/**
	 * Whether the native WP 7.0 Connectors page is available.
	 *
	 * On WP 7.0+, the native Connectors page handles provider credential
	 * management. On WP 6.9 with Gutenberg 22.8.0+, the same page is
	 * available via the Gutenberg plugin.
	 *
	 * @return bool True when the Connectors page is available (WP 7.0+ or Gutenberg 22.8.0+).
	 */
	public static function hasNativeConnectorsPage(): bool {
		global $wp_version;

		// Use 7.0-alpha1 as the floor so that pre-release versions
		// (7.0-alpha, 7.0-beta, 7.0-RC2, etc.) are correctly detected.
		// PHP's version_compare() treats RC/beta/alpha as less than the
		// release, so comparing against '7.0' would miss '7.0-RC2'.
		if ( version_compare( $wp_version, '7.0-alpha1', '>=' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Whether the Gutenberg plugin provides the Connectors page.
	 *
	 * Gutenberg 22.8.0+ backports the WP 7.0 Connectors page to WP 6.9.
	 *
	 * @return bool True when Gutenberg 22.8.0+ is active.
	 */
	public static function hasGutenbergConnectorsPage(): bool {
		if ( ! defined( 'GUTENBERG_VERSION' ) ) {
			return false;
		}
		return version_compare( GUTENBERG_VERSION, '22.8.0', '>=' );
	}

	/**
	 * Get the URL for the Connectors page.
	 *
	 * Always returns the official Connectors page URL. On WP 7.0+ and
	 * Gutenberg 22.8.0+, this page exists natively. On WP 6.9 without
	 * Gutenberg, the page won't exist — the JS layer handles prompting
	 * the user to install Gutenberg.
	 *
	 * @return string Admin URL for the Connectors page.
	 */
	public static function getConnectorsUrl(): string {
		return self::hasNativeConnectorsPage() ? admin_url( 'options-connectors.php' ) : admin_url( 'options-general.php?page=options-connectors-wp-admin' );
	}

	/**
	 * Menu configuration for React Router routes.
	 *
	 * On WP 6.9 (no native Connectors page), a Connectors item is added to
	 * the menu so users can configure provider API keys. On WP 7.0+, the item
	 * is omitted because the native page handles it.
	 *
	 * @return array
	 */
	public static function getMenuItems(): array {
		$items = array(
			array(
				'slug'       => 'chat',
				'label'      => __( 'Chat', 'superdav-ai-agent' ),
				'icon'       => 'dashicons-format-chat',
				'position'   => 0,
				'capability' => self::CAPABILITY,
			),
			array(
				'slug'       => 'abilities',
				'label'      => __( 'Abilities', 'superdav-ai-agent' ),
				'icon'       => 'dashicons-admin-tools',
				'position'   => 10,
				'capability' => self::CAPABILITY,
			),
			array(
				'slug'       => 'changes',
				'label'      => __( 'Changes', 'superdav-ai-agent' ),
				'icon'       => 'dashicons-backup',
				'position'   => 20,
				'capability' => self::CAPABILITY,
			),
		);

		// The Connectors page is handled by WP 7.0+ core or Gutenberg 22.8.0+.
		// No need for a polyfill menu item — users are directed to the
		// official page or prompted to install Gutenberg.

		$items[] = array(
			'slug'       => 'settings',
			'label'      => __( 'Settings', 'superdav-ai-agent' ),
			'icon'       => 'dashicons-admin-settings',
			'position'   => 30,
			'capability' => self::CAPABILITY,
		);

		return $items;
	}

	/**
	 * Register the unified admin menu.
	 */
	public static function register(): void {
		// Top-level menu.
		// Pass the SVG as a base64 data URI so WordPress treats it as a
		// background-image (class="svg") with background-size: 20px auto.
		// URL-based icons are rendered as <img> tags which don't respect
		// background-size and render fill="currentColor" as black.
		$icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="#fff"><text x="9" y="18" text-anchor="middle" font-family="-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif" font-size="16" font-weight="800" letter-spacing="-0.5">AI</text><path d="M15 1.5l.7 2.1 2.1.7-2.1.7-.7 2.1-.7-2.1-2.1-.7 2.1-.7z"/><path d="M3 1l.35 1.05 1.05.35-1.05.35L3 3.8l-.35-1.05-1.05-.35 1.05-.35z"/><path d="M19 10l.3.9.9.3-.9.3-.3.9-.3-.9-.9-.3.9-.3z"/></svg>';
		$icon_uri = 'data:image/svg+xml;base64,' . base64_encode( $icon_svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		add_menu_page(
			__( 'Superdav AI Agent', 'superdav-ai-agent' ),
			__( 'AI Agent', 'superdav-ai-agent' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render' ),
			$icon_uri,
			30 // Position after Dashboard
		);

		// Submenu items — all point to the same render callback.
		// The first submenu uses the parent slug to replace the auto-generated
		// duplicate WordPress creates when add_menu_page has a render callback.
		$menu_items = self::getMenuItems();
		foreach ( $menu_items as $index => $item ) {
			add_submenu_page(
				self::SLUG,
				$item['label'],
				$item['label'],
				$item['capability'],
				0 === $index ? self::SLUG : self::SLUG . '#/' . $item['slug'],
				array( __CLASS__, 'render' )
			);
		}

		// Hook for enqueuing assets.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueueAssets' ) );
	}

	/**
	 * Get the initial route hint for the localized script data.
	 *
	 * PHP never receives URL fragments (browsers strip them before sending HTTP
	 * requests), so deep-link detection must happen client-side. The JS entry
	 * point (index.js) reads window.location.hash directly and uses it as the
	 * authoritative initial route, falling back to this PHP-provided value only
	 * when no hash is present (e.g. a plain admin.php?page=sd-ai-agent load).
	 *
	 * @return string Always 'chat' — the default route when no hash is present.
	 */
	public static function getCurrentRoute(): string {
		return 'chat';
	}

	/**
	 * Enqueue the unified React app.
	 *
	 * Also enqueues the admin-page bundle which exposes window.sdAiAgentChat.
	 * ChatRoute (src/unified-admin/routes/chat.js) calls
	 * window.sdAiAgentChat.mount(container) to embed the full chat UI
	 * (AdminPageApp) inside the #sd-ai-chat-container div. Without this
	 * bundle the mount API is undefined and the chat panel never renders.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public static function enqueueAssets( string $hook_suffix ): void {
		// Only enqueue on our pages.
		if ( strpos( $hook_suffix, 'toplevel_page_' . self::SLUG ) !== 0 &&
			strpos( $hook_suffix, '_page_' . self::SLUG ) === false ) {
			return;
		}

		$build_dir  = (string) apply_filters( 'sd_ai_agent_build_dir', SD_AI_AGENT_DIR . '/build' );
		$asset_file = $build_dir . '/unified-admin.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			// Show admin notice if build is missing.
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'Superdav AI Agent build files are missing. Please run npm run build.', 'superdav-ai-agent' );
					echo '</p></div>';
				}
			);
			return;
		}

		/** @var array{dependencies: string[], version: string} $asset */
		$asset = require $asset_file;

		wp_enqueue_style(
			'sd-ai-agent-unified-admin',
			SD_AI_AGENT_URL . 'build/style-unified-admin.css',
			array( 'wp-components' ),
			$asset['version']
		);

		wp_style_add_data( 'sd-ai-agent-unified-admin', 'rtl', 'replace' );

		wp_enqueue_script(
			'sd-ai-agent-unified-admin',
			SD_AI_AGENT_URL . 'build/unified-admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'sd-ai-agent-unified-admin', 'superdav-ai-agent' );

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

		// Enqueue the admin-page bundle which sets window.sdAiAgentChat.
		// ChatRoute calls window.sdAiAgentChat.mount(container) to render
		// AdminPageApp inside #sd-ai-chat-container. This bundle must load
		// after unified-admin.js so the container element exists in the DOM.
		$admin_page_asset_file = $build_dir . '/admin-page.asset.php';
		if ( file_exists( $admin_page_asset_file ) ) {
			/** @var array{dependencies: string[], version: string} $admin_page_asset */
			$admin_page_asset = require $admin_page_asset_file;

			wp_enqueue_style(
				'sd-ai-agent-admin-page',
				SD_AI_AGENT_URL . 'build/style-admin-page.css',
				array( 'wp-components' ),
				$admin_page_asset['version']
			);

			wp_style_add_data( 'sd-ai-agent-admin-page', 'rtl', 'replace' );

			// Enqueue the JS-extracted CSS (shared.css and other CSS imported
			// from JS files). wp-scripts splits CSS into style-{entry}.css
			// (from style.css imports) and {entry}.css (from JS imports).
			// Without this, the tool confirmation dialog overlay and other
			// shared component styles are missing.
			wp_enqueue_style(
				'sd-ai-agent-admin-page-components',
				SD_AI_AGENT_URL . 'build/admin-page.css',
				array( 'sd-ai-agent-admin-page' ),
				$admin_page_asset['version']
			);

			wp_style_add_data( 'sd-ai-agent-admin-page-components', 'rtl', 'replace' );

			wp_enqueue_script(
				'sd-ai-agent-admin-page',
				SD_AI_AGENT_URL . 'build/admin-page.js',
				array_merge( $admin_page_asset['dependencies'], array( 'sd-ai-agent-unified-admin' ) ),
				$admin_page_asset['version'],
				true
			);

			wp_set_script_translations( 'sd-ai-agent-admin-page', 'superdav-ai-agent' );
		}

		$current_user = wp_get_current_user();

		wp_localize_script(
			'sd-ai-agent-unified-admin',
			'sdAiAgentData',
			array(
				'currentUserId'        => get_current_user_id(),
				'currentUserName'      => $current_user->display_name,
				'restNamespace'        => 'sd-ai-agent/v1',
				'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
				'nonce'                => wp_create_nonce( 'wp_rest' ),
				'initialRoute'         => self::getCurrentRoute(),
				'menuItems'            => self::getMenuItems(),
				'connectorsUrl'        => self::getConnectorsUrl(),
				'connectorsAvailable'  => self::hasNativeConnectorsPage() || self::hasGutenbergConnectorsPage() ? '1' : '',
				'onboarding_complete'  => \SdAiAgent\Core\OnboardingManager::is_complete(),
				// Provider trace is a debug-only feature. The JS settings page reads
				// this flag to show or hide the Provider Trace tab.
				'wpDebug'              => defined( 'WP_DEBUG' ) && WP_DEBUG ? '1' : '',
				// Feature flags — mirrors Features::all() so JS can gate UI sections
				// without waiting for the /settings REST response.
				'features'             => Features::all(),
				// Instructions addendum data for the Settings UI.
				'instructionsAddendum' => array(
					'value'      => InstructionsAddendum::get_addendum(),
					'updated_at' => InstructionsAddendum::get_updated_at(),
					'max_length' => InstructionsAddendum::MAX_LENGTH,
					'endpoint'   => rest_url( 'sd-ai-agent/v1/instructions' ),
				),
			)
		);
	}

	/**
	 * Render the admin page — mount point for React Router app.
	 */
	public static function render(): void {
		?>
		<div class="wrap sdaa-wrap">
			<div id="sdaa-root" class="sdaa-app"></div>
		</div>
		<?php
	}

	/**
	 * Redirect old menu URLs to new unified structure.
	 * Call this on admin_init to handle legacy bookmarks.
	 */
	public static function handleLegacyRedirects(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe redirect, no state change.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		$redirect_map = array(
			'sd-ai-agent-changes'   => admin_url( 'admin.php?page=sd-ai-agent#/changes' ),
			'sd-ai-agent-abilities' => admin_url( 'admin.php?page=sd-ai-agent#/abilities' ),
			'sd-ai-agent-settings'  => admin_url( 'admin.php?page=sd-ai-agent#/settings' ),
		);

		if ( isset( $redirect_map[ $page ] ) ) {
			wp_safe_redirect( $redirect_map[ $page ] );
			exit;
		}
	}
}
