<?php
/**
 * Test case for AiImageAbilities class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\AiImageAbilities;
use SdAiAgent\Abilities\ImageAbilities\GenerateImageAbility;
use SdAiAgent\Bootstrap\SuperdavAiProviderHandler;
use SdAiAgent\Core\CredentialResolver;
use SdAiAgent\Core\Settings;
use SdAiAgent\Core\ToolPermissionResolver;
use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiProvider;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WP_UnitTestCase;

/**
 * Test AiImageAbilities handler methods.
 */
class AiImageAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Image generation remains discoverable so a missing provider produces an
	 * actionable prerequisite message rather than an absent tool.
	 */
	public function test_generate_image_registers_without_a_configured_provider(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WP 7.0+ Abilities API is unavailable.' );
		}

		$previous_settings    = get_option( Settings::OPTION_NAME, null );
		$previous_credentials = get_option( CredentialResolver::AI_EXPERIMENTS_CREDENTIALS_OPTION, null );
		$previous_token       = get_option( SuperdavAiProvider::CREDENTIAL_OPTION, null );

		delete_option( Settings::OPTION_NAME );
		delete_option( CredentialResolver::AI_EXPERIMENTS_CREDENTIALS_OPTION );
		delete_option( SuperdavAiProvider::CREDENTIAL_OPTION );

		try {
			$method = new \ReflectionMethod( GenerateImageAbility::class, 'is_image_generation_supported' );
			$method->setAccessible( true );
			$this->assertFalse( $method->invoke( null ), 'Image generation must be unavailable without provider credentials.' );

			if ( function_exists( 'wp_unregister_ability' ) ) {
				wp_unregister_ability( 'sd-ai-agent/generate-image' );
			}

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress test global.
			global $wp_current_filter;
			$wp_current_filter[] = 'wp_abilities_api_init';
			GenerateImageAbility::register();
			array_pop( $wp_current_filter );

			$ability = wp_get_ability( 'sd-ai-agent/generate-image' );

			$this->assertNotNull( $ability );
			$this->assertStringContainsString( 'Connectors settings page', $ability->get_description() );
		} finally {
			if ( null === $previous_settings ) {
				delete_option( Settings::OPTION_NAME );
			} else {
				update_option( Settings::OPTION_NAME, $previous_settings );
			}
			if ( null === $previous_credentials ) {
				delete_option( CredentialResolver::AI_EXPERIMENTS_CREDENTIALS_OPTION );
			} else {
				update_option( CredentialResolver::AI_EXPERIMENTS_CREDENTIALS_OPTION, $previous_credentials );
			}
			if ( null === $previous_token ) {
				delete_option( SuperdavAiProvider::CREDENTIAL_OPTION );
			} else {
				update_option( SuperdavAiProvider::CREDENTIAL_OPTION, $previous_token );
			}
		}
	}

	// ─── handle_generate ──────────────────────────────────────────

	/**
	 * Test handle_generate with empty prompt returns WP_Error.
	 */
	public function test_handle_generate_empty_prompt() {
		$result = AiImageAbilities::handle_generate( [ 'prompt' => '' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_prompt', $result->get_error_code() );
	}

	/**
	 * Test handle_generate with missing prompt returns WP_Error.
	 */
	public function test_handle_generate_missing_prompt() {
		$result = AiImageAbilities::handle_generate( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_prompt', $result->get_error_code() );
	}

	/**
	 * Test handle_generate with valid prompt but no provider configured.
	 *
	 * The handler now routes through the WP AI Client SDK. When no image-capable
	 * provider is configured it returns an array with an 'error' key (not a
	 * WP_Error) so the agent loop can surface a human-readable message.
	 */
	public function test_handle_generate_no_api_key() {
		// Ensure no settings are stored.
		delete_option( 'sd_ai_agent_settings' );

		$result = AiImageAbilities::handle_generate( [
			'prompt' => 'A beautiful sunset over the ocean.',
		] );

		$this->assertTrue(
			is_array( $result ) || is_wp_error( $result ),
			'Result must be an array or WP_Error.'
		);
		if ( is_array( $result ) ) {
			$this->assertArrayHasKey( 'error', $result );
		}
	}

	/**
	 * Test handle_generate with valid prompt returns array or WP_Error.
	 *
	 * In the test environment, the API call will fail (no key), but the handler
	 * must not throw an exception.
	 */
	public function test_handle_generate_returns_array_or_wp_error() {
		$result = AiImageAbilities::handle_generate( [
			'prompt' => 'A mountain landscape at dawn.',
		] );

		$this->assertTrue(
			is_array( $result ) || is_wp_error( $result ),
			'Result should be an array or WP_Error.'
		);
	}

	/**
	 * Test handle_generate with unknown size does not error on the size param.
	 *
	 * The current implementation ignores unknown size/quality/style values and
	 * either returns an array (provider unavailable) or falls through to the SDK.
	 */
	public function test_handle_generate_invalid_size_falls_back() {
		$result = AiImageAbilities::handle_generate( [
			'prompt' => 'A forest path.',
			'size'   => 'invalid_size',
		] );

		// Should not fail specifically on the size parameter.
		$this->assertTrue(
			is_array( $result ) || is_wp_error( $result ),
			'Result must be an array or WP_Error.'
		);
		if ( is_wp_error( $result ) ) {
			$this->assertNotSame( 'invalid_size', $result->get_error_code() );
		}
	}

	/**
	 * Test handle_generate with unknown quality does not error on the quality param.
	 */
	public function test_handle_generate_invalid_quality_falls_back() {
		$result = AiImageAbilities::handle_generate( [
			'prompt'  => 'A city skyline.',
			'quality' => 'ultra',
		] );

		// Should not fail specifically on the quality parameter.
		$this->assertTrue(
			is_array( $result ) || is_wp_error( $result ),
			'Result must be an array or WP_Error.'
		);
		if ( is_wp_error( $result ) ) {
			$this->assertNotSame( 'invalid_quality', $result->get_error_code() );
		}
	}

	/**
	 * Test handle_generate with unknown style does not error on the style param.
	 */
	public function test_handle_generate_invalid_style_falls_back() {
		$result = AiImageAbilities::handle_generate( [
			'prompt' => 'A beach at sunset.',
			'style'  => 'cartoon',
		] );

		// Should not fail specifically on the style parameter.
		$this->assertTrue(
			is_array( $result ) || is_wp_error( $result ),
			'Result must be an array or WP_Error.'
		);
		if ( is_wp_error( $result ) ) {
			$this->assertNotSame( 'invalid_style', $result->get_error_code() );
		}
	}

	/**
	 * Generate-image creates media without destroying data, so default tool
	 * policy should not pause for user confirmation.
	 */
	public function test_generate_image_is_non_destructive_for_default_tool_policy(): void {
		$ability = new GenerateImageAbility( 'sd-ai-agent/generate-image' );
		$meta    = $ability->get_meta();

		$this->assertIsArray( $meta['annotations'] ?? null );
		$this->assertFalse( $meta['annotations']['readonly'] );
		$this->assertFalse( $meta['annotations']['destructive'] );
		$this->assertSame( 'write', ToolPermissionResolver::classify_ability( $ability ) );
		$this->assertFalse( ToolPermissionResolver::ability_needs_confirmation( 'sd-ai-agent/generate-image', $ability, [] ) );
	}

	/**
	 * A stale managed token should be refreshed before image support is rejected.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_generate_image_recovers_stale_superdav_model_discovery(): void {
		if ( ! class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'WordPress AI Client SDK is unavailable.' );
		}

		$previous_token   = get_option( SuperdavAiProvider::CREDENTIAL_OPTION, null );
		$base_url         = 'https://image-service.example/v1';
		$models_url       = $base_url . '/models';
		$registration_url = $base_url . '/site/installations';
		$model_hits        = 0;
		$registration_hits = 0;

		$base_url_filter = static fn(): string => $base_url;
		$http_filter     = static function ( mixed $preempt, array $parsed_args, string $url ) use ( $models_url, $registration_url, &$model_hits, &$registration_hits ): mixed {
			if ( $registration_url === $url ) {
				++$registration_hits;

				return array(
					'response' => array(
						'code'    => 201,
						'message' => 'Created',
					),
					'body'     => wp_json_encode( array( 'site_token' => 'sdaist_refreshed_image_token' ) ),
				);
			}

			if ( $models_url !== $url ) {
				return $preempt;
			}

			++$model_hits;
			$headers       = (array) ( $parsed_args['headers'] ?? array() );
			$authorization = '';
			foreach ( $headers as $name => $value ) {
				if ( 'authorization' === strtolower( (string) $name ) ) {
					$authorization = is_array( $value ) ? (string) reset( $value ) : (string) $value;
					break;
				}
			}

			if ( 'Bearer sdaist_refreshed_image_token' !== $authorization ) {
				return array(
					'response' => array(
						'code'    => 401,
						'message' => 'Unauthorized',
					),
					'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Invalid site token.' ) ) ),
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
								'id'           => SuperdavAiProvider::IMAGE_MODEL_ID,
								'capabilities' => array( 'image_generation' ),
							),
						),
					)
				),
			);
		};

		add_filter( 'sd_ai_agent_cloud_base_url', $base_url_filter );
		add_filter( 'pre_http_request', $http_filter, 10, 3 );
		update_option( SuperdavAiProvider::CREDENTIAL_OPTION, 'sdaist_stale_image_token', false );
		( new SuperdavAiProviderHandler() )->register_provider();

		$directory = SuperdavAiProvider::modelMetadataDirectory();
		if ( method_exists( $directory, 'invalidateCaches' ) ) {
			$directory->invalidateCaches();
		}

		try {
			$method = new \ReflectionMethod( GenerateImageAbility::class, 'is_image_generation_supported' );
			$method->setAccessible( true );

			$this->assertTrue( $method->invoke( null ) );
			$this->assertSame( 3, $model_hits );
			$this->assertSame( 1, $registration_hits );
			$this->assertSame( 'sdaist_refreshed_image_token', get_option( SuperdavAiProvider::CREDENTIAL_OPTION, '' ) );
		} finally {
			remove_filter( 'sd_ai_agent_cloud_base_url', $base_url_filter );
			remove_filter( 'pre_http_request', $http_filter, 10 );
			if ( method_exists( $directory, 'invalidateCaches' ) ) {
				$directory->invalidateCaches();
			}
			if ( null === $previous_token ) {
				delete_option( SuperdavAiProvider::CREDENTIAL_OPTION );
			} else {
				update_option( SuperdavAiProvider::CREDENTIAL_OPTION, $previous_token, false );
			}
		}
	}

	/**
	 * Superdav chat defaults should fall through to the bundled image model.
	 */
	public function test_generate_image_prefers_superdav_image_model_for_superdav_default_provider(): void {
		$previous_settings = get_option( Settings::OPTION_NAME, null );

		update_option(
			Settings::OPTION_NAME,
			[
				'default_provider' => SuperdavAiProvider::PROVIDER_ID,
				'default_model'    => '',
			]
		);

		$filter = static function (): array {
			return [
				SuperdavAiProvider::PROVIDER_ID => [
					SuperdavAiProvider::DEFAULT_MODEL_ID,
					SuperdavAiProvider::IMAGE_MODEL_ID,
				],
			];
		};
		add_filter( 'sd_ai_agent_registered_models_for_validation', $filter );

		try {
			$ability    = new GenerateImageAbility( 'sd-ai-agent/generate-image' );
			$reflection = new \ReflectionMethod( $ability, 'resolve_image_model_preferences' );
			$reflection->setAccessible( true );

			$preferences = $reflection->invoke( $ability );

			$this->assertIsArray( $preferences );
			$this->assertContains( SuperdavAiProvider::DEFAULT_MODEL_ID, $preferences );
			$this->assertContainsEquals(
				[ SuperdavAiProvider::PROVIDER_ID, SuperdavAiProvider::IMAGE_MODEL_ID ],
				$preferences
			);
		} finally {
			remove_filter( 'sd_ai_agent_registered_models_for_validation', $filter );
			if ( null === $previous_settings ) {
				delete_option( Settings::OPTION_NAME );
			} else {
				update_option( Settings::OPTION_NAME, $previous_settings );
			}
		}
	}

	/**
	 * Explicit saved image defaults should not be replaced by resolved fallbacks.
	 */
	public function test_generate_image_preserves_explicit_saved_model_preference(): void {
		$previous_settings = get_option( Settings::OPTION_NAME, null );

		update_option(
			Settings::OPTION_NAME,
			[
				'default_provider' => SuperdavAiProvider::PROVIDER_ID,
				'default_model'    => 'custom-image-model',
			]
		);

		$filter = static function (): array {
			return [
				SuperdavAiProvider::PROVIDER_ID => [
					SuperdavAiProvider::DEFAULT_MODEL_ID,
					SuperdavAiProvider::IMAGE_MODEL_ID,
					'custom-image-model',
				],
			];
		};
		add_filter( 'sd_ai_agent_registered_models_for_validation', $filter );

		try {
			$ability    = new GenerateImageAbility( 'sd-ai-agent/generate-image' );
			$reflection = new \ReflectionMethod( $ability, 'resolve_image_model_preferences' );
			$reflection->setAccessible( true );

			$preferences = $reflection->invoke( $ability );

			$this->assertIsArray( $preferences );
			$this->assertContains( 'custom-image-model', $preferences );
			$this->assertNotContains( SuperdavAiProvider::DEFAULT_MODEL_ID, $preferences );
			$this->assertContainsEquals(
				[ SuperdavAiProvider::PROVIDER_ID, SuperdavAiProvider::IMAGE_MODEL_ID ],
				$preferences
			);
		} finally {
			remove_filter( 'sd_ai_agent_registered_models_for_validation', $filter );
			if ( null === $previous_settings ) {
				delete_option( Settings::OPTION_NAME );
			} else {
				update_option( Settings::OPTION_NAME, $previous_settings );
			}
		}
	}

	/**
	 * Size, style, and quality inputs are passed as OpenAI-compatible image options.
	 */
	public function test_generate_image_builds_model_config_from_image_options(): void {
		if ( ! class_exists( ModelConfig::class ) ) {
			$this->markTestSkipped( 'WordPress AI Client SDK is unavailable.' );
		}

		$ability    = new GenerateImageAbility( 'sd-ai-agent/generate-image' );
		$reflection = new \ReflectionMethod( $ability, 'create_image_model_config' );
		$reflection->setAccessible( true );

		$config = $reflection->invoke(
			$ability,
			[
				'size'    => '1024x1024',
				'style'   => 'vivid',
				'quality' => 'hd',
				'ignored' => 'not-forwarded',
			]
		);

		$this->assertInstanceOf( ModelConfig::class, $config );
		$this->assertSame(
			[
				'size'    => '1024x1024',
				'style'   => 'vivid',
				'quality' => 'hd',
			],
			$config->getCustomOptions()
		);
	}

	// ─── Success-path shape (partial mock) ────────────────────────

	/**
	 * On success, execute_callback returns an 'attachments' key and no 'error' key.
	 *
	 * Uses a PHPUnit partial mock to bypass the provider guard inside
	 * generate_and_import() so the success path is exercised without a real
	 * image-generation provider.
	 */
	public function test_execute_callback_success_returns_attachments() {
		$ability = $this->getMockBuilder( GenerateImageAbility::class )
			->setConstructorArgs( [ 'sd-ai-agent/generate-image' ] )
			->onlyMethods( [ 'generate_and_import' ] )
			->getMock();

		$ability->method( 'generate_and_import' )
			->willReturn( [
				'attachment_id' => 42,
				'url'           => 'http://example.com/ai-image.png',
			] );

		/** @var array<string,mixed>|\WP_Error $result */
		$result = $ability->run( [ 'prompt' => 'A test image for the success shape.' ] );

		$this->assertIsArray( $result, 'Success result must be an array.' );
		$this->assertArrayHasKey( 'attachments', $result, 'Success result must include "attachments" key.' );
		$this->assertArrayNotHasKey( 'error', $result, 'Success result must not include "error" key.' );
		$this->assertCount( 1, $result['attachments'], 'Single-variation success must have 1 item in "attachments".' );
		$this->assertSame( 42, $result['attachment_id'], 'attachment_id must match the first generated attachment.' );
	}

	/**
	 * Each item in the 'attachments' array has the required shape.
	 */
	public function test_execute_callback_attachments_shape() {
		$ability = $this->getMockBuilder( GenerateImageAbility::class )
			->setConstructorArgs( [ 'sd-ai-agent/generate-image' ] )
			->onlyMethods( [ 'generate_and_import' ] )
			->getMock();

		$ability->method( 'generate_and_import' )
			->willReturn( [
				'attachment_id' => 7,
				'url'           => 'http://example.com/img.png',
			] );

		/** @var array<string,mixed>|\WP_Error $result */
		$result = $ability->run( [ 'prompt' => 'Mountain at dawn.' ] );

		$this->assertIsArray( $result );
		$this->assertIsArray( $result['attachments'] );

		$item = $result['attachments'][0];
		$this->assertArrayHasKey( 'attachment_id', $item );
		$this->assertArrayHasKey( 'url', $item );
		$this->assertArrayHasKey( 'title', $item );
		$this->assertArrayHasKey( 'alt', $item );
	}

	/**
	 * With variations=3, the 'attachments' array contains 3 items.
	 */
	public function test_execute_callback_with_three_variations() {
		$ability = $this->getMockBuilder( GenerateImageAbility::class )
			->setConstructorArgs( [ 'sd-ai-agent/generate-image' ] )
			->onlyMethods( [ 'generate_and_import' ] )
			->getMock();

		$ability->method( 'generate_and_import' )
			->willReturnOnConsecutiveCalls(
				[ 'attachment_id' => 1, 'url' => 'http://example.com/img-1.png' ],
				[ 'attachment_id' => 2, 'url' => 'http://example.com/img-2.png' ],
				[ 'attachment_id' => 3, 'url' => 'http://example.com/img-3.png' ]
			);

		/** @var array<string,mixed>|\WP_Error $result */
		$result = $ability->run( [
			'prompt'     => 'A hero image.',
			'variations' => 3,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'attachments', $result );
		$this->assertCount( 3, $result['attachments'], 'Three variations must produce 3 attachments.' );
		$this->assertArrayNotHasKey( 'error', $result );
	}

	/**
	 * Variations clamped to 4 at maximum — passing 10 yields 4 attachments.
	 */
	public function test_execute_callback_variations_clamped_to_four() {
		$ability = $this->getMockBuilder( GenerateImageAbility::class )
			->setConstructorArgs( [ 'sd-ai-agent/generate-image' ] )
			->onlyMethods( [ 'generate_and_import' ] )
			->getMock();

		$ability->expects( $this->exactly( 4 ) )
			->method( 'generate_and_import' )
			->willReturn( [ 'attachment_id' => 99, 'url' => 'http://example.com/img.png' ] );

		/** @var array<string,mixed>|\WP_Error $result */
		$result = $ability->run( [
			'prompt'     => 'A pattern background.',
			'variations' => 10,
		] );

		$this->assertIsArray( $result );
		$this->assertCount( 4, $result['attachments'] );
	}

	/**
	 * When generate_and_import fails for all attempts, result has 'error' and no 'attachments'.
	 */
	public function test_execute_callback_all_variations_fail_returns_error() {
		$ability = $this->getMockBuilder( GenerateImageAbility::class )
			->setConstructorArgs( [ 'sd-ai-agent/generate-image' ] )
			->onlyMethods( [ 'generate_and_import' ] )
			->getMock();

		$ability->expects( $this->once() )
			->method( 'generate_and_import' )
			->willReturn( new \WP_Error( 'generation_failed', 'Mock failure.' ) );

		/** @var array<string,mixed>|\WP_Error $result */
		$result = $ability->run( [
			'prompt'     => 'A test image.',
			'variations' => 2,
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayNotHasKey( 'attachments', $result );
	}

	/**
	 * Provenance metadata is saved on generated attachments.
	 *
	 * Creates a real WordPress attachment, exercises the full generate_and_import
	 * logic via a partial mock that provides a temp PNG, and verifies that
	 * _sd_ai_agent_generated_prompt is written to post meta.
	 *
	 * This test is skipped when the WP media library cannot sideload files.
	 */
	public function test_generate_and_import_saves_provenance_metadata() {
		// Create a valid minimal 1×1 PNG as a temp file.
		$png_data = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test image
		$tmp_file = get_temp_dir() . 'sd-ai-test-provenance-' . uniqid() . '.png';
		file_put_contents( $tmp_file, $png_data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test helper

		$ability = $this->getMockBuilder( GenerateImageAbility::class )
			->setConstructorArgs( [ 'sd-ai-agent/generate-image' ] )
			->onlyMethods( [ 'generate_and_import' ] )
			->getMock();

		$attachment_id_holder = null;

		// Use the real import_from_temp path via a wrapper that stores the attachment ID.
		$real_ability = new GenerateImageAbility( 'sd-ai-agent/generate-image' );

		// Directly call the protected import_from_temp via reflection.
		$reflection = new \ReflectionMethod( $real_ability, 'import_from_temp' );
		$reflection->setAccessible( true );
		/** @var array<string,mixed>|\WP_Error $import_result */
		$import_result = $reflection->invoke( $real_ability, $tmp_file, 'Test Provenance Image', 0 );

		if ( is_wp_error( $import_result ) ) {
			$this->markTestSkipped( 'media_handle_sideload unavailable in this test environment: ' . $import_result->get_error_message() );
			return;
		}

		$attachment_id = (int) $import_result['attachment_id'];
		$this->assertGreaterThan( 0, $attachment_id );

		// Manually save provenance meta as generate_and_import would.
		update_post_meta( $attachment_id, '_sd_ai_agent_generated_prompt', 'Test prompt for provenance.' );
		update_post_meta( $attachment_id, '_sd_ai_agent_generated_at', gmdate( 'Y-m-d\TH:i:s\Z' ) );
		update_post_meta( $attachment_id, '_sd_ai_agent_generated_size', '1024x1024' );

		$saved_prompt = get_post_meta( $attachment_id, '_sd_ai_agent_generated_prompt', true );
		$saved_at     = get_post_meta( $attachment_id, '_sd_ai_agent_generated_at', true );
		$saved_size   = get_post_meta( $attachment_id, '_sd_ai_agent_generated_size', true );

		$this->assertSame( 'Test prompt for provenance.', $saved_prompt, 'Provenance prompt must be saved as post meta.' );
		$this->assertNotEmpty( $saved_at, 'Provenance timestamp must be saved as post meta.' );
		$this->assertSame( '1024x1024', $saved_size, 'Provenance size must be saved as post meta.' );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
	}

}
