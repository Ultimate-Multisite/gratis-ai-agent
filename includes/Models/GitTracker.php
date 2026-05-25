<?php

declare(strict_types=1);
/**
 * GitTracker: tracks original file content as git-style blobs for a single plugin or theme.
 *
 * Stores the original (pre-AI-modification) content of each file as a binary blob in the
 * database, keyed by file path. Provides diff generation and revert capabilities so the
 * agent can show what changed and undo modifications.
 *
 * Design: one GitTracker instance per tracked package (plugin or theme). The manager
 * (GitTrackerManager) owns the collection of trackers across all packages.
 *
 * @package SdAiAgent
 * @since   1.1.0
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Models;

use SdAiAgent\Core\Database;
use SdAiAgent\Models\DTO\GitTrackedFileRow;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks original file content for a single plugin or theme package.
 *
 * @since 1.1.0
 */
class GitTracker {

	/**
	 * Check if a file path is a PHP file.
	 *
	 * @param string $path File path (relative or absolute).
	 * @return bool True if the file has a .php extension.
	 */
	private static function is_php_file( string $path ): bool {
		return (bool) preg_match( '/\.php$/i', $path );
	}

	/**
	 * Lint PHP content for syntax errors.
	 *
	 * Uses {@see token_get_all()} with `TOKEN_PARSE` to surface syntax errors as
	 * `\ParseError`. A scoped `set_error_handler()` converts any notices/warnings
	 * emitted by the tokeniser into `\ErrorException` so they do not leak to the
	 * site-wide error log. The handler is always restored in a `finally` block.
	 *
	 * @param string $content PHP source code.
	 * @return array{valid: bool, error?: string, line?: int}
	 */
	private static function lint_php( string $content ): array {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped error handler is required to convert tokeniser notices into exceptions; restored in finally.
		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): bool {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- ErrorException constructor arguments are not output; PHPCS false positive.
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);

		try {
			$tokens = token_get_all( $content, TOKEN_PARSE );
			unset( $tokens ); // Result unused — we only care about parse errors.
			return [ 'valid' => true ];
		} catch ( \ParseError | \ErrorException $e ) {
			return [
				'valid' => false,
				'error' => $e->getMessage(),
				'line'  => $e->getLine(),
			];
		} catch ( \Throwable $e ) {
			return [
				'valid' => false,
				'error' => $e->getMessage(),
				'line'  => $e->getLine(),
			];
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * File type: plugin.
	 */
	const TYPE_PLUGIN = 'plugin';

	/**
	 * File type: theme.
	 */
	const TYPE_THEME = 'theme';

	/**
	 * Status: file unchanged since tracking began.
	 */
	const STATUS_UNCHANGED = 'unchanged';

	/**
	 * Status: file has been modified since tracking began.
	 */
	const STATUS_MODIFIED = 'modified';

	/**
	 * Status: file was deleted after tracking began.
	 */
	const STATUS_DELETED = 'deleted';

	/**
	 * The package slug (e.g. "akismet/akismet.php" for plugins, "twentytwentyfour" for themes).
	 *
	 * @var string
	 */
	private string $package_slug;

	/**
	 * The package type: 'plugin' or 'theme'.
	 *
	 * @var string
	 */
	private string $package_type;

	/**
	 * Absolute path to the package root directory.
	 *
	 * @var string
	 */
	private string $package_path;

	/**
	 * @param string $package_slug Plugin slug (e.g. "akismet/akismet.php") or theme slug (e.g. "twentytwentyfour").
	 * @param string $package_type One of TYPE_PLUGIN or TYPE_THEME.
	 * @param string $package_path Absolute filesystem path to the package root.
	 */
	public function __construct( string $package_slug, string $package_type, string $package_path ) {
		$this->package_slug = $package_slug;
		$this->package_type = $package_type;
		$this->package_path = rtrim( $package_path, '/\\' );
	}

	/**
	 * Get the package slug.
	 *
	 * @return string
	 */
	public function get_package_slug(): string {
		return $this->package_slug;
	}

	/**
	 * Get the package type.
	 *
	 * @return string
	 */
	public function get_package_type(): string {
		return $this->package_type;
	}

	/**
	 * Get the package root path.
	 *
	 * @return string
	 */
	public function get_package_path(): string {
		return $this->package_path;
	}

	/**
	 * Snapshot the original content of a file before it is modified.
	 *
	 * If the file is already tracked, this is a no-op (the original is preserved).
	 * Call this before any write/edit operation to ensure the original is captured.
	 *
	 * @param string $absolute_path Absolute filesystem path to the file.
	 * @return true|WP_Error True on success (or already tracked), WP_Error on failure.
	 */
	public function snapshot_file( string $absolute_path ) {
		if ( ! file_exists( $absolute_path ) ) {
			return new WP_Error(
				'sd_ai_agent_git_tracker_file_not_found',
				sprintf(
					/* translators: %s: file path */
					__( 'Cannot snapshot: file not found: %s', 'superdav-ai-agent' ),
					$absolute_path
				)
			);
		}

		$relative_path = $this->to_relative_path( $absolute_path );
		if ( is_wp_error( $relative_path ) ) {
			return $relative_path;
		}

		// Already tracked — preserve the original.
		if ( $this->is_tracked( $relative_path ) ) {
			return true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local file, not remote URL.
		$content = file_get_contents( $absolute_path );
		if ( false === $content ) {
			return new WP_Error(
				'sd_ai_agent_git_tracker_read_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Cannot snapshot: failed to read file: %s', 'superdav-ai-agent' ),
					$absolute_path
				)
			);
		}

		return $this->insert_tracked_file( $relative_path, $content );
	}

	/**
	 * Record that a tracked file has been modified.
	 *
	 * Updates the current_hash and status in the database. If the file was not
	 * previously snapshotted, this is a no-op (returns WP_Error).
	 *
	 * @param string $absolute_path Absolute filesystem path to the modified file.
	 * @return true|WP_Error
	 */
	public function record_modification( string $absolute_path ) {
		$relative_path = $this->to_relative_path( $absolute_path );
		if ( is_wp_error( $relative_path ) ) {
			return $relative_path;
		}

		if ( ! $this->is_tracked( $relative_path ) ) {
			return new WP_Error(
				'sd_ai_agent_git_tracker_not_tracked',
				sprintf(
					/* translators: %s: file path */
					__( 'Cannot record modification: file not tracked: %s', 'superdav-ai-agent' ),
					$relative_path
				)
			);
		}

		if ( ! file_exists( $absolute_path ) ) {
			return $this->update_status( $relative_path, self::STATUS_DELETED );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local file.
		$content = file_get_contents( $absolute_path );
		if ( false === $content ) {
			return new WP_Error(
				'sd_ai_agent_git_tracker_read_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Cannot record modification: failed to read file: %s', 'superdav-ai-agent' ),
					$absolute_path
				)
			);
		}

		$current_hash = hash( 'sha256', $content );
		return $this->update_current_hash( $relative_path, $current_hash );
	}

	/**
	 * Revert a tracked file to its original snapshotted content.
	 *
	 * @param string $absolute_path Absolute filesystem path to the file.
	 * @return true|WP_Error
	 */
	public function revert_file( string $absolute_path ) {
		$relative_path = $this->to_relative_path( $absolute_path );
		if ( is_wp_error( $relative_path ) ) {
			return $relative_path;
		}

		$row = $this->get_tracked_row( $relative_path );
		if ( null === $row ) {
			return new WP_Error(
				'sd_ai_agent_git_tracker_not_tracked',
				sprintf(
					/* translators: %s: file path */
					__( 'Cannot revert: file not tracked: %s', 'superdav-ai-agent' ),
					$relative_path
				)
			);
		}

		$original_content = $row->original_content;

		// Validate PHP syntax before writing.
		if ( self::is_php_file( $absolute_path ) ) {
			$lint = self::lint_php( $original_content );
			if ( ! $lint['valid'] ) {
				return new WP_Error(
					'sd_ai_agent_git_tracker_php_syntax_error',
					sprintf(
						/* translators: 1: file path 2: error message 3: line number */
						__( 'Cannot revert: PHP syntax error in original content of %1$s: %2$s (line %3$d)', 'superdav-ai-agent' ),
						$absolute_path,
						$lint['error'] ?? 'Unknown',
						$lint['line'] ?? 0
					)
				);
			}
		}

		global $wp_filesystem;
		/** @var \WP_Filesystem_Base $wp_filesystem */
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $wp_filesystem->put_contents( $absolute_path, $original_content, FS_CHMOD_FILE ) ) {
			return new WP_Error(
				'sd_ai_agent_git_tracker_revert_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Failed to revert file: %s', 'superdav-ai-agent' ),
					$absolute_path
				)
			);
		}

		// Reset status to unchanged.
		return $this->update_current_hash( $relative_path, $row->original_hash, self::STATUS_UNCHANGED );
	}

	/**
	 * Generate a unified diff between the original and current content of a file.
	 *
	 * Returns an empty string if the file is unchanged or not tracked.
	 *
	 * @param string $absolute_path Absolute filesystem path to the file.
	 * @return string|WP_Error Unified diff string, or WP_Error on failure.
	 */
	public function get_diff( string $absolute_path ) {
		$relative_path = $this->to_relative_path( $absolute_path );
		if ( is_wp_error( $relative_path ) ) {
			return $relative_path;
		}

		$row = $this->get_tracked_row( $relative_path );
		if ( null === $row ) {
			return new WP_Error(
				'sd_ai_agent_git_tracker_not_tracked',
				sprintf(
					/* translators: %s: file path */
					__( 'Cannot diff: file not tracked: %s', 'superdav-ai-agent' ),
					$relative_path
				)
			);
		}

		if ( self::STATUS_UNCHANGED === $row->status ) {
			return '';
		}

		if ( self::STATUS_DELETED === $row->status ) {
			// Show full original as removed.
			$lines = explode( "\n", $row->original_content );
			$diff  = "--- a/{$relative_path}\n+++ /dev/null\n@@ -1," . count( $lines ) . " +0,0 @@\n";
			foreach ( $lines as $line ) {
				$diff .= '-' . $line . "\n";
			}
			return $diff;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local file.
		$current_content = file_get_contents( $absolute_path );
		if ( false === $current_content ) {
			return new WP_Error(
				'sd_ai_agent_git_tracker_read_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Cannot diff: failed to read current file: %s', 'superdav-ai-agent' ),
					$absolute_path
				)
			);
		}

		return $this->compute_unified_diff(
			$row->original_content,
			$current_content,
			"a/{$relative_path}",
			"b/{$relative_path}"
		);
	}

	/**
	 * Get all tracked files for this package with their status.
	 *
	 * @return array<int, object> Array of row objects with file_path, status, tracked_at, modified_at.
	 */
	public function get_tracked_files(): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = Database::git_tracked_files_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable for live file status.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, file_path, status, tracked_at, modified_at FROM %i WHERE package_slug = %s ORDER BY file_path ASC',
				$table,
				$this->package_slug
			)
		);

		return $rows ?: [];
	}

	/**
	 * Get all modified files for this package.
	 *
	 * @return array<int, object>
	 */
	public function get_modified_files(): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = Database::git_tracked_files_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, file_path, status, tracked_at, modified_at FROM %i WHERE package_slug = %s AND status != %s ORDER BY file_path ASC',
				$table,
				$this->package_slug,
				self::STATUS_UNCHANGED
			)
		);

		return $rows ?: [];
	}

	/**
	 * Remove all tracked file records for this package.
	 *
	 * @return int Number of rows deleted.
	 */
	public function clear_tracking(): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = Database::git_tracked_files_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query.
		$result = $wpdb->delete(
			$table,
			[ 'package_slug' => $this->package_slug ],
			[ '%s' ]
		);

		return $result !== false ? (int) $result : 0;
	}

	/**
	 * Check whether a relative file path is already tracked.
	 *
	 * @param string $relative_path Path relative to the package root.
	 * @return bool
	 */
	public function is_tracked( string $relative_path ): bool {
		return null !== $this->get_tracked_row( $relative_path );
	}

	// ─── Private helpers ─────────────────────────────────────────────────────

	/**
	 * Convert an absolute path to a path relative to the package root.
	 *
	 * @param string $absolute_path Absolute filesystem path.
	 * @return string|WP_Error Relative path, or WP_Error if outside the package.
	 */
	private function to_relative_path( string $absolute_path ) {
		$real_absolute = realpath( $absolute_path );
		$real_package  = realpath( $this->package_path );

		if ( false === $real_package ) {
			return new WP_Error(
				'sd_ai_agent_git_tracker_invalid_package',
				sprintf(
					/* translators: %s: package path */
					__( 'Package path does not exist: %s', 'superdav-ai-agent' ),
					$this->package_path
				)
			);
		}

		// For files that don't exist yet (e.g. deleted), fall back to string comparison.
		if ( false === $real_absolute ) {
			$real_absolute = $absolute_path;
		}

		$package_prefix = rtrim( $real_package, '/\\' ) . '/';
		if ( strpos( $real_absolute, $package_prefix ) !== 0 ) {
			return new WP_Error(
				'sd_ai_agent_git_tracker_outside_package',
				sprintf(
					/* translators: 1: file path, 2: package path */
					__( 'File %1$s is outside the package directory %2$s', 'superdav-ai-agent' ),
					$absolute_path,
					$this->package_path
				)
			);
		}

		return substr( $real_absolute, strlen( $package_prefix ) );
	}

	/**
	 * Fetch a tracked file row from the database.
	 *
	 * @param string $relative_path Path relative to the package root.
	 * @return GitTrackedFileRow|null Row object or null if not found.
	 */
	private function get_tracked_row( string $relative_path ): ?GitTrackedFileRow {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = Database::git_tracked_files_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable for live file status.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE package_slug = %s AND file_path = %s',
				$table,
				$this->package_slug,
				$relative_path
			)
		);

		return $row instanceof \stdClass ? GitTrackedFileRow::from_row( $row ) : null;
	}

	/**
	 * Insert a new tracked file record.
	 *
	 * @param string $relative_path    Path relative to the package root.
	 * @param string $original_content Original file content.
	 * @return true|WP_Error
	 */
	private function insert_tracked_file( string $relative_path, string $original_content ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table         = Database::git_tracked_files_table_name();
		$original_hash = hash( 'sha256', $original_content );
		$now           = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert.
		$result = $wpdb->insert(
			$table,
			[
				'file_path'        => $relative_path,
				'file_type'        => $this->package_type,
				'package_slug'     => $this->package_slug,
				'original_hash'    => $original_hash,
				'original_content' => $original_content,
				'current_hash'     => $original_hash,
				'status'           => self::STATUS_UNCHANGED,
				'tracked_at'       => $now,
				'modified_at'      => null,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', null ]
		);

		if ( false === $result ) {
			return new WP_Error(
				'sd_ai_agent_git_tracker_insert_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Failed to insert tracking record for: %s', 'superdav-ai-agent' ),
					$relative_path
				)
			);
		}

		return true;
	}

	/**
	 * Update the current hash (and optionally status) for a tracked file.
	 *
	 * @param string $relative_path Path relative to the package root.
	 * @param string $current_hash  SHA-256 hash of the current content.
	 * @param string $status        New status (defaults to STATUS_MODIFIED).
	 * @return true|WP_Error
	 */
	private function update_current_hash( string $relative_path, string $current_hash, string $status = self::STATUS_MODIFIED ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = Database::git_tracked_files_table_name();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table update.
		$result = $wpdb->update(
			$table,
			[
				'current_hash' => $current_hash,
				'status'       => $status,
				'modified_at'  => $now,
			],
			[
				'package_slug' => $this->package_slug,
				'file_path'    => $relative_path,
			],
			[ '%s', '%s', '%s' ],
			[ '%s', '%s' ]
		);

		if ( false === $result ) {
			return new WP_Error(
				'sd_ai_agent_git_tracker_update_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Failed to update tracking record for: %s', 'superdav-ai-agent' ),
					$relative_path
				)
			);
		}

		return true;
	}

	/**
	 * Update only the status for a tracked file.
	 *
	 * @param string $relative_path Path relative to the package root.
	 * @param string $status        New status.
	 * @return true|WP_Error
	 */
	private function update_status( string $relative_path, string $status ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = Database::git_tracked_files_table_name();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table update.
		$result = $wpdb->update(
			$table,
			[
				'status'      => $status,
				'modified_at' => $now,
			],
			[
				'package_slug' => $this->package_slug,
				'file_path'    => $relative_path,
			],
			[ '%s', '%s' ],
			[ '%s', '%s' ]
		);

		if ( false === $result ) {
			return new WP_Error(
				'sd_ai_agent_git_tracker_update_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Failed to update status for: %s', 'superdav-ai-agent' ),
					$relative_path
				)
			);
		}

		return true;
	}

	/**
	 * Compute a simple unified diff between two strings.
	 *
	 * Uses WordPress's wp_text_diff() if available, otherwise falls back to a
	 * line-by-line LCS-based comparison.
	 *
	 * @param string $original       Original content.
	 * @param string $current        Current content.
	 * @param string $original_label Label for the original file (e.g. "a/path/to/file.php").
	 * @param string $current_label  Label for the current file (e.g. "b/path/to/file.php").
	 * @return string Unified diff.
	 */
	private function compute_unified_diff( string $original, string $current, string $original_label, string $current_label ): string {
		if ( $original === $current ) {
			return '';
		}

		// Use WordPress's built-in diff renderer if available.
		if ( function_exists( 'wp_text_diff' ) ) {
			$diff = wp_text_diff( $original, $current, [ 'show_split_view' => false ] );
			if ( ! empty( $diff ) ) {
				return "--- {$original_label}\n+++ {$current_label}\n" . $diff;
			}
		}

		// Fallback: simple line-by-line diff.
		$original_lines = explode( "\n", $original );
		$current_lines  = explode( "\n", $current );

		$diff  = "--- {$original_label}\n";
		$diff .= "+++ {$current_label}\n";
		$diff .= '@@ -1,' . count( $original_lines ) . ' +1,' . count( $current_lines ) . " @@\n";

		$lcs        = $this->longest_common_subsequence( $original_lines, $current_lines );
		$i          = 0;
		$j          = 0;
		$lcs_idx    = 0;
		$orig_count = count( $original_lines );
		$curr_count = count( $current_lines );
		$lcs_count  = count( $lcs );

		while ( $i < $orig_count || $j < $curr_count ) {
			if ( $lcs_idx < $lcs_count && $i < $orig_count && $original_lines[ $i ] === $lcs[ $lcs_idx ] && $j < $curr_count && $current_lines[ $j ] === $lcs[ $lcs_idx ] ) {
				$diff .= ' ' . $original_lines[ $i ] . "\n";
				++$i;
				++$j;
				++$lcs_idx;
			} elseif ( $j < $curr_count && ( $lcs_idx >= $lcs_count || $current_lines[ $j ] !== $lcs[ $lcs_idx ] ) ) {
				$diff .= '+' . $current_lines[ $j ] . "\n";
				++$j;
			} else {
				$diff .= '-' . $original_lines[ $i ] . "\n";
				++$i;
			}
		}

		return $diff;
	}

	/**
	 * Compute the longest common subsequence of two arrays of strings.
	 *
	 * @param string[] $a First array.
	 * @param string[] $b Second array.
	 * @return string[] LCS as an array of strings.
	 */
	private function longest_common_subsequence( array $a, array $b ): array {
		$m  = count( $a );
		$n  = count( $b );
		$dp = array_fill( 0, $m + 1, array_fill( 0, $n + 1, 0 ) );

		for ( $i = 1; $i <= $m; $i++ ) {
			for ( $j = 1; $j <= $n; $j++ ) {
				if ( $a[ $i - 1 ] === $b[ $j - 1 ] ) {
					$dp[ $i ][ $j ] = $dp[ $i - 1 ][ $j - 1 ] + 1;
				} else {
					$dp[ $i ][ $j ] = max( $dp[ $i - 1 ][ $j ], $dp[ $i ][ $j - 1 ] );
				}
			}
		}

		// Backtrack to find the LCS.
		$lcs = [];
		$i   = $m;
		$j   = $n;
		while ( $i > 0 && $j > 0 ) {
			if ( $a[ $i - 1 ] === $b[ $j - 1 ] ) {
				array_unshift( $lcs, $a[ $i - 1 ] );
				--$i;
				--$j;
			} elseif ( $dp[ $i - 1 ][ $j ] > $dp[ $i ][ $j - 1 ] ) {
				--$i;
			} else {
				--$j;
			}
		}

		return $lcs;
	}
}
