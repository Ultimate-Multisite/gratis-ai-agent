/**
 * Client-side screenshot abilities.
 *
 * Two abilities for visual page review:
 *
 *   1. capture-screenshot — captures the page the user is currently viewing.
 *      Runs html2canvas on the live DOM and returns a base64 JPEG.
 *
 *   2. screenshot-url — loads any same-origin URL in a hidden iframe, waits
 *      for it to render, captures it with html2canvas, then tears down the
 *      iframe. Lets the agent review frontend pages without navigating the
 *      user away from wp-admin.
 *
 * Both abilities return { success, image, width, height, url, error } where
 * `image` is a base64-encoded JPEG data URL suitable for sending to a
 * vision-capable model.
 *
 * Security: screenshot-url restricts targets to WordPress-owned origins exposed
 * by the server. The actual capture remains same-origin because html2canvas
 * needs DOM access to the iframe. Authorized multisite subdomains that are not
 * the current browser origin return explicit guidance instead of being reported
 * as unrelated off-site URLs. The iframe is intentionally NOT `sandbox`'d,
 * because a sandboxed iframe gets an opaque origin that would block the
 * same-origin DOM access html2canvas needs.
 */

import { registerClientAbility } from './registry';

/* ── Constants ─────────────────────────────────────────────────────────── */

/** Maximum width (px) for the captured image to control token cost. */
const MAX_IMAGE_WIDTH = 1024;

/** JPEG quality (0-1). Balances clarity vs size for vision model input. */
const JPEG_QUALITY = 0.8;

/** How long to wait (ms) for an iframe page to fully load before capture. */
const IFRAME_LOAD_TIMEOUT = 15000;

/** Extra settle time (ms) after iframe load event for async renders. */
const IFRAME_SETTLE_DELAY = 1500;

/**
 * Maximum capture height (px) for fullPage mode.
 *
 * html2canvas allocates a canvas of width * height * 4 bytes (RGBA). At
 * 1280 x 16000 that is ~82 MB — tolerable. At 1280 x 40000 it is ~200 MB,
 * which will OOM-crash tabs on low-memory devices. 10000 px is roughly
 * 12 viewport-heights of content — more than enough for visual review.
 * Heights beyond this are clamped and flagged via `truncated: true`.
 */
const MAX_CAPTURE_HEIGHT = 10000;

/**
 * Step size (px) for the scroll pass that triggers lazy-loaded content.
 * Smaller values catch more IntersectionObserver thresholds but take longer.
 * One viewport-height per step is the sweet spot — each step brings a full
 * new screen of images into the intersection root.
 */
const SCROLL_STEP = 800;

/** Pause (ms) between scroll steps to let lazy loaders fire and settle. */
const SCROLL_STEP_DELAY = 150;

/* ── Helpers ───────────────────────────────────────────────────────────── */

/**
 * Downscale a canvas if its width exceeds MAX_IMAGE_WIDTH.
 *
 * Returns a new canvas (or the original if no downscale needed) and exports
 * it as a JPEG data URL.
 *
 * @param {HTMLCanvasElement} canvas Source canvas from html2canvas.
 * @return {{ dataUrl: string, width: number, height: number }} JPEG data URL and dimensions.
 */
function canvasToJpeg( canvas ) {
	let target = canvas;

	if ( canvas.width > MAX_IMAGE_WIDTH ) {
		const scale = MAX_IMAGE_WIDTH / canvas.width;
		const w = Math.round( canvas.width * scale );
		const h = Math.round( canvas.height * scale );

		target = document.createElement( 'canvas' );
		target.width = w;
		target.height = h;

		const ctx = target.getContext( '2d' );
		ctx.drawImage( canvas, 0, 0, w, h );
	}

	return {
		dataUrl: target.toDataURL( 'image/jpeg', JPEG_QUALITY ),
		width: target.width,
		height: target.height,
	};
}

/**
 * Return origins the server identified as WordPress-owned screenshot targets.
 *
 * @return {string[]} Allowed origins.
 */
export function getAuthorizedScreenshotOrigins() {
	const origins = window.sdAiAgentData?.screenshotOrigins;
	if ( ! Array.isArray( origins ) ) {
		return [ window.location.origin ];
	}

	return Array.from(
		new Set(
			origins
				.filter( ( origin ) => typeof origin === 'string' )
				.map( ( origin ) => origin.trim() )
				.filter( Boolean )
		)
	);
}

/**
 * Validate that a URL is within an authorized WordPress origin and can be
 * captured from the current browser page.
 *
 * @param {string} url Absolute or relative URL.
 * @return {{ valid: boolean, resolved: string, error: string, authorized: boolean }} Validation result.
 */
export function validateScreenshotUrl( url ) {
	if ( ! url ) {
		return {
			valid: false,
			resolved: '',
			error: 'URL is required.',
			authorized: false,
		};
	}

	try {
		// Resolve relative URLs against current origin.
		const resolved = new URL( url, window.location.origin );
		const authorizedOrigins = getAuthorizedScreenshotOrigins();
		const isAuthorized = authorizedOrigins.includes( resolved.origin );

		if ( ! isAuthorized ) {
			return {
				valid: false,
				resolved: resolved.href,
				error:
					`URL must be on an authorized WordPress site (${ authorizedOrigins.join( ', ' ) }). ` +
					`Got: ${ resolved.origin }`,
				authorized: false,
			};
		}

		if ( resolved.origin !== window.location.origin ) {
			return {
				valid: false,
				resolved: resolved.href,
				error:
					`URL is an authorized WordPress site (${ resolved.origin }), but browser screenshot capture must run from the same origin as the target. ` +
					`Open the AI agent on ${ resolved.origin } or navigate the browser there, then retry screenshot-url.`,
				authorized: true,
			};
		}

		return {
			valid: true,
			resolved: resolved.href,
			error: '',
			authorized: true,
		};
	} catch ( err ) {
		return {
			valid: false,
			resolved: '',
			error: `Invalid URL: ${ err.message }`,
			authorized: false,
		};
	}
}

/**
 * Scroll a window/document top-to-bottom in steps to trigger lazy-loaded
 * images and IntersectionObserver callbacks.
 *
 * WordPress adds `loading="lazy"` to all `<img>` tags by default (since 5.5).
 * Images below the initial viewport never enter the intersection root of an
 * off-screen iframe, so they remain as empty placeholders in the capture.
 * Scrolling the iframe's contentWindow in increments forces each slice of
 * content through the viewport, triggering native lazy-load and any
 * JS-based lazy loaders.
 *
 * After the scroll pass the window is scrolled back to top for capture.
 *
 * @param {Window}   win       The window to scroll (e.g. iframe.contentWindow).
 * @param {Document} doc       The document for measuring scrollHeight.
 * @param {number}   maxHeight Stop scrolling past this height (the clamped capture height).
 * @return {Promise<void>} Resolves when the scroll pass is complete.
 */
async function scrollToRevealLazyContent( win, doc, maxHeight ) {
	const scrollTarget = Math.min(
		doc.documentElement.scrollHeight,
		maxHeight
	);

	for ( let y = 0; y < scrollTarget; y += SCROLL_STEP ) {
		win.scrollTo( 0, y );
		// eslint-disable-next-line no-await-in-loop
		await new Promise( ( r ) => setTimeout( r, SCROLL_STEP_DELAY ) );
	}

	// Scroll to the very bottom to catch the last slice, then back to top.
	win.scrollTo( 0, scrollTarget );
	await new Promise( ( r ) => setTimeout( r, SCROLL_STEP_DELAY ) );
	win.scrollTo( 0, 0 );

	// Brief settle for any final image decode / layout reflow.
	await new Promise( ( r ) => setTimeout( r, 300 ) );
}

/* ── Ability 1: capture-screenshot ─────────────────────────────────────── */

/**
 * Capture a screenshot of the current page (or a specific element).
 *
 * @param {Object}  args
 * @param {string}  [args.selector] CSS selector to capture. Defaults to document body.
 * @param {boolean} [args.fullPage] Capture the full scrollable page, not just viewport.
 * @return {Promise<Object>} Screenshot result.
 */
async function executeCaptureScreenshot( args ) {
	const selector = args?.selector || '';
	const fullPage = args?.fullPage ?? false;

	try {
		let target = document.body;

		if ( selector ) {
			const el = document.querySelector( selector );
			if ( ! el ) {
				return {
					success: false,
					image: '',
					width: 0,
					height: 0,
					url: window.location.href,
					error: `Element not found for selector: ${ selector }`,
				};
			}
			target = el;
		}

		// For fullPage captures: clamp height to avoid OOM from huge canvases,
		// and scroll through the page to trigger lazy-loaded images.
		let captureHeight;
		let truncated = false;

		if ( fullPage && target === document.body ) {
			const rawHeight = document.documentElement.scrollHeight;
			captureHeight = Math.min( rawHeight, MAX_CAPTURE_HEIGHT );
			truncated = rawHeight > MAX_CAPTURE_HEIGHT;

			const originalScrollX = window.scrollX;
			const originalScrollY = window.scrollY;
			try {
				await scrollToRevealLazyContent(
					window,
					document,
					captureHeight
				);
			} finally {
				window.scrollTo( originalScrollX, originalScrollY );
			}
		}

		const { default: html2canvas } = await import( 'html2canvas' );
		const canvas = await html2canvas( target, {
			useCORS: true,
			allowTaint: false,
			logging: false,
			windowWidth:
				target === document.body
					? document.documentElement.scrollWidth
					: undefined,
			windowHeight: captureHeight,
		} );

		const { dataUrl, width, height } = canvasToJpeg( canvas );

		return {
			success: true,
			image: dataUrl,
			width,
			height,
			url: window.location.href,
			truncated,
			error: '',
		};
	} catch ( err ) {
		return {
			success: false,
			image: '',
			width: 0,
			height: 0,
			url: window.location.href,
			error: `Screenshot failed: ${ err.message }`,
		};
	}
}

/* ── Ability 2: screenshot-url ─────────────────────────────────────────── */

/**
 * Load a URL in a hidden iframe and capture a screenshot.
 *
 * The iframe is created, positioned off-screen, allowed to load and settle,
 * captured, then removed. Same-origin restriction is enforced.
 *
 * @param {Object}  args
 * @param {string}  args.url        URL to screenshot (absolute or site-relative path like "/about/").
 * @param {number}  [args.width]    Viewport width for the iframe (default 1280).
 * @param {number}  [args.height]   Viewport height for the iframe (default 800).
 * @param {boolean} [args.fullPage] Capture the full scrollable height.
 * @return {Promise<Object>} Screenshot result.
 */
async function executeScreenshotUrl( args ) {
	const rawUrl = args?.url || '';
	const viewportWidth = args?.width || 1280;
	const viewportHeight = args?.height || 800;
	const fullPage = args?.fullPage ?? false;

	// Validate authorized WordPress origin and same-origin capture feasibility.
	const { valid, resolved, error: urlError } = validateScreenshotUrl( rawUrl );
	if ( ! valid ) {
		return {
			success: false,
			image: '',
			width: 0,
			height: 0,
			url: rawUrl,
			error: urlError,
		};
	}

	// Load the published URL as-is — no query params appended.
	// WordPress's `?preview=true` triggers is_preview() which renders
	// draft/unsaved post content, not the published page. It also does NOT
	// hide the admin bar. Instead, after the iframe loads we inject CSS to
	// hide #wpadminbar and remove the 32px body offset WordPress adds for
	// logged-in users. This gives us a clean capture of the published
	// frontend without admin chrome.
	const targetUrl = new URL( resolved );

	let iframe = null;

	try {
		// Create hidden iframe.
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

		// Wait for load.
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

		iframe.src = targetUrl.href;
		document.body.appendChild( iframe );
		await loadPromise;

		// Allow async rendering (lazy images, web fonts, JS-rendered content)
		// to settle before capturing.
		await new Promise( ( r ) => setTimeout( r, IFRAME_SETTLE_DELAY ) );

		// Access iframe document (same-origin guaranteed by validation above).
		const iframeDoc =
			iframe.contentDocument || iframe.contentWindow?.document;
		if ( ! iframeDoc || ! iframeDoc.body ) {
			return {
				success: false,
				image: '',
				width: 0,
				height: 0,
				url: resolved,
				error: 'Cannot access iframe content. The page may block framing.',
			};
		}

		// Hide the WordPress admin bar and remove its 32px body/html offset
		// so the screenshot shows the published frontend without admin chrome.
		// WordPress adds margin-top: 32px to <html> and a fixed #wpadminbar
		// for logged-in users; both must be neutralised for a clean capture.
		const adminBarStyle = iframeDoc.createElement( 'style' );
		adminBarStyle.textContent = [
			'#wpadminbar { display: none !important; }',
			'html { margin-top: 0 !important; }',
			'* html body { margin-top: 0 !important; }',
		].join( ' ' );
		iframeDoc.head.appendChild( adminBarStyle );

		// For fullPage captures: clamp height to avoid OOM, resize the iframe
		// to the capture height so the full content is laid out, then scroll
		// through to trigger lazy-loaded images before capturing.
		let captureH = viewportHeight;
		let truncated = false;

		if ( fullPage ) {
			const rawHeight = iframeDoc.documentElement.scrollHeight;
			captureH = Math.min( rawHeight, MAX_CAPTURE_HEIGHT );
			truncated = rawHeight > MAX_CAPTURE_HEIGHT;

			// Resize the iframe to the capture height so the browser lays
			// out all content within view and IntersectionObservers see it.
			iframe.style.height = `${ captureH }px`;

			// Scroll through the iframe content in steps to trigger native
			// loading="lazy" images and JS-based lazy loaders.
			await scrollToRevealLazyContent(
				iframe.contentWindow,
				iframeDoc,
				captureH
			);
		}

		const captureTarget = iframeDoc.body;

		const { default: html2canvas } = await import( 'html2canvas' );
		const canvas = await html2canvas( captureTarget, {
			useCORS: true,
			allowTaint: false,
			logging: false,
			width: viewportWidth,
			height: captureH,
			windowWidth: viewportWidth,
			windowHeight: captureH,
		} );

		const { dataUrl, width, height } = canvasToJpeg( canvas );

		return {
			success: true,
			image: dataUrl,
			width,
			height,
			url: resolved,
			truncated,
			error: '',
		};
	} catch ( err ) {
		return {
			success: false,
			image: '',
			width: 0,
			height: 0,
			url: resolved || rawUrl,
			error: `Screenshot failed: ${ err.message }`,
		};
	} finally {
		// Always clean up the iframe.
		if ( iframe && iframe.parentNode ) {
			iframe.parentNode.removeChild( iframe );
		}
	}
}

/* ── Registration ──────────────────────────────────────────────────────── */

/**
 * Register the capture-screenshot ability.
 *
 * @return {Promise<void>}
 */
export async function registerCaptureScreenshotAbility() {
	await registerClientAbility( {
		name: 'sd-ai-agent-js/capture-screenshot',
		label: 'Capture Screenshot',
		description:
			'Capture a screenshot of the current page the user is viewing. ' +
			'Optionally target a specific element with a CSS selector. ' +
			'Returns a base64 JPEG image for visual review by the AI.',
		inputSchema: {
			type: 'object',
			properties: {
				selector: {
					type: 'string',
					description:
						'CSS selector to capture a specific element (e.g. "#main-content", ".entry-content"). ' +
						'Leave empty to capture the full page body.',
				},
				fullPage: {
					type: 'boolean',
					description:
						'If true, captures the full scrollable page height instead of just the viewport. Default: false.',
				},
			},
			required: [],
		},
		outputSchema: {
			type: 'object',
			properties: {
				success: { type: 'boolean' },
				image: {
					type: 'string',
					description:
						'Base64-encoded JPEG data URL of the screenshot.',
				},
				width: { type: 'integer' },
				height: { type: 'integer' },
				url: { type: 'string' },
				truncated: {
					type: 'boolean',
					description:
						'True if fullPage capture was clamped to the maximum height. Some content at the bottom of the page was not captured.',
				},
				error: { type: 'string' },
			},
		},
		annotations: { readonly: true },
		callback: executeCaptureScreenshot,
	} );
}

/**
 * Register the screenshot-url ability.
 *
 * @return {Promise<void>}
 */
export async function registerScreenshotUrlAbility() {
	await registerClientAbility( {
		name: 'sd-ai-agent-js/screenshot-url',
		label: 'Screenshot URL',
		description:
			'Load any same-origin WordPress page in a hidden iframe and capture a screenshot. ' +
			'Use this to visually review frontend pages without navigating the user away from wp-admin. ' +
			'Multisite subdomain URLs must be opened from that subsite origin before capture. ' +
			'Returns a base64 JPEG image for visual review by the AI.',
		inputSchema: {
			type: 'object',
			properties: {
				url: {
					type: 'string',
					description:
						'URL to screenshot. Can be a full URL on this site or a relative path (e.g. "/about/", "/contact/", "/").',
				},
				width: {
					type: 'integer',
					description:
						'Viewport width in pixels for the capture. Default: 1280.',
				},
				height: {
					type: 'integer',
					description:
						'Viewport height in pixels for the capture. Default: 800.',
				},
				fullPage: {
					type: 'boolean',
					description:
						'If true, captures the full scrollable page height instead of just the viewport. Default: false.',
				},
			},
			required: [ 'url' ],
		},
		outputSchema: {
			type: 'object',
			properties: {
				success: { type: 'boolean' },
				image: {
					type: 'string',
					description:
						'Base64-encoded JPEG data URL of the screenshot.',
				},
				width: { type: 'integer' },
				height: { type: 'integer' },
				url: { type: 'string' },
				truncated: {
					type: 'boolean',
					description:
						'True if fullPage capture was clamped to the maximum height. Some content at the bottom of the page was not captured.',
				},
				error: { type: 'string' },
			},
		},
		annotations: { readonly: true },
		callback: executeScreenshotUrl,
	} );
}
