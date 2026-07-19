<?php

declare(strict_types=1);
/**
 * Read-only ability for validating generated block-theme projects.
 *
 * @package SdAiAgent\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Services\BlockThemeProjectValidator;
use WP_Error;
use WP_Theme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes deterministic generated-theme project validation to agents.
 */
class ValidateBlockThemeProjectAbility extends AbstractAbility {

	private const STYLESHEET_PATTERN = '/^[a-z0-9-]+$/';

	protected function label(): string {
		return __( 'Validate Block Theme Project', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __(
			'Validate a complete block-theme project before activation. Returns deterministic, path-specific diagnostics for theme.json, templates, parts, patterns, variations, tokens, local assets, placeholders, and block markup without writing files or executing project PHP.',
			'superdav-ai-agent'
		);
	}

	protected function input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'stylesheet' => [
					'type'        => 'string',
					'description' => 'Installed theme stylesheet (directory name) to validate.',
				],
			],
			'required'             => [ 'stylesheet' ],
			'additionalProperties' => false,
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'valid'           => [ 'type' => 'boolean' ],
				'marked'          => [ 'type' => 'boolean' ],
				'project_version' => [ 'type' => 'integer' ],
				'fingerprint'     => [ 'type' => 'string' ],
				'files_scanned'   => [ 'type' => 'integer' ],
				'errors'          => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
				'warnings'        => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
			],
			'required'   => [ 'valid', 'marked', 'project_version', 'fingerprint', 'files_scanned', 'errors', 'warnings' ],
		];
	}

	protected function execute_callback( $input ): array|WP_Error {
		$stylesheet_input = is_array( $input ) && isset( $input['stylesheet'] ) ? (string) $input['stylesheet'] : '';
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

		return ( new BlockThemeProjectValidator() )->validate( $theme->get_stylesheet_directory() );
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name )
			&& current_user_can( 'edit_theme_options' );
	}

	protected function meta(): array {
		return [
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}
