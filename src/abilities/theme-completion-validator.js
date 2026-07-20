/**
 * Deterministic activated-theme completion validation.
 *
 * This is intentionally not a visual-direction preview. It loads published,
 * same-origin frontend URLs and reports machine-readable render, responsive,
 * accessibility, and content evidence that the server-side completion gate can
 * bind to one generated stylesheet and project fingerprint.
 */

import { registerClientAbility } from './registry';
import { loadSameOriginIframe } from './theme-completion-iframe';

/** Required browser viewport matrix for every generated theme. */
export const THEME_COMPLETION_VIEWPORTS = Object.freeze( [
	Object.freeze( { label: 'mobile', width: 375, height: 812 } ),
	Object.freeze( { label: 'tablet', width: 768, height: 1024 } ),
	Object.freeze( { label: 'desktop', width: 1280, height: 800 } ),
] );

const MAX_VIOLATIONS = 100;
const PLACEHOLDER_PATTERN =
	/\b(?:lorem ipsum|replace this|edit this|add your|call to action)\b/i;
const ESSENTIAL_CONTENT_SELECTORS = [
	[ 'header', 'header, [role="banner"]' ],
	[ 'navigation', 'nav, [role="navigation"]' ],
	[ 'main', 'main, [role="main"]' ],
	[ 'footer', 'footer, [role="contentinfo"]' ],
	[ 'page heading', 'h1' ],
];

/**
 * Normalize URLs for deterministic report matching without fetching them.
 *
 * @param {string} url Candidate URL.
 * @return {string} Comparable URL.
 */
function normalizeUrl( url ) {
	const value = String( url || '' ).trim();
	if ( ! value ) {
		return '';
	}

	return value === '/' ? '/' : value.replace( /\/+$/, '' );
}

/**
 * Return whether a loaded document is semantically the site homepage.
 *
 * The WordPress body classes carry page semantics through canonical redirects;
 * URL equality remains a safe fallback for sites that omit those classes.
 *
 * @param {Document} doc         Loaded frontend document.
 * @param {string}   finalUrl    Final document URL.
 * @param {string}   homepageUrl Requested homepage URL.
 * @return {boolean} Whether the document is the homepage.
 */
function isHomepageDocument( doc, finalUrl, homepageUrl ) {
	const classes = doc?.body?.classList;
	if ( classes?.contains( 'home' ) || classes?.contains( 'front-page' ) ) {
		return true;
	}

	return normalizeUrl( finalUrl ) === normalizeUrl( homepageUrl );
}

/**
 * Return a classification for an iframe failure without mistaking a missing
 * browser execution environment for a fatal activated-theme render failure.
 *
 * @param {string} error Failure evidence.
 * @return {string} Stable failure classification.
 */
function classifyRenderFailure( error ) {
	return /browser (?:execution|environment).*unavailable|cannot access iframe content|block framing/i.test(
		String( error || '' )
	)
		? 'browser_execution_unavailable'
		: 'frontend_unrenderable';
}

/**
 * Escape a CSS identifier even in browser/test environments without CSS.escape.
 *
 * @param {string} value Identifier value.
 * @return {string} Escaped CSS identifier.
 */
function escapeCssIdentifier( value ) {
	if ( typeof CSS !== 'undefined' && typeof CSS.escape === 'function' ) {
		return CSS.escape( value );
	}

	return String( value ).replace( /[^a-zA-Z0-9_-]/g, '\\$&' );
}

/**
 * Create one bounded, remediation-oriented violation record.
 *
 * @param {Object} data             Violation data.
 * @param {string} data.code        Stable machine-readable violation code.
 * @param {string} data.url         Frontend URL.
 * @param {Object} data.viewport    Viewport metadata.
 * @param {string} data.selector    CSS selector or document-level evidence.
 * @param {string} data.evidence    Deterministic observed evidence.
 * @param {string} data.remediation Concrete repair action.
 * @param {string} [data.severity]  Severity level.
 * @return {Object} Violation record.
 */
function violation( {
	code,
	url,
	viewport,
	selector,
	evidence,
	remediation,
	severity = 'error',
} ) {
	return {
		code,
		url,
		viewport,
		selector,
		evidence,
		severity,
		remediation,
	};
}

/**
 * Return a compact selector suitable for a report, never a full HTML snippet.
 *
 * @param {Element} element DOM element.
 * @return {string} Stable selector evidence.
 */
function selectorFor( element ) {
	if ( ! element || ! element.tagName ) {
		return 'document';
	}
	if ( element.id ) {
		return `#${ escapeCssIdentifier( element.id ) }`;
	}

	const classes = Array.from( element.classList || [] )
		.slice( 0, 2 )
		.map( ( className ) => `.${ escapeCssIdentifier( className ) }` )
		.join( '' );
	return `${ element.tagName.toLowerCase() }${ classes }`;
}

/**
 * Return whether an element is visibly rendered in the given viewport.
 *
 * @param {Element} element DOM element.
 * @param {Window}  win     Owning iframe window.
 * @return {boolean} Whether the element is visible.
 */
function isVisible( element, win ) {
	if ( ! element || ! win || typeof win.getComputedStyle !== 'function' ) {
		return false;
	}

	const style = win.getComputedStyle( element );
	if (
		style.display === 'none' ||
		style.visibility === 'hidden' ||
		style.visibility === 'collapse' ||
		Number.parseFloat( style.opacity || '1' ) === 0
	) {
		return false;
	}

	const rect = element.getBoundingClientRect();
	return rect.width > 0 && rect.height > 0;
}

/**
 * Add a violation until the result cap is reached.
 *
 * @param {Object[]} violations Violation list.
 * @param {Object}   item       Violation record.
 */
function addViolation( violations, item ) {
	if ( violations.length < MAX_VIOLATIONS ) {
		violations.push( item );
	}
}

/**
 * Return the first visible landmark matching a selector.
 *
 * @param {Document} doc      Iframe document.
 * @param {Window}   win      Iframe window.
 * @param {string}   selector Landmark selector.
 * @return {Element|null} Visible landmark or null.
 */
function getVisibleElement( doc, win, selector ) {
	return (
		Array.from( doc.querySelectorAll( selector ) ).find( ( element ) =>
			isVisible( element, win )
		) || null
	);
}

/**
 * Parse a browser-computed RGB/RGBA color.
 *
 * @param {string} value Computed CSS color.
 * @return {{r:number,g:number,b:number,a:number}|null} Parsed colour.
 */
function parseColor( value ) {
	const match = String( value || '' ).match(
		/rgba?\(\s*([\d.]+)[,\s]+\s*([\d.]+)[,\s]+\s*([\d.]+)(?:\s*[,/]\s*([\d.]+))?\s*\)/i
	);
	if ( ! match ) {
		return null;
	}

	return {
		r: Number( match[ 1 ] ),
		g: Number( match[ 2 ] ),
		b: Number( match[ 3 ] ),
		a: match[ 4 ] === undefined ? 1 : Number( match[ 4 ] ),
	};
}

/**
 * Find an opaque effective background by walking ancestors.
 *
 * @param {Element} element Text-bearing element.
 * @param {Window}  win     Iframe window.
 * @return {{r:number,g:number,b:number,a:number}} Background colour.
 */
function getBackgroundColor( element, win ) {
	let current = element;
	while ( current && current.nodeType === 1 ) {
		const color = parseColor(
			win.getComputedStyle( current ).backgroundColor
		);
		if ( color && color.a > 0 ) {
			return color;
		}
		current = current.parentElement;
	}

	return { r: 255, g: 255, b: 255, a: 1 };
}

/**
 * Convert one RGB channel to WCAG linear light.
 *
 * @param {number} channel RGB channel.
 * @return {number} Linear light value.
 */
function toLinearLight( channel ) {
	const value = channel / 255;
	return value <= 0.04045
		? value / 12.92
		: ( ( value + 0.055 ) / 1.055 ) ** 2.4;
}

/**
 * Calculate WCAG contrast ratio between foreground and background colours.
 *
 * @param {{r:number,g:number,b:number}} foreground Foreground colour.
 * @param {{r:number,g:number,b:number}} background Background colour.
 * @return {number} Contrast ratio.
 */
function contrastRatio( foreground, background ) {
	const luminance = ( color ) =>
		0.2126 * toLinearLight( color.r ) +
		0.7152 * toLinearLight( color.g ) +
		0.0722 * toLinearLight( color.b );
	const first = luminance( foreground );
	const second = luminance( background );
	return (
		( Math.max( first, second ) + 0.05 ) /
		( Math.min( first, second ) + 0.05 )
	);
}

/**
 * Return an accessible name for common link/control shapes.
 *
 * @param {Element}  element Candidate control.
 * @param {Document} doc     Iframe document.
 * @return {string} Accessible name approximation.
 */
function getAccessibleName( element, doc ) {
	const ariaLabel = element.getAttribute( 'aria-label' );
	if ( ariaLabel ) {
		return ariaLabel.trim();
	}

	const labelledBy = element.getAttribute( 'aria-labelledby' );
	if ( labelledBy ) {
		const name = labelledBy
			.split( /\s+/ )
			.map(
				( id ) => doc.getElementById( id )?.textContent?.trim() || ''
			)
			.join( ' ' )
			.trim();
		if ( name ) {
			return name;
		}
	}

	if ( element.tagName === 'IMG' ) {
		return ( element.getAttribute( 'alt' ) || '' ).trim();
	}

	const text = ( element.textContent || '' ).trim();
	if ( text ) {
		return text;
	}

	if ( element.tagName === 'INPUT' ) {
		const input = /** @type {HTMLInputElement} */ ( element );
		if ( input.value ) {
			return input.value.trim();
		}
		if ( input.id ) {
			const label = doc.querySelector(
				`label[for="${ escapeCssIdentifier( input.id ) }"]`
			);
			if ( label?.textContent?.trim() ) {
				return label.textContent.trim();
			}
		}
	}

	return ( element.getAttribute( 'title' ) || '' ).trim();
}

/**
 * Return whether a focused control presents a visible focus indicator.
 *
 * @param {HTMLElement} element Focusable element.
 * @param {Window}      win     Iframe window.
 * @return {boolean} Whether the focus indicator is visible.
 */
function hasVisibleFocus( element, win ) {
	try {
		element.focus( { preventScroll: true } );
	} catch ( _error ) {
		return false;
	}

	const style = win.getComputedStyle( element );
	const outlineVisible =
		! [ 'none', 'hidden' ].includes( style.outlineStyle ) &&
		Number.parseFloat( style.outlineWidth || '0' ) > 0;
	const shadowVisible = style.boxShadow && style.boxShadow !== 'none';
	return outlineVisible || shadowVisible;
}

/**
 * Return whether a reduced-motion CSS declaration hides an element.
 *
 * The browser client cannot emulate a different media preference for an
 * already-running iframe. Inspecting same-origin CSS rules catches styles that
 * would hide essential content specifically when the visitor prefers reduced
 * motion, while the render check below verifies content remains visible after
 * motion effects are disabled.
 *
 * @param {CSSStyleDeclaration} style CSS declaration from a style rule.
 * @return {boolean} Whether the declaration removes visible content.
 */
function reducedMotionStyleHidesContent( style ) {
	const display = String( style.getPropertyValue( 'display' ) || '' )
		.trim()
		.toLowerCase();
	const visibility = String( style.getPropertyValue( 'visibility' ) || '' )
		.trim()
		.toLowerCase();
	const opacity = Number.parseFloat(
		style.getPropertyValue( 'opacity' ) || '1'
	);
	const contentVisibility = String(
		style.getPropertyValue( 'content-visibility' ) || ''
	)
		.trim()
		.toLowerCase();

	return (
		display === 'none' ||
		visibility === 'hidden' ||
		visibility === 'collapse' ||
		opacity === 0 ||
		contentVisibility === 'hidden'
	);
}

/**
 * Return whether a CSS rule applies only when reduced motion is requested.
 *
 * @param {CSSRule} rule Browser CSS rule.
 * @return {boolean} Whether the rule is a reduced-motion media rule.
 */
function isReducedMotionMediaRule( rule ) {
	const condition = String(
		rule?.conditionText || rule?.media?.mediaText || ''
	);
	return /prefers-reduced-motion\s*:\s*reduce/i.test( condition );
}

/**
 * Collect CSS style rules nested in a reduced-motion media query.
 *
 * @param {CSSRuleList|CSSRule[]} rules                   CSS rules to inspect.
 * @param {boolean}               [inReducedMotion=false] Parent reduced-motion state.
 * @return {CSSStyleRule[]} Reduced-motion style rules that hide content.
 */
function collectReducedMotionHidingRules( rules, inReducedMotion = false ) {
	const matchingRules = [];
	for ( const rule of Array.from( rules || [] ) ) {
		const reducedMotion =
			inReducedMotion || isReducedMotionMediaRule( rule );
		if (
			reducedMotion &&
			rule.selectorText &&
			rule.style &&
			reducedMotionStyleHidesContent( rule.style )
		) {
			matchingRules.push( rule );
		}

		if ( rule.cssRules ) {
			matchingRules.push(
				...collectReducedMotionHidingRules(
					rule.cssRules,
					reducedMotion
				)
			);
		}
	}

	return matchingRules;
}

/**
 * Return whether a CSS selector list matches an essential rendered element.
 *
 * @param {Element} element      Essential frontend element.
 * @param {string}  selectorText CSS rule selector list.
 * @return {boolean} Whether the rule applies to the element.
 */
function ruleMatchesElement( element, selectorText ) {
	return selectorText.split( ',' ).some( ( selector ) => {
		try {
			return element.matches( selector.trim() );
		} catch ( _error ) {
			return false;
		}
	} );
}

/**
 * Build a remediation-oriented frontend render failure violation.
 *
 * @param {string} url      Failed frontend URL.
 * @param {Object} viewport Current viewport metadata.
 * @param {string} evidence Render failure detail.
 * @return {Object} Structured violation.
 */
function renderFailureViolation( url, viewport, evidence ) {
	return violation( {
		code: 'frontend_render_failed',
		url: normalizeUrl( url ),
		viewport,
		selector: 'document',
		evidence,
		severity: 'error',
		remediation:
			'Restore a renderable active theme and frontend route, then rerun this viewport validation.',
	} );
}

/**
 * Inspect one published document for deterministic completion violations.
 *
 * Exported for focused tests; production callers should use
 * validateThemeCompletion() so all iframe lifecycle safeguards apply.
 *
 * @param {Object}   args                    Inspection inputs.
 * @param {Document} args.document           Loaded iframe document.
 * @param {Window}   args.window             Loaded iframe window.
 * @param {string}   args.url                Loaded URL.
 * @param {Object}   args.viewport           Viewport metadata.
 * @param {string}   args.expectedStylesheet Expected active stylesheet.
 * @return {{success:boolean,active_stylesheet:string,violations:Object[],checks:Object}} Inspection report.
 */
export function inspectThemeDocument( {
	document: doc,
	window: win,
	url,
	viewport,
	expectedStylesheet,
} ) {
	const violations = [];
	const checks = {};
	const pageUrl = normalizeUrl( url );
	const reportViolation = ( data ) =>
		addViolation(
			violations,
			violation( {
				url: pageUrl,
				viewport,
				...data,
			} )
		);

	if ( ! doc?.documentElement || ! doc.body || ! win ) {
		return {
			success: false,
			active_stylesheet: '',
			violations: [
				violation( {
					code: 'iframe_document_unavailable',
					url: pageUrl,
					viewport,
					selector: 'document',
					evidence:
						'The same-origin frontend document was unavailable.',
					remediation:
						'Fix frontend rendering or framing before rerunning completion QA.',
				} ),
			],
			checks,
		};
	}

	const body = doc.body;
	const expectedThemeClass = `wp-theme-${ expectedStylesheet }`;
	const activeStylesheet = body.classList.contains( expectedThemeClass )
		? expectedStylesheet
		: '';
	checks.active_stylesheet = activeStylesheet;
	if ( activeStylesheet !== expectedStylesheet ) {
		reportViolation( {
			code: 'unexpected_active_stylesheet',
			selector: 'body',
			evidence: `Expected body class ${ expectedThemeClass } was not present.`,
			remediation: `Activate stylesheet ${ expectedStylesheet } and rerun the full report.`,
		} );
	}

	const scrollWidth = doc.documentElement.scrollWidth;
	const clientWidth = doc.documentElement.clientWidth;
	checks.horizontal_overflow = {
		scroll_width: scrollWidth,
		client_width: clientWidth,
	};
	if ( clientWidth > 0 && scrollWidth > clientWidth + 1 ) {
		reportViolation( {
			code: 'document_horizontal_overflow',
			selector: 'html',
			evidence: `scrollWidth ${ scrollWidth } exceeds clientWidth ${ clientWidth }.`,
			remediation:
				'Constrain the overflowing element and retest this viewport.',
		} );
	}

	if ( ! doc.documentElement.lang.trim() ) {
		reportViolation( {
			code: 'missing_document_language',
			selector: 'html',
			evidence: 'The document has no lang attribute.',
			remediation:
				'Set the site language so rendered HTML has a valid lang attribute.',
		} );
	}

	for ( const [ label, selector ] of ESSENTIAL_CONTENT_SELECTORS.slice(
		0,
		4
	) ) {
		const element = getVisibleElement( doc, win, selector );
		checks[ label ] = !! element;
		if ( ! element ) {
			reportViolation( {
				code: `missing_visible_${ label }`,
				selector,
				evidence: `No visible ${ label } landmark was found.`,
				remediation: `Render a visible semantic ${ label } landmark on this frontend page.`,
			} );
		}
	}

	const headings = Array.from(
		doc.querySelectorAll( 'h1, h2, h3, h4, h5, h6' )
	).filter( ( heading ) => isVisible( heading, win ) );
	const h1s = headings.filter( ( heading ) => heading.tagName === 'H1' );
	checks.heading_count = headings.length;
	checks.h1_count = h1s.length;
	if ( h1s.length !== 1 ) {
		reportViolation( {
			code: 'invalid_page_heading',
			selector: 'h1',
			evidence: `Expected exactly one visible page-level H1; found ${ h1s.length }.`,
			remediation: 'Provide one visible H1 that identifies this page.',
		} );
	}

	let previousLevel = 0;
	for ( const heading of headings ) {
		const level = Number( heading.tagName.slice( 1 ) );
		if ( previousLevel && level > previousLevel + 1 ) {
			reportViolation( {
				code: 'heading_level_skip',
				selector: selectorFor( heading ),
				evidence: `Heading level jumped from H${ previousLevel } to H${ level }.`,
				remediation:
					'Use a sequential heading hierarchy without skipped levels.',
			} );
		}
		previousLevel = level;
	}

	const ids = new Map();
	for ( const element of doc.querySelectorAll( '[id]' ) ) {
		const id = element.id;
		ids.set( id, ( ids.get( id ) || 0 ) + 1 );
	}
	for ( const [ id, count ] of ids ) {
		if ( count > 1 ) {
			reportViolation( {
				code: 'duplicate_id',
				selector: `#${ escapeCssIdentifier( id ) }`,
				evidence: `The id "${ id }" occurs ${ count } times.`,
				remediation: 'Make every document id unique.',
			} );
		}
	}

	for ( const image of doc.querySelectorAll( 'img' ) ) {
		const src = image.currentSrc || image.getAttribute( 'src' ) || '';
		if ( ! image.hasAttribute( 'alt' ) ) {
			reportViolation( {
				code: 'image_missing_alt',
				selector: selectorFor( image ),
				evidence: 'The image has no alt attribute.',
				remediation:
					'Provide meaningful alt text or an explicit empty alt for a decorative image.',
			} );
		}
		if ( ! src ) {
			reportViolation( {
				code: 'image_missing_source',
				selector: selectorFor( image ),
				evidence: 'The image has no usable source URL.',
				remediation:
					'Provide a valid local media source or remove the image element.',
			} );
		}
		if ( src ) {
			try {
				const sourceUrl = new URL( src, win.location.href );
				if ( sourceUrl.origin !== win.location.origin ) {
					reportViolation( {
						code: 'unprovided_remote_image',
						selector: selectorFor( image ),
						evidence: `Image source uses remote origin ${ sourceUrl.origin }.`,
						remediation:
							'Replace the remote image with a user-provided local media asset.',
					} );
				}
			} catch ( _error ) {
				reportViolation( {
					code: 'invalid_image_source',
					selector: selectorFor( image ),
					evidence: `Image source "${ src }" is not a usable URL.`,
					remediation: 'Use a valid local media URL.',
				} );
			}
		}
		if ( image.complete && src && image.naturalWidth === 0 ) {
			reportViolation( {
				code: 'broken_local_asset',
				selector: selectorFor( image ),
				evidence: `Image "${ src }" completed without rendered pixels.`,
				remediation:
					'Restore the referenced local asset or replace the image source.',
			} );
		}
	}

	for ( const link of doc.querySelectorAll( 'a[href]' ) ) {
		const href = ( link.getAttribute( 'href' ) || '' ).trim();
		if (
			! href ||
			href === '#' ||
			href.toLowerCase().startsWith( 'javascript:' )
		) {
			reportViolation( {
				code: 'empty_or_hash_link',
				selector: selectorFor( link ),
				evidence: `Link href is "${ href || '(empty)' }".`,
				remediation:
					'Replace the placeholder link with a real destination.',
			} );
		}
	}

	const controls = Array.from(
		doc.querySelectorAll(
			'a[href], button, input:not([type="hidden"]), select, textarea, [role="button"]'
		)
	).filter( ( control ) => isVisible( control, win ) );
	for ( const control of controls ) {
		if ( ! getAccessibleName( control, doc ) ) {
			reportViolation( {
				code: 'control_missing_accessible_name',
				selector: selectorFor( control ),
				evidence: 'The visible link or control has no accessible name.',
				remediation:
					'Add visible text, aria-label, or aria-labelledby.',
			} );
		}
		if (
			! hasVisibleFocus( /** @type {HTMLElement} */ ( control ), win )
		) {
			reportViolation( {
				code: 'control_focus_not_visible',
				selector: selectorFor( control ),
				evidence:
					'Focusing the visible control produced no outline or box-shadow indicator.',
				remediation:
					'Provide a visible :focus or :focus-visible indicator with sufficient contrast.',
			} );
		}
	}

	const textCandidates = Array.from(
		doc.querySelectorAll(
			'h1, h2, h3, h4, h5, h6, p, li, a[href], button, label, input, select, textarea'
		)
	).filter(
		( element ) =>
			isVisible( element, win ) &&
			( ( element.textContent || '' ).trim() ||
				element.tagName === 'INPUT' )
	);
	for ( const element of textCandidates ) {
		const style = win.getComputedStyle( element );
		const foreground = parseColor( style.color );
		if ( ! foreground ) {
			continue;
		}
		const ratio = contrastRatio(
			foreground,
			getBackgroundColor( element, win )
		);
		if ( ratio < 4.5 ) {
			reportViolation( {
				code: 'insufficient_text_contrast',
				selector: selectorFor( element ),
				evidence: `Computed foreground/background contrast is ${ ratio.toFixed(
					2
				) }:1, below 4.5:1.`,
				remediation:
					'Use governed foreground/background tokens that meet the 4.5:1 WCAG text contrast threshold.',
			} );
		}
	}

	const visibleText = ( body.innerText || body.textContent || '' ).replace(
		/\s+/g,
		' '
	);
	if ( PLACEHOLDER_PATTERN.test( visibleText ) ) {
		reportViolation( {
			code: 'placeholder_content',
			selector: 'body',
			evidence:
				'Visible frontend text matches a prohibited placeholder pattern.',
			remediation:
				'Replace placeholder copy and CTA text with real site content.',
		} );
	}

	const reducedMotionRules = [];
	for ( const stylesheet of Array.from( doc.styleSheets || [] ) ) {
		try {
			reducedMotionRules.push(
				...collectReducedMotionHidingRules( stylesheet.cssRules )
			);
		} catch ( _error ) {
			// CSSOM blocks cross-origin stylesheets. Same-origin generated theme
			// stylesheets remain inspectable and are the completion evidence.
		}
	}
	checks.reduced_motion_hiding_rules = reducedMotionRules.length;
	for ( const rule of reducedMotionRules ) {
		for ( const [ label, selector ] of ESSENTIAL_CONTENT_SELECTORS ) {
			const element = getVisibleElement( doc, win, selector );
			if ( element && ruleMatchesElement( element, rule.selectorText ) ) {
				reportViolation( {
					code: 'reduced_motion_hidden_content',
					selector: rule.selectorText,
					evidence: `Reduced-motion rule "${ rule.selectorText }" hides the essential ${ label }.`,
					remediation:
						'Keep essential landmarks and the page heading visible in prefers-reduced-motion: reduce rules.',
				} );
			}
		}
	}

	const reducedMotionStyle = doc.createElement( 'style' );
	reducedMotionStyle.textContent =
		'*, *::before, *::after { animation: none !important; transition: none !important; }';
	( doc.head || doc.documentElement ).appendChild( reducedMotionStyle );
	for ( const [ label, selector ] of ESSENTIAL_CONTENT_SELECTORS ) {
		if ( ! getVisibleElement( doc, win, selector ) ) {
			reportViolation( {
				code: 'reduced_motion_hidden_content',
				selector,
				evidence: `Essential ${ label } is not visible when animations and transitions are disabled.`,
				remediation:
					'Ensure reduced-motion rules leave essential content visible without animation.',
			} );
		}
	}
	reducedMotionStyle.remove();

	return {
		success: true,
		active_stylesheet: activeStylesheet,
		violations,
		checks,
	};
}

/**
 * Validate all required active-site URLs and viewports.
 *
 * @param {Object} args              Validator arguments.
 * @param {string} args.stylesheet   Expected active theme stylesheet.
 * @param {string} args.fingerprint  Current project validation fingerprint.
 * @param {string} args.homepage_url Homepage URL.
 * @param {string} args.interior_url Representative interior URL.
 * @return {Promise<Object>} Completion report.
 */
export async function validateThemeCompletion( args ) {
	const stylesheet = String( args?.stylesheet || '' ).trim();
	const fingerprint = String( args?.fingerprint || '' ).trim();
	const homepageUrl = normalizeUrl( args?.homepage_url );
	const interiorUrl = normalizeUrl( args?.interior_url );
	const urls = [ homepageUrl, interiorUrl ].filter( Boolean );
	const violations = [];
	const reports = [];

	if ( ! /^[a-z0-9-]+$/.test( stylesheet ) ) {
		addViolation(
			violations,
			violation( {
				code: 'invalid_expected_stylesheet',
				url: '',
				viewport: null,
				selector: 'request',
				evidence:
					'The completion request did not include a valid expected stylesheet.',
				remediation:
					'Pass the stylesheet returned by the current generated-project validation.',
			} )
		);
	}
	if ( ! fingerprint ) {
		addViolation(
			violations,
			violation( {
				code: 'missing_project_fingerprint',
				url: '',
				viewport: null,
				selector: 'request',
				evidence:
					'The completion request did not include a project fingerprint.',
				remediation:
					'Run validate-block-theme-project and pass its current fingerprint.',
			} )
		);
	}
	if (
		urls.length !== 2 ||
		new Set( urls ).size !== 2 ||
		urls.some( ( url ) => url.includes( '/wp-content/uploads/' ) )
	) {
		addViolation(
			violations,
			violation( {
				code: 'invalid_completion_urls',
				url: '',
				viewport: null,
				selector: 'request',
				evidence:
					'Completion requires exactly the real homepage and one real interior frontend URL; uploaded preview files are not valid targets.',
				remediation:
					'Pass the active homepage URL and one published interior page URL.',
			} )
		);
	}

	if ( violations.length ) {
		return {
			success: false,
			complete: false,
			passed: false,
			fatal_render_failure: false,
			stylesheet,
			fingerprint,
			urls,
			viewports: THEME_COMPLETION_VIEWPORTS,
			reports,
			violations,
		};
	}

	for ( const { url, role } of [
		{ url: homepageUrl, role: 'homepage' },
		{ url: interiorUrl, role: 'interior' },
	] ) {
		for ( const viewport of THEME_COMPLETION_VIEWPORTS ) {
			// eslint-disable-next-line no-await-in-loop -- Six bounded iframe renders must not race shared browser resources.
			const loaded = await loadSameOriginIframe( {
				url,
				width: viewport.width,
				height: viewport.height,
			} );

			if ( ! loaded.success || ! loaded.document || ! loaded.window ) {
				const failedUrl = normalizeUrl( loaded.url || url );
				const failureKind = classifyRenderFailure( loaded.error );
				const failedRender = renderFailureViolation(
					failedUrl,
					viewport,
					loaded.error || 'Frontend iframe could not be rendered.'
				);
				addViolation( violations, failedRender );
				reports.push( {
					url: failedUrl,
					requested_url: normalizeUrl( url ),
					final_url: failedUrl,
					role,
					is_homepage: false,
					failure_kind: failureKind,
					viewport,
					success: false,
					active_stylesheet: '',
					violations: [ failedRender ],
					error: failedRender.evidence,
				} );
				continue;
			}

			try {
				const inspected = inspectThemeDocument( {
					document: loaded.document,
					window: loaded.window,
					url: loaded.url,
					viewport,
					expectedStylesheet: stylesheet,
				} );
				reports.push( {
					url: normalizeUrl( loaded.url ),
					requested_url: normalizeUrl( url ),
					final_url: normalizeUrl( loaded.url ),
					role,
					is_homepage: isHomepageDocument(
						loaded.document,
						loaded.url,
						homepageUrl
					),
					viewport,
					...inspected,
				} );
				for ( const item of inspected.violations ) {
					addViolation( violations, item );
				}
			} catch ( error ) {
				const failedUrl = normalizeUrl( loaded.url || url );
				const failedRender = renderFailureViolation(
					failedUrl,
					viewport,
					error.message || 'Frontend validation failed unexpectedly.'
				);
				addViolation( violations, failedRender );
				reports.push( {
					url: failedUrl,
					requested_url: normalizeUrl( url ),
					final_url: failedUrl,
					role,
					is_homepage: false,
					failure_kind: 'frontend_unrenderable',
					viewport,
					success: false,
					active_stylesheet: '',
					violations: [ failedRender ],
					error: failedRender.evidence,
				} );
			} finally {
				loaded.cleanup();
			}
		}
	}

	const expectedReports = urls.length * THEME_COMPLETION_VIEWPORTS.length;
	const allRendered =
		reports.length === expectedReports &&
		reports.every( ( report ) => report.success );
	const complete = allRendered;
	const passed = complete && violations.length === 0;
	const allFailuresAreBrowserUnavailable =
		reports.length === expectedReports &&
		reports.length > 0 &&
		reports.every(
			( report ) =>
				! report.success &&
				report.failure_kind === 'browser_execution_unavailable'
		);

	return {
		success: allRendered,
		complete,
		passed,
		fatal_render_failure:
			reports.length === expectedReports &&
			reports.length > 0 &&
			reports.every(
				( report ) =>
					! report.success &&
					report.failure_kind === 'frontend_unrenderable'
			),
		browser_execution_unavailable: allFailuresAreBrowserUnavailable,
		stylesheet,
		fingerprint,
		urls,
		viewports: THEME_COMPLETION_VIEWPORTS,
		reports,
		violations,
	};
}

/**
 * Register the activated-theme completion validator as a client ability.
 *
 * @return {Promise<void>}
 */
export async function registerThemeCompletionValidatorAbility() {
	await registerClientAbility( {
		name: 'sd-ai-agent-js/validate-theme-completion',
		label: 'Validate Generated Theme Completion',
		description:
			'Validate the active generated WordPress theme on the real homepage and an interior page at mobile, tablet, and desktop viewports. Returns deterministic render, accessibility, responsive, content, and remediation evidence; previews and screenshots do not satisfy this check.',
		inputSchema: {
			type: 'object',
			properties: {
				stylesheet: {
					type: 'string',
					description:
						'Expected active generated theme stylesheet from the current project validation.',
				},
				fingerprint: {
					type: 'string',
					description:
						'Current fingerprint returned by validate-block-theme-project.',
				},
				homepage_url: {
					type: 'string',
					description: 'Active homepage URL.',
				},
				interior_url: {
					type: 'string',
					description:
						'Published interior page URL, distinct from homepage_url.',
				},
			},
			required: [
				'stylesheet',
				'fingerprint',
				'homepage_url',
				'interior_url',
			],
		},
		outputSchema: {
			type: 'object',
			properties: {
				success: { type: 'boolean' },
				complete: { type: 'boolean' },
				passed: { type: 'boolean' },
				fatal_render_failure: { type: 'boolean' },
				stylesheet: { type: 'string' },
				fingerprint: { type: 'string' },
				reports: { type: 'array', items: { type: 'object' } },
				violations: { type: 'array', items: { type: 'object' } },
			},
		},
		annotations: { readonly: true },
		callback: validateThemeCompletion,
	} );
}
