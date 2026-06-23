<?php

declare(strict_types=1);
/**
 * Git Diff ability — show changes since last snapshot.
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
 * Git Diff ability — show changes since last snapshot.
 *
 * @since 1.1.0
 */
class GitDiffAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Diff File', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Show a unified diff between the original snapshot and the current file content.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'path'         => [
					'type'        => 'string',
					'description' => 'Absolute filesystem path to the file.',
				],
				'package_slug' => [
					'type'        => 'string',
					'description' => 'Plugin file slug (e.g. "akismet/akismet.php") or theme slug (e.g. "twentytwentyfour").',
				],
				'package_type' => [
					'type'        => 'string',
					'enum'        => [ 'plugin', 'theme' ],
					'description' => 'Whether the package is a plugin or theme. Defaults to "plugin" if omitted.',
				],
			],
			'required'   => [ 'path', 'package_slug' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'path'        => [ 'type' => 'string' ],
				'has_changes' => [ 'type' => 'boolean' ],
				'diff'        => [ 'type' => 'string' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$path         = $input['path'] ?? null;
		$package_slug = $input['package_slug'] ?? null;
		$package_type = $input['package_type'] ?? null;

		if ( ! is_string( $path ) || '' === $path ) {
			return new WP_Error( 'sd_ai_agent_invalid_path', __( 'Path must be a non-empty string.', 'superdav-ai-agent' ) );
		}

		if ( ! is_string( $package_slug ) || '' === $package_slug ) {
			return new WP_Error( 'sd_ai_agent_invalid_slug', __( 'Package slug must be a non-empty string.', 'superdav-ai-agent' ) );
		}

		if ( ! is_string( $package_type ) || '' === $package_type ) {
			$package_type = 'plugin';
		}

		if ( 'theme' === $package_type ) {
			$tracker = GitTrackerManager::for_theme( $package_slug );
		} else {
			$tracker = GitTrackerManager::for_plugin( $package_slug );
		}

		if ( is_wp_error( $tracker ) ) {
			return $tracker;
		}

		$diff = $tracker->get_diff( $path );

		if ( is_wp_error( $diff ) ) {
			return $diff;
		}

		return [
			'path'        => $path,
			'has_changes' => '' !== $diff,
			'diff'        => $diff,
		];
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'   => true,
				'idempotent' => true,
			],
			'show_in_rest' => true,
		];
	}
}
