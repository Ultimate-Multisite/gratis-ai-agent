<?php

declare(strict_types=1);
/**
 * REST API controller for changes, modified-plugins, and download.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\REST;

use SdAiAgent\Core\Database;
use SdAiAgent\Core\WordPressPaths;
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

		// Plugin download endpoints.
		register_rest_route(
			RestController::NAMESPACE,
			'/modified-plugins',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list_modified_plugins' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/download-plugin/(?P<slug>[a-z0-9\-_]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_download_plugin' ),
				'permission_callback' => array( $this, 'check_download_permission' ),
				'args'                => array(
					'slug' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
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

	/**
	 * List all plugins that have been modified by the AI agent.
	 */
	public function handle_list_modified_plugins(): WP_REST_Response {
		$rows    = Database::get_modified_plugins();
		$plugins = array();

		foreach ( $rows as $row ) {
			$slug         = $row->plugin_slug ?? '';
			$nonce        = wp_create_nonce( 'sd_ai_agent_download_plugin_' . $slug );
			$rest_url     = rest_url( RestController::NAMESPACE . '/download-plugin/' . rawurlencode( $slug ) );
			$download_url = add_query_arg( '_wpnonce', $nonce, $rest_url );

			$plugins[] = array(
				'plugin_slug'        => $slug,
				'modification_count' => (int) ( $row->modification_count ?? 0 ),
				'last_modified'      => $row->last_modified ?? '',
				'download_url'       => $download_url,
			);
		}

		return new WP_REST_Response(
			array(
				'plugins' => $plugins,
				'count'   => count( $plugins ),
			),
			200
		);
	}

	/**
	 * Stream a zip archive of an AI-modified plugin directory.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_Error
	 */
	public function handle_download_plugin( WP_REST_Request $request ): WP_Error {
		// @phpstan-ignore-next-line
		$slug = sanitize_key( $request->get_param( 'slug' ) );

		if ( empty( $slug ) ) {
			return new WP_Error( 'invalid_slug', __( 'Plugin slug is required.', 'superdav-ai-agent' ), array( 'status' => 400 ) );
		}

		// Verify the plugin has been AI-modified.
		$modified_files = Database::get_modified_files_for_plugin( $slug );
		if ( empty( $modified_files ) ) {
			return new WP_Error(
				'plugin_not_modified',
				sprintf(
					/* translators: %s: plugin slug */
					__( 'No AI modifications recorded for plugin: %s', 'superdav-ai-agent' ),
					$slug
				),
				array( 'status' => 404 )
			);
		}

		// Verify the plugin directory exists.
		$plugin_dir = WordPressPaths::plugin_path( $slug );
		if ( ! is_dir( $plugin_dir ) ) {
			return new WP_Error(
				'plugin_not_found',
				sprintf(
					/* translators: %s: plugin slug */
					__( 'Plugin directory not found: %s', 'superdav-ai-agent' ),
					$slug
				),
				array( 'status' => 404 )
			);
		}

		// Check ZipArchive is available.
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'zip_unavailable',
				__( 'ZipArchive PHP extension is not available on this server.', 'superdav-ai-agent' ),
				array( 'status' => 500 )
			);
		}

		// Create a temporary zip file.
		$tmp_file = wp_tempnam( $slug . '.zip' );
		if ( ! $tmp_file ) {
			return new WP_Error( 'tmp_failed', __( 'Failed to create temporary file.', 'superdav-ai-agent' ), array( 'status' => 500 ) );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $tmp_file, \ZipArchive::OVERWRITE ) ) {
			wp_delete_file( $tmp_file );
			return new WP_Error( 'zip_open_failed', __( 'Failed to open zip archive for writing.', 'superdav-ai-agent' ), array( 'status' => 500 ) );
		}

		$this->add_directory_to_zip( $zip, $plugin_dir, $slug );
		$zip->close();

		// Stream the zip file to the browser.
		$filename = $slug . '-ai-modified.zip';
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $tmp_file ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming local temp file; WP_Filesystem does not support streaming output.
		readfile( $tmp_file );
		wp_delete_file( $tmp_file );
		exit;
	}

	/**
	 * Recursively add a directory to a ZipArchive.
	 *
	 * @param \ZipArchive $zip        The zip archive instance.
	 * @param string      $dir        Absolute path to the directory to add.
	 * @param string      $zip_prefix Prefix for entries inside the zip (the plugin slug).
	 */
	private function add_directory_to_zip( \ZipArchive $zip, string $dir, string $zip_prefix ): void {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $file ) {
			// @phpstan-ignore-next-line
			$file_path = $file->getRealPath();
			$relative  = $zip_prefix . '/' . substr( $file_path, strlen( $dir ) + 1 );

			// @phpstan-ignore-next-line
			if ( $file->isDir() ) {
				$zip->addEmptyDir( $relative );
			} else {
				$zip->addFile( $file_path, $relative );
			}
		}
	}
}
