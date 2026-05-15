<?php

declare(strict_types=1);
/**
 * Provider Trace model — stores HTTP request/response pairs for LLM provider debugging.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Models;

use SdAiAgent\Core\Database;
use SdAiAgent\Models\DTO\ProviderTraceRow;

class ProviderTrace {

	/**
	 * Default maximum number of trace rows to keep (ring buffer).
	 */
	const DEFAULT_MAX_ROWS = 200;

	/**
	 * Default maximum body size in bytes before truncation.
	 */
	const DEFAULT_MAX_BODY_SIZE = 65536; // 64 KB

	/**
	 * Option name for the provider trace setting.
	 */
	const ENABLED_OPTION = 'sd_ai_agent_provider_trace_enabled';

	/**
	 * Option name for the max rows setting.
	 */
	const MAX_ROWS_OPTION = 'sd_ai_agent_provider_trace_max_rows';

	/**
	 * Get the provider trace table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'sd_ai_agent_provider_trace';
	}

	/**
	 * Get the database schema for the provider trace table.
	 *
	 * @param string $charset The charset collation string.
	 * @return string SQL CREATE TABLE statement.
	 */
	public static function get_schema( string $charset ): string {
		$table = self::table_name();

		// `cache_creation_tokens` / `cache_read_tokens` were added in
		// DB version 19.2.0 (sd-ai-bjv). They are populated from the
		// normalised provider response usage block — see
		// {@see \SdAiAgent\Core\PromptCache\CacheUsageExtractor}. dbDelta
		// adds the columns to existing tables on upgrade; pre-19.2.0 rows
		// retain the default of 0.
		//
		// `source` was added in DB version 19.3.0 to distinguish between
		// HTTP-level traces (source='http') and SDK-level traces (source='sdk').
		// dbDelta adds the column to existing tables on upgrade; pre-19.3.0 rows
		// default to 'http' (inferred from method='POST' or method='GET').
		return "\n\nCREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			provider_id varchar(100) NOT NULL DEFAULT '',
			model_id varchar(100) NOT NULL DEFAULT '',
			url varchar(2048) NOT NULL DEFAULT '',
			method varchar(10) NOT NULL DEFAULT 'POST',
			status_code int(11) NOT NULL DEFAULT 0,
			duration_ms bigint(20) unsigned NOT NULL DEFAULT 0,
			cache_creation_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			cache_read_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			request_headers longtext NOT NULL,
			request_body longtext NOT NULL,
			response_headers longtext NOT NULL,
			response_body longtext NOT NULL,
			error text NOT NULL DEFAULT '',
			source varchar(10) NOT NULL DEFAULT 'http',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY provider_id (provider_id),
			KEY status_code (status_code),
			KEY source (source)
		) {$charset};";
	}

	/**
	 * Whether the provider trace feature is available on this installation.
	 *
	 * Provider tracing is a debug-only feature. It captures raw HTTP request
	 * and response bodies from LLM provider calls, which may contain prompt
	 * content and model outputs. Availability is therefore gated on WP_DEBUG
	 * so the feature is never active on production sites.
	 *
	 * @return bool True only when the WP_DEBUG constant is defined and truthy.
	 */
	public static function is_debug_mode(): bool {
		return defined( 'WP_DEBUG' ) && (bool) WP_DEBUG;
	}

	/**
	 * Check whether provider tracing is enabled.
	 *
	 * Returns false immediately when WP_DEBUG is not active — tracing is a
	 * debug-only feature and must never run on production. When WP_DEBUG is
	 * set, checks the filter first, then the stored option.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		// Provider trace is a debug-only feature.
		if ( ! self::is_debug_mode() ) {
			return false;
		}

		/**
		 * Filter to enable/disable provider trace logging.
		 *
		 * Allows enabling tracing environmentally without touching settings.
		 * Only evaluated when WP_DEBUG is active.
		 *
		 * @param bool|null $enabled Null to defer to the option, true/false to override.
		 */
		$filter_value = apply_filters( 'sd_ai_agent_provider_trace_enabled', null );

		if ( is_bool( $filter_value ) ) {
			return $filter_value;
		}

		return (bool) get_option( self::ENABLED_OPTION, false );
	}

	/**
	 * Enable or disable provider tracing.
	 *
	 * @param bool $enabled Whether to enable tracing.
	 * @return bool
	 */
	public static function set_enabled( bool $enabled ): bool {
		return update_option( self::ENABLED_OPTION, $enabled );
	}

	/**
	 * Get the maximum number of trace rows to keep.
	 *
	 * @return int
	 */
	public static function get_max_rows(): int {
		$max = (int) get_option( self::MAX_ROWS_OPTION, self::DEFAULT_MAX_ROWS );
		return max( 10, $max );
	}

	/**
	 * Set the maximum number of trace rows to keep.
	 *
	 * @param int $max Maximum rows.
	 * @return bool
	 */
	public static function set_max_rows( int $max ): bool {
		return update_option( self::MAX_ROWS_OPTION, max( 10, $max ) );
	}

	/**
	 * Insert a trace record and trim the ring buffer.
	 *
	 * @param array<string, mixed> $data Trace data.
	 * @return int|false Inserted row ID or false on failure.
	 */
	public static function insert( array $data ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$max_body_size = self::DEFAULT_MAX_BODY_SIZE;

		$request_body  = $data['request_body'] ?? '';
		$response_body = $data['response_body'] ?? '';

		// Truncate bodies if they exceed the max size.
		if ( strlen( $request_body ) > $max_body_size ) {
			$request_body = substr( $request_body, 0, $max_body_size ) . "\n... [truncated at {$max_body_size} bytes]";
		}
		if ( strlen( $response_body ) > $max_body_size ) {
			$response_body = substr( $response_body, 0, $max_body_size ) . "\n... [truncated at {$max_body_size} bytes]";
		}

		// Redact credentials from headers and bodies.
		$request_headers  = self::redact_sensitive_data( $data['request_headers'] ?? '' );
		$response_headers = self::redact_sensitive_data( $data['response_headers'] ?? '' );
		$request_body     = self::redact_sensitive_data( $request_body );
		$response_body    = self::redact_sensitive_data( $response_body );

		// Determine source: 'sdk' if method is 'SDK', otherwise 'http'.
		$source = 'SDK' === ( $data['method'] ?? '' ) ? 'sdk' : 'http';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert; caching not applicable.
		$result = $wpdb->insert(
			self::table_name(),
			[
				'created_at'            => current_time( 'mysql', true ),
				'provider_id'           => $data['provider_id'] ?? '',
				'model_id'              => $data['model_id'] ?? '',
				'url'                   => $data['url'] ?? '',
				'method'                => $data['method'] ?? 'POST',
				'status_code'           => $data['status_code'] ?? 0,
				'duration_ms'           => $data['duration_ms'] ?? 0,
				'cache_creation_tokens' => max( 0, (int) ( $data['cache_creation_tokens'] ?? 0 ) ),
				'cache_read_tokens'     => max( 0, (int) ( $data['cache_read_tokens'] ?? 0 ) ),
				'request_headers'       => $request_headers,
				'request_body'          => $request_body,
				'response_headers'      => $response_headers,
				'response_body'         => $response_body,
				'error'                 => $data['error'] ?? '',
				'source'                => $source,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( ! $result ) {
			return false;
		}

		$insert_id = (int) $wpdb->insert_id;

		// Trim ring buffer.
		self::trim( self::get_max_rows() );

		return $insert_id;
	}

	/**
	 * Trim the trace table to the specified maximum number of rows.
	 *
	 * Deletes the oldest rows beyond the limit.
	 *
	 * @param int $max_rows Maximum rows to keep.
	 * @return int Number of rows deleted.
	 */
	public static function trim( int $max_rows ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = self::table_name();

		// Count current rows.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table query; table name from internal method.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		if ( $count <= $max_rows ) {
			return 0;
		}

		$to_delete = $count - $max_rows;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table query; table name is from internal method, not user input.
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} ORDER BY id ASC LIMIT %d",
				$to_delete
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $result !== false ? (int) $result : 0;
	}

	/**
	 * Get a single trace record by ID.
	 *
	 * @param int $id Trace ID.
	 * @return ProviderTraceRow|null Typed DTO or null when not found.
	 */
	public static function get( int $id ): ?ProviderTraceRow {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching not applicable.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				self::table_name(),
				$id
			)
		);

		return $row instanceof \stdClass ? ProviderTraceRow::from_row( $row ) : null;
	}

	/**
	 * List trace records with optional filters.
	 *
	 * @param array<string, mixed> $filters Optional: provider_id, status_code, errors_only, limit, offset.
	 * @return list<ProviderTraceRow> Array of trace row DTOs.
	 */
	public static function list( array $filters = [] ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = self::table_name();
		$where = [];

		if ( ! empty( $filters['provider_id'] ) ) {
			$where[] = $wpdb->prepare( 'provider_id = %s', $filters['provider_id'] );
		}

		if ( ! empty( $filters['status_code'] ) ) {
			$where[] = $wpdb->prepare( 'status_code = %d', $filters['status_code'] );
		}

		if ( ! empty( $filters['errors_only'] ) ) {
			$where[] = "(status_code < 200 OR status_code >= 300 OR error != '')";
		}

		$where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$limit     = min( (int) ( $filters['limit'] ?? 50 ), 200 );
		$offset    = max( (int) ( $filters['offset'] ?? 0 ), 0 );

		// Return lightweight list (no full bodies).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query; built from prepared fragments.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, provider_id, model_id, url, method, status_code, duration_ms,
					cache_creation_tokens, cache_read_tokens, error,
					LENGTH(request_body) AS request_body_size,
					LENGTH(response_body) AS response_body_size
				FROM {$table} {$where_sql}
				ORDER BY id DESC
				LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array_map( [ ProviderTraceRow::class, 'from_row' ], $rows ?? [] );
	}

	/**
	 * Get the total count of trace records with optional filters.
	 *
	 * @param array<string, mixed> $filters Optional: provider_id, status_code, errors_only.
	 * @return int Total count.
	 */
	public static function count( array $filters = [] ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = self::table_name();
		$where = [];

		if ( ! empty( $filters['provider_id'] ) ) {
			$where[] = $wpdb->prepare( 'provider_id = %s', $filters['provider_id'] );
		}

		if ( ! empty( $filters['status_code'] ) ) {
			$where[] = $wpdb->prepare( 'status_code = %d', $filters['status_code'] );
		}

		if ( ! empty( $filters['errors_only'] ) ) {
			$where[] = "(status_code < 200 OR status_code >= 300 OR error != '')";
		}

		$where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query; built from prepared fragments.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" );
	}

	/**
	 * Delete all trace records.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function clear(): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table query; table name from internal method.
		$result = $wpdb->query( "TRUNCATE TABLE {$table}" );

		return $result !== false ? (int) $result : 0;
	}

	/**
	 * Generate a curl command for reproducing a trace request.
	 *
	 * @param ProviderTraceRow $trace Typed trace DTO.
	 * @return string Curl command string.
	 */
	public static function to_curl( ProviderTraceRow $trace ): string {
		$parts = [ 'curl' ];

		$method = strtoupper( $trace->method );
		if ( 'GET' !== $method ) {
			$parts[] = '-X ' . escapeshellarg( $method );
		}

		// Parse headers (stored as JSON).
		$headers = json_decode( $trace->request_headers, true );
		if ( is_array( $headers ) ) {
			foreach ( $headers as $name => $value ) {
				// Skip authorization headers — they're redacted anyway.
				$parts[] = '-H ' . escapeshellarg( "{$name}: " . (string) $value );
			}
		}

		// Add body if present.
		$body = $trace->request_body;
		if ( '' !== $body ) {
			// Try to pretty-print JSON for readability.
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) ) {
				$body = (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			}
			$parts[] = '-d ' . escapeshellarg( $body );
		}

		$parts[] = escapeshellarg( $trace->url );

		return implode( " \\\n  ", $parts );
	}

	/**
	 * Redact sensitive data from a string (headers, bodies).
	 *
	 * Redacts:
	 * - Authorization header values
	 * - x-api-key header values
	 * - API key patterns (sk-..., key-..., etc.)
	 * - Bearer tokens
	 *
	 * @param string $content Content to redact.
	 * @return string Redacted content.
	 */
	public static function redact_sensitive_data( string $content ): string {
		if ( '' === $content ) {
			return $content;
		}

		// Try to decode as JSON for structured redaction.
		$decoded = json_decode( $content, true );
		if ( is_array( $decoded ) ) {
			$decoded = self::redact_array( $decoded );
			$result  = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES );
			return false !== $result ? $result : $content;
		}

		// Fallback: regex-based redaction for non-JSON content.
		// Redact Authorization header values.
		$content = (string) preg_replace(
			'/^(Authorization:\s*)(Bearer\s+)?.+$/mi',
			'$1$2[REDACTED]',
			$content
		);

		// Redact x-api-key header values.
		$content = (string) preg_replace(
			'/^(x-api-key:\s*).+$/mi',
			'$1[REDACTED]',
			$content
		);

		// Redact common API key patterns.
		$content = (string) preg_replace(
			'/\b(sk-[a-zA-Z0-9]{3})[a-zA-Z0-9-]{10,}\b/',
			'$1...[REDACTED]',
			$content
		);

		return $content;
	}

	/**
	 * Recursively redact sensitive keys from an array.
	 *
	 * @param array<string|int, mixed> $data Array to redact.
	 * @return array<string|int, mixed> Redacted array.
	 */
	private static function redact_array( array $data ): array {
		$sensitive_keys = [
			'authorization',
			'x-api-key',
			'api_key',
			'api-key',
			'apikey',
			'secret',
			'password',
			'token',
			'access_token',
			'private_key',
		];

		foreach ( $data as $key => $value ) {
			$lower_key = strtolower( (string) $key );

			if ( in_array( $lower_key, $sensitive_keys, true ) ) {
				$data[ $key ] = '[REDACTED]';
				continue;
			}

			if ( is_array( $value ) ) {
				$data[ $key ] = self::redact_array( $value );
			} elseif ( is_string( $value ) ) {
				// Redact Bearer tokens in string values.
				if ( preg_match( '/^Bearer\s+.+/i', $value ) ) {
					$data[ $key ] = 'Bearer [REDACTED]';
				}
				// Redact API key patterns in string values.
				$data[ $key ] = (string) preg_replace(
					'/\b(sk-[a-zA-Z0-9]{3})[a-zA-Z0-9-]{10,}\b/',
					'$1...[REDACTED]',
					(string) $data[ $key ]
				);
			}
		}

		return $data;
	}
}
