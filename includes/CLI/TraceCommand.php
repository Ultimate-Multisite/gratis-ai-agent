<?php

declare(strict_types=1);
/**
 * WP-CLI commands for provider trace debugging.
 *
 * Provides `wp ai-agent trace list|show|clear` commands for inspecting
 * captured LLM provider HTTP traffic from the command line.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\CLI;

use SdAiAgent\Models\ProviderTrace;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage AI provider HTTP trace logs.
 *
 * ## EXAMPLES
 *
 *     # List recent traces
 *     wp ai-agent trace list
 *
 *     # List only errors
 *     wp ai-agent trace list --errors-only
 *
 *     # Show a specific trace
 *     wp ai-agent trace show 42
 *
 *     # Clear all traces
 *     wp ai-agent trace clear
 *
 *     # Enable/disable tracing
 *     wp ai-agent trace enable
 *     wp ai-agent trace disable
 *
 * @since 1.4.0
 */
class TraceCommand extends \WP_CLI_Command {

	/**
	 * List recent provider trace records.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Number of records to show.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--provider=<id>]
	 * : Filter by provider ID (e.g. anthropic, openai, ollama).
	 *
	 * [--errors-only]
	 * : Show only requests with errors or non-2xx status codes.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-agent trace list
	 *     wp ai-agent trace list --limit=5 --provider=anthropic
	 *     wp ai-agent trace list --errors-only --format=json
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function list( array $args, array $assoc_args ): void {
		$limit       = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 20 );
		$provider    = \WP_CLI\Utils\get_flag_value( $assoc_args, 'provider', '' );
		$errors_only = \WP_CLI\Utils\get_flag_value( $assoc_args, 'errors-only', false );
		$format      = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$filters = [
			'limit' => $limit,
		];

		if ( $provider ) {
			$filters['provider_id'] = $provider;
		}

		if ( $errors_only ) {
			$filters['errors_only'] = true;
		}

		$traces = ProviderTrace::list( $filters );

		if ( empty( $traces ) ) {
			WP_CLI::log( 'No trace records found.' );

			if ( ! ProviderTrace::is_enabled() ) {
				WP_CLI::log( '' );
				WP_CLI::log( 'Provider tracing is currently disabled. Enable it with:' );
				WP_CLI::log( '  wp ai-agent trace enable' );
			}

			return;
		}

		// Format for display.
		$rows = [];
		foreach ( $traces as $trace ) {
			$status_display = (string) $trace->status_code;
			if ( $trace->status_code < 200 || $trace->status_code >= 300 ) {
				$status_display .= ' !';
			}

			$rows[] = [
				'ID'         => $trace->id,
				'Time'       => $trace->created_at,
				'Provider'   => $trace->provider_id,
				'Model'      => $trace->model_id,
				'Status'     => $status_display,
				'Duration'   => $trace->duration_ms . 'ms',
				'CacheRead'  => $trace->cache_read_tokens,
				'CacheWrite' => $trace->cache_creation_tokens,
				'Error'      => $trace->error ? substr( $trace->error, 0, 60 ) : '',
			];
		}

		WP_CLI\Utils\format_items(
			$format,
			$rows,
			[ 'ID', 'Time', 'Provider', 'Model', 'Status', 'Duration', 'CacheRead', 'CacheWrite', 'Error' ]
		);
	}

	/**
	 * Show a specific trace record with full request/response details.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The trace record ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: yaml
	 * options:
	 *   - yaml
	 *   - json
	 * ---
	 *
	 * [--curl]
	 * : Output as a curl command instead of the full record.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-agent trace show 42
	 *     wp ai-agent trace show 42 --format=json
	 *     wp ai-agent trace show 42 --curl
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function show( array $args, array $assoc_args ): void {
		$id     = (int) $args[0];
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'yaml' );
		$curl   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'curl', false );

		$trace = ProviderTrace::get( $id );

		if ( ! $trace ) {
			WP_CLI::error( "Trace record #{$id} not found." );
		}

		if ( $curl ) {
			WP_CLI::log( ProviderTrace::to_curl( $trace ) );
			return;
		}

		// Pretty-print JSON bodies.
		$request_body  = json_decode( $trace->request_body ?? '', true );
		$response_body = json_decode( $trace->response_body ?? '', true );

		$data = [
			'ID'               => $trace->id,
			'Created'          => $trace->created_at,
			'Provider'         => $trace->provider_id,
			'Model'            => $trace->model_id,
			'URL'              => $trace->url,
			'Method'           => $trace->method,
			'Status'           => $trace->status_code,
			'Duration'         => $trace->duration_ms . 'ms',
			'Cache Read'       => $trace->cache_read_tokens . ' tokens',
			'Cache Write'      => $trace->cache_creation_tokens . ' tokens',
			'Error'            => $trace->error ?: '(none)',
			'Request Headers'  => json_decode( $trace->request_headers ?? '{}', true ),
			'Request Body'     => $request_body ?: $trace->request_body,
			'Response Headers' => json_decode( $trace->response_headers ?? '{}', true ),
			'Response Body'    => $response_body ?: $trace->response_body,
		];

		if ( 'json' === $format ) {
			WP_CLI::log( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} else {
			// YAML-like output.
			self::print_yaml( $data );
		}
	}

	/**
	 * Clear all trace records.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-agent trace clear
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function clear( array $args, array $assoc_args ): void {
		ProviderTrace::clear();
		WP_CLI::success( 'All trace records cleared.' );
	}

	/**
	 * Enable provider tracing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-agent trace enable
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function enable( array $args, array $assoc_args ): void {
		ProviderTrace::set_enabled( true );
		WP_CLI::success( 'Provider tracing enabled.' );
		WP_CLI::warning( 'Logs may contain prompt content. Disable on shared environments.' );
	}

	/**
	 * Disable provider tracing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-agent trace disable
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function disable( array $args, array $assoc_args ): void {
		ProviderTrace::set_enabled( false );
		WP_CLI::success( 'Provider tracing disabled.' );
	}

	/**
	 * Show current trace status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-agent trace status
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$enabled  = ProviderTrace::is_enabled();
		$max_rows = ProviderTrace::get_max_rows();
		$count    = ProviderTrace::count();

		WP_CLI::log( 'Provider Trace Status:' );
		WP_CLI::log( '  Enabled:    ' . ( $enabled ? 'Yes' : 'No' ) );
		WP_CLI::log( "  Max rows:   {$max_rows}" );
		WP_CLI::log( "  Stored:     {$count}" );
	}

	/**
	 * Print data in a YAML-like format for readability.
	 *
	 * @param array<string, mixed> $data Data to print.
	 * @param int                  $indent Indentation level.
	 */
	private static function print_yaml( array $data, int $indent = 0 ): void {
		$prefix = str_repeat( '  ', $indent );

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				WP_CLI::log( "{$prefix}{$key}:" );
				if ( array_is_list( $value ) ) {
					foreach ( $value as $item ) {
						if ( is_array( $item ) ) {
							WP_CLI::log( "{$prefix}  -" );
							self::print_yaml( $item, $indent + 2 );
						} else {
							WP_CLI::log( "{$prefix}  - " . (string) $item );
						}
					}
				} else {
					self::print_yaml( $value, $indent + 1 );
				}
			} elseif ( is_string( $value ) && strlen( $value ) > 120 ) {
				WP_CLI::log( "{$prefix}{$key}: |" );
				foreach ( explode( "\n", $value ) as $line ) {
					WP_CLI::log( "{$prefix}  {$line}" );
				}
			} else {
				WP_CLI::log( "{$prefix}{$key}: " . (string) $value );
			}
		}
	}
}
