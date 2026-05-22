<?php

declare(strict_types=1);
/**
 * Block Usage admin page.
 *
 * Renders a simple server-side admin sub-page under the Superdav AI Agent
 * menu that shows current block / pattern usage stats and provides a
 * rate-limited "Refresh" button.
 *
 * The page is reachable via:
 *   admin.php?page=sd-ai-agent-block-usage
 *
 * @package SdAiAgent\Admin
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1716
 */

namespace SdAiAgent\Admin;

use SdAiAgent\Core\BlockInventory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block Usage stats admin sub-page.
 *
 * Registers as a visible sub-page under the Superdav AI Agent top-level
 * menu so site admins can view block usage data and manually trigger a
 * refresh.
 */
final class BlockUsagePage {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'sd-ai-agent-block-usage';

	/**
	 * Nonce action for the refresh form.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'sd_ai_agent_refresh_block_usage';

	/**
	 * Nonce field name.
	 *
	 * @var string
	 */
	const NONCE_FIELD = '_sd_block_usage_nonce';

	/**
	 * Register the sub-page under the Superdav AI Agent menu.
	 *
	 * Called from BlockUsageAdminHandler on `admin_menu`.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_submenu_page(
			UnifiedAdminMenu::SLUG,
			__( 'Block Usage', 'superdav-ai-agent' ),
			__( 'Block Usage', 'superdav-ai-agent' ),
			UnifiedAdminMenu::CAPABILITY,
			self::PAGE_SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Handle the refresh POST action submitted from the page form.
	 *
	 * Called on `admin_init` (before output begins) so we can redirect
	 * after processing. Validates nonce, capability, and rate-limit before
	 * triggering the scan.
	 *
	 * @return void
	 */
	public static function handle_refresh_request(): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}

		// Nonce + capability check.
		if (
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ),
				self::NONCE_ACTION
			)
			|| ! current_user_can( UnifiedAdminMenu::CAPABILITY )
		) {
			wp_die(
				esc_html__( 'Security check failed. Please try again.', 'superdav-ai-agent' ),
				esc_html__( 'Permission Denied', 'superdav-ai-agent' ),
				array( 'response' => 403 )
			);
		}

		// Rate-limit: BlockInventory::get( refresh: true ) enforces
		// REFRESH_MIN_INTERVAL internally and falls back to the cached
		// result when the budget is exhausted.
		BlockInventory::get( true );

		// Redirect back to avoid duplicate-submit on browser reload.
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::PAGE_SLUG,
					'sd-refresh' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the Block Usage admin page.
	 *
	 * Displays the cached (or empty) inventory and a rate-limited Refresh
	 * button. Shows the last-scanned timestamp and a truncation notice when
	 * the 1000-post cap was reached.
	 *
	 * @return void
	 */
	public static function render(): void {
		$inventory    = BlockInventory::get();
		$refreshed    = isset( $_GET['sd-refresh'] ) && '1' === $_GET['sd-refresh']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$last_run     = (int) get_option( BlockInventory::REFRESH_LAST_RUN_OPTION, 0 );
		$now          = time();
		$rate_limited = $last_run && ( $now - $last_run ) < BlockInventory::REFRESH_MIN_INTERVAL;

		$last_scanned   = $inventory['last_scanned'] ?? '';
		$block_counts   = $inventory['block_counts'] ?? array();
		$pattern_counts = $inventory['pattern_counts'] ?? array();
		$top_namespaces = $inventory['top_namespaces'] ?? array();
		$truncated      = ! empty( $inventory['truncated'] );
		$scanned_posts  = (int) ( $inventory['scanned_posts'] ?? 0 );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Block &amp; Pattern Usage', 'superdav-ai-agent' ); ?></h1>

			<?php if ( $refreshed ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Block usage stats refreshed.', 'superdav-ai-agent' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $rate_limited ) : ?>
				<div class="notice notice-warning is-dismissible">
					<p>
					<?php
					printf(
						/* translators: %d: minutes until next refresh is allowed */
						esc_html__( 'Rate limit active. Next refresh available in approximately %d minute(s).', 'superdav-ai-agent' ),
						(int) ceil( ( BlockInventory::REFRESH_MIN_INTERVAL - ( $now - $last_run ) ) / 60 )
					);
					?>
					</p>
				</div>
			<?php endif; ?>

			<p>
				<?php if ( $last_scanned ) : ?>
					<?php
					printf(
						/* translators: %s: ISO 8601 datetime string */
						esc_html__( 'Last scanned: %s', 'superdav-ai-agent' ),
						esc_html( $last_scanned )
					);
					?>
					&nbsp;&mdash;&nbsp;
					<?php
					printf(
						/* translators: %d: number of posts scanned */
						esc_html__( '%d post(s) scanned', 'superdav-ai-agent' ),
						(int) $scanned_posts
					);
					?>
					<?php if ( $truncated ) : ?>
						&nbsp;<em><?php esc_html_e( '(capped at 1000 — site has more content)', 'superdav-ai-agent' ); ?></em>
					<?php endif; ?>
				<?php else : ?>
					<em><?php esc_html_e( 'No scan has run yet.', 'superdav-ai-agent' ); ?></em>
				<?php endif; ?>
			</p>

			<form method="post" action="" style="display:inline-block;margin-bottom:1.5em;">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<button
					type="submit"
					class="button button-secondary"
					<?php disabled( $rate_limited ); ?>
					onclick="return confirm( <?php echo esc_attr( (string) wp_json_encode( __( 'Refresh block usage stats now? This may take a moment on large sites.', 'superdav-ai-agent' ) ) ); ?> );"
				>
					<?php esc_html_e( 'Refresh Block Usage', 'superdav-ai-agent' ); ?>
				</button>
			</form>

			<?php if ( ! empty( $top_namespaces ) ) : ?>
				<h2><?php esc_html_e( 'Top Namespaces', 'superdav-ai-agent' ); ?></h2>
				<table class="widefat striped" style="max-width:600px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Namespace', 'superdav-ai-agent' ); ?></th>
							<th><?php esc_html_e( 'Block Instances', 'superdav-ai-agent' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $top_namespaces as $ns => $count ) : ?>
							<tr>
								<td><code><?php echo esc_html( (string) $ns ); ?></code></td>
								<td><?php echo esc_html( (string) $count ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( ! empty( $block_counts ) ) : ?>
				<h2 style="margin-top:1.5em;"><?php esc_html_e( 'Block Counts', 'superdav-ai-agent' ); ?></h2>
				<table class="widefat striped" style="max-width:700px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Block Name', 'superdav-ai-agent' ); ?></th>
							<th><?php esc_html_e( 'Instances', 'superdav-ai-agent' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $block_counts as $block_name => $count ) : ?>
							<tr>
								<td><code><?php echo esc_html( (string) $block_name ); ?></code></td>
								<td><?php echo esc_html( (string) $count ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( ! empty( $pattern_counts ) ) : ?>
				<h2 style="margin-top:1.5em;"><?php esc_html_e( 'Synced Pattern References', 'superdav-ai-agent' ); ?></h2>
				<table class="widefat striped" style="max-width:700px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Pattern Name', 'superdav-ai-agent' ); ?></th>
							<th><?php esc_html_e( 'References', 'superdav-ai-agent' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $pattern_counts as $pattern_name => $refs ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $pattern_name ); ?></td>
								<td><?php echo esc_html( (string) $refs ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( empty( $block_counts ) && $last_scanned ) : ?>
				<p><?php esc_html_e( 'No block usage data found. Your published posts may contain no Gutenberg blocks.', 'superdav-ai-agent' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
