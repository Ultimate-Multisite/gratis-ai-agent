<?php

declare(strict_types=1);

namespace SdAiAgentAdvanced\Bootstrap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SdAiAgent\Tools\CustomTools;
use WP_Error;
use XWP\DI\Decorators\Filter;
use XWP\DI\Decorators\Handler;

/**
 * Adds the WP-CLI custom-tool type to core custom tools.
 */
#[Handler(
	container: 'sd-ai-agent',
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class CustomToolCliHandler {

	/**
	 * Add the advanced CLI type to the custom-tool type allowlist.
	 *
	 * @param array<int,string> $types Existing tool types.
	 * @return array<int,string>
	 */
	#[Filter( tag: 'sd_ai_agent_custom_tool_types', priority: 10 )]
	public function add_cli_type( array $types ): array {
		$types[] = CustomTools::TYPE_CLI;
		return array_values( array_unique( $types ) );
	}

	/**
	 * Validate CLI custom-tool configuration.
	 *
	 * @param mixed               $result Existing validation result.
	 * @param array<string,mixed> $data   Tool data.
	 * @param array<string,mixed> $config Tool config.
	 * @return mixed
	 */
	#[Filter( tag: 'sd_ai_agent_validate_custom_tool_config', priority: 10, args: 3 )]
	public function validate_cli_config( mixed $result, array $data, array $config ): mixed {
		if ( CustomTools::TYPE_CLI !== ( $data['type'] ?? '' ) ) {
			return $result;
		}

		if ( empty( $config['command'] ) ) {
			return new WP_Error( 'missing_command', __( 'CLI tools require a command template.', 'superdav-ai-agent' ) );
		}

		return $config;
	}

	/**
	 * Execute CLI custom tools.
	 *
	 * @param mixed               $result Existing execution result.
	 * @param array<string,mixed> $tool   Tool definition.
	 * @param array<string,mixed> $input  Input parameters.
	 * @return mixed
	 */
	#[Filter( tag: 'sd_ai_agent_execute_custom_tool', priority: 10, args: 3 )]
	public function execute_cli_tool( mixed $result, array $tool, array $input ): mixed {
		if ( CustomTools::TYPE_CLI !== ( $tool['type'] ?? '' ) ) {
			return $result;
		}

		$config  = is_array( $tool['config'] ?? null ) ? $tool['config'] : array();
		$command = (string) ( $config['command'] ?? '' );

		if ( '' === $command ) {
			return new WP_Error( 'missing_config', __( 'No command configured.', 'superdav-ai-agent' ) );
		}

		$command = self::replace_placeholders_escaped( $command, $input );
		$command = (string) preg_replace( '/[;&|`$]/', '', $command );

		$wp_cli_path  = defined( 'WP_CLI_PATH' ) ? (string) constant( 'WP_CLI_PATH' ) : 'wp';
		$full_command = sprintf(
			'%s %s --path=%s 2>&1',
			escapeshellcmd( $wp_cli_path ),
			$command,
			escapeshellarg( ABSPATH )
		);

		$output      = array();
		$return_code = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Advanced plugin intentionally provides WP-CLI custom-tool execution.
		exec( $full_command, $output, $return_code );

		$output_text = implode( "\n", $output );

		return array(
			'success'     => 0 === $return_code,
			'return_code' => $return_code,
			'output'      => $output_text ?: '(no output)',
			'command'     => 'wp ' . $command,
		);
	}

	/**
	 * Replace {{placeholder}} tokens in a string with shell-escaped input values.
	 *
	 * @param string               $template Template string with placeholders.
	 * @param array<string, mixed> $input    Input values.
	 * @return string
	 */
	public static function replace_placeholders_escaped( string $template, array $input ): string {
		return (string) preg_replace_callback(
			'/\{\{(\w[\w.]*)\}\}/',
			static function ( array $matches ) use ( $input ): string {
				$key = $matches[1];

				if ( isset( $input[ $key ] ) ) {
					$value = is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : (string) wp_json_encode( $input[ $key ] );
					return escapeshellarg( $value );
				}

				if ( str_contains( $key, '.' ) ) {
					$parts = explode( '.', $key );
					$value = $input;
					foreach ( $parts as $part ) {
						if ( is_array( $value ) && isset( $value[ $part ] ) ) {
							$value = $value[ $part ];
						} else {
							return $matches[0];
						}
					}

					$scalar = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
					return escapeshellarg( $scalar );
				}

				return $matches[0];
			},
			$template
		);
	}
}
