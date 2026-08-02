/**
 * Focused browser-side tests for rendered page-quality validation.
 */

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

jest.mock( '../theme-completion-iframe', () => ( {
	loadSameOriginIframe: jest.fn(),
} ) );

jest.mock( 'html2canvas', () =>
	jest.fn( async () => ( {
		width: 700,
		height: 438,
		toDataURL: () => 'data:image/jpeg;base64,cmV2aWV3',
	} ) )
);

const originalFetch = global.fetch;
const apiFetch = require( '@wordpress/api-fetch' );
const { loadSameOriginIframe } = require( '../theme-completion-iframe' );
const {
	PAGE_QUALITY_VIEWPORTS,
	inspectPageQualityDocument,
	validatePageQuality,
} = require( '../page-quality-validation-core' );

const heroContract = {
	strategy: 'immersive-media',
	media_role: 'primary',
	desktop_media_min_viewport_ratio: 0.9,
	desktop_min_height_vh: 60,
	primary_cta_above_fold: true,
};

/** Prepare a valid first-impression document and browser metrics. */
function prepareDocument() {
	document.documentElement.lang = 'en';
	document.body.className = 'home front-page wp-theme-demo-theme';
	document.body.innerHTML = `
		<header>
			<nav><a href="/work/">Work</a></nav>
		</header>
		<main>
			<div class="wp-block-post-content">
				<div class="wp-block-cover alignfull">
					<img class="wp-block-cover__image-background" src="/hero.jpg" alt="Studio work">
					<h1>Studio portfolio</h1>
					<div class="wp-block-button"><a href="/contact/">Start a project</a></div>
				</div>
			</div>
		</main>
		<footer><a href="/privacy/">Privacy</a></footer>
	`;

	const image = document.querySelector( 'img' );
	Object.defineProperty( image, 'complete', {
		configurable: true,
		value: true,
	} );
	Object.defineProperty( image, 'naturalWidth', {
		configurable: true,
		value: 2400,
	} );
	Object.defineProperty( image, 'naturalHeight', {
		configurable: true,
		value: 1400,
	} );
	Object.defineProperty( document.documentElement, 'clientWidth', {
		configurable: true,
		value: 1280,
	} );
	Object.defineProperty( document.documentElement, 'scrollWidth', {
		configurable: true,
		value: 1280,
	} );

	jest.spyOn(
		HTMLElement.prototype,
		'getBoundingClientRect'
	).mockImplementation( function getRect() {
		if (
			this.classList?.contains( 'wp-block-cover' ) ||
			this.classList?.contains( 'wp-block-cover__image-background' )
		) {
			return {
				width: 1280,
				height: 700,
				top: 0,
				left: 0,
				bottom: 700,
				right: 1280,
			};
		}
		return {
			width: 160,
			height: 40,
			top: 100,
			left: 20,
			bottom: 140,
			right: 180,
		};
	} );
	jest.spyOn( window, 'getComputedStyle' ).mockReturnValue( {
		display: 'block',
		visibility: 'visible',
		opacity: '1',
		outlineStyle: 'solid',
		outlineWidth: '2px',
		boxShadow: 'none',
		color: 'rgb(0, 0, 0)',
		backgroundColor: 'rgb(255, 255, 255)',
	} );
}

/**
 * Build validator arguments for a quality profile.
 *
 * @param {string} profile Quality profile.
 * @return {Object} Validator arguments.
 */
function args( profile = 'setup' ) {
	return {
		profile,
		quality_token: 'current-token',
		render_mode: 'public',
		visual_review_required: true,
		pages: [
			{
				post_id: 42,
				revision_id: 100,
				url: window.location.origin + '/',
				role: 'homepage',
				render_mode: 'public',
				fields: [ 'post_content' ],
			},
		],
		hero_contract: heroContract,
		viewports: PAGE_QUALITY_VIEWPORTS[ profile ],
	};
}

describe( 'rendered page quality validator', () => {
	beforeEach( () => {
		jest.restoreAllMocks();
		jest.clearAllMocks();
		prepareDocument();
		apiFetch.mockResolvedValue( {} );
		loadSameOriginIframe.mockImplementation( async ( { url } ) => ( {
			success: true,
			url,
			error: '',
			document,
			window,
			cleanup: jest.fn(),
		} ) );
		global.fetch = jest.fn( async () => ( {
			ok: true,
			status: 200,
			text: async () =>
				'<!doctype html><html><body class="home"></body></html>',
		} ) );
	} );

	afterEach( () => {
		jest.restoreAllMocks();
		document.body.className = '';
		document.body.innerHTML = '';
		global.fetch = originalFetch;
	} );

	test( 'uses a fuller setup matrix than incremental edits', () => {
		expect( PAGE_QUALITY_VIEWPORTS.setup ).toHaveLength( 3 );
		expect( PAGE_QUALITY_VIEWPORTS.incremental ).toEqual( [
			{ label: 'mobile', width: 375, height: 812 },
			{ label: 'desktop', width: 1280, height: 800 },
		] );
	} );

	test( 'passes a full-bleed, high-resolution first impression', async () => {
		const report = await validatePageQuality( args() );

		expect( loadSameOriginIframe ).toHaveBeenCalledTimes( 3 );
		expect( report ).toMatchObject( {
			success: true,
			complete: true,
			passed: true,
			profile: 'setup',
			quality_token: 'current-token',
		} );
		expect( report.violations ).toEqual( [] );
		expect( report.screenshots ).toHaveLength( 2 );
		expect( report.screenshots[ 0 ].image ).toMatch(
			/^data:image\/jpeg;base64,/
		);
	} );

	test( 'resolves an authenticated autosave preview without leaking its nonce', async () => {
		const input = args();
		input.render_mode = 'preview';
		Object.assign( input.pages[ 0 ], {
			url: window.location.origin + '/?page_id=42',
			render_mode: 'preview',
			revision_id: 155,
			workspace_id: 'workspace-1',
			preview_rest_path: '/wp/v2/pages/42/autosaves/155?context=edit',
			generation: 2,
			working_hash: 'working-hash',
			featured_image_id: 77,
		} );
		apiFetch.mockResolvedValue( {
			id: 155,
			parent: 42,
			preview_link:
				window.location.origin +
				'/?preview_id=42&preview_nonce=secret&preview=true',
		} );

		const report = await validatePageQuality( input );

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/wp/v2/pages/42/autosaves/155?context=edit',
		} );
		expect( loadSameOriginIframe ).toHaveBeenCalledWith(
			expect.objectContaining( {
				url: expect.stringContaining( 'preview_nonce=secret' ),
			} )
		);
		expect( global.fetch ).not.toHaveBeenCalled();
		expect( report ).toMatchObject( {
			passed: true,
			render_mode: 'preview',
		} );
		expect( JSON.stringify( report ) ).not.toContain( 'secret' );
		expect( report.reports[ 0 ] ).toMatchObject( {
			requested_url: window.location.origin + '/?page_id=42',
			final_url: window.location.origin + '/?page_id=42',
			render_mode: 'preview',
		} );
	} );

	test( 'reports the iframe final URL after a same-origin redirect', async () => {
		loadSameOriginIframe.mockImplementation( async () => ( {
			success: true,
			url: window.location.origin + '/login/',
			error: '',
			document,
			window,
			cleanup: jest.fn(),
		} ) );

		const report = await validatePageQuality( args() );

		expect( report.reports[ 0 ].final_url ).toBe(
			window.location.origin + '/login'
		);
	} );

	test( 'skips duplicate screenshots for the final public smoke check', async () => {
		const input = args();
		input.visual_review_required = false;

		const report = await validatePageQuality( input );

		expect( report.passed ).toBe( true );
		expect( report.screenshots ).toEqual( [] );
	} );

	test( 'rejects a homepage hidden behind anonymous coming-soon mode', async () => {
		global.fetch.mockResolvedValue( {
			ok: true,
			status: 200,
			text: async () =>
				'<html><head><meta name="woo-coming-soon-page" content="yes"></head><body></body></html>',
		} );

		const report = await validatePageQuality( args() );

		expect( report.passed ).toBe( false );
		expect( report.violations ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					code: 'public_homepage_coming_soon',
				} ),
			] )
		);
	} );

	test( 'rejects nested main landmarks and duplicate H1 output', () => {
		document
			.querySelector( 'main' )
			.insertAdjacentHTML(
				'beforeend',
				'<main><h1>Second title</h1></main>'
			);

		const result = inspectPageQualityDocument( {
			document,
			window,
			url: window.location.origin + '/',
			viewport: PAGE_QUALITY_VIEWPORTS.setup[ 2 ],
			profile: 'setup',
			role: 'homepage',
			heroContract,
		} );

		expect( result.violations ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( { code: 'nested_main_landmark' } ),
				expect.objectContaining( { code: 'invalid_page_heading' } ),
			] )
		);
	} );

	test( 'blocks an oversized Setup header but keeps it advisory for General', () => {
		HTMLElement.prototype.getBoundingClientRect.mockImplementation(
			function getRect() {
				if ( this.tagName === 'HEADER' ) {
					return {
						width: 1280,
						height: 360,
						top: 0,
						left: 0,
						bottom: 360,
						right: 1280,
					};
				}
				return {
					width: 160,
					height: 40,
					top: 100,
					left: 20,
					bottom: 140,
					right: 180,
				};
			}
		);

		const inspect = ( profile ) =>
			inspectPageQualityDocument( {
				document,
				window,
				url: window.location.origin + '/',
				viewport: PAGE_QUALITY_VIEWPORTS.setup[ 2 ],
				profile,
				role: 'homepage',
				heroContract,
			} );
		const setup = inspect( 'setup' );
		const general = inspect( 'incremental' );

		expect( setup.violations ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( { code: 'oversized_site_header' } ),
			] )
		);
		expect( general.violations ).not.toEqual(
			expect.arrayContaining( [
				expect.objectContaining( { code: 'oversized_site_header' } ),
			] )
		);
		expect( general.warnings ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( { code: 'oversized_site_header' } ),
			] )
		);
	} );

	test( 'rejects a constrained immersive hero and low-resolution source', () => {
		const image = document.querySelector( 'img' );
		Object.defineProperty( image, 'naturalWidth', {
			configurable: true,
			value: 500,
		} );
		HTMLElement.prototype.getBoundingClientRect.mockImplementation(
			function getRect() {
				if (
					this.classList?.contains( 'wp-block-cover' ) ||
					this.classList?.contains(
						'wp-block-cover__image-background'
					)
				) {
					return {
						width: 620,
						height: 425,
						top: 200,
						left: 330,
						bottom: 625,
						right: 950,
					};
				}
				return {
					width: 160,
					height: 40,
					top: 100,
					left: 20,
					bottom: 140,
					right: 180,
				};
			}
		);

		const result = inspectPageQualityDocument( {
			document,
			window,
			url: window.location.origin + '/',
			viewport: PAGE_QUALITY_VIEWPORTS.setup[ 2 ],
			profile: 'setup',
			role: 'homepage',
			heroContract,
		} );

		expect( result.violations ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					code: 'immersive_hero_not_full_bleed',
				} ),
				expect.objectContaining( { code: 'image_upscaled' } ),
				expect.objectContaining( {
					code: 'hero_source_resolution_too_low',
				} ),
			] )
		);

		const editorialResult = inspectPageQualityDocument( {
			document,
			window,
			url: window.location.origin + '/',
			viewport: PAGE_QUALITY_VIEWPORTS.setup[ 2 ],
			profile: 'setup',
			role: 'homepage',
			heroContract: {
				...heroContract,
				strategy: 'editorial-feature',
				desktop_media_min_viewport_ratio: 0.55,
			},
		} );
		expect( editorialResult.violations ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					code: 'hero_media_contract_width_failed',
				} ),
			] )
		);
	} );

	test( 'rejects a Twenty Twenty-Four-style fallback with duplicate media and placeholders', () => {
		const duplicate = document.querySelector( 'img' ).cloneNode( true );
		duplicate.className = 'wp-post-image';
		Object.defineProperty( duplicate, 'complete', {
			configurable: true,
			value: true,
		} );
		Object.defineProperty( duplicate, 'naturalWidth', {
			configurable: true,
			value: 2400,
		} );
		document
			.querySelector( '.wp-block-post-content' )
			.appendChild( duplicate );
		document
			.querySelector( '.wp-block-post-content' )
			.insertAdjacentHTML(
				'beforeend',
				'<a href="mailto:hello@example.com">Email us</a>'
			);

		const result = inspectPageQualityDocument( {
			document,
			window,
			url: window.location.origin + '/',
			viewport: PAGE_QUALITY_VIEWPORTS.setup[ 2 ],
			profile: 'setup',
			role: 'homepage',
			heroContract,
		} );

		expect( result.violations ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					code: 'duplicate_featured_content_image',
				} ),
				expect.objectContaining( {
					code: 'placeholder_destination',
				} ),
			] )
		);
	} );
} );
