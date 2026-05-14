<?php
/**
 * Test case for GeminiCacheManager.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core\PromptCache;

use SdAiAgent\Core\PromptCache\GeminiCacheManager;
use WP_UnitTestCase;

/**
 * @covers \SdAiAgent\Core\PromptCache\GeminiCacheManager
 */
class GeminiCacheManagerTest extends WP_UnitTestCase {

	private GeminiCacheManager $manager;

	/** Minimal stable-prefix fixture */
	private array $contents;
	private array $tools;

	protected function setUp(): void {
		parent::setUp();
		$this->manager  = new GeminiCacheManager();
		$this->contents = array(
			array(
				'role'  => 'user',
				'parts' => array( array( 'text' => 'Hello, I need help with a complex task.' ) ),
			),
			array(
				'role'  => 'model',
				'parts' => array( array( 'text' => 'Sure, I can help with that.' ) ),
			),
		);
		$this->tools    = array(
			array(
				'function_declarations' => array(
					array( 'name' => 'search', 'description' => 'Search the web.' ),
				),
			),
		);
	}

	// -------------------------------------------------------------------------
	// build_hash
	// -------------------------------------------------------------------------

	public function test_build_hash_returns_32_char_hex_string(): void {
		$hash = $this->manager->build_hash( 'gemini-2.5-pro', $this->contents, $this->tools, 'You are helpful.' );

		$this->assertSame( 32, strlen( $hash ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $hash );
	}

	public function test_build_hash_is_deterministic(): void {
		$a = $this->manager->build_hash( 'gemini-2.5-pro', $this->contents, $this->tools, 'system' );
		$b = $this->manager->build_hash( 'gemini-2.5-pro', $this->contents, $this->tools, 'system' );

		$this->assertSame( $a, $b );
	}

	public function test_build_hash_differs_when_model_changes(): void {
		$a = $this->manager->build_hash( 'gemini-2.5-pro', $this->contents, $this->tools, 'system' );
		$b = $this->manager->build_hash( 'gemini-1.5-pro', $this->contents, $this->tools, 'system' );

		$this->assertNotSame( $a, $b );
	}

	public function test_build_hash_differs_when_system_changes(): void {
		$a = $this->manager->build_hash( 'model', $this->contents, $this->tools, 'System A.' );
		$b = $this->manager->build_hash( 'model', $this->contents, $this->tools, 'System B.' );

		$this->assertNotSame( $a, $b );
	}

	public function test_build_hash_differs_when_tools_change(): void {
		$a = $this->manager->build_hash( 'model', $this->contents, $this->tools, 'system' );
		$b = $this->manager->build_hash( 'model', $this->contents, array(), 'system' );

		$this->assertNotSame( $a, $b );
	}

	// -------------------------------------------------------------------------
	// find_or_create — empty API key
	// -------------------------------------------------------------------------

	public function test_returns_null_on_empty_api_key(): void {
		$result = $this->manager->find_or_create( '', 'gemini-2.5-pro', $this->contents, $this->tools, 'system' );

		$this->assertNull( $result );
	}

	// -------------------------------------------------------------------------
	// find_or_create — transient hit
	// -------------------------------------------------------------------------

	public function test_returns_cached_name_when_transient_is_set(): void {
		$hash = $this->manager->build_hash( 'gemini-2.5-pro', $this->contents, $this->tools, 'system' );
		set_transient(
			'sd_ai_agent_gemini_cache_' . $hash,
			array( 'name' => 'cachedContents/hit123', 'hash' => $hash ),
			3300
		);

		// No HTTP request should be made — the transient is the source of truth.
		$made_request = false;
		add_filter(
			'pre_http_request',
			static function ( $preempt ) use ( &$made_request ) {
				$made_request = true;
				return $preempt;
			}
		);

		$result = $this->manager->find_or_create( 'test-key', 'gemini-2.5-pro', $this->contents, $this->tools, 'system' );

		$this->assertFalse( $made_request, 'Expected no HTTP request on cache hit.' );
		$this->assertSame( 'cachedContents/hit123', $result );
	}

	// -------------------------------------------------------------------------
	// find_or_create — miss → API call
	// -------------------------------------------------------------------------

	public function test_creates_resource_via_api_on_cache_miss(): void {
		$api_response = array(
			'name'       => 'cachedContents/new456',
			'model'      => 'models/gemini-2.5-pro',
			'createTime' => '2026-05-14T00:00:00Z',
			'expireTime' => '2026-05-14T01:00:00Z',
		);

		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( $api_response ) {
				if ( str_contains( $url, 'cachedContents' ) ) {
					return array(
						'response' => array( 'code' => 200, 'message' => 'OK' ),
						'body'     => wp_json_encode( $api_response ),
						'headers'  => array(),
						'cookies'  => array(),
						'filename' => null,
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->manager->find_or_create( 'test-key', 'gemini-2.5-pro', $this->contents, $this->tools, 'You are helpful.' );

		$this->assertSame( 'cachedContents/new456', $result );

		// Subsequent call must hit transient, not API.
		$api_call_count = 0;
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$api_call_count, $api_response ) {
				if ( str_contains( $url, 'cachedContents' ) ) {
					++$api_call_count;
				}
				return $preempt;
			},
			20,
			3
		);

		$result2 = $this->manager->find_or_create( 'test-key', 'gemini-2.5-pro', $this->contents, $this->tools, 'You are helpful.' );

		$this->assertSame( 'cachedContents/new456', $result2 );
		$this->assertSame( 0, $api_call_count, 'Second call must use transient, not API.' );
	}

	// -------------------------------------------------------------------------
	// find_or_create — API failure
	// -------------------------------------------------------------------------

	public function test_returns_null_when_api_returns_error_status(): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) {
				if ( str_contains( $url, 'cachedContents' ) ) {
					return array(
						'response' => array( 'code' => 400, 'message' => 'Bad Request' ),
						'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Invalid argument.' ) ) ),
						'headers'  => array(),
						'cookies'  => array(),
						'filename' => null,
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->manager->find_or_create( 'test-key', 'gemini-2.5-pro', $this->contents, $this->tools, 'system' );

		$this->assertNull( $result );
	}

	public function test_returns_null_when_api_returns_wp_error(): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) {
				if ( str_contains( $url, 'cachedContents' ) ) {
					return new \WP_Error( 'http_request_failed', 'Connection timed out.' );
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->manager->find_or_create( 'test-key', 'gemini-2.5-pro', $this->contents, $this->tools, 'system' );

		$this->assertNull( $result );
	}

	public function test_returns_null_when_api_response_body_missing_name(): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) {
				if ( str_contains( $url, 'cachedContents' ) ) {
					return array(
						'response' => array( 'code' => 200, 'message' => 'OK' ),
						'body'     => wp_json_encode( array( 'model' => 'models/gemini-2.5-pro' ) ),
						'headers'  => array(),
						'cookies'  => array(),
						'filename' => null,
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->manager->find_or_create( 'test-key', 'gemini-2.5-pro', $this->contents, $this->tools, 'system' );

		$this->assertNull( $result );
	}

	// -------------------------------------------------------------------------
	// invalidate
	// -------------------------------------------------------------------------

	public function test_invalidate_removes_transient(): void {
		$hash = $this->manager->build_hash( 'gemini-2.5-pro', $this->contents, $this->tools, 'system' );
		set_transient(
			'sd_ai_agent_gemini_cache_' . $hash,
			array( 'name' => 'cachedContents/old789', 'hash' => $hash ),
			3300
		);

		$this->manager->invalidate( $hash );

		$stored = get_transient( 'sd_ai_agent_gemini_cache_' . $hash );
		$this->assertFalse( $stored, 'Transient should be deleted after invalidation.' );
	}

	public function test_invalidate_allows_re_creation_after_expiry(): void {
		$hash = $this->manager->build_hash( 'gemini-2.5-pro', $this->contents, $this->tools, 'system' );
		set_transient(
			'sd_ai_agent_gemini_cache_' . $hash,
			array( 'name' => 'cachedContents/expired', 'hash' => $hash ),
			3300
		);

		// Simulate server-side expiry by invalidating our reference.
		$this->manager->invalidate( $hash );

		// Next call should hit the API with a fresh resource name.
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) {
				if ( str_contains( $url, 'cachedContents' ) ) {
					return array(
						'response' => array( 'code' => 200, 'message' => 'OK' ),
						'body'     => wp_json_encode( array( 'name' => 'cachedContents/refreshed' ) ),
						'headers'  => array(),
						'cookies'  => array(),
						'filename' => null,
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->manager->find_or_create( 'test-key', 'gemini-2.5-pro', $this->contents, $this->tools, 'system' );

		$this->assertSame( 'cachedContents/refreshed', $result );
	}

	// -------------------------------------------------------------------------
	// Race-condition lock
	// -------------------------------------------------------------------------

	public function test_concurrent_miss_without_lock_does_not_double_create(): void {
		// Simulate the lock being already held (another request is creating
		// the resource). The manager should fall back to checking the
		// transient after waiting — but since no transient is written (the
		// "other request" hasn't finished), we expect null.
		$hash = $this->manager->build_hash( 'gemini-2.5-pro', $this->contents, $this->tools, 'system' );
		wp_cache_add( $hash, 1, 'sd_ai_agent_gemini_cache_lock', 30 );

		$api_call_count = 0;
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$api_call_count ) {
				if ( str_contains( $url, 'cachedContents' ) ) {
					++$api_call_count;
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->manager->find_or_create( 'test-key', 'gemini-2.5-pro', $this->contents, $this->tools, 'system' );

		// No API call because the lock prevented resource creation.
		$this->assertSame( 0, $api_call_count, 'API must not be called when lock is held by another request.' );
		// Result is null because the "winning" request hasn't written the transient.
		$this->assertNull( $result );

		// Clean up lock.
		wp_cache_delete( $hash, 'sd_ai_agent_gemini_cache_lock' );
	}
}
