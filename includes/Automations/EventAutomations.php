<?php

declare(strict_types=1);
/**
 * Event-Driven Automations model — CRUD for hook-based AI triggers.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Automations;

use SdAiAgent\Core\Database;

class EventAutomations {

	/**
	 * Get the event automations table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_event_automations';
	}

	/**
	 * List all event automations.
	 *
	 * @param bool $enabled_only Only return enabled events.
	 * @return list<array<string, mixed>>
	 */
	public static function list( bool $enabled_only = false ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( ! self::database_is_installed() ) {
			return [];
		}

		$table = self::table_name();
		$where = $enabled_only ? 'WHERE enabled = 1' : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query; table/column names from internal methods, not user input.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY name ASC" );

		return array_map( [ __CLASS__, 'decode_row' ], $rows ?: [] );
	}

	/**
	 * Check whether the AI Agent database has been installed for the current site.
	 *
	 * Database::install() persists its version in an autoloaded option only after
	 * it creates the plugin tables. Network-activated multisite installs can reach
	 * a subsite before that happens, so this avoids both failed SELECT queries and
	 * per-request schema discovery while event hooks attach.
	 */
	private static function database_is_installed(): bool {
		return false !== get_option( Database::DB_VERSION_OPTION, false );
	}

	/**
	 * Get a single event automation.
	 *
	 * @param int $id Event automation ID.
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
	 * Create a new event automation.
	 *
	 * @param array<string, mixed> $data Event automation data.
	 * @return int|false Inserted ID or false.
	 */
	public static function create( array $data ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query; caching not applicable.
		$result = $wpdb->insert(
			self::table_name(),
			[
				// @phpstan-ignore-next-line
				'name'            => sanitize_text_field( $data['name'] ?? '' ),
				// @phpstan-ignore-next-line
				'description'     => sanitize_textarea_field( $data['description'] ?? '' ),
				// @phpstan-ignore-next-line
				'hook_name'       => sanitize_text_field( $data['hook_name'] ?? '' ),
				// @phpstan-ignore-next-line
				'prompt_template' => wp_kses_post( $data['prompt_template'] ?? '' ),
				'conditions'      => wp_json_encode( $data['conditions'] ?? [] ),
				// @phpstan-ignore-next-line
				'tool_profile'    => sanitize_text_field( $data['tool_profile'] ?? '' ),
				// @phpstan-ignore-next-line
				'max_iterations'  => absint( $data['max_iterations'] ?? 5 ),
				// @phpstan-ignore-next-line
				'enabled'         => isset( $data['enabled'] ) ? (int) $data['enabled'] : 0,
				'run_count'       => 0,
				'last_run_at'     => null,
				'created_at'      => $now,
				'updated_at'      => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' ]
		);

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update an event automation.
	 *
	 * @param int                  $id   Event automation ID.
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

		$string_fields = [ 'name', 'hook_name', 'tool_profile' ];
		foreach ( $string_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				// @phpstan-ignore-next-line
				$update[ $field ] = sanitize_text_field( $data[ $field ] );
				$formats[]        = '%s';
			}
		}

		if ( isset( $data['description'] ) ) {
			// @phpstan-ignore-next-line
			$update['description'] = sanitize_textarea_field( $data['description'] );
			$formats[]             = '%s';
		}

		if ( isset( $data['prompt_template'] ) ) {
			// @phpstan-ignore-next-line
			$update['prompt_template'] = wp_kses_post( $data['prompt_template'] );
			$formats[]                 = '%s';
		}

		if ( isset( $data['conditions'] ) ) {
			$update['conditions'] = wp_json_encode( $data['conditions'] );
			$formats[]            = '%s';
		}

		if ( isset( $data['max_iterations'] ) ) {
			// @phpstan-ignore-next-line
			$update['max_iterations'] = absint( $data['max_iterations'] );
			$formats[]                = '%d';
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
	 * Delete an event automation.
	 *
	 * @param int $id Event automation ID.
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

		return (int) $result > 0;
	}

	/**
	 * Record a run for an event automation.
	 *
	 * @param int $id Event automation ID.
	 */
	public static function record_run( int $id ): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET last_run_at = %s, run_count = run_count + 1, updated_at = %s WHERE id = %d',
				self::table_name(),
				$now,
				$now,
				$id
			)
		);
	}

	/**
	 * Decode a database row.
	 *
	 * @param object $row Database row.
	 * @return array<string, mixed>
	 */
	private static function decode_row( object $row ): array {
		return [
			'id'              => (int) $row->id,
			'name'            => $row->name,
			'description'     => $row->description,
			'hook_name'       => $row->hook_name,
			'prompt_template' => $row->prompt_template,
			'conditions'      => json_decode( $row->conditions, true ) ?: [],
			'tool_profile'    => $row->tool_profile,
			'max_iterations'  => (int) $row->max_iterations,
			'enabled'         => (bool) $row->enabled,
			'run_count'       => (int) $row->run_count,
			'last_run_at'     => $row->last_run_at,
			'created_at'      => $row->created_at,
			'updated_at'      => $row->updated_at,
		];
	}
}
