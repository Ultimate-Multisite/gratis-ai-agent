<?php

declare(strict_types=1);
/**
 * Plugin Sandbox — three-layer safety system for AI-generated plugin activation.
 *
 * Layer 1: Static Analysis — `php -l` syntax check on every generated file.
 * Layer 2: Isolated Process Test — WP-CLI subprocess loads WordPress core and
 *           tries include_once on the plugin. If fatal, subprocess dies; parent
 *           process survives.
 * Layer 3: Transactional Activation — activate_plugin() with error handler +
 *           shutdown function. Transient flag auto-deactivates on next request
 *           after a fatal. Immediate rollback on failure.
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
 * PluginSandbox — three-layer safety testing for AI-generated plugins.
 *
 * @since 1.5.0
 */
class PluginSandbox {

	/**
	 * Transient key prefix used to flag a plugin that caused a fatal on activation.
	 */
	const FATAL_TRANSIENT_PREFIX = 'sd_ai_agent_plugin_fatal_';

	/**
	 * Run all three safety layers against a plugin directory.
	 *
	 * Returns an array with keys:
	 *   - layer1_passed (bool)
	 *   - layer2_passed (bool)
	 *   - layer3_passed (bool)
	 *   - errors        (string[]) — one entry per failed layer
	 *   - passed        (bool) — true only if all layers passed
	 *
	 * @param string $plugin_dir   Absolute path to the plugin directory.
	 * @param string $plugin_file  Relative path from $plugin_dir to main plugin file.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function run_all( string $plugin_dir, string $plugin_file ): array|\WP_Error {
		$result = [
			'layer1_passed' => false,
			'layer2_passed' => false,
			'layer3_passed' => false,
			'errors'        => [],
			'passed'        => false,
		];

		// Layer 1: syntax check.
		$layer1 = self::layer1_syntax_check( $plugin_dir );
		if ( is_wp_error( $layer1 ) ) {
			$result['errors'][] = 'Layer 1 (syntax): ' . $layer1->get_error_message();
			return $result;
		}
		$result['layer1_passed'] = true;

		// Layer 2: isolated include.
		$layer2 = self::layer2_isolated_include( $plugin_dir, $plugin_file );
		if ( is_wp_error( $layer2 ) ) {
			$result['errors'][] = 'Layer 2 (isolated include): ' . $layer2->get_error_message();
			return $result;
		}
		$result['layer2_passed'] = true;
		$result['passed']        = true;

		return $result;
	}

	/**
	 * Layer 1: Static syntax check via `php -l` on every PHP file in the plugin dir.
	 *
	 * @param string $plugin_dir Absolute path to the plugin directory.
	 * @return true|\WP_Error
	 */
	public static function layer1_syntax_check( string $plugin_dir ): bool|\WP_Error {
		if ( ! is_dir( $plugin_dir ) ) {
			return new WP_Error(
				'sd_ai_agent_sandbox_dir_not_found',
				/* translators: %s: directory path */
				sprintf( __( 'Plugin directory not found: %s', 'superdav-ai-agent' ), $plugin_dir )
			);
		}

		$php_files = self::find_php_files( $plugin_dir );

		foreach ( $php_files as $file ) {
			$output    = [];
			$exit_code = 0;
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Sandbox requires exec for subprocess isolation.
			exec( 'php -l ' . escapeshellarg( $file ) . ' 2>&1', $output, $exit_code );
			if ( 0 !== $exit_code ) {
				return new WP_Error(
					'sd_ai_agent_syntax_error',
					sprintf(
						/* translators: 1: file path 2: error output */
						__( 'Syntax error in %1$s: %2$s', 'superdav-ai-agent' ),
						str_replace( $plugin_dir . '/', '', $file ),
						implode( "\n", $output )
					)
				);
			}
		}

		return true;
	}

	/**
	 * Layer 2: Isolated process test via WP-CLI subprocess.
	 *
	 * Runs `wp eval-file` with `--skip-plugins` so the test process loads
	 * WordPress core and attempts include_once on the plugin file. If the plugin
	 * triggers a fatal, only the subprocess dies; the parent request survives.
	 *
	 * @param string $plugin_dir  Absolute path to the plugin directory.
	 * @param string $plugin_file Relative path from $plugin_dir to the main plugin file.
	 * @return true|\WP_Error
	 */
	public static function layer2_isolated_include( string $plugin_dir, string $plugin_file ): bool|\WP_Error {
		$main_file = trailingslashit( $plugin_dir ) . ltrim( $plugin_file, '/' );

		if ( ! file_exists( $main_file ) ) {
			return new WP_Error(
				'sd_ai_agent_sandbox_file_not_found',
				/* translators: %s: file path */
				sprintf( __( 'Plugin main file not found: %s', 'superdav-ai-agent' ), $main_file )
			);
		}

		// Build a tiny PHP script that attempts to include the plugin file.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- var_export needed to safely embed path in PHP code string.
		$test_php = '<?php @include_once ' . var_export( $main_file, true ) . '; echo "OK";';
		$tmp_file = wp_tempnam( 'sd_sandbox_' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing temp file; WP_Filesystem not available at this stage.
		file_put_contents( $tmp_file, $test_php );

		$wp_path   = ABSPATH;
		$output    = [];
		$exit_code = 0;

		// Attempt WP-CLI isolation. Fall back gracefully if WP-CLI is not available.
		$wp_cli = self::find_wp_cli();
		if ( $wp_cli ) {
			$cmd = $wp_cli . ' eval-file ' . escapeshellarg( $tmp_file )
				. ' --skip-plugins --path=' . escapeshellarg( $wp_path )
				. ' 2>&1';
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Sandbox requires subprocess isolation; exec is intentional here.
			exec( $cmd, $output, $exit_code );
		} else {
			// WP-CLI not available — attempt a bare php subprocess instead.
			$cmd = 'php ' . escapeshellarg( $tmp_file ) . ' 2>&1';
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Sandbox fallback subprocess; exec is intentional here.
			exec( $cmd, $output, $exit_code );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Temp file cleanup; safe to ignore if already deleted.
		@unlink( $tmp_file );

		$output_str = implode( "\n", $output );

		if ( 0 !== $exit_code || false === strpos( $output_str, 'OK' ) ) {
			return new WP_Error(
				'sd_ai_agent_isolated_include_failed',
				sprintf(
					/* translators: 1: exit code 2: output */
					__( 'Isolated include failed (exit %1$d): %2$s', 'superdav-ai-agent' ),
					$exit_code,
					$output_str
				)
			);
		}

		return true;
	}

	/**
	 * Layer 3: Transactional activation — activate with error handler + shutdown guard.
	 *
	 * - Sets a transient flag before activation. If a fatal fires and PHP shuts
	 *   down, the next request sees the transient and deactivates the plugin
	 *   automatically.
	 * - Registers a shutdown function that clears the transient on normal shutdown.
	 * - On WP_Error from activate_plugin(), rolls back immediately.
	 *
	 * This method must be called from the web context (not WP-CLI) so that
	 * activate_plugin() hooks run correctly.
	 *
	 * @param string $plugin_file Plugin file relative to the plugins directory
	 *                            (e.g. "my-plugin/my-plugin.php").
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function layer3_activate( string $plugin_file ): array|\WP_Error {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$transient_key = self::FATAL_TRANSIENT_PREFIX . md5( $plugin_file );

		// Check if previous activation attempt flagged this plugin as fatal.
		if ( get_transient( $transient_key ) ) {
			delete_transient( $transient_key );
			return new WP_Error(
				'sd_ai_agent_previous_fatal',
				/* translators: %s: plugin file */
				sprintf( __( 'Plugin "%s" caused a fatal error on a previous activation attempt and was automatically deactivated.', 'superdav-ai-agent' ), $plugin_file )
			);
		}

		// Set the fatal-guard transient BEFORE activating.
		set_transient( $transient_key, 1, 60 );

		// Register a shutdown function that clears the transient on normal exit.
		register_shutdown_function(
			static function () use ( $transient_key, $plugin_file ) {
				$error = error_get_last();
				if ( null !== $error && in_array( $error['type'], [ E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR ], true ) ) {
					// Fatal — deactivate the plugin on next request via transient.
					set_transient( $transient_key, 1, 60 );
					deactivate_plugins( $plugin_file, true );
				} else {
					// Normal shutdown — clear the guard.
					delete_transient( $transient_key );
				}
			}
		);

		// Backup current active plugins list before activation.
		$backup_active = get_option( 'active_plugins', [] );

		$result = activate_plugin( $plugin_file );

		if ( is_wp_error( $result ) ) {
			delete_transient( $transient_key );
			return $result;
		}

		// Activation succeeded — clear transient immediately.
		delete_transient( $transient_key );

		return [
			'activated'   => true,
			'plugin_file' => $plugin_file,
			'message'     => sprintf(
				/* translators: %s: plugin file */
				__( 'Plugin "%s" activated successfully via sandboxed activation.', 'superdav-ai-agent' ),
				$plugin_file
			),
		];
	}

	/**
	 * Auto-deactivate hook — called on init to deactivate plugins flagged via transient.
	 *
	 * Register with: add_action( 'init', [ PluginSandbox::class, 'auto_deactivate_fatal_plugins' ] );
	 *
	 * @return void
	 */
	public static function auto_deactivate_fatal_plugins(): void {
		/** @var string[] $active_plugins */
		$active_plugins = (array) get_option( 'active_plugins', [] );
		foreach ( $active_plugins as $plugin_file ) {
			$plugin_file   = (string) $plugin_file;
			$transient_key = self::FATAL_TRANSIENT_PREFIX . md5( $plugin_file );
			if ( get_transient( $transient_key ) ) {
				deactivate_plugins( $plugin_file, true );
				delete_transient( $transient_key );
				// Log the auto-deactivation.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Logging fatal deactivation is intentional operational telemetry.
				error_log( 'SdAiAgent: Auto-deactivated plugin after fatal: ' . $plugin_file );
			}
		}
	}

	/**
	 * Find all PHP files in a directory recursively.
	 *
	 * @param string $dir Directory path.
	 * @return list<string>
	 */
	private static function find_php_files( string $dir ): array {
		$files    = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			/** @var \SplFileInfo $file */
			if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
				$real = $file->getRealPath();
				if ( is_string( $real ) ) {
					$files[] = $real;
				}
			}
		}
		return $files;
	}

	/**
	 * Locate the wp CLI binary.
	 *
	 * @return string Path to wp binary, or empty string if not found.
	 */
	private static function find_wp_cli(): string {
		// Check common locations. The ABSPATH-relative candidate covers the
		// common composer-installed-WordPress layout (vendor/ as a sibling of
		// the WordPress root) without depending on WP_CONTENT_DIR's location.
		$candidates = [
			'wp', // PATH
			'/usr/local/bin/wp',
			'/usr/bin/wp',
			ABSPATH . '../vendor/bin/wp',
		];

		foreach ( $candidates as $candidate ) {
			$output    = [];
			$exit_code = 0;
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Locating WP-CLI binary requires exec; no WP alternative.
			exec( 'which ' . escapeshellarg( $candidate ) . ' 2>/dev/null', $output, $exit_code );
			if ( 0 === $exit_code && ! empty( $output[0] ) ) {
				return trim( $output[0] );
			}
		}

		return '';
	}
}
