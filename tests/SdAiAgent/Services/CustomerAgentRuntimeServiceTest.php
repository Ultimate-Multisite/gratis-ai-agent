<?php

declare(strict_types=1);
/**
 * Regression tests for the server-side customer-agent runtime contract.
 *
 * @package SdAiAgent\Tests\Services
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Services;

use SdAiAgent\Core\Database;
use SdAiAgent\Models\CustomerAgentRuntimeRepository;
use SdAiAgent\Services\CustomerAgentRuntimeService;
use WP_Error;
use WP_UnitTestCase;

class CustomerAgentRuntimeServiceTest extends WP_UnitTestCase {

	private const INTEGRATION = 'test-customer-runtime';
	private const OTHER_INTEGRATION = 'other-customer-runtime';

	private CustomerAgentRuntimeService $service;

	private int $executor_runs = 0;

	public function set_up(): void {
		parent::set_up();
		delete_option( Database::DB_VERSION_OPTION );
		Database::install();
		$this->delete_runtime_rows();
		$this->executor_runs = 0;
		$this->service       = new CustomerAgentRuntimeService(
			function (): array {
				++$this->executor_runs;
				return array(
					'reply'           => 'Helpful <strong>customer</strong> answer.',
					'history'         => array(),
					'model_id'        => 'test-model',
					'iterations_used' => 2,
					'token_usage'     => array(
						'prompt'     => 12,
						'completion' => 24,
					),
				);
			}
		);
		add_filter( 'sd_ai_agent_customer_agent_integrations', array( $this, 'register_integrations' ) );
	}

	public function tear_down(): void {
		remove_filter( 'sd_ai_agent_customer_agent_integrations', array( $this, 'register_integrations' ) );
		wp_clear_scheduled_hook( CustomerAgentRuntimeService::PROCESS_HOOK );
		$this->delete_runtime_rows();
		parent::tear_down();
	}

	/**
	 * V1 advertises a stable semantic version and fails closed client features.
	 */
	public function test_discovers_versioned_fail_closed_capabilities(): void {
		$capabilities = $this->service->discover_capabilities();

		$this->assertSame( '1.0.0', $capabilities['contract_version'] );
		$this->assertTrue( $capabilities['features']['durable_jobs'] );
		$this->assertFalse( $capabilities['features']['client_tools_supported'] );
		$this->assertFalse( $capabilities['features']['attachments_supported'] );
		$this->assertFalse( $capabilities['features']['caller_prompts_supported'] );
	}

	/**
	 * A conversation is stable across retries for one trusted integration/session.
	 */
	public function test_creates_and_recovers_opaque_conversation(): void {
		$first = $this->service->create_or_recover_conversation( self::INTEGRATION, 'customer-session-1' );
		$this->assertNotInstanceOf( WP_Error::class, $first );
		$this->assertFalse( $first['recovered'] );

		$second = $this->service->create_or_recover_conversation( self::INTEGRATION, 'customer-session-1' );
		$this->assertNotInstanceOf( WP_Error::class, $second );
		$this->assertTrue( $second['recovered'] );
		$this->assertSame( $first['conversation_id'], $second['conversation_id'] );
	}

	/**
	 * Repeated message IDs resolve to one job, execute once, and terminal reads
	 * remain available without destructive polling.
	 */
	public function test_enqueue_is_idempotent_and_terminal_reads_are_non_consuming(): void {
		$first = $this->service->enqueue_turn( self::INTEGRATION, 'customer-session-2', 'message-1', 'Please help.' );
		$this->assertNotInstanceOf( WP_Error::class, $first );
		$this->assertTrue( $first['created'] );
		$this->assertSame( 'queued', $first['status'] );

		$retry = $this->service->enqueue_turn( self::INTEGRATION, 'customer-session-2', 'message-1', 'Please help.' );
		$this->assertNotInstanceOf( WP_Error::class, $retry );
		$this->assertFalse( $retry['created'] );
		$this->assertSame( $first['job_id'], $retry['job_id'] );

		$this->service->process_job( $first['job_id'] );
		$this->service->process_job( $first['job_id'] );
		$this->assertSame( 1, $this->executor_runs );

		$one = $this->service->inspect_job( self::INTEGRATION, 'customer-session-2', $first['job_id'] );
		$two = $this->service->inspect_job( self::INTEGRATION, 'customer-session-2', $first['job_id'] );
		$this->assertNotInstanceOf( WP_Error::class, $one );
		$this->assertNotInstanceOf( WP_Error::class, $two );
		$this->assertSame( 'complete', $one['status'] );
		$this->assertSame( $one, $two );
		$this->assertSame( 'Helpful customer answer.', $one['reply'] );
		$this->assertSame( 'test-model', $one['model_id'] );
		$this->assertSame( 12, $one['token_usage']['prompt'] );
	}

	/** Different integration/session keys cannot inspect another job. */
	public function test_job_isolated_by_integration_and_external_session(): void {
		$job = $this->service->enqueue_turn( self::INTEGRATION, 'customer-session-3', 'message-1', 'Please help.' );
		$this->assertNotInstanceOf( WP_Error::class, $job );

		$other_integration = $this->service->inspect_job( self::OTHER_INTEGRATION, 'customer-session-3', $job['job_id'] );
		$this->assertInstanceOf( WP_Error::class, $other_integration );
		$this->assertSame( 'sd_ai_agent_customer_agent_conversation_not_found', $other_integration->get_error_code() );

		$other_session = $this->service->inspect_job( self::INTEGRATION, 'customer-session-other', $job['job_id'] );
		$this->assertInstanceOf( WP_Error::class, $other_session );
		$this->assertSame( 'sd_ai_agent_customer_agent_conversation_not_found', $other_session->get_error_code() );
	}

	/**
	 * Cancellation wins a late-result race and processing cannot publish it.
	 */
	public function test_cancellation_prevents_late_result_delivery(): void {
		$service = null;
		$service = new CustomerAgentRuntimeService(
			function ( array $job ) use ( &$service ): array {
				$runtime = $service;
				if ( ! $runtime instanceof CustomerAgentRuntimeService ) {
					throw new \LogicException( 'Customer-agent runtime service was not initialized.' );
				}

				$cancelled = $runtime->cancel_job( self::INTEGRATION, 'customer-session-4', (string) $job['job_id'] );
				$this->assertNotInstanceOf( WP_Error::class, $cancelled );
				return array(
					'reply'       => 'Late provider answer.',
					'history'     => array(),
					'token_usage' => array(),
				);
			}
		);

		$job = $service->enqueue_turn( self::INTEGRATION, 'customer-session-4', 'message-1', 'Please help.' );
		$this->assertNotInstanceOf( WP_Error::class, $job );
		$service->process_job( $job['job_id'] );

		$status = $service->inspect_job( self::INTEGRATION, 'customer-session-4', $job['job_id'] );
		$this->assertNotInstanceOf( WP_Error::class, $status );
		$this->assertSame( 'cancelled', $status['status'] );
		$this->assertArrayNotHasKey( 'reply', $status );
	}

	/** Stuck jobs are converted to a stable timeout response during reconciliation. */
	public function test_stuck_job_becomes_timeout_failure(): void {
		global $wpdb;

		$job = $this->service->enqueue_turn( self::INTEGRATION, 'customer-session-5', 'message-1', 'Please help.' );
		$this->assertNotInstanceOf( WP_Error::class, $job );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-only deadline reconciliation setup.
		$wpdb->update(
			CustomerAgentRuntimeRepository::jobs_table_name(),
			array( 'deadline_at' => '2000-01-01 00:00:00' ),
			array( 'job_id' => $job['job_id'] ),
			array( '%s' ),
			array( '%s' )
		);

		$status = $this->service->inspect_job( self::INTEGRATION, 'customer-session-5', $job['job_id'] );
		$this->assertNotInstanceOf( WP_Error::class, $status );
		$this->assertSame( 'failed', $status['status'] );
		$this->assertSame( 'sd_ai_agent_customer_agent_timeout', $status['error']['code'] );
	}

	/** External identifiers are stored only as fixed-length opaque hashes. */
	public function test_persistence_hashes_external_identifiers(): void {
		$external_session_id = 'customer-session-private-1';
		$external_message_id = 'customer-message-private-1';
		$job                 = $this->service->enqueue_turn( self::INTEGRATION, $external_session_id, $external_message_id, 'Please help.' );
		$this->assertNotInstanceOf( WP_Error::class, $job );

		$stored = CustomerAgentRuntimeRepository::get_job( $job['job_id'] );
		$this->assertNotNull( $stored );
		$this->assertNotSame( $external_session_id, $stored['external_session_hash'] );
		$this->assertNotSame( $external_message_id, $stored['external_message_hash'] );
		$this->assertSame( 64, strlen( (string) $stored['external_session_hash'] ) );
		$this->assertSame( 64, strlen( (string) $stored['external_message_hash'] ) );
	}

	/** Executor errors are redacted before durable storage or customer delivery. */
	public function test_sanitizes_executor_failures_before_delivery(): void {
		$service = new CustomerAgentRuntimeService(
			static function ( array $job, array $conversation, array $profile ): WP_Error {
				return new WP_Error( 'provider_failure', 'Provider failure: token=test-only-secret-value' );
			}
		);
		$job = $service->enqueue_turn( self::INTEGRATION, 'customer-session-privacy', 'message-1', 'Please help.' );
		$this->assertNotInstanceOf( WP_Error::class, $job );

		$service->process_job( $job['job_id'] );

		$status = $service->inspect_job( self::INTEGRATION, 'customer-session-privacy', $job['job_id'] );
		$this->assertNotInstanceOf( WP_Error::class, $status );
		$this->assertSame( 'failed', $status['status'] );
		$this->assertSame( 'sd_ai_agent_customer_agent_execution_failed', $status['error']['code'] );
		$this->assertStringContainsString( '[redacted_credential]', $status['error']['message'] );
		$this->assertStringNotContainsString( 'test-only-secret-value', $status['error']['message'] );

		$stored = CustomerAgentRuntimeRepository::get_job( $job['job_id'] );
		$this->assertNotNull( $stored );
		$this->assertStringNotContainsString( 'test-only-secret-value', (string) $stored['error_message'] );
	}

	/** Unknown or unsafe registrations fail before a conversation can be created. */
	public function test_rejects_unknown_and_unsafe_integrations(): void {
		$unknown = $this->service->create_or_recover_conversation( 'unregistered-runtime', 'customer-session-6' );
		$this->assertInstanceOf( WP_Error::class, $unknown );
		$this->assertSame( 'sd_ai_agent_customer_agent_unknown_integration', $unknown->get_error_code() );

		$unsafe = $this->service->create_or_recover_conversation( 'unsafe-customer-runtime', 'customer-session-6' );
		$this->assertInstanceOf( WP_Error::class, $unsafe );
		$this->assertSame( 'sd_ai_agent_customer_agent_invalid_profile', $unsafe->get_error_code() );
	}

	/** Close purges the runtime record and makes follow-up reads impossible. */
	public function test_close_conversation_purges_runtime_state(): void {
		$conversation = $this->service->create_or_recover_conversation( self::INTEGRATION, 'customer-session-7' );
		$this->assertNotInstanceOf( WP_Error::class, $conversation );

		$closed = $this->service->close_conversation( self::INTEGRATION, 'customer-session-7' );
		$this->assertNotInstanceOf( WP_Error::class, $closed );
		$this->assertSame( 'closed', $closed['status'] );

		$missing = $this->service->create_or_recover_conversation( self::INTEGRATION, 'customer-session-7' );
		$this->assertNotInstanceOf( WP_Error::class, $missing );
		$this->assertFalse( $missing['recovered'] );
	}

	/**
	 * @param array<string,array<string,mixed>> $integrations Existing registrations.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_integrations( array $integrations ): array {
		$profile = array(
			'enabled'             => true,
			'profile'             => 'test-customer-profile',
			'system_instruction'  => 'Answer only from the approved support knowledge base.',
			'abilities'           => array( 'sd-ai-agent/knowledge-search' ),
			'collections'         => array( 'support-docs' ),
			'provider_id'         => 'test-provider',
			'model_id'            => 'test-model',
			'max_message_length'  => 1000,
			'max_history_turns'   => 4,
			'max_iterations'      => 3,
			'max_runtime_seconds' => 60,
		);

		$integrations[ self::INTEGRATION ]       = $profile;
		$integrations[ self::OTHER_INTEGRATION ] = $profile;
		$integrations['unsafe-customer-runtime'] = array_merge(
			$profile,
			array( 'abilities' => array( 'sd-ai-agent/ability-search' ) )
		);

		return $integrations;
	}

	/** Clear only test-owned durable runtime rows. */
	private function delete_runtime_rows(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test isolation for private runtime tables.
		$wpdb->query( 'DELETE FROM ' . CustomerAgentRuntimeRepository::jobs_table_name() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test isolation for private runtime tables.
		$wpdb->query( 'DELETE FROM ' . CustomerAgentRuntimeRepository::conversations_table_name() );
	}
}
