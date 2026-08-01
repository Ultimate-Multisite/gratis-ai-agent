/**
 * Focused browser-side tests for generated-theme completion validation.
 */

jest.mock( '../theme-completion-iframe', () => ( {
	loadSameOriginIframe: jest.fn(),
} ) );

const { loadSameOriginIframe } = require( '../theme-completion-iframe' );
const {
	THEME_COMPLETION_VIEWPORTS,
	inspectThemeDocument,
	validateThemeCompletion,
} = require( '../theme-completion-validator' );

const stylesheet = 'generated-demo-theme';
const fingerprint = 'current-project-fingerprint';
const urls = [ 'https://example.test/', 'https://example.test/contact/' ];
const completionArgs = {
	stylesheet,
	fingerprint,
	homepage_url: urls[ 0 ],
	interior_url: urls[ 1 ],
};

/**
 * Prepare a minimal valid activated frontend document and browser metrics.
 */
function prepareValidDocument() {
	document.documentElement.lang = 'en';
	document.body.className = `wp-theme-${ stylesheet }`;
	document.body.innerHTML = `
		<header><nav><a href="/contact/">Contact us</a></nav></header>
		<main><h1>Example business</h1><p>Real useful copy for visitors.</p></main>
		<footer><a href="/privacy/">Privacy</a></footer>
	`;

	Object.defineProperty( document.documentElement, 'clientWidth', {
		configurable: true,
		value: 375,
	} );
	Object.defineProperty( document.documentElement, 'scrollWidth', {
		configurable: true,
		value: 375,
	} );

	jest.spyOn(
		HTMLElement.prototype,
		'getBoundingClientRect'
	).mockReturnValue( {
		width: 100,
		height: 24,
		top: 0,
		left: 0,
		bottom: 24,
		right: 100,
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

describe( 'generated theme completion validator', () => {
	beforeEach( () => {
		jest.restoreAllMocks();
		jest.clearAllMocks();
		prepareValidDocument();
		loadSameOriginIframe.mockImplementation( async ( { url } ) => ( {
			success: true,
			url,
			error: '',
			document,
			window,
			cleanup: jest.fn(),
		} ) );
	} );

	afterEach( () => {
		jest.restoreAllMocks();
		document.body.className = '';
		document.body.innerHTML = '';
		document
			.querySelectorAll( '[data-theme-completion-test]' )
			.forEach( ( element ) => element.remove() );
	} );

	test( 'uses the required mobile, tablet, and desktop viewport matrix', () => {
		expect( THEME_COMPLETION_VIEWPORTS ).toEqual( [
			{ label: 'mobile', width: 375, height: 812 },
			{ label: 'tablet', width: 768, height: 1024 },
			{ label: 'desktop', width: 1280, height: 800 },
		] );
	} );

	test( 'requires exactly a homepage and published interior page', async () => {
		const report = await validateThemeCompletion( {
			stylesheet,
			fingerprint,
			homepage_url: urls[ 0 ],
		} );

		expect( report ).toMatchObject( {
			success: false,
			complete: false,
			passed: false,
		} );
		expect( report.violations ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( { code: 'invalid_completion_urls' } ),
			] )
		);
	} );

	test( 'reports document overflow with viewport, selector, and remediation evidence', () => {
		Object.defineProperty( document.documentElement, 'scrollWidth', {
			configurable: true,
			value: 420,
		} );

		const result = inspectThemeDocument( {
			document,
			window,
			url: urls[ 0 ],
			viewport: THEME_COMPLETION_VIEWPORTS[ 0 ],
			expectedStylesheet: stylesheet,
		} );

		expect( result.success ).toBe( true );
		expect( result.violations ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					code: 'document_horizontal_overflow',
					selector: 'html',
					url: urls[ 0 ].replace( /\/$/, '' ),
					viewport: THEME_COMPLETION_VIEWPORTS[ 0 ],
					remediation: expect.any( String ),
				} ),
			] )
		);
	} );

	test( 'reports an essential element hidden by a reduced-motion rule', () => {
		const style = document.createElement( 'style' );
		style.setAttribute( 'data-theme-completion-test', 'reduced-motion' );
		style.textContent =
			'@media (prefers-reduced-motion: reduce) { header { display: none; } }';
		document.head.appendChild( style );

		const result = inspectThemeDocument( {
			document,
			window,
			url: urls[ 0 ],
			viewport: THEME_COMPLETION_VIEWPORTS[ 0 ],
			expectedStylesheet: stylesheet,
		} );

		expect( result.violations ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					code: 'reduced_motion_hidden_content',
					selector: 'header',
					url: urls[ 0 ].replace( /\/$/, '' ),
					viewport: THEME_COMPLETION_VIEWPORTS[ 0 ],
					severity: 'error',
					remediation: expect.any( String ),
				} ),
			] )
		);
	} );

	test( 'reports an image with no local source', () => {
		document
			.querySelector( 'main' )
			.insertAdjacentHTML( 'beforeend', '<img alt="Missing source">' );

		const result = inspectThemeDocument( {
			document,
			window,
			url: urls[ 0 ],
			viewport: THEME_COMPLETION_VIEWPORTS[ 0 ],
			expectedStylesheet: stylesheet,
		} );

		expect( result.violations ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					code: 'image_missing_source',
					url: urls[ 0 ].replace( /\/$/, '' ),
					viewport: THEME_COMPLETION_VIEWPORTS[ 0 ],
					severity: 'error',
					remediation: expect.any( String ),
				} ),
			] )
		);
	} );

	test( 'ignores logged-in WordPress admin-bar controls and remote avatars', () => {
		document.body.insertAdjacentHTML(
			'afterbegin',
			'<div id="wpadminbar"><a class="ab-item" href="#"><img src="https://secure.gravatar.com/avatar/demo" alt="Admin"></a><button></button></div>'
		);

		const result = inspectThemeDocument( {
			document,
			window,
			url: urls[ 0 ],
			viewport: THEME_COMPLETION_VIEWPORTS[ 0 ],
			expectedStylesheet: stylesheet,
		} );
		const codes = result.violations.map( ( item ) => item.code );

		expect( codes ).not.toContain( 'unprovided_remote_image' );
		expect( codes ).not.toContain( 'empty_or_hash_link' );
		expect( codes ).not.toContain( 'control_missing_accessible_name' );
	} );

	test( 'uses descendant image alt text for an image-only logo link', () => {
		document
			.querySelector( 'header' )
			.insertAdjacentHTML(
				'afterbegin',
				'<a class="custom-logo-link" href="/"><img src="/logo.png" alt="Example business"></a>'
			);

		const result = inspectThemeDocument( {
			document,
			window,
			url: urls[ 0 ],
			viewport: THEME_COMPLETION_VIEWPORTS[ 0 ],
			expectedStylesheet: stylesheet,
		} );

		expect(
			result.violations.filter(
				( item ) => item.code === 'control_missing_accessible_name'
			)
		).toEqual( [] );
	} );

	test( 'returns structured violations for frontend render failures', async () => {
		loadSameOriginIframe.mockImplementationOnce( async ( { url } ) => ( {
			success: false,
			url,
			error: 'Iframe load timed out.',
			document: null,
			window: null,
			cleanup: jest.fn(),
		} ) );

		const report = await validateThemeCompletion( {
			...completionArgs,
		} );

		expect( report ).toMatchObject( {
			success: false,
			complete: false,
			passed: false,
		} );
		expect( report.violations ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					code: 'frontend_render_failed',
					url: urls[ 0 ].replace( /\/$/, '' ),
					viewport: THEME_COMPLETION_VIEWPORTS[ 0 ],
					selector: 'document',
					severity: 'error',
					evidence: 'Iframe load timed out.',
					remediation: expect.any( String ),
				} ),
			] )
		);
	} );

	test( 'reports requested and final URLs with homepage semantics', async () => {
		loadSameOriginIframe.mockImplementation( async ( { url } ) => ( {
			success: true,
			url: url === urls[ 1 ].replace( /\/$/, '' ) ? urls[ 0 ] : url,
			error: '',
			document,
			window,
			cleanup: jest.fn(),
		} ) );

		const report = await validateThemeCompletion( {
			...completionArgs,
		} );
		const redirectedInterior = report.reports.find(
			( row ) =>
				row.role === 'interior' && row.viewport.label === 'mobile'
		);

		expect( redirectedInterior ).toMatchObject( {
			requested_url: urls[ 1 ].replace( /\/$/, '' ),
			final_url: urls[ 0 ].replace( /\/$/, '' ),
			role: 'interior',
			is_homepage: true,
		} );
	} );

	test( 'keeps browser execution unavailability distinct from fatal rendering', async () => {
		loadSameOriginIframe.mockImplementation( async ( { url } ) => ( {
			success: false,
			url,
			error: 'Browser execution unavailable.',
			document: null,
			window: null,
			cleanup: jest.fn(),
		} ) );

		const report = await validateThemeCompletion( {
			...completionArgs,
		} );

		expect( report.browser_execution_unavailable ).toBe( true );
		expect( report.fatal_render_failure ).toBe( false );
	} );

	test( 'passes only after all real URLs and required viewports render cleanly', async () => {
		const report = await validateThemeCompletion( {
			...completionArgs,
		} );

		expect( loadSameOriginIframe ).toHaveBeenCalledTimes( 6 );
		expect( report ).toMatchObject( {
			success: true,
			complete: true,
			passed: true,
			fatal_render_failure: false,
			stylesheet,
			fingerprint,
		} );
		expect( report.reports ).toHaveLength( 6 );
		expect( report.reports ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					requested_url: urls[ 0 ].replace( /\/$/, '' ),
					final_url: urls[ 0 ].replace( /\/$/, '' ),
					role: 'homepage',
					is_homepage: true,
				} ),
				expect.objectContaining( {
					requested_url: urls[ 1 ].replace( /\/$/, '' ),
					role: 'interior',
					is_homepage: false,
				} ),
			] )
		);
		expect( report.violations ).toEqual( [] );
	} );
} );
