<?php

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\ActiveJobFailureDiagnostic;
use WP_UnitTestCase;

/**
 * Tests prompt-free active-job failure envelopes.
 */
class ActiveJobFailureDiagnosticTest extends WP_UnitTestCase {

	public function test_encoded_diagnostic_keeps_only_allowlisted_metadata(): void {
		$job_id      = '11111111-2222-3333-4444-555555555555';
		$diagnostic  = ActiveJobFailureDiagnostic::create(
			$job_id,
			ActiveJobFailureDiagnostic::REASON_LOCAL_PAYLOAD_GUARD,
			array(
				'last_safe_phase'    => 'before_provider_call',
				'resume_count'       => 2,
				'provider_id'        => 'openai_compat',
				'model_id'           => 'gpt-test',
				'request_size_class' => 'large',
				'prompt'             => 'PRIVATE_PROMPT_CONTENT',
				'authorization'      => 'Bearer PRIVATE_TOKEN',
				'trace'              => '/private/path.php:99',
			)
		);
		$encoded     = ActiveJobFailureDiagnostic::encode( $job_id, $diagnostic );
		$decoded     = ActiveJobFailureDiagnostic::from_stored( $job_id, $encoded );
		$rest_result = ActiveJobFailureDiagnostic::to_rest( $decoded );

		$this->assertStringNotContainsString( 'PRIVATE_PROMPT_CONTENT', $encoded );
		$this->assertStringNotContainsString( 'PRIVATE_TOKEN', $encoded );
		$this->assertStringNotContainsString( '/private/path.php', $encoded );
		$this->assertSame( ActiveJobFailureDiagnostic::REASON_LOCAL_PAYLOAD_GUARD, $rest_result['reason'] );
		$this->assertSame( 'before_provider_call', $rest_result['last_safe_phase'] );
		$this->assertSame( 2, $rest_result['resume_count'] );
		$this->assertSame( 'openai_compat', $rest_result['provider_id'] );
		$this->assertSame( 'gpt-test', $rest_result['model_id'] );
		$this->assertSame( 'large', $rest_result['request_size_class'] );
		$this->assertFalse( $rest_result['retryable'] );
		$this->assertSame( 'compact', $rest_result['next_action'] );
		$this->assertMatchesRegularExpression( '/^job-[a-f0-9]{12}$/', $rest_result['correlation_id'] );
	}

	public function test_legacy_free_text_is_not_returned_as_a_diagnostic(): void {
		$diagnostic = ActiveJobFailureDiagnostic::from_stored(
			'66666666-7777-8888-9999-000000000000',
			'Provider echoed PRIVATE_PROMPT_CONTENT with Authorization: Bearer PRIVATE_TOKEN'
		);
		$encoded    = ActiveJobFailureDiagnostic::encode( '66666666-7777-8888-9999-000000000000', $diagnostic );

		$this->assertSame( ActiveJobFailureDiagnostic::REASON_UNKNOWN, $diagnostic['reason'] );
		$this->assertStringNotContainsString( 'PRIVATE_PROMPT_CONTENT', $encoded );
		$this->assertStringNotContainsString( 'PRIVATE_TOKEN', $encoded );
	}

	/**
	 * @dataProvider provider_error_reason_provider
	 *
	 * @param \WP_Error $error           Provider error to classify.
	 * @param string    $expected_reason Normalized failure reason.
	 */
	public function test_reason_from_error_classifies_provider_failures( \WP_Error $error, string $expected_reason ): void {
		$this->assertSame( $expected_reason, ActiveJobFailureDiagnostic::reason_from_error( $error ) );
	}

	/**
	 * Provider and transport errors carry many vendor-specific messages. The
	 * classifier must normalize the failure without retaining those messages.
	 *
	 * @return array<string, array{0: \WP_Error, 1: string}>
	 */
	public function provider_error_reason_provider(): array {
		return array(
			'local payload guard'       => array(
				new \WP_Error(
					'sd_ai_agent_provider_payload_budget_exceeded',
					'PRIVATE_PROMPT_CONTENT exceeded the local payload budget.',
					array( 'local_rejection' => true )
				),
				ActiveJobFailureDiagnostic::REASON_LOCAL_PAYLOAD_GUARD,
			),
			'upstream payload rejection' => array(
				new \WP_Error(
					'provider_http_error',
					'Provider returned 413 for PRIVATE_PROMPT_CONTENT.',
					array( 'status_code' => 413 )
				),
				ActiveJobFailureDiagnostic::REASON_UPSTREAM_PAYLOAD_REJECTION,
			),
			'provider timeout'          => array(
				new \WP_Error(
					'provider_transport_error',
					'Provider request timed out while processing PRIVATE_PROMPT_CONTENT.'
				),
				ActiveJobFailureDiagnostic::REASON_PROVIDER_TIMEOUT,
			),
			'unknown provider error'    => array(
				new \WP_Error(
					'provider_unexpected_error',
					'Unexpected provider response containing PRIVATE_PROMPT_CONTENT.'
				),
				ActiveJobFailureDiagnostic::REASON_UNKNOWN,
			),
		);
	}

	public function test_all_normalized_reasons_have_safe_recovery_metadata(): void {
		$expected = array(
			ActiveJobFailureDiagnostic::REASON_LOCAL_PAYLOAD_GUARD        => array( 'compact', false ),
			ActiveJobFailureDiagnostic::REASON_UPSTREAM_PAYLOAD_REJECTION => array( 'compact', false ),
			ActiveJobFailureDiagnostic::REASON_PROVIDER_TIMEOUT           => array( 'retry', true ),
			ActiveJobFailureDiagnostic::REASON_WORKER_TERMINATED          => array( 'retry', true ),
			ActiveJobFailureDiagnostic::REASON_APPROVAL_WAIT              => array( 'approve_review', true ),
			ActiveJobFailureDiagnostic::REASON_APPROVAL_EXPIRED           => array( 'continuation', false ),
			ActiveJobFailureDiagnostic::REASON_RESUME_EXHAUSTED           => array( 'continuation', false ),
			ActiveJobFailureDiagnostic::REASON_LOOP_EXCEPTION             => array( 'continuation', false ),
			ActiveJobFailureDiagnostic::REASON_UNKNOWN                    => array( 'contact_support', true ),
		);

		foreach ( $expected as $reason => $metadata ) {
			$diagnostic = ActiveJobFailureDiagnostic::create(
				'77777777-8888-9999-0000-111111111111',
				$reason,
				array( 'last_safe_phase' => 'before_provider_call' )
			);

			$this->assertSame( $reason, $diagnostic['reason'] );
			$this->assertSame( $metadata[0], $diagnostic['next_action'] );
			$this->assertSame( $metadata[1], $diagnostic['retryable'] );
		}
	}
}
