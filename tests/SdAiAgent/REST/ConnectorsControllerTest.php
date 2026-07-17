<?php
/**
 * Tests for ConnectorsController.
 *
 * @package SdAiAgent\Tests\REST
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\REST;

use SdAiAgent\Core\SuperdavSiteConnectionService;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiProvider;
use SdAiAgent\REST\ConnectorsController;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Covers service-managed connector semantics for the first-party provider.
 */
final class ConnectorsControllerTest extends WP_UnitTestCase {

	/**
	 * Clean up connector options.
	 */
	public function tear_down(): void {
		remove_all_filters( 'sd_ai_agent_cloud_base_url' );
		remove_all_filters( 'sd_ai_agent_cloud_registration_endpoint' );
		remove_all_filters( 'sd_ai_agent_cloud_revocation_endpoint' );
		remove_all_filters( 'pre_http_request' );
		delete_option( SuperdavAiProvider::CREDENTIAL_OPTION );
		delete_option( SuperdavSiteConnectionService::INSTALLATION_ID_OPTION );
		delete_option( SuperdavSiteConnectionService::TOKEN_METADATA_OPTION );
		parent::tear_down();
	}

	/**
	 * The connector list includes bundled Superdav AI without exposing tokens.
	 */
	public function test_list_includes_bundled_managed_superdav_provider_without_token(): void {
		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, 'secret-site-token', false );

		$response  = ( new ConnectorsController() )->handle_list();
		$data      = $response->get_data();
		$providers = $data['providers'];
		$superdav  = $this->find_provider( $providers, SuperdavAiProvider::PROVIDER_ID );

		$this->assertNotNull( $superdav );
		$this->assertTrue( $superdav['managed'] );
		$this->assertTrue( $superdav['installed'] );
		$this->assertTrue( $superdav['active'] );
		$this->assertTrue( $superdav['configured'] );
		$this->assertSame( '', $superdav['masked_key'] );
		$this->assertStringNotContainsString( 'secret-site-token', wp_json_encode( $superdav ) ?: '' );
	}

	/**
	 * Stored managed-provider metadata is scrubbed before it reaches connector responses.
	 */
	public function test_list_sanitizes_stored_managed_wallet_metadata(): void {
		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, 'secret-site-token', false );
		update_option(
			SuperdavSiteConnectionService::TOKEN_METADATA_OPTION,
			array(
				'wallet' => array(
					'account_id'        => 'acct_secretish_internal',
					'currency'          => 'USD',
					'promo_usd_micros'  => 10000000,
					'internal_metadata' => 'hidden',
				),
				'usage'  => array(
					'remaining' => 10,
					'internal'  => array( 'secret' => 'hidden' ),
				),
			),
			false
		);

		$response  = ( new ConnectorsController() )->handle_list();
		$data      = $response->get_data();
		$superdav  = $this->find_provider( $data['providers'], SuperdavAiProvider::PROVIDER_ID );
		$status    = $superdav['status'];
		$serialized = wp_json_encode( $superdav ) ?: '';

		$this->assertSame( 10000000, $status['wallet']['promo_usd_micros'] );
		$this->assertSame( 'USD', $status['wallet']['currency'] );
		$this->assertArrayNotHasKey( 'account_id', $status['wallet'] );
		$this->assertArrayNotHasKey( 'internal_metadata', $status['wallet'] );
		$this->assertSame( array( 'remaining' => 10 ), $status['usage'] );
		$this->assertStringNotContainsString( 'secret-site-token', $serialized );
		$this->assertStringNotContainsString( 'acct_secretish_internal', $serialized );
	}

	/**
	 * Managed connection provisions a local site token and returns safe metadata.
	 */
	public function test_connect_provisions_site_token_without_returning_secret(): void {
		add_filter( 'sd_ai_agent_cloud_registration_endpoint', static fn(): string => '' );

		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/connectors/' . SuperdavAiProvider::PROVIDER_ID . '/connect' );
		$request->set_param( 'provider', SuperdavAiProvider::PROVIDER_ID );

		$response = ( new ConnectorsController() )->handle_connect( $request );
		$this->assertNotWPError( $response );

		$token = get_option( SuperdavAiProvider::CREDENTIAL_OPTION, '' );
		$this->assertIsString( $token );
		$this->assertStringStartsWith( 'sdsite_', $token );

		$data = $response->get_data();
		$this->assertTrue( $data['configured'] );
		$this->assertTrue( $data['created'] );
		$this->assertSame( SuperdavAiProvider::PROVIDER_ID, $data['provider'] );
		$this->assertTrue( $data['status']['connection_notice_pending'] );
		$this->assertStringNotContainsString( $token, wp_json_encode( $data ) ?: '' );
	}

	/**
	 * Managed remote connection sends private-site metadata and stores returned site_token.
	 */
	public function test_connect_registers_remote_self_declared_site_token(): void {
		$endpoint      = 'https://service.example/v1/site/installations';
		$captured_body = array();

		add_filter( 'sd_ai_agent_cloud_registration_endpoint', static fn(): string => $endpoint );
		add_filter(
			'pre_http_request',
			static function ( mixed $preempt, array $parsed_args, string $url ) use ( $endpoint, &$captured_body ): mixed {
				if ( $endpoint !== $url ) {
					return $preempt;
				}

				$captured_body = json_decode( (string) ( $parsed_args['body'] ?? '' ), true );

				return array(
					'response' => array(
						'code'    => 201,
						'message' => 'Created',
					),
					'body'     => wp_json_encode(
						array(
							'installation_id'  => $captured_body['installation_id'] ?? '',
							'site_token'       => 'sdaist_remote_site_token',
							'tier'             => 'free',
							'verified'         => false,
							'connect_required' => false,
							'wallet'           => array(
								'account_id'        => 'acct_internal_123',
								'currency'          => 'USD',
								'promo_usd_micros'  => 10000000,
								'cash_usd_micros'   => 0,
								'total_usd_micros'  => 10000000,
								'internal_metadata' => 'hidden',
							),
							'verification'     => array(
								'state' => 'self_declared',
							),
						)
					),
				);
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/connectors/' . SuperdavAiProvider::PROVIDER_ID . '/connect' );
		$request->set_param( 'provider', SuperdavAiProvider::PROVIDER_ID );

		$response = ( new ConnectorsController() )->handle_connect( $request );
		$this->assertNotWPError( $response );

		$this->assertSame( 'sdaist_remote_site_token', get_option( SuperdavAiProvider::CREDENTIAL_OPTION, '' ) );
		$this->assertIsArray( $captured_body );
		$this->assertNotEmpty( $captured_body['installation_id'] );
		$this->assertSame( home_url( '/' ), $captured_body['site_url'] );
		$this->assertSame( SD_AI_AGENT_VERSION, $captured_body['plugin_version'] );
		$this->assertNotEmpty( $captured_body['wordpress_version'] );
		$this->assertArrayNotHasKey( 'verification', $captured_body );

		$data = $response->get_data();
		$this->assertTrue( $data['configured'] );
		$this->assertTrue( $data['created'] );
		$this->assertSame( 'site', $data['status']['connection_mode'] );
		$this->assertFalse( $data['status']['verified'] );
		$this->assertTrue( $data['status']['connection_notice_pending'] );
		$this->assertSame( 10000000, $data['status']['wallet']['promo_usd_micros'] );
		$this->assertArrayNotHasKey( 'account_id', $data['status']['wallet'] );
		$this->assertArrayNotHasKey( 'internal_metadata', $data['status']['wallet'] );
		$this->assertStringNotContainsString( 'sdaist_remote_site_token', wp_json_encode( $data ) ?: '' );
	}

	/**
	 * Existing managed tokens are reported without re-registering a new free entitlement.
	 */
	public function test_connect_does_not_re_register_existing_managed_token(): void {
		$endpoint          = 'https://service.example/v1/site/installations';
		$registration_hits = 0;

		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, 'existing-site-token', false );
		add_filter( 'sd_ai_agent_cloud_registration_endpoint', static fn(): string => $endpoint );
		add_filter(
			'pre_http_request',
			static function ( mixed $preempt, array $parsed_args, string $url ) use ( $endpoint, &$registration_hits ): mixed {
				if ( $endpoint === $url ) {
					++$registration_hits;
				}

				return $preempt;
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/connectors/' . SuperdavAiProvider::PROVIDER_ID . '/connect' );
		$request->set_param( 'provider', SuperdavAiProvider::PROVIDER_ID );

		$response = ( new ConnectorsController() )->handle_connect( $request );
		$this->assertNotWPError( $response );

		$data = $response->get_data();
		$this->assertTrue( $data['configured'] );
		$this->assertFalse( $data['created'] );
		$this->assertSame( 0, $registration_hits );
		$this->assertSame( 'existing-site-token', get_option( SuperdavAiProvider::CREDENTIAL_OPTION, '' ) );
		$this->assertArrayNotHasKey( 'connection_notice_pending', $data['status'] );
	}

	/**
	 * The configured cloud base URL defaults site registration to `/site/installations`.
	 */
	public function test_connect_registers_against_cloud_base_url_default_endpoint(): void {
		$base_url     = 'https://service.example/v1';
		$expected_url = $base_url . '/site/installations';
		$captured_url = '';

		add_filter( 'sd_ai_agent_cloud_base_url', static fn(): string => $base_url );
		add_filter(
			'pre_http_request',
			static function ( mixed $preempt, array $parsed_args, string $url ) use ( $expected_url, &$captured_url ): mixed {
				$captured_url = $url;
				$body         = json_decode( (string) ( $parsed_args['body'] ?? '' ), true );

				return array(
					'response' => array(
						'code'    => $expected_url === $url ? 201 : 404,
						'message' => $expected_url === $url ? 'Created' : 'Not Found',
					),
					'body'     => wp_json_encode(
						array(
							'installation_id'  => is_array( $body ) ? (string) ( $body['installation_id'] ?? '' ) : '',
							'site_token'       => 'sdaist_remote_site_token',
							'tier'             => 'free',
							'verified'         => false,
							'connect_required' => false,
						)
					),
				);
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/connectors/' . SuperdavAiProvider::PROVIDER_ID . '/connect' );
		$request->set_param( 'provider', SuperdavAiProvider::PROVIDER_ID );

		$response = ( new ConnectorsController() )->handle_connect( $request );
		$this->assertNotWPError( $response );

		$this->assertSame( $expected_url, $captured_url );
		$this->assertSame( 'sdaist_remote_site_token', get_option( SuperdavAiProvider::CREDENTIAL_OPTION, '' ) );
	}

	/**
	 * Managed providers reject manual raw API-key storage.
	 */
	public function test_managed_provider_rejects_manual_api_key(): void {
		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/connectors/' . SuperdavAiProvider::PROVIDER_ID . '/key' );
		$request->set_param( 'provider', SuperdavAiProvider::PROVIDER_ID );
		$request->set_param( 'api_key', 'manual-secret' );

		$result = ( new ConnectorsController() )->handle_set_key( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'managed_provider_key_rejected', $result->get_error_code() );
		$this->assertSame( '', get_option( SuperdavAiProvider::CREDENTIAL_OPTION, '' ) );
	}

	/**
	 * Disconnect clears the local token and reports unconfigured state.
	 */
	public function test_disconnect_clears_managed_provider_token(): void {
		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, 'secret-site-token', false );
		add_filter( 'sd_ai_agent_cloud_revocation_endpoint', static fn(): string => '' );

		$request = new WP_REST_Request( 'DELETE', '/sd-ai-agent/v1/connectors/' . SuperdavAiProvider::PROVIDER_ID . '/key' );
		$request->set_param( 'provider', SuperdavAiProvider::PROVIDER_ID );

		$response = ( new ConnectorsController() )->handle_clear_key( $request );
		$this->assertNotWPError( $response );

		$data = $response->get_data();
		$this->assertFalse( $data['configured'] );
		$this->assertSame( '', get_option( SuperdavAiProvider::CREDENTIAL_OPTION, '' ) );
	}

	/**
	 * The configured cloud base URL defaults token revocation to `/site/token/revoke`.
	 */
	public function test_disconnect_revokes_against_cloud_base_url_default_endpoint(): void {
		$base_url        = 'https://service.example/v1';
		$expected_url    = $base_url . '/site/token/revoke';
		$captured_url    = '';
		$captured_header = '';

		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, 'secret-site-token', false );
		add_filter( 'sd_ai_agent_cloud_base_url', static fn(): string => $base_url );
		add_filter(
			'pre_http_request',
			static function ( mixed $preempt, array $parsed_args, string $url ) use ( $expected_url, &$captured_url, &$captured_header ): mixed {
				$captured_url    = $url;
				$headers         = isset( $parsed_args['headers'] ) && is_array( $parsed_args['headers'] ) ? $parsed_args['headers'] : array();
				$captured_header = is_string( $headers['Authorization'] ?? null ) ? $headers['Authorization'] : '';

				return array(
					'response' => array(
						'code'    => $expected_url === $url ? 200 : 404,
						'message' => $expected_url === $url ? 'OK' : 'Not Found',
					),
					'body'     => '{}',
				);
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'DELETE', '/sd-ai-agent/v1/connectors/' . SuperdavAiProvider::PROVIDER_ID . '/key' );
		$request->set_param( 'provider', SuperdavAiProvider::PROVIDER_ID );

		$response = ( new ConnectorsController() )->handle_clear_key( $request );
		$this->assertNotWPError( $response );

		$data = $response->get_data();
		$this->assertFalse( $data['configured'] );
		$this->assertSame( $expected_url, $captured_url );
		$this->assertSame( 'Bearer secret-site-token', $captured_header );
		$this->assertSame( '', get_option( SuperdavAiProvider::CREDENTIAL_OPTION, '' ) );
	}

	/**
	 * Find a provider entry by ID.
	 *
	 * @param array<int, array<string, mixed>> $providers Provider rows.
	 * @param string                          $provider_id Provider ID.
	 * @return array<string, mixed>|null
	 */
	private function find_provider( array $providers, string $provider_id ): ?array {
		foreach ( $providers as $provider ) {
			if ( $provider_id === $provider['id'] ) {
				return $provider;
			}
		}

		return null;
	}
}
