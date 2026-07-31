/**
 * Frontend onboarding helpers.
 *
 * The public floating widget can launch the same first-run Setup Assistant
 * flow as the admin chat page. These helpers keep the start sequencing
 * testable outside React.
 */

import defaultApiFetch from '@wordpress/api-fetch';

const ONBOARDING_START_PATH = '/sd-ai-agent/v1/onboarding/start';
const IN_FLIGHT_JOB_STATUSES = [
	'processing',
	'awaiting_confirmation',
	'pending_proposal',
	'awaiting_client_tools',
];

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

export { hasLiveSiteChangeActivity as hasActivity };

/**
 * Pick the conversation the frontend widget should hydrate on page load.
 *
 * Running sessions win over recency so a long-running build submitted from the
 * widget remains visible after navigation/reload. If nothing is running, fall
 * back to the newest session from the server list.
 *
 * @param {Array}  sessions    Session summaries from /sessions.
 * @param {Object} sessionJobs Map of sessionId → active job metadata.
 * @return {number|null} Session ID to open, or null when no session exists.
 */
export function getHydrationSessionId( sessions, sessionJobs = {} ) {
	let latestId = null;

	for ( const session of Array.isArray( sessions ) ? sessions : [] ) {
		const id =
			typeof session?.id === 'string'
				? parseInt( session.id, 10 )
				: session?.id;

		if ( ! Number.isFinite( id ) ) {
			continue;
		}

		if ( latestId === null ) {
			latestId = id;
		}

		const job = sessionJobs?.[ id ] || sessionJobs?.[ String( id ) ];
		if ( job?.status && IN_FLIGHT_JOB_STATUSES.includes( job.status ) ) {
			return id;
		}
	}

	return latestId;
}

/**
 * Determine whether the widget should reopen a persisted conversation.
 *
 * An empty current session normally means the widget is mounting after a page
 * load or navigation, so it should restore the active/latest conversation.
 * An explicit new-chat action uses the same empty state but must retain it
 * until the user sends the first message in the new conversation.
 *
 * @param {Object}  options
 * @param {number}  options.sessionCount     Number of persisted sessions.
 * @param {?number} options.currentSessionId Currently opened session ID.
 * @param {boolean} options.isNewChatPending Whether the user started a new chat.
 * @return {boolean} Whether persisted-session hydration should run.
 */
export function shouldHydrateSession( {
	sessionCount,
	currentSessionId,
	isNewChatPending,
} ) {
	return sessionCount > 0 && ! currentSessionId && ! isNewChatPending;
}

/**
 * Determine whether first-run frontend onboarding may start.
 *
 * Onboarding must wait until sessions have loaded. Otherwise a reload during a
 * real submitted build can briefly look empty and bootstrap a fresh setup
 * session before the existing conversation list arrives.
 *
 * @param {Object}  options
 * @param {boolean} options.enabled          Frontend onboarding flag.
 * @param {boolean} options.started          Whether this page already started onboarding.
 * @param {boolean} options.providersLoaded  Whether providers finished loading.
 * @param {number}  options.providerCount    Number of available providers.
 * @param {boolean} options.sessionsLoaded   Whether sessions finished loading.
 * @param {number}  options.sessionCount     Number of existing sessions.
 * @param {?number} options.currentSessionId Currently opened session ID.
 * @return {boolean} True when it is safe to create the setup session.
 */
export function shouldStartFrontendOnboarding( {
	enabled,
	started,
	providersLoaded,
	providerCount,
	sessionsLoaded,
	sessionCount,
	currentSessionId,
} ) {
	return (
		!! enabled &&
		! started &&
		!! providersLoaded &&
		providerCount > 0 &&
		!! sessionsLoaded &&
		sessionCount === 0 &&
		! currentSessionId
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
export async function startOnboarding( {
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
