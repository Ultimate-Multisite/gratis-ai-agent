/**
 * Friendly account-action helpers for Superdav managed-credit notices.
 */

import { __ } from '@wordpress/i18n';

const SUPERDAV_CLOUD_PROVIDER_ID = 'sd-ai-agent-cloud';

/**
 * Determine whether a provider error is the managed Superdav credit/payment
 * state that should be shown as an account action instead of an error.
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
 * Resolve the magic account-login URL exposed by the managed Superdav provider.
 *
 * @param {Array} providers Provider objects from the store.
 * @return {string} Safe account URL, or an empty string when unavailable.
 */
export function getSuperdavAccountConnectUrl( providers ) {
	const provider = ( Array.isArray( providers ) ? providers : [] ).find(
		( item ) => item?.id === SUPERDAV_CLOUD_PROVIDER_ID
	);
	const url = provider?.status?.account_connect_url || '';

	return typeof url === 'string' && /^https?:\/\//i.test( url.trim() )
		? url.trim()
		: '';
}

/**
 * Build the system message rendered by the chat UI for managed-credit notices.
 *
 * @param {Array} providers Provider objects from the store.
 * @return {Object} Chat message with notice metadata for the renderer.
 */
export function buildSuperdavCreditNoticeMessage( providers ) {
	return {
		role: 'system',
		notice: [
			__(
				"You've used all of your available SD AI credits. Purchase more credits in your account settings to continue using Standard.",
				'superdav-ai-agent'
			),
			getSuperdavAccountConnectUrl( providers ),
		],
	};
}
