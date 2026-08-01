/**
 * Friendly account-action helpers for Superdav managed-credit notices.
 */

const SUPERDAV_CLOUD_PROVIDER_ID = 'sd-ai-agent-cloud';

/** Safe diagnostic reason shared by every chat runtime. */
export const CREDIT_EXHAUSTED_REASON = 'credit_exhausted';

/** Semantic recovery action shared by every chat runtime. */
export const PURCHASE_CREDITS_ACTION = 'purchase_credits';

/**
 * Determine whether a provider error is the managed Superdav credit/payment
 * state that should be shown as an account action instead of an error.
 *
 * This is a compatibility fallback for older message records. New job failures
 * use the safe `credit_exhausted` diagnostic instead of provider text.
 *
 * @param {string} message Provider/job error message.
 * @return {boolean} True when the message matches the Superdav credit state.
 */
export function isSuperdavCreditBalanceNotice( message ) {
	const text = typeof message === 'string' ? message.toLowerCase() : '';
	return (
		text.includes( 'superdav' ) &&
		text.includes( 'credit' ) &&
		( text.includes( '402' ) ||
			text.includes( 'payment information' ) ||
			text.includes( 'purchase more credit' ) ||
			text.includes( 'insufficient' ) )
	);
}

/**
 * Return an absolute HTTP(S) URL or an empty string.
 *
 * @param {*} value Candidate URL.
 * @return {string} Safe absolute URL.
 */
function normalizeAccountUrl( value ) {
	return typeof value === 'string' && /^https?:\/\//i.test( value.trim() )
		? value.trim()
		: '';
}

/**
 * Resolve the best managed-account destination for a credit-exhaustion action.
 *
 * Prefer service-issued account URLs, then the absolute settings URL localized
 * by WordPress for both admin and frontend chat bundles.
 *
 * @param {Array}  providers     Provider objects from the store.
 * @param {Object} [runtimeData] Localized WordPress runtime configuration.
 * @return {string} Safe account URL, or an empty string when unavailable.
 */
export function getSuperdavAccountActionUrl(
	providers,
	runtimeData = typeof window !== 'undefined'
		? window.sdAiAgentData || {}
		: {}
) {
	const provider = ( Array.isArray( providers ) ? providers : [] ).find(
		( item ) => item?.id === SUPERDAV_CLOUD_PROVIDER_ID
	);
	const status = provider?.status || {};
	const candidates = [
		status.purchase_credits_url,
		status.account_connect_url,
		status.account_portal_url,
		runtimeData?.settingsPageUrl,
	];

	for ( const candidate of candidates ) {
		const url = normalizeAccountUrl( candidate );
		if ( url ) {
			return url;
		}
	}

	return '';
}

/**
 * Build the semantic system message rendered for managed-credit exhaustion.
 *
 * Presentation copy belongs to the shared account-action renderer, not the
 * store payload, so every React chat surface renders the same notice.
 *
 * @param {Array}  providers     Provider objects from the store.
 * @param {Object} [runtimeData] Localized WordPress runtime configuration.
 * @return {Object} Chat message with structured account-action metadata.
 */
export function buildSuperdavCreditNoticeMessage( providers, runtimeData ) {
	return {
		role: 'system',
		notice: {
			type: 'account_action',
			reason: CREDIT_EXHAUSTED_REASON,
			action: PURCHASE_CREDITS_ACTION,
			actionUrl: getSuperdavAccountActionUrl( providers, runtimeData ),
		},
	};
}
