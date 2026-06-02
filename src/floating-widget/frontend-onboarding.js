/**
 * Frontend onboarding helpers.
 *
 * The public floating widget can launch the same first-run Setup Assistant
 * flow as the admin chat page. These helpers keep the branching and endpoint
 * sequencing testable outside React.
 */

import defaultApiFetch from '@wordpress/api-fetch';

const CONTENT_PROBE_PATH = '/wp/v2/posts?per_page=2&status=publish';
const BOOTSTRAP_START_PATH = '/sd-ai-agent/v1/onboarding/bootstrap-start';
const THEME_BUILDER_START_PATH =
	'/sd-ai-agent/v1/onboarding/theme-builder-start';

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
 * Probe whether the site has more than the default seed post.
 *
 * Mirrors the admin-page heuristic: more than one published post means an
 * established site, while zero/one means the Theme Builder branch is safer.
 * Probe failures default to established-site onboarding.
 *
 * @param {Function} fetcher apiFetch-compatible function.
 * @return {Promise<boolean>} True when the site appears to have content.
 */
export async function probeSiteHasContent( fetcher ) {
	try {
		const posts = await fetcher( { path: CONTENT_PROBE_PATH } );
		return Array.isArray( posts ) && posts.length > 1;
	} catch {
		return true;
	}
}

/**
 * Pick the onboarding start endpoint for the detected site state.
 *
 * @param {boolean} siteHasContent Whether the site has published content.
 * @return {string} REST path to start onboarding.
 */
export function getFrontendOnboardingEndpoint( siteHasContent ) {
	return siteHasContent ? BOOTSTRAP_START_PATH : THEME_BUILDER_START_PATH;
}

/**
 * Whether the kickoff message should be auto-sent for the response.
 *
 * Theme-builder resumes set is_fresh_start=false so reloads do not duplicate
 * the first prompt. Established-site bootstrap should send its neutral kickoff.
 *
 * @param {boolean} siteHasContent Whether the site has published content.
 * @param {Object}  data           Onboarding start response.
 * @return {boolean} True when the kickoff should be sent.
 */
export function shouldSendFrontendOnboardingKickoff( siteHasContent, data ) {
	return siteHasContent || data?.is_fresh_start !== false;
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
	const siteHasContent = await probeSiteHasContent( apiFetch );
	const data = await apiFetch( {
		path: getFrontendOnboardingEndpoint( siteHasContent ),
		method: 'POST',
	} );

	if ( data?.agent_id ) {
		setSelectedAgentId( data.agent_id );
	}

	if ( ! data?.session_id ) {
		return null;
	}

	await openSession( data.session_id );

	if ( shouldSendFrontendOnboardingKickoff( siteHasContent, data ) ) {
		await sendMessage( data.kickoff_message || fallbackMessage );
	}

	return {
		data,
		siteHasContent,
	};
}
