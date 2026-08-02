import {
	fetchFreshPage,
	isCurrentLocation,
	pickTargetsForFields,
	reflectPost,
} from '../post';
import { morphTargetFromFresh } from '../dom-morph';

jest.mock( '../dom-morph', () => ( {
	morphTargetFromFresh: jest.fn(),
} ) );

describe( 'post reflector', () => {
	let consoleWarnSpy;

	beforeEach( () => {
		window.history.pushState( {}, '', '/about/?preview=true' );
		global.fetch = jest.fn();
		morphTargetFromFresh.mockClear();
		consoleWarnSpy = jest
			.spyOn( console, 'warn' )
			.mockImplementation( () => {} );
	} );

	afterEach( () => {
		window.history.pushState( {}, '', '/' );
		delete global.fetch;
		consoleWarnSpy.mockRestore();
		document.body.className = '';
		document.body.innerHTML = '';
	} );

	test( 'isCurrentLocation ignores query strings and trailing slashes', () => {
		expect( isCurrentLocation( '/about/' ) ).toBe( true );
		expect( isCurrentLocation( '/contact/' ) ).toBe( false );
	} );

	test( 'isCurrentLocation matches plain permalink post ID', () => {
		window.history.pushState( {}, '', '/?p=42' );

		expect( isCurrentLocation( '/about/', 42 ) ).toBe( true );
	} );

	test( 'fetchFreshPage requests cache-busted HTML with same-origin credentials', async () => {
		global.fetch.mockResolvedValue( {
			ok: true,
			text: () => Promise.resolve( '<main>Fresh</main>' ),
		} );

		const fresh = await fetchFreshPage( '/about/' );

		expect( global.fetch ).toHaveBeenCalledWith(
			expect.stringMatching( /^http:\/\/localhost\/about\/\?_=\d+$/ ),
			expect.objectContaining( {
				credentials: 'same-origin',
				cache: 'no-store',
				headers: expect.objectContaining( { Accept: 'text/html' } ),
			} )
		);
		expect( fresh.querySelector( 'main' ).textContent ).toBe( 'Fresh' );
	} );

	test( 'pickTargetsForFields chooses the first selector present in both documents', () => {
		document.body.innerHTML = `
			<h1 class="entry-title">Old title</h1>
			<div class="entry-content">Old content</div>
		`;
		const fresh = new DOMParser().parseFromString(
			`
				<h1 class="entry-title">Fresh title</h1>
				<div class="wp-block-post-content">Fresh content</div>
				<div class="entry-content">Fallback content</div>
			`,
			'text/html'
		);

		expect(
			pickTargetsForFields( document, fresh, [
				'post_title',
				'post_content',
			] )
		).toEqual( [ '.entry-title', '.entry-content' ] );
	} );

	test( 'pickTargetsForFields falls back to the block-theme site tree for post content', () => {
		document.body.innerHTML = `
			<div class="wp-site-blocks"><h1>Old homepage</h1></div>
		`;
		const fresh = new DOMParser().parseFromString(
			`
				<div class="wp-site-blocks"><h1>Fresh homepage</h1></div>
			`,
			'text/html'
		);

		expect(
			pickTargetsForFields( document, fresh, [ 'post_content' ] )
		).toEqual( [ '.wp-site-blocks' ] );
	} );

	test( 'reflectPost fetches the current post and morphs changed field targets', async () => {
		document.body.innerHTML = `
			<h1 class="entry-title">Old title</h1>
			<div class="entry-content">Old content</div>
		`;
		global.fetch.mockResolvedValue( {
			ok: true,
			text: () =>
				Promise.resolve( `
					<h1 class="entry-title">Fresh title</h1>
					<div class="entry-content">Fresh content</div>
				` ),
		} );

		await reflectPost( {
			type: 'tool-applied',
			affected: {
				kind: 'post',
				post_id: 42,
				url: '/about/',
				fields: [ 'post_title', 'post_content' ],
			},
		} );

		expect( morphTargetFromFresh ).toHaveBeenCalledTimes( 2 );
		expect( morphTargetFromFresh ).toHaveBeenNthCalledWith(
			1,
			document,
			expect.any( Document ),
			'.entry-title'
		);
		expect( morphTargetFromFresh ).toHaveBeenNthCalledWith(
			2,
			document,
			expect.any( Document ),
			'.entry-content'
		);
	} );

	test( 'reflectPost skips private previews, non-current URLs, and block editor pages', async () => {
		await reflectPost( {
			result: { preview: { workspace_id: 'preview-1' } },
			affected: {
				url: '/about/',
				fields: [ 'post_content' ],
			},
		} );

		await reflectPost( {
			affected: {
				url: '/elsewhere/',
				fields: [ 'post_title' ],
			},
		} );

		document.body.classList.add( 'block-editor-page' );
		await reflectPost( {
			affected: {
				url: '/about/',
				fields: [ 'post_title' ],
			},
		} );

		expect( global.fetch ).not.toHaveBeenCalled();
		expect( morphTargetFromFresh ).not.toHaveBeenCalled();
	} );
} );
