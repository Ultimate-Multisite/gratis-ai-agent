/**
 * Safe active-job failure presentation helpers.
 *
 * This module is loaded only after a terminal job failure so the normal
 * floating-widget launcher remains within its initial-download budget. Every
 * value returned here is constrained to the public diagnostic contract.
 */

import { __ } from '@wordpress/i18n';

export {
	buildSuperdavCreditNoticeMessage,
	isSuperdavCreditBalanceNotice,
} from '../../utils/superdav-credit-notice';

const ACTIVE_JOB_FAILURE_REASONS = [
	'local_payload_guard',
	'upstream_payload_rejection',
	'provider_timeout',
	'gateway_rejection',
	'credit_exhausted',
	'worker_terminated',
	'approval_wait',
	'approval_expired',
	'resume_exhausted',
	'loop_exception',
	'unknown',
];

const ACTIVE_JOB_FAILURE_ACTIONS = [
	'compact',
	'retry',
	'approve_review',
	'continuation',
	'contact_support',
	'purchase_credits',
];

/**
 * Keep a failed-job diagnostic limited to the REST contract's safe fields.
 *
 * This provides client-side defence in depth: a stale proxy or integration
 * cannot repopulate the error card with a provider message, trace, or prompt.
 *
 * @param {Object} diagnostic Candidate job failure diagnostic.
 * @return {Object} Safe diagnostic for rendering.
 */
export function normalizeActiveJobFailureDiagnostic( diagnostic ) {
	const source =
		diagnostic &&
		typeof diagnostic === 'object' &&
		! Array.isArray( diagnostic )
			? diagnostic
			: {};
	const reason = ACTIVE_JOB_FAILURE_REASONS.includes( source.reason )
		? source.reason
		: 'unknown';
	const nextAction = ACTIVE_JOB_FAILURE_ACTIONS.includes( source.next_action )
		? source.next_action
		: 'contact_support';
	const phase =
		typeof source.last_safe_phase === 'string' &&
		/^[a-z0-9_]{1,60}$/.test( source.last_safe_phase )
			? source.last_safe_phase
			: '';
	const correlationId =
		typeof source.correlation_id === 'string' &&
		/^job-(?:[a-f0-9]{12}|unknown)$/.test( source.correlation_id )
			? source.correlation_id
			: '';

	return {
		reason,
		last_safe_phase: phase,
		retryable: source.retryable === true,
		next_action: nextAction,
		correlation_id: correlationId,
	};
}

/**
 * Return a normalized diagnostic only when the REST response supplied one.
 *
 * @param {Object} diagnostic Candidate diagnostic from a job status response.
 * @return {Object|null} Safe diagnostic, or null for legacy responses.
 */
export function getFailureDiagnostic( diagnostic ) {
	if (
		! diagnostic ||
		typeof diagnostic !== 'object' ||
		Array.isArray( diagnostic )
	) {
		return null;
	}

	return normalizeActiveJobFailureDiagnostic( diagnostic );
}

/**
 * Return fixed customer copy for a normalized active-job failure reason.
 *
 * The API provides equivalent text, but keeping a fixed frontend mapping
 * prevents accidental display of legacy free-text provider errors.
 *
 * @param {Object} diagnostic Safe job failure diagnostic.
 * @return {string} Customer-safe failure message.
 */
export function getActiveJobFailureMessage( diagnostic ) {
	const { reason } = normalizeActiveJobFailureDiagnostic( diagnostic );

	switch ( reason ) {
		case 'local_payload_guard':
			return __(
				'This request is too large to send safely. Compact the conversation, shorten the latest message, or remove large attachments before retrying.',
				'superdav-ai-agent'
			);
		case 'upstream_payload_rejection':
			return __(
				'The selected AI provider rejected this request because it exceeds its payload limit. Start a smaller continuation and retry.',
				'superdav-ai-agent'
			);
		case 'provider_timeout':
			return __(
				'The AI provider timed out before finishing. Retry the request shortly.',
				'superdav-ai-agent'
			);
		case 'gateway_rejection':
			return __(
				'The AI request was rejected by an upstream security gateway. Verify that the provider endpoint is allowed by your hosting or network policy, then retry. If it continues, contact support with the correlation ID.',
				'superdav-ai-agent'
			);
		case 'credit_exhausted':
			return __(
				'Your Superdav account needs more credits to continue. Purchase credits in your account settings.',
				'superdav-ai-agent'
			);
		case 'worker_terminated':
			return __(
				'The background worker stopped before the job could finish. Retry the job or start a continuation from the saved conversation.',
				'superdav-ai-agent'
			);
		case 'approval_wait':
			return __(
				'This job is waiting for approval before it can continue.',
				'superdav-ai-agent'
			);
		case 'approval_expired':
			return __(
				'The pending approval expired before it could be completed. Review the conversation and start a continuation.',
				'superdav-ai-agent'
			);
		case 'resume_exhausted':
			return __(
				'The job could not make progress after its automatic recovery attempts. Start a continuation from the saved conversation.',
				'superdav-ai-agent'
			);
		case 'loop_exception':
			return __(
				'The background job stopped unexpectedly. Start a continuation from the saved conversation or contact support with the correlation ID.',
				'superdav-ai-agent'
			);
		default:
			return __(
				'The background agent job could not finish. Retry the request, or contact support with the correlation ID if the problem continues.',
				'superdav-ai-agent'
			);
	}
}

/**
 * Create the action-card payload for a terminal job failure.
 *
 * @param {number} sessionId  Session owning the failed job.
 * @param {Object} diagnostic Candidate failure diagnostic.
 * @return {Object} Safe card payload.
 */
export function buildActiveJobFailureCard( sessionId, diagnostic ) {
	const safeDiagnostic = normalizeActiveJobFailureDiagnostic( diagnostic );

	return {
		type: 'active_job_failure',
		sessionId,
		diagnostic: safeDiagnostic,
		message: getActiveJobFailureMessage( safeDiagnostic ),
	};
}
