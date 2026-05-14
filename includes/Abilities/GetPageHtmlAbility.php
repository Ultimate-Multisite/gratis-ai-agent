<?php

declare(strict_types=1);
/**
 * Get Page HTML ability.
 *
 * Returns a client-side action instruction to query DOM elements by CSS selector.
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
 * Get Page HTML ability.
 *
 * @since 1.0.0
 */
class GetPageHtmlAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Get Page HTML', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Get the HTML content of elements on the current page the user is viewing. Use CSS selectors to query specific elements. Returns the outer HTML of matched elements.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'selector'   => [
					'type'        => 'string',
					'description' => 'CSS selector to query (e.g., "#main-content", ".entry-title", "article", "body")',
				],
				'max_length' => [
					'type'        => 'number',
					'description' => 'Maximum characters to return per element (default: 5000)',
				],
			],
			'required'   => [ 'selector' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'selector'   => [ 'type' => 'string' ],
				'max_length' => [ 'type' => 'integer' ],
				'action'     => [ 'type' => 'string' ],
				'message'    => [ 'type' => 'string' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$selector = $input['selector'] ?? '';
		// @phpstan-ignore-next-line
		$max_length = (int) ( $input['max_length'] ?? 5000 );

		if ( empty( $selector ) ) {
			return new WP_Error( 'sd_ai_agent_empty_selector', __( 'CSS selector is required.', 'superdav-ai-agent' ) );
		}

		// This ability returns a client-side action instruction.
		// The React frontend intercepts this and executes the DOM query.
		return [
			'selector'   => $selector,
			'max_length' => $max_length,
			'action'     => 'get_page_html',
			// @phpstan-ignore-next-line
			'message'    => sprintf( 'Querying page HTML with selector: %s', $selector ),
		];
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
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
