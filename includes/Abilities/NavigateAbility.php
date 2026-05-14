<?php

declare(strict_types=1);
/**
 * Navigate ability.
 *
 * Validates and returns a navigate action for a URL within the WordPress site.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Navigate ability.
 *
 * @since 1.0.0
 */
class NavigateAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Navigate', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Navigate the user to a URL within the WordPress site. The URL must be within the current site. This will reload the page.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'url' => [
					'type'        => 'string',
					'description' => 'The URL to navigate to. Can be a full URL (must start with the site URL) or a relative path (e.g., "/wp-admin/edit.php").',
				],
			],
			'required'   => [ 'url' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'url'     => [ 'type' => 'string' ],
				'action'  => [ 'type' => 'string' ],
				'message' => [ 'type' => 'string' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$url      = $input['url'] ?? '';
		$home_url = home_url();

		if ( empty( $url ) ) {
			return new WP_Error( 'sd_ai_agent_empty_url', __( 'URL is required.', 'superdav-ai-agent' ) );
		}

		$validated_url = null;

		// Handle relative URLs.
		// @phpstan-ignore-next-line
		if ( strpos( $url, '/' ) === 0 ) {
			// @phpstan-ignore-next-line
			$validated_url = home_url( $url );
		} else {
			// Compare parsed scheme/host/port to prevent host-substring attacks
			// (e.g. https://example.com.evil.tld/ would pass a naive strpos check).
			// @phpstan-ignore-next-line
			$target_parts  = wp_parse_url( $url );
			$current_parts = wp_parse_url( $home_url );
			$current_path  = trailingslashit( $current_parts['path'] ?? '/' );
			$target_path   = trailingslashit( $target_parts['path'] ?? '/' );

			if (
				is_array( $target_parts )
				&& is_array( $current_parts )
				&& ( $target_parts['scheme'] ?? '' ) === ( $current_parts['scheme'] ?? '' )
				&& strtolower( (string) ( $target_parts['host'] ?? '' ) ) === strtolower( (string) ( $current_parts['host'] ?? '' ) )
				&& (int) ( $target_parts['port'] ?? 0 ) === (int) ( $current_parts['port'] ?? 0 )
				&& 0 === strpos( $target_path, $current_path )
			) {
				$validated_url = $url;
			} else {
				return new WP_Error(
					'sd_ai_agent_invalid_url',
					sprintf(
						/* translators: %s: home URL */
						__( 'Invalid URL: must be within the WordPress site (start with "%s" or be a relative path).', 'superdav-ai-agent' ),
						$home_url
					)
				);
			}
		}

		// Block ThickBox/iframe URLs.
		// @phpstan-ignore-next-line
		if ( strpos( $validated_url, 'TB_iframe=true' ) !== false ) {
			return new WP_Error(
				'sd_ai_agent_iframe_url',
				__( 'Cannot navigate to modal/iframe URLs. Navigate to the main page instead.', 'superdav-ai-agent' )
			);
		}

		return [
			'url'     => $validated_url,
			'action'  => 'navigate',
			// @phpstan-ignore-next-line
			'message' => sprintf( 'Ready to navigate to: %s', $validated_url ),
		];
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			],
			'show_in_rest' => true,
		];
	}
}
