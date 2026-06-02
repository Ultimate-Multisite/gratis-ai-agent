<?php

declare(strict_types=1);
/**
 * List Allowed Roots ability.
 *
 * Returns the resolved (realpath-normalised) list of filesystem directories
 * where the AI is permitted to read or write. Deduplicates by resolved path
 * so symlinked or alternate-mount duplicates collapse to one entry.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Core\WordPressPaths;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List Allowed Roots ability.
 *
 * @since 1.0.0
 */
class ListAllowedRootsAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'List Allowed Roots', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Returns the list of filesystem directories where the AI is permitted to read or write. Call this before any file write operation to pick a valid target path without trial-and-error. Returns an associative array of human-readable labels to absolute paths.', 'superdav-ai-agent' );
	}

	protected function category(): string {
		return 'sd-ai-agent';
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [],
			'required'   => [],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'roots' => [
					'type'                 => 'object',
					'description'          => 'Associative array of label => absolute path for each allowed root.',
					'additionalProperties' => [
						'type' => 'string',
					],
				],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$roots = $this->resolve_allowed_roots();

		return [
			'roots' => $roots,
		];
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

	/**
	 * Resolve the list of allowed filesystem roots.
	 *
	 * Returns an associative array of label => absolute path, deduplicated
	 * by resolved realpath so symlinked or alternate-mount duplicates collapse.
	 *
	 * Roots include:
	 * - plugins → plugins root derived via plugin_dir_path()
	 * - themes → get_theme_root()
	 * - mu-plugins → WPMU_PLUGIN_DIR (only if exists)
	 * - uploads → wp_upload_dir()['basedir']
	 * - ai-edits → uploads/ai-edits (only if exists)
	 * - Any custom roots registered via sd_ai_agent_allowed_roots filter
	 *
	 * @return array<string, string> Associative array of label => resolved path.
	 */
	private function resolve_allowed_roots(): array {
		$raw = [];

		// Standard WordPress roots.
		$raw['plugins'] = WordPressPaths::plugins_dir();
		$raw['themes']  = get_theme_root();
		$raw['uploads'] = WordPressPaths::uploads_dir();

		// Optional roots.
		if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
			$raw['mu-plugins'] = WPMU_PLUGIN_DIR;
		}

		$ai_edits_dir = trailingslashit( WordPressPaths::uploads_dir() ) . 'ai-edits';
		if ( is_dir( $ai_edits_dir ) ) {
			$raw['ai-edits'] = $ai_edits_dir;
		}

		/**
		 * Filter to add custom allowed roots.
		 *
		 * @param array<string, string> $raw Associative array of label => path.
		 * @return array<string, string> Filtered array of label => path.
		 */
		$raw = apply_filters( 'sd_ai_agent_allowed_roots', $raw );

		// Deduplicate by resolved realpath.
		$by_real  = [];
		$resolved = [];

		foreach ( $raw as $label => $path ) {
			$real = realpath( $path );
			if ( false !== $real && is_dir( $real ) && ! isset( $by_real[ $real ] ) ) {
				$by_real[ $real ]   = true;
				$resolved[ $label ] = $real;
			}
		}

		return $resolved;
	}
}
