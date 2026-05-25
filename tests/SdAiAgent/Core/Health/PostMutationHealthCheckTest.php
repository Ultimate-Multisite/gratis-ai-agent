<?php

declare(strict_types=1);
/**
 * Tests for PostMutationHealthCheck service.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core\Health;

use SdAiAgent\Core\Health\PostMutationHealthCheck;
use WP_Error;
use WP_UnitTestCase;

/**
 * Test PostMutationHealthCheck.
 *
 * @group health-check
 * @group post-mutation
 */
class PostMutationHealthCheckTest extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		// Clear any cached health URL.
		delete_transient( 'sd_ai_agent_health_url' );

		// Remove any filters added by tests.
		remove_all_filters( 'sd_ai_agent_skip_health_check' );
		remove_all_filters( 'sd_ai_agent_health_url' );
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down(): void {
		parent::tear_down();

		delete_transient( 'sd_ai_agent_health_url' );
		remove_all_filters( 'sd_ai_agent_skip_health_check' );
		remove_all_filters( 'sd_ai_agent_health_url' );
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test verify() returns true on a healthy site.
	 */
	public function test_verify_returns_true_on_healthy_site(): void {
		// Mock a successful health check response.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( str_contains( $url, 'action=sd_ai_agent_health' ) ) {
					return [
						'headers'       => [ 'content-type' => 'application/json' ],
						'body'          => '{"success":true}',
						'response'      => [ 'code' => 200 ],
						'cookies'       => [],
						'http_response' => null,
					];
				}
				return $preempt;
			},
			10,
			3
		);

		$health_check = new PostMutationHealthCheck();
		$this->assertTrue( $health_check->verify() );
	}

	/**
	 * Test verify() returns false on a broken site.
	 */
	public function test_verify_returns_false_on_broken_site(): void {
		// Mock a failed health check response.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( str_contains( $url, 'action=sd_ai_agent_health' ) ) {
					return [
						'headers'       => [ 'content-type' => 'application/json' ],
						'body'          => '{"error":"Fatal error"}',
						'response'      => [ 'code' => 500 ],
						'cookies'       => [],
						'http_response' => null,
					];
				}
				return $preempt;
			},
			10,
			3
		);

		$health_check = new PostMutationHealthCheck();
		$this->assertFalse( $health_check->verify() );
	}

	/**
	 * Test verify_or_revert() returns null on healthy site.
	 */
	public function test_verify_or_revert_returns_null_on_healthy(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( str_contains( $url, 'action=sd_ai_agent_health' ) ) {
					return [
						'headers'       => [ 'content-type' => 'application/json' ],
						'body'          => '{"success":true}',
						'response'      => [ 'code' => 200 ],
						'cookies'       => [],
						'http_response' => null,
					];
				}
				return $preempt;
			},
			10,
			3
		);

		$undo_called = false;
		$undo        = function () use ( &$undo_called ) {
			$undo_called = true;
			return true;
		};

		$health_check = new PostMutationHealthCheck();
		$result       = $health_check->verify_or_revert( $undo, 'Test operation' );

		$this->assertNull( $result );
		$this->assertFalse( $undo_called, 'Undo should not be called when site is healthy' );
	}

	/**
	 * Test verify_or_revert() calls undo on broken site.
	 */
	public function test_verify_or_revert_calls_undo_on_broken(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( str_contains( $url, 'action=sd_ai_agent_health' ) ) {
					return [
						'headers'       => [ 'content-type' => 'application/json' ],
						'body'          => '{"error":"Fatal error"}',
						'response'      => [ 'code' => 500 ],
						'cookies'       => [],
						'http_response' => null,
					];
				}
				return $preempt;
			},
			10,
			3
		);

		$undo_called = false;
		$undo        = function () use ( &$undo_called ) {
			$undo_called = true;
			return true;
		};

		$health_check = new PostMutationHealthCheck();
		$result       = $health_check->verify_or_revert( $undo, 'Test operation' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertTrue( $undo_called, 'Undo should be called when site is broken' );
		$this->assertStringContainsString( 'site_unhealthy', $result->get_error_code() );
		$this->assertStringContainsString( 'Test operation', $result->get_error_message() );
	}

	/**
	 * Test verify_or_revert() returns null on unreachable loopback.
	 */
	public function test_verify_or_revert_returns_null_on_unreachable(): void {
		// Mock an unreachable loopback.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( str_contains( $url, 'action=sd_ai_agent_health' ) ) {
					return new WP_Error( 'connection_failed', 'Could not connect' );
				}
				return $preempt;
			},
			10,
			3
		);

		$undo_called = false;
		$undo        = function () use ( &$undo_called ) {
			$undo_called = true;
			return true;
		};

		$health_check = new PostMutationHealthCheck();
		$result       = $health_check->verify_or_revert( $undo, 'Test operation' );

		$this->assertNull( $result );
		$this->assertFalse( $undo_called, 'Undo should not be called when loopback is unreachable' );
	}

	/**
	 * Test verify_or_revert() returns WP_Error when undo fails.
	 */
	public function test_verify_or_revert_includes_undo_error(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( str_contains( $url, 'action=sd_ai_agent_health' ) ) {
					return [
						'headers'       => [ 'content-type' => 'application/json' ],
						'body'          => '{"error":"Fatal error"}',
						'response'      => [ 'code' => 500 ],
						'cookies'       => [],
						'http_response' => null,
					];
				}
				return $preempt;
			},
			10,
			3
		);

		$undo = function () {
			return new WP_Error( 'restore_failed', 'Could not restore file' );
		};

		$health_check = new PostMutationHealthCheck();
		$result       = $health_check->verify_or_revert( $undo, 'Test operation' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'Restore also failed', $result->get_error_message() );
		$this->assertStringContainsString( 'Could not restore file', $result->get_error_message() );
	}

	/**
	 * Test verify_or_warn() returns null on healthy site.
	 */
	public function test_verify_or_warn_returns_null_on_healthy(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( str_contains( $url, 'action=sd_ai_agent_health' ) ) {
					return [
						'headers'       => [ 'content-type' => 'application/json' ],
						'body'          => '{"success":true}',
						'response'      => [ 'code' => 200 ],
						'cookies'       => [],
						'http_response' => null,
					];
				}
				return $preempt;
			},
			10,
			3
		);

		$health_check = new PostMutationHealthCheck();
		$result       = $health_check->verify_or_warn( 'Test operation' );

		$this->assertNull( $result );
	}

	/**
	 * Test verify_or_warn() returns WP_Error on broken site.
	 */
	public function test_verify_or_warn_returns_error_on_broken(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( str_contains( $url, 'action=sd_ai_agent_health' ) ) {
					return [
						'headers'       => [ 'content-type' => 'application/json' ],
						'body'          => '{"error":"Fatal error"}',
						'response'      => [ 'code' => 500 ],
						'cookies'       => [],
						'http_response' => null,
					];
				}
				return $preempt;
			},
			10,
			3
		);

		$health_check = new PostMutationHealthCheck();
		$result       = $health_check->verify_or_warn( 'Test operation' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'site_unhealthy', $result->get_error_code() );
		$this->assertStringContainsString( 'no automatic revert', $result->get_error_message() );
	}

	/**
	 * Test verify_or_warn() returns null on unreachable loopback.
	 */
	public function test_verify_or_warn_returns_null_on_unreachable(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( str_contains( $url, 'action=sd_ai_agent_health' ) ) {
					return new WP_Error( 'connection_failed', 'Could not connect' );
				}
				return $preempt;
			},
			10,
			3
		);

		$health_check = new PostMutationHealthCheck();
		$result       = $health_check->verify_or_warn( 'Test operation' );

		$this->assertNull( $result );
	}

	/**
	 * Test sd_ai_agent_skip_health_check filter short-circuits to healthy.
	 */
	public function test_skip_health_check_filter(): void {
		add_filter( 'sd_ai_agent_skip_health_check', '__return_true' );

		// Even with a broken loopback, the filter should make it return healthy.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( str_contains( $url, 'action=sd_ai_agent_health' ) ) {
					return [
						'headers'       => [ 'content-type' => 'application/json' ],
						'body'          => '{"error":"Fatal error"}',
						'response'      => [ 'code' => 500 ],
						'cookies'       => [],
						'http_response' => null,
					];
				}
				return $preempt;
			},
			10,
			3
		);

		$health_check = new PostMutationHealthCheck();
		$this->assertTrue( $health_check->verify() );
	}

	/**
	 * Test sd_ai_agent_health_url filter overrides URL discovery.
	 */
	public function test_health_url_filter_override(): void {
		$custom_url = 'http://custom-health-url.test';

		add_filter(
			'sd_ai_agent_health_url',
			function () use ( $custom_url ) {
				return $custom_url;
			}
		);

		$url_called = false;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $custom_url, &$url_called ) {
				if ( $url === $custom_url ) {
					$url_called = true;
					return [
						'headers'       => [ 'content-type' => 'application/json' ],
						'body'          => '{"success":true}',
						'response'      => [ 'code' => 200 ],
						'cookies'       => [],
						'http_response' => null,
					];
				}
				return $preempt;
			},
			10,
			3
		);

		$health_check = new PostMutationHealthCheck();
		$health_check->verify();

		$this->assertTrue( $url_called, 'Custom URL should be used' );
	}
}
