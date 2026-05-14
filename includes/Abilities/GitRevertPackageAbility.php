<?php

declare(strict_types=1);
/**
 * Git Revert Package ability — revert all modified files in a package.
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
 * Git Revert Package ability — revert all modified files in a package.
 *
 * @since 1.1.0
 */
class GitRevertPackageAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Revert Package', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Revert all modified files in a plugin or theme back to their original snapshotted content.', 'superdav-ai-agent' );
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
				'package_slug' => [ 'type' => 'string' ],
				'reverted'     => [ 'type' => 'integer' ],
				'failed'       => [ 'type' => 'integer' ],
				'message'      => [ 'type' => 'string' ],
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

		$result = GitTrackerManager::revert_package( $package_slug, $package_type );

		return [
			'package_slug' => $package_slug,
			'reverted'     => $result['reverted'],
			'failed'       => $result['failed'],
			'message'      => sprintf(
				/* translators: 1: reverted count, 2: failed count, 3: package slug */
				__( 'Reverted %1$d file(s), %2$d failed for package %3$s.', 'superdav-ai-agent' ),
				$result['reverted'],
				$result['failed'],
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
				'readonly'    => false,
				'destructive' => true,
			],
			'show_in_rest' => true,
		];
	}
}
