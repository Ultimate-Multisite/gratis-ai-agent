/**
 * Internal dependencies
 */
import {
	fetchFreshPage,
	morphTargetFromFresh,
	REFLECTOR_HEADER,
} from '../dom-morph';

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

describe( 'morphTargetFromFresh', () => {
	beforeEach( () => {
		window.scrollTo = jest.fn();
	} );

	test( 'updates the target node while leaving siblings untouched', () => {
		document.body.innerHTML = `
			<main>
				<div class="entry-content"><p>Old content</p></div>
				<aside>Keep me</aside>
			</main>
		`;
		const fresh = new DOMParser().parseFromString(
			`
				<main>
					<div class="entry-content"><p>Fresh content</p></div>
					<aside>Changed remotely</aside>
				</main>
			`,
			'text/html'
		);

		const morphed = morphTargetFromFresh(
			document,
			fresh,
			'.entry-content'
		);

		expect( morphed ).toBe( true );
		expect( document.querySelector( '.entry-content' ).textContent ).toBe(
			'Fresh content'
		);
		expect( document.querySelector( 'aside' ).textContent ).toBe(
			'Keep me'
		);
	} );

	test( 'preserves focused form controls and scroll position', () => {
		document.body.innerHTML = `
			<div class="entry-content" style="height: 10px; overflow: auto;">
				<input value="User typed" />
				<p>Old</p>
			</div>
		`;
		const target = document.querySelector( '.entry-content' );
		target.scrollTop = 5;
		document.querySelector( 'input' ).focus();

		const fresh = new DOMParser().parseFromString(
			`
				<div class="entry-content">
					<input value="Remote value" />
					<p>Fresh</p>
				</div>
			`,
			'text/html'
		);

		morphTargetFromFresh( document, fresh, '.entry-content' );

		expect( document.activeElement.value ).toBe( 'User typed' );
		expect( document.querySelector( 'p' ).textContent ).toBe( 'Fresh' );
		expect( target.scrollTop ).toBe( 5 );
	} );

	test( 'returns false when either target is missing', () => {
		document.body.innerHTML = '<div class="entry-content">Old</div>';
		const fresh = new DOMParser().parseFromString(
			'<div class="other">Fresh</div>',
			'text/html'
		);

		expect(
			morphTargetFromFresh( document, fresh, '.entry-content' )
		).toBe( false );
	} );
} );
