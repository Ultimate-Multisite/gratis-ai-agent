<?php

declare(strict_types=1);
/**
 * Hidden admin page that hosts the browser-side `wp.blocks.validateBlock()`
 * runner (`src/block-validator/index.js`). The page enqueues every block
 * editor / block library script so registered blocks (core *and* third-party)
 * are available, then renders a minimal HTML shell that the JS entry can
 * write into for diagnostics.
 *
 * The page is registered with no menu parent so it is reachable via direct
 * URL (`admin.php?page=sd-ai-agent-block-validator`) but does not appear in
 * the sidebar. It is iframed by the chat / unified-admin app whenever live
 * validation is needed.
 *
 * @package SdAiAgent\Admin
 * @license GPL-2.0-or-later
 * @since   1.11.0
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1584
 */

namespace SdAiAgent\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the hidden block-validator admin page and its assets.
 *
 * @since 1.11.0
 */
final class BlockValidatorPage {

	/**
	 * Admin page slug. Used as the `page` query var.
	 *
	 * @since 1.11.0
	 */
	public const PAGE_SLUG = 'sd-ai-agent-block-validator';

	/**
	 * Script handle for the JS validator entry.
	 *
	 * @since 1.11.0
	 */
	public const SCRIPT_HANDLE = 'sd-ai-agent-block-validator';

	/**
	 * Register the hidden admin page. Called from {@see \SdAiAgent\Bootstrap\BlockValidatorPageHandler}
	 * on `admin_menu`.
	 *
	 * @since 1.11.0
	 *
	 * @return void
	 */
	public static function register(): void {
		add_submenu_page(
			'', // No parent — page is hidden from the menu.
			__( 'Block Validator', 'superdav-ai-agent' ),
			__( 'Block Validator', 'superdav-ai-agent' ),
			'edit_posts',
			self::PAGE_SLUG,
			[ self::class, 'render' ]
		);
	}

	/**
	 * Enqueue the validator JS bundle plus all registered block editor scripts.
	 *
	 * The validator needs `wp-blocks`, `wp-block-library`, `wp-i18n`,
	 * `wp-element`, and `wp-data` to call `wp.blocks.validateBlock()`. We also
	 * fire the standard `enqueue_block_editor_assets` and
	 * `enqueue_block_assets` hooks so third-party block scripts register their
	 * `save()` callbacks before the validator runs.
	 *
	 * @since 1.11.0
	 *
	 * @param string $hook_suffix Admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		// Pull in every registered block's editor + frontend assets.
		if ( function_exists( 'wp_enqueue_editor' ) ) {
			wp_enqueue_editor();
		}

		// Core block library — guarantees wp.blocks.getBlockType() returns
		// real types for core/heading, core/paragraph, etc.
		wp_enqueue_script( 'wp-blocks' );
		wp_enqueue_script( 'wp-block-library' );
		wp_enqueue_script( 'wp-format-library' );
		wp_enqueue_style( 'wp-block-library' );
		wp_enqueue_style( 'wp-block-library-theme' );

		// Third-party block scripts hook these actions.
		do_action( 'enqueue_block_editor_assets' );
		do_action( 'enqueue_block_assets' );

		// Determine asset path. Built file lives at build/block-validator.js.
		$asset_file = SD_AI_AGENT_DIR . '/build/block-validator.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [ 'wp-blocks', 'wp-block-library', 'wp-data', 'wp-element', 'wp-i18n' ],
				'version'      => SD_AI_AGENT_VERSION,
			];

		// @phpstan-ignore-next-line — $asset is a build-time array from wp-scripts.
		$deps_raw = (array) ( $asset['dependencies'] ?? [] );
		$deps     = [];
		foreach ( $deps_raw as $dep ) {
			if ( is_string( $dep ) && '' !== $dep ) {
				$deps[] = $dep;
			}
		}
		// @phpstan-ignore-next-line
		$ver = (string) ( $asset['version'] ?? SD_AI_AGENT_VERSION );

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			SD_AI_AGENT_URL . 'build/block-validator.js',
			$deps,
			$ver,
			true
		);

		// Localise REST endpoints + nonce so the validator can POST cache entries.
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'sdAiAgentBlockValidatorConfig',
			[
				'restNamespace' => 'sd-ai-agent/v1',
				'cacheEndpoint' => rest_url( 'sd-ai-agent/v1/blocks/validate-cache' ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
			]
		);
	}

	/**
	 * Render the page shell. The JS validator populates the diagnostics div
	 * once it has registered its `window.sdAiAgentValidateBlocks` API.
	 *
	 * @since 1.11.0
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'superdav-ai-agent' ),
				'',
				[ 'response' => 403 ]
			);
		}

		?>
		<div class="wrap" id="sd-ai-agent-block-validator-root">
			<h1><?php esc_html_e( 'Block Validator', 'superdav-ai-agent' ); ?></h1>
			<p>
				<?php
				esc_html_e(
					'This page hosts the live block validator. It is intentionally hidden from the admin menu; the AI Agent chat UI loads it in an iframe to validate Gutenberg block content against the real wp.blocks.validateBlock() save-comparison.',
					'superdav-ai-agent'
				);
				?>
			</p>
			<div id="sd-ai-agent-block-validator-status">
				<?php esc_html_e( 'Loading validator…', 'superdav-ai-agent' ); ?>
			</div>
		</div>
		<?php
	}
}
