<?php

declare(strict_types=1);
/**
 * Admin notice handler for rejected default-model values.
 *
 * Surfaces a one-time notice when the resolver in
 * {@see \SdAiAgent\Core\Settings::get_default_model()} falls back because
 * the saved `sd_ai_agent_settings.default_model` (or its paired
 * `default_provider`) is no longer registered with any authenticated
 * provider in the WP AI Client SDK registry.
 *
 * Historical context: the production `gemma4:e4b` regression (GH#1494) was
 * caused by the saved default propagating into every new chat unchecked.
 * The resolver now substitutes a working model and records the rejected
 * value so this handler can explain the substitution to the site owner.
 *
 * @package SdAiAgent\Admin
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Admin;

use SdAiAgent\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the one-time "default model substituted" admin notice.
 *
 * @since 1.13.1
 */
final class DefaultModelNoticeHandler {

	/**
	 * Dismiss-query parameter recognised by {@see handle_dismiss()}.
	 */
	private const DISMISS_QUERY_ARG = 'sd_ai_agent_dismiss_default_model_notice';

	/**
	 * Nonce action paired with {@see DISMISS_QUERY_ARG}.
	 */
	private const DISMISS_NONCE_ACTION = 'sd_ai_agent_dismiss_default_model_notice';

	/**
	 * Conditionally render the substitution notice on the current admin screen.
	 *
	 * Called on the `admin_notices` hook. The notice is shown when:
	 *   - the current user can manage options (so connectors are reachable),
	 *   - a substitution record exists in {@see Settings::INVALID_DEFAULT_NOTICE_OPTION}.
	 *
	 * Dismissal clears the option entirely; the notice will re-appear only
	 * if the resolver records a fresh rejection.
	 */
	public static function maybe_display_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$record = get_option( Settings::INVALID_DEFAULT_NOTICE_OPTION );
		if ( ! is_array( $record ) || empty( $record['model'] ) ) {
			return;
		}

		self::render_notice( $record );
	}

	/**
	 * Handle dismissal of the notice via the query-string handshake.
	 *
	 * Wired on `admin_init` (mirroring
	 * {@see ThirdPartyAbilityNoticeHandler::handle_dismiss()}). Verifies the
	 * nonce, deletes the option, and redirects to drop the query args so a
	 * page refresh does not re-trigger the handler.
	 */
	public static function handle_dismiss(): void {
		if ( ! isset( $_GET[ self::DISMISS_QUERY_ARG ] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ),
				self::DISMISS_NONCE_ACTION
			)
		) {
			return;
		}

		delete_option( Settings::INVALID_DEFAULT_NOTICE_OPTION );

		$redirect = remove_query_arg( array( self::DISMISS_QUERY_ARG, '_wpnonce' ) );
		if ( is_string( $redirect ) && '' !== $redirect ) {
			wp_safe_redirect( $redirect );
			exit;
		}
	}

	/**
	 * Render the notice markup.
	 *
	 * @param array<string, mixed> $record Substitution record from the option.
	 */
	private static function render_notice( array $record ): void {
		$provider          = isset( $record['provider'] ) ? (string) $record['provider'] : '';
		$model             = isset( $record['model'] ) ? (string) $record['model'] : '';
		$replacement_model = isset( $record['replacement_model'] ) ? (string) $record['replacement_model'] : '';

		$settings_url = admin_url( 'admin.php?page=sd-ai-agent#settings' );
		$dismiss_url  = wp_nonce_url(
			add_query_arg( self::DISMISS_QUERY_ARG, '1' ),
			self::DISMISS_NONCE_ACTION
		);

		?>
		<div class="notice notice-warning is-dismissible" id="sd-ai-agent-default-model-notice">
			<p>
				<strong><?php esc_html_e( 'Superdav AI Agent', 'superdav-ai-agent' ); ?></strong>
				<?php
				if ( '' !== $replacement_model ) {
					printf(
						esc_html(
							/* translators: 1: previously saved model ID, 2: substitute model ID currently in use */
							__( 'Your saved default model "%1$s" is not advertised by any authenticated provider, so chats are using "%2$s" instead. Open the settings page to choose a model that matches the providers you have configured.', 'superdav-ai-agent' )
						),
						esc_html( '' !== $model ? $model : ( '' !== $provider ? $provider : __( '(unknown)', 'superdav-ai-agent' ) ) ),
						esc_html( $replacement_model )
					);
				} else {
					printf(
						esc_html(
							/* translators: %s: previously saved model ID */
							__( 'Your saved default model "%s" is not advertised by any authenticated provider, and no fallback could be selected. Configure a provider on the Connectors page to restore chats.', 'superdav-ai-agent' )
						),
						esc_html( '' !== $model ? $model : ( '' !== $provider ? $provider : __( '(unknown)', 'superdav-ai-agent' ) ) )
					);
				}
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Open Settings', 'superdav-ai-agent' ); ?>
				</a>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button">
					<?php esc_html_e( 'Dismiss', 'superdav-ai-agent' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
