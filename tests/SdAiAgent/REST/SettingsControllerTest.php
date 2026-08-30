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
use SdAiAgent\Abilities\MessagingAbilities;
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
		remove_all_filters( 'sd_ai_agent_cloud_account_action_endpoint' );
		remove_all_filters( 'sd_ai_agent_cloud_account_coupon_redemption_endpoint' );
		remove_all_filters( 'sd_ai_agent_options_read_blocklist' );
		remove_all_filters( 'pre_update_option_' . SuperdavSiteConnectionService::TOKEN_METADATA_OPTION );
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
										'name'              => 'Speedy',
										'context_length'    => 128000,
										'max_output_length' => 8192,
										'capabilities'      => array( 'text_generation' ),
									),
									array(
										'id'           => 'superdav-image',
										'name'         => 'Superdav Image',
										'capabilities' => array( 'image_generation' ),
									),
									array(
										'id'           => 'superdav-video',
										'name'         => 'Superdav Video',
										'capabilities' => array( 'video_generation' ),
									),
									array(
										'id'           => 'superdav-tts',
										'name'         => 'Superdav TTS',
										'capabilities' => array( 'text_to_speech_conversion' ),
									),
									array(
										'id'           => 'superdav-embedding',
										'name'         => 'Superdav Embeddings',
										'capabilities' => array( 'embedding_generation' ),
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
		$this->assertSame( array( 'superdav-chat-fast' ), wp_list_pluck( $superdav['models'], 'id' ) );
		$this->assertStringNotContainsString( 'sdaist_auto_provisioned_token', wp_json_encode( $superdav ) ?: '' );

		$second_response = $controller->handle_providers();
		$this->assertSame( 200, $second_response->get_status() );
		$this->assertSame( 1, $registration_hits );
	}

	/**
	 * Agent model lists use declared capabilities rather than model identifiers.
	 */
	public function test_text_generation_model_filter_uses_provider_metadata(): void {
		$method = new \ReflectionMethod( SettingsController::class, 'filter_text_generation_models' );
		$method->setAccessible( true );

		$models = $method->invoke(
			null,
			array(
				array( 'id' => 'text-list', 'capabilities' => array( 'text_generation' ) ),
				array( 'id' => 'text-map', 'capabilities' => array( 'text_generation' => true ) ),
				array( 'id' => 'text-supported', 'supported_capabilities' => array( 'text-generation' ) ),
				array( 'id' => 'text-flag', 'supports_text_generation' => true ),
				array( 'id' => 'image', 'capabilities' => array( 'image_generation' ) ),
				array( 'id' => 'video', 'capabilities' => array( 'video_generation' ) ),
				array( 'id' => 'tts', 'capabilities' => array( 'text_to_speech_conversion' ) ),
				array( 'id' => 'speech', 'capabilities' => array( 'speech_generation' ) ),
				array( 'id' => 'embedding', 'capabilities' => array( 'embedding_generation' ) ),
				array( 'id' => 'unknown' ),
			)
		);

		$this->assertSame( array( 'text-list', 'text-map', 'text-supported', 'text-flag' ), wp_list_pluck( $models, 'id' ) );
	}

	/** Superdav account refresh returns safe wallet metadata without a bearer token. */
	public function test_handle_refresh_superdav_account_returns_safe_wallet_metadata(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$session_id = Database::create_session(
			array(
				'user_id'     => $user_id,
				'title'       => 'Build the landing page',
				'provider_id' => SuperdavAiProvider::PROVIDER_ID,
				'model_id'    => SuperdavAiProvider::DEFAULT_MODEL_ID,
			)
		);
		$this->assertIsInt( $session_id );
		Database::update_session(
			$session_id,
			array(
				'messages'   => wp_json_encode( array( array( 'role' => 'user' ), array( 'role' => 'assistant' ) ) ),
				'tool_calls' => wp_json_encode( array( array( 'name' => 'site-info' ), array( 'name' => 'update-post' ) ) ),
			)
		);

		$base_url    = 'https://service.example/v1';
		$account_url = $base_url . '/site/account';
		$token       = 'sdaist_account_refresh_token';

		add_filter( 'sd_ai_agent_cloud_base_url', static fn(): string => $base_url );
		add_filter(
			'pre_http_request',
			static function ( mixed $preempt, array $parsed_args, string $url ) use ( $account_url, $token, $session_id ): mixed {
				if ( $account_url !== $url ) {
					return $preempt;
				}

				self::assertSame( 'Bearer ' . $token, self::authorization_header_from_args( $parsed_args ) );
				$request_body = json_decode( (string) ( $parsed_args['body'] ?? '' ), true );
				self::assertSame( SuperdavSiteConnectionService::MAX_CREDIT_ACTIVITY_EVENTS, $request_body['credit_activity_limit'] ?? null );
				self::assertSame( SuperdavSiteConnectionService::MAX_CHAT_SESSION_EVENTS, $request_body['chat_session_limit'] ?? null );

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'tier'                 => 'pro',
							'account_portal_url'   => 'https://account.example/action?sdai_action_ticket=opaque-action-ticket',
							'purchase_credits_url' => 'https://account.example/action?sdai_action_ticket=opaque-action-ticket',
							'payment_methods_url'  => 'https://account.example/action?sdai_action_ticket=opaque-action-ticket',
							'link_account_url'     => 'https://account.example/action?sdai_action_ticket=opaque-action-ticket',
							'billing_actions'      => array(
								'account_portal'   => array( 'available' => true ),
								'purchase_credits' => array( 'available' => true ),
								'payment_methods'  => array( 'available' => true ),
								'link_account'     => array( 'available' => true ),
							),
							'linked_user'          => array(
								'display_name'      => 'Verified Customer',
								'masked_email'      => 'v***@example.test',
								'email_verified'    => true,
								'email_verified_at' => '2026-08-05T12:00:00Z',
								'raw_email'         => 'must-not-be-exposed',
							),
							'wallet'             => array(
								'currency'         => 'USD',
								'promo_usd_micros' => 2500000,
								'cash_usd_micros'  => 12500000,
								'total_usd_micros' => 15000000,
								'payment_token'    => 'must-not-be-exposed',
							),
							'chat_sessions' => array(
								array(
									'session_id'         => (string) $session_id,
									'started_at'         => '2026-07-16T00:00:00Z',
									'last_used_at'       => '2026-07-16T00:05:00Z',
									'input_tokens'       => 1200,
									'cached_input_tokens' => 300,
									'output_tokens'      => 400,
									'total_tokens'       => 1600,
									'cost_usd_micros'    => 125000,
									'loop_count'         => 3,
									'models'             => array(
										array(
											'model_id'           => SuperdavAiProvider::DEFAULT_MODEL_ID,
											'input_tokens'       => 1200,
											'cached_input_tokens' => 300,
											'output_tokens'      => 400,
											'total_tokens'       => 1600,
											'cost_usd_micros'    => 125000,
											'loop_count'         => 3,
											'upstream_request_id' => 'must-not-be-exposed',
										),
									),
									'account_id'         => 'must-not-be-exposed',
								),
							),
							'credit_activity' => array(
								array(
									'type'              => 'purchase',
									'amount_usd_micros' => 12500000,
									'effective_at'      => '2026-07-15T00:00:00Z',
									'label'             => 'Credit purchase',
									'invoice_id'        => 'must-not-be-exposed',
								),
								array(
									'type'              => 'promotion',
									'amount_usd_micros' => 2500000,
									'effective_at'      => '2026-07-16T00:00:00Z',
									'expires_at'        => '2026-08-16T00:00:00Z',
									'label'             => 'Welcome coupon',
									'customer_id'       => 'must-not-be-exposed',
								),
								array(
									'type'              => 'processor_event',
									'amount_usd_micros' => 5000000,
									'effective_at'      => '2026-07-17T00:00:00Z',
								),
								array(
									'type'              => 'consumed',
									'amount_usd_micros' => '-1000000',
									'effective_at'      => 'invalid',
								),
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
				'refreshed_at'  => '2026-07-15T00:00:00+00:00',
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
		$this->assertSame( '', $data['account_portal_url'] );
		$this->assertSame( '', $data['purchase_credits_url'] );
		$this->assertSame( '', $data['payment_methods_url'] );
		$this->assertSame( '', $data['link_account_url'] );
		$this->assertTrue( $data['account_portal_available'] );
		$this->assertTrue( $data['purchase_credits_available'] );
		$this->assertTrue( $data['payment_methods_available'] );
		$this->assertTrue( $data['link_account_available'] );
		$this->assertSame(
			array(
				'display_name'      => 'Verified Customer',
				'masked_email'      => 'v***@example.test',
				'email_verified'    => true,
				'email_verified_at' => '2026-08-05T12:00:00+00:00',
			),
			$data['linked_user']
		);
		$this->assertSame( '2026-07-16T00:00:00+00:00', $data['connected_at'] );
		$this->assertSame( wp_timezone_string(), $data['site_timezone'] );
		$this->assertCount( 2, $data['credit_activity'] );
		$this->assertSame( 'promotion', $data['credit_activity'][0]['type'] );
		$this->assertSame( 2500000, $data['credit_activity'][0]['amount_usd_micros'] );
		$this->assertSame( '2026-08-16T00:00:00+00:00', $data['credit_activity'][0]['expires_at'] );
		$this->assertArrayNotHasKey( 'customer_id', $data['credit_activity'][0] );
		$this->assertCount( 1, $data['chat_sessions'] );
		$this->assertSame( $session_id, $data['chat_sessions'][0]['session_id'] );
		$this->assertSame( 'Build the landing page', $data['chat_sessions'][0]['title'] );
		$this->assertSame( 2, $data['chat_sessions'][0]['tool_call_count'] );
		$this->assertSame( 2, $data['chat_sessions'][0]['message_count'] );
		$this->assertSame( 125000, $data['chat_sessions'][0]['cost_usd_micros'] );
		$this->assertSame( SuperdavAiProvider::DEFAULT_MODEL_ID, $data['chat_sessions'][0]['models'][0]['model_id'] );
		$this->assertArrayNotHasKey( 'upstream_request_id', $data['chat_sessions'][0]['models'][0] );
		$this->assertArrayNotHasKey( 'usage', $data );
		$this->assertArrayNotHasKey( 'verification', $data );
		$this->assertArrayNotHasKey( 'refreshed_at', $data );
		$this->assertStringNotContainsString( $token, wp_json_encode( $data ) ?: '' );
		$this->assertStringNotContainsString( 'opaque-action-ticket', wp_json_encode( $data ) ?: '' );
		$this->assertStringNotContainsString( 'opaque-action-ticket', wp_json_encode( get_option( SuperdavSiteConnectionService::TOKEN_METADATA_OPTION ) ) ?: '' );
		$this->assertStringNotContainsString( 'must-not-be-exposed', wp_json_encode( $data ) ?: '' );
	}

	/** Superdav account activity remains restricted to site administrators. */
	public function test_superdav_account_permission_requires_manage_options(): void {
		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator_id );
		$this->assertTrue( SettingsController::check_admin_permission() );

		wp_set_current_user( 0 );
		$this->assertFalse( SettingsController::check_admin_permission() );
	}

	/** Account actions are minted on demand without exposing or persisting the site token. */
	public function test_handle_superdav_account_action_returns_fresh_safe_url(): void {
		$base_url   = 'https://service.example/v1';
		$action_url = $base_url . '/site/account/action';
		$token      = 'sdaist_fresh_account_action_token';

		add_filter( 'sd_ai_agent_cloud_base_url', static fn(): string => $base_url );
		add_filter(
			'pre_http_request',
			static function ( mixed $preempt, array $parsed_args, string $url ) use ( $action_url, $token ): mixed {
				if ( $action_url !== $url ) {
					return $preempt;
				}

				self::assertSame( 'Bearer ' . $token, self::authorization_header_from_args( $parsed_args ) );
				self::assertSame( 0, $parsed_args['redirection'] ?? null );
				self::assertSame(
					'account_portal',
					json_decode( (string) ( $parsed_args['body'] ?? '' ), true )['action'] ?? null
				);

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'action' => 'account_portal',
							'url'    => 'https://account.example/action?sdai_action_ticket=opaque-ticket',
						)
					),
				);
			},
			10,
			3
		);

		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, $token, false );
		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/superdav-account/action' );
		$request->set_param( 'action', 'account_portal' );
		$response = ( new SettingsController( new Settings(), new Database() ) )->handle_superdav_account_action( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'https://account.example/action?sdai_action_ticket=opaque-ticket', $response->get_data()['url'] );
		$this->assertStringNotContainsString( $token, wp_json_encode( $response->get_data() ) ?: '' );
	}

	/** Account actions never send their bearer token to unsafe filtered endpoints. */
	public function test_handle_superdav_account_action_rejects_unsafe_endpoint_overrides(): void {
		$endpoints = array(
			'http://service.example/v1/site/account/action',
			'https://action-user:action-password@service.example/v1/site/account/action',
			'https://service.example/v1/site/account/action?access_token=must-not-be-sent',
		);

		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, 'sdaist_action_endpoint_token', false );
		add_filter(
			'pre_http_request',
			static function (): void {
				self::fail( 'Unsafe account action endpoint must not receive an HTTP request.' );
			},
			10,
			0
		);

		foreach ( $endpoints as $endpoint ) {
			add_filter( 'sd_ai_agent_cloud_account_action_endpoint', static fn(): string => $endpoint );
			$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/superdav-account/action' );
			$request->set_param( 'action', 'account_portal' );
			$response = ( new SettingsController( new Settings(), new Database() ) )->handle_superdav_account_action( $request );

			$this->assertInstanceOf( \WP_Error::class, $response );
			$this->assertSame( 'sd_ai_agent_account_action_unavailable', $response->get_error_code() );
			remove_all_filters( 'sd_ai_agent_cloud_account_action_endpoint' );
		}
	}

	/** Coupon redemption uses the scoped site bearer token and returns only safe refreshed metadata. */
	public function test_handle_redeem_superdav_coupon_returns_safe_refreshed_wallet(): void {
		$baseUrl    = 'https://service.example/v1';
		$redeemUrl  = 'https://service.example/custom/site/redeem-coupon';
		$token      = 'sdaist_coupon_redemption_token';
		$couponCode = 'test-coupon-code';

		add_filter( 'sd_ai_agent_cloud_base_url', static fn(): string => $baseUrl );
		add_filter( 'sd_ai_agent_cloud_account_coupon_redemption_endpoint', static fn(): string => $redeemUrl );
		add_filter(
			'pre_http_request',
			static function ( mixed $preempt, array $parsed_args, string $url ) use ( $redeemUrl, $token, $couponCode ): mixed {
				if ( $redeemUrl !== $url ) {
					return $preempt;
				}

				$body = (string) ( $parsed_args['body'] ?? '' );
				self::assertSame( 'Bearer ' . $token, self::authorization_header_from_args( $parsed_args ) );
				self::assertSame( 0, $parsed_args['redirection'] ?? null );
				self::assertSame( $couponCode, json_decode( $body, true )['coupon_code'] ?? '' );
				self::assertArrayNotHasKey( 'X-Superdav-Timestamp', $parsed_args['headers'] );
				self::assertArrayNotHasKey( 'X-Superdav-Signature', $parsed_args['headers'] );

				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'body'     => wp_json_encode(
						array(
							'wallet' => array(
								'currency'         => 'USD',
								'promo_usd_micros' => 5000000,
								'cash_usd_micros'  => 1000000,
								'total_usd_micros' => 6000000,
								'coupon_code'      => $couponCode,
							),
							'refreshed_at' => '2026-07-26T00:00:00Z',
							'request_id'   => 'safe-request-id',
							'raw_payload'  => 'must-not-be-exposed',
						)
					),
				);
			},
			10,
			3
		);

		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, $token, false );
		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/superdav-account/redeem-coupon' );
		$request->set_param( 'coupon_code', $couponCode );
		$response = ( new SettingsController( new Settings(), new Database() ) )->handle_redeem_superdav_coupon( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 6000000, $data['wallet']['total_usd_micros'] );
		$this->assertSame( '2026-07-26T00:00:00+00:00', $data['refreshed_at'] );
		$this->assertStringNotContainsString( $token, wp_json_encode( $data ) ?: '' );
		$this->assertStringNotContainsString( $couponCode, wp_json_encode( $data ) ?: '' );
		$this->assertStringNotContainsString( 'must-not-be-exposed', wp_json_encode( $data ) ?: '' );
		$this->assertStringNotContainsString( $couponCode, wp_json_encode( get_option( SuperdavSiteConnectionService::TOKEN_METADATA_OPTION, array() ) ) ?: '' );
	}

	/** Redemption preserves documented coupon errors and safely collapses unknown failures. */
	public function test_handle_redeem_superdav_coupon_handles_service_errors_without_disclosure(): void {
		$baseUrl   = 'https://service.example/v1';
		$redeemUrl = $baseUrl . '/site/account/redeem-coupon';
		$responses  = array(
			array( 'code' => 302, 'error' => 'redirect', 'expected' => 'sd_ai_agent_coupon_redemption_unavailable' ),
			array( 'code' => 404, 'error' => 'coupon_invalid', 'expected' => 'sd_ai_agent_coupon_invalid' ),
			array( 'code' => 410, 'error' => 'coupon_expired', 'expected' => 'sd_ai_agent_coupon_expired' ),
			array( 'code' => 410, 'error' => 'coupon_revoked', 'expected' => 'sd_ai_agent_coupon_revoked' ),
			array( 'code' => 403, 'error' => 'coupon_not_eligible', 'expected' => 'sd_ai_agent_coupon_not_eligible' ),
			array( 'code' => 503, 'error' => 'upstream-internal-code', 'expected' => 'sd_ai_agent_coupon_redemption_unavailable' ),
		);
		$response_index = 0;

		add_filter( 'sd_ai_agent_cloud_base_url', static fn(): string => $baseUrl );
		add_filter(
			'pre_http_request',
			static function ( mixed $preempt, array $parsed_args, string $url ) use ( $redeemUrl, $responses, &$response_index ): mixed {
				if ( $redeemUrl !== $url ) {
					return $preempt;
				}

				$current = $responses[ $response_index++ ];

				return array(
					'response' => array( 'code' => $current['code'], 'message' => 'Error' ),
					'body'     => wp_json_encode( array( 'error' => array( 'code' => $current['error'], 'message' => 'must-not-be-exposed' ) ) ),
				);
			},
			10,
			3
		);

		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, 'sdaist_coupon_error_token', false );
		$controller = new SettingsController( new Settings(), new Database() );
		foreach ( $responses as $expected ) {
			$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/superdav-account/redeem-coupon' );
			$request->set_param( 'coupon_code', 'test-coupon-code' );
			$response = $controller->handle_redeem_superdav_coupon( $request );

			$this->assertInstanceOf( \WP_Error::class, $response );
			$this->assertSame( $expected['expected'], $response->get_error_code() );
			$this->assertStringNotContainsString( 'must-not-be-exposed', $response->get_error_message() );
		}
	}

	/** Redemption rejects absent input locally without sending a service request. */
	public function test_handle_redeem_superdav_coupon_requires_a_code(): void {
		$response = ( new SettingsController( new Settings(), new Database() ) )->handle_redeem_superdav_coupon( new WP_REST_Request( 'POST', '/sd-ai-agent/v1/superdav-account/redeem-coupon' ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'sd_ai_agent_coupon_code_required', $response->get_error_code() );
	}

	/** Coupon redemption never sends its bearer token to unsafe filtered endpoints. */
	public function test_handle_redeem_superdav_coupon_rejects_unsafe_endpoint_overrides(): void {
		$endpoints = array(
			'http://service.example/site/account/redeem-coupon',
			'https://coupon-user:coupon-password@service.example/site/account/redeem-coupon',
			'https://service.example/site/account/redeem-coupon?access_token=must-not-be-sent',
		);

		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, 'sdaist_coupon_endpoint_token', false );
		add_filter(
			'pre_http_request',
			static function (): void {
				self::fail( 'Unsafe coupon redemption endpoint must not receive an HTTP request.' );
			},
			10,
			0
		);

		foreach ( $endpoints as $endpoint ) {
			add_filter( 'sd_ai_agent_cloud_account_coupon_redemption_endpoint', static fn(): string => $endpoint );
			$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/superdav-account/redeem-coupon' );
			$request->set_param( 'coupon_code', 'test-coupon-code' );
			$response = ( new SettingsController( new Settings(), new Database() ) )->handle_redeem_superdav_coupon( $request );

			$this->assertInstanceOf( \WP_Error::class, $response );
			$this->assertSame( 'sd_ai_agent_coupon_redemption_unavailable', $response->get_error_code() );
			remove_all_filters( 'sd_ai_agent_cloud_account_coupon_redemption_endpoint' );
		}
	}

	/** A consumed coupon reports a non-retryable refresh error when metadata cannot persist. */
	public function test_handle_redeem_superdav_coupon_reports_metadata_persistence_failure(): void {
		$redeemUrl = 'https://service.example/site/account/redeem-coupon';
		$metadata  = array( 'connected_at' => '2026-07-16T00:00:00+00:00' );

		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, 'sdaist_coupon_persistence_token', false );
		update_option( SuperdavSiteConnectionService::TOKEN_METADATA_OPTION, $metadata, false );
		add_filter( 'sd_ai_agent_cloud_account_coupon_redemption_endpoint', static fn(): string => $redeemUrl );
		add_filter(
			'pre_http_request',
			static function ( mixed $preempt, array $parsed_args, string $url ) use ( $redeemUrl ): mixed {
				if ( $redeemUrl !== $url ) {
					return $preempt;
				}

				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'body'     => wp_json_encode( array( 'wallet' => array( 'total_usd_micros' => 6000000 ) ) ),
				);
			},
			10,
			3
		);
		add_filter(
			'pre_update_option_' . SuperdavSiteConnectionService::TOKEN_METADATA_OPTION,
			static fn( mixed $value, mixed $old_value ): mixed => $old_value,
			10,
			2
		);

		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/superdav-account/redeem-coupon' );
		$request->set_param( 'coupon_code', 'test-coupon-code' );
		$response = ( new SettingsController( new Settings(), new Database() ) )->handle_redeem_superdav_coupon( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'sd_ai_agent_coupon_redemption_persistence_failed', $response->get_error_code() );
		$this->assertSame( $metadata, get_option( SuperdavSiteConnectionService::TOKEN_METADATA_OPTION, array() ) );
	}

	/** Account action URLs containing centrally blocked query keys are not exposed. */
	public function test_handle_refresh_superdav_account_rejects_secret_action_queries(): void {
		$base_url    = 'https://service.example/v1';
		$account_url = $base_url . '/site/account';

		add_filter( 'sd_ai_agent_cloud_base_url', static fn(): string => $base_url );
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
							'account_portal_url'   => 'https://account.example/billing?access_token=must-not-be-exposed',
							'purchase_credits_url' => 'https://account.example/credits?access_token=must-not-be-exposed',
							'payment_methods_url'  => 'https://account.example/payment-methods?api_key=must-not-be-exposed',
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
		$this->assertSame( '', $data['purchase_credits_url'] );
		$this->assertSame( '', $data['payment_methods_url'] );
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
										'name'              => 'Speedy',
										'context_length'    => 128000,
										'max_output_length' => 8192,
										'capabilities'      => array( 'text_generation' ),
									),
									array(
										'id'                => 'superdav-chat-pro',
										'name'              => 'Standard',
										'context_length'    => 200000,
										'max_output_length' => 16384,
										'capabilities'      => array( 'text_generation' ),
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
	 * /providers retries one retryable managed Superdav model discovery failure.
	 */
	public function test_handle_providers_retries_retryable_managed_model_discovery_failure(): void {
		if ( ! class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'WordPress AI Client SDK is unavailable.' );
		}

		$base_url   = 'https://service.example/v1';
		$models_url = $base_url . '/models';
		$model_hits = 0;

		add_filter( 'sd_ai_agent_cloud_base_url', static fn(): string => $base_url );
		add_filter(
			'pre_http_request',
			static function ( mixed $preempt, array $parsed_args, string $url ) use ( $models_url, &$model_hits ): mixed {
				if ( $models_url !== $url ) {
					return $preempt;
				}

				++$model_hits;
				if ( 1 === $model_hits ) {
					return array(
						'response' => array(
							'code'    => 503,
							'message' => 'Service Unavailable',
						),
						'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Temporary upstream outage.' ) ) ),
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
									'id'           => 'superdav-chat-fast',
									'capabilities' => array( 'text_generation' ),
								),
								array(
									'id'           => 'superdav-chat-pro',
									'capabilities' => array( 'text_generation' ),
								),
								array(
									'id'           => 'superdav-chat-strong',
									'capabilities' => array( 'text_generation' ),
								),
								array(
									'id'           => 'superdav-image',
									'capabilities' => array( 'image_generation' ),
								),
							),
						)
					),
				);
			},
			10,
			3
		);

		$token = 'sdaist_retryable_discovery_token';
		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, $token, false );
		( new SuperdavAiProviderHandler() )->register_provider();

		$response  = ( new SettingsController( new Settings(), new Database() ) )->handle_providers();
		$providers = $response->get_data();
		$superdav  = $this->find_provider( is_array( $providers ) ? $providers : array(), SuperdavAiProvider::PROVIDER_ID );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $model_hits );
		$this->assertSame( $token, get_option( SuperdavAiProvider::CREDENTIAL_OPTION, '' ) );
		$this->assertNotNull( $superdav );
		$this->assertSame( array( 'superdav-chat-fast', 'superdav-chat-pro', 'superdav-chat-strong' ), wp_list_pluck( $superdav['models'] ?? array(), 'id' ) );
		$this->assertArrayNotHasKey( 'model_discovery', $superdav );
	}

	/**
	 * /providers exposes scrubbed discovery metadata without rotating credentials for a client failure.
	 */
	public function test_handle_providers_reports_scrubbed_nonretryable_managed_model_discovery_failure(): void {
		if ( ! class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'WordPress AI Client SDK is unavailable.' );
		}

		$base_url   = 'https://service.example/v1';
		$models_url = $base_url . '/models';
		$model_hits = 0;
		$token      = 'sdaist_nonretryable_discovery_token';

		add_filter( 'sd_ai_agent_cloud_base_url', static fn(): string => $base_url );
		add_filter(
			'pre_http_request',
			static function ( mixed $preempt, array $parsed_args, string $url ) use ( $models_url, &$model_hits, $token ): mixed {
				if ( $models_url !== $url ) {
					return $preempt;
				}

				++$model_hits;
				return array(
					'response' => array(
						'code'    => 400,
						'message' => 'Bad Request',
					),
					'body'     => wp_json_encode(
						array( 'error' => array( 'message' => "Invalid request with {$token}." ) )
					),
				);
			},
			10,
			3
		);

		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, $token, false );
		( new SuperdavAiProviderHandler() )->register_provider();

		$response  = ( new SettingsController( new Settings(), new Database() ) )->handle_providers();
		$providers = $response->get_data();
		$superdav  = $this->find_provider( is_array( $providers ) ? $providers : array(), SuperdavAiProvider::PROVIDER_ID );
		$encoded   = wp_json_encode( $superdav );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $model_hits );
		$this->assertSame( $token, get_option( SuperdavAiProvider::CREDENTIAL_OPTION, '' ) );
		$this->assertNotNull( $superdav );
		$this->assertSame( array(), $superdav['models'] ?? null );
		$this->assertSame( 'unavailable', $superdav['model_discovery']['state'] ?? '' );
		$this->assertSame( 'model_discovery_client', $superdav['model_discovery']['code'] ?? '' );
		$this->assertFalse( $superdav['model_discovery']['retryable'] ?? true );
		$this->assertSame( 400, $superdav['model_discovery']['status'] ?? 0 );
		$this->assertSame( 1, $superdav['model_discovery']['attempts'] ?? 0 );
		$this->assertIsString( $encoded );
		$this->assertStringNotContainsString( $token, $encoded );
		$this->assertStringNotContainsString( 'Invalid request with', $encoded );
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

	/** Messaging provider settings expose metadata but never credentials. */
	public function test_messaging_provider_settings_return_safe_metadata(): void {
		$settings   = new Settings();
		$controller = new SettingsController( $settings, new Database() );

		$whatsapp_response = $controller->handle_set_whatsapp_provider(
			$this->json_request(
				[
					'access_token'    => 'meta-secret-token',
					'phone_number_id' => '1234567890',
					'api_version'     => MessagingAbilities::WHATSAPP_API_VERSION,
				]
			)
		);
		$telegram_response = $controller->handle_set_telegram_provider(
			$this->json_request( [ 'bot_token' => '123:telegram-secret' ] )
		);

		$this->assertSame( 200, $whatsapp_response->get_status() );
		$this->assertSame( 200, $telegram_response->get_status() );
		$encoded = wp_json_encode( [ $whatsapp_response->get_data(), $telegram_response->get_data() ] ) ?: '';
		$this->assertStringNotContainsString( 'meta-secret-token', $encoded );
		$this->assertStringNotContainsString( 'telegram-secret', $encoded );
		$this->assertSame( '********7890', $whatsapp_response->get_data()['phone_number_id_redacted'] ?? '' );
		$this->assertTrue( $telegram_response->get_data()['has_bot_token'] ?? false );

		$whatsapp_update = $controller->handle_set_whatsapp_provider(
			$this->json_request(
				[
					'access_token'    => '',
					'phone_number_id' => '',
					'api_version'     => MessagingAbilities::WHATSAPP_API_VERSION,
				]
			)
		);
		$this->assertSame( 200, $whatsapp_update->get_status() );
		$this->assertSame( '1234567890', $settings->get_whatsapp_provider()['phone_number_id'] ?? '' );

		$controller->handle_delete_whatsapp_provider();
		$controller->handle_delete_telegram_provider();
		$this->assertFalse( $settings->has_whatsapp_provider() );
		$this->assertFalse( $settings->has_telegram_provider() );
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
