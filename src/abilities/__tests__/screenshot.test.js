/**
 * Unit tests for screenshot URL validation.
 */

jest.mock( 'html2canvas', () =>
	jest.fn( async () => ( {
		width: 700,
		height: 438,
		toDataURL: () => 'data:image/jpeg;base64,c2NyZWVuc2hvdA==',
	} ) )
);

/**
 * Load an isolated screenshot module instance.
 *
 * @return {Object} Screenshot module exports.
 */
function loadScreenshotModule() {
	let mod;
	jest.isolateModules( () => {
		// eslint-disable-next-line global-require
		mod = require( '../screenshot' );
	} );
	return mod;
}

/**
 * Load the screenshot ability and its matching registry module instance.
 *
 * @return {{ screenshot: Object, registry: Object }} Isolated modules.
 */
function loadScreenshotAndRegistry() {
	let screenshot;
	let registry;
	jest.isolateModules( () => {
		// eslint-disable-next-line global-require
		screenshot = require( '../screenshot' );
		// eslint-disable-next-line global-require
		registry = require( '../registry' );
	} );
	return { screenshot, registry };
}

describe( 'screenshot-url validation', () => {
	beforeEach( () => {
		window.sdAiAgentData = {
			screenshotOrigins: [
				window.location.origin,
				'https://template.myshopmaker.ng',
			],
		};
	} );

	afterEach( () => {
		delete window.sdAiAgentData;
	} );

	test( 'accepts same-origin URLs from the authorized WordPress origins list', () => {
		const { validateScreenshotUrl } = loadScreenshotModule();

		expect( validateScreenshotUrl( '/product/t-shirt-ne/' ) ).toMatchObject(
			{
				valid: true,
				authorized: true,
				resolved: `${ window.location.origin }/product/t-shirt-ne/`,
				error: '',
			}
		);
	} );

	test( 'recognizes an authorized multisite subdomain and returns capture guidance', () => {
		const { validateScreenshotUrl } = loadScreenshotModule();

		const result = validateScreenshotUrl(
			'https://template.myshopmaker.ng/product/t-shirt-ne/'
		);

		expect( result ).toMatchObject( {
			valid: false,
			authorized: true,
			resolved: 'https://template.myshopmaker.ng/product/t-shirt-ne/',
		} );
		expect( result.error ).toContain( 'Authorized WordPress site' );
		expect( result.error ).toContain( 'screenshot capture requires' );
		expect( result.error ).toContain( 'wp-admin origin' );
	} );

	test( 'rejects unrelated external domains even when multisite origins are configured', () => {
		const { validateScreenshotUrl } = loadScreenshotModule();

		const result = validateScreenshotUrl( 'https://example.com/product/' );

		expect( result ).toMatchObject( {
			valid: false,
			authorized: false,
			resolved: 'https://example.com/product/',
		} );
		expect( result.error ).toContain( 'authorized WordPress site' );
		expect( result.error ).toContain( 'https://example.com' );
	} );
} );

describe( 'full-page screenshot safety', () => {
	test( 'rejects unsafe full-page canvas dimensions before capture', () => {
		const { isFullPageCaptureSafe } = loadScreenshotModule();

		expect( isFullPageCaptureSafe( 1280, 10000 ) ).toBe( true );
		expect( isFullPageCaptureSafe( 2560, 10000 ) ).toBe( false );
	} );

	test( 'publishes bounded-capture guidance in direct client metadata', async () => {
		delete global.wp;
		const { screenshot, registry } = loadScreenshotAndRegistry();

		await screenshot.registerCaptureScreenshotAbility();
		await screenshot.registerScreenshotUrlAbility();
		const descriptors = await registry.snapshotDescriptors();

		for ( const name of [
			'sd-ai-agent-js/capture-screenshot',
			'sd-ai-agent-js/screenshot-url',
		] ) {
			const descriptor = descriptors.find(
				( candidate ) => candidate.name === name
			);
			expect( descriptor.description ).toContain( 'routine review' );
			expect(
				descriptor.input_schema.properties.fullPage.description
			).toContain( 'Default: false' );
			expect( descriptor.output_schema.properties ).toHaveProperty(
				'truncated'
			);
			if ( name === 'sd-ai-agent-js/capture-screenshot' ) {
				expect( descriptor.input_schema ).not.toHaveProperty(
					'required'
				);
			} else {
				expect( descriptor.input_schema.required ).toEqual( [ 'url' ] );
			}
		}
	} );
} );

describe( 'screenshot-url iframe navigation', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		document.body.innerHTML = '';
		window.sdAiAgentData = {
			screenshotOrigins: [ window.location.origin ],
		};
	} );

	afterEach( () => {
		jest.clearAllTimers();
		jest.useRealTimers();
		delete window.sdAiAgentData;
		delete window.__sdAiAgentClientAbilityRegistry;
		document.body.innerHTML = '';
	} );

	test( 'captures a same-origin iframe that loads after the initial window', async () => {
		const { executeScreenshotUrl } = loadScreenshotModule();
		const resultPromise = executeScreenshotUrl( { url: '/wp-admin/' } );
		const iframe = document.querySelector( 'iframe' );

		expect( iframe ).not.toBeNull();
		Object.defineProperty( iframe, 'contentDocument', {
			configurable: true,
			value: document.implementation.createHTMLDocument(),
		} );
		await jest.advanceTimersByTimeAsync( 16000 );
		iframe.dispatchEvent( new Event( 'load' ) );
		await jest.advanceTimersByTimeAsync( 1500 );

		await expect( resultPromise ).resolves.toMatchObject( {
			success: true,
			url: `${ window.location.origin }/wp-admin/`,
			error: '',
		} );
		expect( document.querySelector( 'iframe' ) ).toBeNull();
		expect( jest.getTimerCount() ).toBe( 0 );
	} );

	test( 'returns a bounded navigation error and cleans up a never-loading iframe', async () => {
		const { executeScreenshotUrl } = loadScreenshotModule();
		const resultPromise = executeScreenshotUrl( { url: '/wp-admin/' } );

		expect( document.querySelector( 'iframe' ) ).not.toBeNull();
		await jest.advanceTimersByTimeAsync( 60000 );

		await expect( resultPromise ).resolves.toMatchObject( {
			success: false,
			error: 'Screenshot failed: Iframe navigation timed out after 60 seconds.',
		} );
		expect( document.querySelector( 'iframe' ) ).toBeNull();
		expect( jest.getTimerCount() ).toBe( 0 );
	} );

	test( 'returns a distinct navigation failure and cleans up the iframe', async () => {
		const { executeScreenshotUrl } = loadScreenshotModule();
		const resultPromise = executeScreenshotUrl( { url: '/wp-admin/' } );
		const iframe = document.querySelector( 'iframe' );

		iframe.dispatchEvent( new Event( 'error' ) );

		await expect( resultPromise ).resolves.toMatchObject( {
			success: false,
			error: 'Screenshot failed: Iframe failed to load.',
		} );
		expect( document.querySelector( 'iframe' ) ).toBeNull();
		expect( jest.getTimerCount() ).toBe( 0 );
	} );
} );
