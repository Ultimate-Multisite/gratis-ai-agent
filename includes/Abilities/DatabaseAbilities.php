<?php

declare(strict_types=1);
/**
 * Database operation abilities for the AI agent.
 *
 * Provides SELECT query execution against the WordPress database.
 * Supports {prefix} placeholder for table prefix substitution.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DatabaseAbilities {

	// ─── Static proxy methods (for backwards-compatible test access) ─────────

	/**
	 * Execute a SELECT database query.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_db_query( array $input = [] ) {
		$ability = new DatabaseQueryAbility(
			'sd-ai-agent/db-query',
			[
				'label'       => __( 'Database Query', 'superdav-ai-agent' ),
				'description' => __( 'Execute a SELECT query on the WordPress database. Only SELECT queries are allowed. Use {prefix} as placeholder for the table prefix.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Register database abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/db-query',
			[
				'label'         => __( 'Database Query', 'superdav-ai-agent' ),
				'description'   => __( 'Execute a SELECT query on the WordPress database. Only SELECT queries are allowed. Use {prefix} as placeholder for the table prefix.', 'superdav-ai-agent' ),
				'ability_class' => DatabaseQueryAbility::class,
			]
		);
	}
}

/**
 * Database Query ability.
 *
 * @since 1.0.0
 */
class DatabaseQueryAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Database Query', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Execute a SELECT query on the WordPress database. Only SELECT queries are allowed. Use {prefix} as placeholder for the table prefix.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'sql' => [
					'type'        => 'string',
					'description' => 'The SELECT SQL query to execute. Use {prefix} as placeholder for table prefix.',
				],
			],
			'required'   => [ 'sql' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'query' => [ 'type' => 'string' ],
				'rows'  => [ 'type' => 'array' ],
				'count' => [ 'type' => 'integer' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		// @phpstan-ignore-next-line
		global $wpdb;
		/** @var \wpdb $wpdb */

		// @phpstan-ignore-next-line
		$sql = trim( $input['sql'] ?? '' );

		if ( empty( $sql ) ) {
			return new WP_Error( 'sd_ai_agent_empty_sql', __( 'SQL query cannot be empty.', 'superdav-ai-agent' ) );
		}

		// Only allow SELECT queries.
		if ( stripos( $sql, 'SELECT' ) !== 0 ) {
			return new WP_Error(
				'sd_ai_agent_sql_not_select',
				__( 'Only SELECT queries are allowed. Use WordPress functions for data modification.', 'superdav-ai-agent' )
			);
		}

		// Secret-aware pre-check: reject any query that names an auth-key /
		// salt option as a string literal. Without this guard a caller could
		// bypass GetOptionAbility / ListOptionsAbility with
		// `SELECT option_value FROM wp_options WHERE option_name='auth_key'`.
		$secret_literal = self::find_secret_option_literal( $sql );
		if ( null !== $secret_literal ) {
			return new WP_Error(
				'sd_ai_agent_sql_secret_literal',
				sprintf(
					/* translators: %s: secret option name */
					__( 'The query references the secret option "%s". Reading auth keys/salts via SQL is not permitted.', 'superdav-ai-agent' ),
					$secret_literal
				),
				array( 'status' => 403 )
			);
		}

		// Replace {prefix} placeholder.
		$sql = str_replace( '{prefix}', $wpdb->prefix, $sql );

		$results = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- AI agent database ability executes user-approved dynamic SELECT queries with capability checks; results are not cacheable.

		if ( $wpdb->last_error ) {
			return new WP_Error( 'sd_ai_agent_db_error', sprintf( 'Database error: %s', $wpdb->last_error ) );
		}

		// Defence-in-depth: even when the SQL did not name a secret as a
		// literal (e.g. a wildcard `SELECT * FROM wp_options`), scrub any
		// row whose option_name/meta_key identifies a secret.
		$rows = is_array( $results ) ? self::scrub_secret_rows( $results ) : $results;

		return [
			'query' => $sql,
			'rows'  => $rows,
			'count' => is_array( $rows ) ? count( $rows ) : 0,
		];
	}

	/**
	 * Find the first secret option name referenced as a string literal in
	 * the SQL. Returns null when no secret is referenced.
	 *
	 * The check intentionally ignores SQL identifiers (column / table names)
	 * and only looks at single- or double-quoted literals so a query that
	 * happens to mention `option_name` as a column does not false-positive.
	 *
	 * @param string $sql Raw (pre-prefix-substituted) SQL.
	 * @return string|null
	 */
	private static function find_secret_option_literal( string $sql ): ?string {
		$blocklist = OptionsAbilities::get_secret_read_blocklist();
		if ( empty( $blocklist ) ) {
			return null;
		}

		if ( ! preg_match_all( "/(?:'([^'\\\\]*(?:\\\\.[^'\\\\]*)*)')|(?:\"([^\"\\\\]*(?:\\\\.[^\"\\\\]*)*)\")/", $sql, $matches ) ) {
			return null;
		}

		$literals = array_filter(
			array_merge( $matches[1], $matches[2] ),
			static function ( $literal ): bool {
				return is_string( $literal ) && '' !== $literal;
			}
		);

		foreach ( $literals as $literal ) {
			if ( in_array( $literal, $blocklist, true ) ) {
				return $literal;
			}
		}

		return null;
	}

	/**
	 * Redact `option_value` (or `meta_value`) on any returned row whose
	 * `option_name` (or `meta_key`) is on the secret read blocklist.
	 *
	 * Supports multisite (`wp_sitemeta`) and any other `meta_key`/`meta_value`
	 * table that happens to store an auth-key-shaped name.
	 *
	 * @param array<int,array<mixed>> $rows Result rows in ARRAY_A shape.
	 * @return array<int,array<mixed>>
	 */
	private static function scrub_secret_rows( array $rows ): array {
		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( isset( $row['option_name'], $row['option_value'] )
				&& is_string( $row['option_name'] )
				&& OptionsAbilities::is_secret_option_name( $row['option_name'] ) ) {
				$rows[ $index ]['option_value'] = OptionsAbilities::SECRET_REDACTED_PLACEHOLDER;
			}

			// `meta_value` and `meta_key` here are PHP array keys on a returned row
			// (not a SQL query column reference), so the slow-query and quoting
			// rules do not apply.
			// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			if ( isset( $row['meta_key'], $row['meta_value'] )
				&& is_string( $row['meta_key'] )
				&& OptionsAbilities::is_secret_option_name( $row['meta_key'] ) ) {
				$rows[ $index ]['meta_value'] = OptionsAbilities::SECRET_REDACTED_PLACEHOLDER;
			}
			// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		}

		return $rows;
	}

	protected function permission_callback( $input ): bool {
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
