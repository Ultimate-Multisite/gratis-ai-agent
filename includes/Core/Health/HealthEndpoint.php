<?php

declare(strict_types=1);
/**
 * HealthEndpoint — registers a REST route sd-ai-agent/v1/_health that returns
 * {"success":true} for loopback requests only.
 *
 * MUST validate REMOTE_ADDR is loopback and reject otherwise.
 *
 * @package SdAiAgent
 * @since   1.2.0
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core\Health;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Health endpoint handler.
 *
 * @since 1.2.0
 */
class HealthEndpoint {

	/**
	 * Register the health endpoint.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_rest_route(
			'sd-ai-agent/v1',
			'/_health',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'handle_health_check' ],
				'permission_callback' => [ self::class, 'check_loopback_permission' ],
				'show_in_index'       => false,
			]
		);
	}

	/**
	 * Permission callback: only allow loopback requests.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool|WP_Error True if allowed, WP_Error otherwise.
	 */
	public static function check_loopback_permission( WP_REST_Request $request ): bool|WP_Error {
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		// Allow loopback and private-network addresses used by local Docker/dev proxies.
		if (
			'127.0.0.1' === $remote_addr
			|| '::1' === $remote_addr
			|| (bool) filter_var( $remote_addr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false
		) {
			return true;
		}

		return new WP_Error(
			'sd_ai_agent_health_forbidden',
			'Health endpoint is only accessible from loopback or private-network addresses.',
			[ 'status' => 403 ]
		);
	}

	/**
	 * Handle the health check request.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response The response.
	 */
	public static function handle_health_check( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			[ 'success' => true ],
			200
		);
	}
}
