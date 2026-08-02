/**
 * Rendered page-quality validation for agent-created and agent-edited pages.
 *
 * The Setup Assistant receives a strict first-impression profile across mobile,
 * tablet, and desktop. The General agent receives a focused profile across
 * mobile and desktop so small edits remain incremental while still catching
 * structural, responsive, media, and placeholder regressions.
 */

import apiFetch from '@wordpress/api-fetch';

import { loadSameOriginIframe } from './theme-completion-iframe';
import { inspectThemeDocument } from './theme-completion-validator';

export const PAGE_QUALITY_VIEWPORTS = Object.freeze( {
	setup: Object.freeze( [
		Object.freeze( { label: 'mobile', width: 375, height: 812 } ),
		Object.freeze( { label: 'tablet', width: 768, height: 1024 } ),
		Object.freeze( { label: 'desktop', width: 1280, height: 800 } ),
	] ),
	incremental: Object.freeze( [
		Object.freeze( { label: 'mobile', width: 375, height: 812 } ),
		Object.freeze( { label: 'desktop', width: 1280, height: 800 } ),
	] ),
} );

const MAX_FINDINGS = 100;
const MAX_REVIEW_IMAGE_WIDTH = 768;
const INCREMENTAL_BLOCKING_CODES = new Set( [
	'iframe_document_unavailable',
	'frontend_render_failed',
	'document_horizontal_overflow',
	'invalid_page_heading',
	'heading_level_skip',
	'duplicate_id',
	'image_missing_alt',
	'image_missing_source',
	'invalid_image_source',
	'broken_local_asset',
	'unprovided_remote_image',
	'placeholder_content',
	'empty_or_hash_link',
] );

/**
 * Normalize a frontend URL for report matching.
 *
 * @param {string} url URL to normalize.
 * @return {string} Normalized URL.
 */
function normalizeUrl( url ) {
	const value = String( url || '' ).trim();
	return value === '/' ? '/' : value.replace( /\/+$/, '' );
}

/**
 * Resolve a browser-session-valid WordPress preview URL without exposing its
 * nonce to the background worker, model transcript, or persisted report.
 *
 * @param {Object} page Gate-owned page descriptor.
 * @return {Promise<string>} Internal render URL.
 */
async function resolveRenderUrl( page ) {
	if ( page.render_mode !== 'preview' ) {
		return String( page.url || '' );
	}

	const restPath = String( page.preview_rest_path || '' );
	const expectedPath = new RegExp(
		`^/wp/v2/pages/${ Number( page.post_id ) }/autosaves/${ Number(
			page.revision_id
		) }\\?context=edit$`
	);
	if ( ! expectedPath.test( restPath ) ) {
		throw new Error( 'The preview REST descriptor is invalid or stale.' );
	}

	const autosave = await apiFetch( { path: restPath } );
	if (
		Number( autosave?.id ) !== Number( page.revision_id ) ||
		Number( autosave?.parent ) !== Number( page.post_id ) ||
		! autosave?.preview_link
	) {
		throw new Error( 'WordPress returned a different autosave preview.' );
	}

	const previewUrl = new URL( autosave.preview_link, window.location.origin );
	if ( Number( page.featured_image_id ) > 0 ) {
		previewUrl.searchParams.set(
			'_thumbnail_id',
			String( Number( page.featured_image_id ) )
		);
	}
	return previewUrl.href;
}

/**
 * Build one structured quality finding.
 *
 * @param {Object} root0             Finding data.
 * @param {string} root0.code        Stable finding code.
 * @param {string} root0.url         Frontend URL.
 * @param {Object} root0.viewport    Viewport metadata.
 * @param {string} root0.selector    Evidence selector.
 * @param {string} root0.evidence    Observed evidence.
 * @param {string} root0.remediation Repair instruction.
 * @param {string} root0.severity    Finding severity.
 * @return {Object} Structured finding.
 */
function finding( {
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
		url: normalizeUrl( url ),
		viewport,
		selector,
		evidence,
		severity,
		remediation,
	};
}

/**
 * Append a finding without allowing unbounded reports.
 *
 * @param {Object[]} list Finding list.
 * @param {Object}   item Finding to append.
 */
function pushFinding( list, item ) {
	if ( list.length < MAX_FINDINGS ) {
		list.push( item );
	}
}

/**
 * Return whether an element has a visible rendered box.
 *
 * @param {Element} element Element to inspect.
 * @param {Window}  win     Owning window.
 * @return {boolean} Whether the element is visible.
 */
function isVisible( element, win ) {
	if ( ! element || ! win?.getComputedStyle ) {
		return false;
	}
	const style = win.getComputedStyle( element );
	if (
		style.display === 'none' ||
		style.visibility === 'hidden' ||
		Number.parseFloat( style.opacity || '1' ) === 0
	) {
		return false;
	}
	const rect = element.getBoundingClientRect();
	return rect.width > 0 && rect.height > 0;
}

/**
 * Return compact selector evidence for one element.
 *
 * @param {Element} element Element to describe.
 * @return {string} Compact selector.
 */
function selectorFor( element ) {
	if ( ! element?.tagName ) {
		return 'document';
	}
	if ( element.id ) {
		return `#${ element.id }`;
	}
	const classes = Array.from( element.classList || [] )
		.slice( 0, 2 )
		.map( ( className ) => `.${ className }` )
		.join( '' );
	return `${ element.tagName.toLowerCase() }${ classes }`;
}

/**
 * Confirm that a homepage target rendered homepage semantics.
 *
 * @param {Document} doc          Loaded document.
 * @param {string}   finalUrl     Final URL.
 * @param {string}   requestedUrl Requested URL.
 * @param {string}   role         Expected page role.
 * @return {boolean} Whether the document is the requested homepage.
 */
function isHomepageDocument( doc, finalUrl, requestedUrl, role ) {
	if ( role !== 'homepage' ) {
		return false;
	}
	return (
		doc.body.classList.contains( 'home' ) ||
		doc.body.classList.contains( 'front-page' ) ||
		normalizeUrl( finalUrl ) === normalizeUrl( requestedUrl )
	);
}

/**
 * Force native lazy-loaded images to resolve before dimensions are inspected.
 *
 * @param {Document} doc Loaded document.
 * @return {Promise<void>} Image-settle promise.
 */
async function settleImages( doc ) {
	const images = Array.from( doc.images || [] );
	for ( const image of images ) {
		image.loading = 'eager';
	}

	await Promise.all(
		images.map( ( image ) => {
			if ( image.complete ) {
				return Promise.resolve();
			}
			return Promise.race( [
				new Promise( ( resolve ) => {
					image.addEventListener( 'load', resolve, { once: true } );
					image.addEventListener( 'error', resolve, { once: true } );
				} ),
				new Promise( ( resolve ) => setTimeout( resolve, 2000 ) ),
			] );
		} )
	);
}

/**
 * Find the narrowest useful rendered page-content root.
 *
 * @param {Document} doc Loaded document.
 * @return {Element} Page-content root.
 */
function findPageContentRoot( doc ) {
	return (
		doc.querySelector( 'main .wp-block-post-content' ) ||
		doc.querySelector( 'main .entry-content' ) ||
		doc.querySelector( 'main article' ) ||
		doc.querySelector( 'main' ) ||
		doc.body
	);
}

/**
 * Keep focused edits blocked only by regressions the page mutation can own.
 *
 * @param {Object} item Finding to classify.
 * @return {Object} Classified finding.
 */
function downgradeIncrementalFinding( item ) {
	if ( INCREMENTAL_BLOCKING_CODES.has( item.code ) ) {
		return item;
	}
	return { ...item, severity: 'warning' };
}

/**
 * Capture one loaded viewport for the Setup Assistant's visual critique.
 *
 * @param {Document} doc      Loaded document.
 * @param {Object}   viewport Viewport metadata.
 * @return {Promise<Object>} Screenshot result.
 */
async function captureReviewScreenshot( doc, viewport ) {
	try {
		const { default: html2canvas } = await import( 'html2canvas' );
		const canvas = await html2canvas( doc.body, {
			useCORS: true,
			allowTaint: false,
			logging: false,
			width: viewport.width,
			height: viewport.height,
			windowWidth: viewport.width,
			windowHeight: viewport.height,
		} );
		let target = canvas;
		if ( canvas.width > MAX_REVIEW_IMAGE_WIDTH ) {
			const scale = MAX_REVIEW_IMAGE_WIDTH / canvas.width;
			target = document.createElement( 'canvas' );
			target.width = MAX_REVIEW_IMAGE_WIDTH;
			target.height = Math.round( canvas.height * scale );
			target
				.getContext( '2d' )
				.drawImage( canvas, 0, 0, target.width, target.height );
		}
		return {
			success: true,
			image: target.toDataURL( 'image/jpeg', 0.72 ),
			width: target.width,
			height: target.height,
			error: '',
		};
	} catch ( error ) {
		return {
			success: false,
			image: '',
			width: 0,
			height: 0,
			error: error.message || 'Screenshot capture failed.',
		};
	}
}

/**
 * Verify that an anonymous visitor receives the real homepage, not a launch,
 * maintenance, or coming-soon interstitial hidden from logged-in editors.
 *
 * @param {string} url      Public homepage URL.
 * @param {Object} viewport Evidence viewport.
 * @return {Promise<Object|null>} Blocking finding, or null.
 */
async function inspectAnonymousHomepage( url, viewport ) {
	try {
		const response = await fetch( url, {
			credentials: 'omit',
			cache: 'no-store',
			headers: { Accept: 'text/html' },
		} );
		if ( ! response.ok ) {
			return finding( {
				code: 'public_homepage_unavailable',
				url,
				viewport,
				selector: 'document',
				evidence: `Anonymous homepage request returned HTTP ${ response.status } instead of the published page.`,
				remediation:
					'Publish the site for anonymous visitors, then rerun rendered-page validation.',
			} );
		}

		const html = await response.text();
		const publicDocument = new DOMParser().parseFromString(
			html,
			'text/html'
		);
		const launchInterstitial = publicDocument.querySelector(
			'meta[name="woo-coming-soon-page"][content="yes"], .wp-block-woocommerce-coming-soon, [data-block-name="woocommerce/coming-soon"]'
		);
		if ( launchInterstitial ) {
			return finding( {
				code: 'public_homepage_coming_soon',
				url,
				viewport,
				selector: selectorFor( launchInterstitial ),
				evidence:
					'Anonymous visitors receive a WooCommerce coming-soon page while logged-in editors see the composed homepage.',
				remediation:
					'Disable Coming soon / launch mode and rerun validation against the public homepage before reporting a successful first impression.',
			} );
		}
		return null;
	} catch ( error ) {
		return finding( {
			code: 'public_homepage_check_failed',
			url,
			viewport,
			selector: 'document',
			evidence:
				error.message || 'Anonymous homepage verification failed.',
			remediation:
				'Restore anonymous same-origin homepage access and rerun rendered-page validation.',
		} );
	}
}

/**
 * Inspect one loaded page for composition and media-quality defects.
 *
 * @param {Object}   args
 * @param {Document} args.document
 * @param {Window}   args.window
 * @param {string}   args.url
 * @param {Object}   args.viewport
 * @param {string}   args.profile
 * @param {string}   args.role
 * @param {Object}   args.heroContract
 * @return {{violations:Object[],warnings:Object[],checks:Object,score:number,active_stylesheet:string}} Page inspection report.
 */
export function inspectPageQualityDocument( {
	document: doc,
	window: win,
	url,
	viewport,
	profile,
	role,
	heroContract = {},
} ) {
	const base = inspectThemeDocument( {
		document: doc,
		window: win,
		url,
		viewport,
		expectedStylesheet: '',
	} );
	const violations = [];
	const warnings = [];
	const checks = { ...base.checks };
	const add = ( item ) => {
		const normalized =
			profile === 'incremental'
				? downgradeIncrementalFinding( item )
				: item;
		pushFinding(
			normalized.severity === 'warning' ? warnings : violations,
			normalized
		);
	};

	for ( const item of base.violations ) {
		add( item );
	}

	if ( ! doc?.body || ! win ) {
		return {
			violations,
			warnings,
			checks,
			score: 0,
			active_stylesheet: base.active_stylesheet || '',
		};
	}

	const pageUrl = normalizeUrl( url );
	const report = ( data ) =>
		add(
			finding( {
				url: pageUrl,
				viewport,
				...data,
			} )
		);
	const visibleMains = Array.from( doc.querySelectorAll( 'main' ) ).filter(
		( main ) => isVisible( main, win )
	);
	checks.visible_main_count = visibleMains.length;
	if ( visibleMains.length !== 1 ) {
		report( {
			code: 'invalid_main_landmark_count',
			selector: 'main',
			evidence: `Expected exactly one visible main landmark; found ${ visibleMains.length }.`,
			remediation:
				'Remove main elements from post content and let the active page template own the single main landmark.',
		} );
	}
	if ( doc.querySelector( 'main main' ) ) {
		report( {
			code: 'nested_main_landmark',
			selector: 'main main',
			evidence: 'A main landmark is nested inside another main landmark.',
			remediation:
				'Replace the post-content main block with a core/group container.',
		} );
	}

	const siteHeader = Array.from( doc.querySelectorAll( 'header' ) ).find(
		( header ) => isVisible( header, win )
	);
	if ( siteHeader ) {
		const headerRect = siteHeader.getBoundingClientRect();
		const headerRatio = headerRect.height / viewport.height;
		const maximumRatio = viewport.label === 'desktop' ? 0.4 : 0.34;
		checks.header_viewport_ratio = headerRatio;
		if ( headerRatio > maximumRatio ) {
			report( {
				code: 'oversized_site_header',
				selector: selectorFor( siteHeader ),
				evidence: `The site header consumes ${ Math.round(
					headerRatio * 100
				) }% of the initial ${ viewport.label } viewport.`,
				remediation:
					"Reduce logo scale and header padding so navigation remains clear without crowding the page's primary first impression.",
			} );
		}
	}

	const contentRoot = findPageContentRoot( doc );
	const imageRoot = visibleMains[ 0 ] || contentRoot;
	const contentImages = Array.from(
		imageRoot.querySelectorAll( 'img' )
	).filter(
		( image ) =>
			isVisible( image, win ) &&
			! image.classList.contains( 'custom-logo' )
	);
	checks.content_image_count = contentImages.length;

	const imagesBySource = new Map();
	for ( const image of contentImages ) {
		const source = normalizeUrl(
			image.currentSrc || image.getAttribute( 'src' ) || ''
		);
		if ( ! source ) {
			continue;
		}
		if ( ! imagesBySource.has( source ) ) {
			imagesBySource.set( source, [] );
		}
		imagesBySource.get( source ).push( image );

		const rect = image.getBoundingClientRect();
		if (
			image.complete &&
			image.naturalWidth > 0 &&
			rect.width >= 300 &&
			rect.width > image.naturalWidth + 8
		) {
			report( {
				code: 'image_upscaled',
				selector: selectorFor( image ),
				evidence: `The image renders at ${ Math.round(
					rect.width
				) }px wide from a ${ image.naturalWidth }px source.`,
				remediation:
					'Replace it with a higher-resolution local asset or reduce its rendered size.',
			} );
		}
	}

	for ( const [ source, images ] of imagesBySource ) {
		if (
			images.length > 1 &&
			images.some( ( image ) =>
				image.classList.contains( 'wp-post-image' )
			)
		) {
			report( {
				code: 'duplicate_featured_content_image',
				selector: images.map( selectorFor ).join( ', ' ),
				evidence: `The same source is rendered as both featured media and page content: ${ source }.`,
				remediation:
					'Use the image once: either let the template render featured media or use it in the composed hero, not both.',
			} );
		}
	}

	const placeholderLinks = Array.from(
		doc.querySelectorAll( 'a[href]' )
	).filter(
		( link ) =>
			/(?:^|\.)example\.(?:com|org|net)(?:$|[/?#:])/i.test( link.href ) ||
			/@example\.(?:com|org|net)\b/i.test( link.href )
	);
	for ( const link of placeholderLinks ) {
		report( {
			code: 'placeholder_destination',
			selector: selectorFor( link ),
			evidence: `The visible page links to placeholder destination "${ link.getAttribute(
				'href'
			) }".`,
			remediation:
				'Remove the CTA or replace it with a real known destination; never publish example.com contact details.',
		} );
	}

	if ( profile === 'setup' ) {
		const customLogo = doc.querySelector( 'header img.custom-logo' );
		const siteTitle = doc.querySelector( 'header .wp-block-site-title' );
		const logoName = ( customLogo?.getAttribute( 'alt' ) || '' )
			.trim()
			.toLowerCase();
		const titleName = ( siteTitle?.textContent || '' ).trim().toLowerCase();
		if ( logoName && titleName && logoName === titleName ) {
			report( {
				code: 'duplicate_header_branding',
				selector: 'header .custom-logo, header .wp-block-site-title',
				evidence:
					'The wordmark logo and adjacent site-title block repeat the same brand name.',
				remediation:
					'Use a mark-only logo with the site title, or keep the wordmark and remove the duplicate title block.',
			} );
		}

		const sampleNavigation = Array.from(
			doc.querySelectorAll( 'header nav a, footer nav a' )
		).find( ( link ) => /^sample page$/i.test( link.textContent.trim() ) );
		if ( sampleNavigation ) {
			report( {
				code: 'default_sample_navigation',
				selector: selectorFor( sampleNavigation ),
				evidence: 'Default “Sample Page” navigation remains visible.',
				remediation:
					'Remove seed navigation and link only to real pages created for this site.',
			} );
		}
	}

	const isHomepage =
		role === 'homepage' &&
		( doc.body.classList.contains( 'home' ) ||
			doc.body.classList.contains( 'front-page' ) );
	checks.is_homepage = isHomepage;

	if ( profile === 'setup' && isHomepage ) {
		const visibleH1 = Array.from( doc.querySelectorAll( 'h1' ) ).find(
			( h1 ) => isVisible( h1, win )
		);
		if ( visibleH1 ) {
			const h1Rect = visibleH1.getBoundingClientRect();
			if ( h1Rect.top >= viewport.height ) {
				report( {
					code: 'homepage_heading_below_fold',
					selector: selectorFor( visibleH1 ),
					evidence: `The page heading begins at ${ Math.round(
						h1Rect.top
					) }px, below the ${ viewport.height }px initial viewport.`,
					remediation:
						'Move the primary value proposition into the first hero viewport.',
				} );
			}
		}

		const primaryCta = Array.from(
			contentRoot.querySelectorAll(
				'.wp-block-button a, a.wp-element-button, button, [role="button"]'
			)
		).find( ( control ) => {
			const rect = control.getBoundingClientRect();
			return (
				isVisible( control, win ) &&
				rect.top >= 0 &&
				rect.top < viewport.height &&
				rect.bottom <= viewport.height + 1
			);
		} );
		checks.primary_cta_above_fold = !! primaryCta;
		if ( ! primaryCta ) {
			report( {
				code: 'homepage_primary_cta_below_fold',
				selector: '.wp-block-button a, a.wp-element-button',
				evidence:
					'No visible primary homepage action appears in the initial viewport.',
				remediation:
					'Place one real primary CTA in the hero without pushing it below the first viewport.',
			} );
		}

		if (
			contentImages.length === 0 &&
			heroContract.strategy !== 'balanced' &&
			heroContract.media_role !== 'none'
		) {
			report( {
				code: 'homepage_missing_local_visual',
				selector: selectorFor( contentRoot ),
				evidence:
					'The first-run homepage contains no visible local visual asset.',
				remediation:
					'Add a reviewed, high-resolution local image that supports the chosen composition contract.',
			} );
		}
	}

	if (
		role === 'homepage' &&
		heroContract.strategy !== 'balanced' &&
		contentImages.length > 0
	) {
		const heroImage = contentImages[ 0 ];
		const heroContainer =
			heroImage.closest( '.wp-block-cover, figure, .wp-block-image' ) ||
			heroImage;
		const rect = heroContainer.getBoundingClientRect();
		const minRatio = Number(
			heroContract.desktop_media_min_viewport_ratio || 0.85
		);
		checks.hero_media_viewport_ratio = rect.width / viewport.width;
		if (
			viewport.label === 'desktop' &&
			rect.width / viewport.width < minRatio
		) {
			const immersive = heroContract.strategy === 'immersive-media';
			report( {
				code: immersive
					? 'immersive_hero_not_full_bleed'
					: 'hero_media_contract_width_failed',
				selector: selectorFor( heroContainer ),
				evidence: `The selected ${
					heroContract.strategy
				} hero spans ${ (
					( rect.width / viewport.width ) *
					100
				).toFixed(
					1
				) }% of the viewport; the contract requires at least ${ (
					minRatio * 100
				).toFixed( 0 ) }%.`,
				remediation: immersive
					? 'Use an alignfull Cover or full-bleed Group/Image structure; do not place immersive media inside a constrained post-content wrapper.'
					: 'Recompose the hero so its media meets the selected layout contract instead of falling back to a generic narrow column.',
			} );
		}

		const minHeight =
			( Number( heroContract.desktop_min_height_vh || 55 ) / 100 ) *
			viewport.height;
		if ( viewport.label === 'desktop' && rect.height < minHeight ) {
			const immersive = heroContract.strategy === 'immersive-media';
			report( {
				code: immersive
					? 'immersive_hero_too_shallow'
					: 'hero_media_contract_height_failed',
				selector: selectorFor( heroContainer ),
				evidence: `The selected ${
					heroContract.strategy
				} hero is ${ Math.round(
					rect.height
				) }px tall; the contract requires at least ${ Math.round(
					minHeight
				) }px at this viewport.`,
				remediation: immersive
					? 'Increase the Cover minimum height while keeping the primary content and CTA readable.'
					: 'Increase the hero media height to satisfy the selected composition without adding empty spacer blocks.',
			} );
		}

		if (
			viewport.label === 'desktop' &&
			heroImage.naturalWidth > 0 &&
			heroImage.naturalWidth < Math.max( 1600, rect.width * 1.25 )
		) {
			report( {
				code: 'hero_source_resolution_too_low',
				selector: selectorFor( heroImage ),
				evidence: `The selected ${
					heroContract.strategy
				} hero source is ${
					heroImage.naturalWidth
				}px wide, below the high-density requirement for a ${ Math.round(
					rect.width
				) }px rendered hero.`,
				remediation:
					'Replace it with a reviewed hero asset at least 1600px wide and preferably 2000px or wider.',
			} );
		}
	}

	const score = Math.max(
		0,
		100 - violations.length * 12 - warnings.length * 2
	);
	checks.composition_score = score;

	return {
		violations,
		warnings,
		checks,
		score,
		active_stylesheet: base.active_stylesheet || '',
	};
}

/**
 * Validate every affected page at the profile's required viewports.
 *
 * @param {Object} args Quality-gate arguments.
 * @return {Promise<Object>} Complete rendered-page report.
 */
export async function validatePageQuality( args ) {
	const profile = String( args?.profile || '' );
	const qualityToken = String( args?.quality_token || '' ).trim();
	const renderMode = String( args?.render_mode || '' );
	const visualReviewRequired = args?.visual_review_required === true;
	const pages = Array.isArray( args?.pages ) ? args.pages : [];
	const heroContract = args?.hero_contract || {};
	const viewports = PAGE_QUALITY_VIEWPORTS[ profile ] || [];
	const reports = [];
	const violations = [];
	const warnings = [];
	const screenshots = [];

	if ( ! [ 'setup', 'incremental' ].includes( profile ) ) {
		pushFinding(
			violations,
			finding( {
				code: 'invalid_page_quality_profile',
				url: '',
				viewport: null,
				selector: 'request',
				evidence: 'Page quality requires setup or incremental profile.',
				remediation:
					'Use the profile supplied by the server completion gate.',
			} )
		);
	}
	if ( ! [ 'preview', 'public' ].includes( renderMode ) ) {
		pushFinding(
			violations,
			finding( {
				code: 'invalid_page_render_mode',
				url: '',
				viewport: null,
				selector: 'request',
				evidence:
					'Page quality requires preview or public render mode.',
				remediation:
					'Use the render_mode supplied by the server completion gate.',
			} )
		);
	}
	if ( ! qualityToken ) {
		pushFinding(
			violations,
			finding( {
				code: 'missing_page_quality_token',
				url: '',
				viewport: null,
				selector: 'request',
				evidence:
					'The report is not bound to a current page mutation token.',
				remediation:
					'Use the quality_token supplied by page_quality_completion status.',
			} )
		);
	}
	if (
		pages.length === 0 ||
		pages.some(
			( page ) =>
				! Number.isInteger( Number( page?.post_id ) ) ||
				Number( page?.post_id ) <= 0 ||
				! page?.url ||
				page?.render_mode !== renderMode ||
				! [ 'homepage', 'page' ].includes( page?.role ) ||
				String( page.url ).includes( '/wp-content/uploads/' )
		)
	) {
		pushFinding(
			violations,
			finding( {
				code: 'invalid_page_quality_targets',
				url: '',
				viewport: null,
				selector: 'request',
				evidence:
					'Page quality requires one or more real published page targets.',
				remediation:
					'Pass the exact pages supplied by page_quality_completion status.',
			} )
		);
	}

	if ( violations.length ) {
		return {
			success: false,
			complete: false,
			passed: false,
			profile,
			quality_token: qualityToken,
			render_mode: renderMode,
			viewports,
			reports,
			violations,
			warnings,
			screenshots,
		};
	}

	for ( const page of pages ) {
		let renderUrl = String( page.url );
		try {
			// eslint-disable-next-line no-await-in-loop -- Each autosave is user/session specific.
			renderUrl = await resolveRenderUrl( page );
		} catch ( error ) {
			for ( const viewport of viewports ) {
				const item = finding( {
					code: 'preview_url_unavailable',
					url: page.url,
					viewport,
					selector: 'document',
					evidence:
						error.message ||
						'The private WordPress preview could not be resolved.',
					remediation:
						'Restore the current user autosave and rerun the server-owned preview validation.',
				} );
				pushFinding( violations, item );
				reports.push( {
					post_id: Number( page.post_id ),
					revision_id: Number( page.revision_id || 0 ),
					requested_url: normalizeUrl( page.url ),
					final_url: normalizeUrl( page.url ),
					role: page.role,
					render_mode: renderMode,
					viewport,
					success: false,
					violations: [ item ],
					warnings: [],
				} );
			}
			continue;
		}

		if (
			renderMode === 'public' &&
			profile === 'setup' &&
			page.role === 'homepage'
		) {
			// eslint-disable-next-line no-await-in-loop -- Each homepage has a distinct anonymous launch state.
			const publicFinding = await inspectAnonymousHomepage(
				page.url,
				viewports.find(
					( viewport ) => viewport.label === 'desktop'
				) || viewports[ 0 ]
			);
			if ( publicFinding ) {
				pushFinding( violations, publicFinding );
			}
		}

		for ( const viewport of viewports ) {
			// eslint-disable-next-line no-await-in-loop -- Quality reports must not race hidden iframe resources.
			const loaded = await loadSameOriginIframe( {
				url: renderUrl,
				width: viewport.width,
				height: viewport.height,
			} );

			if ( ! loaded.success || ! loaded.document || ! loaded.window ) {
				const item = finding( {
					code: 'frontend_render_failed',
					url: page.url,
					viewport,
					selector: 'document',
					evidence:
						loaded.error ||
						'The frontend document could not be rendered.',
					remediation:
						'Restore a renderable page and rerun the current quality report.',
				} );
				pushFinding( violations, item );
				reports.push( {
					post_id: Number( page.post_id ),
					revision_id: Number( page.revision_id || 0 ),
					requested_url: normalizeUrl( page.url ),
					final_url: normalizeUrl( page.url ),
					role: page.role,
					render_mode: renderMode,
					viewport,
					success: false,
					violations: [ item ],
					warnings: [],
				} );
				continue;
			}

			try {
				// eslint-disable-next-line no-await-in-loop -- Native lazy images must settle before natural dimensions are measured.
				await settleImages( loaded.document );
				const inspected = inspectPageQualityDocument( {
					document: loaded.document,
					window: loaded.window,
					url: page.url,
					viewport,
					profile,
					role: page.role,
					heroContract,
				} );
				const semanticallyHome = isHomepageDocument(
					loaded.document,
					renderUrl,
					page.url,
					page.role
				);
				if ( page.role === 'homepage' && ! semanticallyHome ) {
					const item = finding( {
						code: 'homepage_target_not_homepage',
						url: page.url,
						viewport,
						selector: 'body',
						evidence:
							'The expected homepage URL rendered a document without homepage semantics.',
						remediation:
							'Confirm show_on_front and page_on_front, then validate the real homepage URL.',
					} );
					inspected.violations.push( item );
				}

				reports.push( {
					post_id: Number( page.post_id ),
					revision_id: Number( page.revision_id || 0 ),
					requested_url: normalizeUrl( page.url ),
					final_url: normalizeUrl( page.url ),
					role: page.role,
					render_mode: renderMode,
					is_homepage: semanticallyHome,
					viewport,
					success: inspected.violations.length === 0,
					...inspected,
				} );
				for ( const item of inspected.violations ) {
					pushFinding( violations, item );
				}
				for ( const item of inspected.warnings ) {
					pushFinding( warnings, item );
				}

				if (
					visualReviewRequired &&
					profile === 'setup' &&
					page.role === 'homepage' &&
					[ 'mobile', 'desktop' ].includes( viewport.label )
				) {
					// eslint-disable-next-line no-await-in-loop -- Each screenshot belongs to this exact validated iframe render.
					const screenshot = await captureReviewScreenshot(
						loaded.document,
						viewport
					);
					if ( screenshot.success ) {
						screenshots.push( {
							post_id: Number( page.post_id ),
							url: normalizeUrl( page.url ),
							viewport,
							...screenshot,
						} );
					} else {
						const item = finding( {
							code: 'visual_review_screenshot_failed',
							url: page.url,
							viewport,
							selector: 'document',
							evidence: screenshot.error,
							remediation:
								'Restore screenshot capture and rerun page quality so the Setup Assistant can perform its visual critique.',
						} );
						pushFinding( violations, item );
					}
				}
			} catch ( error ) {
				const item = finding( {
					code: 'page_quality_inspection_failed',
					url: page.url,
					viewport,
					selector: 'document',
					evidence:
						error.message ||
						'Page-quality inspection failed unexpectedly.',
					remediation:
						'Repair the rendered page or browser environment and rerun validation.',
				} );
				pushFinding( violations, item );
				reports.push( {
					post_id: Number( page.post_id ),
					revision_id: Number( page.revision_id || 0 ),
					requested_url: normalizeUrl( page.url ),
					final_url: normalizeUrl( page.url ),
					role: page.role,
					render_mode: renderMode,
					viewport,
					success: false,
					violations: [ item ],
					warnings: [],
				} );
			} finally {
				loaded.cleanup();
			}
		}
	}

	const expectedReports = pages.length * viewports.length;
	const complete =
		reports.length === expectedReports &&
		reports.every( ( report ) => report.final_url );
	const passed =
		complete &&
		violations.length === 0 &&
		reports.every( ( report ) => report.success );

	return {
		success: complete,
		complete,
		passed,
		profile,
		quality_token: qualityToken,
		render_mode: renderMode,
		viewports,
		reports,
		violations,
		warnings,
		screenshots,
		minimum_score: reports.length
			? Math.min( ...reports.map( ( report ) => report.score || 0 ) )
			: 0,
	};
}
