<?php

declare(strict_types=1);
/**
 * Scan Plugin Hooks ability — extract hooks from an installed plugin.
 *
 * @package SdAiAgent\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\PluginBuilder\HookScanner;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scan Plugin Hooks ability.
 *
 * @since 1.5.0
 */
class ScanPluginHooksAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Scan Plugin Hooks', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Scan an installed plugin for WordPress hooks (actions and filters) to enable extension-plugin generation.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'slug' => [
					'type'        => 'string',
					'description' => 'Plugin slug (directory name under wp-content/plugins/).',
				],
			],
			'required'   => [ 'slug' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'hooks'   => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'type' => [ 'type' => 'string' ],
							'name' => [ 'type' => 'string' ],
							'file' => [ 'type' => 'string' ],
							'line' => [ 'type' => 'integer' ],
						],
					],
				],
				'count'   => [ 'type' => 'integer' ],
				'summary' => [
					'type'       => 'object',
					'properties' => [
						'actions_total'  => [ 'type' => 'integer' ],
						'filters_total'  => [ 'type' => 'integer' ],
						'unique_actions' => [ 'type' => 'array' ],
						'unique_filters' => [ 'type' => 'array' ],
					],
				],
			],
		];
	}

	protected function execute_callback( $input ): array|\WP_Error {
		$slug = (string) ( $input['slug'] ?? '' );

		if ( empty( $slug ) ) {
			return new WP_Error( 'sd_ai_agent_invalid_slug', __( 'slug is required.', 'superdav-ai-agent' ) );
		}

		return HookScanner::scan_plugin( $slug );
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
