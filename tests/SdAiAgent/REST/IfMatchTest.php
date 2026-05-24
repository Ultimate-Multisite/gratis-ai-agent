<?php

declare(strict_types=1);
/**
 * Tests for HTTP If-Match / ETag optimistic concurrency on MCP block routes (t269).
 *
 * Covers the contract defined in the Wave 3.5 brief:
 *   - IfMatchHeader::parse() covers all accepted and rejected header shapes.
 *   - IfMatchHeader::format() produces the canonical W/"rev-N" form.
 *   - McpController::handle_call_tool() emits ETag on responses that include
 *     revision_id (block-read path).
 *   - McpController::handle_call_tool() enforces If-Match preconditions for
 *     block-write calls (pre-flight revision check).
 *   - Missing If-Match header is a no-op (backward-compatible pass-through).
 *   - Unparseable If-Match returns 412 invalid_if_match.
 *   - Both body expected_revision and If-Match header agreeing → 200.
 *   - Both body expected_revision and If-Match header disagreeing → 412
 *     conflicting_revision_preconditions.
 *   - Response ETag advances after a successful write.
 *   - Wildcard `If-Match: *` short-circuits the revision check.
 *   - RestController::add_etag_header() adds ETag to direct-route responses
 *     that carry revision_id in the top-level data array.
 *
 * @package SdAiAgent
 * @subpackage Tests\REST
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1785
 */

namespace SdAiAgent\Tests\REST;

use SdAiAgent\REST\IfMatchHeader;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Integration and unit tests for If-Match / ETag optimistic concurrency (t269).
 *
 * @group if-match
 * @group rest
 * @group t269
 */
class IfMatchTest extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	protected WP_REST_Server $server;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected int $admin_id;

	/**
	 * MCP endpoint route.
	 */
	private const MCP_ROUTE = '/sd-ai-agent/v1/mcp';

	/**
	 * Ability name for the mock block-read ability (returns revision_id).
	 */
	private const READ_ABILITY  = 'test-t269/get-blocks';

	/**
	 * Ability name for the mock block-write ability (checks revision, advances it).
	 */
	private const WRITE_ABILITY = 'test-t269/write-blocks';

	/**
	 * MCP tool names (ability name with / replaced by __).
	 */
	private const READ_TOOL  = 'test-t269__get-blocks';
	private const WRITE_TOOL = 'test-t269__write-blocks';

	/**
	 * Test post ID, created fresh for each test.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Set up REST server, user, post, and mock abilities before each test.
	 */
	public function set_up(): void {
		// REST server + rest_api_init must precede parent::set_up().
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'rest_api_init' );

		parent::set_up();

		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );

		// Create a test post with two revisions so RevisionGuard has data.
		$this->post_id = self::factory()->post->create(
			[
				'post_title'   => 'If-Match Test Post',
				'post_status'  => 'publish',
				'post_content' => 'initial content',
			]
		);
		// Create a first revision.
		wp_update_post(
			[
				'ID'           => $this->post_id,
				'post_content' => 'revision 1',
			]
		);

		// Register mock abilities using the hook-context trick from McpControllerTest.
		if ( function_exists( 'wp_register_ability' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			global $wp_current_filter;
			$wp_current_filter[] = 'wp_abilities_api_init';

			$post_id = $this->post_id;

			// Mock block-READ ability: returns revision_id in response.
			wp_register_ability(
				self::READ_ABILITY,
				[
					'label'               => 'Mock Get Blocks',
					'description'         => 'Mock read ability for If-Match tests.',
					'category'            => 'sd-ai-agent',
					'input_schema'        => [
						'type'       => 'object',
						'properties' => [
							'post_id' => [ 'type' => 'integer' ],
						],
						'required'   => [ 'post_id' ],
					],
					'execute_callback'    => static function ( $args ) {
						$pid         = (int) ( $args['post_id'] ?? 0 );
						$revision_id = \SdAiAgent\Core\RevisionGuard::current_revision_id( $pid );
						return [
							'blocks'      => [],
							'revision_id' => $revision_id,
						];
					},
					'permission_callback' => static function () {
						return current_user_can( 'manage_options' );
					},
				]
			);

			// Mock block-WRITE ability: checks expected_revision, returns new revision_id.
			//
			// The execute_callback bypasses WordPress's revision deduplication by
			// using the `wp_save_post_revision_check_for_changes` filter to always
			// create a new revision, regardless of content diff. This is necessary
			// because WordPress skips revision creation when the post content hasn't
			// changed since the last revision, making the "ETag advances" assertion
			// unreliable. Real block-write abilities always change content, but a
			// minimal mock for this test class avoids a full block-tree setup.
			wp_register_ability(
				self::WRITE_ABILITY,
				[
					'label'               => 'Mock Write Blocks',
					'description'         => 'Mock write ability for If-Match tests.',
					'category'            => 'sd-ai-agent',
					'input_schema'        => [
						'type'       => 'object',
						'properties' => [
							'post_id'           => [ 'type' => 'integer' ],
							'expected_revision' => [ 'type' => [ 'integer', 'null' ] ],
						],
						'required'   => [ 'post_id' ],
					],
					'execute_callback'    => static function ( $args ) {
						$pid      = (int) ( $args['post_id'] ?? 0 );
						$expected = isset( $args['expected_revision'] ) ? (int) $args['expected_revision'] : null;
						$check    = \SdAiAgent\Core\RevisionGuard::check( $pid, $expected );
						if ( \is_wp_error( $check ) ) {
							return $check;
						}
						// Simulate a write by updating the post with unique content.
						wp_update_post(
							[
								'ID'           => $pid,
								'post_content' => 'write-' . microtime( true ) . '-' . mt_rand(),
							]
						);
						// Force a revision even if content is considered unchanged
						// (WordPress deduplication can cause wp_update_post to skip
						// revision creation in some test-environment conditions).
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
						add_filter( 'wp_save_post_revision_check_for_changes', '__return_false' );
						$new_rev = wp_save_post_revision( $pid );
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
						remove_filter( 'wp_save_post_revision_check_for_changes', '__return_false' );

						// Use the forced revision when available; otherwise fall back to
						// the latest stored revision from wp_update_post.
						$new_revision_id = ( is_int( $new_rev ) && $new_rev > 0 )
							? $new_rev
							: \SdAiAgent\Core\RevisionGuard::current_revision_id( $pid );

						return [
							'updated'     => true,
							'revision_id' => $new_revision_id,
						];
					},
					'permission_callback' => static function () {
						return current_user_can( 'manage_options' );
					},
				]
			);

			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Tear down REST server and unregister mock abilities.
	 */
	public function tear_down(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		global $wp_rest_server;
		$wp_rest_server = null;

		if ( function_exists( 'wp_unregister_ability' ) ) {
			wp_unregister_ability( self::READ_ABILITY );
			wp_unregister_ability( self::WRITE_ABILITY );
		}

		parent::tear_down();
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Get the current revision ID for the test post.
	 *
	 * @return int|null
	 */
	private function current_revision_id(): ?int {
		return \SdAiAgent\Core\RevisionGuard::current_revision_id( $this->post_id );
	}

	/**
	 * Dispatch a POST to the MCP endpoint.
	 *
	 * @param array<string, mixed> $params     MCP JSON params.
	 * @param string               $if_match   Optional If-Match header value (empty = absent).
	 * @return WP_REST_Response|\WP_Error
	 */
	private function dispatch_mcp( array $params, string $if_match = '' ) {
		$request = new WP_REST_Request( 'POST', self::MCP_ROUTE );
		$request->set_body( wp_json_encode( $params ) );
		$request->set_header( 'Content-Type', 'application/json' );

		if ( '' !== $if_match ) {
			$request->set_header( 'If-Match', $if_match );
		}

		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatch a call_tool MCP request for the read ability.
	 *
	 * @param string $if_match Optional If-Match header.
	 * @return WP_REST_Response|\WP_Error
	 */
	private function dispatch_read( string $if_match = '' ) {
		return $this->dispatch_mcp(
			[
				'method' => 'call_tool',
				'params' => [
					'name'      => self::READ_TOOL,
					'arguments' => [ 'post_id' => $this->post_id ],
				],
			],
			$if_match
		);
	}

	/**
	 * Dispatch a call_tool MCP request for the write ability.
	 *
	 * @param array<string, mixed> $extra_args  Extra arguments merged into the call.
	 * @param string               $if_match    Optional If-Match header.
	 * @return WP_REST_Response|\WP_Error
	 */
	private function dispatch_write( array $extra_args = [], string $if_match = '' ) {
		$args = array_merge( [ 'post_id' => $this->post_id ], $extra_args );
		return $this->dispatch_mcp(
			[
				'method' => 'call_tool',
				'params' => [
					'name'      => self::WRITE_TOOL,
					'arguments' => $args,
				],
			],
			$if_match
		);
	}

	/**
	 * Assert an HTTP response has the expected status code.
	 *
	 * @param int                            $expected Expected status.
	 * @param WP_REST_Response|\WP_Error $response Response to inspect.
	 */
	private function assertStatus( int $expected, $response ): void {
		if ( is_wp_error( $response ) ) {
			$data   = $response->get_error_data();
			$status = is_array( $data ) ? ( $data['status'] ?? 0 ) : 0;
		} else {
			$status = $response->get_status();
		}
		$this->assertSame( $expected, $status, "Expected HTTP {$expected}, got {$status}." );
	}

	// ── IfMatchHeader::parse() unit tests ─────────────────────────────────────

	/**
	 * Empty string returns null (no precondition).
	 */
	public function test_parse_empty_returns_null(): void {
		$this->assertNull( IfMatchHeader::parse( '' ) );
		$this->assertNull( IfMatchHeader::parse( '   ' ) );
	}

	/**
	 * Wildcard `*` returns the WILDCARD sentinel.
	 */
	public function test_parse_wildcard_returns_sentinel(): void {
		$result = IfMatchHeader::parse( '*' );
		$this->assertSame( IfMatchHeader::WILDCARD, $result );
	}

	/**
	 * Canonical `W/"rev-N"` form is accepted and unwrapped to N.
	 */
	public function test_parse_weak_etag_with_rev_prefix(): void {
		$this->assertSame( 218, IfMatchHeader::parse( 'W/"rev-218"' ) );
		$this->assertSame( 0, IfMatchHeader::parse( 'W/"rev-0"' ) );
		$this->assertSame( 1, IfMatchHeader::parse( 'W/"rev-1"' ) );
	}

	/**
	 * Strong ETag `"rev-N"` is also accepted (lenient compare per RFC 7232 guidance).
	 */
	public function test_parse_strong_etag_with_rev_prefix(): void {
		$this->assertSame( 218, IfMatchHeader::parse( '"rev-218"' ) );
		$this->assertSame( 5, IfMatchHeader::parse( '"rev-5"' ) );
	}

	/**
	 * Bare integer inside quotes `W/"N"` is accepted for backward compatibility.
	 */
	public function test_parse_bare_integer_weak_etag(): void {
		$this->assertSame( 218, IfMatchHeader::parse( 'W/"218"' ) );
		$this->assertSame( 0, IfMatchHeader::parse( '"0"' ) );
	}

	/**
	 * Case-insensitive `W/` prefix is accepted.
	 */
	public function test_parse_case_insensitive_weak_prefix(): void {
		$this->assertSame( 218, IfMatchHeader::parse( 'w/"rev-218"' ) );
		$this->assertSame( 218, IfMatchHeader::parse( 'W/"rev-218"' ) );
	}

	/**
	 * Whitespace around the header value is trimmed.
	 */
	public function test_parse_trims_surrounding_whitespace(): void {
		$this->assertSame( 218, IfMatchHeader::parse( '  W/"rev-218"  ' ) );
	}

	/**
	 * Garbage text returns 412 WP_Error with code `invalid_if_match`.
	 */
	public function test_parse_garbage_returns_invalid_if_match_error(): void {
		$result = IfMatchHeader::parse( 'not-a-revision' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_if_match', $result->get_error_code() );
		$this->assertSame( 412, $result->get_error_data()['status'] );
	}

	/**
	 * Decimal number is garbage → 412.
	 */
	public function test_parse_decimal_returns_error(): void {
		$result = IfMatchHeader::parse( 'W/"rev-1.5"' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_if_match', $result->get_error_code() );
	}

	/**
	 * Negative integer is garbage → 412.
	 */
	public function test_parse_negative_returns_error(): void {
		$result = IfMatchHeader::parse( 'W/"rev--5"' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_if_match', $result->get_error_code() );
	}

	// ── IfMatchHeader::format() unit tests ────────────────────────────────────

	/**
	 * format() returns the canonical `W/"rev-N"` form.
	 */
	public function test_format_produces_canonical_etag(): void {
		$this->assertSame( 'W/"rev-218"', IfMatchHeader::format( 218 ) );
		$this->assertSame( 'W/"rev-0"', IfMatchHeader::format( 0 ) );
		$this->assertSame( 'W/"rev-1"', IfMatchHeader::format( 1 ) );
	}

	/**
	 * format() with null revision_id (post with no revisions) → `W/"rev-0"`.
	 */
	public function test_format_null_revision_produces_rev_zero(): void {
		$this->assertSame( 'W/"rev-0"', IfMatchHeader::format( null ) );
	}

	/**
	 * Round-trip: parse(format(N)) === N.
	 */
	public function test_parse_format_round_trip(): void {
		foreach ( [ 0, 1, 218, 99999 ] as $rev ) {
			$etag   = IfMatchHeader::format( $rev );
			$parsed = IfMatchHeader::parse( $etag );
			$this->assertSame( $rev, $parsed, "Round-trip failed for revision {$rev}" );
		}
	}

	// ── MCP integration: ETag on block-read response ──────────────────────────

	/**
	 * A call_tool request to the mock read ability returns an ETag header
	 * whose value matches the current revision of the test post.
	 */
	public function test_etag_present_on_read_response(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$current_rev = $this->current_revision_id();
		$response    = $this->dispatch_read();

		$this->assertStatus( 200, $response );

		/** @var WP_REST_Response $response */
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'ETag', $headers, 'Response must include ETag header.' );
		$this->assertSame(
			IfMatchHeader::format( $current_rev ),
			$headers['ETag'],
			'ETag must match the current revision.'
		);
	}

	// ── MCP integration: missing If-Match header is a no-op ──────────────────

	/**
	 * A write call without any If-Match header succeeds (backward compat).
	 */
	public function test_missing_if_match_header_is_noop(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$response = $this->dispatch_write();
		$this->assertStatus( 200, $response );
	}

	// ── MCP integration: If-Match match → 200 ────────────────────────────────

	/**
	 * A write call with If-Match matching the current revision succeeds.
	 */
	public function test_if_match_matching_revision_returns_200(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$current_rev = $this->current_revision_id();
		$response    = $this->dispatch_write(
			[],
			IfMatchHeader::format( $current_rev )
		);

		$this->assertStatus( 200, $response );
	}

	// ── MCP integration: If-Match mismatch → 412 ─────────────────────────────

	/**
	 * A write call with a stale If-Match returns 412.
	 */
	public function test_if_match_stale_revision_returns_412(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		// Use revision ID 1, which is guaranteed to be stale.
		$response = $this->dispatch_write( [], 'W/"rev-1"' );

		$this->assertStatus( 412, $response );

		/** @var WP_REST_Response $response */
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'stale_revision', $data['code'] ?? '' );
	}

	// ── MCP integration: unparseable If-Match → 412 invalid_if_match ─────────

	/**
	 * An unparseable If-Match header returns 412 invalid_if_match.
	 */
	public function test_unparseable_if_match_returns_412_invalid_if_match(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$response = $this->dispatch_write( [], 'garbage-not-a-revision' );

		$this->assertStatus( 412, $response );

		/** @var WP_REST_Response $response */
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'invalid_if_match', $data['code'] ?? '' );
	}

	// ── MCP integration: wildcard `*` short-circuits revision check ───────────

	/**
	 * `If-Match: *` skips the revision pre-flight check and the write succeeds.
	 */
	public function test_if_match_wildcard_skips_revision_check(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$response = $this->dispatch_write( [], '*' );
		$this->assertStatus( 200, $response );
	}

	// ── MCP integration: body + header both present and agreeing → 200 ────────

	/**
	 * When If-Match header and body expected_revision agree, the call succeeds.
	 */
	public function test_header_and_body_agreeing_returns_200(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$current_rev = $this->current_revision_id();
		$response    = $this->dispatch_write(
			[ 'expected_revision' => $current_rev ],
			IfMatchHeader::format( $current_rev )
		);

		$this->assertStatus( 200, $response );
	}

	// ── MCP integration: body + header both present and disagreeing → 412 ─────

	/**
	 * When If-Match header and body expected_revision disagree, return 412
	 * conflicting_revision_preconditions — before touching the ability layer.
	 */
	public function test_header_and_body_disagreeing_returns_412_conflicting(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$current_rev = $this->current_revision_id();

		$response = $this->dispatch_write(
			// Body says current, header says stale.
			[ 'expected_revision' => $current_rev ],
			'W/"rev-1"'  // stale
		);

		$this->assertStatus( 412, $response );

		/** @var WP_REST_Response $response */
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'conflicting_revision_preconditions', $data['code'] ?? '' );
	}

	// ── MCP integration: ETag advances after successful write ─────────────────

	/**
	 * After a successful write, the response ETag reflects the NEW revision ID
	 * (i.e. it is different from and higher than the pre-write ETag).
	 */
	public function test_response_etag_advances_after_write(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		// Capture revision before write.
		$pre_write_rev = $this->current_revision_id();

		// Write with the current revision as If-Match.
		$response = $this->dispatch_write(
			[],
			IfMatchHeader::format( $pre_write_rev )
		);

		$this->assertStatus( 200, $response );

		/** @var WP_REST_Response $response */
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'ETag', $headers, 'Write response must include ETag.' );

		// The ETag after write must differ from (be higher than) the pre-write one.
		$post_write_rev = $this->current_revision_id();
		$this->assertSame(
			IfMatchHeader::format( $post_write_rev ),
			$headers['ETag'],
			'Post-write ETag must reflect the new revision ID.'
		);
		$this->assertNotSame(
			IfMatchHeader::format( $pre_write_rev ),
			$headers['ETag'],
			'Post-write ETag must differ from pre-write ETag.'
		);
	}

	// ── RestController::add_etag_header() integration ─────────────────────────

	/**
	 * The rest_post_dispatch filter in RestController adds an ETag header to
	 * any WP_REST_Response in the sd-ai-agent/v1 namespace whose body data
	 * has a top-level `revision_id` key.
	 *
	 * This is exercised by dispatching through the server, which triggers all
	 * registered rest_post_dispatch hooks (including add_etag_header at priority 15).
	 * The MCP call_tool response itself also carries an ETag (set by McpController),
	 * so the filter's de-duplication guard prevents it from overwriting.
	 *
	 * We test the filter in isolation by constructing a mock WP_REST_Response
	 * with a top-level revision_id key and calling add_etag_header directly.
	 */
	public function test_rest_controller_etag_filter_adds_etag_for_revision_id_response(): void {
		$response = new WP_REST_Response( [ 'revision_id' => 218, 'blocks' => [] ], 200 );

		$filtered = \SdAiAgent\REST\RestController::add_etag_header( $response );

		$this->assertInstanceOf( WP_REST_Response::class, $filtered );
		$headers = $filtered->get_headers();
		$this->assertArrayHasKey( 'ETag', $headers );
		$this->assertSame( 'W/"rev-218"', $headers['ETag'] );
	}

	/**
	 * The filter does NOT overwrite an ETag that is already present.
	 */
	public function test_rest_controller_etag_filter_does_not_overwrite_existing_etag(): void {
		$response = new WP_REST_Response( [ 'revision_id' => 218 ], 200 );
		$response->header( 'ETag', 'W/"rev-999"' );

		$filtered = \SdAiAgent\REST\RestController::add_etag_header( $response );

		$headers = $filtered->get_headers();
		$this->assertSame( 'W/"rev-999"', $headers['ETag'], 'Existing ETag must not be overwritten.' );
	}

	/**
	 * The filter skips non-2xx responses.
	 */
	public function test_rest_controller_etag_filter_skips_error_responses(): void {
		$response = new WP_REST_Response( [ 'revision_id' => 218 ], 404 );

		$filtered = \SdAiAgent\REST\RestController::add_etag_header( $response );

		$headers = $filtered->get_headers();
		$this->assertArrayNotHasKey( 'ETag', $headers, 'Error response must not get ETag.' );
	}

	/**
	 * The filter is a no-op for responses without revision_id.
	 */
	public function test_rest_controller_etag_filter_skips_responses_without_revision_id(): void {
		$response = new WP_REST_Response( [ 'foo' => 'bar' ], 200 );

		$filtered = \SdAiAgent\REST\RestController::add_etag_header( $response );

		$headers = $filtered->get_headers();
		$this->assertArrayNotHasKey( 'ETag', $headers, 'Response without revision_id must not get ETag.' );
	}
}
