/**
 * Automatic feedback reporting preferences and submission helpers.
 */

import apiFetch from '@wordpress/api-fetch';

export const FEEDBACK_REPORTING_PREFERENCE_KEY =
	'sdAiAgentFeedbackReportingPreference';

export const FEEDBACK_REPORTING_PREFERENCES = {
	ASK: 'ask',
	NEVER: 'never',
	ALWAYS: 'always',
};

const pendingReports = new Map();

/**
 * Scope persistent consent to the logged-in WordPress user.
 *
 * @return {string} Browser storage key for the current user.
 */
function getPreferenceStorageKey() {
	const currentUserId = globalThis.sdAiAgentData?.currentUserId;
	return currentUserId
		? `${ FEEDBACK_REPORTING_PREFERENCE_KEY }:${ currentUserId }`
		: FEEDBACK_REPORTING_PREFERENCE_KEY;
}

/**
 * Read the browser-local automatic reporting preference.
 *
 * @return {'ask'|'never'|'always'} Saved preference.
 */
export function getFeedbackReportingPreference() {
	try {
		const preference = localStorage.getItem( getPreferenceStorageKey() );
		if (
			preference === FEEDBACK_REPORTING_PREFERENCES.NEVER ||
			preference === FEEDBACK_REPORTING_PREFERENCES.ALWAYS
		) {
			return preference;
		}
	} catch {
		// Storage may be unavailable in privacy-restricted browser contexts.
	}

	return FEEDBACK_REPORTING_PREFERENCES.ASK;
}

/**
 * Persist the browser-local automatic reporting preference.
 *
 * @param {'ask'|'never'|'always'} preference Preference to save.
 */
export function setFeedbackReportingPreference( preference ) {
	try {
		if ( preference === FEEDBACK_REPORTING_PREFERENCES.ASK ) {
			localStorage.removeItem( getPreferenceStorageKey() );
			return;
		}
		localStorage.setItem( getPreferenceStorageKey(), preference );
	} catch {
		// A blocked storage write must not prevent one-time reporting.
	}
}

/**
 * Whether a terminal tool log contains an explicit failed response.
 *
 * @param {Array} toolCalls Flat tool call and response entries.
 * @return {boolean} True when a tool response explicitly failed.
 */
export function toolCallsContainFailure( toolCalls ) {
	if ( ! Array.isArray( toolCalls ) ) {
		return false;
	}

	return toolCalls.some( ( entry ) => {
		if ( entry?.status === 'error' ) {
			return true;
		}

		if ( entry?.type !== 'response' && entry?.type !== 'result' ) {
			return false;
		}

		const result = entry.response ?? entry.result;
		const nestedResult = result?.result;
		return Boolean(
			result &&
				typeof result === 'object' &&
				( result.success === false ||
					result.error ||
					( nestedResult &&
						typeof nestedResult === 'object' &&
						( nestedResult.success === false ||
							nestedResult.error ) ) )
		);
	} );
}

/**
 * Submit one sanitized automatic feedback report.
 *
 * Concurrent mounts can observe the same store event. Reuse the in-flight
 * request so the main chat and floating widget cannot submit duplicate reports.
 *
 * @param {number} sessionId Current session ID.
 * @param {Object} failure   Detected failure metadata.
 * @return {Promise<*>} Feedback endpoint response.
 */
export function submitAutomaticFeedback( sessionId, failure ) {
	const failureReason = failure?.reason || failure?.exitReason || 'job_error';
	const eventKey = `${ sessionId }:${ failure?.eventId || failureReason }`;
	if ( pendingReports.has( eventKey ) ) {
		return pendingReports.get( eventKey );
	}

	const reason = String( failureReason ).replace( /[_-]+/g, ' ' );
	const request = apiFetch( {
		path: '/sd-ai-agent/v1/feedback/send',
		method: 'POST',
		data: {
			report_type: 'self_reported',
			user_description: `Automatically detected after the agent finished: ${ reason }.`,
			session_id: sessionId,
			strip_tool_results: false,
		},
	} ).finally( () => pendingReports.delete( eventKey ) );

	pendingReports.set( eventKey, request );
	return request;
}
