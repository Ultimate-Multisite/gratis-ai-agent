<?php

declare(strict_types=1);
/**
 * XSS bypass security tests.
 *
 * Verifies that every ability that accepts user-supplied HTML applies
 * `wp_kses_post()` correctly so that OWASP XSS filter-evasion payloads
 * cannot survive a round-trip through the block write/read cycle.
 *
 * Test strategy:
 *   1. Feed each OWASP payload through BlockMutator::apply_batch (op=update-html)
 *      which is the shared backend for the update-blocks ability and any op that
 *      mutates innerHTML.
 *   2. Verify the mutated block tree no longer contains the dangerous fragment.
 *   3. Assert that wp_kses_post() alone strips every payload — this is the
 *      underlying contract that all write paths must honour.
 *
 * @package SdAiAgent
 * @subpackage Tests\Security
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1789
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Security;

use SdAiAgent\Core\BlockMutator;
use WP_UnitTestCase;

/**
 * XSS bypass tests for block write operations.
 *
 * @group security
 * @group xss
 *
 * @since 1.11.0
 */
class XssBypassTest extends WP_UnitTestCase {

	/**
	 * OWASP XSS filter evasion payload list.
	 *
	 * Sourced from the OWASP XSS Filter Evasion Cheat Sheet and the set used
	 * in the upstream gk-block-api security suite.
	 *
	 * Each entry is [string $description, string $payload, string $dangerous_fragment].
	 * The dangerous fragment is what must NOT appear in the sanitised output.
	 *
	 * @return array<int, array{0: string, 1: string, 2: string}>
	 */
	private function xss_payloads(): array {
		return [
			// ── Script tag variants ────────────────────────────────────────
			[ 'script-basic',               '<script>alert(1)</script>',                      '<script' ],
			[ 'script-uppercase',           '<SCRIPT>alert(1)</SCRIPT>',                      '<SCRIPT' ],
			[ 'script-mixed-case',          '<ScRiPt>alert(1)</ScRiPt>',                      '<ScRiPt' ],
			[ 'script-type-attr',           '<script type="text/javascript">alert(1)</script>', '<script' ],
			[ 'script-src',                 '<script src="http://evil.example.com/x.js"></script>', '<script' ],
			[ 'script-broken-up',           '<scr<script>ipt>alert(1)</scr</script>ipt>',      'alert(1)' ],
			[ 'script-html-entities',       '&lt;script&gt;alert(1)&lt;/script&gt;',           '&lt;script' ],

			// ── Event handlers ─────────────────────────────────────────────
			[ 'onerror-img',                '<img src=x onerror="alert(1)">',                 'onerror' ],
			[ 'onload-body',                '<body onload="alert(1)">',                       'onload' ],
			[ 'onclick',                    '<a href="#" onclick="alert(1)">click</a>',        'onclick' ],
			[ 'onmouseover',                '<p onmouseover="alert(1)">hover</p>',             'onmouseover' ],
			[ 'onfocus',                    '<input onfocus="alert(1)" autofocus>',            'onfocus' ],
			[ 'onmouseout',                 '<div onmouseout="alert(1)">x</div>',             'onmouseout' ],
			[ 'onerror-svg',                '<svg onload="alert(1)"></svg>',                  'onload' ],

			// ── javascript: URI ────────────────────────────────────────────
			[ 'javascript-href',            '<a href="javascript:alert(1)">click</a>',         'javascript:' ],
			[ 'javascript-href-uppercase',  '<a href="JAVASCRIPT:alert(1)">click</a>',         'JAVASCRIPT:' ],
			[ 'javascript-href-entities',   '<a href="&#106;avascript:alert(1)">click</a>',    'javascript:' ],
			[ 'javascript-href-space',      '<a href=" javascript:alert(1)">click</a>',        'javascript:' ],

			// ── data: URI ──────────────────────────────────────────────────
			[ 'data-uri-img',               '<img src="data:text/html,<script>alert(1)</script>">', 'data:' ],
			[ 'data-uri-iframe',            '<iframe src="data:text/html,<h1>XSS</h1>"></iframe>', '<iframe' ],

			// ── SVG / MathML ───────────────────────────────────────────────
			[ 'svg-onload',                 '<svg><script>alert(1)</script></svg>',            '<script' ],
			[ 'svg-animate',                '<svg><animate onbegin="alert(1)"/></svg>',        'onbegin' ],
			[ 'mathml',                     '<math><mtext><table><mglyph><style><img src onerror="alert(1)"></style></mglyph></table></mtext></math>', 'onerror' ],

			// ── Malformed / partial tags ───────────────────────────────────
			[ 'unclosed-script',            '<script>alert(1)',                               'alert(1)' ],
			[ 'null-byte',                  "<img src=\"java\0script:alert(1)\">",             "java\0script:" ],
			[ 'double-open-angle',          '<<script>alert(1)<</script>',                    '<script' ],
			[ 'vbscript',                   '<a href="vbscript:alert(1)">click</a>',           'vbscript:' ],
			[ 'expression-css',             '<div style="width:expression(alert(1))">x</div>', 'expression(' ],
			[ 'style-import',               '<style>@import url("javascript:alert(1)")</style>', '@import' ],
			[ 'meta-refresh',               '<meta http-equiv="refresh" content="0;url=javascript:alert(1)">', '<meta' ],
			[ 'object-tag',                 '<object data="javascript:alert(1)">click</object>', '<object' ],
		];
	}

	/**
	 * Build a minimal parsed-block tree for use in apply_batch tests.
	 *
	 * Returns one core/paragraph block with a stable fake ref so that
	 * apply_batch can resolve the 'flat_index' address without needing a DB.
	 *
	 * @param string $initial_html Initial safe innerHTML.
	 * @return array<int, mixed> Single-item parsed block array.
	 */
	private function make_blocks( string $initial_html = '<p>Original safe content.</p>' ): array {
		return [
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [
					'metadata' => [
						'sd_ref' => 'blk_test_xss_paragraph',
					],
				],
				'innerBlocks'  => [],
				'innerHTML'    => $initial_html,
				'innerContent' => [ $initial_html ],
			],
		];
	}

	// ── wp_kses_post direct tests (30+ OWASP payloads) ───────────────────

	/**
	 * wp_kses_post must strip every OWASP XSS payload.
	 *
	 * @dataProvider xss_payload_provider
	 *
	 * @param string $description     Human-readable payload name.
	 * @param string $payload         Raw XSS payload.
	 * @param string $dangerous_frag  Fragment that must not appear in the output.
	 */
	public function test_wp_kses_post_strips_xss_payload(
		string $description,
		string $payload,
		string $dangerous_frag
	): void {
		$sanitised = wp_kses_post( $payload );

		$this->assertStringNotContainsStringIgnoringCase(
			$dangerous_frag,
			$sanitised,
			"wp_kses_post() must strip '{$description}' — fragment '{$dangerous_frag}' survived."
		);
	}

	/**
	 * Data provider delegating to xss_payloads().
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function xss_payload_provider(): array {
		$out = [];
		foreach ( $this->xss_payloads() as [ $desc, $payload, $frag ] ) {
			$out[ $desc ] = [ $desc, $payload, $frag ];
		}
		return $out;
	}

	// ── update-html op through BlockMutator ──────────────────────────────

	/**
	 * BlockMutator::apply_batch op=update-html must sanitise innerHTML via wp_kses_post.
	 *
	 * Feeds a basic script payload through the block-mutator layer and asserts
	 * that the mutated block's innerHTML does not contain the script tag.
	 */
	public function test_update_html_op_strips_script_tag(): void {
		$blocks  = $this->make_blocks();
		$payload = '<p>safe</p><script>alert("xss")</script>';

		$result = BlockMutator::apply_batch(
			$blocks,
			[
				[
					'op'        => 'update-html',
					'flat_index' => 0,
					'innerHTML' => $payload,
				],
			]
		);

		$this->assertIsArray( $result );
		$mutated_html = (string) ( $result[0]['innerHTML'] ?? '' );
		$this->assertStringNotContainsString( '<script', $mutated_html );
		$this->assertStringNotContainsString( 'alert(', $mutated_html );
	}

	/**
	 * BlockMutator::apply_batch op=update-html strips event handler attributes.
	 */
	public function test_update_html_op_strips_event_handler(): void {
		$blocks  = $this->make_blocks();
		$payload = '<p onmouseover="alert(1)">hover</p>';

		$result = BlockMutator::apply_batch(
			$blocks,
			[
				[
					'op'        => 'update-html',
					'flat_index' => 0,
					'innerHTML' => $payload,
				],
			]
		);

		$this->assertIsArray( $result );
		$mutated_html = (string) ( $result[0]['innerHTML'] ?? '' );
		$this->assertStringNotContainsString( 'onmouseover', $mutated_html );
		$this->assertStringNotContainsString( 'alert(', $mutated_html );
	}

	/**
	 * BlockMutator::apply_batch op=update-html strips javascript: href.
	 */
	public function test_update_html_op_strips_javascript_href(): void {
		$blocks  = $this->make_blocks();
		$payload = '<p><a href="javascript:alert(1)">click me</a></p>';

		$result = BlockMutator::apply_batch(
			$blocks,
			[
				[
					'op'        => 'update-html',
					'flat_index' => 0,
					'innerHTML' => $payload,
				],
			]
		);

		$this->assertIsArray( $result );
		$mutated_html = (string) ( $result[0]['innerHTML'] ?? '' );
		$this->assertStringNotContainsString( 'javascript:', $mutated_html );
	}

	/**
	 * BlockMutator::apply_batch op=update-html strips SVG onload.
	 */
	public function test_update_html_op_strips_svg_onload(): void {
		$blocks  = $this->make_blocks();
		$payload = '<p>text<svg onload="alert(1)"></svg></p>';

		$result = BlockMutator::apply_batch(
			$blocks,
			[
				[
					'op'        => 'update-html',
					'flat_index' => 0,
					'innerHTML' => $payload,
				],
			]
		);

		$this->assertIsArray( $result );
		$mutated_html = (string) ( $result[0]['innerHTML'] ?? '' );
		$this->assertStringNotContainsString( 'onload', $mutated_html );
	}

	/**
	 * Safe HTML (bold, italic, paragraph, links with http href) survives kses.
	 *
	 * This confirms that kses is not over-stripping legitimate block content.
	 */
	public function test_update_html_op_preserves_safe_html(): void {
		$blocks   = $this->make_blocks();
		$safe     = '<p>Hello <strong>world</strong>, <em>this</em> is <a href="https://example.com">a link</a>.</p>';

		$result = BlockMutator::apply_batch(
			$blocks,
			[
				[
					'op'        => 'update-html',
					'flat_index' => 0,
					'innerHTML' => $safe,
				],
			]
		);

		$this->assertIsArray( $result );
		$mutated_html = (string) ( $result[0]['innerHTML'] ?? '' );
		$this->assertStringContainsString( '<strong>world</strong>', $mutated_html );
		$this->assertStringContainsString( '<em>this</em>', $mutated_html );
		$this->assertStringContainsString( 'https://example.com', $mutated_html );
	}

	// ── Round-trip: write → read via post_content ─────────────────────────

	/**
	 * XSS payload fed through handle_update_blocks does not survive the
	 * write → read round-trip via post_content.
	 *
	 * Creates a real post, persists refs, applies an XSS update-html via
	 * handle_update_blocks, then reads the post_content back and asserts
	 * the dangerous fragment is not present.
	 */
	public function test_round_trip_xss_payload_does_not_survive(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$post_id = self::factory()->post->create( [
			'post_status'  => 'draft',
			'post_content' => "<!-- wp:paragraph -->\n<p>Original content.</p>\n<!-- /wp:paragraph -->",
		] );

		// Get refs so update-blocks can target by ref.
		$page_blocks = \SdAiAgent\Abilities\BlockAbilities::handle_get_page_blocks( [
			'post_id'      => $post_id,
			'persist_refs' => true,
		] );

		$this->assertIsArray( $page_blocks, 'handle_get_page_blocks must succeed.' );
		$this->assertNotEmpty( $page_blocks['blocks'], 'Post must have at least one block.' );
		$ref = $page_blocks['blocks'][0]['ref'] ?? '';
		$this->assertNotEmpty( $ref, 'Block must have a ref after persist_refs.' );

		// Attempt to inject XSS via update-html.
		$xss_payload = '<p>safe</p><script>alert("xss")</script><img src=x onerror="alert(1)">';

		$update_result = \SdAiAgent\Abilities\BlockAbilities::handle_update_blocks( [
			'post_id' => $post_id,
			'updates' => [
				[
					'op'        => 'update-html',
					'ref'       => $ref,
					'innerHTML' => $xss_payload,
				],
			],
		] );

		$this->assertIsArray( $update_result, 'handle_update_blocks must succeed (sanitisation, not rejection).' );

		// Read the saved post_content and assert the XSS is absent.
		$saved_post    = get_post( $post_id );
		$saved_content = $saved_post->post_content ?? '';

		$this->assertStringNotContainsString( '<script', $saved_content );
		$this->assertStringNotContainsString( 'onerror', $saved_content );
		$this->assertStringNotContainsString( 'alert(', $saved_content );

		wp_set_current_user( 0 );
	}
}
