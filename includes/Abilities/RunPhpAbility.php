<?php

declare(strict_types=1);
/**
 * Low-level whitelisted WordPress-function dispatcher.
 *
 * This file is physically removed from the WordPress.org distribution build
 * (`bin/build.sh --target=wporg`) and the corresponding
 * `SD_AI_AGENT_FEATURE_RUN_PHP` constant is forced to `false` so the gated
 * registration in `WordPressAbilities::register_abilities()` becomes a no-op
 * and the class is never autoloaded. Both belt-and-braces guards exist
 * because WP.org Guideline 4 prohibits plugins that allow arbitrary script
 * insertion or low-level PHP dispatch — even when guarded by an allowlist.
 *
 * The full-feature GitHub release build retains this file because it is the
 * documented low-level fallback for self-hosted users when no purpose-built
 * ability matches a task.
 *
 * @package SdAiAgent
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Call a whitelisted WordPress function by name with arguments.
 *
 * Replaces the former eval-based dispatcher with a safe, whitelisted
 * approach that only allows calling pre-approved WordPress functions via
 * `call_user_func_array()`. Execution is gated by
 * `ToolCapabilities::current_user_can()`, which enforces both the
 * per-tool capability (`sd_ai_agent_tool_run_php`) and the
 * `CORE_CAP_MAP` entry for `sd-ai-agent/run-php` — the strictest gate
 * in the plugin (`manage_options` + `update_core` + `unfiltered_html`)
 * so even a misconfigured role-management plugin cannot lower the bar.
 *
 * @since 1.1.0
 */
class RunPhpAbility extends AbstractAbility {

	/**
	 * Option-reading functions whose first argument is an option/transient
	 * name. Any call into these functions is gated against the secret
	 * read blocklist defined in {@see OptionsAbilities::get_secret_read_blocklist()}
	 * so a caller cannot bypass {@see GetOptionAbility} via this low-level
	 * function caller.
	 *
	 * Transient names share the auth-key/salt naming space when stored as
	 * options ({@see WP_Object_Cache}); gating them here is defence-in-depth.
	 *
	 * @var string[]
	 */
	private const OPTION_READ_FUNCTIONS = [
		'get_option',
		'get_site_option',
		'get_network_option',
		'get_transient',
		'get_site_transient',
	];

	/**
	 * Option-mutating functions whose first argument is an option name.
	 * Calls are gated against {@see OptionsAbilities::get_write_blocklist()}
	 * so the agent cannot regenerate auth keys/salts (which would log out
	 * every user) by routing around {@see UpdateOptionAbility}.
	 *
	 * @var string[]
	 */
	private const OPTION_WRITE_FUNCTIONS = [
		'update_option',
		'delete_option',
		'update_site_option',
		'delete_site_option',
		'update_network_option',
		'delete_network_option',
	];

	/**
	 * Allowed WordPress functions that the AI agent may call.
	 *
	 * Only side-effect-free read functions and common write functions with
	 * well-understood behaviour are included. Extend via the
	 * `sd_ai_agent_allowed_wp_functions` filter.
	 *
	 * @var string[]
	 */
	private const ALLOWED_FUNCTIONS = [
		// Options.
		'get_option',
		'update_option',
		'delete_option',
		// Posts / Pages.
		'get_post',
		'get_posts',
		'wp_insert_post',
		'wp_update_post',
		'wp_delete_post',
		'get_post_meta',
		'update_post_meta',
		'delete_post_meta',
		// Terms / Taxonomies.
		'get_terms',
		'get_term',
		'wp_insert_term',
		'wp_update_term',
		'wp_delete_term',
		'wp_set_post_terms',
		'wp_get_post_terms',
		// Users.
		'get_user_by',
		'get_users',
		'get_current_user_id',
		'get_user_meta',
		'update_user_meta',
		// Comments.
		'get_comments',
		'get_comment',
		'wp_insert_comment',
		'wp_update_comment',
		'wp_delete_comment',
		// Queries.
		'wp_count_posts',
		'wp_count_terms',
		'count_users',
		// Transients.
		'get_transient',
		'set_transient',
		'delete_transient',
		// Site info.
		'get_bloginfo',
		'home_url',
		'site_url',
		'admin_url',
		'wp_upload_dir',
		'is_multisite',
		// Plugins / Themes.
		'get_plugins',
		'is_plugin_active',
		'wp_get_theme',
		'wp_get_themes',
		// Shortcodes.
		'do_shortcode',
		'shortcode_exists',
		// Menus.
		'wp_get_nav_menus',
		'wp_get_nav_menu_items',
		// Misc.
		'wp_remote_get',
		'wp_remote_post',
		'current_time',
		'wp_date',
		'wp_create_nonce',
	];

	protected function label(): string {
		return __( 'Call WordPress Function', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Low-level fallback: call a whitelisted WordPress function directly. Use ONLY when no dedicated ability exists for the task. For posts (use `sd-ai-agent/create-post`), users, options, plugins, themes, and other common operations, call `sd-ai-agent/ability-search` first to find a purpose-built tool — dedicated abilities have typed schemas and better error recovery than guessing positional args here. When you do use this, pass the function name via `function` and an ordered array via `args`.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'function' => [
					'type'        => 'string',
					'description' => 'The WordPress function name to call, e.g. "get_option", "wp_insert_post".',
				],
				'args'     => [
					'type'        => 'array',
					'description' => 'Ordered array of arguments to pass to the function. Defaults to an empty array.',
					'items'       => array(
						'type' => array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ),
					),
				],
			],
			'required'   => [ 'function' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'result' => [
					'type'        => [ 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ],
					'description' => 'The function return value. Type varies by function.',
				],
				'output' => [ 'type' => 'string' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$function = $input['function'] ?? '';
		$args     = $input['args'] ?? [];

		if ( empty( $function ) || ! is_string( $function ) ) {
			return new WP_Error(
				'sd_ai_agent_empty_function',
				__( 'A function name is required.', 'superdav-ai-agent' )
			);
		}

		if ( ! is_array( $args ) ) {
			return new WP_Error(
				'sd_ai_agent_invalid_args',
				__( 'The "args" parameter must be an array.', 'superdav-ai-agent' )
			);
		}

		// Build the runtime allowlist (static defaults + user extensions).
		$allowed = self::get_allowed_functions();

		if ( ! in_array( $function, $allowed, true ) ) {
			return new WP_Error(
				'sd_ai_agent_disallowed_function',
				sprintf(
					/* translators: %s: function name */
					__( 'The function "%s" is not in the allowed list. Use the sd_ai_agent_allowed_wp_functions filter to extend it.', 'superdav-ai-agent' ),
					$function
				)
			);
		}

		// Secret-aware gating: option reads/writes against the auth-key
		// blocklist. Applied AFTER the function allowlist check so any
		// extension via `sd_ai_agent_allowed_wp_functions` is still
		// constrained. Network/site variants of get_option take the option
		// name in arg position 1 (network ID is arg 0); standard variants
		// take it in arg 0. Both shapes are covered below.
		$secret_check = self::check_secret_option_call( $function, $args );
		if ( is_wp_error( $secret_check ) ) {
			return $secret_check;
		}

		if ( ! function_exists( $function ) ) {
			return new WP_Error(
				'sd_ai_agent_undefined_function',
				sprintf(
					/* translators: %s: function name */
					__( 'The function "%s" does not exist in this WordPress environment.', 'superdav-ai-agent' ),
					$function
				)
			);
		}

		ob_start();
		$error  = null;
		$result = null;

		try {
			$result = call_user_func_array( $function, array_values( $args ) );
		} catch ( \Throwable $e ) {
			$error = $e->getMessage();
		}

		$output = ob_get_clean();

		if ( null !== $error ) {
			return new WP_Error(
				'sd_ai_agent_php_error',
				sprintf( 'PHP error: %s', $error )
			);
		}

		return [
			'result' => $result,
			'output' => $output,
		];
	}

	/**
	 * Get the full list of allowed functions (built-in + filtered).
	 *
	 * @return string[]
	 */
	private static function get_allowed_functions(): array {
		/**
		 * Filters the list of WordPress functions the AI agent is allowed to call.
		 *
		 * @since 1.1.0
		 *
		 * @param string[] $functions List of allowed function names.
		 */
		$functions = apply_filters( 'sd_ai_agent_allowed_wp_functions', self::ALLOWED_FUNCTIONS );

		// Ensure the list is a flat array of strings (defensive).
		return array_values( array_filter( (array) $functions, 'is_string' ) );
	}

	/**
	 * Gate option-reading / option-writing function calls against the
	 * secret read blocklist and the existing write blocklist.
	 *
	 * Without this check, the AI could bypass {@see GetOptionAbility}'s
	 * secret-read gate (and {@see UpdateOptionAbility}'s write gate) by
	 * calling `get_option('auth_key')` (or `update_option('auth_key', ...)`)
	 * through this low-level function caller.
	 *
	 * Returns null when the call is allowed, a WP_Error when blocked.
	 *
	 * @param string       $function_name The PHP function name about to be called.
	 * @param array<mixed> $args          Positional arguments that will be passed.
	 * @return \WP_Error|null
	 */
	private static function check_secret_option_call( string $function_name, array $args ): ?\WP_Error {
		// Network/site option variants accept (int $network_id, string $option, ...);
		// standard variants accept (string $option, ...). Pick the right arg index.
		$is_network_variant = in_array(
			$function_name,
			[ 'get_network_option', 'update_network_option', 'delete_network_option' ],
			true
		);
		$name_index         = $is_network_variant ? 1 : 0;

		if ( ! isset( $args[ $name_index ] ) || ! is_string( $args[ $name_index ] ) ) {
			return null;
		}

		$option_name = $args[ $name_index ];

		if ( in_array( $function_name, self::OPTION_READ_FUNCTIONS, true )
			&& OptionsAbilities::is_secret_option_name( $option_name ) ) {
			return OptionsAbilities::secret_read_error( $option_name );
		}

		if ( in_array( $function_name, self::OPTION_WRITE_FUNCTIONS, true )
			&& in_array( $option_name, OptionsAbilities::get_write_blocklist(), true ) ) {
			return new \WP_Error(
				'sd_ai_agent_option_blocked',
				sprintf(
					/* translators: 1: function name, 2: option name */
					__( 'The function "%1$s" cannot be used to modify the protected option "%2$s".', 'superdav-ai-agent' ),
					$function_name,
					$option_name
				),
				[ 'status' => 403 ]
			);
		}

		return null;
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			],
			'show_in_rest' => true,
		];
	}
}
