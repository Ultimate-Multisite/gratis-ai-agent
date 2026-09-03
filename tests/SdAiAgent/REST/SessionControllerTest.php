<?php

declare(strict_types=1);
/**
 * Integration tests for session-scoped durable plan REST endpoints.
 *
 * @package SdAiAgent
 * @subpackage Tests\REST
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\REST;

use SdAiAgent\Core\BackgroundJobDispatcher;
use SdAiAgent\Core\Database;
use SdAiAgent\Core\DurablePlanRunner;
use SdAiAgent\Models\ActiveJobRepository;
use SdAiAgent\Models\DurablePlanRepository;
use SdAiAgent\REST\RestController;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

class SessionControllerTest extends WP_UnitTestCase {

	protected WP_REST_Server $server;

	private int $admin_id;

	private int $other_admin_id;

	/** Jobs are queued once on the main site and preserve blog context. */
	public function test_background_dispatcher_queues_job_on_main_site_once(): void {
		$tenant_id = is_multisite() ? self::factory()->blog->create() : get_current_blog_id();
		$job_id    = '11111111-2222-3333-4444-555555555555';
		$args      = array( $tenant_id, $job_id );
		$requests  = 0;
		$intercept = static function ( $preempt ) use ( &$requests ) {
			++$requests;
			return $preempt;
		};
		add_filter( 'pre_http_request', $intercept );

		if ( is_multisite() ) {
			switch_to_blog( $tenant_id );
		}
		BackgroundJobDispatcher::dispatch( $job_id, 'test-token' );
		BackgroundJobDispatcher::dispatch( $job_id, 'test-token' );
		$this->assertSame( $tenant_id, get_current_blog_id() );
		if ( is_multisite() ) {
			restore_current_blog();
		}
		remove_filter( 'pre_http_request', $intercept );

		$this->assertNotFalse( wp_next_scheduled( BackgroundJobDispatcher::HOOK, $args ) );
		$this->assertSame( 0, $requests, 'An already queued event must not fall back to an HTTP loopback.' );
		wp_clear_scheduled_hook( BackgroundJobDispatcher::HOOK, $args );
	}

	public function set_up(): void {
		// REST registration must happen before parent::set_up() snapshots hooks.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress test global.
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Standard WordPress REST action.
		do_action( 'rest_api_init' );

		parent::set_up();
		Database::install();
		$this->admin_id       = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->other_admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );

		global $wpdb;
		/** @var \wpdb $wpdb */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup for custom plan tables.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', DurablePlanRepository::steps_table_name() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup for custom plan tables.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', DurablePlanRepository::table_name() ) );
	}

	public function tear_down(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress test global.
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/** Completed mixed-model turns keep their own immutable model attribution. */
	public function test_turn_model_metadata_is_added_without_overwriting_existing_attribution(): void {
		$method = new \ReflectionMethod(
			\SdAiAgent\REST\SessionController::class,
			'add_turn_model_metadata'
		);
		$method->setAccessible( true );
		$messages = [
			[
				'role'  => 'user',
				'parts' => [ [ 'text' => 'First turn.' ] ],
			],
			[
				'role'        => 'model',
				'provider_id' => 'existing-provider',
				'model_id'    => 'existing-model',
				'parts'       => [ [ 'text' => 'Existing reply.' ] ],
			],
			[
				'role'  => 'system',
				'parts' => [ [ 'text' => 'System notice.' ] ],
			],
		];

		/** @var array<int, array<string, mixed>> $annotated */
		$annotated = $method->invoke( null, $messages, 'superdav', 'superdav-chat-fast' );

		$this->assertSame( 'superdav', $annotated[0]['provider_id'] );
		$this->assertSame( 'superdav-chat-fast', $annotated[0]['model_id'] );
		$this->assertSame( 'existing-provider', $annotated[1]['provider_id'] );
		$this->assertSame( 'existing-model', $annotated[1]['model_id'] );
		$this->assertArrayNotHasKey( 'model_id', $annotated[2] );
	}

	/** Queued user steering reaches AgentLoop once and in FIFO order. */
	public function test_job_interrupt_checker_consumes_queued_messages_in_order(): void {
		$job_id = '00000000-0000-4000-8000-000000000199';
		set_transient(
			RestController::JOB_PREFIX . $job_id,
			[
				'status'     => 'processing',
				'user_id'    => $this->admin_id,
				'interrupts' => [
					[ 'message' => 'Stop inspecting and mutate.', 'timestamp' => 1 ],
					[ 'message' => 'Then validate the preview.', 'timestamp' => 2 ],
				],
			],
			RestController::JOB_TTL
		);

		$method = new \ReflectionMethod(
			\SdAiAgent\REST\SessionController::class,
			'build_job_interrupt_checker'
		);
		$method->setAccessible( true );
		/** @var \Closure(): ?array $checker */
		$checker = $method->invoke( null, $job_id );

		$first = $checker();
		$this->assertIsArray( $first );
		$this->assertSame( 'Stop inspecting and mutate.', $first['message'] );
		$second = $checker();
		$this->assertIsArray( $second );
		$this->assertSame( 'Then validate the preview.', $second['message'] );
		$this->assertNull( $checker() );

		$job = get_transient( RestController::JOB_PREFIX . $job_id );
		$this->assertIsArray( $job );
		$this->assertSame( [], $job['interrupts'] );
		delete_transient( RestController::JOB_PREFIX . $job_id );
	}

	/** Durable-plan routes remain private session endpoints. */
	public function test_durable_plan_routes_are_registered(): void {
		$routes = $this->server->get_routes();
		foreach (
			[
				'/sd-ai-agent/v1/sessions/(?P<id>\d+)/plan',
				'/sd-ai-agent/v1/sessions/(?P<id>\d+)/plan/status',
				'/sd-ai-agent/v1/sessions/(?P<id>\d+)/plan/continue',
				'/sd-ai-agent/v1/sessions/(?P<id>\d+)/plan/approve',
				'/sd-ai-agent/v1/sessions/(?P<id>\d+)/plan/reject',
				'/sd-ai-agent/v1/sessions/(?P<id>\d+)/plan/retry',
				'/sd-ai-agent/v1/sessions/(?P<id>\d+)/plan/cancel',
				'/sd-ai-agent/v1/sessions/(?P<id>\d+)/plan/scope',
			] as $route
		) {
			$this->assertArrayHasKey( $route, $routes, "Route {$route} should be registered." );
		}
	}

	/** A plan remains outside session messages and a pending scope blocks continue. */
	public function test_create_status_and_scope_approval_are_session_bound(): void {
		$session_id = $this->create_session();
		$created    = $this->dispatch(
			'POST',
			"/sd-ai-agent/v1/sessions/{$session_id}/plan",
			$this->plan_definition()
		);
		$this->assert_status( 201, $created );
		$plan = $created->get_data()['plan'];
		$this->assertSame( 'pending', $plan['status'] );
		$this->assertCount( 2, $plan['steps'] );
		$this->assertArrayNotHasKey( 'idempotency_key', $plan['steps'][0] );

		$session = Database::get_session( $session_id );
		$this->assertNotNull( $session );
		$this->assertSame( '[]', $session->messages );

		$status = $this->dispatch( 'GET', "/sd-ai-agent/v1/sessions/{$session_id}/plan" );
		$this->assert_status( 200, $status );
		$this->assertSame( $plan['plan_id'], $status->get_data()['plan']['plan_id'] );

		$scope = $this->dispatch(
			'POST',
			"/sd-ai-agent/v1/sessions/{$session_id}/plan/scope",
			[
				'plan_id' => $plan['plan_id'],
				'scope'   => 'Also update the production footer.',
			]
		);
		$this->assert_status( 200, $scope );
		$this->assertSame( 'awaiting_approval', $scope->get_data()['status'] );

		$continue = $this->dispatch(
			'POST',
			"/sd-ai-agent/v1/sessions/{$session_id}/plan/continue",
			[ 'plan_id' => $plan['plan_id'] ]
		);
		$this->assert_status( 200, $continue );
		$this->assertSame( 'awaiting_approval', $continue->get_data()['status'] );
		$this->assertSame( 'pending', $continue->get_data()['plan']['steps'][0]['status'] );

		$approved = $this->dispatch(
			'POST',
			"/sd-ai-agent/v1/sessions/{$session_id}/plan/approve",
			[
				'plan_id'             => $plan['plan_id'],
				'approval_request_id' => $scope->get_data()['plan']['approval_request_id'],
			]
		);
		$this->assert_status( 200, $approved );
		$this->assertSame( 'pending', $approved->get_data()['status'] );
		$this->assertSame( 'Also update the production footer.', $approved->get_data()['plan']['scope'] );
	}

	/** Shared-session permissions do not allow another administrator to create a plan. */
	public function test_other_administrator_cannot_create_plan_for_session_owner(): void {
		$session_id = $this->create_session();
		wp_set_current_user( $this->other_admin_id );

		$response = $this->dispatch(
			'POST',
			"/sd-ai-agent/v1/sessions/{$session_id}/plan",
			$this->plan_definition()
		);

		$this->assert_status( 403, $response );
	}

	/** Shared-session access never grants another administrator plan mutation rights. */
	public function test_shared_administrator_cannot_mutate_durable_plan(): void {
		$session_id = $this->create_session();
		$created    = $this->dispatch(
			'POST',
			"/sd-ai-agent/v1/sessions/{$session_id}/plan",
			$this->plan_definition()
		);
		$this->assert_status( 201, $created );
		$plan_id = (string) $created->get_data()['plan']['plan_id'];
		$this->assertTrue( Database::share_session( $session_id, $this->admin_id ) );

		wp_set_current_user( $this->other_admin_id );
		$requests = [
			[ 'plan', $this->plan_definition() ],
			[ 'plan/continue', [ 'plan_id' => $plan_id ] ],
			[ 'plan/approve', [ 'plan_id' => $plan_id, 'approval_request_id' => 1 ] ],
			[ 'plan/reject', [ 'plan_id' => $plan_id, 'approval_request_id' => 1 ] ],
			[ 'plan/retry', [ 'plan_id' => $plan_id ] ],
			[ 'plan/cancel', [ 'plan_id' => $plan_id ] ],
			[ 'plan/scope', [ 'plan_id' => $plan_id, 'scope' => 'Change the approved scope.' ] ],
		];

		foreach ( $requests as [ $suffix, $params ] ) {
			$response = $this->dispatch( 'POST', "/sd-ai-agent/v1/sessions/{$session_id}/{$suffix}", $params );
			$this->assert_status( 403, $response );
		}
	}

	/** Browser-supplied phase metadata cannot disable durable approval safeguards. */
	public function test_client_plan_metadata_cannot_disable_approval_or_safe_resume(): void {
		$session_id = $this->create_session();
		$definition = $this->plan_definition();
		$definition['steps'][0]['classification']      = 'read';
		$definition['steps'][0]['idempotency_key']     = 'browser-controlled-key';
		$definition['steps'][0]['requires_approval']   = false;
		$definition['steps'][0]['safe_to_resume']      = true;

		$created = $this->dispatch(
			'POST',
			"/sd-ai-agent/v1/sessions/{$session_id}/plan",
			$definition
		);
		$this->assert_status( 201, $created );
		$plan = $created->get_data()['plan'];

		$stored = DurablePlanRepository::get_by_plan_id( (string) $plan['plan_id'] );
		$this->assertIsArray( $stored );
		$this->assertSame( 1, $stored['steps'][0]['requires_approval'] );
		$this->assertSame( 0, $stored['steps'][0]['safe_to_resume'] );
		$this->assertNotSame( 'browser-controlled-key', $stored['steps'][0]['idempotency_key'] );

		$continue = $this->dispatch(
			'POST',
			"/sd-ai-agent/v1/sessions/{$session_id}/plan/continue",
			[ 'plan_id' => $plan['plan_id'] ]
		);
		$this->assert_status( 200, $continue );
		$this->assertSame( 'awaiting_approval', $continue->get_data()['status'] );
	}

	/** Planning jobs are session-owner-only and carry the server-recognised planning flag. */
	public function test_durable_plan_run_is_session_owner_only_and_marks_the_job(): void {
		$session_id     = $this->create_session();
		$block_loopback = static function () {
			return [
				'headers'  => [],
				'body'     => '',
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies'  => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $block_loopback, 10, 3 );
		try {
			$response = $this->dispatch(
				'POST',
				'/sd-ai-agent/v1/run',
				[
					'message'      => 'Prepare a phased plan to review site navigation.',
					'session_id'   => $session_id,
					'durable_plan' => true,
					'attachments'  => [
						[
							'name'     => 'planner-attachment.txt',
							'type'     => 'text/plain',
							'data_url' => 'data:text/plain;base64,cGxhbm5lciBhdHRhY2htZW50',
							'is_image' => false,
						],
					],
				]
			);
		} finally {
			remove_filter( 'pre_http_request', $block_loopback, 10 );
		}

		$this->assert_status( 202, $response );
		$job_id = (string) $response->get_data()['job_id'];
		$job    = get_transient( RestController::JOB_PREFIX . $job_id );
		$this->assertIsArray( $job );
		$this->assertTrue( $job['params']['durable_plan'] );
		$this->assertSame( [], $job['params']['attachments'] );
		$active_job = ActiveJobRepository::get_by_job_id( $job_id );
		$this->assertNotNull( $active_job );
		$this->assertSame( 'queued', $active_job->status );

		delete_transient( RestController::JOB_PREFIX . $job_id );
		ActiveJobRepository::delete( $job_id );

		wp_set_current_user( $this->other_admin_id );
		$forbidden = $this->dispatch(
			'POST',
			'/sd-ai-agent/v1/run',
			[
				'message'      => 'Prepare a phased plan to review site navigation.',
				'session_id'   => $session_id,
				'durable_plan' => true,
			]
		);
		$this->assert_status( 403, $forbidden );
	}

	/** A durable phase remains queued in storage until its worker claims it. */
	public function test_durable_plan_continue_queues_the_worker_until_process_claims_it(): void {
		$session_id = $this->create_session();
		$created    = $this->dispatch(
			'POST',
			"/sd-ai-agent/v1/sessions/{$session_id}/plan",
			$this->plan_definition()
		);
		$this->assert_status( 201, $created );
		$plan_id = (string) $created->get_data()['plan']['plan_id'];
		$awaiting = $this->dispatch(
			'POST',
			"/sd-ai-agent/v1/sessions/{$session_id}/plan/continue",
			[ 'plan_id' => $plan_id ]
		);
		$this->assert_status( 200, $awaiting );
		$this->assertSame( 'awaiting_approval', $awaiting->get_data()['status'] );

		$block_loopback = static function () {
			return [
				'headers'  => [],
				'body'     => '',
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies'  => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $block_loopback, 10, 3 );
		try {
			$response = $this->dispatch(
				'POST',
				"/sd-ai-agent/v1/sessions/{$session_id}/plan/approve",
				[
					'plan_id'             => $plan_id,
					'approval_request_id' => $awaiting->get_data()['plan']['approval_request_id'],
				]
			);
		} finally {
			remove_filter( 'pre_http_request', $block_loopback, 10 );
		}

		$this->assert_status( 202, $response );
		$this->assertSame( 'processing', $response->get_data()['status'] );
		$job_id = (string) $response->get_data()['job_id'];
		$job    = ActiveJobRepository::get_by_job_id( $job_id );

		$this->assertNotNull( $job );
		$this->assertSame( 'queued', $job->status );

		$active_job = $this->dispatch( 'GET', "/sd-ai-agent/v1/sessions/{$session_id}/active-job" );
		$this->assert_status( 200, $active_job );
		$this->assertSame( 'processing', $active_job->get_data()['status'] );
	}

	/** Job polling keeps durable plan details scoped to the job owner. */
	public function test_job_status_hides_durable_plan_details_from_other_administrators(): void {
		$session_id = $this->create_session();
		$plan       = DurablePlanRunner::create( $session_id, $this->admin_id, $this->plan_definition() );
		$this->assertIsArray( $plan );
		$next = DurablePlanRunner::prepare_next( (string) $plan['plan_id'], $this->admin_id );
		$this->assertIsArray( $next );
		$this->assertSame( 'ready', $next['status'] );

		$job_id = '00000000-0000-4000-8000-000000000101';
		$this->assertNotFalse( ActiveJobRepository::create( $session_id, $job_id, $this->admin_id, 'queued' ) );
		$this->assertTrue( DurablePlanRunner::assign_job( (string) $plan['plan_id'], (int) $next['step']['id'], $job_id ) );
		$public_plan = DurablePlanRunner::public_plan( (string) $plan['plan_id'] );
		$this->assertIsArray( $public_plan );

		set_transient(
			RestController::JOB_PREFIX . $job_id,
			[
				'status'       => 'processing',
				'user_id'      => $this->admin_id,
				'durable_plan' => [ 'plan' => $public_plan ],
			],
			RestController::JOB_TTL
		);

		$owner_inline = $this->dispatch( 'GET', "/sd-ai-agent/v1/job/{$job_id}" );
		$this->assert_status( 200, $owner_inline );
		$this->assertArrayHasKey( 'durable_plan', $owner_inline->get_data() );

		wp_set_current_user( $this->other_admin_id );
		$other_inline = $this->dispatch( 'GET', "/sd-ai-agent/v1/job/{$job_id}" );
		$this->assert_status( 200, $other_inline );
		$this->assertArrayNotHasKey( 'durable_plan', $other_inline->get_data() );

		delete_transient( RestController::JOB_PREFIX . $job_id );
		wp_set_current_user( $this->admin_id );
		$owner_fallback = $this->dispatch( 'GET', "/sd-ai-agent/v1/job/{$job_id}" );
		$this->assert_status( 200, $owner_fallback );
		$this->assertArrayHasKey( 'durable_plan', $owner_fallback->get_data() );

		wp_set_current_user( $this->other_admin_id );
		$other_fallback = $this->dispatch( 'GET', "/sd-ai-agent/v1/job/{$job_id}" );
		$this->assert_status( 200, $other_fallback );
		$this->assertArrayNotHasKey( 'durable_plan', $other_fallback->get_data() );

		ActiveJobRepository::delete( $job_id );
	}

	/** A stale confirmation cannot return a claimed durable worker to the queue. */
	public function test_stale_durable_confirmation_does_not_requeue_an_active_worker(): void {
		$job_id = '00000000-0000-4000-8000-000000000102';
		$this->assertNotFalse( ActiveJobRepository::create( $this->create_session(), $job_id, $this->admin_id, 'processing' ) );
		set_transient(
			RestController::JOB_PREFIX . $job_id,
			[
				'status'  => 'awaiting_confirmation',
				'user_id' => $this->admin_id,
				'params'  => [
					'durable_plan_phase' => [
						'plan_id' => '00000000-0000-0000-0000-000000000001',
						'step_id' => 1,
					],
				],
			],
			RestController::JOB_TTL
		);

		$response = $this->dispatch( 'POST', "/sd-ai-agent/v1/job/{$job_id}/confirm" );
		$this->assert_status( 409, $response );
		if ( is_wp_error( $response ) ) {
			$this->assertSame( 'sd_ai_agent_job_not_resumable', $response->get_error_code() );
		}

		$row = ActiveJobRepository::get_by_job_id( $job_id );
		$this->assertNotNull( $row );
		$this->assertSame( 'processing', $row->status );
		delete_transient( RestController::JOB_PREFIX . $job_id );
		ActiveJobRepository::delete( $job_id );
	}

	/** Expired durable confirmation pauses update their associated plan state. */
	public function test_expired_paused_durable_job_updates_its_plan_state(): void {
		$session_id = $this->create_session();
		$cases      = [
			[ 'classification' => 'read', 'plan_status' => 'pending', 'step_status' => 'interrupted' ],
			[ 'classification' => 'write', 'plan_status' => 'blocked', 'step_status' => 'failed' ],
		];

		foreach ( $cases as $index => $case ) {
			$plan = DurablePlanRunner::create(
				$session_id,
				$this->admin_id,
				[
					'scope' => 'Test expired paused durable job recovery.',
					'steps' => [
						[
							'key'            => 'paused-' . $case['classification'],
							'title'          => 'Paused ' . $case['classification'] . ' phase',
							'instruction'    => 'Execute only this bounded test phase.',
							'classification' => $case['classification'],
						],
					],
				]
			);
			$this->assertIsArray( $plan );
			$next = DurablePlanRunner::prepare_next( (string) $plan['plan_id'], $this->admin_id );
			$this->assertIsArray( $next );
			if ( 'awaiting_approval' === $next['status'] ) {
				$next = DurablePlanRunner::approve( (string) $plan['plan_id'], (int) $next['plan']['approval_request_id'], $this->admin_id );
				$this->assertIsArray( $next );
			}
			$this->assertSame( 'ready', $next['status'] );

			$job_id = 'test-expired-paused-plan-' . $index;
			$this->assertNotFalse( ActiveJobRepository::create( $session_id, $job_id, $this->admin_id, 'awaiting_confirmation' ) );
			$this->assertTrue( DurablePlanRunner::assign_job( (string) $plan['plan_id'], (int) $next['step']['id'], $job_id ) );

			$expired = $this->dispatch( 'GET', "/sd-ai-agent/v1/sessions/{$session_id}/active-job" );
			$this->assert_status( 404, $expired );
			$this->assertNull( ActiveJobRepository::get_by_job_id( $job_id ) );

			$updated = DurablePlanRepository::get_by_plan_id( (string) $plan['plan_id'] );
			$this->assertIsArray( $updated );
			$this->assertSame( $case['plan_status'], $updated['status'] );
			$this->assertSame( $case['step_status'], $updated['steps'][0]['status'] );
		}
	}

	/** Completed job and persisted-session display projections hide textual reasoning. */
	public function test_job_and_session_display_responses_scrub_textual_thinking(): void {
		$session_id = $this->create_session();
		$messages   = [
			[
				'role'  => 'assistant',
				'parts' => [
					[ 'text' => 'Saved answer.<thinking>Persisted private reasoning.</thinking>' ],
				],
			],
		];
		$this->assertTrue( Database::append_to_session( $session_id, $messages ) );

		$session_response = $this->dispatch( 'GET', "/sd-ai-agent/v1/sessions/{$session_id}" );
		$this->assert_status( 200, $session_response );
		$this->assertSame( 'Saved answer.', $session_response->get_data()['messages'][0]['parts'][0]['text'] );

		$job_id = '00000000-0000-4000-8000-000000000103';
		$this->assertNotFalse( ActiveJobRepository::create( $session_id, $job_id, $this->admin_id, 'processing' ) );
		set_transient(
			RestController::JOB_PREFIX . $job_id,
			[
				'status'   => 'complete',
				'user_id'  => $this->admin_id,
				'result'   => [
					'reply'      => "Completed answer.<thinking>\nDo not expose this.\n</thinking>",
					'history'    => $messages,
					'messages'   => [ [ 'type' => 'preamble', 'text' => '<thinking>Live private reasoning.</thinking>' ] ],
					'tool_calls' => [],
				],
			],
			RestController::JOB_TTL
		);

		$job_response = $this->dispatch( 'GET', "/sd-ai-agent/v1/job/{$job_id}" );
		$this->assert_status( 200, $job_response );
		$this->assertSame( 'Completed answer.', $job_response->get_data()['reply'] );
		$this->assertSame( 'Saved answer.', $job_response->get_data()['history'][0]['parts'][0]['text'] );
		$this->assertSame( '', $job_response->get_data()['messages'][0]['text'] );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function dispatch( string $method, string $route, array $params = [] ) {
		$request = new WP_REST_Request( $method, $route );
		if ( in_array( $method, [ 'POST', 'PATCH', 'PUT' ], true ) ) {
			$request->set_body( wp_json_encode( $params ) );
			$request->set_header( 'Content-Type', 'application/json' );
		} else {
			$request->set_query_params( $params );
		}

		return $this->server->dispatch( $request );
	}

	/**
	 * @param \WP_REST_Response|\WP_Error $response REST response.
	 */
	private function assert_status( int $expected, $response ): void {
		if ( is_wp_error( $response ) ) {
			$data   = $response->get_error_data();
			$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
		} else {
			$status = $response->get_status();
		}

		$this->assertSame( $expected, $status, "Expected HTTP {$expected}, got {$status}." );
	}

	private function create_session(): int {
		$session_id = Database::create_session(
			[
				'user_id'     => $this->admin_id,
				'title'       => 'Durable REST plan test',
				'provider_id' => 'test-provider',
				'model_id'    => 'test-model',
			]
		);
		$this->assertIsInt( $session_id );

		return (int) $session_id;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function plan_definition(): array {
		return [
			'scope'   => 'Review and update the site navigation.',
			'summary' => 'Inspect before applying the reviewed update.',
			'steps'   => [
				[
					'key'               => 'inspect',
					'title'             => 'Inspect navigation',
					'instruction'       => 'Inspect the current navigation without making changes.',
					'classification'    => 'read',
					'idempotency_key'   => 'rest-inspect-navigation',
					'preconditions'     => 'Administrator session available.',
					'expected_evidence' => 'Navigation inventory.',
					'rollback_guidance' => 'No rollback required.',
				],
				[
					'key'               => 'configure',
					'title'             => 'Configure navigation',
					'instruction'       => 'Apply the reviewed navigation update.',
					'classification'    => 'write',
					'idempotency_key'   => 'rest-configure-navigation',
					'preconditions'     => 'Inspection evidence reviewed.',
					'expected_evidence' => 'Configuration confirmation.',
					'rollback_guidance' => 'Restore the prior navigation.',
				],
			],
		];
	}
}
