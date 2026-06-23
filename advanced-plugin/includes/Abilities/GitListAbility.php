<?php

declare(strict_types=1);
/**
 * Git List ability — list all tracked files across all packages.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Models\GitTrackerManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Git List ability — list all tracked files across all packages.
 *
 * @since 1.1.0
 */
class GitListAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'List Tracked Files', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'List all files that have been snapshotted, with their modification status.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'status'       => [
					'type'        => 'string',
					'enum'        => [ 'unchanged', 'modified', 'deleted' ],
					'description' => 'Filter by status. Omit to list all tracked files.',
				],
				'package_slug' => [
					'type'        => 'string',
					'description' => 'Filter to a single plugin or theme slug. Match is on `package_slug` (e.g. "akismet" or "akismet/akismet.php").',
				],
			],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'files'    => [ 'type' => 'array' ],
				'count'    => [ 'type' => 'integer' ],
				'packages' => [ 'type' => 'array' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$status_raw = $input['status'] ?? null;
		$status     = is_string( $status_raw ) && '' !== $status_raw ? $status_raw : null;

		$rows = GitTrackerManager::get_all_tracked_files( $status );

		$slug_filter_raw = $input['package_slug'] ?? null;
		$slug_filter     = is_string( $slug_filter_raw ) && '' !== $slug_filter_raw ? $slug_filter_raw : null;

		$files = [];
		foreach ( $rows as $row ) {
			if ( null !== $slug_filter ) {
				$row_slug = (string) $row->package_slug;
				$head     = explode( '/', $row_slug, 2 )[0];
				if ( $row_slug !== $slug_filter && $head !== $slug_filter ) {
					continue;
				}
			}
			$files[] = [
				'id'           => (int) $row->id,
				'package_slug' => $row->package_slug,
				'file_type'    => $row->file_type,
				'file_path'    => $row->file_path,
				'status'       => $row->status,
				'tracked_at'   => $row->tracked_at,
				'modified_at'  => $row->modified_at,
			];
		}

		$packages = GitTrackerManager::get_modified_packages();

		return [
			'files'    => $files,
			'count'    => count( $files ),
			'packages' => $packages,
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
