<?php

declare(strict_types=1);
/**
 * Prompt-free terminal diagnostics for background agent jobs.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the compact, allowlisted diagnostic envelope stored for a failed job.
 *
 * Failure messages from providers and PHP exceptions are deliberately excluded:
 * those values can echo prompts, tool input, credentials, paths, or stack traces.
 */
final class ActiveJobFailureDiagnostic {

	/** Current persisted-envelope version. */
	private const VERSION = 1;

	/** Failure caused by the local request-size guard. */
	public const REASON_LOCAL_PAYLOAD_GUARD = 'local_payload_guard';

	/** Failure caused by an upstream request-size rejection. */
	public const REASON_UPSTREAM_PAYLOAD_REJECTION = 'upstream_payload_rejection';

	/** Failure caused by an upstream provider timeout. */
	public const REASON_PROVIDER_TIMEOUT = 'provider_timeout';

	/** The managed Superdav provider requires additional account credit. */
	public const REASON_CREDIT_EXHAUSTED = 'credit_exhausted';

	/** Failure caused by a loopback request or worker terminating unexpectedly. */
	public const REASON_WORKER_TERMINATED = 'worker_terminated';

	/** Job is paused for an approval or client-side review. */
	public const REASON_APPROVAL_WAIT = 'approval_wait';

	/** Approval state expired before it could be completed safely. */
	public const REASON_APPROVAL_EXPIRED = 'approval_expired';

	/** Automatic resume budget was exhausted without a durable completion. */
	public const REASON_RESUME_EXHAUSTED = 'resume_exhausted';

	/** A caught loop exception left durable continuation state. */
	public const REASON_LOOP_EXCEPTION = 'loop_exception';

	/** Failure could not be classified without retaining unsafe detail. */
	public const REASON_UNKNOWN = 'unknown';

	/** @var list<string> Reasons that may be persisted or exposed to clients. */
	private const REASONS = array(
		self::REASON_LOCAL_PAYLOAD_GUARD,
		self::REASON_UPSTREAM_PAYLOAD_REJECTION,
		self::REASON_PROVIDER_TIMEOUT,
		self::REASON_CREDIT_EXHAUSTED,
		self::REASON_WORKER_TERMINATED,
		self::REASON_APPROVAL_WAIT,
		self::REASON_APPROVAL_EXPIRED,
		self::REASON_RESUME_EXHAUSTED,
		self::REASON_LOOP_EXCEPTION,
		self::REASON_UNKNOWN,
	);

	/** @var list<string> Safe request-size classifications emitted by ProviderTraceLogger. */
	private const REQUEST_SIZE_CLASSES = array( 'small', 'medium', 'large', 'very_large' );

	/**
	 * Build a safe diagnostic envelope from allowlisted metadata only.
	 *
	 * @param string               $job_id  Active-job UUID.
	 * @param string               $reason  Normalized terminal reason.
	 * @param array<string, mixed> $context Safe metadata supplied by the caller.
	 * @return array<string, bool|int|string>
	 */
	public static function create( string $job_id, string $reason, array $context = array() ): array {
		$reason = self::normalize_reason( $reason );

		return array(
			'version'            => self::VERSION,
			'reason'             => $reason,
			'last_safe_phase'    => self::sanitize_phase( (string) ( $context['last_safe_phase'] ?? '' ) ),
			'resume_count'       => max( 0, (int) ( $context['resume_count'] ?? 0 ) ),
			'provider_id'        => self::sanitize_identifier( (string) ( $context['provider_id'] ?? '' ) ),
			'model_id'           => self::sanitize_identifier( (string) ( $context['model_id'] ?? '' ) ),
			'request_size_class' => self::sanitize_request_size_class( (string) ( $context['request_size_class'] ?? '' ) ),
			'retryable'          => self::is_retryable_reason( $reason ),
			'next_action'        => self::next_action_for_reason( $reason ),
			'correlation_id'     => self::correlation_id( $job_id ),
		);
	}

	/**
	 * Encode a diagnostic for the existing active_jobs.error column.
	 *
	 * @param string               $job_id     Active-job UUID.
	 * @param array<string, mixed> $diagnostic Candidate diagnostic data.
	 * @return string JSON envelope. Never returns unsafe caller-supplied detail.
	 */
	public static function encode( string $job_id, array $diagnostic ): string {
		$normalized = self::create(
			$job_id,
			(string) ( $diagnostic['reason'] ?? self::REASON_UNKNOWN ),
			$diagnostic
		);
		$encoded    = wp_json_encode( $normalized );

		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * Decode a diagnostic envelope. Legacy free-text error rows intentionally
	 * become an unknown diagnostic instead of exposing their historical content.
	 *
	 * @param string      $job_id Active-job UUID.
	 * @param string|null $stored Stored active_jobs.error value.
	 * @return array<string, bool|int|string>
	 */
	public static function from_stored( string $job_id, ?string $stored ): array {
		$decoded = json_decode( (string) $stored, true );
		if ( ! is_array( $decoded ) || self::VERSION !== (int) ( $decoded['version'] ?? 0 ) ) {
			return self::create( $job_id, self::REASON_UNKNOWN );
		}

		return self::create( $job_id, (string) ( $decoded['reason'] ?? self::REASON_UNKNOWN ), $decoded );
	}

	/**
	 * Classify a provider or loop WP_Error without retaining its message.
	 *
	 * Error messages are inspected only to recognize a timeout when an upstream
	 * library does not supply a stable code. They are never returned, persisted,
	 * or added to the diagnostic context.
	 *
	 * @param \WP_Error $error Provider or loop error.
	 * @return string Normalized diagnostic reason.
	 */
	public static function reason_from_error( \WP_Error $error, string $provider_id = '' ): string {
		$code        = sanitize_key( (string) $error->get_error_code() );
		$data        = $error->get_error_data();
		$data        = is_array( $data ) ? $data : array();
		$provider_id = sanitize_key( $provider_id );

		// HTTP 402 only gets the managed-credit recovery path for the provider
		// that owns that account. Other providers may use 402 for unrelated
		// billing states and must remain safely classified as unknown.
		if ( 'sd-ai-agent-cloud' === $provider_id && 402 === (int) ( $data['status_code'] ?? 0 ) ) {
			return self::REASON_CREDIT_EXHAUSTED;
		}

		if (
			'sd_ai_agent_provider_payload_budget_exceeded' === $code ||
			! empty( $data['local_rejection'] )
		) {
			return self::REASON_LOCAL_PAYLOAD_GUARD;
		}

		if ( 'sd_ai_agent_provider_payload_too_large' === $code || 413 === (int) ( $data['status_code'] ?? 0 ) ) {
			return self::REASON_UPSTREAM_PAYLOAD_REJECTION;
		}

		if (
			in_array( $code, array( 'sd_ai_agent_provider_timeout', 'sd_ai_agent_provider_retry_failed', 'timeout', 'timed_out', 'deadline_exceeded' ), true ) ||
			in_array( (int) ( $data['status_code'] ?? 0 ), array( 408, 504, 524 ), true )
		) {
			return self::REASON_PROVIDER_TIMEOUT;
		}

		$message = strtolower( $error->get_error_message() );
		if ( str_contains( $message, 'timed out' ) || str_contains( $message, 'timeout' ) || str_contains( $message, 'deadline exceeded' ) ) {
			return self::REASON_PROVIDER_TIMEOUT;
		}

		return self::REASON_UNKNOWN;
	}

	/**
	 * Extract allowlisted diagnostic context from a WP_Error data payload.
	 *
	 * @param array<string, mixed> $data Error data.
	 * @return array<string, string>
	 */
	public static function context_from_error_data( array $data ): array {
		$context = array(
			'provider_id'        => (string) ( $data['provider_id'] ?? '' ),
			'model_id'           => (string) ( $data['model_id'] ?? '' ),
			'request_size_class' => (string) ( $data['request_size_class'] ?? '' ),
		);

		if ( '' === $context['request_size_class'] ) {
			$request_bytes = max( 0, (int) ( $data['request_bytes'] ?? $data['request_bytes_estimate'] ?? 0 ) );
			if ( $request_bytes > 0 ) {
				$context['request_size_class'] = ProviderTraceLogger::classify_request_size( $request_bytes );
			}
		}

		return $context;
	}

	/**
	 * Build the safe REST DTO for a persisted diagnostic.
	 *
	 * @param array<string, bool|int|string> $diagnostic Diagnostic envelope.
	 * @return array<string, bool|int|string>
	 */
	public static function to_rest( array $diagnostic ): array {
		return array(
			'reason'             => (string) $diagnostic['reason'],
			'last_safe_phase'    => (string) $diagnostic['last_safe_phase'],
			'resume_count'       => (int) $diagnostic['resume_count'],
			'provider_id'        => (string) $diagnostic['provider_id'],
			'model_id'           => (string) $diagnostic['model_id'],
			'request_size_class' => (string) $diagnostic['request_size_class'],
			'retryable'          => (bool) $diagnostic['retryable'],
			'next_action'        => (string) $diagnostic['next_action'],
			'correlation_id'     => (string) $diagnostic['correlation_id'],
		);
	}

	/**
	 * Return a customer-safe, fixed recovery message for a normalized reason.
	 */
	public static function message_for( string $reason ): string {
		return match ( self::normalize_reason( $reason ) ) {
			self::REASON_LOCAL_PAYLOAD_GUARD => __( 'This request is too large to send safely. Compact the conversation, shorten the latest message, or remove large attachments before retrying.', 'superdav-ai-agent' ),
			self::REASON_UPSTREAM_PAYLOAD_REJECTION => __( 'The selected AI provider rejected this request because it exceeds its payload limit. Start a smaller continuation and retry.', 'superdav-ai-agent' ),
			self::REASON_PROVIDER_TIMEOUT => __( 'The AI provider timed out before finishing. Retry the request shortly.', 'superdav-ai-agent' ),
			self::REASON_CREDIT_EXHAUSTED => __( 'Your Superdav account needs more credits to continue. Purchase credits in your account settings.', 'superdav-ai-agent' ),
			self::REASON_WORKER_TERMINATED => __( 'The background worker stopped before the job could finish. Retry the job or start a continuation from the saved conversation.', 'superdav-ai-agent' ),
			self::REASON_APPROVAL_WAIT => __( 'This job is waiting for approval before it can continue.', 'superdav-ai-agent' ),
			self::REASON_APPROVAL_EXPIRED => __( 'The pending approval expired before it could be completed. Review the conversation and start a continuation.', 'superdav-ai-agent' ),
			self::REASON_RESUME_EXHAUSTED => __( 'The job could not make progress after its automatic recovery attempts. Start a continuation from the saved conversation.', 'superdav-ai-agent' ),
			self::REASON_LOOP_EXCEPTION => __( 'The background job stopped unexpectedly. Start a continuation from the saved conversation or contact support with the correlation ID.', 'superdav-ai-agent' ),
			default => __( 'The background agent job could not finish. Retry the request, or contact support with the correlation ID if the problem continues.', 'superdav-ai-agent' ),
		};
	}

	/**
	 * Emit only prompt-free failure telemetry through the allowlisted event log.
	 *
	 * @param array<string, bool|int|string> $diagnostic Diagnostic envelope.
	 * @param int                            $session_id Session identifier.
	 */
	public static function log( array $diagnostic, int $session_id ): void {
		AgentEventLog::log(
			'active_job_failure',
			AgentEventLog::SEVERITY_ERROR,
			array(
				'session_id'         => max( 0, $session_id ),
				'code'               => (string) $diagnostic['reason'],
				'reason'             => (string) $diagnostic['reason'],
				'phase'              => (string) $diagnostic['last_safe_phase'],
				'resume_count'       => (int) $diagnostic['resume_count'],
				'provider_id'        => (string) $diagnostic['provider_id'],
				'model_id'           => (string) $diagnostic['model_id'],
				'request_size_class' => (string) $diagnostic['request_size_class'],
				'retryable'          => (bool) $diagnostic['retryable'],
				'next_action'        => (string) $diagnostic['next_action'],
				'correlation_id'     => (string) $diagnostic['correlation_id'],
			)
		);
	}

	/** Normalize a candidate failure reason against the shipped allowlist. */
	private static function normalize_reason( string $reason ): string {
		$reason = sanitize_key( $reason );

		return in_array( $reason, self::REASONS, true ) ? $reason : self::REASON_UNKNOWN;
	}

	/** Return whether the reason is safe to retry without changing the request. */
	private static function is_retryable_reason( string $reason ): bool {
		return in_array(
			$reason,
			array(
				self::REASON_PROVIDER_TIMEOUT,
				self::REASON_WORKER_TERMINATED,
				self::REASON_APPROVAL_WAIT,
				self::REASON_UNKNOWN,
			),
			true
		);
	}

	/** Return the next user action for a normalized failure reason. */
	private static function next_action_for_reason( string $reason ): string {
		return match ( $reason ) {
			self::REASON_LOCAL_PAYLOAD_GUARD,
			self::REASON_UPSTREAM_PAYLOAD_REJECTION => 'compact',
			self::REASON_PROVIDER_TIMEOUT,
			self::REASON_WORKER_TERMINATED => 'retry',
			self::REASON_CREDIT_EXHAUSTED => 'purchase_credits',
			self::REASON_APPROVAL_WAIT => 'approve_review',
			self::REASON_APPROVAL_EXPIRED,
			self::REASON_RESUME_EXHAUSTED,
			self::REASON_LOOP_EXCEPTION => 'continuation',
			default => 'contact_support',
		};
	}

	/** Keep a checkpoint phase to a short, machine-readable token. */
	private static function sanitize_phase( string $phase ): string {
		$phase = sanitize_key( $phase );

		return substr( $phase, 0, 60 );
	}

	/** Keep provider and model identifiers free of free-text request content. */
	private static function sanitize_identifier( string $identifier ): string {
		$identifier = sanitize_text_field( $identifier );
		$identifier = preg_replace( '/[^A-Za-z0-9._:-]/', '', $identifier );

		return is_string( $identifier ) ? substr( $identifier, 0, 100 ) : '';
	}

	/** Normalize a request-size classification emitted by ProviderTraceLogger. */
	private static function sanitize_request_size_class( string $size_class ): string {
		$size_class = sanitize_key( $size_class );

		return in_array( $size_class, self::REQUEST_SIZE_CLASSES, true ) ? $size_class : '';
	}

	/** Build a stable support correlation ID without exposing the raw job UUID. */
	private static function correlation_id( string $job_id ): string {
		if ( '' === $job_id ) {
			return 'job-unknown';
		}

		return 'job-' . substr( hash( 'sha256', $job_id ), 0, 12 );
	}
}
