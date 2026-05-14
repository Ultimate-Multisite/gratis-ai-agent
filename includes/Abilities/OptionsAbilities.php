<?php

declare(strict_types=1);
/**
 * Options management abilities for the AI agent.
 *
 * Provides get, update, and delete operations for WordPress options with a
 * safety blocklist that prevents the AI from modifying critical site options
 * (e.g. siteurl, admin_email, active_plugins, db_version).
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
				'description'   => __( 'Create or update a WordPress option. Blocked for critical system options (siteurl, admin_email, active_plugins, etc.).', 'superdav-ai-agent' ),
				'ability_class' => UpdateOptionAbility::class,
			]
		);

		wp_register_ability(
			'sd-ai-agent/delete-option',
			[
				'label'         => __( 'Delete Option', 'superdav-ai-agent' ),
				'description'   => __( 'Delete a WordPress option by name. Blocked for critical system options.', 'superdav-ai-agent' ),
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
				'value'       => [],
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
 * Creates or updates a WordPress option with blocklist enforcement.
 *
 * @since 1.2.0
 */
class UpdateOptionAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Update Option', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Create or update a WordPress option. Blocked for critical system options (siteurl, admin_email, active_plugins, etc.).', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'option_name'  => [
					'type'        => 'string',
					'description' => 'The option name to create or update.',
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
 * Removes a WordPress option with blocklist enforcement.
 *
 * @since 1.2.0
 */
class DeleteOptionAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Delete Option', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Delete a WordPress option by name. Blocked for critical system options.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'option_name' => [
					'type'        => 'string',
					'description' => 'The option name to delete.',
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
		return __( 'List WordPress options with optional prefix filtering. Returns option names and values (truncated for large values). Useful for discovering plugin/theme settings.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'prefix'   => [
					'type'        => 'string',
					'description' => 'Filter options whose names start with this prefix (e.g. "woocommerce_", "elementor_"). Leave empty to list all options.',
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
				'options' => [ 'type' => 'array' ],
				'total'   => [ 'type' => 'integer' ],
				'prefix'  => [ 'type' => 'string' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$prefix   = isset( $input['prefix'] ) ? (string) $input['prefix'] : '';
		$limit    = min( 200, max( 1, (int) ( $input['limit'] ?? 50 ) ) );
		$autoload = isset( $input['autoload'] ) ? (string) $input['autoload'] : 'all';

		// Each branch uses a fully static SQL template — $autoload and $prefix are never
		// interpolated into SQL; only %i/%s/%d placeholders carry runtime values.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Discovery query; caching not appropriate for dynamic option listings.
		if ( '' !== $prefix ) {
			if ( 'yes' === $autoload ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT option_name, option_value, autoload FROM %i WHERE option_name LIKE %s AND autoload IN ('yes', 'on', '1', 'true') ORDER BY option_name LIMIT %d",
						$wpdb->options,
						$prefix,
						$limit
					),
					ARRAY_A
				);
			} elseif ( 'no' === $autoload ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT option_name, option_value, autoload FROM %i WHERE option_name LIKE %s AND autoload NOT IN ('yes', 'on', '1', 'true') ORDER BY option_name LIMIT %d",
						$wpdb->options,
						$prefix,
						$limit
					),
					ARRAY_A
				);
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT option_name, option_value, autoload FROM %i WHERE option_name LIKE %s ORDER BY option_name LIMIT %d',
						$wpdb->options,
						$prefix,
						$limit
					),
					ARRAY_A
				);
			}
		} elseif ( 'yes' === $autoload ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT option_name, option_value, autoload FROM %i WHERE autoload IN ('yes', 'on', '1', 'true') ORDER BY option_name LIMIT %d",
						$wpdb->options,
						$limit
					),
					ARRAY_A
				);
		} elseif ( 'no' === $autoload ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value, autoload FROM %i WHERE autoload NOT IN ('yes', 'on', '1', 'true') ORDER BY option_name LIMIT %d",
					$wpdb->options,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT option_name, option_value, autoload FROM %i ORDER BY option_name LIMIT %d',
					$wpdb->options,
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

		$options = [];
		foreach ( $rows as $row ) {
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
				'option_name'  => $row['option_name'],
				'option_value' => $unserialized,
				'autoload'     => $row['autoload'],
			];
		}

		return [
			'options' => $options,
			'total'   => count( $options ),
			'prefix'  => $prefix,
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
