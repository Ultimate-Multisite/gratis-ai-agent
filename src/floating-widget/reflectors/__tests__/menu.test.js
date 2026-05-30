/**
 * Unit tests for the navigation menu reflector.
 */

import { reflectMenu } from '../menu';

/**
 * Get menu item text for a selected menu.
 *
 * @param {string} selector Menu selector.
 * @return {string[]} Menu item labels.
 */
function menuItems( selector ) {
	return Array.from(
		document.querySelector( selector ).querySelectorAll( 'li' )
	).map( ( item ) => item.textContent.trim() );
}

/**
 * Build a mocked Fetch API response.
 *
 * @param {string}  html   Response body.
 * @param {boolean} ok     Whether the response is successful.
 * @param {number}  status HTTP response status.
 * @return {Promise<Object>} Mock fetch response.
 */
function responseWithHtml( html, ok = true, status = 200 ) {
	return Promise.resolve( {
		ok,
		status,
		text: () => Promise.resolve( html ),
	} );
}

describe( 'reflectMenu', () => {
	let warnSpy;

	beforeEach( () => {
		warnSpy = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		global.fetch = jest.fn();
		window.scrollTo = jest.fn();
	} );

	afterEach( () => {
		warnSpy.mockRestore();
		delete global.fetch;
		document.body.innerHTML = '';
		document.head.innerHTML = '';
	} );

	test( 'morphs multiple block navigation menus in document order', async () => {
		document.body.innerHTML = `
			<nav class="wp-block-navigation" aria-label="Header">
				<ul><li>Home</li><li>Blog</li><li>About</li></ul>
			</nav>
			<nav class="wp-block-navigation" aria-label="Footer">
				<ul><li>Home</li><li>Privacy</li><li>Terms</li></ul>
			</nav>
		`;

		global.fetch.mockReturnValue(
			responseWithHtml( `
				<html><body>
					<nav class="wp-block-navigation" aria-label="Header">
						<ul><li>Home</li><li>Blog</li><li>About</li><li>Contact</li></ul>
					</nav>
					<nav class="wp-block-navigation" aria-label="Footer">
						<ul><li>Home</li><li>Privacy</li><li>Terms</li><li>Contact</li></ul>
					</nav>
				</body></html>
			` )
		);

		await reflectMenu( { affected: { kind: 'menu', url: '/' } } );

		expect( menuItems( 'nav[aria-label="Header"]' ) ).toEqual( [
			'Home',
			'Blog',
			'About',
			'Contact',
		] );
		expect( menuItems( 'nav[aria-label="Footer"]' ) ).toEqual( [
			'Home',
			'Privacy',
			'Terms',
			'Contact',
		] );
	} );

	test( 'keeps extra current menus when the fresh page has fewer matches', async () => {
		document.body.innerHTML = `
			<nav class="wp-block-navigation" aria-label="Header"><ul><li>Old</li></ul></nav>
			<nav class="wp-block-navigation" aria-label="Mobile"><ul><li>Keep</li></ul></nav>
		`;

		global.fetch.mockReturnValue(
			responseWithHtml( `
				<html><body>
					<nav class="wp-block-navigation" aria-label="Header"><ul><li>New</li><li>Contact</li></ul></nav>
				</body></html>
			` )
		);

		await reflectMenu( { affected: { kind: 'menu', url: '/' } } );

		expect( menuItems( 'nav[aria-label="Header"]' ) ).toEqual( [
			'New',
			'Contact',
		] );
		expect( menuItems( 'nav[aria-label="Mobile"]' ) ).toEqual( [ 'Keep' ] );
	} );

	test( 'silently no-ops when no current menus match', async () => {
		document.body.innerHTML = '<main>No menus here</main>';
		global.fetch.mockReturnValue(
			responseWithHtml(
				'<html><body><nav class="wp-block-navigation"><ul><li>Fresh</li></ul></nav></body></html>'
			)
		);

		await reflectMenu( { affected: { kind: 'menu', url: '/' } } );

		expect( document.body.textContent ).toContain( 'No menus here' );
		expect( warnSpy ).not.toHaveBeenCalled();
	} );

	test( 'catches and logs fetch failures', async () => {
		document.body.innerHTML =
			'<nav class="wp-block-navigation"><ul><li>Home</li></ul></nav>';
		global.fetch.mockReturnValue( responseWithHtml( '', false, 500 ) );

		await reflectMenu( { affected: { kind: 'menu', url: '/' } } );

		expect( warnSpy ).toHaveBeenCalledWith(
			'[sd-ai-agent] menu reflector failed',
			expect.any( Error )
		);
	} );
} );
