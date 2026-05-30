/**
 * Navigation menu reflector.
 */

import { fetchFreshPage, morphTargetByElement } from './dom-morph';

const MENU_SELECTORS = [
	'nav.wp-block-navigation',
	'.wp-block-navigation',
	'.menu',
	'.nav-menu',
	'ul.wp-block-navigation__container',
];

/**
 * Refresh visible frontend navigation menus after an agent menu update.
 *
 * @param {Object} event Reflection event.
 */
export async function reflectMenu( event ) {
	try {
		const fresh = await fetchFreshPage(
			event?.affected?.url || window.location.href
		);

		for ( const selector of MENU_SELECTORS ) {
			const currents = document.querySelectorAll( selector );
			const freshes = fresh.querySelectorAll( selector );
			const pairs = Math.min( currents.length, freshes.length );

			for ( let i = 0; i < pairs; i++ ) {
				morphTargetByElement( currents[ i ], freshes[ i ] );
			}
		}
	} catch ( err ) {
		// eslint-disable-next-line no-console
		console.warn( '[sd-ai-agent] menu reflector failed', err );
	}
}
