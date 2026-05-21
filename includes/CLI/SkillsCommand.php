<?php

declare(strict_types=1);
/**
 * WP-CLI command: wp sd-ai-agent skills
 *
 * Maintenance commands for the vendored WordPress/agent-skills bundle.
 *
 * @package SdAiAgent\CLI
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\CLI;

use WP_CLI;
use WP_CLI_Command;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage vendored WordPress agent skills.
 *
 * ## EXAMPLES
 *
 *   # Dry-run: preview what would change
 *   wp sd-ai-agent skills sync-wp-agent-skills --dry-run
 *
 *   # Perform a live sync
 *   wp sd-ai-agent skills sync-wp-agent-skills
 */
class SkillsCommand extends WP_CLI_Command {

	/**
	 * Upstream repo API base URL.
	 */
	private const UPSTREAM_API_BASE = 'https://api.github.com/repos/WordPress/agent-skills/contents/skills';

	/**
	 * Raw content base for direct file downloads.
	 */
	private const UPSTREAM_RAW_BASE = 'https://raw.githubusercontent.com/WordPress/agent-skills/trunk/skills';

	/**
	 * Skills to sync (slugs from WordPress/agent-skills).
	 *
	 * @var list<string>
	 */
	private const SYNC_SLUGS = [
		'wp-plugin-development',
		'wp-block-development',
		'wp-block-themes',
		'wp-rest-api',
		'wp-wpcli-and-ops',
	];

	/**
	 * Attribution header prepended to every vendored skill file.
	 */
	private const ATTRIBUTION_HEADER = "<!-- Adapted from github.com/WordPress/agent-skills (GPL-2.0-or-later) -->\n<!-- studio wp  →  wp  (Studio WP-CLI prefix removed for non-Studio environments) -->\n";

	/**
	 * Sync the five curated WordPress/agent-skills into includes/Models/skills/.
	 *
	 * Fetches each SKILL.md from WordPress/agent-skills, applies sanitisation
	 * (removes Studio-specific `studio wp ` CLI prefix, replaces wp-project-triage
	 * Node script references), prepends an attribution header, and writes the
	 * result to `includes/Models/skills/<slug>.md`.
	 *
	 * Uses `wp_remote_get()` so it works in any WP environment (honours
	 * `WP_HTTP_BLOCK_EXTERNAL` and proxy settings).
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would change without writing any files.
	 *
	 * ## EXAMPLES
	 *
	 *   wp sd-ai-agent skills sync-wp-agent-skills --dry-run
	 *   wp sd-ai-agent skills sync-wp-agent-skills
	 *
	 * @subcommand sync-wp-agent-skills
	 * @param array<int, string>   $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function sync_wp_agent_skills( array $args, array $assoc_args ): void {
		$dry_run    = (bool) ( $assoc_args['dry-run'] ?? false );
		$skills_dir = SD_AI_AGENT_DIR . 'includes/Models/skills/';

		if ( $dry_run ) {
			WP_CLI::log( '[dry-run] No files will be written.' );
		}

		$results = [];

		foreach ( self::SYNC_SLUGS as $slug ) {
			$result    = $this->sync_skill( $slug, $skills_dir, $dry_run );
			$results[] = $result;
			$status    = $result['status'];
			$msg       = "[{$slug}] {$status}";
			if ( 'error' === $result['outcome'] ) {
				WP_CLI::warning( $msg );
			} else {
				WP_CLI::log( $msg );
			}
		}

		$this->print_summary( $results, $dry_run );
	}

	/**
	 * Sync a single skill file.
	 *
	 * @param string $slug       Skill slug.
	 * @param string $skills_dir Absolute path to skills directory (trailing slash).
	 * @param bool   $dry_run    When true, skip writing.
	 * @return array{slug:string, status:string, outcome:string}
	 */
	private function sync_skill( string $slug, string $skills_dir, bool $dry_run ): array {
		$url     = self::UPSTREAM_RAW_BASE . "/{$slug}/SKILL.md";
		$content = $this->fetch_remote( $url );

		if ( null === $content ) {
			return [
				'slug'    => $slug,
				'status'  => "FETCH FAILED ({$url})",
				'outcome' => 'error',
			];
		}

		$sanitised = $this->sanitise( $content );
		$dest_path = $skills_dir . $slug . '.md';

		// Check if the current file (without attribution header) matches.
		$existing_body = '';
		if ( file_exists( $dest_path ) ) {
			// Reading a local plugin file path, not a remote URL — wp_remote_get() does not apply.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$raw_existing  = (string) file_get_contents( $dest_path );
			$existing_body = $this->strip_attribution_header( $raw_existing );
		}

		if ( $sanitised === $existing_body ) {
			return [
				'slug'    => $slug,
				'status'  => 'up to date',
				'outcome' => 'ok',
			];
		}

		$final = self::ATTRIBUTION_HEADER . $sanitised;

		if ( ! $dry_run ) {
			// Writing a local plugin file — WP_Filesystem initialization is not appropriate
			// in a CLI command context where the filesystem transport is always direct.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dest_path, $final );
			$action = file_exists( $dest_path ) ? 'updated' : 'created';
		} else {
			$action = file_exists( $dest_path ) ? 'would update' : 'would create';
		}

		return [
			'slug'    => $slug,
			'status'  => $action,
			'outcome' => 'ok',
		];
	}

	/**
	 * Apply sanitisation rules to upstream skill content.
	 *
	 * Rules:
	 * 1. Replace `studio wp ` with `wp ` (Studio's WP-CLI prefix is not used outside Studio).
	 * 2. Replace `node skills/wp-project-triage/scripts/detect_wp_project.mjs` (and variations)
	 *    with a manual verification note, since the Node triage script is not available here.
	 * 3. Replace other `node skills/{slug}/scripts/{name}.mjs` invocations with a manual note.
	 *
	 * @param string $content Raw upstream content.
	 * @return string Sanitised content (no attribution header).
	 */
	private function sanitise( string $content ): string {
		// Rule 1: strip Studio WP-CLI prefix.
		$content = str_replace( 'studio wp ', 'wp ', $content );

		// Rule 2: replace wp-project-triage Node script reference.
		$content = preg_replace(
			'/`node\s+skills\/wp-project-triage\/scripts\/detect_wp_project\.mjs`/',
			'Verify WordPress project structure manually before proceeding.',
			(string) $content
		);
		$content = preg_replace(
			'/node\s+skills\/wp-project-triage\/scripts\/detect_wp_project\.mjs/',
			'Verify WordPress project structure manually before proceeding.',
			(string) $content
		);

		// Rule 3: replace remaining skill-specific Node script references.
		$content = preg_replace(
			'/`node\s+skills\/[^`]+\.mjs(?:[^`]*)`/',
			'Run the appropriate manual inspection for this skill (Node triage scripts are not available in this environment).',
			(string) $content
		);
		$content = preg_replace(
			'/- `node\s+skills\/[^\n]+\.mjs[^\n]*`\n/',
			'- Inspect manually (Node triage scripts are not available in this environment).' . "\n",
			(string) $content
		);

		return (string) $content;
	}

	/**
	 * Strip the attribution header block from existing file content for comparison.
	 *
	 * @param string $content Existing file content (may or may not have the header).
	 * @return string Content without the header lines.
	 */
	private function strip_attribution_header( string $content ): string {
		$lines     = explode( "\n", $content );
		$result    = [];
		$in_header = true;

		foreach ( $lines as $line ) {
			if ( $in_header && str_starts_with( $line, '<!--' ) ) {
				continue;
			}
			$in_header = false;
			$result[]  = $line;
		}

		return ltrim( implode( "\n", $result ) );
	}

	/**
	 * Fetch a remote URL using wp_remote_get().
	 *
	 * @param string $url URL to fetch.
	 * @return string|null Response body, or null on failure.
	 */
	private function fetch_remote( string $url ): ?string {
		$response = wp_remote_get(
			$url,
			[
				'timeout'    => 30,
				'user-agent' => 'SdAiAgent/skills-sync (+https://github.com/Ultimate-Multisite/superdav-ai-agent)',
			]
		);

		if ( is_wp_error( $response ) ) {
			WP_CLI::warning( 'HTTP error for ' . $url . ': ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			WP_CLI::warning( "HTTP {$code} for {$url}" );
			return null;
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Print a summary table of sync results.
	 *
	 * @param list<array{slug:string, status:string, outcome:string}> $results Sync results.
	 * @param bool                                                    $dry_run Whether this was a dry run.
	 */
	private function print_summary( array $results, bool $dry_run ): void {
		$errors = array_filter( $results, fn( $r ) => 'error' === $r['outcome'] );

		WP_CLI\Utils\format_items(
			'table',
			array_map(
				fn( $r ) => [
					'skill'  => $r['slug'],
					'result' => $r['status'],
				],
				$results
				),
			[ 'skill', 'result' ]
		);

		if ( ! empty( $errors ) ) {
			WP_CLI::error( count( $errors ) . ' skill(s) failed to sync. Check warnings above.' );
			return;
		}

		$label = $dry_run ? 'Dry run complete.' : 'Sync complete.';
		WP_CLI::success( $label . ' ' . count( $results ) . ' skill(s) processed.' );
	}
}
