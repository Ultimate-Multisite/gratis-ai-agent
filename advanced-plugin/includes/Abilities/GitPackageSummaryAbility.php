<?php

declare(strict_types=1);
/**
 * Git Package Summary ability — get a summary for a specific package.
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
 * Git Package Summary ability — get a summary for a specific package.
 *
 * @since 1.1.0
 */
class GitPackageSummaryAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Package Change Summary', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Get a summary of tracked and modified files for a specific plugin or theme package.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
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
			'required'   => [ 'package_slug' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'slug'           => [ 'type' => 'string' ],
				'type'           => [ 'type' => 'string' ],
				'path'           => [ 'type' => 'string' ],
				'total_tracked'  => [ 'type' => 'integer' ],
				'modified_count' => [ 'type' => 'integer' ],
				'by_status'      => [ 'type' => 'object' ],
				'modified_files' => [ 'type' => 'array' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$package_slug = $input['package_slug'] ?? null;
		$package_type = $input['package_type'] ?? null;

		if ( ! is_string( $package_slug ) || '' === $package_slug ) {
			return new WP_Error( 'sd_ai_agent_invalid_slug', __( 'Package slug must be a non-empty string.', 'superdav-ai-agent' ) );
		}

		if ( ! is_string( $package_type ) || '' === $package_type ) {
			$package_type = 'plugin';
		}

		$summary = GitTrackerManager::get_package_summary( $package_slug, $package_type );

		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		return $summary;
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
