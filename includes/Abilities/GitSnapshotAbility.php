<?php

declare(strict_types=1);
/**
 * Git Snapshot ability — explicitly snapshot a file or a whole package.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Models\GitTrackerManager;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Git Snapshot ability — explicitly snapshot a file or a whole package.
 *
 * @since 1.1.0
 */
class GitSnapshotAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Snapshot File', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Snapshot a baseline before edits. Pass `path` for a single file, or `package_slug` (e.g. "akismet") to snapshot a plugin\'s files without knowing the filesystem path. Note: FileAbilities auto-snapshots on write/edit; this is for manual control.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'path'         => [
					'type'        => 'string',
					'description' => 'Absolute filesystem path to a single file to snapshot.',
				],
				'package_slug' => [
					'type'        => 'string',
					'description' => 'Plugin directory name (e.g. "akismet") or theme slug. Resolves to the package directory and snapshots every PHP file in it (capped).',
				],
				'package_type' => [
					'type'        => 'string',
					'enum'        => [ 'plugin', 'theme' ],
					'description' => 'Whether `package_slug` refers to a plugin or theme. Defaults to "plugin".',
				],
			],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'path'              => [ 'type' => 'string' ],
				'package_slug'      => [ 'type' => 'string' ],
				'snapshotted_files' => [ 'type' => 'array' ],
				'count'             => [ 'type' => 'integer' ],
				'message'           => [ 'type' => 'string' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$path         = $input['path'] ?? null;
		$package_slug = $input['package_slug'] ?? null;
		$package_type = isset( $input['package_type'] ) ? (string) $input['package_type'] : 'plugin';

		// Single-file mode (back-compat).
		if ( is_string( $path ) && '' !== $path ) {
			$result = GitTrackerManager::snapshot_before_modify( $path );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return [
				'path'              => $path,
				'snapshotted_files' => [ $path ],
				'count'             => 1,
				'message'           => sprintf(
					/* translators: %s: file path */
					__( 'File snapshotted successfully: %s', 'superdav-ai-agent' ),
					$path
				),
			];
		}

		// Package-mode: resolve slug to a directory and snapshot its PHP files.
		if ( ! is_string( $package_slug ) || '' === $package_slug ) {
			return new WP_Error(
				'sd_ai_agent_invalid_args',
				__( 'Provide either `path` (absolute file) or `package_slug` (plugin/theme directory name).', 'superdav-ai-agent' )
			);
		}

		if ( 'theme' === $package_type ) {
			$dir = get_theme_root() . '/' . $package_slug;
		} else {
			$dir = WP_PLUGIN_DIR . '/' . $package_slug;
		}

		if ( ! is_dir( $dir ) ) {
			return new WP_Error(
				'sd_ai_agent_package_not_found',
				sprintf(
					/* translators: 1: package type, 2: slug */
					__( '%1$s "%2$s" not found.', 'superdav-ai-agent' ),
					ucfirst( $package_type ),
					$package_slug
				)
			);
		}

		$cap         = 200;
		$snapshotted = [];
		$failures    = [];

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( count( $snapshotted ) >= $cap ) {
				break;
			}
			if ( ! $file->isFile() ) {
				continue;
			}
			$ext = strtolower( $file->getExtension() );
			if ( ! in_array( $ext, [ 'php', 'js', 'css', 'html', 'json' ], true ) ) {
				continue;
			}
			$abs    = $file->getPathname();
			$result = GitTrackerManager::snapshot_before_modify( $abs );
			if ( is_wp_error( $result ) ) {
				$failures[] = [
					'path'  => $abs,
					'error' => $result->get_error_message(),
				];
				continue;
			}
			$snapshotted[] = $abs;
		}

		return [
			'package_slug'      => $package_slug,
			'package_type'      => $package_type,
			'snapshotted_files' => $snapshotted,
			'count'             => count( $snapshotted ),
			'failures'          => $failures,
			'truncated'         => count( $snapshotted ) >= $cap,
			'message'           => sprintf(
				/* translators: 1: count, 2: slug */
				__( 'Snapshotted %1$d files in package "%2$s".', 'superdav-ai-agent' ),
				count( $snapshotted ),
				$package_slug
			),
		];
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'   => false,
				'idempotent' => true,
			],
			'show_in_rest' => true,
		];
	}
}
