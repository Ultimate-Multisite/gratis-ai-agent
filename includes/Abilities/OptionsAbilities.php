<?php

declare(strict_types=1);
/**
 * Options management abilities for the AI agent.
 *
 * Provides get, update, and delete operations for WordPress options. Writes
 * are default-deny: only plugin-owned options and site-allowlisted option
 * names can be modified, and critical core options remain blocklisted.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OptionsAbilities {

	/**
	 * Placeholder substituted for a secret option value in any response that
	 * cannot omit the row entirely (e.g. database query results, WP-CLI
	 * `option list` output).
	 *
	 * Centralised so every read surface — get-option, list-options, db-query,
	 * run-php, wp-cli — uses the same opaque token. Reviewers and automated
	 * tests can grep on this constant.
	 *
	 * @var string
	 */
	public const SECRET_REDACTED_PLACEHOLDER = '[redacted: secret option]';

	/**
	 * Authentication keys, salts, and known credential options that must NEVER
	 * be returned to the AI agent, even when stored in the options table.
	 *
	 * WordPress writes auth keys/salts into `wp_options` when `wp-config.php`
	 * does not define them, and the Connectors API stores provider credentials
	 * as options. A read path that names these rows by string would leak the
	 * values. This list is the single source of truth used by every read surface
	 * in the plugin (get-option, list-options, db-query, run-php with
	 * `get_option`/`get_transient`, and wp-cli `option get`).
	 *
	 * Extend exact names via the `sd_ai_agent_options_read_blocklist` filter.
	 * Open-ended WordPress Connectors API credential names are also blocked by
	 * {@see OptionsAbilities::is_secret_option_name()} using the
	 * `connectors_ai_{provider}_api_key` shape.
	 *
	 * @var string[]
	 */
	private const SECRET_READ_BLOCKLIST = [
		// Cryptographic keys and salts used to sign auth cookies. Leaking
		// any of these enables session forgery / impersonation.
		'auth_key',
		'secure_auth_key',
		'logged_in_key',
		'nonce_key',
		'auth_salt',
		'secure_auth_salt',
		'logged_in_salt',
		'nonce_salt',
		// WordPress 7 Connectors API credentials. Third-party provider IDs that
		// follow the same naming convention are caught by the predicate below.
		'connectors_ai_openai_api_key',
		'connectors_ai_anthropic_api_key',
		'connectors_ai_google_api_key',
		'connectors_ai_sd_ai_agent_cloud_api_key',
	];

	/**
	 * Prefix used by WordPress 7 Connectors API credential option names.
	 */
	private const CONNECTORS_AI_OPTION_PREFIX = 'connectors_ai_';

	/**
	 * Suffix used by WordPress 7 Connectors API credential option names.
	 */
	private const CONNECTORS_AI_OPTION_SUFFIX = '_api_key';

	/**
	 * Options that the AI agent is never allowed to modify or delete.
	 *
	 * These are critical WordPress core options whose corruption would break
	 * the site or compromise security. The list can be extended via the
	 * `sd_ai_agent_options_blocklist` filter.
	 *
	 * @var string[]
	 */
	private const WRITE_BLOCKLIST = [
		// Core site identity / URLs — changing these breaks the site.
		'siteurl',
		'home',
		// Admin contact — changing silently locks out the admin.
		'admin_email',
		// Plugin/theme activation state — must go through the Upgrader API.
		'active_plugins',
		'active_sitewide_plugins',
		'template',
		'stylesheet',
		// Database schema version — must only be changed by upgrade routines.
		'db_version',
		'db_upgraded',
		// WordPress core update channel.
		'auto_update_core_type',
		// User roles — changing breaks capability checks site-wide.
		'user_roles',
		// Cron schedule — corrupting this stops all scheduled events.
		'cron',
		// Auth keys / salts — regenerating these logs out all users.
		'auth_key',
		'secure_auth_key',
		'logged_in_key',
		'nonce_key',
		'auth_salt',
		'secure_auth_salt',
		'logged_in_salt',
		'nonce_salt',
		// WordPress secret keys stored as options (some setups).
		'wp_user_roles',
		// Multisite network options.
		'site_admins',
		'allowedthemes',
		// Plugin/theme file editing gate.
		'disallow_file_edit',
		'disallow_file_mods',
	];

	/**
	 * Exact option names the AI agent may modify by default.
	 *
	 * The default list is intentionally empty. Site owners can opt specific
	 * third-party options into AI write access with the
	 * `sd_ai_agent_options_write_allowlist` filter.
	 *
	 * @var string[]
	 */
	private const WRITE_ALLOWLIST = [];

	/**
	 * Option-name prefixes the AI agent may modify by default.
	 *
	 * Limit default write/delete access to this plugin's own option namespace so
	 * arbitrary WordPress core or third-party options cannot be changed merely
	 * because they were missed by the finite blocklist above.
	 *
	 * @var string[]
	 */
	private const WRITE_ALLOWLIST_PREFIXES = [
		'sd_ai_agent_',
	];

	/**
	 * Exact option names the AI agent may read by default.
	 *
	 * The default list is intentionally empty. Site owners can opt specific
	 * non-secret third-party options into AI read access with the
	 * `sd_ai_agent_options_read_allowlist` filter.
	 *
	 * @var string[]
	 */
	private const READ_ALLOWLIST = [];

	/**
	 * Option-name prefixes the AI agent may read by default.
	 *
	 * Limit default read access to this plugin's own option namespace so arbitrary
	 * third-party options cannot be disclosed merely because they were missed by a
	 * finite secret-name blocklist.
	 *
	 * @var string[]
	 */
	private const READ_ALLOWLIST_PREFIXES = [
		'sd_ai_agent_',
	];

	/**
	 * Register all options management abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/get-option',
			[
				'label'         => __( 'Get Option', 'superdav-ai-agent' ),
				'description'   => __( 'Read a WordPress option by name. Returns the stored value or a default if the option does not exist.', 'superdav-ai-agent' ),
				'ability_class' => GetOptionAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/update-option',
			[
				'label'         => __( 'Update Option', 'superdav-ai-agent' ),
				'description'   => __( 'Create or update an allowed WordPress option. Default write access is limited to plugin-owned options; critical system options remain blocked.', 'superdav-ai-agent' ),
				'ability_class' => UpdateOptionAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/delete-option',
			[
				'label'         => __( 'Delete Option', 'superdav-ai-agent' ),
				'description'   => __( 'Delete an allowed WordPress option by name. Default delete access is limited to plugin-owned options; critical system options remain blocked.', 'superdav-ai-agent' ),
				'ability_class' => DeleteOptionAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/list-options',
			[
				'label'         => __( 'List Options', 'superdav-ai-agent' ),
				'description'   => __( 'List WordPress options with optional prefix filtering. Returns option names and values (truncated for large values). Useful for discovering plugin/theme settings.', 'superdav-ai-agent' ),
				'ability_class' => ListOptionsAbility::class,
			]
		);
	}

	/**
	 * Get the runtime write blocklist (built-in + filtered).
	 *
	 * @return string[]
	 */
	public static function get_write_blocklist(): array {
		/**
		 * Filters the list of WordPress options the AI agent is blocked from writing.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $blocklist List of blocked option names.
		 */
		$blocklist = apply_filters( 'sd_ai_agent_options_blocklist', self::WRITE_BLOCKLIST );

		return array_values( array_filter( (array) $blocklist, 'is_string' ) );
	}

	/**
	 * Get exact option names the AI agent may modify.
	 *
	 * @return string[]
	 */
	public static function get_write_allowlist(): array {
		/**
		 * Filters the exact WordPress option names the AI agent may write/delete.
		 *
		 * Use this only for options that are safe for an AI-assisted administrator
		 * to manage. The write blocklist still takes precedence.
		 *
		 * @since 1.3.0
		 *
		 * @param string[] $allowlist List of allowed option names.
		 */
		$allowlist = apply_filters( 'sd_ai_agent_options_write_allowlist', self::WRITE_ALLOWLIST );

		return array_values( array_filter( (array) $allowlist, 'is_string' ) );
	}

	/**
	 * Get option-name prefixes the AI agent may modify.
	 *
	 * @return string[]
	 */
	public static function get_write_allowlist_prefixes(): array {
		/**
		 * Filters option-name prefixes the AI agent may write/delete.
		 *
		 * The default allows only this plugin's `sd_ai_agent_` options. The write
		 * blocklist still takes precedence over every prefix.
		 *
		 * @since 1.3.0
		 *
		 * @param string[] $prefixes List of allowed option-name prefixes.
		 */
		$prefixes = apply_filters( 'sd_ai_agent_options_write_allowlist_prefixes', self::WRITE_ALLOWLIST_PREFIXES );

		return array_values( array_filter( (array) $prefixes, 'is_string' ) );
	}

	/**
	 * Predicate: may the AI agent modify or delete the given option name?
	 *
	 * The write policy is default-deny. Exact allowlist entries and allowed
	 * prefixes grant access, but the blocklist always takes precedence.
	 *
	 * @param string $option_name Option name to test.
	 * @return bool True if the option is safe for write/delete access.
	 */
	public static function is_write_allowed_option( string $option_name ): bool {
		if ( '' === $option_name ) {
			return false;
		}

		if ( in_array( $option_name, self::get_write_blocklist(), true ) ) {
			return false;
		}

		if ( in_array( $option_name, self::get_write_allowlist(), true ) ) {
			return true;
		}

		foreach ( self::get_write_allowlist_prefixes() as $prefix ) {
			if ( '' !== $prefix && str_starts_with( $option_name, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get exact option names the AI agent may read.
	 *
	 * @return string[]
	 */
	public static function get_read_allowlist(): array {
		/**
		 * Filters exact WordPress option names the AI agent may read.
		 *
		 * Secret option names remain blocked even when added to this allowlist.
		 *
		 * @since 1.16.3
		 *
		 * @param string[] $allowlist List of allowed option names.
		 */
		$allowlist = apply_filters( 'sd_ai_agent_options_read_allowlist', self::READ_ALLOWLIST );

		return array_values( array_filter( (array) $allowlist, 'is_string' ) );
	}

	/**
	 * Get option-name prefixes the AI agent may read.
	 *
	 * @return string[]
	 */
	public static function get_read_allowlist_prefixes(): array {
		/**
		 * Filters option-name prefixes the AI agent may read.
		 *
		 * The default allows only this plugin's `sd_ai_agent_` options. Secret
		 * option names remain blocked even when they match an allowed prefix.
		 *
		 * @since 1.16.3
		 *
		 * @param string[] $prefixes List of allowed option-name prefixes.
		 */
		$prefixes = apply_filters( 'sd_ai_agent_options_read_allowlist_prefixes', self::READ_ALLOWLIST_PREFIXES );

		return array_values( array_filter( (array) $prefixes, 'is_string' ) );
	}

	/**
	 * Predicate: may the AI agent read the given option name?
	 *
	 * The read policy is default-deny except for plugin-owned options and explicit
	 * site-owner allowlist entries. The secret blocklist always takes precedence.
	 *
	 * @param string $option_name Option name to test.
	 * @return bool True if the option is safe for read access.
	 */
	public static function is_read_allowed_option( string $option_name ): bool {
		if ( '' === $option_name || self::is_secret_option_name( $option_name ) ) {
			return false;
		}

		if ( in_array( $option_name, self::get_read_allowlist(), true ) ) {
			return true;
		}

		foreach ( self::get_read_allowlist_prefixes() as $prefix ) {
			if ( '' !== $prefix && str_starts_with( $option_name, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the runtime read blocklist for exact secret option names.
	 *
	 * The list is intentionally narrower than the write blocklist: a few
	 * options (e.g. `siteurl`, `active_plugins`) are write-blocked because
	 * mutating them would break the site, but their values are not secrets
	 * and may legitimately be inspected. Only values whose disclosure would
	 * enable credential misuse, session forgery, or impersonation belong here.
	 *
	 * @return string[]
	 */
	public static function get_secret_read_blocklist(): array {
		/**
		 * Filters the list of WordPress option names whose values must never
		 * be returned by any AI-agent read surface.
		 *
		 * @since 1.3.0
		 *
		 * @param string[] $blocklist List of secret option names.
		 */
		$blocklist = apply_filters( 'sd_ai_agent_options_read_blocklist', self::SECRET_READ_BLOCKLIST );

		return array_values( array_filter( (array) $blocklist, 'is_string' ) );
	}

	/**
	 * Predicate: is the given option name a blocked secret option?
	 *
	 * Exact-name comparisons are case-sensitive — WordPress option names are
	 * case-sensitive at the storage layer. WordPress Connectors API credentials
	 * are additionally blocked by shape so third-party provider key options do
	 * not need to be enumerated one by one.
	 *
	 * @param string $option_name Option name to test.
	 * @return bool True if the name is a known secret.
	 */
	public static function is_secret_option_name( string $option_name ): bool {
		if ( '' === $option_name ) {
			return false;
		}

		if ( in_array( $option_name, self::get_secret_read_blocklist(), true ) ) {
			return true;
		}

		return self::is_connector_api_key_option( $option_name );
	}

	/**
	 * Predicate: does the option name match the WordPress Connectors API key shape?
	 *
	 * WordPress 7 uses `connectors_ai_{provider}_api_key` for provider
	 * credentials. Provider IDs are open-ended, so this shape gate
	 * catches future and third-party connector credential options without adding
	 * brittle option-key allow/deny lists.
	 *
	 * @param string $option_name Option name to test.
	 * @return bool True if the name is a connector credential option.
	 */
	private static function is_connector_api_key_option( string $option_name ): bool {
		return strlen( $option_name ) > strlen( self::CONNECTORS_AI_OPTION_PREFIX ) + strlen( self::CONNECTORS_AI_OPTION_SUFFIX )
			&& str_starts_with( $option_name, self::CONNECTORS_AI_OPTION_PREFIX )
			&& str_ends_with( $option_name, self::CONNECTORS_AI_OPTION_SUFFIX );
	}

	/**
	 * Build a uniform WP_Error for a blocked secret read across surfaces.
	 *
	 * @param string $option_name Option name that was requested.
	 * @return WP_Error
	 */
	public static function secret_read_error( string $option_name ): WP_Error {
		return new WP_Error(
			'sd_ai_agent_option_secret_redacted',
			sprintf(
				/* translators: %s: option name */
				__( 'The option "%s" stores a credential secret and cannot be read by the AI agent.', 'superdav-ai-agent' ),
				$option_name
			),
			array( 'status' => 403 )
		);
	}
}

/**
 * Get Option ability.
 *
 * Reads a single WordPress option by name.
 *
 * @since 1.2.0
 */
class GetOptionAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Get Option', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Read a WordPress option by name. Returns the stored value or a default if the option does not exist.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'option_name' => [
					'type'        => 'string',
					'description' => 'The option name to retrieve (e.g. "blogname", "blogdescription", "posts_per_page").',
				],
				'default'     => [
					'type'        => array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ),
					'description' => 'Value to return if the option does not exist. Defaults to false.',
				],
			],
			'required'   => [ 'option_name' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'option_name' => [ 'type' => 'string' ],
				'value'       => [
					'type'        => [ 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ],
					'description' => 'The option value. Type varies by option.',
				],
				'exists'      => [ 'type' => 'boolean' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$option_name = isset( $input['option_name'] ) ? (string) $input['option_name'] : '';

		if ( '' === $option_name ) {
			return new WP_Error(
				'sd_ai_agent_empty_option_name',
				__( 'The "option_name" parameter is required.', 'superdav-ai-agent' )
			);
		}

		// Default-deny read gate. Secret options are blocked with the uniform
		// redaction error; all other third-party options must be explicitly opted in
		// by site code before their values can be disclosed to the agent.
		if ( OptionsAbilities::is_secret_option_name( $option_name ) ) {
			return OptionsAbilities::secret_read_error( $option_name );
		}

		if ( ! OptionsAbilities::is_read_allowed_option( $option_name ) ) {
			return new WP_Error(
				'sd_ai_agent_option_read_not_allowed',
				sprintf(
					/* translators: %s: option name */
					__( 'The option "%s" is not in the AI agent read allowlist. Only plugin-owned options and options explicitly allowed by site code can be read by this ability.', 'superdav-ai-agent' ),
					$option_name
				),
				[ 'status' => 403 ]
			);
		}

		$default = $input['default'] ?? false;

		// Check whether the option exists before fetching so we can report it.
		$raw    = get_option( $option_name, null );
		$exists = null !== $raw;

		$value = $exists ? $raw : $default;

		return [
			'option_name' => $option_name,
			'value'       => $value,
			'exists'      => $exists,
		];
	}

	protected function permission_callback( $input = null ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}

/**
 * Update Option ability.
 *
 * Creates or updates an allowed WordPress option.
 *
 * @since 1.2.0
 */
class UpdateOptionAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Update Option', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Create or update an allowed WordPress option. Default write access is limited to plugin-owned options; critical system options remain blocked.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'option_name'  => [
					'type'        => 'string',
					'description' => 'The allowed option name to create or update. By default, write access is limited to sd_ai_agent_ options unless site code extends the allowlist.',
				],
				'option_value' => [
					'type'        => array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ),
					'description' => 'The new value to store. Strings, numbers, booleans, arrays, and objects are all supported.',
				],
				'autoload'     => [
					'type'        => 'string',
					'enum'        => [ 'yes', 'no' ],
					'description' => 'Whether to autoload this option on every page load. Use "no" for large or infrequently-accessed options. Defaults to "yes".',
					'default'     => 'yes',
				],
			],
			'required'   => [ 'option_name', 'option_value' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'option_name' => [ 'type' => 'string' ],
				'status'      => [ 'type' => 'string' ],
				'message'     => [ 'type' => 'string' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$option_name = isset( $input['option_name'] ) ? (string) $input['option_name'] : '';

		if ( '' === $option_name ) {
			return new WP_Error(
				'sd_ai_agent_empty_option_name',
				__( 'The "option_name" parameter is required.', 'superdav-ai-agent' )
			);
		}

		// Blocklist check.
		$blocklist = OptionsAbilities::get_write_blocklist();
		if ( in_array( $option_name, $blocklist, true ) ) {
			return new WP_Error(
				'sd_ai_agent_option_blocked',
				sprintf(
					/* translators: %s: option name */
					__( 'The option "%s" is protected and cannot be modified by the AI agent.', 'superdav-ai-agent' ),
					$option_name
				)
			);
		}

		if ( ! OptionsAbilities::is_write_allowed_option( $option_name ) ) {
			return new WP_Error(
				'sd_ai_agent_option_not_allowed',
				sprintf(
					/* translators: %s: option name */
					__( 'The option "%s" is not in the AI agent write allowlist. Only plugin-owned options and options explicitly allowed by site code can be modified by this ability.', 'superdav-ai-agent' ),
					$option_name
				),
				array( 'status' => 403 )
			);
		}

		if ( ! array_key_exists( 'option_value', $input ) ) {
			return new WP_Error(
				'sd_ai_agent_missing_option_value',
				__( 'The "option_value" parameter is required.', 'superdav-ai-agent' )
			);
		}

		$option_value = $input['option_value'];
		// WordPress 7.0+ update_option() accepts bool|null for $autoload.
		// false = do not autoload, true = autoload, null = keep existing setting.
		$autoload = isset( $input['autoload'] ) && 'no' === $input['autoload'] ? false : true;

		$updated = update_option( $option_name, $option_value, $autoload );

		if ( $updated ) {
			return [
				'option_name'  => $option_name,
				'status'       => 'updated',
				'message'      => sprintf(
					/* translators: %s: option name */
					__( 'Option "%s" updated successfully.', 'superdav-ai-agent' ),
					$option_name
				),
				'verification' => [
					'persisted_value' => get_option( $option_name ),
				],
			];
		}

		// update_option() returns false both when the value is unchanged and
		// when the option does not exist yet (add_option path). Distinguish
		// the two cases so the caller gets accurate feedback.
		// Use a sentinel object so options storing literal false are not
		// misdetected as non-existent.
		$sentinel = new \stdClass();
		$exists   = get_option( $option_name, $sentinel ) !== $sentinel;

		if ( $exists ) {
			return [
				'option_name'  => $option_name,
				'status'       => 'unchanged',
				'message'      => sprintf(
					/* translators: %s: option name */
					__( 'Option "%s" already has the requested value — no change made.', 'superdav-ai-agent' ),
					$option_name
				),
				'verification' => [
					'persisted_value' => get_option( $option_name ),
				],
			];
		}

		// Option did not exist and add_option (called internally by update_option) failed.
		return new WP_Error(
			'sd_ai_agent_update_failed',
			sprintf(
				/* translators: %s: option name */
				__( 'Failed to update option "%s".', 'superdav-ai-agent' ),
				$option_name
			)
		);
	}

	protected function permission_callback( $input = null ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}

/**
 * Delete Option ability.
 *
 * Removes an allowed WordPress option.
 *
 * @since 1.2.0
 */
class DeleteOptionAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Delete Option', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Delete an allowed WordPress option by name. Default delete access is limited to plugin-owned options; critical system options remain blocked.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'option_name' => [
					'type'        => 'string',
					'description' => 'The allowed option name to delete. By default, delete access is limited to sd_ai_agent_ options unless site code extends the allowlist.',
				],
			],
			'required'   => [ 'option_name' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'option_name' => [ 'type' => 'string' ],
				'status'      => [ 'type' => 'string' ],
				'message'     => [ 'type' => 'string' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		$option_name = isset( $input['option_name'] ) ? (string) $input['option_name'] : '';

		if ( '' === $option_name ) {
			return new WP_Error(
				'sd_ai_agent_empty_option_name',
				__( 'The "option_name" parameter is required.', 'superdav-ai-agent' )
			);
		}

		// Blocklist check.
		$blocklist = OptionsAbilities::get_write_blocklist();
		if ( in_array( $option_name, $blocklist, true ) ) {
			return new WP_Error(
				'sd_ai_agent_option_blocked',
				sprintf(
					/* translators: %s: option name */
					__( 'The option "%s" is protected and cannot be deleted by the AI agent.', 'superdav-ai-agent' ),
					$option_name
				)
			);
		}

		if ( ! OptionsAbilities::is_write_allowed_option( $option_name ) ) {
			return new WP_Error(
				'sd_ai_agent_option_not_allowed',
				sprintf(
					/* translators: %s: option name */
					__( 'The option "%s" is not in the AI agent write allowlist. Only plugin-owned options and options explicitly allowed by site code can be deleted by this ability.', 'superdav-ai-agent' ),
					$option_name
				),
				array( 'status' => 403 )
			);
		}

		// Check existence before deleting so we can report accurately.
		// Use a sentinel object so options storing literal false are not
		// misdetected as non-existent.
		$sentinel = new \stdClass();
		$exists   = get_option( $option_name, $sentinel ) !== $sentinel;

		if ( ! $exists ) {
			return [
				'option_name' => $option_name,
				'status'      => 'not_found',
				'message'     => sprintf(
					/* translators: %s: option name */
					__( 'Option "%s" does not exist.', 'superdav-ai-agent' ),
					$option_name
				),
			];
		}

		$deleted = delete_option( $option_name );

		if ( $deleted ) {
			return [
				'option_name' => $option_name,
				'status'      => 'deleted',
				'message'     => sprintf(
					/* translators: %s: option name */
					__( 'Option "%s" deleted successfully.', 'superdav-ai-agent' ),
					$option_name
				),
			];
		}

		return new WP_Error(
			'sd_ai_agent_delete_failed',
			sprintf(
				/* translators: %s: option name */
				__( 'Failed to delete option "%s".', 'superdav-ai-agent' ),
				$option_name
			)
		);
	}

	protected function permission_callback( $input = null ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}

/**
 * List Options ability.
 *
 * Lists WordPress options with optional prefix filtering. Useful for
 * discovering plugin/theme settings without knowing exact option names.
 *
 * @since 1.2.0
 */
class ListOptionsAbility extends AbstractAbility {

	/**
	 * Maximum number of characters to include per option value in the listing.
	 * Large values (serialised arrays, HTML blobs) are truncated to keep the
	 * response token-efficient.
	 */
	private const VALUE_TRUNCATE_LENGTH = 200;

	protected function label(): string {
		return __( 'List Options', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'List allowed WordPress options with optional prefix filtering. Default read access is limited to plugin-owned options; site code can opt in specific non-secret third-party options.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'prefix'   => [
					'type'        => 'string',
					'description' => 'Filter allowed options whose names start with this prefix. Leave empty to list this plugin\'s sd_ai_agent_ options. Third-party prefixes require a site-code read allowlist filter.',
					'default'     => '',
				],
				'limit'    => [
					'type'        => 'integer',
					'description' => 'Maximum number of options to return (default: 50, max: 200).',
					'default'     => 50,
				],
				'autoload' => [
					'type'        => 'string',
					'enum'        => [ 'all', 'yes', 'no' ],
					'description' => 'Filter by autoload status: "yes" (autoloaded), "no" (not autoloaded), or "all" (default).',
					'default'     => 'all',
				],
			],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'options'        => [ 'type' => 'array' ],
				'total'          => [ 'type' => 'integer' ],
				'prefix'         => [ 'type' => 'string' ],
				'redacted_count' => [
					'type'        => 'integer',
					'description' => 'Number of rows removed from the response because their option_name is not permitted by the read allowlist or is secret.',
				],
			],
		];
	}

	protected function execute_callback( $input ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$prefix   = isset( $input['prefix'] ) ? (string) $input['prefix'] : '';
		$prefix   = '' === $prefix ? 'sd_ai_agent_' : $prefix;
		$limit    = min( 200, max( 1, (int) ( $input['limit'] ?? 50 ) ) );
		$autoload = isset( $input['autoload'] ) ? (string) $input['autoload'] : 'all';

		$prefix_allowed = false;
		foreach ( OptionsAbilities::get_read_allowlist_prefixes() as $allowed_prefix ) {
			if ( '' !== $allowed_prefix && str_starts_with( $prefix, $allowed_prefix ) ) {
				$prefix_allowed = true;
				break;
			}
		}

		if ( ! $prefix_allowed ) {
			return new WP_Error(
				'sd_ai_agent_option_read_prefix_not_allowed',
				__( 'The requested option prefix is not in the AI agent read allowlist.', 'superdav-ai-agent' ),
				[ 'status' => 403 ]
			);
		}

		$like_prefix = $prefix . '%';

		// Each branch uses a fully static SQL template — $autoload and $prefix are never
		// interpolated into SQL; only %i/%s/%d placeholders carry runtime values.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Discovery query; caching not appropriate for dynamic option listings.
		if ( 'yes' === $autoload ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value, autoload FROM %i WHERE option_name LIKE %s AND autoload IN ('yes', 'on', '1', 'true') ORDER BY option_name LIMIT %d",
					$wpdb->options,
					$like_prefix,
					$limit
				),
				ARRAY_A
			);
		} elseif ( 'no' === $autoload ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value, autoload FROM %i WHERE option_name LIKE %s AND autoload NOT IN ('yes', 'on', '1', 'true') ORDER BY option_name LIMIT %d",
					$wpdb->options,
					$like_prefix,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT option_name, option_value, autoload FROM %i WHERE option_name LIKE %s ORDER BY option_name LIMIT %d',
					$wpdb->options,
					$like_prefix,
					$limit
				),
				ARRAY_A
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( null === $rows ) {
			return new WP_Error(
				'sd_ai_agent_db_error',
				__( 'Database query failed while listing options.', 'superdav-ai-agent' )
			);
		}

		$options        = [];
		$redacted_count = 0;
		foreach ( $rows as $row ) {
			$option_name = (string) $row['option_name'];

			// Read gate. Omit blocked rows entirely so the AI neither sees their
			// values nor learns whether a sensitive option is stored in wp_options.
			if ( ! OptionsAbilities::is_read_allowed_option( $option_name ) ) {
				++$redacted_count;
				continue;
			}

			$value = $row['option_value'];

			// Attempt to unserialise so the caller sees the real data type.
			$unserialized = maybe_unserialize( $value );

			// Truncate large values to keep the response token-efficient.
			if ( is_string( $unserialized ) && strlen( $unserialized ) > self::VALUE_TRUNCATE_LENGTH ) {
				$unserialized = substr( $unserialized, 0, self::VALUE_TRUNCATE_LENGTH ) . '…';
			} elseif ( ! is_scalar( $unserialized ) ) {
				// For arrays/objects, encode to JSON and truncate if needed.
				$encoded = wp_json_encode( $unserialized );
				if ( false !== $encoded && strlen( $encoded ) > self::VALUE_TRUNCATE_LENGTH ) {
					$unserialized = substr( $encoded, 0, self::VALUE_TRUNCATE_LENGTH ) . '…';
				}
			}

			$options[] = [
				'option_name'  => $option_name,
				'option_value' => $unserialized,
				'autoload'     => $row['autoload'],
			];
		}

		return [
			'options'        => $options,
			'total'          => count( $options ),
			'prefix'         => $prefix,
			'redacted_count' => $redacted_count,
		];
	}

	protected function permission_callback( $input = null ): bool {
		return ToolCapabilities::current_user_can( $this->name );
	}

	protected function meta(): array {
		return [
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}
