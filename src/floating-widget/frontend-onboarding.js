/**
 * Frontend onboarding helpers.
 *
 * The public floating widget can launch the same first-run Setup Assistant
 * flow as the admin chat page. These helpers keep the start sequencing
 * testable outside React.
 */

import defaultApiFetch from '@wordpress/api-fetch';

const ONBOARDING_START_PATH = '/sd-ai-agent/v1/onboarding/start';

/**
 * Coerce wp_localize_script booleans and REST booleans into a real boolean.
 *
 * @param {*} value Value to normalize.
 * @return {boolean} True only for explicit true-like values.
 */
function isTruthyFlag( value ) {
	return value === true || value === 1 || value === '1' || value === 'true';
}

/**
 * Whether the current page should run first-run onboarding from the widget.
 *
 * @param {Object} data Localized sdAiAgentData payload.
 * @return {boolean} True when the frontend widget should bootstrap onboarding.
 */
export function isFrontendOnboardingEnabled( data ) {
	if ( isTruthyFlag( data?.onboarding_complete ) ) {
		return false;
	}

	if ( data?.context ) {
		return data.context === 'frontend';
	}

	if ( isTruthyFlag( data?.isFrontend ) ) {
		return true;
	}

	const path = window.location?.pathname || '';
	const isAdmin =
		document.body?.classList?.contains( 'wp-admin' ) ||
		path.includes( '/wp-admin/' );

	return ! isAdmin;
}

/**
 * Detect whether the viewport should prefer a minimized mobile build view.
 *
 * @return {boolean} True for narrow screens.
 */
export function isMobileViewport() {
	return (
		typeof window !== 'undefined' &&
		window.matchMedia?.( '(max-width: 600px)' )?.matches === true
	);
}

/**
 * Whether live job activity contains a site-mutating response.
 *
 * The live-preview reflection bus uses `response.affected` as the contract for
 * changes that can be reflected into the current frontend page. The widget only
 * docks/minimizes after this signal appears, so early discovery/chat turns can
 * stay centered without blocking a live build.
 *
 * @param {Array} toolCalls Live tool-call/activity entries.
 * @return {boolean} True when a response carries an affected descriptor.
 */
export function hasLiveSiteChangeActivity( toolCalls ) {
	return ( toolCalls || [] ).some(
		( entry ) =>
			entry?.type === 'response' &&
			entry?.response?.affected &&
			typeof entry.response.affected === 'object'
	);
}

/**
 * Start frontend onboarding and send its first message when appropriate.
 *
 * @param {Object}   options                    Start options.
 * @param {Function} options.apiFetch           apiFetch-compatible function.
 * @param {Function} options.openSession        Store action to open a session.
 * @param {Function} options.sendMessage        Store action to send a message.
 * @param {Function} options.setSelectedAgentId Store action to select an agent.
 * @param {string}   options.fallbackMessage    Message used when REST omits one.
 * @return {Promise<Object|null>} Start metadata, or null if no session returned.
 */
export async function startFrontendOnboarding( {
	apiFetch = defaultApiFetch,
	openSession,
	sendMessage,
	setSelectedAgentId,
	fallbackMessage = "Hi! I'm ready to set up this site.",
} ) {
	const data = await apiFetch( {
		path: ONBOARDING_START_PATH,
		method: 'POST',
	} );

	if ( data?.agent_id ) {
		setSelectedAgentId( data.agent_id );
	}

	if ( ! data?.session_id ) {
		return null;
	}

	await openSession( data.session_id );

	await sendMessage( data.kickoff_message || fallbackMessage );

	return {
		data,
	};
}
