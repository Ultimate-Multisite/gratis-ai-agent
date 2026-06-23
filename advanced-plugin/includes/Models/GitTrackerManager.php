<?php

declare(strict_types=1);
/**
 * GitTrackerManager: manages GitTracker instances across all plugins and themes.
 *
 * Acts as a registry and factory for GitTracker objects. Provides site-wide
 * operations (list all modified packages, revert all changes, etc.) and hooks
 * into FileAbilities write/edit operations to automatically snapshot files
 * before they are modified by the AI agent.
 *
 * @package SdAiAgent
 * @since   1.1.0
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Models;

use SdAiAgent\Core\Database;
use SdAiAgent\Core\WordPressPaths;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry and factory for GitTracker instances.
 *
 * @since 1.1.0
 */
class GitTrackerManager {

	/**
	 * In-memory cache of GitTracker instances keyed by package slug.
	 *
	 * @var array<string, GitTracker>
	 */
	private static array $trackers = [];

	/**
	 * Get or create a GitTracker for a plugin.
	 *
	 * @param string $plugin_file Plugin file relative to the plugins directory (e.g. "akismet/akismet.php").
	 * @return GitTracker|WP_Error
	 */
	public static function for_plugin( string $plugin_file ) {
		if ( isset( self::$trackers[ $plugin_file ] ) ) {
			return self::$trackers[ $plugin_file ];
		}

		$plugin_dir = WordPressPaths::plugin_path( dirname( $plugin_file ) );

		if ( ! is_dir( $plugin_dir ) ) {
			return new WP_Error(
				'sd_ai_agent_git_tracker_plugin_not_found',
				sprintf(
					/* translators: %s: plugin file */
					__( 'Plugin directory not found for: %s', 'superdav-ai-agent' ),
					$plugin_file
				)
			);
		}

		$tracker                        = new GitTracker( $plugin_file, GitTracker::TYPE_PLUGIN, $plugin_dir );
		self::$trackers[ $plugin_file ] = $tracker;
		return $tracker;
	}

	/**
	 * Get or create a GitTracker for a theme.
	 *
	 * @param string $theme_slug Theme directory name (e.g. "twentytwentyfour").
	 * @return GitTracker|WP_Error
	 */
	public static function for_theme( string $theme_slug ) {
		$cache_key = 'theme:' . $theme_slug;

		if ( isset( self::$trackers[ $cache_key ] ) ) {
			return self::$trackers[ $cache_key ];
		}

		$theme_dir = get_theme_root() . '/' . $theme_slug;

		if ( ! is_dir( $theme_dir ) ) {
			return new WP_Error(
				'sd_ai_agent_git_tracker_theme_not_found',
				sprintf(
					/* translators: %s: theme slug */
					__( 'Theme directory not found for: %s', 'superdav-ai-agent' ),
					$theme_slug
				)
			);
		}

		$tracker                      = new GitTracker( $theme_slug, GitTracker::TYPE_THEME, $theme_dir );
		self::$trackers[ $cache_key ] = $tracker;
		return $tracker;
	}

	/**
	 * Resolve the correct GitTracker for an absolute file path.
	 *
	 * Determines whether the file belongs to a plugin or theme and returns
	 * the appropriate tracker. Returns WP_Error if the file is outside all
	 * tracked package directories.
	 *
	 * @param string $absolute_path Absolute filesystem path to the file.
	 * @return GitTracker|WP_Error
	 */
	public static function for_file( string $absolute_path ) {
		$real_path = realpath( $absolute_path );
		if ( false === $real_path ) {
			// File may not exist yet (e.g. new file being written); use the raw path.
			$real_path = $absolute_path;
		}

		// Check plugins directory.
		$plugin_dir = realpath( WordPressPaths::plugins_dir() );
		if ( false !== $plugin_dir && strpos( $real_path, $plugin_dir . '/' ) === 0 ) {
			$relative    = substr( $real_path, strlen( $plugin_dir ) + 1 );
			$parts       = explode( '/', $relative, 2 );
			$plugin_slug = $parts[0];

			// Resolve to the plugin's main file slug (dirname/mainfile.php).
			$plugin_file = self::resolve_plugin_file( $plugin_slug );
			if ( is_wp_error( $plugin_file ) ) {
				// Fall back to using the directory name as the slug.
				$plugin_file = $plugin_slug . '/' . $plugin_slug . '.php';
			}

			return self::for_plugin( $plugin_file );
		}

		// Check themes directory.
		$theme_root = realpath( get_theme_root() );
		if ( false !== $theme_root && strpos( $real_path, $theme_root . '/' ) === 0 ) {
			$relative   = substr( $real_path, strlen( $theme_root ) + 1 );
			$parts      = explode( '/', $relative, 2 );
			$theme_slug = $parts[0];
			return self::for_theme( $theme_slug );
		}

		return new WP_Error(
			'sd_ai_agent_git_tracker_outside_packages',
			sprintf(
				/* translators: %s: file path */
				__( 'File is not inside a plugin or theme directory: %s', 'superdav-ai-agent' ),
				$absolute_path
			)
		);
	}

	/**
	 * Snapshot a file before it is modified.
	 *
	 * This is the primary entry point called by FileAbilities before any write
	 * or edit operation. It resolves the correct tracker and snapshots the file.
	 * Silently succeeds if the file is outside tracked package directories.
	 *
	 * @param string $absolute_path Absolute filesystem path to the file.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function snapshot_before_modify( string $absolute_path ) {
		$tracker = self::for_file( $absolute_path );

		// Files outside plugins/themes are not tracked — not an error.
		if ( is_wp_error( $tracker ) ) {
			return true;
		}

		return $tracker->snapshot_file( $absolute_path );
	}

	/**
	 * Record that a file has been modified.
	 *
	 * Call this after a successful write/edit operation.
	 *
	 * @param string $absolute_path Absolute filesystem path to the modified file.
	 * @return true|WP_Error
	 */
	public static function record_modification( string $absolute_path ) {
		$tracker = self::for_file( $absolute_path );

		if ( is_wp_error( $tracker ) ) {
			return true; // Outside tracked packages — not an error.
		}

		return $tracker->record_modification( $absolute_path );
	}

	/**
	 * Get all packages that have modified files.
	 *
	 * Returns an array of package summaries with slug, type, and modified file count.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_modified_packages(): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = Database::git_tracked_files_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT package_slug, file_type, COUNT(*) AS modified_count FROM %i WHERE status != %s GROUP BY package_slug, file_type ORDER BY package_slug ASC',
				$table,
				GitTracker::STATUS_UNCHANGED
			)
		);

		if ( ! $rows ) {
			return [];
		}

		$packages = [];
		foreach ( $rows as $row ) {
			$packages[] = [
				'slug'           => $row->package_slug,
				'type'           => $row->file_type,
				'modified_count' => (int) $row->modified_count,
			];
		}

		return $packages;
	}

	/**
	 * Get all tracked files across all packages.
	 *
	 * @param string|null $status Filter by status (null = all).
	 * @return array<int, object>
	 */
	public static function get_all_tracked_files( ?string $status = null ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = Database::git_tracked_files_table_name();

		if ( null !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, package_slug, file_type, file_path, status, tracked_at, modified_at FROM %i WHERE status = %s ORDER BY package_slug ASC, file_path ASC',
					$table,
					$status
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, package_slug, file_type, file_path, status, tracked_at, modified_at FROM %i ORDER BY package_slug ASC, file_path ASC',
					$table
				)
			);
		}

		return $rows ?: [];
	}

	/**
	 * Revert all modified files for a specific package.
	 *
	 * @param string $package_slug Plugin file slug or theme slug.
	 * @param string $package_type GitTracker::TYPE_PLUGIN or GitTracker::TYPE_THEME.
	 * @return array{reverted: int, failed: int, errors: WP_Error[]}
	 */
	public static function revert_package( string $package_slug, string $package_type ): array {
		if ( GitTracker::TYPE_PLUGIN === $package_type ) {
			$tracker = self::for_plugin( $package_slug );
		} else {
			$tracker = self::for_theme( $package_slug );
		}

		if ( is_wp_error( $tracker ) ) {
			return [
				'reverted' => 0,
				'failed'   => 0,
				'errors'   => [ $tracker ],
			];
		}

		$modified_files = $tracker->get_modified_files();
		$reverted       = 0;
		$failed         = 0;
		$errors         = [];

		foreach ( $modified_files as $row ) {
			$absolute_path = $tracker->get_package_path() . '/' . $row->file_path;
			$result        = $tracker->revert_file( $absolute_path );

			if ( is_wp_error( $result ) ) {
				++$failed;
				$errors[] = $result;
			} else {
				++$reverted;
			}
		}

		return [
			'reverted' => $reverted,
			'failed'   => $failed,
			'errors'   => $errors,
		];
	}

	/**
	 * Get a summary of all tracked and modified files for a package.
	 *
	 * @param string $package_slug Plugin file slug or theme slug.
	 * @param string $package_type GitTracker::TYPE_PLUGIN or GitTracker::TYPE_THEME.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_package_summary( string $package_slug, string $package_type ) {
		if ( GitTracker::TYPE_PLUGIN === $package_type ) {
			$tracker = self::for_plugin( $package_slug );
		} else {
			$tracker = self::for_theme( $package_slug );
		}

		if ( is_wp_error( $tracker ) ) {
			return $tracker;
		}

		$all_files      = $tracker->get_tracked_files();
		$modified_files = $tracker->get_modified_files();

		$by_status = [
			GitTracker::STATUS_UNCHANGED => 0,
			GitTracker::STATUS_MODIFIED  => 0,
			GitTracker::STATUS_DELETED   => 0,
		];

		foreach ( $all_files as $row ) {
			if ( isset( $by_status[ $row->status ] ) ) {
				++$by_status[ $row->status ];
			}
		}

		return [
			'slug'           => $package_slug,
			'type'           => $package_type,
			'path'           => $tracker->get_package_path(),
			'total_tracked'  => count( $all_files ),
			'modified_count' => count( $modified_files ),
			'by_status'      => $by_status,
			'modified_files' => array_map(
				static function ( object $row ): array {
					return [
						'path'        => $row->file_path,
						'status'      => $row->status,
						'tracked_at'  => $row->tracked_at,
						'modified_at' => $row->modified_at,
					];
				},
				$modified_files
			),
		];
	}

	/**
	 * Register WordPress hooks to auto-snapshot files before FileAbilities modifies them.
	 *
	 * Hooks into the `sd_ai_agent_before_file_write` and
	 * `sd_ai_agent_before_file_edit` actions fired by FileAbilities.
	 */
	public static function register(): void {
		add_action( 'sd_ai_agent_before_file_write', [ self::class, 'on_before_file_write' ] );
		add_action( 'sd_ai_agent_before_file_edit', [ self::class, 'on_before_file_edit' ] );
		add_action( 'sd_ai_agent_after_file_write', [ self::class, 'on_after_file_write' ] );
		add_action( 'sd_ai_agent_after_file_edit', [ self::class, 'on_after_file_edit' ] );
	}

	/**
	 * Hook: snapshot a file before a write operation.
	 *
	 * @param string $absolute_path Absolute path to the file being written.
	 */
	public static function on_before_file_write( string $absolute_path ): void {
		self::snapshot_before_modify( $absolute_path );
	}

	/**
	 * Hook: snapshot a file before an edit operation.
	 *
	 * @param string $absolute_path Absolute path to the file being edited.
	 */
	public static function on_before_file_edit( string $absolute_path ): void {
		self::snapshot_before_modify( $absolute_path );
	}

	/**
	 * Hook: record modification after a write operation.
	 *
	 * @param string $absolute_path Absolute path to the file that was written.
	 */
	public static function on_after_file_write( string $absolute_path ): void {
		self::record_modification( $absolute_path );
	}

	/**
	 * Hook: record modification after an edit operation.
	 *
	 * @param string $absolute_path Absolute path to the file that was edited.
	 */
	public static function on_after_file_edit( string $absolute_path ): void {
		self::record_modification( $absolute_path );
	}

	/**
	 * Clear the in-memory tracker cache.
	 *
	 * Useful in tests or after bulk operations.
	 */
	public static function clear_cache(): void {
		self::$trackers = [];
	}

	// ─── Private helpers ─────────────────────────────────────────────────────

	/**
	 * Resolve the main plugin file for a plugin directory slug.
	 *
	 * Looks for a PHP file in the plugin directory that contains the
	 * "Plugin Name:" header.
	 *
	 * @param string $plugin_dir_slug Plugin directory name (e.g. "akismet").
	 * @return string|WP_Error Plugin file relative to plugins dir (e.g. "akismet/akismet.php"), or WP_Error.
	 */
	public static function resolve_plugin_file( string $plugin_dir_slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		foreach ( array_keys( $all_plugins ) as $plugin_file ) {
			if ( dirname( $plugin_file ) === $plugin_dir_slug ) {
				return $plugin_file;
			}
		}

		return new WP_Error(
			'sd_ai_agent_git_tracker_plugin_file_not_found',
			sprintf(
				/* translators: %s: plugin directory slug */
				__( 'Cannot resolve main plugin file for directory: %s', 'superdav-ai-agent' ),
				$plugin_dir_slug
			)
		);
	}
}
