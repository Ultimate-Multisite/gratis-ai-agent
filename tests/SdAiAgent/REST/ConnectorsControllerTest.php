<?php
/**
 * Tests for ConnectorsController.
 *
 * @package SdAiAgent\Tests\REST
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\REST;

use SdAiAgent\REST\ConnectorsController;
use WP_UnitTestCase;

/**
 * Covers safe connector credential reporting.
 */
final class ConnectorsControllerTest extends WP_UnitTestCase {

	/**
	 * Clean up connector options.
	 */
	public function tear_down(): void {
		delete_option( 'connectors_ai_openai_api_key' );
		parent::tear_down();
	}

	/**
	 * The connector list uses the Connectors API mask and never exposes raw keys.
	 */
	public function test_list_reports_masked_connector_status_without_raw_key(): void {
		$fixture_value = 'fixture-openai-value-tail-1234';
		update_option( 'connectors_ai_openai_api_key', $fixture_value, false );

		$response  = ( new ConnectorsController() )->handle_list();
		$data      = $response->get_data();
		$providers = $data['providers'];
		$openai    = $this->find_provider( $providers, 'openai' );

		$this->assertNotNull( $openai );
		$this->assertTrue( $openai['configured'] );
		$this->assertIsString( $openai['masked_key'] );
		$this->assertStringEndsWith( '1234', $openai['masked_key'] );
		$this->assertStringNotContainsString( $fixture_value, wp_json_encode( $openai ) ?: '' );
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
