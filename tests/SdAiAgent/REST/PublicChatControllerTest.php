<?php

declare(strict_types=1);
/**
 * Tests for the public static-site chat endpoints.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\REST;

use SdAiAgent\Core\Settings;
use SdAiAgent\Models\ActiveJobRepository;
use SdAiAgent\REST\RestController;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Public chat endpoint tests.
 */
class PublicChatControllerTest extends WP_UnitTestCase {

	/** @var WP_REST_Server REST server instance. */
	protected WP_REST_Server $server;

	/**
	 * Set up REST server.
	 */
	public function set_up(): void {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		do_action( 'rest_api_init' );

		parent::set_up();
	}

	/**
	 * Tear down REST server.
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		delete_option( Settings::OPTION_NAME );

		parent::tear_down();
	}

	/**
	 * Dispatch a REST request.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route path.
	 * @param array  $params Request parameters.
	 * @param string $origin Origin header.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function dispatch( string $method, string $route, array $params = array(), string $origin = 'https://docs.example.test' ) {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Origin', $origin );
		if ( in_array( $method, array( 'POST', 'PATCH', 'PUT' ), true ) ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
		} else {
			$request->set_query_params( $params );
		}

		return $this->server->dispatch( $request );
	}

	/**
	 * Enable public chat for the allowed docs origin.
	 */
	private function enable_public_chat(): void {
		Settings::instance()->update(
			array(
				'public_chat_enabled'         => true,
				'public_chat_allowed_origins' => array( 'https://docs.example.test' ),
				'public_chat_embed_id'        => 'docs',
				'public_chat_collection'      => 'docs',
			)
		);
	}

	/**
	 * Routes are registered for public static embeds.
	 */
	public function test_public_chat_routes_are_registered(): void {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/sd-ai-agent/v1/public-chat/config', $routes );
		$this->assertArrayHasKey( '/sd-ai-agent/v1/public-chat/run', $routes );
		$this->assertArrayHasKey( '/sd-ai-agent/v1/public-chat/job/(?P<id>[a-f0-9-]+)', $routes );
	}

	/**
	 * Config reports disabled unless public chat and the origin allowlist both pass.
	 */
	public function test_config_fails_closed_for_disabled_or_disallowed_origin(): void {
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/public-chat/config' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['enabled'] );

		$this->enable_public_chat();
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/public-chat/config', array(), 'https://evil.example.test' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['enabled'] );
	}

	/**
	 * Disallowed origins cannot create public chat jobs.
	 */
	public function test_disallowed_origin_cannot_run_public_chat(): void {
		$this->enable_public_chat();

		$response = $this->dispatch(
			'POST',
			'/sd-ai-agent/v1/public-chat/run',
			array( 'message' => 'Hello' ),
			'https://evil.example.test'
		);

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Allowed origins create anonymous jobs with a separate polling token.
	 */
	public function test_allowed_origin_creates_public_job_without_user_or_cookies(): void {
		$this->enable_public_chat();

		$response = $this->dispatch(
			'POST',
			'/sd-ai-agent/v1/public-chat/run',
			array(
				'message'  => 'How do I install it?',
				'embed_id' => 'docs',
			)
		);

		$this->assertSame( 202, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'job_id', $data );
		$this->assertArrayHasKey( 'job_token', $data );
		$this->assertArrayNotHasKey( 'token', $data );
		$this->assertSame( 'https://docs.example.test', $response->get_headers()['Access-Control-Allow-Origin'] );

		$job = get_transient( RestController::JOB_PREFIX . $data['job_id'] );
		$this->assertIsArray( $job );
		$this->assertTrue( $job['public_mode'] );
		$this->assertSame( 0, $job['user_id'] );
		$this->assertSame( array(), $job['params']['abilities'] );

		ActiveJobRepository::delete( $data['job_id'] );
		delete_transient( RestController::JOB_PREFIX . $data['job_id'] );
	}

	/**
	 * Public job polling requires the browser polling token.
	 */
	public function test_public_job_polling_requires_public_token(): void {
		$this->enable_public_chat();
		$job_id = wp_generate_uuid4();
		set_transient(
			RestController::JOB_PREFIX . $job_id,
			array(
				'status'       => 'complete',
				'public_mode'  => true,
				'public_token' => 'public-token',
				'result'       => array( 'reply' => 'Use the docs.' ),
			),
			600
		);

		$response = $this->dispatch( 'GET', "/sd-ai-agent/v1/public-chat/job/{$job_id}", array( 'token' => 'wrong' ) );
		$this->assertSame( 403, $response->get_status() );

		$response = $this->dispatch( 'GET', "/sd-ai-agent/v1/public-chat/job/{$job_id}", array( 'token' => 'public-token' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'complete', $response->get_data()['status'] );
		$this->assertSame( 'Use the docs.', $response->get_data()['reply'] );
	}
}
