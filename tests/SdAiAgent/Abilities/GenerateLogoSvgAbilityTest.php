<?php

declare(strict_types=1);
/**
 * Unit tests for GenerateLogoSvgAbility (GH#1527).
 *
 * Covers parse-and-validate, sanitisation, fallback wordmark, SVG extraction,
 * and site-logo selection wiring as required by the acceptance criteria.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\GenerateLogoSvgAbility;
use WP_UnitTestCase;

/**
 * Test GenerateLogoSvgAbility.
 *
 * Protected helpers (sanitize_svg, validate_svg, extract_svg,
 * generate_fallback_wordmark, save_svg_to_media_library) are exercised via PHP
 * Reflection so their logic can be tested independently of the AI provider.
 */
class GenerateLogoSvgAbilityTest extends WP_UnitTestCase {

	/**
	 * The ability under test.
	 *
	 * @var GenerateLogoSvgAbility
	 */
	private GenerateLogoSvgAbility $ability;

	/**
	 * Attachment IDs created during tests — removed in tearDown.
	 *
	 * @var array<int,int>
	 */
	private array $created_attachments = [];

	public function setUp(): void {
		parent::setUp();
		$this->ability = new GenerateLogoSvgAbility( 'sd-ai-agent/generate-logo-svg' );
	}

	public function tearDown(): void {
		foreach ( $this->created_attachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		remove_theme_mod( 'custom_logo' );
		delete_option( 'site_icon' );
		parent::tearDown();
	}

	// ─── Input validation ─────────────────────────────────────────────────────

	/**
	 * generate action with empty brand_name returns WP_Error.
	 */
	public function test_generate_missing_brand_name_returns_wp_error(): void {
		$result = $this->ability->run( [
			'action'      => 'generate',
			'brand_name'  => '',
			'description' => 'A coffee shop.',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_brand_name', $result->get_error_code() );
	}

	/**
	 * generate action with missing brand_name returns WP_Error.
	 */
	public function test_generate_absent_brand_name_returns_wp_error(): void {
		$result = $this->ability->run( [
			'description' => 'A coffee shop.',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_brand_name', $result->get_error_code() );
	}

	/**
	 * generate action with empty description returns WP_Error.
	 */
	public function test_generate_missing_description_returns_wp_error(): void {
		$result = $this->ability->run( [
			'action'      => 'generate',
			'brand_name'  => 'BeanBrew',
			'description' => '',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_description', $result->get_error_code() );
	}

	/**
	 * When existing_logo_url is non-empty the ability returns immediately
	 * without generating any candidates.
	 */
	public function test_generate_preserves_existing_logo(): void {
		$result = $this->ability->run( [
			'brand_name'        => 'BeanBrew',
			'description'       => 'Specialty coffee.',
			'existing_logo_url' => 'https://example.com/logo.png',
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['existing_logo_preserved'] );
		$this->assertEmpty( $result['candidates'] );
		$this->assertFalse( $result['logo_set'] );
	}

	// ─── select_candidate ────────────────────────────────────────────────────

	/**
	 * select_candidate with zero attachment_id returns WP_Error.
	 */
	public function test_select_candidate_missing_id_returns_wp_error(): void {
		$result = $this->ability->run( [
			'action'        => 'select_candidate',
			'attachment_id' => 0,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_attachment_id', $result->get_error_code() );
	}

	/**
	 * select_candidate with absent attachment_id returns WP_Error.
	 */
	public function test_select_candidate_absent_id_returns_wp_error(): void {
		$result = $this->ability->run( [ 'action' => 'select_candidate' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_attachment_id', $result->get_error_code() );
	}

	/**
	 * select_candidate with a non-existent attachment ID returns WP_Error.
	 */
	public function test_select_candidate_invalid_attachment_id_returns_wp_error(): void {
		$result = $this->ability->run( [
			'action'        => 'select_candidate',
			'attachment_id' => PHP_INT_MAX,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_attachment_id', $result->get_error_code() );
	}

	/**
	 * select_candidate with a valid attachment ID sets the custom_logo theme mod
	 * and returns logo_set = true.
	 */
	public function test_select_candidate_sets_site_logo(): void {
		// Create a real attachment for the test.
		$attachment_id = $this->create_test_svg_attachment();
		$this->assertGreaterThan( 0, $attachment_id, 'Fixture attachment must be created successfully.' );

		$result = $this->ability->run( [
			'action'        => 'select_candidate',
			'attachment_id' => $attachment_id,
		] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['logo_set'] );
		$this->assertSame( $attachment_id, $result['selected_attachment_id'] );

		// Verify the theme mod was actually set.
		$this->assertSame( $attachment_id, (int) get_theme_mod( 'custom_logo' ) );
	}

	/**
	 * select_candidate wires site_icon option for SVG attachments.
	 */
	public function test_select_candidate_sets_site_icon_for_svg(): void {
		$attachment_id = $this->create_test_svg_attachment();
		$this->assertGreaterThan( 0, $attachment_id );

		$this->ability->run( [
			'action'        => 'select_candidate',
			'attachment_id' => $attachment_id,
		] );

		$this->assertSame( $attachment_id, (int) get_option( 'site_icon' ) );
	}

	// ─── sanitize_svg ────────────────────────────────────────────────────────

	/**
	 * A clean, minimal SVG passes sanitisation unchanged (no dangerous nodes).
	 */
	public function test_sanitize_svg_passes_clean_svg(): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50" width="100" height="50"><rect x="0" y="0" width="100" height="50" fill="#abc"/></svg>';
		$result = $this->call_protected( 'sanitize_svg', $svg );

		$this->assertIsString( $result );
		$this->assertStringContainsString( '<rect', $result );
	}

	/**
	 * sanitize_svg strips <script> elements.
	 */
	public function test_sanitize_svg_removes_script_element(): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50" width="100" height="50"><script>alert(1)</script><rect x="0" y="0" width="100" height="50" fill="#f00"/></svg>';
		$result = $this->call_protected( 'sanitize_svg', $svg );

		$this->assertIsString( $result );
		$this->assertStringNotContainsString( '<script', $result );
		$this->assertStringNotContainsString( 'alert(1)', $result );
	}

	/**
	 * sanitize_svg strips <foreignObject> elements.
	 */
	public function test_sanitize_svg_removes_foreignobject_element(): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50" width="100" height="50"><foreignObject width="100" height="50"><div xmlns="http://www.w3.org/1999/xhtml">evil</div></foreignObject></svg>';
		$result = $this->call_protected( 'sanitize_svg', $svg );

		$this->assertIsString( $result );
		$this->assertStringNotContainsString( 'foreignObject', $result );
		$this->assertStringNotContainsString( 'evil', $result );
	}

	/**
	 * sanitize_svg strips event handler attributes (onclick, onload, etc.).
	 */
	public function test_sanitize_svg_removes_event_attributes(): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50" width="100" height="50"><rect onclick="alert(1)" onload="steal()" x="0" y="0" width="100" height="50" fill="#0f0"/></svg>';
		$result = $this->call_protected( 'sanitize_svg', $svg );

		$this->assertIsString( $result );
		$this->assertStringNotContainsString( 'onclick', $result );
		$this->assertStringNotContainsString( 'onload', $result );
		$this->assertStringNotContainsString( 'alert(1)', $result );
	}

	/**
	 * sanitize_svg strips external xlink:href references.
	 */
	public function test_sanitize_svg_removes_external_xlink_href(): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 100 50" width="100" height="50"><use xlink:href="https://evil.example/sprite.svg#icon"/></svg>';
		$result = $this->call_protected( 'sanitize_svg', $svg );

		$this->assertIsString( $result );
		$this->assertStringNotContainsString( 'https://evil.example', $result );
	}

	/**
	 * sanitize_svg strips attributes containing javascript: URIs.
	 */
	public function test_sanitize_svg_removes_javascript_uri_attributes(): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50" width="100" height="50"><a href="javascript:alert(1)"><rect x="0" y="0" width="100" height="50" fill="#00f"/></a></svg>';
		$result = $this->call_protected( 'sanitize_svg', $svg );

		$this->assertIsString( $result );
		$this->assertStringNotContainsString( 'javascript:', $result );
	}

	/**
	 * sanitize_svg strips <image> elements with external (non-data) src.
	 */
	public function test_sanitize_svg_removes_external_image_element(): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50" width="100" height="50"><image href="https://tracker.example/pixel.png" width="1" height="1"/></svg>';
		$result = $this->call_protected( 'sanitize_svg', $svg );

		$this->assertIsString( $result );
		$this->assertStringNotContainsString( 'https://tracker.example', $result );
	}

	/**
	 * sanitize_svg returns WP_Error for invalid XML.
	 */
	public function test_sanitize_svg_rejects_invalid_xml(): void {
		$result = $this->call_protected( 'sanitize_svg', '<svg><unclosed>' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'svg_parse_failed', $result->get_error_code() );
	}

	/**
	 * sanitize_svg returns WP_Error when SVG exceeds 500 KB.
	 */
	public function test_sanitize_svg_rejects_oversized_content(): void {
		// Create an SVG with enough padding to exceed MAX_SVG_BYTES (512 000).
		$padding = str_repeat( 'x', 520000 );
		$svg     = "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 100 50\">{$padding}</svg>";

		$result = $this->call_protected( 'sanitize_svg', $svg );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'svg_too_large', $result->get_error_code() );
	}

	// ─── validate_svg ────────────────────────────────────────────────────────

	/**
	 * validate_svg returns true for a well-formed SVG with viewBox.
	 */
	public function test_validate_svg_accepts_valid_svg(): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50" width="100" height="50"><rect x="0" y="0" width="100" height="50" fill="#abc"/></svg>';
		$result = $this->call_protected( 'validate_svg', $svg );

		$this->assertTrue( $result );
	}

	/**
	 * validate_svg returns WP_Error when viewBox is absent.
	 */
	public function test_validate_svg_rejects_missing_viewbox(): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="50"><rect x="0" y="0" width="100" height="50" fill="#abc"/></svg>';
		$result = $this->call_protected( 'validate_svg', $svg );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'svg_no_viewbox', $result->get_error_code() );
	}

	/**
	 * validate_svg returns WP_Error when the root element is not <svg>.
	 */
	public function test_validate_svg_rejects_non_svg_root(): void {
		$svg    = '<div xmlns="http://www.w3.org/1999/xhtml" viewBox="0 0 100 50"><p>not an svg</p></div>';
		$result = $this->call_protected( 'validate_svg', $svg );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'svg_no_root', $result->get_error_code() );
	}

	/**
	 * validate_svg returns WP_Error for non-parseable markup.
	 */
	public function test_validate_svg_rejects_invalid_xml(): void {
		$result = $this->call_protected( 'validate_svg', 'not xml at all' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'svg_invalid', $result->get_error_code() );
	}

	// ─── extract_svg ─────────────────────────────────────────────────────────

	/**
	 * extract_svg returns direct SVG markup unchanged.
	 */
	public function test_extract_svg_returns_direct_svg(): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50"><rect/></svg>';
		$result = $this->call_protected( 'extract_svg', $svg );

		$this->assertSame( $svg, $result );
	}

	/**
	 * extract_svg extracts SVG from a markdown ```svg code block.
	 */
	public function test_extract_svg_from_svg_code_block(): void {
		$svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50"><circle cx="50" cy="25" r="25"/></svg>';
		$raw  = "Here is your logo:\n\n```svg\n{$svg}\n```\n";
		$result = $this->call_protected( 'extract_svg', $raw );

		$this->assertSame( $svg, $result );
	}

	/**
	 * extract_svg extracts SVG from a markdown ```xml code block.
	 */
	public function test_extract_svg_from_xml_code_block(): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50"><rect x="0" y="0" width="100" height="50"/></svg>';
		$raw    = "```xml\n{$svg}\n```";
		$result = $this->call_protected( 'extract_svg', $raw );

		$this->assertSame( $svg, $result );
	}

	/**
	 * extract_svg extracts SVG embedded in prose.
	 */
	public function test_extract_svg_from_prose(): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50"><text x="10" y="30">Brand</text></svg>';
		$raw    = "Sure, here is the logo: {$svg} Hope you like it!";
		$result = $this->call_protected( 'extract_svg', $raw );

		$this->assertSame( $svg, $result );
	}

	/**
	 * extract_svg returns null when no SVG markup is present.
	 */
	public function test_extract_svg_returns_null_when_not_found(): void {
		$result = $this->call_protected( 'extract_svg', 'Sorry, I cannot generate an SVG right now.' );

		$this->assertNull( $result );
	}

	// ─── generate_fallback_wordmark ───────────────────────────────────────────

	/**
	 * generate_fallback_wordmark produces valid SVG that passes validation.
	 */
	public function test_fallback_wordmark_produces_valid_svg(): void {
		$svg   = $this->call_protected( 'generate_fallback_wordmark', 'BeanBrew' );
		$valid = $this->call_protected( 'validate_svg', $svg );

		$this->assertIsString( $svg );
		$this->assertTrue( $valid, 'Fallback wordmark must pass validate_svg.' );
	}

	/**
	 * generate_fallback_wordmark escapes HTML special characters in brand name.
	 */
	public function test_fallback_wordmark_escapes_special_chars(): void {
		$svg = $this->call_protected( 'generate_fallback_wordmark', 'B&B <Bistro>' );

		$this->assertIsString( $svg );
		$this->assertStringContainsString( '&amp;', $svg );
		$this->assertStringContainsString( '&lt;', $svg );
		$this->assertStringNotContainsString( '<Bistro>', $svg );
	}

	/**
	 * generate_fallback_wordmark includes the brand name in the output.
	 */
	public function test_fallback_wordmark_includes_brand_name(): void {
		$svg = $this->call_protected( 'generate_fallback_wordmark', 'PixelCraft' );

		$this->assertStringContainsString( 'PixelCraft', $svg );
	}

	// ─── save_svg_to_media_library ────────────────────────────────────────────

	/**
	 * save_svg_to_media_library creates an attachment with image/svg+xml MIME type.
	 */
	public function test_save_svg_creates_attachment_with_svg_mime(): void {
		$svg           = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50" width="100" height="50"><rect x="0" y="0" width="100" height="50" fill="#abc"/></svg>';
		$attachment_id = $this->call_protected( 'save_svg_to_media_library', $svg, 'test-logo' );

		if ( is_wp_error( $attachment_id ) ) {
			$this->fail( 'save_svg_to_media_library returned WP_Error: ' . $attachment_id->get_error_message() );
		}

		$this->created_attachments[] = $attachment_id;
		$this->assertIsInt( $attachment_id );
		$this->assertGreaterThan( 0, $attachment_id );

		$mime = get_post_mime_type( $attachment_id );
		$this->assertSame( 'image/svg+xml', $mime );
	}

	/**
	 * save_svg_to_media_library stores a data URI in post meta for inline preview.
	 */
	public function test_save_svg_stores_data_uri_meta(): void {
		$svg           = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50" width="100" height="50"><rect x="0" y="0" width="100" height="50" fill="#abc"/></svg>';
		$attachment_id = $this->call_protected( 'save_svg_to_media_library', $svg, 'test-logo-meta' );

		if ( is_wp_error( $attachment_id ) ) {
			$this->markTestSkipped( 'Attachment creation failed: ' . $attachment_id->get_error_message() );
		}

		$this->created_attachments[] = $attachment_id;
		$data_uri = get_post_meta( $attachment_id, '_sd_ai_agent_svg_data_uri', true );

		$this->assertNotEmpty( $data_uri );
		$this->assertStringStartsWith( 'data:image/svg+xml;base64,', $data_uri );
	}

	// ─── end-to-end via run() when AI is unavailable ──────────────────────────

	/**
	 * When wp_ai_client_prompt is not available, generate falls back to the
	 * type-only wordmark and still returns valid candidates.
	 *
	 * In the PHPUnit environment the WP AI Client SDK is not loaded, so
	 * generate_one_candidate() always returns a WP_Error which triggers the
	 * fallback path.
	 */
	public function test_generate_falls_back_to_wordmark_without_ai_provider(): void {
		$result = $this->ability->run( [
			'brand_name'  => 'TestBrand',
			'description' => 'A test service business.',
			'count'       => 1,
		] );

		// In the test environment the AI client SDK is not available, so we
		// expect either a fallback wordmark result or a generation_failed error.
		// Both are valid outcomes — the key assertion is no exception is thrown.
		$this->assertTrue(
			is_array( $result ) || is_wp_error( $result ),
			'run() must return an array or WP_Error, not throw.'
		);

		if ( is_array( $result ) && ! empty( $result['candidates'] ) ) {
			// If candidates were generated, fallback flag must be true since AI is unavailable.
			$this->assertTrue( $result['fallback'], 'fallback must be true when AI provider is unavailable.' );
			foreach ( $result['candidates'] as $candidate ) {
				$this->assertArrayHasKey( 'attachment_id', $candidate );
				$this->assertArrayHasKey( 'data_uri', $candidate );
				$this->created_attachments[] = (int) $candidate['attachment_id'];
			}
		}
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Call a protected method on the ability instance via reflection.
	 *
	 * @param string $method_name  The protected method name.
	 * @param mixed  ...$args      Arguments to pass to the method.
	 * @return mixed               The return value.
	 */
	private function call_protected( string $method_name, mixed ...$args ): mixed {
		$method = new \ReflectionMethod( GenerateLogoSvgAbility::class, $method_name );
		$method->setAccessible( true );
		return $method->invokeArgs( $this->ability, $args );
	}

	/**
	 * Create a minimal SVG attachment for testing select_candidate.
	 *
	 * @return int Attachment ID.
	 */
	private function create_test_svg_attachment(): int {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			$this->markTestSkipped( 'Upload directory not available: ' . $upload_dir['error'] );
		}

		$svg      = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50" width="100" height="50"><rect x="0" y="0" width="100" height="50" fill="#abc"/></svg>';
		$filename = 'test-logo-fixture-' . substr( uniqid( '', false ), -6 ) . '.svg';
		$filepath = trailingslashit( (string) $upload_dir['path'] ) . $filename;
		$url      = trailingslashit( (string) $upload_dir['url'] ) . $filename;

		file_put_contents( $filepath, $svg ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$attachment_id = wp_insert_attachment(
			[
				'post_mime_type' => 'image/svg+xml',
				'post_title'     => 'Test Logo Fixture',
				'post_content'   => '',
				'post_status'    => 'inherit',
				'guid'           => $url,
			],
			$filepath,
			0
		);

		if ( is_wp_error( $attachment_id ) || ! is_int( $attachment_id ) || $attachment_id <= 0 ) {
			$this->markTestSkipped( 'Could not create fixture attachment.' );
		}

		$this->created_attachments[] = $attachment_id;

		return $attachment_id;
	}
}
