/**
 * Internal dependencies
 */
import { fetchFreshPage, REFLECTOR_HEADER } from '../dom-morph';

describe( 'fetchFreshPage', () => {
	let dateSpy;

	beforeEach( () => {
		dateSpy = jest.spyOn( Date, 'now' ).mockReturnValue( 1770000000000 );
	} );

	afterEach( () => {
		dateSpy.mockRestore();
	} );

	it( 'adds cache bypass request options and timestamp query parameter', async () => {
		const fetchImpl = jest.fn().mockResolvedValue( {
			ok: true,
			text: jest
				.fn()
				.mockResolvedValue( '<main id="fresh">fresh</main>' ),
		} );

		const freshDoc = await fetchFreshPage(
			'https://example.test/page?foo=bar',
			fetchImpl
		);

		expect( freshDoc.querySelector( 'main' ).id ).toBe( 'fresh' );

		expect( fetchImpl ).toHaveBeenCalledWith(
			'https://example.test/page?foo=bar&_=1770000000000',
			expect.objectContaining( {
				cache: 'no-store',
				credentials: 'same-origin',
				headers: {
					Accept: 'text/html',
					[ REFLECTOR_HEADER ]: '1',
				},
			} )
		);
	} );

	it( 'throws when the fresh page request fails', async () => {
		const fetchImpl = jest.fn().mockResolvedValue( {
			ok: false,
			status: 503,
		} );

		await expect(
			fetchFreshPage( 'https://example.test/page', fetchImpl )
		).rejects.toThrow( 'HTTP 503' );
	} );
} );
