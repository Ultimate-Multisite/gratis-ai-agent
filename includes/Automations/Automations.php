<?php

declare(strict_types=1);
/**
 * Scheduled Automations model — CRUD for cron-based AI tasks.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Automations;

class Automations {

	const VALID_SCHEDULES = [ 'hourly', 'twicedaily', 'daily', 'weekly' ];

	/**
	 * Get the automations table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_automations';
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
			if ( ! in_array( $type, [ 'slack', 'discord' ], true ) ) {
				continue;
			}
			$clean[] = [
				'type'        => $type,
				'webhook_url' => esc_url_raw( $channel['webhook_url'] ?? '' ),
				'enabled'     => ! empty( $channel['enabled'] ),
			];
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

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query; caching not applicable.
		$result = $wpdb->insert(
			self::table_name(),
			[
				// @phpstan-ignore-next-line
				'name'                  => sanitize_text_field( $data['name'] ?? '' ),
				// @phpstan-ignore-next-line
				'description'           => sanitize_textarea_field( $data['description'] ?? '' ),
				// @phpstan-ignore-next-line
				'prompt'                => wp_kses_post( $data['prompt'] ?? '' ),
				// @phpstan-ignore-next-line
				'schedule'              => sanitize_text_field( $data['schedule'] ?? 'daily' ),
				// @phpstan-ignore-next-line
				'cron_expression'       => sanitize_text_field( $data['cron_expression'] ?? '' ),
				// @phpstan-ignore-next-line
				'tool_profile'          => sanitize_text_field( $data['tool_profile'] ?? '' ),
				// @phpstan-ignore-next-line
				'max_iterations'        => absint( $data['max_iterations'] ?? 10 ),
				// @phpstan-ignore-next-line
				'enabled'               => isset( $data['enabled'] ) ? (int) $data['enabled'] : 0,
				'notification_channels' => self::sanitize_notification_channels( $data['notification_channels'] ?? [] ),
				'last_run_at'           => null,
				'next_run_at'           => null,
				'run_count'             => 0,
				'created_at'            => $now,
				'updated_at'            => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);

		if ( ! $result ) {
			return false;
		}

		$id = (int) $wpdb->insert_id;

		// Schedule cron if enabled.
		if ( ! empty( $data['enabled'] ) ) {
			// @phpstan-ignore-next-line
			AutomationRunner::schedule( $id, $data['schedule'] ?? 'daily' );
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

		$update  = [];
		$formats = [];

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

		if ( isset( $data['enabled'] ) ) {
			// @phpstan-ignore-next-line
			$update['enabled'] = (int) $data['enabled'];
			$formats[]         = '%d';
		}

		if ( isset( $data['notification_channels'] ) ) {
			$update['notification_channels'] = self::sanitize_notification_channels( $data['notification_channels'] );
			$formats[]                       = '%s';
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
		$new_enabled  = $data['enabled'] ?? $existing['enabled'];
		$new_schedule = $data['schedule'] ?? $existing['schedule'];

		AutomationRunner::unschedule( $id );
		if ( $new_enabled ) {
			// @phpstan-ignore-next-line
			AutomationRunner::schedule( $id, $new_schedule );
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

		// Delete associated logs.
		AutomationLogs::delete_for_automation( $id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$result = $wpdb->delete(
			self::table_name(),
			[ 'id' => $id ],
			[ '%d' ]
		);

		return (int) $result > 0;
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
		if ( ! empty( $channels_raw ) ) {
			$decoded  = json_decode( $channels_raw, true );
			$channels = is_array( $decoded ) ? $decoded : [];
		}

		return [
			'id'                    => (int) $row->id,
			'name'                  => $row->name,
			'description'           => $row->description,
			'prompt'                => $row->prompt,
			'schedule'              => $row->schedule,
			'cron_expression'       => $row->cron_expression,
			'tool_profile'          => $row->tool_profile,
			'max_iterations'        => (int) $row->max_iterations,
			'enabled'               => (bool) $row->enabled,
			'notification_channels' => $channels,
			'last_run_at'           => $row->last_run_at,
			'next_run_at'           => $row->next_run_at,
			'run_count'             => (int) $row->run_count,
			'created_at'            => $row->created_at,
			'updated_at'            => $row->updated_at,
		];
	}
}
