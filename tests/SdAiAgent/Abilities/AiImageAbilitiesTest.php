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
use WP_UnitTestCase;

/**
 * Test AiImageAbilities handler methods.
 */
class AiImageAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Return a tiny valid PNG provider-file mock.
	 *
	 * @return object
	 */
	private function provider_image_mock(): object {
		return new class() {
			public function isRemote(): bool {
				return false;
			}

			public function getBase64Data(): string {
				return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=';
			}

			public function getMimeType(): string {
				return 'image/png';
			}

			public function getModel(): string {
				return 'test-image-model';
			}

			/** @return array<string, mixed> */
			public function getSafetyFlags(): array {
				return [ 'blocked' => false ];
			}
		};
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
	 * Generated image variations are imported as media-library attachments with provenance.
	 */
	public function test_handle_generate_imports_variations_with_provenance_meta(): void {
		$prompts = [];
		$options = [];
		$factory = function ( $unused, string $prompt, array $provider_options ) use ( &$prompts, &$options ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
			$prompts[] = $prompt;
			$options[] = $provider_options;

			return $this->provider_image_mock();
		};

		add_filter( 'sd_ai_agent_generate_image_result', $factory, 10, 3 );

		try {
			$result = AiImageAbilities::handle_generate( [
				'prompt'                   => 'Brand-specific geometric hero artwork for a robotics startup.',
				'title'                    => 'Robotics Hero',
				'size'                     => '1024x1024',
				'style'                    => 'vivid',
				'variations'               => 2,
				'reference_attachment_ids' => [ 123, '456', 0 ],
			] );
		} finally {
			remove_filter( 'sd_ai_agent_generate_image_result', $factory, 10 );
		}

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'attachments', $result );
		$this->assertCount( 2, $result['attachments'] );
		$this->assertSame( 2, count( $prompts ) );
		$this->assertSame( '1024x1024', $options[0]['size'] );
		$this->assertSame( 'vivid', $options[0]['style'] );
		$this->assertSame( [ 123, 456 ], $options[0]['reference_attachment_ids'] );

		$attachment_id = (int) $result['attachments'][0]['attachment_id'];
		$this->assertGreaterThan( 0, $attachment_id );
		$this->assertSame( 1, (int) $result['attachments'][0]['width'] );
		$this->assertSame( 1, (int) $result['attachments'][0]['height'] );

		$provenance = get_post_meta( $attachment_id, '_sd_ai_agent_image_provenance', true );
		$this->assertIsArray( $provenance );
		$this->assertSame( 'wp-ai-client', $provenance['provider'] );
		$this->assertSame( 'test-image-model', $provenance['model'] );
		$this->assertSame( 'Brand-specific geometric hero artwork for a robotics startup.', $provenance['prompt'] );
		$this->assertSame( 1, (int) $provenance['width'] );
		$this->assertSame( 1, (int) $provenance['height'] );
		$this->assertSame( 'image/png', $provenance['mime'] );
		$this->assertSame( [ 'blocked' => false ], $provenance['safety_flags'] );
	}

	/**
	 * Unsupported or provider-specific options are passed opportunistically without validation errors.
	 */
	public function test_handle_generate_unsupported_options_fall_back_without_validation_error(): void {
		$factory = function () {
			return $this->provider_image_mock();
		};

		add_filter( 'sd_ai_agent_generate_image_result', $factory, 10, 3 );

		try {
			$result = AiImageAbilities::handle_generate( [
				'prompt'     => 'A soft background motif.',
				'size'       => 'not-provider-specific',
				'style'      => 'not-provider-specific',
				'variations' => 1,
			] );
		} finally {
			remove_filter( 'sd_ai_agent_generate_image_result', $factory, 10 );
		}

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertGreaterThan( 0, (int) $result['attachment_id'] );
	}

}
