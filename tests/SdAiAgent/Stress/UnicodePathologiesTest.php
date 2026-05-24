<?php
// SPDX-License-Identifier: MIT
// SPDX-FileCopyrightText: 2025-2026 Marcus Quinn
/**
 * Scenario 9: Unicode pathologies in attrs and innerHTML (ported from block-mcp).
 *
 * JSON encoding (for attrs) and HTML sanitization (for innerHTML) are
 * Unicode-sensitive. Pathological strings — RTL marks, ZWJs, BOM,
 * combining-mark stacks, mixed normalization, control characters —
 * occasionally trip parsers that work fine on ASCII.
 *
 * Every hostile string in this battery must either survive byte-identical
 * through save→read OR be rejected with a clean error. Silent mangling
 * (truncation, replacement-character substitution, JSON corruption) is
 * the failure mode we're hunting.
 *
 * Covers 7 pathology classes:
 *   1. RTL / BiDi control marks (U+202E).
 *   2. Zero-width joiner (U+200D).
 *   3. Byte order mark (U+FEFF).
 *   4. Mixed Unicode normalization forms (NFC vs NFD).
 *   5. 4-byte emoji clusters.
 *   6. Combining-mark stacks (zalgo).
 *   7. Control characters / NUL bytes.
 *
 * Ported one-for-one from block-mcp tests/Stress/UnicodePathologiesTest.php
 * (GPL-2.0-or-later). gk_block_api_rate_ transient cleared → not required
 * (BlockMutator::apply() does not check rate limits); all other adaptations
 * follow AGENTS.md.
 *
 * @package SdAiAgent\Tests\Stress
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1788
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Stress;

use WP_UnitTestCase;

/**
 * Unicode pathology tests for attrs and innerHTML round-trips.
 *
 * Uses WP_UnitTestCase so parse_blocks() / serialize_blocks() / wp_update_post()
 * and real database round-trips are available.
 */
class UnicodePathologiesTest extends WP_UnitTestCase {

	/** @var int WP post ID used throughout the test. */
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		$this->post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
	}

	// ── Helper ────────────────────────────────────────────────────────────

	/**
	 * Write a single paragraph with `data` attribute set to $value,
	 * then read it back and return the stored attribute value.
	 *
	 * @param string $value Attribute value to store.
	 * @return string Attribute value as read back from the DB.
	 */
	private function round_trip_attr( string $value ): string {
		$block = [
			'blockName'    => 'core/paragraph',
			'attrs'        => [ 'data' => $value ],
			'innerHTML'    => '<p>x</p>',
			'innerContent' => [ '<p>x</p>' ],
			'innerBlocks'  => [],
		];

		wp_update_post( [
			'ID'           => $this->post_id,
			'post_content' => serialize_blocks( [ $block ] ),
		] );

		$blocks  = parse_blocks( (string) get_post_field( 'post_content', $this->post_id ) );
		$visible = array_values( array_filter( $blocks, static fn( $b ) => null !== ( $b['blockName'] ?? null ) ) );

		return (string) ( $visible[0]['attrs']['data'] ?? '' );
	}

	// ── Pathology class 1: RTL / BiDi control marks ───────────────────────

	/**
	 * U+202E RIGHT-TO-LEFT OVERRIDE — visually flips text direction.
	 * Valid Unicode; should round-trip byte-identical through JSON attrs.
	 */
	public function test_rtl_override_mark_survives_round_trip(): void {
		$payload = "before\xE2\x80\xAEafter";  // U+202E in UTF-8
		$this->assertSame( $payload, $this->round_trip_attr( $payload ) );
	}

	// ── Pathology class 2: Zero-width joiner ──────────────────────────────

	public function test_zero_width_joiner_survives(): void {
		// U+200D ZERO WIDTH JOINER — common in emoji sequences.
		$payload = "a\xE2\x80\x8Db";
		$this->assertSame( $payload, $this->round_trip_attr( $payload ) );
	}

	// ── Pathology class 3: Byte order mark ───────────────────────────────

	/**
	 * U+FEFF BYTE ORDER MARK — must NOT be silently stripped from a value
	 * just because it's the BOM codepoint.
	 */
	public function test_bom_in_value_survives(): void {
		$payload = "\xEF\xBB\xBFvalue";  // U+FEFF in UTF-8
		$this->assertSame( $payload, $this->round_trip_attr( $payload ) );
	}

	// ── Pathology class 4: Mixed normalization forms ──────────────────────

	/**
	 * "café" can be NFC (single codepoint) or NFD (e + combining acute).
	 * WordPress must NOT normalize either way silently.
	 */
	public function test_mixed_normalization_forms_round_trip(): void {
		$nfc = "caf\xC3\xA9";          // U+00E9 — é as single codepoint
		$nfd = "cafe\xCC\x81";         // e + U+0301 COMBINING ACUTE ACCENT

		$got_nfc = $this->round_trip_attr( $nfc );
		$got_nfd = $this->round_trip_attr( $nfd );

		$this->assertSame( $nfc, $got_nfc, 'NFC normalization form must survive' );
		$this->assertSame( $nfd, $got_nfd, 'NFD normalization form must survive without coercion to NFC' );
	}

	// ── Pathology class 5: 4-byte emoji clusters ─────────────────────────

	public function test_emoji_survives_round_trip(): void {
		// Pre-PR #13 this was the canary for JSON_UNESCAPED_UNICODE behaviour.
		$payload = '🎉 héllo 日本語 🌸';
		$this->assertSame( $payload, $this->round_trip_attr( $payload ) );
	}

	public function test_high_surrogate_pair_emoji_in_innerhtml(): void {
		// 🎉 is U+1F389 (4-byte UTF-8). JSON encoders that handle surrogates
		// correctly emit it unescaped.
		wp_update_post( [
			'ID'           => $this->post_id,
			'post_content' => serialize_blocks( [
				[
					'blockName'    => 'core/paragraph',
					'attrs'        => [],
					'innerHTML'    => '<p>🎉 party</p>',
					'innerContent' => [ '<p>🎉 party</p>' ],
					'innerBlocks'  => [],
				],
			] ),
		] );
		$saved = (string) get_post_field( 'post_content', $this->post_id );
		$this->assertStringContainsString( '🎉 party', $saved );

		$blocks  = parse_blocks( $saved );
		$visible = array_values( array_filter( $blocks, static fn( $b ) => null !== ( $b['blockName'] ?? null ) ) );
		$this->assertStringContainsString( '🎉 party', $visible[0]['innerHTML'] );
	}

	// ── Pathology class 6: Combining-mark stacks (zalgo) ─────────────────

	public function test_zalgo_combining_mark_stack(): void {
		// 'a' + 50× U+0301 COMBINING ACUTE ACCENT — zalgo text.
		$payload = 'a' . str_repeat( "\xCC\x81", 50 );
		$got     = $this->round_trip_attr( $payload );
		$this->assertSame( $payload, $got, 'zalgo combining-mark stack must survive without truncation' );
	}

	// ── Pathology class 7: Control characters ────────────────────────────

	public function test_control_chars_filtered_or_rejected(): void {
		// C0 control characters (0x00-0x1F except tab/CR/LF) — invalid in
		// JSON strings per RFC 8259. WordPress escapes/strips them via
		// wp_kses_post + JSON encoding.
		$with_null = "before\0after";
		$got       = $this->round_trip_attr( $with_null );
		// Real WordPress JSON-encodes NUL in attrs — exact output depends on
		// WP version and PHP json_encode flags. What matters: no truncation
		// at the NUL position and no PHP fatal.
		$this->assertStringContainsString( 'before', $got );
		$this->assertStringContainsString( 'after', $got );
	}

	// ── Bonus: large mixed-Unicode strings ───────────────────────────────

	public function test_long_unicode_string_round_trips(): void {
		// ~10 KB of mixed Unicode — exercises JSON encoder buffer management.
		$pattern = '日本語🎉héllo';
		$payload = str_repeat( $pattern, 500 );
		$this->assertGreaterThan( 5000, strlen( $payload ) );
		$got = $this->round_trip_attr( $payload );
		$this->assertSame( $payload, $got );
	}
}
