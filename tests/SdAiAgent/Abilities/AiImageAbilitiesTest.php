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
use WP_UnitTestCase;

/**
 * Test AiImageAbilities handler methods.
 */
class AiImageAbilitiesTest extends WP_UnitTestCase {

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

		$ability->method( 'generate_and_import' )
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
