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
	 * Managed connection provisions a local site token and returns safe metadata.
	 */
	public function test_connect_provisions_site_token_without_returning_secret(): void {
		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/connectors/' . SuperdavAiProvider::PROVIDER_ID . '/connect' );
		$request->set_param( 'provider', SuperdavAiProvider::PROVIDER_ID );

		$response = ( new ConnectorsController() )->handle_connect( $request );
		$this->assertNotWPError( $response );

		$token = get_option( SuperdavAiProvider::CREDENTIAL_OPTION, '' );
		$this->assertIsString( $token );
		$this->assertStringStartsWith( 'sdsite_', $token );

		$data = $response->get_data();
		$this->assertTrue( $data['configured'] );
		$this->assertSame( SuperdavAiProvider::PROVIDER_ID, $data['provider'] );
		$this->assertStringNotContainsString( $token, wp_json_encode( $data ) ?: '' );
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

		$request = new WP_REST_Request( 'DELETE', '/sd-ai-agent/v1/connectors/' . SuperdavAiProvider::PROVIDER_ID . '/key' );
		$request->set_param( 'provider', SuperdavAiProvider::PROVIDER_ID );

		$response = ( new ConnectorsController() )->handle_clear_key( $request );
		$this->assertNotWPError( $response );

		$data = $response->get_data();
		$this->assertFalse( $data['configured'] );
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
