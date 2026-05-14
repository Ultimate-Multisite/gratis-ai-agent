<?php

declare(strict_types=1);
/**
 * Site Health abilities for the AI agent.
 *
 * Provides tools for daily automated health checks:
 *  - Plugin update availability
 *  - PHP error log scanning
 *  - Disk space usage
 *  - Security checks (file permissions, admin users, debug mode)
 *  - Performance indicators (autoloaded options, transients, object cache)
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SiteHealthAbilities {

	/**
	 * Register all site health abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::register_check_plugin_updates();
		self::register_scan_php_error_log();
		self::register_check_disk_space();
		self::register_check_security();
		self::register_check_performance();
		self::register_site_health_summary();
	}

	// -------------------------------------------------------------------------
	// Registration helpers
	// -------------------------------------------------------------------------

	/**
	 * Register the check-plugin-updates ability.
	 */
	private static function register_check_plugin_updates(): void {
		wp_register_ability(
			'sd-ai-agent/check-plugin-updates',
			[
				'label'               => __( 'Check Plugin Updates', 'superdav-ai-agent' ),
				'description'         => __( 'List all installed plugins that have updates available, including current and new version numbers.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'force_refresh' => [
							'type'        => 'boolean',
							'description' => 'Force a fresh update check from WordPress.org (default: false, uses cached data).',
							'default'     => false,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'updates_available' => [ 'type' => 'integer' ],
						'plugins'           => [ 'type' => 'array' ],
						'checked_at'        => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'   => true,
						'idempotent' => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_check_plugin_updates' ],
				'permission_callback' => function () {
					return ToolCapabilities::current_user_can( 'sd-ai-agent/check-plugin-updates' );
				},
			]
		);
	}

	/**
	 * Register the scan-php-error-log ability.
	 */
	private static function register_scan_php_error_log(): void {
		wp_register_ability(
			'sd-ai-agent/scan-php-error-log',
			[
				'label'               => __( 'Scan PHP Error Log', 'superdav-ai-agent' ),
				'description'         => __( 'Read the PHP/WordPress debug log and return recent errors, warnings, and notices. Returns the most recent entries up to the specified limit.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'limit'       => [
							'type'        => 'integer',
							'description' => 'Maximum number of log entries to return (default: 50, max: 200).',
							'default'     => 50,
						],
						'level'       => [
							'type'        => 'string',
							'enum'        => [ 'all', 'error', 'warning', 'notice' ],
							'description' => 'Filter by severity level (default: all).',
							'default'     => 'all',
						],
						'since_hours' => [
							'type'        => 'integer',
							'description' => 'Only return entries from the last N hours (default: 24).',
							'default'     => 24,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'log_path'          => [ 'type' => 'string' ],
						'log_size_kb'       => [ 'type' => 'number' ],
						'entries'           => [ 'type' => 'array' ],
						'total_found'       => [ 'type' => 'integer' ],
						'debug_log_enabled' => [ 'type' => 'boolean' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'   => true,
						'idempotent' => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_scan_php_error_log' ],
				'permission_callback' => function () {
					return ToolCapabilities::current_user_can( 'sd-ai-agent/scan-php-error-log' );
				},
			]
		);
	}

	/**
	 * Register the check-disk-space ability.
	 */
	private static function register_check_disk_space(): void {
		wp_register_ability(
			'sd-ai-agent/check-disk-space',
			[
				'label'               => __( 'Check Disk Space', 'superdav-ai-agent' ),
				'description'         => __( 'Report disk space usage for the WordPress installation: total, used, free space, and wp-content directory size.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => (object) [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'disk_total_gb'      => [ 'type' => 'number' ],
						'disk_free_gb'       => [ 'type' => 'number' ],
						'disk_used_gb'       => [ 'type' => 'number' ],
						'disk_used_percent'  => [ 'type' => 'number' ],
						'wp_content_size_mb' => [ 'type' => 'number' ],
						'uploads_size_mb'    => [ 'type' => 'number' ],
						'status'             => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'   => true,
						'idempotent' => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_check_disk_space' ],
				'permission_callback' => function () {
					return ToolCapabilities::current_user_can( 'sd-ai-agent/check-disk-space' );
				},
			]
		);
	}

	/**
	 * Register the check-security ability.
	 */
	private static function register_check_security(): void {
		wp_register_ability(
			'sd-ai-agent/check-security',
			[
				'label'               => __( 'Check Security', 'superdav-ai-agent' ),
				'description'         => __( 'Run security checks: debug mode status, admin user enumeration risk, file editor status, inactive plugins, and WordPress version currency.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => (object) [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'issues'   => [ 'type' => 'array' ],
						'warnings' => [ 'type' => 'array' ],
						'passed'   => [ 'type' => 'array' ],
						'score'    => [ 'type' => 'integer' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'   => true,
						'idempotent' => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_check_security' ],
				'permission_callback' => function () {
					return ToolCapabilities::current_user_can( 'sd-ai-agent/check-security' );
				},
			]
		);
	}

	/**
	 * Register the check-performance ability.
	 */
	private static function register_check_performance(): void {
		wp_register_ability(
			'sd-ai-agent/check-performance',
			[
				'label'               => __( 'Check Performance', 'superdav-ai-agent' ),
				'description'         => __( 'Check performance indicators: autoloaded options size, expired transients count, object cache status, and post revision count.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => (object) [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'autoloaded_size_kb'   => [ 'type' => 'number' ],
						'autoloaded_count'     => [ 'type' => 'integer' ],
						'expired_transients'   => [ 'type' => 'integer' ],
						'total_transients'     => [ 'type' => 'integer' ],
						'post_revisions'       => [ 'type' => 'integer' ],
						'object_cache_enabled' => [ 'type' => 'boolean' ],
						'recommendations'      => [ 'type' => 'array' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'   => true,
						'idempotent' => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_check_performance' ],
				'permission_callback' => function () {
					return ToolCapabilities::current_user_can( 'sd-ai-agent/check-performance' );
				},
			]
		);
	}

	/**
	 * Register the site-health-summary ability.
	 */
	private static function register_site_health_summary(): void {
		wp_register_ability(
			'sd-ai-agent/site-health-summary',
			[
				'label'               => __( 'Site Health Summary', 'superdav-ai-agent' ),
				'description'         => __( 'Run all site health checks (plugins, errors, disk, security, performance) in one call and return a consolidated summary report.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'force_refresh' => [
							'type'        => 'boolean',
							'description' => 'Force a fresh plugin update check (default: false).',
							'default'     => false,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'overall_status' => [ 'type' => 'string' ],
						'plugin_updates' => [ 'type' => 'object' ],
						'error_log'      => [ 'type' => 'object' ],
						'disk_space'     => [ 'type' => 'object' ],
						'security'       => [ 'type' => 'object' ],
						'performance'    => [ 'type' => 'object' ],
						'generated_at'   => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'   => true,
						'idempotent' => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_site_health_summary' ],
				'permission_callback' => function () {
					return ToolCapabilities::current_user_can( 'sd-ai-agent/site-health-summary' );
				},
			]
		);
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	/**
	 * Handle the check-plugin-updates ability.
	 *
	 * @param array<string, mixed> $input Input parameters.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_check_plugin_updates( array $input ): array|WP_Error {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$force_refresh = (bool) ( $input['force_refresh'] ?? false );

		if ( $force_refresh ) {
			wp_update_plugins();
		}

		$update_data = get_site_transient( 'update_plugins' );
		$plugins     = [];

		// @phpstan-ignore-next-line
		if ( $update_data && ! empty( $update_data->response ) ) {
			$all_plugins = get_plugins();

			foreach ( $update_data->response as $plugin_file => $update_info ) {
				$plugin_data = $all_plugins[ $plugin_file ] ?? [];
				$plugins[]   = [
					'file'            => $plugin_file,
					'name'            => $plugin_data['Name'] ?? $plugin_file,
					'current_version' => $plugin_data['Version'] ?? 'unknown',
					'new_version'     => $update_info->new_version ?? 'unknown',
					'slug'            => $update_info->slug ?? '',
					'url'             => $update_info->url ?? '',
				];
			}
		}

		$checked_at = '';
		// @phpstan-ignore-next-line
		if ( $update_data && ! empty( $update_data->last_checked ) ) {
			$checked_at = gmdate( 'Y-m-d H:i:s', $update_data->last_checked );
		}

		return [
			'updates_available' => count( $plugins ),
			'plugins'           => $plugins,
			'checked_at'        => $checked_at,
		];
	}

	/**
	 * Handle the scan-php-error-log ability.
	 *
	 * @param array<string, mixed> $input Input parameters.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_scan_php_error_log( array $input ): array|WP_Error {
		// @phpstan-ignore-next-line
		$limit = min( 200, max( 1, (int) ( $input['limit'] ?? 50 ) ) );
		$level = $input['level'] ?? 'all';
		// @phpstan-ignore-next-line
		$since_hours = max( 1, (int) ( $input['since_hours'] ?? 24 ) );

		$debug_log_enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;

		// Resolve log path.
		$log_path = self::resolve_error_log_path();

		if ( ! $log_path || ! file_exists( $log_path ) ) {
			return [
				'log_path'          => $log_path ?: 'not configured',
				'log_size_kb'       => 0,
				'entries'           => [],
				'total_found'       => 0,
				'debug_log_enabled' => $debug_log_enabled,
				'message'           => 'No error log file found. Enable WP_DEBUG_LOG in wp-config.php to start logging.',
			];
		}

		$log_size_kb = round( filesize( $log_path ) / 1024, 2 );

		// Read the last portion of the log (avoid loading huge files into memory).
		$max_read_bytes = 512 * 1024; // 512 KB
		$file_size      = filesize( $log_path );
		$offset         = max( 0, $file_size - $max_read_bytes );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem does not support fseek/streaming reads needed for large log files.
		$handle = fopen( $log_path, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'log_read_error', __( 'Could not open error log file.', 'superdav-ai-agent' ) );
		}

		if ( $offset > 0 ) {
			fseek( $handle, $offset );
			// Skip partial first line.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets -- Streaming read; WP_Filesystem does not support this pattern.
			fgets( $handle );
		}

		$raw_lines = [];
		while ( ! feof( $handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets -- Streaming read; WP_Filesystem does not support this pattern.
			$line = fgets( $handle );
			if ( false !== $line ) {
				$raw_lines[] = rtrim( $line );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with fopen above.
		fclose( $handle );

		// Parse and filter entries.
		$since_timestamp = time() - ( $since_hours * HOUR_IN_SECONDS );
		// @phpstan-ignore-next-line
		$entries = self::parse_log_entries( $raw_lines, $level, $since_timestamp, $limit );

		return [
			'log_path'          => $log_path,
			'log_size_kb'       => $log_size_kb,
			'entries'           => $entries,
			'total_found'       => count( $entries ),
			'debug_log_enabled' => $debug_log_enabled,
		];
	}

	/**
	 * Handle the check-disk-space ability.
	 *
	 * @param array<string, mixed> $input Input parameters.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_check_disk_space( array $input ): array|WP_Error {
		$abspath = defined( 'ABSPATH' ) ? ABSPATH : '/';

		$disk_total = disk_total_space( $abspath );
		$disk_free  = disk_free_space( $abspath );

		if ( false === $disk_total || false === $disk_free ) {
			return new WP_Error( 'disk_check_failed', __( 'Could not retrieve disk space information.', 'superdav-ai-agent' ) );
		}

		$disk_used         = $disk_total - $disk_free;
		$disk_used_percent = $disk_total > 0 ? round( ( $disk_used / $disk_total ) * 100, 1 ) : 0;

		$wp_content_size_mb = self::get_directory_size_mb( WP_CONTENT_DIR );
		$uploads_dir        = wp_upload_dir();
		$uploads_size_mb    = self::get_directory_size_mb( $uploads_dir['basedir'] );

		// Determine status based on absolute free space, not percentage.
		// Percentage thresholds are misleading on large disks — 10% free on a
		// 1TB disk is 100GB, while 10% free on a 10GB disk is only 1GB.
		// Use absolute thresholds: < 2GB free = critical, < 5GB free = warning.
		$disk_free_gb = $disk_free / ( 1024 ** 3 );
		$status       = 'ok';
		if ( $disk_free_gb < 2.0 ) {
			$status = 'critical';
		} elseif ( $disk_free_gb < 5.0 ) {
			$status = 'warning';
		}

		return [
			'disk_total_gb'      => round( $disk_total / ( 1024 ** 3 ), 2 ),
			'disk_free_gb'       => round( $disk_free / ( 1024 ** 3 ), 2 ),
			'disk_used_gb'       => round( $disk_used / ( 1024 ** 3 ), 2 ),
			'disk_used_percent'  => $disk_used_percent,
			'wp_content_size_mb' => $wp_content_size_mb,
			'uploads_size_mb'    => $uploads_size_mb,
			'status'             => $status,
		];
	}

	/**
	 * Handle the check-security ability.
	 *
	 * @param array<string, mixed> $input Input parameters.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_check_security( array $input ): array|WP_Error {
		$issues   = [];
		$warnings = [];
		$passed   = [];

		// 1. Debug mode check.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			if ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) {
				$issues[] = 'WP_DEBUG_DISPLAY is enabled — errors are visible to site visitors.';
			} else {
				$warnings[] = 'WP_DEBUG is enabled. Disable on production sites.';
			}
		} else {
			$passed[] = 'WP_DEBUG is disabled.';
		}

		// 2. File editor check.
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			$passed[] = 'File editor is disabled (DISALLOW_FILE_EDIT).';
		} else {
			$warnings[] = 'File editor is enabled. Consider setting DISALLOW_FILE_EDIT = true in wp-config.php.';
		}

		// 3. WordPress version check.
		global $wp_version;
		$core_updates = get_site_transient( 'update_core' );
		// @phpstan-ignore-next-line
		if ( $core_updates && ! empty( $core_updates->updates ) ) {
			foreach ( $core_updates->updates as $update ) {
				if ( 'upgrade' === $update->response ) {
					$issues[] = sprintf(
						'WordPress core update available: %s → %s.',
						$wp_version,
						$update->version
					);
					break;
				}
			}
		} else {
			$passed[] = sprintf( 'WordPress %s is up to date.', $wp_version );
		}

		// 4. Admin username check.
		$admin_user = get_user_by( 'login', 'admin' );
		if ( $admin_user ) {
			$warnings[] = 'A user with the username "admin" exists. This is a common brute-force target — consider renaming it.';
		} else {
			$passed[] = 'No user with the default "admin" username found.';
		}

		// 5. Inactive plugins check.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins    = get_plugins();
		$active_plugins = (array) get_option( 'active_plugins', [] );
		$inactive_count = count( $all_plugins ) - count( $active_plugins );

		if ( $inactive_count > 5 ) {
			$warnings[] = sprintf(
				'%d inactive plugins found. Unused plugins should be removed to reduce attack surface.',
				$inactive_count
			);
		} else {
			$passed[] = sprintf( '%d inactive plugins (acceptable).', $inactive_count );
		}

		// 6. SSL check.
		// @phpstan-ignore-next-line
		if ( is_ssl() || str_starts_with( get_option( 'siteurl' ), 'https://' ) ) {
			$passed[] = 'Site is served over HTTPS.';
		} else {
			$issues[] = 'Site is not using HTTPS. SSL/TLS is required for security.';
		}

		// 7. wp-config.php location check (above webroot is more secure).
		$wp_config_above = file_exists( dirname( ABSPATH ) . '/wp-config.php' );
		if ( $wp_config_above ) {
			$passed[] = 'wp-config.php is located above the webroot.';
		} else {
			$warnings[] = 'wp-config.php is inside the webroot. Moving it one level up improves security.';
		}

		// Score: start at 100, deduct for issues/warnings.
		$score = 100 - ( count( $issues ) * 20 ) - ( count( $warnings ) * 5 );
		$score = max( 0, $score );

		return [
			'issues'   => $issues,
			'warnings' => $warnings,
			'passed'   => $passed,
			'score'    => $score,
		];
	}

	/**
	 * Handle the check-performance ability.
	 *
	 * @param array<string, mixed> $input Input parameters.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_check_performance( array $input ): array|WP_Error {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$recommendations = [];

		// 1. Autoloaded options size.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time health check query; caching not appropriate.
		$autoload_data = $wpdb->get_results(
			"SELECT SUM(LENGTH(option_value)) as total_size, COUNT(*) as total_count
			 FROM {$wpdb->options}
			 WHERE autoload IN ('yes', 'on', '1', 'true')",
			ARRAY_A
		);

		$autoloaded_size_bytes = (int) ( $autoload_data[0]['total_size'] ?? 0 );
		$autoloaded_count      = (int) ( $autoload_data[0]['total_count'] ?? 0 );
		$autoloaded_size_kb    = round( $autoloaded_size_bytes / 1024, 2 );

		if ( $autoloaded_size_kb > 1024 ) {
			$recommendations[] = sprintf(
				'Autoloaded options are %.1f KB — above the 1 MB threshold. Review and remove unnecessary autoloaded options.',
				$autoloaded_size_kb
			);
		}

		// 2. Transients.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time health check query.
		$transient_data = $wpdb->get_results(
			"SELECT
				COUNT(*) as total,
				SUM(CASE WHEN option_name LIKE '_transient_timeout_%' AND CAST(option_value AS UNSIGNED) < UNIX_TIMESTAMP() THEN 1 ELSE 0 END) as expired
			 FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_%'",
			ARRAY_A
		);

		$total_transients   = (int) ( $transient_data[0]['total'] ?? 0 );
		$expired_transients = (int) ( $transient_data[0]['expired'] ?? 0 );

		if ( $expired_transients > 100 ) {
			$recommendations[] = sprintf(
				'%d expired transients found. Run DELETE FROM %s WHERE option_name LIKE \'_transient_timeout_%%\' AND CAST(option_value AS UNSIGNED) < UNIX_TIMESTAMP() to clean up.',
				$expired_transients,
				$wpdb->options
			);
		}

		// 3. Post revisions.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time health check query.
		$revision_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"
		);

		if ( $revision_count > 1000 ) {
			$recommendations[] = sprintf(
				'%d post revisions found. Consider limiting revisions via WP_POST_REVISIONS in wp-config.php.',
				$revision_count
			);
		}

		// 4. Object cache.
		$object_cache_enabled = (bool) wp_using_ext_object_cache();

		if ( ! $object_cache_enabled ) {
			$recommendations[] = 'No persistent object cache detected. Consider installing Redis or Memcached for improved performance.';
		}

		return [
			'autoloaded_size_kb'   => $autoloaded_size_kb,
			'autoloaded_count'     => $autoloaded_count,
			'expired_transients'   => $expired_transients,
			'total_transients'     => $total_transients,
			'post_revisions'       => $revision_count,
			'object_cache_enabled' => $object_cache_enabled,
			'recommendations'      => $recommendations,
		];
	}

	/**
	 * Handle the site-health-summary ability.
	 *
	 * Runs all checks and returns a consolidated report.
	 *
	 * @param array<string, mixed> $input Input parameters.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_site_health_summary( array $input ): array|WP_Error {
		$force_refresh = (bool) ( $input['force_refresh'] ?? false );

		$plugin_updates = self::handle_check_plugin_updates( [ 'force_refresh' => $force_refresh ] );
		$error_log      = self::handle_scan_php_error_log(
			[
				'limit'       => 20,
				'level'       => 'error',
				'since_hours' => 24,
			]
		);
		$disk_space     = self::handle_check_disk_space( [] );
		$security       = self::handle_check_security( [] );
		$performance    = self::handle_check_performance( [] );

		// Determine overall status.
		$overall_status = 'healthy';

		if ( ! is_wp_error( $security ) && ! empty( $security['issues'] ) ) {
			$overall_status = 'critical';
		} elseif ( ! is_wp_error( $disk_space ) && 'critical' === ( $disk_space['status'] ?? '' ) ) {
			$overall_status = 'critical';
		} elseif (
			( ! is_wp_error( $security ) && ! empty( $security['warnings'] ) ) ||
			( ! is_wp_error( $disk_space ) && 'warning' === ( $disk_space['status'] ?? '' ) ) ||
			( ! is_wp_error( $plugin_updates ) && ( $plugin_updates['updates_available'] ?? 0 ) > 0 ) ||
			( ! is_wp_error( $performance ) && ! empty( $performance['recommendations'] ) )
		) {
			$overall_status = 'needs_attention';
		}

		return [
			'overall_status' => $overall_status,
			'plugin_updates' => is_wp_error( $plugin_updates ) ? [ 'error' => $plugin_updates->get_error_message() ] : $plugin_updates,
			'error_log'      => is_wp_error( $error_log ) ? [ 'error' => $error_log->get_error_message() ] : $error_log,
			'disk_space'     => is_wp_error( $disk_space ) ? [ 'error' => $disk_space->get_error_message() ] : $disk_space,
			'security'       => is_wp_error( $security ) ? [ 'error' => $security->get_error_message() ] : $security,
			'performance'    => is_wp_error( $performance ) ? [ 'error' => $performance->get_error_message() ] : $performance,
			'generated_at'   => gmdate( 'Y-m-d H:i:s' ),
		];
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve the PHP/WordPress error log path.
	 *
	 * @return string|null Absolute path to the log file, or null if not found.
	 */
	private static function resolve_error_log_path(): ?string {
		// 1. WP_DEBUG_LOG as a path string.
		// WP_DEBUG_LOG may be bool or a file-path string at runtime (since WP 5.1).
		// The phpstan-wordpress bootstrap stubs it as `true` (literal bool), so PHPStan
		// cannot see the string case. We use constant() + a @var assertion to expose
		// the real runtime union type without suppressing the check globally.
		/** @var bool|string $debug_log_value */
		$debug_log_value = defined( 'WP_DEBUG_LOG' ) ? constant( 'WP_DEBUG_LOG' ) : false;
		if ( is_string( $debug_log_value ) && '' !== $debug_log_value ) {
			$path = $debug_log_value;
			if ( ! path_is_absolute( $path ) ) {
				$path = ABSPATH . $path;
			}
			if ( file_exists( $path ) ) {
				return $path;
			}
		}

		// 2. Default WordPress debug.log location.
		$default = WP_CONTENT_DIR . '/debug.log';
		if ( file_exists( $default ) ) {
			return $default;
		}

		// 3. PHP error_log ini setting.
		$php_log = ini_get( 'error_log' );
		if ( $php_log && file_exists( $php_log ) ) {
			return $php_log;
		}

		return null;
	}

	/**
	 * Parse raw log lines into structured entries.
	 *
	 * @param string[] $lines          Raw log lines.
	 * @param string   $level_filter   Level filter: 'all', 'error', 'warning', 'notice'.
	 * @param int      $since_timestamp Unix timestamp — only include entries after this time.
	 * @param int      $limit          Maximum entries to return.
	 * @return list<array<string, string>>
	 */
	private static function parse_log_entries( array $lines, string $level_filter, int $since_timestamp, int $limit ): array {
		$entries = [];

		foreach ( array_reverse( $lines ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}

			// Parse WordPress-style log: [DD-Mon-YYYY HH:MM:SS UTC] PHP Error: message
			$level   = 'unknown';
			$message = $line;
			$time    = '';

			if ( preg_match( '/^\[([^\]]+)\]\s+PHP\s+(Fatal error|Error|Warning|Notice|Deprecated|Parse error):\s*(.+)$/i', $line, $matches ) ) {
				$time    = $matches[1];
				$level   = strtolower( $matches[2] );
				$message = $matches[3];

				// Normalise level names.
				if ( str_contains( $level, 'fatal' ) || str_contains( $level, 'parse' ) ) {
					$level = 'error';
				} elseif ( str_contains( $level, 'warning' ) ) {
					$level = 'warning';
				} elseif ( str_contains( $level, 'notice' ) || str_contains( $level, 'deprecated' ) ) {
					$level = 'notice';
				}

				// Apply time filter.
				if ( $time ) {
					$entry_timestamp = strtotime( $time );
					if ( $entry_timestamp && $entry_timestamp < $since_timestamp ) {
						continue;
					}
				}
			}

			// Apply level filter.
			if ( 'all' !== $level_filter && $level !== $level_filter ) {
				continue;
			}

			$entries[] = [
				'time'    => $time,
				'level'   => $level,
				'message' => $message,
			];

			if ( count( $entries ) >= $limit ) {
				break;
			}
		}

		return $entries;
	}

	/**
	 * Get the total size of a directory in megabytes.
	 *
	 * Uses a recursive iterator for accuracy. Returns 0 on failure.
	 *
	 * @param string $path Absolute directory path.
	 * @return float Size in MB, rounded to 2 decimal places.
	 */
	private static function get_directory_size_mb( string $path ): float {
		if ( ! is_dir( $path ) ) {
			return 0.0;
		}

		$total_bytes = 0;

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS )
			);

			foreach ( $iterator as $file ) {
				// @phpstan-ignore-next-line
				if ( $file->isFile() ) {
					// @phpstan-ignore-next-line
					$total_bytes += $file->getSize();
				}
			}
		} catch ( \Exception $e ) {
			// Silently return what we have if we hit a permission error mid-scan.
		}

		return round( $total_bytes / ( 1024 * 1024 ), 2 );
	}
}
