<?php

declare(strict_types=1);
/**
 * Integration tests for durable phased site-operation plans.
 *
 * @package SdAiAgent
 * @subpackage Tests\Core
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Automations\HumanApprovalGate;
use SdAiAgent\Core\ActiveJobsCleanupService;
use SdAiAgent\Core\Database;
use SdAiAgent\Core\DurablePlanRunner;
use SdAiAgent\Models\ActiveJobRepository;
use SdAiAgent\Models\DurablePlanRepository;
use WP_UnitTestCase;

class DurablePlanRunnerTest extends WP_UnitTestCase {

	private int $admin_id;

	public function set_up(): void {
		parent::set_up();

		Database::install();
		HumanApprovalGate::clear_handlers();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );

		global $wpdb;
		/** @var \wpdb $wpdb */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup for custom plan tables.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', DurablePlanRepository::steps_table_name() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup for custom plan tables.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', DurablePlanRepository::table_name() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup for plan approval records.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source_type = %s', HumanApprovalGate::table_name(), 'durable-plan' ) );
	}

	public function tear_down(): void {
		HumanApprovalGate::clear_handlers();
		parent::tear_down();
	}

	/**
	 * Four phases demonstrate normal completion, a consequential interruption
	 * requiring a fresh approval, and a safe idempotent interruption resume.
	 */
	public function test_four_phase_plan_requires_fresh_approval_after_write_interruption(): void {
		$plan    = $this->create_plan();
		$plan_id = (string) $plan['plan_id'];

		$first = $this->prepare( $plan_id );
		$this->assertSame( 'inspect', $first['step']['step_key'] );
		$this->assign_job( $plan_id, $first, '10000000-0000-0000-0000-000000000001' );
		$after_first = $this->complete( $plan_id, $first, 'Inventory captured.' );
		$this->assertSame( 'pending', $after_first['status'] );
		$this->assertSame( 2, $after_first['current_step'] );
		$this->assertSame( 'completed', $this->step( $after_first, 'inspect' )['status'] );

		$awaiting_write = DurablePlanRunner::prepare_next( $plan_id, $this->admin_id );
		$this->assertIsArray( $awaiting_write );
		$this->assertSame( 'awaiting_approval', $awaiting_write['status'] );
		$first_approval = (int) $awaiting_write['plan']['approval_request_id'];
		$this->assertGreaterThan( 0, $first_approval );

		$write = $this->approve_and_prepare( $plan_id, $first_approval );
		$this->assertSame( 'configure', $write['step']['step_key'] );
		$this->assign_job( $plan_id, $write, '10000000-0000-0000-0000-000000000002' );
		$blocked = DurablePlanRunner::mark_phase_interrupted_by_job( '10000000-0000-0000-0000-000000000002' );
		$this->assertIsArray( $blocked );
		$this->assertSame( 'blocked', $blocked['status'] );
		$this->assertSame( 'failed', $this->step( $blocked, 'configure' )['status'] );

		$retry_approval = DurablePlanRunner::retry( $plan_id, $this->admin_id );
		$this->assertIsArray( $retry_approval );
		$this->assertSame( 'awaiting_approval', $retry_approval['status'] );
		$second_approval = (int) $retry_approval['plan']['approval_request_id'];
		$this->assertGreaterThan( 0, $second_approval );
		$this->assertNotSame( $first_approval, $second_approval );

		$write_retry = $this->approve_and_prepare( $plan_id, $second_approval );
		$this->complete( $plan_id, $write_retry, 'Configuration applied.' );

		$verify = $this->prepare( $plan_id );
		$this->assertSame( 'verify', $verify['step']['step_key'] );
		$this->assign_job( $plan_id, $verify, '10000000-0000-0000-0000-000000000003' );
		$safe_interruption = DurablePlanRunner::mark_phase_interrupted_by_job( '10000000-0000-0000-0000-000000000003' );
		$this->assertIsArray( $safe_interruption );
		$this->assertSame( 'pending', $safe_interruption['status'] );
		$this->assertSame( 'interrupted', $this->step( $safe_interruption, 'verify' )['status'] );

		$verify_resume = $this->prepare( $plan_id );
		$this->assertSame( 'verify', $verify_resume['step']['step_key'] );
		$this->complete( $plan_id, $verify_resume, 'Verification succeeded.' );

		$awaiting_destructive = DurablePlanRunner::prepare_next( $plan_id, $this->admin_id );
		$this->assertIsArray( $awaiting_destructive );
		$this->assertSame( 'awaiting_approval', $awaiting_destructive['status'] );
		$cancelled = DurablePlanRunner::reject(
			$plan_id,
			(int) $awaiting_destructive['plan']['approval_request_id'],
			$this->admin_id
		);
		$this->assertIsArray( $cancelled );
		$this->assertSame( 'cancelled', $cancelled['status'] );
	}

	/** Client phase metadata must not weaken approval or interruption handling. */
	public function test_client_phase_metadata_cannot_bypass_approval_or_safe_resume(): void {
		$session_id = Database::create_session(
			[
				'user_id'     => $this->admin_id,
				'title'       => 'Untrusted durable plan test',
				'provider_id' => 'test-provider',
				'model_id'    => 'test-model',
			]
		);
		$this->assertIsInt( $session_id );

		$plan = DurablePlanRunner::create_from_client(
			(int) $session_id,
			$this->admin_id,
			[
				'scope' => 'Inspect the site configuration.',
				'steps' => [
					[
						'key'               => 'browser-read',
						'title'             => 'Browser-labelled read phase',
						'instruction'       => 'Inspect the site configuration without changes.',
						'classification'    => 'read',
						'idempotency_key'   => 'browser-controlled-key',
						'requires_approval' => false,
						'safe_to_resume'    => true,
					],
				],
			]
		);
		$this->assertIsArray( $plan );
		$stored_step = $plan['steps'][0];
		$this->assertSame( 1, $stored_step['requires_approval'] );
		$this->assertSame( 0, $stored_step['safe_to_resume'] );
		$this->assertNotSame( 'browser-controlled-key', $stored_step['idempotency_key'] );

		$awaiting = DurablePlanRunner::prepare_next( (string) $plan['plan_id'], $this->admin_id );
		$this->assertIsArray( $awaiting );
		$this->assertSame( 'awaiting_approval', $awaiting['status'] );
		$first_approval = (int) $awaiting['plan']['approval_request_id'];

		$ready = $this->approve_and_prepare( (string) $plan['plan_id'], $first_approval );
		$this->assign_job( (string) $plan['plan_id'], $ready, '10000000-0000-0000-0000-000000000005' );
		$interrupted = DurablePlanRunner::mark_phase_interrupted_by_job( '10000000-0000-0000-0000-000000000005' );
		$this->assertIsArray( $interrupted );
		$this->assertSame( 'blocked', $interrupted['status'] );
		$this->assertSame( 'failed', $this->step( $interrupted, 'browser-read' )['status'] );

		$retry = DurablePlanRunner::retry( (string) $plan['plan_id'], $this->admin_id );
		$this->assertIsArray( $retry );
		$this->assertSame( 'awaiting_approval', $retry['status'] );
		$this->assertNotSame( $first_approval, (int) $retry['plan']['approval_request_id'] );
	}

	/** Invalid phase keys are rejected before they can trip a database constraint. */
	public function test_invalid_step_keys_are_rejected_and_empty_job_lookups_are_safe(): void {
		$plan = $this->create_plan();
		$this->assertNull( DurablePlanRepository::get_step_by_job_id( '' ) );

		$duplicate_steps         = $this->steps();
		$duplicate_steps[1]['key'] = $duplicate_steps[0]['key'];
		$duplicate               = DurablePlanRunner::create(
			(int) $plan['session_id'],
			$this->admin_id,
			[
				'scope' => 'Reject duplicate durable plan phase keys.',
				'steps' => $duplicate_steps,
			]
		);
		$this->assertTrue( is_wp_error( $duplicate ) );
		if ( is_wp_error( $duplicate ) ) {
			$this->assertSame( 'sd_ai_agent_plan_duplicate_step_key', $duplicate->get_error_code() );
		}

		$long_key_steps         = $this->steps();
		$long_key_steps[0]['key'] = str_repeat( 'phase-key-', 12 );
		$long_key               = DurablePlanRunner::create(
			(int) $plan['session_id'],
			$this->admin_id,
			[
				'scope' => 'Reject oversized durable plan phase keys.',
				'steps' => $long_key_steps,
			]
		);
		$this->assertTrue( is_wp_error( $long_key ) );
		if ( is_wp_error( $long_key ) ) {
			$this->assertSame( 'sd_ai_agent_plan_invalid_step_key', $long_key->get_error_code() );
		}
	}

	/** Pending scope approval cannot be bypassed with a direct continue call. */
	public function test_scope_change_requires_approval_before_a_phase_can_start(): void {
		$plan    = $this->create_plan();
		$plan_id = (string) $plan['plan_id'];

		$requested = DurablePlanRunner::request_scope_change( $plan_id, $this->admin_id, 'Include the production landing page.' );
		$this->assertIsArray( $requested );
		$this->assertSame( 'awaiting_approval', $requested['status'] );
		$this->assertSame( 'Include the production landing page.', $requested['plan']['pending_scope'] );

		$blocked_continue = DurablePlanRunner::prepare_next( $plan_id, $this->admin_id );
		$this->assertIsArray( $blocked_continue );
		$this->assertSame( 'awaiting_approval', $blocked_continue['status'] );
		$this->assertSame( 'pending', $this->step( $blocked_continue['plan'], 'inspect' )['status'] );

		$approved = DurablePlanRunner::approve(
			$plan_id,
			(int) $requested['plan']['approval_request_id'],
			$this->admin_id
		);
		$this->assertIsArray( $approved );
		$this->assertSame( 'pending', $approved['status'] );
		$this->assertSame( 'Include the production landing page.', $approved['plan']['scope'] );
		$this->assertSame( '', $approved['plan']['pending_scope'] );

		$second_request = DurablePlanRunner::request_scope_change( $plan_id, $this->admin_id, 'Replace the approved scope.' );
		$this->assertIsArray( $second_request );
		$rejected = DurablePlanRunner::reject(
			$plan_id,
			(int) $second_request['plan']['approval_request_id'],
			$this->admin_id
		);
		$this->assertIsArray( $rejected );
		$this->assertSame( 'Include the production landing page.', $rejected['plan']['scope'] );
		$this->assertSame( '', $rejected['plan']['pending_scope'] );
	}

	/** Provider context omits the session transcript and remains bounded. */
	public function test_provider_context_uses_only_compact_plan_state(): void {
		$session_id = Database::create_session(
			[
				'user_id' => $this->admin_id,
				'title'   => 'Durable context test',
			]
		);
		$this->assertIsInt( $session_id );
		Database::append_to_session(
			(int) $session_id,
			[
				[
					'role'  => 'user',
					'parts' => [ [ 'text' => 'RAW SESSION TRANSCRIPT MUST NOT REACH THE PROVIDER CONTEXT.' ] ],
				],
			]
		);

		$created = DurablePlanRunner::create(
			$session_id,
			$this->admin_id,
			[
				'scope' => 'api_key=do-not-store Authorization: Bearer repository-bearer-token {"authorization":"Bearer repository-json-bearer-token"} ' . str_repeat( 'bounded scope ', 200 ),
				'steps' => $this->steps(),
			]
		);
		$this->assertIsArray( $created );
		$stored = DurablePlanRepository::get_by_plan_id( (string) $created['plan_id'] );
		$this->assertIsArray( $stored );

		$context = DurablePlanRunner::build_provider_context( $stored, $stored['steps'][0] );
		$this->assertLessThanOrEqual( 6000, strlen( $context ) );
		$this->assertStringNotContainsString( 'RAW SESSION TRANSCRIPT', $context );
		$this->assertStringNotContainsString( 'do-not-store', $context );
		$this->assertStringNotContainsString( 'repository-bearer-token', $context );
		$this->assertStringNotContainsString( 'repository-json-bearer-token', $context );
		$this->assertStringContainsString( 'api_key: [redacted]', $context );
		$this->assertStringContainsString( 'Authorization: [redacted]', $context );
		$this->assertStringContainsString( '"authorization": "[redacted]"', $context );

		$stored['steps'] = array_map(
			static function ( int $position ): array {
				return [
					'position' => $position,
					'title'    => "Completed phase {$position}",
					'status'   => 'completed',
					'evidence' => [ 'summary' => "Evidence from phase {$position}." ],
				];
			},
			range( 1, 5 )
		);
		$evidence_context = DurablePlanRunner::build_provider_context(
			$stored,
			[
				'position'          => 6,
				'title'             => 'Current phase',
				'instruction'       => 'Perform only the current read-only phase.',
				'classification'    => 'read',
				'preconditions'     => '',
				'expected_evidence' => '',
				'rollback_guidance' => '',
			]
		);
		$this->assertStringNotContainsString( 'Evidence from phase 1.', $evidence_context );
		$this->assertStringNotContainsString( 'Evidence from phase 2.', $evidence_context );
		$this->assertStringContainsString( 'Evidence from phase 3.', $evidence_context );
		$this->assertStringContainsString( 'Evidence from phase 4.', $evidence_context );
		$this->assertStringContainsString( 'Evidence from phase 5.', $evidence_context );
	}

	/** Structured credentials are redacted from every compact plan field. */
	public function test_plan_fields_redact_structured_credentials(): void {
		$session_id = Database::create_session(
			[
				'user_id' => $this->admin_id,
				'title'   => 'Structured credential plan test',
			]
		);
		$this->assertIsInt( $session_id );

		$markers = [
			'plan-scope-json-secret',
			'plan-summary-json-secret',
			'plan-title-json-secret',
			'plan-instruction-json-secret',
			'plan-precondition-json-secret',
			'plan-evidence-json-secret',
			'plan-rollback-json-secret',
		];
		$plan = DurablePlanRunner::create(
			(int) $session_id,
			$this->admin_id,
			[
				'scope'   => '{"credentials":{"api_key":"plan-scope-json-secret with spaces"}}',
				'summary' => '{"access_token":"plan-summary-json-secret with spaces"}',
				'steps'   => [
					[
						'key'               => 'structured-redaction',
						'title'             => '{"password":"plan-title-json-secret with spaces"}',
						'instruction'       => '{"client_secret":"plan-instruction-json-secret with spaces"}',
						'classification'    => 'read',
						'preconditions'     => '{"credential":"plan-precondition-json-secret with spaces"}',
						'expected_evidence' => '{"token":"plan-evidence-json-secret with spaces"}',
						'rollback_guidance' => '{"private_key":"plan-rollback-json-secret with spaces"}',
					],
				],
			]
		);
		$this->assertIsArray( $plan );
		$stored = DurablePlanRepository::get_by_plan_id( (string) $plan['plan_id'] );
		$this->assertIsArray( $stored );
		$step = $stored['steps'][0];
		$fields = implode(
			"\n",
			[
				(string) $stored['scope'],
				(string) $stored['summary'],
				(string) $step['title'],
				(string) $step['instruction'],
				(string) $step['preconditions'],
				(string) $step['expected_evidence'],
				(string) $step['rollback_guidance'],
			]
		);
		foreach ( $markers as $marker ) {
			$this->assertStringNotContainsString( $marker, $fields );
		}
		$this->assertStringContainsString( '[redacted]', $fields );
	}

	/** Completion evidence never persists a bearer credential from provider output. */
	public function test_phase_evidence_redacts_bearer_credentials(): void {
		$plan    = $this->create_plan();
		$plan_id = (string) $plan['plan_id'];
		$phase   = $this->prepare( $plan_id );

		$this->assign_job( $plan_id, $phase, '10000000-0000-0000-0000-000000000004' );
		$completed = $this->complete( $plan_id, $phase, 'Authorization: Bearer evidence-bearer-token {"authorization":"Bearer evidence-json-bearer-token","credentials":{"api_key":"evidence-json-secret with spaces"}}' );
		$step      = $this->step( $completed, 'inspect' );

		$this->assertStringNotContainsString( 'evidence-bearer-token', (string) $step['evidence']['summary'] );
		$this->assertStringNotContainsString( 'evidence-json-bearer-token', (string) $step['evidence']['summary'] );
		$this->assertStringNotContainsString( 'evidence-json-secret', (string) $step['evidence']['summary'] );
		$this->assertStringContainsString( 'Authorization: [redacted]', (string) $step['evidence']['summary'] );
		$this->assertStringContainsString( '"authorization": "[redacted]"', (string) $step['evidence']['summary'] );
	}

	/** Scope and phase claims cannot overwrite one another after either wins. */
	public function test_scope_and_phase_claims_are_mutually_exclusive(): void {
		$plan      = $this->create_plan();
		$first_step = $plan['steps'][0];
		$scope     = 'Add the production footer to the reviewed scope.';

		$this->assertTrue(
			DurablePlanRepository::claim_scope_change(
				(int) $plan['id'],
				$scope,
				hash( 'sha256', $scope ),
				99
			)
		);
		$this->assertFalse(
			DurablePlanRepository::claim_step_and_start_plan(
				(int) $plan['id'],
				(int) $first_step['id'],
				(int) $first_step['position']
			)
		);

		$after_scope_claim = DurablePlanRepository::get_by_plan_id( (string) $plan['plan_id'] );
		$this->assertIsArray( $after_scope_claim );
		$this->assertSame( 'awaiting_approval', $after_scope_claim['status'] );
		$this->assertSame( 'pending', $after_scope_claim['steps'][0]['status'] );

		$second_plan = $this->create_plan();
		$second_step = $second_plan['steps'][0];
		$this->assertTrue(
			DurablePlanRepository::claim_step_and_start_plan(
				(int) $second_plan['id'],
				(int) $second_step['id'],
				(int) $second_step['position']
			)
		);
		$this->assertFalse(
			DurablePlanRepository::claim_scope_change(
				(int) $second_plan['id'],
				'Change the scope after the phase has started.',
				hash( 'sha256', 'Change the scope after the phase has started.' ),
				100
			)
		);
	}

	/** The cron reaper converts a stale durable worker into a safe resume state. */
	public function test_stale_durable_worker_marks_safe_phase_interrupted(): void {
		$plan    = $this->create_plan();
		$plan_id = (string) $plan['plan_id'];
		$phase   = $this->prepare( $plan_id );
		$job_id  = 'test-durable-stale-worker';

		$this->assertNotFalse(
			ActiveJobRepository::create( (int) $plan['session_id'], $job_id, $this->admin_id, 'queued' )
		);
		$this->assign_job( $plan_id, $phase, $job_id );

		global $wpdb;
		/** @var \wpdb $wpdb */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Set up a deterministic stale-worker fixture.
		$wpdb->update(
			ActiveJobRepository::table_name(),
			[ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 1800 ) ],
			[ 'job_id' => $job_id ],
			[ '%s' ],
			[ '%s' ]
		);

		ActiveJobsCleanupService::run();

		$job = ActiveJobRepository::get_by_job_id( $job_id );
		$this->assertNotNull( $job );
		$this->assertSame( 'abandoned', $job->status );
		$updated_plan = DurablePlanRunner::public_plan( $plan_id );
		$this->assertIsArray( $updated_plan );
		$this->assertSame( 'pending', $updated_plan['status'] );
		$this->assertSame( 'interrupted', $this->step( $updated_plan, 'inspect' )['status'] );

		ActiveJobRepository::delete( $job_id );
	}

	/** A late worker error cannot overwrite a phase that already completed. */
	public function test_late_failure_cannot_overwrite_a_completed_phase(): void {
		$plan    = $this->create_plan();
		$plan_id = (string) $plan['plan_id'];
		$phase   = $this->prepare( $plan_id );

		$this->complete( $plan_id, $phase, 'Inspection completed before the late failure.' );
		$this->assertNull(
			DurablePlanRunner::fail_phase(
				$plan_id,
				(int) $phase['step']['id'],
				'A stale worker reported an error after completion.'
			)
		);

		$updated = DurablePlanRunner::public_plan( $plan_id );
		$this->assertIsArray( $updated );
		$this->assertSame( 'pending', $updated['status'] );
		$this->assertSame( 'completed', $this->step( $updated, 'inspect' )['status'] );
	}

	/**
	 * Create a plan owned by the current administrator.
	 *
	 * @return array<string, mixed>
	 */
	private function create_plan(): array {
		$session_id = Database::create_session(
			[
				'user_id'     => $this->admin_id,
				'title'       => 'Durable plan test',
				'provider_id' => 'test-provider',
				'model_id'    => 'test-model',
			]
		);
		$this->assertIsInt( $session_id );
		$plan       = DurablePlanRunner::create(
			(int) $session_id,
			$this->admin_id,
			[
				'scope'   => 'Safely update the site navigation.',
				'summary' => 'Four bounded phases.',
				'steps'   => $this->steps(),
			]
		);
		$this->assertIsArray( $plan );

		return $plan;
	}

	/**
	 * @return list<array<string, string>>
	 */
	private function steps(): array {
		return [
			[
				'key'               => 'inspect',
				'title'             => 'Inspect current navigation',
				'instruction'       => 'Inspect the current navigation configuration only.',
				'classification'    => 'read',
				'idempotency_key'   => 'inspect-navigation',
				'preconditions'     => 'Administrator session available.',
				'expected_evidence' => 'A summary of existing navigation.',
				'rollback_guidance' => 'No rollback is required.',
			],
			[
				'key'               => 'configure',
				'title'             => 'Configure navigation',
				'instruction'       => 'Apply the reviewed navigation configuration.',
				'classification'    => 'write',
				'idempotency_key'   => 'configure-navigation',
				'preconditions'     => 'Inspection evidence reviewed.',
				'expected_evidence' => 'Configuration change confirmation.',
				'rollback_guidance' => 'Restore the prior navigation configuration.',
			],
			[
				'key'               => 'verify',
				'title'             => 'Verify navigation',
				'instruction'       => 'Verify the navigation output without modifying the site.',
				'classification'    => 'read',
				'idempotency_key'   => 'verify-navigation',
				'preconditions'     => 'Configuration phase completed.',
				'expected_evidence' => 'Verification result.',
				'rollback_guidance' => 'No rollback is required.',
			],
			[
				'key'               => 'publish',
				'title'             => 'Publish navigation',
				'instruction'       => 'Publish the reviewed navigation changes.',
				'classification'    => 'destructive',
				'idempotency_key'   => 'publish-navigation',
				'preconditions'     => 'Verification evidence reviewed.',
				'expected_evidence' => 'Publication confirmation.',
				'rollback_guidance' => 'Restore the previously published navigation.',
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function prepare( string $plan_id ): array {
		$outcome = DurablePlanRunner::prepare_next( $plan_id, $this->admin_id );
		$this->assertIsArray( $outcome );
		$this->assertSame( 'ready', $outcome['status'] );

		return $outcome;
	}

	/**
	 * @param array<string, mixed> $outcome Ready phase outcome.
	 */
	private function assign_job( string $plan_id, array $outcome, string $job_id ): void {
		$this->assertTrue( DurablePlanRunner::assign_job( $plan_id, (int) $outcome['step']['id'], $job_id ) );
	}

	/**
	 * @param array<string, mixed> $outcome Ready phase outcome.
	 * @return array<string, mixed>
	 */
	private function complete( string $plan_id, array $outcome, string $summary ): array {
		$plan = DurablePlanRunner::complete_phase(
			$plan_id,
			(int) $outcome['step']['id'],
			[
				'reply'       => $summary,
				'exit_reason' => 'complete',
				'token_usage' => [ 'prompt' => 4, 'completion' => 2 ],
			]
		);
		$this->assertIsArray( $plan );

		return $plan;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function approve_and_prepare( string $plan_id, int $approval_id ): array {
		$outcome = DurablePlanRunner::approve( $plan_id, $approval_id, $this->admin_id );
		$this->assertIsArray( $outcome );
		$this->assertSame( 'ready', $outcome['status'] );

		return $outcome;
	}

	/**
	 * @param array<string, mixed> $plan Public plan record.
	 * @return array<string, mixed>
	 */
	private function step( array $plan, string $key ): array {
		foreach ( $plan['steps'] as $step ) {
			if ( is_array( $step ) && $key === (string) ( $step['key'] ?? $step['step_key'] ?? '' ) ) {
				return $step;
			}
		}

		$this->fail( "Missing durable plan step '{$key}'." );

		return [];
	}
}
