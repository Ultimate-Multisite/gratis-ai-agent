/**
 * Helpers for silent Superdav AI service connection onboarding.
 */

import { __, sprintf } from '@wordpress/i18n';

export const SUPERDAV_PROVIDER_ID = 'sd-ai-agent-cloud';

const NOTICE_STORAGE_PREFIX = 'sdAiAgentSuperdavConnectionNotice';

/**
 * Find the bundled Superdav AI provider in a provider list.
 *
 * @param {Array} providers Provider response rows.
 * @return {Object|null} Superdav provider row, or null.
 */
export function findSuperdavProvider( providers ) {
	if ( ! Array.isArray( providers ) ) {
		return null;
	}

	return (
		providers.find(
			( provider ) => provider?.id === SUPERDAV_PROVIDER_ID
		) || null
	);
}

/**
 * Build a localStorage key for a connection notice.
 *
 * @param {Object} status Safe Superdav connection status metadata.
 * @return {string} localStorage key.
 */
export function getConnectionNoticeStorageKey( status = {} ) {
	const installationId = status.installation_id || 'site';
	const connectedAt = status.connected_at || 'unknown';

	return `${ NOTICE_STORAGE_PREFIX }:${ installationId }:${ connectedAt }`;
}

/**
 * Format USD micros as a compact dollar amount.
 *
 * @param {number|string} micros USD micros.
 * @return {string} Formatted USD amount.
 */
export function formatUsdMicros( micros ) {
	const value = Number( micros );
	if ( ! Number.isFinite( value ) || value <= 0 ) {
		return '';
	}

	const dollars = value / 1000000;
	const hasCents = Math.round( dollars * 100 ) % 100 !== 0;
	return new Intl.NumberFormat( undefined, {
		style: 'currency',
		currency: 'USD',
		minimumFractionDigits: hasCents ? 2 : 0,
		maximumFractionDigits: 2,
	} ).format( dollars );
}

/**
 * Extract the starter/free-tier credit amount from safe status metadata.
 *
 * @param {Object} status Safe Superdav connection status metadata.
 * @return {string} Formatted starter credit, or default free-tier amount.
 */
export function getStarterCreditAmount( status = {} ) {
	const wallet = status.wallet || {};
	const amount =
		formatUsdMicros( wallet.promo_usd_micros ) ||
		formatUsdMicros( wallet.total_usd_micros );

	return amount || '$10';
}

/**
 * Build the transient chat notice shown after a new service token is created.
 *
 * @param {Object} status Safe Superdav connection status metadata.
 * @return {string} Notice text.
 */
export function buildConnectionNoticeText( status = {} ) {
	if ( status.tier === 'free' ) {
		return sprintf(
			/* translators: %s: formatted starter usage credit, for example $10. */
			__(
				'Superdav AI is connected. A secure site token was created and stored safely for this site; raw token values are never shown. This site is on the free tier with %s of starter usage credit.',
				'superdav-ai-agent'
			),
			getStarterCreditAmount( status )
		);
	}

	return __(
		'Superdav AI is connected. A secure site token was created and stored safely for this site; raw token values are never shown.',
		'superdav-ai-agent'
	);
}

/**
 * Remember that a connection notice was already displayed in this browser.
 *
 * @param {Object} status Safe Superdav connection status metadata.
 * @return {boolean} True when the notice may be shown now.
 */
export function claimConnectionNotice( status = {} ) {
	const key = getConnectionNoticeStorageKey( status );
	try {
		if ( window.localStorage?.getItem( key ) ) {
			return false;
		}
		window.localStorage?.setItem( key, '1' );
	} catch {
		// Storage failures should not suppress the one useful chat notice.
	}

	return true;
}
