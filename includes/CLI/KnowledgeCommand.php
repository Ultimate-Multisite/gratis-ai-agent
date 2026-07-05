<?php

declare(strict_types=1);
/**
 * WP-CLI command for knowledge base maintenance.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\CLI;

use SdAiAgent\Knowledge\Knowledge;
use SdAiAgent\Knowledge\KnowledgeDatabase;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import and maintain knowledge base sources.
 */
class KnowledgeCommand extends \WP_CLI_Command {

	/**
	 * Import a static documentation JSON/JSONL manifest.
	 *
	 * ## OPTIONS
	 *
	 * <manifest>
	 * : Path to a trusted JSON or JSONL manifest file. Each record should include
	 *   id/path/url, title, route/url, metadata, and content.
	 *
	 * [--collection-id=<id>]
	 * : Existing knowledge collection ID.
	 *
	 * [--collection=<slug>]
	 * : Existing knowledge collection slug. Used when --collection-id is omitted.
	 *
	 * [--prune]
	 * : Delete static_file sources no longer present in the manifest.
	 *
	 * [--stale-removed]
	 * : Mark static_file sources no longer present in the manifest as stale.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sd-ai-agent knowledge import-static-docs build/docs-manifest.jsonl --collection=docs --prune
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function import_static_docs( array $args, array $assoc_args ): void {
		$manifest_path = $args[0] ?? '';
		if ( '' === $manifest_path ) {
			WP_CLI::error( 'Manifest path is required.' );
			return;
		}

		$collection_id = $this->resolve_collection_id( $assoc_args );
		if ( $collection_id <= 0 ) {
			WP_CLI::error( 'Provide a valid --collection-id or --collection slug.' );
			return;
		}

		$result = Knowledge::import_static_docs_manifest_file(
			$collection_id,
			$manifest_path,
			[
				'prune'         => \WP_CLI\Utils\get_flag_value( $assoc_args, 'prune', false ),
				'stale_removed' => \WP_CLI\Utils\get_flag_value( $assoc_args, 'stale-removed', false ),
			]
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		WP_CLI::success(
			sprintf(
				'Imported static docs: imported=%d updated=%d skipped=%d pruned=%d stale=%d errors=%d',
				$result['imported'],
				$result['updated'],
				$result['skipped'],
				$result['pruned'],
				$result['stale'],
				$result['errors']
			)
		);
	}

	/**
	 * Resolve a collection ID from CLI flags.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return int Collection ID, or 0 when unresolved.
	 */
	private function resolve_collection_id( array $assoc_args ): int {
		$collection_id = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'collection-id', 0 );
		if ( $collection_id > 0 ) {
			return $collection_id;
		}

		$slug = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'collection', '' );
		if ( '' === $slug ) {
			return 0;
		}

		$collection = KnowledgeDatabase::get_collection_by_slug( $slug );
		return $collection ? (int) $collection->id : 0;
	}
}
