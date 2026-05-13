<?php

declare(strict_types=1);
/**
 * WP-CLI ability for the AI agent.
 *
 * Registers a single `wp-cli/execute` ability that accepts raw WP-CLI
 * command strings. This is the natural interface for any LLM — pass
 * commands exactly as you would type them in a terminal.
 *
 * Security layers:
 *   1. Top-level command blocklist (db, eval, shell, config, core, …)
 *   2. Sub-command blocklist (site delete, plugin install, …)
 *   3. Permission classification (read → manage_options, write → manage_options,
 *      destructive → manage_network)
 *   4. Array-based proc_open (no shell interpretation — no injection risk)
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Core\ChangeLogger;
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
	 * Current site URL for multisite context persistence.
	 *
	 * @var string
	 */
	private static string $current_site_url = '';

	/**
	 * Register the wp-cli ability category.
	 *
	 * @return void
	 */
	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) || ! function_exists( 'wp_has_ability_category' ) ) {
			return;
		}

		if ( wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'WP-CLI', 'superdav-ai-agent' ),
				'description' => __( 'Execute WP-CLI commands on this WordPress installation.', 'superdav-ai-agent' ),
			)
		);
	}

	/**
	 * Register the wp-cli/execute ability.
	 *
	 * @return void
	 */
	public static function register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$description = implode(
			"\n",
			array(
				'Execute any WP-CLI command and return the output.',
				'Pass commands exactly as you would type them in a terminal, without the "wp" prefix.',
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
				'- Some dangerous commands are blocked: db, eval, shell, config, core, search-replace, scaffold.',
			)
		);

		wp_register_ability(
			self::CATEGORY . '/execute',
			array(
				'label'               => __( 'Execute WP-CLI Command', 'superdav-ai-agent' ),
				'description'         => $description,
				'category'            => self::CATEGORY,
				'permission_callback' => static function () {
					if ( current_user_can( 'manage_network' ) ) {
						return true;
					}
					if ( current_user_can( 'manage_options' ) ) {
						return true;
					}
					return new WP_Error(
						'wp_cli_forbidden',
						__( 'You do not have permission to execute WP-CLI commands. Required capability: manage_options.', 'superdav-ai-agent' ),
						array( 'status' => 403 )
					);
				},
				'execute_callback'    => array( __CLASS__, 'handle_execute' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'command' => array(
							'type'        => 'string',
							'description' => 'The WP-CLI command to execute, without the "wp" prefix. Example: "post list --post_type=page --format=json"',
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
	 * @return array<mixed>|string|WP_Error
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
	 * @return array<mixed>|string|WP_Error Parsed JSON, raw output, or error.
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

		// Permission check based on command classification.
		$level      = self::classify_command( $command_path );
		$perm_check = self::check_permission_level( $level );

		if ( is_wp_error( $perm_check ) ) {
			return $perm_check;
		}

		if ( ! self::is_proc_open_available() ) {
			return new WP_Error(
				'proc_open_unavailable',
				__( 'WP-CLI execution is unavailable because PHP proc_open() is disabled on this host. Use the REST, posts, options, media, or other WordPress abilities instead.', 'superdav-ai-agent' ),
				array( 'status' => 501 )
			);
		}

		// Find WP-CLI binary.
		$wp_binary = self::find_wp_cli();

		if ( is_wp_error( $wp_binary ) ) {
			return $wp_binary;
		}

		// Track --url if explicitly provided (multisite context persistence).
		foreach ( $tokens as $token ) {
			if ( preg_match( '/^--url=(.+)$/', $token, $m ) ) {
				self::$current_site_url = $m[1];
			}
		}

		// Build the process argument array.
		$proc_args = array( $wp_binary );
		$proc_args = array_merge( $proc_args, $tokens );

		if ( ! self::tokens_have_flag( $tokens, '--path' ) ) {
			$proc_args[] = '--path=' . ABSPATH;
		}

		if ( ! self::tokens_have_flag( $tokens, '--url' ) && is_multisite() ) {
			$target_url  = self::$current_site_url !== '' ? self::$current_site_url : network_site_url();
			$proc_args[] = '--url=' . $target_url;
		}

		if ( ! self::tokens_have_flag( $tokens, '--user' ) ) {
			$current_user_id = get_current_user_id();
			if ( $current_user_id > 0 ) {
				$proc_args[] = '--user=' . (string) $current_user_id;
			}
		}

		if ( ! self::tokens_have_flag( $tokens, '--no-color' ) ) {
			$proc_args[] = '--no-color';
		}

		/** @var list<string> $proc_args */
		$result = self::run_process( $proc_args, $command_path );

		// Auto-set current site context after site creation.
		if ( str_starts_with( $command_path, 'site create' ) && ! is_wp_error( $result ) ) {
			$url = self::extract_url_from_output( $result );
			if ( '' !== $url ) {
				self::$current_site_url = $url;
			}
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
	 * Check if the current user has permission for a given access level.
	 *
	 * @param string $level 'read', 'write', or 'destructive'.
	 * @return true|WP_Error
	 */
	private static function check_permission_level( string $level ) {
		if ( current_user_can( 'manage_network' ) ) {
			return true;
		}

		$capability_map = array(
			'read'        => 'manage_options',
			'write'       => 'manage_options',
			'destructive' => 'manage_network',
		);

		$required_cap = $capability_map[ $level ] ?? 'manage_network';

		if ( current_user_can( $required_cap ) ) {
			return true;
		}

		return new WP_Error(
			'wp_cli_forbidden',
			sprintf(
				/* translators: 1: access level, 2: capability name */
				__( 'You do not have permission to execute this %1$s command. Required capability: %2$s.', 'superdav-ai-agent' ),
				$level,
				$required_cap
			),
			array( 'status' => 403 )
		);
	}

	// ─── Process execution ──────────────────────────────────────────────

	/**
	 * Check if a flag is present in the tokens.
	 *
	 * @param string[] $tokens Tokenized arguments.
	 * @param string   $flag   The flag to check.
	 * @return bool
	 */
	private static function tokens_have_flag( array $tokens, string $flag ): bool {
		foreach ( $tokens as $token ) {
			if ( $token === $flag || str_starts_with( $token, $flag . '=' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Determine whether PHP can spawn a WP-CLI subprocess.
	 *
	 * @return bool
	 */
	private static function is_proc_open_available(): bool {
		$available = function_exists( 'proc_open' );

		/**
		 * Filter whether the WP-CLI ability may use proc_open().
		 *
		 * Primarily useful for tests and hosts that wrap proc_open availability.
		 *
		 * @param bool $available Whether proc_open() is available.
		 */
		return (bool) apply_filters( 'sd_ai_agent_wp_cli_proc_open_available', $available );
	}

	/**
	 * Find the WP-CLI binary path.
	 *
	 * @return string|WP_Error
	 */
	private static function find_wp_cli() {
		/**
		 * Filter the WP-CLI binary path.
		 *
		 * @param string $path Path to the WP-CLI binary.
		 */
		$path = (string) apply_filters( 'sd_ai_agent_wp_cli_binary', '' );

		if ( '' !== $path && is_executable( $path ) ) {
			return $path;
		}

		$candidates = array(
			'/usr/local/bin/wp',
			'/usr/bin/wp',
			ABSPATH . 'wp-cli.phar',
		);

		$home = getenv( 'HOME' );
		if ( is_string( $home ) && '' !== $home ) {
			$candidates[] = $home . '/.local/bin/wp';
		}

		foreach ( $candidates as $candidate ) {
			if ( file_exists( $candidate ) && is_executable( $candidate ) ) {
				return $candidate;
			}
		}

		// Try which.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- shell_exec is required to locate the WP-CLI binary at runtime.
		$which = trim( (string) shell_exec( 'which wp 2>/dev/null' ) );

		if ( '' !== $which && is_executable( $which ) ) {
			return $which;
		}

		return new WP_Error(
			'wp_cli_not_found',
			__( 'WP-CLI binary not found. Install WP-CLI or set the path via the sd_ai_agent_wp_cli_binary filter.', 'superdav-ai-agent' )
		);
	}

	/**
	 * Run a command via array-based proc_open (no shell interpretation).
	 *
	 * @param string[] $args         The command as an array of arguments.
	 * @param string   $command_path The WP-CLI command path for error context.
	 * @return array<mixed>|string|WP_Error
	 */
	private static function run_process( array $args, string $command_path = '' ) {
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		/** @var list<string> $args */
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open,Generic.PHP.ForbiddenFunctions.Found -- proc_open is essential for executing WP-CLI commands via process pipes.
		$process = proc_open( $args, $descriptors, $pipes, ABSPATH );

		if ( ! is_resource( $process ) ) {
			return new WP_Error( 'proc_open_failed', __( 'Failed to execute WP-CLI command.', 'superdav-ai-agent' ) );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing proc_open() process pipes.
		fclose( $pipes[0] );

		$stdout = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );

		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[2] );
		// phpcs:enable

		$exit_code = proc_close( $process );

		if ( 0 !== $exit_code ) {
			$raw_msg = ! empty( $stderr ) ? trim( (string) $stderr ) : "WP-CLI exited with code {$exit_code}";
			$hint    = self::humanize_error( $raw_msg, $command_path );

			return new WP_Error(
				'wp_cli_error',
				$hint,
				array(
					'exit_code' => $exit_code,
					'stderr'    => $stderr,
					'stdout'    => $stdout,
				)
			);
		}

		// Try to parse as JSON for structured responses.
		$decoded = json_decode( (string) $stdout, true );

		if ( JSON_ERROR_NONE === json_last_error() ) {
			return $decoded;
		}

		return trim( (string) $stdout );
	}

	/**
	 * Generate actionable error hints from WP-CLI stderr output.
	 *
	 * @param string $stderr       The raw stderr text.
	 * @param string $command_path The WP-CLI command path for context.
	 * @return string
	 */
	private static function humanize_error( string $stderr, string $command_path = '' ): string {
		$hint = '';

		if ( str_contains( $stderr, 'Invalid JSON:' ) ) {
			$hint = 'Hint: The value was interpreted as JSON. Remove --format or use --format=plaintext for this command.';
		} elseif ( str_contains( $stderr, "isn't a registered" ) || str_contains( $stderr, 'not a registered' ) ) {
			$hint = 'Hint: This WP-CLI command is not available. Check that required plugins are active.';
		} elseif ( preg_match( '/^(usage|Synopsis):/im', $stderr ) ) {
			$hint = 'Hint: Wrong arguments. Run "help ' . $command_path . '" to see the correct usage.';
		}

		if ( '' !== $hint ) {
			return $stderr . "\n" . $hint;
		}

		return $stderr;
	}

	/**
	 * Extract a URL from WP-CLI site create output.
	 *
	 * @param array<mixed>|string $output The command output.
	 * @return string
	 */
	private static function extract_url_from_output( $output ): string {
		$text = is_array( $output ) ? (string) wp_json_encode( $output, JSON_UNESCAPED_SLASHES ) : (string) $output;

		if ( preg_match( '#(https?://[^\s"\'}\]>]+)#i', $text, $matches ) ) {
			return rtrim( $matches[1], '.,;' );
		}

		return '';
	}
}
