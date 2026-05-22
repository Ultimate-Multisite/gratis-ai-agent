<?php

declare(strict_types=1);
/**
 * Per-site MCP server instructions addendum.
 *
 * Stores an admin-editable string that the MCP server appends to its baseline
 * `serverInfo.instructions` at handshake time, letting a site encode style
 * conventions — callout class names, code-block themes, heading hierarchy rules
 * — once and have every connected MCP client receive them without re-discovery.
 *
 * The string supplements (does not replace) the existing Skills / Knowledge
 * plane: those handle RAG-style lookup; this is a tiny always-on prefix.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the per-site instructions addendum.
 *
 * All public-facing read paths sanitize on the way out (belt-and-braces) so
 * options written outside our sanitize_callback (direct update_option call,
 * database restore from an older schema, etc.) are still safe.
 */
final class InstructionsAddendum {

	/**
	 * WP option key.
	 *
	 * Public read via the REST endpoint by design — the value reaches every
	 * connected MCP client at handshake. Admins MUST NOT put secrets in this
	 * field; UI copy warns about this.
	 */
	const OPTION_KEY = 'sd_ai_agent_instructions';

	/**
	 * Companion option tracking the last update timestamp (unix seconds).
	 *
	 * Stored separately so REST callers can distinguish "never set" (0) from
	 * "explicitly cleared" (>0, empty addendum), and so a wipe-on-empty save
	 * still emits a fresh timestamp.
	 */
	const UPDATED_AT_OPTION = 'sd_ai_agent_instructions_updated_at';

	/**
	 * Maximum allowed length, counted in UTF-8 characters (not bytes).
	 *
	 * Hard-enforced on save and again on REST-read as defense in depth.
	 * All checks/truncations use mb_strlen / mb_substr with 'UTF-8' so
	 * emoji, CJK, and accented Latin are not split mid-codepoint.
	 *
	 * Sized to ~500 tokens of English text — cheap enough to prepend to
	 * every LLM session while fitting a useful style-rules list.
	 */
	const MAX_LENGTH = 2000;

	/**
	 * Rate-limit window for the public REST endpoint (requests per minute per
	 * remote IP).
	 *
	 * Deters scraping without affecting legitimate clients, which cache at
	 * max-age=60 and hit the endpoint once per session.
	 */
	const RATE_LIMIT_PER_MIN = 30;

	/**
	 * Return the stored addendum, sanitized and truncated at read time as
	 * belt-and-braces (protects against options written outside our callbacks).
	 *
	 * @return string Empty string when no addendum is set.
	 */
	public static function get_addendum(): string {
		$raw = (string) get_option( self::OPTION_KEY, '' );
		if ( '' === $raw ) {
			return '';
		}
		return self::maybe_truncate( self::sanitize( $raw ) );
	}

	/**
	 * Return the timestamp (unix seconds) of the last successful save.
	 *
	 * Zero when no value has ever been saved.
	 *
	 * @return int
	 */
	public static function get_updated_at(): int {
		return (int) get_option( self::UPDATED_AT_OPTION, 0 );
	}

	/**
	 * Save the addendum. Sanitizes input, enforces length cap, updates the
	 * companion timestamp.
	 *
	 * @param mixed $value Raw input.
	 *
	 * @return true|\WP_Error True on success; WP_Error('addendum_too_long')
	 *                        when input exceeds MAX_LENGTH after sanitize.
	 */
	public static function set_addendum( $value ) {
		$clean = self::sanitize( $value );

		// Length check fires after sanitize so HTML stripping doesn't
		// accidentally push a 1990-char input over the limit; the 2000-char
		// budget is the post-sanitize character count that reaches clients.
		if ( mb_strlen( $clean, 'UTF-8' ) > self::MAX_LENGTH ) {
			return new \WP_Error(
				'addendum_too_long',
				sprintf(
					/* translators: 1: max length, 2: submitted length */
					__( 'Instructions addendum is too long: %2$d characters (max %1$d).', 'superdav-ai-agent' ),
					self::MAX_LENGTH,
					mb_strlen( $clean, 'UTF-8' )
				),
				array( 'status' => 400 )
			);
		}

		update_option( self::OPTION_KEY, $clean, false );
		update_option( self::UPDATED_AT_OPTION, time(), false );
		return true;
	}

	/**
	 * Sanitize an addendum value.
	 *
	 * Strips HTML tags, PHP, WordPress shortcodes, and ASCII C0/C1 control
	 * characters (preserving \t, \n, \r for markdown indentation and bullet
	 * lists). Normalizes CRLF/CR to LF. Trims outer whitespace.
	 *
	 * Does NOT truncate to MAX_LENGTH — callers that need truncation (the
	 * Settings API sanitize_callback and the belt-and-braces read path) apply
	 * it themselves with self::maybe_truncate(). set_addendum() must see the
	 * full post-sanitize length so it can return WP_Error('addendum_too_long')
	 * rather than silently losing characters.
	 *
	 * What this does NOT do:
	 * - Render markdown. Output is sent verbatim to MCP clients.
	 * - Strip Unicode control characters beyond ASCII C0/C1 ranges (Bidi
	 *   marks, zero-width chars). The TypeScript MCP client does an
	 *   additional pass for those where they have higher injection signal.
	 *
	 * @param mixed $value Raw input.
	 *
	 * @return string Sanitized string (may be empty).
	 */
	public static function sanitize( $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}
		$str = (string) $value;
		if ( '' === $str ) {
			return '';
		}

		// Strip HTML/PHP tags. wp_strip_all_tags( $str, false ) does NOT
		// collapse whitespace (second arg=true would; we override).
		$str = wp_strip_all_tags( $str, false );

		// Strip WordPress shortcodes — defence against pasting [do_something]
		// and being surprised it doesn't execute (it can't, we never call
		// do_shortcode() on this value, but stripping removes the question).
		$str = strip_shortcodes( $str );

		// Strip C0 control chars except \t (0x09), \n (0x0A), \r (0x0D).
		// Also strips DEL (0x7F).
		$str = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $str );

		// Normalize CRLF / CR line endings to LF.
		$str = str_replace( array( "\r\n", "\r" ), "\n", $str );

		// Trim outer whitespace — leading/trailing newlines from a paste
		// don't carry meaning and waste token budget.
		$str = trim( $str );

		return $str;
	}

	/**
	 * Truncate a sanitized string to MAX_LENGTH UTF-8 characters.
	 *
	 * Uses mb_substr so truncation never lands inside a multi-byte codepoint
	 * sequence (emoji, CJK, accented Latin would otherwise produce a mojibake
	 * tail that breaks downstream JSON parsers).
	 *
	 * @param string $str Post-sanitize string.
	 *
	 * @return string String at most MAX_LENGTH UTF-8 characters long.
	 */
	private static function maybe_truncate( string $str ): string {
		if ( mb_strlen( $str, 'UTF-8' ) > self::MAX_LENGTH ) {
			return mb_substr( $str, 0, self::MAX_LENGTH, 'UTF-8' );
		}
		return $str;
	}

	/**
	 * Sanitize callback for register_setting().
	 *
	 * Wraps sanitize() + silent truncation for the Settings API on form submit.
	 * Over-length input is silently truncated to MAX_LENGTH here (the Settings
	 * API has no per-field WP_Error path without add_settings_error()).
	 *
	 * @param mixed $value Raw input from $_POST.
	 *
	 * @return string
	 */
	public static function sanitize_callback( $value ): string {
		$clean = self::maybe_truncate( self::sanitize( $value ) );

		// Touch the timestamp so REST consumers see the save even when the
		// value itself didn't change (admin re-saving to refresh the ts).
		update_option( self::UPDATED_AT_OPTION, time(), false );

		return $clean;
	}

	/**
	 * Check (and record) the per-IP rate limit for the public read endpoint.
	 *
	 * Uses a 60-second sliding-window transient keyed by a SHA-256 prefix of
	 * the remote IP (PII minimization — raw IP never touches the options table).
	 * Returns false when the request count within the last 60 s exceeds
	 * RATE_LIMIT_PER_MIN; caller should respond 429.
	 *
	 * @param string $ip Remote IP (caller passes $_SERVER['REMOTE_ADDR']).
	 *
	 * @return bool True when the request is permitted; false when exhausted.
	 */
	public static function check_rate_limit( string $ip ): bool {
		// Hash the IP to keep the transient key short and avoid storing PII.
		$key = 'sd_ai_agent_instr_rl_' . substr( hash( 'sha256', $ip ), 0, 12 );

		$now    = time();
		$window = 60;
		$bucket = get_transient( $key );
		if ( ! is_array( $bucket ) ) {
			$bucket = array();
		}

		// Drop entries outside the rolling window.
		$bucket = array_values(
			array_filter(
				$bucket,
				static function ( $ts ) use ( $now, $window ): bool {
					return is_numeric( $ts ) && ( $now - (int) $ts ) < $window;
				}
			)
		);

		if ( count( $bucket ) >= self::RATE_LIMIT_PER_MIN ) {
			return false;
		}

		$bucket[] = $now;
		// Slightly longer TTL than the window so the bucket survives until
		// every entry has aged out, even with no further requests.
		set_transient( $key, $bucket, $window * 2 );
		return true;
	}
}
