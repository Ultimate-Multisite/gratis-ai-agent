<?php

declare(strict_types=1);
/**
 * Sandbox Test Plugin ability — PHP syntax and isolation checks.
 *
 * @package SdAiAgent\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\PluginBuilder\PluginSandbox;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sandbox Test Plugin ability.
 *
 * Runs layers 1 and 2 of the plugin sandbox against an installed plugin.
 *
 * @since 1.5.0
 */
class SandboxTestPluginAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Sandbox Test Plugin', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Run layers 1 and 2 of the sandbox safety check against a plugin: PHP syntax validation and isolated subprocess include test.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'slug'        => [
					'type'        => 'string',
					'description' => 'Plugin slug (directory name under wp-content/plugins/).',
				],
				'plugin_file' => [
					'type'        => 'string',
					'description' => 'Main plugin file path relative to the plugin directory (e.g. "my-plugin.php").',
				],
			],
			'required'   => [ 'slug', 'plugin_file' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'layer1_passed' => [ 'type' => 'boolean' ],
				'layer2_passed' => [ 'type' => 'boolean' ],
				'layer3_passed' => [ 'type' => 'boolean' ],
				'errors'        => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'passed'        => [ 'type' => 'boolean' ],
			],
		];
	}

	protected function execute_callback( $input ): array|\WP_Error {
		$slug        = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		$plugin_file = (string) ( $input['plugin_file'] ?? '' );

		if ( empty( $slug ) ) {
			return new WP_Error( 'sd_ai_agent_invalid_slug', __( 'slug is required.', 'superdav-ai-agent' ) );
		}
		if ( empty( $plugin_file ) ) {
			return new WP_Error( 'sd_ai_agent_invalid_plugin_file', __( 'plugin_file is required.', 'superdav-ai-agent' ) );
		}

		// Use WP_PLUGIN_DIR per wp.org guidelines for referencing other plugins.
		$plugin_dir = trailingslashit( WP_PLUGIN_DIR ) . $slug . '/';

		return PluginSandbox::run_all( $plugin_dir, $plugin_file );
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
