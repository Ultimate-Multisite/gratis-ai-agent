<?php

declare(strict_types=1);
/**
 * REST API controller for changes, modified-plugins, and download.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\REST;

use SdAiAgent\Models\ChangesLog;
use SdAiAgent\Services\ChangeRevertService;
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
 * Manages changes, modified-plugins, and download endpoints via REST.
 *
 * Revert domain logic is delegated to ChangeRevertService.
 *
 * Uses #[Handler] + #[Action] because this controller serves multiple
 * basenames (/changes, /modified-plugins, /download-plugin, /plugins).
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_REST,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class ChangesController {

	use PermissionTrait;

	/**
	 * Register REST routes.
	 */
	#[Action( tag: 'rest_api_init', priority: 10 )]
	public function register_routes(): void {

		// Changes log endpoints.
		register_rest_route(
			RestController::NAMESPACE,
			'/changes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list_changes' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'session_id'  => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'object_type' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'reverted'    => array(
						'required' => false,
						'type'     => 'boolean',
					),
					'revertable'  => array(
						'required' => false,
						'type'     => 'boolean',
					),
					'per_page'    => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 50,
					),
					'page'        => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 1,
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/changes/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_get_change' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'handle_delete_change' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/changes/(?P<id>\d+)/diff',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_change_diff' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/changes/(?P<id>\d+)/revert',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_revert_change' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/changes/export',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_export_changes' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'ids' => array(
						'required' => true,
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
					),
				),
			)
		);
	}

	/**
	 * List change records with optional filters.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_list_changes( WP_REST_Request $request ): WP_REST_Response {
		$filters = array(
			// @phpstan-ignore-next-line
			'per_page' => (int) $request->get_param( 'per_page' ),
			// @phpstan-ignore-next-line
			'page'     => (int) $request->get_param( 'page' ),
		);

		$session_id = $request->get_param( 'session_id' );
		if ( $session_id ) {
			// @phpstan-ignore-next-line
			$filters['session_id'] = (int) $session_id;
		}

		$object_type = $request->get_param( 'object_type' );
		if ( $object_type ) {
			// @phpstan-ignore-next-line
			$filters['object_type'] = sanitize_key( $object_type );
		}

		$reverted = $request->get_param( 'reverted' );
		if ( null !== $reverted ) {
			$filters['reverted'] = (bool) $reverted;
		}

		$revertable = $request->get_param( 'revertable' );
		if ( null !== $revertable ) {
			$filters['revertable'] = (bool) $revertable;
		}

		$result = ChangesLog::list( $filters );

		return new WP_REST_Response(
			array(
				'items'    => $result['items'],
				'total'    => $result['total'],
				'per_page' => $filters['per_page'],
				'page'     => $filters['page'],
			),
			200
		);
	}

	/**
	 * Get a single change record.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_get_change( WP_REST_Request $request ) {
		// @phpstan-ignore-next-line
		$id     = (int) $request->get_param( 'id' );
		$change = ChangesLog::get( $id );

		if ( ! $change ) {
			return new WP_Error( 'not_found', __( 'Change record not found.', 'superdav-ai-agent' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $change, 200 );
	}

	/**
	 * Get the diff for a single change record.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_get_change_diff( WP_REST_Request $request ) {
		// @phpstan-ignore-next-line
		$id     = (int) $request->get_param( 'id' );
		$change = ChangesLog::get( $id );

		if ( ! $change ) {
			return new WP_Error( 'not_found', __( 'Change record not found.', 'superdav-ai-agent' ), array( 'status' => 404 ) );
		}

		$diff = ChangesLog::generate_diff( $change->before_value, $change->after_value );

		return new WP_REST_Response(
			array(
				'id'           => $change->id,
				'before_value' => $change->before_value,
				'after_value'  => $change->after_value,
				'diff'         => $diff,
			),
			200
		);
	}

	/**
	 * Revert a single change — restores the before_value to the object.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_revert_change( WP_REST_Request $request ) {
		// @phpstan-ignore-next-line
		$id     = (int) $request->get_param( 'id' );
		$change = ChangesLog::get( $id );

		if ( ! $change ) {
			return new WP_Error( 'not_found', __( 'Change record not found.', 'superdav-ai-agent' ), array( 'status' => 404 ) );
		}

		if ( $change->reverted ) {
			return new WP_Error( 'already_reverted', __( 'This change has already been reverted.', 'superdav-ai-agent' ), array( 'status' => 409 ) );
		}

		$result = ChangeRevertService::apply_revert( $change );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		ChangesLog::mark_reverted( $id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Change reverted successfully.', 'superdav-ai-agent' ),
				'id'      => $id,
			),
			200
		);
	}

	/**
	 * Export selected changes as a patch file.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_export_changes( WP_REST_Request $request ) {
		// @phpstan-ignore-next-line
		$ids = array_map( 'absint', (array) $request->get_param( 'ids' ) );

		if ( empty( $ids ) ) {
			return new WP_Error( 'no_ids', __( 'No change IDs provided.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
		}

		$patch = ChangesLog::generate_patch( $ids );

		return new WP_REST_Response(
			array(
				'patch'    => $patch,
				'filename' => 'ai-changes-' . gmdate( 'Y-m-d-His' ) . '.patch',
			),
			200
		);
	}

	/**
	 * Delete a change record.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_delete_change( WP_REST_Request $request ) {
		// @phpstan-ignore-next-line
		$id     = (int) $request->get_param( 'id' );
		$change = ChangesLog::get( $id );

		if ( ! $change ) {
			return new WP_Error( 'not_found', __( 'Change record not found.', 'superdav-ai-agent' ), array( 'status' => 404 ) );
		}

		$deleted = ChangesLog::delete( $id );

		if ( ! $deleted ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete change record.', 'superdav-ai-agent' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $id,
			),
			200
		);
	}
}
