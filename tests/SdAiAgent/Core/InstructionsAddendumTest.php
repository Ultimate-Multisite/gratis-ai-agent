<?php

declare(strict_types=1);
/**
 * Test case for InstructionsAddendum class.
 *
 * Coverage:
 *   - get_addendum() returns empty string by default.
 *   - set_addendum() persists value and updates timestamp.
 *   - set_addendum() clears value on empty string but still bumps timestamp.
 *   - set_addendum() returns WP_Error('addendum_too_long') for input > MAX_LENGTH.
 *   - sanitize() strips HTML, shortcodes, and C0 control characters.
 *   - sanitize() preserves \t and \n (markdown whitespace).
 *   - sanitize() normalizes CRLF/CR to LF.
 *   - sanitize() truncates at MAX_LENGTH (UTF-8 character-safe, not byte-safe).
 *   - sanitize() handles multi-byte characters (emoji, CJK, accented Latin).
 *   - sanitize_callback() silently truncates over-length input.
 *   - check_rate_limit() permits requests within RATE_LIMIT_PER_MIN.
 *   - check_rate_limit() blocks when limit is exceeded.
 *   - check_rate_limit() isolates buckets by IP.
 *
 * @package SdAiAgent
 * @subpackage Tests\Core
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\InstructionsAddendum;
use WP_UnitTestCase;

/**
 * Test InstructionsAddendum functionality.
 */
class InstructionsAddendumTest extends WP_UnitTestCase {

	/**
	 * Clean up options and transients after each test.
	 */
	public function tear_down(): void {
		parent::tear_down();
		delete_option( InstructionsAddendum::OPTION_KEY );
		delete_option( InstructionsAddendum::UPDATED_AT_OPTION );
		// Clear any rate-limit transients created during tests.
		// We cannot know the exact keys (they use a hash of the IP), so
		// we rely on set_transient() + WordPress test cleanup.
	}

	// -----------------------------------------------------------------------
	// get_addendum / set_addendum
	// -----------------------------------------------------------------------

	/**
	 * Default value is empty string before any save.
	 */
	public function test_get_addendum_returns_empty_string_by_default(): void {
		$this->assertSame( '', InstructionsAddendum::get_addendum() );
	}

	/**
	 * Saving a value persists it and can be retrieved.
	 */
	public function test_set_and_get_addendum_round_trip(): void {
		$value = "Use class `is-style-info` for callouts.\nHeadings start at H2.";
		$result = InstructionsAddendum::set_addendum( $value );
		$this->assertTrue( $result );
		$this->assertSame( $value, InstructionsAddendum::get_addendum() );
	}

	/**
	 * Saving bumps the timestamp companion option.
	 */
	public function test_set_addendum_updates_timestamp(): void {
		$before = time();
		InstructionsAddendum::set_addendum( 'Hello.' );
		$after = time();

		$ts = InstructionsAddendum::get_updated_at();
		$this->assertGreaterThanOrEqual( $before, $ts );
		$this->assertLessThanOrEqual( $after, $ts );
	}

	/**
	 * Saving an empty string clears the value but still bumps the timestamp.
	 */
	public function test_set_addendum_empty_clears_value_and_bumps_timestamp(): void {
		InstructionsAddendum::set_addendum( 'Initial value.' );

		$ts_before = InstructionsAddendum::get_updated_at();
		// Sleep 1s so the timestamp can change.
		sleep( 1 );

		$result = InstructionsAddendum::set_addendum( '' );
		$this->assertTrue( $result );
		$this->assertSame( '', InstructionsAddendum::get_addendum() );
		$this->assertGreaterThan( $ts_before, InstructionsAddendum::get_updated_at() );
	}

	/**
	 * Over-length input returns WP_Error('addendum_too_long').
	 */
	public function test_set_addendum_too_long_returns_wp_error(): void {
		// Build a string of MAX_LENGTH + 1 plain ASCII characters.
		$value  = str_repeat( 'x', InstructionsAddendum::MAX_LENGTH + 1 );
		$result = InstructionsAddendum::set_addendum( $value );

		$this->assertWPError( $result );
		$this->assertSame( 'addendum_too_long', $result->get_error_code() );
	}

	/**
	 * Exactly MAX_LENGTH characters are accepted.
	 */
	public function test_set_addendum_at_max_length_succeeds(): void {
		$value  = str_repeat( 'a', InstructionsAddendum::MAX_LENGTH );
		$result = InstructionsAddendum::set_addendum( $value );
		$this->assertTrue( $result );
	}

	// -----------------------------------------------------------------------
	// sanitize()
	// -----------------------------------------------------------------------

	/**
	 * HTML tags are stripped.
	 */
	public function test_sanitize_strips_html(): void {
		$input  = '<b>Bold</b> and <script>alert(1)</script> text.';
		$result = InstructionsAddendum::sanitize( $input );
		$this->assertStringNotContainsString( '<b>', $result );
		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringContainsString( 'Bold', $result );
		$this->assertStringContainsString( 'text.', $result );
	}

	/**
	 * WordPress shortcodes are stripped.
	 *
	 * Uses a self-closing fake shortcode to avoid WordPress's known
	 * [gallery] shortcode, which would consume the text between open/close
	 * tags. Unregistered shortcodes are also stripped by strip_shortcodes().
	 */
	public function test_sanitize_strips_shortcodes(): void {
		// Register a fake shortcode so strip_shortcodes() sees it.
		add_shortcode( 'sd-ai-agent-fake-test-sc', '__return_false' );
		$input  = '[sd-ai-agent-fake-test-sc id="1"] Some text.';
		$result = InstructionsAddendum::sanitize( $input );
		remove_shortcode( 'sd-ai-agent-fake-test-sc' );

		$this->assertStringNotContainsString( '[sd-ai-agent-fake-test-sc', $result );
		$this->assertStringContainsString( 'Some text.', $result );
	}

	/**
	 * C0 control characters (except \t, \n, \r) are stripped.
	 */
	public function test_sanitize_strips_c0_control_characters(): void {
		// NUL, BEL, SOH — should be removed.
		$input  = "Before\x00\x07\x01After";
		$result = InstructionsAddendum::sanitize( $input );
		$this->assertSame( 'BeforeAfter', $result );
	}

	/**
	 * Tabs and newlines are preserved (needed for markdown indentation).
	 */
	public function test_sanitize_preserves_tab_and_newline(): void {
		$input  = "Line one\n\tIndented line\n";
		$result = InstructionsAddendum::sanitize( $input );
		$this->assertStringContainsString( "\n", $result );
		$this->assertStringContainsString( "\t", $result );
	}

	/**
	 * CRLF line endings are normalized to LF.
	 */
	public function test_sanitize_normalizes_crlf_to_lf(): void {
		$input  = "Line one\r\nLine two\r";
		$result = InstructionsAddendum::sanitize( $input );
		$this->assertStringNotContainsString( "\r", $result );
		$this->assertStringContainsString( "Line one\nLine two", $result );
	}

	/**
	 * sanitize() alone does NOT truncate — truncation is the responsibility
	 * of callers (sanitize_callback for Settings API, get_addendum for reads).
	 */
	public function test_sanitize_does_not_truncate(): void {
		$input  = str_repeat( 'a', InstructionsAddendum::MAX_LENGTH + 100 );
		$result = InstructionsAddendum::sanitize( $input );
		$this->assertSame( InstructionsAddendum::MAX_LENGTH + 100, mb_strlen( $result, 'UTF-8' ) );
	}

	/**
	 * sanitize_callback() truncates over-length input to exactly MAX_LENGTH chars.
	 */
	public function test_sanitize_callback_truncates_to_max_length(): void {
		$input  = str_repeat( 'a', InstructionsAddendum::MAX_LENGTH + 100 );
		$result = InstructionsAddendum::sanitize_callback( $input );
		$this->assertSame( InstructionsAddendum::MAX_LENGTH, mb_strlen( $result, 'UTF-8' ) );
	}

	/**
	 * Multi-byte truncation in sanitize_callback() is codepoint-safe.
	 *
	 * Each emoji is 1 codepoint but 4 bytes. If the truncation used substr()
	 * instead of mb_substr(), the result would be a broken byte sequence.
	 */
	public function test_sanitize_callback_truncates_multibyte_without_splitting(): void {
		// Each 🎉 is U+1F389 (4 bytes, 1 codepoint).
		$emoji  = "\xF0\x9F\x8E\x89"; // 🎉
		$input  = str_repeat( $emoji, InstructionsAddendum::MAX_LENGTH + 5 );
		$result = InstructionsAddendum::sanitize_callback( $input );

		// Result must be exactly MAX_LENGTH codepoints.
		$this->assertSame( InstructionsAddendum::MAX_LENGTH, mb_strlen( $result, 'UTF-8' ) );
		// Result must be valid UTF-8 (no broken sequences).
		$this->assertNotFalse( mb_detect_encoding( $result, 'UTF-8', true ) );
	}

	/**
	 * CJK characters (3 bytes each) are counted as single characters.
	 *
	 * A string of MAX_LENGTH+1 CJK characters exceeds the limit even if
	 * set_addendum() uses mb_strlen, so it must return WP_Error.
	 */
	public function test_set_addendum_cjk_over_limit_returns_error(): void {
		// Each CJK character U+4E00 is 3 bytes but 1 codepoint.
		$cjk   = "\xE4\xB8\x80"; // 一
		$value = str_repeat( $cjk, InstructionsAddendum::MAX_LENGTH + 1 );
		$this->assertSame( InstructionsAddendum::MAX_LENGTH + 1, mb_strlen( $value, 'UTF-8' ) );

		$result = InstructionsAddendum::set_addendum( $value );
		$this->assertWPError( $result );
		$this->assertSame( 'addendum_too_long', $result->get_error_code() );
	}

	/**
	 * sanitize() returns empty string for array/object input.
	 */
	public function test_sanitize_returns_empty_for_non_string(): void {
		$this->assertSame( '', InstructionsAddendum::sanitize( array( 'foo' ) ) );
		$this->assertSame( '', InstructionsAddendum::sanitize( new \stdClass() ) );
	}

	// -----------------------------------------------------------------------
	// sanitize_callback()
	// -----------------------------------------------------------------------

	/**
	 * sanitize_callback() silently truncates over-length input (Settings API).
	 */
	public function test_sanitize_callback_truncates_silently(): void {
		$long   = str_repeat( 'b', InstructionsAddendum::MAX_LENGTH + 50 );
		$result = InstructionsAddendum::sanitize_callback( $long );
		$this->assertSame( InstructionsAddendum::MAX_LENGTH, mb_strlen( $result, 'UTF-8' ) );
	}

	/**
	 * sanitize_callback() touches the updated_at timestamp.
	 */
	public function test_sanitize_callback_bumps_timestamp(): void {
		$before = time();
		InstructionsAddendum::sanitize_callback( 'Some rules.' );
		$after = time();

		$ts = InstructionsAddendum::get_updated_at();
		$this->assertGreaterThanOrEqual( $before, $ts );
		$this->assertLessThanOrEqual( $after, $ts );
	}

	// -----------------------------------------------------------------------
	// check_rate_limit()
	// -----------------------------------------------------------------------

	/**
	 * Requests within the rate limit are permitted.
	 */
	public function test_check_rate_limit_permits_within_budget(): void {
		$ip = '192.0.2.1';
		// First request must be permitted.
		$this->assertTrue( InstructionsAddendum::check_rate_limit( $ip ) );
	}

	/**
	 * Requests exceeding RATE_LIMIT_PER_MIN are blocked.
	 */
	public function test_check_rate_limit_blocks_when_exceeded(): void {
		$ip = '192.0.2.2';

		// Exhaust the budget.
		for ( $i = 0; $i < InstructionsAddendum::RATE_LIMIT_PER_MIN; $i++ ) {
			InstructionsAddendum::check_rate_limit( $ip );
		}

		// The next request must be blocked.
		$this->assertFalse( InstructionsAddendum::check_rate_limit( $ip ) );
	}

	/**
	 * Different IPs have independent rate-limit buckets.
	 */
	public function test_check_rate_limit_isolates_by_ip(): void {
		$ip_a = '192.0.2.10';
		$ip_b = '192.0.2.11';

		// Exhaust ip_a's budget.
		for ( $i = 0; $i < InstructionsAddendum::RATE_LIMIT_PER_MIN; $i++ ) {
			InstructionsAddendum::check_rate_limit( $ip_a );
		}

		// ip_a is now blocked.
		$this->assertFalse( InstructionsAddendum::check_rate_limit( $ip_a ) );

		// ip_b must still be permitted.
		$this->assertTrue( InstructionsAddendum::check_rate_limit( $ip_b ) );
	}

	// -----------------------------------------------------------------------
	// get_updated_at()
	// -----------------------------------------------------------------------

	/**
	 * get_updated_at() returns 0 when the option has never been written.
	 */
	public function test_get_updated_at_returns_zero_by_default(): void {
		$this->assertSame( 0, InstructionsAddendum::get_updated_at() );
	}
}
