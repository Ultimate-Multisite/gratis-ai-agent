<?php

declare(strict_types=1);
/**
 * Plugin Installer — writes AI-generated plugin files to wp-content/plugins/
 * and tracks them in the generated_plugins database table.
 *
 * @package SdAiAgent\PluginBuilder
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\PluginBuilder;

use SdAiAgent\Core\Database;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PluginInstaller — installs AI-generated plugins on disk and in the DB.
 *
 * @since 1.5.0
 */
class PluginInstaller {

	/**
	 * Valid slug pattern: lowercase letters, digits, and hyphens only.
	 */
	private const SLUG_PATTERN = '/^[a-z0-9-]+$/';

	/**
	 * Get the generated plugins table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		return Database::generated_plugins_table_name();
	}

	/**
	 * Validate that a relative file path is safe and resides inside the plugin directory.
	 *
	 * Checks:
	 *  - Path is not empty.
	 *  - Path contains no null bytes.
	 *  - Path does not contain traversal sequences (../).
	 *  - Resolved absolute path starts with WP_PLUGIN_DIR/{slug}/.
	 *
	 * @param string $slug          Validated plugin slug.
	 * @param string $relative_path Relative file path (relative to the plugin directory).
	 * @return string|\WP_Error Normalised relative path on success, WP_Error on failure.
	 */
	public static function validate_plugin_path( string $slug, string $relative_path ): string|\WP_Error {
		if ( '' === $relative_path ) {
			return new WP_Error(
				'sd_ai_agent_empty_path',
				__( 'File path must not be empty.', 'superdav-ai-agent' )
			);
		}

		// Reject null bytes.
		if ( str_contains( $relative_path, "\0" ) ) {
			return new WP_Error(
				'sd_ai_agent_invalid_path',
				__( 'File path contains invalid characters.', 'superdav-ai-agent' )
			);
		}

		// Reject explicit traversal sequences.
		if ( str_contains( $relative_path, '../' ) || str_contains( $relative_path, '..' . DIRECTORY_SEPARATOR ) ) {
			return new WP_Error(
				'sd_ai_agent_path_traversal',
				__( 'File path contains directory traversal sequences.', 'superdav-ai-agent' )
			);
		}

		// Normalise: strip leading slashes and backslashes.
		$normalised = ltrim( $relative_path, '/\\' );

		// Strip redundant "{slug}/" prefix so callers need not be consistent about it.
		if ( str_starts_with( $normalised, $slug . '/' ) ) {
			$normalised = substr( $normalised, strlen( $slug ) + 1 );
		}

		if ( '' === $normalised ) {
			return new WP_Error(
				'sd_ai_agent_empty_path',
				__( 'File path resolves to an empty path after normalisation.', 'superdav-ai-agent' )
			);
		}

		// Build the expected plugin directory path for boundary validation.
		// Use WP_PLUGIN_DIR (not WP_CONTENT_DIR.'/plugins/') so this works on
		// installs that relocate the plugins directory.
		$plugin_dir = trailingslashit( WP_PLUGIN_DIR ) . $slug . '/';

		// Ensure the plugin directory exists before using realpath.
		if ( is_dir( $plugin_dir ) ) {
			$real_plugin_dir = realpath( $plugin_dir );
			if ( false !== $real_plugin_dir ) {
				// Resolve what the final path would be (without the file existing yet).
				$candidate     = $real_plugin_dir . '/' . $normalised;
				$dir_candidate = dirname( $candidate );

				// The parent directory must be inside the plugin directory.
				if ( is_dir( $dir_candidate ) ) {
					$real_dir = realpath( $dir_candidate );
					if ( false !== $real_dir && ! str_starts_with( $real_dir . '/', $real_plugin_dir . '/' ) ) {
						return new WP_Error(
							'sd_ai_agent_path_traversal',
							__( 'File path escapes the plugin directory.', 'superdav-ai-agent' )
						);
					}
				}
			}
		}

		return $normalised;
	}

	/**
	 * Install a single-file AI-generated plugin.
	 *
	 * Creates wp-content/plugins/{slug}/{slug}.php and records the plugin in the
	 * generated_plugins table with status='installed'.
	 *
	 * @param string              $slug              Plugin slug (must match [a-z0-9-]).
	 * @param string              $main_file_content Full PHP source of the main plugin file.
	 * @param string              $description       Human-readable plugin description.
	 * @param array<string,mixed> $plan              Implementation plan (stored as JSON).
	 * @return array{id: int, plugin_dir: string, plugin_file: string}|\WP_Error
	 */
	public static function install_plugin(
		string $slug,
		string $main_file_content,
		string $description,
		array $plan
	): array|\WP_Error {
		if ( ! preg_match( self::SLUG_PATTERN, $slug ) ) {
			return new WP_Error(
				'sd_ai_agent_invalid_slug',
				__( 'Plugin slug must contain only lowercase letters, digits, and hyphens.', 'superdav-ai-agent' )
			);
		}

		$main_file = $slug . '.php';
		$files     = [ $main_file => $main_file_content ];

		return self::install(
			$slug,
			$files,
			$description,
			wp_json_encode( $plan ) ?: '',
			$slug . '/' . $main_file
		);
	}

	/**
	 * Install a multi-file AI-generated plugin.
	 *
	 * Creates the full directory structure under wp-content/plugins/{slug}/ and
	 * records the plugin in the generated_plugins table with status='installed'.
	 * Path traversal protection is applied to every file path.
	 * Redundant "{slug}/" prefixes in file paths are normalised automatically.
	 *
	 * @param string               $slug        Plugin slug (must match [a-z0-9-]).
	 * @param array<string,string> $files       Map of relative path → PHP source.
	 * @param string               $description Human-readable plugin description.
	 * @param array<string,mixed>  $plan        Implementation plan (stored as JSON).
	 * @return array{id: int, plugin_dir: string, plugin_file: string}|\WP_Error
	 */
	public static function install_complex_plugin(
		string $slug,
		array $files,
		string $description,
		array $plan
	): array|\WP_Error {
		if ( ! preg_match( self::SLUG_PATTERN, $slug ) ) {
			return new WP_Error(
				'sd_ai_agent_invalid_slug',
				__( 'Plugin slug must contain only lowercase letters, digits, and hyphens.', 'superdav-ai-agent' )
			);
		}

		if ( empty( $files ) ) {
			return new WP_Error(
				'sd_ai_agent_no_files',
				__( 'No plugin files provided for installation.', 'superdav-ai-agent' )
			);
		}

		// Validate all paths before touching the filesystem.
		$normalised_files = [];
		foreach ( $files as $relative_path => $content ) {
			$validated = self::validate_plugin_path( $slug, $relative_path );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
			$normalised_files[ $validated ] = $content;
		}

		// Derive the main plugin file: prefer "{slug}.php", otherwise first file.
		$plugin_file_relative = $slug . '.php';
		if ( ! isset( $normalised_files[ $plugin_file_relative ] ) ) {
			$plugin_file_relative = array_key_first( $normalised_files );
		}

		return self::install(
			$slug,
			$normalised_files,
			$description,
			wp_json_encode( $plan ) ?: '',
			$slug . '/' . $plugin_file_relative
		);
	}

	/**
	 * Update specific files in an existing AI-generated plugin.
	 *
	 * Writes new content for the given files and updates the 'files' column and
	 * updated_at timestamp in the database record. Path traversal protection is
	 * applied to every file path.
	 *
	 * @param string               $slug  Plugin slug.
	 * @param array<string,string> $files Map of relative path → new PHP source.
	 * @return array{updated: string[]}|\WP_Error
	 */
	public static function update_plugin_files( string $slug, array $files ): array|\WP_Error {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$slug = sanitize_title( $slug );
		if ( empty( $slug ) ) {
			return new WP_Error(
				'sd_ai_agent_invalid_slug',
				__( 'Plugin slug must not be empty.', 'superdav-ai-agent' )
			);
		}

		if ( empty( $files ) ) {
			return new WP_Error(
				'sd_ai_agent_no_files',
				__( 'No files provided for update.', 'superdav-ai-agent' )
			);
		}

		$plugin_dir = trailingslashit( WP_PLUGIN_DIR ) . $slug . '/';
		if ( ! is_dir( $plugin_dir ) ) {
			return new WP_Error(
				'sd_ai_agent_plugin_not_found',
				/* translators: %s: plugin slug */
				sprintf( __( 'Plugin directory not found for slug: %s', 'superdav-ai-agent' ), $slug )
			);
		}

		// Validate all paths before touching the filesystem.
		$normalised_files = [];
		foreach ( $files as $relative_path => $content ) {
			$validated = self::validate_plugin_path( $slug, $relative_path );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
			$normalised_files[ $validated ] = $content;
		}

		// Write files using WP_Filesystem.
		global $wp_filesystem;
		/** @var \WP_Filesystem_Base $wp_filesystem */
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$updated = [];
		foreach ( $normalised_files as $relative_path => $content ) {
			$abs_path = $plugin_dir . $relative_path;
			wp_mkdir_p( dirname( $abs_path ) );

			if ( ! $wp_filesystem->put_contents( $abs_path, $content, FS_CHMOD_FILE ) ) {
				return new WP_Error(
					'sd_ai_agent_write_failed',
					/* translators: %s: file path */
					sprintf( __( 'Could not write file: %s', 'superdav-ai-agent' ), $relative_path )
				);
			}

			$updated[] = $relative_path;
		}

		// Refresh the files list and updated_at in the DB record.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Update generated plugin record.
		$wpdb->update(
			self::table_name(),
			[
				'files'      => wp_json_encode( $updated ),
				'updated_at' => current_time( 'mysql' ),
			],
			[ 'slug' => $slug ],
			[ '%s', '%s' ],
			[ '%s' ]
		);

		return [ 'updated' => $updated ];
	}

	/**
	 * Delete an AI-generated plugin by slug.
	 *
	 * Deactivates the plugin if it is currently active, removes its directory
	 * from disk, and deletes the database record. Only works on plugins that
	 * have a record in sd_ai_agent_generated_plugins (i.e. AI-generated).
	 *
	 * @param string $slug Plugin slug.
	 * @return array{deleted: bool, deactivated: bool}|\WP_Error
	 */
	public static function delete_generated_plugin( string $slug ): array|\WP_Error {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$slug = sanitize_title( $slug );
		if ( empty( $slug ) ) {
			return new WP_Error(
				'sd_ai_agent_invalid_slug',
				__( 'Plugin slug must not be empty.', 'superdav-ai-agent' )
			);
		}

		// Only delete plugins that are tracked in our database.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table from trusted internal method.
		$record = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name comes from internal method, not user input.
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE slug = %s LIMIT 1', $slug ),
			ARRAY_A
		);

		if ( null === $record ) {
			return new WP_Error(
				'sd_ai_agent_plugin_not_found',
				/* translators: %s: plugin slug */
				sprintf( __( 'No generated plugin record found for slug: %s', 'superdav-ai-agent' ), $slug )
			);
		}

		// Deactivate if currently active.
		$deactivated = false;
		if ( ! empty( $record['plugin_file'] ) ) {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugin_file_str = (string) $record['plugin_file'];
			if ( is_plugin_active( $plugin_file_str ) ) {
				deactivate_plugins( $plugin_file_str, true );
				$deactivated = true;
			}
		}

		// Remove directory from disk.
		$plugin_dir = trailingslashit( WP_PLUGIN_DIR ) . $slug . '/';
		if ( is_dir( $plugin_dir ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			$fs = new \WP_Filesystem_Direct( [] );
			$fs->rmdir( $plugin_dir, true );
		}

		// Remove DB record.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal plugin deletion; caching not applicable.
		$wpdb->delete(
			self::table_name(),
			[ 'slug' => $slug ],
			[ '%s' ]
		);

		return [
			'deleted'     => true,
			'deactivated' => $deactivated,
		];
	}

	/**
	 * Install a generated plugin to disk and record it in the database.
	 *
	 * @param string               $slug        Plugin slug (directory name).
	 * @param array<string,string> $files       Map of relative path → PHP source.
	 * @param string               $description Human-readable plugin description.
	 * @param string               $plan        Implementation plan JSON or text.
	 * @param string               $plugin_file Main plugin file relative to plugins dir (e.g. "slug/slug.php").
	 * @return array{id: int, plugin_dir: string, plugin_file: string}|\WP_Error
	 */
	public static function install(
		string $slug,
		array $files,
		string $description,
		string $plan,
		string $plugin_file
	): array|\WP_Error {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$slug = sanitize_title( $slug );
		if ( empty( $slug ) ) {
			return new WP_Error(
				'sd_ai_agent_invalid_slug',
				__( 'Plugin slug must not be empty.', 'superdav-ai-agent' )
			);
		}

		if ( empty( $files ) ) {
			return new WP_Error(
				'sd_ai_agent_no_files',
				__( 'No plugin files provided for installation.', 'superdav-ai-agent' )
			);
		}

		$plugins_dir = trailingslashit( WP_PLUGIN_DIR );
		$plugin_dir  = $plugins_dir . $slug . '/';

		// Validate paths to prevent directory traversal.
		$canonical_plugins = realpath( $plugins_dir );
		if ( false === $canonical_plugins ) {
			return new WP_Error(
				'sd_ai_agent_plugins_dir_missing',
				__( 'WordPress plugins directory not found.', 'superdav-ai-agent' )
			);
		}

		// Create plugin directory.
		if ( ! wp_mkdir_p( $plugin_dir ) ) {
			return new WP_Error(
				'sd_ai_agent_mkdir_failed',
				/* translators: %s: directory path */
				sprintf( __( 'Could not create plugin directory: %s', 'superdav-ai-agent' ), $plugin_dir )
			);
		}

		// Write each file.
		$written = [];
		foreach ( $files as $relative_path => $content ) {
			// Sanitize path to prevent traversal.
			$relative_path = ltrim( $relative_path, '/\\' );
			$abs_path      = realpath( $plugin_dir ) . '/' . $relative_path;

			// Ensure destination is inside plugin dir.
			$dest_real = dirname( $abs_path );
			if ( false === wp_mkdir_p( $dest_real ) ) {
				return new WP_Error(
					'sd_ai_agent_mkdir_failed',
					/* translators: %s: directory path */
					sprintf( __( 'Could not create directory: %s', 'superdav-ai-agent' ), $dest_real )
				);
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Plugin installation writes local files; WP_Filesystem not available at this stage.
			$result = file_put_contents( $abs_path, $content );
			if ( false === $result ) {
				return new WP_Error(
					'sd_ai_agent_write_failed',
					/* translators: %s: file path */
					sprintf( __( 'Could not write file: %s', 'superdav-ai-agent' ), $relative_path )
				);
			}

			$written[] = $relative_path;
		}

		// Determine the plugin file path relative to the plugins directory.
		if ( empty( $plugin_file ) ) {
			$plugin_file = $slug . '/' . $slug . '.php';
		}

		// Record in database.
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal plugin installation; caching not applicable.
		$insert = $wpdb->insert(
			self::table_name(),
			[
				'slug'             => $slug,
				'description'      => $description,
				'plan'             => $plan,
				'plugin_file'      => $plugin_file,
				'files'            => wp_json_encode( $written ),
				'status'           => 'installed',
				'sandbox_result'   => wp_json_encode( [] ),
				'activation_error' => '',
				'created_at'       => $now,
				'updated_at'       => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $insert ) {
			return new WP_Error(
				'sd_ai_agent_db_insert_failed',
				/* translators: %s: database error */
				sprintf( __( 'Database insert failed: %s', 'superdav-ai-agent' ), $wpdb->last_error )
			);
		}

		$id = (int) $wpdb->insert_id;

		return [
			'id'          => $id,
			'plugin_dir'  => $plugin_dir,
			'plugin_file' => $plugin_file,
		];
	}

	/**
	 * Update the status and sandbox result for a generated plugin record.
	 *
	 * @param int                 $id             Record ID.
	 * @param string              $status         New status (installed, sandbox_passed, active, error).
	 * @param array<string,mixed> $sandbox_result Sandbox test result array.
	 * @param string              $activation_error Error message if activation failed.
	 * @return bool
	 */
	public static function update_status(
		int $id,
		string $status,
		array $sandbox_result = [],
		string $activation_error = ''
	): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal plugin status update; caching not applicable.
		$updated = $wpdb->update(
			self::table_name(),
			[
				'status'           => $status,
				'sandbox_result'   => wp_json_encode( $sandbox_result ),
				'activation_error' => $activation_error,
				'updated_at'       => current_time( 'mysql' ),
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		return false !== $updated;
	}

	/**
	 * Get a generated plugin record by ID.
	 *
	 * @param int $id Record ID.
	 * @return array<string,mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Admin lookup; table name is a trusted internal constant, not user input.
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name comes from a trusted internal method, not user input.
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $id ),
			ARRAY_A
		);

		// get_row( ARRAY_A ) returns associative array with string keys; cast is safe.
		/** @var array<string, mixed>|null $row */
		return $row ?? null;
	}

	/**
	 * List generated plugin records, newest first.
	 *
	 * @param int $limit Maximum records to return.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list( int $limit = 20 ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin listing.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name comes from a trusted internal method, not user input.
				'SELECT * FROM ' . self::table_name() . ' ORDER BY created_at DESC LIMIT %d',
				$limit
			),
			ARRAY_A
		);

		// get_results( ARRAY_A ) returns rows with string keys; cast is safe.
		/** @var array<int,array<string,mixed>> $rows */
		return $rows ?: [];
	}

	/**
	 * Remove a generated plugin record and optionally delete its files from disk.
	 *
	 * @param int  $id          Record ID.
	 * @param bool $delete_files Whether to delete the plugin directory from disk.
	 * @return bool
	 */
	public static function delete( int $id, bool $delete_files = false ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$record = self::get( $id );
		if ( null === $record ) {
			return false;
		}

		if ( $delete_files && ! empty( $record['slug'] ) ) {
			$plugin_dir = trailingslashit( WP_PLUGIN_DIR ) . sanitize_title( $record['slug'] ) . '/';
			if ( is_dir( $plugin_dir ) ) {
				// Use WP Filesystem for safe deletion.
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
				$fs = new \WP_Filesystem_Direct( [] );
				$fs->rmdir( $plugin_dir, true );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal plugin deletion; caching not applicable.
		$deleted = $wpdb->delete(
			self::table_name(),
			[ 'id' => $id ],
			[ '%d' ]
		);

		return false !== $deleted;
	}
}
