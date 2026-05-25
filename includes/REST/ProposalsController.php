<?php

declare(strict_types=1);
/**
 * REST API controller for proposal management.
 *
 * Handles proposal apply, reject, and diff endpoints.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\REST;

use SdAiAgent\Core\ProposalRegistry;
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
 * Manages proposal endpoints: apply, reject, and diff.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_REST,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class ProposalsController {

	use PermissionTrait;

	/**
	 * Register REST routes.
	 */
	#[Action( tag: 'rest_api_init', priority: 10 )]
	public function register_routes(): void {

		// GET /proposals/{id}/diff — retrieve live diff.
		register_rest_route(
			RestController::NAMESPACE,
			'/proposals/(?P<id>[a-f0-9\-]+)/diff',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_get_diff' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// POST /proposals/{id}/apply — apply the proposal.
		register_rest_route(
			RestController::NAMESPACE,
			'/proposals/(?P<id>[a-f0-9\-]+)/apply',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_apply' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// POST /proposals/{id}/reject — reject the proposal.
		register_rest_route(
			RestController::NAMESPACE,
			'/proposals/(?P<id>[a-f0-9\-]+)/reject',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_reject' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Handle GET /proposals/{id}/diff.
	 *
	 * Returns the live diff by re-reading the file at request time.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_get_diff( WP_REST_Request $request ) {
		$proposal_id = $request->get_param( 'id' );
		$proposal    = ProposalRegistry::get( $proposal_id );

		if ( is_wp_error( $proposal ) ) {
			return $proposal;
		}

		// Verify the current user owns this proposal.
		if ( (int) $proposal['user_id'] !== get_current_user_id() ) {
			return new WP_Error(
				'proposal_forbidden',
				__( 'You do not have permission to access this proposal.', 'superdav-ai-agent' ),
				array( 'status' => 403 )
			);
		}

		$ability_name = $proposal['ability_name'];
		$arguments    = $proposal['arguments'];

		// Re-run the ability to get the live diff.
		// For file-write and file-edit, the ability returns a diff in its result.
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new WP_Error(
				'wp_abilities_unavailable',
				__( 'WordPress Abilities API is not available.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		// @phpstan-ignore-next-line
		$ability = wp_get_ability( $ability_name );
		if ( ! $ability instanceof \WP_Ability ) {
			return new WP_Error(
				'ability_not_found',
				__( 'Ability not found.', 'superdav-ai-agent' ),
				array( 'status' => 404 )
			);
		}

		// Call the ability's execute_callback to get the diff.
		// We pass a special flag to indicate this is a diff-only request.
		$diff_arguments = array_merge( $arguments, array( '_diff_only' => true ) );

		// @phpstan-ignore-next-line
		$result = $ability->run( $diff_arguments );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'proposal_id' => $proposal_id,
				'diff'        => $result['diff'] ?? '',
				'file_path'   => $result['file_path'] ?? '',
			),
			200
		);
	}

	/**
	 * Handle POST /proposals/{id}/apply.
	 *
	 * Applies the proposal by re-running the ability with original arguments.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_apply( WP_REST_Request $request ) {
		$proposal_id = $request->get_param( 'id' );
		$proposal    = ProposalRegistry::get( $proposal_id );

		if ( is_wp_error( $proposal ) ) {
			return $proposal;
		}

		// Verify the current user owns this proposal.
		if ( (int) $proposal['user_id'] !== get_current_user_id() ) {
			return new WP_Error(
				'proposal_forbidden',
				__( 'You do not have permission to apply this proposal.', 'superdav-ai-agent' ),
				array( 'status' => 403 )
			);
		}

		$ability_name = $proposal['ability_name'];
		$arguments    = $proposal['arguments'];

		// Delete the proposal (single-use).
		ProposalRegistry::delete( $proposal_id );

		// Re-run the ability with the original arguments.
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new WP_Error(
				'wp_abilities_unavailable',
				__( 'WordPress Abilities API is not available.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		// @phpstan-ignore-next-line
		$ability = wp_get_ability( $ability_name );
		if ( ! $ability instanceof \WP_Ability ) {
			return new WP_Error(
				'ability_not_found',
				__( 'Ability not found.', 'superdav-ai-agent' ),
				array( 'status' => 404 )
			);
		}

		// @phpstan-ignore-next-line
		$result = $ability->run( $arguments );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'status'  => 'success',
				'result'  => $result,
				'message' => __( 'Proposal applied successfully.', 'superdav-ai-agent' ),
			),
			200
		);
	}

	/**
	 * Handle POST /proposals/{id}/reject.
	 *
	 * Rejects the proposal by deleting it.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_reject( WP_REST_Request $request ) {
		$proposal_id = $request->get_param( 'id' );
		$proposal    = ProposalRegistry::get( $proposal_id );

		if ( is_wp_error( $proposal ) ) {
			return $proposal;
		}

		// Verify the current user owns this proposal.
		if ( (int) $proposal['user_id'] !== get_current_user_id() ) {
			return new WP_Error(
				'proposal_forbidden',
				__( 'You do not have permission to reject this proposal.', 'superdav-ai-agent' ),
				array( 'status' => 403 )
			);
		}

		// Delete the proposal (single-use).
		ProposalRegistry::delete( $proposal_id );

		return new WP_REST_Response(
			array(
				'status'  => 'success',
				'message' => __( 'Proposal rejected.', 'superdav-ai-agent' ),
			),
			200
		);
	}
}
