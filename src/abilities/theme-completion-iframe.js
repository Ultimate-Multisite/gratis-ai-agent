/**
 * Load frontend documents for generated-theme completion validation.
 *
 * This module is imported only by the Setup Assistant completion validator.
 * Keeping it separate prevents deterministic theme QA from increasing the
 * customer-facing floating-widget bundle.
 */

import { validateScreenshotUrl } from './screenshot';

/** How long to wait for an iframe page to load. */
const IFRAME_LOAD_TIMEOUT = 15000;

/** Extra settle time after the iframe load event. */
const IFRAME_SETTLE_DELAY = 1500;

/**
 * Load a same-origin frontend URL in an off-screen iframe and expose its DOM.
 *
 * Callers must invoke the returned cleanup function after inspection.
 *
 * @param {Object} args              Iframe arguments.
 * @param {string} args.url          Absolute or site-relative frontend URL.
 * @param {number} [args.width=1280] Iframe viewport width.
 * @param {number} [args.height=800] Iframe viewport height.
 * @return {Promise<{success: boolean, url: string, error: string, iframe: HTMLIFrameElement|null, document: Document|null, window: Window|null, cleanup: () => void}>} Loaded iframe state.
 */
export async function loadSameOriginIframe( args ) {
	const rawUrl = args?.url || '';
	const viewportWidth = args?.width || 1280;
	const viewportHeight = args?.height || 800;
	const {
		valid,
		resolved,
		error: urlError,
	} = validateScreenshotUrl( rawUrl );
	let iframe = null;

	const cleanup = () => {
		if ( iframe && iframe.parentNode ) {
			iframe.parentNode.removeChild( iframe );
		}
	};

	if ( ! valid ) {
		return {
			success: false,
			url: rawUrl,
			error: urlError,
			iframe: null,
			document: null,
			window: null,
			cleanup,
		};
	}

	try {
		iframe = document.createElement( 'iframe' );
		iframe.style.cssText = [
			'position: fixed',
			'top: -20000px',
			'left: -20000px',
			`width: ${ viewportWidth }px`,
			`height: ${ viewportHeight }px`,
			'border: none',
			'opacity: 0',
			'pointer-events: none',
			'z-index: -9999',
		].join( '; ' );
		iframe.setAttribute( 'aria-hidden', 'true' );
		iframe.setAttribute( 'tabindex', '-1' );

		const loadPromise = new Promise( ( resolveLoad, rejectLoad ) => {
			const timer = setTimeout( () => {
				rejectLoad( new Error( 'Iframe load timed out.' ) );
			}, IFRAME_LOAD_TIMEOUT );

			iframe.addEventListener( 'load', () => {
				clearTimeout( timer );
				resolveLoad();
			} );

			iframe.addEventListener( 'error', () => {
				clearTimeout( timer );
				rejectLoad( new Error( 'Iframe failed to load.' ) );
			} );
		} );

		iframe.src = resolved;
		document.body.appendChild( iframe );
		await loadPromise;
		await new Promise( ( resolve ) =>
			setTimeout( resolve, IFRAME_SETTLE_DELAY )
		);

		const iframeDocument =
			iframe.contentDocument || iframe.contentWindow?.document;
		const iframeWindow = iframe.contentWindow;
		if ( ! iframeDocument || ! iframeDocument.body || ! iframeWindow ) {
			cleanup();
			return {
				success: false,
				url: resolved,
				error: 'Cannot access iframe content. The page may block framing.',
				iframe: null,
				document: null,
				window: null,
				cleanup,
			};
		}

		const adminBarStyle = iframeDocument.createElement( 'style' );
		adminBarStyle.textContent = [
			'#wpadminbar { display: none !important; }',
			'html { margin-top: 0 !important; }',
			'* html body { margin-top: 0 !important; }',
		].join( ' ' );
		( iframeDocument.head || iframeDocument.documentElement ).appendChild(
			adminBarStyle
		);

		return {
			success: true,
			// contentWindow.location is the final same-origin document after a
			// canonical redirect; callers retain their requested URL separately.
			url: iframeWindow.location.href || resolved,
			error: '',
			iframe,
			document: iframeDocument,
			window: iframeWindow,
			cleanup,
		};
	} catch ( err ) {
		cleanup();
		return {
			success: false,
			url: resolved || rawUrl,
			error: err.message,
			iframe: null,
			document: null,
			window: null,
			cleanup,
		};
	}
}
