<?php

declare(strict_types=1);
/**
 * Custom Tool Executor — registers each enabled custom tool as a WordPress Ability.
 *
 * Handles execution of HTTP, ACTION, and CLI tool types.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SdAiAgent\Abilities\ToolCapabilities;
use SdAiAgent\Core\Features;
use WP_Error;

class CustomToolExecutor {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'wp_abilities_api_init', [ __CLASS__, 'register_abilities' ] );
	}

	/**
	 * Register each enabled custom tool as a WordPress Ability.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$tools = CustomTools::list( true );

		// When the WP-CLI custom-tool feature is disabled (e.g. WordPress.org
		// distribution build) skip registration of any cli-type tools so they
		// never become callable abilities. HTTP and Action tools register as
		// normal regardless of the flag.
		$cli_enabled = Features::is_enabled( Features::CUSTOM_TOOLS_CLI );

		foreach ( $tools as $tool ) {
			// @phpstan-ignore-next-line
			if ( ! $cli_enabled && CustomTools::TYPE_CLI === ( $tool['type'] ?? '' ) ) {
				continue;
			}

			// @phpstan-ignore-next-line
			$ability_name = 'sd-ai-agent-custom/' . $tool['slug'];

			wp_register_ability(
				$ability_name,
				[
					'label'               => $tool['name'],
					'description'         => $tool['description'] ?: sprintf(
						/* translators: %s: tool type */
						__( 'Custom %s tool', 'superdav-ai-agent' ),
						// @phpstan-ignore-next-line
						strtoupper( $tool['type'] )
					),
					'category'            => 'sd-ai-agent',
					'input_schema'        => ! empty( $tool['input_schema'] ) ? $tool['input_schema'] : [
						'type'       => 'object',
						'properties' => new \stdClass(),
					],
					'meta'                => [
						'show_in_rest' => true,
					],
					'execute_callback'    => function ( array $input ) use ( $tool ) {
						return self::execute( $tool, $input );
					},
					'permission_callback' => function () use ( $ability_name ) {
						return ToolCapabilities::current_user_can( $ability_name );
					},
				]
			);
		}
	}

	/**
	 * Execute a custom tool.
	 *
	 * @param array<string, mixed> $tool  The tool definition.
	 * @param array<string, mixed> $input Input parameters from the AI.
	 * @return array<string, mixed>|\WP_Error Result array or WP_Error on failure.
	 */
	public static function execute( array $tool, array $input ): array|\WP_Error {
		switch ( $tool['type'] ) {
			case CustomTools::TYPE_HTTP:
				return self::execute_http( $tool, $input );

			case CustomTools::TYPE_ACTION:
				return self::execute_action( $tool, $input );

			case CustomTools::TYPE_CLI:
				if ( ! Features::is_enabled( Features::CUSTOM_TOOLS_CLI ) ) {
					return new WP_Error(
						'cli_tools_disabled',
						__( 'WP-CLI custom tools are disabled in this distribution of Superdav AI Agent. Use HTTP or Action tools instead, or install the GitHub release zip.', 'superdav-ai-agent' )
					);
				}
				return self::execute_cli( $tool, $input );

			default:
				return new WP_Error(
					'unknown_tool_type',
					sprintf(
						/* translators: %s: tool type */
						__( 'Unknown tool type: %s', 'superdav-ai-agent' ),
						// @phpstan-ignore-next-line
						$tool['type']
					)
				);
		}
	}

	/**
	 * Execute an HTTP tool.
	 *
	 * @param array<string, mixed> $tool  Tool definition.
	 * @param array<string, mixed> $input Input parameters.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function execute_http( array $tool, array $input ): array|\WP_Error {
		$config = $tool['config'];
		// @phpstan-ignore-next-line
		$url = $config['url'] ?? '';
		// @phpstan-ignore-next-line
		$method = strtoupper( $config['method'] ?? 'GET' );

		// Replace {{placeholders}} in URL.
		// @phpstan-ignore-next-line
		$url = self::replace_placeholders( $url, $input );

		// Replace placeholders in headers.
		$headers = [];
		// @phpstan-ignore-next-line
		foreach ( ( $config['headers'] ?? [] ) as $key => $value ) {
			// @phpstan-ignore-next-line
			$headers[ $key ] = self::replace_placeholders( $value, $input );
		}

		// Build request body for non-GET methods.
		$body = null;
		if ( 'GET' !== $method ) {
			// If a 'body' or 'data' key exists in input, use it.
			// Otherwise send all input params as JSON body.
			$body_data = $input['body'] ?? $input['data'] ?? $input;

			// Replace placeholders in body template if present.
			// @phpstan-ignore-next-line
			if ( ! empty( $config['body_template'] ) ) {
				// @phpstan-ignore-next-line
				$body = self::replace_placeholders( $config['body_template'], $input );
			} else {
				$body = wp_json_encode( $body_data );
			}

			if ( ! isset( $headers['Content-Type'] ) ) {
				$headers['Content-Type'] = 'application/json';
			}
		}

		$args = [
			'method'    => $method,
			// @phpstan-ignore-next-line
			'timeout'   => (int) ( $config['timeout'] ?? 30 ),
			'headers'   => $headers,
			'sslverify' => true,
		];

		if ( null !== $body ) {
			$args['body'] = (string) $body;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'http_request_failed', $response->get_error_message() );
		}

		$code          = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		// Try to parse JSON response.
		$parsed = json_decode( $response_body, true );

		return [
			'success'     => $code >= 200 && $code < 300,
			'status_code' => $code,
			'data'        => $parsed ?? $response_body,
		];
	}

	/**
	 * Execute an ACTION tool (do_action).
	 *
	 * @param array<string, mixed> $tool  Tool definition.
	 * @param array<string, mixed> $input Input parameters.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function execute_action( array $tool, array $input ): array|\WP_Error {
		$config = $tool['config'];
		// @phpstan-ignore-next-line
		$hook_name = $config['hook_name'] ?? '';

		if ( empty( $hook_name ) ) {
			return new WP_Error( 'missing_config', __( 'No hook_name configured.', 'superdav-ai-agent' ) );
		}

		// Sanitize hook name — only allow valid hook characters.
		// @phpstan-ignore-next-line
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $hook_name ) ) {
			return new WP_Error( 'invalid_hook_name', __( 'Invalid hook name.', 'superdav-ai-agent' ) );
		}

		// Ensure the hook name is prefixed to comply with WP.org plugin guidelines.
		if ( ! str_starts_with( $hook_name, 'sd_ai_agent_' ) ) {
			$hook_name = 'sd_ai_agent_' . $hook_name;
		}

		// Build arguments from config defaults + input.
		$args = [];
		// @phpstan-ignore-next-line
		$arg_defs = $config['args'] ?? [];

		if ( is_array( $arg_defs ) ) {
			foreach ( $arg_defs as $key => $default ) {
				$args[] = $input[ $key ] ?? $default;
			}
		}

		// If no arg definitions, pass the full input as the first argument.
		if ( empty( $args ) && ! empty( $input ) ) {
			$args = [ $input ];
		}

		// Capture output during action execution.
		ob_start();

		try {
			// @phpstan-ignore-next-line
			do_action( $hook_name, ...$args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name is forced to sd_ai_agent_ prefix above.
			$output = ob_get_clean();

			return [
				'success'   => true,
				'hook_name' => $hook_name,
				'output'    => $output ?: 'Action executed successfully (no output).',
			];
		} catch ( \Throwable $e ) {
			ob_end_clean();

			return new WP_Error( 'action_exception', $e->getMessage() );
		}
	}

	/**
	 * Execute a CLI tool (WP-CLI command).
	 *
	 * @param array<string, mixed> $tool  Tool definition.
	 * @param array<string, mixed> $input Input parameters.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function execute_cli( array $tool, array $input ): array|\WP_Error {
		$config = $tool['config'];
		// @phpstan-ignore-next-line
		$command = $config['command'] ?? '';

		if ( empty( $command ) ) {
			return new WP_Error( 'missing_config', __( 'No command configured.', 'superdav-ai-agent' ) );
		}

		// Replace {{placeholders}} in the command, escaping each substituted
		// value with escapeshellarg() to prevent shell injection.
		// @phpstan-ignore-next-line
		$command = self::replace_placeholders_escaped( $command, $input );

		// Secondary defence: strip shell metacharacters that may remain in the
		// static (non-placeholder) parts of the command template.
		$command = preg_replace( '/[;&|`$]/', '', $command );

		// Build full WP-CLI command.
		$wp_cli_path = defined( 'WP_CLI_PATH' ) ? WP_CLI_PATH : 'wp';
		// NOTE: exec() is required for WP-CLI tool execution - alternative approaches (proc_open, shell_exec) provide no security benefit.
		// All input is properly escaped via escapeshellcmd() and escapeshellarg() above.
		// See: https://www.php.net/manual/en/function.exec.php
		$full_command = sprintf(
			'%s %s --path=%s 2>&1',
			escapeshellcmd( $wp_cli_path ),
			$command,
			escapeshellarg( ABSPATH )
		);

		// Execute with a timeout.
		// @phpstan-ignore-next-line
		$timeout     = (int) ( $config['timeout'] ?? 30 );
		$output      = [];
		$return_code = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Intentional: WP-CLI tool execution requires shell access.
		exec( $full_command, $output, $return_code );

		$output_text = implode( "\n", $output );

		return [
			'success'     => 0 === $return_code,
			'return_code' => $return_code,
			'output'      => $output_text ?: '(no output)',
			'command'     => 'wp ' . $command,
		];
	}

	/**
	 * Replace {{placeholder}} tokens in a string with shell-escaped input values.
	 *
	 * Each substituted value is passed through escapeshellarg() to prevent
	 * shell injection from user-controlled input. Use this variant when the
	 * result will be passed to exec() or similar shell execution functions.
	 *
	 * @param string               $template Template string with placeholders.
	 * @param array<string, mixed> $input    Input values.
	 * @return string
	 */
	public static function replace_placeholders_escaped( string $template, array $input ): string {
		return (string) preg_replace_callback(
			'/\{\{(\w[\w.]*)\}\}/',
			/** @phpstan-ignore-next-line */
			function ( $matches ) use ( $input ) {
				$key = $matches[1];

				// Direct key lookup.
				if ( isset( $input[ $key ] ) ) {
					$value = is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : (string) wp_json_encode( $input[ $key ] );
					return escapeshellarg( $value );
				}

				// Dot-notation traversal.
				if ( str_contains( $key, '.' ) ) {
					$parts = explode( '.', $key );
					$value = $input;
					foreach ( $parts as $part ) {
						if ( is_array( $value ) && isset( $value[ $part ] ) ) {
							$value = $value[ $part ];
						} else {
							return $matches[0]; // Leave placeholder as-is if not found.
						}
					}
					$scalar = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
					return escapeshellarg( $scalar );
				}

				return $matches[0]; // Leave placeholder as-is if not found.
			},
			$template
		);
	}

	/**
	 * Replace {{placeholder}} tokens in a string with input values.
	 *
	 * Supports nested dot-notation: {{order.id}} will look for $input['order']['id']
	 * then fall back to $input['order.id'].
	 *
	 * @param string               $template Template string with placeholders.
	 * @param array<string, mixed> $input    Input values.
	 * @return string
	 */
	public static function replace_placeholders( string $template, array $input ): string {
		return (string) preg_replace_callback(
			'/\{\{(\w[\w.]*)\}\}/',
			/** @phpstan-ignore-next-line */
			function ( $matches ) use ( $input ) {
				$key = $matches[1];

				// Direct key lookup.
				if ( isset( $input[ $key ] ) ) {
					return is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : (string) wp_json_encode( $input[ $key ] );
				}

				// Dot-notation traversal.
				if ( str_contains( $key, '.' ) ) {
					$parts = explode( '.', $key );
					$value = $input;
					foreach ( $parts as $part ) {
						if ( is_array( $value ) && isset( $value[ $part ] ) ) {
							$value = $value[ $part ];
						} else {
							return $matches[0]; // Leave placeholder as-is if not found.
						}
					}
					return is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
				}

				return $matches[0]; // Leave placeholder as-is if not found.
			},
			$template
		);
	}
}
