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
 * Resolve the best managed-account destination for a credit-exhaustion action.
 *
 * Prefer the service-issued credit-purchase URL, then its account portal. The
 * local admin fallback lets a site owner reach the plugin's account settings
 * even when older provider metadata has not supplied either service URL yet.
 *
 * @param {Array} providers Provider objects from the store.
 * @return {string} Safe account URL, or an empty string when unavailable.
 */
export function getSuperdavAccountConnectUrl( providers ) {
	const provider = ( Array.isArray( providers ) ? providers : [] ).find(
		( item ) => item?.id === SUPERDAV_CLOUD_PROVIDER_ID
	);
	const status = provider?.status || {};
	const url =
		status.purchase_credits_url ||
		status.account_connect_url ||
		status.account_portal_url ||
		'';

	if ( typeof url === 'string' && /^https?:\/\//i.test( url.trim() ) ) {
		return url.trim();
	}

	return typeof window !== 'undefined' &&
		window.location.pathname.includes( '/wp-admin/' )
		? 'admin.php?page=sd-ai-agent#/settings'
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
				"You've used all of your available Superdav credits. Purchase more credits in your account settings to continue using Superdav Chat Pro.",
				'superdav-ai-agent'
			),
			getSuperdavAccountConnectUrl( providers ),
			'credit_exhausted',
		],
	};
}
