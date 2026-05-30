/**
 * DOM morphing helpers for frontend live-preview reflectors.
 */

import morphdom from 'morphdom';

const REFLECTOR_HEADER = 'X-Sd-Ai-Agent-Reflector';

/**
 * Fetch a fresh copy of a frontend page as a parsed document.
 *
 * `cache: 'no-store'` bypasses the browser cache. The timestamp query parameter
 * is required for page caches/CDNs that key by URL and ignore request
 * Cache-Control headers.
 *
 * @param {string}       url       Page URL to fetch.
 * @param {typeof fetch} fetchImpl Fetch implementation for tests.
 * @return {Promise<Document>} Parsed fresh document.
 */
export async function fetchFreshPage( url, fetchImpl = window.fetch ) {
	const target = new URL( url || window.location.href, window.location.href );
	target.searchParams.set( '_', String( Date.now() ) );

	const res = await fetchImpl( target.toString(), {
		credentials: 'same-origin',
		cache: 'no-store',
		headers: {
			Accept: 'text/html',
			[ REFLECTOR_HEADER ]: '1',
		},
	} );

	if ( ! res.ok ) {
		throw new Error( `HTTP ${ res.status }` );
	}

	return new DOMParser().parseFromString( await res.text(), 'text/html' );
}

/**
 * Morph the first matching selector from a fresh document into the current one.
 *
 * @param {Document|Element} currentDoc Current document or root element.
 * @param {Document|Element} freshDoc   Fresh document or root element.
 * @param {string}           selector   CSS selector to morph.
 */
export function morphTargetFromFresh( currentDoc, freshDoc, selector ) {
	const current = currentDoc.querySelector( selector );
	const fresh = freshDoc.querySelector( selector );

	if ( ! current || ! fresh ) {
		return;
	}

	morphTargetByElement( current, fresh );
}

/**
 * Morph a specific current element to match a fresh element.
 *
 * @param {Element} currentEl Current DOM element.
 * @param {Element} freshEl   Fresh DOM element.
 */
export function morphTargetByElement( currentEl, freshEl ) {
	if ( ! currentEl || ! freshEl ) {
		return;
	}

	morphdom( currentEl, freshEl.cloneNode( true ), {
		onBeforeElUpdated( fromEl, toEl ) {
			if ( fromEl === fromEl.ownerDocument.activeElement ) {
				return false;
			}

			if ( fromEl.closest && fromEl.closest( '#sdaa-floating-root' ) ) {
				return false;
			}

			return ! fromEl.isEqualNode( toEl );
		},
	} );
}

export { REFLECTOR_HEADER };
