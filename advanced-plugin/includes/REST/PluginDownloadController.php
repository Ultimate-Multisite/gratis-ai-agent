<?php

declare(strict_types=1);
/**
 * REST routes for advanced modified-plugin downloads.
 *
 * @package SdAiAgentAdvanced\REST
 * @license GPL-2.0-or-later
 */

namespace SdAiAgentAdvanced\REST;

use SdAiAgent\Core\Database;
use SdAiAgent\Core\WordPressPaths;
use SdAiAgent\REST\PermissionTrait;
use SdAiAgent\REST\RestController;
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
 * Serves advanced plugin download endpoints.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_REST,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class PluginDownloadController {

	use PermissionTrait;

	/**
	 * Register advanced plugin download REST routes.
	 */
	#[Action( tag: 'rest_api_init', priority: 20 )]
	public function register_routes(): void {
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
