/**
 * Current-post live preview reflector.
 *
 * Fetches the affected post permalink and morphs only content selectors that
 * correspond to changed fields when the viewer is on that public permalink.
 */

import { morphTargetFromFresh } from './dom-morph';

const FIELD_SELECTORS = {
	post_title: [ '.wp-block-post-title', '.entry-title', 'h1.entry-title' ],
	post_content: [
		'.wp-block-post-content',
		'.entry-content',
		// Block themes can render the homepage/front-page template without a
		// dedicated post-content wrapper. In that case, morph the public site
		// block tree so content edited through the agent appears immediately.
		'.wp-site-blocks',
	],
	post_excerpt: [ '.wp-block-post-excerpt', '.entry-summary' ],
	featured_image: [
		'.wp-block-post-featured-image img',
		'.post-thumbnail img',
	],
};

/**
 * Normalize a URL to a comparable path without query, hash, or trailing slash.
 *
 * @param {string} url URL or path to normalize.
 * @return {string} Normalized pathname.
 */
function normalizePath( url ) {
	try {
		const parsed = new URL( url, window.location.origin );
		return parsed.pathname.replace( /\/+$/, '' ) || '/';
	} catch {
		return '';
	}
}

/**
 * Determine whether the affected permalink describes the current public page.
 *
 * @param {string}        url      Affected permalink.
 * @param {number|string} [postId] Affected post ID for plain permalink checks.
 * @return {boolean} True when the viewer is on the affected post.
 */
export function isCurrentLocation( url, postId ) {
	if ( normalizePath( url ) === normalizePath( window.location.href ) ) {
		return true;
	}

	try {
		const current = new URL( window.location.href );
		return Boolean(
			postId && current.searchParams.get( 'p' ) === String( postId )
		);
	} catch {
		return false;
	}
}

/**
 * Fetch a cache-busted copy of a public post page.
 *
 * @param {string} url Public post URL.
 * @return {Promise<Document>} Fresh DOM document.
 */
export async function fetchFreshPage( url ) {
	const freshUrl = new URL( url, window.location.origin );
	freshUrl.searchParams.set( '_', String( Date.now() ) );

	const response = await fetch( freshUrl.toString(), {
		credentials: 'same-origin',
		cache: 'no-store',
		headers: {
			Accept: 'text/html',
			'X-Sd-Ai-Agent-Reflector': 'post',
		},
	} );

	if ( ! response.ok ) {
		throw new Error( `HTTP ${ response.status }` );
	}

	const html = await response.text();
	return new DOMParser().parseFromString( html, 'text/html' );
}

/**
 * Pick the first selector for each field that exists in both documents.
 *
 * @param {Document} currentDoc Current document.
 * @param {Document} freshDoc   Fresh document.
 * @param {string[]} fields     Changed affected fields.
 * @return {string[]} Selectors to morph.
 */
export function pickTargetsForFields( currentDoc, freshDoc, fields = [] ) {
	const selectors = [];

	for ( const field of fields ) {
		const candidates = FIELD_SELECTORS[ field ] || [];
		const selector = candidates.find(
			( candidate ) =>
				currentDoc.querySelector( candidate ) &&
				freshDoc.querySelector( candidate )
		);

		if ( selector && ! selectors.includes( selector ) ) {
			selectors.push( selector );
		}
	}

	return selectors;
}

/**
 * Reflect a post update into the visible public post DOM.
 *
 * @param {{affected?: {url?: string, post_id?: number|string, fields?: string[]}}} event Reflection event.
 */
export async function reflectPost( event ) {
	const { affected } = event;

	if (
		! affected?.url ||
		document.body.classList.contains( 'block-editor-page' ) ||
		! isCurrentLocation( affected.url, affected.post_id )
	) {
		return;
	}

	try {
		const fresh = await fetchFreshPage( affected.url );
		const selectors = pickTargetsForFields(
			document,
			fresh,
			Array.isArray( affected.fields ) ? affected.fields : []
		);

		for ( const selector of selectors ) {
			morphTargetFromFresh( document, fresh, selector );
		}
	} catch ( err ) {
		// eslint-disable-next-line no-console
		console.warn( '[sd-ai-agent] post reflector failed', err );
	}
}
