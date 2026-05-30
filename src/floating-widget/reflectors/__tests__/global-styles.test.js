/**
 * Unit tests for the global-styles live-preview reflector.
 */

import { reflectGlobalStyles } from '../global-styles';

/**
 * Populate the current document head with global styles fixtures.
 *
 * @param {string} extra Additional HTML appended to the fixture.
 */
function setCurrentHead( extra = '' ) {
	document.head.innerHTML = `
		<style id="global-styles-inline-css">body{color:blue}</style>
		<link rel="stylesheet" id="wp_global_styles-css" href="https://example.com/wp-global.css?ver=1" />
		${ extra }
	`;
}

/**
 * Mock fetch with a fresh HTML document containing the supplied head markup.
 *
 * @param {string} head Fresh document head HTML.
 */
function mockFreshHtml( head ) {
	global.fetch = jest.fn().mockResolvedValue( {
		ok: true,
		status: 200,
		text: jest
			.fn()
			.mockResolvedValue( `<html><head>${ head }</head></html>` ),
	} );
}

describe( 'reflectGlobalStyles', () => {
	let consoleWarnSpy;
	let dateNowSpy;

	beforeEach( () => {
		setCurrentHead();
		consoleWarnSpy = jest
			.spyOn( console, 'warn' )
			.mockImplementation( () => {} );
		dateNowSpy = jest.spyOn( Date, 'now' ).mockReturnValue( 1700000000000 );
	} );

	afterEach( () => {
		consoleWarnSpy.mockRestore();
		dateNowSpy.mockRestore();
		delete global.fetch;
		document.head.innerHTML = '';
	} );

	test( 'updates inline styles and changed global stylesheet hrefs', async () => {
		mockFreshHtml( `
			<style id="global-styles-inline-css">body{color:red}</style>
			<link rel="stylesheet" id="wp_global_styles-css" href="/wp-global.css?ver=2" />
		` );

		await reflectGlobalStyles( {
			affected: { kind: 'global_styles', url: '/' },
		} );

		expect( global.fetch ).toHaveBeenCalledWith(
			'/?_=1700000000000',
			expect.objectContaining( {
				credentials: 'same-origin',
				cache: 'no-store',
				headers: { Accept: 'text/html' },
			} )
		);
		expect(
			document.getElementById( 'global-styles-inline-css' ).textContent
		).toBe( 'body{color:red}' );

		const href = document
			.getElementById( 'wp_global_styles-css' )
			.getAttribute( 'href' );
		expect( href ).toBe(
			'http://localhost/wp-global.css?ver=2&_=1700000000000'
		);
	} );

	test( 'does not delete current inline style when fresh document omits it', async () => {
		mockFreshHtml(
			'<link rel="stylesheet" id="wp_global_styles-css" href="https://example.com/wp-global.css?ver=1" />'
		);

		await reflectGlobalStyles( {
			affected: { kind: 'global_styles', url: '/' },
		} );

		expect(
			document.getElementById( 'global-styles-inline-css' ).textContent
		).toBe( 'body{color:blue}' );
	} );

	test( 'keeps stylesheet href unchanged when fresh href is identical', async () => {
		mockFreshHtml( `
			<style id="global-styles-inline-css">body{color:blue}</style>
			<link rel="stylesheet" id="wp_global_styles-css" href="https://example.com/wp-global.css?ver=1" />
		` );

		await reflectGlobalStyles( {
			affected: { kind: 'global_styles', url: '/' },
		} );

		expect(
			document
				.getElementById( 'wp_global_styles-css' )
				.getAttribute( 'href' )
		).toBe( 'https://example.com/wp-global.css?ver=1' );
	} );

	test( 'logs and leaves DOM unchanged when fetch fails', async () => {
		global.fetch = jest.fn().mockResolvedValue( {
			ok: false,
			status: 500,
			text: jest.fn(),
		} );

		await reflectGlobalStyles( {
			affected: { kind: 'global_styles', url: '/' },
		} );

		expect( consoleWarnSpy ).toHaveBeenCalledWith(
			'[sd-ai-agent] global-styles reflector failed',
			expect.any( Error )
		);
		expect(
			document.getElementById( 'global-styles-inline-css' ).textContent
		).toBe( 'body{color:blue}' );
		expect(
			document
				.getElementById( 'wp_global_styles-css' )
				.getAttribute( 'href' )
		).toBe( 'https://example.com/wp-global.css?ver=1' );
	} );

	test( 'also swaps the classic global-styles-css link id', async () => {
		setCurrentHead(
			'<link rel="stylesheet" id="global-styles-css" href="https://example.com/global.css?ver=1" />'
		);
		mockFreshHtml( `
			<style id="global-styles-inline-css">body{color:blue}</style>
			<link rel="stylesheet" id="wp_global_styles-css" href="https://example.com/wp-global.css?ver=1" />
			<link rel="stylesheet" id="global-styles-css" href="/global.css?ver=2" />
		` );

		await reflectGlobalStyles( {
			affected: { kind: 'global_styles', url: '/' },
		} );

		expect(
			document
				.getElementById( 'global-styles-css' )
				.getAttribute( 'href' )
		).toBe( 'http://localhost/global.css?ver=2&_=1700000000000' );
	} );
} );
