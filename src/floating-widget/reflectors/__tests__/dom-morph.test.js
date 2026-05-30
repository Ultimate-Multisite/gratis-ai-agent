import { morphTargetFromFresh } from '../dom-morph';

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
