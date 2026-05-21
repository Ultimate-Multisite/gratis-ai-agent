<?php

declare(strict_types=1);
/**
 * WP REST API abilities for the AI agent.
 *
 * Registers three abilities under the `wp-rest` category:
 *
 *   - `wp-rest/discover` — list namespaces and routes (read-only).
 *   - `wp-rest/inspect` — return schema/methods/permissions for a single route.
 *   - `wp-rest/execute` — dispatch via rest_do_request() (read/write/destructive).
 *
 * All requests are dispatched via WP's internal REST dispatcher — no
 * wp_remote_request(), no HTTP loopback.
 *
 * Security layers (mirror WpCliAbilities semantics):
 *   1. Namespace blocklist (`sd-ai-agent/v1` is always blocked).
 *   2. Route blocklist (destructive user routes blocked by default).
 *   3. Permission classification (read/write → manage_options,
 *      destructive → manage_network, falls back to manage_options on single-site).
 *   4. Loop guard — refuses to dispatch when AgentController is on the call stack.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Core\ChangeLogger;
use SdAiAgent\Models\ChangesLog;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WpRestAbilities {

	/**
	 * Ability category slug.
	 */
	private const CATEGORY = 'wp-rest';

	/**
	 * REST namespaces that are always blocked from execute.
	 *
	 * @var string[]
	 */
	private const BLOCKED_NAMESPACES = array( 'sd-ai-agent/v1' );

	/**
	 * Route patterns that are always blocked.
	 *
	 * Format: "<METHOD> <route_prefix>" — the route prefix is matched as an
	 * exact string or as a path prefix (i.e. the check covers the route and
	 * all sub-paths).
	 *
	 * @var string[]
	 */
	private const BLOCKED_ROUTES = array(
		'DELETE /wp/v2/users',
		'POST /wp/v2/users',
	);

	/**
	 * Maximum response size (in bytes of JSON) before truncation.
	 */
	private const MAX_RESPONSE_BYTES = 65536; // 64 KB

	// ─── Category registration ───────────────────────────────────────────

	/**
	 * Register the wp-rest ability category.
	 *
	 * @return void
	 */
	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) || ! function_exists( 'wp_has_ability_category' ) ) {
			return;
		}

		if ( wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'WP REST API', 'superdav-ai-agent' ),
				'description' => __( 'Invoke any registered WordPress REST endpoint.', 'superdav-ai-agent' ),
			)
		);
	}

	// ─── Ability registration ────────────────────────────────────────────

	/**
	 * Register all three wp-rest abilities.
	 *
	 * @return void
	 */
	public static function register_abilities(): void {
		self::register_discover();
		self::register_inspect();
		self::register_execute();
	}

	/**
	 * Register the wp-rest/discover ability.
	 *
	 * @return void
	 */
	private static function register_discover(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			self::CATEGORY . '/discover',
			array(
				'label'               => __( 'Discover REST Routes', 'superdav-ai-agent' ),
				'description'         => implode(
					"\n",
					array(
						'List registered WordPress REST API namespaces and routes.',
						'',
						'When called with no arguments, returns all registered namespaces.',
						'Pass `namespace` to list only the routes in that namespace.',
						'Pass `search` for a case-insensitive substring filter on route paths.',
						'Pass `limit` to cap the number of routes returned (default 50, max 200).',
						'',
						'Note: file-upload endpoints (e.g. /wp/v2/media POST) are hidden; use the media/upload ability instead.',
					)
				),
				'category'            => self::CATEGORY,
				'permission_callback' => static function () {
					return self::check_permission_level( 'read' );
				},
				'execute_callback'    => array( __CLASS__, 'handle_discover' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'namespace' => array(
							'type'        => 'string',
							'description' => "Filter by namespace, e.g. 'wp/v2'. Omit to list all namespaces only.",
						),
						'search'    => array(
							'type'        => 'string',
							'description' => 'Case-insensitive substring filter on the route path.',
						),
						'limit'     => array(
							'type'    => 'integer',
							'default' => 50,
							'maximum' => 200,
						),
					),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'title'       => 'WP REST Discover',
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'mcp'          => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			)
		);
	}

	/**
	 * Register the wp-rest/inspect ability.
	 *
	 * @return void
	 */
	private static function register_inspect(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			self::CATEGORY . '/inspect',
			array(
				'label'               => __( 'Inspect REST Route', 'superdav-ai-agent' ),
				'description'         => implode(
					"\n",
					array(
						'Return the schema, accepted methods, registered arguments, and permission summary for a single REST route.',
						'',
						'Pass the route path as registered, e.g. \'/wp/v2/posts\' or \'/wp/v2/posts/(?P<id>[\\d]+)\'.',
					)
				),
				'category'            => self::CATEGORY,
				'permission_callback' => static function () {
					return self::check_permission_level( 'read' );
				},
				'execute_callback'    => array( __CLASS__, 'handle_inspect' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'route' => array(
							'type'        => 'string',
							'description' => "Route path, e.g. '/wp/v2/posts' or '/wp/v2/posts/(?P<id>[\\d]+)'.",
						),
					),
					'required'             => array( 'route' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'title'       => 'WP REST Inspect',
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'mcp'          => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			)
		);
	}

	/**
	 * Register the wp-rest/execute ability.
	 *
	 * @return void
	 */
	private static function register_execute(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			self::CATEGORY . '/execute',
			array(
				'label'               => __( 'Execute REST Request', 'superdav-ai-agent' ),
				'description'         => implode(
					"\n",
					array(
						'Dispatch an HTTP request to any registered WordPress REST endpoint using the internal dispatcher.',
						'No HTTP loopback — the request is handled in-process.',
						'',
						'Examples:',
						'  { "route": "/wp/v2/posts", "method": "GET", "params": { "per_page": 5 } }',
						'  { "route": "/wp/v2/posts", "method": "POST", "params": { "title": "Hello", "status": "draft" } }',
						'  { "route": "/wp/v2/posts/42", "method": "DELETE" }',
						'',
						'Tips:',
						'- Do NOT include the /wp-json prefix in `route`.',
						'- GET params go in `params` as query string key/value pairs.',
						'- POST/PUT/PATCH params go in `params` as the request body.',
						'- Some namespaces and routes are always blocked for security.',
					)
				),
				'category'            => self::CATEGORY,
				'permission_callback' => static function () {
					if ( current_user_can( 'manage_network' ) ) {
						return true;
					}
					if ( current_user_can( 'manage_options' ) ) {
						return true;
					}
					return new WP_Error(
						'wp_rest_forbidden',
						__( 'You do not have permission to execute REST requests. Required capability: manage_options.', 'superdav-ai-agent' ),
						array( 'status' => 403 )
					);
				},
				'execute_callback'    => array( __CLASS__, 'handle_execute' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'method'  => array(
							'type'    => 'string',
							'enum'    => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ),
							'default' => 'GET',
						),
						'route'   => array(
							'type'        => 'string',
							'description' => "Route path with concrete IDs, e.g. '/wp/v2/posts/42'. Do NOT include /wp-json prefix.",
						),
						'params'  => array(
							'type'        => 'object',
							'description' => 'Query params for GET/DELETE; JSON body for POST/PUT/PATCH.',
						),
						'headers' => array(
							'type'        => 'object',
							'description' => 'Rarely needed for internal dispatch.',
						),
					),
					'required'             => array( 'route' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'title'       => 'WP REST Execute',
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
						'open_world'  => true,
					),
					'mcp'          => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			)
		);
	}

	// ─── Execute handlers ────────────────────────────────────────────────

	/**
	 * Handle a call to wp-rest/discover.
	 *
	 * @param array<string,mixed> $input Input arguments.
	 * @return array<mixed>|WP_Error
	 */
	public static function handle_discover( array $input = array() ) {
		$namespace = isset( $input['namespace'] ) ? (string) $input['namespace'] : '';
		$search    = isset( $input['search'] ) ? (string) $input['search'] : '';
		$limit     = isset( $input['limit'] ) ? (int) $input['limit'] : 50;
		$limit     = min( max( 1, $limit ), 200 );

		$server = rest_get_server();

		// No namespace filter → return the list of all registered namespaces.
		if ( '' === $namespace && '' === $search ) {
			return array(
				'namespaces' => array_values( $server->get_namespaces() ),
			);
		}

		$all_routes = $server->get_routes();
		$result     = array();
		$count      = 0;

		foreach ( $all_routes as $route_path => $endpoints ) {
			// Namespace filter.
			if ( '' !== $namespace ) {
				$expected_prefix = '/' . ltrim( $namespace, '/' );
				if ( ! str_starts_with( $route_path, $expected_prefix . '/' ) && $route_path !== $expected_prefix ) {
					continue;
				}
			}

			// Search filter.
			if ( '' !== $search && false === stripos( $route_path, $search ) ) {
				continue;
			}

			// Collect methods across endpoint handlers.
			$methods   = array();
			$is_upload = false;
			$summary   = '';

			foreach ( $endpoints as $endpoint ) {
				if ( ! is_array( $endpoint ) ) {
					continue;
				}

				$raw_methods = ( isset( $endpoint['methods'] ) && is_array( $endpoint['methods'] ) ) ? array_keys( $endpoint['methods'] ) : array();
				$ep_methods  = array_map( 'strval', $raw_methods );
				$methods     = array_values( array_unique( array_merge( $methods, $ep_methods ) ) );

				// Detect file-upload endpoints.
				if ( ! $is_upload ) {
					$args = $endpoint['args'] ?? array();
					foreach ( $args as $arg ) {
						if ( is_array( $arg ) && isset( $arg['type'] ) && 'file' === $arg['type'] ) {
							$is_upload = true;
							break;
						}
					}
				}

				// Capture summary from schema callback if available.
				if ( '' === $summary && isset( $endpoint['schema'] ) && is_callable( $endpoint['schema'] ) ) {
					$schema = call_user_func( $endpoint['schema'] );
					if ( is_array( $schema ) && isset( $schema['title'] ) ) {
						$summary = (string) $schema['title'];
					}
				}
			}

			// Hide file-upload endpoints (e.g. /wp/v2/media POST).
			// The media/upload ability handles those cases with proper multipart support.
			if ( $is_upload || self::is_media_upload_route( $route_path, $methods ) ) {
				// Only surface the hint if the agent was specifically looking for media routes.
				if ( '' !== $search && false !== stripos( $route_path, 'media' ) ) {
					$result[] = array(
						'hidden'              => 'media-upload',
						'alternative_ability' => 'media/upload',
					);
				}
				continue;
			}

			$entry = array(
				'route'   => $route_path,
				'methods' => $methods,
			);
			if ( '' !== $summary ) {
				$entry['summary'] = $summary;
			}

			$result[] = $entry;
			++$count;

			if ( $count >= $limit ) {
				break;
			}
		}

		return $result;
	}

	/**
	 * Handle a call to wp-rest/inspect.
	 *
	 * @param array<string,mixed> $input Input arguments.
	 * @return array<mixed>|WP_Error
	 */
	public static function handle_inspect( array $input = array() ) {
		$route = isset( $input['route'] ) ? (string) $input['route'] : '';
		$route = '/' . ltrim( $route, '/' );

		if ( '/' === $route ) {
			return new WP_Error(
				'wp_rest_missing_route',
				__( 'A `route` path is required for wp-rest/inspect.', 'superdav-ai-agent' ),
				array( 'status' => 400 )
			);
		}

		$server = rest_get_server();
		$routes = $server->get_routes();

		if ( ! isset( $routes[ $route ] ) ) {
			return new WP_Error(
				'wp_rest_route_not_found',
				sprintf(
					/* translators: %s: route path */
					__( 'Route "%s" is not registered. Use wp-rest/discover to list available routes.', 'superdav-ai-agent' ),
					$route
				),
				array( 'status' => 404 )
			);
		}

		$endpoints = $routes[ $route ];
		$result    = array(
			'route'     => $route,
			'endpoints' => array(),
		);

		foreach ( $endpoints as $endpoint ) {
			if ( ! is_array( $endpoint ) ) {
				continue;
			}

			$ep_methods = ( isset( $endpoint['methods'] ) && is_array( $endpoint['methods'] ) ) ? array_map( 'strval', array_keys( $endpoint['methods'] ) ) : array();
			$args       = $endpoint['args'] ?? array();

			// Summarize args.
			$arg_summary = array();
			foreach ( $args as $arg_name => $arg_def ) {
				if ( ! is_array( $arg_def ) ) {
					continue;
				}
				$arg_entry = array();
				if ( isset( $arg_def['type'] ) ) {
					$arg_entry['type'] = $arg_def['type'];
				}
				if ( ! empty( $arg_def['required'] ) ) {
					$arg_entry['required'] = true;
				}
				if ( isset( $arg_def['enum'] ) ) {
					$arg_entry['enum'] = $arg_def['enum'];
				}
				if ( isset( $arg_def['description'] ) && '' !== $arg_def['description'] ) {
					$arg_entry['description'] = $arg_def['description'];
				}
				$arg_summary[ $arg_name ] = $arg_entry;
			}

			// Permission summary: best-effort inspection of the callback.
			$permission_summary = self::guess_permission_summary( $endpoint );

			// Response schema.
			$schema = null;
			if ( isset( $endpoint['schema'] ) && is_callable( $endpoint['schema'] ) ) {
				$schema = call_user_func( $endpoint['schema'] );
			}

			$ep_entry = array(
				'methods'    => $ep_methods,
				'args'       => $arg_summary,
				'permission' => $permission_summary,
			);
			if ( null !== $schema ) {
				$ep_entry['schema'] = $schema;
			}

			$result['endpoints'][] = $ep_entry;
		}

		return $result;
	}

	/**
	 * Handle a call to wp-rest/execute.
	 *
	 * @param array<string,mixed> $input Input arguments.
	 * @return array<mixed>|WP_Error
	 */
	public static function handle_execute( array $input = array() ) {
		$method  = strtoupper( isset( $input['method'] ) ? (string) $input['method'] : 'GET' );
		$route   = isset( $input['route'] ) ? (string) $input['route'] : '';
		$params  = isset( $input['params'] ) && is_array( $input['params'] ) ? $input['params'] : array();
		$headers = isset( $input['headers'] ) && is_array( $input['headers'] ) ? $input['headers'] : array();

		$route = '/' . ltrim( $route, '/' );

		if ( '/' === $route ) {
			return new WP_Error(
				'wp_rest_missing_route',
				__( 'A `route` path is required for wp-rest/execute.', 'superdav-ai-agent' ),
				array( 'status' => 400 )
			);
		}

		// Loop guard: refuse to dispatch if AgentController is on the call stack.
		// This prevents the agent from recursively calling itself via REST.
		if ( self::is_in_agent_controller_stack() ) {
			return new WP_Error(
				'wp_rest_loop_blocked',
				__( 'wp-rest/execute cannot be dispatched from within the agent REST controller. This would create an infinite loop. Use a different ability or restructure the agent workflow to avoid calling wp-rest/execute recursively.', 'superdav-ai-agent' ),
				array( 'status' => 400 )
			);
		}

		// Namespace blocklist check.
		$namespace_block = self::check_namespace_block( $route );
		if ( is_wp_error( $namespace_block ) ) {
			return $namespace_block;
		}

		// Route blocklist check.
		$route_block = self::check_route_block( $method, $route );
		if ( is_wp_error( $route_block ) ) {
			return $route_block;
		}

		// Classify and check permissions.
		$level      = self::classify_request( $method, $route );
		$perm_check = self::check_permission_level( $level );
		if ( is_wp_error( $perm_check ) ) {
			return $perm_check;
		}

		// Dispatch via internal REST dispatcher.
		$response = self::dispatch( $method, $route, $params, $headers );

		// Audit trail: log write/destructive calls (skip read — noise-reduction rule).
		if ( ChangeLogger::is_active() && 'read' !== $level ) {
			ChangesLog::record(
				array(
					'session_id'   => ChangeLogger::get_session_id(),
					'object_type'  => 'wp_rest',
					'object_id'    => 0,
					'object_title' => $method . ' ' . $route,
					'ability_name' => ChangeLogger::get_ability_name() ?: 'wp_rest',
					'field_name'   => 'request',
					'before_value' => '',
					'after_value'  => wp_json_encode( self::scrub_secrets( $params ) ),
					'revertable'   => false,
				)
			);
		}

		return $response;
	}

	// ─── Dispatch helper ─────────────────────────────────────────────────

	/**
	 * Build and dispatch a WP_REST_Request and return a shaped response.
	 *
	 * @param string              $method  HTTP method.
	 * @param string              $route   Route path (must start with /).
	 * @param array<string,mixed> $params  Query params (GET/DELETE) or body params (POST/PUT/PATCH).
	 * @param array<string,mixed> $headers Extra request headers.
	 * @return array<string,mixed>
	 */
	private static function dispatch( string $method, string $route, array $params, array $headers ): array {
		$request = new WP_REST_Request( strtoupper( $method ), $route );

		if ( in_array( strtoupper( $method ), array( 'GET', 'DELETE', 'HEAD' ), true ) ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body_params( $params );
			$body = wp_json_encode( $params );
			if ( false !== $body ) {
				$request->set_body( $body );
			}
		}

		foreach ( $headers as $k => $v ) {
			$request->set_header( (string) $k, (string) $v );
		}

		// Always run as the current user — never bypass permission callbacks.
		// DO NOT add `$request->set_attributes(['is_internal' => true])` here:
		// that would short-circuit WordPress capability checks and allow
		// unauthenticated or low-privilege agents to invoke privileged endpoints.
		$response = rest_do_request( $request );

		return self::shape_response( $response );
	}

	/**
	 * Shape a WP_REST_Response into a clean array.
	 *
	 * If the serialised data exceeds MAX_RESPONSE_BYTES, truncates and adds a
	 * hint telling the agent to use _fields= and per_page= to narrow results.
	 *
	 * @param \WP_REST_Response $response The REST response.
	 * @return array<string,mixed>
	 */
	private static function shape_response( \WP_REST_Response $response ): array {
		$status  = $response->get_status();
		$headers = $response->get_headers();
		$data    = $response->get_data();

		$shaped = array(
			'status'  => $status,
			'headers' => $headers,
			'data'    => $data,
		);

		$encoded = wp_json_encode( $shaped );
		if ( false !== $encoded && strlen( $encoded ) > self::MAX_RESPONSE_BYTES ) {
			$shaped['data']            = null;
			$shaped['truncated']       = true;
			$shaped['truncation_hint'] = 'Response exceeded 64 KB. Refine the request using _fields= to limit returned fields and per_page= to reduce the number of items.';
		}

		return $shaped;
	}

	// ─── Security helpers ────────────────────────────────────────────────

	/**
	 * Check if the route's namespace is on the blocklist.
	 *
	 * @param string $route Route path (must start with /).
	 * @return true|WP_Error
	 */
	private static function check_namespace_block( string $route ) {
		/**
		 * Filter the WP REST namespace blocklist.
		 *
		 * @param string[] $blocklist Array of namespace strings to block.
		 */
		$blocklist = (array) apply_filters( 'sd_ai_agent_wp_rest_namespace_blocklist', self::BLOCKED_NAMESPACES );

		foreach ( $blocklist as $blocked_ns ) {
			$prefix = '/' . ltrim( (string) $blocked_ns, '/' );
			if ( str_starts_with( $route, $prefix . '/' ) || $route === $prefix ) {
				return new WP_Error(
					'wp_rest_namespace_blocked',
					sprintf(
						/* translators: %s: blocked namespace */
						__( 'The namespace "%s" is blocked and cannot be accessed via wp-rest/execute.', 'superdav-ai-agent' ),
						$blocked_ns
					),
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}

	/**
	 * Check if the method+route combination is on the route blocklist.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route path (must start with /).
	 * @return true|WP_Error
	 */
	private static function check_route_block( string $method, string $route ) {
		/**
		 * Filter the WP REST route blocklist.
		 *
		 * Entries are in "<METHOD> <route_prefix>" format.
		 *
		 * @param string[] $blocklist Array of method+route patterns to block.
		 */
		$blocklist = (array) apply_filters( 'sd_ai_agent_wp_rest_route_blocklist', self::BLOCKED_ROUTES );

		foreach ( $blocklist as $entry ) {
			$parts          = explode( ' ', (string) $entry, 2 );
			$blocked_method = strtoupper( $parts[0] ?? '' );
			$blocked_prefix = $parts[1] ?? '';

			if ( $blocked_method !== strtoupper( $method ) ) {
				continue;
			}

			if ( $route === $blocked_prefix || str_starts_with( $route, $blocked_prefix . '/' ) ) {
				return new WP_Error(
					'wp_rest_route_blocked',
					sprintf(
						/* translators: 1: HTTP method, 2: route path */
						__( 'The route "%1$s %2$s" is blocked for security reasons.', 'superdav-ai-agent' ),
						$method,
						$route
					),
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}

	/**
	 * Classify a REST request as 'read', 'write', or 'destructive'.
	 *
	 * Classification is filterable via `sd_ai_agent_wp_rest_classify`.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route path.
	 * @return string 'read', 'write', or 'destructive'.
	 */
	private static function classify_request( string $method, string $route ): string {
		$method = strtoupper( $method );

		if ( in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			$level = 'read';
		} elseif ( 'DELETE' === $method ) {
			$level = 'destructive';
		} else {
			// POST, PUT, PATCH
			$level = 'write';
		}

		/**
		 * Filter the classification of a WP REST request.
		 *
		 * @param string $level  The default classification: 'read', 'write', or 'destructive'.
		 * @param string $method The HTTP method (upper-cased).
		 * @param string $route  The route path.
		 */
		return (string) apply_filters( 'sd_ai_agent_wp_rest_classify', $level, $method, $route );
	}

	/**
	 * Check if the current user has permission for a given access level.
	 *
	 * Mirrors WpCliAbilities::check_permission_level() semantics exactly.
	 *
	 * @param string $level 'read', 'write', or 'destructive'.
	 * @return true|WP_Error
	 */
	private static function check_permission_level( string $level ) {
		if ( current_user_can( 'manage_network' ) ) {
			return true;
		}

		$capability_map = array(
			'read'        => 'manage_options',
			'write'       => 'manage_options',
			'destructive' => 'manage_network',
		);

		$required_cap = $capability_map[ $level ] ?? 'manage_network';

		// On single-site installs, manage_network is never granted.
		// Fall back to manage_options so destructive calls remain accessible
		// to administrators on non-multisite WordPress installations.
		if ( 'manage_network' === $required_cap && ! is_multisite() ) {
			$required_cap = 'manage_options';
		}

		if ( current_user_can( $required_cap ) ) {
			return true;
		}

		return new WP_Error(
			'wp_rest_forbidden',
			sprintf(
				/* translators: 1: access level, 2: capability name */
				__( 'You do not have permission to execute this %1$s REST request. Required capability: %2$s.', 'superdav-ai-agent' ),
				$level,
				$required_cap
			),
			array( 'status' => 403 )
		);
	}

	/**
	 * Detect whether AgentController is anywhere on the current call stack.
	 *
	 * Walks up to 30 frames to check for a recursive REST-via-agent call.
	 *
	 * @return bool
	 */
	private static function is_in_agent_controller_stack(): bool {
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 30 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- intentional loop guard, not debug logging.
		foreach ( $frames as $frame ) {
			if ( isset( $frame['class'] ) && 'SdAiAgent\\REST\\AgentController' === $frame['class'] ) {
				return true;
			}
		}
		return false;
	}

	// ─── Utility helpers ─────────────────────────────────────────────────

	/**
	 * Detect whether a route is a known media-upload endpoint.
	 *
	 * Hides /wp/v2/media POST from route discovery since file uploads require
	 * multipart/form-data, which the internal dispatcher cannot accept as
	 * structured JSON. Agents should use media/upload instead.
	 *
	 * @param string   $route   Route path.
	 * @param string[] $methods HTTP methods for the route.
	 * @return bool
	 */
	private static function is_media_upload_route( string $route, array $methods ): bool {
		return '/wp/v2/media' === $route && in_array( 'POST', $methods, true );
	}

	/**
	 * Generate a best-effort permission summary for a route endpoint.
	 *
	 * Uses reflection on the permission_callback to extract the capability
	 * name when the callback follows the common WordPress pattern of calling
	 * current_user_can() with a literal string capability name.
	 *
	 * @param array<string,mixed> $endpoint Route endpoint definition.
	 * @return string
	 */
	private static function guess_permission_summary( array $endpoint ): string {
		if ( ! isset( $endpoint['permission_callback'] ) ) {
			return 'no permission callback registered';
		}

		$cb = $endpoint['permission_callback'];

		if ( '__return_true' === $cb || ( is_string( $cb ) && $cb === '__return_true' ) ) {
			return 'public (no authentication required)';
		}

		// Try to extract capability name from closure/function source via reflection.
		if ( is_callable( $cb ) ) {
			try {
				if ( is_array( $cb ) && isset( $cb[0], $cb[1] ) ) {
					/** @var object|string $class_ref */
					$class_ref = $cb[0];
					/** @var string $method_ref */
					$method_ref = (string) $cb[1];
					$ref        = new \ReflectionMethod( $class_ref, $method_ref );
				} elseif ( $cb instanceof \Closure ) {
					$ref = new \ReflectionFunction( $cb );
				} elseif ( is_string( $cb ) ) {
					$ref = new \ReflectionFunction( $cb );
				} else {
					return 'callback registered (capability could not be determined by reflection)';
				}

				$file  = $ref->getFileName();
				$start = $ref->getStartLine();
				$end   = $ref->getEndLine();

				if ( false !== $file && false !== $start && false !== $end ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local PHP source file for reflection.
					$source = file_get_contents( $file, false, null, 0 );
					if ( false !== $source ) {
						$lines = explode( "\n", $source );
						$body  = implode( "\n", array_slice( $lines, $start - 1, $end - $start + 1 ) );
						if ( preg_match( '/current_user_can\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $body, $m ) ) {
							return sprintf( 'requires capability: %s', $m[1] );
						}
					}
				}
			} catch ( \ReflectionException $e ) {
				// Swallow — best-effort only.
			}
		}

		return 'callback registered (capability could not be determined by reflection)';
	}

	/**
	 * Recursively scrub sensitive values from a params array.
	 *
	 * Replaces the value of any key matching /(secret|token|password|key|auth)/i
	 * with '***' so audit log entries do not store credentials.
	 *
	 * // SECURITY: This scrub must run before any audit logging. Adding new
	 * // sensitive key patterns here keeps the audit log clean across all callers.
	 *
	 * @param array<string,mixed> $params Input params.
	 * @return array<string,mixed>
	 */
	private static function scrub_secrets( array $params ): array {
		$scrubbed = array();
		foreach ( $params as $key => $value ) {
			if ( is_string( $key ) && preg_match( '/(secret|token|password|key|auth)/i', $key ) ) {
				$scrubbed[ $key ] = '***';
			} elseif ( is_array( $value ) ) {
				$scrubbed[ $key ] = self::scrub_secrets( $value );
			} else {
				$scrubbed[ $key ] = $value;
			}
		}
		return $scrubbed;
	}
}
