<?php

declare(strict_types=1);
/**
 * Admin notice handler for unclassified third-party abilities.
 *
 * Surfaces a batched per-namespace summary of abilities that have not been
 * classified as public or private. Allows site owners to make one decision
 * per namespace rather than toggling each ability individually.
 *
 * @package SdAiAgent\Admin
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Admin;

use SdAiAgent\Abilities\ThirdParty\PartnerAllowlist;
use SdAiAgent\Core\AbilityVisibility;
use SdAiAgent\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Displays admin notice for unclassified third-party abilities.
 *
 * @since 1.9.0
 */
final class ThirdPartyAbilityNoticeHandler {

	/**
	 * Option key for tracking dismissed notices.
	 *
	 * @var string
	 */
	private const DISMISSED_KEY = 'sd_ai_agent_third_party_notice_dismissed';

	/**
	 * Scan registered abilities and display notice if unclassified ones exist.
	 *
	 * Called on the `admin_notices` hook. Only displays if:
	 * - WordPress Abilities API is available
	 * - At least one ability is classified as `private-unknown`
	 * - The notice has not been dismissed
	 * - The current user can manage options
	 */
	public static function maybe_display_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return;
		}

		// Check if notice has been dismissed.
		if ( get_option( self::DISMISSED_KEY ) ) {
			return;
		}

		$unclassified = self::get_unclassified_by_namespace();
		if ( empty( $unclassified ) ) {
			return;
		}

		self::render_notice( $unclassified );
	}

	/**
	 * Get unclassified abilities grouped by namespace.
	 *
	 * Returns an associative array where keys are namespace slugs and values
	 * are arrays of ability names in that namespace.
	 *
	 * @return array<string, array<int, string>> Unclassified abilities by namespace.
	 */
	private static function get_unclassified_by_namespace(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$unclassified = array();

		foreach ( wp_get_abilities() as $ability ) {
			$classification = AbilityVisibility::classify( $ability );

			// Only surface private-unknown abilities.
			if ( AbilityVisibility::CLASSIFICATION_PRIVATE_UNKNOWN !== $classification ) {
				continue;
			}

			$name = (string) $ability->get_name();
			if ( ! str_contains( $name, '/' ) ) {
				continue;
			}

			[ $namespace ] = explode( '/', $name, 2 );
			$namespace     = strtolower( trim( $namespace ) );

			if ( '' === $namespace ) {
				continue;
			}

			if ( ! isset( $unclassified[ $namespace ] ) ) {
				$unclassified[ $namespace ] = array();
			}

			$unclassified[ $namespace ][] = $name;
		}

		return $unclassified;
	}

	/**
	 * Render the admin notice.
	 *
	 * @param array<string, array<int, string>> $unclassified Unclassified abilities by namespace.
	 */
	private static function render_notice( array $unclassified ): void {
		$count = count( $unclassified );
		$label = 1 === $count
			? __( 'plugin', 'superdav-ai-agent' )
			: __( 'plugins', 'superdav-ai-agent' );

		$review_url = admin_url( 'admin.php?page=sd-ai-agent#abilities/third-party-review' );
		$dismiss_url = wp_nonce_url(
			add_query_arg( 'sd_ai_agent_dismiss_third_party_notice', '1' ),
			'sd_ai_agent_dismiss_third_party_notice'
		);

		?>
		<div class="notice notice-warning is-dismissible" id="sd-ai-agent-third-party-notice">
			<p>
				<strong><?php esc_html_e( 'Superdav AI Agent', 'superdav-ai-agent' ); ?></strong>
				<?php
				printf(
					/* translators: %d is the number of plugins */
					esc_html( _n(
						'%d plugin has registered AI abilities that have not been classified.',
						'%d plugins have registered AI abilities that have not been classified.',
						$count,
						'superdav-ai-agent'
					) ),
					intval( $count )
				);
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( $review_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Review & Decide', 'superdav-ai-agent' ); ?>
				</a>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button">
					<?php esc_html_e( 'Dismiss', 'superdav-ai-agent' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle dismissal of the notice.
	 *
	 * Called on `admin_init` to check for the dismiss query parameter.
	 */
	public static function handle_dismiss(): void {
		if ( ! isset( $_GET['sd_ai_agent_dismiss_third_party_notice'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Verify nonce.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'sd_ai_agent_dismiss_third_party_notice' ) ) {
			return;
		}

		update_option( self::DISMISSED_KEY, '1' );

		// Redirect to remove the query parameter.
		wp_safe_remote_get( remove_query_arg( array( 'sd_ai_agent_dismiss_third_party_notice', '_wpnonce' ) ) );
	}
}
