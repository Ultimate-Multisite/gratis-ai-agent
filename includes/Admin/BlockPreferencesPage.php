<?php

declare(strict_types=1);
/**
 * Block Preferences admin page.
 *
 * Provides a site-editable mapping of block namespace / full block name →
 * preference score (0–100), plus a legacy-block replacement map.  Both
 * datasets are persisted to `sd_ai_agent_block_preferences` and
 * `sd_ai_agent_block_replacements` and consumed by
 * {@see \SdAiAgent\Core\BlockContentPolicy::check_insert()}.
 *
 * The page is reachable via:
 *   admin.php?page=sd-ai-agent-block-preferences
 *
 * @package SdAiAgent\Admin
 * @license GPL-2.0-or-later
 * @since   1.16.0
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1712
 */

namespace SdAiAgent\Admin;

use SdAiAgent\Core\BlockContentPolicy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block Preferences admin sub-page.
 *
 * Registers under the Superdav AI Agent top-level menu and renders a
 * server-side form for managing namespace scores and the legacy-block
 * replacement map.
 *
 * @since 1.16.0
 */
final class BlockPreferencesPage {

	/**
	 * Admin page slug.
	 *
	 * @since 1.16.0
	 * @var string
	 */
	const PAGE_SLUG = 'sd-ai-agent-block-preferences';

	/**
	 * Nonce action for the save form.
	 *
	 * @since 1.16.0
	 * @var string
	 */
	const NONCE_ACTION = 'sd_ai_agent_save_block_preferences';

	/**
	 * Nonce field name.
	 *
	 * @since 1.16.0
	 * @var string
	 */
	const NONCE_FIELD = '_sd_block_prefs_nonce';

	/**
	 * Register the sub-page under the Superdav AI Agent menu.
	 *
	 * Called from {@see \SdAiAgent\Bootstrap\BlockPreferencesAdminHandler}
	 * on the `admin_menu` hook.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	public static function register(): void {
		add_submenu_page(
			UnifiedAdminMenu::SLUG,
			__( 'Block Preferences', 'superdav-ai-agent' ),
			__( 'Block Preferences', 'superdav-ai-agent' ),
			UnifiedAdminMenu::CAPABILITY,
			self::PAGE_SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Handle the save POST action before output begins.
	 *
	 * Validates nonce and capability, then persists the submitted preferences
	 * and replacement map.  Redirects back to the page after saving.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	public static function handle_save_request(): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}

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

		// --- Preferences (namespace → score) --------------------------------

		$raw_keys   = isset( $_POST['sd_pref_keys'] ) && is_array( $_POST['sd_pref_keys'] )
			? array_map( static fn( mixed $v ): string => (string) $v, (array) wp_unslash( $_POST['sd_pref_keys'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per-element in loop via sanitize_text_field()
			: array();
		$raw_scores = isset( $_POST['sd_pref_scores'] ) && is_array( $_POST['sd_pref_scores'] )
			? array_map( static fn( mixed $v ): string => (string) $v, (array) wp_unslash( $_POST['sd_pref_scores'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to int in loop
			: array();

		$preferences = array();
		foreach ( $raw_keys as $idx => $raw_key ) {
			$key   = sanitize_text_field( wp_unslash( (string) $raw_key ) );
			$score = isset( $raw_scores[ $idx ] )
				? max( 0, min( 100, (int) $raw_scores[ $idx ] ) )
				: 50;

			if ( '' !== $key ) {
				$preferences[ $key ] = $score;
			}
		}

		// --- Replacements (legacy → modern) ----------------------------------

		$raw_legacy = isset( $_POST['sd_repl_legacy'] ) && is_array( $_POST['sd_repl_legacy'] )
			? array_map( static fn( mixed $v ): string => (string) $v, (array) wp_unslash( $_POST['sd_repl_legacy'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per-element in loop via sanitize_text_field()
			: array();
		$raw_modern = isset( $_POST['sd_repl_modern'] ) && is_array( $_POST['sd_repl_modern'] )
			? array_map( static fn( mixed $v ): string => (string) $v, (array) wp_unslash( $_POST['sd_repl_modern'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per-element in loop via sanitize_text_field()
			: array();

		$replacements = array();
		foreach ( $raw_legacy as $idx => $raw_legacy_name ) {
			$legacy = sanitize_text_field( wp_unslash( (string) $raw_legacy_name ) );
			$modern = isset( $raw_modern[ $idx ] )
				? sanitize_text_field( wp_unslash( (string) $raw_modern[ $idx ] ) )
				: '';

			if ( '' !== $legacy && '' !== $modern ) {
				$replacements[ $legacy ] = $modern;
			}
		}

		// Persist both options.
		update_option( BlockContentPolicy::OPTION_PREFERENCES, $preferences, false );
		update_option( BlockContentPolicy::OPTION_REPLACEMENTS, $replacements, false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::PAGE_SLUG,
					'sd-saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the Block Preferences admin page.
	 *
	 * Displays a two-section form: namespace/block scores and the
	 * legacy-block replacement map.  Both sections start from the current
	 * option value, falling back to plugin defaults when empty.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	public static function render(): void {
		$saved        = isset( $_GET['sd-saved'] ) && '1' === $_GET['sd-saved']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$preferences  = BlockContentPolicy::get_preferences();
		$replacements = BlockContentPolicy::get_replacements();

		// Sort preferences for display: full block names after namespaces.
		uksort(
			$preferences,
			static function ( string $a, string $b ): int {
				$a_full = str_contains( $a, '/' );
				$b_full = str_contains( $b, '/' );
				if ( $a_full !== $b_full ) {
					return $a_full ? 1 : -1;
				}
				return strcmp( $a, $b );
			}
		);
		ksort( $replacements );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Block Preferences', 'superdav-ai-agent' ); ?></h1>

			<p>
			<?php
			esc_html_e( 'Set a preference score (0–100) for each block namespace or individual block. The score determines how the AI agent handles insert requests.', 'superdav-ai-agent' );
			?>
			</p>

			<table class="widefat" style="max-width:700px;margin-bottom:1em;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Score range', 'superdav-ai-agent' ); ?></th>
						<th><?php esc_html_e( 'Tier', 'superdav-ai-agent' ); ?></th>
						<th><?php esc_html_e( 'Insert behaviour', 'superdav-ai-agent' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr><td>≥ 80</td><td><strong><?php esc_html_e( 'preferred', 'superdav-ai-agent' ); ?></strong></td><td><?php esc_html_e( 'Allow silently', 'superdav-ai-agent' ); ?></td></tr>
					<tr><td>50 – 79</td><td><strong><?php esc_html_e( 'acceptable', 'superdav-ai-agent' ); ?></strong></td><td><?php esc_html_e( 'Allow silently', 'superdav-ai-agent' ); ?></td></tr>
					<tr><td>10 – 49</td><td><strong><?php esc_html_e( 'avoid', 'superdav-ai-agent' ); ?></strong></td><td><?php esc_html_e( 'Allow + advisory warning with suggested replacement', 'superdav-ai-agent' ); ?></td></tr>
					<tr><td>0 – 9</td><td><strong><?php esc_html_e( 'legacy', 'superdav-ai-agent' ); ?></strong></td><td><?php esc_html_e( 'Reject on insert; allow on update', 'superdav-ai-agent' ); ?></td></tr>
				</tbody>
			</table>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Block preferences saved.', 'superdav-ai-agent' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>

				<!-- ── Namespace / block scores ─────────────────────────────── -->
				<h2><?php esc_html_e( 'Namespace &amp; Block Scores', 'superdav-ai-agent' ); ?></h2>
				<p>
					<em>
					<?php
					esc_html_e( 'Keys without a slash are namespace prefixes (e.g. "core"). Keys with a slash are exact block names (e.g. "core/freeform") and take priority.', 'superdav-ai-agent' );
					?>
					</em>
				</p>

				<table class="widefat striped" id="sd-pref-table" style="max-width:700px;">
					<thead>
						<tr>
							<th style="width:55%"><?php esc_html_e( 'Namespace or block name', 'superdav-ai-agent' ); ?></th>
							<th style="width:20%"><?php esc_html_e( 'Score (0–100)', 'superdav-ai-agent' ); ?></th>
							<th style="width:15%"><?php esc_html_e( 'Tier', 'superdav-ai-agent' ); ?></th>
							<th style="width:10%"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $preferences as $key => $score ) : ?>
							<?php $score = (int) $score; ?>
						<tr>
							<td>
								<input
									type="text"
									name="sd_pref_keys[]"
									value="<?php echo esc_attr( (string) $key ); ?>"
									class="regular-text"
									style="width:95%"
								/>
							</td>
							<td>
								<input
									type="number"
									name="sd_pref_scores[]"
									value="<?php echo esc_attr( (string) $score ); ?>"
									min="0"
									max="100"
									style="width:70px"
								/>
							</td>
							<td>
								<span class="sd-tier-label"><?php echo esc_html( BlockContentPolicy::score_to_tier( $score ) ); ?></span>
							</td>
							<td>
								<button type="button" class="button button-small sd-remove-row"><?php esc_html_e( 'Remove', 'superdav-ai-agent' ); ?></button>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<td colspan="4">
								<button type="button" class="button button-secondary" id="sd-add-pref-row">
									<?php esc_html_e( '+ Add row', 'superdav-ai-agent' ); ?>
								</button>
							</td>
						</tr>
					</tfoot>
				</table>

				<!-- ── Replacement map ──────────────────────────────────────── -->
				<h2 style="margin-top:2em;"><?php esc_html_e( 'Legacy Block Replacement Map', 'superdav-ai-agent' ); ?></h2>
				<p>
					<em>
					<?php
					esc_html_e(
						'When a legacy-tier block is rejected, the agent receives the "modern replacement" as the suggested alternative.',
						'superdav-ai-agent'
					);
					?>
					</em>
				</p>

				<table class="widefat striped" id="sd-repl-table" style="max-width:700px;">
					<thead>
						<tr>
							<th style="width:45%"><?php esc_html_e( 'Legacy block name', 'superdav-ai-agent' ); ?></th>
							<th style="width:45%"><?php esc_html_e( 'Modern replacement', 'superdav-ai-agent' ); ?></th>
							<th style="width:10%"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $replacements as $legacy => $modern ) : ?>
						<tr>
							<td>
								<input
									type="text"
									name="sd_repl_legacy[]"
									value="<?php echo esc_attr( (string) $legacy ); ?>"
									class="regular-text"
									style="width:95%"
								/>
							</td>
							<td>
								<input
									type="text"
									name="sd_repl_modern[]"
									value="<?php echo esc_attr( (string) $modern ); ?>"
									class="regular-text"
									style="width:95%"
								/>
							</td>
							<td>
								<button type="button" class="button button-small sd-remove-row"><?php esc_html_e( 'Remove', 'superdav-ai-agent' ); ?></button>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<td colspan="3">
								<button type="button" class="button button-secondary" id="sd-add-repl-row">
									<?php esc_html_e( '+ Add row', 'superdav-ai-agent' ); ?>
								</button>
							</td>
						</tr>
					</tfoot>
				</table>

				<p class="submit" style="margin-top:1.5em;">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Save Preferences', 'superdav-ai-agent' ); ?>
					</button>
				</p>
			</form>
		</div>

		<script>
		( function() {
			// Remove row.
			document.addEventListener( 'click', function( e ) {
				if ( e.target && e.target.classList.contains( 'sd-remove-row' ) ) {
					e.target.closest( 'tr' ).remove();
				}
			} );

			// Add preference row.
			var addPrefBtn = document.getElementById( 'sd-add-pref-row' );
			if ( addPrefBtn ) {
				addPrefBtn.addEventListener( 'click', function() {
					var tbody = document.querySelector( '#sd-pref-table tbody' );
					var tr = document.createElement( 'tr' );
					tr.innerHTML =
						'<td><input type="text" name="sd_pref_keys[]" value="" class="regular-text" style="width:95%" /></td>' +
						'<td><input type="number" name="sd_pref_scores[]" value="50" min="0" max="100" style="width:70px" /></td>' +
						'<td><span class="sd-tier-label">acceptable</span></td>' +
						'<td><button type="button" class="button button-small sd-remove-row"><?php echo esc_js( __( 'Remove', 'superdav-ai-agent' ) ); ?></button></td>';
					tbody.appendChild( tr );
				} );
			}

			// Add replacement row.
			var addReplBtn = document.getElementById( 'sd-add-repl-row' );
			if ( addReplBtn ) {
				addReplBtn.addEventListener( 'click', function() {
					var tbody = document.querySelector( '#sd-repl-table tbody' );
					var tr = document.createElement( 'tr' );
					tr.innerHTML =
						'<td><input type="text" name="sd_repl_legacy[]" value="" class="regular-text" style="width:95%" /></td>' +
						'<td><input type="text" name="sd_repl_modern[]" value="" class="regular-text" style="width:95%" /></td>' +
						'<td><button type="button" class="button button-small sd-remove-row"><?php echo esc_js( __( 'Remove', 'superdav-ai-agent' ) ); ?></button></td>';
					tbody.appendChild( tr );
				} );
			}
		} )();
		</script>
		<?php
	}
}
