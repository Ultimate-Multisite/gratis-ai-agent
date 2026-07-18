import { validateSameOrigin } from '../screenshot';

jest.mock( '../registry', () => ( {
	registerClientAbility: jest.fn(),
} ) );

describe( 'screenshot-url validation', () => {
	let originalLocation;
	let originalData;

	beforeEach( () => {
		originalLocation = window.location.href;
		originalData = window.sdAiAgentData;

		window.history.pushState( {}, '', '/wp-admin/admin.php' );
		window.sdAiAgentData = {
			screenshotOrigins: [
				window.location.origin,
				'https://template.myshopmaker.ng',
			],
		};
	} );

	afterEach( () => {
		window.history.pushState( {}, '', originalLocation );
		window.sdAiAgentData = originalData;
	} );

	test( 'accepts relative paths on the current browser origin', () => {
		expect( validateSameOrigin( '/shop/' ) ).toEqual( {
			valid: true,
			resolved: `${ window.location.origin }/shop/`,
			error: '',
		} );
	} );

	test( 'returns actionable guidance for recognised multisite subsite origins', () => {
		const result = validateSameOrigin( 'https://template.myshopmaker.ng/shop/' );

		expect( result.valid ).toBe( false );
		expect( result.resolved ).toBe( 'https://template.myshopmaker.ng/shop/' );
		expect( result.error ).toContain( 'another site in this WordPress network' );
		expect( result.error ).toContain( 'Open the AI Agent chat or floating widget' );
		expect( result.error ).toContain( 'retry with a relative path' );
	} );

	test( 'rejects unknown off-site origins without calling them WordPress network sites', () => {
		const result = validateSameOrigin( 'https://example.net/' );

		expect( result.valid ).toBe( false );
		expect( result.error ).toBe(
			`URL must be on the same browser origin (${ window.location.origin }). Got: https://example.net`
		);
	} );
} );
