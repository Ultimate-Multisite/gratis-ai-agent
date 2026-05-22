<?php

declare(strict_types=1);
/**
 * REST controller for the per-site instructions addendum.
 *
 * Exposes two routes:
 *
 *   GET  /wp-json/sd-ai-agent/v1/instructions
 *     Public, rate-limited per IP (30 req/min), cached max-age=60.
 *     Returns { addendum: string, updated_at: int }.
 *
 *   POST /wp-json/sd-ai-agent/v1/instructions
 *     Authenticated (manage_options). Saves or clears the addendum.
 *     Accepts { addendum: string }.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\REST;

use SdAiAgent\Core\InstructionsAddendum;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the instructions addendum REST endpoints.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_REST,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class InstructionsController {

	use PermissionTrait;

	/**
	 * Register REST routes.
	 */
	#[Action( tag: 'rest_api_init', priority: 10 )]
	public function register_routes(): void {

		register_rest_route(
			RestController::NAMESPACE,
			'/instructions',
			array(
				// GET — public, rate-limited.
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_get' ),
					'permission_callback' => '__return_true',
				),
				// POST — authenticated save.
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_post' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'addendum' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => static fn( $v ) => (string) $v,
							'description'       => __( 'Per-site instructions addendum (max 2000 UTF-8 chars).', 'superdav-ai-agent' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Handle GET /instructions.
	 *
	 * Public endpoint, rate-limited 30 req/min per IP. Returns the current
	 * addendum and its companion timestamp with Cache-Control: public, max-age=60.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_get( WP_REST_Request $request ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( '' !== $ip && ! InstructionsAddendum::check_rate_limit( $ip ) ) {
			return new WP_Error(
				'rate_limit_exceeded',
				__( 'Too many requests. Please wait before trying again.', 'superdav-ai-agent' ),
				array( 'status' => 429 )
			);
		}

		$response = new WP_REST_Response(
			array(
				'addendum'   => InstructionsAddendum::get_addendum(),
				'updated_at' => InstructionsAddendum::get_updated_at(),
			),
			200
		);

		$response->header( 'Cache-Control', 'public, max-age=60' );

		return $response;
	}

	/**
	 * Handle POST /instructions.
	 *
	 * Authenticated endpoint (manage_options). Saves or clears the addendum.
	 * Returns the persisted addendum and updated timestamp.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_post( WP_REST_Request $request ) {
		$value  = $request->get_param( 'addendum' );
		$result = InstructionsAddendum::set_addendum( $value );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'addendum'   => InstructionsAddendum::get_addendum(),
				'updated_at' => InstructionsAddendum::get_updated_at(),
			),
			200
		);
	}
}
