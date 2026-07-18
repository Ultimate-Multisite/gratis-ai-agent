/**
 * Unit tests for screenshot URL validation.
 */

/**
 *
 */
function loadScreenshotModule() {
	let mod;
	jest.isolateModules( () => {
		// eslint-disable-next-line global-require
		mod = require( '../screenshot' );
	} );
	return mod;
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
		expect( result.error ).toContain( 'authorized WordPress site' );
		expect( result.error ).toContain( 'must run from the same origin' );
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
