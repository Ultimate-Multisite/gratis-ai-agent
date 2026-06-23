<?php

declare(strict_types=1);
/**
 * Update Plugin (Sandboxed) ability — safe plugin code updates with rollback.
 *
 * @package SdAiAgent\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Core\Health\PostMutationHealthCheck;
use SdAiAgent\PluginBuilder\PluginUpdater;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Update Plugin (Sandboxed) ability.
 *
 * @since 1.5.0
 */
class UpdatePluginSandboxedAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Update Plugin (Sandboxed)', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Update a running plugin with new code: backup → stage → sandbox test → swap. Rolls back automatically on failure.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'slug'  => [
					'type'        => 'string',
					'description' => 'Plugin slug (directory name under wp-content/plugins/).',
				],
				'files' => [
					'type'        => 'object',
					'description' => 'Map of relative file paths to new PHP source code.',
				],
			],
			'required'   => [ 'slug', 'files' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'swapped'     => [ 'type' => 'boolean' ],
				'plugin_file' => [ 'type' => 'string' ],
				'was_active'  => [ 'type' => 'boolean' ],
				'backup_dir'  => [ 'type' => 'string' ],
			],
		];
	}

	protected function execute_callback( $input ): array|\WP_Error {
		$slug = (string) ( $input['slug'] ?? '' );

		// Coerce to array<string,string>: PluginUpdater::update() requires that shape.
		$raw_files = is_array( $input['files'] ?? null ) ? $input['files'] : [];
		/** @var array<string,string> $files */
		$files = array_filter(
			$raw_files,
			static fn( $v ) => is_string( $v )
		);

		if ( empty( $slug ) ) {
			return new WP_Error( 'sd_ai_agent_invalid_slug', __( 'slug is required.', 'superdav-ai-agent' ) );
		}
		if ( empty( $files ) ) {
			return new WP_Error( 'sd_ai_agent_no_files', __( 'files must not be empty.', 'superdav-ai-agent' ) );
		}

		$result = ( new PluginUpdater() )->update( $slug, $files );

		// If the swap succeeded, run a post-mutation health check.
		// If the site is broken, swap back to the backup.
		if ( ! is_wp_error( $result ) && isset( $result['swapped'] ) && $result['swapped'] && isset( $result['backup_dir'] ) ) {
			$backup_dir = (string) $result['backup_dir'];
			$plugin_dir = WP_PLUGIN_DIR . '/' . $slug;

			$health_check = new PostMutationHealthCheck();
			$health_error = $health_check->verify_or_revert(
				function () use ( $plugin_dir, $backup_dir ) {
					// Undo closure: swap back to the backup.
					// Remove the current (broken) version and restore from backup.
					if ( is_dir( $plugin_dir ) ) {
						require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
						require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
						$fs = new \WP_Filesystem_Direct( [] );
						$fs->rmdir( $plugin_dir, true );
					}

					// Restore from backup.
					if ( is_dir( $backup_dir ) ) {
						require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
						require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
						$fs = new \WP_Filesystem_Direct( [] );
						if ( ! wp_mkdir_p( $plugin_dir ) ) {
							return new WP_Error( 'sd_ai_agent_mkdir_failed', 'Could not create plugin directory' );
						}

						$iterator = new \RecursiveIteratorIterator(
							new \RecursiveDirectoryIterator( $backup_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
							\RecursiveIteratorIterator::SELF_FIRST
						);

						foreach ( $iterator as $item ) {
							/** @var \SplFileInfo $item */
							$real_path = $item->getRealPath();
							if ( false === $real_path ) {
								continue;
							}

							$dest_path = $plugin_dir . str_replace( $backup_dir, '', (string) $real_path );

							if ( $item->isDir() ) {
								wp_mkdir_p( $dest_path );
							} else {
								copy( $real_path, $dest_path );
							}
						}
					}

					return true;
				},
				'Plugin update'
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
