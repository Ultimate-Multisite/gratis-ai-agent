<?php

declare(strict_types=1);

namespace SdAiAgentAdvanced\Core;

use WP_Error;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Filter;
use XWP\DI\Decorators\Handler;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and verifies updates for the separately distributed Advanced plugin.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_GLOBAL,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class AdvancedPluginUpdater {

	private const METADATA_ENDPOINT = 'https://sdaiagent.com/?sdai_update_action=get_metadata&sdai_update_slug=superdav-ai-agent-advanced';

	/** Register the Advanced plugin's external update metadata. */
	#[Action( tag: 'init', priority: 20 )]
	public function register_update_checker(): void {
		$endpoint = $this->get_metadata_endpoint();
		if ( '' === $endpoint || ! class_exists( PucFactory::class ) ) {
			return;
		}

		PucFactory::buildUpdateChecker(
			$endpoint,
			SD_AI_AGENT_ADVANCED_DIR . '/superdav-ai-agent-advanced.php',
			'superdav-ai-agent-advanced',
			12
		);
	}

	/**
	 * Download the exact public package and verify its update-server checksum.
	 *
	 * @param false|string|WP_Error $reply      Prior short-circuit value.
	 * @param string                $package    Requested package URL.
	 * @param mixed                 $upgrader   WordPress upgrader instance.
	 * @param array<string, mixed>  $hook_extra Upgrader context.
	 * @return false|string|WP_Error Verified temporary ZIP path or prior value.
	 */
	#[Filter( tag: 'upgrader_pre_download', priority: 10, args: 4 )]
	public function verified_package_download( false|string|WP_Error $reply, string $package, mixed $upgrader, array $hook_extra ): false|string|WP_Error {
		unset( $upgrader, $hook_extra );

		if ( false !== $reply || ! $this->is_advanced_package_url( $package ) ) {
			return $reply;
		}

		$metadata = $this->request_metadata();
		if ( $metadata instanceof WP_Error ) {
			return $metadata;
		}
		if ( $package !== $metadata['download_url'] ) {
			return new WP_Error( 'sd_ai_agent_advanced_download_mismatch', __( 'SD AI Agent Advanced package metadata changed before download.', 'superdav-ai-agent' ) );
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$temporary_file = wp_tempnam( 'superdav-ai-agent-advanced' );
		if ( ! is_string( $temporary_file ) || '' === $temporary_file ) {
			return new WP_Error( 'sd_ai_agent_advanced_download_failed', __( 'WordPress could not create a temporary file for Advanced.', 'superdav-ai-agent' ) );
		}

		$response = wp_remote_get(
			$metadata['download_url'],
			array(
				'timeout'     => 300.0,
				'redirection' => 0,
				'stream'      => true,
				'filename'    => $temporary_file,
				'headers'     => array( 'Accept' => 'application/zip, application/octet-stream' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			wp_delete_file( $temporary_file );
			return new WP_Error( 'sd_ai_agent_advanced_download_failed', __( 'SD AI Agent Advanced could not be downloaded.', 'superdav-ai-agent' ) );
		}

		$checksum        = hash_file( 'sha256', $temporary_file );
		$response_header = wp_remote_retrieve_header( $response, 'x-package-sha256' );
		$header_matches  = '' === $response_header
			|| ( is_string( $response_header ) && hash_equals( $metadata['package_sha256'], strtolower( $response_header ) ) );
		if ( ! is_string( $checksum )
			|| ! hash_equals( $metadata['package_sha256'], $checksum )
			|| ! $header_matches
		) {
			wp_delete_file( $temporary_file );
			return new WP_Error( 'sd_ai_agent_advanced_checksum_mismatch', __( 'SD AI Agent Advanced failed integrity verification.', 'superdav-ai-agent' ) );
		}

		return $temporary_file;
	}

	/**
	 * Fetch and validate the package metadata used for updates.
	 *
	 * @return array{version:string,download_url:string,package_sha256:string}|WP_Error
	 */
	private function request_metadata(): array|WP_Error {
		$endpoint = $this->get_metadata_endpoint();
		if ( '' === $endpoint ) {
			return new WP_Error( 'sd_ai_agent_advanced_package_unavailable', __( 'SD AI Agent Advanced is temporarily unavailable.', 'superdav-ai-agent' ), array( 'status' => 503 ) );
		}

		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout'     => 15.0,
				'redirection' => 0,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'sd_ai_agent_advanced_package_unavailable', __( 'SD AI Agent Advanced is temporarily unavailable.', 'superdav-ai-agent' ), array( 'status' => 503 ) );
		}

		$body           = json_decode( wp_remote_retrieve_body( $response ), true );
		$download_url   = is_array( $body ) ? $this->sanitize_https_url( $body['download_url'] ?? '' ) : '';
		$version        = is_array( $body ) && isset( $body['version'] ) && is_string( $body['version'] ) ? $body['version'] : '';
		$download_query = array();
		parse_str( (string) wp_parse_url( $download_url, PHP_URL_QUERY ), $download_query );
		if ( ! is_array( $body )
			|| 'superdav-ai-agent-advanced' !== ( $body['slug'] ?? null )
			|| ! isset( $body['package_sha256'] )
			|| ! is_string( $body['package_sha256'] )
			|| 1 !== preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $body['package_sha256'] )
			|| '' === $download_url
			|| wp_parse_url( $endpoint, PHP_URL_SCHEME ) !== wp_parse_url( $download_url, PHP_URL_SCHEME )
			|| wp_parse_url( $endpoint, PHP_URL_HOST ) !== wp_parse_url( $download_url, PHP_URL_HOST )
			|| wp_parse_url( $endpoint, PHP_URL_PORT ) !== wp_parse_url( $download_url, PHP_URL_PORT )
			|| wp_parse_url( $endpoint, PHP_URL_PATH ) !== wp_parse_url( $download_url, PHP_URL_PATH )
			|| array(
				'sdai_update_action' => 'download',
				'sdai_update_slug'   => 'superdav-ai-agent-advanced',
			) !== $download_query
		) {
			return new WP_Error( 'sd_ai_agent_advanced_package_invalid', __( 'The SD AI Agent Advanced package response was invalid.', 'superdav-ai-agent' ), array( 'status' => 502 ) );
		}

		return array(
			'version'        => $version,
			'download_url'   => $download_url,
			'package_sha256' => $body['package_sha256'],
		);
	}

	/** Return the filterable public metadata endpoint. */
	private function get_metadata_endpoint(): string {
		$endpoint = defined( 'SD_AI_AGENT_ADVANCED_PLUGIN_METADATA_ENDPOINT' ) && is_string( SD_AI_AGENT_ADVANCED_PLUGIN_METADATA_ENDPOINT )
			? SD_AI_AGENT_ADVANCED_PLUGIN_METADATA_ENDPOINT
			: self::METADATA_ENDPOINT;
		$endpoint = apply_filters( 'sd_ai_agent_advanced_plugin_metadata_endpoint', $endpoint );

		return $this->sanitize_https_url( $endpoint );
	}

	/** Return a complete credential-free HTTPS URL, or an empty string. */
	private function sanitize_https_url( mixed $url ): string {
		$url    = is_string( $url ) ? esc_url_raw( $url ) : '';
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );
		$user   = wp_parse_url( $url, PHP_URL_USER );
		$pass   = wp_parse_url( $url, PHP_URL_PASS );

		return '' !== $url
			&& 'https' === $scheme
			&& is_string( $host )
			&& '' !== $host
			&& ( ! is_string( $user ) || '' === $user )
			&& ( ! is_string( $pass ) || '' === $pass )
			? $url
			: '';
	}

	/** Determine whether a URL is the public Advanced download endpoint. */
	private function is_advanced_package_url( string $url ): bool {
		$endpoint = $this->get_metadata_endpoint();
		$query    = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return '' !== $endpoint
			&& wp_parse_url( $endpoint, PHP_URL_SCHEME ) === wp_parse_url( $url, PHP_URL_SCHEME )
			&& wp_parse_url( $endpoint, PHP_URL_HOST ) === wp_parse_url( $url, PHP_URL_HOST )
			&& wp_parse_url( $endpoint, PHP_URL_PORT ) === wp_parse_url( $url, PHP_URL_PORT )
			&& wp_parse_url( $endpoint, PHP_URL_PATH ) === wp_parse_url( $url, PHP_URL_PATH )
			&& array(
				'sdai_update_action' => 'download',
				'sdai_update_slug'   => 'superdav-ai-agent-advanced',
			) === $query;
	}
}
