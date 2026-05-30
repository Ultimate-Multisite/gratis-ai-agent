/**
 * DOM morphing helpers for frontend live-preview reflectors.
 *
 * Keeps morph targets narrow and preserves user interaction state so live
 * preview updates do not clobber focus, scroll position, forms, or the chat UI.
 */

import morphdom from 'morphdom';

const WIDGET_ROOT_SELECTOR = '#sdaa-floating-root';

/**
 * Fetch a fresh copy of a frontend page as a parsed document.
 *
 * @param {string} url Page URL to fetch.
 * @return {Promise<Document>} Parsed fresh document.
 */
export async function fetchFreshPage( url ) {
	const freshUrl = new URL(
		url || window.location.href,
		window.location.origin
	);
	freshUrl.searchParams.set( '_', String( Date.now() ) );

	const res = await fetch( freshUrl.toString(), {
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
 * Determine whether an element is a form control whose transient state should
 * be preserved while its surrounding content is morphed.
 *
 * @param {Element} element Element to inspect.
 * @return {boolean} True when element is a form control.
 */
function isFormControl( element ) {
	return [ 'INPUT', 'TEXTAREA', 'SELECT', 'OPTION' ].includes(
		element.tagName
	);
}

/**
 * Copy current user-entered state onto morphdom's incoming element.
 *
 * @param {Element} fromEl Current element.
 * @param {Element} toEl   Fresh element.
 */
function preserveFormState( fromEl, toEl ) {
	if ( ! isFormControl( fromEl ) || ! isFormControl( toEl ) ) {
		return;
	}

	if ( 'value' in fromEl && 'value' in toEl ) {
		toEl.value = fromEl.value;
	}
	if ( 'checked' in fromEl && 'checked' in toEl ) {
		toEl.checked = fromEl.checked;
	}
	if ( 'selected' in fromEl && 'selected' in toEl ) {
		toEl.selected = fromEl.selected;
	}
}

/**
 * Morph the first matching selector from a fresh document into the current one.
 *
 * @param {Document|Element} currentDoc Current document or root element.
 * @param {Document|Element} freshDoc   Fresh document or root element.
 * @param {string}           selector   CSS selector to morph.
 * @return {boolean} True when a target was found and morphed.
 */
export function morphTargetFromFresh( currentDoc, freshDoc, selector ) {
	const current = currentDoc.querySelector( selector );
	const fresh = freshDoc.querySelector( selector );

	return morphTargetByElement( current, fresh );
}

/**
 * Morph a specific current element to match a fresh element.
 *
 * @param {Element|null} currentEl Current DOM element.
 * @param {Element|null} freshEl   Fresh DOM element.
 * @return {boolean} True when the element was morphed.
 */
export function morphTargetByElement( currentEl, freshEl ) {
	if ( ! currentEl || ! freshEl ) {
		return false;
	}

	const view = currentEl.ownerDocument.defaultView || window;
	const scrollX = view.scrollX;
	const scrollY = view.scrollY;
	const targetScrollTop = currentEl.scrollTop;
	const targetScrollLeft = currentEl.scrollLeft;

	morphdom( currentEl, freshEl.cloneNode( true ), {
		onBeforeElUpdated( fromEl, toEl ) {
			if ( fromEl === fromEl.ownerDocument.activeElement ) {
				return false;
			}

			if ( fromEl.closest?.( WIDGET_ROOT_SELECTOR ) ) {
				return false;
			}

			preserveFormState( fromEl, toEl );

			return ! fromEl.isEqualNode( toEl );
		},
	} );

	currentEl.scrollTop = targetScrollTop;
	currentEl.scrollLeft = targetScrollLeft;
	view.scrollTo( scrollX, scrollY );

	return true;
}
