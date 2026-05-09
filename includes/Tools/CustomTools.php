<?php
/**
 * Custom Tools model — CRUD for user-defined tools.
 *
 * Three tool types:
 *  - HTTP:   GET/POST/PUT/DELETE to external URLs with {{placeholder}} substitution.
 *  - ACTION: Calls do_action() with arguments (integrates with any WP plugin).
 *  - CLI:    Runs WP-CLI commands with argument schema.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tools;

/**
 * Prevents direct access to the file.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SdAiAgent\Core\Features;

class CustomTools {

	const TYPE_HTTP   = 'http';
	const TYPE_ACTION = 'action';
	const TYPE_CLI    = 'cli';

	const VALID_TYPES = [ self::TYPE_HTTP, self::TYPE_ACTION, self::TYPE_CLI ];

	const VALID_HTTP_METHODS = [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ];

	/**
	 * Get the table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_custom_tools';
	}

	/**
	 * List all custom tools.
	 *
	 * @param bool $enabled_only Only return enabled tools.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list( bool $enabled_only = false ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = self::table_name();
		$where = $enabled_only ? 'WHERE enabled = 1' : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query; table/column names from internal methods, not user input.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY name ASC" );

		return array_values( array_map( [ __CLASS__, 'decode_row' ], $rows ?: [] ) );
	}

	/**
	 * Get a single tool by ID.
	 *
	 * @param int $id Tool ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table_name(), $id )
		);

		return $row ? self::decode_row( $row ) : null;
	}

	/**
	 * Get a tool by slug.
	 *
	 * @param string $slug Tool slug.
	 * @return array<string, mixed>|null
	 */
	public static function get_by_slug( string $slug ): ?array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE slug = %s', self::table_name(), $slug )
		);

		return $row ? self::decode_row( $row ) : null;
	}

	/**
	 * Create a new custom tool.
	 *
	 * @param array<string, mixed> $data Tool data.
	 * @return int|false Inserted ID or false.
	 */
	public static function create( array $data ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$data = self::validate( $data );
		if ( is_wp_error( $data ) ) {
			return false;
		}

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query; caching not applicable.
		$result = $wpdb->insert(
			self::table_name(),
			[
				'slug'         => $data['slug'],
				'name'         => $data['name'],
				'description'  => $data['description'] ?? '',
				'type'         => $data['type'],
				'config'       => wp_json_encode( $data['config'] ?? [] ),
				'input_schema' => wp_json_encode( $data['input_schema'] ?? [] ),
				// @phpstan-ignore-next-line
				'enabled'      => isset( $data['enabled'] ) ? (int) $data['enabled'] : 1,
				'created_at'   => $now,
				'updated_at'   => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update an existing tool.
	 *
	 * @param int                  $id   Tool ID.
	 * @param array<string, mixed> $data Fields to update.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$existing = self::get( $id );
		if ( ! $existing ) {
			return false;
		}

		$update  = [];
		$formats = [];

		if ( isset( $data['name'] ) ) {
			// @phpstan-ignore-next-line
			$update['name'] = sanitize_text_field( $data['name'] );
			$formats[]      = '%s';
		}

		if ( isset( $data['slug'] ) ) {
			// @phpstan-ignore-next-line
			$update['slug'] = sanitize_title( $data['slug'] );
			$formats[]      = '%s';
		}

		if ( isset( $data['description'] ) ) {
			// @phpstan-ignore-next-line
			$update['description'] = sanitize_textarea_field( $data['description'] );
			$formats[]             = '%s';
		}

		if ( isset( $data['type'] ) && in_array( $data['type'], self::VALID_TYPES, true ) ) {
			$update['type'] = $data['type'];
			$formats[]      = '%s';
		}

		if ( isset( $data['config'] ) ) {
			$update['config'] = wp_json_encode( $data['config'] );
			$formats[]        = '%s';
		}

		if ( isset( $data['input_schema'] ) ) {
			$update['input_schema'] = wp_json_encode( $data['input_schema'] );
			$formats[]              = '%s';
		}

		if ( isset( $data['enabled'] ) ) {
			// @phpstan-ignore-next-line
			$update['enabled'] = (int) $data['enabled'];
			$formats[]         = '%d';
		}

		if ( empty( $update ) ) {
			return true;
		}

		$update['updated_at'] = current_time( 'mysql', true );
		$formats[]            = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->update(
			self::table_name(),
			$update,
			[ 'id' => $id ],
			$formats,
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Delete a tool.
	 *
	 * @param int $id Tool ID.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->delete(
			self::table_name(),
			[ 'id' => $id ],
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Validate tool data for creation.
	 *
	 * @param array<string, mixed> $data Tool data.
	 * @return array<string, mixed>|\WP_Error Validated data or error.
	 */
	public static function validate( array $data ) {
		if ( empty( $data['name'] ) ) {
			return new \WP_Error( 'missing_name', __( 'Tool name is required.', 'superdav-ai-agent' ) );
		}

		if ( empty( $data['type'] ) || ! in_array( $data['type'], self::VALID_TYPES, true ) ) {
			return new \WP_Error( 'invalid_type', __( 'Tool type must be http, action, or cli.', 'superdav-ai-agent' ) );
		}

		// Reject CLI tools when the feature is disabled (e.g. WordPress.org
		// distribution build). HTTP and Action tools remain creatable.
		if ( self::TYPE_CLI === $data['type'] && ! Features::is_enabled( Features::CUSTOM_TOOLS_CLI ) ) {
			return new \WP_Error(
				'cli_tools_disabled',
				__( 'WP-CLI custom tools are disabled in this distribution of Superdav AI Agent. Use HTTP or Action tools instead.', 'superdav-ai-agent' )
			);
		}

		// Auto-generate slug from name if not provided.
		if ( empty( $data['slug'] ) ) {
			// @phpstan-ignore-next-line
			$data['slug'] = sanitize_title( $data['name'] );
		}

		// @phpstan-ignore-next-line
		$data['name'] = sanitize_text_field( $data['name'] );
		// @phpstan-ignore-next-line
		$data['slug'] = sanitize_title( $data['slug'] );
		// @phpstan-ignore-next-line
		$data['description'] = sanitize_textarea_field( $data['description'] ?? '' );

		// Validate type-specific config.
		$config = $data['config'] ?? [];

		switch ( $data['type'] ) {
			case self::TYPE_HTTP:
				// @phpstan-ignore-next-line
				if ( empty( $config['url'] ) ) {
					return new \WP_Error( 'missing_url', __( 'HTTP tools require a URL.', 'superdav-ai-agent' ) );
				}
				// @phpstan-ignore-next-line
				if ( ! empty( $config['method'] ) && ! in_array( strtoupper( $config['method'] ), self::VALID_HTTP_METHODS, true ) ) {
					return new \WP_Error( 'invalid_method', __( 'Invalid HTTP method.', 'superdav-ai-agent' ) );
				}
				// @phpstan-ignore-next-line
				$config['method'] = strtoupper( $config['method'] ?? 'GET' );
				break;

			case self::TYPE_ACTION:
				// @phpstan-ignore-next-line
				if ( empty( $config['hook_name'] ) ) {
					return new \WP_Error( 'missing_hook', __( 'Action tools require a hook_name.', 'superdav-ai-agent' ) );
				}
				break;

			case self::TYPE_CLI:
				// @phpstan-ignore-next-line
				if ( empty( $config['command'] ) ) {
					return new \WP_Error( 'missing_command', __( 'CLI tools require a command template.', 'superdav-ai-agent' ) );
				}
				break;
		}

		$data['config'] = $config;

		return $data;
	}

	/**
	 * Seed example tools on first install.
	 */
	public static function seed_examples(): void {
		if ( get_option( 'sd_ai_agent_custom_tools_seeded' ) ) {
			return;
		}

		$examples = [
			[
				'name'         => 'Weather API',
				'slug'         => 'weather-api',
				'description'  => 'Get current weather for a city using wttr.in.',
				'type'         => self::TYPE_HTTP,
				'config'       => [
					'url'     => 'https://wttr.in/{{city}}?format=j1',
					'method'  => 'GET',
					'headers' => [],
				],
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'city' => [
							'type'        => 'string',
							'description' => 'City name (e.g. "London", "New York")',
						],
					],
					'required'   => [ 'city' ],
				],
				'enabled'      => 0,
			],
			[
				'name'         => 'Zapier Webhook',
				'slug'         => 'zapier-webhook',
				'description'  => 'Send data to a Zapier webhook for external automations.',
				'type'         => self::TYPE_HTTP,
				'config'       => [
					'url'     => 'https://hooks.zapier.com/hooks/catch/YOUR_ID/YOUR_HOOK/',
					'method'  => 'POST',
					'headers' => [ 'Content-Type' => 'application/json' ],
				],
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'data' => [
							'type'        => 'object',
							'description' => 'JSON data to send to Zapier',
						],
					],
					'required'   => [ 'data' ],
				],
				'enabled'      => 0,
			],
			[
				'name'         => 'Clear Object Cache',
				'slug'         => 'clear-object-cache',
				'description'  => 'Flush the WordPress object cache.',
				'type'         => self::TYPE_CLI,
				'config'       => [
					'command' => 'cache flush',
				],
				'input_schema' => [
					'type'       => 'object',
					'properties' => new \stdClass(),
				],
				'enabled'      => 1,
			],
			[
				'name'         => 'Toggle Maintenance Mode',
				'slug'         => 'maintenance-mode',
				'description'  => 'Enable or disable WordPress maintenance mode.',
				'type'         => self::TYPE_CLI,
				'config'       => [
					'command' => 'maintenance-mode {{action}}',
				],
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'action' => [
							'type'        => 'string',
							'enum'        => [ 'activate', 'deactivate', 'status' ],
							'description' => 'Whether to activate, deactivate, or check maintenance mode.',
						],
					],
					'required'   => [ 'action' ],
				],
				'enabled'      => 1,
			],
			[
				'name'         => 'Site Health Check',
				'slug'         => 'site-health-check',
				'description'  => 'Fire the site health check action to trigger health checks.',
				'type'         => self::TYPE_ACTION,
				'config'       => [
					'hook_name' => 'sd_ai_agent_site_health_check',
					'args'      => [],
				],
				'input_schema' => [
					'type'       => 'object',
					'properties' => new \stdClass(),
				],
				'enabled'      => 0,
			],
		];

		// Skip CLI examples when the feature is disabled so the
		// WordPress.org distribution build does not seed shell-exec tools.
		$cli_enabled = Features::is_enabled( Features::CUSTOM_TOOLS_CLI );

		foreach ( $examples as $example ) {
			if ( ! $cli_enabled && self::TYPE_CLI === $example['type'] ) {
				continue;
			}
			self::create( $example );
		}

		update_option( 'sd_ai_agent_custom_tools_seeded', true );
	}

	/**
	 * Decode a database row into an array with parsed JSON.
	 *
	 * @param object $row Database row.
	 * @return array<string, mixed>
	 */
	private static function decode_row( object $row ): array {
		return [
			'id'           => (int) $row->id,
			'slug'         => $row->slug,
			'name'         => $row->name,
			'description'  => $row->description,
			'type'         => $row->type,
			'config'       => json_decode( $row->config, true ) ?: [],
			'input_schema' => json_decode( $row->input_schema, true ) ?: [],
			'enabled'      => (bool) $row->enabled,
			'created_at'   => $row->created_at,
			'updated_at'   => $row->updated_at,
		];
	}
}
