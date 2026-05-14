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

		// Replace {prefix} placeholder.
		$sql = str_replace( '{prefix}', $wpdb->prefix, $sql );

		$results = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- AI agent database ability executes user-approved dynamic SELECT queries with capability checks; results are not cacheable.

		if ( $wpdb->last_error ) {
			return new WP_Error( 'sd_ai_agent_db_error', sprintf( 'Database error: %s', $wpdb->last_error ) );
		}

		return [
			'query' => $sql,
			'rows'  => $results,
			'count' => is_array( $results ) ? count( $results ) : 0,
		];
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
