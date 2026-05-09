<?php

declare(strict_types=1);
/**
 * Plugin Updater — sandboxed live updates for AI-generated plugins.
 *
 * Flow: backup → stage → test → swap → verify → rollback on failure.
 *
 * Backups and staging directories live inside the uploads basedir under
 * `sd-ai-backups/` and `sd-ai-staging/` respectively, so they survive
 * relocated wp-content / plugins directories and follow WordPress's
 * recommended writeable-area convention.
 *
 * @package SdAiAgent\PluginBuilder
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\PluginBuilder;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PluginUpdater — sandboxed updates for running AI-generated plugins.
 *
 * @since 1.5.0
 */
class PluginUpdater {

	/**
	 * Get the absolute path to the backups root directory.
	 *
	 * Uses uploads basedir per WP guidelines for plugin-managed writeable
	 * locations, so it works on installs that relocate WP_CONTENT_DIR.
	 *
	 * @return string Trailing-slashed absolute path.
	 */
	private function backups_root(): string {
		$uploads = wp_upload_dir( null, false );
		$basedir = ! empty( $uploads['basedir'] ) ? (string) $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
		return trailingslashit( $basedir ) . 'sd-ai-backups/';
	}

	/**
	 * Get the absolute path to the staging root directory.
	 *
	 * @return string Trailing-slashed absolute path.
	 */
	private function staging_root(): string {
		$uploads = wp_upload_dir( null, false );
		$basedir = ! empty( $uploads['basedir'] ) ? (string) $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
		return trailingslashit( $basedir ) . 'sd-ai-staging/';
	}

	/**
	 * Get the trailing-slashed absolute path to an installed plugin's directory.
	 *
	 * Uses WP_PLUGIN_DIR per wp.org guidelines for referencing other plugins.
	 *
	 * @param string $slug Plugin slug (already validated by caller).
	 * @return string
	 */
	private function plugin_dir_path( string $slug ): string {
		return trailingslashit( WP_PLUGIN_DIR ) . $slug . '/';
	}

	/**
	 * Backup an installed plugin to wp-content/sd-ai-backups/{slug}-{timestamp}/.
	 *
	 * @param string $slug Plugin slug (directory name under wp-content/plugins/).
	 * @return string|\WP_Error Absolute path to the backup directory on success.
	 */
	public function backup( string $slug ): string|\WP_Error {
		$slug = sanitize_title( $slug );
		if ( empty( $slug ) ) {
			return new WP_Error(
				'sd_ai_agent_invalid_slug',
				__( 'Plugin slug must not be empty.', 'superdav-ai-agent' )
			);
		}

		$plugin_dir = $this->plugin_dir_path( $slug );
		if ( ! is_dir( $plugin_dir ) ) {
			return new WP_Error(
				'sd_ai_agent_plugin_not_found',
				/* translators: %s: plugin directory */
				sprintf( __( 'Plugin directory not found: %s', 'superdav-ai-agent' ), $plugin_dir )
			);
		}

		$timestamp  = gmdate( 'Y-m-d-His' );
		$backup_dir = $this->backups_root() . $slug . '-' . $timestamp . '/';

		$result = $this->copy_directory( $plugin_dir, $backup_dir );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $backup_dir;
	}

	/**
	 * Stage new file content for a plugin.
	 *
	 * Copies the live plugin directory to the staging location first so the
	 * staged copy is always complete, then overlays the provided modified files.
	 *
	 * @param string               $slug           Plugin slug.
	 * @param array<string,string> $modified_files Map of relative path → PHP source.
	 * @return string|\WP_Error Absolute path to the staging directory on success.
	 */
	public function stage( string $slug, array $modified_files ): string|\WP_Error {
		$slug = sanitize_title( $slug );
		if ( empty( $slug ) ) {
			return new WP_Error(
				'sd_ai_agent_invalid_slug',
				__( 'Plugin slug must not be empty.', 'superdav-ai-agent' )
			);
		}

		$plugin_dir = $this->plugin_dir_path( $slug );
		if ( ! is_dir( $plugin_dir ) ) {
			return new WP_Error(
				'sd_ai_agent_plugin_not_found',
				/* translators: %s: plugin directory */
				sprintf( __( 'Plugin directory not found: %s', 'superdav-ai-agent' ), $plugin_dir )
			);
		}

		$staging_dir = $this->staging_root() . $slug . '/';

		// Remove stale staging dir if it exists.
		if ( is_dir( $staging_dir ) ) {
			$this->remove_directory( $staging_dir );
		}

		// Copy live plugin to staging so the staged copy is always complete.
		$result = $this->copy_directory( $plugin_dir, $staging_dir );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Overlay the modified files.
		foreach ( $modified_files as $relative_path => $content ) {
			$relative_path = ltrim( (string) $relative_path, '/\\' );
			$abs_path      = $staging_dir . $relative_path;
			wp_mkdir_p( dirname( $abs_path ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Staging: WP_Filesystem not applicable when writing generated PHP source to a temp location.
			if ( false === file_put_contents( $abs_path, $content ) ) {
				$this->remove_directory( $staging_dir );
				return new WP_Error(
					'sd_ai_agent_staging_write_failed',
					/* translators: %s: relative file path */
					sprintf( __( 'Could not write staging file: %s', 'superdav-ai-agent' ), $relative_path )
				);
			}
		}

		return $staging_dir;
	}

	/**
	 * Run PluginSandbox checks on a staged plugin directory.
	 *
	 * @param string $slug        Plugin slug (used to derive the main plugin file name).
	 * @param string $staging_dir Absolute path to the staging directory.
	 * @return array<string,mixed> Sandbox result with at least `passed` (bool) and `errors` (string[]).
	 */
	public function test_staged( string $slug, string $staging_dir ): array {
		$plugin_file    = $slug . '.php';
		$sandbox_result = PluginSandbox::run_all( $staging_dir, $plugin_file );

		if ( is_wp_error( $sandbox_result ) ) {
			return [
				'passed' => false,
				'errors' => [ $sandbox_result->get_error_message() ],
			];
		}

		return $sandbox_result;
	}

	/**
	 * Swap the staged plugin over the live plugin.
	 *
	 * Deactivates the plugin (if active), copies staging over the live directory,
	 * then reactivates. If reactivation fails, restores from backup automatically.
	 *
	 * @param string $slug        Plugin slug.
	 * @param string $staging_dir Absolute path to the staging directory.
	 * @param string $backup_dir  Absolute path to the backup directory (used for rollback on failure).
	 * @return array<string,mixed>|\WP_Error
	 */
	public function swap( string $slug, string $staging_dir, string $backup_dir ): array|\WP_Error {
		if ( ! is_dir( $staging_dir ) ) {
			return new WP_Error(
				'sd_ai_agent_staging_not_found',
				/* translators: %s: staging directory */
				sprintf( __( 'Staging directory not found: %s', 'superdav-ai-agent' ), $staging_dir )
			);
		}

		$plugin_file = $slug . '/' . $slug . '.php';
		$plugin_dir  = $this->plugin_dir_path( $slug );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$was_active = is_plugin_active( $plugin_file );
		if ( $was_active ) {
			deactivate_plugins( $plugin_file, true );
		}

		// Replace live directory with staged copy.
		if ( is_dir( $plugin_dir ) ) {
			$this->remove_directory( $plugin_dir );
		}

		$copy_result = $this->copy_directory( $staging_dir, $plugin_dir );
		if ( is_wp_error( $copy_result ) ) {
			// Restore backup.
			$this->rollback( $slug, $backup_dir );
			return new WP_Error(
				'sd_ai_agent_swap_copy_failed',
				/* translators: %s: underlying error message */
				sprintf( __( 'Swap failed, backup restored: %s', 'superdav-ai-agent' ), $copy_result->get_error_message() )
			);
		}

		// Reactivate if the plugin was active before the swap.
		if ( $was_active ) {
			$activate_result = activate_plugin( $plugin_file );
			if ( is_wp_error( $activate_result ) ) {
				// Reactivation failed — restore from backup.
				$this->remove_directory( $plugin_dir );
				$this->rollback( $slug, $backup_dir );
				return new WP_Error(
					'sd_ai_agent_reactivation_failed',
					sprintf(
						/* translators: 1: plugin file, 2: underlying error */
						__( 'Reactivation of "%1$s" failed after swap, backup restored: %2$s', 'superdav-ai-agent' ),
						$plugin_file,
						$activate_result->get_error_message()
					)
				);
			}
		}

		return [
			'swapped'     => true,
			'plugin_file' => $plugin_file,
			'was_active'  => $was_active,
		];
	}

	/**
	 * Roll back a plugin to a previously created backup.
	 *
	 * @param string $slug       Plugin slug.
	 * @param string $backup_dir Absolute path to the backup directory.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function rollback( string $slug, string $backup_dir ): array|\WP_Error {
		if ( ! is_dir( $backup_dir ) ) {
			return new WP_Error(
				'sd_ai_agent_backup_not_found',
				/* translators: %s: backup directory */
				sprintf( __( 'Backup directory not found: %s', 'superdav-ai-agent' ), $backup_dir )
			);
		}

		$plugin_dir = $this->plugin_dir_path( sanitize_title( $slug ) );

		if ( is_dir( $plugin_dir ) ) {
			$this->remove_directory( $plugin_dir );
		}

		$result = $this->copy_directory( $backup_dir, $plugin_dir );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'rolled_back' => true,
			'slug'        => $slug,
			'backup_dir'  => $backup_dir,
		];
	}

	/**
	 * Remove old plugin backups beyond the configured retention window.
	 *
	 * Never deletes the most recent backup for any slug regardless of age.
	 * Retention window is configurable via the `sd_ai_agent_backup_retention_days` filter.
	 *
	 * @param int $max_age_days Maximum backup age in days. Default 7.
	 * @return int Number of backup directories removed.
	 */
	public function cleanup_old_backups( int $max_age_days = 7 ): int {
		/**
		 * Filter the number of days to retain plugin backups.
		 *
		 * @param int $max_age_days Default retention in days.
		 */
		$max_age_days = (int) apply_filters( 'sd_ai_agent_backup_retention_days', $max_age_days );
		$backups_root = $this->backups_root();

		if ( ! is_dir( $backups_root ) ) {
			return 0;
		}

		$cutoff  = time() - ( $max_age_days * DAY_IN_SECONDS );
		$removed = 0;

		// Collect all backup directories grouped by slug.
		$entries = glob( $backups_root . '*', GLOB_ONLYDIR );
		if ( false === $entries || empty( $entries ) ) {
			return 0;
		}

		/** @var array<string,list<array{dir:string,timestamp:string}>> $by_slug */
		$by_slug = [];
		foreach ( $entries as $entry ) {
			$basename = basename( $entry );
			// Expected format: {slug}-YYYY-MM-DD-HHiiss
			if ( preg_match( '/^(.+)-(\d{4}-\d{2}-\d{2}-\d{6})$/', $basename, $m ) ) {
				$by_slug[ $m[1] ][] = [
					'dir'       => $entry,
					'timestamp' => $m[2],
				];
			}
		}

		foreach ( $by_slug as $backups ) {
			// Sort descending by timestamp so index 0 is the most recent.
			usort(
				$backups,
				static function ( array $a, array $b ): int {
					return strcmp( $b['timestamp'], $a['timestamp'] );
				}
			);

			foreach ( $backups as $index => $backup ) {
				// Always preserve the most recent backup.
				if ( 0 === $index ) {
					continue;
				}

				$mtime = filemtime( $backup['dir'] );
				if ( false !== $mtime && $mtime < $cutoff ) {
					$this->remove_directory( $backup['dir'] );
					++$removed;
				}
			}
		}

		return $removed;
	}

	/**
	 * Orchestrate the full update flow for an installed plugin.
	 *
	 * Steps: backup → stage → test → swap → cleanup staging.
	 *
	 * @param string               $slug           Plugin slug.
	 * @param array<string,string> $modified_files Map of relative path → PHP source.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function update( string $slug, array $modified_files ): array|\WP_Error {
		// Step 1: Backup.
		$backup_dir = $this->backup( $slug );
		if ( is_wp_error( $backup_dir ) ) {
			return $backup_dir;
		}

		// Step 2: Stage.
		$staging_dir = $this->stage( $slug, $modified_files );
		if ( is_wp_error( $staging_dir ) ) {
			return $staging_dir;
		}

		// Step 3: Test staged copy.
		$test_result = $this->test_staged( $slug, $staging_dir );
		if ( ! $test_result['passed'] ) {
			$this->remove_directory( $staging_dir );
			return new WP_Error(
				'sd_ai_agent_sandbox_failed',
				implode( '; ', $test_result['errors'] )
			);
		}

		// Step 4: Swap.
		$swap_result = $this->swap( $slug, $staging_dir, $backup_dir );
		if ( is_wp_error( $swap_result ) ) {
			$this->remove_directory( $staging_dir );
			return $swap_result;
		}

		// Step 5: Cleanup staging.
		$this->remove_directory( $staging_dir );

		return array_merge(
			$swap_result,
			[ 'backup_dir' => $backup_dir ]
		);
	}

	// ─── Private helpers ──────────────────────────────────────────────────────

	/**
	 * Recursively copy a directory.
	 *
	 * @param string $source      Source directory (must exist).
	 * @param string $destination Destination directory (will be created).
	 * @return true|\WP_Error
	 */
	private function copy_directory( string $source, string $destination ): bool|\WP_Error {
		if ( ! wp_mkdir_p( $destination ) ) {
			return new WP_Error(
				'sd_ai_agent_mkdir_failed',
				/* translators: %s: directory path */
				sprintf( __( 'Could not create directory: %s', 'superdav-ai-agent' ), $destination )
			);
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			/** @var \SplFileInfo $item */
			$real_path = $item->getRealPath();
			if ( false === $real_path ) {
				continue;
			}

			$dest_path = $destination . str_replace( $source, '', $real_path );

			if ( $item->isDir() ) {
				wp_mkdir_p( $dest_path );
			} else {
				copy( $real_path, $dest_path );
			}
		}

		return true;
	}

	/**
	 * Recursively remove a directory using WP_Filesystem_Direct.
	 *
	 * @param string $dir Absolute path to the directory to remove.
	 * @return void
	 */
	private function remove_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		$fs = new \WP_Filesystem_Direct( [] );
		$fs->rmdir( $dir, true );
	}
}
