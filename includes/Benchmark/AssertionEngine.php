<?php

declare(strict_types=1);
/**
 * Assertion engine — runs functional checks against live WordPress state
 * after the agent loop completes.
 *
 * Each assertion type inspects real WordPress state (DB tables, registered
 * routes, hooks, post types, etc.) and returns a structured result so the
 * benchmark CLI can report exactly what passed, what failed, and why.
 *
 * @package SdAiAgent\Benchmark
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Benchmark;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AssertionEngine {

	/**
	 * Run all assertions for a question and return structured results.
	 *
	 * @param array<int, array<string, mixed>> $assertions Assertion definitions.
	 * @param array<string, mixed>             $context    Runtime context (plugin_slug, etc.).
	 * @return array{passed: int, failed: int, total: int, results: list<array<string, mixed>>}
	 */
	public static function run( array $assertions, array $context = array() ): array {
		$results = array();

		foreach ( $assertions as $assertion ) {
			$type   = (string) ( $assertion['type'] ?? '' );
			$result = self::run_one( $type, $assertion, $context );

			$results[] = array_merge(
				array(
					'type'        => $type,
					'description' => $assertion['description'] ?? $type,
				),
				$result
			);
		}

		$passed = count( array_filter( $results, fn( $r ) => $r['pass'] ) );
		$total  = count( $results );

		return array(
			'passed'  => $passed,
			'failed'  => $total - $passed,
			'total'   => $total,
			'results' => $results,
		);
	}

	/**
	 * Dispatch a single assertion by type.
	 *
	 * @param string               $type      Assertion type identifier.
	 * @param array<string, mixed> $assertion Full assertion definition.
	 * @param array<string, mixed> $context   Runtime context.
	 * @return array{pass: bool, expected: string, actual: string, detail?: string}
	 */
	private static function run_one( string $type, array $assertion, array $context ): array {
		switch ( $type ) {

			case 'plugin_activates':
				return self::assert_plugin_activates( $context );

			case 'plugin_no_php_errors':
				return self::assert_plugin_no_php_errors( $context );

			case 'rest_endpoint_registered':
				return self::assert_rest_endpoint_registered(
					(string) ( $assertion['method'] ?? 'GET' ),
					(string) ( $assertion['path'] ?? '' )
				);

			case 'rest_endpoint_response':
				return self::assert_rest_endpoint_response(
					(string) ( $assertion['method'] ?? 'GET' ),
					(string) ( $assertion['path'] ?? '' ),
					(array) ( $assertion['body'] ?? array() ),
					self::normalize_int_list( $assertion['expected_status'] ?? 200 ),
					self::normalize_string_list( $assertion['expected_body_keys'] ?? array() ),
					self::normalize_optional_int( $assertion['as_user'] ?? null )
				);

			case 'db_table_exists':
				return self::assert_db_table_exists( (string) ( $assertion['table'] ?? '' ) );

			case 'db_table_has_columns':
				return self::assert_db_table_has_columns(
					(string) ( $assertion['table'] ?? '' ),
					self::normalize_string_list( $assertion['columns'] ?? array() )
				);

			case 'shortcode_registered':
				return self::assert_shortcode_registered( (string) ( $assertion['tag'] ?? '' ) );

			case 'post_type_registered':
				return self::assert_post_type_registered( (string) ( $assertion['post_type'] ?? '' ) );

			case 'hook_registered':
				return self::assert_hook_registered(
					(string) ( $assertion['hook'] ?? '' ),
					(string) ( $assertion['callback_pattern'] ?? '' )
				);

			case 'option_exists':
				return self::assert_option_exists( (string) ( $assertion['option'] ?? '' ) );

			case 'file_exists_in_plugin':
				return self::assert_file_exists_in_plugin(
					(string) ( $assertion['file'] ?? '' ),
					$context
				);

			case 'file_contains':
				return self::assert_file_contains(
					(string) ( $assertion['file'] ?? '' ),
					(string) ( $assertion['pattern'] ?? '' ),
					$context
				);

			case 'wp_cli_command':
				return self::assert_wp_cli_command(
					(string) ( $assertion['command'] ?? '' ),
					(string) ( $assertion['expected_output_pattern'] ?? '' ),
					(int) ( $assertion['expected_exit_code'] ?? 0 )
				);

			case 'tool_called':
				return self::assert_tool_called(
					self::normalize_string_list( $assertion['tools'] ?? array() ),
					$context,
					(int) ( $assertion['min_calls'] ?? 1 )
				);

			case 'post_exists':
				return self::assert_post_exists(
					(string) ( $assertion['post_type'] ?? 'post' ),
					(string) ( $assertion['title_pattern'] ?? '' ),
					(string) ( $assertion['status'] ?? '' )
				);

			case 'option_value_matches':
				return self::assert_option_value_matches(
					(string) ( $assertion['option'] ?? '' ),
					(string) ( $assertion['pattern'] ?? '' )
				);

			case 'taxonomy_registered':
				return self::assert_taxonomy_registered( (string) ( $assertion['taxonomy'] ?? '' ) );

			case 'menu_exists':
				return self::assert_menu_exists( (string) ( $assertion['name'] ?? '' ) );

			case 'user_exists':
				return self::assert_user_exists(
					(string) ( $assertion['login'] ?? '' ),
					(string) ( $assertion['role'] ?? '' )
				);

			default:
				return array(
					'pass'     => false,
					'expected' => 'known assertion type',
					'actual'   => "unknown type: {$type}",
				);
		}
	}

	// ── Assertion implementations ─────────────────────────────────────────────

	/**
	 * Try to activate the benchmark plugin and check for errors.
	 *
	 * @param array<string, mixed> $context Runtime context containing plugin_slug.
	 * @return array{pass: bool, expected: string, actual: string}
	 */
	private static function assert_plugin_activates( array $context ): array {
		$slug = (string) ( $context['plugin_slug'] ?? '' );

		if ( '' === $slug ) {
			return array(
				'pass'     => false,
				'expected' => 'plugin_slug in context',
				'actual'   => 'no plugin_slug provided',
			);
		}

		$plugin_file = "{$slug}/{$slug}.php";

		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
			return array(
				'pass'     => false,
				'expected' => "plugin file {$plugin_file} to exist",
				'actual'   => 'plugin file not found — agent did not create it',
			);
		}

		// validate_plugin() uses the get_plugins() cache, which was populated
		// before the agent created this file — bust it so the new plugin is
		// recognised.
		wp_cache_delete( 'plugins', 'plugins' );
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( false );
		}

		// Activate and capture any WP_Error.
		$result = activate_plugin( $plugin_file, '', false, true );

		if ( is_wp_error( $result ) ) {
			return array(
				'pass'     => false,
				'expected' => 'plugin activates without error',
				'actual'   => $result->get_error_message(),
			);
		}

		// activate_plugin() updates the active_plugins option but does not
		// load the plugin's PHP into the current process — so its hooks
		// (init, rest_api_init, etc.) are not registered for assertions that
		// follow. Include it now and replay only the *newly added* init /
		// rest_api_init callbacks (re-firing the whole init action would
		// re-trigger every other plugin and explode on idempotency checks).
		$absolute = WP_PLUGIN_DIR . '/' . $plugin_file;

		$replay_hooks = array( 'plugins_loaded', 'after_setup_theme', 'init', 'wp_loaded', 'rest_api_init' );
		$snapshot     = self::snapshot_callbacks( $replay_hooks );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		include_once $absolute;

		foreach ( $replay_hooks as $hook ) {
			self::run_new_callbacks( $hook, $snapshot[ $hook ] ?? array() );
		}

		return array(
			'pass'     => true,
			'expected' => 'plugin activates without error',
			'actual'   => 'activated successfully',
		);
	}

	/**
	 * Check the plugin file passes PHP syntax check.
	 *
	 * @param array<string, mixed> $context Runtime context.
	 * @return array{pass: bool, expected: string, actual: string}
	 */
	private static function assert_plugin_no_php_errors( array $context ): array {
		$slug = (string) ( $context['plugin_slug'] ?? '' );
		$dir  = WP_PLUGIN_DIR . '/' . $slug;

		if ( ! is_dir( $dir ) ) {
			return array(
				'pass'     => false,
				'expected' => "plugin directory {$slug} to exist",
				'actual'   => 'directory not found',
			);
		}

		$iterator  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);
		$php_files = array();
		foreach ( $iterator as $file_info ) {
			if ( $file_info->isFile() && 'php' === strtolower( $file_info->getExtension() ) ) {
				$php_files[] = $file_info->getPathname();
			}
		}
		$errors = array();

		$php_binary = PHP_BINARY;
		foreach ( $php_files as $file ) {
			$output    = array();
			$exit_code = 0;
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- PHP syntax check; no WP alternative.
			exec( escapeshellarg( $php_binary ) . ' -l ' . escapeshellarg( $file ) . ' 2>&1', $output, $exit_code );
			if ( $exit_code !== 0 ) {
				$errors[] = ltrim( str_replace( $dir, '', $file ), '/' ) . ': ' . implode( ' ', $output );
			}
		}

		if ( ! empty( $errors ) ) {
			return array(
				'pass'     => false,
				'expected' => 'no PHP syntax errors',
				'actual'   => implode( '; ', $errors ),
			);
		}

		return array(
			'pass'     => true,
			'expected' => 'no PHP syntax errors',
			'actual'   => count( $php_files ) . ' file(s) passed syntax check',
		);
	}

	/**
	 * Check a REST route is registered for the given method + path.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Route path (e.g. '/my-plugin/v1/events').
	 * @return array{pass: bool, expected: string, actual: string}
	 */
	private static function assert_rest_endpoint_registered( string $method, string $path ): array {
		// Force REST route registration. `rest_api_init` is a WordPress core
		// hook (not a plugin-owned hook), so the prefix rule does not apply —
		// we are intentionally re-firing core's own hook in benchmark context
		// because rest_get_server() may be called before the normal request
		// lifecycle would have triggered it.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core hook, not a custom plugin hook.
		do_action( 'rest_api_init' );

		$server = rest_get_server();
		$routes = $server->get_routes();

		$normalized_path = '/' . ltrim( $path, '/' );
		foreach ( $routes as $route => $handlers ) {
			if ( untrailingslashit( $route ) !== untrailingslashit( $normalized_path ) ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				$methods = array_keys( array_filter( (array) ( $handler['methods'] ?? array() ) ) );
				if ( in_array( strtoupper( $method ), $methods, true ) ) {
					return array(
						'pass'     => true,
						'expected' => "{$method} {$path} registered",
						'actual'   => "found at route: {$route}",
					);
				}
			}
		}

		return array(
			'pass'     => false,
			'expected' => "{$method} {$path} registered",
			'actual'   => 'route not found in REST server',
		);
	}

	/**
	 * Make a real REST request and check the HTTP status and optional body keys.
	 *
	 * @param string               $method              HTTP method.
	 * @param string               $path                Route path.
	 * @param array<string, mixed> $body                Request body.
	 * @param array<int, int>      $expected_statuses   Expected HTTP status codes.
	 * @param array<int, string>   $expected_body_keys  Keys that must exist in the JSON response.
	 * @return array{pass: bool, expected: string, actual: string}
	 */
	private static function assert_rest_endpoint_response(
		string $method,
		string $path,
		array $body,
		array $expected_statuses,
		array $expected_body_keys,
		?int $as_user = null
	): array {
		// `rest_api_init` is a WP core hook — not a plugin hook needing a prefix.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core hook, not a custom plugin hook.
		do_action( 'rest_api_init' );

		$request = new \WP_REST_Request( $method, $path );
		if ( ! empty( $body ) ) {
			$request->set_body_params( $body );
		}

		// Optionally swap the current user just for this request — used to
		// verify capability gates (e.g. as_user=0 means anonymous).
		$prev_user_id = null;
		if ( null !== $as_user ) {
			$prev_user_id = get_current_user_id();
			wp_set_current_user( (int) $as_user );
		}

		$response = rest_do_request( $request );

		if ( null !== $prev_user_id ) {
			wp_set_current_user( $prev_user_id );
		}

		$status      = $response->get_status();
		$data        = (array) $response->get_data();
		$status_pass = in_array( $status, $expected_statuses, true );

		$missing_keys = array();
		foreach ( $expected_body_keys as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				$missing_keys[] = $key;
			}
		}

		$pass = $status_pass && empty( $missing_keys );

		$actual_parts = array( "HTTP {$status}" );
		if ( ! empty( $missing_keys ) ) {
			$actual_parts[] = 'missing keys: ' . implode( ', ', $missing_keys );
		}
		if ( ! $status_pass || ! empty( $missing_keys ) ) {
			$body_preview   = wp_json_encode( array_slice( $data, 0, 5 ) );
			$actual_parts[] = "body: {$body_preview}";
		}

		$expected_label = count( $expected_statuses ) === 1
			? "HTTP {$expected_statuses[0]}"
			: 'HTTP ' . implode( '|', $expected_statuses );
		$expected_parts = array( $expected_label );
		if ( ! empty( $expected_body_keys ) ) {
			$expected_parts[] = 'keys: ' . implode( ', ', $expected_body_keys );
		}

		return array(
			'pass'     => $pass,
			'expected' => implode( ', ', $expected_parts ),
			'actual'   => implode( ', ', $actual_parts ),
		);
	}

	/**
	 * Check a database table exists.
	 *
	 * @param string $table Full table name (with prefix) or bare name — prefix is auto-added if missing.
	 * @return array{pass: bool, expected: string, actual: string}
	 */
	private static function assert_db_table_exists( string $table ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// Auto-prepend prefix if the caller passed a bare name.
		if ( false === strpos( $table, $wpdb->prefix ) ) {
			$table = $wpdb->prefix . $table;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return array(
			'pass'     => $found === $table,
			'expected' => "table {$table} exists",
			'actual'   => $found ? "found: {$found}" : 'table not found',
		);
	}

	/**
	 * Check a table exists and has the expected columns.
	 *
	 * @param string             $table   Table name.
	 * @param array<int, string> $columns Column names that must exist.
	 * @return array{pass: bool, expected: string, actual: string}
	 */
	private static function assert_db_table_has_columns( string $table, array $columns ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( false === strpos( $table, $wpdb->prefix ) ) {
			$table = $wpdb->prefix . $table;
		}

		// Defence in depth: DESCRIBE cannot use prepared placeholders for the
		// table identifier, so validate the name is a plain MySQL identifier
		// (alphanumerics + underscore) and that it actually exists in this
		// database before interpolating it into the query. This eliminates
		// any path from a benchmark assertion definition to arbitrary SQL.
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			return array(
				'pass'     => false,
				'expected' => 'table name to contain only [A-Za-z0-9_]',
				'actual'   => "rejected unsafe table name: {$table}",
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return array(
				'pass'     => false,
				'expected' => "table {$table} exists with columns: " . implode( ', ', $columns ),
				'actual'   => "table {$table} does not exist",
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is validated against /^[A-Za-z0-9_]+$/ AND confirmed to exist via SHOW TABLES above; DESCRIBE does not accept prepared placeholders for identifiers.
		$rows = (array) $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A );

		if ( empty( $rows ) ) {
			return array(
				'pass'     => false,
				'expected' => 'table exists with columns: ' . implode( ', ', $columns ),
				'actual'   => "table {$table} not found or has no columns",
			);
		}

		$existing = self::normalize_string_list( array_column( $rows, 'Field' ) );
		$missing  = array_diff( $columns, $existing );

		return array(
			'pass'     => empty( $missing ),
			'expected' => 'columns: ' . implode( ', ', $columns ),
			'actual'   => empty( $missing )
				? 'all columns present'
				: 'missing: ' . implode( ', ', $missing ),
		);
	}

	/**
	 * Check a shortcode tag is registered.
	 *
	 * @param string $tag Shortcode tag.
	 * @return array{pass: bool, expected: string, actual: string}
	 */
	private static function assert_shortcode_registered( string $tag ): array {
		$pass = shortcode_exists( $tag );
		return array(
			'pass'     => $pass,
			'expected' => "shortcode [{$tag}] registered",
			'actual'   => $pass ? 'registered' : 'not registered',
		);
	}

	/**
	 * Check a post type is registered.
	 *
	 * @param string $post_type Post type slug.
	 * @return array{pass: bool, expected: string, actual: string}
	 */
	private static function assert_post_type_registered( string $post_type ): array {
		$pass = post_type_exists( $post_type );
		return array(
			'pass'     => $pass,
			'expected' => "post type '{$post_type}' registered",
			'actual'   => $pass ? 'registered' : 'not registered',
		);
	}

	/**
	 * Check a WordPress hook has at least one callback matching an optional pattern.
	 *
	 * @param string $hook             Hook name.
	 * @param string $callback_pattern Optional regex to match against callback name.
	 * @return array{pass: bool, expected: string, actual: string}
	 */
	private static function assert_hook_registered( string $hook, string $callback_pattern ): array {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook ] ) ) {
			return array(
				'pass'     => false,
				'expected' => "hook '{$hook}' has callbacks",
				'actual'   => 'hook not registered',
			);
		}

		if ( '' === $callback_pattern ) {
			return array(
				'pass'     => true,
				'expected' => "hook '{$hook}' has callbacks",
				'actual'   => 'hook has callbacks',
			);
		}

		// Walk all callbacks and check for pattern match.
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			$priority_label = is_scalar( $priority ) ? (string) $priority : 'unknown';
			foreach ( $callbacks as $callback ) {
				$fn   = $callback['function'];
				$name = is_array( $fn )
					? ( is_object( $fn[0] ) ? get_class( $fn[0] ) : $fn[0] ) . '::' . $fn[1]
					: ( is_string( $fn ) ? $fn : '{closure}' );

				if ( preg_match( '/' . $callback_pattern . '/i', $name ) ) {
					return array(
						'pass'     => true,
						'expected' => "callback matching '{$callback_pattern}' on '{$hook}'",
						'actual'   => "found: {$name} (priority {$priority_label})",
					);
				}
			}
		}

		return array(
			'pass'     => false,
			'expected' => "callback matching '{$callback_pattern}' on '{$hook}'",
			'actual'   => 'no matching callback found',
		);
	}

	/**
	 * Check a WordPress option exists (is not false).
	 *
	 * @param string $option Option name.
	 * @return array{pass: bool, expected: string, actual: string}
	 */
	private static function assert_option_exists( string $option ): array {
		$value = get_option( $option, '__benchmark_not_found__' );
		$pass  = '__benchmark_not_found__' !== $value;

		return array(
			'pass'     => $pass,
			'expected' => "option '{$option}' exists",
			'actual'   => $pass ? 'exists (value: ' . substr( (string) wp_json_encode( $value ), 0, 80 ) . ')' : 'not found',
		);
	}

	/**
	 * Check a file exists inside the benchmark plugin directory.
	 *
	 * @param string               $file    Relative path within the plugin.
	 * @param array<string, mixed> $context Runtime context.
	 * @return array{pass: bool, expected: string, actual: string}
	 */
	private static function assert_file_exists_in_plugin( string $file, array $context ): array {
		$slug      = (string) ( $context['plugin_slug'] ?? '' );
		$full_path = WP_PLUGIN_DIR . "/{$slug}/{$file}";
		$pass      = file_exists( $full_path );

		return array(
			'pass'     => $pass,
			'expected' => "file {$slug}/{$file} exists",
			'actual'   => $pass ? 'found' : 'not found',
		);
	}

	/**
	 * Check a file in the plugin contains a given regex pattern.
	 *
	 * @param string               $file    Relative path within the plugin.
	 * @param string               $pattern Regex pattern.
	 * @param array<string, mixed> $context Runtime context.
	 * @return array{pass: bool, expected: string, actual: string}
	 */
	private static function assert_file_contains( string $file, string $pattern, array $context ): array {
		$slug      = (string) ( $context['plugin_slug'] ?? '' );
		$full_path = WP_PLUGIN_DIR . "/{$slug}/{$file}";

		if ( ! file_exists( $full_path ) ) {
			return array(
				'pass'     => false,
				'expected' => "file {$slug}/{$file} containing /{$pattern}/",
				'actual'   => 'file not found',
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local file path, not a remote URL.
		$contents = (string) file_get_contents( $full_path );
		$pass     = (bool) preg_match( '/' . $pattern . '/i', $contents );

		return array(
			'pass'     => $pass,
			'expected' => "file contains /{$pattern}/",
			'actual'   => $pass ? 'pattern found' : 'pattern not found',
		);
	}

	/**
	 * Run a WP-CLI command and check exit code and optional output pattern.
	 *
	 * @param string $command                WP-CLI command (without 'wp' prefix).
	 * @param string $expected_output_pattern Regex pattern the output must match.
	 * @param int    $expected_exit_code      Expected exit code.
	 * @return array{pass: bool, expected: string, actual: string}
	 */
	private static function assert_wp_cli_command(
		string $command,
		string $expected_output_pattern,
		int $expected_exit_code
	): array {
		// Auto-inject --user=<admin> when the caller didn't pass one. WC CLI
		// commands (and any others gated on capabilities) fail with 401
		// otherwise because the external `wp` invocation has no logged-in user.
		if ( false === strpos( $command, '--user=' ) ) {
			$current = wp_get_current_user();
			if ( $current && $current->exists() ) {
				$command = '--user=' . (int) $current->ID . ' ' . $command;
			}
		}

		$safe_command = escapeshellcmd( 'wp --allow-root ' . $command ) . ' 2>&1';
		$output       = array();
		$exit_code    = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- running WP-CLI for assertion checks; no WP alternative.
		exec( $safe_command, $output, $exit_code );

		$output_str  = implode( "\n", $output );
		$exit_pass   = ( $exit_code === $expected_exit_code );
		$output_pass = ( '' === $expected_output_pattern )
			|| (bool) preg_match( '/' . $expected_output_pattern . '/i', $output_str );

		$pass = $exit_pass && $output_pass;

		$expected_desc = "exit {$expected_exit_code}";
		if ( '' !== $expected_output_pattern ) {
			$expected_desc .= ", output matching /{$expected_output_pattern}/";
		}

		$actual_desc = "exit {$exit_code}";
		if ( ! $output_pass ) {
			$actual_desc .= ', output: ' . substr( $output_str, 0, 200 );
		}

		return array(
			'pass'     => $pass,
			'expected' => $expected_desc,
			'actual'   => $pass ? 'passed' : $actual_desc,
		);
	}

	/**
	 * Assert that the agent invoked one of the listed abilities/tools.
	 *
	 * @param array<int, string>   $tools     Ability names (e.g. 'sd-ai-agent/create-post')
	 *                                        or short suffixes ('create-post'). ANY match passes.
	 * @param array<string, mixed> $context   Runtime context with 'tool_call_log'.
	 * @param int                  $min_calls Minimum number of matching calls required.
	 * @return array{pass: bool, expected: string, actual: string}
	 */
	private static function assert_tool_called( array $tools, array $context, int $min_calls = 1 ): array {
		$log = (array) ( $context['tool_call_log'] ?? array() );

		if ( empty( $tools ) ) {
			return array(
				'pass'     => false,
				'expected' => 'at least one tool name to match',
				'actual'   => 'no tools listed in assertion',
			);
		}

		$matches = 0;
		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$name      = (string) ( $entry['tool'] ?? '' );
			$input     = (array) ( $entry['input'] ?? array() );
			$is_meta   = str_ends_with( $name, '/ability-call' )
				|| str_ends_with( $name, '__ability-call' );
			$inner     = $is_meta ? (string) ( $input['ability'] ?? $input['ability_name'] ?? $input['name'] ?? '' ) : '';
			$wpab_name = self::ability_to_function_name( $name );

			foreach ( $tools as $candidate ) {
				if ( '' === $candidate ) {
					continue;
				}
				$cand_func = self::ability_to_function_name( $candidate );

				// Direct invocation match: name equals or wpab-form matches.
				if ( $name === $candidate || $wpab_name === $cand_func || $name === $cand_func ) {
					++$matches;
					break;
				}
				// Meta-dispatch via ability-call: candidate appears in the inner ability_name argument.
				if ( '' !== $inner && ( $inner === $candidate || str_ends_with( $inner, '/' . self::ability_short_name( $candidate ) ) ) ) {
					++$matches;
					break;
				}
				// Permissive suffix match (e.g. 'create-post' matches 'sd-ai-agent/create-post' direct call).
				$short = self::ability_short_name( $candidate );
				if ( '' !== $short && ( str_ends_with( $name, '/' . $short ) || str_ends_with( $name, '__' . $short ) || ( '' !== $inner && str_ends_with( $inner, '/' . $short ) ) ) ) {
					++$matches;
					break;
				}
			}
		}

		$pass = $matches >= $min_calls;

		return array(
			'pass'     => $pass,
			'expected' => sprintf( 'at least %d call to one of [%s]', $min_calls, implode( ', ', $tools ) ),
			'actual'   => sprintf( '%d matching call(s) in tool log', $matches ),
		);
	}

	/**
	 * Convert an ability identifier into the wpab__ function-name form the
	 * AI client uses, e.g. `sd-ai-agent/create-post` → `wpab__ai-agent__create-post`.
	 * If already in wpab__ form, returns as-is.
	 */
	private static function ability_to_function_name( string $name ): string {
		if ( '' === $name ) {
			return '';
		}
		if ( str_starts_with( $name, 'wpab__' ) ) {
			return $name;
		}
		if ( str_contains( $name, '/' ) ) {
			return 'wpab__' . str_replace( '/', '__', $name );
		}
		return $name;
	}

	/**
	 * Strip the namespace prefix from an ability name.
	 * `sd-ai-agent/create-post` → `create-post`. Already-short names pass through.
	 */
	private static function ability_short_name( string $name ): string {
		$pos = strrpos( $name, '/' );
		return false === $pos ? $name : substr( $name, $pos + 1 );
	}

	/**
	 * Normalize a scalar-or-list assertion value to a string list.
	 *
	 * @param mixed $value Raw assertion value.
	 * @return array<int, string>
	 */
	private static function normalize_string_list( mixed $value ): array {
		$items = is_array( $value ) ? $value : array( $value );
		$out   = array();

		foreach ( $items as $item ) {
			if ( is_scalar( $item ) ) {
				$out[] = (string) $item;
			}
		}

		return $out;
	}

	/**
	 * Normalize a scalar-or-list assertion value to an integer list.
	 *
	 * @param mixed $value Raw assertion value.
	 * @return array<int, int>
	 */
	private static function normalize_int_list( mixed $value ): array {
		$items = is_array( $value ) ? $value : array( $value );
		$out   = array();

		foreach ( $items as $item ) {
			if ( is_scalar( $item ) ) {
				$out[] = (int) $item;
			}
		}

		return empty( $out ) ? array( 200 ) : $out;
	}

	/**
	 * Normalize an optional integer assertion value.
	 *
	 * @param mixed $value Raw assertion value.
	 */
	private static function normalize_optional_int( mixed $value ): ?int {
		return null === $value || ! is_scalar( $value ) ? null : (int) $value;
	}

	/**
	 * Assert a post matching a title pattern exists, optionally constrained by status.
	 *
	 * @return array{pass: bool, expected: string, actual: string}
	 */
	private static function assert_post_exists( string $post_type, string $title_pattern, string $status ): array {
		$args  = array(
			'post_type'   => $post_type ?: 'post',
			'post_status' => $status ? array( $status ) : array( 'any', 'trash', 'draft', 'publish', 'pending', 'private', 'future' ),
			'numberposts' => 50,
			's'           => '',
		);
		$posts = get_posts( $args );
		foreach ( $posts as $post ) {
			if ( '' === $title_pattern || preg_match( '/' . $title_pattern . '/i', $post->post_title ) ) {
				return array(
					'pass'     => true,
					'expected' => "{$post_type} matching /{$title_pattern}/",
					'actual'   => 'found post #' . $post->ID . ' "' . $post->post_title . '"',
				);
			}
		}
		return array(
			'pass'     => false,
			'expected' => "{$post_type} matching /{$title_pattern}/",
			'actual'   => 'no matching post found',
		);
	}

	/**
	 * Assert option exists and its serialised value matches a regex pattern.
	 *
	 * @return array{pass: bool, expected: string, actual: string}
	 */
	private static function assert_option_value_matches( string $option, string $pattern ): array {
		if ( ! self::assert_option_exists( $option )['pass'] ) {
			return array(
				'pass'     => false,
				'expected' => "option {$option} matching /{$pattern}/",
				'actual'   => 'option missing',
			);
		}
		$value = get_option( $option );
		$blob  = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
		$pass  = (bool) preg_match( '/' . $pattern . '/i', $blob );
		return array(
			'pass'     => $pass,
			'expected' => "option {$option} matching /{$pattern}/",
			'actual'   => $pass ? 'matched' : 'value: ' . substr( $blob, 0, 200 ),
		);
	}

	/**
	 * Assert a taxonomy is registered.
	 *
	 * @return array{pass: bool, expected: string, actual: string}
	 */
	private static function assert_taxonomy_registered( string $taxonomy ): array {
		$pass = taxonomy_exists( $taxonomy );
		return array(
			'pass'     => $pass,
			'expected' => "taxonomy {$taxonomy} registered",
			'actual'   => $pass ? 'registered' : 'not registered',
		);
	}

	/**
	 * Assert a navigation menu exists by name.
	 *
	 * @return array{pass: bool, expected: string, actual: string}
	 */
	private static function assert_menu_exists( string $name ): array {
		$menu = wp_get_nav_menu_object( $name );
		$pass = $menu && ! is_wp_error( $menu );
		return array(
			'pass'     => $pass,
			'expected' => "nav menu '{$name}' exists",
			'actual'   => $pass ? 'menu #' . $menu->term_id : 'not found',
		);
	}

	/**
	 * Assert a user exists with an optional role constraint.
	 *
	 * @return array{pass: bool, expected: string, actual: string}
	 */
	private static function assert_user_exists( string $login, string $role ): array {
		$user = get_user_by( 'login', $login );
		if ( ! $user ) {
			return array(
				'pass'     => false,
				'expected' => "user '{$login}' exists" . ( $role ? " with role '{$role}'" : '' ),
				'actual'   => 'user not found',
			);
		}
		if ( '' === $role ) {
			return array(
				'pass'     => true,
				'expected' => "user '{$login}' exists",
				'actual'   => 'user #' . $user->ID,
			);
		}
		$pass = in_array( $role, (array) $user->roles, true );
		return array(
			'pass'     => $pass,
			'expected' => "user '{$login}' has role '{$role}'",
			'actual'   => 'roles: ' . implode( ',', (array) $user->roles ),
		);
	}

	/**
	 * Snapshot the registered callback IDs for a list of action names.
	 *
	 * Used by assert_plugin_activates() to identify which callbacks were
	 * added by including the plugin file so we can run just those without
	 * re-firing the whole hook (which would explode on plugins that detect
	 * duplicate registration).
	 *
	 * @param array<int, string> $hooks Hook names.
	 * @return array<string, array<string, true>>
	 */
	private static function snapshot_callbacks( array $hooks ): array {
		global $wp_filter;
		$out = array();
		foreach ( $hooks as $hook ) {
			$out[ $hook ] = array();
			if ( ! isset( $wp_filter[ $hook ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $id => $_cb ) {
					$out[ $hook ][ (string) $id ] = true;
				}
			}
		}
		return $out;
	}

	/**
	 * Invoke any callbacks added to $hook that are NOT in the snapshot.
	 *
	 * @param string              $hook     Hook name.
	 * @param array<string, true> $existing Snapshot of callback IDs from before.
	 */
	private static function run_new_callbacks( string $hook, array $existing ): void {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $id => $cb ) {
				if ( isset( $existing[ $id ] ) ) {
					continue;
				}
				if ( is_callable( $cb['function'] ) ) {
					call_user_func( $cb['function'] );
				}
			}
		}
	}
}
