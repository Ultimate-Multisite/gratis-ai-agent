<?php

declare(strict_types=1);
/**
 * Sandbox Activate Plugin ability — layer 3 transactional plugin activation.
 *
 * @package SdAiAgent\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Core\Health\PostMutationHealthCheck;
use SdAiAgent\PluginBuilder\PluginSandbox;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sandbox Activate Plugin ability.
 *
 * Activates a plugin using layer 3 transactional safety.
 *
 * @since 1.5.0
 */
class SandboxActivatePluginAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Sandbox Activate Plugin', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Activate a plugin using layer 3 transactional safety: error handler + shutdown guard. Auto-deactivates on fatal error.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'plugin_file' => [
					'type'        => 'string',
					'description' => 'Plugin file path relative to the plugins directory (e.g. "my-plugin/my-plugin.php").',
				],
			],
			'required'   => [ 'plugin_file' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'activated'   => [ 'type' => 'boolean' ],
				'plugin_file' => [ 'type' => 'string' ],
				'message'     => [ 'type' => 'string' ],
			],
		];
	}

	protected function execute_callback( $input ): array|\WP_Error {
		$plugin_file = (string) ( $input['plugin_file'] ?? '' );

		if ( empty( $plugin_file ) ) {
			return new WP_Error(
				'sd_ai_agent_invalid_plugin_file',
				__( 'plugin_file is required.', 'superdav-ai-agent' )
			);
		}

		$result = PluginSandbox::layer3_activate( $plugin_file );

		// If layer 3 activation succeeded, run a post-mutation health check.
		// If the site is broken, deactivate the plugin and return an error.
		if ( ! is_wp_error( $result ) && isset( $result['activated'] ) && $result['activated'] ) {
			$health_check = new PostMutationHealthCheck();
			$health_error = $health_check->verify_or_revert(
				function () use ( $plugin_file ) {
					// Undo closure: deactivate the plugin.
					deactivate_plugins( $plugin_file );
					return true;
				},
				'Plugin activation'
			);

			if ( is_wp_error( $health_error ) ) {
				return $health_error;
			}
		}

		return $result;
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
