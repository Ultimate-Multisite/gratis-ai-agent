<?php

declare(strict_types=1);
/**
 * Service-managed connection flow for the first-party Superdav AI provider.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use SdAiAgent\Abilities\OptionsAbilities;
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

	private const REGISTRATION_ENDPOINT_PATH   = 'site/installations';
	private const REVOCATION_ENDPOINT_PATH     = 'site/token/revoke';
	private const ACCOUNT_STATUS_ENDPOINT_PATH = 'site/account';

	/**
	 * Maximum number of newest-first credit activity rows retained for display.
	 *
	 * The account endpoint is intentionally a single bounded summary response:
	 * it does not accept or expose cursors, and WordPress does not act as a
	 * billing ledger. The managed service must return only its newest page.
	 */
	public const MAX_CREDIT_ACTIVITY_EVENTS = 25;

	/** @var string[] Event types that are safe to render in the account UI. */
	private const CREDIT_ACTIVITY_EVENT_TYPES = array(
		'purchase',
		'promotion',
		'redeemed',
		'consumed',
		'pending',
		'expired',
	);

	/**
	 * Build safe connector status metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function get_status(): array {
		$token    = $this->get_stored_token();
		$metadata = $this->get_metadata();

		$account_portal_url   = $this->get_account_portal_url( $metadata );
		$purchase_credits_url = $this->get_account_action_url( $metadata, 'purchase_credits_url' );
		$payment_methods_url  = $this->get_account_action_url( $metadata, 'payment_methods_url' );

		$status = array(
			'configured'                => '' !== $token,
			'provider'                  => SuperdavAiProvider::PROVIDER_ID,
			'connection_mode'           => $metadata['connection_mode'] ?? ( '' !== $token ? 'site' : 'none' ),
			'installation_id'           => $this->get_installation_id(),
			'site_url'                  => $this->get_verified_site_url(),
			'connected_at'              => $metadata['connected_at'] ?? null,
			'account_connect_available' => '' !== $account_portal_url,
			'account_connect_url'       => $account_portal_url,
			'account_portal_url'        => $account_portal_url,
			'purchase_credits_url'      => $purchase_credits_url,
			'payment_methods_url'       => $payment_methods_url,
		);

		foreach ( array( 'tier', 'verified', 'request_id', 'connection_notice_pending' ) as $key ) {
			if ( array_key_exists( $key, $metadata ) && ( is_scalar( $metadata[ $key ] ) || null === $metadata[ $key ] ) ) {
				$status[ $key ] = $metadata[ $key ];
			}
		}

		foreach ( array( 'usage', 'verification' ) as $key ) {
			if ( isset( $metadata[ $key ] ) && is_array( $metadata[ $key ] ) ) {
				$status[ $key ] = array_filter(
					$metadata[ $key ],
					static fn( mixed $value ): bool => is_scalar( $value ) || null === $value
				);
			}
		}

		if ( isset( $metadata['wallet'] ) && is_array( $metadata['wallet'] ) ) {
			$status['wallet'] = $this->sanitize_wallet_metadata( $metadata['wallet'] );
		}

		if ( isset( $metadata['credit_activity'] ) && is_array( $metadata['credit_activity'] ) ) {
			$status['credit_activity'] = $this->sanitize_credit_activity_metadata( $metadata['credit_activity'] );
		}

		$status['site_timezone'] = wp_timezone_string();

		return $status;
	}

	/**
	 * Refresh safe account metadata from the managed Superdav service.
	 *
	 * The bearer token remains inside WordPress and is never included in the
	 * response. Payment data is handled only by the external account portal.
	 *
	 * @return array<string, mixed>|WP_Error Safe account status or refresh error.
	 */
	public function refresh_account_status(): array|WP_Error {
		$token = $this->get_stored_token();
		if ( '' === $token ) {
			return new WP_Error( 'sd_ai_agent_cloud_account_unavailable', __( 'Connect Superdav AI before refreshing your account.', 'superdav-ai-agent' ), array( 'status' => 412 ) );
		}

		$endpoint = $this->get_account_status_endpoint();
		if ( '' === $endpoint ) {
			return new WP_Error( 'sd_ai_agent_cloud_account_unavailable', __( 'Superdav AI account status is unavailable.', 'superdav-ai-agent' ), array( 'status' => 503 ) );
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
						'installation_id'       => $this->get_installation_id(),
						'site_url'              => $this->get_verified_site_url(),
						'credit_activity_limit' => self::MAX_CREDIT_ACTIVITY_EVENTS,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'sd_ai_agent_cloud_account_refresh_failed', __( 'Superdav AI account refresh failed.', 'superdav-ai-agent' ), array( 'status' => 502 ) );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			return new WP_Error( 'sd_ai_agent_cloud_account_refresh_failed', __( 'Superdav AI account refresh failed.', 'superdav-ai-agent' ), array( 'status' => 502 ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'sd_ai_agent_cloud_account_invalid', __( 'Superdav AI account response was invalid.', 'superdav-ai-agent' ), array( 'status' => 502 ) );
		}

		$metadata = $this->get_metadata();
		unset(
			$metadata['tier'],
			$metadata['verified'],
			$metadata['connect_required'],
			$metadata['request_id'],
			$metadata['account_portal_url'],
			$metadata['usage'],
			$metadata['verification'],
			$metadata['wallet'],
			$metadata['credit_activity']
		);
		$metadata = array_merge( $metadata, $this->sanitize_remote_metadata( $body ) );
		update_option( self::TOKEN_METADATA_OPTION, $metadata, false );

		return $this->get_status();
	}

	/**
	 * Provision a site-scoped token for anonymous/free-tier installs.
	 *
	 * @return array<string, mixed>|WP_Error Safe status metadata or error.
	 */
	public function provision_site_token(): array|WP_Error {
		$created             = '' === $this->get_stored_token();
		$remote_registration = $this->request_remote_site_token();
		if ( $remote_registration instanceof WP_Error ) {
			return $remote_registration;
		}

		$remote_token    = is_array( $remote_registration )
			? (string) ( $remote_registration['token'] ?? '' )
			: $remote_registration;
		$remote_metadata = is_array( $remote_registration )
			? (array) ( $remote_registration['metadata'] ?? array() )
			: array();
		$token           = '' !== $remote_token ? $remote_token : $this->create_local_site_token();
		$metadata        = array_merge(
			$remote_metadata,
			array(
				'connection_mode' => '' !== $remote_token ? 'site' : 'local-site',
				'connected_at'    => gmdate( 'c' ),
			)
		);

		if ( $created ) {
			$metadata['connection_notice_pending'] = true;
		}

		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, $token, false );
		update_option( self::TOKEN_METADATA_OPTION, $metadata, false );

		return $this->get_status();
	}

	/**
	 * Store a freshly provisioned local development token when no remote edge is configured.
	 *
	 * Production installs should use the managed edge so the durable installation
	 * ID maps to exactly one free plan and one starter-credit grant. Local tokens
	 * are intentionally marked as local-site and never claim a remote free wallet.
	 *
	 * @return array<string, mixed> Safe status metadata.
	 */
	public function provision_local_site_token(): array {
		$created  = '' === $this->get_stored_token();
		$token    = $this->create_local_site_token();
		$metadata = array(
			'connection_mode' => 'local-site',
			'connected_at'    => gmdate( 'c' ),
		);

		if ( $created ) {
			$metadata['connection_notice_pending'] = true;
		}

		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, $token, false );
		update_option( self::TOKEN_METADATA_OPTION, $metadata, false );

		return $this->get_status();
	}

	/**
	 * Ensure the site has a service-managed Superdav token when the edge is configured.
	 *
	 * @return array<string, mixed>|WP_Error Safe status metadata or provisioning error.
	 */
	public function ensure_site_token(): array|WP_Error {
		$status = $this->get_status();
		if ( ! empty( $status['configured'] ) || ! $this->has_remote_registration_endpoint() ) {
			return $status;
		}

		return $this->provision_site_token();
	}

	/**
	 * Determine whether a remote Superdav registration endpoint is configured.
	 */
	public function has_remote_registration_endpoint(): bool {
		return '' !== $this->get_registration_endpoint();
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
	 * Return the account portal URL when the service exposes one.
	 *
	 * @param array<string, mixed> $metadata Safe connection metadata.
	 */
	private function get_account_portal_url( array $metadata ): string {
		/**
		 * Filters the Superdav account connection URL for future paid-account flows.
		 *
		 * The URL is safe UI metadata and must not include bearer tokens.
		 *
		 * @param string $url             Account connect URL.
		 * @param string $installation_id Durable site-installation identity.
		 * @param string $site_url        Verified site URL.
		 */
		$default_url = $metadata['account_portal_url'] ?? '';
		$default_url = is_string( $default_url ) ? $default_url : '';
		$url         = apply_filters( 'sd_ai_agent_cloud_account_connect_url', $default_url, $this->get_installation_id(), $this->get_verified_site_url() );

		return $this->sanitize_account_url( $url );
	}

	/**
	 * Return a dedicated, service-issued billing action URL when available.
	 *
	 * @param array<string, mixed> $metadata Safe connection metadata.
	 * @param string               $key      Dedicated action metadata key.
	 * @return string Service-issued action URL, or empty when unavailable.
	 */
	private function get_account_action_url( array $metadata, string $key ): string {
		return $this->sanitize_account_url( $metadata[ $key ] ?? '' );
	}

	/**
	 * Resolve the managed service endpoint used to refresh safe wallet metadata.
	 */
	private function get_account_status_endpoint(): string {
		$endpoint = $this->configured_endpoint( 'SD_AI_AGENT_CLOUD_ACCOUNT_STATUS_ENDPOINT', self::ACCOUNT_STATUS_ENDPOINT_PATH );

		/**
		 * Filters the Superdav account status endpoint.
		 *
		 * The endpoint receives the site token server-to-server and must never be
		 * exposed to the browser or include credential query parameters.
		 *
		 * @param string $endpoint Account-status endpoint URL.
		 */
		$endpoint = apply_filters( 'sd_ai_agent_cloud_account_status_endpoint', $endpoint );

		return is_string( $endpoint ) ? esc_url_raw( $endpoint ) : '';
	}

	/**
	 * Request a token from a configured cloud registration endpoint.
	 *
	 * @return array{token: string, metadata: array<string, mixed>}|string|WP_Error Token registration, empty string when no endpoint is configured, or error.
	 */
	private function request_remote_site_token(): array|string|WP_Error {
		$endpoint = $this->get_registration_endpoint();
		if ( '' === $endpoint ) {
			return '';
		}

		$payload = array(
			'installation_id'   => $this->get_installation_id(),
			'site_url'          => $this->get_verified_site_url(),
			'plugin_version'    => defined( 'SD_AI_AGENT_VERSION' ) ? (string) SD_AI_AGENT_VERSION : 'unknown',
			'wordpress_version' => get_bloginfo( 'version' ),
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
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
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'sd_ai_agent_cloud_registration_invalid', __( 'Superdav AI connection response was invalid.', 'superdav-ai-agent' ), array( 'status' => 502 ) );
		}

		$token = $body['site_token'] ?? $body['access_token'] ?? '';
		if ( ! is_string( $token ) || '' === $token ) {
			return new WP_Error( 'sd_ai_agent_cloud_registration_invalid', __( 'Superdav AI connection response was invalid.', 'superdav-ai-agent' ), array( 'status' => 502 ) );
		}

		unset( $body['site_token'], $body['access_token'] );

		return array(
			'token'    => $token,
			'metadata' => $this->sanitize_remote_metadata( $body ),
		);
	}

	/**
	 * Revoke a token against a configured endpoint when available.
	 */
	private function revoke_remote_token( string $token ): bool|WP_Error {
		$endpoint = $this->get_revocation_endpoint();
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
	 * Resolve the site registration endpoint from explicit config or base URL.
	 */
	private function get_registration_endpoint(): string {
		$endpoint = $this->configured_endpoint( 'SD_AI_AGENT_CLOUD_REGISTRATION_ENDPOINT', self::REGISTRATION_ENDPOINT_PATH );

		/**
		 * Filters the Superdav site registration endpoint.
		 *
		 * Return an empty string to use local development provisioning.
		 * The default is SD_AI_AGENT_CLOUD_REGISTRATION_ENDPOINT, or the
		 * configured cloud base URL plus `/site/installations`.
		 *
		 * @param string $endpoint Registration endpoint URL.
		 */
		$endpoint = apply_filters( 'sd_ai_agent_cloud_registration_endpoint', $endpoint );

		return is_string( $endpoint ) ? esc_url_raw( $endpoint ) : '';
	}

	/**
	 * Resolve the token revocation endpoint from explicit config or base URL.
	 */
	private function get_revocation_endpoint(): string {
		$endpoint = $this->configured_endpoint( 'SD_AI_AGENT_CLOUD_REVOCATION_ENDPOINT', self::REVOCATION_ENDPOINT_PATH );

		/**
		 * Filters the Superdav token revocation endpoint.
		 *
		 * Return an empty string when revocation is unavailable.
		 * The default is SD_AI_AGENT_CLOUD_REVOCATION_ENDPOINT, or the
		 * configured cloud base URL plus `/site/token/revoke`.
		 *
		 * @param string $endpoint Revocation endpoint URL.
		 */
		$endpoint = apply_filters( 'sd_ai_agent_cloud_revocation_endpoint', $endpoint );

		return is_string( $endpoint ) ? esc_url_raw( $endpoint ) : '';
	}

	/**
	 * Resolve a service endpoint from a specific constant or the cloud base URL.
	 *
	 * @param string $constant_name Endpoint override constant name.
	 * @param string $path          Path relative to the configured cloud base URL.
	 * @return string Resolved endpoint URL, or empty when not configured.
	 */
	private function configured_endpoint( string $constant_name, string $path ): string {
		$endpoint = defined( $constant_name ) && is_string( constant( $constant_name ) )
			? (string) constant( $constant_name )
			: '';

		if ( '' === $endpoint ) {
			$endpoint = SuperdavAiProvider::configured_service_url( $path );
		}

		return is_string( $endpoint ) ? esc_url_raw( $endpoint ) : '';
	}

	/**
	 * Keep only safe scalar registration metadata from the remote service.
	 *
	 * @param array<string, mixed> $metadata Remote response without token fields.
	 * @return array<string, mixed>
	 */
	private function sanitize_remote_metadata( array $metadata ): array {
		$allowed_keys = array(
			'installation_id',
			'token_expires_at',
			'tier',
			'verified',
			'connect_required',
			'request_id',
			'account_portal_url',
			'purchase_credits_url',
			'payment_methods_url',
		);
		$safe         = array();

		foreach ( $allowed_keys as $key ) {
			if ( array_key_exists( $key, $metadata ) && ( is_scalar( $metadata[ $key ] ) || null === $metadata[ $key ] ) ) {
				$safe[ $key ] = $metadata[ $key ];
			}
		}

		foreach ( array( 'account_portal_url', 'purchase_credits_url', 'payment_methods_url' ) as $key ) {
			if ( isset( $safe[ $key ] ) ) {
				$url = $this->sanitize_account_url( $safe[ $key ] );
				if ( '' === $url ) {
					unset( $safe[ $key ] );
				} else {
					$safe[ $key ] = $url;
				}
			}
		}

		if ( isset( $metadata['usage'] ) && is_array( $metadata['usage'] ) ) {
			$safe['usage'] = array_filter(
				$metadata['usage'],
				static fn( mixed $value ): bool => is_scalar( $value ) || null === $value
			);
		}

		if ( isset( $metadata['verification'] ) && is_array( $metadata['verification'] ) ) {
			$safe['verification'] = array_filter(
				$metadata['verification'],
				static fn( mixed $value ): bool => is_scalar( $value ) || null === $value
			);
		}

		if ( isset( $metadata['wallet'] ) && is_array( $metadata['wallet'] ) ) {
			$safe['wallet'] = $this->sanitize_wallet_metadata( $metadata['wallet'] );
		}

		if ( isset( $metadata['credit_activity'] ) && is_array( $metadata['credit_activity'] ) ) {
			$safe['credit_activity'] = $this->sanitize_credit_activity_metadata( $metadata['credit_activity'] );
		}

		return $safe;
	}

	/**
	 * Sanitize a service-issued account action URL.
	 *
	 * External billing actions must use a complete HTTPS URL and must not expose
	 * credentials in their query string.
	 *
	 * @param mixed $url Potential account action URL.
	 * @return string Safe account action URL, or empty when invalid.
	 */
	private function sanitize_account_url( mixed $url ): string {
		$url    = is_string( $url ) ? esc_url_raw( $url ) : '';
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		if ( '' === $url || 'https' !== $scheme || ! is_string( $host ) || '' === $host ) {
			return '';
		}

		return $this->account_url_has_secret_query_key( $url ) ? '' : $url;
	}

	/**
	 * Determine whether an account action URL contains a blocked secret query key.
	 */
	private function account_url_has_secret_query_key( string $url ): bool {
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! is_string( $query ) || '' === $query ) {
			return false;
		}

		$parameters = array();
		parse_str( $query, $parameters );

		$pending = array( $parameters );
		while ( array() !== $pending ) {
			$current = array_pop( $pending );
			if ( ! is_array( $current ) ) {
				continue;
			}

			foreach ( $current as $key => $value ) {
				if ( is_string( $key ) && $this->is_secret_account_url_query_key( $key ) ) {
					return true;
				}

				if ( is_array( $value ) ) {
					$pending[] = $value;
				}
			}
		}

		return false;
	}

	/**
	 * Determine whether an account action query parameter could expose a secret.
	 *
	 * The option read blocklist remains the canonical protection for WordPress
	 * secrets. Remote account URLs also need to reject conventional credential
	 * parameter names, which are not WordPress option names.
	 */
	private function is_secret_account_url_query_key( string $key ): bool {
		if ( OptionsAbilities::is_secret_option_name( $key ) ) {
			return true;
		}

		$normalized_key = preg_replace( '/(?<!^)[A-Z]/', '_$0', $key ) ?? $key;
		$normalized_key = strtolower( $normalized_key );

		return 1 === preg_match(
			'/(?:^|[_-])(?:access[_-]?token|api[_-]?key|auth[_-]?token|authorization|bearer|client[_-]?secret|credential(?:s)?|key|password|secret|token)(?:$|[_-])/',
			$normalized_key
		);
	}

	/**
	 * Keep only safe scalar wallet metadata for UI balance notices.
	 *
	 * @param array<string, mixed> $wallet Remote or stored wallet metadata.
	 * @return array<string, mixed>
	 */
	private function sanitize_wallet_metadata( array $wallet ): array {
		return array_intersect_key(
			array_filter(
				$wallet,
				static fn( mixed $value ): bool => is_scalar( $value ) || null === $value
			),
			array_flip(
				array(
					'currency',
					'promo_usd_micros',
					'cash_usd_micros',
					'total_usd_micros',
				)
			)
		);
	}

	/**
	 * Keep the bounded, display-safe credit activity contract from the service.
	 *
	 * Each retained event contains only a recognized event type, a signed USD
	 * micro amount, an effective timestamp, and optionally an expiry timestamp
	 * and short safe label. Customer, invoice, payment, processor, token, and
	 * arbitrary event payload fields are never stored or returned.
	 *
	 * @param array<mixed, mixed> $activity Remote credit activity rows.
	 * @return array<int, array<string, int|string>>
	 */
	private function sanitize_credit_activity_metadata( array $activity ): array {
		$safe_activity = array();

		foreach ( array_slice( $activity, 0, self::MAX_CREDIT_ACTIVITY_EVENTS ) as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$type         = $event['type'] ?? '';
			$amount       = $event['amount_usd_micros'] ?? null;
			$effective_at = $this->sanitize_credit_activity_timestamp( $event['effective_at'] ?? null );

			if ( ! is_string( $type ) || ! in_array( $type, self::CREDIT_ACTIVITY_EVENT_TYPES, true ) || ! is_int( $amount ) || null === $effective_at ) {
				continue;
			}

			$safe_event = array(
				'type'              => $type,
				'amount_usd_micros' => $amount,
				'effective_at'      => $effective_at,
			);
			$expires_at = $this->sanitize_credit_activity_timestamp( $event['expires_at'] ?? null );
			if ( null !== $expires_at ) {
				$safe_event['expires_at'] = $expires_at;
			}

			if ( isset( $event['label'] ) && is_string( $event['label'] ) ) {
				$label = substr( sanitize_text_field( $event['label'] ), 0, 120 );
				if ( '' !== $label ) {
					$safe_event['label'] = $label;
				}
			}

			$safe_activity[] = $safe_event;
		}

		usort(
			$safe_activity,
			static fn( array $left, array $right ): int => strcmp( $right['effective_at'], $left['effective_at'] )
		);

		return $safe_activity;
	}

	/**
	 * Normalize a valid remote ISO-8601 timestamp to UTC for browser rendering.
	 *
	 * @param mixed $timestamp Remote timestamp value.
	 * @return string|null Normalized timestamp, or null when invalid.
	 */
	private function sanitize_credit_activity_timestamp( mixed $timestamp ): ?string {
		if ( ! is_string( $timestamp ) || '' === trim( $timestamp ) ) {
			return null;
		}

		$unix_timestamp = rest_parse_date( $timestamp, true );
		if ( false === $unix_timestamp ) {
			return null;
		}

		return gmdate( 'c', $unix_timestamp );
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
