<?php

declare(strict_types=1);
/**
 * Activate Theme ability — switches the active WordPress theme.
 *
 * Wraps `switch_theme()` with explicit existence and validity checks so the
 * AI agent receives a structured WP_Error instead of a silent failure when
 * the target stylesheet does not exist or fails WP_Theme's own validation.
 *
 * @package SdAiAgent\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use WP_Error;
use WP_Theme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activate Theme ability.
 *
 * @since 1.6.0
 */
class ActivateThemeAbility extends AbstractAbility {

	/**
	 * Valid stylesheet pattern: lowercase letters, digits, hyphens.
	 *
	 * Stricter than WP_Theme::scandir would accept on disk, but it matches
	 * the slug rules used by ScaffoldBlockThemeAbility so callers that
	 * generated the theme via this plugin can round-trip safely.
	 */
	private const STYLESHEET_PATTERN = '/^[a-z0-9-]+$/';

	protected function label(): string {
		return __( 'Activate Theme', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __(
			'Switch the active WordPress theme. Returns the previously-active stylesheet alongside the new one so the agent can offer an undo step.',
			'superdav-ai-agent'
		);
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'stylesheet' => [
					'type'        => 'string',
					'description' => 'Theme stylesheet (directory name). Must match an installed theme.',
				],
			],
			'required'   => [ 'stylesheet' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'previous_stylesheet' => [ 'type' => 'string' ],
				'previous_template'   => [ 'type' => 'string' ],
				'stylesheet'          => [ 'type' => 'string' ],
				'template'            => [ 'type' => 'string' ],
				'is_block_theme'      => [ 'type' => 'boolean' ],
			],
		];
	}

	protected function execute_callback( $input ): array|WP_Error {
		$stylesheet_input = isset( $input['stylesheet'] ) ? (string) $input['stylesheet'] : '';
		$stylesheet       = sanitize_title( $stylesheet_input );

		if ( '' === $stylesheet || ! preg_match( self::STYLESHEET_PATTERN, $stylesheet ) ) {
			return new WP_Error(
				'sd_ai_agent_invalid_stylesheet',
				__( 'Stylesheet must contain only lowercase letters, digits, and hyphens.', 'superdav-ai-agent' )
			);
		}

		if ( ! function_exists( 'wp_get_theme' ) ) {
			return new WP_Error(
				'sd_ai_agent_theme_api_unavailable',
				__( 'WordPress theme API is not loaded.', 'superdav-ai-agent' )
			);
		}

		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme instanceof WP_Theme || ! $theme->exists() ) {
			return new WP_Error(
				'sd_ai_agent_theme_not_found',
				/* translators: %s: theme stylesheet */
				sprintf( __( 'Theme "%s" is not installed.', 'superdav-ai-agent' ), $stylesheet )
			);
		}

		$errors = $theme->errors();
		if ( $errors instanceof WP_Error ) {
			return new WP_Error(
				'sd_ai_agent_theme_invalid',
				/* translators: 1: theme stylesheet, 2: error message */
				sprintf( __( 'Theme "%1$s" is invalid: %2$s', 'superdav-ai-agent' ), $stylesheet, $errors->get_error_message() )
			);
		}

		$previous_stylesheet = (string) get_stylesheet();
		$previous_template   = (string) get_template();

		// switch_theme() returns void; success is inferred by re-reading the
		// option afterwards. This matches how core's theme installer wires it.
		switch_theme( $stylesheet );

		$new_stylesheet = (string) get_stylesheet();
		$new_template   = (string) get_template();

		if ( $new_stylesheet !== $stylesheet ) {
			return new WP_Error(
				'sd_ai_agent_switch_theme_failed',
				/* translators: %s: theme stylesheet */
				sprintf( __( 'switch_theme() did not activate "%s".', 'superdav-ai-agent' ), $stylesheet )
			);
		}

		// WP_Theme::is_block_theme() has shipped since WordPress 5.9; this
		// plugin requires 7.0+, so no method_exists guard is needed.
		$is_block_theme = (bool) $theme->is_block_theme();

		return [
			'previous_stylesheet' => $previous_stylesheet,
			'previous_template'   => $previous_template,
			'stylesheet'          => $new_stylesheet,
			'template'            => $new_template,
			'is_block_theme'      => $is_block_theme,
		];
	}

	protected function permission_callback( $input ): bool {
		// Tool-level delegatable cap AND core's switch_themes cap. The latter
		// is the canonical gate for theme activation and must not be bypassed
		// by tool-cap delegation alone.
		return ToolCapabilities::current_user_can( $this->name )
			&& current_user_can( 'switch_themes' );
	}

	protected function meta(): array {
		return [
			'annotations'  => [
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}
