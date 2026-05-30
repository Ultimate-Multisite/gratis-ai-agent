/**
 * Reflect WordPress global styles updates into the current page.
 *
 * WordPress may render global styles as an inline `<style>` block or as a
 * stylesheet `<link>`, depending on theme support and persistence mode. This
 * reflector fetches a fresh copy of the current/affected page head and swaps
 * only the known global-styles nodes so unrelated theme/block CSS does not
 * flicker.
 */

const GLOBAL_INLINE_STYLE_ID = 'global-styles-inline-css';
const GLOBAL_STYLESHEET_IDS = [ 'wp_global_styles-css', 'global-styles-css' ];

/**
 * Re-fetch the page head and apply fresh global styles nodes when they change.
 *
 * @param {{ affected?: { url?: string } }} event Reflection event.
 * @return {Promise<void>} Resolves once reflection has been attempted.
 */
export async function reflectGlobalStyles( event ) {
	try {
		const fresh = await fetchFreshHead( event?.affected?.url );

		replaceInlineStyle( fresh, GLOBAL_INLINE_STYLE_ID );
		for ( const id of GLOBAL_STYLESHEET_IDS ) {
			replaceLinkStylesheet( fresh, id );
		}
	} catch ( err ) {
		// Reflector failure is non-fatal; the agent/tool result already landed.
		// eslint-disable-next-line no-console
		console.warn( '[sd-ai-agent] global-styles reflector failed', err );
	}
}

/**
 * Fetch and parse a fresh page document for global-style extraction.
 *
 * @param {string|undefined} url Optional affected page URL.
 * @return {Promise<Document>} Parsed fresh document.
 */
async function fetchFreshHead( url ) {
	const target = url || window.location.href;
	const bust = `${ target }${
		target.includes( '?' ) ? '&' : '?'
	}_=${ Date.now() }`;
	const res = await fetch( bust, {
		credentials: 'same-origin',
		cache: 'no-store',
		headers: { Accept: 'text/html' },
	} );

	if ( ! res.ok ) {
		throw new Error( `HTTP ${ res.status }` );
	}

	return new DOMParser().parseFromString( await res.text(), 'text/html' );
}

/**
 * Replace the current inline global styles text when the fresh page changed.
 *
 * @param {Document} freshDoc Freshly fetched document.
 * @param {string}   id       Element ID to compare.
 */
function replaceInlineStyle( freshDoc, id ) {
	const fresh = freshDoc.getElementById( id );
	const current = document.getElementById( id );

	if ( ! fresh || ! current || fresh.tagName !== 'STYLE' ) {
		return;
	}

	if ( fresh.textContent === current.textContent ) {
		return;
	}

	current.textContent = fresh.textContent;
}

/**
 * Swap a known global-styles stylesheet href when the fresh page changed.
 *
 * @param {Document} freshDoc Freshly fetched document.
 * @param {string}   id       Element ID to compare.
 */
function replaceLinkStylesheet( freshDoc, id ) {
	const fresh = freshDoc.getElementById( id );
	const current = document.getElementById( id );

	if ( ! fresh || ! current || fresh.tagName !== 'LINK' ) {
		return;
	}

	const freshHref = fresh.getAttribute( 'href' );
	if ( ! freshHref || freshHref === current.getAttribute( 'href' ) ) {
		return;
	}

	const url = new URL( freshHref, window.location.origin );
	url.searchParams.set( '_', String( Date.now() ) );
	current.setAttribute( 'href', url.toString() );
}
