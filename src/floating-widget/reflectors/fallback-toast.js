/**
 * Fallback reload toast for affected kinds without a dedicated reflector.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf, _n } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './fallback-toast.css';

let toastEl = null;
let count = 0;
let reloadPage = () => window.location.reload();

/**
 * Show or update the fallback reload toast for an unmapped reflection event.
 *
 * @param {Object} event Reflection event. Accepted for future strategy parity.
 */
export function showFallbackToast( event = {} ) {
	void event;

	if (
		typeof document === 'undefined' ||
		! document.body ||
		document.body.classList.contains( 'wp-admin' )
	) {
		return;
	}

	count++;

	if ( ! toastEl ) {
		toastEl = createToast();
		document.body.appendChild( toastEl );
	}

	updateToastText( toastEl, count );
}

/**
 * Create the fallback toast DOM node.
 *
 * @return {HTMLDivElement} Toast root element.
 */
function createToast() {
	const root = document.createElement( 'div' );
	root.className = 'sd-ai-agent-fallback-toast';
	root.setAttribute( 'role', 'status' );
	root.setAttribute( 'aria-live', 'polite' );

	const message = document.createElement( 'span' );
	message.className = 'sd-ai-agent-fallback-toast__msg';

	const reloadButton = document.createElement( 'button' );
	reloadButton.type = 'button';
	reloadButton.className = 'sd-ai-agent-fallback-toast__reload';
	reloadButton.textContent = __( 'Reload', 'superdav-ai-agent' );
	reloadButton.addEventListener( 'click', () => reloadPage() );

	const dismissButton = document.createElement( 'button' );
	dismissButton.type = 'button';
	dismissButton.className = 'sd-ai-agent-fallback-toast__dismiss';
	dismissButton.setAttribute(
		'aria-label',
		__( 'Dismiss', 'superdav-ai-agent' )
	);
	dismissButton.textContent = '×';
	dismissButton.addEventListener( 'click', dismissToast );

	root.append( message, reloadButton, dismissButton );

	return root;
}

/**
 * Update the toast message for the current pending update count.
 *
 * @param {HTMLElement} el Toast root element.
 * @param {number}      n  Pending update count.
 */
function updateToastText( el, n ) {
	const message = el.querySelector( '.sd-ai-agent-fallback-toast__msg' );

	if ( ! message ) {
		return;
	}

	message.textContent = sprintf(
		/* translators: %d: number of pending site updates. */
		_n(
			'Agent made %d update. Reload to see changes.',
			'Agent made %d updates. Reload to see changes.',
			n,
			'superdav-ai-agent'
		),
		n
	);
}

/**
 * Dismiss the toast and reset pending update count.
 */
function dismissToast() {
	if ( toastEl ) {
		toastEl.remove();
		toastEl = null;
	}

	count = 0;
}

/**
 * Reset module state for Jest tests.
 */
export function __resetFallbackToastForTests() {
	dismissToast();
	reloadPage = () => window.location.reload();
}

/**
 * Override the reload callback for Jest tests.
 *
 * @param {Function} callback Reload callback.
 */
export function __setFallbackToastReloadForTests( callback ) {
	reloadPage = callback;
}
