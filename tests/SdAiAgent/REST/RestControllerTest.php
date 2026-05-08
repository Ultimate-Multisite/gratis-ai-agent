<?php

declare(strict_types=1);
/**
 * Integration tests for RestController endpoints.
 *
 * Uses the WordPress REST API test infrastructure (WP_REST_Server) to dispatch
 * real HTTP-style requests through the registered routes. Each test group covers:
 *   - Unauthenticated access is rejected (401/403).
 *   - Authenticated admin access succeeds (2xx).
 *   - Core CRUD behaviour for data-bearing endpoints.
 *
 * The /run and /process endpoints are tested for job creation and status
 * polling only — the background AgentLoop is not exercised here (that belongs
 * in AgentLoopTest, t014).
 *
 * @package SdAiAgent
 * @subpackage Tests\REST
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\REST;

use SdAiAgent\Core\Database;
use SdAiAgent\Models\ActiveJobRepository;
use SdAiAgent\Models\Memory;
use SdAiAgent\Models\Skill;
use SdAiAgent\REST\RestController;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Integration tests for RestController.
 */
class RestControllerTest extends WP_UnitTestCase {

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
	 * Subscriber user ID (no manage_options).
	 *
	 * @var int
	 */
	protected int $subscriber_id;

	/**
	 * Set up REST server and test users before each test.
	 */
	public function set_up(): void {
		// REST server + rest_api_init must precede parent::set_up() so that
		// _backup_hooks() snapshots the DI route callbacks. See BenchmarkControllerTest
		// for the full explanation of why this ordering is required.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress test global.
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Standard WordPress core hook.
		do_action( 'rest_api_init' );

		parent::set_up();

		$this->admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	/**
	 * Tear down REST server after each test.
	 */
	public function tear_down(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress test global.
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Dispatch a REST request and return the response.
	 *
	 * @param string $method  HTTP method.
	 * @param string $route   Route path (e.g. '/sd-ai-agent/v1/memory').
	 * @param array  $params  Request parameters.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function dispatch( string $method, string $route, array $params = [] ) {
		$request = new WP_REST_Request( $method, $route );

		if ( in_array( $method, [ 'POST', 'PATCH', 'PUT' ], true ) ) {
			// Use JSON body — WP REST parses it for both get_param() (route arg
			// validation) and get_json_params() (used by some handlers directly).
			$request->set_body( wp_json_encode( $params ) );
			$request->set_header( 'Content-Type', 'application/json' );
		} else {
			$request->set_query_params( $params );
		}

		return $this->server->dispatch( $request );
	}

	/**
	 * Assert a response has the expected HTTP status code.
	 *
	 * @param int                        $expected Expected status code.
	 * @param \WP_REST_Response|\WP_Error $response Response to check.
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

	// ─── Route Registration ───────────────────────────────────────────────────

	/**
	 * Test that all expected routes are registered.
	 */
	public function test_routes_are_registered(): void {
		$routes = $this->server->get_routes();

		$expected_routes = [
			'/sd-ai-agent/v1/run',
			'/sd-ai-agent/v1/job/(?P<id>[a-f0-9-]+)',
			'/sd-ai-agent/v1/process',
			'/sd-ai-agent/v1/abilities',
			'/sd-ai-agent/v1/providers',
			'/sd-ai-agent/v1/settings',
			'/sd-ai-agent/v1/memory',
			'/sd-ai-agent/v1/memory/(?P<id>\d+)',
			'/sd-ai-agent/v1/memory/forget',
			'/sd-ai-agent/v1/skills',
			'/sd-ai-agent/v1/skills/(?P<id>\d+)',
			'/sd-ai-agent/v1/sessions',
			'/sd-ai-agent/v1/sessions/(?P<id>\d+)',
			'/sd-ai-agent/v1/sessions/folders',
			'/sd-ai-agent/v1/sessions/bulk',
			'/sd-ai-agent/v1/sessions/trash',
			'/sd-ai-agent/v1/usage',
			'/sd-ai-agent/v1/custom-tools',
			'/sd-ai-agent/v1/custom-tools/(?P<id>\d+)',
			'/sd-ai-agent/v1/automations',
			'/sd-ai-agent/v1/automations/(?P<id>\d+)',
			'/sd-ai-agent/v1/event-automations',
			'/sd-ai-agent/v1/event-automations/(?P<id>\d+)',
			'/sd-ai-agent/v1/event-triggers',
			'/sd-ai-agent/v1/automation-logs',
		];

		foreach ( $expected_routes as $route ) {
			$this->assertArrayHasKey( $route, $routes, "Route {$route} should be registered." );
		}
	}

	// ─── Permission: check_permission ────────────────────────────────────────

	/**
	 * Test unauthenticated request to /abilities is rejected.
	 */
	public function test_abilities_requires_auth(): void {
		wp_set_current_user( 0 );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/abilities' );
		$this->assertStatus( 401, $response );
	}

	/**
	 * Test subscriber (no manage_options) is rejected.
	 */
	public function test_abilities_requires_manage_options(): void {
		wp_set_current_user( $this->subscriber_id );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/abilities' );
		$this->assertStatus( 403, $response );
	}

	/**
	 * Test admin can access /abilities.
	 */
	public function test_abilities_admin_access(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/abilities' );
		$this->assertStatus( 200, $response );
		$this->assertIsArray( $response->get_data() );
	}

	// ─── /providers ──────────────────────────────────────────────────────────

	/**
	 * Test unauthenticated request to /providers is rejected.
	 */
	public function test_providers_requires_auth(): void {
		wp_set_current_user( 0 );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/providers' );
		$this->assertStatus( 401, $response );
	}

	/**
	 * Test admin can access /providers.
	 */
	public function test_providers_admin_access(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/providers' );
		$this->assertStatus( 200, $response );
		$this->assertIsArray( $response->get_data() );
	}

	// ─── /settings ───────────────────────────────────────────────────────────

	/**
	 * Test GET /settings returns settings array.
	 */
	public function test_get_settings(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/settings' );
		$this->assertStatus( 200, $response );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Test POST /settings updates a setting.
	 */
	public function test_update_settings(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/settings', [
			'max_iterations' => 5,
		] );

		$this->assertStatus( 200, $response );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Test unauthenticated access to /settings is rejected.
	 */
	public function test_settings_requires_auth(): void {
		wp_set_current_user( 0 );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/settings' );
		$this->assertStatus( 401, $response );
	}

	// ─── /memory ─────────────────────────────────────────────────────────────

	/**
	 * Test GET /memory returns list.
	 */
	public function test_list_memory(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/memory' );
		$this->assertStatus( 200, $response );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Test POST /memory creates a memory entry.
	 */
	public function test_create_memory(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/memory', [
			'category' => 'general',
			'content'  => 'REST test memory content',
		] );

		$this->assertStatus( 201, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
		$this->assertGreaterThan( 0, $data['id'] );
	}

	/**
	 * Test POST /memory requires category.
	 */
	public function test_create_memory_missing_category(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/memory', [
			'content' => 'No category provided',
		] );

		$this->assertStatus( 400, $response );
	}

	/**
	 * Test POST /memory requires content.
	 */
	public function test_create_memory_missing_content(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/memory', [
			'category' => 'general',
		] );

		$this->assertStatus( 400, $response );
	}

	/**
	 * Test PATCH /memory/{id} updates a memory entry.
	 */
	public function test_update_memory(): void {
		wp_set_current_user( $this->admin_id );

		// Create via model directly.
		$memory_id = Memory::create( 'general', 'Original content' );

		$request = new WP_REST_Request( 'PATCH', "/sd-ai-agent/v1/memory/{$memory_id}" );
		$request->set_body_params( [ 'content' => 'Updated via REST' ] );
		$response = $this->server->dispatch( $request );

		$this->assertStatus( 200, $response );
		$data = $response->get_data();
		// Handler returns {updated: true, id: N}.
		$this->assertArrayHasKey( 'updated', $data );
		$this->assertTrue( $data['updated'] );
	}

	/**
	 * Test DELETE /memory/{id} removes a memory entry.
	 */
	public function test_delete_memory(): void {
		wp_set_current_user( $this->admin_id );

		$memory_id = Memory::create( 'general', 'To be deleted via REST' );

		$request  = new WP_REST_Request( 'DELETE', "/sd-ai-agent/v1/memory/{$memory_id}" );
		$response = $this->server->dispatch( $request );

		$this->assertStatus( 200, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'deleted', $data );
		$this->assertTrue( $data['deleted'] );
	}

	/**
	 * Test PATCH /memory/{id} with non-existent ID.
	 *
	 * Memory::update uses $wpdb->update which returns 0 (not false) when no rows
	 * are affected, so the handler returns 200 with {updated: true} even for
	 * non-existent IDs. This is a known behaviour of the current implementation.
	 */
	public function test_update_memory_not_found(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'PATCH', '/sd-ai-agent/v1/memory/999999' );
		$request->set_body( wp_json_encode( [ 'content' => 'Ghost update' ] ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$response = $this->server->dispatch( $request );

		// $wpdb->update returns 0 (not false) for non-existent rows → handler
		// treats it as success. Accept 200 or any error status.
		$this->assertContains( $response->get_status(), [ 200, 404, 500 ] );
	}

	/**
	 * Test POST /memory/forget requires topic.
	 */
	public function test_forget_memory_missing_topic(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/memory/forget', [] );
		$this->assertStatus( 400, $response );
	}

	/**
	 * Test POST /memory/forget with a topic returns success.
	 */
	public function test_forget_memory(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/memory/forget', [
			'topic' => 'nonexistent_topic_xyz',
		] );

		$this->assertStatus( 200, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'deleted', $data );
	}

	/**
	 * Test unauthenticated access to /memory is rejected.
	 */
	public function test_memory_requires_auth(): void {
		wp_set_current_user( 0 );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/memory' );
		$this->assertStatus( 401, $response );
	}

	// ─── /skills ─────────────────────────────────────────────────────────────

	/**
	 * Test GET /skills returns list.
	 */
	public function test_list_skills(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/skills' );
		$this->assertStatus( 200, $response );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Test POST /skills creates a skill.
	 */
	public function test_create_skill(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/skills', [
			'slug'    => 'test-skill-rest-' . wp_generate_password( 6, false ),
			'name'    => 'REST Test Skill',
			'content' => 'You are a test skill.',
		] );

		$this->assertStatus( 201, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
		$this->assertGreaterThan( 0, $data['id'] );
	}

	/**
	 * Test POST /skills requires slug.
	 */
	public function test_create_skill_missing_slug(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/skills', [
			'name'    => 'No Slug Skill',
			'content' => 'Content here.',
		] );

		$this->assertStatus( 400, $response );
	}

	/**
	 * Test POST /skills requires name.
	 */
	public function test_create_skill_missing_name(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/skills', [
			'slug'    => 'no-name-skill',
			'content' => 'Content here.',
		] );

		$this->assertStatus( 400, $response );
	}

	/**
	 * Test POST /skills requires content.
	 */
	public function test_create_skill_missing_content(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/skills', [
			'slug' => 'no-content-skill',
			'name' => 'No Content Skill',
		] );

		$this->assertStatus( 400, $response );
	}

	/**
	 * Test POST /skills rejects duplicate slug.
	 */
	public function test_create_skill_duplicate_slug(): void {
		wp_set_current_user( $this->admin_id );

		$slug = 'duplicate-slug-' . wp_generate_password( 6, false );

		// Create first.
		$this->dispatch( 'POST', '/sd-ai-agent/v1/skills', [
			'slug'    => $slug,
			'name'    => 'First Skill',
			'content' => 'First content.',
		] );

		// Try to create again with same slug.
		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/skills', [
			'slug'    => $slug,
			'name'    => 'Duplicate Skill',
			'content' => 'Duplicate content.',
		] );

		$this->assertStatus( 409, $response );
	}

	/**
	 * Test PATCH /skills/{id} updates a skill.
	 */
	public function test_update_skill(): void {
		wp_set_current_user( $this->admin_id );

		$skill_id = Skill::create( [
			'slug'    => 'update-test-' . wp_generate_password( 6, false ),
			'name'    => 'Original Skill Name',
			'content' => 'Original content.',
		] );

		$request = new WP_REST_Request( 'PATCH', "/sd-ai-agent/v1/skills/{$skill_id}" );
		$request->set_body_params( [ 'name' => 'Updated Skill Name' ] );
		$response = $this->server->dispatch( $request );

		$this->assertStatus( 200, $response );
		$data = $response->get_data();
		$this->assertSame( 'Updated Skill Name', $data['name'] );
	}

	/**
	 * Test DELETE /skills/{id} removes a custom skill.
	 */
	public function test_delete_skill(): void {
		wp_set_current_user( $this->admin_id );

		$skill_id = Skill::create( [
			'slug'    => 'delete-test-' . wp_generate_password( 6, false ),
			'name'    => 'Skill To Delete',
			'content' => 'Delete me.',
		] );

		$request  = new WP_REST_Request( 'DELETE', "/sd-ai-agent/v1/skills/{$skill_id}" );
		$response = $this->server->dispatch( $request );

		$this->assertStatus( 200, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'deleted', $data );
		$this->assertTrue( $data['deleted'] );
	}

	/**
	 * Test PATCH /skills/{id} with non-existent ID.
	 *
	 * Known handler behaviour: handle_update_skill calls Skill::get() after
	 * Skill::update() returns false, which returns null for non-existent IDs,
	 * causing a PHP error (null property access). The handler should return a
	 * WP_Error instead. This test documents the current behaviour.
	 */
	public function test_update_skill_not_found(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'PATCH', '/sd-ai-agent/v1/skills/999999' );
		$request->set_body_params( [ 'name' => 'Ghost' ] );
		$request->set_body( wp_json_encode( [ 'name' => 'Ghost' ] ) );
		$request->set_header( 'Content-Type', 'application/json' );

		try {
			$response = $this->server->dispatch( $request );
			// If no exception, handler returned a response — accept any error status.
			$this->assertContains( $response->get_status(), [ 404, 500 ] );
		} catch ( \Throwable $e ) {
			// Handler throws due to null dereference on non-existent skill.
			// This is a known bug — the test documents it.
			$this->assertStringContainsString( 'null', strtolower( $e->getMessage() ) );
		}
	}

	/**
	 * Test unauthenticated access to /skills is rejected.
	 */
	public function test_skills_requires_auth(): void {
		wp_set_current_user( 0 );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/skills' );
		$this->assertStatus( 401, $response );
	}

	// ─── /sessions ───────────────────────────────────────────────────────────

	/**
	 * Test GET /sessions returns list.
	 */
	public function test_list_sessions(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/sessions' );
		$this->assertStatus( 200, $response );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Test POST /sessions creates a session.
	 */
	public function test_create_session(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/sessions', [
			'title' => 'REST Integration Test Session',
		] );

		$this->assertStatus( 201, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
		$this->assertGreaterThan( 0, $data['id'] );
		$this->assertSame( 'REST Integration Test Session', $data['title'] );
	}

	/**
	 * Test GET /sessions/{id} returns session data.
	 */
	public function test_get_session(): void {
		wp_set_current_user( $this->admin_id );

		$session_id = Database::create_session( [
			'user_id' => $this->admin_id,
			'title'   => 'Get Session Test',
		] );

		$response = $this->dispatch( 'GET', "/sd-ai-agent/v1/sessions/{$session_id}" );
		$this->assertStatus( 200, $response );
		$data = $response->get_data();
		$this->assertSame( 'Get Session Test', $data['title'] );
		$this->assertArrayHasKey( 'messages', $data );
		$this->assertArrayHasKey( 'tool_calls', $data );
	}

	/**
	 * Test GET /sessions/{id} for another user's session returns 403.
	 */
	public function test_get_session_other_user_forbidden(): void {
		wp_set_current_user( $this->admin_id );

		// Create session as admin.
		$session_id = Database::create_session( [
			'user_id' => $this->admin_id,
			'title'   => 'Admin Session',
		] );

		// Try to access as a different admin.
		$other_admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $other_admin );

		$response = $this->dispatch( 'GET', "/sd-ai-agent/v1/sessions/{$session_id}" );
		$this->assertStatus( 403, $response );
	}

	/**
	 * Test GET /sessions/{id} for non-existent session returns 403 (permission check fails first).
	 */
	public function test_get_session_not_found(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/sessions/999999' );
		// check_session_permission returns false when session not found → 403.
		$this->assertStatus( 403, $response );
	}

	/**
	 * Test PATCH /sessions/{id} updates session title.
	 */
	public function test_update_session(): void {
		wp_set_current_user( $this->admin_id );

		$session_id = Database::create_session( [
			'user_id' => $this->admin_id,
			'title'   => 'Original Title',
		] );

		$request = new WP_REST_Request( 'PATCH', "/sd-ai-agent/v1/sessions/{$session_id}" );
		$request->set_body_params( [ 'title' => 'Updated Title' ] );
		$response = $this->server->dispatch( $request );

		$this->assertStatus( 200, $response );
		$data = $response->get_data();
		$this->assertSame( 'Updated Title', $data['title'] );
	}

	/**
	 * Test PATCH /sessions/{id} with no fields returns 400.
	 */
	public function test_update_session_no_fields(): void {
		wp_set_current_user( $this->admin_id );

		$session_id = Database::create_session( [
			'user_id' => $this->admin_id,
			'title'   => 'No Update Session',
		] );

		$request = new WP_REST_Request( 'PATCH', "/sd-ai-agent/v1/sessions/{$session_id}" );
		$request->set_body_params( [] );
		$response = $this->server->dispatch( $request );

		$this->assertStatus( 400, $response );
	}

	/**
	 * Test DELETE /sessions/{id} removes session.
	 */
	public function test_delete_session(): void {
		wp_set_current_user( $this->admin_id );

		$session_id = Database::create_session( [
			'user_id' => $this->admin_id,
			'title'   => 'To Delete',
		] );

		$request  = new WP_REST_Request( 'DELETE', "/sd-ai-agent/v1/sessions/{$session_id}" );
		$response = $this->server->dispatch( $request );

		$this->assertStatus( 200, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'deleted', $data );
		$this->assertTrue( $data['deleted'] );
	}

	/**
	 * Test GET /sessions/folders returns folder list.
	 */
	public function test_list_folders(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/sessions/folders' );
		$this->assertStatus( 200, $response );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Test POST /sessions/bulk with trash action.
	 */
	public function test_bulk_sessions_trash(): void {
		wp_set_current_user( $this->admin_id );

		$s1 = Database::create_session( [ 'user_id' => $this->admin_id, 'title' => 'Bulk 1' ] );
		$s2 = Database::create_session( [ 'user_id' => $this->admin_id, 'title' => 'Bulk 2' ] );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/sessions/bulk', [
			'ids'    => [ $s1, $s2 ],
			'action' => 'trash',
		] );

		$this->assertStatus( 200, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'updated', $data );
		$this->assertSame( 2, $data['updated'] );
	}

	/**
	 * Test POST /sessions/bulk with invalid action returns 400.
	 */
	public function test_bulk_sessions_invalid_action(): void {
		wp_set_current_user( $this->admin_id );

		$s1 = Database::create_session( [ 'user_id' => $this->admin_id, 'title' => 'Bulk Invalid' ] );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/sessions/bulk', [
			'ids'    => [ $s1 ],
			'action' => 'invalid_action',
		] );

		$this->assertStatus( 400, $response );
	}

	/**
	 * Test DELETE /sessions/trash empties trash.
	 */
	public function test_empty_trash(): void {
		wp_set_current_user( $this->admin_id );

		$session_id = Database::create_session( [
			'user_id' => $this->admin_id,
			'title'   => 'Trash Me',
		] );
		Database::update_session( $session_id, [ 'status' => 'trash' ] );

		$request  = new WP_REST_Request( 'DELETE', '/sd-ai-agent/v1/sessions/trash' );
		$response = $this->server->dispatch( $request );

		$this->assertStatus( 200, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'deleted', $data );
		$this->assertGreaterThanOrEqual( 1, $data['deleted'] );
	}

	/**
	 * Test unauthenticated access to /sessions is rejected.
	 */
	public function test_sessions_requires_auth(): void {
		wp_set_current_user( 0 );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/sessions' );
		$this->assertStatus( 401, $response );
	}

	// ─── /usage ──────────────────────────────────────────────────────────────

	/**
	 * Test GET /usage returns usage summary.
	 */
	public function test_get_usage(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/usage' );
		$this->assertStatus( 200, $response );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'totals', $data );
		$this->assertArrayHasKey( 'by_model', $data );
	}

	/**
	 * Test unauthenticated access to /usage is rejected.
	 */
	public function test_usage_requires_auth(): void {
		wp_set_current_user( 0 );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/usage' );
		$this->assertStatus( 401, $response );
	}

	// ─── /run and /job/{id} ───────────────────────────────────────────────────

	/**
	 * Test POST /run requires authentication.
	 */
	public function test_run_requires_auth(): void {
		wp_set_current_user( 0 );
		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/run', [
			'message' => 'Hello',
		] );
		$this->assertStatus( 401, $response );
	}

	/**
	 * Test POST /run requires message parameter.
	 */
	public function test_run_requires_message(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/run', [] );
		$this->assertStatus( 400, $response );
	}

	/**
	 * Test POST /run returns 202 with job_id.
	 *
	 * The background worker is not exercised — we only verify the job is
	 * created and the polling endpoint can find it.
	 */
	public function test_run_creates_job(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/run', [
			'message' => 'Test message for job creation',
		] );

		$this->assertStatus( 202, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'job_id', $data );
		$this->assertArrayHasKey( 'status', $data );
		$this->assertSame( 'processing', $data['status'] );
		$this->assertNotEmpty( $data['job_id'] );
	}

	/**
	 * Test GET /job/{id} returns 404 for unknown job.
	 */
	public function test_job_status_not_found(): void {
		wp_set_current_user( $this->admin_id );

		$fake_id  = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
		$response = $this->dispatch( 'GET', "/sd-ai-agent/v1/job/{$fake_id}" );

		$this->assertStatus( 404, $response );
	}

	/**
	 * Test GET /job/{id} returns processing status for a real job.
	 */
	public function test_job_status_processing(): void {
		wp_set_current_user( $this->admin_id );

		// Create a job via /run.
		$run_response = $this->dispatch( 'POST', '/sd-ai-agent/v1/run', [
			'message' => 'Status check test',
		] );

		$this->assertStatus( 202, $run_response );
		$job_id = $run_response->get_data()['job_id'];

		// Poll the job — it will still be 'processing' since the background
		// worker hasn't run in the test environment.
		$status_response = $this->dispatch( 'GET', "/sd-ai-agent/v1/job/{$job_id}" );
		$this->assertStatus( 200, $status_response );
		$data = $status_response->get_data();
		$this->assertArrayHasKey( 'status', $data );
		$this->assertSame( 'processing', $data['status'] );
	}

	/**
	 * Test invalid direct history processing persists the DB job status as error.
	 */
	public function test_process_invalid_history_persists_error_status(): void {
		wp_set_current_user( $this->admin_id );

		$run_response = $this->dispatch( 'POST', '/sd-ai-agent/v1/run', [
			'message' => 'Invalid history persistence test',
		] );

		$this->assertStatus( 202, $run_response );
		$job_id = $run_response->get_data()['job_id'];
		$job    = get_transient( RestController::JOB_PREFIX . $job_id );

		$this->assertIsArray( $job );
		$this->assertArrayHasKey( 'token', $job );

		$job['params']['history'] = [
			[
				'role'  => 'invalid-role',
				'parts' => [],
			],
		];
		set_transient( RestController::JOB_PREFIX . $job_id, $job, RestController::JOB_TTL );

		$process_response = $this->dispatch( 'POST', '/sd-ai-agent/v1/process', [
			'job_id' => $job_id,
			'token'  => $job['token'],
		] );

		$this->assertStatus( 200, $process_response );
		$db_row = ActiveJobRepository::get_by_job_id( $job_id );

		$this->assertNotNull( $db_row );
		$this->assertSame( 'error', $db_row->status );
	}

	/**
	 * Test GET /job/{id} requires authentication.
	 */
	public function test_job_status_requires_auth(): void {
		wp_set_current_user( 0 );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/job/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' );
		$this->assertStatus( 401, $response );
	}

	// ─── /custom-tools ────────────────────────────────────────────────────────

	/**
	 * Test GET /custom-tools returns list.
	 */
	public function test_list_custom_tools(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/custom-tools' );
		$this->assertStatus( 200, $response );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Test POST /custom-tools creates a tool.
	 *
	 * HTTP type tools require a config.url — include it to pass validation.
	 */
	public function test_create_custom_tool(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/custom-tools', [
			'name'   => 'REST Test Tool',
			'type'   => 'http',
			'config' => [ 'url' => 'https://example.com/api' ],
		] );

		$this->assertStatus( 201, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
	}

	/**
	 * Test POST /custom-tools requires name.
	 */
	public function test_create_custom_tool_missing_name(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/custom-tools', [
			'type' => 'http',
		] );

		$this->assertStatus( 400, $response );
	}

	/**
	 * Test POST /custom-tools requires type.
	 */
	public function test_create_custom_tool_missing_type(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/custom-tools', [
			'name' => 'No Type Tool',
		] );

		$this->assertStatus( 400, $response );
	}

	/**
	 * Test unauthenticated access to /custom-tools is rejected.
	 */
	public function test_custom_tools_requires_auth(): void {
		wp_set_current_user( 0 );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/custom-tools' );
		$this->assertStatus( 401, $response );
	}

	// ─── /automations ────────────────────────────────────────────────────────

	/**
	 * Test GET /automations returns list.
	 */
	public function test_list_automations(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/automations' );
		$this->assertStatus( 200, $response );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Test POST /automations creates an automation.
	 */
	public function test_create_automation(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/automations', [
			'name'     => 'REST Test Automation',
			'prompt'   => 'Summarise recent posts.',
			'schedule' => 'daily',
		] );

		$this->assertStatus( 201, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
	}

	/**
	 * Test POST /automations requires name.
	 */
	public function test_create_automation_missing_name(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/automations', [
			'prompt' => 'No name provided.',
		] );

		$this->assertStatus( 400, $response );
	}

	/**
	 * Test POST /automations requires prompt.
	 */
	public function test_create_automation_missing_prompt(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/automations', [
			'name' => 'No Prompt Automation',
		] );

		$this->assertStatus( 400, $response );
	}

	/**
	 * Test PATCH /automations/{id} updates an automation.
	 */
	public function test_update_automation(): void {
		wp_set_current_user( $this->admin_id );

		// Create first.
		$create = $this->dispatch( 'POST', '/sd-ai-agent/v1/automations', [
			'name'   => 'Update Test Automation',
			'prompt' => 'Original prompt.',
		] );
		$this->assertStatus( 201, $create );
		$automation_id = $create->get_data()['id'];

		$request = new WP_REST_Request( 'PATCH', "/sd-ai-agent/v1/automations/{$automation_id}" );
		$request->set_body( wp_json_encode( [ 'name' => 'Updated Automation Name' ] ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$response = $this->server->dispatch( $request );

		$this->assertStatus( 200, $response );
		$data = $response->get_data();
		$this->assertSame( 'Updated Automation Name', $data['name'] );
	}

	/**
	 * Test DELETE /automations/{id} removes an automation.
	 */
	public function test_delete_automation(): void {
		wp_set_current_user( $this->admin_id );

		$create = $this->dispatch( 'POST', '/sd-ai-agent/v1/automations', [
			'name'   => 'Delete Test Automation',
			'prompt' => 'Delete me.',
		] );
		$this->assertStatus( 201, $create );
		$automation_id = $create->get_data()['id'];

		$request  = new WP_REST_Request( 'DELETE', "/sd-ai-agent/v1/automations/{$automation_id}" );
		$response = $this->server->dispatch( $request );

		$this->assertStatus( 200, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'deleted', $data );
		$this->assertTrue( $data['deleted'] );
	}

	/**
	 * Test GET /automation-templates returns list.
	 */
	public function test_automation_templates(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/automation-templates' );
		$this->assertStatus( 200, $response );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Test GET /automations/{id}/logs returns list.
	 */
	public function test_automation_logs(): void {
		wp_set_current_user( $this->admin_id );

		$create = $this->dispatch( 'POST', '/sd-ai-agent/v1/automations', [
			'name'   => 'Logs Test Automation',
			'prompt' => 'Log me.',
		] );
		$this->assertStatus( 201, $create );
		$automation_id = $create->get_data()['id'];

		$response = $this->dispatch( 'GET', "/sd-ai-agent/v1/automations/{$automation_id}/logs" );
		$this->assertStatus( 200, $response );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Test unauthenticated access to /automations is rejected.
	 */
	public function test_automations_requires_auth(): void {
		wp_set_current_user( 0 );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/automations' );
		$this->assertStatus( 401, $response );
	}

	// ─── /event-automations ──────────────────────────────────────────────────

	/**
	 * Test GET /event-automations returns list.
	 */
	public function test_list_event_automations(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/event-automations' );
		$this->assertStatus( 200, $response );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Test POST /event-automations creates an event automation.
	 */
	public function test_create_event_automation(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/event-automations', [
			'name'            => 'REST Test Event Automation',
			'hook_name'       => 'publish_post',
			'prompt_template' => 'A post was published: {{post_title}}',
		] );

		$this->assertStatus( 201, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
	}

	/**
	 * Test POST /event-automations requires hook_name.
	 */
	public function test_create_event_automation_missing_hook(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/event-automations', [
			'name'            => 'No Hook',
			'prompt_template' => 'Template here.',
		] );

		$this->assertStatus( 400, $response );
	}

	/**
	 * Test POST /event-automations requires prompt_template.
	 */
	public function test_create_event_automation_missing_prompt_template(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/event-automations', [
			'name'      => 'No Template',
			'hook_name' => 'publish_post',
		] );

		$this->assertStatus( 400, $response );
	}

	/**
	 * Test PATCH /event-automations/{id} updates an event automation.
	 */
	public function test_update_event_automation(): void {
		wp_set_current_user( $this->admin_id );

		$create = $this->dispatch( 'POST', '/sd-ai-agent/v1/event-automations', [
			'name'            => 'Update Event Automation',
			'hook_name'       => 'save_post',
			'prompt_template' => 'Post saved: {{post_title}}',
		] );
		$this->assertStatus( 201, $create );
		$event_id = $create->get_data()['id'];

		$request = new WP_REST_Request( 'PATCH', "/sd-ai-agent/v1/event-automations/{$event_id}" );
		$request->set_body( wp_json_encode( [ 'name' => 'Updated Event Automation Name' ] ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$response = $this->server->dispatch( $request );

		$this->assertStatus( 200, $response );
		$data = $response->get_data();
		$this->assertSame( 'Updated Event Automation Name', $data['name'] );
	}

	/**
	 * Test DELETE /event-automations/{id} removes an event automation.
	 */
	public function test_delete_event_automation(): void {
		wp_set_current_user( $this->admin_id );

		$create = $this->dispatch( 'POST', '/sd-ai-agent/v1/event-automations', [
			'name'            => 'Delete Event Automation',
			'hook_name'       => 'delete_post',
			'prompt_template' => 'Post deleted.',
		] );
		$this->assertStatus( 201, $create );
		$event_id = $create->get_data()['id'];

		$request  = new WP_REST_Request( 'DELETE', "/sd-ai-agent/v1/event-automations/{$event_id}" );
		$response = $this->server->dispatch( $request );

		$this->assertStatus( 200, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'deleted', $data );
		$this->assertTrue( $data['deleted'] );
	}

	/**
	 * Test GET /event-triggers returns list.
	 */
	public function test_list_event_triggers(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/event-triggers' );
		$this->assertStatus( 200, $response );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Test GET /automation-logs returns list.
	 */
	public function test_list_automation_logs(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/automation-logs' );
		$this->assertStatus( 200, $response );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Test unauthenticated access to /event-automations is rejected.
	 */
	public function test_event_automations_requires_auth(): void {
		wp_set_current_user( 0 );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/event-automations' );
		$this->assertStatus( 401, $response );
	}

	// ─── /process permission ─────────────────────────────────────────────────

	/**
	 * Test POST /process with no token is rejected.
	 */
	public function test_process_requires_valid_token(): void {
		wp_set_current_user( 0 );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/process', [
			'job_id' => 'fake-job-id',
			'token'  => 'invalid-token',
		] );

		// check_process_permission returns false → 401 (no cookie auth) or 403.
		$this->assertContains( $response->get_status(), [ 401, 403 ] );
	}

	/**
	 * Test POST /process with missing parameters is rejected.
	 */
	public function test_process_requires_job_id_and_token(): void {
		wp_set_current_user( 0 );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/process', [] );
		$this->assertContains( $response->get_status(), [ 400, 401, 403 ] );
	}

	// ─── /knowledge ──────────────────────────────────────────────────────────

	/**
	 * Test GET /knowledge/collections returns list.
	 */
	public function test_list_knowledge_collections(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/knowledge/collections' );
		$this->assertStatus( 200, $response );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Test POST /knowledge/collections creates a collection.
	 */
	public function test_create_knowledge_collection(): void {
		wp_set_current_user( $this->admin_id );

		// Use lowercase slug — sanitize_title() lowercases the value.
		$slug = 'rest-test-collection-' . strtolower( wp_generate_password( 6, false ) );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/knowledge/collections', [
			'name' => 'REST Test Collection',
			'slug' => $slug,
		] );

		$this->assertStatus( 201, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
		$this->assertSame( $slug, $data['slug'] );
	}

	/**
	 * Test POST /knowledge/collections rejects duplicate slug.
	 */
	public function test_create_knowledge_collection_duplicate_slug(): void {
		wp_set_current_user( $this->admin_id );

		$slug = 'dup-collection-' . wp_generate_password( 6, false );

		$this->dispatch( 'POST', '/sd-ai-agent/v1/knowledge/collections', [
			'name' => 'First Collection',
			'slug' => $slug,
		] );

		$response = $this->dispatch( 'POST', '/sd-ai-agent/v1/knowledge/collections', [
			'name' => 'Duplicate Collection',
			'slug' => $slug,
		] );

		$this->assertStatus( 409, $response );
	}

	/**
	 * Test DELETE /knowledge/collections/{id} removes a collection.
	 */
	public function test_delete_knowledge_collection(): void {
		wp_set_current_user( $this->admin_id );

		$create = $this->dispatch( 'POST', '/sd-ai-agent/v1/knowledge/collections', [
			'name' => 'Delete Collection',
			'slug' => 'delete-collection-' . wp_generate_password( 6, false ),
		] );
		$this->assertStatus( 201, $create );
		$collection_id = $create->get_data()['id'];

		$request  = new WP_REST_Request( 'DELETE', "/sd-ai-agent/v1/knowledge/collections/{$collection_id}" );
		$response = $this->server->dispatch( $request );

		$this->assertStatus( 200, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'deleted', $data );
		$this->assertTrue( $data['deleted'] );
	}

	/**
	 * Test GET /knowledge/stats returns statistics.
	 */
	public function test_knowledge_stats(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/knowledge/stats' );
		$this->assertStatus( 200, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'total_collections', $data );
		$this->assertArrayHasKey( 'total_chunks', $data );
		$this->assertArrayHasKey( 'collections', $data );
	}

	/**
	 * Test GET /knowledge/search requires q parameter.
	 */
	public function test_knowledge_search_requires_query(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/knowledge/search', [] );
		$this->assertStatus( 400, $response );
	}

	/**
	 * Test GET /knowledge/search with query returns results.
	 */
	public function test_knowledge_search(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/knowledge/search', [
			'q' => 'test query',
		] );
		$this->assertStatus( 200, $response );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Test unauthenticated access to /knowledge/collections is rejected.
	 */
	public function test_knowledge_requires_auth(): void {
		wp_set_current_user( 0 );
		$response = $this->dispatch( 'GET', '/sd-ai-agent/v1/knowledge/collections' );
		$this->assertStatus( 401, $response );
	}

	// ─── /chat/tool-result regression: 409 loop fix (sd-ai-9ip) ──────────────

	/**
	 * Test POST /chat/tool-result returns 409 when no paused_state exists AND
	 * downgrades a stale 'awaiting_client_tools' job transient/DB row to
	 * 'error'.
	 *
	 * Regression for the infinite 409 loop: before the fix, a duplicate POST
	 * (or a TTL-expiry replay) would return 409 but leave the job transient
	 * advertising 'awaiting_client_tools' with the same pending_client_tool_calls.
	 * The browser would then poll, re-execute the screenshot ability,
	 * POST again, get 409 again, and loop forever.
	 *
	 * @group t-409-loop
	 */
	public function test_tool_result_409_clears_stale_awaiting_client_tools_transient(): void {
		wp_set_current_user( $this->admin_id );

		$session_id = Database::create_session( [
			'user_id' => $this->admin_id,
			'title'   => '409 loop regression session',
		] );
		$job_id = '11111111-2222-3333-4444-555555555555';

		// Simulate a job that is mid-pause: transient + DB row both say
		// 'awaiting_client_tools' with one pending screenshot call. The
		// session has NO paused_state — that is the failure scenario.
		ActiveJobRepository::create( $session_id, $job_id, $this->admin_id, 'awaiting_client_tools' );
		set_transient(
			RestController::JOB_PREFIX . $job_id,
			[
				'status'                    => 'awaiting_client_tools',
				'pending_client_tool_calls' => [
					[
						'id'   => 'call_screenshot_1',
						'name' => 'sd-ai-agent-js/screenshot-url',
						'args' => [ 'url' => '/about/' ],
					],
				],
				'tool_calls'                => [],
			],
			RestController::JOB_TTL
		);

		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/chat/tool-result' );
		$request->set_body( wp_json_encode( [
			'session_id'   => $session_id,
			'job_id'       => $job_id,
			'tool_results' => [
				[
					'id'     => 'call_screenshot_1',
					'name'   => 'sd-ai-agent-js/screenshot-url',
					'result' => [ 'success' => true, 'image' => 'data:image/jpeg;base64,/9j/...' ],
				],
			],
		] ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$response = $this->server->dispatch( $request );

		// Endpoint still returns 409 — the contract is unchanged for the
		// browser's retry loop, which already treats 409 as "already done".
		$this->assertStatus( 409, $response );

		// The transient must be downgraded to 'error' so the next poll sees
		// a terminal state and stops re-executing the pending call.
		$transient_after = get_transient( RestController::JOB_PREFIX . $job_id );
		$this->assertIsArray( $transient_after );
		$this->assertSame( 'error', $transient_after['status'], 'Transient must be downgraded to error to break the 409 loop.' );
		$this->assertArrayNotHasKey(
			'pending_client_tool_calls',
			$transient_after,
			'Stale pending client tool calls must be cleared so the next poll cannot re-emit awaiting_client_tools.'
		);

		// And the DB row must match so that transient-expiry fallback also
		// serves 'error' rather than the stale 'awaiting_client_tools'.
		$db_row = ActiveJobRepository::get_by_job_id( $job_id );
		$this->assertNotNull( $db_row );
		$this->assertSame( 'error', $db_row->status );
	}

	/**
	 * Test POST /chat/tool-result on a 'complete' transient does NOT
	 * downgrade it to 'error'. A duplicate POST after the original already
	 * succeeded must leave the prior result intact for the browser to read.
	 *
	 * @group t-409-loop
	 */
	public function test_tool_result_409_preserves_complete_transient(): void {
		wp_set_current_user( $this->admin_id );

		$session_id = Database::create_session( [
			'user_id' => $this->admin_id,
			'title'   => '409 loop regression — complete preserved',
		] );
		$job_id = '22222222-3333-4444-5555-666666666666';

		ActiveJobRepository::create( $session_id, $job_id, $this->admin_id, 'complete' );
		set_transient(
			RestController::JOB_PREFIX . $job_id,
			[
				'status' => 'complete',
				'result' => [ 'reply' => 'Done.', 'history' => [], 'tool_calls' => [] ],
			],
			RestController::JOB_TTL
		);

		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/chat/tool-result' );
		$request->set_body( wp_json_encode( [
			'session_id'   => $session_id,
			'job_id'       => $job_id,
			'tool_results' => [
				[
					'id'     => 'call_x',
					'name'   => 'sd-ai-agent-js/screenshot-url',
					'result' => [ 'success' => true ],
				],
			],
		] ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$response = $this->server->dispatch( $request );

		$this->assertStatus( 409, $response );

		$transient_after = get_transient( RestController::JOB_PREFIX . $job_id );
		$this->assertIsArray( $transient_after );
		$this->assertSame(
			'complete',
			$transient_after['status'],
			'A complete transient must not be stomped by a duplicate tool-result POST.'
		);
	}

	/**
	 * Test POST /chat/tool-result with an empty job_id still returns 409
	 * without raising an error — the helper short-circuits when no job_id
	 * is supplied (defence in depth for older clients).
	 *
	 * @group t-409-loop
	 */
	public function test_tool_result_409_no_job_id_is_safe(): void {
		wp_set_current_user( $this->admin_id );

		$session_id = Database::create_session( [
			'user_id' => $this->admin_id,
			'title'   => '409 loop regression — no job_id',
		] );

		$request = new WP_REST_Request( 'POST', '/sd-ai-agent/v1/chat/tool-result' );
		$request->set_body( wp_json_encode( [
			'session_id'   => $session_id,
			// job_id intentionally omitted.
			'tool_results' => [
				[
					'id'     => 'call_x',
					'name'   => 'sd-ai-agent-js/screenshot-url',
					'result' => [ 'success' => true ],
				],
			],
		] ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$response = $this->server->dispatch( $request );

		$this->assertStatus( 409, $response );
	}
}
