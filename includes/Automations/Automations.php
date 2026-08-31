<?php

declare(strict_types=1);
/**
 * Scheduled Automations model — CRUD for cron-based AI tasks.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Automations;

use SdAiAgent\Core\JobErrorSanitizer;
use WP_Error;

class Automations {

	const VALID_SCHEDULES = [ 'hourly', 'twicedaily', 'daily', 'weekly' ];

	const TASK_MODE    = 'task';
	const MONITOR_MODE = 'monitor';

	/** Maximum independently selected event sources for one Monitor. */
	const MAX_MONITOR_WAKE_SOURCES = 4;

	/** @var list<string> Supported scheduled automation modes. */
	const VALID_MODES = [ self::TASK_MODE, self::MONITOR_MODE ];

	/** @var list<string> Durable lifecycle statuses for scheduled automation runs. */
	const RUN_STATUSES = [ 'idle', 'claimed', 'running', 'succeeded', 'failed', 'blocked', 'abandoned' ];

	/** @var list<string> Lifecycle statuses that release a durable execution claim. */
	const TERMINAL_RUN_STATUSES = [ 'succeeded', 'failed', 'blocked', 'abandoned' ];

	/** Prefix for per-automation, cross-request execution fences. */
	private const EXECUTION_LOCK_PREFIX = 'sd_ai_agent_automation_run_';

	/** Maximum expired claims inspected during one recovery pass. */
	private const STALE_RUN_BATCH_SIZE = 100;

	/** @var array<string, true> Advisory locks held by this PHP request. */
	private static array $execution_locks = [];

	/** @var bool Whether this request currently owns an automation lifecycle transaction. */
	private static bool $lifecycle_transaction_active = false;

	/** @var bool Whether the lifecycle transaction is isolated by a savepoint. */
	private static bool $lifecycle_transaction_uses_savepoint = false;

	private const LIFECYCLE_SAVEPOINT = 'sd_ai_agent_automation_lifecycle';

	/**
	 * Built-in least-privilege tool profiles for scheduled automations.
	 *
	 * Additional profiles can be supplied through the
	 * `sd_ai_agent_automation_tool_profile_abilities` filter. An unknown
	 * non-empty profile is intentionally blocked rather than falling back to
	 * the full administrator tool catalog.
	 *
	 * @var array<string, list<string>>
	 */
	const BUILTIN_TOOL_PROFILES = [
		'site-health' => [
			'sd-ai-agent/site-health-summary',
			'sd-ai-agent/ability-search',
			'sd-ai-agent/ability-call',
		],
	];

	/**
	 * Get the automations table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_automations';
	}

	/**
	 * Return whether an automation uses the quiet Monitor execution contract.
	 *
	 * @param array<string, mixed> $automation Automation definition.
	 */
	public static function is_monitor( array $automation ): bool {
		return self::MONITOR_MODE === self::get_mode( $automation );
	}

	/**
	 * Provide timing guidance for a Monitor configuration UI.
	 *
	 * WP-Cron is traffic-driven, so this stays with the durable Monitor data
	 * returned by the REST API instead of promising an exact execution time.
	 */
	public static function get_monitor_timing_help(): string {
		return __( 'Monitor schedules use WP-Cron, which is triggered by site traffic and can run late. For more reliable timing, configure a real system cron to trigger wp-cron.php.', 'superdav-ai-agent' );
	}

	/**
	 * Validate Monitor-specific create or update input before persistence.
	 *
	 * @param array<string, mixed>      $data     Request data.
	 * @param array<string, mixed>|null $existing Existing definition on update.
	 * @return true|WP_Error
	 */
	public static function validate_definition( array $data, ?array $existing = null ): true|WP_Error {
		$mode = array_key_exists( 'mode', $data ) ? self::sanitize_mode( $data['mode'] ) : self::get_mode( $existing ?? [] );
		if ( ! in_array( $mode, self::VALID_MODES, true ) ) {
			return new WP_Error(
				'sd_ai_agent_automation_invalid_mode',
				__( 'The automation mode must be either "task" or "monitor".', 'superdav-ai-agent' )
			);
		}

		$monitor_fields = [ 'monitor_scratch', 'monitor_event_wakes_enabled', 'monitor_event_sources' ];
		foreach ( array_keys( $data ) as $key ) {
			if ( is_string( $key ) && str_starts_with( $key, 'monitor_' ) && ! in_array( $key, $monitor_fields, true ) ) {
				return new WP_Error(
					'sd_ai_agent_automation_unknown_monitor_field',
					__( 'This Monitor field is not supported by the current site.', 'superdav-ai-agent' )
				);
			}
		}

		$event_wake_fields_present = array_key_exists( 'monitor_event_wakes_enabled', $data ) || array_key_exists( 'monitor_event_sources', $data );
		if ( $event_wake_fields_present && self::MONITOR_MODE !== $mode ) {
			return new WP_Error(
				'sd_ai_agent_automation_monitor_event_wakes_requires_monitor',
				__( 'Event wakes can only be configured for Monitor automations.', 'superdav-ai-agent' )
			);
		}

		$sources = array_key_exists( 'monitor_event_sources', $data )
			? self::sanitize_monitor_event_sources( $data['monitor_event_sources'] )
			: self::get_monitor_event_sources( $existing ?? [] );
		if ( is_wp_error( $sources ) ) {
			return $sources;
		}

		$event_wakes_enabled = array_key_exists( 'monitor_event_wakes_enabled', $data )
			? ! empty( $data['monitor_event_wakes_enabled'] )
			: self::is_monitor_event_wakes_enabled( $existing ?? [] );
		if ( self::MONITOR_MODE === $mode && $event_wakes_enabled && empty( $sources ) ) {
			return new WP_Error(
				'sd_ai_agent_automation_monitor_event_wakes_requires_source',
				__( 'Select at least one approved event source before enabling Monitor event wakes.', 'superdav-ai-agent' )
			);
		}

		if ( array_key_exists( 'monitor_scratch', $data ) ) {
			if ( ! is_scalar( $data['monitor_scratch'] ) ) {
				return new WP_Error(
					'sd_ai_agent_automation_invalid_monitor_scratch',
					__( 'The Monitor checklist must be text.', 'superdav-ai-agent' )
				);
			}

			if ( strlen( (string) $data['monitor_scratch'] ) > MonitorOutcome::MAX_SCRATCH_LENGTH ) {
				return new WP_Error(
					'sd_ai_agent_automation_monitor_scratch_too_long',
					__( 'The Monitor checklist is too long.', 'superdav-ai-agent' )
				);
			}

			if ( self::MONITOR_MODE !== $mode && '' !== trim( (string) $data['monitor_scratch'] ) ) {
				return new WP_Error(
					'sd_ai_agent_automation_monitor_scratch_requires_monitor',
					__( 'A Monitor checklist can only be saved for Monitor automations.', 'superdav-ai-agent' )
				);
			}
		}

		if ( self::MONITOR_MODE === $mode ) {
			$schedule = array_key_exists( 'schedule', $data ) ? sanitize_key( (string) $data['schedule'] ) : (string) ( $existing['schedule'] ?? 'daily' );
			if ( ! in_array( $schedule, self::VALID_SCHEDULES, true ) ) {
				return new WP_Error(
					'sd_ai_agent_automation_invalid_monitor_schedule',
					__( 'Monitor cadence must be hourly, twice daily, daily, or weekly.', 'superdav-ai-agent' )
				);
			}
		}

		return true;
	}

	/**
	 * Return whether a decoded automation has separately enabled event wakes.
	 *
	 * @param array<string, mixed> $automation Automation definition.
	 */
	public static function is_monitor_event_wakes_enabled( array $automation ): bool {
		return self::is_monitor( $automation ) && ! empty( $automation['monitor_event_wakes_enabled'] );
	}

	/**
	 * Return the strictly allowlisted event sources selected for one Monitor.
	 *
	 * @param array<string, mixed> $automation Automation definition.
	 * @return list<string>
	 */
	public static function get_monitor_event_sources( array $automation ): array {
		$raw = $automation['monitor_event_sources'] ?? [];
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : [];
		}

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$sources = [];
		foreach ( $raw as $source ) {
			if ( ! is_scalar( $source ) ) {
				continue;
			}

			$source = sanitize_key( (string) $source );
			if ( EventTriggerRegistry::is_monitor_wake_source( $source ) ) {
				$sources[] = $source;
			}
		}

		return array_values( array_unique( $sources ) );
	}

	/**
	 * Return enabled Monitor definitions subscribed to one approved event source.
	 *
	 * @param string $source Registered WordPress event source.
	 * @return list<array<string, mixed>>
	 */
	public static function list_monitor_wake_subscribers( string $source ): array {
		if ( ! EventTriggerRegistry::is_monitor_wake_source( $source ) ) {
			return [];
		}

		$subscribers = [];
		foreach ( self::list( true ) as $automation ) {
			if ( ! self::is_monitor_event_wakes_enabled( $automation ) ) {
				continue;
			}

			if ( in_array( $source, self::get_monitor_event_sources( $automation ), true ) ) {
				$subscribers[] = $automation;
			}
		}

		return $subscribers;
	}

	/**
	 * Persist the inspectable next WordPress cron timestamp for one automation.
	 */
	public static function update_next_run_at( int $id, ?int $timestamp ): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( $id <= 0 ) {
			return;
		}

		$wpdb->update(
			self::table_name(),
			[
				'next_run_at' => null === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp ),
				'updated_at'  => current_time( 'mysql', true ),
			],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Acquire the request-scoped execution fence for one automation.
	 *
	 * The durable lease makes abandoned work recoverable. This advisory lock
	 * prevents a still-live worker whose lease elapsed from overlapping a new
	 * provider or tool execution while recovery is deciding whether it is safe
	 * to release the claim.
	 *
	 * @return string|null Opaque lock name when acquired, otherwise null.
	 */
	public static function acquire_execution_lock( int $id ): ?string {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( $id <= 0 ) {
			return null;
		}

		$lock_name = self::execution_lock_name( $id );
		// MySQL advisory locks are recursive for one connection. Treat a lock
		// already held by this request as busy so a re-entrant delivery cannot
		// accidentally bypass the execution fence.
		if ( isset( self::$execution_locks[ $lock_name ] ) ) {
			return null;
		}

		if ( ! self::ensure_execution_lock_session_lifetime() ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A database advisory lock serializes one automation execution across PHP requests and web heads.
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 0 ) );
		if ( 1 !== (int) $acquired ) {
			return null;
		}

		self::$execution_locks[ $lock_name ] = true;

		return $lock_name;
	}

	/** Release an execution fence previously acquired by this request. */
	public static function release_execution_lock( string $lock_name ): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( '' === $lock_name || ! isset( self::$execution_locks[ $lock_name ] ) ) {
			return;
		}

		unset( self::$execution_locks[ $lock_name ] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the request-scoped advisory lock acquired for this automation execution.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
	}

	/**
	 * Ensure an advisory lock session cannot expire before the longest permitted lease.
	 *
	 * MySQL releases named locks with an idle connection. The execution fence is
	 * held while provider and tool work is in progress, so require a session
	 * timeout longer than the one-day maximum lease plus a one-hour margin.
	 */
	private static function ensure_execution_lock_session_lifetime(): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$minimum_wait_timeout = DAY_IN_SECONDS + HOUR_IN_SECONDS;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirms the advisory-lock connection will outlive the bounded execution lease.
		$wait_timeout = $wpdb->get_var( 'SELECT @@SESSION.wait_timeout' );
		if ( is_numeric( $wait_timeout ) && (int) $wait_timeout >= $minimum_wait_timeout ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Raises this request's advisory-lock session lifetime without changing the server default.
		$set_timeout = $wpdb->query( $wpdb->prepare( 'SET SESSION wait_timeout = %d', $minimum_wait_timeout ) );
		if ( false === $set_timeout ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fail closed if the database server constrained the requested session lifetime.
		$wait_timeout = $wpdb->get_var( 'SELECT @@SESSION.wait_timeout' );

		return is_numeric( $wait_timeout ) && (int) $wait_timeout >= $minimum_wait_timeout;
	}

	/**
	 * List all automations.
	 *
	 * @param bool $enabled_only Only return enabled automations.
	 * @return list<array<string, mixed>>
	 */
	public static function list( bool $enabled_only = false ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = self::table_name();
		$where = $enabled_only ? 'WHERE enabled = 1' : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query; table/column names from internal methods, not user input.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY name ASC" );

		return array_map( [ __CLASS__, 'decode_row' ], $rows ?: [] );
	}

	/**
	 * Get a single automation by ID.
	 *
	 * @param int $id Automation ID.
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
	 * Sanitise and JSON-encode a notification_channels value.
	 *
	 * Accepts either a JSON string or a PHP array. Returns a JSON string
	 * (empty array JSON on invalid input).
	 *
	 * @param mixed $value Raw value from request or DB.
	 * @return string JSON-encoded array.
	 */
	private static function sanitize_notification_channels( $value ): string {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : [];
		}

		if ( ! is_array( $value ) ) {
			return '[]';
		}

		$clean = [];
		foreach ( $value as $channel ) {
			if ( ! is_array( $channel ) ) {
				continue;
			}
			// @phpstan-ignore-next-line
			$type = sanitize_text_field( $channel['type'] ?? '' );
			if ( ! in_array( $type, [ 'slack', 'discord', 'whatsapp', 'telegram' ], true ) ) {
				continue;
			}
			$clean_channel = [
				'type'    => $type,
				'enabled' => ! empty( $channel['enabled'] ),
			];
			if ( in_array( $type, [ 'slack', 'discord' ], true ) ) {
				$clean_channel['webhook_url'] = esc_url_raw( $channel['webhook_url'] ?? '' );
			} else {
				$clean_channel['recipient'] = sanitize_text_field( (string) ( $channel['recipient'] ?? '' ) );
			}
			$clean[] = $clean_channel;
		}

		return wp_json_encode( $clean ) ?: '[]';
	}

	/**
	 * Create a new automation.
	 *
	 * @param array<string, mixed> $data Automation data.
	 * @return int|false Inserted ID or false.
	 */
	public static function create( array $data ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$validation = self::validate_definition( $data );
		if ( is_wp_error( $validation ) ) {
			return false;
		}

		$mode = self::sanitize_mode( $data['mode'] ?? self::TASK_MODE );
		if ( ! in_array( $mode, self::VALID_MODES, true ) ) {
			return false;
		}

		$enabled = isset( $data['enabled'] ) ? (int) $data['enabled'] : 0;
		if ( self::MONITOR_MODE === $mode && ! array_key_exists( 'enabled', $data ) ) {
			$enabled = 0;
		}

		$schedule                    = sanitize_key( (string) ( $data['schedule'] ?? 'daily' ) );
		$monitor_scratch             = self::MONITOR_MODE === $mode ? MonitorOutcome::sanitize_scratch( $data['monitor_scratch'] ?? '' ) : '';
		$monitor_event_sources       = self::MONITOR_MODE === $mode ? self::get_monitor_event_sources( [ 'monitor_event_sources' => $data['monitor_event_sources'] ?? [] ] ) : [];
		$monitor_event_wakes_enabled = self::MONITOR_MODE === $mode && ! empty( $data['monitor_event_wakes_enabled'] ) ? 1 : 0;
		$now                         = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query; caching not applicable.
		$result = $wpdb->insert(
			self::table_name(),
			[
				// @phpstan-ignore-next-line
				'name'                        => sanitize_text_field( $data['name'] ?? '' ),
				// @phpstan-ignore-next-line
				'description'                 => sanitize_textarea_field( $data['description'] ?? '' ),
				// @phpstan-ignore-next-line
				'prompt'                      => wp_kses_post( $data['prompt'] ?? '' ),
				'mode'                        => $mode,
				'monitor_scratch'             => $monitor_scratch,
				'monitor_event_wakes_enabled' => $monitor_event_wakes_enabled,
				'monitor_event_sources'       => wp_json_encode( $monitor_event_sources ) ?: '[]',
				'monitor_wake_cooldown_until' => null,
				'monitor_wake_dropped_count'  => 0,
				'monitor_wake_deferred_count' => 0,
				// @phpstan-ignore-next-line
				'schedule'                    => $schedule,
				// @phpstan-ignore-next-line
				'cron_expression'             => sanitize_text_field( $data['cron_expression'] ?? '' ),
				// @phpstan-ignore-next-line
				'tool_profile'                => sanitize_text_field( $data['tool_profile'] ?? '' ),
				// @phpstan-ignore-next-line
				'owner_user_id'               => absint( $data['owner_user_id'] ?? 0 ),
				// @phpstan-ignore-next-line
				'max_iterations'              => absint( $data['max_iterations'] ?? 10 ),
				// @phpstan-ignore-next-line
				'enabled'                     => $enabled,
				'notification_channels'       => self::sanitize_notification_channels( $data['notification_channels'] ?? [] ),
				'last_run_at'                 => null,
				'next_run_at'                 => null,
				'run_count'                   => 0,
				'active_run_id'               => '',
				'execution_status'            => 'idle',
				'lease_expires_at'            => null,
				'last_run_id'                 => '',
				'last_run_status'             => '',
				'last_run_error'              => '',
				'last_monitor_outcome'        => '',
				'last_monitor_summary'        => '',
				'created_at'                  => $now,
				'updated_at'                  => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( ! $result ) {
			return false;
		}

		$id = (int) $wpdb->insert_id;

		// Schedule cron if enabled.
		if ( $enabled ) {
			AutomationRunner::schedule( $id, $schedule );
		}

		return $id;
	}

	/**
	 * Update an existing automation.
	 *
	 * @param int                  $id   Automation ID.
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

		$validation = self::validate_definition( $data, $existing );
		if ( is_wp_error( $validation ) ) {
			return false;
		}

		$update        = [];
		$formats       = [];
		$existing_mode = self::get_mode( $existing );
		$new_mode      = $existing_mode;

		if ( array_key_exists( 'mode', $data ) ) {
			$new_mode = self::sanitize_mode( $data['mode'] );
			if ( ! in_array( $new_mode, self::VALID_MODES, true ) ) {
				return false;
			}

			$update['mode'] = $new_mode;
			$formats[]      = '%s';
		}

		$string_fields = [ 'name', 'description', 'prompt', 'schedule', 'cron_expression', 'tool_profile' ];
		foreach ( $string_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$sanitize = 'prompt' === $field ? 'wp_kses_post' : 'sanitize_text_field';
				if ( 'description' === $field ) {
					$sanitize = 'sanitize_textarea_field';
				}
				// @phpstan-ignore-next-line
				$update[ $field ] = $sanitize( $data[ $field ] );
				$formats[]        = '%s';
			}
		}

		if ( isset( $data['max_iterations'] ) ) {
			// @phpstan-ignore-next-line
			$update['max_iterations'] = absint( $data['max_iterations'] );
			$formats[]                = '%d';
		}

		if ( isset( $data['owner_user_id'] ) ) {
			// @phpstan-ignore-next-line
			$update['owner_user_id'] = absint( $data['owner_user_id'] );
			$formats[]               = '%d';
		}

		if ( array_key_exists( 'monitor_scratch', $data ) ) {
			if ( self::MONITOR_MODE !== $new_mode || ! is_scalar( $data['monitor_scratch'] ) ) {
				return false;
			}

			$update['monitor_scratch'] = MonitorOutcome::sanitize_scratch( $data['monitor_scratch'] );
			$formats[]                 = '%s';
		}

		$event_wake_sources_changed  = false;
		$event_wakes_enabled_changed = false;
		if ( array_key_exists( 'monitor_event_sources', $data ) ) {
			if ( self::MONITOR_MODE !== $new_mode ) {
				return false;
			}

			$sources = self::sanitize_monitor_event_sources( $data['monitor_event_sources'] );
			if ( is_wp_error( $sources ) ) {
				return false;
			}

			$existing_sources   = self::get_monitor_event_sources( $existing );
			$comparison_sources = $sources;
			sort( $existing_sources );
			sort( $comparison_sources );

			$update['monitor_event_sources'] = wp_json_encode( $sources ) ?: '[]';
			$formats[]                       = '%s';
			$event_wake_sources_changed      = $comparison_sources !== $existing_sources;
		}

		if ( array_key_exists( 'monitor_event_wakes_enabled', $data ) ) {
			if ( self::MONITOR_MODE !== $new_mode ) {
				return false;
			}

			$new_event_wakes_enabled               = ! empty( $data['monitor_event_wakes_enabled'] );
			$update['monitor_event_wakes_enabled'] = $new_event_wakes_enabled ? 1 : 0;
			$formats[]                             = '%d';
			$event_wakes_enabled_changed           = $new_event_wakes_enabled !== self::is_monitor_event_wakes_enabled( $existing );
		}

		if ( self::MONITOR_MODE === $new_mode && ( $event_wake_sources_changed || $event_wakes_enabled_changed ) ) {
			$update['monitor_wake_cooldown_until'] = null;
			$formats[]                             = '%s';
		}

		if ( array_key_exists( 'enabled', $data ) ) {
			// @phpstan-ignore-next-line
			$update['enabled'] = (int) $data['enabled'];
			$formats[]         = '%d';
		} elseif ( self::MONITOR_MODE === $new_mode && self::MONITOR_MODE !== $existing_mode ) {
			// A task becoming a Monitor requires a separately explicit enable.
			$update['enabled'] = 0;
			$formats[]         = '%d';
		}

		if ( isset( $data['notification_channels'] ) ) {
			$update['notification_channels'] = self::sanitize_notification_channels( $data['notification_channels'] );
			$formats[]                       = '%s';
		}

		if ( self::TASK_MODE === $new_mode && self::MONITOR_MODE === $existing_mode ) {
			$update['monitor_scratch']             = '';
			$update['monitor_event_wakes_enabled'] = 0;
			$update['monitor_event_sources']       = '[]';
			$update['monitor_wake_cooldown_until'] = null;
			$update['last_monitor_outcome']        = '';
			$update['last_monitor_summary']        = '';
			$formats[]                             = '%s';
			$formats[]                             = '%d';
			$formats[]                             = '%s';
			$formats[]                             = '%s';
			$formats[]                             = '%s';
			$formats[]                             = '%s';
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

		// Reschedule cron based on new state.
		$new_enabled  = array_key_exists( 'enabled', $data ) ? (bool) $data['enabled'] : (bool) $existing['enabled'];
		$new_schedule = isset( $data['schedule'] ) ? sanitize_key( (string) $data['schedule'] ) : (string) $existing['schedule'];
		if ( self::MONITOR_MODE === $new_mode && self::MONITOR_MODE !== $existing_mode && ! array_key_exists( 'enabled', $data ) ) {
			$new_enabled = false;
		}

		$new_event_wakes_enabled = self::MONITOR_MODE === $new_mode
			&& ( array_key_exists( 'monitor_event_wakes_enabled', $data )
				? ! empty( $data['monitor_event_wakes_enabled'] )
				: self::is_monitor_event_wakes_enabled( $existing ) );

		if ( false !== $result ) {
			AutomationRunner::unschedule( $id );
			if ( $new_enabled ) {
				AutomationRunner::schedule( $id, $new_schedule );
			}

			if ( ! $new_enabled || ! $new_event_wakes_enabled || $event_wake_sources_changed || self::TASK_MODE === $new_mode ) {
				MonitorWakeQueue::clear_for_monitor( $id );
			}
		}

		return $result !== false;
	}

	/**
	 * Delete an automation.
	 *
	 * @param int $id Automation ID.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		AutomationRunner::unschedule( $id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->delete(
			self::table_name(),
			[ 'id' => $id ],
			[ '%d' ]
		);

		if ( (int) $result <= 0 ) {
			return false;
		}

		// Delete retained event evidence after the definition so a concurrent
		// capture cannot create a new authorized wake after this cleanup completes.
		MonitorWakeQueue::clear_for_monitor( $id );
		AutomationLogs::delete_for_automation( $id );

		return true;
	}

	/** Record one bounded event that could not be retained in the queue. */
	public static function record_monitor_wake_drop( int $id ): void {
		self::increment_monitor_wake_counter( $id, 'monitor_wake_dropped_count' );
	}

	/** Record one safe-to-retry event wake deferral. */
	public static function record_monitor_wake_deferral( int $id ): void {
		self::increment_monitor_wake_counter( $id, 'monitor_wake_deferred_count' );
	}

	/** Set the bounded cooldown deadline after a Monitor event wake completes. */
	public static function set_monitor_wake_cooldown( int $id, int $seconds ): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( $id <= 0 ) {
			return;
		}

		$wpdb->update(
			self::table_name(),
			[
				'monitor_wake_cooldown_until' => gmdate( 'Y-m-d H:i:s', time() + max( 0, $seconds ) ),
				'updated_at'                  => current_time( 'mysql', true ),
			],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Update run metadata after execution.
	 *
	 * @param int    $id       Automation ID.
	 * @param string $run_time MySQL datetime of the run.
	 */
	public static function record_run( int $id, string $run_time ): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET last_run_at = %s, run_count = run_count + 1, updated_at = %s WHERE id = %d',
				self::table_name(),
				$run_time,
				$run_time,
				$id
			)
		);
	}

	/**
	 * Atomically claim one enabled automation run for a bounded lease.
	 *
	 * A WP-Cron event can be delivered more than once. Only the caller that
	 * changes the durable row may continue into credential loading, model
	 * execution, or tool calls.
	 *
	 * @param int    $id               Automation ID.
	 * @param string $run_id           Correlation UUID for this delivery.
	 * @param string $lease_expires_at UTC MySQL datetime when the claim expires.
	 * @return bool True when this delivery owns the execution claim.
	 */
	public static function claim_run( int $id, string $run_id, string $lease_expires_at ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( $id <= 0 || '' === $run_id || '' === $lease_expires_at ) {
			return false;
		}

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional status transition is the durable worker claim.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET active_run_id = %s, execution_status = 'claimed', lease_expires_at = %s, updated_at = %s WHERE id = %d AND enabled = 1 AND active_run_id = ''",
				self::table_name(),
				$run_id,
				$lease_expires_at,
				$now,
				$id
			)
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Transition a claimed run to running before provider work starts.
	 *
	 * @param int    $id     Automation ID.
	 * @param string $run_id Correlation UUID for this delivery.
	 * @return bool True when the run remains owned by this delivery.
	 */
	public static function mark_run_running( int $id, string $run_id ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional lifecycle transition prevents stale deliveries from reviving another run.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET execution_status = 'running', updated_at = %s WHERE id = %d AND active_run_id = %s AND execution_status = 'claimed'",
				self::table_name(),
				current_time( 'mysql', true ),
				$id,
				$run_id
			)
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Complete an owned run and release its durable execution claim.
	 *
	 * @param int    $id     Automation ID.
	 * @param string $run_id Correlation UUID for this delivery.
	 * @param string $status Terminal lifecycle status.
	 * @param string $error           Safe operator-facing failure detail.
	 * @param string $monitor_outcome Valid monitor outcome, if this was a Monitor run.
	 * @param string $monitor_summary Safe Monitor summary, if this was a Monitor run.
	 * @return bool True when the owned claim was released.
	 */
	public static function finish_run( int $id, string $run_id, string $status, string $error = '', string $monitor_outcome = '', string $monitor_summary = '' ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( ! in_array( $status, self::TERMINAL_RUN_STATUSES, true ) ) {
			return false;
		}

		$now             = current_time( 'mysql', true );
		$safe_error      = 'succeeded' === $status ? '' : JobErrorSanitizer::sanitize( $error, 500 );
		$stored_status   = '' !== $safe_error ? $safe_error : ( 'succeeded' === $status ? '' : __( 'Automation execution did not complete.', 'superdav-ai-agent' ) );
		$monitor_outcome = MonitorOutcome::is_valid( $monitor_outcome ) ? $monitor_outcome : '';
		$monitor_summary = '' === $monitor_outcome ? '' : MonitorOutcome::sanitize_summary( $monitor_summary );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional lifecycle transition prevents an expired worker from clearing a newer run.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET execution_status = %s, lease_expires_at = NULL, last_run_id = %s, last_run_status = %s, last_run_error = %s, last_monitor_outcome = %s, last_monitor_summary = %s, last_run_at = %s, run_count = run_count + 1, active_run_id = '', updated_at = %s WHERE id = %d AND active_run_id = %s AND execution_status IN ('claimed', 'running')",
				self::table_name(),
				$status,
				$run_id,
				$status,
				$stored_status,
				$monitor_outcome,
				$monitor_summary,
				$now,
				$now,
				$id,
				$run_id
			)
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Mark safely recoverable expired claims abandoned with their matching log.
	 *
	 * A live worker holds the advisory execution fence through provider and tool
	 * work, so recovery skips it even after its diagnostic lease expires. Both
	 * tables transition in one transaction to keep the correlation ID and
	 * lifecycle outcome consistent for operators.
	 *
	 * @return int Number of released automation claims.
	 */
	public static function abandon_expired_runs(): int {
		$abandoned = 0;
		$reason    = __( 'The automation execution lease expired before completion.', 'superdav-ai-agent' );

		foreach ( self::get_expired_run_candidates() as $candidate ) {
			$automation_id   = (int) $candidate['id'];
			$run_id          = (string) $candidate['run_id'];
			$monitor_outcome = self::MONITOR_MODE === $candidate['mode'] ? 'error' : '';
			$lock_name       = self::acquire_execution_lock( $automation_id );
			if ( null === $lock_name ) {
				continue;
			}

			try {
				if ( ! self::begin_lifecycle_transaction() ) {
					continue;
				}

				$log_abandoned        = AutomationLogs::abandon_run( $automation_id, $run_id, $reason, $monitor_outcome );
				$automation_abandoned = self::abandon_expired_run( $automation_id, $run_id, $reason, $monitor_outcome );
				if ( ! $log_abandoned || ! $automation_abandoned || ! self::commit_lifecycle_transaction() ) {
					self::rollback_lifecycle_transaction();
					continue;
				}

				++$abandoned;
			} finally {
				self::release_execution_lock( $lock_name );
			}
		}

		return $abandoned;
	}

	/**
	 * Get bounded candidates whose durable lease has elapsed.
	 *
	 * @return list<array{id:int,run_id:string,mode:string}>
	 */
	private static function get_expired_run_candidates(): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Candidate discovery is followed by a per-row advisory lock and conditional transition.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, active_run_id, mode FROM %i WHERE active_run_id != '' AND execution_status IN ('claimed', 'running') AND lease_expires_at IS NOT NULL AND lease_expires_at < %s ORDER BY id ASC LIMIT %d",
				self::table_name(),
				$now,
				self::STALE_RUN_BATCH_SIZE
			)
		);

		$candidates = [];
		foreach ( $rows ?: [] as $row ) {
			$id     = isset( $row->id ) ? (int) $row->id : 0;
			$run_id = isset( $row->active_run_id ) ? (string) $row->active_run_id : '';
			if ( $id > 0 && '' !== $run_id ) {
				$candidates[] = [
					'id'     => $id,
					'run_id' => $run_id,
					'mode'   => self::get_mode( [ 'mode' => $row->mode ?? self::TASK_MODE ] ),
				];
			}
		}

		return $candidates;
	}

	/**
	 * Conditionally abandon one expired owned claim.
	 *
	 * @param int    $id              Automation ID.
	 * @param string $run_id          Correlation UUID for the execution.
	 * @param string $reason          Safe operator-facing failure reason.
	 * @param string $monitor_outcome Valid monitor outcome when this was a Monitor.
	 */
	private static function abandon_expired_run( int $id, string $run_id, string $reason, string $monitor_outcome ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( $id <= 0 || '' === $run_id ) {
			return false;
		}

		$now             = current_time( 'mysql', true );
		$monitor_outcome = MonitorOutcome::is_valid( $monitor_outcome ) ? $monitor_outcome : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional terminal transition retains the durable owner and prevents stale recovery from overwriting a renewed run.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET execution_status = 'abandoned', last_run_id = active_run_id, last_run_status = 'abandoned', last_run_error = %s, last_monitor_outcome = %s, last_monitor_summary = '', last_run_at = %s, run_count = run_count + 1, active_run_id = '', lease_expires_at = NULL, updated_at = %s WHERE id = %d AND active_run_id = %s AND execution_status IN ('claimed', 'running') AND lease_expires_at IS NOT NULL AND lease_expires_at < %s",
				self::table_name(),
				JobErrorSanitizer::sanitize( $reason, 500 ),
				$monitor_outcome,
				$now,
				$now,
				$id,
				$run_id,
				$now
			)
		);

		return false !== $result && $result > 0;
	}

	/** Begin one guarded cross-table lifecycle transaction. */
	public static function begin_lifecycle_transaction(): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( self::$lifecycle_transaction_active ) {
			return false;
		}

		// Respect an outer transaction, including PHPUnit's database isolation,
		// by using a savepoint rather than implicitly committing it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Detects an outer transaction before creating the short lifecycle scope.
		$has_outer_transaction = 1 === (int) $wpdb->get_var( 'SELECT @@in_transaction' );
		$query                 = $has_outer_transaction ? 'SAVEPOINT ' . self::LIFECYCLE_SAVEPOINT : 'START TRANSACTION';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Coordinates durable automation and log lifecycle rows without nesting transactions.
		if ( false === $wpdb->query( $query ) ) {
			return false;
		}

		self::$lifecycle_transaction_active         = true;
		self::$lifecycle_transaction_uses_savepoint = $has_outer_transaction;

		return true;
	}

	/** Commit one guarded cross-table lifecycle transaction. */
	public static function commit_lifecycle_transaction(): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( ! self::$lifecycle_transaction_active ) {
			return false;
		}

		$query = self::$lifecycle_transaction_uses_savepoint ? 'RELEASE SAVEPOINT ' . self::LIFECYCLE_SAVEPOINT : 'COMMIT';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Coordinates durable automation and log lifecycle rows.
		$committed = false !== $wpdb->query( $query );
		if ( $committed ) {
			self::$lifecycle_transaction_active         = false;
			self::$lifecycle_transaction_uses_savepoint = false;
		}

		return $committed;
	}

	/** Roll back one guarded cross-table lifecycle transaction. */
	public static function rollback_lifecycle_transaction(): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( ! self::$lifecycle_transaction_active ) {
			return;
		}

		if ( self::$lifecycle_transaction_uses_savepoint ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Restores the lifecycle scope without rolling back the outer transaction.
			$wpdb->query( 'ROLLBACK TO SAVEPOINT ' . self::LIFECYCLE_SAVEPOINT );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Clears the scoped savepoint after rollback.
			$wpdb->query( 'RELEASE SAVEPOINT ' . self::LIFECYCLE_SAVEPOINT );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Restores both lifecycle rows when either guarded transition fails.
			$wpdb->query( 'ROLLBACK' );
		}

		self::$lifecycle_transaction_active         = false;
		self::$lifecycle_transaction_uses_savepoint = false;
	}

	/** Build an opaque database advisory-lock name for one automation row. */
	private static function execution_lock_name( int $id ): string {
		return self::EXECUTION_LOCK_PREFIX . substr( hash( 'sha256', self::table_name() . ':' . $id ), 0, 32 );
	}

	/**
	 * Resolve an automation's stored tool profile to an explicit allowlist.
	 *
	 * A blank legacy profile deliberately preserves the global tool policy. A
	 * non-empty profile must resolve to an explicit allowlist so it can never
	 * silently broaden a scheduled run's access.
	 *
	 * @param array<string, mixed> $automation Automation definition.
	 * @return list<string>|null|WP_Error Null when no profile restriction exists.
	 */
	public static function resolve_tool_profile( array $automation ): array|WP_Error|null {
		$profile = sanitize_key( (string) ( $automation['tool_profile'] ?? '' ) );
		if ( '' === $profile ) {
			return null;
		}

		/**
		 * Filter the explicit ability allowlist for a scheduled automation profile.
		 *
		 * Return an array of canonical ability IDs. Returning null for a non-empty
		 * profile blocks the run rather than exposing the unrestricted catalog.
		 *
		 * @param list<string>|null    $abilities  Built-in or previously filtered allowlist.
		 * @param string               $profile    Sanitized stored profile slug.
		 * @param array<string, mixed> $automation Automation definition.
		 */
		$abilities = apply_filters(
			'sd_ai_agent_automation_tool_profile_abilities',
			self::BUILTIN_TOOL_PROFILES[ $profile ] ?? null,
			$profile,
			$automation
		);

		if ( ! is_array( $abilities ) ) {
			return new WP_Error(
				'sd_ai_agent_automation_unknown_tool_profile',
				__( 'The automation tool profile must be reconfigured before this automation can run.', 'superdav-ai-agent' )
			);
		}

		$clean = array();
		foreach ( $abilities as $ability ) {
			if ( ! is_string( $ability ) ) {
				continue;
			}

			$ability = trim( sanitize_text_field( $ability ) );
			if ( '' !== $ability ) {
				$clean[ $ability ] = true;
			}
		}

		if ( empty( $clean ) ) {
			return new WP_Error(
				'sd_ai_agent_automation_empty_tool_profile',
				__( 'The automation tool profile has no allowed abilities and must be reconfigured.', 'superdav-ai-agent' )
			);
		}

		return array_keys( $clean );
	}

	/**
	 * Get pre-built automation templates.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function get_templates(): array {
		return [
			[
				'name'         => __( 'Daily Site Health Report', 'superdav-ai-agent' ),
				'description'  => __( 'Run a comprehensive automated site health check covering plugins, errors, disk space, security, and performance.', 'superdav-ai-agent' ),
				'prompt'       => "Run a full site health check using the site-health-summary tool. It will check:\n1. Plugin updates available\n2. PHP error log (last 24 hours)\n3. Disk space usage\n4. Security issues (debug mode, file editor, WP version, admin username, SSL)\n5. Performance indicators (autoloaded options, transients, object cache)\n\nAfter getting the summary, provide a concise report with:\n- Overall status (healthy / needs_attention / critical)\n- Any critical issues that need immediate action\n- Warnings to address soon\n- A brief summary of what is working well\n\nKeep the report clear and actionable.",
				'schedule'     => 'daily',
				'tool_profile' => 'site-health',
			],
			[
				'name'        => __( 'Daily Google Calendar SMS Reminders', 'superdav-ai-agent' ),
				'description' => __( 'Check upcoming Google Calendar events daily and send or queue TextBee SMS reminders only for mapped, consenting attendees.', 'superdav-ai-agent' ),
				'prompt'      => 'Run sd-ai-agent/calendar-send-sms-reminders with calendar_id=primary, lookahead_hours=24, approval_mode=require_approval, max_events=10, and max_recipients=50. Report the structured sent, skipped, pending, and failed counts. Do not manually chain lower-level calendar, contact, or SMS tools for this workflow.',
				'schedule'    => 'daily',
			],
			[
				'name'        => __( 'Weekly Plugin Update Check', 'superdav-ai-agent' ),
				'description' => __( 'Check for plugin updates and report what needs updating.', 'superdav-ai-agent' ),
				'prompt'      => "List all plugins that have updates available. For each:\n- Plugin name and current version\n- Available version\n- Whether it's a major, minor, or patch update\n\nDo NOT update any plugins — just report.",
				'schedule'    => 'weekly',
			],
			[
				'name'        => __( 'Content Moderation', 'superdav-ai-agent' ),
				'description' => __( 'Review recent comments for spam or inappropriate content.', 'superdav-ai-agent' ),
				'prompt'      => 'Review pending comments from the last 24 hours. Flag any that appear to be spam, contain inappropriate language, or are off-topic. Provide a summary of reviewed vs flagged comments.',
				'schedule'    => 'daily',
			],
			[
				'name'        => __( 'Broken Link Check', 'superdav-ai-agent' ),
				'description' => __( 'Scan recent posts for broken links.', 'superdav-ai-agent' ),
				'prompt'      => 'Check the 10 most recent published posts for any broken external links. For each broken link found, report the post title, the broken URL, and the HTTP status code.',
				'schedule'    => 'weekly',
			],
			[
				'name'        => __( 'Database Optimization', 'superdav-ai-agent' ),
				'description' => __( 'Clean up transients, revisions, and optimize tables.', 'superdav-ai-agent' ),
				'prompt'      => "Perform database maintenance:\n1. Delete expired transients\n2. Report how many post revisions exist\n3. Report autoloaded option size\n4. List any database tables that could benefit from optimization\n\nDo NOT delete revisions — just report.",
				'schedule'    => 'weekly',
			],
			[
				'name'        => __( 'Weekly SEO Health Report', 'superdav-ai-agent' ),
				'description' => __( 'Audit your homepage and top pages for SEO issues.', 'superdav-ai-agent' ),
				'prompt'      => "Run an SEO audit on the site's homepage using the seo-audit-url tool. Then check the 5 most recent published posts with seo-analyze-content. Report:\n1. Homepage SEO score and issues\n2. Posts missing meta descriptions\n3. Posts with titles that are too long or too short\n4. Images missing alt text\n5. Any technical SEO concerns\n\nProvide a prioritized action list.",
				'schedule'    => 'weekly',
			],
			[
				'name'        => __( 'Monthly Content Performance Report', 'superdav-ai-agent' ),
				'description' => __( 'Summarize content publishing activity and performance.', 'superdav-ai-agent' ),
				'prompt'      => "Generate a content performance report for the last 30 days using the content-performance-report tool. Also run content-analyze to check content health. Report:\n1. Posts published this month vs last month\n2. Content by category breakdown\n3. Average word count\n4. Posts missing featured images\n5. Draft posts pending review\n6. Content recommendations for next month",
				'schedule'    => 'weekly',
			],
		];
	}

	/**
	 * Decode a database row into an array with parsed JSON.
	 *
	 * @param object $row Database row.
	 * @return array<string, mixed>
	 */
	private static function decode_row( object $row ): array {
		$channels_raw = $row->notification_channels ?? '';
		$channels     = [];
		$mode         = self::get_mode( [ 'mode' => $row->mode ?? self::TASK_MODE ] );
		if ( ! empty( $channels_raw ) ) {
			$decoded  = json_decode( $channels_raw, true );
			$channels = is_array( $decoded ) ? $decoded : [];
		}

		return [
			'id'                          => (int) $row->id,
			'name'                        => $row->name,
			'description'                 => $row->description,
			'prompt'                      => $row->prompt,
			'mode'                        => $mode,
			'monitor_scratch'             => (string) ( $row->monitor_scratch ?? '' ),
			'monitor_event_wakes_enabled' => self::MONITOR_MODE === $mode && ! empty( $row->monitor_event_wakes_enabled ),
			'monitor_event_sources'       => self::get_monitor_event_sources( [ 'monitor_event_sources' => $row->monitor_event_sources ?? '' ] ),
			'monitor_wake_cooldown_until' => $row->monitor_wake_cooldown_until ?? null,
			'monitor_wake_dropped_count'  => (int) ( $row->monitor_wake_dropped_count ?? 0 ),
			'monitor_wake_deferred_count' => (int) ( $row->monitor_wake_deferred_count ?? 0 ),
			'monitor_timing_help'         => self::MONITOR_MODE === $mode ? self::get_monitor_timing_help() : '',
			'schedule'                    => $row->schedule,
			'cron_expression'             => $row->cron_expression,
			'tool_profile'                => $row->tool_profile,
			'owner_user_id'               => (int) ( $row->owner_user_id ?? 0 ),
			'max_iterations'              => (int) $row->max_iterations,
			'enabled'                     => (bool) $row->enabled,
			'notification_channels'       => $channels,
			'last_run_at'                 => $row->last_run_at,
			'next_run_at'                 => $row->next_run_at,
			'run_count'                   => (int) $row->run_count,
			'active_run_id'               => (string) ( $row->active_run_id ?? '' ),
			'execution_status'            => (string) ( $row->execution_status ?? 'idle' ),
			'lease_expires_at'            => $row->lease_expires_at ?? null,
			'last_run_id'                 => (string) ( $row->last_run_id ?? '' ),
			'last_run_status'             => (string) ( $row->last_run_status ?? '' ),
			'last_run_error'              => (string) ( $row->last_run_error ?? '' ),
			'last_monitor_outcome'        => (string) ( $row->last_monitor_outcome ?? '' ),
			'last_monitor_summary'        => (string) ( $row->last_monitor_summary ?? '' ),
			'created_at'                  => $row->created_at,
			'updated_at'                  => $row->updated_at,
		];
	}

	/**
	 * Normalize a mode without silently converting unknown values to task mode.
	 *
	 * @param mixed $mode Candidate mode.
	 */
	private static function sanitize_mode( $mode ): string {
		return is_scalar( $mode ) ? strtolower( trim( (string) $mode ) ) : '';
	}

	/**
	 * Validate and normalize the explicitly selected Monitor event sources.
	 *
	 * @param mixed $sources Candidate source list.
	 * @return list<string>|WP_Error
	 */
	private static function sanitize_monitor_event_sources( $sources ): array|WP_Error {
		if ( ! is_array( $sources ) || array_is_list( $sources ) === false ) {
			return new WP_Error(
				'sd_ai_agent_automation_invalid_monitor_event_sources',
				__( 'Monitor event sources must be a list of approved sources.', 'superdav-ai-agent' )
			);
		}

		$sanitized = [];
		foreach ( $sources as $source ) {
			if ( ! is_scalar( $source ) ) {
				return new WP_Error(
					'sd_ai_agent_automation_invalid_monitor_event_source',
					__( 'Each Monitor event source must be an approved source name.', 'superdav-ai-agent' )
				);
			}

			$source = sanitize_key( (string) $source );
			if ( ! EventTriggerRegistry::is_monitor_wake_source( $source ) ) {
				return new WP_Error(
					'sd_ai_agent_automation_monitor_event_source_not_allowed',
					__( 'This Monitor event source is not allowed.', 'superdav-ai-agent' )
				);
			}

			$sanitized[] = $source;
		}

		$sanitized = array_values( array_unique( $sanitized ) );
		if ( count( $sanitized ) > self::MAX_MONITOR_WAKE_SOURCES ) {
			return new WP_Error(
				'sd_ai_agent_automation_too_many_monitor_event_sources',
				__( 'Too many Monitor event sources were selected.', 'superdav-ai-agent' )
			);
		}

		return $sanitized;
	}

	/** Increment one fixed, internal Monitor event-wake diagnostic counter. */
	private static function increment_monitor_wake_counter( int $id, string $column ): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( $id <= 0 || ! in_array( $column, [ 'monitor_wake_dropped_count', 'monitor_wake_deferred_count' ], true ) ) {
			return;
		}

		if ( 'monitor_wake_dropped_count' === $column ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Records one bounded dropped wake using a fixed internal column.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET monitor_wake_dropped_count = monitor_wake_dropped_count + 1, updated_at = %s WHERE id = %d',
					self::table_name(),
					current_time( 'mysql', true ),
					$id
				)
			);

			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Records one bounded deferred wake using a fixed internal column.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET monitor_wake_deferred_count = monitor_wake_deferred_count + 1, updated_at = %s WHERE id = %d',
				self::table_name(),
				current_time( 'mysql', true ),
				$id
			)
		);
	}

	/**
	 * Get a compatible mode from an existing definition or legacy database row.
	 *
	 * @param array<string, mixed> $automation Automation definition.
	 */
	private static function get_mode( array $automation ): string {
		$mode = self::sanitize_mode( $automation['mode'] ?? self::TASK_MODE );

		return in_array( $mode, self::VALID_MODES, true ) ? $mode : self::TASK_MODE;
	}
}
