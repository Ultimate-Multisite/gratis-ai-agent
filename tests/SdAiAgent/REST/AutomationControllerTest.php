<?php

declare(strict_types=1);

/**
 * REST integration tests for the Monitor/Pulse draft check route.
 *
 * @package SdAiAgent
 * @subpackage Tests\REST
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\REST;

use SdAiAgent\Automations\AutomationRunner;
use SdAiAgent\Automations\Automations;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

final class AutomationControllerTest extends WP_UnitTestCase {

	/** @var WP_REST_Server REST server used to dispatch controller routes. */
	private WP_REST_Server $server;

	/** @var int Administrator permitted to invoke the protected route. */
	private int $admin_id;

	/** @var int Subscriber used to verify the permission callback. */
	private int $subscriber_id;

	/** Register REST routes and create users for each request-level test. */
	public function set_up(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress REST test global.
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Standard WordPress REST hook.
		do_action( 'rest_api_init' );
		parent::set_up();

		$this->admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	/** Clear registered test state after each test. */
	public function tear_down(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress REST test global.
		global $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/** Create a disabled Monitor whose empty checklist avoids provider work. */
	private function create_disabled_monitor(): int {
		$monitor_id = Automations::create(
			[
				'name'            => 'REST Monitor Draft',
				'prompt'          => 'Assess the current site state.',
				'mode'            => Automations::MONITOR_MODE,
				'monitor_scratch' => '',
				'owner_user_id'   => $this->admin_id,
				'enabled'         => 0,
			]
		);

		$this->assertIsInt( $monitor_id );
		$this->assertGreaterThan( 0, $monitor_id );

		return $monitor_id;
	}

	/** Dispatch a JSON REST request through the registered controller routes. */
	private function dispatch( string $method, string $route, array $params = [] ): WP_REST_Response|WP_Error {
		$request = new WP_REST_Request( $method, $route );
		$request->set_body( (string) wp_json_encode( $params ) );
		$request->set_header( 'Content-Type', 'application/json' );

		return $this->server->dispatch( $request );
	}

	/** Assert a response or error uses the expected REST status. */
	private function assert_status( int $expected, WP_REST_Response|WP_Error $response ): void {
		if ( is_wp_error( $response ) ) {
			$data   = $response->get_error_data();
			$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
		} else {
			$status = $response->get_status();
		}

		$this->assertSame( $expected, $status );
	}

	/** A permitted Check now call runs one disabled Monitor draft without scheduling it. */
	public function test_manual_monitor_draft_check_preserves_disabled_state_and_no_schedule(): void {
		wp_set_current_user( $this->admin_id );
		$monitor_id = $this->create_disabled_monitor();

		$this->assertFalse( wp_next_scheduled( AutomationRunner::CRON_HOOK, [ $monitor_id ] ) );
		$response = $this->dispatch(
			'POST',
			"/sd-ai-agent/v1/automations/{$monitor_id}/run",
			[ 'manual_monitor_draft' => true ]
		);

		$this->assert_status( 200, $response );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'succeeded', $response->get_data()['lifecycle_status'] );
		$this->assertSame( 'quiet', $response->get_data()['monitor_outcome'] );

		$monitor = Automations::get( $monitor_id );
		$this->assertNotNull( $monitor );
		$this->assertFalse( $monitor['enabled'] );
		$this->assertSame( 'quiet', $monitor['last_monitor_outcome'] );
		$this->assertFalse( wp_next_scheduled( AutomationRunner::CRON_HOOK, [ $monitor_id ] ) );
	}

	/** The protected route rejects a user without automation-management capability. */
	public function test_manual_monitor_draft_check_requires_administrator(): void {
		wp_set_current_user( $this->admin_id );
		$monitor_id = $this->create_disabled_monitor();
		wp_set_current_user( $this->subscriber_id );

		$response = $this->dispatch(
			'POST',
			"/sd-ai-agent/v1/automations/{$monitor_id}/run",
			[ 'manual_monitor_draft' => true ]
		);

		$this->assert_status( 403, $response );
		$this->assertFalse( wp_next_scheduled( AutomationRunner::CRON_HOOK, [ $monitor_id ] ) );
		$this->assertFalse( Automations::get( $monitor_id )['enabled'] );
	}

	/** The manual flag cannot turn the existing scheduled-task path into a draft runner. */
	public function test_manual_monitor_draft_check_rejects_scheduled_task(): void {
		wp_set_current_user( $this->admin_id );
		$task_id = Automations::create(
			[
				'name'          => 'Scheduled task',
				'prompt'        => 'This must remain blocked.',
				'owner_user_id' => $this->admin_id,
				'enabled'       => 0,
			]
		);
		$this->assertIsInt( $task_id );

		$response = $this->dispatch(
			'POST',
			"/sd-ai-agent/v1/automations/{$task_id}/run",
			[ 'manual_monitor_draft' => true ]
		);

		$this->assert_status( 400, $response );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame(
			'sd_ai_agent_automation_manual_draft_requires_disabled_monitor',
			$response->get_data()['code'] ?? ''
		);
	}

	/** The source endpoint exposes only fixed presentation-safe descriptors. */
	public function test_monitor_wake_sources_are_available_to_administrators(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/monitor-wake-sources' );

		$this->assert_status( 200, $response );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$sources = $response->get_data();
		$this->assertIsArray( $sources );
		$this->assertSame(
			[
				'transition_post_status',
				'delete_post',
				'activated_plugin',
				'deactivated_plugin',
				'switch_theme',
				'add_attachment',
			],
			array_column( $sources, 'hook_name' )
		);
		$this->assertSame( [ 'post_id' ], $sources[1]['args'] );
		$this->assertArrayNotHasKey( 'plugin', $sources[2] );
	}
}
