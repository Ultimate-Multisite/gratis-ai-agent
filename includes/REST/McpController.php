<?php

declare(strict_types=1);
/**
 * MCP (Model Context Protocol) REST endpoint.
 *
 * Exposes all registered WordPress abilities as MCP tools so that external
 * AI clients (Claude Desktop, Cursor, etc.) can discover and invoke them
 * via the standard MCP protocol over HTTP.
 *
 * Endpoint: POST /wp-json/sd-ai-agent/v1/mcp
 *
 * Supported methods:
 *   - list_tools  — returns all registered abilities as MCP tool definitions
 *   - call_tool   — executes a named ability with the provided arguments
 *
 * Authentication: WordPress nonce (X-WP-Nonce header) or Application Password
 * (HTTP Basic Auth). Both are handled transparently by the WP REST API.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\REST;

use SdAiAgent\Core\AbilityVisibility;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use XWP\DI\Decorators\REST_Handler;
use XWP\DI\Decorators\REST_Route;
use XWP_REST_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements the MCP protocol over a single WordPress REST endpoint.
 */
#[REST_Handler(
	namespace: RestController::NAMESPACE,
	basename: 'mcp',
	container: 'sd-ai-agent',
)]
final class McpController extends XWP_REST_Controller {

	/**
	 * MCP protocol version advertised in responses.
	 */
	const MCP_PROTOCOL_VERSION = '2024-11-05';

	/**
	 * Permission check — requires manage_options capability.
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Dispatch an MCP request to the appropriate handler.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	#[REST_Route(
		route: '',
		methods: WP_REST_Server::CREATABLE,
		vars: 'get_mcp_args',
		guard: 'check_permission',
	)]
	public function handle_request( WP_REST_Request $request ) {
		$method = $request->get_param( 'method' );
		$params = $request->get_param( 'params' );

		if ( ! is_array( $params ) ) {
			$params = [];
		}
		/** @var array<string, mixed> $params */

		switch ( $method ) {
			case 'list_tools':
				return self::handle_list_tools();

			case 'call_tool':
				return self::handle_call_tool( $params );

			default:
				return new WP_Error(
					'ai_agent_mcp_unknown_method',
					sprintf(
						/* translators: %s: MCP method name */
						__( 'Unknown MCP method: %s. Supported methods: list_tools, call_tool.', 'superdav-ai-agent' ),
						// @phpstan-ignore-next-line
						$method
					),
					[ 'status' => 400 ]
				);
		}
	}

	/**
	 * Schema arguments for POST /mcp.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_mcp_args(): array {
		return array(
			'method' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => 'MCP method: list_tools or call_tool.',
			),
			'params' => array(
				'required' => false,
				'type'     => 'object',
				'default'  => array(),
			),
		);
	}

	/**
	 * Handle the list_tools MCP method.
	 *
	 * @return WP_REST_Response
	 */
	private static function handle_list_tools(): WP_REST_Response {
		$tools = self::get_mcp_tools();

		return new WP_REST_Response(
			[
				'protocol_version' => self::MCP_PROTOCOL_VERSION,
				'tools'            => $tools,
			],
			200
		);
	}

	/**
	 * Handle the call_tool MCP method.
	 *
	 * @param array<string, mixed> $params MCP params: { name: string, arguments?: object }.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function handle_call_tool( array $params ) {
		// @phpstan-ignore-next-line
		$tool_name = isset( $params['name'] ) ? sanitize_text_field( (string) $params['name'] ) : '';
		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] )
			? $params['arguments']
			: [];

		if ( '' === $tool_name ) {
			return new WP_Error(
				'ai_agent_mcp_missing_name',
				__( 'call_tool requires a "name" parameter.', 'superdav-ai-agent' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new WP_Error(
				'ai_agent_mcp_no_abilities_api',
				__( 'WordPress Abilities API is not available. WordPress 7.0+ is required.', 'superdav-ai-agent' ),
				[ 'status' => 503 ]
			);
		}

		$ability_name = self::mcp_name_to_ability_name( $tool_name );
		$ability      = wp_get_ability( $ability_name );

		if ( null === $ability ) {
			return new WP_Error(
				'ai_agent_mcp_tool_not_found',
				sprintf(
					/* translators: %s: tool name */
					__( 'Tool not found: %s', 'superdav-ai-agent' ),
					$tool_name
				),
				[ 'status' => 404 ]
			);
		}

		$result = $ability->execute( ! empty( $arguments ) ? $arguments : null );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				[
					'protocol_version' => self::MCP_PROTOCOL_VERSION,
					'tool'             => $tool_name,
					'isError'          => true,
					'content'          => [
						[
							'type' => 'text',
							'text' => $result->get_error_message(),
						],
					],
				],
				200
			);
		}

		$text = is_string( $result ) ? $result : wp_json_encode( $result );

		return new WP_REST_Response(
			[
				'protocol_version' => self::MCP_PROTOCOL_VERSION,
				'tool'             => $tool_name,
				'isError'          => false,
				'content'          => [
					[
						'type' => 'text',
						'text' => $text,
					],
				],
			],
			200
		);
	}

	/**
	 * Build the full list of MCP tool definitions from registered abilities.
	 *
	 * @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}>
	 */
	private static function get_mcp_tools(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return [];
		}

		$abilities = wp_get_abilities();
		$tools     = [];

		foreach ( $abilities as $ability ) {
			// Only expose abilities the site owner has sanctioned for MCP access.
			if ( ! AbilityVisibility::for_mcp( $ability ) ) {
				continue;
			}

			$name         = self::ability_name_to_mcp_name( $ability->get_name() );
			$description  = $ability->get_description();
			$input_schema = $ability->get_input_schema();

			if ( empty( $input_schema ) || ! is_array( $input_schema ) ) {
				$input_schema = [
					'type'       => 'object',
					'properties' => new \stdClass(),
				];
			} else {
				if ( isset( $input_schema['properties'] ) && $input_schema['properties'] === [] ) {
					$input_schema['properties'] = new \stdClass();
				}
				if ( isset( $input_schema['required'] ) && is_array( $input_schema['required'] ) && empty( $input_schema['required'] ) ) {
					unset( $input_schema['required'] );
				}
			}

			$tools[] = [
				'name'        => $name,
				'description' => $description,
				'inputSchema' => $input_schema,
			];
		}

		return $tools;
	}

	/**
	 * Convert a WordPress ability name to an MCP-safe tool name.
	 *
	 * @param string $ability_name WordPress ability name.
	 * @return string MCP tool name.
	 */
	public static function ability_name_to_mcp_name( string $ability_name ): string {
		return str_replace( '/', '__', $ability_name );
	}

	/**
	 * Convert an MCP tool name back to a WordPress ability name.
	 *
	 * @param string $mcp_name MCP tool name.
	 * @return string WordPress ability name.
	 */
	public static function mcp_name_to_ability_name( string $mcp_name ): string {
		return str_replace( '__', '/', $mcp_name );
	}
}
