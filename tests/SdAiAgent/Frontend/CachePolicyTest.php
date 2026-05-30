<?php
/**
 * Test case for frontend reflector cache policy headers.
 *
 * @package SdAiAgent
 * @subpackage Tests\Frontend
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Frontend;

use SdAiAgent\Frontend\CachePolicy;
use WP_UnitTestCase;

/**
 * @covers \SdAiAgent\Frontend\CachePolicy
 */
class CachePolicyTest extends WP_UnitTestCase {

	/**
	 * Preserve server globals changed by the test.
	 *
	 * @var mixed
	 */
	private $previous_header;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_header = $_SERVER[ CachePolicy::REFLECTOR_REQUEST_HEADER ] ?? null;
		unset( $_SERVER[ CachePolicy::REFLECTOR_REQUEST_HEADER ] );
	}

	protected function tearDown(): void {
		if ( null === $this->previous_header ) {
			unset( $_SERVER[ CachePolicy::REFLECTOR_REQUEST_HEADER ] );
		} else {
			$_SERVER[ CachePolicy::REFLECTOR_REQUEST_HEADER ] = $this->previous_header;
		}

		parent::tearDown();
	}

	public function test_reflector_request_requires_custom_header(): void {
		$this->assertFalse( CachePolicy::is_reflector_request() );

		$_SERVER[ CachePolicy::REFLECTOR_REQUEST_HEADER ] = '1';

		$this->assertTrue( CachePolicy::is_reflector_request() );
	}

	public function test_reflector_headers_include_no_store_policy_and_diagnostic_header(): void {
		$headers = CachePolicy::headers();

		$this->assertSame( 'no-store, no-cache, must-revalidate', $headers['Cache-Control'] );
		$this->assertSame( 'no-cache', $headers['Pragma'] );
		$this->assertSame( '0', $headers['Expires'] );
		$this->assertSame( 'no-store', $headers[ CachePolicy::RESPONSE_HEADER ] );
	}
}
