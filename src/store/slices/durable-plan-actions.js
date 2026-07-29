/**
 * Lazy-loaded client actions for durable site-operation plans.
 *
 * The floating widget initializes the shared job slice on every frontend page.
 * Keep plan actions in their own chunk so code needed only after an operator
 * opens a durable-plan card does not consume the widget's startup budget.
 */

import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * Add a user-visible error from a durable-plan action.
 *
 * @param {Object} dispatch Store dispatchers.
 * @param {Object} error    Request or action error.
 * @param {string} fallback Localized fallback message.
 */
function appendPlanError( dispatch, error, fallback ) {
	dispatch.appendMessage( {
		role: 'system',
		parts: [
			{
				text: `${ __( 'Error:', 'superdav-ai-agent' ) } ${
					error?.message || fallback
				}`,
			},
		],
	} );
}

/**
 * Reconcile a durable-plan action or polling response with UI state.
 *
 * @param {Object} dispatch  Store dispatchers.
 * @param {Object} result    REST response.
 * @param {number} sessionId Session identifier.
 */
function handleDurablePlanResponse( dispatch, result, sessionId ) {
	const plan = result?.plan || result?.durable_plan;
	if ( plan ) {
		if ( [ 'completed', 'cancelled' ].includes( plan.status ) ) {
			dispatch.setPendingActionCard( null );
		} else {
			dispatch.setPendingActionCard( {
				type: 'durable_plan',
				sessionId,
				plan,
			} );
		}
	}

	if ( result?.job_id ) {
		dispatch.setCurrentJobId( result.job_id );
		dispatch.setSessionJob( sessionId, {
			jobId: result.job_id,
			toolCalls: [],
			status: 'processing',
		} );
		dispatch.pollJob( result.job_id, sessionId );
		return;
	}

	dispatch.setSending( false );
}

/**
 * Reconcile a durable-plan polling result with the current action card.
 *
 * @param {Object} context          Client action context.
 * @param {Object} context.dispatch Store dispatchers.
 * @param {Object} context.select   Store selectors.
 * @param {Object} plan             Browser-safe durable plan payload.
 * @param {number} sessionId        Session identifier.
 * @param {string} jobStatus        Background job status.
 */
export function syncDurablePlanCard(
	{ dispatch, select },
	plan,
	sessionId,
	jobStatus
) {
	if (
		! plan?.plan_id ||
		select.getCurrentSessionId() !== sessionId ||
		[ 'awaiting_confirmation', 'awaiting_client_tools' ].includes(
			jobStatus
		)
	) {
		return;
	}

	if ( [ 'completed', 'cancelled' ].includes( plan.status ) ) {
		if ( select.getPendingActionCard()?.type === 'durable_plan' ) {
			dispatch.setPendingActionCard( null );
		}
		return;
	}

	dispatch.setPendingActionCard( {
		type: 'durable_plan',
		sessionId,
		plan,
	} );
}

const DURABLE_PLAN_ACTIONS = {
	continue: {
		path: 'continue',
		failureMessage: __(
			'Unable to continue the durable plan.',
			'superdav-ai-agent'
		),
		setsSending: true,
	},
	approve: {
		path: 'approve',
		failureMessage: __(
			'Unable to approve the durable plan phase.',
			'superdav-ai-agent'
		),
		requiresApproval: true,
		setsSending: true,
	},
	reject: {
		path: 'reject',
		failureMessage: __(
			'Unable to reject the durable plan phase.',
			'superdav-ai-agent'
		),
		requiresApproval: true,
	},
	retry: {
		path: 'retry',
		failureMessage: __(
			'Unable to retry the durable plan phase.',
			'superdav-ai-agent'
		),
		setsSending: true,
	},
	cancel: {
		path: 'cancel',
		failureMessage: __(
			'Unable to cancel the durable plan.',
			'superdav-ai-agent'
		),
	},
};

/**
 * Fetch the latest durable plan for a chat session.
 *
 * @param {number} sessionId Session identifier.
 * @return {Promise<Object|null>} Browser-safe plan payload or null.
 */
export async function loadDurablePlan( sessionId ) {
	try {
		const result = await apiFetch( {
			path: `/sd-ai-agent/v1/sessions/${ sessionId }/plan`,
		} );
		return result?.plan || null;
	} catch {
		return null;
	}
}

/**
 * Execute one operator-selected durable-plan action.
 *
 * The server remains authoritative for phase ordering, fresh approval records,
 * scope checks, and retry eligibility.
 *
 * @param {string} actionName Action name.
 * @param {Object} context    Client action context.
 */
export async function runDurablePlanAction( actionName, context ) {
	const { dispatch, card } = context;
	const action = DURABLE_PLAN_ACTIONS[ actionName ];
	if ( ! action ) {
		return;
	}
	const sessionId = card?.sessionId;
	const planId = card?.plan?.plan_id;
	const approvalRequestId = card?.plan?.approval_request_id;
	if ( card?.type !== 'durable_plan' || ! sessionId || ! planId ) {
		return;
	}
	if ( action.requiresApproval && ! approvalRequestId ) {
		appendPlanError(
			dispatch,
			null,
			__(
				'The plan approval is no longer available. Refresh the plan before trying again.',
				'superdav-ai-agent'
			)
		);
		return;
	}

	const data = { plan_id: planId };
	if ( action.requiresApproval ) {
		data.approval_request_id = approvalRequestId;
	}

	if ( action.setsSending ) {
		dispatch.setSending( true );
	}
	try {
		const result = await apiFetch( {
			path: `/sd-ai-agent/v1/sessions/${ sessionId }/plan/${ action.path }`,
			method: 'POST',
			data,
		} );
		handleDurablePlanResponse( dispatch, result, sessionId );
	} catch ( error ) {
		appendPlanError( dispatch, error, action.failureMessage );
		if ( action.setsSending ) {
			dispatch.setSending( false );
		}
	}
}
