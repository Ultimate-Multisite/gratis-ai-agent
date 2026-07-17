<?php
/**
 * Tests for SettingsController provider bootstrap behavior.
 *
 * @package SdAiAgent\Tests\REST
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\REST;

use SdAiAgent\Bootstrap\SuperdavAiProviderHandler;
use SdAiAgent\Abilities\SmsAbilities;
use SdAiAgent\Core\Database;
use SdAiAgent\Core\Settings;
use SdAiAgent\Core\SuperdavSiteConnectionService;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiProvider;
use SdAiAgent\REST\SettingsController;
use WP_REST_Request;
use WP_UnitTestCase;
use WordPress\AiClient\AiClient;

/**
 * Covers first-install provider auto-provisioning.
 */
final class SettingsControllerTest extends WP_UnitTestCase {

	/**
	 * Reset cached SDK model metadata before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->invalidate_superdav_model_cache();
	}

	/**
	 * Clean up provider-specific options and filters.
	 */
	public function tear_down(): void {
		$this->invalidate_superdav_model_cache();
		Settings::instance()->set_google_calendar_credentials( array() );
		delete_option( SuperdavAiProvider::CREDENTIAL_OPTION );
		delete_option( Settings::SMS_PROVIDER_OPTION );
		delete_option( SuperdavSiteConnectionService::INSTALLATION_ID_OPTION );
		delete_option( SuperdavSiteConnectionService::TOKEN_METADATA_OPTION );
		remove_all_filters( 'sd_ai_agent_cloud_base_url' );
		remove_all_filters( 'sd_ai_agent_options_read_blocklist' );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/** Google Calendar credential GET responses expose metadata without secrets. */
	public function test_google_calendar_credentials_responses_do_not_expose_secrets(): void {
		$controller = new SettingsController( new Settings(), new Database() );
		$request    = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/settings/google-calendar' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'type'                => 'oauth2_refresh_token',
					'client_id'           => 'calendar-client-id',
					'client_secret'       => 'calendar-client-secret',
					'refresh_token'       => 'calendar-refresh-token',
					'default_calendar_id' => 'team@example.com',
				)
			)
		);
		$request->set_header( 'Content-Type', 'application/json' );

		$save_response = $controller->handle_set_google_calendar_credentials( $request );
		$get_response  = $controller->handle_get_google_calendar_credentials();
		$settings      = $controller->handle_get_settings();

		$this->assertSame( 200, $save_response->get_status() );
		$this->assertStringNotContainsString( 'calendar-client-secret', wp_json_encode( $save_response->get_data() ) ?: '' );
		$this->assertStringNotContainsString( 'calendar-refresh-token', wp_json_encode( $save_response->get_data() ) ?: '' );
		$this->assertStringNotContainsString( 'calendar-client-secret', wp_json_encode( $get_response->get_data() ) ?: '' );
		$this->assertStringNotContainsString( 'calendar-refresh-token', wp_json_encode( $get_response->get_data() ) ?: '' );
		$this->assertStringNotContainsString( 'calendar-client-secret', wp_json_encode( $settings->get_data() ) ?: '' );
		$this->assertStringNotContainsString( 'calendar-refresh-token', wp_json_encode( $settings->get_data() ) ?: '' );
		$this->assertSame( 'team@example.com', $get_response->get_data()['default_calendar_id'] ?? '' );
	}

	/** Google Calendar credential save validates supported credential type. */
	public function test_google_calendar_credentials_reject_invalid_type(): void {
		$controller = new SettingsController( new Settings(), new Database() );
		$request    = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/settings/google-calendar' );
		$request->set_body( (string) wp_json_encode( array( 'type' => 'service_account' ) ) );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $controller->handle_set_google_calendar_credentials( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/** Calendar reminder dry-run returns setup status when Google Calendar is not connected. */
	public function test_calendar_reminders_dry_run_missing_google_calendar_credentials_returns_setup_status(): void {
		$controller = new SettingsController( new Settings(), new Database() );
		$request    = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/settings/calendar-reminders/dry-run' );
		$request->set_body( (string) wp_json_encode( array() ) );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $controller->handle_calendar_reminders_dry_run( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'google_calendar_not_configured', $response->get_error_code() );
		$this->assertSame( 412, $response->get_error_data()['status'] ?? null );
	}

	/**
	 * /providers auto-provisions the managed Superdav token before listing providers.
	 */
	public function test_handle_providers_auto_provisions_managed_superdav_provider(): void {
		if ( ! class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'WordPress AI Client SDK is unavailable.' );
		}

		$base_url         = 'https://service.example/v1';
		$registration_url = $base_url . '/site/installations';
		$models_url       = $base_url . '/models';
		$registration_hits = 0;

		add_filter( 'sd_ai_agent_cloud_base_url', static fn(): string => $base_url );
		add_filter(
			'pre_http_request',
			static function ( mixed $preempt, array $parsed_args, string $url ) use ( $registration_url, $models_url, &$registration_hits ): mixed {
				if ( $registration_url === $url ) {
					++$registration_hits;
					$body             = json_decode( (string) ( $parsed_args['body'] ?? '' ), true );

					return array(
						'response' => array(
							'code'    => 201,
							'message' => 'Created',
						),
						'body'     => wp_json_encode(
							array(
								'installation_id' => is_array( $body ) ? (string) ( $body['installation_id'] ?? '' ) : '',
								'site_token'      => 'sdaist_auto_provisioned_token',
								'tier'            => 'free',
								'verified'        => true,
								'wallet'          => array(
									'currency'         => 'USD',
									'promo_usd_micros' => 10000000,
									'cash_usd_micros'  => 0,
									'total_usd_micros' => 10000000,
								),
							)
						),
					);
				}

				if ( $models_url === $url ) {
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'data' => array(
									array(
										'id'                => 'superdav-chat-fast',
										'name'              => 'Superdav Chat Fast',
										'context_length'    => 128000,
										'max_output_length' => 8192,
									),
								),
							)
						),
					);
				}

				return $preempt;
			},
			10,
			3
		);

		( new SuperdavAiProviderHandler() )->register_provider();

		$controller = new SettingsController( new Settings(), new Database() );
		$response   = $controller->handle_providers();
		$providers = $response->get_data();
		$superdav  = $this->find_provider( is_array( $providers ) ? $providers : array(), SuperdavAiProvider::PROVIDER_ID );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $registration_hits );
		$this->assertSame( 'sdaist_auto_provisioned_token', get_option( SuperdavAiProvider::CREDENTIAL_OPTION, '' ) );
		$this->assertNotNull( $superdav );
		$this->assertTrue( $superdav['configured'] );
		$this->assertSame( SuperdavAiProvider::DEFAULT_MODEL_ID, $superdav['default_model'] ?? '' );
		$this->assertTrue( $superdav['status']['connection_notice_pending'] );
		$this->assertSame( 10000000, $superdav['status']['wallet']['promo_usd_micros'] );
		$this->assertSame( 'superdav-chat-fast', $superdav['models'][0]['id'] ?? '' );
		$this->assertStringNotContainsString( 'sdaist_auto_provisioned_token', wp_json_encode( $superdav ) ?: '' );

		$second_response = $controller->handle_providers();
		$this->assertSame( 200, $second_response->get_status() );
		$this->assertSame( 1, $registration_hits );
	}

	/** Superdav account refresh returns safe wallet metadata without a bearer token. */
	public function test_handle_refresh_superdav_account_returns_safe_wallet_metadata(): void {
		$base_url    = 'https://service.example/v1';
		$account_url = $base_url . '/site/account';
		$token       = 'sdaist_account_refresh_token';

		add_filter( 'sd_ai_agent_cloud_base_url', static fn(): string => $base_url );
		add_filter(
			'pre_http_request',
			static function ( mixed $preempt, array $parsed_args, string $url ) use ( $account_url, $token ): mixed {
				if ( $account_url !== $url ) {
					return $preempt;
				}

				self::assertSame( 'Bearer ' . $token, self::authorization_header_from_args( $parsed_args ) );

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'tier'               => 'pro',
							'account_portal_url' => 'https://account.example/billing',
							'wallet'             => array(
								'currency'         => 'USD',
								'promo_usd_micros' => 2500000,
								'cash_usd_micros'  => 12500000,
								'total_usd_micros' => 15000000,
								'payment_token'    => 'must-not-be-exposed',
							),
							'access_token'       => 'must-not-be-exposed',
						)
					),
				);
			},
			10,
			3
		);

		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, $token, false );
		update_option(
			SuperdavSiteConnectionService::TOKEN_METADATA_OPTION,
			array(
				'connected_at' => '2026-07-16T00:00:00+00:00',
				'usage'        => array( 'requests' => 99 ),
				'verification' => array( 'status' => 'stale' ),
			),
			false
		);
		$response = ( new SettingsController( new Settings(), new Database() ) )->handle_refresh_superdav_account();

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 15000000, $data['wallet']['total_usd_micros'] );
		$this->assertSame( 'https://account.example/billing', $data['account_portal_url'] );
		$this->assertSame( '2026-07-16T00:00:00+00:00', $data['connected_at'] );
		$this->assertArrayNotHasKey( 'usage', $data );
		$this->assertArrayNotHasKey( 'verification', $data );
		$this->assertStringNotContainsString( $token, wp_json_encode( $data ) ?: '' );
		$this->assertStringNotContainsString( 'must-not-be-exposed', wp_json_encode( $data ) ?: '' );
	}

	/** Account portal URLs containing centrally blocked query keys are not exposed. */
	public function test_handle_refresh_superdav_account_rejects_secret_portal_query(): void {
		$base_url    = 'https://service.example/v1';
		$account_url = $base_url . '/site/account';

		add_filter( 'sd_ai_agent_cloud_base_url', static fn(): string => $base_url );
		add_filter(
			'sd_ai_agent_options_read_blocklist',
			static function ( array $blocklist ): array {
				$blocklist[] = 'access_token';

				return $blocklist;
			}
		);
		add_filter(
			'pre_http_request',
			static function ( mixed $preempt, array $parsed_args, string $url ) use ( $account_url ): mixed {
				if ( $account_url !== $url ) {
					return $preempt;
				}

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'account_portal_url' => 'https://account.example/billing?access_token=must-not-be-exposed',
						)
					),
				);
			},
			10,
			3
		);

		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, 'sdaist_portal_test_token', false );
		$response = ( new SettingsController( new Settings(), new Database() ) )->handle_refresh_superdav_account();

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( '', $data['account_portal_url'] );
		$this->assertStringNotContainsString( 'must-not-be-exposed', wp_json_encode( $data ) ?: '' );
		$this->assertStringNotContainsString(
			'must-not-be-exposed',
			wp_json_encode( get_option( SuperdavSiteConnectionService::TOKEN_METADATA_OPTION, array() ) ) ?: ''
		);
	}

	/**
	 * /providers refreshes a stale managed Superdav token when model listing returns 401.
	 */
	public function test_handle_providers_refreshes_stale_managed_superdav_token(): void {
		if ( ! class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'WordPress AI Client SDK is unavailable.' );
		}

		$base_url          = 'https://service.example/v1';
		$registration_url  = $base_url . '/site/installations';
		$models_url        = $base_url . '/models';
		$registration_hits = 0;
		$model_hits        = 0;
		$registration_ids  = array();

		add_filter( 'sd_ai_agent_cloud_base_url', static fn(): string => $base_url );
		add_filter(
			'pre_http_request',
			static function ( mixed $preempt, array $parsed_args, string $url ) use ( $registration_url, $models_url, &$registration_hits, &$model_hits, &$registration_ids ): mixed {
				if ( $registration_url === $url ) {
					++$registration_hits;
					$body               = json_decode( (string) ( $parsed_args['body'] ?? '' ), true );
					$registration_ids[] = is_array( $body ) ? (string) ( $body['installation_id'] ?? '' ) : '';

					return array(
						'response' => array(
							'code'    => 201,
							'message' => 'Created',
						),
						'body'     => wp_json_encode(
							array(
								'site_token' => 'sdaist_refreshed_token',
								'tier'       => 'free',
								'verified'   => true,
							)
						),
					);
				}

				if ( $models_url === $url ) {
					++$model_hits;
					$authorization = self::authorization_header_from_args( $parsed_args );
					if ( 'Bearer sdaist_refreshed_token' !== $authorization ) {
						return array(
							'response' => array(
								'code'    => 401,
								'message' => 'Unauthorized',
							),
							'body'     => wp_json_encode(
								array(
									'error' => array(
										'code'    => 'site_token_invalid',
										'message' => 'Invalid or missing site token.',
									),
								)
							),
						);
					}

					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'data' => array(
									array(
										'id'                => 'superdav-chat-fast',
										'name'              => 'Superdav Chat Fast',
										'context_length'    => 128000,
										'max_output_length' => 8192,
									),
									array(
										'id'                => 'superdav-chat-pro',
										'name'              => 'Superdav Chat Pro',
										'context_length'    => 200000,
										'max_output_length' => 16384,
									),
								),
							)
						),
					);
				}

				return $preempt;
			},
			10,
			3
		);

		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, 'sdaist_stale_token', false );
		( new SuperdavAiProviderHandler() )->register_provider();

		$response  = ( new SettingsController( new Settings(), new Database() ) )->handle_providers();
		$providers = $response->get_data();
		$superdav  = $this->find_provider( is_array( $providers ) ? $providers : array(), SuperdavAiProvider::PROVIDER_ID );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $registration_hits );
		$this->assertSame( get_option( SuperdavSiteConnectionService::INSTALLATION_ID_OPTION, '' ), $registration_ids[0] ?? '' );
		$this->assertSame( 2, $model_hits );
		$this->assertSame( 'sdaist_refreshed_token', get_option( SuperdavAiProvider::CREDENTIAL_OPTION, '' ) );
		$this->assertNotNull( $superdav );
		$this->assertSame( SuperdavAiProvider::DEFAULT_MODEL_ID, $superdav['default_model'] ?? '' );
		$this->assertSame( array( 'superdav-chat-fast', 'superdav-chat-pro' ), wp_list_pluck( $superdav['models'] ?? array(), 'id' ) );
	}

	/**
	 * SMS provider settings can be saved and read without exposing the API key.
	 */
	public function test_handle_sms_provider_save_and_get_returns_safe_metadata(): void {
		$controller = new SettingsController( new Settings(), new Database() );

		$response = $controller->handle_set_sms_provider(
			$this->json_request(
				[
					'provider'     => 'textbee',
					'api_key'      => 'tb_secret_key',
					'device_id'    => 'android-device-1234',
					'api_base_url' => 'https://textbee.example/',
				]
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertTrue( $data['configured'] );
		$this->assertTrue( $data['has_api_key'] );
		$this->assertSame( 'textbee', $data['provider'] );
		$this->assertSame( 'https://textbee.example', $data['api_base_url'] );
		$this->assertSame( '********1234', $data['device_id_redacted'] );
		$this->assertArrayNotHasKey( 'api_key', $data );
		$this->assertStringNotContainsString( 'tb_secret_key', wp_json_encode( $data ) ?: '' );

		$get_response = $controller->handle_get_sms_provider();
		$get_data     = $get_response->get_data();
		$this->assertIsArray( $get_data );
		$this->assertTrue( $get_data['configured'] );
		$this->assertArrayNotHasKey( 'api_key', $get_data );
		$this->assertStringNotContainsString( 'tb_secret_key', wp_json_encode( $get_data ) ?: '' );
	}

	/**
	 * Invalid SMS provider base URLs are rejected.
	 */
	public function test_handle_sms_provider_invalid_base_url_returns_error(): void {
		$controller = new SettingsController( new Settings(), new Database() );
		$response   = $controller->handle_set_sms_provider(
			$this->json_request(
				[
					'provider'     => 'textbee',
					'api_key'      => 'tb_secret_key',
					'device_id'    => 'android-device-1234',
					'api_base_url' => 'not-a-url',
				]
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( ( new Settings() )->has_sms_provider() );
	}

	/**
	 * SMS provider credentials can be deleted.
	 */
	public function test_handle_sms_provider_delete_clears_credentials(): void {
		$settings = new Settings();
		$settings->set_sms_provider(
			[
				'provider'     => 'textbee',
				'api_key'      => 'tb_secret_key',
				'device_id'    => 'android-device-1234',
				'api_base_url' => SmsAbilities::DEFAULT_API_BASE_URL,
			]
		);

		$controller = new SettingsController( $settings, new Database() );
		$response   = $controller->handle_delete_sms_provider( new WP_REST_Request( 'DELETE', '/sd-ai-agent/v1/settings/sms-provider' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $settings->has_sms_provider() );
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
			if ( $provider_id === ( $provider['id'] ?? '' ) ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * Extract the Authorization header from a pre_http_request argument array.
	 *
	 * @param array<string, mixed> $parsed_args Parsed HTTP arguments.
	 * @return string Authorization header value.
	 */
	private static function authorization_header_from_args( array $parsed_args ): string {
		$headers = (array) ( $parsed_args['headers'] ?? array() );
		foreach ( $headers as $name => $value ) {
			if ( 'authorization' !== strtolower( (string) $name ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$value = reset( $value );
			}

			return is_string( $value ) ? $value : '';
		}

		return '';
	}

	/**
	 * Build a JSON REST request for controller tests.
	 *
	 * @param array<string, mixed> $params JSON request parameters.
	 * @return WP_REST_Request
	 */
	private function json_request( array $params ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/settings/sms-provider' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) ?: '{}' );

		return $request;
	}

	/**
	 * Invalidate the SDK cache for Superdav model metadata.
	 */
	private function invalidate_superdav_model_cache(): void {
		if ( ! class_exists( AiClient::class ) ) {
			return;
		}

		try {
			$directory = SuperdavAiProvider::modelMetadataDirectory();
			if ( method_exists( $directory, 'invalidateCaches' ) ) {
				$directory->invalidateCaches();
			}
		} catch ( \Throwable ) {
			return;
		}
	}
}
