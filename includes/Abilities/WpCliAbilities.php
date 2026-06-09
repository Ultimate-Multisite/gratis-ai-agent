<?php

declare(strict_types=1);
/**
 * WP-CLI ability for the AI agent.
 *
 * Registers a single `wp-cli/execute` ability that accepts WP-CLI-style
 * command strings and dispatches them to native PHP handlers. This preserves
 * the familiar WP-CLI syntax without spawning a shell or external binary.
 *
 * Security layers:
 *   1. Top-level command blocklist (db, eval, shell, config, core, …)
 *   2. Sub-command blocklist (site delete, plugin install, …)
 *   3. Permission classification (read → manage_options, write → manage_options,
 *      destructive → manage_network)
 *   4. Native PHP dispatcher registry (no shell/process interpretation)
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Core\ChangeLogger;
use SdAiAgent\Core\Features;
use SdAiAgent\Models\ChangesLog;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WpCliAbilities {

	/**
	 * Ability category slug.
	 */
	private const CATEGORY = 'wp-cli';

	/**
	 * Top-level WP-CLI command groups to block entirely.
	 *
	 * @var string[]
	 */
	private const BLOCKED_COMMANDS = array(
		'db',
		'server',
		'shell',
		'cli',
		'config',
		'core',
		'package',
		'abilities',
		'eval',
		'eval-file',
		'search-replace',
		'scaffold',
	);

	/**
	 * Specific sub-command paths to block.
	 *
	 * @var string[]
	 */
	private const BLOCKED_SUBCOMMANDS = array(
		'site empty',
		'site generate',
		'plugin install',
		'plugin uninstall',
		'theme install',
		'super-admin add',
		'super-admin remove',
		'user application-password create',
		'cap add',
		'cap remove',
		'role delete',
		'role reset',
		'maintenance-mode activate',
		'post generate',
		'comment generate',
		'term generate',
		'user generate',
		'plugin delete',
		'theme delete',
		'site delete',
		'site spam',
		'site unspam',
		'widget reset',
		'cron event delete',
		'user reset-password',
		'user import-csv',
		'user spam',
		'user unspam',
	);

	/**
	 * Leaf command names that indicate read-only operations.
	 *
	 * @var string[]
	 */
	private const READ_ACTIONS = array(
		'list',
		'get',
		'status',
		'exists',
		'is-active',
		'is-installed',
		'count',
		'check-update',
		'path',
		'search',
		'version',
		'type',
		'pluck',
		'supports',
		'verify',
		'info',
		'describe',
		'diff',
		'logs',
		'structure',
		'providers',
	);

	/**
	 * Leaf command names that indicate destructive operations.
	 *
	 * @var string[]
	 */
	private const DESTRUCTIVE_ACTIONS = array(
		'delete',
		'drop',
		'reset',
		'destroy',
		'flush',
		'flush-group',
		'clean',
		'remove',
		'uninstall',
		'empty',
		'spam',
		'archive',
		'deactivate',
		'disable',
	);

	/**
	 * Register the wp-cli ability category.
	 *
	 * @return void
	 */
	public static function register_category(): void {
		// Feature-gated: the wp-cli/execute ability is force-disabled in
		// the WordPress.org distribution build and may be disabled
		// per-site via SD_AI_AGENT_FEATURE_WP_CLI_DISPATCHER. Skipping
		// the category registration here means the ability cannot
		// register against a valid category later in the boot sequence.
		if ( ! Features::is_enabled( Features::WP_CLI_DISPATCHER ) ) {
			return;
		}

		if ( ! function_exists( 'wp_register_ability_category' ) || ! function_exists( 'wp_has_ability_category' ) ) {
			return;
		}

		if ( ! self::is_available() ) {
			return;
		}

		if ( wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'WP-CLI', 'superdav-ai-agent' ),
				'description' => __( 'Execute supported WP-CLI-style commands through native WordPress handlers.', 'superdav-ai-agent' ),
			)
		);
	}

	/**
	 * Register the wp-cli/execute ability.
	 *
	 * @return void
	 */
	public static function register_ability(): void {
		// Feature-gated: see register_category() for the rationale.
		if ( ! Features::is_enabled( Features::WP_CLI_DISPATCHER ) ) {
			return;
		}

		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_has_ability_category' ) ) {
			return;
		}

		if ( ! self::is_available() ) {
			return;
		}

		if ( ! wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}

		$description = implode(
			"\n",
			array(
				'Execute supported WP-CLI-style commands through native PHP handlers and return structured output.',
				'Pass commands as you would type them in WP-CLI, without the "wp" prefix. Unsupported command paths fail clearly instead of shelling out.',
				'',
				'Examples:',
				'  post list --post_type=page --format=json',
				'  option get blogname',
				'  plugin list --status=active --format=json',
				'  user list --role=administrator --format=json',
				'  site list --format=json',
				'  post create --post_title="Hello World" --post_status=publish',
				'  option update blogdescription "My new tagline"',
				'',
				'Tips:',
				'- Use --format=json for structured data when the command supports it.',
				'- For multisite, add --url=<site-url> to target a specific site.',
				'- Commands that modify data require write permissions.',
				'- Implemented command paths: post list/create, option get/list/update, plugin list, user list, site list.',
				'- Dangerous or unsupported commands are blocked or return unsupported_command; no shell, eval, raw SQL mutation, or external wp binary is used.',
			)
		);

		wp_register_ability(
			self::CATEGORY . '/execute',
			array(
				'label'               => __( 'Execute WP-CLI Command', 'superdav-ai-agent' ),
				'description'         => $description,
				'category'            => self::CATEGORY,
				'permission_callback' => static function () {
					// Strictest cap set retained from the former process-backed
					// dispatcher because this ability remains a broad WordPress
					// mutation surface even though execution is now native PHP.
					return self::check_permission_level( 'execute' );
				},
				'execute_callback'    => array( __CLASS__, 'handle_execute' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'command' => array(
							'type'        => 'string',
							'description' => 'The supported WP-CLI-style command to execute through native PHP handlers, without the "wp" prefix. Example: "post list --post_type=page --format=json"',
						),
					),
					'required'             => array( 'command' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'title'       => 'WP-CLI',
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
						'open_world'  => true,
					),
					'mcp'          => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			)
		);
	}

	// ─── Execute handler ────────────────────────────────────────────────

	/**
	 * Handle a call to wp-cli/execute.
	 *
	 * @param array<string,mixed> $input The input arguments.
	 * @return array<mixed>|bool|int|string|WP_Error
	 */
	public static function handle_execute( array $input = array() ) {
		$command = '';

		if ( is_array( $input ) ) {
			$command = isset( $input['command'] ) ? (string) $input['command'] : '';
		}

		return self::execute( $command );
	}

	/**
	 * Execute a WP-CLI command from a raw command string.
	 *
	 * @param string $command The command string without the `wp` prefix.
	 * @return array<mixed>|bool|int|string|WP_Error Structured output, scalar value, or error.
	 */
	public static function execute( string $command ) {
		$command = trim( $command );

		// Strip leading 'wp ' if the agent included it.
		if ( str_starts_with( $command, 'wp ' ) ) {
			$command = substr( $command, 3 );
		}

		if ( '' === $command ) {
			return new WP_Error(
				'wp_cli_empty_command',
				__( 'No command provided. Pass a WP-CLI command, e.g. "post list --format=json".', 'superdav-ai-agent' )
			);
		}

		$tokens       = self::tokenize( $command );
		$command_path = self::extract_command_path( $tokens );

		// Check blocklist.
		if ( self::is_blocked( $command_path ) ) {
			return new WP_Error(
				'wp_cli_blocked_command',
				sprintf(
					/* translators: %s: command path */
					__( 'The command "%s" is blocked for security reasons.', 'superdav-ai-agent' ),
					$command_path
				),
				array( 'status' => 403 )
			);
		}

		// Secret-aware option subcommand gate. Blocks `wp option get auth_key`
		// and friends before native dispatch, so an unsafe value is never read
		// or expanded into logs.
		$secret_gate = self::check_secret_option_subcommand( $command_path, $tokens );
		if ( is_wp_error( $secret_gate ) ) {
			return $secret_gate;
		}

		// Permission check based on command classification.
		$level      = self::classify_command( $command_path );
		$perm_check = self::check_permission_level( $level );

		if ( is_wp_error( $perm_check ) ) {
			return $perm_check;
		}

		$result = self::dispatch_native_command( $tokens, $command_path );

		// Post-process scrub for `option list` (and any other multi-row
		// secret-bearing subcommand we add later). Catches secrets that
		// slip past the pre-check because the option name is not a
		// positional argument of the subcommand.
		if ( ! is_wp_error( $result ) && ( is_array( $result ) || is_string( $result ) ) ) {
			$result = self::scrub_secret_output( $command_path, $result );
		}

		// Audit trail: log write/destructive WP-CLI commands as unrevertable.
		// Read-only commands (list, get, status, …) are not logged.
		if ( ChangeLogger::is_active() && 'read' !== $level && ! is_wp_error( $result ) ) {
			ChangesLog::record(
				[
					'session_id'   => ChangeLogger::get_session_id(),
					'object_type'  => 'wp_cli',
					'object_id'    => 0,
					'object_title' => $command_path,
					'ability_name' => ChangeLogger::get_ability_name() ?: 'wp_cli',
					'field_name'   => 'command',
					'before_value' => '',
					'after_value'  => 'wp ' . $command,
					'revertable'   => false,
				]
			);
		}

		return $result;
	}

	// ─── Tokenizer ──────────────────────────────────────────────────────

	/**
	 * Tokenize a command string into an array of arguments.
	 *
	 * Handles single-quoted, double-quoted, and backslash-escaped characters.
	 *
	 * @param string $command The raw command string.
	 * @return string[]
	 */
	private static function tokenize( string $command ): array {
		$tokens    = array();
		$current   = '';
		$in_single = false;
		$in_double = false;
		$len       = strlen( $command );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $command[ $i ];

			if ( $in_single ) {
				if ( "'" === $char ) {
					$in_single = false;
				} else {
					$current .= $char;
				}
			} elseif ( $in_double ) {
				if ( '"' === $char ) {
					$in_double = false;
				} elseif ( '\\' === $char && $i + 1 < $len ) {
					$next = $command[ $i + 1 ];
					if ( '"' === $next || '\\' === $next ) {
						$current .= $next;
						++$i;
					} else {
						$current .= $char;
					}
				} else {
					$current .= $char;
				}
			} elseif ( "'" === $char ) {
					$in_single = true;
			} elseif ( '"' === $char ) {
				$in_double = true;
			} elseif ( '\\' === $char && $i + 1 < $len ) {
				$current .= $command[ $i + 1 ];
				++$i;
			} elseif ( ctype_space( $char ) ) {
				if ( '' !== $current ) {
					$tokens[] = $current;
					$current  = '';
				}
			} else {
				$current .= $char;
			}
		}

		if ( '' !== $current ) {
			$tokens[] = $current;
		}

		return $tokens;
	}

	// ─── Security ───────────────────────────────────────────────────────

	/**
	 * Extract the command path (non-flag tokens at the start).
	 *
	 * @param string[] $tokens Tokenized arguments.
	 * @return string Space-separated command path.
	 */
	private static function extract_command_path( array $tokens ): string {
		$path_parts = array();

		foreach ( $tokens as $token ) {
			if ( str_starts_with( $token, '-' ) ) {
				break;
			}
			$path_parts[] = $token;
		}

		return implode( ' ', $path_parts );
	}

	/**
	 * Check if a command path is blocked.
	 *
	 * @param string $command_path Space-separated command path.
	 * @return bool
	 */
	private static function is_blocked( string $command_path ): bool {
		$parts     = explode( ' ', $command_path );
		$top_level = $parts[0] ?? '';

		/**
		 * Filter the WP-CLI top-level command blocklist.
		 *
		 * @param string[] $blocklist Array of top-level command names to block.
		 */
		$blocklist = (array) apply_filters( 'sd_ai_agent_wp_cli_blocklist', self::BLOCKED_COMMANDS );

		if ( in_array( $top_level, $blocklist, true ) ) {
			return true;
		}

		/**
		 * Filter the WP-CLI sub-command blocklist.
		 *
		 * @param string[] $blocklist Array of command paths to block.
		 */
		$sub_blocklist = (array) apply_filters( 'sd_ai_agent_wp_cli_subcommand_blocklist', self::BLOCKED_SUBCOMMANDS );

		foreach ( $sub_blocklist as $blocked_path ) {
			if ( $command_path === $blocked_path || str_starts_with( $command_path, $blocked_path . ' ' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Classify a command's access level based on its leaf action.
	 *
	 * @param string $command_path Space-separated command path.
	 * @return string 'read', 'write', or 'destructive'.
	 */
	private static function classify_command( string $command_path ): string {
		$parts = explode( ' ', $command_path );
		$leaf  = end( $parts );

		if ( in_array( $leaf, self::READ_ACTIONS, true ) ) {
			return 'read';
		}

		if ( in_array( $leaf, self::DESTRUCTIVE_ACTIONS, true ) ) {
			return 'destructive';
		}

		return 'write';
	}

	/**
	 * Check if the current user has the strictest cap set required to
	 * use the wp-cli/execute dispatcher.
	 *
	 * The dispatcher shells out to the `wp` binary via PHP `exec()` and
	 * can run arbitrary WP-CLI subcommands (subject to the
	 * BLOCKED_COMMANDS / BLOCKED_SUBCOMMANDS allowlist). We therefore
	 * enforce the same cap set as `sd-ai-agent/run-php`:
	 * `manage_options` AND `update_core` AND `unfiltered_html`. These
	 * three caps are individually revocable via role-management
	 * plugins, and an administrator who loses any one of them
	 * (typical for managed-hosting customer admins) is correctly
	 * excluded from the dispatcher.
	 *
	 * The `$level` parameter is retained for backward-compatible error
	 * messages; the underlying cap requirement is the same for every
	 * level because the dispatcher itself is the risk surface.
	 *
	 * @param string $level 'read', 'write', or 'destructive' (used in error message only).
	 * @return true|WP_Error
	 */
	private static function check_permission_level( string $level ) {
		$required = array( 'manage_options', 'update_core', 'unfiltered_html' );

		foreach ( $required as $cap ) {
			if ( ! current_user_can( $cap ) ) {
				return new WP_Error(
					'wp_cli_forbidden',
					sprintf(
						/* translators: 1: access level, 2: list of required capability names */
						__( 'You do not have permission to execute this %1$s command. Required capabilities (all): %2$s.', 'superdav-ai-agent' ),
						$level,
						implode( ', ', $required )
					),
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}

	// ─── Native dispatcher ───────────────────────────────────────────────

	/**
	 * Determine whether the WP-CLI-style native dispatcher should be advertised.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		/**
		 * Filter whether the native WP-CLI-style dispatcher is available.
		 *
		 * @param bool $available Whether the dispatcher is available.
		 */
		return (bool) apply_filters( 'sd_ai_agent_wp_cli_dispatcher_available', true );
	}

	/**
	 * Reset legacy binary cache state.
	 *
	 * Retained as a no-op for compatibility with tests and callers that reset
	 * request-local WP-CLI state. Native dispatch no longer resolves a binary.
	 *
	 * @return void
	 */
	public static function reset_binary_cache(): void {
	}

	/**
	 * Dispatch tokenized WP-CLI syntax to native handlers.
	 *
	 * @param string[] $tokens       Tokenized command arguments.
	 * @param string   $command_path Full positional command path for diagnostics.
	 * @return array<mixed>|string|int|bool|WP_Error
	 */
	private static function dispatch_native_command( array $tokens, string $command_path ) {
		$positionals = self::positionals( $tokens );
		if ( count( $positionals ) < 2 ) {
			return self::unsupported_command_error( $command_path );
		}

		$key      = $positionals[0] . ' ' . $positionals[1];
		$registry = self::native_command_registry();
		if ( ! isset( $registry[ $key ] ) ) {
			return self::unsupported_command_error( $command_path );
		}

		switch ( $key ) {
			case 'post list':
				return self::handle_post_list( $tokens, $positionals );
			case 'post create':
				return self::handle_post_create( $tokens, $positionals );
			case 'option get':
				return self::handle_option_get( $tokens, $positionals );
			case 'option list':
				return self::handle_option_list( $tokens, $positionals );
			case 'option update':
				return self::handle_option_update( $tokens, $positionals );
			case 'plugin list':
				return self::handle_plugin_list( $tokens, $positionals );
			case 'user list':
				return self::handle_user_list( $tokens, $positionals );
			case 'site list':
				return self::handle_site_list( $tokens, $positionals );
		}

		return self::unsupported_command_error( $command_path );
	}

	/**
	 * Native handler registry keyed by two-token WP-CLI command path.
	 *
	 * @return array<string,string>
	 */
	private static function native_command_registry(): array {
		return array(
			'post list'     => 'handle_post_list',
			'post create'   => 'handle_post_create',
			'option get'    => 'handle_option_get',
			'option list'   => 'handle_option_list',
			'option update' => 'handle_option_update',
			'plugin list'   => 'handle_plugin_list',
			'user list'     => 'handle_user_list',
			'site list'     => 'handle_site_list',
		);
	}

	/**
	 * Return non-flag command tokens.
	 *
	 * @param string[] $tokens Tokenized command arguments.
	 * @return string[]
	 */
	private static function positionals( array $tokens ): array {
		$positionals = array();
		foreach ( $tokens as $token ) {
			if ( ! str_starts_with( $token, '-' ) ) {
				$positionals[] = $token;
			}
		}
		return $positionals;
	}

	/**
	 * Parse WP-CLI style flags into an associative array.
	 *
	 * @param string[] $tokens Tokenized command arguments.
	 * @return array<string,string|bool>
	 */
	private static function assoc_args( array $tokens ): array {
		$args = array();
		foreach ( $tokens as $index => $token ) {
			if ( ! str_starts_with( $token, '--' ) ) {
				continue;
			}

			$raw = substr( $token, 2 );
			if ( str_contains( $raw, '=' ) ) {
				list( $name, $value ) = explode( '=', $raw, 2 );
				$args[ $name ]        = $value;
				continue;
			}

			$next = $tokens[ $index + 1 ] ?? '';
			if ( '' !== $next && ! str_starts_with( $next, '-' ) ) {
				$args[ $raw ] = $next;
			} else {
				$args[ $raw ] = true;
			}
		}
		return $args;
	}

	/**
	 * Build a structured unsupported-command error.
	 *
	 * @param string $command_path Command path that was requested.
	 * @return WP_Error
	 */
	private static function unsupported_command_error( string $command_path ): WP_Error {
		$suggestions = array(
			'post list'     => 'sd-ai-agent/list-posts',
			'post create'   => 'sd-ai-agent/create-post',
			'option get'    => 'sd-ai-agent/get-option',
			'option list'   => 'sd-ai-agent/list-options',
			'option update' => 'sd-ai-agent/update-option',
			'plugin list'   => 'sd-ai-agent/plugin-status',
			'user list'     => 'sd-ai-agent/list-users',
			'site list'     => 'sd-ai-agent/site-info',
		);

		$hint = '';
		foreach ( $suggestions as $path => $ability ) {
			if ( $command_path === $path || str_starts_with( $command_path, $path . ' ' ) ) {
				$hint = $ability;
				break;
			}
		}

		return new WP_Error(
			'unsupported_command',
			sprintf(
				/* translators: %s: command path */
				__( 'The WP-CLI-style command "%s" is not implemented by the native dispatcher.', 'superdav-ai-agent' ),
				$command_path
			),
			array(
				'status'                  => 501,
				'command_path'            => $command_path,
				'suggested_ability'       => $hint,
				'implemented_command_set' => array_keys( self::native_command_registry() ),
			)
		);
	}

	/**
	 * Handle `wp option get`.
	 *
	 * @param string[] $tokens       Tokenized command arguments.
	 * @param string[] $positionals  Positional command arguments.
	 * @return mixed|WP_Error
	 */
	private static function handle_option_get( array $tokens, array $positionals ) {
		unset( $tokens );
		$option_name = $positionals[2] ?? '';
		if ( '' === $option_name ) {
			return self::usage_error( 'option get', 'option get <name>' );
		}
		if ( OptionsAbilities::is_secret_option_name( $option_name ) ) {
			return OptionsAbilities::secret_read_error( $option_name );
		}
		return get_option( $option_name );
	}

	/**
	 * Handle `wp option update`.
	 *
	 * @param string[] $tokens       Tokenized command arguments.
	 * @param string[] $positionals  Positional command arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function handle_option_update( array $tokens, array $positionals ) {
		unset( $tokens );
		$option_name = $positionals[2] ?? '';
		if ( '' === $option_name || ! array_key_exists( 3, $positionals ) ) {
			return self::usage_error( 'option update', 'option update <name> <value>' );
		}
		$value = implode( ' ', array_slice( $positionals, 3 ) );
		$ok    = update_option( $option_name, $value );
		return array(
			'option_name' => $option_name,
			'value'       => $value,
			'updated'     => (bool) $ok,
		);
	}

	/**
	 * Handle `wp option list`.
	 *
	 * @param string[] $tokens       Tokenized command arguments.
	 * @param string[] $positionals  Positional command arguments.
	 * @return array<int,array<string,mixed>>
	 */
	private static function handle_option_list( array $tokens, array $positionals ): array {
		unset( $positionals );
		global $wpdb;

		$args   = self::assoc_args( $tokens );
		$search = isset( $args['search'] ) && is_string( $args['search'] ) ? $args['search'] : '';
		$limit  = isset( $args['limit'] ) && is_numeric( $args['limit'] ) ? max( 1, min( 500, (int) $args['limit'] ) ) : 200;
		$table  = (string) $wpdb->options;

		if ( '' !== $search ) {
			$like = str_replace( '*', '%', $search );
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is the trusted wp_options table name from wpdb.
					"SELECT option_name, option_value, autoload FROM {$table} WHERE option_name LIKE %s ORDER BY option_name ASC LIMIT %d",
					$like,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is the trusted wp_options table name from wpdb.
					"SELECT option_name, option_value, autoload FROM {$table} ORDER BY option_name ASC LIMIT %d",
					$limit
				),
				ARRAY_A
			);
		}

		$output = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( is_array( $row ) ) {
					$output[] = array(
						'option_name'  => $row['option_name'] ?? '',
						'option_value' => $row['option_value'] ?? '',
						'autoload'     => $row['autoload'] ?? '',
					);
				}
			}
		}

		return $output;
	}

	/**
	 * Handle `wp post list`.
	 *
	 * @param string[] $tokens       Tokenized command arguments.
	 * @param string[] $positionals  Positional command arguments.
	 * @return array<int,array<string,mixed>>
	 */
	private static function handle_post_list( array $tokens, array $positionals ): array {
		unset( $positionals );
		$args       = self::assoc_args( $tokens );
		$post_type  = isset( $args['post_type'] ) && is_string( $args['post_type'] ) ? $args['post_type'] : 'post';
		$post_state = isset( $args['post_status'] ) && is_string( $args['post_status'] ) ? $args['post_status'] : 'any';
		$number     = isset( $args['posts_per_page'] ) && is_numeric( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 100;
		$number     = isset( $args['number'] ) && is_numeric( $args['number'] ) ? (int) $args['number'] : $number;

		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => $post_state,
				'posts_per_page' => max( 1, min( 500, $number ) ),
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);

		$rows = array();
		foreach ( $posts as $post ) {
			$rows[] = array(
				'ID'          => (int) $post->ID,
				'post_title'  => $post->post_title,
				'post_name'   => $post->post_name,
				'post_date'   => $post->post_date,
				'post_status' => $post->post_status,
				'post_type'   => $post->post_type,
			);
		}
		return $rows;
	}

	/**
	 * Handle `wp post create`.
	 *
	 * @param string[] $tokens       Tokenized command arguments.
	 * @param string[] $positionals  Positional command arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function handle_post_create( array $tokens, array $positionals ) {
		unset( $positionals );
		$args = self::assoc_args( $tokens );

		$postarr = array(
			'post_author'  => get_current_user_id(),
			'post_title'   => isset( $args['post_title'] ) && is_string( $args['post_title'] ) ? $args['post_title'] : '',
			'post_content' => isset( $args['post_content'] ) && is_string( $args['post_content'] ) ? $args['post_content'] : '',
			'post_status'  => isset( $args['post_status'] ) && is_string( $args['post_status'] ) ? $args['post_status'] : 'draft',
			'post_type'    => isset( $args['post_type'] ) && is_string( $args['post_type'] ) ? $args['post_type'] : 'post',
		);

		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return array(
			'ID'          => (int) $post_id,
			'post_title'  => $postarr['post_title'],
			'post_status' => $postarr['post_status'],
			'post_type'   => $postarr['post_type'],
		);
	}

	/**
	 * Handle `wp plugin list`.
	 *
	 * @param string[] $tokens       Tokenized command arguments.
	 * @param string[] $positionals  Positional command arguments.
	 * @return array<int,array<string,mixed>>
	 */
	private static function handle_plugin_list( array $tokens, array $positionals ): array {
		unset( $positionals );
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$args   = self::assoc_args( $tokens );
		$status = isset( $args['status'] ) && is_string( $args['status'] ) ? $args['status'] : '';
		$rows   = array();
		foreach ( get_plugins() as $file => $data ) {
			$is_active = is_plugin_active( $file );
			$row       = array(
				'name'    => $file,
				'title'   => $data['Name'] ?? '',
				'status'  => $is_active ? 'active' : 'inactive',
				'version' => $data['Version'] ?? '',
			);
			if ( '' !== $status && $status !== $row['status'] ) {
				continue;
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Handle `wp user list`.
	 *
	 * @param string[] $tokens       Tokenized command arguments.
	 * @param string[] $positionals  Positional command arguments.
	 * @return array<int,array<string,mixed>>
	 */
	private static function handle_user_list( array $tokens, array $positionals ): array {
		unset( $positionals );
		$args  = self::assoc_args( $tokens );
		$query = array(
			'number' => isset( $args['number'] ) && is_numeric( $args['number'] ) ? max( 1, min( 500, (int) $args['number'] ) ) : 100,
		);
		if ( isset( $args['role'] ) && is_string( $args['role'] ) ) {
			$query['role'] = $args['role'];
		}

		$rows = array();
		foreach ( get_users( $query ) as $user ) {
			if ( ! $user instanceof \WP_User ) {
				continue;
			}
			$rows[] = array(
				'ID'           => (int) $user->ID,
				'user_login'   => $user->user_login,
				'display_name' => $user->display_name,
				'user_email'   => $user->user_email,
				'roles'        => array_values( (array) $user->roles ),
			);
		}
		return $rows;
	}

	/**
	 * Handle `wp site list`.
	 *
	 * @param string[] $tokens       Tokenized command arguments.
	 * @param string[] $positionals  Positional command arguments.
	 * @return array<int,array<string,mixed>>
	 */
	private static function handle_site_list( array $tokens, array $positionals ): array {
		unset( $tokens, $positionals );
		if ( ! is_multisite() ) {
			return array(
				array(
					'blog_id' => get_current_blog_id(),
					'url'     => home_url( '/' ),
				),
			);
		}

		$rows = array();
		foreach ( get_sites( array( 'number' => 500 ) ) as $site ) {
			$rows[] = array(
				'blog_id' => (int) $site->blog_id,
				'url'     => get_site_url( (int) $site->blog_id, '/' ),
				'domain'  => $site->domain,
				'path'    => $site->path,
			);
		}
		return $rows;
	}

	/**
	 * Build a WP-CLI-style usage error.
	 *
	 * @param string $command_path Command path.
	 * @param string $usage        Expected usage.
	 * @return WP_Error
	 */
	private static function usage_error( string $command_path, string $usage ): WP_Error {
		return new WP_Error(
			'wp_cli_usage_error',
			sprintf(
				/* translators: 1: command path, 2: usage string */
				__( 'Wrong arguments for "%1$s". Usage: wp %2$s.', 'superdav-ai-agent' ),
				$command_path,
				$usage
			),
			array( 'status' => 400 )
		);
	}

	// ─── Secret-aware option subcommand gating ──────────────────────────

	/**
	 * Subcommands that take an option name as their first non-flag positional
	 * argument and would therefore expose / mutate a secret if not gated.
	 *
	 * Index value is a classification used by {@see check_secret_option_subcommand()}:
	 *   - 'read'  → name must not be on the secret read blocklist.
	 *   - 'write' → name must pass the default-deny write allowlist policy.
	 *
	 * @var array<string,string>
	 */
	private const SECRET_AWARE_OPTION_SUBCOMMANDS = array(
		'option get'          => 'read',
		'option pluck'        => 'read',
		'option get-autoload' => 'read',
		'option update'       => 'write',
		'option set'          => 'write', // alias for update in some WP-CLI versions.
		'option add'          => 'write',
		'option delete'       => 'write',
		'option patch'        => 'write',
	);

	/**
	 * Reject `wp option get/pluck/update/delete <secret-or-unallowlisted>` before execution.
	 *
	 * {@see extract_command_path()} stops at the first flag but consumes the
	 * option name into the positional path (so `option get auth_key` becomes
	 * the full command_path string). We therefore derive the 2-token
	 * subcommand prefix and the option-name argument directly from the
	 * tokenised input, not from the joined command_path.
	 *
	 * Returns null when the command is allowed, a WP_Error when blocked.
	 *
	 * @param string   $command_path The space-joined positional prefix (unused
	 *                               for the lookup; kept for caller symmetry).
	 * @param string[] $tokens       Full tokenised command (includes flags).
	 * @return WP_Error|null
	 */
	private static function check_secret_option_subcommand( string $command_path, array $tokens ): ?WP_Error {
		unset( $command_path );

		// Collect non-flag positionals; the first two form the "<top>
		// <verb>" prefix and the third (if any) is the option name.
		$positionals = array();
		foreach ( $tokens as $token ) {
			if ( ! str_starts_with( $token, '-' ) ) {
				$positionals[] = $token;
			}
		}

		if ( count( $positionals ) < 2 ) {
			return null;
		}

		$prefix = $positionals[0] . ' ' . $positionals[1];
		if ( ! isset( self::SECRET_AWARE_OPTION_SUBCOMMANDS[ $prefix ] ) ) {
			return null;
		}

		$mode        = self::SECRET_AWARE_OPTION_SUBCOMMANDS[ $prefix ];
		$option_name = $positionals[2] ?? '';
		if ( '' === $option_name ) {
			// No name yet — let WP-CLI surface the usage error.
			return null;
		}

		if ( 'read' === $mode && OptionsAbilities::is_secret_option_name( $option_name ) ) {
			return new WP_Error(
				'wp_cli_option_secret_redacted',
				sprintf(
					/* translators: 1: WP-CLI subcommand, 2: option name */
					__( 'The WP-CLI command "wp %1$s %2$s" would read an authentication secret and is blocked.', 'superdav-ai-agent' ),
					$prefix,
					$option_name
				),
				array( 'status' => 403 )
			);
		}

		if ( 'write' === $mode ) {
			if ( in_array( $option_name, OptionsAbilities::get_write_blocklist(), true ) ) {
				return new WP_Error(
					'wp_cli_option_protected',
					sprintf(
						/* translators: 1: WP-CLI subcommand, 2: option name */
						__( 'The WP-CLI command "wp %1$s %2$s" targets a protected option and is blocked.', 'superdav-ai-agent' ),
						$prefix,
						$option_name
					),
					array( 'status' => 403 )
				);
			}

			if ( ! OptionsAbilities::is_write_allowed_option( $option_name ) ) {
				return new WP_Error(
					'wp_cli_option_not_allowed',
					sprintf(
						/* translators: 1: WP-CLI subcommand, 2: option name */
						__( 'The WP-CLI command "wp %1$s %2$s" targets an unallowlisted option and is blocked.', 'superdav-ai-agent' ),
						$prefix,
						$option_name
					),
					array( 'status' => 403 )
				);
			}
		}

		return null;
	}

	/**
	 * Scrub secret option values out of WP-CLI subcommand output.
	 *
	 * Handles the structured `--format=json` case (already decoded into an
	 * array by {@see run_process()}) and the unstructured fallback (raw
	 * trimmed stdout string).
	 *
	 * @param string              $command_path The space-joined positional prefix.
	 * @param array<mixed>|string $result       The decoded or raw command result.
	 * @return array<mixed>|string
	 */
	public static function scrub_secret_output( string $command_path, $result ) {
		if ( ! str_starts_with( $command_path, 'option list' ) && 'option' !== $command_path ) {
			return $result;
		}

		$secrets = OptionsAbilities::get_secret_read_blocklist();
		if ( empty( $secrets ) ) {
			return $result;
		}

		// Structured JSON output: list of associative arrays keyed by
		// option_name. Redact value, keep the row visible so the caller
		// learns the option exists.
		if ( is_array( $result ) ) {
			foreach ( $result as $index => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$name = isset( $row['option_name'] ) && is_string( $row['option_name'] )
					? $row['option_name']
					: '';
				if ( '' !== $name && in_array( $name, $secrets, true ) ) {
					if ( array_key_exists( 'option_value', $row ) ) {
						$result[ $index ]['option_value'] = OptionsAbilities::SECRET_REDACTED_PLACEHOLDER;
					}
					if ( array_key_exists( 'value', $row ) ) {
						$result[ $index ]['value'] = OptionsAbilities::SECRET_REDACTED_PLACEHOLDER;
					}
				}
			}
			return $result;
		}

		// Unstructured text output (table/csv/tsv/yaml/etc.). Redact each
		// physical line that begins with a secret option name followed by
		// a separator. Cheap and format-agnostic.
		if ( is_string( $result ) ) {
			$pattern = '/^(' . implode( '|', array_map( 'preg_quote', $secrets ) ) . ')(\s|\t|,|:)(.*)$/m';
			return (string) preg_replace_callback(
				$pattern,
				static function ( array $m ): string {
					return $m[1] . $m[2] . OptionsAbilities::SECRET_REDACTED_PLACEHOLDER;
				},
				$result
			);
		}

		return $result;
	}
}
