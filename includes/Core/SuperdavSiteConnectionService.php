<?php

declare(strict_types=1);
/**
 * Service-managed connection flow for the first-party Superdav AI provider.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiProvider;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provisions and revokes site-scoped Superdav AI bearer tokens.
 */
final class SuperdavSiteConnectionService {

	public const INSTALLATION_ID_OPTION = 'sd_ai_agent_site_installation_id';
	public const TOKEN_METADATA_OPTION  = 'sd_ai_agent_cloud_connection_metadata';

	/**
	 * Build safe connector status metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function get_status(): array {
		$token    = $this->get_stored_token();
		$metadata = $this->get_metadata();

		return array(
			'configured'                => '' !== $token,
			'provider'                  => SuperdavAiProvider::PROVIDER_ID,
			'connection_mode'           => $metadata['connection_mode'] ?? ( '' !== $token ? 'site' : 'none' ),
			'installation_id'           => $this->get_installation_id(),
			'site_url'                  => $this->get_verified_site_url(),
			'connected_at'              => $metadata['connected_at'] ?? null,
			'account_connect_available' => '' !== $this->get_account_connect_url(),
			'account_connect_url'       => $this->get_account_connect_url(),
		);
	}

	/**
	 * Provision a site-scoped token for anonymous/free-tier installs.
	 *
	 * @return array<string, mixed>|WP_Error Safe status metadata or error.
	 */
	public function provision_site_token(): array|WP_Error {
		$remote_token = $this->request_remote_site_token();
		if ( $remote_token instanceof WP_Error ) {
			return $remote_token;
		}

		$token = '' !== $remote_token ? $remote_token : $this->create_local_site_token();
		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, $token, false );
		update_option(
			self::TOKEN_METADATA_OPTION,
			array(
				'connection_mode' => '' !== $remote_token ? 'site' : 'local-site',
				'connected_at'    => gmdate( 'c' ),
			),
			false
		);

		return $this->get_status();
	}

	/**
	 * Disconnect the first-party provider and revoke the token if configured.
	 *
	 * @return array<string, mixed>|WP_Error Safe status metadata or error.
	 */
	public function disconnect(): array|WP_Error {
		$token = $this->get_stored_token();
		if ( '' !== $token ) {
			$revoked = $this->revoke_remote_token( $token );
			if ( $revoked instanceof WP_Error ) {
				return $revoked;
			}
		}

		delete_option( SuperdavAiProvider::CREDENTIAL_OPTION );
		delete_option( self::TOKEN_METADATA_OPTION );

		return $this->get_status();
	}

	/**
	 * Return the durable site-installation identity.
	 */
	private function get_installation_id(): string {
		$installation_id = get_option( self::INSTALLATION_ID_OPTION, '' );
		if ( is_string( $installation_id ) && '' !== $installation_id ) {
			return $installation_id;
		}

		$installation_id = wp_generate_uuid4();
		update_option( self::INSTALLATION_ID_OPTION, $installation_id, false );

		return $installation_id;
	}

	/**
	 * Return the verified site URL used for registration.
	 */
	private function get_verified_site_url(): string {
		return (string) home_url( '/' );
	}

	/**
	 * Return the account/OAuth connect URL when the service exposes one.
	 */
	private function get_account_connect_url(): string {
		/**
		 * Filters the Superdav account connection URL for future paid-account flows.
		 *
		 * The URL is safe UI metadata and must not include bearer tokens.
		 *
		 * @param string $url             Account connect URL.
		 * @param string $installation_id Durable site-installation identity.
		 * @param string $site_url        Verified site URL.
		 */
		$url = apply_filters( 'sd_ai_agent_cloud_account_connect_url', '', $this->get_installation_id(), $this->get_verified_site_url() );

		return is_string( $url ) ? esc_url_raw( $url ) : '';
	}

	/**
	 * Request a token from a configured cloud registration endpoint.
	 *
	 * @return string|WP_Error Token, empty string when no endpoint is configured, or error.
	 */
	private function request_remote_site_token(): string|WP_Error {
		/**
		 * Filters the Superdav site registration endpoint.
		 *
		 * Return an empty string to use local development provisioning.
		 *
		 * @param string $endpoint Registration endpoint URL.
		 */
		$endpoint = apply_filters( 'sd_ai_agent_cloud_registration_endpoint', '' );
		$endpoint = is_string( $endpoint ) ? esc_url_raw( $endpoint ) : '';
		if ( '' === $endpoint ) {
			return '';
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'installation_id' => $this->get_installation_id(),
						'site_url'        => $this->get_verified_site_url(),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'sd_ai_agent_cloud_registration_failed', __( 'Superdav AI connection failed.', 'superdav-ai-agent' ), array( 'status' => 502 ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'sd_ai_agent_cloud_registration_failed', __( 'Superdav AI connection failed.', 'superdav-ai-agent' ), array( 'status' => 502 ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['access_token'] ) || ! is_string( $body['access_token'] ) ) {
			return new WP_Error( 'sd_ai_agent_cloud_registration_invalid', __( 'Superdav AI connection response was invalid.', 'superdav-ai-agent' ), array( 'status' => 502 ) );
		}

		return $body['access_token'];
	}

	/**
	 * Revoke a token against a configured endpoint when available.
	 */
	private function revoke_remote_token( string $token ): bool|WP_Error {
		/**
		 * Filters the Superdav token revocation endpoint.
		 *
		 * Return an empty string when revocation is unavailable.
		 *
		 * @param string $endpoint Revocation endpoint URL.
		 */
		$endpoint = apply_filters( 'sd_ai_agent_cloud_revocation_endpoint', '' );
		$endpoint = is_string( $endpoint ) ? esc_url_raw( $endpoint ) : '';
		if ( '' === $endpoint ) {
			return true;
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'installation_id' => $this->get_installation_id(),
						'site_url'        => $this->get_verified_site_url(),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'sd_ai_agent_cloud_revoke_failed', __( 'Superdav AI disconnection failed.', 'superdav-ai-agent' ), array( 'status' => 502 ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'sd_ai_agent_cloud_revoke_failed', __( 'Superdav AI disconnection failed.', 'superdav-ai-agent' ), array( 'status' => 502 ) );
		}

		return true;
	}

	/**
	 * Create a local site-scoped bearer token for free-tier/local installs.
	 */
	private function create_local_site_token(): string {
		$payload = implode( '|', array( $this->get_installation_id(), $this->get_verified_site_url(), wp_generate_password( 32, false, false ) ) );

		return 'sdsite_' . hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	}

	/**
	 * Return the stored site token without exposing it to callers.
	 */
	private function get_stored_token(): string {
		$token = get_option( SuperdavAiProvider::CREDENTIAL_OPTION, '' );

		return is_string( $token ) ? $token : '';
	}

	/**
	 * Return safe connection metadata.
	 *
	 * @return array<string, mixed>
	 */
	private function get_metadata(): array {
		$metadata = get_option( self::TOKEN_METADATA_OPTION, array() );
		if ( ! is_array( $metadata ) ) {
			return array();
		}

		$safe_metadata = array();
		foreach ( $metadata as $key => $value ) {
			if ( is_string( $key ) ) {
				$safe_metadata[ $key ] = $value;
			}
		}

		return $safe_metadata;
	}
}
